<?php
declare(strict_types=1);
/*
 * 3.2.2: funil comercial derivado, fila de trabalho, títulos resumidos, semelhantes, segmentos,
 * candidatos ML enriquecidos e desempenho. Execução: php -d allow_url_fopen=0 tests/v322_test.php
 */
require __DIR__ . '/harness.php';
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';
require __DIR__ . '/fakes.php';
require __DIR__ . '/../tools/bench_seed.php';

use Gmax\Connectors\Registry;
use Gmax\{Catalog, Db, Funnel, Migrator, MlCandidates, Queue, Settings, Text, Time};

$ready = static function (): PDO {
    Registry::resetOverrides();
    $pdo = t_seed('hostgator');
    Migrator::up($pdo, 'teste');
    Time::freeze(null);
    Settings::set($pdo, 'publicador_v21_aposentado', '1', 't');
    return $pdo;
};
/** Produto Shopee completo (aprovável) com ajustes. */
$make = static function (PDO $pdo, array $over = [], string $status = 'aguardando_validacao') use (&$seq): int {
    $seq = ($seq ?? 0) + 1;
    $id = Catalog::insert($pdo, ['marketplace' => 'shopee', 'external_id' => (string)(77000000000 + $seq), 'title' => "Fone Bluetooth Teste $seq", 'category' => 'Eletrônicos',
        'price_promo_cents' => 4990, 'payment_condition' => 'normal', 'availability' => 'disponivel'], $status, 't');
    Db::run($pdo, "UPDATE gmax3_catalog SET affiliate_url='https://s.shopee.com.br/Fun' || id, affiliate_norm='s.shopee.com.br/Fun' || id, price_verified_at=?, price_verified_by='api:shopee', price_source='api_shopee' WHERE id=?", [Time::nowSql(), $id]);
    if ($over) {
        $set = implode(',', array_map(fn($k) => "$k = ?", array_keys($over)));
        Db::run($pdo, "UPDATE gmax3_catalog SET $set WHERE id=?", [...array_values($over), $id]);
    }
    return $id;
};
$a = static fn(PDO $pdo, int $id) => Funnel::analyze($pdo, Catalog::get($pdo, $id), Funnel::context($pdo));
$dump = static function (PDO $pdo): string {
    $s = [];
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'gmax3_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $s[$t] = $pdo->query("SELECT * FROM $t ORDER BY rowid")->fetchAll(PDO::FETCH_NUM);
    }
    return hash('sha256', serialize($s));
};

echo "\n== Funil comercial (camada derivada)\n";
test('Cada situação cai na etapa certa, com pendência principal, próxima ação e prioridade', function () use ($ready, $make, $a) {
    $pdo = $ready();
    $cases = [
        'rascunho' => [$make($pdo, [], 'rascunho'), 'triagem', 'triagem', 3, 'oferta'],
        'preço nunca conferido' => [$make($pdo, ['price_verified_at' => null]), 'conferencia', 'preco_desatualizado', 2, 'conferir'],
        'preço vencido em aprovada' => [$make($pdo, ['price_verified_at' => '2020-01-01 00:00:00'], 'aprovado'), 'conferencia', 'preco_desatualizado', 1, 'conferir'],
        'variação' => [$make($pdo, ['variation_pending' => 1]), 'conferencia', 'variacao', 2, 'conferir'],
        'indisponível' => [$make($pdo, ['availability' => 'indisponivel']), 'conferencia', 'inconsistencia', 2, 'conferir'],
        'De menor que Por' => [$make($pdo, ['price_original_cents' => 1000]), 'conferencia', 'inconsistencia', 2, 'conferir'],
        'sem link' => [$make($pdo, ['affiliate_url' => null, 'affiliate_norm' => null]), 'link', 'sem_link', 2, null],
        'completa aguardando' => [$make($pdo), 'aprovacao', 'aguardando_aprovacao', 2, null],
        'aprovada pronta' => [$make($pdo, [], 'aprovado'), 'fila', 'disponivel_selecao', 2, null],
    ];
    foreach ($cases as $why => [$id, $stage, $sit, $prio, $page]) {
        $r = $a($pdo, $id);
        eq([$stage, $sit, $prio], [$r['stage'], $r['situation'], $r['priority']], $why);
        ok(is_string($r['pending']) && $r['pending'] !== '' && $r['action'] !== null, "$why: pendência e ação");
        if ($page) {
            eq($page, $r['action']['page'], "$why: ação");
        }
    }
    eq('link_set', $a($pdo, $cases['sem link'][0])['action']['kind']);
    eq('approve', $a($pdo, $cases['completa aguardando'][0])['action']['kind']);
    eq('enqueue', $a($pdo, $cases['aprovada pronta'][0])['action']['kind']);
    $arch = $make($pdo, [], 'arquivado');
    eq('encerrado', $a($pdo, $arch)['stage']);
});
test('Fila comercial: agendado = etapa Fila; reservado/incerto = Publicação (prioridade alta); confirmado = Resultado', function () use ($ready, $make, $a) {
    $pdo = $ready();
    $id = $make($pdo, [], 'aprovado');
    eq([], Queue::enqueue($pdo, $id, 'telegram_privado', null, 't'));
    eq(['fila', null], [$a($pdo, $id)['stage'], $a($pdo, $id)['situation']], 'agendado sem pendência não entra na fila de trabalho');
    Db::run($pdo, "UPDATE gmax3_pub_queue SET state='incerto' WHERE catalog_id=?", [$id]);
    eq(['publicacao', 'inconsistencia', 1], [$a($pdo, $id)['stage'], $a($pdo, $id)['situation'], $a($pdo, $id)['priority']]);
    Db::run($pdo, "UPDATE gmax3_pub_queue SET state='confirmado', finished_at=? WHERE catalog_id=?", [Time::nowSql(), $id]);
    eq('resultado', $a($pdo, $id)['stage']);
});
test('Mesmas regras do sistema: "Aprovar" da fila aprova de fato; "Agendar" passa nas travas de entrada da fila', function () use ($ready, $make, $a) {
    $pdo = $ready();
    $id = $make($pdo);
    eq('approve', $a($pdo, $id)['action']['kind']);
    eq([], Catalog::approvalIssues(Catalog::get($pdo, $id)));
    eq([], Catalog::setStatus($pdo, $id, 'aprovado', 't'));
    eq('enqueue', $a($pdo, $id)['action']['kind']);
    eq([], Queue::enqueue($pdo, $id, 'telegram_privado', null, 't'), 'travas de entrada aceitam');
    $ml = $make($pdo, ['marketplace' => 'mercadolivre', 'external_id' => 'item:MLB999000111', 'affiliate_url' => 'https://meli.la/AbCdEf', 'affiliate_norm' => 'meli.la/abcdef'], 'aprovado');
    ok($a($pdo, $ml)['situation'] === null, 'ML aprovado bloqueado pela regra do programa não aparece como "disponível para seleção": ' . json_encode($a($pdo, $ml)));
});
test('Somente leitura: calcular funil, fila e resumo não grava nada no banco', function () use ($ready, $make, $dump) {
    $pdo = $ready();
    for ($i = 0; $i < 5; $i++) {
        $make($pdo, ['price_verified_at' => null]);
    }
    $before = $dump($pdo);
    Funnel::summary($pdo);
    Funnel::workQueue($pdo, ['prio' => '2']);
    Funnel::next($pdo, [], 1, 0);
    eq($before, $dump($pdo));
});

echo "\n== Minha fila de trabalho\n";
test('Ordem de atendimento, filtros (loja, categoria, prioridade, situação, etapa) e resumo coerente com a lista', function () use ($ready, $make) {
    $pdo = $ready();
    $hi = $make($pdo, ['price_verified_at' => null], 'aprovado');
    $mid = $make($pdo, ['variation_pending' => 1]);
    $cat = $make($pdo, ['category' => 'Categoria Teste Única', 'affiliate_url' => null, 'affiliate_norm' => null]);
    $q = Funnel::workQueue($pdo);
    eq($hi, (int)$q[0]['item']['id'], 'prioridade alta primeiro');
    $sum = Funnel::summary($pdo);
    eq($sum['total'], count($q), 'resumo = lista');
    foreach (Funnel::SITUATIONS as $k => $_) {
        eq($sum['situacoes'][$k], count(Funnel::workQueue($pdo, ['sit' => $k])), "contagem $k");
    }
    eq([$cat], array_map(fn($x) => (int)$x['item']['id'], Funnel::workQueue($pdo, ['cat' => 'Categoria Teste Única'])));
    eq(0, count(Funnel::workQueue($pdo, ['mp' => 'shopee', 'sit' => 'triagem', 'cat' => 'Categoria Teste Única'])));
    ok(in_array($mid, array_map(fn($x) => (int)$x['item']['id'], Funnel::workQueue($pdo, ['etapa' => 'conferencia'])), true), 'etapa');
    ok(!array_filter(Funnel::workQueue($pdo, ['prio' => '1']), fn($x) => $x['a']['priority'] !== 1), 'só prioridade alta');
    eq([], Funnel::filters(['mp' => 'amazon', 'sit' => '<x>', 'prio' => '9', 'etapa' => 'x']), 'filtros inválidos ignorados');
});
test('Salvar e avançar: resolvido sai e o próximo ocupa a posição; pendente avança; fim da fila', function () use ($ready, $make) {
    $pdo = $ready();
    $ids = [];
    for ($i = 0; $i < 3; $i++) {
        $ids[] = $make($pdo, ['price_verified_at' => null]);
    }
    $order = array_map(fn($x) => (int)$x['item']['id'], Funnel::workQueue($pdo, ['sit' => 'preco_desatualizado']));
    $f = ['sit' => 'preco_desatualizado'];
    $first = $order[0];
    Catalog::confirmPrice($pdo, $first, 't'); // resolvido
    eq($order[1], (int)Funnel::next($pdo, $f, $first, 0)['item']['id'], 'o próximo ocupa a posição do resolvido');
    eq($order[2], (int)Funnel::next($pdo, $f, $order[1], 0)['item']['id'], 'pendente: avança uma posição');
    foreach (array_slice($order, 1) as $id) {
        Catalog::confirmPrice($pdo, $id, 't');
    }
    eq(null, Funnel::next($pdo, $f, $order[2], 1), 'fila concluída');
});
test('Versão do produto muda a cada alteração relevante (base da proteção contra ação duplicada)', function () use ($ready, $make) {
    $pdo = $ready();
    $id = $make($pdo);
    $v1 = Funnel::version(Catalog::get($pdo, $id));
    Time::freeze(Time::now()->modify('+2 seconds'));
    Catalog::setStatus($pdo, $id, 'aprovado', 't');
    $v2 = Funnel::version(Catalog::get($pdo, $id));
    ok($v1 !== $v2, 'aprovação muda a versão');
    Time::freeze(Time::now()->modify('+2 seconds'));
    Catalog::confirmPrice($pdo, $id, 't');
    ok($v2 !== Funnel::version(Catalog::get($pdo, $id)), 'conferência muda a versão');
    Time::freeze(null);
});

echo "\n== Títulos, semelhantes e segmentos\n";
test('Título resumido: tira repetições e termos genéricos; mantém marca (1ª palavra) e todos os números', function () {
    eq('Fone Bluetooth JBL Tune 510BT Preto', Text::shortTitle('Fone Bluetooth Original JBL Tune 510BT Preto Original Promoção Envio Imediato'));
    $long = 'Parafusadeira Furadeira Impacto 48V Bateria Lítio Maleta Completa Kit Acessórios Brocas Bits Profissional Reversível 2 Baterias 13mm';
    $s = Text::shortTitle($long, 60);
    foreach (['48V', '2', '13mm'] as $n) {
        ok(str_contains($s, $n), "$n mantido: $s");
    }
    ok(str_starts_with($s, 'Parafusadeira'), 'primeira palavra mantida');
    ok(mb_strlen($s) <= 80, 'curto: ' . $s);
    eq('Garrafa Térmica 500ml', Text::shortTitle('Garrafa Térmica 500ml'), 'título curto não muda');
});
test('Semelhança (possível duplicado) e segmento comercial', function () {
    ok(Text::similarity('Fone de Ouvido Bluetooth JBL Tune 510BT Preto', 'JBL Tune 510BT Fone Bluetooth Preto Original') >= 0.6, 'mesmo produto');
    ok(Text::similarity('Fone de Ouvido Bluetooth JBL', 'Air Fryer Mondial 4L') < 0.2, 'produtos diferentes');
    eq('tecnologia', Text::segment('Fone de Ouvido Bluetooth JBL'));
    eq('ferramentas', Text::segment('Parafusadeira Furadeira 48V'));
    eq('casa', Text::segment('Air Fryer Mondial 4L Fritadeira'));
    eq('beleza', Text::segment('Secador de Cabelo 2000W'));
    eq('infantil', Text::segment('Mamadeira Anticólica Bebê 260ml'));
    eq(null, Text::segment('Item genérico xyz'));
});

echo "\n== Candidatos ML\n";
test('Candidatos: paginação, segmento, já avaliados, semelhantes (candidato e catálogo) e só campos gravados', function () use ($ready, $make) {
    $pdo = $ready();
    $now = Time::nowSql();
    $ins = $pdo->prepare("INSERT INTO gmax3_ml_candidates(product_id,name,origem,status,permalink,bb_price_cents,bb_checked_at,first_seen_at,last_seen_at) VALUES(?,?,'termo',?,?,?,?,?,?)");
    for ($i = 1; $i <= 25; $i++) {
        $ins->execute(['MLB' . (5000000 + $i), $i <= 2 ? 'Fone Bluetooth JBL Tune 510BT Preto' : "Furadeira Profissional Modelo $i", $i === 25 ? 'descartado' : 'novo', null, null, null, $now, $now]);
    }
    $make($pdo, ['title' => 'JBL Tune 510BT Fone Bluetooth Preto']);
    $p1 = MlCandidates::browse($pdo, ['status' => 'novo'], 10);
    eq([24, 3, 1, 10], [$p1['total'], $p1['pages'], $p1['page'], count($p1['rows'])]);
    eq(4, count(MlCandidates::browse($pdo, ['status' => 'novo', 'pg' => '3'], 10)['rows']));
    eq(2, MlCandidates::browse($pdo, ['status' => 'novo', 'seg' => 'tecnologia'], 10)['total']);
    eq(1, MlCandidates::browse($pdo, ['status' => 'avaliados'], 10)['total']);
    $fone = MlCandidates::browse($pdo, ['status' => 'novo', 'seg' => 'tecnologia'], 10)['rows'][0];
    $types = array_column($fone['_similar'], 'tipo');
    ok(in_array('candidato', $types, true) && in_array('produto', $types, true), 'semelhante a candidato e a produto: ' . json_encode($fone['_similar']));
    ok(!isset($fone['_fields']['bb_price_cents']) && !isset($fone['_fields']['permalink']), 'campos não informados não aparecem');
    eq('tecnologia', MlCandidates::browse($pdo, ['status' => 'novo', 'ordem' => 'segmento'], 30)['rows'][0]['_seg'], 'prioridade por segmento');
});

echo "\n== Desempenho\n";
test('Fila e resumo com 1.000 e 10.000 produtos (tempo registrado)', function () {
    $out = [];
    foreach ([1000, 10000] as $n) {
        $pdo = bench_seed($n);
        $t = hrtime(true);
        $s = Funnel::summary($pdo);
        $q = Funnel::workQueue($pdo);
        $ms = (hrtime(true) - $t) / 1e6;
        $out[$n] = ['ms' => round($ms), 'pendencias' => count($q), 'total_resumo' => $s['total']];
        eq($s['total'], count($q), "$n: resumo = fila");
        Db::reset();
    }
    $GLOBALS['v322_perf'] = $out;
    ok($out[10000]['ms'] < 15000, 'resumo + fila com 10 mil produtos: ' . json_encode($out));
    echo '    ' . json_encode($out) . "\n";
});

Time::freeze(null);
$pass = count(array_filter($GLOBALS['t_results'], fn($r) => $r[1]));
$total = count($GLOBALS['t_results']);
echo "\nResultado 3.2.2: $pass/$total testes aprovados\n";
file_put_contents(__DIR__ . '/../docs/resultado-testes-v322.json', json_encode([
    'executado_em' => date('c'), 'php' => PHP_VERSION, 'aprovados' => $pass, 'total' => $total, 'desempenho' => $GLOBALS['v322_perf'] ?? null,
    'testes' => array_map(fn($r) => ['nome' => $r[0], 'ok' => $r[1], 'erro' => $r[2], 'segundos' => round($r[3], 3)], $GLOBALS['t_results']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($pass === $total ? 0 : 1);

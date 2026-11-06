<?php
declare(strict_types=1);
/*
 * Testes de interface 3.1.5 no navegador (Chromium/Playwright, emulação de dispositivos).
 * Sobe o painel no servidor embutido do PHP (sem rede externa: os domínios das lojas são
 * interceptados no navegador) e confere no banco que nada foi enviado nem confirmado indevidamente.
 * Execução: php tests/ui_test.php   (requer node + playwright; caso contrário é PULADO)
 */
require __DIR__ . '/harness.php';
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';

use Gmax\{Catalog, Db, Migrator, Settings};

$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node === '' || !str_contains((string)shell_exec('NODE_PATH=$(npm root -g 2>/dev/null) node -e "require(\'playwright\');console.log(\'pw-ok\')" 2>/dev/null'), 'pw-ok')) {
    echo "Teste de interface PULADO: node/playwright indisponível\n";
    exit(0);
}

$pdo = t_seed('hostgator');
Migrator::up($pdo, 'teste');
// Catálogo variado: com e sem links, variação pendente, indisponível, validado pela API, rascunho sem link.
$ids = array_map('intval', $pdo->query("SELECT id FROM gmax3_catalog WHERE marketplace='shopee' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$ml = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE marketplace='mercadolivre'");
Db::run($pdo, "UPDATE gmax3_catalog SET variation_pending=1, variation_note='Escolher voltagem 110 V ou 220 V' WHERE id=?", [$ids[1]]);
Db::run($pdo, "UPDATE gmax3_catalog SET availability='indisponivel' WHERE id=?", [$ids[2]]);
Catalog::markVerifiedByApi($pdo, $ids[3], 'shopee', 'teste');
$noLink = Catalog::insert($pdo, ['marketplace' => 'shopee', 'external_id' => '22899999901', 'title' => 'Mochila Teste Sem Link <b>negrito</b>',
    'original_url' => 'https://shopee.com.br/product/222222/22899999901', 'price_promo_cents' => 8990], 'rascunho', 'teste');
Gmax\Automation\Runner::runManual($pdo, 'revisao_precos', 'teste'); // gera histórico de execução e alertas
// Diagnóstico ML gravado (respostas simuladas no formato real do PolicyAgent), para exibir a Central de Integrações.
$diagHttp = new class extends Gmax\Http {
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array
    {
        $path = substr($url, strlen(Gmax\Connectors\MercadoLivre::API));
        $pa = '{"code":"PA_UNAUTHORIZED_RESULT_FROM_POLICIES","blocked_by":"PolicyAgent","status":403,"message":"At least one policy returned UNAUTHORIZED."}';
        return match (true) {
            $path === '/sites/MLB' => ['status' => 200, 'body' => '{}', 'error' => null],
            $path === '/users/me' => ['status' => 200, 'body' => '{"id":123456789}', 'error' => null],
            default => ['status' => 403, 'body' => $pa, 'error' => null],
        };
    }
};
Settings::store($pdo, 'ml_diagnostico_ultimo', json_encode((new Gmax\Connectors\MercadoLivre($diagHttp, 'APP_USR-1234567890123456-000000-abcdefabcdefabcdefabcdefabcdefab-1'))->diagnose('MLB7519481690'), JSON_UNESCAPED_UNICODE), 'teste');
// Candidatos do catálogo ML (3.1.9): um com preço do anúncio vencedor, outro sem.
foreach ([['MLB19615283', 'Fone de Ouvido Bluetooth X1', 'https://http2.mlstatic.com/D_NQ_NP_1.jpg', 12990, 'item:MLB4455667788'], ['MLB20000001', 'Air Fryer 4L Z3', null, null, null]] as $i => [$pid, $nm, $pic, $price, $bb]) {
    Db::run($pdo, "INSERT INTO gmax3_ml_candidates(product_id,name,category_name,picture_url,permalink,origem,termo,trend_pos,bb_item_id,bb_price_cents,bb_checked_at,first_seen_at,last_seen_at) VALUES(?,?,?,?,?,?,?,?,?,?,datetime('now'),datetime('now'),datetime('now'))",
        [$pid, $nm, 'Eletrônicos', $pic, "https://www.mercadolivre.com.br/x/p/$pid", $i ? 'tendencia' : 'termo', $i ? 'air fryer' : 'fone', $i ? 1 : null, $bb, $price]);
}
// GMAX AutoLab (3.2.1): ativo, com uma oferta validada enviada e um candidato incerto no histórico.
Settings::set($pdo, 'autolab_estado', 'ativo', 'teste');
Settings::set($pdo, 'autolab_ate', gmdate('Y-m-d H:i:s', time() + 5 * 86400), 'teste');
Settings::store($pdo, 'autolab_destino_info', json_encode(['chat_id' => Gmax\Config::PRIVATE_CHAT_ID, 'type' => 'supergroup', 'title' => 'GMAX homologação', 'public_username' => false, 'members' => 3, 'bot_status' => 'administrator', 'problemas' => [], 'em' => gmdate('Y-m-d H:i:s')]), 'teste');
foreach ([['2026-11-04 10:00', 'oferta_validada', 'api_shopee', 1, 3990, 'confirmado', "TESTE — OFERTA VALIDADA\nHomologação GMAX AutoLab · não é publicação comercial\n\nGarrafa térmica\nPreço: R$ 39,90 — confirmado pela API Shopee"],
          ['2026-11-04 18:00', 'candidato', 'catalogo_ml', 0, null, 'incerto', "TESTE — CANDIDATO\nHomologação GMAX AutoLab · não é oferta e não tem link de compra\n\nFone\nID de catálogo: MLB19615283 (não é ID de anúncio)"]] as $i => [$slot, $kind, $src, $conf, $price, $state, $text]) {
    Db::run($pdo, "INSERT INTO gmax3_autolab_runs(slot_key,status,started_at,finished_at,detail) VALUES(?,'concluido',datetime('now'),datetime('now'),'teste')", [$slot]);
    Db::run($pdo, 'INSERT INTO gmax3_autolab_messages(run_id,slot_key,kind,source,price_confirmed,price_cents,dedup_key,text,text_hash,state,created_at,http_code,telegram_message_id) VALUES(?,?,?,?,?,?,?,?,?,?,datetime(\'now\'),?,?)',
        [$i + 1, $slot, $kind, $src, $conf, $price, "k$i", $text, hash('sha256', $text), $state, $state === 'confirmado' ? 200 : 0, $state === 'confirmado' ? 501 : null]);
}
// 3.2.2: produtos com preço nunca conferido para o fluxo da fila de trabalho (salvar e avançar).
$filaUi = [];
for ($i = 1; $i <= 4; $i++) {
    $fid = Catalog::insert($pdo, ['marketplace' => 'shopee', 'external_id' => (string)(66600000000 + $i), 'title' => "Garrafa Fila UI $i 500ml", 'category' => 'Fila UI',
        'price_promo_cents' => 2990, 'payment_condition' => 'normal', 'availability' => 'disponivel', 'original_url' => "https://shopee.com.br/product/123/" . (66600000000 + $i)], 'aguardando_validacao', 'teste');
    Db::run($pdo, "UPDATE gmax3_catalog SET affiliate_url='https://s.shopee.com.br/FilaUi' || id, affiliate_norm='s.shopee.com.br/FilaUi' || id WHERE id=?", [$fid]);
    // Aprovados com preço nunca conferido = prioridade alta: são os primeiros da rodada de conferência no teste.
    if ($issues = Catalog::setStatus($pdo, $fid, 'aprovado', 'teste')) {
        throw new RuntimeException('Fila UI: ' . implode('; ', $issues));
    }
    Db::run($pdo, "UPDATE gmax3_catalog SET variation_pending=1, variation_note='Escolher a cor' WHERE id=?", [$fid]); // continua pendente (não fica pronto)
    $filaUi[] = $fid;
}
$before = [
    'pausa' => Settings::paused($pdo), 'auto' => Settings::get($pdo, 'automacoes_pausadas'), 'aprov' => Settings::get($pdo, 'aprovacao_automatica'),
    'links' => Db::all($pdo, 'SELECT id, affiliate_url FROM gmax3_catalog ORDER BY id'),
];

$app = t_app();
$port = 19000 + random_int(0, 899);
$base = "http://127.0.0.1:$port";
$env = ['GMAX_SECRETS_DIR' => t_sandbox() . '/gmax-secrets', 'GMAX_TESTING' => '1', 'PATH' => getenv('PATH')];
$server = proc_open([PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'display_errors=1', '-S', "127.0.0.1:$port", '-t', $app], [1 => ['file', '/dev/null', 'w'], 2 => ['file', t_sandbox() . '/ui-server.log', 'w']], $pipes, $app, $env);
register_shutdown_function(static fn() => proc_terminate($server));
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}
$shots = __DIR__ . '/../docs/capturas/3.1.5';
@mkdir($shots, 0755, true);
$ctx = json_encode(['base' => $base, 'password' => T_PASSWORD, 'shots' => realpath($shots), 'fixture' => realpath(__DIR__ . '/fixtures/shopee-5-ofertas.csv'),
    'estimates' => array_map(static fn($pct) => array_map(static fn($c) => Gmax\Money::format($c), array_intersect_key(Gmax\Pricing::estimate(9990, $pct), ['original' => 1, 'min' => 1, 'max' => 1])), ['35' => '35', '35,5' => '35,5', '7' => '7']),
    'ids' => ['filaUi' => $filaUi, 'calc' => $ids[4], 'shopee' => $ids, 'ml' => $ml, 'semLink' => $noLink, 'api' => $ids[3], 'variacao' => $ids[1], 'indisponivel' => $ids[2]],
    'urls' => ['shopee0' => Catalog::get($pdo, $ids[0])['original_url'], 'aff0' => Catalog::get($pdo, $ids[0])['affiliate_url']]]);
$cmd = 'env -u HTTPS_PROXY -u HTTP_PROXY -u https_proxy -u http_proxy NODE_PATH=$(npm root -g) node ' . escapeshellarg(__DIR__ . '/browser_ui.js') . ' ' . escapeshellarg($ctx) . ' 2>&1';
$out = (string)shell_exec($cmd);
$lines = array_values(array_filter(array_map(static fn($l) => json_decode($l, true), explode("\n", $out)), 'is_array'));
if (!$lines) {
    test('Navegador: teste executado', fn() => ok(false, mb_substr($out, 0, 800)));
}
$current = '';
foreach ($lines as $l) {
    if (($l['grupo'] ?? '') !== $current) {
        $current = (string)$l['grupo'];
        echo "\n== $current\n";
    }
    test($l['nome'], fn() => ok((bool)$l['ok'], (string)$l['erro']));
}

echo "\n== Integridade após os testes de interface (banco)\n";
test('Envios continuam pausados, automações pausadas, aprovação automática desligada; nada enviado', function () use ($pdo, $before) {
    Db::reset();
    $pdo = Db::get();
    eq([$before['pausa'], $before['auto'], $before['aprov']], [Settings::paused($pdo), Settings::get($pdo, 'automacoes_pausadas'), Settings::get($pdo, 'aprovacao_automatica')]);
    ok(Settings::paused($pdo), 'envios pausados');
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_pub_queue WHERE state IN ('reservado','confirmado','incerto')"), 'nada enviado');
});
test('Links afiliados preservados (nenhum trocado pelo link comum)', function () use ($before) {
    eq($before['links'], Db::all(Db::get(), 'SELECT id, affiliate_url FROM gmax3_catalog WHERE id IN (' . implode(',', array_column($before['links'], 'id')) . ') ORDER BY id'));
});
test('Fila pela interface: exatamente um produto conferido (manual), sem aprovação automática nem fila comercial', function () use ($filaUi) {
    $pdo = Db::get();
    $done = Db::all($pdo, 'SELECT id, price_source, validation_status FROM gmax3_catalog WHERE id IN (' . implode(',', $filaUi) . ') AND price_verified_at IS NOT NULL');
    eq(1, count($done), 'um conferido');
    eq(['conferencia_manual', 'aprovado'], [$done[0]['price_source'], $done[0]['validation_status']], 'registrado como manual; aprovação inalterada (mesmo preço), nada aprovado pela conferência');
    eq(0, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_pub_queue WHERE catalog_id IN (' . implode(',', $filaUi) . ')'));
});
test('Abrir o anúncio não confirmou preço; só a conferência salva gerou registro manual', function () use ($ids, $ml) {
    $pdo = Db::get();
    eq(null, Catalog::get($pdo, $ids[0])['price_verified_at'], 'produto aberto na loja sem salvar: continua não conferido');
    $p = Catalog::get($pdo, $ml);
    ok($p['price_verified_at'] !== null && $p['price_verified_by'] === 'admin' && $p['price_source'] === 'conferencia_manual', json_encode([$p['price_verified_by'], $p['price_source']]));
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_price_history WHERE catalog_id=? AND source LIKE 'api_%'", [$ml]), 'nunca registrado como API');
    eq(1, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_audit WHERE action='conferencia_manual' AND entity_id=?", [(string)$ml]));
});
test('Modo desconto pela tela: "De" estimado não gravado; estimativa registrada à parte', function () use ($ids) {
    $p = Catalog::get(Db::get(), $ids[4]);
    eq([9990, null], [(int)$p['price_promo_cents'], $p['price_original_cents']]);
    $e = Gmax\Pricing::latestEstimate(Db::get(), $p);
    eq([15369, false], [$e['de_estimado'] ?? null, $e['confirmado'] ?? null]);
    ok(!str_contains(Gmax\Message::render($p, Db::get()), '153,69'), 'fora da mensagem de publicação');
});
test('Nenhum service worker nem cache: o servidor não serve sw.js', function () use ($app) {
    ok(!is_file("$app/sw.js") && !is_file("$app/gmax-assets/sw.js"), 'sem service worker');
});

$pass = count(array_filter($GLOBALS['t_results'], fn($r) => $r[1]));
$total = count($GLOBALS['t_results']);
echo "\nResultado interface: $pass/$total testes aprovados\n";
file_put_contents(__DIR__ . '/../docs/resultado-testes-interface.json', json_encode([
    'executado_em' => date('c'), 'php' => PHP_VERSION, 'aprovados' => $pass, 'total' => $total,
    'testes' => array_map(fn($r) => ['nome' => $r[0], 'ok' => $r[1], 'erro' => $r[2], 'segundos' => round($r[3], 3)], $GLOBALS['t_results']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($pass === $total ? 0 : 1);

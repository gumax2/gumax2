<?php
declare(strict_types=1);
/*
 * 3.2.2.2: conferência progressiva por rodadas, persistida no SQLite (migração 007).
 * Execução: php -d allow_url_fopen=0 tests/v3222_test.php
 */
require __DIR__ . '/harness.php';
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';

use Gmax\{Catalog, Db, Funnel, Migrator, Ranking, Review, Time};

$ready = static function (int $n = 0, bool $onlyThese = true): array {
    $pdo = t_seed('hostgator');
    Migrator::up($pdo, 'teste');
    Time::freeze(null);
    if ($onlyThese) {
        $pdo->exec("UPDATE gmax3_catalog SET validation_status='arquivado'");
    }
    $ids = [];
    for ($i = 1; $i <= $n; $i++) {
        $id = Catalog::insert($pdo, ['marketplace' => 'shopee', 'external_id' => (string)(94000000000 + $i), 'title' => "Rodada $i", 'category' => 'R',
            'price_promo_cents' => 10000, 'payment_condition' => 'normal', 'availability' => 'disponivel'], 'aguardando_validacao', 't');
        Db::run($pdo, "UPDATE gmax3_catalog SET affiliate_url='https://s.shopee.com.br/Rd' || id, affiliate_norm='s.shopee.com.br/Rd' || id, variation_pending=? WHERE id=?", [$i <= 4 ? 1 : 0, $id]);
        $ids[] = $id;
    }
    return [$pdo, $ids];
};
$walk = static function (PDO $pdo, int $rid, int $max, string $result = 'analisado'): array {
    $seen = [];
    while (count($seen) < $max && ($id = Review::nextId($pdo, $rid)) !== null) {
        $seen[] = $id;
        Review::record($pdo, $rid, $id, $result, 'teste', 'admin');
    }
    return $seen;
};

echo "\n== Rodada: progresso persistente, sem repetição\n";
test('89 produtos: todos entram na rodada (não só os 4 da etapa de conferência); levas seguidas nunca repetem; fim sem reinício', function () use ($ready, $walk) {
    [$pdo, $ids] = $ready(89);
    $round = Review::start($pdo, 'admin', 'admin');
    eq(89, (int)$round['total_initial'], 'rodada com os 89 produtos ativos');
    $first = $walk($pdo, (int)$round['id'], 4);
    $second = $walk($pdo, (int)$round['id'], 4);
    eq([], array_values(array_intersect($first, $second)), 'segunda leva sem nenhum dos 4 primeiros');
    $rest = $walk($pdo, (int)$round['id'], 200);
    $all = [...$first, ...$second, ...$rest];
    eq(89, count($all));
    eq(count($all), count(array_unique($all)), 'cada produto uma única vez');
    eq(null, Review::nextId($pdo, (int)$round['id']), 'fim');
    eq(['total' => 89, 'nao_analisado' => 0, 'analisados' => 89], array_intersect_key(Review::stats($pdo, (int)$round['id']), array_flip(['total', 'nao_analisado', 'analisados'])));
    eq('aberta', Review::open($pdo, 'admin')['status'], 'a rodada não recomeça nem fecha sozinha');
});
test('Logout / novo login / navegador fechado: o progresso está no banco (nova conexão continua do mesmo ponto)', function () use ($ready, $walk) {
    [$pdo, $ids] = $ready(10);
    $round = Review::start($pdo, 'admin', 'admin');
    $done = $walk($pdo, (int)$round['id'], 3);
    $_SESSION = []; // sessão perdida
    Db::reset();
    $pdo2 = Db::get();
    $r2 = Review::open($pdo2, 'admin');
    eq((int)$round['id'], (int)$r2['id'], 'mesma rodada aberta');
    $next = Review::nextId($pdo2, (int)$r2['id']);
    ok(!in_array($next, $done, true), 'próximo não é nenhum dos já analisados');
    eq($ids[3], $next, 'continua do 4º');
    eq(3, (int)Db::value($pdo2, 'SELECT COUNT(*) FROM gmax3_review_visits WHERE round_id=?', [$r2['id']]), 'data, administrador e resultado registrados');
    eq('admin', Db::value($pdo2, 'SELECT admin FROM gmax3_review_visits ORDER BY id DESC LIMIT 1'));
});
test('Analisado ≠ validado ≠ pronto: variação continua pendente; nada é aprovado; dados do produto intactos', function () use ($ready, $walk) {
    [$pdo, $ids] = $ready(6);
    $before = Db::all($pdo, 'SELECT * FROM gmax3_catalog ORDER BY id');
    $round = Review::start($pdo, 'admin', 'admin');
    $walk($pdo, (int)$round['id'], 10);
    eq($before, Db::all($pdo, 'SELECT * FROM gmax3_catalog ORDER BY id'), 'catálogo idêntico (analisar sem salvar conferência não altera nada)');
    $a = Funnel::analyze($pdo, Catalog::get($pdo, $ids[0]), Funnel::context($pdo));
    ok(in_array('Variação (cor/voltagem/tamanho) pendente', $a['issues'], true), 'pendência comercial continua');
    ok(Ranking::readiness($pdo, Catalog::get($pdo, $ids[0])) !== [], 'não está pronto para divulgar');
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE validation_status='aprovado'"));
});
test('Pular e "revisar depois": não voltam sozinhos; devolver é ação explícita; revisitar registra nova visita sem perder a original', function () use ($ready) {
    [$pdo, $ids] = $ready(5);
    $rid = (int)Review::start($pdo, 'admin', 'admin')['id'];
    Review::record($pdo, $rid, $ids[0], 'pulado', 'pulado', 'admin');
    Review::record($pdo, $rid, $ids[1], 'revisar', 'revisar_depois', 'admin');
    Review::record($pdo, $rid, $ids[2], 'analisado', 'conferencia', 'admin');
    eq($ids[3], Review::nextId($pdo, $rid), 'pulado e revisar não voltam ao "próximo"');
    eq(['pulado' => 1, 'revisar' => 1], array_intersect_key(Review::stats($pdo, $rid), array_flip(['pulado', 'revisar'])));
    eq('revisitado', Review::record($pdo, $rid, $ids[2], 'analisado', 'revisita', 'admin'), 'revisita registrada');
    eq(2, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_review_visits WHERE round_id=? AND catalog_id=?', [$rid, $ids[2]]), 'visita original preservada');
    eq('analisado', Review::item($pdo, $rid, $ids[2])['state']);
    eq(1, Review::requeue($pdo, $rid, 'pulado', 'admin'));
    eq($ids[0], Review::nextId($pdo, $rid), 'pulado devolvido explicitamente volta na posição dele');
});
test('Automação muda preço/disponibilidade de um analisado: vira "precisa de revisão" sem apagar o progresso nem reinserir', function () use ($ready) {
    [$pdo, $ids] = $ready(4, true);
    $rid = (int)Review::start($pdo, 'admin', 'admin')['id'];
    foreach ($ids as $id) {
        Review::record($pdo, $rid, $id, 'analisado', 'conferencia', 'admin');
    }
    Db::run($pdo, 'UPDATE gmax3_catalog SET price_promo_cents=10050 WHERE id=?', [$ids[0]]); // +0,5%: irrelevante
    Db::run($pdo, 'UPDATE gmax3_catalog SET price_promo_cents=9000 WHERE id=?', [$ids[1]]);  // -10%: relevante
    Db::run($pdo, "UPDATE gmax3_catalog SET availability='indisponivel' WHERE id=?", [$ids[2]]);
    eq([false, true, true, false], array_map(fn($id) => Review::needsReview($pdo, $rid, $id), $ids));
    eq(2, Review::stats($pdo, $rid)['revisar']);
    eq(null, Review::nextId($pdo, $rid), 'não reinserido em "não analisados"');
    eq('analisado', Review::item($pdo, $rid, $ids[1])['state'], 'progresso preservado');
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE validation_status='aprovado'"), 'nada aprovado');
    Review::record($pdo, $rid, $ids[1], 'analisado', 'revisita', 'admin');
    ok(!Review::needsReview($pdo, $rid, $ids[1]), 'revisitado: nova linha de base');
});
test('Produtos novos: fora da rodada, em "recém-chegados"; extensão explícita os inclui no fim; nova rodada encerra a anterior (histórico)', function () use ($ready, $walk) {
    [$pdo, $ids] = $ready(3);
    $rid = (int)Review::start($pdo, 'admin', 'admin')['id'];
    $walk($pdo, $rid, 1);
    $new = Catalog::insert($pdo, ['marketplace' => 'shopee', 'external_id' => '94099999999', 'title' => 'Chegou agora', 'price_promo_cents' => 100], 'aguardando_validacao', 't');
    eq(1, Review::stats($pdo, $rid)['novos']);
    eq([$ids[1], $ids[2]], $walk($pdo, $rid, 10), 'novo não entra sozinho');
    eq(1, Review::extend($pdo, $rid, 'admin'));
    eq($new, Review::nextId($pdo, $rid), 'incluído no fim');
    $r2 = Review::start($pdo, 'admin', 'admin');
    eq('encerrada', Db::value($pdo, 'SELECT status FROM gmax3_review_rounds WHERE id=?', [$rid]), 'anterior encerrada');
    eq(4, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_review_items WHERE round_id=?', [$rid]), 'histórico da anterior preservado');
    eq(4, (int)$r2['total_initial']);
    throws(fn() => $pdo->exec('DELETE FROM gmax3_review_visits'), 'somente-inclusão');
    throws(fn() => $pdo->exec('DELETE FROM gmax3_review_rounds'), 'não pode ser apagada');
});
test('Concorrência real: 6 processos iniciando rodada ao mesmo tempo → uma única rodada aberta; 6 registrando o mesmo produto → estado consistente', function () use ($ready) {
    [$pdo, $ids] = $ready(5);
    $script = t_sandbox() . '/rodada_filho.php';
    file_put_contents($script, '<?php define("GMAX_APP_DIR", ' . var_export(t_app(), true) . '); require GMAX_APP_DIR . "/gmax-lib/bootstrap.php";'
        . 'while (microtime(true) < (float)$argv[1]) { usleep(500); } $pdo = Gmax\Db::get();'
        . 'try { if ($argv[2] === "start") { echo Gmax\Review::start($pdo, "admin", "admin")["id"]; } else { echo Gmax\Review::record($pdo, (int)$argv[3], (int)$argv[4], "analisado", "teste", "admin"); } } catch (Throwable $e) { echo "ERRO ", $e->getMessage(); }');
    $run = static function (array $args) use ($script): array {
        $start = microtime(true) + 0.8;
        $procs = [];
        foreach (range(1, 6) as $i) {
            $procs[] = proc_open([PHP_BINARY, $script, (string)$start, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], t_app(), ['GMAX_SECRETS_DIR' => t_sandbox() . '/gmax-secrets', 'GMAX_TESTING' => '1', 'PATH' => getenv('PATH')]);
        }
        $o = [];
        foreach ($procs as $i => $p) {
            $o[] = stream_get_contents($pipes[$i + 1][1]) . stream_get_contents($pipes[$i + 1][2]);
            proc_close($p);
        }
        return $o;
    };
    $outs = $run(['start']);
    ok(!array_filter($outs, fn($o) => str_contains($o, 'ERRO')), implode(' | ', $outs));
    eq(1, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_review_rounds WHERE admin='admin' AND status='aberta'"), 'uma aberta');
    $rid = (int)Review::open($pdo, 'admin')['id'];
    $outs = $run(['record', (string)$rid, (string)$ids[0]]);
    ok(!array_filter($outs, fn($o) => str_contains($o, 'ERRO')), implode(' | ', $outs));
    eq('analisado', Review::item($pdo, $rid, $ids[0])['state']);
    eq(6, (int)Review::item($pdo, $rid, $ids[0])['visits'], 'visitas contadas sem perda');
    ok(Review::nextId($pdo, $rid) !== $ids[0], 'segue para o próximo');
});
test('Compatibilidade: sem a 007 a fila funciona; a 007 reverte sozinha, sem tocar em produtos', function () use ($ready) {
    [$pdo] = $ready(3, false);
    $cat = Db::all($pdo, 'SELECT * FROM gmax3_catalog ORDER BY id');
    Review::start($pdo, 'admin', 'admin');
    eq('007', Migrator::down($pdo, 'teste')['version']);
    ok(!Review::installed($pdo), 'tabelas removidas');
    eq(null, Review::open($pdo, 'admin'));
    ok(count(Funnel::workQueue($pdo)) > 0, 'fila de trabalho continua funcionando');
    eq($cat, Db::all($pdo, 'SELECT * FROM gmax3_catalog ORDER BY id'), 'catálogo intacto');
    Migrator::up($pdo, 'teste');
    ok(Review::installed($pdo), 'reaplicada');
});

$pass = count(array_filter($GLOBALS['t_results'], fn($r) => $r[1]));
$total = count($GLOBALS['t_results']);
echo "\nResultado 3.2.2.2: $pass/$total testes aprovados\n";
file_put_contents(__DIR__ . '/../docs/resultado-testes-v3222.json', json_encode(['executado_em' => date('c'), 'php' => PHP_VERSION, 'aprovados' => $pass, 'total' => $total,
    'testes' => array_map(fn($r) => ['nome' => $r[0], 'ok' => $r[1], 'erro' => $r[2], 'segundos' => round($r[3], 3)], $GLOBALS['t_results'])], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($pass === $total ? 0 : 1);

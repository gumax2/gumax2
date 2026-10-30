<?php
declare(strict_types=1);
/*
 * Detalhes das execuções (3.1.2): produtos analisados, motivos, consultas à API e preços.
 * Sem rede: conectores e HTTP falsos. Nenhuma mensagem é enviada.
 * Execução: php -d allow_url_fopen=0 tests/execucoes_test.php
 */
require __DIR__ . '/harness.php';
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';
require __DIR__ . '/fakes.php';

use Gmax\Automation\{Jobs, RunLog, Runner};
use Gmax\Connectors\{MercadoLivre, Registry};
use Gmax\{Catalog, Db, Migrator, Settings};

const T_TOKEN = 'APP_USR-1234567890123456-103012-abcdefabcdefabcdefabcdefabcdef12-99887766';

$ready = static function (): PDO {
    Registry::resetOverrides();
    $pdo = t_seed('hostgator'); // espelho do diagnóstico real: 5 Shopee pendentes + ML-001 só com meli.la
    Migrator::up($pdo, 'teste');
    Settings::set($pdo, 'api_intervalo_ms', '0', 'teste');
    return $pdo;
};
$items = static fn(PDO $pdo, int $run) => Db::all($pdo, 'SELECT * FROM gmax3_job_run_items WHERE run_id=? ORDER BY id', [$run]);
/** Dá ao ML-001 um anúncio MLB consultável (como se o administrador tivesse cadastrado a URL original). */
$withMlb = static function (PDO $pdo): int {
    $id = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE marketplace='mercadolivre'");
    Db::run($pdo, "UPDATE gmax3_catalog SET external_id='item:MLB1234567890', original_url='https://produto.mercadolivre.com.br/MLB-1234567890-parafusadeira-_JM' WHERE id=?", [$id]);
    return $id;
};
$mlHttp = static fn(int $status, array $body = []) => new MercadoLivre(new FakeHttp(['status' => $status, 'body' => json_encode($body), 'error' => null]), T_TOKEN);

echo "\n== Reprodução da execução #2 da produção\n";
test('Cenário real: 5 Shopee bloqueados por falta de credencial Shopee; ML-001 fora da revisão por não ter código MLB', function () use ($ready, $items) {
    $pdo = $ready();
    Registry::override('mercadolivre', new FakeMarketConnector('mercadolivre')); // OAuth conectado
    $r = Runner::runManual($pdo, 'revisao_precos', 'admin');
    eq([5, 0, 5, 0, 'concluido'], [$r['found'], $r['updated'], $r['blocked'], $r['errors'], $r['resultado']], 'mesmos números da produção');
    $rows = $items($pdo, $r['execucao']);
    $blocked = array_values(array_filter($rows, fn($i) => $i['outcome'] === 'bloqueado'));
    eq(5, count($blocked));
    foreach ($blocked as $b) {
        eq('shopee', $b['marketplace']);
        ok(preg_match('/^\d{3,20}$/', (string)$b['external_id']) === 1, 'Item Id Shopee registrado');
        ok(str_contains($b['reason'], 'Shopee') && str_contains($b['reason'], 'shopee-api.php ausente') && str_contains($b['reason'], 'Nenhuma consulta feita'), $b['reason']);
        ok($b['title'] !== '' && $b['price_before_cents'] !== null, 'nome e preço anterior');
        eq(null, $b['price_api_cents'], 'sem preço inventado');
        eq(null, $b['http_status'], 'nenhuma requisição feita');
    }
    $ml = array_values(array_filter($rows, fn($i) => $i['marketplace'] === 'mercadolivre'));
    eq(1, count($ml));
    eq('fora_da_revisao', $ml[0]['outcome']);
    ok(str_contains($ml[0]['reason'], 'meli.la') && str_contains($ml[0]['reason'], '/items/{id}'), $ml[0]['reason']);
    $detail = (string)Db::value($pdo, 'SELECT detail FROM gmax3_job_runs WHERE id=?', [$r['execucao']]);
    ok(str_contains($detail, 'Elegíveis: 5 · fora da revisão: 1') && str_contains($detail, 'Nenhum preço foi confirmado'), $detail);
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE price_verified_by LIKE 'api:%'"), 'nenhuma validação registrada');
});

echo "\n== Consulta individual /items/{id} do Mercado Livre\n";
test('HTTP 403 em /items: bloqueado com código, endpoint e motivo; preço e validação intocados; token não gravado', function () use ($ready, $items, $withMlb, $mlHttp) {
    $pdo = $ready();
    $id = $withMlb($pdo);
    $before = Catalog::get($pdo, $id);
    Registry::override('mercadolivre', $mlHttp(403, ['message' => 'forbidden', 'error' => 'forbidden', 'status' => 403]));
    $r = Runner::runManual($pdo, 'revisao_precos', 'admin');
    $i = array_values(array_filter($items($pdo, $r['execucao']), fn($i) => $i['catalog_id'] === $id))[0];
    eq(['bloqueado', 403, 'GET /items/MLB1234567890'], [$i['outcome'], $i['http_status'], $i['api_endpoint']]);
    ok(str_contains($i['reason'], 'falhou (HTTP 403)') && str_contains($i['reason'], 'preço não validado'), $i['reason']);
    ok(str_contains($i['api_error'], 'Acesso negado (HTTP 403)'), (string)$i['api_error']);
    eq(null, $i['price_api_cents']);
    ok($i['checked_at'] !== null, 'horário da consulta');
    $after = Catalog::get($pdo, $id);
    eq([$before['price_promo_cents'], $before['price_original_cents'], $before['price_verified_at']], [$after['price_promo_cents'], $after['price_original_cents'], $after['price_verified_at']]);
    eq('parcial', $r['resultado'], 'erro de API marca a execução como parcial');
    $dump = json_encode(Db::all($pdo, 'SELECT * FROM gmax3_job_run_items')) . json_encode(Db::all($pdo, 'SELECT * FROM gmax3_alerts')) . json_encode(Db::all($pdo, 'SELECT * FROM gmax3_job_runs'));
    ok(!str_contains($dump, T_TOKEN) && !str_contains($dump, 'abcdefabcdef'), 'token ausente do histórico, alertas e execuções');
});
test('HTTP 401 em /items: motivo de autenticação explícito', function () use ($ready, $items, $withMlb, $mlHttp) {
    $pdo = $ready();
    $id = $withMlb($pdo);
    Registry::override('mercadolivre', $mlHttp(401, ['message' => 'invalid access token']));
    $r = Runner::runManual($pdo, 'revisao_precos', 'admin');
    $i = array_values(array_filter($items($pdo, $r['execucao']), fn($i) => $i['catalog_id'] === $id))[0];
    eq([401, 'bloqueado'], [$i['http_status'], $i['outcome']]);
    ok(str_contains($i['api_error'], 'Não autenticado (HTTP 401)'), (string)$i['api_error']);
});
test('HTTP 200 com o mesmo preço: validado, com preço anterior, preço da API e horário', function () use ($ready, $items, $withMlb, $mlHttp) {
    $pdo = $ready();
    $id = $withMlb($pdo);
    Db::run($pdo, "UPDATE gmax3_catalog SET availability='disponivel' WHERE id=?", [$id]);
    Registry::override('mercadolivre', $mlHttp(200, ['id' => 'MLB1234567890', 'price' => 104.31, 'original_price' => 299.90, 'status' => 'active']));
    $r = Runner::runManual($pdo, 'revisao_precos', 'admin');
    $i = array_values(array_filter($items($pdo, $r['execucao']), fn($i) => $i['catalog_id'] === $id))[0];
    eq(['validado', 200, 10431, 10431, 29990, 29990, 'disponivel'], [$i['outcome'], $i['http_status'], $i['price_before_cents'], $i['price_api_cents'], $i['original_before_cents'], $i['original_api_cents'], $i['availability_api']]);
    ok(str_contains($i['reason'], 'preço confirmado pela API'), $i['reason']);
    eq('api:mercadolivre', Catalog::get($pdo, $id)['price_verified_by']);
});
test('HTTP 200 com preço novo: atualizado, campos alterados listados e aprovação retirada', function () use ($ready, $items, $withMlb, $mlHttp) {
    $pdo = $ready();
    $id = $withMlb($pdo);
    Registry::override('mercadolivre', $mlHttp(200, ['id' => 'MLB1234567890', 'price' => 119.90, 'status' => 'active']));
    $r = Runner::runManual($pdo, 'revisao_precos', 'admin');
    $i = array_values(array_filter($items($pdo, $r['execucao']), fn($i) => $i['catalog_id'] === $id))[0];
    eq(['atualizado', 10431, 11990, null], [$i['outcome'], $i['price_before_cents'], $i['price_api_cents'], $i['original_api_cents']]);
    ok(str_contains($i['reason'], 'preço Por') && str_contains($i['reason'], 'preço De'), $i['reason']);
    eq(11990, (int)Catalog::get($pdo, $id)['price_promo_cents']);
});
test('HTTP 200 sem preço: sem alteração e sem validação (nada inventado)', function () use ($ready, $items, $withMlb, $mlHttp) {
    $pdo = $ready();
    $id = $withMlb($pdo);
    Db::run($pdo, 'UPDATE gmax3_catalog SET price_original_cents=NULL WHERE id=?', [$id]);
    Registry::override('mercadolivre', $mlHttp(200, ['id' => 'MLB1234567890', 'status' => 'active']));
    Db::run($pdo, "UPDATE gmax3_catalog SET availability='disponivel' WHERE id=?", [$id]);
    $r = Runner::runManual($pdo, 'revisao_precos', 'admin');
    $i = array_values(array_filter($items($pdo, $r['execucao']), fn($i) => $i['catalog_id'] === $id))[0];
    eq(['sem_alteracao', null], [$i['outcome'], $i['price_api_cents']]);
    ok(str_contains($i['reason'], 'preço NÃO validado: a API não devolveu preço'), $i['reason']);
    eq(null, Catalog::get($pdo, $id)['price_verified_by']);
});
test('Teste isolado de /items: mostra HTTP e preço sem gravar nada no catálogo', function () use ($ready, $withMlb, $mlHttp) {
    $pdo = $ready();
    $id = $withMlb($pdo);
    $before = Catalog::get($pdo, $id);
    $p = $mlHttp(200, ['id' => 'MLB1234567890', 'price' => 99.0, 'status' => 'paused'])->probeItem('MLB-1234567890');
    eq([true, 200, 'GET /items/MLB1234567890', 9900, 'indisponivel'], [$p['ok'], $p['http'], $p['endpoint'], $p['preco'], $p['situacao']]);
    $p = $mlHttp(403, [])->probeItem('MLB1234567890');
    eq([false, 403], [$p['ok'], $p['http']]);
    ok(str_contains($p['detalhe'], 'HTTP 403'), $p['detalhe']);
    eq(false, $mlHttp(200, [])->probeItem('meli.la/abc')['ok'], 'formato inválido recusado sem requisição');
    eq($before, Catalog::get($pdo, $id), 'catálogo intacto');
});

echo "\n== Elegibilidade, limites e integridade\n";
test('Limite por execução: excedentes ficam como "não processado" com o motivo', function () use ($ready, $items) {
    $pdo = $ready();
    Db::run($pdo, "UPDATE gmax3_jobs SET params='{\"limite\":2}' WHERE job='revisao_precos'");
    Registry::override('shopee', new FakeMarketConnector('shopee', fetchMap: []));
    $r = Runner::runManual($pdo, 'revisao_precos', 'admin');
    $by = array_count_values(array_column($items($pdo, $r['execucao']), 'outcome'));
    eq(['bloqueado' => 2, 'nao_processado' => 3, 'fora_da_revisao' => 1], $by);
    ok(str_contains((string)Db::value($pdo, "SELECT reason FROM gmax3_job_run_items WHERE outcome='nao_processado' LIMIT 1"), 'Limite de 2 produtos'), 'motivo do limite');
    eq(2, $r['found']);
});
test('Rascunho e arquivado: fora da revisão com motivo; arquivados não poluem o histórico', function () use ($ready, $items) {
    $pdo = $ready();
    $ids = array_map('intval', $pdo->query("SELECT id FROM gmax3_catalog WHERE marketplace='shopee' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    Db::run($pdo, "UPDATE gmax3_catalog SET validation_status='rascunho' WHERE id=?", [$ids[0]]);
    Db::run($pdo, "UPDATE gmax3_catalog SET validation_status='arquivado' WHERE id=?", [$ids[1]]);
    $r = Runner::runManual($pdo, 'revisao_precos', 'admin');
    $rows = array_column($items($pdo, $r['execucao']), null, 'catalog_id');
    eq('fora_da_revisao', $rows[$ids[0]]['outcome']);
    ok(str_contains($rows[$ids[0]]['reason'], 'Estado "rascunho"'), $rows[$ids[0]]['reason']);
    ok(!isset($rows[$ids[1]]), 'arquivado não listado');
    eq(3, $r['found']);
});
test('Histórico somente-inclusão: alterar ou apagar linhas é recusado pelo banco', function () use ($ready) {
    $pdo = $ready();
    Runner::runManual($pdo, 'revisao_precos', 'admin');
    throws(fn() => $pdo->exec("UPDATE gmax3_job_run_items SET outcome='validado'"), 'somente-inclusão');
    throws(fn() => $pdo->exec('DELETE FROM gmax3_job_run_items'), 'somente-inclusão');
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_job_run_items WHERE outcome='validado'"));
});
test('Mensagens de erro passam por limpeza de credenciais', function () {
    $s = RunLog::sanitize('falhou Bearer ' . T_TOKEN . ' url?access_token=XYZ123&x=1 refresh_token=abc TG-abcdef123456 Signature=0123456789abcdef0123');
    ok(!str_contains($s, T_TOKEN) && !str_contains($s, 'XYZ123') && !str_contains($s, 'abcdef123456') && !str_contains($s, '0123456789abcdef0123'), $s);
    ok(str_contains($s, 'x=1'), 'texto comum preservado');
});
test('Sem a migração 003 a revisão funciona como na 3.1.1 (sem detalhamento)', function () use ($ready) {
    $pdo = $ready();
    eq('003', Migrator::down($pdo, 'teste')['version']);
    $r = Runner::runManual($pdo, 'revisao_precos', 'admin');
    eq([5, 5], [$r['found'], $r['blocked']]);
    ok(!RunLog::enabled($pdo), 'tabela ausente');
});
test('Execuções e dados anteriores preservados pela migração 003', function () {
    Registry::resetOverrides();
    $pdo = t_seed('hostgator');
    Migrator::up($pdo, 'teste');
    Migrator::down($pdo, 'teste'); // volta para 002
    Runner::runManual($pdo, 'revisao_precos', 'admin');
    $runs = Db::all($pdo, 'SELECT * FROM gmax3_job_runs');
    $catalog = Db::all($pdo, 'SELECT * FROM gmax3_catalog');
    Migrator::up($pdo, 'teste');
    eq($runs, Db::all($pdo, 'SELECT * FROM gmax3_job_runs'), 'execuções antigas intactas');
    eq($catalog, Db::all($pdo, 'SELECT * FROM gmax3_catalog'), 'catálogo intacto');
    eq(0, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_job_run_items'), 'nada é reconstruído para execuções antigas');
});
test('Prévia da próxima revisão usa as mesmas regras de elegibilidade', function () use ($ready) {
    $pdo = $ready();
    $plan = array_map(fn($r) => Jobs::reviewExclusion($r, false), Jobs::reviewCandidates($pdo));
    eq(5, count(array_filter($plan, fn($w) => $w === null)));
    eq(1, count(array_filter($plan, fn($w) => $w !== null && str_contains($w, 'meli.la'))));
});

$pass = count(array_filter($GLOBALS['t_results'], fn($r) => $r[1]));
$total = count($GLOBALS['t_results']);
echo "\nResultado execuções: $pass/$total testes aprovados\n";
file_put_contents(__DIR__ . '/../docs/resultado-testes-execucoes.json', json_encode([
    'executado_em' => date('c'), 'php' => PHP_VERSION, 'aprovados' => $pass, 'total' => $total,
    'testes' => array_map(fn($r) => ['nome' => $r[0], 'ok' => $r[1], 'erro' => $r[2], 'segundos' => round($r[3], 3)], $GLOBALS['t_results']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($pass === $total ? 0 : 1);

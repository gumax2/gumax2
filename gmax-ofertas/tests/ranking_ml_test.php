<?php
declare(strict_types=1);
/*
 * 3.1.7: Ranking inteligente (potencial × prontas para divulgar) e diagnóstico/suspensão do Mercado Livre.
 * Sem rede: HTTP falso que responde por endpoint (com e sem token).
 * Execução: php -d allow_url_fopen=0 tests/ranking_ml_test.php
 */
require __DIR__ . '/harness.php';
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';
require __DIR__ . '/fakes.php';

use Gmax\Automation\Runner;
use Gmax\Connectors\{EndpointGuard, MercadoLivre, Registry};
use Gmax\{Catalog, Db, Message, Migrator, Pricing, Ranking, Settings};

const T_ML_TOKEN = 'APP_USR-9988776655443322-110512-ffffeeeeddddccccbbbbaaaa99998888-1234';

/** HTTP falso por endpoint: [regex do caminho, com token?] => [status, corpo]. Conta requisições. */
final class MapHttp extends Gmax\Http
{
    public array $calls = [];

    public function __construct(private array $map)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array
    {
        $auth = (bool)array_filter($headers, static fn($h) => str_starts_with($h, 'Authorization: Bearer '));
        $path = substr($url, strlen(MercadoLivre::API));
        $this->calls[] = [$path, $auth];
        foreach ($this->map as [$re, $needAuth, $status, $resp]) {
            if (preg_match($re, $path) && ($needAuth === null || $needAuth === $auth)) {
                return ['status' => $status, 'body' => is_string($resp) ? $resp : json_encode($resp), 'error' => null];
            }
        }
        return ['status' => 404, 'body' => '{"message":"not found"}', 'error' => null];
    }
}

$pa = ['code' => 'PA_UNAUTHORIZED_RESULT_FROM_POLICIES', 'blocked_by' => 'PolicyAgent', 'status' => 403, 'message' => 'At least one policy returned UNAUTHORIZED.'];
$ready = static function (): PDO {
    Registry::resetOverrides();
    $pdo = t_seed('hostgator');
    Migrator::up($pdo, 'teste');
    Settings::set($pdo, 'api_intervalo_ms', '0', 'teste');
    Settings::set($pdo, 'publicador_v21_aposentado', '1', 'teste'); // sem isso a fila antiga bloqueia todos (regra de duplicidade)
    return $pdo;
};
/** Produto Shopee 100% pronto para divulgar. */
$makeReady = static function (PDO $pdo, string $item): int {
    $id = (int)Db::value($pdo, 'SELECT id FROM gmax3_catalog WHERE external_id=?', [$item]);
    Db::run($pdo, "UPDATE gmax3_catalog SET payment_condition='normal', availability='disponivel', price_original_cents=NULL, sales_min=5000, rating=4.8, rating_count=900 WHERE id=?", [$id]);
    Catalog::markVerifiedByApi($pdo, $id, 'shopee', 'teste');
    eq([], Catalog::setStatus($pdo, $id, 'aprovado', 'teste'));
    return $id;
};
$ids = static fn(array $res) => array_map(static fn($r) => (int)$r['item']['id'], $res['rows']);

echo "\n== Ranking: dois modos\n";
test('Potencial: mantém a pontuação existente e lista produtos não validados com suas pendências', function () use ($ready) {
    $pdo = $ready();
    $res = Ranking::board($pdo, ['modo' => 'potencial']);
    eq(6, $res['total']);
    foreach ($res['rows'] as $row) {
        $e = Ranking::evaluate($row['item'], null, [], Ranking::DEFAULT_PRICE_BAND, 24);
        eq([$e['score'], $e['confidence']], [$row['score'], $row['confidence']], 'pontuação preservada');
        ok(!$row['pronto'] && $row['pendencias'] !== [], 'não validado mostra pendências');
    }
    eq(0, $res['prontas']);
});
test('Prontas: só aparece o produto que passa em todas as regras de publicação', function () use ($ready, $makeReady, $ids) {
    $pdo = $ready();
    $ok = $makeReady($pdo, '22812345602');
    $res = Ranking::board($pdo, ['modo' => 'prontas']);
    eq([[$ok], 1], [$ids($res), $res['prontas']]);
    eq([], $res['rows'][0]['pendencias']);
    eq([], array_values(array_filter(Gmax\Rules::blockers($pdo, $res['rows'][0]['item'], 'telegram_privado'))), 'mesmas regras da fila');
});
test('Prontas exclui: preço desatualizado, sem link, variação pendente, indisponível, "De" não confirmado e ML no grupo privado', function () use ($ready, $makeReady, $ids) {
    $pdo = $ready();
    $a = $makeReady($pdo, '22812345601');
    $b = $makeReady($pdo, '22812345602');
    $c = $makeReady($pdo, '22812345603');
    $d = $makeReady($pdo, '22812345604');
    $e = $makeReady($pdo, '22812345605');
    Db::run($pdo, "UPDATE gmax3_catalog SET price_verified_at='2026-01-01 00:00:00' WHERE id=?", [$a]);
    Db::run($pdo, "UPDATE gmax3_catalog SET affiliate_url=NULL, affiliate_norm=NULL WHERE id=?", [$b]);
    Db::run($pdo, 'UPDATE gmax3_catalog SET variation_pending=1 WHERE id=?', [$c]);
    Db::run($pdo, "UPDATE gmax3_catalog SET availability='indisponivel' WHERE id=?", [$d]);
    Db::run($pdo, 'UPDATE gmax3_catalog SET price_original_cents=99990 WHERE id=?', [$e]); // De sem confirmação (fonte api_shopee não informa De)
    $ml = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE marketplace='mercadolivre'");
    Db::run($pdo, "UPDATE gmax3_catalog SET payment_condition='pix', availability='disponivel', validation_status='aprovado', price_verified_at=datetime('now'), price_verified_by='admin', price_source='conferencia_manual' WHERE id=?", [$ml]);
    eq([], $ids(Ranking::board($pdo, ['modo' => 'prontas'])));
    $p = array_column(array_map(static fn($r) => [$r['item']['id'], implode(' | ', $r['pendencias'])], Ranking::board($pdo, ['modo' => 'potencial'])['rows']), 1, 0);
    ok(str_contains($p[$a], 'reconfira'), $p[$a]);
    ok(str_contains($p[$b], 'Link afiliado'), $p[$b]);
    ok(str_contains($p[$c], 'Variação'), $p[$c]);
    ok(str_contains($p[$d], 'indisponível'), $p[$d]);
    ok(str_contains($p[$e], 'Preço original (De) não confirmado'), $p[$e]);
    ok(str_contains($p[$ml], 'Mercado Livre'), $p[$ml]);
});
test('Preço original só estimado: nunca vira "De", não pontua desconto e não aparece na mensagem', function () use ($ready, $makeReady, $ids) {
    $pdo = $ready();
    $id = $makeReady($pdo, '22812345602');
    $before = Ranking::board($pdo, ['modo' => 'potencial'])['rows'];
    Catalog::manualCheck($pdo, $id, ['confirmo' => true, 'modo' => 'desconto', 'price_promo' => '39,90', 'desconto_pct' => '50', 'payment_condition' => 'normal', 'availability' => 'disponivel'], 'admin');
    Catalog::setStatus($pdo, $id, 'aprovado', 'teste');
    $row = array_values(array_filter(Ranking::board($pdo, ['modo' => 'prontas'])['rows'], static fn($r) => (int)$r['item']['id'] === $id))[0] ?? null;
    ok($row !== null, 'pronto (sem De, com Por conferido manualmente)');
    eq(null, $row['item']['price_original_cents']);
    eq(null, array_values(array_filter($row['components'], static fn($c) => $c['criterio'] === 'desconto'))[0]['valor'], 'desconto sem dado');
    ok(!str_contains(implode(' ', $row['destaques']), 'OFF'), 'sem desconto nos destaques');
    ok(!str_contains(Message::render($row['item'], $pdo), '79,80'), 'estimativa fora da mensagem');
    ok(Pricing::latestEstimate($pdo, $row['item']) !== null, 'estimativa registrada à parte');
});

echo "\n== Ranking: filtros, ordenação e paginação\n";
test('Filtros combinados: marketplace, conferência API/manual/nunca, link, preço, pontuação, confiança, dias, pronto', function () use ($ready, $makeReady, $ids) {
    $pdo = $ready();
    $a = $makeReady($pdo, '22812345601');
    $ml = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE marketplace='mercadolivre'");
    eq(5, Ranking::board($pdo, ['marketplace' => 'shopee'])['total']);
    eq([$a], $ids(Ranking::board($pdo, ['marketplace' => 'shopee', 'conferido' => 'api', 'link' => 'com'])));
    eq(5, Ranking::board($pdo, ['conferido' => 'nunca'])['total']);
    eq([$ml], $ids(Ranking::board($pdo, ['preco_min' => '100,00'])));
    eq(2, Ranking::board($pdo, ['preco_min' => '30', 'preco_max' => '50,00'])['total']);
    eq([$a], $ids(Ranking::board($pdo, ['dias' => '1'])));
    eq([$a], $ids(Ranking::board($pdo, ['pronto' => 'sim'])));
    eq(5, Ranking::board($pdo, ['pronto' => 'nao'])['total']);
    $hi = Ranking::board($pdo, ['score_min' => '40', 'conf_min' => '50']);
    foreach ($hi['rows'] as $r) {
        ok($r['score'] >= 40 && $r['confidence'] >= 50, 'limites mínimos');
    }
    eq(0, Ranking::board($pdo, ['marketplace' => 'mercadolivre', 'conferido' => 'api'])['total']);
    eq(6, Ranking::board($pdo, ['marketplace' => '<script>', 'conferido' => 'x', 'preco_min' => 'abc'])['total'], 'valores inválidos ignorados');
});
test('Ordenação por pontuação, confiança, preço e data; paginação de 25', function () use ($ready, $makeReady) {
    $pdo = $ready();
    $makeReady($pdo, '22812345603');
    $prices = array_map(static fn($r) => (int)$r['item']['price_promo_cents'], Ranking::board($pdo, ['ordem' => 'preco'])['rows']);
    $sorted = $prices;
    sort($sorted);
    eq($sorted, $prices, 'menor preço primeiro');
    $scores = array_map(static fn($r) => $r['score'] ?? -1, Ranking::board($pdo, [])['rows']);
    $s2 = $scores;
    rsort($s2);
    eq($s2, $scores);
    eq('22812345603', Ranking::board($pdo, ['ordem' => 'data'])['rows'][0]['item']['external_id'], 'conferido mais recente primeiro');
    for ($i = 0; $i < 30; $i++) {
        Catalog::insert($pdo, ['marketplace' => 'shopee', 'external_id' => (string)(33000000000 + $i), 'title' => "Produto $i", 'price_promo_cents' => 1000 + $i], 'rascunho', 't');
    }
    $p1 = Ranking::board($pdo, []);
    $p2 = Ranking::board($pdo, ['page' => '2']);
    eq([36, 2, 25, 11, 25], [$p1['total'], $p1['pages'], count($p1['rows']), count($p2['rows']), $p2['offset']]);
    eq([], array_intersect(array_map(static fn($r) => $r['item']['id'], $p1['rows']), array_map(static fn($r) => $r['item']['id'], $p2['rows'])), 'páginas sem repetição');
});

echo "\n== Mercado Livre: diagnóstico\n";
test('Diagnóstico: 403 com e sem token = restrição da plataforma; token válido; alternativas mapeadas; sem token no resultado', function () use ($pa) {
    $h = new MapHttp([
        ['~^/sites/MLB$~', false, 200, ['id' => 'MLB']], ['~^/users/me$~', true, 200, ['id' => 123456789]],
        ['~^/sites/MLB/search~', null, 403, $pa], ['~^/items\?ids=~', true, 200, [['code' => 403, 'body' => $pa]]],
        ['~^/items/MLB7519481690/sale_price~', true, 403, $pa], ['~^/items/MLB7519481690$~', null, 403, $pa],
        ['~^/products/search~', true, 200, ['results' => [['id' => 'MLB19615283']]]], ['~^/products/MLB19615283$~', true, 200, ['id' => 'MLB19615283']],
        ['~^/sites/MLB/domain_discovery~', true, 200, [['category_id' => 'MLB1055']]], ['~^/users/123456789/items/search~', true, 200, ['results' => []]],
    ]);
    $d = (new MercadoLivre($h, T_ML_TOKEN))->diagnose('MLB-7519481690');
    $by = array_column($d['testes'], null, 'id');
    eq([200, 200, 403, 403, 403, 403, 200, 200, 200], [$by['site']['http'], $by['conta']['http'], $by['busca']['http'], $by['busca_publica']['http'], $by['item']['http'], $by['item_publico']['http'], $by['catalogo_busca']['http'], $by['catalogo_produto']['http'], $by['dominio']['http']]);
    eq('Bloqueado por política (PolicyAgent)', $by['busca']['resultado']);
    ok(str_contains((string)$by['busca']['mensagem_api'], 'PA_UNAUTHORIZED_RESULT_FROM_POLICIES'), (string)$by['busca']['mensagem_api']);
    eq([false, 'código do item no lote: 403'], [$by['item_lote']['ok'], $by['item_lote']['codigo_api']]);
    $txt = implode(' | ', array_column($d['conclusoes'], 'texto'));
    ok(str_contains($txt, 'token é válido') && str_contains($txt, 'pesquisa (/sites/MLB/search) é negada com e sem token') && str_contains($txt, 'anúncio de terceiros (/items/{id}) é negada com e sem token'), $txt);
    ok(str_contains($txt, 'Alternativa oficial disponível: Pesquisa no catálogo') && str_contains($txt, 'Produto do catálogo'), $txt);
    ok(!str_contains(json_encode($d), T_ML_TOKEN) && !str_contains(json_encode($d), 'ffffeeee'), 'nenhum token no diagnóstico');
    foreach ($h->calls as [$p, $auth]) {
        ok(!str_contains($p, 'access_token') && !str_contains($p, T_ML_TOKEN), 'token nunca na URL');
    }
    ok(in_array(['/sites/MLB/search?q=fone&limit=1', false], $h->calls, true), 'par sem token executado');
});
test('Diagnóstico distingue falta de autorização (sem token 200), token rejeitado (401), anúncio inexistente (404) e limite (429)', function () use ($pa) {
    $h = new MapHttp([['~^/sites/MLB$~', false, 200, []], ['~^/users/me$~', true, 200, ['id' => 1]], ['~^/sites/MLB/search~', true, 403, $pa], ['~^/sites/MLB/search~', false, 200, ['results' => []]],
        ['~^/items\?ids=~', true, 200, [['code' => 404, 'body' => ['message' => 'Item not found']]]], ['~^/items/~', null, 404, ['message' => 'Item with id MLB1 not found', 'error' => 'not_found']]]);
    $d = (new MercadoLivre($h, T_ML_TOKEN))->diagnose('MLB12345678');
    $txt = implode(' | ', array_column($d['conclusoes'], 'texto'));
    ok(str_contains($txt, 'funciona sem token mas é negada com o token'), $txt);
    ok(str_contains($txt, 'não existe ou foi removido'), $txt);
    eq('autorizacao', array_column($d['testes'], null, 'id')['busca']['tipo']);
    $k = MercadoLivre::classify(['status' => 401, 'error' => null], true, null);
    eq(['configuracao', 'Token rejeitado'], [$k['tipo'], $k['resultado']]);
    eq('Limite de requisições', MercadoLivre::classify(['status' => 429, 'error' => null], true, null)['resultado']);
    eq('implementacao', MercadoLivre::classify(['status' => 0, 'error' => 'timeout'], true, null)['tipo']);
    $none = (new MercadoLivre(new MapHttp([]), null, null))->diagnose('MLB1');
    ok($none['testes'] === [] || $none['conclusoes'] !== [], 'sem token: nenhuma requisição ou conclusão de configuração');
});

echo "\n== Mercado Livre: suspensão automática de 403 repetido\n";
test('Três 403 seguidos suspendem /items: sem novas requisições, um único alerta, OAuth preservado; reativação manual', function () use ($ready, $pa) {
    $pdo = $ready();
    $h = new MapHttp([['~^/items/~', true, 403, $pa]]);
    $c = new MercadoLivre($h, T_ML_TOKEN, null, $pdo);
    $row = ['external_id' => 'item:MLB7519481690'];
    for ($i = 0; $i < 3; $i++) {
        $r = $c->fetch($row);
        eq([false, 403], [$r['ok'], $r['http']]);
    }
    ok(str_contains((string)$r['error'], 'PA_UNAUTHORIZED'), (string)$r['error']);
    eq(3, count($h->calls));
    $r = $c->fetch($row);
    eq([false, true, 3], [$r['ok'], $r['suspended'], count($h->calls)], 'suspenso: nenhuma requisição nova');
    ok(str_contains($r['error'], 'Reative em Integrações'), $r['error']);
    eq(1, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_alerts WHERE kind='integracao' AND message LIKE '%suspensa após 3%'"));
    ok(EndpointGuard::suspended($pdo, 'items') !== null && EndpointGuard::suspended($pdo, 'search') === null, 'só o grupo afetado');
    $d = $c->diagnose('MLB7519481690');
    ok(count($h->calls) > 4, 'diagnóstico supervisionado consulta mesmo suspenso');
    eq(3, EndpointGuard::state($pdo)['items']['count'], 'diagnóstico não altera a suspensão');
    ok(EndpointGuard::reactivate($pdo, 'items', 'admin'), 'reativado');
    eq(null, EndpointGuard::suspended($pdo, 'items'));
    eq(1, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_audit WHERE action='ml_endpoint_reativado'"));
    ok(!str_contains(json_encode(Db::all($pdo, 'SELECT * FROM gmax3_audit')) . json_encode(Db::all($pdo, 'SELECT * FROM gmax3_alerts')) . json_encode(Db::all($pdo, 'SELECT * FROM gmax3_settings')), T_ML_TOKEN), 'token fora do banco');
});
test('200 zera a contagem; 404 e 429 não contam; desativar a suspensão impede suspender', function () use ($ready, $pa) {
    $pdo = $ready();
    $seq = [403, 403, 200, 403, 404, 429, 403];
    foreach ($seq as $st) {
        EndpointGuard::record($pdo, 'items', $st, null);
    }
    eq(2, EndpointGuard::state($pdo)['items']['count']);
    eq(null, EndpointGuard::suspended($pdo, 'items'));
    Settings::set($pdo, 'ml_suspensao_403', '0', 'admin');
    for ($i = 0; $i < 10; $i++) {
        EndpointGuard::record($pdo, 'search', 403, null);
    }
    eq(null, EndpointGuard::suspended($pdo, 'search'));
    throws(fn() => Settings::setFromWeb($pdo, 'ml_403_limite', '1', 'admin'), 'inválido');
    Settings::setFromWeb($pdo, 'ml_403_limite', '5', 'admin');
    eq(5, EndpointGuard::threshold($pdo));
});
test('Automações com consultas suspensas: revisão sem erros repetidos e descoberta com uma linha, sem alerta por termo', function () use ($ready, $pa) {
    $pdo = $ready();
    $ml = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE marketplace='mercadolivre'");
    Db::run($pdo, "UPDATE gmax3_catalog SET external_id='item:MLB7519481690' WHERE id=?", [$ml]);
    Settings::set($pdo, 'termos_descoberta_mercadolivre', "fone\nmouse\ngarrafa\nkit", 'admin');
    $h = new MapHttp([['~^/items/~', true, 403, $pa], ['~^/sites/MLB/search~', true, 403, $pa]]);
    Registry::override('mercadolivre', $connML = new MercadoLivre($h, T_ML_TOKEN, null, $pdo));
    Registry::override('shopee', new FakeMarketConnector('shopee', ok: false));
    for ($i = 0; $i < 3; $i++) {
        EndpointGuard::record($pdo, 'items', 403, 'PA');
        EndpointGuard::record($pdo, 'search', 403, 'PA');
    }
    $alerts = (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_alerts');
    $r = Runner::runManual($pdo, 'revisao_precos', 'admin');
    eq(0, $r['errors'], 'nenhum erro repetido');
    $it = Db::one($pdo, 'SELECT * FROM gmax3_job_run_items WHERE run_id=? AND catalog_id=?', [$r['execucao'], $ml]);
    ok($it['outcome'] === 'bloqueado' && str_contains($it['reason'], 'conferência manual'), $it['reason']);
    $d = Runner::runManual($pdo, 'descoberta', 'admin');
    eq(0, $d['errors']);
    eq(1, substr_count((string)Db::value($pdo, 'SELECT detail FROM gmax3_job_runs WHERE id=?', [$d['execucao']]), 'Consulta suspensa'));
    eq(0, count($h->calls), 'nenhuma requisição ao Mercado Livre');
    eq($alerts, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_alerts'), 'nenhum alerta novo');
});

$pass = count(array_filter($GLOBALS['t_results'], fn($r) => $r[1]));
$total = count($GLOBALS['t_results']);
echo "\nResultado ranking/ML: $pass/$total testes aprovados\n";
file_put_contents(__DIR__ . '/../docs/resultado-testes-ranking-ml.json', json_encode([
    'executado_em' => date('c'), 'php' => PHP_VERSION, 'aprovados' => $pass, 'total' => $total,
    'testes' => array_map(fn($r) => ['nome' => $r[0], 'ok' => $r[1], 'erro' => $r[2], 'segundos' => round($r[3], 3)], $GLOBALS['t_results']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($pass === $total ? 0 : 1);

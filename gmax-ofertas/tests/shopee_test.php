<?php
declare(strict_types=1);
/*
 * Shopee Affiliate Open API (3.1.4): formato da consulta, assinatura, erros GraphQL e revisão.
 * Sem rede: respostas simuladas no formato da API (HTTP 200 + "errors" com extensions.code).
 * Execução: php -d allow_url_fopen=0 tests/shopee_test.php
 */
require __DIR__ . '/harness.php';
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';
require __DIR__ . '/fakes.php';

use Gmax\Automation\Runner;
use Gmax\Connectors\{Registry, ShopeeAffiliate};
use Gmax\{Catalog, Db, Migrator, Settings};

const T_APP_ID = '18812345678';
const T_SECRET = 'SEGREDO-SHOPEE-DE-TESTE-0123456789';

/** HTTP falso com fila de respostas; guarda cada requisição para inspeção. */
final class ShopeeQueueHttp extends Gmax\Http
{
    public array $requests = [];

    public function __construct(private array $queue)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        $r = array_shift($this->queue) ?? ['status' => 500, 'body' => '', 'error' => null];
        return ['status' => $r['status'] ?? 200, 'body' => is_array($r['body'] ?? null) ? json_encode($r['body']) : (string)($r['body'] ?? ''), 'error' => $r['error'] ?? null];
    }
}

$gqlError = static fn(int $code, string $msg) => ['status' => 200, 'body' => ['errors' => [['message' => $msg, 'extensions' => ['code' => $code, 'message' => $msg]]], 'data' => null]];
$node = static fn(string $item, string $min, string $max, string $shop = '111112') => ['status' => 200, 'body' => ['data' => ['productOfferV2' => ['nodes' => [[
    'itemId' => (int)$item, 'shopId' => (int)$shop, 'productName' => 'Garrafa Térmica Teste B 1L', 'priceMin' => $min, 'priceMax' => $max,
    'commissionRate' => '0.07', 'offerLink' => 'https://s.shopee.com.br/BbbBbb2', 'shopName' => 'Loja Teste 2',
]]]]]];
$conn = static fn(array $queue) => [$h = new ShopeeQueueHttp($queue), new ShopeeAffiliate($h, ['app_id' => T_APP_ID, 'secret' => T_SECRET])];
$row = ['external_id' => '22812345602', 'original_url' => 'https://shopee.com.br/product/111112/22812345602'];
$ready = static function (): PDO {
    Registry::resetOverrides();
    $pdo = t_seed('hostgator');
    Migrator::up($pdo, 'teste');
    Settings::set($pdo, 'api_intervalo_ms', '0', 'teste');
    return $pdo;
};

echo "\n== Formato da requisição e assinatura\n";
test('Int64 vai como literal só com dígitos; shopId vem do link do produto; JSON sem "variables" vazio', function () use ($conn, $node, $row) {
    [$h, $c] = $conn([$node('22812345602', '39.90', '39.90')]);
    $r = $c->fetch($row);
    ok($r['ok'], (string)$r['error']);
    $body = json_decode($h->requests[0]['body'], true);
    eq(['query'], array_keys($body), 'sem variables');
    ok(str_contains($body['query'], 'productOfferV2(itemId: 22812345602, shopId: 111112, limit: 1)'), $body['query']);
    ok(!str_contains($body['query'], '$itemId'), 'sem variável Int64 numérica (causa do 10010 "wrong type")');
    foreach (['itemId', 'shopId', 'productName', 'priceMin', 'priceMax', 'commissionRate', 'offerLink', 'shopName'] as $f) {
        ok(str_contains($body['query'], $f), "campo $f");
    }
    eq(['literal', 'POST /graphql', 200, null], [$r['formato'], $r['endpoint'], $r['http'], $r['gql_code']]);
    eq('https://open-api.affiliate.shopee.com.br/graphql', $h->requests[0]['url']);
});
test('Assinatura SHA256(AppId+Timestamp+Payload+Secret) sobre o corpo exato enviado', function () use ($conn, $node, $row) {
    [$h, $c] = $conn([$node('22812345602', '39.90', '39.90')]);
    $c->fetch($row);
    $auth = '';
    foreach ($h->requests[0]['headers'] as $hd) {
        if (str_starts_with($hd, 'Authorization: ')) {
            $auth = substr($hd, 15);
        }
    }
    ok(preg_match('/^SHA256 Credential=(\d+), Timestamp=(\d{10}), Signature=([0-9a-f]{64})$/D', $auth, $m) === 1, $auth);
    eq(T_APP_ID, $m[1]);
    eq(hash('sha256', T_APP_ID . $m[2] . $h->requests[0]['body'] . T_SECRET), $m[3], 'assinatura confere');
    ok(abs((int)$m[2] - time()) < 5, 'timestamp em segundos (Unix)');
    ok(in_array('Content-Type: application/json', $h->requests[0]['headers'], true), 'Content-Type JSON');
    ok(!str_contains($h->requests[0]['body'], T_SECRET), 'Secret não vai no corpo');
});
test('Item Id de 18 dígitos não perde precisão; sem link de produto não envia shopId', function () use ($conn) {
    [$h, $c] = $conn([['status' => 200, 'body' => ['data' => ['productOfferV2' => ['nodes' => []]]]]]);
    $c->fetch(['external_id' => '123456789012345678', 'original_url' => null]);
    $q = json_decode($h->requests[0]['body'], true)['query'];
    ok(str_contains($q, 'itemId: 123456789012345678, limit: 1') && !str_contains($q, 'shopId:'), $q);
});

echo "\n== Respostas da API\n";
test('Resposta válida: preço, comissão e link oficial; nada é gravado pelo conector', function () use ($conn, $node, $row) {
    [, $c] = $conn([$node('22812345602', '39.90', '39.90')]);
    $r = $c->fetch($row);
    eq([3990, '0.07', 'https://s.shopee.com.br/BbbBbb2'], [$r['data']['price_promo_cents'], $r['data']['commission_rate'], $r['offer_link']]);
});
test('Faixa de preço entre variações: sem preço único (nada confirmado)', function () use ($conn, $node, $row) {
    [, $c] = $conn([$node('22812345602', '39.90', '59.90')]);
    $r = $c->fetch($row);
    ok($r['ok'] && !isset($r['data']['price_promo_cents']) && !empty($r['data']['variation_pending']), json_encode($r['data']));
});
test('10010 no literal: tenta uma vez com variáveis Int64 em texto; sucesso registra o formato', function () use ($conn, $gqlError, $node, $row) {
    [$h, $c] = $conn([$gqlError(10010, 'wrong type'), $node('22812345602', '39.90', '39.90')]);
    $r = $c->fetch($row);
    ok($r['ok'], (string)$r['error']);
    eq('variaveis_texto', $r['formato']);
    $b = json_decode($h->requests[1]['body'], true);
    eq(['itemId' => '22812345602', 'shopId' => '111112'], $b['variables'], 'Int64 como string no JSON');
    ok(str_contains($b['query'], 'query($itemId: Int64, $shopId: Int64)'), $b['query']);
});
test('10010 nos dois formatos: falha com código, significado e mensagem; HTTP 200 não é sucesso', function () use ($conn, $gqlError, $row) {
    [$h, $c] = $conn([$gqlError(10010, 'wrong type'), $gqlError(10010, 'wrong type')]);
    $r = $c->fetch($row);
    eq([false, 200, 10010], [$r['ok'], $r['http'], $r['gql_code']]);
    ok(str_contains($r['error'], 'GraphQL 10010 (erro de análise da consulta (Parse Error)') && str_contains($r['error'], '"wrong type"'), $r['error']);
    eq([], $r['data'], 'nenhum dado');
    eq(2, count($h->requests), 'no máximo uma nova tentativa');
});
foreach ([10020 => 'falha de autenticação', 10030 => 'limite de requisições', 10035 => 'sem permissão de acesso'] as $code => $label) {
    test("Erro $code: mensagem explicada e sem nova tentativa", function () use ($conn, $gqlError, $row, $code, $label) {
        [$h, $c] = $conn([$gqlError($code, 'msg da api ' . $code)]);
        $r = $c->fetch($row);
        eq([false, $code, 1], [$r['ok'], $r['gql_code'], count($h->requests)]);
        ok(str_contains($r['error'], "GraphQL $code ($label") && str_contains($r['error'], 'msg da api'), $r['error']);
    });
}
test('Produto inexistente (nodes vazio ou outro item): não encontrado, sem preço', function () use ($conn, $node, $row) {
    [, $c] = $conn([['status' => 200, 'body' => ['data' => ['productOfferV2' => ['nodes' => []]]]]]);
    $r = $c->fetch($row);
    ok(!$r['ok'] && str_contains($r['error'], 'Produto não encontrado na API (itemId 22812345602, shopId 111112)'), (string)$r['error']);
    [, $c] = $conn([$node('99999999999', '10.00', '10.00')]);
    ok(!$c->fetch($row)['ok'], 'item diferente do pedido é recusado');
});
test('Respostas malformadas com HTTP 200: sem data, JSON inválido, código desconhecido', function () use ($conn, $row) {
    [, $c] = $conn([['status' => 200, 'body' => '{}']]);
    ok(str_contains((string)$c->fetch($row)['error'], 'sem "data"'), 'sem data');
    [, $c] = $conn([['status' => 200, 'body' => '<html>']]);
    ok(str_contains((string)$c->fetch($row)['error'], 'não é JSON'), 'html');
    [, $c] = $conn([['status' => 200, 'body' => ['errors' => [['message' => "linha\nnova"]]]]]);
    $e = (string)$c->fetch($row)['error'];
    ok(str_contains($e, 'GraphQL ?') && !str_contains($e, "\n"), $e);
    [, $c] = $conn([['status' => 502, 'body' => 'bad gateway']]);
    eq([false, 502], [($r = $c->fetch($row))['ok'], $r['http']]);
});

echo "\n== Revisão de preços e testes do painel\n";
test('Revisão com 10010: bloqueado, erro contado, código no histórico, preço não confirmado, catálogo intacto', function () use ($ready, $conn, $gqlError) {
    $pdo = $ready();
    [, $c] = $conn(array_fill(0, 10, $gqlError(10010, 'wrong type')));
    Registry::override('shopee', $c);
    $before = Db::all($pdo, "SELECT * FROM gmax3_catalog WHERE marketplace='shopee' ORDER BY id");
    $r = Runner::runManual($pdo, 'revisao_precos', 'admin');
    eq([5, 5, 5, 'parcial'], [$r['found'], $r['blocked'], $r['errors'], $r['resultado']], 'mesmos números da execução #3');
    $items = Db::all($pdo, "SELECT * FROM gmax3_job_run_items WHERE run_id=? AND marketplace='shopee'", [$r['execucao']]);
    foreach ($items as $i) {
        eq(['bloqueado', 200, 'POST /graphql', null], [$i['outcome'], (int)$i['http_status'], $i['api_endpoint'], $i['price_api_cents']]);
        ok(str_contains($i['reason'], 'HTTP 200, GraphQL 10010') && str_contains($i['api_error'], '"wrong type"'), $i['reason'] . ' | ' . $i['api_error']);
    }
    eq($before, Db::all($pdo, "SELECT * FROM gmax3_catalog WHERE marketplace='shopee' ORDER BY id"), 'produtos, links e preços intactos');
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE price_verified_by='api:shopee'"));
    eq(0, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_price_history WHERE verified=1'));
    $dump = json_encode(Db::all($pdo, 'SELECT * FROM gmax3_job_run_items')) . json_encode(Db::all($pdo, 'SELECT * FROM gmax3_alerts')) . json_encode(Db::all($pdo, 'SELECT * FROM gmax3_audit'));
    ok(!str_contains($dump, T_SECRET) && !str_contains($dump, 'Signature='), 'sem segredo nem assinatura no banco');
});
test('Revisão após a correção: preço igual validado; link afiliado preservado', function () use ($ready, $conn, $node) {
    $pdo = $ready();
    $id = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE external_id='22812345602'");
    $link = Catalog::get($pdo, $id)['affiliate_url'];
    $queue = [];
    foreach (Db::all($pdo, "SELECT external_id, price_promo_cents FROM gmax3_catalog WHERE marketplace='shopee' ORDER BY id") as $p) {
        $v = number_format((int)$p['price_promo_cents'] / 100, 2, '.', '');
        $queue[] = $node($p['external_id'], $v, $v);
    }
    [, $c] = $conn($queue);
    Registry::override('shopee', $c);
    $r = Runner::runManual($pdo, 'revisao_precos', 'admin');
    eq(0, $r['errors']);
    eq(5, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_job_run_items WHERE run_id=? AND marketplace='shopee' AND outcome IN ('validado','atualizado')", [$r['execucao']]));
    eq('api:shopee', Catalog::get($pdo, $id)['price_verified_by']);
    eq($link, Catalog::get($pdo, $id)['affiliate_url'], 'link afiliado não é trocado pela revisão');
});
test('Testar conexão e consulta individual: não alteram o catálogo e não expõem segredos', function () use ($ready, $conn, $node, $gqlError) {
    $pdo = $ready();
    $snap = json_encode(Db::all($pdo, 'SELECT * FROM gmax3_catalog')) . json_encode(Db::all($pdo, 'SELECT * FROM gmax3_price_history'));
    [$h, $c] = $conn([['status' => 200, 'body' => ['data' => ['productOfferV2' => ['nodes' => [['itemId' => 1, 'productName' => 'x']]]]]], $node('22812345602', '39.90', '39.90'), $gqlError(10020, 'Invalid Signature')]);
    $p = $c->probe();
    eq([true, 200], [$p['ok'], $p['http']]);
    ok(str_contains($p['credenciais'], 'terminado em 5678') && !str_contains(json_encode($p), T_APP_ID) && !str_contains(json_encode($p), T_SECRET), json_encode($p));
    $i = $c->probeItem('22812345602', '111112');
    eq([true, 3990, '0.07', true, 'literal'], [$i['ok'], $i['preco'], $i['comissao'], $i['link_oficial'], $i['formato']]);
    $p = $c->probe();
    eq([false, 10020], [$p['ok'], $p['gql_code']]);
    ok(str_contains($p['detalhe'], 'falha de autenticação'), $p['detalhe']);
    eq(false, $c->probeItem('abc')['ok'], 'Item Id inválido recusado sem requisição');
    eq(3, count($h->requests));
    eq($snap, json_encode(Db::all($pdo, 'SELECT * FROM gmax3_catalog')) . json_encode(Db::all($pdo, 'SELECT * FROM gmax3_price_history')), 'catálogo e histórico de preços intactos');
    $sem = (new ShopeeAffiliate(new ShopeeQueueHttp([]), null))->probe();
    ok(!$sem['ok'] && str_contains($sem['detalhe'], 'nenhuma requisição'), $sem['detalhe']);
});
test('Busca (descoberta) também trata "errors" com HTTP 200 como falha', function () use ($conn, $gqlError) {
    [, $c] = $conn([$gqlError(10035, 'no permission')]);
    $r = $c->search('fone', 5);
    ok(!$r['ok'] && str_contains($r['error'], 'GraphQL 10035') && $r['items'] === [], (string)$r['error']);
});

$pass = count(array_filter($GLOBALS['t_results'], fn($r) => $r[1]));
$total = count($GLOBALS['t_results']);
echo "\nResultado Shopee: $pass/$total testes aprovados\n";
file_put_contents(__DIR__ . '/../docs/resultado-testes-shopee.json', json_encode([
    'executado_em' => date('c'), 'php' => PHP_VERSION, 'aprovados' => $pass, 'total' => $total,
    'testes' => array_map(fn($r) => ['nome' => $r[0], 'ok' => $r[1], 'erro' => $r[2], 'segundos' => round($r[3], 3)], $GLOBALS['t_results']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($pass === $total ? 0 : 1);

<?php
declare(strict_types=1);
/*
 * 3.1.9: candidatos do catálogo do Mercado Livre (/products/search, /products/{id}, tendências).
 * Respostas simuladas no formato documentado (buy_box_winner etc.). Sem rede.
 * Execução: php -d allow_url_fopen=0 tests/candidatos_ml_test.php
 */
require __DIR__ . '/harness.php';
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';
require __DIR__ . '/fakes.php';

use Gmax\Automation\Runner;
use Gmax\Connectors\{EndpointGuard, MercadoLivre, Registry};
use Gmax\{Catalog, Db, Message, Migrator, MlCandidates, Ranking, Settings, View};

const T_TOKEN = 'APP_USR-5566778899001122-110612-0123456789abcdef0123456789abcdef-77';
$pa = ['code' => 'PA_UNAUTHORIZED_RESULT_FROM_POLICIES', 'blocked_by' => 'PolicyAgent', 'status' => 403, 'message' => 'At least one policy returned UNAUTHORIZED.'];
$search = ['keywords' => 'fone', 'paging' => ['total' => 2], 'results' => [
    ['id' => 'MLB19615283', 'status' => 'active', 'domain_id' => 'MLB-HEADPHONES', 'name' => 'Fone de Ouvido Bluetooth X1', 'pictures' => [['id' => 'p1', 'url' => 'https://http2.mlstatic.com/D_NQ_NP_1.jpg']], 'attributes' => []],
    ['id' => 'MLB20000001', 'status' => 'active', 'domain_id' => 'MLB-HEADPHONES', 'name' => 'Fone Sem Fio Y2 <script>', 'pictures' => [['url' => 'https://evil.example/x.jpg']]],
    ['id' => '123', 'name' => 'ID inválido'], ['id' => 'MLB30000001', 'name' => ''],
]];
$product = ['id' => 'MLB19615283', 'name' => 'Fone de Ouvido Bluetooth X1', 'permalink' => 'https://www.mercadolivre.com.br/fone-de-ouvido-bluetooth-x1/p/MLB19615283',
    'domain_id' => 'MLB-HEADPHONES', 'settings' => ['category_id' => 'MLB1055'], 'pictures' => [['url' => 'https://http2.mlstatic.com/D_NQ_NP_1.jpg']],
    'buy_box_winner' => ['item_id' => 'MLB4455667788', 'price' => 129.9, 'original_price' => 199.9, 'currency_id' => 'BRL', 'shipping' => ['free_shipping' => true], 'official_store_id' => 123]];
$noWinner = ['id' => 'MLB20000001', 'name' => 'Fone Sem Fio Y2', 'buy_box_winner' => null, 'permalink' => 'https://evil.example/p/MLB20000001'];
$map = static fn(array $extra = []) => new MapHttp(array_merge($extra, [
    ['~^/products/search~', true, 200, $search], ['~^/products/MLB19615283$~', true, 200, $product], ['~^/products/MLB20000001$~', true, 200, $noWinner],
    ['~^/trends/MLB$~', true, 200, [['keyword' => 'air fryer', 'url' => 'https://lista.mercadolivre.com.br/air-fryer'], ['keyword' => 'fone bluetooth', 'url' => 'x']]],
    ['~^/categories/MLB1055$~', true, 200, ['id' => 'MLB1055', 'name' => 'Celulares e Smartphones']],
    ['~^/sites/MLB/domain_discovery~', true, 200, [['category_id' => 'MLB1055']]], ['~^/trends/MLB/MLB1055$~', true, 200, [['keyword' => 'fone']]],
]));
$ready = static function () {
    Registry::resetOverrides();
    $pdo = t_seed('hostgator');
    Migrator::up($pdo, 'teste');
    Settings::set($pdo, 'api_intervalo_ms', '0', 'teste');
    Settings::set($pdo, 'termos_descoberta_mercadolivre', 'fone', 'teste');
    return $pdo;
};

echo "\n== Leitura do catálogo e tendências\n";
test('Pesquisa no catálogo: só IDs de catálogo válidos com nome; imagem só do CDN oficial', function () use ($map) {
    $r = (new MercadoLivre($map(), T_TOKEN))->catalogSearch('fone');
    eq([true, 2], [$r['ok'], count($r['items'])]);
    eq(['MLB19615283', 'https://http2.mlstatic.com/D_NQ_NP_1.jpg'], [$r['items'][0]['product_id'], $r['items'][0]['picture_url']]);
    eq(null, $r['items'][1]['picture_url'], 'imagem de outro domínio descartada');
});
test('Produto de catálogo: anúncio vencedor e preço só como informados pela API; IDs separados; permalink validado', function () use ($map) {
    $c = new MercadoLivre($map(), T_TOKEN);
    $d = $c->catalogProduct('MLB19615283')['data'];
    eq(['item:MLB4455667788', 12990, 19990, 1, 1, 'MLB1055'], [$d['bb_item_id'], $d['bb_price_cents'], $d['bb_original_cents'], $d['bb_free_shipping'], $d['bb_official_store'], $d['category_id']]);
    ok(str_contains((string)$d['permalink'], '/p/MLB19615283'), 'permalink oficial do catálogo');
    $n = $c->catalogProduct('MLB20000001')['data'];
    eq([null, null, null], [$n['bb_item_id'], $n['bb_price_cents'], $n['permalink']], 'sem vencedor: sem preço; permalink de outro domínio descartado');
    eq(false, $c->catalogProduct('item:MLB1')['ok'], 'ID de anúncio não é aceito como ID de catálogo');
    eq(['air fryer', 'fone bluetooth'], $c->trends()['keywords']);
    eq('Celulares e Smartphones', $c->categoryName('MLB1055'));
});
test('Inspeção de campos: estrutura e indicadores sem valores nem token', function () use ($map) {
    $i = (new MercadoLivre($map(), T_TOKEN))->inspectFields('fone', 'MLB7519481690');
    eq([true, true, false, true, true, true, true], array_values(array_intersect_key($i['indicadores'], array_flip(['busca_por_palavra', 'busca_tem_imagem', 'busca_tem_preco', 'produto_tem_vencedor', 'vencedor_tem_preco', 'vencedor_tem_anuncio', 'produto_tem_permalink']))));
    ok(in_array('buy_box_winner.price (double)', $i['recursos']['catalogo_produto']['campos'], true), implode(', ', $i['recursos']['catalogo_produto']['campos']));
    $json = json_encode($i);
    ok(!str_contains($json, '129.9') && !str_contains($json, 'Fone de Ouvido') && !str_contains($json, T_TOKEN), 'sem valores nem token');
});

echo "\n== Descoberta de candidatos (tarefa candidatos_ml)\n";
test('Tarefa: grava candidatos separados das ofertas; detalhe com preço da API não conferido; nada no catálogo', function () use ($ready, $map) {
    $pdo = $ready();
    Registry::override('mercadolivre', new MercadoLivre($map(), T_TOKEN, null, $pdo));
    $catalog = Db::all($pdo, 'SELECT * FROM gmax3_catalog');
    $r = Runner::runManual($pdo, 'candidatos_ml', 'admin');
    eq('concluido', $r['resultado']);
    eq(2, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_ml_candidates'));
    $c = Db::one($pdo, "SELECT * FROM gmax3_ml_candidates WHERE product_id='MLB19615283'");
    eq([12990, 'item:MLB4455667788', 'Celulares e Smartphones', 'termo', 'novo'], [(int)$c['bb_price_cents'], $c['bb_item_id'], $c['category_name'], $c['origem'], $c['status']]);
    eq($catalog, Db::all($pdo, 'SELECT * FROM gmax3_catalog'), 'catálogo de ofertas intacto');
    eq(0, Ranking::board($pdo, ['modo' => 'prontas'])['total'], 'candidatos não entram nas prontas');
    ok(str_contains((string)Db::value($pdo, 'SELECT detail FROM gmax3_job_runs WHERE id=?', [$r['execucao']]), 'Nenhuma oferta foi criada'), 'resumo');
    Runner::runManual($pdo, 'candidatos_ml', 'admin');
    eq(2, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_ml_candidates'), 'sem duplicar candidatos');
});
test('Tendências também geram candidatos (origem e posição registradas)', function () use ($ready, $map) {
    $pdo = $ready();
    Settings::set($pdo, 'termos_descoberta_mercadolivre', '', 'teste');
    Registry::override('mercadolivre', new MercadoLivre($map(), T_TOKEN, null, $pdo));
    Runner::runManual($pdo, 'candidatos_ml', 'admin');
    eq(['tendencia', 1, 'air fryer'], array_values(Db::one($pdo, "SELECT origem, trend_pos, termo FROM gmax3_ml_candidates WHERE product_id='MLB19615283'")));
});
test('Catálogo bloqueado (403 repetido): suspende /products sem repetir consultas nem desconectar o OAuth', function () use ($ready, $pa) {
    $pdo = $ready();
    Settings::set($pdo, 'termos_descoberta_mercadolivre', "a\nb\nc\nd\ne", 'teste');
    $h = new MapHttp([['~^/products/~', true, 403, $pa], ['~^/trends/MLB$~', true, 200, []]]);
    Registry::override('mercadolivre', new MercadoLivre($h, T_TOKEN, null, $pdo));
    Runner::runManual($pdo, 'candidatos_ml', 'admin');
    eq(3, count(array_filter($h->calls, fn($c) => str_starts_with($c[0], '/products'))), 'para após 3 respostas 403');
    ok(EndpointGuard::suspended($pdo, 'products') !== null, 'grupo suspenso');
    $n = count($h->calls);
    Runner::runManual($pdo, 'candidatos_ml', 'admin');
    eq(0, count(array_filter(array_slice($h->calls, $n), fn($c) => str_starts_with($c[0], '/products'))), 'nenhuma consulta repetida');
    eq(0, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_ml_candidates'));
});

echo "\n== Rascunho a partir do candidato\n";
test('Criar rascunho: ID de catálogo (não de anúncio), sem link afiliado, preço NÃO conferido, fora das prontas', function () use ($ready, $map) {
    $pdo = $ready();
    Registry::override('mercadolivre', new MercadoLivre($map(), T_TOKEN, null, $pdo));
    Runner::runManual($pdo, 'candidatos_ml', 'admin');
    $cid = (int)Db::value($pdo, "SELECT id FROM gmax3_ml_candidates WHERE product_id='MLB19615283'");
    $id = MlCandidates::createDraft($pdo, $cid, 'admin');
    $p = Catalog::get($pdo, $id);
    eq(['rascunho', 'catalogo:MLB19615283', null, 12990, null, null, 'api_ml_catalogo'], [$p['validation_status'], $p['external_id'], $p['affiliate_url'], (int)$p['price_promo_cents'], $p['price_original_cents'], $p['price_verified_at'], $p['price_source']]);
    ok(str_contains((string)$p['notes'], 'NÃO conferido') && str_contains((string)$p['notes'], 'MLB4455667788'), (string)$p['notes']);
    ok(!str_contains(View::storeButtons($p), 'btn-affiliate'), 'sem link afiliado inventado');
    $row = array_values(array_filter(Ranking::board($pdo, [])['rows'], fn($r) => (int)$r['item']['id'] === $id))[0];
    ok(!$row['pronto'] && str_contains(implode(' ', $row['pendencias']), 'Link afiliado'), 'pendências exigem link oficial e conferência');
    ok(!str_contains(Message::render($p, $pdo), '199,90'), 'preço original da API não vira "De"');
    throws(fn() => MlCandidates::createDraft($pdo, $cid, 'admin'), 'já foi tratado');
    eq('rascunho_criado', Db::value($pdo, 'SELECT status FROM gmax3_ml_candidates WHERE id=?', [$cid]));
});
test('Descartar e filtros da lista', function () use ($ready, $map) {
    $pdo = $ready();
    Registry::override('mercadolivre', new MercadoLivre($map(), T_TOKEN, null, $pdo));
    Runner::runManual($pdo, 'candidatos_ml', 'admin');
    eq(['MLB19615283'], array_column(MlCandidates::list($pdo, ['preco' => 'com']), 'product_id'));
    eq(['MLB20000001'], array_column(MlCandidates::list($pdo, ['preco' => 'sem']), 'product_id'));
    $cid = (int)Db::value($pdo, "SELECT id FROM gmax3_ml_candidates WHERE product_id='MLB20000001'");
    ok(MlCandidates::discard($pdo, $cid, 'admin'), 'descartado');
    eq(1, count(MlCandidates::list($pdo, [])));
    eq(1, count(MlCandidates::list($pdo, ['status' => 'descartado'])));
    eq(0, count(MlCandidates::list($pdo, ['q' => '%_'])), 'curingas escapados');
});
test('Sem a migração 004 a tarefa apenas avisa; a 004 é reversível sem tocar no restante', function () use ($ready) {
    $pdo = $ready();
    $catalog = json_encode(Db::all($pdo, 'SELECT * FROM gmax3_catalog'));
    eq('005', Migrator::down($pdo, 'teste')['version'], 'desfaz a 005 (3.2.1)');
    eq('004', Migrator::down($pdo, 'teste')['version']);
    $r = Runner::runManual($pdo, 'candidatos_ml', 'admin');
    ok(str_contains((string)Db::value($pdo, 'SELECT detail FROM gmax3_job_runs WHERE id=?', [$r['execucao']]), 'migração 004'), 'aviso');
    eq($catalog, json_encode(Db::all($pdo, 'SELECT * FROM gmax3_catalog')));
    eq('0', (string)Db::value($pdo, "SELECT enabled FROM gmax3_jobs WHERE job='candidatos_ml'"), 'tarefa inativa');
});

$pass = count(array_filter($GLOBALS['t_results'], fn($r) => $r[1]));
$total = count($GLOBALS['t_results']);
echo "\nResultado candidatos ML: $pass/$total testes aprovados\n";
file_put_contents(__DIR__ . '/../docs/resultado-testes-candidatos-ml.json', json_encode([
    'executado_em' => date('c'), 'php' => PHP_VERSION, 'aprovados' => $pass, 'total' => $total,
    'testes' => array_map(fn($r) => ['nome' => $r[0], 'ok' => $r[1], 'erro' => $r[2], 'segundos' => round($r[3], 3)], $GLOBALS['t_results']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($pass === $total ? 0 : 1);

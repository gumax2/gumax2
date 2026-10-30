<?php
declare(strict_types=1);
/*
 * Associação de links afiliados em lote (3.0.3). Sem rede: verificação de destino usa HTTP falso.
 * Execução: php -d allow_url_fopen=0 tests/links_test.php
 */
require __DIR__ . '/harness.php';
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';
require __DIR__ . '/fakes.php';

use Gmax\{Catalog, Db, Importer, LinkImporter, LinkVerifier, Migrator, Settings};

$F = __DIR__ . '/fixtures';
$CSV18 = "$F/GMAX_18_Links_Recebidos_Para_Associacao.csv";

/** Estado real: migrado + 25 rascunhos ML importados da planilha. */
$ready = static function () use ($F): PDO {
    $pdo = t_seed('hostgator');
    Migrator::up($pdo, 'teste');
    $id = Importer::preview($pdo, "$F/ml-25-anuncios.xlsx", 'ml-25.xlsx', 'teste');
    Importer::apply($pdo, $id, 'teste');
    return $pdo;
};
$csv = static function (array $lines): string {
    $f = tempnam(sys_get_temp_dir(), 'gml') . '.csv';
    file_put_contents($f, "\xEF\xBB\xBFranking;id_anuncio;produto;url_afiliado;status_validacao\n" . implode("\n", $lines) . "\n");
    return $f;
};
/** Tudo exceto link e metadados de link/atualização. */
$protected = static function (PDO $pdo): string {
    $cols = 'id,marketplace,codigo,external_id,title,category,store_name,original_url,price_original_cents,price_promo_cents,payment_condition,payment_note,price_source,price_seen_at,price_verified_at,sales_public,sales_min,rating,rating_count,seller_reputation,availability,commission_rate,variation_pending,notes,validation_status';
    return hash('sha256', json_encode($pdo->query("SELECT $cols FROM gmax3_catalog ORDER BY id")->fetchAll()));
};
$items = static fn(PDO $pdo, int $b) => array_column(Db::all($pdo, 'SELECT * FROM gmax3_import_items WHERE batch_id=? ORDER BY line', [$b]), null, 'line');

echo "\n== Associação dos 18 links\n";
test('Prévia do arquivo de 18 links: 18 links novos por ID do anúncio, nada gravado ainda', function () use ($ready, $CSV18) {
    $pdo = $ready();
    $b = LinkImporter::preview($pdo, $CSV18, basename($CSV18), 'admin');
    $batch = LinkImporter::batch($pdo, $b);
    eq(18, (int)$batch['total']);
    eq(18, (int)$batch['inserted'], 'links novos');
    eq(0, (int)$batch['rejected']);
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE marketplace='mercadolivre' AND affiliate_url LIKE 'https://meli.la/Gx%'"), 'prévia não grava');
});
test('Aplicar: 18 links associados; preços, condição, disponibilidade e estado intactos; nada criado ou aprovado', function () use ($ready, $CSV18, $protected) {
    $pdo = $ready();
    $before = $protected($pdo);
    $count = (int)$pdo->query('SELECT COUNT(*) FROM gmax3_catalog')->fetchColumn();
    $shopee = hash('sha256', json_encode($pdo->query("SELECT * FROM gmax3_catalog WHERE marketplace='shopee' ORDER BY id")->fetchAll()));
    $legacy = t_legacy_snapshot_safe($pdo);
    $b = LinkImporter::preview($pdo, $CSV18, basename($CSV18), 'admin');
    $r = LinkImporter::apply($pdo, $b, false, 'admin', true);
    eq(18, $r['aplicados']);
    ok(str_contains($r['backup'], 'antes-links-lote'), 'backup antes de aplicar');
    eq($before, $protected($pdo), 'somente o link mudou');
    eq($count, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_catalog')->fetchColumn(), 'nenhum produto criado');
    eq(25, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE marketplace='mercadolivre' AND validation_status='rascunho'"), 'continuam rascunhos');
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE validation_status='aprovado'"), 'nenhuma aprovação');
    eq($shopee, hash('sha256', json_encode($pdo->query("SELECT * FROM gmax3_catalog WHERE marketplace='shopee' ORDER BY id")->fetchAll())), 'Shopee intacta');
    eq($legacy, t_legacy_snapshot_safe($pdo), 'tabelas antigas intactas');
    eq('https://meli.la/Gx01Tst', Db::value($pdo, "SELECT affiliate_url FROM gmax3_catalog WHERE external_id='item:MLB4047815501'"), 'link no produto certo pelo MLB');
    eq('https://meli.la/Gx18Tst', Db::value($pdo, "SELECT affiliate_url FROM gmax3_catalog WHERE external_id='item:MLB5281931488'"), 'linha 18 no produto do ranking 18');
    eq(0, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_pub_queue')->fetchColumn(), 'nada na fila');
    eq('1', Settings::get($pdo, 'publicacao_pausada'));
});
test('Associação independe da ordem das linhas (arquivo embaralhado dá o mesmo resultado)', function () use ($ready, $CSV18, $csv) {
    $pdo = $ready();
    $lines = array_slice(file($CSV18, FILE_IGNORE_NEW_LINES), 1);
    $lines = array_reverse($lines);
    $f = $csv($lines);
    LinkImporter::apply($pdo, LinkImporter::preview($pdo, $f, 'x.csv', 't'), false, 't', true);
    eq('https://meli.la/Gx01Tst', Db::value($pdo, "SELECT affiliate_url FROM gmax3_catalog WHERE external_id='item:MLB4047815501'"));
    eq('https://meli.la/Gx07Tst', Db::value($pdo, "SELECT affiliate_url FROM gmax3_catalog WHERE external_id='item:MLB6632626272'"));
});
test('Reimportação do mesmo arquivo: 18 "já iguais", nenhuma alteração', function () use ($ready, $CSV18) {
    $pdo = $ready();
    LinkImporter::apply($pdo, LinkImporter::preview($pdo, $CSV18, 'a.csv', 't'), false, 't', true);
    $snap = hash('sha256', json_encode($pdo->query('SELECT * FROM gmax3_catalog ORDER BY id')->fetchAll()));
    $b = LinkImporter::preview($pdo, $CSV18, 'a.csv', 't');
    $batch = LinkImporter::batch($pdo, $b);
    eq(18, (int)$batch['unchanged']);
    eq(0, (int)$batch['inserted'] + (int)$batch['updated']);
    LinkImporter::apply($pdo, $b, true, 't', true);
    eq($snap, hash('sha256', json_encode($pdo->query('SELECT * FROM gmax3_catalog ORDER BY id')->fetchAll())), 'banco igual');
});

echo "\n== Erros, duplicidades e divergências\n";
test('Links inválidos são recusados (http, link comum, outro domínio, javascript, vazio)', function () use ($ready, $csv, $items) {
    $pdo = $ready();
    $b = LinkImporter::preview($pdo, $csv([
        '1;MLB-4047815501;Mouse;http://meli.la/Abc1234;OK',
        '2;MLB-5381436588;Mouse preto;https://produto.mercadolivre.com.br/MLB-5381436588-x-_JM;OK',
        '3;MLB-3156552171;Kit;https://s.shopee.com.br/Abc1234;OK',
        '4;MLB-4162581707;Escova;javascript:alert(1);OK',
        '5;MLB-3199424993;Garrafa;;OK',
        '6;MLB-4308562565;Copo;https://meli.la.golpe.com/Abc1234;OK',
    ]), 'x.csv', 't');
    foreach ($items($pdo, $b) as $line => $it) {
        eq('rejeitado', $it['action'], "linha $line");
    }
    eq(0, LinkImporter::apply($pdo, $b, true, 't', true)['aplicados']);
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE marketplace='mercadolivre' AND affiliate_url IS NOT NULL AND codigo IS NULL"));
});
test('ID inexistente não cria produto; ID inválido é recusado; Shopee nunca é alterada', function () use ($ready, $csv, $items) {
    $pdo = $ready();
    $n = (int)$pdo->query('SELECT COUNT(*) FROM gmax3_catalog')->fetchColumn();
    $f = tempnam(sys_get_temp_dir(), 'gml') . '.csv';
    file_put_contents($f, "id_anuncio;url_afiliado;marketplace\nMLB-9999999999;https://meli.la/Nao0001;mercadolivre\nabc;https://meli.la/Nao0002;\n22812345602;https://meli.la/Nao0003;shopee\n");
    $b = LinkImporter::preview($pdo, $f, 'x.csv', 't');
    $it = $items($pdo, $b);
    ok(str_contains((string)$it[2]['errors_json'], 'não está cadastrado'), 'inexistente');
    ok(str_contains((string)$it[3]['errors_json'], 'ID do anúncio inválido'), 'inválido');
    ok(str_contains((string)$it[4]['errors_json'], 'Shopee'), 'shopee recusada');
    LinkImporter::apply($pdo, $b, true, 't', true);
    eq($n, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_catalog')->fetchColumn(), 'nenhum produto criado');
});
test('Duplicidades no arquivo: mesmo link em 2 anúncios e mesmo anúncio 2 vezes → todas as ocorrências bloqueadas', function () use ($ready, $csv, $items) {
    $pdo = $ready();
    $b = LinkImporter::preview($pdo, $csv([
        '1;MLB-4047815501;Mouse Bluetooth;https://meli.la/Dup0001;OK',
        '2;MLB-5381436588;Mouse Bluetooth preto;https://meli.la/Dup0001;OK',
        '3;MLB-3156552171;Kit organizador;https://meli.la/Dup0003;OK',
        '4;MLB-3156552171;Kit organizador;https://meli.la/Dup0004;OK',
        '5;MLB-4162581707;Escova secadora;https://meli.la/Ok00005;OK',
    ]), 'x.csv', 't');
    $it = $items($pdo, $b);
    foreach ([2, 3, 4, 5] as $l) {
        eq('rejeitado', $it[$l]['action'], "linha $l");
    }
    eq('novo_link', $it[6]['action'], 'linha válida segue');
});
test('Link já associado a outro produto (ex.: ML-001) é bloqueado', function () use ($ready, $csv, $items) {
    $pdo = $ready();
    $b = LinkImporter::preview($pdo, $csv(['1;MLB-4047815501;Mouse Bluetooth;https://meli.la/1uPYV76;OK']), 'x.csv', 't');
    ok(str_contains((string)$items($pdo, $b)[2]['errors_json'], 'outro produto'), 'bloqueado');
});
test('Nome sem nenhuma palavra em comum com o cadastrado é bloqueado (possível produto trocado)', function () use ($ready, $csv, $items) {
    $pdo = $ready();
    $b = LinkImporter::preview($pdo, $csv([
        '1;MLB-4047815501;Panela de pressão elétrica inox;https://meli.la/Nom0001;OK',
        '2;MLB-5381436588;Mouse sem fio preto gamer;https://meli.la/Nom0002;OK',
    ]), 'x.csv', 't');
    $it = $items($pdo, $b);
    eq('rejeitado', $it[2]['action']);
    ok(str_contains((string)$it[2]['errors_json'], 'possível produto diferente'), 'motivo');
    eq('novo_link', $it[3]['action'], 'nome parecido segue');
});
test('Linha marcada como inválida na planilha não é aplicada', function () use ($ready, $csv, $items) {
    $pdo = $ready();
    $b = LinkImporter::preview($pdo, $csv(['1;MLB-4047815501;Mouse Bluetooth;https://meli.la/Inv0001;Inválido — não corresponde']), 'x.csv', 't');
    eq('rejeitado', $items($pdo, $b)[2]['action']);
});
test('Substituir link existente exige autorização explícita; produto aprovado volta para validação', function () use ($ready, $csv, $items) {
    $pdo = $ready();
    LinkImporter::apply($pdo, LinkImporter::preview($pdo, $csv(['1;MLB-4047815501;Mouse Bluetooth;https://meli.la/Old0001;OK']), 'a.csv', 't'), false, 't', true);
    $id = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE external_id='item:MLB4047815501'");
    Db::run($pdo, "UPDATE gmax3_catalog SET validation_status='aprovado' WHERE id=?", [$id]);
    $b = LinkImporter::preview($pdo, $csv(['1;MLB-4047815501;Mouse Bluetooth;https://meli.la/New0001;OK']), 'b.csv', 't');
    eq('alteracao', $items($pdo, $b)[2]['action']);
    $r = LinkImporter::apply($pdo, $b, false, 't', true);
    eq(0, $r['substituidos'], 'sem autorização não substitui');
    eq('https://meli.la/Old0001', Db::value($pdo, 'SELECT affiliate_url FROM gmax3_catalog WHERE id=?', [$id]));
    $b = LinkImporter::preview($pdo, $csv(['1;MLB-4047815501;Mouse Bluetooth;https://meli.la/New0001;OK']), 'c.csv', 't');
    eq(1, LinkImporter::apply($pdo, $b, true, 't', true)['substituidos']);
    eq('https://meli.la/New0001', Db::value($pdo, 'SELECT affiliate_url FROM gmax3_catalog WHERE id=?', [$id]));
    eq('aguardando_validacao', Db::value($pdo, 'SELECT validation_status FROM gmax3_catalog WHERE id=?', [$id]), 'aprovação retirada');
});

echo "\n== Verificação do destino (o formato não basta)\n";
test('Verificação: confere, diverge (bloqueia), 404 (bloqueia) e indeterminado (rede)', function () use ($ready, $csv, $items) {
    $pdo = $ready();
    $b = LinkImporter::preview($pdo, $csv([
        '1;MLB-4047815501;Mouse Bluetooth;https://meli.la/Ver0001;OK',
        '2;MLB-5381436588;Mouse Bluetooth preto;https://meli.la/Ver0002;OK',
        '3;MLB-3156552171;Kit organizador;https://meli.la/Ver0003;OK',
        '4;MLB-4162581707;Escova secadora;https://meli.la/Ver0004;OK',
    ]), 'x.csv', 't');
    $http = new FakeProbeHttp([
        'https://meli.la/Ver0001' => ['status' => 301, 'location' => 'https://produto.mercadolivre.com.br/MLB-4047815501-mouse-_JM?matt_tool=123', 'error' => null],
        'https://meli.la/Ver0002' => ['status' => 302, 'location' => 'https://www.mercadolivre.com.br/social/x?url=https%3A%2F%2Fproduto.mercadolivre.com.br%2FMLB-1111111111-outro-_JM', 'error' => null],
        'https://meli.la/Ver0003' => ['status' => 404, 'location' => null, 'error' => null],
    ]);
    $r = LinkImporter::verify($pdo, $b, new LinkVerifier($http), 't');
    eq(['confere' => 1, 'diverge' => 2, 'indeterminado' => 1], $r);
    $it = $items($pdo, $b);
    eq('novo_link', $it[2]['action'], 'confere segue');
    eq('rejeitado', $it[3]['action'], 'outro anúncio bloqueado');
    eq('rejeitado', $it[4]['action'], '404 bloqueado');
    eq('novo_link', $it[5]['action'], 'indeterminado segue, marcado');
    eq(2, LinkImporter::apply($pdo, $b, false, 't', true)['aplicados'], 'confere + indeterminado aplicados');
    ok(!in_array('https://evil', $http->calls, true), 'só domínios do Mercado Livre');
});
test('Verificador não segue redirecionamento para domínio estranho', function () {
    $v = new LinkVerifier(new FakeProbeHttp(['https://meli.la/Abc1234' => ['status' => 302, 'location' => 'https://phishing.example.com/MLB-4047815501', 'error' => null]]));
    eq(LinkVerifier::INDETERMINADO, $v->check('https://meli.la/Abc1234', 'item:MLB4047815501')['resultado']);
});

test('Sem verificação "confere" e sem confirmação manual, nenhum link é associado', function () use ($ready, $CSV18, $csv, $items) {
    $pdo = $ready();
    $b = LinkImporter::preview($pdo, $CSV18, 'a.csv', 't');
    $r = LinkImporter::apply($pdo, $b, false, 't');
    eq(0, $r['aplicados']);
    eq(18, count($r['ignorados']));
    ok(str_contains($r['ignorados'][0], 'não verificado'), 'motivo');
    $b = LinkImporter::preview($pdo, $csv(['1;MLB-4047815501;Mouse Bluetooth;https://meli.la/Ver0001;OK', '2;MLB-5381436588;Mouse Bluetooth preto;https://meli.la/Ver0002;OK']), 'v.csv', 't');
    LinkImporter::verify($pdo, $b, new LinkVerifier(new FakeProbeHttp(['https://meli.la/Ver0001' => ['status' => 301, 'location' => 'https://produto.mercadolivre.com.br/MLB-4047815501-x-_JM', 'error' => null]])), 't');
    eq(1, LinkImporter::apply($pdo, $b, false, 't')['aplicados'], 'só o verificado "confere" entra sem confirmação');
});
test('Concorrência: aplicar duas vezes, aplicar após descartar e verificar após aplicar são recusados', function () use ($ready, $CSV18) {
    $pdo = $ready();
    $b = LinkImporter::preview($pdo, $CSV18, 'a.csv', 't');
    LinkImporter::apply($pdo, $b, false, 't', true);
    throws(fn() => LinkImporter::apply($pdo, $b, false, 't', true), 'aplicado');
    throws(fn() => LinkImporter::verify($pdo, $b, new LinkVerifier(new FakeProbeHttp([])), 't'), 'prévias');
    $b2 = LinkImporter::preview($pdo, $CSV18, 'b.csv', 't');
    LinkImporter::discard($pdo, $b2, 't');
    throws(fn() => LinkImporter::apply($pdo, $b2, false, 't', true), 'descartado');
    LinkImporter::revert($pdo, $b, 't');
    throws(fn() => LinkImporter::revert($pdo, $b, 't'));
});
test('Verificação que termina depois de o lote ser aplicado não grava nada', function () use ($ready, $CSV18) {
    $pdo = $ready();
    $b = LinkImporter::preview($pdo, $CSV18, 'a.csv', 't');
    $http = new class($pdo, $b) extends Gmax\Http {
        private bool $done = false;
        public function __construct(private PDO $pdo, private int $b) {}
        public function probe(string $url, int $timeout = 8): array {
            if (!$this->done) { $this->done = true; LinkImporter::apply($this->pdo, $this->b, false, 'outra-aba', true); }
            return ['status' => 404, 'location' => null, 'error' => null];
        }
    };
    throws(fn() => LinkImporter::verify($pdo, $b, new LinkVerifier($http), 't'), 'durante a verificação');
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_import_items WHERE batch_id=? AND action='rejeitado'", [$b]), 'resultados não gravados');
});
test('Número de anúncio ambíguo (item e catálogo) é recusado', function () use ($ready, $csv, $items) {
    $pdo = $ready();
    $pdo->exec("INSERT INTO gmax3_catalog(marketplace,dedup_key,external_id,title,validation_status) VALUES('mercadolivre','catalogo:MLB4047815501','catalogo:MLB4047815501','Mouse catálogo','rascunho')");
    $b = LinkImporter::preview($pdo, $csv(['1;MLB-4047815501;Mouse Bluetooth;https://meli.la/Amb0001;OK']), 'x.csv', 't');
    ok(str_contains((string)$items($pdo, $b)[2]['errors_json'], 'Mais de um produto'), 'ambíguo');
});

echo "\n== Desfazer, auditoria e isolamento\n";
test('Desfazer restaura os links anteriores (vazios) e preserva produto alterado depois', function () use ($ready, $CSV18) {
    $pdo = $ready();
    $b = LinkImporter::preview($pdo, $CSV18, 'a.csv', 't');
    LinkImporter::apply($pdo, $b, false, 't', true);
    $edited = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE external_id='item:MLB4047815501'");
    Catalog::update($pdo, Catalog::get($pdo, $edited), ['affiliate_url' => 'https://meli.la/Manual1'], 't', false);
    $r = LinkImporter::revert($pdo, $b, 't');
    eq(17, $r['restaurados']);
    eq(1, count($r['ignorados']), 'produto editado preservado');
    eq(1, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE marketplace='mercadolivre' AND affiliate_url LIKE 'https://meli.la/%' AND codigo IS NULL"), 'só o editado mantém link');
    eq('https://meli.la/Manual1', Db::value($pdo, 'SELECT affiliate_url FROM gmax3_catalog WHERE id=?', [$edited]));
    throws(fn() => LinkImporter::revert($pdo, $b, 't'), 'aplicados');
});
test('Auditoria registra prévia, aplicação, cada link e o desfazer, com usuário e data', function () use ($ready, $CSV18) {
    $pdo = $ready();
    $b = LinkImporter::preview($pdo, $CSV18, 'a.csv', 'admin');
    LinkImporter::apply($pdo, $b, false, 'admin', true);
    LinkImporter::revert($pdo, $b, 'admin');
    $acts = $pdo->query("SELECT action, COUNT(*) FROM gmax3_audit WHERE actor='admin' GROUP BY action")->fetchAll(PDO::FETCH_KEY_PAIR);
    eq(1, (int)$acts['links_lote_previa']);
    eq(1, (int)$acts['links_lote_aplicado']);
    eq(18, (int)$acts['link_associado_lote']);
    eq(18, (int)$acts['link_restaurado_lote']);
    eq(1, (int)$acts['links_lote_desfeito']);
    $batch = LinkImporter::batch($pdo, $b);
    ok($batch['applied_at'] !== null && $batch['reverted_at'] !== null && $batch['actor'] === 'admin', 'datas e usuário no lote');
});
test('Importador de produtos não opera lotes de links (e vice-versa)', function () use ($ready, $CSV18, $F) {
    $pdo = $ready();
    $b = LinkImporter::preview($pdo, $CSV18, 'a.csv', 't');
    throws(fn() => Importer::apply($pdo, $b, 't'), 'Links afiliados');
    throws(fn() => Importer::discard($pdo, $b, 't'));
    $p = Importer::preview($pdo, "$F/unificado.csv", 'u.csv', 't');
    throws(fn() => LinkImporter::apply($pdo, $p, false, 't', true), 'não encontrado');
});
test('Arquivo sem as colunas obrigatórias é recusado; XLSX com as mesmas colunas funciona', function () use ($ready) {
    $pdo = $ready();
    $f = tempnam(sys_get_temp_dir(), 'gml') . '.csv';
    file_put_contents($f, "produto;link\nx;https://meli.la/Abc1234\n");
    throws(fn() => LinkImporter::preview($pdo, $f, 'x.csv', 't'), 'id_anuncio');
    $x = tempnam(sys_get_temp_dir(), 'gml') . '.xlsx';
    $zip = new ZipArchive();
    $zip->open($x, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Links" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="x"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . '<row r="1"><c r="A1" t="inlineStr"><is><t>id_anuncio</t></is></c><c r="B1" t="inlineStr"><is><t>url_afiliado</t></is></c></row>'
        . '<row r="2"><c r="A2" t="inlineStr"><is><t>MLB-4047815501</t></is></c><c r="B2" t="inlineStr"><is><t>https://meli.la/Xls0001</t></is></c></row></sheetData></worksheet>');
    $zip->close();
    $b = LinkImporter::preview($pdo, $x, 'links.xlsx', 't');
    eq(1, (int)LinkImporter::batch($pdo, $b)['inserted']);
});

function t_legacy_snapshot_safe(PDO $pdo): string
{
    $out = [];
    foreach (['users', 'offers', 'slots', 'gmax_ml_offers'] as $t) {
        $out[$t] = $pdo->query("SELECT * FROM $t ORDER BY rowid")->fetchAll();
    }
    return hash('sha256', json_encode($out));
}

$pass = count(array_filter($GLOBALS['t_results'], fn($r) => $r[1]));
$total = count($GLOBALS['t_results']);
echo "\nResultado links em lote: $pass/$total testes aprovados\n";
file_put_contents(__DIR__ . '/../docs/resultado-testes-links.json', json_encode([
    'executado_em' => date('c'), 'php' => PHP_VERSION, 'aprovados' => $pass, 'total' => $total,
    'testes' => array_map(fn($r) => ['nome' => $r[0], 'ok' => $r[1], 'erro' => $r[2], 'segundos' => round($r[3], 3)], $GLOBALS['t_results']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($pass === $total ? 0 : 1);

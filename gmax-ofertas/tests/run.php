<?php
declare(strict_types=1);
/*
 * Testes automatizados GMAX Ofertas 3.0 (unidade + integração).
 * Execução: php -d allow_url_fopen=0 tests/run.php
 * Sem rede, sem Telegram: GMAX_TESTING=1 e allow_url_fopen=0.
 */
require __DIR__ . '/harness.php';

if (ini_get('allow_url_fopen')) {
    fwrite(STDERR, "Execute com -d allow_url_fopen=0 (garantia de que nenhuma chamada de rede ocorre)\n");
    exit(2);
}
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';
require __DIR__ . '/fakes.php';

use Gmax\{Audit, Backup, Catalog, Config, Connectors\MercadoLivre, Connectors\ShopeeAffiliate, Db, Http, Importer, Message, Migrator, Money, Preflight, Queue, Ranking, Rules, SendResult, Settings, TelegramTransport, Time, Url};

$F = __DIR__ . '/fixtures';
$migrated = static function (): PDO {
    $pdo = t_seed();
    Migrator::up($pdo, 'teste');
    return $pdo;
};
$approveReady = static function (PDO $pdo, int $id): void {
    Catalog::confirmPrice($pdo, $id, 'teste');
    eq([], Catalog::setStatus($pdo, $id, 'aprovado', 'teste'), 'aprovação');
};
$importFile = static function (PDO $pdo, string $file, bool $apply = true): array {
    $id = Importer::preview($pdo, $file, basename($file), 'teste');
    $b = Db::one($pdo, 'SELECT * FROM gmax3_import_batches WHERE id=?', [$id]);
    $res = $apply ? Importer::apply($pdo, $id, 'teste') : null;
    return ['id' => $id, 'preview' => $b, 'apply' => $res];
};
$allowSending = static function (PDO $pdo): void {
    // Libera TODAS as travas, mas somente com transporte falso (modo de teste).
    Settings::set($pdo, 'publicacao_pausada', '0', 'teste');
    Settings::set($pdo, 'automacao_comercial', '1', 'teste');
    file_put_contents(Config::activationFile(), "<?php return ['enabled'=>true,'chat_id'=>'-1004443626419','authorization'=>'AUTORIZO_FILA_GMAX3_PRIVADA'];");
};
$shopeeReadyId = static function (PDO $pdo): int {
    // Segunda oferta Shopee (a primeira já foi enviada pelo V2.1).
    $id = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE marketplace='shopee' AND external_id='22812345602'");
    Db::run($pdo, "UPDATE gmax3_catalog SET payment_condition='normal' WHERE id=?", [$id]);
    Settings::set($pdo, 'publicador_v21_aposentado', '1', 'teste'); // V2.1 desativado de forma explícita
    return $id;
};

echo "\n== Dinheiro, desconto e links\n";
test('Converte valores brasileiros e de planilha em centavos', function () {
    eq(19990, Money::parse('199,90'));
    eq(129990, Money::parse('1.299,90'));
    eq(129900, Money::parse('1.299'));
    eq(4990, Money::parse('R$ 49,90'));
    eq(14391, Money::parse(143.91));
    eq(2990, Money::parse('29.9'));
    eq(null, Money::parse(''));
    throws(fn() => Money::parse('abc'));
    throws(fn() => Money::parse('12,345'));
});
test('Desconto do exemplo (199,90 → 119,90) = 40% OFF, economia R$ 80,00', function () {
    eq(['percent' => 40, 'savings' => 8000], Money::discount(19990, 11990));
    eq('R$ 80,00', Money::format(8000));
    eq(null, Money::discount(null, 11990), 'sem original não há desconto');
    eq(null, Money::discount(11990, 11990), 'preços iguais');
    eq(null, Money::discount(10000, 11990), 'original menor');
    eq(65, Money::discount(29990, 10431)['percent'], 'ML-001 arredonda para baixo (65,2%)');
    eq(33, Money::discount(300, 200)['percent'], 'nunca arredonda para cima');
});
test('Validação de links afiliados e de produto por marketplace', function () {
    ok(Url::isAffiliate('mercadolivre', 'https://meli.la/1uPYV76'), 'meli.la válido');
    ok(!Url::isAffiliate('mercadolivre', 'http://meli.la/1uPYV76'), 'http recusado');
    ok(!Url::isAffiliate('mercadolivre', 'https://www.mercadolivre.com.br/x/p/MLB123456'), 'link comum não é afiliado');
    ok(!Url::isAffiliate('mercadolivre', 'https://meli.la.evil.com/abcd'), 'domínio parecido recusado');
    ok(!Url::isAffiliate('mercadolivre', 'https://user:pw@meli.la/abcd'), 'credenciais na URL recusadas');
    ok(!Url::isAffiliate('mercadolivre', 'javascript:alert(1)'), 'javascript recusado');
    ok(!Url::isAffiliate('mercadolivre', 'https://meli.la/abcd"onmouseover=x'), 'aspas recusadas');
    ok(Url::isAffiliate('shopee', 'https://s.shopee.com.br/AaaAaa1'), 'shopee válido');
    ok(!Url::isAffiliate('shopee', 'https://meli.la/AaaAaa1'), 'link ML não vale para Shopee');
    eq('item:MLB4047815501', Url::mlId('https://produto.mercadolivre.com.br/MLB-4047815501-mouse-sem-fio-_JM'));
    eq('catalogo:MLB30144703', Url::mlId('https://www.mercadolivre.com.br/parafusadeira/p/MLB30144703'));
    ok(Url::isListing('https://lista.mercadolivre.com.br/air-fryer-electrolux'), 'página de busca detectada');
    ok(!Url::isProduct('mercadolivre', 'https://lista.mercadolivre.com.br/air-fryer'), 'busca não é produto');
    eq('98765432101', Url::shopeeItemId('https://shopee.com.br/Tapete-i.555.98765432101'));
});

echo "\n== Migração, preservação e rollback\n";
test('Migração preserva tabelas antigas e copia 5 Shopee + 1 Mercado Livre', function () {
    $pdo = t_seed();
    $before = t_legacy_snapshot($pdo);
    eq(['001'], Migrator::pending($pdo));
    $done = Migrator::up($pdo, 'teste');
    eq(['shopee' => 5, 'mercadolivre' => 1], $done[0]['result']);
    ok(is_file($done[0]['backup']), 'backup prévio criado');
    eq($before, t_legacy_snapshot($pdo), 'tabelas legadas idênticas byte a byte');
    eq(6, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_catalog')->fetchColumn());
    $ml = Db::one($pdo, "SELECT * FROM gmax3_catalog WHERE codigo='ML-001'");
    eq(29990, (int)$ml['price_original_cents']);
    eq(10431, (int)$ml['price_promo_cents']);
    eq('pix', $ml['payment_condition']);
    eq(null, $ml['price_verified_at'], 'preço da captura não vira preço conferido');
    eq('gmax_ml_offers', $ml['legacy_table']);
    eq('1', Settings::get($pdo, 'publicacao_pausada'), 'publicação pausada por padrão');
    eq('0', Settings::get($pdo, 'automacao_comercial'), 'automação desativada por padrão');
    eq('0', Settings::get($pdo, 'ml_grupo_privado_permitido'));
    eq([], Migrator::pending($pdo));
    eq([], Migrator::up($pdo, 'teste'), 'reaplicar não faz nada');
});
test('Sincronização com tabelas antigas é idempotente e capta novos produtos do index.php antigo', function () {
    $pdo = t_seed();
    Migrator::up($pdo, 'teste');
    eq(['shopee' => 0, 'mercadolivre' => 0], Catalog::syncLegacy($pdo, 'teste'));
    $pdo->exec("INSERT INTO offers(item_id,title,price,original_url,affiliate_url) VALUES('22812345699','Novo pelo painel antigo','10,00','https://shopee.com.br/product/1/22812345699','https://s.shopee.com.br/Novo999')");
    eq(['shopee' => 1, 'mercadolivre' => 0], Catalog::syncLegacy($pdo, 'teste'));
    eq(['shopee' => 0, 'mercadolivre' => 0], Catalog::syncLegacy($pdo, 'teste'));
});
test('Reverter migração é recusado com envio não reconciliado', function () use ($migrated) {
    $pdo = $migrated();
    $pdo->exec("INSERT INTO gmax3_pub_queue(catalog_id,channel,state,scheduled_for) VALUES(1,'telegram_privado','incerto','2026-10-29 10:00:00')");
    throws(fn() => Migrator::down($pdo, 'teste'), 'não reconciliados');
});
test('Tentativa do cron antigo (slots=tentativa) também bloqueia', function () use ($migrated) {
    $pdo = $migrated();
    $pdo->exec("INSERT INTO slots(slot,status,detail) VALUES('2026-10-28 12:00','tentativa','x')");
    ok(str_contains(implode(' ', Queue::unreconciled($pdo)), 'slots'), 'slots');
});
test('Reverter migração remove apenas tabelas gmax3_ e mantém dados antigos', function () {
    $pdo = t_seed();
    $before = t_legacy_snapshot($pdo);
    Migrator::up($pdo, 'teste');
    $r = Migrator::down($pdo, 'teste');
    eq('001', $r['version']);
    eq(0, (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'gmax3%'")->fetchColumn());
    eq($before, t_legacy_snapshot($pdo));
    Migrator::up($pdo, 'teste');
    eq(6, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_catalog')->fetchColumn(), 'reaplicação após rollback');
});
test('Backup verificado e restauração das tabelas do painel', function () use ($migrated) {
    $pdo = $migrated();
    $b = Backup::create($pdo, 'teste');
    ok(is_file($b['file']) && $b['bytes'] > 0, 'arquivo de backup');
    eq(0600, fileperms($b['file']) & 0777, 'permissão 0600');
    ok(str_starts_with($b['file'], t_sandbox() . '/gmax-secrets/gmax-backups/'), 'backup fora da pasta pública');
    $legacy = t_legacy_snapshot($pdo);
    $pdo->exec('DELETE FROM gmax3_catalog');
    eq(0, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_catalog')->fetchColumn());
    Settings::set($pdo, 'automacao_comercial', '1', 'teste');
    $pdo->exec("INSERT INTO gmax3_pub_queue(catalog_id,channel,state,scheduled_for) VALUES(1,'telegram_privado','confirmado','2026-10-29 10:00:00')");
    $r = Backup::restorePanelTables($pdo, basename($b['file']), 'teste');
    eq(6, $r['tables']['gmax3_catalog']);
    eq('1', Settings::get($pdo, 'automacao_comercial'), 'configurações não são restauradas');
    eq('confirmado', Db::value($pdo, 'SELECT state FROM gmax3_pub_queue'), 'fila não volta ao passado');
    eq('1', Settings::get($pdo, 'publicacao_pausada'), 'pausa forçada após restauração');
    eq(6, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_catalog')->fetchColumn());
    eq($legacy, t_legacy_snapshot($pdo), 'restauração não toca tabelas antigas');
    throws(fn() => Backup::resolve('../../etc/passwd'), 'inválido');
    throws(fn() => Backup::resolve('gmax-20261029-000000-x.sqlite.php'), 'inválido');
});
test('Preflight: ambiente de teste sem falhas e arquivos iguais à referência', function () use ($migrated) {
    $pdo = $migrated();
    $res = Preflight::run($pdo);
    $fails = array_filter($res, fn($r) => $r[0] === 'falha');
    eq([], array_values($fails), 'sem falhas');
    $files = array_filter($res, fn($r) => str_starts_with($r[1], 'Arquivo ') && str_ends_with($r[1], '.php'));
    foreach ($files as $f) {
        // O publicador do teste foi ajustado para a pasta isolada: deve ser apontado como DIFERENTE.
        $expected = $f[1] === 'Arquivo gmax-publicador-cli.php' ? 'DIFERENTE' : 'idêntico';
        ok(str_contains($f[2], $expected), $f[1] . " => $expected");
    }
});

echo "\n== Importação em lote e duplicidade\n";
test('Planilha real "25 anúncios" (.xlsx): importa como rascunho sem link e não duplica na reimportação', function () use ($migrated, $importFile, $F) {
    $pdo = $migrated();
    $r = $importFile($pdo, "$F/ml-25-anuncios.xlsx");
    eq('ml_pesquisa', $r['preview']['format']);
    eq(25, (int)$r['preview']['total']);
    eq(25, $r['apply']['inserir']);
    eq(25, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE validation_status='rascunho' AND marketplace='mercadolivre'"));
    $mouse = Db::one($pdo, "SELECT * FROM gmax3_catalog WHERE external_id='item:MLB4047815501'");
    eq(21400, (int)$mouse['price_original_cents']);
    eq(5799, (int)$mouse['price_promo_cents']);
    eq(null, $mouse['price_verified_at'], 'preço de pesquisa não é conferido');
    eq(10000, (int)$mouse['sales_min']);
    $r2 = $importFile($pdo, "$F/ml-25-anuncios.xlsx");
    eq(0, $r2['apply']['inserir'], 'reimportação não cria produtos');
    eq(25, $r2['apply']['sem_alteracao']);
    eq(31, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_catalog')->fetchColumn());
});
test('Planilha "16 conferência": páginas de busca descartadas, tudo como rascunho', function () use ($migrated, $importFile, $F) {
    $pdo = $migrated();
    $r = $importFile($pdo, "$F/ml-16-conferencia.xlsx");
    eq('ml_conferencia', $r['preview']['format']);
    eq(16, (int)$r['preview']['total']);
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE original_url LIKE 'https://lista.%'"), 'nenhuma URL de busca gravada');
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE price_source='pesquisa_historica' AND validation_status<>'rascunho'"));
    $warn = (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_import_items WHERE batch_id=? AND warnings_json LIKE '%busca/categoria%'", [$r['id']]);
    ok($warn >= 10, "avisos de URL de busca ($warn)");
});
test('Modelo ML (.xlsx) e CSV de referência atualizam ML-001 em vez de duplicar', function () use ($migrated, $importFile, $F) {
    $pdo = $migrated();
    $r = $importFile($pdo, "$F/ml-modelo-100.xlsx");
    eq('gmax_ml', $r['preview']['format']);
    eq(0, $r['apply']['inserir']);
    eq(1, $r['apply']['atualizar'] + $r['apply']['sem_alteracao']);
    $r = $importFile($pdo, "$F/ml-lote-referencia.csv");
    eq(0, $r['apply']['inserir']);
    eq(1, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE codigo='ML-001'"));
});
test('CSV Shopee reimportado: 5 existentes reconhecidos, nenhum duplicado', function () use ($migrated, $importFile, $F) {
    $pdo = $migrated();
    $r = $importFile($pdo, "$F/shopee-5-ofertas.csv");
    eq('shopee', $r['preview']['format']);
    eq(0, $r['apply']['inserir']);
    eq(5, $r['apply']['sem_alteracao'] + $r['apply']['atualizar']);
    eq(5, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE marketplace='shopee'"));
});
test('Linhas inválidas rejeitadas com motivo; válidas aceitas; nada parcial', function () use ($migrated, $importFile, $F) {
    $pdo = $migrated();
    $r = $importFile($pdo, "$F/ml-invalidos.csv");
    $items = Db::all($pdo, 'SELECT line, action, errors_json FROM gmax3_import_items WHERE batch_id=? ORDER BY line', [$r['id']]);
    $by = array_column($items, null, 'line');
    eq('rejeitado', $by[2]['action'], 'link comum');
    ok(str_contains($by[2]['errors_json'], 'meli.la'), 'motivo link comum');
    eq('rejeitado', $by[3]['action'], 'original < promo');
    eq('rejeitado', $by[4]['action'], 'preço texto');
    eq('rejeitado', $by[5]['action'], 'javascript:');
    eq('inserir', $by[6]['action'], 'válido');
    eq('rejeitado', $by[7]['action'], 'link repetido no arquivo');
    ok(str_contains($by[7]['errors_json'], 'repetido'), 'motivo repetido');
    eq('rejeitado', $by[8]['action'], 'data inválida');
    eq('inserir', $by[9]['action'], 'título com HTML é aceito como texto');
    eq(2, $r['apply']['inserir']);
});
test('Modelo unificado: Shopee + ML, preço com milhar, cupom e ID extraído da URL', function () use ($migrated, $importFile, $F) {
    $pdo = $migrated();
    $r = $importFile($pdo, "$F/unificado.csv");
    eq('gmax_unificado', $r['preview']['format']);
    eq(2, $r['apply']['inserir']);
    $p = Db::one($pdo, "SELECT * FROM gmax3_catalog WHERE codigo='ML-200'");
    eq(129990, (int)$p['price_original_cents']);
    eq('cupom', $p['payment_condition']);
    eq('cupom GMAX10', $p['payment_note']);
    eq(1520, (int)$p['rating_count']);
    eq('98765432101', Db::value($pdo, "SELECT external_id FROM gmax3_catalog WHERE affiliate_url='https://s.shopee.com.br/Uni201x'"));
});
test('Associação de link por reimportação: rascunho recebe link e vai para validação', function () use ($migrated, $importFile, $F) {
    $pdo = $migrated();
    $importFile($pdo, "$F/ml-25-anuncios.xlsx");
    $csv = tempnam(sys_get_temp_dir(), 'gm') . '.csv';
    file_put_contents($csv, "Ranking;Categoria;Produto;ID anúncio;Preço referência (R\$);Preço oferta (R\$);Desconto calculado;Condição;Vendas indicadas;Nota;Avaliações;URL produto original;LINK AFILIADO (PREENCHER);Status;Observações;Fonte;Coleta\n"
        . "1;Tecnologia;Mouse Bluetooth recarregável 2.4G;MLB-4047815501;214;57,99;;Normal;+10 mil;4,9;3390;https://produto.mercadolivre.com.br/MLB-4047815501-mouse-sem-fio-bluetooth-recarregavel-gamer-ergonmico-24g-_JM;https://meli.la/Mouse01;;;;\n");
    $r = $importFile($pdo, $csv);
    eq(1, $r['apply']['atualizar']);
    $m = Db::one($pdo, "SELECT * FROM gmax3_catalog WHERE external_id='item:MLB4047815501'");
    eq('https://meli.la/Mouse01', $m['affiliate_url']);
    eq('aguardando_validacao', $m['validation_status']);
    eq(31, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_catalog')->fetchColumn(), 'sem duplicar');
    unlink($csv);
});
test('Conflito: mesmo link afiliado já usado por outro produto é rejeitado', function () use ($migrated, $importFile) {
    $pdo = $migrated();
    $csv = tempnam(sys_get_temp_dir(), 'gm') . '.csv';
    file_put_contents($csv, "codigo_gmax;categoria;produto;url_original;url_afiliado;preco_original;preco_promocional;condicao_preco;verificado_em;observacoes\nML-900;Casa;Outro produto;;https://meli.la/1uPYV76;;10,00;Pix;;\n");
    $r = $importFile($pdo, $csv, false);
    $it = Db::one($pdo, 'SELECT * FROM gmax3_import_items WHERE batch_id=?', [$r['id']]);
    eq('rejeitado', $it['action']);
    ok(str_contains((string)$it['errors_json'], 'Conflito'), 'motivo conflito');
    unlink($csv);
});
test('Desfazer lote remove inseridos e restaura atualizados; prévia descartada não grava', function () use ($migrated, $importFile, $F) {
    $pdo = $migrated();
    $r = $importFile($pdo, "$F/unificado.csv");
    eq(8, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_catalog')->fetchColumn());
    $rev = Importer::revert($pdo, $r['id'], 'teste');
    eq(2, $rev['removidos']);
    eq(6, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_catalog')->fetchColumn());
    throws(fn() => Importer::revert($pdo, $r['id'], 'teste'), 'aplicados');
    $p = $importFile($pdo, "$F/unificado.csv", false);
    Importer::discard($pdo, $p['id'], 'teste');
    eq(6, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_catalog')->fetchColumn());
    throws(fn() => Importer::apply($pdo, $p['id'], 'teste'), 'descartado');
});
test('Arquivos fora do padrão são recusados (cabeçalho desconhecido, binário, vazio)', function () use ($migrated) {
    $pdo = $migrated();
    $f = tempnam(sys_get_temp_dir(), 'gm');
    file_put_contents($f, "nome;preco\nx;1\n");
    throws(fn() => Importer::preview($pdo, $f, 'x.csv', 't'), 'Cabeçalho');
    file_put_contents($f, '');
    throws(fn() => Importer::preview($pdo, $f, 'x.csv', 't'), 'vazio');
    file_put_contents($f, "PK\x03\x04lixo");
    throws(fn() => Importer::preview($pdo, $f, 'x.xlsx', 't'), 'XLSX');
    file_put_contents($f, 'a');
    throws(fn() => Importer::preview($pdo, $f, 'x.exe', 't'), 'Formato');
    unlink($f);
});

echo "\n== Mensagem, aprovação e regras\n";
test('Mensagem no modelo GMAX com De/Por/% OFF/Economize/Pix', function () {
    $msg = Message::render(['marketplace' => 'mercadolivre', 'title' => 'Nome do produto', 'price_original_cents' => 19990, 'price_promo_cents' => 11990, 'payment_condition' => 'pix', 'payment_note' => null, 'affiliate_url' => 'https://meli.la/XXXX123']);
    eq("GMAX OFERTAS | MERCADO LIVRE\n\nNome do produto\n\nDe: R$ 199,90\nPor: R$ 119,90\n40% OFF\nEconomize R$ 80,00\n\nCondição: pagamento via Pix\n\nhttps://meli.la/XXXX123\n\n" . Message::DISCLAIMER, $msg);
});
test('Sem preço original a mensagem não inventa De nem desconto', function () {
    $msg = Message::render(['marketplace' => 'shopee', 'title' => 'X', 'price_original_cents' => null, 'price_promo_cents' => 4990, 'payment_condition' => 'normal', 'affiliate_url' => 'https://s.shopee.com.br/AaaAaa1']);
    ok(!str_contains($msg, 'De:') && !str_contains($msg, 'OFF') && !str_contains($msg, 'Economize'), 'sem De/OFF');
    ok(str_contains($msg, 'Por: R$ 49,90'), 'preço Por');
    ok(!str_contains($msg, 'Condição'), 'preço normal não exibe condição');
});
test('Aprovação exige link oficial, preço e condição de pagamento', function () use ($migrated) {
    $pdo = $migrated();
    $id = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE external_id='22812345602'");
    $issues = Catalog::setStatus($pdo, $id, 'aprovado', 'teste');
    ok(in_array('Condição de pagamento não identificada', $issues, true), 'condição exigida');
    eq('aguardando_validacao', Catalog::get($pdo, $id)['validation_status']);
});
test('Bloqueios de publicação: sem aprovação, preço não conferido, preço vencido, ML em grupo privado', function () use ($migrated, $approveReady) {
    $pdo = $migrated();
    $ml = Catalog::get($pdo, (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE codigo='ML-001'"));
    $b = implode(' | ', Rules::blockers($pdo, $ml, 'telegram_privado'));
    ok(str_contains($b, 'não aprovada'), 'não aprovada');
    ok(str_contains($b, 'Preço não confirmado'), 'preço não conferido');
    ok(str_contains($b, 'Mercado Livre'), 'regra ML grupo privado');
    $approveReady($pdo, (int)$ml['id']);
    $b = Rules::blockers($pdo, Catalog::get($pdo, (int)$ml['id']), 'telegram_privado');
    eq(1, count($b), 'restou apenas a regra ML');
    Time::freeze(new DateTimeImmutable('+25 hours'));
    $b = implode(' | ', Rules::blockers($pdo, Catalog::get($pdo, (int)$ml['id']), 'telegram_privado'));
    Time::freeze(null);
    ok(str_contains($b, 'reconfira'), 'preço vencido após 24 h');
});
test('Alterar preço retira aprovação e confirmação', function () use ($migrated, $approveReady, $shopeeReadyId) {
    $pdo = $migrated();
    $id = $shopeeReadyId($pdo);
    $approveReady($pdo, $id);
    $r = Catalog::update($pdo, Catalog::get($pdo, $id), ['price_promo_cents' => 3500], 'teste', false);
    eq('aguardando_validacao', $r['status']);
    eq(null, Catalog::get($pdo, $id)['price_verified_at']);
});
test('Item já enviado pelo publicador V2.1 é bloqueado por duplicidade', function () use ($migrated, $approveReady) {
    $pdo = $migrated();
    $id = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE external_id='22812345601'");
    Db::run($pdo, "UPDATE gmax3_catalog SET payment_condition='normal' WHERE id=?", [$id]);
    $approveReady($pdo, $id);
    $reasons = Queue::enqueue($pdo, $id, 'telegram_privado', null, 'teste');
    ok(str_contains(implode(' ', $reasons), 'V2.1'), 'bloqueado pelo histórico V2.1');
});

echo "\n== Fila de publicação (transporte FALSO, nunca Telegram real)\n";
test('Item ainda pendente no V2.1 é bloqueado até o V2.1 ser aposentado', function () use ($migrated, $approveReady) {
    $pdo = $migrated();
    $id = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE external_id='22812345602'");
    Db::run($pdo, "UPDATE gmax3_catalog SET payment_condition='normal' WHERE id=?", [$id]);
    $approveReady($pdo, $id);
    ok(str_contains(implode(' ', Queue::enqueue($pdo, $id, 'telegram_privado', null, 'teste')), 'pendente na fila do publicador V2.1'), 'bloqueado');
    Settings::set($pdo, 'publicador_v21_aposentado', '1', 'teste');
    eq([], Queue::enqueue($pdo, $id, 'telegram_privado', null, 'teste'));
});
test('Ativação do V2.1 presente bloqueia a fila 3.0 (dois publicadores nunca juntos)', function () use ($migrated, $approveReady, $shopeeReadyId, $allowSending) {
    $pdo = $migrated();
    $id = $shopeeReadyId($pdo);
    $approveReady($pdo, $id);
    Queue::enqueue($pdo, $id, 'telegram_privado', Time::nowSql(), 'teste');
    $allowSending($pdo);
    file_put_contents(Config::legacyActivationFile(), "<?php return [];");
    $t = new FakeTransport(new SendResult(SendResult::CONFIRMED, 200, 1));
    $r = Queue::processNext($pdo, false, fn() => $t, 'teste');
    unlink(Config::legacyActivationFile());
    eq('envio_bloqueado', $r['resultado']);
    eq(0, $t->calls);
});
test('Pausa pelo painel também cria gmax-publicacao.pause (respeitado pelo V2.1)', function () use ($migrated) {
    $pdo = $migrated();
    Settings::pause($pdo, 'teste', 'teste');
    ok(is_file(Config::pauseFile()), 'arquivo de pausa');
    ok(Settings::resume($pdo, 'teste'), 'retomada');
    ok(!is_file(Config::pauseFile()), 'arquivo removido');
    file_put_contents(Config::pauseFile(), 'manual');
    ok(!Settings::resume($pdo, 'teste'), 'arquivo manual preservado');
    ok(Settings::paused($pdo), 'continua pausado pelo arquivo manual');
});
test('Lote enfileirado recebe horários distintos (sem rajada)', function () use ($migrated, $approveReady) {
    $pdo = $migrated();
    Settings::set($pdo, 'publicador_v21_aposentado', '1', 'teste');
    foreach (['22812345602', '22812345603', '22812345604'] as $e) {
        $id = (int)Db::value($pdo, 'SELECT id FROM gmax3_catalog WHERE external_id=?', [$e]);
        Db::run($pdo, "UPDATE gmax3_catalog SET payment_condition='normal' WHERE id=?", [$id]);
        $approveReady($pdo, $id);
        eq([], Queue::enqueue($pdo, $id, 'telegram_privado', null, 'teste'));
    }
    eq(3, (int)$pdo->query('SELECT COUNT(DISTINCT scheduled_for) FROM gmax3_pub_queue')->fetchColumn());
});
test('Pausa ou edição entre a checagem e a reserva aborta o envio', function () use ($migrated, $approveReady, $shopeeReadyId, $allowSending) {
    $pdo = $migrated();
    $id = $shopeeReadyId($pdo);
    $approveReady($pdo, $id);
    Queue::enqueue($pdo, $id, 'telegram_privado', Time::nowSql(), 'teste');
    $allowSending($pdo);
    $t = new FakeTransport(new SendResult(SendResult::CONFIRMED, 200, 1));
    $r = Queue::processNext($pdo, false, function () use ($pdo, $id, $t) {
        Db::run($pdo, "UPDATE gmax3_catalog SET updated_at='2099-01-01 00:00:00' WHERE id=?", [$id]); // edição concorrente
        return $t;
    }, 'teste');
    eq('abortado_mudanca_recente', $r['resultado']);
    eq(0, $t->calls);
    eq('agendado', Db::value($pdo, 'SELECT state FROM gmax3_pub_queue'));
});
test('Duplicidade na fila: o mesmo produto não entra duas vezes na janela', function () use ($migrated, $approveReady, $shopeeReadyId) {
    $pdo = $migrated();
    $id = $shopeeReadyId($pdo);
    $approveReady($pdo, $id);
    eq([], Queue::enqueue($pdo, $id, 'telegram_privado', null, 'teste'));
    ok(str_contains(implode(' ', Queue::enqueue($pdo, $id, 'telegram_privado', null, 'teste')), 'Duplicidade'), 'segunda vez bloqueada');
});
test('Com travas padrão nada é enviado e o transporte nem é criado', function () use ($migrated, $approveReady, $shopeeReadyId) {
    $pdo = $migrated();
    $id = $shopeeReadyId($pdo);
    $approveReady($pdo, $id);
    Queue::enqueue($pdo, $id, 'telegram_privado', Time::nowSql(), 'teste');
    $created = 0;
    $r = Queue::processNext($pdo, false, function () use (&$created) { $created++; return new FakeTransport(new SendResult('confirmado', 200, 1)); }, 'teste');
    eq('envio_bloqueado', $r['resultado']);
    eq(0, $created, 'transporte não instanciado');
    eq('agendado', Db::value($pdo, 'SELECT state FROM gmax3_pub_queue'));
    ok(count($r['travas']) >= 3, 'pausa + automação + ativação');
});
test('Simulação não altera estados nem chama transporte', function () use ($migrated, $approveReady, $shopeeReadyId) {
    $pdo = $migrated();
    $id = $shopeeReadyId($pdo);
    $approveReady($pdo, $id);
    Queue::enqueue($pdo, $id, 'telegram_privado', Time::nowSql(), 'teste');
    $r = Queue::processNext($pdo, true, null, 'teste');
    eq('simulacao_sem_envio', $r['resultado']);
    ok(str_contains($r['mensagem'], 'GMAX OFERTAS | SHOPEE'), 'mensagem simulada');
    eq('agendado', Db::value($pdo, 'SELECT state FROM gmax3_pub_queue'));
});
test('Envio confirmado: registra message_id e não republica', function () use ($migrated, $approveReady, $shopeeReadyId, $allowSending) {
    $pdo = $migrated();
    $id = $shopeeReadyId($pdo);
    $approveReady($pdo, $id);
    Queue::enqueue($pdo, $id, 'telegram_privado', Time::nowSql(), 'teste');
    $allowSending($pdo);
    $t = new FakeTransport(new SendResult(SendResult::CONFIRMED, 200, 77, 'ok'));
    $r = Queue::processNext($pdo, false, fn() => $t, 'teste');
    eq('confirmado', $r['resultado']);
    eq(1, $t->calls);
    eq('-1004443626419', $t->sent[0][0], 'somente o supergrupo autorizado');
    eq(77, (int)Db::value($pdo, 'SELECT telegram_message_id FROM gmax3_pub_queue'));
    eq('fila_vazia', Queue::processNext($pdo, false, fn() => $t, 'teste')['resultado']);
    ok(str_contains(implode(' ', Queue::enqueue($pdo, $id, 'telegram_privado', null, 'teste')), 'Duplicidade'), 'não reenfileira publicado');
    eq(1, $t->calls);
});
test('Falha de rede/timeout: estado incerto, pausa automática, nenhuma repetição', function () use ($migrated, $approveReady, $shopeeReadyId, $allowSending) {
    $pdo = $migrated();
    $id = $shopeeReadyId($pdo);
    $approveReady($pdo, $id);
    $id2 = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE external_id='22812345603'");
    Db::run($pdo, "UPDATE gmax3_catalog SET payment_condition='normal' WHERE id=?", [$id2]);
    $approveReady($pdo, $id2);
    Queue::enqueue($pdo, $id, 'telegram_privado', Time::nowSql(), 'teste');
    Queue::enqueue($pdo, $id2, 'telegram_privado', Time::nowSql(), 'teste');
    $allowSending($pdo);
    $t = new FakeTransport(TelegramTransport::classify(0, null, Config::PRIVATE_CHAT_ID));
    $r = Queue::processNext($pdo, false, fn() => $t, 'teste');
    eq('incerto', $r['resultado']);
    eq('1', Settings::get($pdo, 'publicacao_pausada'), 'pausa automática');
    $r = Queue::processNext($pdo, false, fn() => $t, 'teste');
    eq('pausa_reconciliacao', $r['resultado']);
    eq(1, $t->calls, 'nenhum novo envio enquanto houver dúvida');
    // Mesmo despausando manualmente, a pendência impede envios.
    Settings::set($pdo, 'publicacao_pausada', '0', 'teste');
    eq('pausa_reconciliacao', Queue::processNext($pdo, false, fn() => $t, 'teste')['resultado']);
    eq(1, $t->calls);
});
test('Exceção no transporte vira incerto (nunca reenvio automático)', function () use ($migrated, $approveReady, $shopeeReadyId, $allowSending) {
    $pdo = $migrated();
    $id = $shopeeReadyId($pdo);
    $approveReady($pdo, $id);
    Queue::enqueue($pdo, $id, 'telegram_privado', Time::nowSql(), 'teste');
    $allowSending($pdo);
    $r = Queue::processNext($pdo, false, fn() => new FakeTransport(new RuntimeException('conexão caiu')), 'teste');
    eq('incerto', $r['resultado']);
    eq('incerto', Db::value($pdo, 'SELECT state FROM gmax3_pub_queue'));
});
test('Reconciliação: "publicado" impede reenvio; "não publicado" permite reagendar', function () use ($migrated, $approveReady, $shopeeReadyId, $allowSending) {
    $pdo = $migrated();
    $id = $shopeeReadyId($pdo);
    $approveReady($pdo, $id);
    Queue::enqueue($pdo, $id, 'telegram_privado', Time::nowSql(), 'teste');
    $allowSending($pdo);
    Queue::processNext($pdo, false, fn() => new FakeTransport(new SendResult(SendResult::UNCERTAIN, 502)), 'teste');
    $qid = (int)Db::value($pdo, 'SELECT id FROM gmax3_pub_queue');
    Queue::reconcile($pdo, $qid, false, null, 'teste');
    eq([], Queue::unreconciled($pdo));
    eq([], Queue::enqueue($pdo, $id, 'telegram_privado', null, 'teste'), 'pode reagendar após "não publicado"');
    $q2 = (int)Db::value($pdo, "SELECT id FROM gmax3_pub_queue WHERE state='agendado'");
    Db::run($pdo, "UPDATE gmax3_pub_queue SET state='incerto' WHERE id=?", [$q2]);
    Queue::reconcile($pdo, $q2, true, 99, 'teste');
    ok(str_contains(implode(' ', Queue::enqueue($pdo, $id, 'telegram_privado', null, 'teste')), 'Duplicidade'), 'publicado não volta à fila');
    throws(fn() => Queue::reconcile($pdo, $q2, false, null, 'teste'), 'reservados ou incertos');
});
test('Recusa explícita do Telegram (HTTP 400) = falhou, sem pausa e sem repetição automática', function () use ($migrated, $approveReady, $shopeeReadyId, $allowSending) {
    $pdo = $migrated();
    $id = $shopeeReadyId($pdo);
    $approveReady($pdo, $id);
    Queue::enqueue($pdo, $id, 'telegram_privado', Time::nowSql(), 'teste');
    $allowSending($pdo);
    $t = new FakeTransport(TelegramTransport::classify(400, '{"ok":false,"error_code":400,"description":"Bad Request: chat not found"}', Config::PRIVATE_CHAT_ID));
    eq('falhou', Queue::processNext($pdo, false, fn() => $t, 'teste')['resultado']);
    eq('fila_vazia', Queue::processNext($pdo, false, fn() => $t, 'teste')['resultado']);
    eq(1, $t->calls);
});
test('Concorrência: com o mutex ocupado (ex.: V2.1 rodando) o processador não age', function () use ($migrated, $approveReady, $shopeeReadyId, $allowSending) {
    $pdo = $migrated();
    $id = $shopeeReadyId($pdo);
    $approveReady($pdo, $id);
    Queue::enqueue($pdo, $id, 'telegram_privado', Time::nowSql(), 'teste');
    $allowSending($pdo);
    $other = fopen(Config::lockFile(), 'c');
    // flock é por descritor: um segundo processo simula o worker concorrente.
    $proc = proc_open([PHP_BINARY, '-r', 'flock($f=fopen($argv[1],"c"),LOCK_EX); echo "ok\n"; fflush(STDOUT); sleep(3);', Config::lockFile()], [1 => ['pipe', 'w']], $pipes);
    fgets($pipes[1]);
    $t = new FakeTransport(new SendResult(SendResult::CONFIRMED, 200, 1));
    eq('worker_ja_em_execucao', Queue::processNext($pdo, false, fn() => $t, 'teste')['resultado']);
    eq(0, $t->calls);
    proc_terminate($proc);
    proc_close($proc);
    fclose($other);
});
test('Reserva atômica: item já reservado por outro processo não é enviado de novo', function () use ($migrated, $approveReady, $shopeeReadyId, $allowSending) {
    $pdo = $migrated();
    $id = $shopeeReadyId($pdo);
    $approveReady($pdo, $id);
    Queue::enqueue($pdo, $id, 'telegram_privado', Time::nowSql(), 'teste');
    $allowSending($pdo);
    Db::run($pdo, "UPDATE gmax3_pub_queue SET state='reservado'");
    $t = new FakeTransport(new SendResult(SendResult::CONFIRMED, 200, 1));
    eq('pausa_reconciliacao', Queue::processNext($pdo, false, fn() => $t, 'teste')['resultado']);
    eq(0, $t->calls);
});
test('Pendência do publicador V2.1 (attempted) também bloqueia a fila 3.0', function () use ($migrated, $approveReady, $shopeeReadyId, $allowSending) {
    $pdo = $migrated();
    $id = $shopeeReadyId($pdo);
    $approveReady($pdo, $id);
    Queue::enqueue($pdo, $id, 'telegram_privado', Time::nowSql(), 'teste');
    $allowSending($pdo);
    $pdo->exec("INSERT INTO gmax_dispatches(slot,item_id,status) VALUES('2026-10-28 10:00','22812345604','attempted')");
    $t = new FakeTransport(new SendResult(SendResult::CONFIRMED, 200, 1));
    $r = Queue::processNext($pdo, false, fn() => $t, 'teste');
    eq('pausa_reconciliacao', $r['resultado']);
    eq(0, $t->calls);
});
test('Item agendado com dados alterados depois é bloqueado na hora do envio', function () use ($migrated, $approveReady, $shopeeReadyId, $allowSending) {
    $pdo = $migrated();
    $id = $shopeeReadyId($pdo);
    $approveReady($pdo, $id);
    Queue::enqueue($pdo, $id, 'telegram_privado', Time::nowSql(), 'teste');
    Catalog::update($pdo, Catalog::get($pdo, $id), ['price_promo_cents' => 100], 'teste', false);
    $allowSending($pdo);
    $t = new FakeTransport(new SendResult(SendResult::CONFIRMED, 200, 1));
    $r = Queue::processNext($pdo, false, fn() => $t, 'teste');
    eq('bloqueado', $r['resultado']);
    eq(0, $t->calls);
});
test('Mercado Livre em grupo privado: bloqueado por padrão mesmo aprovado e conferido', function () use ($migrated, $approveReady) {
    $pdo = $migrated();
    $id = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE codigo='ML-001'");
    $approveReady($pdo, $id);
    ok(str_contains(implode(' ', Queue::enqueue($pdo, $id, 'telegram_privado', null, 'teste')), 'Mercado Livre'), 'regra ML');
});
test('Transporte real e HTTP são proibidos em modo de teste', function () {
    throws(fn() => new TelegramTransport('123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 'teste');
    throws(fn() => (new Http())->request('GET', 'https://example.com'), 'teste');
});
test('Classificação de respostas do Telegram', function () {
    $c = Config::PRIVATE_CHAT_ID;
    eq('confirmado', TelegramTransport::classify(200, json_encode(['ok' => true, 'result' => ['message_id' => 5, 'chat' => ['id' => (int)$c]]]), $c)->outcome);
    eq('incerto', TelegramTransport::classify(200, json_encode(['ok' => true, 'result' => ['message_id' => 5, 'chat' => ['id' => -1]]]), $c)->outcome, 'outro chat');
    eq('incerto', TelegramTransport::classify(502, '<html>', $c)->outcome);
    eq('incerto', TelegramTransport::classify(0, null, $c)->outcome);
    eq('falhou', TelegramTransport::classify(429, '{"ok":false,"description":"Too Many Requests"}', $c)->outcome);
});

echo "\n== Conectores (HTTP falso)\n";
test('Shopee: assinatura SHA256 e tratamento de falha de rede', function () {
    $auth = ShopeeAffiliate::authorization('123456', 'segredo-de-teste-123', 1700000000, '{"query":"x"}');
    eq('SHA256 Credential=123456, Timestamp=1700000000, Signature=' . hash('sha256', '1234561700000000{"query":"x"}segredo-de-teste-123'), $auth);
    $none = new ShopeeAffiliate(new FakeHttp(['status' => 0, 'body' => null, 'error' => 'x']), []);
    ok(!$none->configured(), 'sem credenciais');
    eq(false, $none->fetch(['external_id' => '22812345601'])['ok']);
    $fail = new ShopeeAffiliate(new FakeHttp(['status' => 0, 'body' => null, 'error' => 'Falha de rede ou timeout']), ['app_id' => '123456', 'secret' => 'segredo-de-teste-123']);
    eq('Falha de rede ou timeout', $fail->fetch(['external_id' => '22812345601'])['error']);
    $okHttp = new FakeHttp(['status' => 200, 'error' => null, 'body' => json_encode(['data' => ['productOfferV2' => ['nodes' => [['itemId' => 22812345601, 'productName' => 'X', 'priceMin' => '49.90', 'priceMax' => '59.90', 'commissionRate' => '0.08']]]]])]);
    $res = (new ShopeeAffiliate($okHttp, ['app_id' => '123456', 'secret' => 'segredo-de-teste-123']))->fetch(['external_id' => '22812345601']);
    ok($res['ok'] && !isset($res['data']['price_promo_cents']) && $res['data']['variation_pending'] === 1, 'faixa de preço exige variação, não inventa preço');
});
test('Mercado Livre: consulta de anúncio, sem conversão de links', function () {
    $http = new FakeHttp(['status' => 200, 'error' => null, 'body' => json_encode(['id' => 'MLB4047815501', 'title' => 'Mouse', 'price' => 57.99, 'original_price' => 214, 'status' => 'active'])]);
    $c = new MercadoLivre($http, str_repeat('A', 30));
    $r = $c->fetch(['external_id' => 'item:MLB4047815501']);
    eq(5799, $r['data']['price_promo_cents']);
    eq(21400, $r['data']['price_original_cents']);
    eq('disponivel', $r['data']['availability']);
    ok(!array_key_exists('affiliate_url', $r['data']), 'não gera link afiliado');
    eq(false, $c->fetch(['external_id' => 'catalogo:MLB30144703'])['ok'], 'catálogo exige anúncio');
});

echo "\n== Ranking comercial\n";
test('Ranking transparente: sem dados não inventa pontuação', function () {
    $r = Ranking::evaluate(['price_original_cents' => null, 'price_promo_cents' => 1000, 'availability' => 'desconhecida'], null);
    eq(null, $r['score']);
    eq(0, $r['confidence']);
    $full = Ranking::evaluate(['price_original_cents' => 20000, 'price_promo_cents' => 8000, 'sales_min' => 100000, 'sales_public' => '+100 mil', 'rating' => 5.0, 'rating_count' => 500,
        'seller_reputation' => 'MercadoLíder Platinum', 'availability' => 'disponivel', 'payment_condition' => 'normal', 'category' => 'Casa', 'price_verified_at' => '2026-10-29 10:00:00'], 100);
    eq(100, $full['score']);
    eq(100, $full['confidence']);
    $few = Ranking::evaluate(['rating' => 5.0, 'rating_count' => 2, 'availability' => 'desconhecida'], null);
    eq(null, $few['score'], '2 avaliações não pontuam');
});
test('Produto com um único dado não supera produto bem documentado', function () {
    $thin = Ranking::evaluate(['payment_condition' => 'normal', 'availability' => 'desconhecida'], null);
    $rich = Ranking::evaluate(['price_original_cents' => 10000, 'price_promo_cents' => 7000, 'sales_min' => 1000, 'rating' => 4.6, 'rating_count' => 200, 'availability' => 'disponivel', 'payment_condition' => 'pix'], null);
    eq(5, $thin['score']);
    ok($rich['score'] > $thin['score'], "rico {$rich['score']} > pobre {$thin['score']}");
});
test('Ranking ordena a planilha real de 25 anúncios e mostra confiança parcial', function () use ($migrated, $importFile, $F) {
    $pdo = $migrated();
    $importFile($pdo, "$F/ml-25-anuncios.xlsx");
    $list = Ranking::list($pdo);
    ok(count($list) >= 25, 'lista');
    ok($list[0]['score'] >= $list[10]['score'], 'ordenado');
    ok($list[0]['confidence'] < 100, 'sem reputação/categoria = confiança parcial');
});

echo "\n== Auditoria\n";
test('Ações relevantes ficam registradas na auditoria', function () use ($migrated, $approveReady, $shopeeReadyId) {
    $pdo = $migrated();
    $id = $shopeeReadyId($pdo);
    $approveReady($pdo, $id);
    $acts = $pdo->query('SELECT action FROM gmax3_audit')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['migracao_aplicada', 'produto_criado', 'preco_confirmado', 'status_alterado'] as $a) {
        ok(in_array($a, $acts, true), "auditoria: $a");
    }
});

// ---------------------------------------------------------------- resumo
$pass = count(array_filter($GLOBALS['t_results'], fn($r) => $r[1]));
$total = count($GLOBALS['t_results']);
echo "\nResultado: $pass/$total testes aprovados\n";
file_put_contents(__DIR__ . '/../docs/resultado-testes-unidade.json', json_encode([
    'executado_em' => date('c'), 'php' => PHP_VERSION, 'sqlite' => Db::get()->query('SELECT sqlite_version()')->fetchColumn(),
    'aprovados' => $pass, 'total' => $total,
    'testes' => array_map(fn($r) => ['nome' => $r[0], 'ok' => $r[1], 'erro' => $r[2], 'segundos' => round($r[3], 3)], $GLOBALS['t_results']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($pass === $total ? 0 : 1);

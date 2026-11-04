<?php
declare(strict_types=1);
/*
 * Conferência rápida (3.1.5): regras do servidor para a conferência manual e filtros novos.
 * Execução: php -d allow_url_fopen=0 tests/conferencia_test.php
 */
require __DIR__ . '/harness.php';
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';

use Gmax\{Catalog, Db, Migrator, View};

$ready = static function (): array {
    $pdo = t_seed('hostgator');
    Migrator::up($pdo, 'teste');
    $id = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE external_id='22812345602'");
    return [$pdo, $id];
};
$ok = ['confirmo' => true, 'price_promo' => '39,90', 'price_original' => '59,90', 'payment_condition' => 'pix', 'availability' => 'disponivel'];

echo "\n== Conferência manual\n";
test('Sem confirmação explícita nada é gravado (nem preço, nem conferência, nem histórico)', function () use ($ready, $ok) {
    [$pdo, $id] = $ready();
    $snap = json_encode([Catalog::get($pdo, $id), Db::all($pdo, 'SELECT * FROM gmax3_price_history WHERE catalog_id=?', [$id])]);
    throws(fn() => Catalog::manualCheck($pdo, $id, ['confirmo' => false] + $ok, 'admin'), 'Nada foi gravado');
    eq($snap, json_encode([Catalog::get($pdo, $id), Db::all($pdo, 'SELECT * FROM gmax3_price_history WHERE catalog_id=?', [$id])]));
});
test('Conferência confirmada: grava como MANUAL (usuário e fonte conferencia_manual), nunca como API', function () use ($ready, $ok) {
    [$pdo, $id] = $ready();
    $r = Catalog::manualCheck($pdo, $id, $ok + ['observacao' => 'Frete grátis', 'store_name' => 'Loja X'], 'admin');
    $p = Catalog::get($pdo, $id);
    eq([3990, 5990, 'pix', 'disponivel', 'admin', 'conferencia_manual', 'Loja X'], [(int)$p['price_promo_cents'], (int)$p['price_original_cents'], $p['payment_condition'], $p['availability'], $p['price_verified_by'], $p['price_source'], $p['store_name']]);
    eq('manual', Catalog::verificationSource($p));
    ok(str_contains((string)$p['notes'], 'Frete grátis') && str_contains((string)$p['notes'], '[Conferência'), (string)$p['notes']);
    eq(1, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_price_history WHERE catalog_id=? AND verified=1 AND source='conferencia_manual'", [$id]));
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_price_history WHERE catalog_id=? AND verified=0 AND source='conferencia_manual'", [$id]), 'uma só linha por conferência (sem duplicata não conferida)');
    Catalog::manualCheck($pdo, $id, $ok, 'admin'); // mesma conferência repetida, sem mudança de preço
    eq(2, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_price_history WHERE catalog_id=? AND source='conferencia_manual'", [$id]), 'nova conferência sem mudança: um registro conferido');
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_price_history WHERE catalog_id=? AND source LIKE 'api_%'", [$id]));
    ok(in_array('price_promo_cents', $r['changed'], true) || in_array('price_original_cents', $r['changed'], true), json_encode($r['changed']));
    ok(str_contains(View::freshness($p, 24), 'src-manual') && !str_contains(View::freshness($p, 24), 'src-api'), 'selo manual');
});
test('Preço alterado retira a aprovação; link afiliado nunca é tocado', function () use ($ready, $ok) {
    [$pdo, $id] = $ready();
    $link = Catalog::get($pdo, $id)['affiliate_url'];
    Db::run($pdo, "UPDATE gmax3_catalog SET validation_status='aprovado', payment_condition='normal' WHERE id=?", [$id]);
    $r = Catalog::manualCheck($pdo, $id, ['price_promo' => '35,00', 'price_original' => ''] + $ok, 'admin');
    eq(['aprovado', 'aguardando_validacao'], [$r['status_before'], $r['status']]);
    eq($link, Catalog::get($pdo, $id)['affiliate_url']);
    eq(null, Catalog::get($pdo, $id)['price_original_cents'], '"De" vazio = sem preço anterior');
});
test('Validações: Por obrigatório, De ≥ Por, condição e disponibilidade válidas, variação descrita', function () use ($ready, $ok) {
    [$pdo, $id] = $ready();
    throws(fn() => Catalog::manualCheck($pdo, $id, ['price_promo' => ''] + $ok, 'a'), 'preço promocional');
    throws(fn() => Catalog::manualCheck($pdo, $id, ['price_promo' => 'abc'] + $ok, 'a'), 'preço promocional');
    throws(fn() => Catalog::manualCheck($pdo, $id, ['price_original' => '10,00'] + $ok, 'a'), 'não pode ser menor');
    throws(fn() => Catalog::manualCheck($pdo, $id, ['payment_condition' => 'boleto'] + $ok, 'a'), 'Condição');
    throws(fn() => Catalog::manualCheck($pdo, $id, ['availability' => ''] + $ok, 'a'), 'disponibilidade');
    throws(fn() => Catalog::manualCheck($pdo, $id, ['variation_ok' => true] + $ok, 'a'), 'Descreva a variação');
    eq(null, Catalog::get($pdo, $id)['price_verified_at'], 'nada gravado nas tentativas inválidas');
});
test('Variação definida remove a pendência com a descrição informada', function () use ($ready, $ok) {
    [$pdo, $id] = $ready();
    Db::run($pdo, 'UPDATE gmax3_catalog SET variation_pending=1 WHERE id=?', [$id]);
    Catalog::manualCheck($pdo, $id, ['variation_note' => 'Azul 1 L', 'variation_ok' => true] + $ok, 'admin');
    $p = Catalog::get($pdo, $id);
    eq([0, 'Azul 1 L'], [(int)$p['variation_pending'], $p['variation_note']]);
});
test('Validação pela API continua identificada como API', function () use ($ready) {
    [$pdo, $id] = $ready();
    Catalog::markVerifiedByApi($pdo, $id, 'shopee', 'automacao');
    $p = Catalog::get($pdo, $id);
    eq('api', Catalog::verificationSource($p));
    ok(str_contains(View::freshness($p, 24), 'API Shopee'), View::freshness($p, 24));
});

echo "\n== Botões das lojas e filtros\n";
test('Botões: só URLs oficiais https; javascript:/outros domínios nunca viram link', function () use ($ready) {
    [$pdo, $id] = $ready();
    $r = Catalog::get($pdo, $id);
    $h = View::storeButtons($r);
    ok(str_contains($h, 'href="' . View::e($r['original_url']) . '"') && str_contains($h, 'rel="noopener noreferrer"') && str_contains($h, 'target="_blank"'), $h);
    foreach (['javascript:alert(1)', 'http://shopee.com.br/product/1/2', 'https://evil.com/shopee.com.br', 'https://user@shopee.com.br/product/1/2', 'data:text/html,x'] as $bad) {
        $x = View::storeButtons(['original_url' => $bad, 'affiliate_url' => $bad] + $r);
        ok(!str_contains($x, '<a ') && str_contains($x, 'Sem URL do anúncio') && str_contains($x, 'Sem link afiliado'), $bad);
    }
    $ml = View::storeButtons(['marketplace' => 'mercadolivre', 'original_url' => 'https://lista.mercadolivre.com.br/fone', 'affiliate_url' => 'https://meli.la/1uPYV76']);
    ok(!str_contains($ml, 'btn-store') && str_contains($ml, 'https://meli.la/1uPYV76'), 'página de busca não é anúncio; afiliado separado');
});
test('Filtros novos: disponibilidade e variação pendente', function () use ($ready) {
    [$pdo, $id] = $ready();
    Db::run($pdo, "UPDATE gmax3_catalog SET availability='indisponivel', variation_pending=1 WHERE id=?", [$id]);
    eq([$id], array_map(fn($r) => (int)$r['id'], Catalog::search($pdo, ['avail' => 'indisponivel'])['rows']));
    eq([$id], array_map(fn($r) => (int)$r['id'], Catalog::search($pdo, ['variation' => '1'])['rows']));
    eq(6, Catalog::search($pdo, ['avail' => 'xss<script>'])['total'], 'valor inválido ignorado');
});

$pass = count(array_filter($GLOBALS['t_results'], fn($r) => $r[1]));
$total = count($GLOBALS['t_results']);
echo "\nResultado conferência: $pass/$total testes aprovados\n";
file_put_contents(__DIR__ . '/../docs/resultado-testes-conferencia.json', json_encode([
    'executado_em' => date('c'), 'php' => PHP_VERSION, 'aprovados' => $pass, 'total' => $total,
    'testes' => array_map(fn($r) => ['nome' => $r[0], 'ok' => $r[1], 'erro' => $r[2], 'segundos' => round($r[3], 3)], $GLOBALS['t_results']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($pass === $total ? 0 : 1);

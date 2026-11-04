<?php
declare(strict_types=1);
/*
 * Calculadora de preços (3.1.6): modos "preço original da loja" e "calcular pelo desconto %".
 * Execução: php -d allow_url_fopen=0 tests/calculadora_test.php
 */
require __DIR__ . '/harness.php';
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';

use Gmax\{Catalog, Db, Message, Migrator, Money, Pricing, View};

$ready = static function (): array {
    $pdo = t_seed('hostgator');
    Migrator::up($pdo, 'teste');
    return [$pdo, (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE external_id='22812345602'"), (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE marketplace='mercadolivre'")];
};
$base = ['confirmo' => true, 'payment_condition' => 'normal', 'availability' => 'disponivel'];

echo "\n== Cálculo\n";
test('Percentual: aceita 35, 35%, 35,5 e 35.25; recusa 0, 96, texto e 3 casas', function () {
    eq([7000, 0], Pricing::parsePercent('35'));
    eq([7000, 0], Pricing::parsePercent(' 35 % '));
    eq([7100, 1], Pricing::parsePercent('35,5'));
    eq([7050, 2], Pricing::parsePercent('35.25'));
    foreach (['0', '0,5', '96', '100', 'abc', '35,555', '-10', '', '1e2'] as $bad) {
        eq(null, Pricing::parsePercent($bad), $bad);
    }
    ok(Pricing::parsePercent('1') !== null && Pricing::parsePercent('95') !== null, 'limites 1 e 95');
});
test('Estimativa exata ao centavo, com a faixa do arredondamento da loja', function () {
    $e = Pricing::estimate(9990, '35'); // 99,90 com 35% => 99,90 / 0,65
    eq([15369, 15252, 15488, 5379, '35'], [$e['original'], $e['min'], $e['max'], $e['economia'], $e['percentual']]);
    $e = Pricing::estimate(9990, '35,5'); // faixa 35,45% a 35,55%
    eq(15488, $e['original']);
    ok($e['min'] < $e['original'] && $e['original'] < $e['max'] && $e['max'] - $e['min'] < 60, json_encode($e));
    eq(5366, Pricing::estimate(4990, '7')['original']);
    throws(fn() => Pricing::estimate(4990, '99'), 'Percentual');
});
test('Modo preço original: desconto e economia pela regra existente (percentual arredondado para baixo)', function () {
    eq(['percent' => 33, 'savings' => 5000], array_intersect_key(Money::discount(14990, 9990), ['percent' => 1, 'savings' => 1]));
    $r = Pricing::resolve('original', 9990, '149,90', '', null);
    eq([14990, null, []], [$r['original'], $r['estimativa'], $r['erros']]);
    ok(str_contains(implode(' ', Pricing::resolve('original', 9990, '50,00', '', null)['erros']), 'menor que o promocional'), 'De < Por');
    ok(str_contains(implode(' ', Pricing::resolve('original', 9990, 'abc', '', null)['erros']), 'inválido'), 'De inválido');
    ok(str_contains(implode(' ', Pricing::resolve('desconto', null, '', '35', null)['erros']), 'preço promocional'), 'Por obrigatório no modo desconto');
    ok(str_contains(implode(' ', Pricing::resolve('desconto', 9990, '', '120', null)['erros']), 'Percentual de desconto inválido'), '% fora da faixa');
    ok(str_contains(implode(' ', Pricing::resolve('xyz', 9990, '', '', null)['erros']), 'Modo'), 'modo inválido');
});

echo "\n== Regras de origem e de gravação\n";
test('Origem do "De": API só para o Mercado Livre; Shopee validada pela API continua "importado"', function () {
    eq('api', Pricing::originalSource(['marketplace' => 'mercadolivre', 'price_original_cents' => 1000, 'price_source' => 'api_mercadolivre']));
    eq('importado', Pricing::originalSource(['marketplace' => 'shopee', 'price_original_cents' => 1000, 'price_source' => 'api_shopee', 'price_verified_at' => 'x', 'price_verified_by' => 'api:shopee']));
    eq('manual', Pricing::originalSource(['marketplace' => 'shopee', 'price_original_cents' => 1000, 'price_source' => 'conferencia_manual']));
    eq('importado', Pricing::originalSource(['marketplace' => 'shopee', 'price_original_cents' => 1000, 'price_source' => 'legado_offers', 'price_verified_at' => null]));
    eq(null, Pricing::originalSource(['marketplace' => 'shopee', 'price_original_cents' => null]));
});
test('Conferência no modo desconto: "De" estimado NÃO é gravado como preço original; estimativa registrada à parte', function () use ($ready, $base) {
    [$pdo, $id] = $ready();
    Db::run($pdo, 'UPDATE gmax3_catalog SET price_original_cents=8990 WHERE id=?', [$id]); // "De" antigo não conferido
    $res = Catalog::manualCheck($pdo, $id, $base + ['modo' => 'desconto', 'price_promo' => '99,90', 'desconto_pct' => '35', 'price_original' => '149,90'], 'admin');
    $p = Catalog::get($pdo, $id);
    eq([9990, null], [(int)$p['price_promo_cents'], $p['price_original_cents']], 'só o Por (conferido) é gravado; valor do campo De é ignorado no modo desconto');
    eq(15369, $res['estimativa']['original']);
    $e = Pricing::latestEstimate($pdo, $p);
    eq([15369, '35', false, 'conferencia'], [$e['de_estimado'], $e['percentual'], $e['confirmado'], $e['origem']]);
    eq('manual', Catalog::verificationSource($p), 'conferência manual do Por');
    eq(0, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_price_history WHERE price_original_cents=15369'), 'estimativa fora do histórico de preços');
    $msg = Message::render($p, $pdo);
    ok(!str_contains($msg, '153,69') && !str_contains($msg, 'De R$'), 'estimativa nunca aparece na mensagem de publicação: ' . $msg);
});
test('Preço original confirmado pela API é preservado no modo desconto', function () use ($ready, $base) {
    [$pdo, , $ml] = $ready();
    Db::run($pdo, "UPDATE gmax3_catalog SET price_original_cents=29990, price_promo_cents=10431, price_source='api_mercadolivre', price_verified_at=datetime('now'), price_verified_by='api:mercadolivre' WHERE id=?", [$ml]);
    $res = Catalog::manualCheck($pdo, $ml, $base + ['modo' => 'desconto', 'price_promo' => '104,31', 'desconto_pct' => '60'], 'admin');
    eq([29990, true], [(int)Catalog::get($pdo, $ml)['price_original_cents'], $res['preservado_api']]);
    eq(['api', 'manual'], [Pricing::originalSource(Catalog::get($pdo, $ml)), Catalog::verificationSource(Catalog::get($pdo, $ml))], '"De" continua da API; "Por" conferido à mão');
    ok(str_contains(View::priceCalculator(['uid' => 't', 'row' => Catalog::get($pdo, $ml), 'estimate' => null, 'mode' => 'desconto', 'pct' => '', 'promo' => '', 'original' => '']), 'confirmado pela API'), 'aviso na tela');
});
test('Sem confirmação explícita, nem o modo desconto grava nada (nem a estimativa)', function () use ($ready, $base) {
    [$pdo, $id] = $ready();
    $n = (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_audit');
    $snap = json_encode(Catalog::get($pdo, $id));
    throws(fn() => Catalog::manualCheck($pdo, $id, ['confirmo' => false, 'modo' => 'desconto', 'price_promo' => '99,90', 'desconto_pct' => '35'] + $base, 'admin'), 'Nada foi gravado');
    eq([$snap, $n], [json_encode(Catalog::get($pdo, $id)), (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_audit')]);
    throws(fn() => Catalog::manualCheck($pdo, $id, ['modo' => 'desconto', 'price_promo' => '99,90', 'desconto_pct' => '0'] + $base, 'admin'), 'Percentual');
    eq($snap, json_encode(Catalog::get($pdo, $id)), '% inválido: nada gravado');
});
test('Estimativa deixa de valer quando o preço Por muda', function () use ($ready, $base) {
    [$pdo, $id] = $ready();
    Catalog::manualCheck($pdo, $id, $base + ['modo' => 'desconto', 'price_promo' => '99,90', 'desconto_pct' => '35'], 'admin');
    ok(Pricing::latestEstimate($pdo, Catalog::get($pdo, $id)) !== null, 'vale para 99,90');
    Catalog::manualCheck($pdo, $id, $base + ['modo' => 'original', 'price_promo' => '89,90', 'price_original' => '129,90'], 'admin');
    $p = Catalog::get($pdo, $id);
    eq([8990, 12990, null], [(int)$p['price_promo_cents'], (int)$p['price_original_cents'], Pricing::latestEstimate($pdo, $p)]);
    eq('manual', Pricing::originalSource($p));
});
test('Tela: os dois modos no mesmo formulário (valores preservados) e selo "Calculado ≈" na última estimativa', function () use ($ready, $base) {
    [$pdo, $id] = $ready();
    Catalog::manualCheck($pdo, $id, $base + ['modo' => 'desconto', 'price_promo' => '99,90', 'desconto_pct' => '35'], 'admin');
    $p = Catalog::get($pdo, $id);
    $h = View::priceCalculator(['uid' => 'x', 'row' => $p, 'estimate' => Pricing::latestEstimate($pdo, $p), 'mode' => 'desconto', 'pct' => '35', 'promo' => '99,90', 'original' => '149,90']);
    ok(str_contains($h, 'name="price_original"') && str_contains($h, 'value="149,90"') && str_contains($h, 'name="desconto_pct"') && str_contains($h, 'value="35"'), 'dois campos com valores');
    ok(str_contains($h, 'value="desconto" checked') && str_contains($h, 'Calculado ≈') && str_contains($h, 'R$ 153,69'), 'modo e estimativa');
    ok(!str_contains($h, 'style='), 'sem estilo em linha (CSP)');
});

$pass = count(array_filter($GLOBALS['t_results'], fn($r) => $r[1]));
$total = count($GLOBALS['t_results']);
echo "\nResultado calculadora: $pass/$total testes aprovados\n";
file_put_contents(__DIR__ . '/../docs/resultado-testes-calculadora.json', json_encode([
    'executado_em' => date('c'), 'php' => PHP_VERSION, 'aprovados' => $pass, 'total' => $total,
    'testes' => array_map(fn($r) => ['nome' => $r[0], 'ok' => $r[1], 'erro' => $r[2], 'segundos' => round($r[3], 3)], $GLOBALS['t_results']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($pass === $total ? 0 : 1);

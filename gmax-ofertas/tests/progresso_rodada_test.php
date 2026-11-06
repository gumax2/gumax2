<?php
declare(strict_types=1);
/*
 * Regressão do defeito relatado na 3.2.2.1 (produção): 89 produtos, "Conferir em sequência (4)",
 * 4 conferidos, sequência concluída, de volta à fila → os MESMOS 4 de novo.
 * Pelo servidor PHP real, sem JavaScript. Funciona com as duas versões:
 *  - 3.2.2.1: botão seq_start ("Conferir em sequência");
 *  - 3.2.2.2: rodada persistente (round_start / round_continue).
 * Exige: a segunda leva não contém nenhum dos 4 já analisados. Execução: php tests/progresso_rodada_test.php
 */
require __DIR__ . '/harness.php';
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';

use Gmax\{Catalog, Db, Migrator};

$pdo = t_seed('hostgator');
Migrator::up($pdo, 't');
$pdo->exec("UPDATE gmax3_catalog SET validation_status='arquivado'"); // cenário com exatamente os 89 produtos
for ($i = 1; $i <= 89; $i++) {
    $id = Catalog::insert($pdo, ['marketplace' => 'shopee', 'external_id' => (string)(93000000000 + $i), 'title' => "Produto $i", 'category' => 'C',
        'price_promo_cents' => 1990, 'payment_condition' => 'normal', 'availability' => 'disponivel'], 'aguardando_validacao', 't');
    if ($i <= 4) { // 4 precisam de conferência (variação pendente; continuam pendentes depois de conferidos)
        Db::run($pdo, "UPDATE gmax3_catalog SET variation_pending=1, affiliate_url='https://s.shopee.com.br/Pr' || id, affiliate_norm='s.shopee.com.br/Pr' || id WHERE id=?", [$id]);
    } else { // os demais têm outras pendências (ex.: sem link afiliado)
        Db::run($pdo, "UPDATE gmax3_catalog SET price_verified_at=datetime('now'), price_verified_by='api:shopee', price_source='api_shopee' WHERE id=?", [$id]);
    }
}
$app = t_app();
$port = 19300 + random_int(0, 299);
$env = ['GMAX_SECRETS_DIR' => t_sandbox() . '/gmax-secrets', 'GMAX_TESTING' => '1', 'PATH' => getenv('PATH')];
@mkdir(t_sandbox() . '/sessoes', 0700);
$srv = proc_open([PHP_BINARY, '-d', 'session.save_path=' . t_sandbox() . '/sessoes', '-S', "127.0.0.1:$port", '-t', $app], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p, $app, $env);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}
$jar = '';
$req = static function (string $m, string $path, array $post = []) use ($port, &$jar): array {
    $ctx = stream_context_create(['http' => ['method' => $m, 'header' => "Cookie: $jar\r\nContent-Type: application/x-www-form-urlencoded\r\n", 'content' => http_build_query($post), 'ignore_errors' => true, 'follow_location' => 0]]);
    $b = (string)@file_get_contents("http://127.0.0.1:$port/" . ltrim($path, '/'), false, $ctx);
    $loc = null;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/i', $h, $mm)) {
            $jar = $mm[1];
        }
        if (preg_match('/^Location: (.+)$/i', $h, $mm)) {
            $loc = ltrim(str_replace("http://127.0.0.1:$port/", '', trim($mm[1])), '/');
        }
    }
    return [$b, $loc];
};
$csrf = static fn(string $h) => preg_match('/name="csrf" value="([^"]+)"/', $h, $m) ? $m[1] : '';
/** Inicia/continua pela fila e confere $n produtos; devolve os IDs visitados. */
$batch = static function (int $n) use ($req, $csrf): array {
    [$fila] = $req('GET', 'painel.php?p=fila');
    if (str_contains($fila, 'value="round_continue"')) {
        [, $url] = $req('POST', 'painel.php', ['action' => 'round_continue', 'back' => 'fila', 'csrf' => $csrf($fila)]);
    } elseif (str_contains($fila, 'value="round_start"')) {
        [, $url] = $req('POST', 'painel.php', ['action' => 'round_start', 'back' => 'fila', 'csrf' => $csrf($fila)]);
    } else {
        [, $url] = $req('POST', 'painel.php', ['action' => 'seq_start', 'back' => 'fila', 'csrf' => $csrf($fila)]); // 3.2.2.1
    }
    $seen = [];
    while ($url && str_contains($url, 'p=conferir') && count($seen) < $n) {
        [$page] = $req('GET', $url);
        preg_match('/name="id" value="(\d+)"/', $page, $mi);
        preg_match('/name="v" value="([0-9a-f]+)"/', $page, $mv);
        $seen[] = (int)($mi[1] ?? 0);
        $post = ['action' => 'offer_check', 'back' => 'conferir', 'id' => $mi[1] ?? '', 'v' => $mv[1] ?? '', 'csrf' => $csrf($page),
            'confirmo' => '1', 'price_promo' => '19,90', 'price_mode' => 'original', 'payment_condition' => 'normal', 'availability' => 'disponivel'];
        foreach (['seq', 'rodada', 'rev'] as $k) {
            if (preg_match('/name="' . $k . '" value="([^"]+)"/', $page, $mx)) {
                $post[$k] = $mx[1];
            }
        }
        [, $url] = $req('POST', 'painel.php', $post);
    }
    return $seen;
};
test('Produção (89 produtos): depois de conferir 4 e voltar à fila, a próxima leva NÃO repete nenhum dos 4', function () use ($req, $csrf, $batch) {
    [$b] = $req('GET', 'painel.php');
    $req('POST', 'painel.php', ['action' => 'login', 'password' => T_PASSWORD, 'csrf' => $csrf($b)]);
    $first = $batch(4);
    eq(4, count($first), 'primeira leva com 4');
    $second = $batch(4);
    $GLOBALS['prog'] = ['primeira' => $first, 'segunda' => $second];
    eq(4, count($second), 'segunda leva com 4 (há 85 ainda não analisados): ' . implode(',', $second));
    eq([], array_values(array_intersect($first, $second)), 'nenhum dos 4 primeiros se repete (1ª: ' . implode(',', $first) . ' · 2ª: ' . implode(',', $second) . ')');
});
proc_terminate($srv);
$pass = count(array_filter($GLOBALS['t_results'], fn($r) => $r[1]));
$total = count($GLOBALS['t_results']);
echo "\nResultado progresso entre sequências: $pass/$total testes aprovados\n";
file_put_contents(__DIR__ . '/../docs/resultado-testes-progresso-rodada.json', json_encode(['executado_em' => date('c'), 'php' => PHP_VERSION, 'aprovados' => $pass, 'total' => $total,
    'levas' => $GLOBALS['prog'] ?? null, 'testes' => array_map(fn($r) => ['nome' => $r[0], 'ok' => $r[1], 'erro' => $r[2], 'segundos' => round($r[3], 3)], $GLOBALS['t_results'])], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($pass === $total ? 0 : 1);

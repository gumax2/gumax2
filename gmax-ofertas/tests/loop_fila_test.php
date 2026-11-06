<?php
declare(strict_types=1);
/*
 * Regressão do defeito da 3.2.2 ("Salvar e ir para o próximo" entra em ciclo).
 * Fluxo real pelo servidor PHP, sem JavaScript: 10 produtos que CONTINUAM pendentes depois de
 * conferidos (variação), conferidos em sequência. Funciona com as duas versões:
 * 3.2.2 (link "Conferir agora" + fila_next) e 3.2.2.1 (sequência iniciada por POST seq_start).
 * Na 3.2.2 FALHA (ciclo); na 3.2.2.1 passa. Execução: php tests/loop_fila_test.php
 */
require __DIR__ . '/harness.php';
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';

use Gmax\{Catalog, Db, Migrator};

$pdo = t_seed('hostgator');
Migrator::up($pdo, 't');
$ids = [];
for ($i = 1; $i <= 10; $i++) {
    $ids[] = $id = Catalog::insert($pdo, ['marketplace' => 'shopee', 'external_id' => (string)(91000000000 + $i), 'title' => "Loop $i", 'category' => 'Seq Loop',
        'price_promo_cents' => 1990, 'payment_condition' => 'normal', 'availability' => 'disponivel'], 'aguardando_validacao', 't');
    Db::run($pdo, "UPDATE gmax3_catalog SET variation_pending=1, affiliate_url='https://s.shopee.com.br/Loop' || id, affiliate_norm='s.shopee.com.br/Loop' || id WHERE id=?", [$id]);
}
$app = t_app();
$port = 19600 + random_int(0, 299);
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
test('Defeito 3.2.2: "salvar e ir para o próximo" percorre os 10 produtos uma única vez e termina (sem ciclo)', function () use ($req, $csrf, $ids) {
    [$b] = $req('GET', 'painel.php');
    $req('POST', 'painel.php', ['action' => 'login', 'password' => T_PASSWORD, 'csrf' => $csrf($b)]);
    [$b] = $req('GET', 'painel.php?p=fila&cat=Seq+Loop');
    if (preg_match('~href="(painel\.php\?p=conferir[^"]+fila=1[^"]*)"[^>]*>Conferir agora~', html_entity_decode($b), $m)) {
        $url = html_entity_decode($m[1]); // 3.2.2
    } else {
        preg_match('/name="action" value="seq_start"><input type="hidden" name="from" value="(\d+)"/', $b, $mf);
        [, $url] = $req('POST', 'painel.php', ['action' => 'seq_start', 'from' => $mf[1] ?? '', 'back' => 'fila', 'csrf' => $csrf($b)]); // 3.2.2.1
    }
    $seen = [];
    for ($step = 0; $step < 30 && $url && str_contains($url, 'p=conferir'); $step++) {
        [$page] = $req('GET', $url);
        preg_match('/name="id" value="(\d+)"/', $page, $mi);
        preg_match('/name="v" value="([0-9a-f]+)"/', $page, $mv);
        $seen[] = (int)($mi[1] ?? 0);
        $post = ['action' => 'offer_check', 'back' => 'conferir', 'id' => $mi[1] ?? '', 'v' => $mv[1] ?? '', 'csrf' => $csrf($page), 'fila_next' => '1',
            'confirmo' => '1', 'price_promo' => '19,90', 'price_mode' => 'original', 'payment_condition' => 'normal', 'availability' => 'disponivel'];
        foreach (['seq', 'pos', 'fila'] as $k) {
            if (preg_match('/name="' . $k . '" value="([^"]+)"/', $page, $mx)) {
                $post[$k] = $mx[1];
            }
        }
        [, $url] = $req('POST', 'painel.php', $post);
    }
    [$end] = $url ? $req('GET', $url) : [''];
    $GLOBALS['loop_seen'] = $seen;
    eq(count($seen), count(array_unique($seen)), 'nenhum produto repetido: ' . implode(',', $seen));
    eq(10, count(array_unique($seen)), 'todos os 10 visitados');
    ok(str_contains($end, 'Conferência da sequência concluída'), 'termina com "Conferência da sequência concluída" (último destino: ' . $url . ')');
});
proc_terminate($srv);
$pass = count(array_filter($GLOBALS['t_results'], fn($r) => $r[1]));
$total = count($GLOBALS['t_results']);
echo "\nResultado loop da fila: $pass/$total testes aprovados\n";
file_put_contents(__DIR__ . '/../docs/resultado-testes-loop-fila.json', json_encode(['executado_em' => date('c'), 'php' => PHP_VERSION, 'aprovados' => $pass, 'total' => $total,
    'visitados' => $GLOBALS['loop_seen'] ?? [], 'testes' => array_map(fn($r) => ['nome' => $r[0], 'ok' => $r[1], 'erro' => $r[2], 'segundos' => round($r[3], 3)], $GLOBALS['t_results'])], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($pass === $total ? 0 : 1);

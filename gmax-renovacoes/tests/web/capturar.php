<?php
/**
 * Gera as capturas de tela da aba Renovações em docs/capturas/ (celular-*.png e desktop-*.png).
 * Uso: php tests/web/capturar.php
 * Banco de teste novo (grtest_*), dados fictícios, provedor simulado, servidor PHP embutido temporário.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/lib.php';
require_once __DIR__ . '/ambiente.php';

$t = new T();
$exit = 1;
$web = GrWeb::get();
try {
    $web->useTest($t);
    GrWeb::seed($t);
    $http = $web->client()->login();
    $r = $http->api('executar_agora', ['ignorar_horario' => true]);
    echo 'executar_agora: HTTP ' . $r['status'] . ' ' . $r['body'] . "\n";
    $out = dirname(__DIR__, 2) . '/docs/capturas';
    $cmd = 'node ' . escapeshellarg(__DIR__ . '/capturas.js') . ' ' . escapeshellarg($web->base) . ' ' . escapeshellarg($out) . ' 2>&1';
    $lines = [];
    exec($cmd, $lines, $code);
    $rel = json_decode((string)end($lines), true);
    echo json_encode($rel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    if ($web->phpErrors() !== '') {
        echo "Erros PHP do servidor:\n" . $web->phpErrors();
    }
    $exit = ($code === 0 && $rel && !$rel['erros']) ? 0 : 1;
    echo "Capturas em $out\n";
} finally {
    $web->stop();
    $t->drop();
}
exit($exit);

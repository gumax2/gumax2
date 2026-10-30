<?php
/**
 * Executa todos os testes (tests/t_*.php) e grava o relatório em docs/TESTES_RESULTADO.md.
 * Requer MariaDB/MySQL local com usuário de teste (GR_TEST_HOST/GR_TEST_USER/GR_TEST_PASS).
 */
declare(strict_types=1);

require __DIR__ . '/lib.php';

$only = $argv[1] ?? null;
$files = glob(__DIR__ . '/t_*.php');
sort($files);
foreach ($files as $f) {
    if ($only && strpos(basename($f), $only) === false) {
        continue;
    }
    echo "\n== " . basename($f) . "\n";
    require $f;
}

$ok = count(array_filter(T::$results, fn($r) => $r[1] === true));
$skipped = count(array_filter(T::$results, fn($r) => $r[1] === null));
$total = count(T::$results) - $skipped;
echo "\n$ok/$total testes passaram." . ($skipped ? " $skipped PULADO(S) — não executado(s), ver motivo acima." : '') . "\n";

if (!$only) {
    $md = "# Resultado dos testes executados\n\n";
    $md .= "Gerado automaticamente por `php tests/run.php` em " . date('d/m/Y H:i') . " (America/Sao_Paulo).\n\n";
    $md .= "Ambiente: PHP " . PHP_VERSION . ", " . (new PDO('mysql:host=' . T::env('GR_TEST_HOST', 'localhost'), T::env('GR_TEST_USER', 'grtest'), T::env('GR_TEST_PASS', 'grtest')))->query('SELECT VERSION()')->fetchColumn() . ". Dados fictícios. Nenhuma chamada de rede.\n\n";
    $md .= "**$ok de $total testes executados passaram.**" . ($skipped ? " **$skipped teste(s) NÃO executado(s)** (marcados como PULADO, com o motivo)." : ' Nenhum teste pulado.') . "\n\n| # | Teste | Resultado | Tempo |\n|---|---|---|---|\n";
    foreach (T::$results as $i => [$name, $pass, $err, $time]) {
        $md .= '| ' . ($i + 1) . ' | ' . str_replace('|', '\\|', $name) . ' | ' . ($pass === null ? '⏭ PULADO: ' . str_replace('|', '\\|', $err) : ($pass ? '✅ passou' : '❌ ' . str_replace('|', '\\|', $err))) . ' | ' . number_format($time, 2) . 's |' . "\n";
    }
    file_put_contents(dirname(__DIR__) . '/docs/TESTES_RESULTADO.md', $md);
}
exit($ok === $total ? 0 : 1);

<?php
declare(strict_types=1);
/*
 * GMAX Ofertas 3.0 (P0) — bootstrap comum do painel multiloja e das ferramentas CLI.
 * Reaproveita a conexão db() do app.php instalado; não altera app.php, index.php,
 * cron.php nem o publicador V2.1.
 */
if (!defined('GMAX_APP_DIR')) {
    define('GMAX_APP_DIR', dirname(__DIR__));
}
const GMAX_VERSION = '3.1.1';

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Gmax\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require_once GMAX_APP_DIR . '/app.php';
date_default_timezone_set('America/Sao_Paulo');
if (PHP_SAPI !== 'cli' && getenv('GMAX_TESTING') !== '1') {
    ini_set('display_errors', '0'); // erros vão para o log do servidor, nunca para a tela
}

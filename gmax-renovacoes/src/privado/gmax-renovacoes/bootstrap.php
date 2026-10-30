<?php
/**
 * GMAX Renovações — ponto de entrada da biblioteca privada.
 *
 * Esta pasta deve ficar FORA da pasta pública do site (ex.: /home1/gust1922/gmax-renovacoes; o painel fica em /home1/gust1922/gmaxcanais.com.br/painel).
 * Os arquivos públicos em /painel/ apenas incluem este bootstrap.
 */
declare(strict_types=1);

if (!defined('GR_ROOT')) {
    define('GR_ROOT', __DIR__);
}
define('GR_VERSION', '1.2.0');

if (PHP_SAPI !== 'cli') {
    // Nunca exibir avisos do PHP na web (revelariam caminhos privados); tudo vai para o log.
    ini_set('display_errors', '0');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'GmaxRenov\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = GR_ROOT . '/lib/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/**
 * Carrega o App a partir do config.php privado (ou de um caminho explícito, usado nos testes).
 */
function gr_app(?string $configPath = null): \GmaxRenov\App
{
    static $apps = [];
    if ($configPath === null) {
        // GR_CONFIG só é aceito na linha de comando (testes/CLI); na web vale sempre o config.php privado.
        $env = PHP_SAPI === 'cli' ? getenv('GR_CONFIG') : false;
        $configPath = $env ?: GR_ROOT . '/config.php';
    }
    if (!isset($apps[$configPath])) {
        if (!is_file($configPath)) {
            throw new \RuntimeException('Arquivo de configuração não encontrado. Copie config.example.php para config.php.');
        }
        $config = require $configPath;
        $apps[$configPath] = new \GmaxRenov\App($config);
    }
    return $apps[$configPath];
}

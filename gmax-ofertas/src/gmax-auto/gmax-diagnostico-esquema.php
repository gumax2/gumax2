<?php
declare(strict_types=1);
/*
 * GMAX Ofertas — diagnóstico de ESQUEMA do banco (somente CLI, somente leitura).
 * Exporta: versões, extensões, tabelas, colunas, índices, quantidade de linhas e
 * distribuição de estados (status). NÃO exporta nenhum valor de produto, link, preço,
 * senha, hash, token ou mensagem. Não carrega app.php e abre o banco em modo leitura.
 *
 * Uso (Cron temporário no cPanel, uma única vez):
 *   /usr/local/bin/php /home1/gust1922/gmaxcanais.com.br/gmax-auto/gmax-diagnostico-esquema.php > /home1/gust1922/gmax-secrets/gmax-esquema.txt 2>&1
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Apenas CLI');
}

$app = __DIR__;
$out = static function (string $line = ''): void {
    echo $line, PHP_EOL;
};
$out('GMAX — DIAGNÓSTICO DE ESQUEMA (sem dados) — ' . date('c'));
$out('PHP ' . PHP_VERSION . ' · SAPI ' . PHP_SAPI . ' · ' . PHP_OS_FAMILY);
foreach (['pdo_sqlite', 'sqlite3', 'mbstring', 'json', 'zip', 'curl', 'openssl', 'dom', 'simplexml'] as $ext) {
    $out(sprintf('  extensão %-10s %s', $ext, extension_loaded($ext) ? 'sim' : 'NÃO'));
}
$out('  allow_url_fopen=' . ini_get('allow_url_fopen') . ' memory_limit=' . ini_get('memory_limit') . ' upload_max_filesize=' . ini_get('upload_max_filesize') . ' post_max_size=' . ini_get('post_max_size'));
$out('  session.save_handler=' . ini_get('session.save_handler') . ' session.name=' . ini_get('session.name') . ' session.cookie_path=' . ini_get('session.cookie_path'));

$files = glob($app . '/storage/*.sqlite') ?: [];
if (!$files) {
    $out('ERRO: nenhum arquivo .sqlite em storage/');
    exit(1);
}
foreach ($files as $file) {
    $out();
    $out('=== Banco: storage/' . basename($file) . ' · ' . filesize($file) . ' bytes · permissão ' . sprintf('%04o', fileperms($file) & 0777));
    try {
        $pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
        ]);
    } catch (Throwable $e) {
        $out('  ERRO ao abrir em modo leitura: ' . get_class($e));
        continue;
    }
    $pdo->exec('PRAGMA query_only = ON');
    $out('  SQLite ' . $pdo->query('SELECT sqlite_version()')->fetchColumn()
        . ' · journal_mode=' . $pdo->query('PRAGMA journal_mode')->fetchColumn()
        . ' · quick_check=' . $pdo->query('PRAGMA quick_check')->fetchColumn());
    $objects = $pdo->query("SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type DESC, name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($objects as $o) {
        if ($o['type'] !== 'table') {
            continue;
        }
        $t = $o['name'];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) {
            $out("  tabela com nome não usual ignorada");
            continue;
        }
        $count = (int)$pdo->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn();
        $out();
        $out("  TABELA $t ($count linha(s))");
        $out('    SQL: ' . preg_replace('/\s+/', ' ', (string)$o['sql']));
        $cols = $pdo->query("PRAGMA table_info(\"$t\")")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            $out(sprintf('    - %-28s %-8s %s%s', $c['name'], $c['type'], $c['notnull'] ? 'NOT NULL ' : '', $c['pk'] ? 'PK' : ''));
        }
        // Distribuição de colunas de estado: somente valores curtos de controle (ex.: pendente, enviado).
        foreach (['status', 'state', 'validation_status'] as $sc) {
            if (in_array($sc, array_column($cols, 'name'), true)) {
                foreach ($pdo->query("SELECT \"$sc\" v, COUNT(*) n FROM \"$t\" GROUP BY \"$sc\"")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $v = (string)$row['v'];
                    $v = preg_match('/^[a-z_]{1,30}$/', $v) ? $v : '(valor não padronizado)';
                    $out("    $sc=$v: {$row['n']}");
                }
            }
        }
    }
    foreach ($objects as $o) {
        if ($o['type'] === 'index' && $o['sql']) {
            $out('  ÍNDICE ' . $o['name'] . ' em ' . $o['tbl_name'] . ': ' . preg_replace('/\s+/', ' ', (string)$o['sql']));
        }
    }
}
$out();
$out('Arquivos da aplicação (hash SHA-256, 16 primeiros caracteres):');
foreach (['app.php', 'index.php', 'cron.php', '.htaccess', 'gmax-publicador-cli.php', 'mercadolivre.php', 'gmax-ml-importar-cli.php', 'painel.php'] as $f) {
    $out(sprintf('  %-26s %s', $f, is_file("$app/$f") ? substr((string)hash_file('sha256', "$app/$f"), 0, 16) : 'ausente'));
}
$secrets = dirname($app, 2) . '/gmax-secrets';
$out();
$out('Pasta privada: ' . (is_dir($secrets) ? 'existe, permissão ' . sprintf('%04o', fileperms($secrets) & 0777) : 'não encontrada em ' . $secrets));
foreach (['gmax-publicacao-privada.php', 'gmax3-publicacao-privada.php', 'gmax-publicacao.pause', 'telegram.php', 'shopee-api.php', 'mercadolivre-api.php'] as $f) {
    $out(sprintf('  %-30s %s', $f, is_file("$secrets/$f") ? 'EXISTE (conteúdo não lido)' : 'ausente'));
}
$out();
$out('FIM — nenhum dado de produto, link, preço, senha ou token foi exportado.');

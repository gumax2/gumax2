<?php
declare(strict_types=1);
/*
 * Ambiente de teste isolado: cria uma cópia da aplicação com os arquivos de REFERÊNCIA
 * (app.php, index.php, cron.php, publicador V2.1, mercadolivre.php) + o pacote 3.0, e um
 * banco que reproduz o estado declarado da produção (5 Shopee, 1 Mercado Livre, registros
 * do publicador). GMAX_TESTING=1 proíbe transporte real; allow_url_fopen deve estar desligado.
 */
const T_ROOT = __DIR__ . '/..';

function t_sandbox(): string
{
    static $dir = null;
    if ($dir) {
        return $dir;
    }
    $dir = sys_get_temp_dir() . '/gmax-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
    $app = "$dir/gmaxcanais.com.br/gmax-auto";
    mkdir($app, 0700, true);
    mkdir("$dir/gmax-secrets", 0700, true);
    $ref = T_ROOT . '/referencia';
    $map = [
        'base_inicial/app.php' => 'app.php', 'painel_v2_2/index.php' => 'index.php', 'base_inicial/cron.php' => 'cron.php',
        'base_inicial/.htaccess' => '.htaccess', 'publicador_v2_1/gmax-publicador-v2-1-cli.php' => 'gmax-publicador-cli.php',
        'painel_mercadolivre/mercadolivre.php' => 'mercadolivre.php', 'importador_mercadolivre/gmax-ml-importar-cli.php' => 'gmax-ml-importar-cli.php',
    ];
    foreach ($map as $from => $to) {
        copy("$ref/$from", "$app/$to");
    }
    // O publicador V2.1 de referência aponta para /home1/...; no teste aponta para a pasta isolada.
    $pub = file_get_contents("$app/gmax-publicador-cli.php");
    file_put_contents("$app/gmax-publicador-cli.php", str_replace("'/home1/gust1922/gmax-secrets'", var_export("$dir/gmax-secrets", true), $pub));
    t_copy_tree(T_ROOT . '/src/gmax-auto', $app);
    putenv("GMAX_SECRETS_DIR=$dir/gmax-secrets");
    putenv('GMAX_TESTING=1');
    return $dir;
}

function t_app(): string
{
    return t_sandbox() . '/gmaxcanais.com.br/gmax-auto';
}

function t_copy_tree(string $from, string $to): void
{
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $dest = $to . '/' . substr($f->getPathname(), strlen($from) + 1);
        $f->isDir() ? @mkdir($dest, 0700, true) : copy($f->getPathname(), $dest);
    }
}

const T_PASSWORD = 'senha-de-teste-forte-123';

/** Recria o banco no estado "produção declarada". */
function t_seed(): PDO
{
    $app = t_app();
    @mkdir("$app/storage", 0700, true);
    foreach (glob("$app/storage/offers.sqlite*") as $f) {
        unlink($f);
    }
    file_put_contents("$app/storage/.htaccess", "Require all denied\n");
    foreach (glob(t_sandbox() . '/gmax-secrets/*') as $f) {
        is_dir($f) ? array_map('unlink', glob("$f/*") ?: []) : unlink($f);
    }
    Gmax\Db::reset();
    $pdo = db(); // app.php de referência cria users, offers, slots
    $pdo->prepare('INSERT INTO users(id,password_hash) VALUES(1,?)')->execute([password_hash(T_PASSWORD, PASSWORD_DEFAULT)]);
    [$ok, $bad] = importFile($pdo, __DIR__ . '/fixtures/shopee-5-ofertas.csv'); // importador original
    if ($ok !== 5 || $bad !== 0) {
        throw new RuntimeException("Seed Shopee falhou: $ok/$bad");
    }
    // Tabela e linha exatamente como o importador ML de referência cria.
    $pdo->exec('CREATE TABLE IF NOT EXISTS gmax_ml_offers (codigo_gmax TEXT PRIMARY KEY,categoria TEXT NOT NULL,produto TEXT NOT NULL,url_original TEXT,url_afiliado TEXT NOT NULL,preco_original_centavos INTEGER,preco_promocional_centavos INTEGER,condicao_preco TEXT,verificado_em TEXT,observacoes TEXT,status TEXT NOT NULL DEFAULT "aguardando_validacao",updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("INSERT INTO gmax_ml_offers(codigo_gmax,categoria,produto,url_original,url_afiliado,preco_original_centavos,preco_promocional_centavos,condicao_preco,verificado_em,observacoes) VALUES('ML-001','Ferramentas','Parafusadeira Furadeira 48V Profissional - 2 baterias','','https://meli.la/1uPYV76',29990,10431,'Pix','2026-10-26','Valores de captura; revalidar')");
    // Envio real de homologação já confirmado pelo V2.1 (primeira oferta).
    $pdo->exec('CREATE TABLE IF NOT EXISTS gmax_dispatches (slot TEXT PRIMARY KEY, item_id TEXT NOT NULL, status TEXT NOT NULL, http_code INTEGER, message_id INTEGER, response_note TEXT, started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, finished_at TEXT)');
    $first = (string)$pdo->query('SELECT item_id FROM offers ORDER BY created_at,item_id LIMIT 1')->fetchColumn();
    $pdo->prepare("INSERT INTO gmax_dispatches(slot,item_id,status,http_code,message_id,response_note,finished_at) VALUES('2026-10-27 08:00',?,'confirmed',200,15,'Telegram confirmou',CURRENT_TIMESTAMP)")->execute([$first]);
    $pdo->prepare("UPDATE offers SET status='enviado', sent_at=CURRENT_TIMESTAMP WHERE item_id=?")->execute([$first]);
    $pdo->exec("INSERT INTO slots(slot,status,detail) VALUES('2026-10-26 08:00','concluido','Telegram confirmou')");
    Gmax\Db::use($pdo);
    Gmax\Time::freeze(null);
    return $pdo;
}

/** Instantâneo das tabelas legadas para provar que não mudaram. */
function t_legacy_snapshot(PDO $pdo): string
{
    $out = [];
    foreach (['users', 'offers', 'slots', 'gmax_ml_offers', 'gmax_dispatches'] as $t) {
        $out[$t] = $pdo->query("SELECT * FROM $t ORDER BY rowid")->fetchAll(PDO::FETCH_ASSOC);
        $out["$t#sql"] = $pdo->query("SELECT sql FROM sqlite_master WHERE name='$t'")->fetchColumn();
    }
    return hash('sha256', json_encode($out));
}

// ---------------------------------------------------------------- mini framework
$GLOBALS['t_results'] = [];

function test(string $name, callable $fn): void
{
    $t0 = microtime(true);
    try {
        $fn();
        $GLOBALS['t_results'][] = [$name, true, '', microtime(true) - $t0];
        echo "  ✓ $name\n";
    } catch (Throwable $e) {
        $GLOBALS['t_results'][] = [$name, false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), microtime(true) - $t0];
        echo "  ✗ $name\n    " . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}

function eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($msg ? "$msg: " : '') . 'esperado ' . var_export($expected, true) . ', obtido ' . var_export($actual, true));
    }
}

function ok(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException('Falhou: ' . $msg);
    }
}

function throws(callable $fn, string $contains = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($contains !== '' && !str_contains($e->getMessage(), $contains)) {
            throw new RuntimeException("Exceção inesperada: {$e->getMessage()}");
        }
        return;
    }
    throw new RuntimeException('Exceção esperada não ocorreu' . ($contains ? " ($contains)" : ''));
}

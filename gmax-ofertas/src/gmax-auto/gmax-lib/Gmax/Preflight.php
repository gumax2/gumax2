<?php
declare(strict_types=1);

namespace Gmax;

use PDO;
use Throwable;

/**
 * Verificação somente leitura do ambiente real (HostGator) antes de migrar.
 * Compara os arquivos instalados com as versões de referência usadas no desenvolvimento.
 */
final class Preflight
{
    public const LEGACY_FILES = ['app.php', 'index.php', 'cron.php', 'gmax-publicador-cli.php', 'mercadolivre.php', 'gmax-ml-importar-cli.php'];
    private const EXPECTED_COLUMNS = [
        'offers' => ['item_id', 'title', 'price', 'affiliate_url', 'status'],
        'gmax_ml_offers' => ['codigo_gmax', 'produto', 'url_afiliado', 'preco_promocional_centavos', 'status'],
        'users' => ['id', 'password_hash'],
    ];

    /** @return list<array{0:string,1:string,2:string}> [nivel ok|aviso|falha, item, detalhe] */
    public static function run(?PDO $pdo = null): array
    {
        $out = [];
        $add = static function (string $level, string $item, string $detail) use (&$out): void {
            $out[] = [$level, $item, $detail];
        };
        $add(version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'falha', 'Versão do PHP', PHP_VERSION . ' (' . PHP_SAPI . ')');
        foreach (['pdo_sqlite' => 'falha', 'mbstring' => 'falha', 'json' => 'falha', 'zip' => 'aviso'] as $ext => $lvl) {
            $add(extension_loaded($ext) ? 'ok' : $lvl, "Extensão $ext", extension_loaded($ext) ? 'carregada' : ($ext === 'zip' ? 'ausente: importação de .xlsx indisponível (CSV funciona)' : 'ausente'));
        }
        try {
            $pdo ??= Db::get();
        } catch (Throwable $e) {
            $add('falha', 'Conexão db() do app.php', get_class($e));
            return $out;
        }
        $v = (string)$pdo->query('SELECT sqlite_version()')->fetchColumn();
        $add(version_compare($v, '3.27.0', '>=') ? 'ok' : 'falha', 'Versão do SQLite', $v . ' (mínimo 3.27 para backup VACUUM INTO)');
        $path = Db::path($pdo);
        $add($path !== '' && is_writable($path) ? 'ok' : 'falha', 'Arquivo do banco', $path !== '' ? self::rel($path) . ' — ' . (is_writable($path) ? 'gravável' : 'SEM permissão de escrita') : 'banco em memória?');
        if ($path !== '') {
            $perm = fileperms($path) & 0777;
            $add(($perm & 0007) === 0 ? 'ok' : 'aviso', 'Permissão do banco', sprintf('%04o', $perm) . (($perm & 0007) ? ' — recomendável 0600' : ''));
            $storageHt = dirname($path) . '/.htaccess';
            $add(is_file($storageHt) ? 'ok' : 'falha', 'Proteção web da pasta do banco', is_file($storageHt) ? '.htaccess presente' : '.htaccess AUSENTE na pasta do banco');
        }
        $check = (string)$pdo->query('PRAGMA quick_check')->fetchColumn();
        $add($check === 'ok' ? 'ok' : 'falha', 'Integridade do banco', $check);

        foreach (self::EXPECTED_COLUMNS as $table => $cols) {
            if (!Db::tableExists($pdo, $table)) {
                $add($table === 'gmax_ml_offers' ? 'aviso' : 'falha', "Tabela $table", 'não encontrada');
                continue;
            }
            $missing = array_diff($cols, Db::columns($pdo, $table));
            $count = (int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
            $add($missing ? 'falha' : 'ok', "Tabela $table", "$count registro(s)" . ($missing ? ' — colunas ausentes: ' . implode(', ', $missing) : ''));
        }
        if (Db::tableExists($pdo, 'offers')) {
            $st = $pdo->query('SELECT status, COUNT(*) FROM offers GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
            $add('ok', 'Ofertas Shopee por estado', $st ? http_build_query($st, '', ', ') : 'nenhuma');
        }
        foreach (['slots', 'gmax_dispatches'] as $t) {
            if (Db::tableExists($pdo, $t)) {
                $st = $pdo->query("SELECT status, COUNT(*) FROM $t GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
                $add('ok', "Registros $t (preservados)", $st ? http_build_query($st, '', ', ') : 'vazia');
            }
        }
        $pending = Db::tableExists($pdo, 'gmax3_pub_queue') ? Queue::unreconciled($pdo) : self::legacyPending($pdo);
        $add($pending ? 'aviso' : 'ok', 'Pendências de reconciliação de envios', $pending ? implode('; ', $pending) : 'nenhuma');

        $hashes = self::referenceHashes();
        foreach (self::LEGACY_FILES as $f) {
            $file = GMAX_APP_DIR . '/' . $f;
            if (!is_file($file)) {
                $add($f === 'app.php' ? 'falha' : 'aviso', "Arquivo $f", 'não encontrado');
                continue;
            }
            $sha = hash_file('sha256', $file);
            $ref = $hashes[$f] ?? null;
            $add('ok', "Arquivo $f", $ref === null ? 'sem referência para comparar' : ($sha === $ref ? 'idêntico à referência do kit' : 'DIFERENTE da referência do kit (versão do servidor prevalece; nada será substituído)'));
        }
        $cron = @file_get_contents(GMAX_APP_DIR . '/cron.php');
        $preview = is_string($cron) && preg_match('/slot\(\s*db\(\)\s*,\s*false\s*\)/', $cron) === 1;
        $add($preview ? 'ok' : 'aviso', 'cron.php em modo prévia', $preview ? 'slot(db(), false) preservado' : 'não foi possível confirmar slot(db(), false)');
        $rootHt = @file_get_contents(GMAX_APP_DIR . '/.htaccess');
        $add(is_string($rootHt) && str_contains($rootHt, 'Require all denied') ? 'ok' : 'aviso', '.htaccess da aplicação', is_string($rootHt) ? 'presente' : 'ausente');
        $libHt = GMAX_APP_DIR . '/gmax-lib/.htaccess';
        $add(is_file($libHt) ? 'ok' : 'falha', 'Proteção web de gmax-lib/', is_file($libHt) ? '.htaccess presente' : 'AUSENTE — não use o painel até enviar gmax-lib/.htaccess');

        $secrets = Config::secretsDir();
        if (!is_dir($secrets)) {
            $add('aviso', 'Pasta privada gmax-secrets', 'não encontrada: ' . $secrets);
        } else {
            $inside = str_starts_with(realpath($secrets) ?: $secrets, realpath(GMAX_APP_DIR) ?: GMAX_APP_DIR);
            $add($inside ? 'falha' : 'ok', 'Pasta privada fora da área pública', $inside ? 'gmax-secrets está DENTRO da pasta pública' : 'ok');
            $perm = fileperms($secrets) & 0777;
            $add(($perm & 0007) === 0 ? 'ok' : 'aviso', 'Permissão de gmax-secrets', sprintf('%04o', $perm) . (($perm & 0007) ? ' — recomendável 0700' : ''));
            $tg = $secrets . '/telegram.php';
            if (is_file($tg)) {
                $p = fileperms($tg) & 0777;
                $add(($p & 0077) === 0 ? 'ok' : 'aviso', 'Permissão de telegram.php', sprintf('%04o', $p) . (($p & 0077) ? ' — recomendável 0600' : ''));
            }
        }
        $add(is_file(Config::legacyActivationFile()) ? 'aviso' : 'ok', 'Ativação do publicador V2.1', is_file(Config::legacyActivationFile()) ? 'EXISTE — V2.1 pode enviar; revise' : 'ausente (bloqueado)');
        $add(is_file(Config::activationFile()) ? 'aviso' : 'ok', 'Ativação da fila 3.0', is_file(Config::activationFile()) ? 'EXISTE — revise' : 'ausente (bloqueado)');
        try {
            $dir = Backup::dir();
            $add(is_writable($dir) ? 'ok' : 'falha', 'Pasta de backups', self::rel($dir) . (is_writable($dir) ? '' : ' sem permissão de escrita'));
        } catch (Throwable $e) {
            $add('falha', 'Pasta de backups', $e->getMessage());
        }
        $pendingMig = Migrator::pending($pdo);
        $add($pendingMig ? 'aviso' : 'ok', 'Migrações', $pendingMig ? 'pendentes: ' . implode(', ', $pendingMig) : 'banco atualizado');
        return $out;
    }

    private static function legacyPending(PDO $pdo): array
    {
        if (!Db::tableExists($pdo, 'gmax_dispatches')) {
            return [];
        }
        return array_map(static fn($d) => "V2.1 {$d['slot']} {$d['item_id']} {$d['status']}", Db::all($pdo, "SELECT slot,item_id,status FROM gmax_dispatches WHERE status IN ('attempted','uncertain')"));
    }

    private static function referenceHashes(): array
    {
        $f = __DIR__ . '/../reference-hashes.json';
        $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        return is_array($j) ? $j : [];
    }

    private static function rel(string $path): string
    {
        $home = dirname(GMAX_APP_DIR, 2);
        return str_starts_with($path, $home) ? '~' . substr($path, strlen($home)) : $path;
    }

    public static function hasFailures(array $results): bool
    {
        foreach ($results as [$level]) {
            if ($level === 'falha') {
                return true;
            }
        }
        return false;
    }
}

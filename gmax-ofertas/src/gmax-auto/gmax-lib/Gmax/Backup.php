<?php
declare(strict_types=1);

namespace Gmax;

use PDO;
use RuntimeException;

/**
 * Backups do banco SQLite. Ficam em gmax-secrets/gmax-backups (fora da pasta pública);
 * se não houver permissão, em storage/gmax-backups (protegido por .htaccess).
 */
final class Backup
{
    private const NAME = '/^gmax-\d{8}-\d{6}-[a-z0-9-]{1,60}\.sqlite$/D';

    public static function dir(): string
    {
        $dir = Config::backupDir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar a pasta de backups');
        }
        if (!is_file($dir . '/.htaccess')) {
            file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        return $dir;
    }

    /** @return array{file:string,sha256:string,bytes:int} */
    public static function create(PDO $pdo, string $label): array
    {
        $label = trim((string)preg_replace('/[^a-z0-9-]+/', '-', strtolower($label)), '-') ?: 'manual';
        $label = substr($label, 0, 60);
        $file = self::dir() . '/gmax-' . Time::now()->setTimezone(new \DateTimeZone(Config::TZ))->format('Ymd-His') . '-' . $label . '.sqlite';
        $n = 1;
        while (is_file($file)) {
            $file = preg_replace('/(-\d+)?\.sqlite$/', '-' . ++$n . '.sqlite', $file);
        }
        // VACUUM INTO gera cópia consistente mesmo com o banco em uso (SQLite >= 3.27).
        $st = $pdo->prepare('VACUUM INTO ?');
        $st->execute([$file]);
        @chmod($file, 0600);
        self::verify($file);
        $sha = hash_file('sha256', $file) ?: '';
        return ['file' => $file, 'sha256' => $sha, 'bytes' => (int)filesize($file)];
    }

    public static function verify(string $file): void
    {
        $b = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $check = (string)$b->query('PRAGMA integrity_check')->fetchColumn();
        if ($check !== 'ok') {
            throw new RuntimeException('Backup gerado com falha de integridade: ' . $check);
        }
    }

    /** @return list<array{name:string,bytes:int,mtime:int}> */
    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/gmax-*.sqlite') ?: [] as $f) {
            if (preg_match(self::NAME, basename($f))) {
                $out[] = ['name' => basename($f), 'bytes' => (int)filesize($f), 'mtime' => (int)filemtime($f)];
            }
        }
        usort($out, static fn($a, $b) => $b['mtime'] <=> $a['mtime'] ?: strcmp($b['name'], $a['name']));
        return $out;
    }

    public static function resolve(string $name): string
    {
        if (!preg_match(self::NAME, $name)) {
            throw new RuntimeException('Nome de backup inválido');
        }
        $file = self::dir() . '/' . $name;
        if (!is_file($file)) {
            throw new RuntimeException('Backup não encontrado');
        }
        return $file;
    }

    /** Tabelas de DADOS restauráveis pelo painel. Fila, configurações, auditoria e logins nunca voltam. */
    public const RESTORABLE = ['gmax3_catalog', 'gmax3_price_history', 'gmax3_categories', 'gmax3_import_batches', 'gmax3_import_items'];

    /**
     * Restaura SOMENTE os dados do catálogo 3.0 a partir do backup. Não restaura a fila de
     * publicação (um item enviado nunca volta a "agendado"), nem configurações (automação e
     * regras continuam como estão), nem auditoria. Força pausa ao final.
     * As tabelas legadas não são alteradas — restauração completa é feita pelo cPanel.
     */
    public static function restorePanelTables(PDO $pdo, string $name, string $actor): array
    {
        $file = self::resolve($name);
        self::verify($file);
        $pre = self::create($pdo, 'antes-restaurar');
        $pdo->prepare('ATTACH DATABASE ? AS b')->execute([$file]);
        try {
            $restored = Db::immediate($pdo, static function (PDO $pdo): array {
                $versions = static fn(string $db) => (int)$pdo->query("SELECT COUNT(*) FROM $db.sqlite_master WHERE name='gmax3_schema_migrations'")->fetchColumn()
                    ? implode(',', $pdo->query("SELECT version FROM $db.gmax3_schema_migrations ORDER BY version")->fetchAll(PDO::FETCH_COLUMN)) : '';
                if ($versions('b') === '' || $versions('b') !== $versions('main')) {
                    throw new RuntimeException('Backup de outra versão do esquema (ou anterior à migração): use o rollback pelo cPanel');
                }
                $count = [];
                foreach (array_reverse(self::RESTORABLE) as $t) {
                    $pdo->exec("DELETE FROM main.\"$t\"");
                }
                foreach (self::RESTORABLE as $t) {
                    $pdo->exec("INSERT INTO main.\"$t\" SELECT * FROM b.\"$t\"");
                    $count[$t] = (int)$pdo->query("SELECT COUNT(*) FROM main.\"$t\"")->fetchColumn();
                }
                return $count;
            });
        } finally {
            $pdo->exec('DETACH DATABASE b');
        }
        Settings::pause($pdo, 'Pausa automática após restauração de backup', $actor);
        if (Db::tableExists($pdo, 'gmax3_audit')) {
            Audit::log($pdo, $actor, 'backup_restaurado', 'backup', $name, ['backup_previo' => basename($pre['file']), 'tabelas' => $restored]);
        }
        return ['pre_backup' => $pre['file'], 'tables' => $restored];
    }
}

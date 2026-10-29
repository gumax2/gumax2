<?php
declare(strict_types=1);

namespace Gmax;

use PDO;
use RuntimeException;

/** Migrações versionadas, sempre precedidas de backup verificado. */
final class Migrator
{
    private const LEGACY_TABLES = ['offers', 'slots', 'users', 'gmax_ml_offers', 'gmax_dispatches'];

    /** @return array<string,array> */
    public static function available(): array
    {
        $out = [];
        foreach (glob(__DIR__ . '/../migrations/*.php') ?: [] as $file) {
            $m = require $file;
            $out[$m['version']] = $m;
        }
        ksort($out);
        return $out;
    }

    private static function ensureTable(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS gmax3_schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL, backup_file TEXT, result TEXT)');
    }

    /** @return list<string> */
    public static function applied(PDO $pdo): array
    {
        if (!Db::tableExists($pdo, 'gmax3_schema_migrations')) {
            return [];
        }
        return array_map('strval', $pdo->query('SELECT version FROM gmax3_schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> */
    public static function pending(PDO $pdo): array
    {
        return array_values(array_diff(array_keys(self::available()), self::applied($pdo)));
    }

    public static function isCurrent(PDO $pdo): bool
    {
        return self::pending($pdo) === [];
    }

    /** @return list<array{version:string,backup:string,result:mixed}> */
    public static function up(PDO $pdo, string $actor): array
    {
        $pending = self::pending($pdo);
        if (!$pending) {
            return [];
        }
        $backup = Backup::create($pdo, 'antes-migracao-' . implode('-', $pending));
        $done = [];
        foreach ($pending as $version) {
            $m = self::available()[$version];
            $result = Db::immediate($pdo, static function (PDO $pdo) use ($m, $version, $backup) {
                self::ensureTable($pdo);
                $result = ($m['up'])($pdo);
                Db::run($pdo, 'INSERT INTO gmax3_schema_migrations(version,applied_at,backup_file,result) VALUES(?,?,?,?)', [
                    $version, Time::nowSql(), basename($backup['file']), json_encode($result, JSON_UNESCAPED_UNICODE),
                ]);
                return $result;
            });
            Audit::log($pdo, $actor, 'migracao_aplicada', 'schema', $version, ['backup' => basename($backup['file']), 'resultado' => $result]);
            $done[] = ['version' => $version, 'backup' => $backup['file'], 'result' => $result];
        }
        return $done;
    }

    /** Reverte a última migração aplicada. Tabelas legadas nunca são tocadas. */
    public static function down(PDO $pdo, string $actor): ?array
    {
        $applied = self::applied($pdo);
        if (!$applied) {
            return null;
        }
        $version = end($applied);
        $m = self::available()[$version] ?? throw new RuntimeException("Migração $version não encontrada no pacote");
        if (Db::tableExists($pdo, 'gmax3_pub_queue') && ($pending = Queue::unreconciled($pdo))) {
            throw new RuntimeException('Recusado: há envios não reconciliados (' . implode('; ', $pending) . ')');
        }
        $backup = Backup::create($pdo, 'antes-reverter-' . $version);
        Db::immediate($pdo, static function (PDO $pdo) use ($m, $version) {
            foreach (array_reverse($m['tables']) as $table) {
                if (in_array($table, self::LEGACY_TABLES, true) || !str_starts_with($table, 'gmax3_')) {
                    throw new RuntimeException("Recusa de segurança: tabela $table não pertence à migração");
                }
                $pdo->exec('DROP TABLE IF EXISTS ' . $table);
            }
            Db::run($pdo, 'DELETE FROM gmax3_schema_migrations WHERE version=?', [$version]);
            if ((int)$pdo->query('SELECT COUNT(*) FROM gmax3_schema_migrations')->fetchColumn() === 0) {
                $pdo->exec('DROP TABLE gmax3_schema_migrations');
            }
        });
        return ['version' => $version, 'backup' => $backup['file'], 'actor' => $actor];
    }
}

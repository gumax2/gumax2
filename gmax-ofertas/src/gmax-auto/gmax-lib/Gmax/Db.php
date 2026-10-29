<?php
declare(strict_types=1);

namespace Gmax;

use PDO;
use Throwable;

final class Db
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            self::use(\db());
        }
        return self::$pdo;
    }

    public static function use(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        self::$pdo = $pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function path(PDO $pdo): string
    {
        foreach ($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['name'] === 'main') {
                return (string)$row['file'];
            }
        }
        return '';
    }

    public static function tableExists(PDO $pdo, string $table): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        $st->execute([$table]);
        return $st->fetchColumn() !== false;
    }

    /** @return list<string> */
    public static function columns(PDO $pdo, string $table): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return [];
        }
        return array_map(static fn(array $r) => (string)$r['name'], $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Executa $fn dentro de BEGIN IMMEDIATE (trava de escrita adquirida já no início),
     * impedindo que dois processos reservem o mesmo item.
     */
    public static function immediate(PDO $pdo, callable $fn): mixed
    {
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn($pdo);
            $pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
            throw $e;
        }
    }

    public static function one(PDO $pdo, string $sql, array $args = []): ?array
    {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public static function all(PDO $pdo, string $sql, array $args = []): array
    {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function value(PDO $pdo, string $sql, array $args = []): mixed
    {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function run(PDO $pdo, string $sql, array $args = []): int
    {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->rowCount();
    }
}

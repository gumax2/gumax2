<?php
declare(strict_types=1);

namespace Gmax;

use PDO;
use RuntimeException;

final class Settings
{
    /** Chaves que o painel web pode alterar. As demais só mudam pela CLI, com frase de autorização. */
    public const WEB_EDITABLE = ['janela_duplicidade_dias', 'validade_preco_horas', 'horarios'];
    /** Chaves que liberam envio: só pela CLI com --autorizo. */
    public const CLI_ONLY = ['automacao_comercial', 'ml_grupo_privado_permitido', 'publicador_v21_aposentado'];

    public static function all(PDO $pdo): array
    {
        return $pdo->query('SELECT key, value FROM gmax3_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public static function get(PDO $pdo, string $key, string $default = ''): string
    {
        $v = Db::value($pdo, 'SELECT value FROM gmax3_settings WHERE key=?', [$key]);
        return $v === null ? $default : (string)$v;
    }

    public static function int(PDO $pdo, string $key, int $default): int
    {
        $v = self::get($pdo, $key, (string)$default);
        return preg_match('/^\d+$/D', $v) ? (int)$v : $default;
    }

    public static function set(PDO $pdo, string $key, string $value, string $actor): void
    {
        $old = self::get($pdo, $key);
        Db::run($pdo, 'INSERT INTO gmax3_settings(key,value,updated_at,updated_by) VALUES(?,?,?,?)
            ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at, updated_by=excluded.updated_by',
            [$key, $value, Time::nowSql(), $actor]);
        Audit::log($pdo, $actor, 'configuracao_alterada', 'setting', $key, ['de' => $old, 'para' => $value]);
    }

    public static function setFromWeb(PDO $pdo, string $key, string $value, string $actor): void
    {
        if (!in_array($key, self::WEB_EDITABLE, true)) {
            throw new RuntimeException('Esta configuração não pode ser alterada pelo painel');
        }
        $value = trim($value);
        $ok = match ($key) {
            'janela_duplicidade_dias' => preg_match('/^\d{1,3}$/D', $value) && (int)$value >= 1 && (int)$value <= 365,
            'validade_preco_horas' => preg_match('/^\d{1,3}$/D', $value) && (int)$value >= 1 && (int)$value <= 168,
            'horarios' => (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d(,([01]\d|2[0-3]):[0-5]\d){0,23}$/D', $value),
        };
        if (!$ok) {
            throw new RuntimeException('Valor inválido para ' . $key);
        }
        self::set($pdo, $key, $value, $actor);
    }

    public static function paused(PDO $pdo): bool
    {
        return self::get($pdo, 'publicacao_pausada', '1') !== '0' || is_file(Config::pauseFile());
    }

    public const PAUSE_MARKER = "GMAX3 pausa emergencial\n";

    /** Pausa no banco E no arquivo gmax-publicacao.pause (respeitado também pelo publicador V2.1). */
    public static function pause(PDO $pdo, string $reason, string $actor): void
    {
        self::set($pdo, 'publicacao_pausada', '1', $actor);
        self::set($pdo, 'motivo_pausa', mb_substr($reason, 0, 300), $actor);
        $file = Config::pauseFile();
        if (!is_file($file) && is_dir(dirname($file)) && is_writable(dirname($file))) {
            @file_put_contents($file, self::PAUSE_MARKER);
        }
    }

    /** Remove a pausa; o arquivo só é apagado se tiver sido criado pelo próprio painel. */
    public static function resume(PDO $pdo, string $actor): bool
    {
        self::set($pdo, 'publicacao_pausada', '0', $actor);
        self::set($pdo, 'motivo_pausa', '', $actor);
        $file = Config::pauseFile();
        if (is_file($file)) {
            if (@file_get_contents($file) === self::PAUSE_MARKER) {
                @unlink($file);
            } else {
                return false;
            }
        }
        return true;
    }
}

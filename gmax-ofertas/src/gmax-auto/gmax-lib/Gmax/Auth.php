<?php
declare(strict_types=1);

namespace Gmax;

use PDO;

/**
 * Sessão compartilhada com o index.php existente ($_SESSION['auth'] e $_SESSION['csrf']),
 * portanto o login antigo continua valendo e vice-versa.
 */
final class Auth
{
    public const IDLE_SECONDS = 7200;
    private const MAX_FAILS = 5;
    private const WINDOW_MIN = 15;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params(['path' => '/', 'httponly' => true, 'secure' => $https, 'samesite' => 'Lax']);
        session_start();
        if (!empty($_SESSION['auth'])) {
            $last = (int)($_SESSION['gmax3_last'] ?? time());
            if (time() - $last > self::IDLE_SECONDS) {
                self::logout();
                session_start();
                $_SESSION['gmax3_flash'] = [['erro', 'Sessão expirada por inatividade. Entre novamente.']];
            } else {
                $_SESSION['gmax3_last'] = time();
            }
        }
        if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(20));
        }
    }

    public static function check(): bool
    {
        return !empty($_SESSION['auth']);
    }

    public static function csrf(): string
    {
        return (string)$_SESSION['csrf'];
    }

    public static function validCsrf(mixed $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals(self::csrf(), $token);
    }

    public static function hasUser(PDO $pdo): bool
    {
        return Db::tableExists($pdo, 'users') && (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    }

    private static function ipHash(): string
    {
        return hash('sha256', 'gmax3|' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'));
    }

    public static function locked(PDO $pdo): bool
    {
        if (!Db::tableExists($pdo, 'gmax3_login_attempts')) {
            return false;
        }
        $since = Time::now()->modify('-' . self::WINDOW_MIN . ' minutes')->format('Y-m-d H:i:s');
        return (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_login_attempts WHERE ip_hash=? AND at>=?', [self::ipHash(), $since]) >= self::MAX_FAILS;
    }

    /** @return string|null mensagem de erro */
    public static function login(PDO $pdo, string $password): ?string
    {
        $tracked = Db::tableExists($pdo, 'gmax3_login_attempts');
        if (self::locked($pdo)) {
            return 'Muitas tentativas. Aguarde ' . self::WINDOW_MIN . ' minutos.';
        }
        $hash = Db::value($pdo, 'SELECT password_hash FROM users ORDER BY id LIMIT 1');
        if (!is_string($hash) || !password_verify($password, $hash)) {
            if ($tracked) {
                Db::run($pdo, 'INSERT INTO gmax3_login_attempts(ip_hash,at) VALUES(?,?)', [self::ipHash(), Time::nowSql()]);
                Audit::log($pdo, 'anonimo', 'login_falhou', 'sessao');
            }
            return 'Senha incorreta';
        }
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        $_SESSION['gmax3_last'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(20));
        if ($tracked) {
            Db::run($pdo, 'DELETE FROM gmax3_login_attempts WHERE ip_hash=?', [self::ipHash()]);
            Audit::log($pdo, 'admin', 'login', 'sessao');
        }
        return null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'], 'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => 'Lax']);
        }
        session_destroy();
    }

    public static function actor(): string
    {
        return 'admin';
    }
}

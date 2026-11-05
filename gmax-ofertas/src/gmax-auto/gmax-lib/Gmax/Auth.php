<?php
declare(strict_types=1);

namespace Gmax;

use PDO;

/**
 * Autenticação centralizada (3.2.0). Só o login deste painel cria uma sessão válida
 * (marcador gmax3_auth). Sessões criadas pelo antigo index.php exigem novo login.
 * $_SESSION['auth'] continua sendo definido para o mercadolivre.php antigo seguir funcionando.
 * Limites: 5 falhas por IP e 50 no total a cada 15 min; inatividade de 2 h; sessão de no máximo 12 h.
 */
final class Auth
{
    public const IDLE_SECONDS = 7200;
    public const MAX_SESSION_SECONDS = 43200;
    private const MAX_FAILS = 5;
    private const MAX_FAILS_GLOBAL = 50;
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
            $why = match (true) {
                empty($_SESSION['gmax3_auth']) => 'O acesso agora é centralizado neste painel. Entre novamente.',
                time() - (int)($_SESSION['gmax3_last'] ?? 0) > self::IDLE_SECONDS => 'Sessão expirada por inatividade. Entre novamente.',
                time() - (int)($_SESSION['gmax3_since'] ?? 0) > self::MAX_SESSION_SECONDS => 'Sessão encerrada após 12 horas. Entre novamente.',
                default => null,
            };
            if ($why !== null) {
                self::logout();
                session_start();
                session_regenerate_id(true);
                $_SESSION['gmax3_flash'] = [['erro', $why]];
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
        return !empty($_SESSION['auth']) && !empty($_SESSION['gmax3_auth']);
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

    /** Endereço que identifica a origem: IPv4 inteiro; IPv6 agrupado pelo prefixo /64 (troca de endereço na mesma rede não escapa). */
    public static function clientKey(string $addr): string
    {
        $bin = @inet_pton($addr);
        if (is_string($bin) && strlen($bin) === 16 && !str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            return bin2hex(substr($bin, 0, 8)) . '::/64';
        }
        return $addr;
    }

    private static function ipHash(): string
    {
        return hash('sha256', 'gmax3|' . self::clientKey((string)($_SERVER['REMOTE_ADDR'] ?? 'cli')));
    }

    /** Libera o login bloqueado (gmax-admin-cli.php desbloquear-login). @return int tentativas apagadas */
    public static function unlock(PDO $pdo): int
    {
        return Db::tableExists($pdo, 'gmax3_login_attempts') ? Db::run($pdo, 'DELETE FROM gmax3_login_attempts') : 0;
    }

    public static function locked(PDO $pdo): bool
    {
        if (!Db::tableExists($pdo, 'gmax3_login_attempts')) {
            return (int)($_SESSION['gmax3_fails'] ?? 0) >= self::MAX_FAILS;
        }
        [$ip, $all] = self::counts($pdo);
        return $ip >= self::MAX_FAILS || $all >= self::MAX_FAILS_GLOBAL;
    }

    /** @return array{0:int,1:int} tentativas na janela: deste IP e de todos */
    private static function counts(PDO $pdo): array
    {
        $since = Time::now()->modify('-' . self::WINDOW_MIN . ' minutes')->format('Y-m-d H:i:s');
        return [(int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_login_attempts WHERE ip_hash=? AND at>=?', [self::ipHash(), $since]),
            (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_login_attempts WHERE at>=?', [$since])];
    }

    /** @return string|null mensagem de erro */
    public static function login(PDO $pdo, string $password): ?string
    {
        $tracked = Db::tableExists($pdo, 'gmax3_login_attempts');
        $blocked = 'Muitas tentativas. Aguarde ' . self::WINDOW_MIN . ' minutos.';
        if ($tracked) {
            // Contagem e registro na MESMA transação (BEGIN IMMEDIATE): pedidos em paralelo são
            // serializados e não escapam do limite. Bloqueado = não grava (a tabela não cresce sob ataque).
            $over = Db::immediate($pdo, static function () use ($pdo) {
                [$ip, $all] = self::counts($pdo);
                if ($ip >= self::MAX_FAILS || $all >= self::MAX_FAILS_GLOBAL) {
                    return true;
                }
                Db::run($pdo, 'INSERT INTO gmax3_login_attempts(ip_hash,at) VALUES(?,?)', [self::ipHash(), Time::nowSql()]);
                // Só a janela de 15 min importa: tentativas com mais de 1 dia são descartadas (crescimento limitado).
                Db::run($pdo, 'DELETE FROM gmax3_login_attempts WHERE at < ?', [Time::now()->modify('-1 day')->format('Y-m-d H:i:s')]);
                return false;
            });
            if ($over) {
                return $blocked;
            }
        } elseif (self::locked($pdo)) {
            return $blocked;
        }
        $hash = Db::value($pdo, 'SELECT password_hash FROM users ORDER BY id LIMIT 1');
        if (!is_string($hash) || !password_verify($password, $hash)) {
            if ($tracked) {
                Audit::log($pdo, 'anonimo', 'login_falhou', 'sessao');
            } else {
                $_SESSION['gmax3_fails'] = (int)($_SESSION['gmax3_fails'] ?? 0) + 1; // antes da migração: limite por sessão
                usleep(500000);
            }
            return 'Senha incorreta';
        }
        session_regenerate_id(true);
        $_SESSION['auth'] = true; // compatibilidade com o mercadolivre.php antigo
        $_SESSION['gmax3_auth'] = 1;
        $_SESSION['gmax3_last'] = $_SESSION['gmax3_since'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(20));
        unset($_SESSION['gmax3_fails']);
        if ($tracked) {
            Db::run($pdo, 'DELETE FROM gmax3_login_attempts WHERE ip_hash=?', [self::ipHash()]);
            Audit::log($pdo, 'admin', 'login', 'sessao');
        }
        return null;
    }

    /** Cria a senha inicial (só se NÃO existir usuário). Nunca sobrescreve uma senha existente. */
    public static function createInitialPassword(PDO $pdo, string $password): void
    {
        if (mb_strlen($password) < 14 || strlen($password) > 72) {
            // bcrypt considera só os primeiros 72 bytes: senhas maiores seriam truncadas sem aviso
            throw new \RuntimeException('A senha precisa ter de 14 a 72 caracteres (no máximo 72 bytes).');
        }
        Db::immediate($pdo, static function () use ($pdo, $password) {
            if (self::hasUser($pdo)) {
                throw new \RuntimeException('Já existe uma senha cadastrada: nada foi alterado.');
            }
            Db::run($pdo, 'INSERT INTO users(id,password_hash) VALUES(1,?)', [password_hash($password, PASSWORD_DEFAULT)]);
        });
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

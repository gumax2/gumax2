<?php
declare(strict_types=1);

namespace GmaxRenov;

/**
 * Reaproveita a sessão de login do painel GMAX (api.php), com a MESMA configuração:
 * nome gmax_session, cookie path=/ HttpOnly SameSite=Strict (Secure em HTTPS), use_strict_mode,
 * autenticado = $_SESSION['admin'], CSRF = $_SESSION['csrf'], expira após 3600 s sem atividade ($_SESSION['last']).
 * Não existe senha alternativa nem parâmetro de URL. Falha FECHADA se a configuração estiver vazia.
 */
final class Auth
{
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg + [
            'sessao_nome' => 'gmax_session',
            'chave_sessao' => 'admin',
            'csrf_chave_sessao' => 'csrf',
            'chave_ultimo_acesso' => 'last',
            'expira_segundos' => 3600,
            'cookie_path' => '/',
            'cookie_samesite' => 'Strict',
            'exigir_https' => true,
            'url_login' => './',
        ];
    }

    public static function isHttps(): bool
    {
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }

    /** Mesmo requisito do api.php ('require_https'). */
    public function httpsOk(): bool
    {
        return !$this->cfg['exigir_https'] || self::isHttps();
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        if (!empty($this->cfg['sessao_nome'])) {
            session_name((string)$this->cfg['sessao_nome']);
        }
        session_set_cookie_params([
            'httponly' => true,
            'secure' => self::isHttps(),
            'samesite' => (string)$this->cfg['cookie_samesite'],
            'path' => (string)$this->cfg['cookie_path'],
        ]);
        session_start();
        // Expiração por inatividade idêntica ao api.php: zera a sessão e troca o ID.
        $last = (string)$this->cfg['chave_ultimo_acesso'];
        $ttl = (int)$this->cfg['expira_segundos'];
        if ($last !== '' && $ttl > 0) {
            if (isset($_SESSION[$last]) && time() - (int)$_SESSION[$last] > $ttl) {
                $_SESSION = [];
                session_regenerate_id(true);
            }
            $_SESSION[$last] = time();
        }
    }

    public function loggedIn(): bool
    {
        $key = (string)$this->cfg['chave_sessao'];
        return $key !== '' && !empty($_SESSION[$key]);
    }

    private function csrfKey(): string
    {
        return (string)($this->cfg['csrf_chave_sessao'] ?: 'gr_csrf');
    }

    /** Usa o token do painel; só cria se ainda não existir (igual ao api.php). */
    public function csrfToken(): string
    {
        $key = $this->csrfKey();
        if (empty($_SESSION[$key]) || !is_string($_SESSION[$key])) {
            $_SESSION[$key] = bin2hex(random_bytes(32));
        }
        return $_SESSION[$key];
    }

    public function checkCsrf(?string $sent): bool
    {
        $expected = $_SESSION[$this->csrfKey()] ?? '';
        return is_string($expected) && $expected !== '' && is_string($sent) && hash_equals($expected, $sent);
    }

    /** Requisições que alteram dados devem vir do mesmo site. */
    public static function sameOrigin(): bool
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
            $v = $_SERVER[$h] ?? '';
            if ($v !== '') {
                $port = parse_url($v, PHP_URL_PORT);
                return (parse_url($v, PHP_URL_HOST) . ($port ? ':' . $port : '')) === $host;
            }
        }
        return true; // alguns navegadores omitem ambos; o token CSRF continua obrigatório
    }

    public function loginUrl(): string
    {
        $u = (string)$this->cfg['url_login'];
        // Somente caminho relativo/local (evita redirecionamento aberto por configuração errada).
        return preg_match('~^(\./|/(?!/))~', $u) ? $u : './';
    }

    public static function securityHeaders(): void
    {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store, max-age=0');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    }
}

<?php
declare(strict_types=1);

namespace GmaxRenov;

/**
 * Reaproveita a sessão de login do painel existente (não cria outro login).
 * Falha FECHADA: se o mapeamento não estiver configurado, ninguém entra.
 */
final class Auth
{
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg + [
            'arquivo_painel' => null,     // arquivo do painel que inicia a sessão/valida login (opcional)
            'sessao_nome' => null,        // session_name() usado pelo painel, se personalizado
            'chave_sessao' => '',         // chave em $_SESSION que indica usuário logado (OBRIGATÓRIA)
            'csrf_chave_sessao' => null,  // reutilizar o token CSRF do painel; null = token próprio do módulo
            'url_login' => './',
        ];
    }

    public function start(): void
    {
        if (!empty($this->cfg['arquivo_painel']) && is_file($this->cfg['arquivo_painel'])) {
            // O arquivo do painel pode iniciar a sessão e até redirecionar para o login por conta própria.
            require_once $this->cfg['arquivo_painel'];
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (!empty($this->cfg['sessao_nome'])) {
                session_name((string)$this->cfg['sessao_nome']);
            }
            session_set_cookie_params([
                'httponly' => true,
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public function loggedIn(): bool
    {
        $key = (string)$this->cfg['chave_sessao'];
        return $key !== '' && !empty($_SESSION[$key]);
    }

    public function csrfToken(): string
    {
        $key = $this->cfg['csrf_chave_sessao'] ?: 'gr_csrf';
        if (empty($_SESSION[$key]) || !is_string($_SESSION[$key])) {
            $_SESSION[$key] = bin2hex(random_bytes(32));
        }
        return $_SESSION[$key];
    }

    public function checkCsrf(?string $sent): bool
    {
        $key = $this->cfg['csrf_chave_sessao'] ?: 'gr_csrf';
        $expected = $_SESSION[$key] ?? '';
        return is_string($expected) && $expected !== '' && is_string($sent) && hash_equals($expected, $sent);
    }

    /** Requisições que alteram dados devem vir do mesmo site. */
    public static function sameOrigin(): bool
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '') {
            return parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '') === $host;
        }
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        if ($ref !== '') {
            return parse_url($ref, PHP_URL_HOST) . (parse_url($ref, PHP_URL_PORT) ? ':' . parse_url($ref, PHP_URL_PORT) : '') === $host;
        }
        return true; // alguns navegadores omitem ambos; o token CSRF continua obrigatório
    }

    public function loginUrl(): string
    {
        return (string)$this->cfg['url_login'];
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

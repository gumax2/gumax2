<?php
declare(strict_types=1);

namespace Gmax\OAuth;

use Gmax\Config;
use Gmax\Http;
use Gmax\Time;
use RuntimeException;

/**
 * OAuth 2.0 do Mercado Livre — Authorization Code + PKCE (S256) + state, com renovação
 * automática e rotação do refresh token. Somente leitura (pesquisa e consulta de preços).
 *
 * Arquivos em gmax-secrets/ (fora da pasta pública; criados pelo administrador no cPanel):
 *   mercadolivre-app.php    => ['client_id' => '...', 'client_secret' => '...', 'redirect_uri' => 'https://...']
 *   mercadolivre-token.enc  => tokens CIFRADOS (gerado pelo sistema; permissão 0600)
 * Nenhum token ou segredo é exibido, registrado em log ou gravado em texto puro.
 */
final class MercadoLivreOAuth
{
    public const AUTH_URL = 'https://auth.mercadolivre.com.br/authorization';
    public const TOKEN_URL = 'https://api.mercadolibre.com/oauth/token';
    public const STATE_TTL = 600;
    /** Renova quando faltar menos que isso para expirar. */
    public const REFRESH_MARGIN = 600;

    private ?array $app;

    public function __construct(private Http $http = new Http(), ?array $app = null, private ?string $dir = null)
    {
        $this->dir ??= Config::secretsDir();
        $this->app = $app ?? self::loadApp($this->dir);
    }

    private static function loadApp(string $dir): ?array
    {
        $file = $dir . '/mercadolivre-app.php';
        if (!is_readable($file)) {
            return null;
        }
        $c = require $file;
        return is_array($c) ? $c : null;
    }

    /** @return list<string> problemas na configuração do aplicativo (sem revelar valores) */
    public function appProblems(): array
    {
        $p = [];
        if ($this->app === null) {
            return ['Arquivo gmax-secrets/mercadolivre-app.php ausente'];
        }
        if (!preg_match('/^\d{6,25}$/D', (string)($this->app['client_id'] ?? ''))) {
            $p[] = 'client_id ausente ou inválido (deve ser o número do App ID)';
        }
        if (strlen((string)($this->app['client_secret'] ?? '')) < 16) {
            $p[] = 'client_secret ausente ou curto demais';
        }
        $uri = (string)($this->app['redirect_uri'] ?? '');
        $u = parse_url($uri);
        if (!is_array($u) || ($u['scheme'] ?? '') !== 'https' || empty($u['host']) || isset($u['query']) || isset($u['fragment'])) {
            $p[] = 'redirect_uri deve ser https, sem parâmetros, idêntica à cadastrada no Mercado Livre Developers';
        }
        return $p;
    }

    public function appConfigured(): bool
    {
        return $this->appProblems() === [];
    }

    public function redirectUri(): string
    {
        return (string)($this->app['redirect_uri'] ?? '');
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** RFC 7636: code_challenge = BASE64URL(SHA256(code_verifier)). */
    public static function challenge(string $verifier): string
    {
        return self::b64url(hash('sha256', $verifier, true));
    }

    /**
     * Inicia a autorização: gera state e code_verifier (guardados só na sessão do administrador)
     * e devolve a URL do Mercado Livre.
     */
    public function begin(array &$session): string
    {
        if (!$this->appConfigured()) {
            throw new RuntimeException('Aplicativo não configurado: ' . implode('; ', $this->appProblems()));
        }
        $state = self::b64url(random_bytes(32));
        $verifier = self::b64url(random_bytes(64)); // 86 caracteres (RFC 7636: 43 a 128)
        $session['gmax3_ml_oauth'] = ['state' => $state, 'verifier' => $verifier, 'at' => time()];
        return self::AUTH_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => (string)$this->app['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'code_challenge' => self::challenge($verifier),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Conclui a autorização no callback. O state é de uso único, com validade de 10 minutos e
     * comparado em tempo constante; o code_verifier vai junto na troca do código.
     * @return array{user_id:?string,expires_at:int,scope:string}
     */
    public function complete(array &$session, array $query): array
    {
        $saved = $session['gmax3_ml_oauth'] ?? null;
        unset($session['gmax3_ml_oauth']); // uso único, inclusive em caso de erro
        if (isset($query['error'])) {
            throw new RuntimeException('Autorização recusada no Mercado Livre: ' . mb_substr((string)($query['error_description'] ?? $query['error']), 0, 120));
        }
        $state = (string)($query['state'] ?? '');
        $code = (string)($query['code'] ?? '');
        if (!is_array($saved) || $state === '' || !hash_equals((string)$saved['state'], $state)) {
            throw new RuntimeException('State inválido ou ausente: inicie a conexão novamente pelo painel');
        }
        if (time() - (int)$saved['at'] > self::STATE_TTL) {
            throw new RuntimeException('Autorização expirada (mais de 10 minutos): inicie novamente');
        }
        if (!preg_match('/^[A-Za-z0-9._\-]{8,200}$/D', $code)) {
            throw new RuntimeException('Código de autorização inválido');
        }
        $tok = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'client_id' => (string)$this->app['client_id'],
            'client_secret' => (string)$this->app['client_secret'],
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => (string)$saved['verifier'],
        ]);
        $this->store()->save($tok);
        return ['user_id' => $tok['user_id'], 'expires_at' => $tok['expires_at'], 'scope' => $tok['scope']];
    }

    public function store(): TokenStore
    {
        return new TokenStore($this->dir, (string)($this->app['client_secret'] ?? ''));
    }

    /**
     * Token de acesso válido, renovando quando necessário. A renovação é serializada por uma
     * trava de arquivo e relê o token depois de obter a trava: como o refresh token do Mercado
     * Livre é de uso único (rotação), dois processos nunca o usam ao mesmo tempo.
     */
    public function accessToken(bool $force = false): ?string
    {
        if (!$this->appConfigured()) {
            return null;
        }
        $store = $this->store();
        $t = $store->load();
        if ($t === null || !empty($t['invalid'])) {
            return null;
        }
        if (!$force && $t['expires_at'] - Time::now()->getTimestamp() > self::REFRESH_MARGIN) {
            return $t['access_token'];
        }
        return $store->withLock(function () use ($store, $force, $t) {
            $cur = $store->load();
            if ($cur === null || !empty($cur['invalid'])) {
                return null;
            }
            // Outro processo já renovou enquanto esperávamos a trava?
            if ($cur['refresh_token'] !== $t['refresh_token'] || (!$force && $cur['expires_at'] - Time::now()->getTimestamp() > self::REFRESH_MARGIN)) {
                return $cur['access_token'];
            }
            try {
                $new = $this->tokenRequest([
                    'grant_type' => 'refresh_token',
                    'client_id' => (string)$this->app['client_id'],
                    'client_secret' => (string)$this->app['client_secret'],
                    'refresh_token' => $cur['refresh_token'],
                ]);
            } catch (OAuthDenied $e) {
                $store->markInvalid($e->getMessage());
                return null;
            }
            if ($new['refresh_token'] === '') {
                $new['refresh_token'] = $cur['refresh_token'];
            }
            $store->save($new + ['refreshed_at' => Time::now()->getTimestamp()]);
            return $new['access_token'];
        });
    }

    /** @return array{access_token:string,refresh_token:string,expires_at:int,user_id:?string,scope:string} */
    private function tokenRequest(array $form): array
    {
        $res = $this->http->request('POST', self::TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], http_build_query($form));
        $j = is_string($res['body'] ?? null) ? json_decode($res['body'], true) : null;
        if ($res['error'] !== null || $res['status'] === 0) {
            throw new RuntimeException('Sem resposta do Mercado Livre (rede/timeout)');
        }
        if ($res['status'] !== 200 || !is_array($j) || empty($j['access_token'])) {
            $err = is_array($j) ? (string)($j['error'] ?? 'erro') : 'resposta inválida';
            $msg = 'Mercado Livre recusou (' . $res['status'] . ' ' . mb_substr($err, 0, 60) . ')';
            // invalid_grant/unauthorized: o refresh token não serve mais — exige nova autorização.
            if (in_array($err, ['invalid_grant', 'unauthorized_client', 'invalid_client'], true)) {
                throw new OAuthDenied($msg);
            }
            throw new RuntimeException($msg);
        }
        return [
            'access_token' => (string)$j['access_token'],
            'refresh_token' => (string)($j['refresh_token'] ?? ''),
            'expires_at' => Time::now()->getTimestamp() + max(60, (int)($j['expires_in'] ?? 21600)),
            'user_id' => isset($j['user_id']) ? (string)$j['user_id'] : null,
            'scope' => mb_substr((string)($j['scope'] ?? ''), 0, 200),
        ];
    }

    /** Situação para o painel, sem expor segredos. */
    public function status(): array
    {
        $t = $this->appConfigured() ? $this->store()->load() : null;
        return [
            'app' => $this->appConfigured(),
            'app_problems' => $this->appProblems(),
            'redirect_uri' => $this->redirectUri(),
            'connected' => $t !== null && empty($t['invalid']),
            'invalid' => $t['invalid'] ?? null,
            'expires_at' => $t['expires_at'] ?? null,
            'refreshed_at' => $t['refreshed_at'] ?? ($t['obtained_at'] ?? null),
            'user_id_end' => isset($t['user_id']) ? substr((string)$t['user_id'], -4) : null,
            'scope' => $t['scope'] ?? null,
        ];
    }
}

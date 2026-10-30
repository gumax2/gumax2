<?php
declare(strict_types=1);

namespace Gmax\Connectors;

use Gmax\Config;
use Gmax\Http;
use Gmax\OAuth\MercadoLivreOAuth;

/**
 * API oficial do Mercado Livre (consulta de anúncio: preço, preço original, situação).
 * NÃO gera nem converte links afiliados: o link continua vindo do gerador oficial do
 * programa de afiliados. Credencial opcional: gmax-secrets/mercadolivre-api.php =>
 * ['access_token' => '...'] (token OAuth do aplicativo cadastrado no DevCenter).
 */
final class MercadoLivre implements Connector, Discovery
{
    public const API = 'https://api.mercadolibre.com';

    private ?string $staticToken;
    private ?MercadoLivreOAuth $oauth;

    /**
     * Ordem das credenciais: token informado (testes) > OAuth com renovação automática
     * (gmax-secrets/mercadolivre-app.php + token cifrado) > token fixo legado (mercadolivre-api.php).
     */
    public function __construct(private Http $http = new Http(), ?string $accessToken = null, ?MercadoLivreOAuth $oauth = null)
    {
        $this->staticToken = $accessToken;
        $this->oauth = $accessToken === null ? ($oauth ?? new MercadoLivreOAuth($http)) : null;
        if ($accessToken === null && !$this->oauth->appConfigured()) {
            $this->oauth = null;
            $this->staticToken = self::loadToken();
        }
    }

    private ?string $cached = null;

    private function token(bool $force = false): ?string
    {
        if ($this->oauth === null) {
            return $this->staticToken;
        }
        if ($this->cached === null || $force) {
            try {
                $this->cached = $this->oauth->accessToken($force);
            } catch (\Throwable) {
                $this->cached = null;
            }
        }
        return $this->cached;
    }

    /** GET autenticado; em 401 renova o token uma vez e repete. */
    private function get(string $path): array
    {
        $token = $this->token();
        $res = $this->http->request('GET', self::API . $path, ['Authorization: Bearer ' . $token, 'Accept: application/json']);
        if ($res['status'] === 401 && $this->oauth !== null && ($new = $this->token(true)) !== null) {
            $res = $this->http->request('GET', self::API . $path, ['Authorization: Bearer ' . $new, 'Accept: application/json']);
        }
        return $res;
    }

    private static function loadToken(): ?string
    {
        $file = Config::secretsDir() . '/mercadolivre-api.php';
        if (!is_readable($file)) {
            return null;
        }
        $c = require $file;
        $t = is_array($c) ? (string)($c['access_token'] ?? '') : '';
        return $t !== '' ? $t : null;
    }

    public function marketplace(): string
    {
        return 'mercadolivre';
    }

    public function name(): string
    {
        return 'Mercado Livre API (itens)';
    }

    public function configured(): bool
    {
        if ($this->oauth !== null) {
            return $this->oauth->status()['connected'];
        }
        return $this->staticToken !== null && preg_match('/^[A-Za-z0-9._-]{20,}$/D', $this->staticToken) === 1;
    }

    public function status(): string
    {
        if ($this->oauth !== null) {
            $s = $this->oauth->status();
            return $s['connected'] ? 'Conectado via OAuth (renovação automática)' : ($s['invalid'] ? 'Autorização expirada ou revogada: reconecte em Integrações' : 'Aplicativo configurado; falta conectar a conta em Integrações');
        }
        return $this->configured() ? 'Token fixo (legado, sem renovação)' : 'Não conectado: configure o aplicativo e conecte em Integrações';
    }

    public function fetch(array $row): array
    {
        if (!$this->configured() || $this->token() === null) {
            return ['ok' => false, 'error' => 'Conector Mercado Livre não conectado (OAuth)', 'data' => []];
        }
        $ext = (string)($row['external_id'] ?? '');
        if (!preg_match('/^item:(MLB\d{5,15})$/D', $ext, $m)) {
            return ['ok' => false, 'error' => 'Consulta disponível apenas para anúncios individuais (MLB-…); produtos de catálogo exigem escolher o anúncio', 'data' => []];
        }
        $res = $this->get('/items/' . $m[1]);
        if ($res['error'] !== null || $res['status'] !== 200) {
            return ['ok' => false, 'error' => $res['error'] ?? 'HTTP ' . $res['status'], 'data' => []];
        }
        $j = json_decode((string)$res['body'], true);
        if (!is_array($j) || ($j['id'] ?? '') !== $m[1]) {
            return ['ok' => false, 'error' => 'Resposta inesperada da API', 'data' => []];
        }
        // Esta fonte informa o preço original quando existe: ausência significa "sem desconto".
        $data = ['title' => (string)($j['title'] ?? ''), 'reports_original' => true];
        if (isset($j['price']) && is_numeric($j['price']) && $j['price'] > 0) {
            $data['price_promo_cents'] = (int)round((float)$j['price'] * 100);
        }
        if (isset($j['original_price']) && is_numeric($j['original_price']) && $j['original_price'] > ($j['price'] ?? 0)) {
            $data['price_original_cents'] = (int)round((float)$j['original_price'] * 100);
        }
        $data['availability'] = match ($j['status'] ?? '') {
            'active' => 'disponivel',
            'paused', 'closed', 'inactive' => 'indisponivel',
            default => 'desconhecida',
        };
        if (!empty($j['variations'])) {
            $data['variation_pending'] = 1;
            $data['variation_note'] = 'Anúncio com ' . count($j['variations']) . ' variação(ões): confirme a correta';
        }
        return ['ok' => true, 'error' => null, 'data' => $data];
    }

    /**
     * Busca oficial (/sites/MLB/search). Só usa campos devolvidos pela API; vendas e
     * reputação só aparecem se a API informar. 401/403 = sem autorização: nada é inventado.
     */
    public function search(string $query, int $limit): array
    {
        if (!$this->configured() || $this->token() === null) {
            return ['ok' => false, 'error' => 'Conector Mercado Livre não conectado (OAuth)', 'items' => []];
        }
        $q = http_build_query(['q' => mb_substr($query, 0, 80), 'limit' => max(1, min(50, $limit))]);
        $res = $this->get('/sites/MLB/search?' . $q);
        if ($res['error'] !== null || $res['status'] !== 200) {
            $why = in_array($res['status'], [401, 403], true) ? 'Busca não autorizada para este aplicativo (HTTP ' . $res['status'] . ')' : ($res['error'] ?? 'HTTP ' . $res['status']);
            return ['ok' => false, 'error' => $why, 'items' => []];
        }
        $j = json_decode((string)$res['body'], true);
        if (!is_array($j) || !isset($j['results']) || !is_array($j['results'])) {
            return ['ok' => false, 'error' => 'Resposta inesperada da busca', 'items' => []];
        }
        $items = [];
        foreach ($j['results'] as $r) {
            if (!is_array($r) || !preg_match('/^MLB\d{5,15}$/D', (string)($r['id'] ?? ''))) {
                continue;
            }
            $item = [
                'marketplace' => 'mercadolivre',
                'external_id' => 'item:' . $r['id'],
                'title' => mb_substr((string)($r['title'] ?? ''), 0, 300),
                'original_url' => is_string($r['permalink'] ?? null) && str_starts_with($r['permalink'], 'https://') ? $r['permalink'] : null,
                'price_promo_cents' => isset($r['price']) && is_numeric($r['price']) && $r['price'] > 0 ? (int)round((float)$r['price'] * 100) : null,
                'price_original_cents' => isset($r['original_price']) && is_numeric($r['original_price']) && $r['original_price'] > ($r['price'] ?? 0) ? (int)round((float)$r['original_price'] * 100) : null,
                'availability' => isset($r['available_quantity']) ? ((int)$r['available_quantity'] > 0 ? 'disponivel' : 'indisponivel') : 'desconhecida',
                'store_name' => isset($r['seller']['nickname']) ? mb_substr((string)$r['seller']['nickname'], 0, 120) : null,
            ];
            if (isset($r['sold_quantity']) && is_numeric($r['sold_quantity'])) {
                $item['sales_min'] = (int)$r['sold_quantity'];
                $item['sales_public'] = (string)(int)$r['sold_quantity'];
            }
            $rep = $r['seller']['seller_reputation']['power_seller_status'] ?? null;
            if (is_string($rep) && $rep !== '') {
                $item['seller_reputation'] = 'MercadoLíder ' . ucfirst($rep);
            } elseif (!empty($r['official_store_id'])) {
                $item['seller_reputation'] = 'Loja oficial';
            }
            $items[] = $item;
        }
        return ['ok' => true, 'error' => null, 'items' => $items];
    }

    /**
     * Diagnóstico de permissões (somente leitura): /users/me e uma busca mínima.
     * @return array{conta:array,busca:array}
     */
    public function probePermissions(): array
    {
        if ($this->token() === null) {
            return ['conta' => ['ok' => false, 'detalhe' => 'Sem token'], 'busca' => ['ok' => false, 'detalhe' => 'Sem token']];
        }
        $me = $this->get('/users/me');
        $search = $this->get('/sites/MLB/search?' . http_build_query(['q' => 'fone', 'limit' => 1]));
        $why = static fn(array $r) => $r['error'] ?? match (true) {
            $r['status'] === 200 => 'Disponível',
            in_array($r['status'], [401, 403], true) => 'Não autorizado para este aplicativo (HTTP ' . $r['status'] . ')',
            default => 'HTTP ' . $r['status'],
        };
        return ['conta' => ['ok' => $me['status'] === 200, 'detalhe' => $why($me)], 'busca' => ['ok' => $search['status'] === 200, 'detalhe' => $why($search)]];
    }
}

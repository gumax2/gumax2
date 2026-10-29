<?php
declare(strict_types=1);

namespace Gmax\Connectors;

use Gmax\Config;
use Gmax\Http;

/**
 * API oficial do Mercado Livre (consulta de anúncio: preço, preço original, situação).
 * NÃO gera nem converte links afiliados: o link continua vindo do gerador oficial do
 * programa de afiliados. Credencial opcional: gmax-secrets/mercadolivre-api.php =>
 * ['access_token' => '...'] (token OAuth do aplicativo cadastrado no DevCenter).
 */
final class MercadoLivre implements Connector
{
    public const API = 'https://api.mercadolibre.com';

    private ?string $token;

    public function __construct(private Http $http = new Http(), ?string $accessToken = null)
    {
        $this->token = $accessToken ?? self::loadToken();
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

    public function name(): string
    {
        return 'Mercado Livre API (itens)';
    }

    public function configured(): bool
    {
        return $this->token !== null && preg_match('/^[A-Za-z0-9._-]{20,}$/D', $this->token) === 1;
    }

    public function status(): string
    {
        return $this->configured() ? 'Token presente (consulta sob demanda)' : 'Sem token OAuth (gmax-secrets/mercadolivre-api.php ausente) — consultas desativadas';
    }

    public function fetch(array $row): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => 'Conector Mercado Livre não configurado', 'data' => []];
        }
        $ext = (string)($row['external_id'] ?? '');
        if (!preg_match('/^item:(MLB\d{5,15})$/D', $ext, $m)) {
            return ['ok' => false, 'error' => 'Consulta disponível apenas para anúncios individuais (MLB-…); produtos de catálogo exigem escolher o anúncio', 'data' => []];
        }
        $res = $this->http->request('GET', self::API . '/items/' . $m[1], ['Authorization: Bearer ' . $this->token, 'Accept: application/json']);
        if ($res['error'] !== null || $res['status'] !== 200) {
            return ['ok' => false, 'error' => $res['error'] ?? 'HTTP ' . $res['status'], 'data' => []];
        }
        $j = json_decode((string)$res['body'], true);
        if (!is_array($j) || ($j['id'] ?? '') !== $m[1]) {
            return ['ok' => false, 'error' => 'Resposta inesperada da API', 'data' => []];
        }
        $data = ['title' => (string)($j['title'] ?? '')];
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
}

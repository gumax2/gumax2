<?php
declare(strict_types=1);

namespace Gmax\Connectors;

use Gmax\Config;
use Gmax\Http;
use Gmax\Money;

/**
 * Shopee Affiliate Open API (GraphQL) — PREPARADO, aguardando liberação das credenciais.
 * Credenciais: gmax-secrets/shopee-api.php => ['app_id' => '...', 'secret' => '...'].
 * Assinatura: SHA256(AppId + Timestamp + Payload + Secret), conforme documentação pública
 * da Shopee Affiliate. Confirme endpoint e campos na documentação entregue com o acesso.
 */
final class ShopeeAffiliate implements Connector, Discovery
{
    public const ENDPOINT = 'https://open-api.affiliate.shopee.com.br/graphql';

    private ?array $creds;

    public function __construct(private Http $http = new Http(), ?array $credentials = null)
    {
        $this->creds = $credentials ?? self::loadCredentials();
    }

    private static function loadCredentials(): ?array
    {
        $file = Config::secretsDir() . '/shopee-api.php';
        if (!is_readable($file)) {
            return null;
        }
        $c = require $file;
        return is_array($c) ? $c : null;
    }

    public function marketplace(): string
    {
        return 'shopee';
    }

    public function name(): string
    {
        return 'Shopee Affiliate API';
    }

    public function configured(): bool
    {
        return is_array($this->creds)
            && preg_match('/^\d{4,20}$/D', (string)($this->creds['app_id'] ?? ''))
            && strlen((string)($this->creds['secret'] ?? '')) >= 16;
    }

    public function status(): string
    {
        return $this->configured() ? 'Credenciais presentes (consulta sob demanda)' : 'Aguardando liberação das credenciais (gmax-secrets/shopee-api.php ausente)';
    }

    public static function authorization(string $appId, string $secret, int $timestamp, string $payload): string
    {
        $signature = hash('sha256', $appId . $timestamp . $payload . $secret);
        return "SHA256 Credential=$appId, Timestamp=$timestamp, Signature=$signature";
    }

    /** @return array{ok:bool,error:?string,data:array} */
    private function graphql(string $query, array $variables, ?int $timestamp = null): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => 'Conector Shopee não configurado', 'data' => []];
        }
        $payload = json_encode(['query' => $query, 'variables' => $variables], JSON_UNESCAPED_SLASHES);
        $auth = self::authorization((string)$this->creds['app_id'], (string)$this->creds['secret'], $timestamp ?? time(), $payload);
        $res = $this->http->request('POST', self::ENDPOINT, ['Content-Type: application/json', 'Authorization: ' . $auth], $payload);
        if ($res['error'] !== null || $res['status'] !== 200) {
            return ['ok' => false, 'error' => $res['error'] ?? 'HTTP ' . $res['status'], 'data' => []];
        }
        $json = json_decode((string)$res['body'], true);
        if (!is_array($json) || !empty($json['errors'])) {
            return ['ok' => false, 'error' => 'Resposta da API com erro: ' . mb_substr(json_encode($json['errors'] ?? 'JSON inválido', JSON_UNESCAPED_UNICODE), 0, 200), 'data' => []];
        }
        return ['ok' => true, 'error' => null, 'data' => $json['data'] ?? []];
    }

    public function fetch(array $row): array
    {
        $itemId = (string)($row['external_id'] ?? '');
        if (!preg_match('/^\d{3,20}$/D', $itemId)) {
            return ['ok' => false, 'error' => 'Produto sem Item Id Shopee', 'data' => []];
        }
        $q = 'query($itemId: Int64){ productOfferV2(itemId: $itemId, limit: 1){ nodes { itemId productName priceMin priceMax commissionRate offerLink shopName } } }';
        $res = $this->graphql($q, ['itemId' => (int)$itemId]);
        if (!$res['ok']) {
            return $res;
        }
        $node = $res['data']['productOfferV2']['nodes'][0] ?? null;
        if (!is_array($node) || (string)($node['itemId'] ?? '') !== $itemId) {
            return ['ok' => false, 'error' => 'Item não retornado pela API', 'data' => []];
        }
        $data = ['title' => (string)($node['productName'] ?? '')];
        // Faixa de preço (variações): só usa quando mínimo = máximo, senão exige conferir variação.
        $min = Money::tryParse((string)($node['priceMin'] ?? ''));
        $max = Money::tryParse((string)($node['priceMax'] ?? ''));
        if ($min !== null && $min === $max) {
            $data['price_promo_cents'] = $min;
        } elseif ($min !== null) {
            $data['variation_pending'] = 1;
            $data['variation_note'] = 'Faixa de preço entre variações: ' . Money::format($min) . ' a ' . Money::format($max);
        }
        if (isset($node['commissionRate'])) {
            $data['commission_rate'] = (string)$node['commissionRate'];
        }
        return ['ok' => true, 'error' => null, 'data' => $data];
    }

    /**
     * Busca oficial por palavra-chave (productOfferV2). offerLink é o link de afiliado gerado
     * pela própria Shopee (conversão autorizada). A API informa % de desconto, mas não o preço
     * original: o "De" fica vazio em vez de ser calculado.
     */
    public function search(string $query, int $limit): array
    {
        $q = 'query($kw: String, $limit: Int){ productOfferV2(keyword: $kw, sortType: 2, page: 1, limit: $limit){ nodes { itemId productName priceMin priceMax priceDiscountRate sales ratingStar shopName productLink offerLink commissionRate } } }';
        $res = $this->graphql($q, ['kw' => mb_substr($query, 0, 80), 'limit' => max(1, min(50, $limit))]);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'], 'items' => []];
        }
        $items = [];
        foreach ($res['data']['productOfferV2']['nodes'] ?? [] as $n) {
            $id = (string)($n['itemId'] ?? '');
            if (!preg_match('/^\d{3,20}$/D', $id)) {
                continue;
            }
            $min = Money::tryParse((string)($n['priceMin'] ?? ''));
            $max = Money::tryParse((string)($n['priceMax'] ?? ''));
            $item = [
                'marketplace' => 'shopee',
                'external_id' => $id,
                'codigo' => $id,
                'title' => mb_substr((string)($n['productName'] ?? ''), 0, 300),
                'store_name' => isset($n['shopName']) ? mb_substr((string)$n['shopName'], 0, 120) : null,
                'original_url' => is_string($n['productLink'] ?? null) ? $n['productLink'] : null,
                'affiliate_url' => is_string($n['offerLink'] ?? null) ? $n['offerLink'] : null,
                'commission_rate' => isset($n['commissionRate']) ? (string)$n['commissionRate'] : null,
            ];
            if ($min !== null && $min === $max) {
                $item['price_promo_cents'] = $min;
            } elseif ($min !== null) {
                $item['variation_pending'] = 1;
                $item['variation_note'] = 'Faixa entre variações: ' . Money::format($min) . ' a ' . Money::format($max);
            }
            if (isset($n['sales']) && is_numeric($n['sales'])) {
                $item['sales_min'] = (int)$n['sales'];
                $item['sales_public'] = (string)(int)$n['sales'];
            }
            if (isset($n['ratingStar']) && is_numeric($n['ratingStar']) && $n['ratingStar'] > 0) {
                $item['rating'] = round((float)$n['ratingStar'], 2);
            }
            if (isset($n['priceDiscountRate']) && is_numeric($n['priceDiscountRate']) && $n['priceDiscountRate'] > 0) {
                $item['notes'] = 'Shopee informa desconto de ' . (int)$n['priceDiscountRate'] . '% (preço original não informado pela API)';
            }
            $items[] = $item;
        }
        return ['ok' => true, 'error' => null, 'items' => $items];
    }
}

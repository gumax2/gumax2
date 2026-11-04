<?php
declare(strict_types=1);

namespace Gmax\Connectors;

use Gmax\Config;
use Gmax\Http;
use Gmax\Money;

/**
 * Shopee Affiliate Open API (GraphQL).
 * Credenciais: gmax-secrets/shopee-api.php => ['app_id' => '...', 'secret' => '...'].
 * Assinatura: SHA256(AppId + Timestamp + Payload + Secret), cabeçalho
 * "SHA256 Credential=<AppId>, Timestamp=<unix>, Signature=<hex>", sobre o corpo exato enviado.
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

    /** Códigos de erro da Shopee Affiliate Open API (a mensagem original da API sempre acompanha). */
    public const ERROR_CODES = [
        10000 => 'erro de sistema da Shopee',
        10010 => 'erro de análise da consulta (Parse Error): sintaxe, campo ou tipo de argumento inválido',
        10020 => 'falha de autenticação: assinatura inválida, AppId/Secret incorretos ou relógio do servidor fora da janela aceita',
        10030 => 'limite de requisições da Shopee excedido',
        10035 => 'sem permissão de acesso a esta API para a conta de afiliado',
    ];
    /** Campos pedidos na consulta de produto (todos existentes em ProductOfferV2). */
    private const ITEM_FIELDS = 'itemId shopId productName priceMin priceMax commissionRate offerLink shopName';

    public static function authorization(string $appId, string $secret, int $timestamp, string $payload): string
    {
        $signature = hash('sha256', $appId . $timestamp . $payload . $secret);
        return "SHA256 Credential=$appId, Timestamp=$timestamp, Signature=$signature";
    }

    /** Final do AppId para exibição (nunca o valor inteiro, nunca o Secret). */
    public function appIdEnd(): ?string
    {
        return $this->configured() ? substr((string)$this->creds['app_id'], -4) : null;
    }

    /** Texto seguro de um erro GraphQL: código, significado e mensagem original (curta, sem controle). */
    public static function describeError(?int $code, string $message): string
    {
        $message = mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message)), 0, 160);
        $label = $code !== null ? (self::ERROR_CODES[$code] ?? 'código não documentado no painel') : 'erro sem código';
        return 'GraphQL ' . ($code ?? '?') . " ($label)" . ($message !== '' ? ': "' . $message . '"' : '');
    }

    /**
     * Uma requisição GraphQL assinada. HTTP 200 NÃO significa sucesso: "errors" na resposta é falha.
     * O corpo assinado é exatamente o corpo enviado. "variables" só é enviado quando há variáveis
     * (um array vazio do PHP viraria [] no JSON, que não é objeto).
     * @return array{ok:bool,error:?string,data:array,endpoint:string,http:?int,gql_code:?int}
     */
    private function graphql(string $query, array $variables = [], ?int $timestamp = null): array
    {
        $meta = ['endpoint' => 'POST /graphql', 'http' => null, 'gql_code' => null];
        if (!$this->configured()) {
            return ['ok' => false, 'error' => 'Conector Shopee não configurado', 'data' => []] + $meta;
        }
        $body = ['query' => $query];
        if ($variables) {
            $body['variables'] = $variables;
        }
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $auth = self::authorization((string)$this->creds['app_id'], (string)$this->creds['secret'], $timestamp ?? time(), $payload);
        try {
            $res = $this->http->request('POST', self::ENDPOINT, ['Content-Type: application/json', 'Authorization: ' . $auth], $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Requisição não realizada: ' . mb_substr($e->getMessage(), 0, 160), 'data' => []] + $meta;
        }
        $meta['http'] = $res['status'] ?: null;
        if ($res['error'] !== null || $res['status'] !== 200) {
            return ['ok' => false, 'error' => $res['error'] ?? 'HTTP ' . $res['status'], 'data' => []] + $meta;
        }
        $json = json_decode((string)$res['body'], true);
        if (!is_array($json)) {
            return ['ok' => false, 'error' => 'Resposta da API não é JSON válido (HTTP 200)', 'data' => []] + $meta;
        }
        if (!empty($json['errors'])) {
            $e = is_array($json['errors'][0] ?? null) ? $json['errors'][0] : [];
            $code = $e['extensions']['code'] ?? $e['code'] ?? null;
            $code = is_numeric($code) ? (int)$code : null;
            $msg = (string)($e['message'] ?? $e['extensions']['message'] ?? '');
            return ['ok' => false, 'error' => self::describeError($code, $msg), 'data' => [], 'gql_code' => $code] + $meta;
        }
        if (!isset($json['data']) || !is_array($json['data'])) {
            return ['ok' => false, 'error' => 'Resposta sem "data" e sem "errors" (HTTP 200)', 'data' => []] + $meta;
        }
        return ['ok' => true, 'error' => null, 'data' => $json['data']] + $meta;
    }

    /**
     * Consulta de produto. Int64 (itemId, shopId) vai como LITERAL na consulta, só com dígitos
     * validados. Na 3.1.3 ia como variável Int64 com número JSON, e a API respondia
     * 10010 "wrong type". Se a API recusar o literal com 10010, tenta uma vez com variáveis
     * Int64 em texto (formato aceito pelo escalar Int64 da Shopee).
     * @return array resultado do graphql() + formato usado
     */
    private function productQuery(string $itemId, ?string $shopId): array
    {
        $args = 'itemId: ' . $itemId . ($shopId !== null ? ', shopId: ' . $shopId : '') . ', limit: 1';
        $res = $this->graphql('{ productOfferV2(' . $args . '){ nodes { ' . self::ITEM_FIELDS . ' } } }') + ['formato' => 'literal'];
        if ($res['ok'] || $res['gql_code'] !== 10010) {
            return $res;
        }
        $vars = ['itemId' => $itemId] + ($shopId !== null ? ['shopId' => $shopId] : []);
        $decl = '$itemId: Int64' . ($shopId !== null ? ', $shopId: Int64' : '');
        $use = 'itemId: $itemId' . ($shopId !== null ? ', shopId: $shopId' : '');
        $second = $this->graphql('query(' . $decl . '){ productOfferV2(' . $use . ', limit: 1){ nodes { ' . self::ITEM_FIELDS . ' } } }', $vars) + ['formato' => 'variaveis_texto'];
        if (!$second['ok']) {
            $second['error'] = 'Literal: ' . $res['error'] . ' · Variáveis em texto: ' . $second['error'];
        }
        return $second;
    }

    /** IDs consultáveis de uma linha do catálogo: [itemId, shopId|null] ou null. */
    public static function ids(array $row): ?array
    {
        $itemId = (string)($row['external_id'] ?? '');
        if (!preg_match('/^\d{3,20}$/D', $itemId)) {
            return null;
        }
        $shopId = null;
        foreach (['original_url', 'product_url'] as $k) {
            if (\Gmax\Url::shopeeItemId($row[$k] ?? null) === $itemId) {
                $shopId = \Gmax\Url::shopeeShopId($row[$k]);
                break;
            }
        }
        return [$itemId, $shopId];
    }

    public function fetch(array $row): array
    {
        $ids = self::ids($row);
        if ($ids === null) {
            return ['ok' => false, 'error' => 'Produto sem Item Id Shopee', 'data' => []];
        }
        [$itemId, $shopId] = $ids;
        $res = $this->productQuery($itemId, $shopId);
        $meta = ['endpoint' => $res['endpoint'], 'http' => $res['http'], 'gql_code' => $res['gql_code'], 'formato' => $res['formato']];
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'], 'data' => []] + $meta;
        }
        $nodes = $res['data']['productOfferV2']['nodes'] ?? null;
        if (!is_array($nodes)) {
            return ['ok' => false, 'error' => 'Resposta sem productOfferV2.nodes (HTTP 200)', 'data' => []] + $meta;
        }
        $node = null;
        foreach ($nodes as $n) {
            if (is_array($n) && (string)($n['itemId'] ?? '') === $itemId) {
                $node = $n;
                break;
            }
        }
        if ($node === null) {
            return ['ok' => false, 'error' => "Produto não encontrado na API (itemId $itemId" . ($shopId ? ", shopId $shopId" : '') . '): inexistente, removido ou fora do programa de afiliados', 'data' => []] + $meta;
        }
        $data = ['title' => (string)($node['productName'] ?? '')];
        // Faixa de preço (variações): só usa quando mínimo = máximo, senão exige conferir variação.
        $min = Money::tryParse((string)($node['priceMin'] ?? ''));
        $max = Money::tryParse((string)($node['priceMax'] ?? ''));
        if ($min !== null && $min > 0 && $min === $max) {
            $data['price_promo_cents'] = $min;
        } elseif ($min !== null && $max !== null) {
            $data['variation_pending'] = 1;
            $data['variation_note'] = 'Faixa de preço entre variações: ' . Money::format($min) . ' a ' . Money::format($max);
        }
        if (isset($node['commissionRate']) && is_scalar($node['commissionRate'])) {
            $data['commission_rate'] = (string)$node['commissionRate'];
        }
        return ['ok' => true, 'error' => null, 'data' => $data, 'offer_link' => is_string($node['offerLink'] ?? null) ? $node['offerLink'] : null,
            'shop_name' => is_string($node['shopName'] ?? null) ? $node['shopName'] : null] + $meta;
    }

    /**
     * "Testar conexão Shopee": assinatura, credenciais e permissão, com uma consulta mínima.
     * Somente leitura; nada é gravado no catálogo; nunca devolve AppId inteiro nem Secret.
     */
    public function probe(): array
    {
        $out = ['credenciais' => $this->configured() ? 'presentes (AppId terminado em ' . $this->appIdEnd() . ')' : 'ausentes ou incompletas (gmax-secrets/shopee-api.php)',
            'relogio_servidor_utc' => gmdate('Y-m-d H:i:s'), 'ok' => false, 'endpoint' => 'POST /graphql', 'http' => null, 'gql_code' => null, 'detalhe' => ''];
        if (!$this->configured()) {
            $out['detalhe'] = 'Sem credenciais: nenhuma requisição feita';
            return $out;
        }
        $r = $this->graphql('{ productOfferV2(keyword: "fone", page: 1, limit: 1){ nodes { itemId productName } } }');
        $out = array_merge($out, ['ok' => $r['ok'], 'http' => $r['http'], 'gql_code' => $r['gql_code']]);
        $out['detalhe'] = $r['ok']
            ? 'Conexão, assinatura e permissão OK (' . count($r['data']['productOfferV2']['nodes'] ?? []) . ' produto(s) na consulta mínima)'
            : (string)$r['error'];
        return $out;
    }

    /** Consulta individual de produto (somente leitura; nada é gravado). */
    public function probeItem(string $itemId, ?string $shopId = null): array
    {
        $itemId = trim($itemId);
        $shopId = $shopId !== null && trim($shopId) !== '' ? trim($shopId) : null;
        if (!preg_match('/^\d{3,20}$/D', $itemId) || ($shopId !== null && !preg_match('/^\d{1,20}$/D', $shopId))) {
            return ['ok' => false, 'endpoint' => 'POST /graphql', 'http' => null, 'gql_code' => null, 'formato' => null, 'detalhe' => 'Informe o Item Id (e, se quiser, o Shop Id) só com números', 'preco' => null, 'faixa' => null, 'nome' => null, 'comissao' => null, 'link_oficial' => false];
        }
        $r = $this->fetch(['external_id' => $itemId, 'original_url' => $shopId ? "https://shopee.com.br/product/$shopId/$itemId" : null]);
        return [
            'ok' => $r['ok'], 'endpoint' => $r['endpoint'] ?? 'POST /graphql', 'http' => $r['http'] ?? null, 'gql_code' => $r['gql_code'] ?? null, 'formato' => $r['formato'] ?? null,
            'detalhe' => $r['ok'] ? 'Produto encontrado: a revisão de preços consegue consultar este item' : (string)$r['error'],
            'preco' => $r['data']['price_promo_cents'] ?? null, 'faixa' => $r['data']['variation_note'] ?? null, 'nome' => $r['data']['title'] ?? null,
            'comissao' => $r['data']['commission_rate'] ?? null, 'link_oficial' => !empty($r['offer_link']),
        ];
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

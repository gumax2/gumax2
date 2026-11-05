<?php
declare(strict_types=1);

namespace Gmax\Connectors;

use Gmax\Config;
use Gmax\Http;
use Gmax\Time;
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
    public function __construct(private Http $http = new Http(), ?string $accessToken = null, ?MercadoLivreOAuth $oauth = null, private ?\PDO $pdo = null)
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

    /**
     * GET autenticado; em 401 renova o token uma vez e repete. Grupos suspensos por 403 repetido
     * (EndpointGuard) não são consultados pelas automações; $supervised = diagnóstico manual.
     */
    private function get(string $path, bool $supervised = false): array
    {
        $group = EndpointGuard::group($path);
        if ($this->pdo && $group && !$supervised && ($g = EndpointGuard::suspended($this->pdo, $group))) {
            return ['status' => 0, 'body' => null, 'suspended' => true,
                'error' => 'Consulta suspensa: ' . EndpointGuard::GROUPS[$group] . ' respondeu HTTP 403 repetidamente (desde ' . Time::show($g['suspended_at']) . '). Reative em Integrações após a liberação.'];
        }
        $token = $this->token();
        $res = $this->http->request('GET', self::API . $path, ['Authorization: Bearer ' . $token, 'Accept: application/json']);
        // Renovação forçada só nas automações: testes supervisionados não rotacionam tokens.
        if ($res['status'] === 401 && !$supervised && $this->oauth !== null && ($new = $this->token(true)) !== null) {
            $res = $this->http->request('GET', self::API . $path, ['Authorization: Bearer ' . $new, 'Accept: application/json']);
        }
        if ($this->pdo && $group && !$supervised && $res['error'] === null) {
            EndpointGuard::record($this->pdo, $group, (int)$res['status'], self::apiError($res)['texto']);
        }
        return $res;
    }

    /** GET sem token (endpoint público), só para o diagnóstico comparar com a chamada autenticada. */
    private function getPublic(string $path): array
    {
        return $this->http->request('GET', self::API . $path, ['Accept: application/json']);
    }

    /** Código e mensagem do corpo de erro da API (ex.: PolicyAgent PA_UNAUTHORIZED_RESULT_FROM_POLICIES), sem segredos. */
    public static function apiError(array $res): array
    {
        $j = json_decode((string)($res['body'] ?? ''), true);
        if (!is_array($j)) {
            return ['code' => null, 'message' => null, 'texto' => null];
        }
        $code = $j['code'] ?? $j['error'] ?? null;
        $code = is_scalar($code) ? mb_substr((string)$code, 0, 80) : null;
        $msg = $j['message'] ?? null;
        $msg = is_scalar($msg) ? mb_substr(preg_replace('/\s+/', ' ', (string)$msg), 0, 160) : null;
        if (isset($j['blocked_by']) && is_scalar($j['blocked_by'])) {
            $msg = trim(($msg ?? '') . ' (bloqueado por: ' . mb_substr((string)$j['blocked_by'], 0, 40) . ')');
        }
        $txt = trim(($code ? $code . ': ' : '') . ($msg ?? ''));
        return ['code' => $code, 'message' => $msg, 'texto' => $txt !== '' ? \Gmax\Automation\RunLog::sanitize($txt) : null];
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

    /** Motivo legível de uma resposta de erro da API (nunca inclui o token). */
    public static function httpReason(array $res, string $what): string
    {
        if ($res['error'] !== null) {
            return $res['error'];
        }
        $api = self::apiError($res)['texto'];
        $api = $api ? ' — resposta da API: "' . $api . '"' : '';
        return match ($res['status']) {
            401 => "Não autenticado (HTTP 401) em $what: token inválido ou expirado mesmo após a renovação automática; reconecte em Integrações$api",
            403 => "Acesso negado (HTTP 403) em $what: política do Mercado Livre não autoriza este recurso para o aplicativo/conta (veja o diagnóstico em Integrações)$api",
            404 => "Não encontrado (HTTP 404) em $what: anúncio inexistente ou removido$api",
            429 => "Limite de requisições do Mercado Livre (HTTP 429) em $what: aguarde e reduza a frequência",
            default => "HTTP {$res['status']} em $what$api",
        };
    }

    public function fetch(array $row, bool $supervised = false): array
    {
        if (!$this->configured() || $this->token() === null) {
            return ['ok' => false, 'error' => 'Conector Mercado Livre não conectado (OAuth) ou token não renovável', 'data' => []];
        }
        $ext = (string)($row['external_id'] ?? '');
        if (!preg_match('/^item:(MLB\d{5,15})$/D', $ext, $m)) {
            return ['ok' => false, 'error' => 'Consulta disponível apenas para anúncios individuais (MLB-…); produtos de catálogo exigem escolher o anúncio', 'data' => []];
        }
        $endpoint = '/items/' . $m[1];
        $res = $this->get($endpoint, $supervised);
        $meta = ['endpoint' => 'GET ' . $endpoint, 'http' => $res['status'] ?: null];
        if (!empty($res['suspended'])) {
            return ['ok' => false, 'error' => $res['error'], 'data' => [], 'suspended' => true] + $meta;
        }
        if ($res['error'] !== null || $res['status'] !== 200) {
            return ['ok' => false, 'error' => self::httpReason($res, $endpoint), 'data' => []] + $meta;
        }
        $j = json_decode((string)$res['body'], true);
        if (!is_array($j) || ($j['id'] ?? '') !== $m[1]) {
            return ['ok' => false, 'error' => 'Resposta inesperada da API', 'data' => []] + $meta;
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
        return ['ok' => true, 'error' => null, 'data' => $data] + $meta;
    }

    /**
     * Teste isolado de /items/{id} (somente leitura; nada é gravado no catálogo).
     * @return array{endpoint:string,http:?int,ok:bool,detalhe:string,preco:?int,preco_original:?int,situacao:?string}
     */
    public function probeItem(string $mlb): array
    {
        $mlb = strtoupper(str_replace('-', '', trim($mlb)));
        if (!preg_match('/^MLB\d{5,15}$/D', $mlb)) {
            return ['endpoint' => 'GET /items/{id}', 'http' => null, 'ok' => false, 'detalhe' => 'Informe um código de anúncio no formato MLB123456789', 'preco' => null, 'preco_original' => null, 'situacao' => null];
        }
        $r = $this->fetch(['external_id' => 'item:' . $mlb], true); // teste manual: não conta nem respeita a suspensão
        return [
            'endpoint' => $r['endpoint'] ?? 'GET /items/' . $mlb, 'http' => $r['http'] ?? null, 'ok' => $r['ok'],
            'detalhe' => $r['ok'] ? 'Disponível: a revisão de preços consegue consultar este anúncio' : (string)$r['error'],
            'preco' => $r['data']['price_promo_cents'] ?? null, 'preco_original' => $r['data']['price_original_cents'] ?? null, 'situacao' => $r['data']['availability'] ?? null,
        ];
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
        if (!empty($res['suspended'])) {
            return ['ok' => false, 'error' => $res['error'], 'items' => [], 'suspended' => true];
        }
        if ($res['error'] !== null || $res['status'] !== 200) {
            $why = in_array($res['status'], [401, 403], true) ? 'Busca não autorizada para este aplicativo (HTTP ' . $res['status'] . ')' . (($a = self::apiError($res)['texto']) ? ' — "' . $a . '"' : '') : ($res['error'] ?? 'HTTP ' . $res['status']);
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

    // ------------------------------------------------------------------ catálogo (3.1.9)
    /** ID de produto de CATÁLOGO (não confundir com ID de anúncio "item:MLB…"). */
    public static function isCatalogId(string $id): bool
    {
        return preg_match('/^MLB\d{4,15}$/D', $id) === 1;
    }

    /** Imagem só de https do CDN oficial (*.mlstatic.com); qualquer outra é descartada. */
    private static function picture(mixed $pics): ?string
    {
        $u = is_array($pics) && isset($pics[0]['url']) && is_string($pics[0]['url']) ? $pics[0]['url'] : null;
        $h = $u ? strtolower((string)parse_url($u, PHP_URL_HOST)) : '';
        return $u && str_starts_with($u, 'https://') && ($h === 'mlstatic.com' || str_ends_with($h, '.mlstatic.com')) && !preg_match('/[\s"\'<>]/', $u) ? mb_substr($u, 0, 500) : null;
    }

    private static function str(mixed $v, int $max = 300): ?string
    {
        return is_scalar($v) && trim((string)$v) !== '' ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$v)), 0, $max) : null;
    }

    /**
     * Pesquisa no catálogo (/products/search). Devolve produtos de catálogo SEM preço de anúncio.
     * @return array{ok:bool,error:?string,suspended?:bool,http?:?int,items:list<array{product_id:string,name:string,domain_id:?string,picture_url:?string}>}
     */
    public function catalogSearch(string $query, int $limit = 10, bool $supervised = false): array
    {
        if ($this->token() === null) {
            return ['ok' => false, 'error' => 'Conector Mercado Livre não conectado (OAuth)', 'items' => []];
        }
        $path = '/products/search?' . http_build_query(['status' => 'active', 'site_id' => 'MLB', 'q' => mb_substr($query, 0, 80), 'limit' => max(1, min(50, $limit))]);
        $res = $this->get($path, $supervised);
        if (!empty($res['suspended'])) {
            return ['ok' => false, 'error' => $res['error'], 'items' => [], 'suspended' => true];
        }
        if ($res['error'] !== null || $res['status'] !== 200) {
            return ['ok' => false, 'error' => self::httpReason($res, '/products/search'), 'items' => [], 'http' => $res['status'] ?: null];
        }
        $j = json_decode((string)$res['body'], true);
        if (!is_array($j) || !isset($j['results']) || !is_array($j['results'])) {
            return ['ok' => false, 'error' => 'Resposta inesperada da pesquisa no catálogo', 'items' => [], 'http' => 200];
        }
        $items = [];
        foreach ($j['results'] as $r) {
            $id = is_array($r) ? (string)($r['id'] ?? '') : '';
            $name = is_array($r) ? self::str($r['name'] ?? null) : null;
            if (!self::isCatalogId($id) || $name === null) {
                continue;
            }
            $items[] = ['product_id' => $id, 'name' => $name, 'domain_id' => self::str($r['domain_id'] ?? null, 80), 'picture_url' => self::picture($r['pictures'] ?? null)];
        }
        return ['ok' => true, 'error' => null, 'items' => $items, 'http' => 200];
    }

    /**
     * Produto de catálogo (/products/{id}): permalink oficial e, SE a API informar, o anúncio vencedor
     * (buy_box_winner) com o preço dele no momento da consulta. Nada é estimado.
     */
    public function catalogProduct(string $productId, bool $supervised = false): array
    {
        if (!self::isCatalogId($productId)) {
            return ['ok' => false, 'error' => 'ID de catálogo inválido', 'data' => []];
        }
        $res = $this->get('/products/' . $productId, $supervised);
        if (!empty($res['suspended'])) {
            return ['ok' => false, 'error' => $res['error'], 'data' => [], 'suspended' => true];
        }
        if ($res['error'] !== null || $res['status'] !== 200) {
            return ['ok' => false, 'error' => self::httpReason($res, '/products/{id}'), 'data' => [], 'http' => $res['status'] ?: null];
        }
        $j = json_decode((string)$res['body'], true);
        if (!is_array($j) || (string)($j['id'] ?? '') !== $productId) {
            return ['ok' => false, 'error' => 'Resposta inesperada do produto de catálogo', 'data' => [], 'http' => 200];
        }
        $perma = is_string($j['permalink'] ?? null) && \Gmax\Url::isProduct('mercadolivre', $j['permalink']) && \Gmax\Url::mlId($j['permalink']) === 'catalogo:' . $productId
            ? $j['permalink'] : null; // domínio oficial + mesmo ID de catálogo
        $d = ['name' => self::str($j['name'] ?? null), 'permalink' => $perma, 'picture_url' => self::picture($j['pictures'] ?? null),
            'category_id' => self::str($j['category_id'] ?? ($j['settings']['category_id'] ?? null), 20), 'domain_id' => self::str($j['domain_id'] ?? null, 80),
            'bb_item_id' => null, 'bb_price_cents' => null, 'bb_original_cents' => null, 'bb_currency' => null, 'bb_free_shipping' => null, 'bb_official_store' => null];
        $bb = $j['buy_box_winner'] ?? null;
        if (is_array($bb) && preg_match('/^MLB\d{5,15}$/D', (string)($bb['item_id'] ?? ''))) {
            $d['bb_item_id'] = 'item:' . $bb['item_id'];
            $d['bb_currency'] = self::str($bb['currency_id'] ?? null, 5);
            if (isset($bb['price']) && is_numeric($bb['price']) && $bb['price'] > 0 && ($d['bb_currency'] ?? 'BRL') === 'BRL') {
                $d['bb_price_cents'] = (int)round((float)$bb['price'] * 100);
            }
            if (isset($bb['original_price']) && is_numeric($bb['original_price']) && $d['bb_price_cents'] && $bb['original_price'] * 100 > $d['bb_price_cents']) {
                $d['bb_original_cents'] = (int)round((float)$bb['original_price'] * 100);
            }
            $d['bb_free_shipping'] = isset($bb['shipping']['free_shipping']) ? (int)(bool)$bb['shipping']['free_shipping'] : null;
            $d['bb_official_store'] = array_key_exists('official_store_id', $bb) ? (int)($bb['official_store_id'] !== null) : null;
        }
        return ['ok' => true, 'error' => null, 'data' => $d, 'http' => 200];
    }

    /** Nome da categoria (/categories/{id}); null se indisponível. */
    public function categoryName(string $cat): ?string
    {
        if (!preg_match('/^MLB\d{1,10}$/D', $cat) || $this->token() === null) {
            return null;
        }
        $res = $this->get('/categories/' . $cat);
        $j = $res['status'] === 200 ? json_decode((string)$res['body'], true) : null;
        return is_array($j) ? self::str($j['name'] ?? null, 80) : null;
    }

    /** Tendências de busca (/trends/MLB[/categoria]). @return array{ok:bool,error:?string,keywords:list<string>} */
    public function trends(?string $categoryId = null, bool $supervised = false): array
    {
        if ($this->token() === null) {
            return ['ok' => false, 'error' => 'Conector Mercado Livre não conectado (OAuth)', 'keywords' => []];
        }
        $path = '/trends/MLB' . ($categoryId !== null && preg_match('/^MLB\d{1,10}$/D', $categoryId) ? '/' . $categoryId : '');
        $res = $this->get($path, $supervised);
        if ($res['error'] !== null || $res['status'] !== 200) {
            return ['ok' => false, 'error' => self::httpReason($res, '/trends/MLB'), 'keywords' => []];
        }
        $j = json_decode((string)$res['body'], true);
        $kw = [];
        foreach (is_array($j) ? $j : [] as $t) {
            if (is_array($t) && ($k = self::str($t['keyword'] ?? null, 80)) !== null) {
                $kw[] = $k;
            }
        }
        return ['ok' => true, 'error' => null, 'keywords' => array_values(array_unique($kw))];
    }

    /** Caminhos de campos de um JSON (estrutura, sem valores), para a inspeção. @return list<string> */
    public static function fieldPaths(mixed $j, string $prefix = '', int $depth = 0): array
    {
        if ($depth > 4) {
            return [];
        }
        $out = [];
        if (is_array($j) && array_is_list($j)) {
            return $j ? self::fieldPaths($j[0], $prefix . '[]', $depth + 1) : [$prefix . '[] (vazio)'];
        }
        if (is_array($j)) {
            foreach ($j as $k => $v) {
                $p = $prefix === '' ? (string)$k : $prefix . '.' . $k;
                $out[] = $p . ' (' . (is_array($v) ? (array_is_list($v) ? 'lista' : 'objeto') : ($v === null ? 'nulo' : gettype($v))) . ')';
                if (is_array($v)) {
                    $out = array_merge($out, self::fieldPaths($v, $p, $depth + 1));
                }
            }
        }
        return array_slice($out, 0, 120);
    }

    /**
     * Inspeção de campos (supervisionada, somente leitura): estrutura real de cada recurso
     * de catálogo/descoberta. Grava só nomes e tipos de campos e indicadores — sem valores.
     */
    public function inspectFields(string $term = 'fone de ouvido', ?string $mlb = null): array
    {
        $out = ['em' => Time::nowSql(), 'termo' => mb_substr($term, 0, 60), 'recursos' => []];
        $probe = function (string $key, string $path) use (&$out): ?array {
            $res = $this->get($path, true);
            $j = json_decode((string)$res['body'], true);
            $out['recursos'][$key] = ['endpoint' => 'GET ' . strtok($path, '?'), 'http' => $res['status'] ?: null, 'mensagem_api' => $res['status'] === 200 ? null : self::apiError($res)['texto'],
                'campos' => $res['status'] === 200 ? self::fieldPaths($j) : []];
            return $res['status'] === 200 && is_array($j) ? $j : null;
        };
        $ps = $probe('catalogo_busca', '/products/search?' . http_build_query(['status' => 'active', 'site_id' => 'MLB', 'q' => $term, 'limit' => 3]));
        $pid = (string)($ps['results'][0]['id'] ?? '');
        $out['indicadores']['busca_por_palavra'] = $ps !== null && !empty($ps['results']);
        $out['indicadores']['busca_tem_imagem'] = $ps !== null && self::picture($ps['results'][0]['pictures'] ?? null) !== null;
        $out['indicadores']['busca_tem_preco'] = $ps !== null && (isset($ps['results'][0]['price']) || is_array($ps['results'][0]['buy_box_winner'] ?? null));
        if (self::isCatalogId($pid)) {
            $p = $probe('catalogo_produto', "/products/$pid");
            $bb = $p['buy_box_winner'] ?? null;
            $out['indicadores']['produto_tem_vencedor'] = is_array($bb);
            $out['indicadores']['vencedor_tem_preco'] = is_array($bb) && isset($bb['price']) && is_numeric($bb['price']);
            $out['indicadores']['vencedor_tem_anuncio'] = is_array($bb) && preg_match('/^MLB\d{5,15}$/D', (string)($bb['item_id'] ?? '')) === 1;
            $out['indicadores']['produto_tem_permalink'] = is_string($p['permalink'] ?? null);
        }
        $dd = $probe('dominio', '/sites/MLB/domain_discovery/search?' . http_build_query(['q' => $term, 'limit' => 1]));
        $cat = (string)($dd[0]['category_id'] ?? '');
        if (preg_match('/^MLB\d{1,10}$/D', $cat)) {
            $probe('categoria', "/categories/$cat");
            $probe('tendencias_categoria', "/trends/MLB/$cat");
        }
        $probe('tendencias', '/trends/MLB');
        if ($mlb !== null && preg_match('/^MLB\d{5,15}$/D', $mlb = strtoupper(str_replace('-', '', $mlb)))) {
            $probe('descricao', "/items/$mlb/description");
        }
        return $out;
    }

    /**
     * Diagnóstico completo (somente leitura, supervisionado): ignora a suspensão automática e não a altera.
     * Pares com e sem token distinguem restrição da plataforma de falta de autorização do aplicativo.
     * Nunca devolve token, segredo nem o corpo completo das respostas.
     * @return array{em:string,mlb:?string,testes:list<array>,conclusoes:list<array{tipo:string,texto:string}>}
     */
    public function diagnose(?string $mlb = null, ?string $appId = null): array
    {
        $mlb = $mlb !== null ? strtoupper(str_replace('-', '', trim($mlb))) : null;
        $mlb = $mlb !== null && preg_match('/^MLB\d{5,15}$/D', $mlb) ? $mlb : null;
        $t = [];
        $run = function (string $id, string $title, string $path, bool $auth) use (&$t): array {
            $res = $auth ? $this->get($path, true) : $this->getPublic($path);
            $api = self::apiError($res);
            $t[$id] = ['id' => $id, 'titulo' => $title, 'metodo' => 'GET', 'endpoint' => strtok($path, '?') . (str_contains($path, '?') ? '?…' : ''),
                'autenticado' => $auth, 'http' => $res['status'] ?: null, 'ok' => $res['status'] === 200, 'codigo_api' => $api['code'], 'mensagem_api' => $api['texto'],
                'erro_rede' => $res['error'] !== null ? mb_substr((string)$res['error'], 0, 120) : null] + self::classify($res, $auth, $api['code']);
            return $res;
        };
        if ($this->token() === null) {
            return ['em' => Time::nowSql(), 'mlb' => $mlb, 'testes' => [], 'conclusoes' => [['tipo' => 'configuracao', 'texto' => 'Sem token OAuth válido: conecte ou reconecte a conta em Integrações. Nenhuma requisição foi feita.']]];
        }
        $run('site', 'Metadados do site sem token (acesso anônimo)', '/sites/MLB', false);
        $run('site_token', 'Metadados do site com token', '/sites/MLB', true);
        $me = $run('conta', 'Conta autenticada', '/users/me', true);
        $uid = (string)(json_decode((string)$me['body'], true)['id'] ?? '');
        // Estado real do aplicativo e escopos efetivamente concedidos (documentação "Gerenciar aplicações").
        $appId ??= $this->oauth?->clientId();
        if ($appId !== null) {
            $app = $run('aplicativo', 'Estado do aplicativo (/applications/{id})', "/applications/$appId", true);
            $a = json_decode((string)$app['body'], true);
            if ($app['status'] === 200 && is_array($a)) {
                $t['aplicativo']['mensagem_api'] = self::safeText(sprintf('ativo: %s · certificação: %s · limite/hora: %s · sandbox: %s',
                    var_export($a['active'] ?? null, true), is_scalar($a['certification_status'] ?? null) ? (string)$a['certification_status'] : '—',
                    is_scalar($a['max_requests_per_hour'] ?? null) ? (string)$a['max_requests_per_hour'] : '—', var_export($a['sandbox_mode'] ?? null, true)));
                $t['aplicativo']['app_ativo'] = ($a['active'] ?? null) === true || ($a['active'] ?? null) === 'true';
            }
        }
        if (ctype_digit($uid)) {
            $gr = $run('escopos', 'Escopos concedidos ao aplicativo (/users/{id}/applications)', "/users/$uid/applications", true);
            $list = json_decode((string)$gr['body'], true);
            if ($gr['status'] === 200 && is_array($list)) {
                $mine = array_values(array_filter($list, static fn($x) => is_array($x) && (string)($x['app_id'] ?? '') === (string)$appId));
                $scopes = $mine ? array_values(array_filter((array)($mine[0]['scopes'] ?? []), 'is_scalar')) : [];
                $t['escopos']['mensagem_api'] = $mine ? 'escopos: ' . self::safeText(implode(', ', array_map('strval', $scopes))) . (isset($mine[0]['date_created']) && is_scalar($mine[0]['date_created']) ? ' · autorizado em ' . self::safeText((string)$mine[0]['date_created']) : '') : 'aplicativo não encontrado entre as autorizações da conta';
                $t['escopos']['escopos'] = array_map('strval', $scopes);
            }
        }
        $run('busca', 'Pesquisa de anúncios', '/sites/MLB/search?' . http_build_query(['q' => 'fone', 'limit' => 1]), true);
        $run('busca_publica', 'Pesquisa de anúncios sem token', '/sites/MLB/search?' . http_build_query(['q' => 'fone', 'limit' => 1]), false);
        if ($mlb) {
            $run('item', "Anúncio individual $mlb", "/items/$mlb", true);
            $run('item_publico', "Anúncio $mlb sem token", "/items/$mlb", false);
            $multi = $run('item_lote', 'Consulta em lote (multiget)', "/items?ids=$mlb", true);
            $j = json_decode((string)$multi['body'], true);
            if (is_array($j) && isset($j[0]['code'])) {
                $t['item_lote']['codigo_api'] = 'código do item no lote: ' . (int)$j[0]['code'];
                $t['item_lote']['ok'] = (int)$j[0]['code'] === 200;
                $raw = $j[0]['body']['code'] ?? $j[0]['body']['error'] ?? null;
                $code = is_scalar($raw) ? mb_substr((string)\Gmax\Automation\RunLog::sanitize((string)$raw), 0, 80) : null;
                $t['item_lote'] = array_merge($t['item_lote'], self::classify(['status' => (int)$j[0]['code'], 'error' => null], true, $code));
            }
            $run('preco_venda', 'Preço de venda do anúncio (sale_price)', "/items/$mlb/sale_price", true);
            $run('descricao', 'Descrição do anúncio de terceiro', "/items/$mlb/description", true);
        }
        $ps = $run('catalogo_busca', 'Pesquisa no catálogo de produtos', '/products/search?' . http_build_query(['status' => 'active', 'site_id' => 'MLB', 'q' => 'fone', 'limit' => 1]), true);
        $pid = (string)(json_decode((string)$ps['body'], true)['results'][0]['id'] ?? '');
        if (preg_match('/^MLB\d{4,15}$/D', $pid)) {
            $run('catalogo_produto', 'Produto do catálogo', "/products/$pid", true);
        }
        $dd = $run('dominio', 'Descoberta de categoria (domain_discovery)', '/sites/MLB/domain_discovery/search?' . http_build_query(['q' => 'fone', 'limit' => 1]), true);
        $cat = (string)(json_decode((string)$dd['body'], true)[0]['category_id'] ?? '');
        if (preg_match('/^MLB\d{1,10}$/D', $cat)) {
            $run('categoria', 'Categoria (/categories/{id})', "/categories/$cat", true);
        }
        $run('tendencias', 'Tendências de busca (/trends/MLB)', '/trends/MLB', true);
        if (ctype_digit($uid)) {
            $own = $run('itens_proprios', 'Anúncios da própria conta', "/users/$uid/items/search?limit=1", true);
            $oid = (string)(json_decode((string)$own['body'], true)['results'][0] ?? '');
            if (preg_match('/^MLB\d{5,15}$/D', $oid)) {
                $run('item_proprio', 'Anúncio da própria conta', "/items/$oid", true);
            }
        }
        return ['em' => Time::nowSql(), 'mlb' => $mlb, 'testes' => array_values($t), 'conclusoes' => self::conclude($t)];
    }

    private static function safeText(string $s): string
    {
        return mb_substr((string)\Gmax\Automation\RunLog::sanitize(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s)), 0, 200);
    }

    /** Resultado, causa provável, tipo e orientação de uma resposta. */
    public static function classify(array $res, bool $auth, ?string $apiCode): array
    {
        $policy = $apiCode !== null && str_starts_with(strtoupper($apiCode), 'PA_');
        return match (true) {
            $res['error'] !== null && !$res['status'] => ['resultado' => 'Falha de rede', 'tipo' => 'implementacao', 'causa' => 'O servidor não conseguiu falar com api.mercadolibre.com', 'orientacao' => 'Verifique a conectividade de saída da hospedagem e tente de novo'],
            $res['status'] === 200 => ['resultado' => 'Disponível', 'tipo' => 'ok', 'causa' => '—', 'orientacao' => '—'],
            $res['status'] === 401 && $auth => ['resultado' => 'Token rejeitado', 'tipo' => 'configuracao', 'causa' => 'Token expirado, revogado ou de outro aplicativo', 'orientacao' => 'Integrações → Reconectar conta'],
            $res['status'] === 401 => ['resultado' => 'Exige autenticação', 'tipo' => 'ok', 'causa' => 'Recurso não é público (esperado)', 'orientacao' => '—'],
            $res['status'] === 403 && $policy => ['resultado' => 'Bloqueado por política (PolicyAgent)', 'tipo' => $auth ? 'autorizacao' : 'plataforma',
                'causa' => 'O Mercado Livre não autoriza este recurso para o aplicativo/conta (' . $apiCode . ')',
                'orientacao' => 'Confira as permissões funcionais (leitura) no DevCenter e reautorize; se já habilitadas, é restrição da plataforma: solicite liberação ao suporte'],
            $res['status'] === 403 => ['resultado' => 'Acesso negado', 'tipo' => $auth ? 'autorizacao' : 'plataforma',
                'causa' => $auth ? 'Aplicativo, conta ou escopo sem permissão para este recurso' : 'Acesso sem token não permitido pela plataforma',
                'orientacao' => $auth ? 'Verifique escopos/permissões do aplicativo, validação de dados da conta e lista de IPs; depois solicite ao suporte' : '—'],
            $res['status'] === 404 => ['resultado' => 'Não encontrado', 'tipo' => 'anuncio', 'causa' => 'Anúncio inexistente, removido ou código incorreto', 'orientacao' => 'Confira o código MLB no anúncio'],
            $res['status'] === 429 => ['resultado' => 'Limite de requisições', 'tipo' => 'plataforma', 'causa' => 'Muitas chamadas em pouco tempo (limite por aplicativo e endpoint)', 'orientacao' => 'Aguarde alguns minutos; reduza a frequência das automações'],
            default => ['resultado' => 'HTTP ' . $res['status'], 'tipo' => 'plataforma', 'causa' => 'Resposta inesperada do Mercado Livre', 'orientacao' => 'Repita o teste mais tarde'],
        };
    }

    /** @param array<string,array> $t @return list<array{tipo:string,texto:string}> */
    private static function conclude(array $t): array
    {
        $c = [];
        $st = static fn(string $id) => $t[$id]['http'] ?? null;
        $authOk = $st('conta') === 200 || $st('dominio') === 200 || $st('site_token') === 200;
        if (($t['site']['http'] ?? null) !== 200 && $authOk) {
            $c[] = ['tipo' => 'plataforma', 'texto' => 'Chamadas SEM token são negadas a partir deste servidor, mas chamadas COM token funcionam (ex.: /users/me): não é bloqueio geral do IP da hospedagem; o acesso anônimo é que está fechado para esta origem. Para comparar, abra https://api.mercadolibre.com/sites/MLB no navegador do seu computador ou celular (sem login, sem token).'];
        } elseif (($t['site']['http'] ?? null) !== 200 && !$authOk && ($t['site']['http'] ?? null) !== null) {
            $c[] = ['tipo' => 'implementacao', 'texto' => 'Nenhuma chamada respondeu 200, com ou sem token: suspeite de bloqueio de rede/IP da hospedagem antes de qualquer outra causa.'];
        }
        if (isset($t['aplicativo']['app_ativo']) && $t['aplicativo']['app_ativo'] === false) {
            $c[] = ['tipo' => 'configuracao', 'texto' => 'O Mercado Livre informa o aplicativo como INATIVO: reative-o no DevCenter antes de qualquer outro teste.'];
        }
        if (isset($t['escopos']['escopos'])) {
            $sc = $t['escopos']['escopos'];
            $c[] = in_array('read', $sc, true)
                ? ['tipo' => 'ok', 'texto' => 'O token atual tem o escopo "read" concedido (' . implode(', ', $sc) . '): o 403 não vem de escopo OAuth ausente.']
                : ['tipo' => 'configuracao', 'texto' => 'O escopo "read" NÃO aparece entre os concedidos ao aplicativo (' . (implode(', ', $sc) ?: 'nenhum') . '): reconecte a conta depois de salvar as permissões no DevCenter.'];
        }
        if ($st('descricao') === 200 && $st('item') === 403) {
            $c[] = ['tipo' => 'plataforma', 'texto' => 'A descrição do anúncio de terceiro é liberada, mas o anúncio (preço/detalhe) não: a restrição é específica a dados comerciais de anúncios de outros vendedores, não ao aplicativo como um todo.'];
        }
        if ($st('conta') === 200) {
            $c[] = ['tipo' => 'ok', 'texto' => 'O token é válido e a conta é reconhecida (/users/me = 200): não é falha de implementação do OAuth nem do cabeçalho Authorization.'];
        } elseif ($st('conta') === 401) {
            $c[] = ['tipo' => 'configuracao', 'texto' => 'O token foi rejeitado em /users/me: reconecte a conta antes de avaliar os demais recursos.'];
        }
        foreach (['busca' => 'A pesquisa (/sites/MLB/search)', 'item' => 'A consulta de anúncio de terceiros (/items/{id})'] as $id => $label) {
            $a = $st($id);
            $p = $st($id . '_publica') ?? $st($id . '_publico');
            if ($a === 403 && $p === 403) {
                $c[] = ['tipo' => 'plataforma', 'texto' => "$label é negada com e sem token: restrição da plataforma/política do Mercado Livre para este recurso, não um erro do token."];
            } elseif ($a === 403 && $p === 200) {
                $c[] = ['tipo' => 'autorizacao', 'texto' => "$label funciona sem token mas é negada com o token do aplicativo: falta autorização/permissão do aplicativo ou da conta."];
            } elseif ($a === 200) {
                $c[] = ['tipo' => 'ok', 'texto' => "$label está disponível: a automação correspondente pode ser reativada."];
            }
        }
        if (($t['item_lote']['tipo'] ?? '') === 'anuncio' || ($t['item']['tipo'] ?? '') === 'anuncio') {
            $c[] = ['tipo' => 'anuncio', 'texto' => 'O anúncio informado não existe ou foi removido: teste com outro código MLB ativo.'];
        }
        if ($st('item_proprio') === 200 && $st('item') === 403) {
            $c[] = ['tipo' => 'plataforma', 'texto' => 'Anúncios da própria conta podem ser lidos, mas os de terceiros não: a política restringe leitura de anúncios de outros vendedores.'];
        }
        foreach (['catalogo_busca' => 'Pesquisa no catálogo (/products/search)', 'catalogo_produto' => 'Produto do catálogo (/products/{id})', 'preco_venda' => 'Preço de venda (/items/{id}/sale_price)',
            'dominio' => 'Descoberta de categoria', 'categoria' => 'Categorias', 'tendencias' => 'Tendências de busca', 'descricao' => 'Descrição de anúncio'] as $id => $label) {
            if ($st($id) === 200) {
                $c[] = ['tipo' => 'ok', 'texto' => "Alternativa oficial disponível: $label."];
            }
        }
        // Multiget responde 200 no envelope mesmo com o item negado: vale o resultado por item ("ok").
        if (!array_filter(['catalogo_busca', 'catalogo_produto', 'preco_venda', 'item', 'item_lote'], static fn($id) => !empty($t[$id]['ok']))) {
            $c[] = ['tipo' => 'plataforma', 'texto' => 'Nenhuma fonte oficial com PREÇO de anúncios de terceiros respondeu 200 para este aplicativo (anúncio, lote, sale_price, catálogo): mantenha a conferência manual e envie a solicitação ao suporte.'];
        }
        return $c;
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
        $me = $this->get('/users/me', true);
        $search = $this->get('/sites/MLB/search?' . http_build_query(['q' => 'fone', 'limit' => 1]), true);
        $why = static fn(array $r) => $r['error'] ?? match (true) {
            $r['status'] === 200 => 'Disponível',
            in_array($r['status'], [401, 403], true) => 'Não autorizado para este aplicativo (HTTP ' . $r['status'] . ')',
            default => 'HTTP ' . $r['status'],
        };
        return ['conta' => ['ok' => $me['status'] === 200, 'detalhe' => $why($me)], 'busca' => ['ok' => $search['status'] === 200, 'detalhe' => $why($search)]];
    }
}

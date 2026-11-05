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
        if ($res['status'] === 401 && $this->oauth !== null && ($new = $this->token(true)) !== null) {
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

    public function fetch(array $row): array
    {
        if (!$this->configured() || $this->token() === null) {
            return ['ok' => false, 'error' => 'Conector Mercado Livre não conectado (OAuth) ou token não renovável', 'data' => []];
        }
        $ext = (string)($row['external_id'] ?? '');
        if (!preg_match('/^item:(MLB\d{5,15})$/D', $ext, $m)) {
            return ['ok' => false, 'error' => 'Consulta disponível apenas para anúncios individuais (MLB-…); produtos de catálogo exigem escolher o anúncio', 'data' => []];
        }
        $endpoint = '/items/' . $m[1];
        $res = $this->get($endpoint);
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
        $r = $this->fetch(['external_id' => 'item:' . $mlb]);
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

    /**
     * Diagnóstico completo (somente leitura, supervisionado): ignora a suspensão automática e não a altera.
     * Pares com e sem token distinguem restrição da plataforma de falta de autorização do aplicativo.
     * Nunca devolve token, segredo nem o corpo completo das respostas.
     * @return array{em:string,mlb:?string,testes:list<array>,conclusoes:list<array{tipo:string,texto:string}>}
     */
    public function diagnose(?string $mlb = null): array
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
        $run('site', 'Metadados públicos do site (controle de rede/IP)', '/sites/MLB', false);
        $me = $run('conta', 'Conta autenticada', '/users/me', true);
        $uid = (string)(json_decode((string)$me['body'], true)['id'] ?? '');
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
                $t['item_lote'] = array_merge($t['item_lote'], self::classify(['status' => (int)$j[0]['code'], 'error' => null], true, (string)($j[0]['body']['code'] ?? $j[0]['body']['error'] ?? '')));
            }
            $run('preco_venda', 'Preço de venda do anúncio (sale_price)', "/items/$mlb/sale_price", true);
        }
        $ps = $run('catalogo_busca', 'Pesquisa no catálogo de produtos', '/products/search?' . http_build_query(['status' => 'active', 'site_id' => 'MLB', 'q' => 'fone', 'limit' => 1]), true);
        $pid = (string)(json_decode((string)$ps['body'], true)['results'][0]['id'] ?? '');
        if (preg_match('/^MLB\d{4,15}$/D', $pid)) {
            $run('catalogo_produto', 'Produto do catálogo', "/products/$pid", true);
        }
        $run('dominio', 'Descoberta de categoria (domain_discovery)', '/sites/MLB/domain_discovery/search?' . http_build_query(['q' => 'fone', 'limit' => 1]), true);
        if (ctype_digit($uid)) {
            $own = $run('itens_proprios', 'Anúncios da própria conta', "/users/$uid/items/search?limit=1", true);
            $oid = (string)(json_decode((string)$own['body'], true)['results'][0] ?? '');
            if (preg_match('/^MLB\d{5,15}$/D', $oid)) {
                $run('item_proprio', 'Anúncio da própria conta', "/items/$oid", true);
            }
        }
        return ['em' => Time::nowSql(), 'mlb' => $mlb, 'testes' => array_values($t), 'conclusoes' => self::conclude($t)];
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
        if (($t['site']['http'] ?? null) !== 200) {
            $c[] = ['tipo' => 'implementacao', 'texto' => 'Nem o recurso público /sites/MLB respondeu 200: suspeite de rede ou bloqueio de IP da hospedagem antes de qualquer outra causa.'];
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
        foreach (['catalogo_busca' => 'Pesquisa no catálogo (/products/search)', 'catalogo_produto' => 'Produto do catálogo (/products/{id})', 'preco_venda' => 'Preço de venda (/items/{id}/sale_price)', 'dominio' => 'Descoberta de categoria'] as $id => $label) {
            if ($st($id) === 200) {
                $c[] = ['tipo' => 'ok', 'texto' => "Alternativa oficial disponível: $label."];
            }
        }
        if (!array_filter($c, static fn($x) => $x['tipo'] === 'ok' && str_contains($x['texto'], 'Alternativa'))) {
            $c[] = ['tipo' => 'plataforma', 'texto' => 'Nenhuma alternativa oficial de leitura de produtos respondeu 200 para este aplicativo: mantenha a conferência manual e envie a solicitação ao suporte.'];
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

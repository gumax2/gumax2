<?php
declare(strict_types=1);
/*
 * OAuth Mercado Livre (3.1.1): PKCE S256, state, troca do código, armazenamento cifrado,
 * renovação com rotação do refresh token, concorrência e 401. Sem rede (HTTP falso).
 * Execução: php -d allow_url_fopen=0 tests/oauth_test.php
 */
require __DIR__ . '/harness.php';
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';
require __DIR__ . '/fakes.php';

use Gmax\Connectors\MercadoLivre;
use Gmax\OAuth\{MercadoLivreOAuth, TokenStore};
use Gmax\Time;

/** HTTP falso com fila de respostas e registro das requisições. */
final class QueueHttp extends Gmax\Http
{
    public array $requests = [];

    public function __construct(public array $responses)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        return array_shift($this->responses) ?? ['status' => 0, 'body' => null, 'error' => 'sem resposta'];
    }
}

$dir = t_sandbox() . '/oauth-secrets';
@mkdir($dir, 0700, true);
$APP = ['client_id' => '1234567890123456', 'client_secret' => 'segredo-de-teste-muito-longo-123', 'redirect_uri' => 'https://gmaxcanais.com.br/gmax-auto/ml-oauth-callback.php'];
$tokenJson = static fn(string $access, string $refresh, int $exp = 21600) => ['status' => 200, 'error' => null, 'body' => json_encode(['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => $exp, 'scope' => 'offline_access read', 'user_id' => 987654321, 'refresh_token' => $refresh])];
$fresh = static function () use ($dir) {
    foreach (glob("$dir/*") as $f) {
        unlink($f);
    }
    Time::freeze(null);
};
$connect = static function (QueueHttp $http) use ($APP, $dir, $tokenJson): MercadoLivreOAuth {
    $o = new MercadoLivreOAuth($http, $APP, $dir);
    $session = [];
    $url = $o->begin($session);
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
    $http->responses = array_merge([$tokenJson('APP_USR-acesso-1', 'TG-refresh-1')], $http->responses);
    $o->complete($session, ['code' => 'TG-codigo-autorizacao-1', 'state' => $q['state']]);
    return $o;
};

echo "\n== PKCE e state\n";
test('PKCE S256 confere com o vetor oficial da RFC 7636', function () {
    eq('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', MercadoLivreOAuth::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
});
test('URL de autorização: S256, state aleatório, redirect exata, verifier só na sessão', function () use ($APP, $dir, $fresh) {
    $fresh();
    $o = new MercadoLivreOAuth(new QueueHttp([]), $APP, $dir);
    $s1 = [];
    $url = $o->begin($s1);
    ok(str_starts_with($url, 'https://auth.mercadolivre.com.br/authorization?'), 'endpoint oficial');
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
    eq('code', $q['response_type']);
    eq('S256', $q['code_challenge_method']);
    eq($APP['redirect_uri'], $q['redirect_uri']);
    eq(MercadoLivreOAuth::challenge($s1['gmax3_ml_oauth']['verifier']), $q['code_challenge']);
    ok(strlen($s1['gmax3_ml_oauth']['verifier']) >= 43 && strlen($s1['gmax3_ml_oauth']['verifier']) <= 128, 'tamanho do verifier');
    ok(!str_contains($url, $s1['gmax3_ml_oauth']['verifier']), 'verifier não vai na URL');
    ok(!str_contains($url, $APP['client_secret']), 'segredo não vai na URL');
    $s2 = [];
    $o->begin($s2);
    ok($s1['gmax3_ml_oauth']['state'] !== $s2['gmax3_ml_oauth']['state'], 'state aleatório');
});
test('Callback recusa state errado, ausente, expirado, reutilizado e erro do provedor', function () use ($APP, $dir, $fresh) {
    $fresh();
    $http = new QueueHttp([]);
    $o = new MercadoLivreOAuth($http, $APP, $dir);
    $s = [];
    $o->begin($s);
    throws(function () use ($o, &$s) { $o->complete($s, ['code' => 'TG-abcdefgh', 'state' => 'forjado']); }, 'State inválido');
    throws(function () use ($o, &$s) { $o->complete($s, ['code' => 'TG-abcdefgh', 'state' => 'x']); }, 'State inválido'); // já consumido
    $o->begin($s);
    $s['gmax3_ml_oauth']['at'] = time() - 3600;
    throws(function () use ($o, &$s) { $o->complete($s, ['code' => 'TG-abcdefgh', 'state' => $s['gmax3_ml_oauth']['state']]); }, 'expirada');
    $o->begin($s);
    throws(function () use ($o, &$s) { $o->complete($s, ['error' => 'access_denied', 'state' => $s['gmax3_ml_oauth']['state']]); }, 'recusada');
    eq([], $http->requests, 'nenhuma troca de código foi tentada');
    ok(!isset($s['gmax3_ml_oauth']), 'state descartado');
});
test('Troca do código envia code_verifier, redirect_uri e grant correto; token gravado cifrado (0600)', function () use ($connect, $dir, $fresh, $APP) {
    $fresh();
    $http = new QueueHttp([]);
    $o = $connect($http);
    parse_str((string)$http->requests[0]['body'], $form);
    eq('authorization_code', $form['grant_type']);
    eq($APP['redirect_uri'], $form['redirect_uri']);
    ok(strlen($form['code_verifier']) >= 43, 'code_verifier enviado');
    eq(MercadoLivreOAuth::TOKEN_URL, $http->requests[0]['url']);
    $raw = file_get_contents("$dir/mercadolivre-token.enc");
    ok(!str_contains($raw, 'APP_USR-acesso-1') && !str_contains($raw, 'TG-refresh-1'), 'sem token em texto puro');
    eq(0600, fileperms("$dir/mercadolivre-token.enc") & 0777);
    eq('APP_USR-acesso-1', $o->accessToken());
    $st = $o->status();
    ok($st['connected'] && $st['user_id_end'] === '4321', 'status sem expor tokens');
    ok(!str_contains(json_encode($st), 'APP_USR'), 'status não contém token');
});

echo "\n== Renovação e rotação do refresh token\n";
test('Token perto de expirar é renovado; refresh token novo substitui o antigo (rotação)', function () use ($connect, $fresh, $tokenJson) {
    $fresh();
    $http = new QueueHttp([]);
    $o = $connect($http);
    Time::freeze(new DateTimeImmutable('+6 hours'));
    $http->responses = [$tokenJson('APP_USR-acesso-2', 'TG-refresh-2')];
    eq('APP_USR-acesso-2', $o->accessToken());
    parse_str((string)end($http->requests)['body'], $form);
    eq('refresh_token', $form['grant_type']);
    eq('TG-refresh-1', $form['refresh_token']);
    Time::freeze(new DateTimeImmutable('+12 hours'));
    $http->responses = [$tokenJson('APP_USR-acesso-3', 'TG-refresh-3')];
    eq('APP_USR-acesso-3', $o->accessToken());
    parse_str((string)end($http->requests)['body'], $form);
    eq('TG-refresh-2', $form['refresh_token'], 'usa o refresh token rotacionado, nunca o antigo');
    Time::freeze(null);
});
test('Token válido não gera requisição; outro processo renovou antes: não renova de novo', function () use ($connect, $fresh, $APP, $dir) {
    $fresh();
    $http = new QueueHttp([]);
    $o = $connect($http);
    $n = count($http->requests);
    $o->accessToken();
    eq($n, count($http->requests), 'sem renovação desnecessária');
    Time::freeze(new DateTimeImmutable('+6 hours'));
    // Simula: entre a leitura e a trava, outro processo gravou um token novo.
    $store = new TokenStore($dir, $APP['client_secret']);
    $store->save(['access_token' => 'APP_USR-outro-processo', 'refresh_token' => 'TG-refresh-X', 'expires_at' => Time::now()->getTimestamp() + 21600, 'user_id' => '1', 'scope' => '']);
    eq('APP_USR-outro-processo', $o->accessToken());
    eq($n, count($http->requests), 'refresh token de uso único não foi reutilizado');
    Time::freeze(null);
});
test('invalid_grant na renovação: token marcado para reautorizar; conector fica desconectado', function () use ($connect, $fresh) {
    $fresh();
    $http = new QueueHttp([]);
    $o = $connect($http);
    Time::freeze(new DateTimeImmutable('+7 hours'));
    $http->responses = [['status' => 400, 'error' => null, 'body' => json_encode(['error' => 'invalid_grant', 'message' => 'invalid refresh token'])]];
    eq(null, $o->accessToken());
    ok(!$o->status()['connected'] && $o->status()['invalid'] !== null, 'exige reconectar');
    eq(false, (new MercadoLivre(new QueueHttp([]), null, $o))->configured());
    Time::freeze(null);
});
test('Falha de rede na renovação não invalida o token (tenta de novo depois)', function () use ($connect, $fresh, $tokenJson) {
    $fresh();
    $http = new QueueHttp([]);
    $o = $connect($http);
    Time::freeze(new DateTimeImmutable('+7 hours'));
    $http->responses = [['status' => 0, 'error' => 'timeout', 'body' => null]];
    throws(fn() => $o->accessToken(), 'rede');
    ok($o->status()['connected'], 'continua conectado');
    $http->responses = [$tokenJson('APP_USR-acesso-9', 'TG-refresh-9')];
    eq('APP_USR-acesso-9', $o->accessToken());
    Time::freeze(null);
});
test('Conector: 401 da API renova uma vez e repete a consulta; pesquisa usa o token OAuth', function () use ($connect, $fresh, $tokenJson) {
    $fresh();
    $http = new QueueHttp([]);
    $o = $connect($http);
    $http->responses = [
        ['status' => 401, 'error' => null, 'body' => '{}'],
        $tokenJson('APP_USR-acesso-novo', 'TG-refresh-novo'),
        ['status' => 200, 'error' => null, 'body' => json_encode(['results' => [['id' => 'MLB3000000001', 'title' => 'Fone', 'price' => 99.9, 'available_quantity' => 3]]])],
    ];
    $c = new MercadoLivre($http, null, $o);
    $r = $c->search('fone', 5);
    eq(true, $r['ok']);
    eq(9990, $r['items'][0]['price_promo_cents']);
    $last = end($http->requests);
    ok(in_array('Authorization: Bearer APP_USR-acesso-novo', $last['headers'], true), 'repetiu com o token renovado');
    eq('GET', $last['method'], 'somente leitura');
});
test('Diagnóstico de permissões: busca negada (403) é reportada sem quebrar', function () use ($connect, $fresh) {
    $fresh();
    $http = new QueueHttp([]);
    $o = $connect($http);
    $http->responses = [['status' => 200, 'error' => null, 'body' => '{"id":1}'], ['status' => 403, 'error' => null, 'body' => '{"message":"forbidden"}']];
    $p = (new MercadoLivre($http, null, $o))->probePermissions();
    eq(true, $p['conta']['ok']);
    eq(false, $p['busca']['ok']);
    ok(str_contains($p['busca']['detalhe'], 'Não autorizado'), $p['busca']['detalhe']);
});
test('Configuração do app: redirect sem https, client_id inválido e ausência do arquivo são apontados', function () use ($dir) {
    eq(['Arquivo gmax-secrets/mercadolivre-app.php ausente'], (new MercadoLivreOAuth(new QueueHttp([]), null, "$dir/nao-existe"))->appProblems());
    $bad = new MercadoLivreOAuth(new QueueHttp([]), ['client_id' => 'abc', 'client_secret' => 'curto', 'redirect_uri' => 'http://x.com/cb?a=1'], $dir);
    eq(3, count($bad->appProblems()));
    $s = [];
    throws(fn() => $bad->begin($s), 'não configurado');
});
test('Segredo trocado: token antigo não decifra (exige nova conexão)', function () use ($connect, $fresh, $dir) {
    $fresh();
    $connect(new QueueHttp([]));
    eq(null, (new TokenStore($dir, 'outro-segredo-completamente-diferente'))->load());
});

$pass = count(array_filter($GLOBALS['t_results'], fn($r) => $r[1]));
$total = count($GLOBALS['t_results']);
echo "\nResultado OAuth: $pass/$total testes aprovados\n";
file_put_contents(__DIR__ . '/../docs/resultado-testes-oauth.json', json_encode([
    'executado_em' => date('c'), 'php' => PHP_VERSION, 'aprovados' => $pass, 'total' => $total,
    'testes' => array_map(fn($r) => ['nome' => $r[0], 'ok' => $r[1], 'erro' => $r[2], 'segundos' => round($r[3], 3)], $GLOBALS['t_results']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($pass === $total ? 0 : 1);

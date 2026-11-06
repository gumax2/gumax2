<?php
declare(strict_types=1);
/*
 * Testes ponta a ponta via HTTP (servidor embutido do PHP) e CLI.
 * O servidor roda com allow_url_fopen=0 e GMAX_TESTING=1: nenhuma chamada externa é possível.
 * Execução: php tests/http_test.php
 */
require __DIR__ . '/harness.php';
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';

use Gmax\{Db, Settings};

t_seed();
$app = t_app();
$port = 18000 + random_int(0, 999);
$base = "http://127.0.0.1:$port";
$env = ['GMAX_SECRETS_DIR' => t_sandbox() . '/gmax-secrets', 'GMAX_TESTING' => '1', 'PATH' => getenv('PATH')];
@mkdir(t_sandbox() . '/sessoes', 0700, true);
$server = proc_open([PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'session.save_path=' . t_sandbox() . '/sessoes', '-S', "127.0.0.1:$port", '-t', $app], [1 => ['file', '/dev/null', 'w'], 2 => ['file', t_sandbox() . '/server.log', 'w']], $pipes, $app, $env);
register_shutdown_function(static function () use ($server) {
    proc_terminate($server);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}

$jar = [];
/** @return array{status:int,headers:array,body:string} */
function http(string $method, string $path, array $fields = [], array $files = []): array
{
    global $base, $jar;
    $headers = [];
    if ($jar) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($jar), $jar));
    }
    $content = null;
    if ($method === 'POST') {
        if ($files) {
            $b = '----gmax' . bin2hex(random_bytes(6));
            $content = '';
            foreach ($fields as $k => $v) {
                foreach ((array)$v as $vv) {
                    $content .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$vv\r\n";
                }
            }
            foreach ($files as $k => [$name, $data]) {
                $content .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"; filename=\"$name\"\r\nContent-Type: application/octet-stream\r\n\r\n$data\r\n";
            }
            $content .= "--$b--\r\n";
            $headers[] = "Content-Type: multipart/form-data; boundary=$b";
        } else {
            $content = http_build_query($fields);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content ?? '', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20]]);
    $body = (string)file_get_contents($base . '/' . ltrim($path, '/'), false, $ctx);
    $hdrs = $http_response_header ?? [];
    preg_match('~HTTP/\S+\s+(\d{3})~', $hdrs[0] ?? '', $m);
    foreach ($hdrs as $h) {
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $c)) {
            if ($c[2] === '' || $c[2] === 'deleted') {
                unset($jar[$c[1]]);
            } else {
                $jar[$c[1]] = $c[2];
            }
        }
    }
    return ['status' => (int)($m[1] ?? 0), 'headers' => $hdrs, 'body' => $body];
}

function follow(array $r): array
{
    foreach ($r['headers'] as $h) {
        if (preg_match('/^Location:\s*(.+)$/i', $h, $m)) {
            return http('GET', trim($m[1]));
        }
    }
    return $r;
}

function csrf(string $html): string
{
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m);
    return $m[1] ?? '';
}

function header_value(array $r, string $name): string
{
    foreach ($r['headers'] as $h) {
        if (stripos($h, $name . ':') === 0) {
            return trim(substr($h, strlen($name) + 1));
        }
    }
    return '';
}

function no_php_errors(array $r, string $where): void
{
    ok(!preg_match('/(Warning|Fatal error|Notice|Deprecated|Uncaught)/', $r['body']), "sem erros PHP em $where: " . substr(strip_tags($r['body']), 0, 300));
}

$cliRun = static function (array $cmd) use ($env, $app): array {
    $p = proc_open(array_merge([PHP_BINARY, '-d', 'allow_url_fopen=0'], $cmd), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $app, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
};

echo "\n== Segurança HTTP e sessão\n";
test('Sem login: tela de entrada, sem dados de ofertas, cabeçalhos de segurança', function () {
    $r = http('GET', 'painel.php?p=ofertas');
    eq(200, $r['status']);
    ok(str_contains($r['body'], 'Senha de administrador'), 'tela de login');
    ok(!str_contains($r['body'], 'Teste A'), 'nenhum produto exposto');
    ok(str_contains(header_value($r, 'Content-Security-Policy'), "default-src 'self'"), 'CSP');
    eq('DENY', header_value($r, 'X-Frame-Options'));
    eq('nosniff', header_value($r, 'X-Content-Type-Options'));
    $cookie = implode("\n", array_filter($r['headers'], fn($h) => stripos($h, 'Set-Cookie') === 0));
    ok(stripos($cookie, 'HttpOnly') !== false && stripos($cookie, 'SameSite=Lax') !== false, 'cookie HttpOnly + SameSite');
});
test('POST sem token CSRF é recusado (inclusive login)', function () {
    $r = follow(http('POST', 'painel.php', ['action' => 'login', 'password' => T_PASSWORD]));
    ok(str_contains($r['body'], 'Sessão expirada ou formulário inválido'), 'CSRF recusado');
    ok(str_contains($r['body'], 'Senha de administrador'), 'continua deslogado');
    $r = http('POST', 'painel.php', ['action' => 'pause', 'csrf' => 'x']);
    eq(303, $r['status']);
});
test('Ação sem login retorna 403 mesmo com CSRF válido', function () {
    $t = csrf(http('GET', 'painel.php')['body']);
    $r = http('POST', 'painel.php', ['action' => 'migrate', 'confirm' => 'MIGRAR', 'csrf' => $t]);
    eq(403, $r['status']);
});
test('Arquivos internos não executam pela web (guarda direta e CLI)', function () {
    eq('', http('GET', 'gmax-lib/pages/painel.php')['body'], 'página interna sem saída');
    $r = http('GET', 'gmax-admin-cli.php');
    eq(403, $r['status']);
    eq(403, http('GET', 'gmax-publicador-cli.php')['status'], 'publicador antigo continua só CLI');
});
test('index.php antigo (3.2.0): só redireciona ao painel; não tem login nem autentica', function () {
    foreach (['GET', 'POST'] as $m) {
        $r = http($m, 'index.php', $m === 'POST' ? ['action' => 'login', 'password' => T_PASSWORD, 'csrf' => 'x'] : []);
        eq(302, $r['status'], $m);
        eq('painel.php', header_value($r, 'Location'), 'destino fixo');
        ok(!str_contains($r['body'], 'password'), 'sem formulário de senha');
    }
    $r = http('GET', 'index.php?next=https://evil.example');
    eq('painel.php', header_value($r, 'Location'), 'ignora parâmetros (sem redirecionamento aberto)');
    ok(str_contains(http('GET', 'painel.php')['body'], 'Senha de administrador'), 'continua deslogado');
});
test('Login centralizado no painel.php leva à migração pendente', function () {
    $t = csrf(http('GET', 'painel.php')['body']);
    follow(http('POST', 'painel.php', ['action' => 'login', 'password' => T_PASSWORD, 'csrf' => $t]));
    $r = http('GET', 'painel.php');
    ok(str_contains($r['body'], 'Migração pendente'), 'logado e direcionado à migração');
    no_php_errors($r, 'sistema (pré-migração)');
});

echo "\n== Migração pelo navegador\n";
test('Migração exige confirmação digitada e cria backup', function () {
    $t = csrf(http('GET', 'painel.php?p=sistema')['body']);
    $r = follow(http('POST', 'painel.php', ['action' => 'migrate', 'confirm' => 'sim', 'csrf' => $t, 'back' => 'sistema']));
    ok(str_contains($r['body'], 'Digite MIGRAR'), 'confirmação exigida');
    $r = follow(http('POST', 'painel.php', ['action' => 'migrate', 'confirm' => 'MIGRAR', 'csrf' => $t, 'back' => 'sistema']));
    ok(str_contains($r['body'], 'Migração aplicada'), 'migrado: ' . substr(strip_tags($r['body']), 0, 400));
    ok(count(glob(t_sandbox() . '/gmax-secrets/gmax-backups/*.sqlite')) >= 1, 'backup criado');
});
test('Painel unificado mostra as duas lojas (5 Shopee + 1 Mercado Livre)', function () {
    $r = http('GET', 'painel.php');
    no_php_errors($r, 'visão geral');
    ok(preg_match('/Shopee <strong>5<\/strong>/', $r['body']) === 1, '5 Shopee');
    ok(preg_match('/Mercado Livre <strong>1<\/strong>/', $r['body']) === 1, '1 Mercado Livre');
    $r = http('GET', 'painel.php?p=ofertas&marketplace=mercadolivre');
    ok(str_contains($r['body'], 'Parafusadeira Furadeira 48V'), 'ML-001 listado');
    ok(str_contains($r['body'], '65% OFF'), 'desconto calculado');
});
test('mercadolivre.php antigo continua funcionando com a sessão do painel', function () {
    $r = http('GET', 'mercadolivre.php');
    eq(200, $r['status']);
    ok(str_contains($r['body'], 'Parafusadeira Furadeira 48V'), 'mercadolivre.php mostra ML-001');
    eq('painel.php', header_value(http('GET', 'index.php'), 'Location'), 'index.php leva ao painel centralizado');
});
test('Todas as páginas do painel abrem sem erros', function () {
    foreach (['painel', 'automacoes', 'integracoes', 'ofertas', 'oferta', 'importar', 'links', 'ranking', 'publicacoes', 'relatorios', 'categorias', 'sistema'] as $p) {
        $r = http('GET', "painel.php?p=$p");
        eq(200, $r['status'], $p);
        no_php_errors($r, $p);
    }
    $id = (int)Db::value(Db::get(), "SELECT id FROM gmax3_catalog WHERE codigo='ML-001'");
    no_php_errors(http('GET', "painel.php?p=oferta&id=$id"), 'oferta ML-001');
});

echo "\n== Importação pelo navegador\n";
test('Upload da planilha real .xlsx: prévia, confirmação e reimportação sem duplicar', function () {
    $t = csrf(http('GET', 'painel.php?p=importar')['body']);
    $file = file_get_contents(__DIR__ . '/fixtures/ml-25-anuncios.xlsx');
    $r = follow(http('POST', 'painel.php', ['action' => 'import_upload', 'csrf' => $t, 'back' => 'importar'], ['arquivo' => ['GMAX_25_Anuncios.xlsx', $file]]));
    ok(str_contains($r['body'], 'Confirmar importação'), 'prévia exibida');
    no_php_errors($r, 'prévia');
    preg_match('/name="batch" value="(\d+)"/', $r['body'], $m);
    $before = (int)Db::value(Db::get(), 'SELECT COUNT(*) FROM gmax3_catalog');
    eq(6, $before, 'prévia não grava');
    $r = follow(http('POST', 'painel.php', ['action' => 'import_apply', 'batch' => $m[1], 'csrf' => $t, 'back' => 'lote']));
    ok(str_contains($r['body'], '25 novo(s)'), 'aplicado');
    $r = follow(http('POST', 'painel.php', ['action' => 'import_upload', 'csrf' => $t, 'back' => 'importar'], ['arquivo' => ['GMAX_25_Anuncios.xlsx', $file]]));
    preg_match('/name="batch" value="(\d+)"/', $r['body'], $m);
    $r = follow(http('POST', 'painel.php', ['action' => 'import_apply', 'batch' => $m[1], 'csrf' => $t, 'back' => 'lote']));
    ok(str_contains($r['body'], '0 novo(s)'), 'reimportação sem novos');
    eq(31, (int)Db::value(Db::get(), 'SELECT COUNT(*) FROM gmax3_catalog'));
});
test('XSS: título malicioso importado é exibido como texto', function () {
    $t = csrf(http('GET', 'painel.php?p=importar')['body']);
    $r = follow(http('POST', 'painel.php', ['action' => 'import_upload', 'csrf' => $t, 'back' => 'importar'], ['arquivo' => ['inv.csv', file_get_contents(__DIR__ . '/fixtures/ml-invalidos.csv')]]));
    preg_match('/name="batch" value="(\d+)"/', $r['body'], $m);
    ok(!str_contains($r['body'], '<script>alert("xss")</script>'), 'prévia escapada');
    follow(http('POST', 'painel.php', ['action' => 'import_apply', 'batch' => $m[1], 'csrf' => $t, 'back' => 'lote']));
    $r = http('GET', 'painel.php?p=ofertas&q=xss');
    ok(str_contains($r['body'], '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;'), 'listagem escapada');
    ok(!str_contains($r['body'], '<script>alert("xss")'), 'nenhum script injetado');
    $id = (int)Db::value(Db::get(), "SELECT id FROM gmax3_catalog WHERE title LIKE '%xss%'");
    $r = http('GET', "painel.php?p=oferta&id=$id");
    ok(!str_contains($r['body'], '<script>alert("xss")'), 'detalhe escapado');
});
test('Exportação CSV neutraliza fórmulas de planilha', function () {
    Db::run(Db::get(), "UPDATE gmax3_catalog SET title='=HYPERLINK(\"http://x\")' WHERE codigo='ML-105'");
    $r = http('GET', 'painel.php?p=exportar');
    ok(str_contains($r['body'], "'=HYPERLINK"), 'fórmula prefixada');
});

echo "\n== Fluxo de aprovação, links e fila pelo navegador\n";
test('Associar link, conferir preço, aprovar e enfileirar (sem envio)', function () {
    $pdo = Db::get();
    $id = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE external_id='item:MLB4047815501'");
    $t = csrf(http('GET', 'painel.php?p=links')['body']);
    $r = follow(http('POST', 'painel.php', ['action' => 'link_set', 'id' => $id, 'affiliate_url' => 'https://www.mercadolivre.com.br/x', 'csrf' => $t, 'back' => 'links']));
    ok(str_contains($r['body'], 'meli.la'), 'link comum recusado');
    $r = follow(http('POST', 'painel.php', ['action' => 'link_set', 'id' => $id, 'affiliate_url' => 'https://meli.la/1uPYV76', 'csrf' => $t, 'back' => 'links']));
    ok(str_contains($r['body'], 'já está associado'), 'link de outro produto recusado');
    $r = follow(http('POST', 'painel.php', ['action' => 'link_set', 'id' => $id, 'affiliate_url' => 'https://meli.la/Mouse01', 'csrf' => $t, 'back' => 'links']));
    ok(str_contains($r['body'], 'Link associado'), 'link associado');
    eq('aguardando_validacao', Db::value($pdo, 'SELECT validation_status FROM gmax3_catalog WHERE id=?', [$id]));
    $r = follow(http('POST', 'painel.php', ['action' => 'offer_status', 'id' => $id, 'status' => 'aprovado', 'confirm_price' => '1', 'csrf' => $t, 'back' => 'oferta']));
    ok(str_contains($r['body'], 'Estado atualizado'), 'aprovado: ' . substr(strip_tags($r['body']), 0, 200));
    $r = follow(http('POST', 'painel.php', ['action' => 'queue_add', 'id' => $id, 'csrf' => $t, 'back' => 'oferta']));
    ok(str_contains($r['body'], 'Mercado Livre'), 'ML em grupo privado bloqueado no enfileiramento');
    // Shopee: aprovação em lote exige condição de pagamento.
    $shopee = array_map('intval', $pdo->query("SELECT id FROM gmax3_catalog WHERE marketplace='shopee' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    $r = follow(http('POST', 'painel.php', ['action' => 'bulk', 'op' => 'aprovar', 'ids' => $shopee, 'csrf' => $t, 'back' => 'ofertas']));
    ok(str_contains($r['body'], 'Condição de pagamento não identificada'), 'aprovação em lote mostra pendências');
    $pdo->exec("UPDATE gmax3_catalog SET payment_condition='normal' WHERE marketplace='shopee'");
    foreach ($shopee as $sid) {
        follow(http('POST', 'painel.php', ['action' => 'offer_confirm_price', 'id' => $sid, 'csrf' => $t, 'back' => 'oferta']));
    }
    $r = follow(http('POST', 'painel.php', ['action' => 'bulk', 'op' => 'confirmar_preco', 'ids' => $shopee, 'csrf' => $t, 'back' => 'ofertas']));
    ok(str_contains($r['body'], 'Operação inválida'), 'confirmação de preço em lote não existe');
    $r = follow(http('POST', 'painel.php', ['action' => 'bulk', 'op' => 'aprovar', 'ids' => $shopee, 'csrf' => $t, 'back' => 'ofertas']));
    ok(str_contains($r['body'], '5 produto(s) processado(s)'), 'lote aprovado');
    $r = follow(http('POST', 'painel.php', ['action' => 'bulk', 'op' => 'enfileirar', 'ids' => $shopee, 'csrf' => $t, 'back' => 'ofertas']));
    ok(str_contains($r['body'], '0 produto(s) processado(s)') && str_contains($r['body'], 'pendente na fila do publicador antigo'), 'pendentes do V2.1 bloqueados');
    global $cliRun;
    [$code, $out] = $cliRun(['gmax-admin-cli.php', 'publicador-v21', '--aposentar', 'CONFIRMO_V21_DESATIVADO']);
    eq(0, $code, $out);
    $r = follow(http('POST', 'painel.php', ['action' => 'bulk', 'op' => 'enfileirar', 'ids' => $shopee, 'csrf' => $t, 'back' => 'ofertas']));
    ok(str_contains($r['body'], '4 produto(s) processado(s)') && str_contains($r['body'], 'enviado pelo publicador antigo'), 'item já enviado pelo V2.1 não entra');
    eq(4, (int)$pdo->query('SELECT COUNT(DISTINCT scheduled_for) FROM gmax3_pub_queue')->fetchColumn(), 'horários distintos');
    eq(4, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_pub_queue WHERE state='agendado'"));
});
test('Pausa emergencial e retomada com confirmação; nenhum envio pelo navegador', function () {
    $t = csrf(http('GET', 'painel.php?p=publicacoes')['body']);
    $pdo = Db::get();
    Db::run($pdo, "UPDATE gmax3_settings SET value='0' WHERE key='publicacao_pausada'");
    $r = follow(http('POST', 'painel.php', ['action' => 'pause', 'reason' => 'teste', 'csrf' => $t, 'back' => 'publicacoes']));
    ok(str_contains($r['body'], 'PAUSA EMERGENCIAL ATIVADA'), 'pausado');
    eq('1', Db::value($pdo, "SELECT value FROM gmax3_settings WHERE key='publicacao_pausada'"));
    $r = follow(http('POST', 'painel.php', ['action' => 'resume', 'confirm' => 'x', 'csrf' => $t, 'back' => 'publicacoes']));
    ok(str_contains($r['body'], 'Digite RETOMAR'), 'confirmação exigida');
    eq('1', Db::value($pdo, "SELECT value FROM gmax3_settings WHERE key='publicacao_pausada'"));
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_pub_queue WHERE state NOT IN ('agendado')"), 'nenhum item saiu de agendado');
    ok(!str_contains((string)file_get_contents(t_sandbox() . '/server.log'), 'api.telegram.org'), 'servidor nunca acessou Telegram');
});
test('Configurações sensíveis não são alteráveis pela web', function () {
    $t = csrf(http('GET', 'painel.php?p=sistema')['body']);
    follow(http('POST', 'painel.php', ['action' => 'settings_save', 'automacao_comercial' => '1', 'ml_grupo_privado_permitido' => '1', 'janela_duplicidade_dias' => '10', 'csrf' => $t, 'back' => 'sistema']));
    $pdo = Db::get();
    eq('0', Db::value($pdo, "SELECT value FROM gmax3_settings WHERE key='automacao_comercial'"));
    eq('0', Db::value($pdo, "SELECT value FROM gmax3_settings WHERE key='ml_grupo_privado_permitido'"));
    eq('10', Db::value($pdo, "SELECT value FROM gmax3_settings WHERE key='janela_duplicidade_dias'"));
});
test('Bloqueio após 5 senhas erradas', function () {
    global $jar;
    $saved = $jar;
    $jar = [];
    for ($i = 0; $i < 5; $i++) {
        $t = csrf(http('GET', 'painel.php')['body']);
        http('POST', 'painel.php', ['action' => 'login', 'password' => 'errada', 'csrf' => $t]);
    }
    $t = csrf(http('GET', 'painel.php')['body']);
    $r = follow(http('POST', 'painel.php', ['action' => 'login', 'password' => T_PASSWORD, 'csrf' => $t]));
    ok(str_contains($r['body'], 'Muitas tentativas'), 'bloqueado mesmo com senha certa');
    Db::get()->exec('DELETE FROM gmax3_login_attempts');
    $jar = $saved;
});
test('Logout encerra a sessão', function () {
    global $jar;
    $saved = $jar;
    $t = csrf(http('GET', 'painel.php')['body']);
    follow(http('POST', 'painel.php', ['action' => 'logout', 'csrf' => $t]));
    $r = http('GET', 'painel.php?p=ofertas');
    ok(str_contains($r['body'], 'Senha de administrador'), 'deslogado');
    $jar = $saved;
});

/** Grava uma sessão PHP "pronta" (formato php serialize_handler) e devolve o cookie para usá-la. */
function forge_session(array $data): array
{
    $id = bin2hex(random_bytes(16));
    $raw = '';
    foreach ($data as $k => $v) {
        $raw .= $k . '|' . serialize($v);
    }
    file_put_contents(t_sandbox() . "/sessoes/sess_$id", $raw);
    return ['PHPSESSID' => $id];
}
test('3.2.0: sessão criada pelo login antigo (sem marcador) não abre o painel e pede novo login', function () {
    global $jar;
    $saved = $jar;
    $jar = forge_session(['auth' => true, 'csrf' => 'abc']);
    $r = http('GET', 'painel.php?p=ofertas');
    ok(str_contains($r['body'], 'Senha de administrador') && str_contains($r['body'], 'centralizado neste painel'), 'login exigido com aviso');
    $jar = forge_session(['auth' => true, 'csrf' => 'abc']);
    $n = (int)Db::value(Db::get(), "SELECT COUNT(*) FROM gmax3_audit WHERE action='configuracao_alterada' AND entity_id='motivo_pausa'");
    $st = http('POST', 'painel.php', ['action' => 'pause', 'csrf' => 'abc'])['status'];
    ok(in_array($st, [303, 403], true), "ação recusada sem o marcador (HTTP $st)");
    eq($n, (int)Db::value(Db::get(), "SELECT COUNT(*) FROM gmax3_audit WHERE action='configuracao_alterada' AND entity_id='motivo_pausa'"), 'nada executado');
    $jar = $saved;
});
test('3.2.0: inatividade de 2 h e sessão com mais de 12 h expiram', function () {
    global $jar;
    $saved = $jar;
    $jar = forge_session(['auth' => true, 'gmax3_auth' => 1, 'gmax3_last' => time() - 7300, 'gmax3_since' => time() - 8000, 'csrf' => 'x']);
    ok(str_contains(http('GET', 'painel.php')['body'], 'inatividade'), 'inatividade');
    $jar = forge_session(['auth' => true, 'gmax3_auth' => 1, 'gmax3_last' => time() - 60, 'gmax3_since' => time() - 43300, 'csrf' => 'x']);
    ok(str_contains(http('GET', 'painel.php')['body'], '12 horas'), 'tempo máximo');
    $jar = forge_session(['auth' => true, 'gmax3_auth' => 1, 'gmax3_last' => time() - 60, 'gmax3_since' => time() - 600, 'csrf' => 'x']);
    ok(!str_contains(http('GET', 'painel.php')['body'], 'Senha de administrador'), 'sessão válida continua');
    $jar = $saved;
});
test('3.2.0: login gera nova sessão (id regenerado) e o mercadolivre.php antigo usa a sessão do painel', function () {
    global $jar;
    $saved = $jar;
    $jar = [];
    $t = csrf(http('GET', 'painel.php')['body']);
    $before = $jar['PHPSESSID'] ?? '';
    follow(http('POST', 'painel.php', ['action' => 'login', 'password' => T_PASSWORD, 'csrf' => $t]));
    ok(($jar['PHPSESSID'] ?? '') !== '' && $jar['PHPSESSID'] !== $before, 'id da sessão regenerado');
    eq(200, http('GET', 'mercadolivre.php')['status']);
    $jar = [];
    eq('painel.php', header_value(http('GET', 'mercadolivre.php'), 'Location'), 'sem sessão: mercadolivre.php manda ao painel');
    $jar = $saved;
});
test('3.2.0: mercadolivre.php recusa sessão do login antigo, inativa ou com mais de 12 h', function () {
    global $jar;
    $saved = $jar;
    foreach ([
        'login antigo (sem marcador)' => ['auth' => true],
        'inativa há mais de 2 h' => ['auth' => true, 'gmax3_auth' => 1, 'gmax3_last' => time() - 7300, 'gmax3_since' => time() - 8000],
        'com mais de 12 h' => ['auth' => true, 'gmax3_auth' => 1, 'gmax3_last' => time() - 60, 'gmax3_since' => time() - 43300],
    ] as $why => $data) {
        $jar = forge_session($data);
        $r = http('GET', 'mercadolivre.php');
        eq('painel.php', header_value($r, 'Location'), $why);
        ok(!str_contains($r['body'], 'Parafusadeira'), "$why: nada listado");
    }
    $jar = forge_session(['auth' => true, 'gmax3_auth' => 1, 'gmax3_last' => time() - 60, 'gmax3_since' => time() - 600]);
    eq(200, http('GET', 'mercadolivre.php')['status'], 'sessão válida do painel continua abrindo');
    $jar = $saved;
});

echo "\n== Links afiliados em lote pelo navegador\n";
test('Importar links em lote: botão na aba, prévia, CSRF, aplicar e desfazer', function () {
    $pdo = Db::get();
    // O teste de logout encerrou a sessão anterior: entra de novo.
    $t = csrf(http('GET', 'painel.php')['body']);
    follow(http('POST', 'painel.php', ['action' => 'login', 'password' => T_PASSWORD, 'csrf' => $t]));
    $r = http('GET', 'painel.php?p=links');
    ok(str_contains($r['body'], 'Importar links em lote'), 'botão presente');
    no_php_errors($r, 'links');
    $file = file_get_contents(__DIR__ . '/fixtures/GMAX_18_Links_Recebidos_Para_Associacao.csv');
    $r = follow(http('POST', 'painel.php', ['action' => 'link_upload', 'csrf' => 'invalido', 'back' => 'links'], ['arquivo' => ['GMAX_18.csv', $file]]));
    ok(str_contains($r['body'], 'Sessão expirada ou formulário inválido'), 'CSRF exigido no upload');
    $t = csrf(http('GET', 'painel.php?p=links')['body']);
    $before = hash('sha256', json_encode($pdo->query('SELECT id, price_original_cents, price_promo_cents, payment_condition, availability, validation_status FROM gmax3_catalog ORDER BY id')->fetchAll()));
    $r = follow(http('POST', 'painel.php', ['action' => 'link_upload', 'csrf' => $t, 'back' => 'links'], ['arquivo' => ['GMAX_18_Links_Recebidos_Para_Associacao.csv', $file]]));
    no_php_errors($r, 'prévia de links');
    ok(str_contains($r['body'], 'Verificar destino dos links') && str_contains($r['body'], 'Associar links'), 'prévia com ações');
    preg_match('/name="batch" value="(\d+)"/', $r['body'], $m);
    $batch = (int)$m[1];
    // MLB4047815501 já recebeu https://meli.la/Mouse01 no teste anterior: aparece como alteração.
    $b = Db::one($pdo, 'SELECT * FROM gmax3_import_batches WHERE id=?', [$batch]);
    eq(17, (int)$b['inserted']);
    eq(1, (int)$b['updated']);
    $r = follow(http('POST', 'painel.php', ['action' => 'link_batch_apply', 'batch' => $batch, 'accept_unverified' => '1', 'csrf' => $t, 'back' => 'lote_links']));
    ok(str_contains($r['body'], 'Links associados: 17 novo(s), 0 substituído(s)'), substr(strip_tags($r['body']), 0, 300));
    eq('https://meli.la/Mouse01', Db::value($pdo, "SELECT affiliate_url FROM gmax3_catalog WHERE external_id='item:MLB4047815501'"), 'sem autorização não substitui');
    eq($before, hash('sha256', json_encode($pdo->query('SELECT id, price_original_cents, price_promo_cents, payment_condition, availability, validation_status FROM gmax3_catalog ORDER BY id')->fetchAll())), 'preço, condição, disponibilidade e estado intactos');
    $r = follow(http('POST', 'painel.php', ['action' => 'link_batch_revert', 'batch' => $batch, 'csrf' => $t, 'back' => 'lote_links']));
    ok(str_contains($r['body'], '17 link(s) restaurado(s)'), 'desfeito');
    no_php_errors(http('GET', "painel.php?p=lote_links&id=$batch"), 'lote desfeito');
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE affiliate_url LIKE 'https://meli.la/Gx%'"));
});

echo "\n== Central de Automações pelo navegador\n";
test('Central: execução manual, agendamento validado, pausa/retomada, modelo escapado, nada enviado', function () {
    $pdo = Db::get();
    $r = http('GET', 'painel.php?p=automacoes');
    no_php_errors($r, 'central');
    ok(str_contains($r['body'], 'Automações pausadas') && str_contains($r['body'], 'Executar agora'), 'tela');
    $t = csrf($r['body']);
    $r = follow(http('POST', 'painel.php', ['action' => 'auto_run', 'job' => 'saude_catalogo', 'csrf' => 'x', 'back' => 'automacoes']));
    ok(str_contains($r['body'], 'Sessão expirada ou formulário inválido'), 'CSRF');
    $r = follow(http('POST', 'painel.php', ['action' => 'auto_run', 'job' => 'saude_catalogo', 'csrf' => $t, 'back' => 'automacoes']));
    ok(str_contains($r['body'], 'Nenhuma mensagem foi enviada'), substr(strip_tags($r['body']), 0, 300));
    $r = follow(http('POST', 'painel.php', ['action' => 'auto_run', 'job' => 'descoberta', 'csrf' => $t, 'back' => 'automacoes']));
    ok(str_contains($r['body'], 'Execução'), 'descoberta sem integração não quebra');
    $r = follow(http('POST', 'painel.php', ['action' => 'auto_job_save', 'job' => 'descoberta', 'enabled' => '1', 'times' => '07:00,25:99', 'weekdays' => ['1', '2'], 'csrf' => $t, 'back' => 'automacoes']));
    ok(str_contains($r['body'], 'Horário inválido'), 'horário validado');
    follow(http('POST', 'painel.php', ['action' => 'auto_job_save', 'job' => 'descoberta', 'enabled' => '1', 'times' => '07:15', 'weekdays' => ['1', '3', '5'], 'csrf' => $t, 'back' => 'automacoes']));
    eq([1, '07:15', '1,3,5'], array_values(Db::one($pdo, "SELECT enabled, times, weekdays FROM gmax3_jobs WHERE job='descoberta'")));
    $r = follow(http('POST', 'painel.php', ['action' => 'auto_resume', 'confirm' => 'nao', 'csrf' => $t, 'back' => 'automacoes']));
    ok(str_contains($r['body'], 'Digite RETOMAR'), 'confirmação');
    follow(http('POST', 'painel.php', ['action' => 'auto_resume', 'confirm' => 'RETOMAR', 'csrf' => $t, 'back' => 'automacoes']));
    eq('0', Db::value($pdo, "SELECT value FROM gmax3_settings WHERE key='automacoes_pausadas'"));
    follow(http('POST', 'painel.php', ['action' => 'auto_pause', 'csrf' => $t, 'back' => 'automacoes']));
    eq('1', Db::value($pdo, "SELECT value FROM gmax3_settings WHERE key='automacoes_pausadas'"));
    $r = follow(http('POST', 'painel.php', ['action' => 'template_save', 'marketplace' => 'shopee', 'category' => '<b>Casa</b>', 'body' => "<script>x</script> {titulo}\nPor: {por}\n{link}", 'csrf' => $t, 'back' => 'automacoes']));
    ok(str_contains($r['body'], 'Modelo de mensagem salvo'), 'modelo salvo');
    ok(!str_contains($r['body'], '<script>x</script>') && !str_contains($r['body'], '<b>Casa</b>'), 'modelo escapado na tela');
    $r = follow(http('POST', 'painel.php', ['action' => 'settings_save', 'automacao_comercial' => '1', 'aprovacao_automatica' => '1', 'termos_descoberta_shopee' => "garrafa\nfone", 'csrf' => $t, 'back' => 'automacoes']));
    eq('0', Db::value($pdo, "SELECT value FROM gmax3_settings WHERE key='aprovacao_automatica'"), 'aprovação automática não liga pela web');
    eq("garrafa\nfone", Db::value($pdo, "SELECT value FROM gmax3_settings WHERE key='termos_descoberta_shopee'"));
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_pub_queue WHERE state IN ('reservado','confirmado','incerto')"), 'nada enviado');
});

echo "\n== Detalhes das execuções pelo navegador\n";
test('Histórico: "Ver detalhes" abre produtos, motivos e filtros; nada é enviado', function () {
    $pdo = Db::get();
    $r = http('GET', 'painel.php?p=automacoes');
    $t = csrf($r['body']);
    follow(http('POST', 'painel.php', ['action' => 'auto_run', 'job' => 'revisao_precos', 'csrf' => $t, 'back' => 'automacoes']));
    $run = (int)Db::value($pdo, "SELECT MAX(id) FROM gmax3_job_runs WHERE job='revisao_precos'");
    $r = http('GET', 'painel.php?p=automacoes');
    ok(str_contains($r['body'], 'p=execucoes&amp;run=' . $run) && str_contains($r['body'], 'Ver detalhes'), 'botão Ver detalhes');
    $r = http('GET', 'painel.php?p=execucoes&run=' . $run);
    no_php_errors($r, 'execucoes');
    ok(str_contains($r['body'], 'Execução #' . $run) && str_contains($r['body'], 'Bloqueado (não foi possível consultar)'), 'resumo e resultados');
    ok(str_contains($r['body'], 'shopee-api.php ausente'), 'motivo individual do bloqueio');
    ok(str_contains($r['body'], 'Prévia da próxima revisão'), 'prévia de elegibilidade');
    $r = http('GET', 'painel.php?p=execucoes&run=' . $run . '&mp=mercadolivre&res=fora_da_revisao');
    ok(str_contains($r['body'], 'meli.la') && !str_contains($r['body'], 'shopee-api.php ausente</td>'), 'filtro por marketplace e resultado');
    $r = http('GET', 'painel.php?p=execucoes&q=Parafusadeira');
    ok(str_contains($r['body'], 'Parafusadeira') && !str_contains($r['body'], 'shopee-api.php ausente'), 'filtro por produto');
    $r = http('GET', 'painel.php?p=execucoes&run=abc&res=%3Cscript%3E&q=%25%27%22%3Cb%3E');
    no_php_errors($r, 'filtros inválidos');
    ok(!str_contains($r['body'], '<b>') && !str_contains($r['body'], '<script>'), 'entrada escapada');
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_pub_queue WHERE state IN ('reservado','confirmado','incerto')"), 'nada enviado');
});

echo "\n== Conferência rápida (3.1.5)\n";
test('Conferência: exige CSRF, login e confirmação explícita; salva como manual; páginas novas sem erros', function () {
    global $jar;
    $pdo = Db::get();
    $id = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE external_id='22812345603'");
    $r = http('GET', 'painel.php?p=conferir&id=' . $id);
    no_php_errors($r, 'conferir');
    ok(str_contains($r['body'], 'Salvar conferência') && str_contains($r['body'], 'Conferir na Shopee') && str_contains($r['body'], 'rel="noopener noreferrer"'), 'tela de conferência');
    $t = csrf($r['body']);
    $snap = json_encode(Gmax\Catalog::get($pdo, $id));
    $post = ['action' => 'offer_check', 'id' => $id, 'back' => 'conferir', 'price_promo' => '28,90', 'availability' => 'disponivel', 'payment_condition' => 'normal'];
    follow(http('POST', 'painel.php', $post + ['csrf' => 'x', 'confirmo' => '1']));
    $r = follow(http('POST', 'painel.php', $post + ['csrf' => $t]));
    ok(str_contains($r['body'], 'Nada foi gravado'), 'sem confirmação: recusado');
    ok(str_contains($r['body'], 'value="28,90"'), 'valores digitados preservados no formulário');
    eq($snap, json_encode(Gmax\Catalog::get($pdo, $id)), 'nada alterado sem CSRF ou sem confirmação');
    $saved = $jar;
    $jar = [];
    $x = http('POST', 'painel.php', $post + ['csrf' => $t, 'confirmo' => '1']);
    $jar = $saved;
    eq($snap, json_encode(Gmax\Catalog::get($pdo, $id)), 'sem login: nada alterado');
    $r = follow(http('POST', 'painel.php', $post + ['csrf' => $t, 'confirmo' => '1']));
    ok(str_contains($r['body'], 'Conferência manual salva') && str_contains($r['body'], 'não como validação pela API'), substr(strip_tags($r['body']), 0, 300));
    $p = Gmax\Catalog::get($pdo, $id);
    eq([2890, 'admin', 'conferencia_manual'], [(int)$p['price_promo_cents'], $p['price_verified_by'], $p['price_source']]);
    foreach (['painel', 'ofertas&avail=indisponivel', 'ofertas&variation=1&marketplace=shopee', 'conferir&id=999999', 'conferir'] as $pg) {
        no_php_errors(http('GET', 'painel.php?p=' . $pg), $pg);
    }
    $r = http('GET', 'painel.php?p=painel');
    ok(str_contains($r['body'], 'rel="manifest"') && str_contains($r['body'], 'apple-touch-icon') && !str_contains($r['body'], 'serviceWorker'), 'PWA sem service worker');
    ok(str_contains(header_value($r, 'Content-Security-Policy'), "form-action 'self';"), 'CSP preservada');
});

echo "\n== Calculadora de preços (3.1.6)\n";
test('Edição de produto no modo desconto: Por salvo, "De" estimado nunca gravado; modo original mantém o cálculo existente', function () {
    $pdo = Db::get();
    $id = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE external_id='22812345604'");
    $p = Gmax\Catalog::get($pdo, $id);
    $r = http('GET', 'painel.php?p=oferta&id=' . $id);
    no_php_errors($r, 'oferta com calculadora');
    ok(str_contains($r['body'], 'name="price_mode" value="desconto"') && str_contains($r['body'], 'name="desconto_pct"'), 'calculadora na edição');
    $t = csrf($r['body']);
    $form = ['action' => 'offer_save', 'id' => $id, 'back' => 'oferta', 'csrf' => $t, 'marketplace' => 'shopee', 'title' => $p['title'], 'original_url' => $p['original_url'],
        'affiliate_url' => $p['affiliate_url'], 'availability' => 'disponivel', 'price_mode' => 'desconto', 'price_promo' => '59,90', 'price_original' => '99,90', 'desconto_pct' => '40'];
    $r = follow(http('POST', 'painel.php', $form));
    ok(str_contains($r['body'], 'ESTIMADO') && str_contains($r['body'], 'R$ 99,83'), substr(strip_tags($r['body']), 0, 400));
    $p = Gmax\Catalog::get($pdo, $id);
    eq([5990, null], [(int)$p['price_promo_cents'], $p['price_original_cents']], 'estimativa (99,83) não virou "De"; o campo De digitado é ignorado no modo desconto');
    $r = follow(http('POST', 'painel.php', ['desconto_pct' => '0'] + $form));
    ok(str_contains($r['body'], 'Percentual de desconto inválido'), '% inválido recusado');
    $r = follow(http('POST', 'painel.php', ['price_mode' => 'original', 'price_original' => '89,90'] + $form));
    eq(8990, (int)Gmax\Catalog::get($pdo, $id)['price_original_cents'], 'modo original grava o De digitado');
});

echo "\n== Shopee pelo navegador\n";
test('Integrações: Testar conexão e Consultar produto da Shopee sem alterar o catálogo nem mostrar segredos', function () {
    $pdo = Db::get();
    $secrets = t_sandbox() . '/gmax-secrets';
    $r = http('GET', 'painel.php?p=integracoes');
    ok(str_contains($r['body'], 'ausentes ou incompletas') && !str_contains($r['body'], 'Testar conexão Shopee'), 'sem credenciais: sem botões');
    file_put_contents("$secrets/shopee-api.php", "<?php return ['app_id' => '18812345678', 'secret' => 'SEGREDO-SHOPEE-DE-TESTE-0123456789'];");
    $snap = json_encode(Db::all($pdo, 'SELECT * FROM gmax3_catalog'));
    $r = http('GET', 'painel.php?p=integracoes');
    no_php_errors($r, 'integracoes shopee');
    ok(str_contains($r['body'], 'Testar conexão Shopee') && str_contains($r['body'], 'terminado em 5678'), 'botões e final do AppId');
    $t = csrf($r['body']);
    $r = follow(http('POST', 'painel.php', ['action' => 'shopee_test', 'csrf' => $t, 'back' => 'integracoes']));
    ok(str_contains($r['body'], 'Teste de conexão') && str_contains($r['body'], 'Requisição não realizada'), 'resultado exibido (rede proibida no teste)');
    $r = follow(http('POST', 'painel.php', ['action' => 'shopee_item_test', 'item' => '22812345602', 'shop' => '', 'csrf' => $t, 'back' => 'integracoes']));
    ok(str_contains($r['body'], 'Consulta de produto'), 'consulta individual exibida');
    $r = follow(http('POST', 'painel.php', ['action' => 'shopee_item_test', 'item' => '<b>1', 'csrf' => $t, 'back' => 'integracoes']));
    ok(str_contains($r['body'], 'só com números') && !str_contains($r['body'], '<b>1'), 'entrada inválida recusada e escapada');
    ok(!str_contains($r['body'], 'SEGREDO-SHOPEE') && !str_contains($r['body'], '18812345678'), 'segredos não aparecem');
    eq($snap, json_encode(Db::all($pdo, 'SELECT * FROM gmax3_catalog')), 'catálogo intacto');
    unlink("$secrets/shopee-api.php");
});

echo "\n== OAuth Mercado Livre pelo navegador\n";
test('Integrações: conectar gera redirecionamento S256 com state; callback exige login e state válido', function () {
    $secrets = t_sandbox() . '/gmax-secrets';
    $r = http('GET', 'painel.php?p=integracoes');
    no_php_errors($r, 'integracoes sem app');
    ok(str_contains($r['body'], 'mercadolivre-app.php ausente'), 'aponta arquivo ausente');
    file_put_contents("$secrets/mercadolivre-app.php", "<?php return ['client_id' => '1234567890123456', 'client_secret' => 'segredo-de-teste-muito-longo-123', 'redirect_uri' => 'https://gmaxcanais.com.br/gmax-auto/ml-oauth-callback.php'];");
    $r = http('GET', 'painel.php?p=integracoes');
    no_php_errors($r, 'integracoes com app');
    ok(!str_contains($r['body'], 'segredo-de-teste-muito-longo-123'), 'segredo nunca aparece na tela');
    ok(str_contains(header_value($r, 'Content-Security-Policy'), "form-action 'self';"), 'CSP: formulários só para o próprio painel');
    $t = csrf($r['body']);
    eq(303, http('POST', 'painel.php', ['action' => 'ml_oauth_start', 'csrf' => 'x', 'back' => 'integracoes'])['status']);
    $r = http('POST', 'painel.php', ['action' => 'ml_oauth_start', 'csrf' => $t, 'back' => 'integracoes']);
    eq(200, $r['status'], 'POST não redireciona (form-action não segue para o login do ML)');
    eq('', header_value($r, 'Location'));
    ok(preg_match('/<a [^>]*href="([^"]+)" data-autonav/', $r['body'], $m) === 1, 'página intermediária com link');
    $loc = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    ok(str_starts_with($loc, 'https://auth.mercadolivre.com.br/authorization?'), $loc);
    ok(str_contains($r['body'], 'http-equiv="refresh" content="0;url=' . $m[1] . '"'), 'meta refresh para o mesmo endereço');
    parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
    eq('S256', $q['code_challenge_method']);
    ok(strlen($q['state']) >= 40, 'state forte');
    $r = follow(http('GET', 'ml-oauth-callback.php?code=TG-abcdefgh&state=forjado'));
    ok(str_contains($r['body'], 'State inválido'), 'state forjado recusado: ' . substr(strip_tags($r['body']), 0, 200));
    global $jar;
    $saved = $jar;
    $jar = [];
    $r = http('GET', 'ml-oauth-callback.php?code=TG-abcdefgh&state=' . urlencode($q['state']));
    eq(403, $r['status'], 'callback sem login recusado');
    $jar = $saved;
    ok(!is_file("$secrets/mercadolivre-token.enc"), 'nenhum token gravado');
    unlink("$secrets/mercadolivre-app.php");
});

// Navegador real (Chromium): o botão Conectar não pode esbarrar em form-action quando o
// autorizador do ML redireciona para o login em outro host (falha relatada no Edge).
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
$hasPw = $node !== '' && trim((string)shell_exec('command -v openssl 2>/dev/null')) !== ''
    && str_contains((string)shell_exec('NODE_PATH=$(npm root -g 2>/dev/null) node -e "require(\'playwright\');console.log(\'pw-ok\')" 2>/dev/null'), 'pw-ok');
if (!$hasPw) {
    echo "  (teste de navegador PULADO: node/playwright/openssl indisponível)\n";
} else {
    $secrets = t_sandbox() . '/gmax-secrets';
    file_put_contents("$secrets/mercadolivre-app.php", "<?php return ['client_id' => '1234567890123456', 'client_secret' => 'segredo-de-teste-muito-longo-123', 'redirect_uri' => 'https://gmaxcanais.com.br/gmax-auto/ml-oauth-callback.php'];");
    $cmd = 'env -u HTTPS_PROXY -u HTTP_PROXY -u https_proxy -u http_proxy NODE_PATH=$(npm root -g) node '
        . escapeshellarg(__DIR__ . '/browser_oauth.js') . ' ' . escapeshellarg($base) . ' ' . escapeshellarg(T_PASSWORD) . ' 2>&1';
    $out = (string)shell_exec($cmd);
    $lines = array_filter(array_map(static fn($l) => json_decode($l, true), explode("\n", $out)));
    if (!$lines) {
        test('Navegador: teste executado', fn() => ok(false, mb_substr($out, 0, 500)));
    }
    foreach ($lines as $l) {
        test('Navegador (Chromium) — ' . $l['nome'], fn() => ok($l['ok'], $l['erro']));
    }
    @unlink("$secrets/mercadolivre-app.php");
    @unlink("$secrets/mercadolivre-token.enc");
}

echo "\n== GMAX AutoLab pelo navegador\n";
test('AutoLab: página abre; pausa de emergência imediata com CSRF; retomar só de "pausado"; destino e duração não editáveis', function () {
    $pdo = Db::get();
    Settings::set($pdo, 'autolab_estado', 'ativo', 'teste');
    Settings::set($pdo, 'autolab_ate', gmdate('Y-m-d H:i:s', time() + 86400), 'teste');
    $r = http('GET', 'painel.php?p=autolab');
    eq(200, $r['status']);
    ok(str_contains($r['body'], 'PAUSAR AUTOLAB AGORA') && str_contains($r['body'], '-1004443626419'), 'botão de pausa e destino fixo');
    http('POST', 'painel.php', ['action' => 'autolab_pause', 'csrf' => 'errado']);
    eq('ativo', Settings::get($pdo, 'autolab_estado'), 'sem CSRF válido nada acontece');
    follow(http('POST', 'painel.php', ['action' => 'autolab_pause', 'csrf' => csrf($r['body'])]));
    eq('pausado', Settings::get($pdo, 'autolab_estado'));
    $r = http('GET', 'painel.php?p=autolab');
    ok(str_contains($r['body'], 'Retomar AutoLab'), 'retomar disponível');
    follow(http('POST', 'painel.php', ['action' => 'autolab_resume', 'csrf' => csrf($r['body'])]));
    eq('ativo', Settings::get($pdo, 'autolab_estado'));
    Settings::set($pdo, 'autolab_estado', 'falha', 'teste');
    $r = follow(http('POST', 'painel.php', ['action' => 'autolab_resume', 'csrf' => csrf(http('GET', 'painel.php?p=autolab')['body'])]));
    eq('falha', Settings::get($pdo, 'autolab_estado'), 'falha não se retoma pelo painel');
    ok(str_contains($r['body'], 'retome pela CLI'), 'explica o caminho');
    follow(http('POST', 'painel.php', ['action' => 'settings_save', 'back' => 'sistema', 'csrf' => csrf(http('GET', 'painel.php?p=sistema')['body']), 'autolab_ate' => '2099-01-01 00:00:00', 'autolab_estado' => 'ativo']));
    eq('falha', Settings::get($pdo, 'autolab_estado'), 'estado não muda pelas configurações');
    ok(Settings::get($pdo, 'autolab_ate') < '2099', 'duração não muda pelo painel');
    Settings::set($pdo, 'autolab_estado', 'desativado', 'teste');
});

test('Diagnóstico: saúde e tarefas; registra o usuário do PHP web; simulação de retenção não apaga; execução não existe no navegador', function () {
    $pdo = Db::get();
    $r = http('GET', 'painel.php?p=diagnostico');
    eq(200, $r['status']);
    ok(str_contains($r['body'], 'Saúde do sistema') && str_contains($r['body'], 'Diagnóstico das tarefas') && str_contains($r['body'], 'modo de diário'), 'seções');
    ok(Settings::get($pdo, 'php_web_uid', '') !== '', 'uid do PHP web registrado');
    $items = (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_job_run_items');
    $r = follow(http('POST', 'painel.php', ['action' => 'retention_simulate', 'csrf' => csrf($r['body'])]));
    ok(str_contains($r['body'], 'Nada foi apagado'), 'simulação pelo navegador');
    eq($items, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_job_run_items'), 'nada apagado');
    $r = follow(http('POST', 'painel.php', ['action' => 'retention_execute', 'csrf' => csrf(http('GET', 'painel.php?p=diagnostico')['body'])]));
    ok(str_contains($r['body'], 'Ação desconhecida'), 'sem ação de exclusão no painel');
    $r = follow(http('POST', 'painel.php', ['action' => 'health_check', 'csrf' => csrf(http('GET', 'painel.php?p=diagnostico')['body'])]));
    ok(str_contains($r['body'], 'Integridade do banco: ok'), 'integridade');
});

echo "\n== 3.2.2: fila de trabalho, funil e navegação\n";
$mk = static function (string $title, array $over = [], string $status = 'aguardando_validacao'): int {
    static $n = 0;
    $n++;
    $pdo = Db::get();
    $id = Gmax\Catalog::insert($pdo, ['marketplace' => 'shopee', 'external_id' => (string)(88800000000 + $n + random_int(0, 99999) * 100), 'title' => $title, 'category' => 'Fila HTTP',
        'price_promo_cents' => 3990, 'payment_condition' => 'normal', 'availability' => 'disponivel'], $status, 'teste');
    Db::run($pdo, "UPDATE gmax3_catalog SET affiliate_url='https://s.shopee.com.br/Ht' || id, affiliate_norm='s.shopee.com.br/Ht' || id, price_verified_at=datetime('now'), price_verified_by='api:shopee', price_source='api_shopee' WHERE id=?", [$id]);
    foreach ($over as $k => $v) {
        Db::run($pdo, "UPDATE gmax3_catalog SET $k=? WHERE id=?", [$v, $id]);
    }
    return $id;
};
test('Fila: abre com pendências, títulos maliciosos escapados, barra inferior com 5 atalhos e menu em grupos', function () use ($mk) {
    $id = $mk('<script>alert(1)</script> Fone', ['price_verified_at' => null]);
    $r = http('GET', 'painel.php?p=fila&cat=' . rawurlencode('Fila HTTP'));
    eq(200, $r['status']);
    ok(str_contains($r['body'], 'id="p' . $id . '"') && str_contains($r['body'], '&lt;script&gt;') && !str_contains($r['body'], '<script>alert'), 'item listado e escapado');
    preg_match('~<nav class="bottomnav".*?</nav>~s', $r['body'], $m);
    eq(5, preg_match_all('~<a |<button ~', $m[0] ?? ''), 'Início, Minha fila, Ofertas, Automações e Menu');
    foreach (['Operação', 'Produtos', 'Automação', 'Análise', 'Sistema'] as $g) {
        ok(str_contains($r['body'], 'aria-label="' . $g . '"'), "grupo $g");
    }
});
test('Aprovar pela fila: uma vez só (clique repetido ou outra aba é recusado) e volta à mesma posição', function () use ($mk) {
    $pdo = Db::get();
    $id = $mk('Aprovar pela fila 1');
    $mk('Aprovar pela fila 2');
    $page = http('GET', 'painel.php?p=fila&cat=' . rawurlencode('Fila HTTP') . '&sit=aguardando_aprovacao')['body'];
    ok(preg_match('~name="action" value="fila_approve"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="v" value="([0-9a-f]+)"><input type="hidden" name="pos" value="(\d+)"~', $page, $m) === 1, 'botão Aprovar com versão');
    $post = ['action' => 'fila_approve', 'id' => (string)$id, 'v' => $m[1], 'pos' => $m[2], 'back' => 'fila', 'csrf' => csrf($page)];
    $r1 = http('POST', 'painel.php', $post);
    ok(str_contains((string)header_value($r1, 'Location'), 'p=fila') && str_contains((string)header_value($r1, 'Location'), '#p'), 'volta à fila na posição do próximo: ' . header_value($r1, 'Location'));
    eq('aprovado', Gmax\Catalog::get($pdo, $id)['validation_status']);
    $r2 = follow(http('POST', 'painel.php', $post)); // mesmo formulário de novo
    ok(str_contains($r2['body'], 'mudou depois que a tela foi aberta') || str_contains($r2['body'], 'já está aprovado'), 'segunda vez recusada');
    eq(1, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_audit WHERE action='status_alterado' AND entity_id=?", [(string)$id]), 'uma única aprovação registrada');
});
test('Link pela fila: inválido não grava e volta ao item; válido grava e segue', function () use ($mk) {
    $pdo = Db::get();
    $id = $mk('Sem link pela fila', ['affiliate_url' => null, 'affiliate_norm' => null]);
    $page = http('GET', 'painel.php?p=fila&cat=' . rawurlencode('Fila HTTP') . '&sit=sem_link')['body'];
    preg_match('~name="id" value="' . $id . '"><input type="hidden" name="v" value="([0-9a-f]+)"><input type="hidden" name="pos" value="(\d+)"~', $page, $m);
    $base = ['action' => 'link_set', 'back' => 'fila', 'id' => (string)$id, 'v' => $m[1] ?? '', 'pos' => $m[2] ?? '0', 'csrf' => csrf($page)];
    $r = http('POST', 'painel.php', $base + ['affiliate_url' => 'https://example.com/x']);
    ok(str_ends_with((string)header_value($r, 'Location'), '#p' . $id), 'erro volta ao mesmo item: ' . header_value($r, 'Location'));
    eq(null, Gmax\Catalog::get($pdo, $id)['affiliate_url']);
    $r = http('POST', 'painel.php', $base + ['affiliate_url' => 'https://s.shopee.com.br/FilaOk' . $id, 'csrf' => csrf(http('GET', 'painel.php?p=fila')['body'])]);
    ok(str_contains((string)header_value($r, 'Location'), 'p=fila'), 'segue na fila');
    eq('https://s.shopee.com.br/FilaOk' . $id, Gmax\Catalog::get($pdo, $id)['affiliate_url']);
});
/** POST de conferência a partir da página de conferência já carregada. */
$checkPost = static function (string $page, array $over = []): array {
    preg_match('~name="id" value="(\d+)"~', $page, $mi);
    preg_match('~name="v" value="([0-9a-f]+)"~', $page, $mv);
    $f = ['action' => 'offer_check', 'back' => 'conferir', 'id' => $mi[1] ?? '', 'v' => $mv[1] ?? '', 'csrf' => csrf($page),
        'confirmo' => '1', 'price_promo' => '39,90', 'price_mode' => 'original', 'payment_condition' => 'normal', 'availability' => 'disponivel'];
    if (preg_match('~name="seq" value="([0-9a-f]+)"~', $page, $ms)) {
        $f['seq'] = $ms[1];
    }
    if (str_contains($page, 'name="rev" value="1"')) {
        $f['rev'] = '1';
    }
    return array_merge($f, $over);
};
$loc = static fn(array $r) => (string)header_value($r, 'Location');
$idOf = static fn(string $url) => preg_match('~id=(\d+)~', $url, $m) ? (int)$m[1] : 0;
$startSeq = static function (string $cat, ?int $from = null) use ($loc): string {
    $page = http('GET', 'painel.php?p=fila&cat=' . rawurlencode($cat))['body'];
    return $loc(http('POST', 'painel.php', ['action' => 'seq_start', 'back' => 'fila', 'csrf' => csrf($page)] + ($from ? ['from' => (string)$from] : [])));
};
test('3.2.2.1 — conferência sequencial: 10 produtos sem repetição mesmo continuando pendentes, mudando de prioridade e de ordem; termina com "concluída"', function () use ($mk, $checkPost, $loc, $idOf, $startSeq) {
    $pdo = Db::get();
    $ids = [];
    for ($i = 1; $i <= 10; $i++) {
        // variação pendente (continua na conferência depois de salvo); o 3º é APROVADO (prioridade alta → muda ao alterar o preço)
        $ids[] = $mk("Sequencia $i", ['price_verified_at' => null, 'variation_pending' => 1, 'category' => 'Seq HTTP'], $i === 3 ? 'aprovado' : 'aguardando_validacao');
    }
    $outside = $mk('Fora do filtro', ['price_verified_at' => null, 'category' => 'Outra Cat']);
    $url = $startSeq('Seq HTTP');
    $seen = [];
    for ($n = 0; $n < 30 && str_contains($url, 'p=conferir'); $n++) {
        $page = http('GET', $url)['body'];
        ok(str_contains($page, 'Conferência ' . ($n + 1) . ' de 10'), "indicador " . ($n + 1) . " de 10");
        $seen[] = $idOf($url);
        if ($n === 4) {
            http('GET', $url); // atualizar a página não muda o progresso
            ok(str_contains(http('GET', $url)['body'], 'Conferência 5 de 10'), 'atualizar mantém a posição');
            $mk('Novo durante a sequência', ['price_verified_at' => null, 'category' => 'Seq HTTP']); // produto novo
        }
        $price = $idOf($url) === $ids[2] ? '35,00' : '39,90'; // preço alterado no APROVADO: perde a aprovação (regra comercial) e muda de prioridade
        $url = $loc(http('POST', 'painel.php', $checkPost($page, ['price_promo' => $price])));
    }
    eq(10, count($seen), 'dez conferências: ' . implode(',', $seen));
    eq(count($seen), count(array_unique($seen)), 'nenhuma repetição');
    eq(sort($ids) ? $ids : [], (function () use ($seen) { $s = $seen; sort($s); return $s; })(), 'exatamente os 10 do filtro (novo e fora do filtro não entram)');
    ok(!in_array($outside, $seen, true), 'filtro respeitado');
    ok(str_contains($url, 'p=fila'), 'último: volta à fila');
    $end = http('GET', $url)['body'];
    ok(str_contains($end, 'Conferência da sequência concluída: 10 conferido(s)') && str_contains($end, 'Sequência concluída'), 'mensagem de conclusão');
    eq('aguardando_validacao', Gmax\Catalog::get($pdo, $ids[2])['validation_status'], 'preço alterado retirou a aprovação (nada aprovado sem as regras)');
    eq(0, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_catalog WHERE id IN (' . implode(',', $ids) . ") AND validation_status='aprovado'"), 'nenhum aprovado pela sequência');
    eq(0, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_pub_queue WHERE catalog_id IN (' . implode(',', $ids) . ')'), 'nada enfileirado');
    eq(10, count(Gmax\Funnel::workQueue($pdo, ['cat' => 'Seq HTTP', 'sit' => 'variacao'])), 'visitado ≠ validado: variações continuam pendentes');
});
test('3.2.2.1 — clique duplo, salvamento recusado, outra aba, sair e retomar, pular, voltar ao anterior e encerrar', function () use ($mk, $checkPost, $loc, $idOf, $startSeq) {
    $pdo = Db::get();
    $ids = [];
    for ($i = 1; $i <= 4; $i++) {
        $ids[] = $mk("Cenario $i", ['price_verified_at' => null, 'category' => 'Seq Casos']);
    }
    $url = $startSeq('Seq Casos');
    eq($ids[0], $idOf($url));
    $page = http('GET', $url)['body'];
    // Salvamento recusado (sem "confirmo"): fica no MESMO produto, com os dados digitados, sem avançar.
    $r = http('POST', 'painel.php', $checkPost($page, ['confirmo' => '', 'price_promo' => '12,34']));
    eq($ids[0], $idOf($loc($r)), 'recusado: mesmo produto');
    ok(str_contains($loc($r), 'seq='), 'continua na sequência');
    $again = http('GET', $loc($r))['body'];
    ok(str_contains($again, '12,34') && str_contains($again, 'Conferência 1 de 4'), 'dados preservados e progresso igual');
    // Clique duplo: o mesmo formulário duas vezes → um salvamento e o mesmo próximo.
    $post = $checkPost($again);
    $r1 = http('POST', 'painel.php', $post);
    $r2 = http('POST', 'painel.php', $post);
    eq($ids[1], $idOf($loc($r1)), 'avançou para o 2º');
    eq($ids[1], $idOf($loc($r2)), 'reenvio não pula o 2º');
    eq(1, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_audit WHERE action='preco_confirmado' AND entity_id=?", [(string)$ids[0]]), 'uma única conferência registrada');
    // Outra aba altera o 2º entre abrir e salvar: recusado, mesmo produto.
    $p2 = http('GET', $loc($r1))['body'];
    Db::run($pdo, "UPDATE gmax3_catalog SET updated_at=datetime('now','+1 minute') WHERE id=?", [$ids[1]]);
    $r = http('POST', 'painel.php', $checkPost($p2));
    eq($ids[1], $idOf($loc($r)), 'alterado em outra aba: fica no mesmo produto');
    ok(str_contains(follow($r)['body'], 'mudou depois que a tela foi aberta'), 'explica a recusa');
    // Outra aba RESOLVE o 3º: a sequência o pula como "resolvido fora".
    Gmax\Catalog::confirmPrice($pdo, $ids[2], 'outra_aba');
    // Pular o 2º.
    $p2 = http('GET', 'painel.php?p=conferir&id=' . $ids[1] . '&seq=' . (preg_match('~seq=([0-9a-f]+)~', $url, $ms) ? $ms[1] : ''))['body'];
    preg_match('~name="action" value="seq_skip">.*?name="seq" value="([0-9a-f]+)"~s', $p2, $mk2);
    $r = http('POST', 'painel.php', ['action' => 'seq_skip', 'id' => (string)$ids[1], 'seq' => $mk2[1] ?? '', 'back' => 'conferir', 'csrf' => csrf($p2)]);
    eq($ids[3], $idOf($loc($r)), 'pulou o 2º e o 3º (resolvido fora) → 4º');
    // Sair e voltar: a fila oferece Retomar; retomar volta ao 4º.
    $fila = http('GET', 'painel.php?p=fila&cat=' . rawurlencode('Seq Casos'))['body'];
    ok(str_contains($fila, 'Sequência em andamento') && str_contains($fila, 'Retomar sequência'), 'banner de retomada');
    eq($ids[3], $idOf($loc(http('POST', 'painel.php', ['action' => 'seq_resume', 'back' => 'fila', 'csrf' => csrf($fila)]))), 'retomou no 4º');
    // Voltar ao anterior só revisita (não reinsere): salvar o revisitado volta ao próximo não visitado.
    $p4 = http('GET', 'painel.php?p=conferir&id=' . $ids[3] . '&seq=' . $mk2[1])['body'];
    ok(preg_match('~href="([^"]+)">← Voltar ao anterior~', $p4, $mp) === 1, 'botão voltar ao anterior');
    $rev = http('GET', html_entity_decode($mp[1]))['body'];
    ok(str_contains($rev, 'Revisitando') && str_contains($rev, 'name="rev" value="1"'), 'revisitar sem mudar o progresso');
    eq($ids[3], $idOf($loc(http('POST', 'painel.php', ['action' => 'seq_resume', 'back' => 'fila', 'csrf' => csrf($rev)]))), 'revisitado sem salvar: "Ir ao próximo não visitado" volta ao 4º');
    // Último: conclui, oferece revisitar os pulados; encerrar limpa a sequência.
    $p4 = http('GET', 'painel.php?p=conferir&id=' . $ids[3] . '&seq=' . $mk2[1])['body'];
    $end = follow(http('POST', 'painel.php', $checkPost($p4)))['body'];
    ok(str_contains($end, 'concluída') && str_contains($end, 'Revisitar pulados (1)'), 'concluída com 1 pulado');
    eq($ids[1], $idOf($loc(http('POST', 'painel.php', ['action' => 'seq_skipped', 'back' => 'fila', 'csrf' => csrf($end)]))), 'revisitar pulados abre o 2º');
    $f2 = http('GET', 'painel.php?p=fila')['body'];
    follow(http('POST', 'painel.php', ['action' => 'seq_end', 'back' => 'fila', 'csrf' => csrf($f2)]));
    ok(!str_contains(http('GET', 'painel.php?p=fila')['body'], 'Sequência em andamento'), 'encerrada');
    // Formulário de uma sequência encerrada: salva o produto, mas não "avança" numa sequência que não existe.
    $r = http('POST', 'painel.php', $checkPost($p4, ['seq' => $mk2[1]]));
    ok(!str_contains($loc($r), 'seq='), 'sem avanço fantasma');
});
test('3.2.2.1 — Ranking e Candidatos: depois de aprovar/descartar, volta ao mesmo modo, filtros e página', function () use ($mk, $loc) {
    $pdo = Db::get();
    $id = $mk('Ranking volta', ['category' => 'Rank Volta']);
    $page = http('GET', 'painel.php?p=ranking&modo=potencial&marketplace=shopee&ordem=preco')['body'];
    $l = $loc(http('POST', 'painel.php', ['action' => 'offer_status', 'id' => (string)$id, 'status' => 'aprovado', 'back' => 'ranking', 'csrf' => csrf($page)]));
    ok(str_contains($l, 'marketplace=shopee') && str_contains($l, 'ordem=preco') && str_ends_with($l, '#r' . $id), 'ranking preservado: ' . $l);
    eq('aprovado', Gmax\Catalog::get($pdo, $id)['validation_status']);
    $now = gmdate('Y-m-d H:i:s');
    for ($i = 0; $i < 25; $i++) {
        Db::run($pdo, "INSERT OR IGNORE INTO gmax3_ml_candidates(product_id,name,origem,first_seen_at,last_seen_at) VALUES(?,?,'termo',?,?)", ['MLB' . (7100000 + $i), "Candidato volta $i", $now, $now]);
    }
    $page = http('GET', 'painel.php?p=candidatos&status=novo&q=volta&pg=2')['body'];
    preg_match('~name="action" value="ml_candidate_discard"><input type="hidden" name="id" value="(\d+)"~', $page, $m);
    $l = $loc(http('POST', 'painel.php', ['action' => 'ml_candidate_discard', 'id' => $m[1] ?? '0', 'back' => 'candidatos', 'csrf' => csrf($page)]));
    ok(str_contains($l, 'q=volta') && str_contains($l, 'pg=2'), 'candidatos: mesma busca e página: ' . $l);
});
test('Abrir Início, Minha fila, Candidatos, AutoLab e Ofertas não grava nada no banco', function () {
    $pdo = Db::get();
    $dump = static function () use ($pdo): string {
        $s = [];
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'gmax3_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $s[$t] = $pdo->query("SELECT * FROM $t ORDER BY rowid")->fetchAll(PDO::FETCH_NUM);
        }
        return hash('sha256', serialize($s));
    };
    http('GET', 'painel.php?p=painel'); // primeira visita pode sincronizar o legado (comportamento anterior)
    $before = $dump();
    foreach (['painel', 'fila', 'fila&sit=sem_link', 'candidatos', 'autolab', 'ofertas'] as $p) {
        eq(200, http('GET', "painel.php?p=$p")['status'], $p);
    }
    eq($before, $dump(), 'nenhuma gravação');
});

echo "\n== Agendadores existentes e CLI (sem rede)\n";
$cli = static function (array $cmd) use ($env, $app): array {
    $p = proc_open(array_merge([PHP_BINARY, '-d', 'allow_url_fopen=0'], $cmd), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $app, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
};
test('cron.php original continua rodando em modo prévia após a migração', function () use ($cli) {
    [$code, $out] = $cli(['cron.php']);
    eq(0, $code, $out);
    ok(str_contains($out, 'Fora da janela') || str_contains($out, 'PRÉVIA') || str_contains($out, 'Janela') || str_contains($out, 'Fila vazia'), $out);
});
test('Publicador antigo continua sem enviar (sem arquivo de ativação)', function () use ($cli) {
    [$code, $out] = $cli(['gmax-publicador-cli.php']);
    eq(0, $code, $out);
    ok(str_contains($out, 'fora_do_horario') || str_contains($out, 'simulacao_sem_envio'), $out);
});
test('CLI 3.0: verificar, status e simulação da fila', function () use ($cli) {
    [$code, $out] = $cli(['gmax-admin-cli.php', 'verificar']);
    ok(!str_contains($out, '[FALHA]'), $out);
    [$code, $out] = $cli(['gmax-admin-cli.php', 'status']);
    eq(0, $code, $out);
    ok(str_contains($out, 'Automação comercial desativada'), 'travas listadas');
    [$code, $out] = $cli(['gmax-admin-cli.php', 'fila:processar']);
    eq(0, $code, $out);
    ok(str_contains($out, 'fila_vazia') || str_contains($out, 'envio_bloqueado'), $out);
    eq(0, (int)Db::value(Db::get(), "SELECT COUNT(*) FROM gmax3_pub_queue WHERE state NOT IN ('agendado')"), 'nada reservado');
    [$code, $out] = $cli(['gmax-admin-cli.php', 'automacao', '--autorizar', 'errado']);
    eq(1, $code, 'frase errada recusada');
});

$pass = count(array_filter($GLOBALS['t_results'], fn($r) => $r[1]));
$total = count($GLOBALS['t_results']);
echo "\nResultado HTTP/CLI: $pass/$total testes aprovados\n";
file_put_contents(__DIR__ . '/../docs/resultado-testes-http.json', json_encode([
    'executado_em' => date('c'), 'php' => PHP_VERSION, 'aprovados' => $pass, 'total' => $total,
    'testes' => array_map(fn($r) => ['nome' => $r[0], 'ok' => $r[1], 'erro' => $r[2], 'segundos' => round($r[3], 3)], $GLOBALS['t_results']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($pass === $total ? 0 : 1);

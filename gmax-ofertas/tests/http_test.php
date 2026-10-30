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

use Gmax\Db;

t_seed();
$app = t_app();
$port = 18000 + random_int(0, 999);
$base = "http://127.0.0.1:$port";
$env = ['GMAX_SECRETS_DIR' => t_sandbox() . '/gmax-secrets', 'GMAX_TESTING' => '1', 'PATH' => getenv('PATH')];
$server = proc_open([PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-S', "127.0.0.1:$port", '-t', $app], [1 => ['file', '/dev/null', 'w'], 2 => ['file', t_sandbox() . '/server.log', 'w']], $pipes, $app, $env);
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
test('Login pelo painel antigo (index.php) vale para o novo painel', function () {
    $t = csrf(http('GET', 'index.php')['body']);
    http('POST', 'index.php', ['csrf' => $t, 'action' => 'login', 'password' => T_PASSWORD]);
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
    ok(preg_match('/Shopee<\/span>\s*<strong>5<\/strong>/', $r['body']) === 1, '5 Shopee');
    ok(preg_match('/Mercado Livre<\/span>\s*<strong>1<\/strong>/', $r['body']) === 1, '1 Mercado Livre');
    $r = http('GET', 'painel.php?p=ofertas&marketplace=mercadolivre');
    ok(str_contains($r['body'], 'Parafusadeira Furadeira 48V'), 'ML-001 listado');
    ok(str_contains($r['body'], '65% OFF'), 'desconto calculado');
});
test('Páginas antigas continuam funcionando após a migração', function () {
    $r = http('GET', 'mercadolivre.php');
    eq(200, $r['status']);
    ok(str_contains($r['body'], 'Parafusadeira Furadeira 48V'), 'mercadolivre.php mostra ML-001');
    $r = http('GET', 'index.php');
    ok(str_contains($r['body'], 'Fone Bluetooth TWS Teste A'), 'index.php lista ofertas Shopee');
});
test('Todas as páginas do painel abrem sem erros', function () {
    foreach (['painel', 'automacoes', 'ofertas', 'oferta', 'importar', 'links', 'ranking', 'publicacoes', 'relatorios', 'categorias', 'sistema'] as $p) {
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

<?php
/**
 * Testes INDEPENDENTES em nível HTTP sobre o PAINEL GMAX REAL: o servidor PHP embutido serve uma cópia
 * temporária da base do painel (index.html, api.php, assets) + os arquivos públicos do módulo
 * (ver tests/web/ambiente.php). Login pelo api.php real (setup/login), sessão gmax_session compartilhada.
 * Cada teste usa um banco grtest_* novo (classe T). Nenhuma chamada de rede externa: provedor simulado.
 * A base do painel vem de GR_PAINEL_BASE (material de desenvolvimento, não versionado aqui).
 */
declare(strict_types=1);

require_once __DIR__ . '/web/ambiente.php';

echo "Web (HTTP real sobre o painel GMAX, servidor embutido)\n";

if (!GrWeb::available()) {
    echo "  (pulado: base do painel não encontrada em " . GrWeb::panelBase() . " — defina GR_PAINEL_BASE)\n";
    return;
}

/** Executa um teste web: servidor compartilhado, banco próprio do teste. */
function webtest(string $name, callable $fn, array $extraCfg = []): void
{
    test($name, function (T $t) use ($fn, $extraCfg) {
        $web = GrWeb::get();
        $web->useTest($t, $extraCfg);
        $fn($t, $web);
    });
}

function hdr(array $r, string $name): string
{
    return implode(', ', $r['headers'][strtolower($name)] ?? []);
}

function settingDb(T $t, string $key)
{
    $v = $t->pdo()->query('SELECT valor FROM gr_configuracoes WHERE chave=' . $t->pdo()->quote($key))->fetchColumn();
    return $v === false ? null : json_decode($v, true);
}

function setSettingDb(T $t, string $key, $value): void
{
    $t->pdo()->prepare('UPDATE gr_configuracoes SET valor=? WHERE chave=?')->execute([json_encode($value), $key]);
}

/** Set-Cookie da sessão do painel numa resposta (ou null). */
function sessionCookie(array $r): ?string
{
    foreach ($r['headers']['set-cookie'] ?? [] as $c) {
        if (stripos($c, 'gmax_session=') === 0) {
            return $c;
        }
    }
    return null;
}

function assertStrongCookie(?string $c, string $ctx): void
{
    check($c !== null, "$ctx: Set-Cookie gmax_session presente");
    $l = strtolower($c);
    check(strpos($l, 'path=/;') !== false || preg_match('~path=/($|;)~', $l), "$ctx: path=/ ($c)");
    check(strpos($l, 'httponly') !== false, "$ctx: HttpOnly ($c)");
    check(strpos($l, 'samesite=strict') !== false, "$ctx: SameSite=Strict ($c)");
    check(!preg_match('~domain=~', $l), "$ctx: sem Domain amplo ($c)");
}

function webhookPost(GrWeb $web, string $body, ?string $secret): array
{
    $h = ['Content-Type' => 'application/json'];
    if ($secret !== null) {
        $h['X-Hub-Signature-256'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
    }
    return $web->client()->request('POST', '/painel/renovacoes-webhook.php', $body, $h);
}

function metaStatus(string $wamid, string $status, int $ts): string
{
    return json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '123'],
        'statuses' => [['id' => $wamid, 'status' => $status, 'timestamp' => (string)$ts, 'recipient_id' => '5531988887777']],
    ]]]]]]);
}

function insertMetaMessage(T $t, string $clientId, string $wamid, string $status = 'aceito'): int
{
    $now = date('Y-m-d H:i:s');
    $t->pdo()->prepare("INSERT INTO gr_mensagens (modo, ref, cliente_id, telefone, etapa, vencimento, data_prevista, corpo, status, provedor, provedor_msg_id, tentativas, criado_em, atualizado_em)
        VALUES ('real', ?, ?, '5531988887777', 'antes', ?, ?, 'Olá', ?, 'meta_cloud', ?, 1, ?, ?)")
        ->execute(['grw' . bin2hex(random_bytes(6)), $clientId, GrWeb::today(3), GrWeb::today(), $status, $wamid, $now, $now]);
    return (int)$t->pdo()->lastInsertId();
}

/** Resposta de api.php?action=data sem o carimbo de exportação (para comparar antes/depois). */
function panelData(GrHttp $c): array
{
    $r = $c->get('/painel/api.php?action=data');
    eq(200, $r['status'], 'api.php data 200: ' . substr($r['body'], 0, 200));
    $d = $r['json'];
    unset($d['exported_at']);
    return $d;
}

function panelTotals(array $d): array
{
    $plans = array_merge(...array_map(fn($c) => $c['plans'], array_merge($d['clients'], $d['deleted_clients'])));
    $kinds = [];
    foreach ($d['entries'] as $e) {
        $kinds[$e['kind']] = ($kinds[$e['kind']] ?? 0) + $e['amount_cents'];
    }
    ksort($kinds);
    return ['clientes' => count($d['clients']), 'excluidos' => count($d['deleted_clients']), 'pacotes' => count($plans),
        'soma_pacotes' => array_sum(array_column($plans, 'price_cents')), 'lancamentos' => count($d['entries']), 'por_tipo' => $kinds];
}

// ================================================================ sessão do painel (login real)
webtest('web: API do módulo sem sessão do painel → 401 JSON; cookie forjado também', function (T $t, GrWeb $web) {
    $c = $web->client();
    foreach (['painel', 'config', 'historico', 'clientes'] as $a) {
        $r = $c->apiGet($a);
        eq(401, $r['status'], "GET $a sem sessão");
        check(is_array($r['json']) && isset($r['json']['erro']), "corpo JSON com erro ($a)");
        check(str_starts_with(hdr($r, 'content-type'), 'application/json'), 'content-type JSON');
    }
    eq(401, $c->api('pausa_global', ['pausado' => true], 'qualquer')['status'], 'POST mutação sem sessão');
    eq(false, settingDb($t, 'pausa_global'), 'pausa_global não mudou');
    $c->cookies = ['gmax_session' => 'inventado' . bin2hex(random_bytes(8))];
    eq(401, $c->apiGet('painel')['status'], 'cookie gmax_session forjado (strict mode)');
    // Sessão aberta no api.php (status), mas SEM login: continua 401.
    $c2 = $web->client();
    $c2->panelStatus();
    check(isset($c2->cookies['gmax_session']), 'api.php criou a sessão');
    eq(401, $c2->apiGet('painel')['status'], 'sessão sem admin');
    eq(401, $c2->api('pausa_global', ['pausado' => true])['status'], 'sessão sem admin, com CSRF válido do painel');
    // Senha errada no painel: continua sem acesso.
    $r = $c2->panel('login', ['username' => GrWeb::ADMIN_USER, 'password' => 'errada']);
    check(in_array($r['status'], [401, 409], true) || $r['status'] === 401, 'login inválido rejeitado');
    eq(401, $c2->apiGet('painel')['status'], 'após login inválido');
});

webtest('web: renovacoes.php sem sessão redireciona para o login do painel (./) sem dados', function (T $t, GrWeb $web) {
    GrWeb::seed($t);
    $r = $web->client()->get('/painel/renovacoes.php');
    check(in_array($r['status'], [302, 303], true), 'redireciona (HTTP ' . $r['status'] . ')');
    eq('./', hdr($r, 'location'), 'Location = ./ (index.html do painel)');
    check(strpos($r['body'], 'csrf-token') === false && strpos($r['body'], 'Ana Souza') === false, 'sem página nem dados');
});

webtest('web: login REAL no api.php dá acesso a renovacoes.php e à API; CSRF do painel vale nas mutações do módulo', function (T $t, GrWeb $web) {
    $c = $web->client();
    $c->panelStatus();
    $preLogin = $c->csrf;
    $c->login();
    check($c->csrf !== $preLogin, 'login regenerou o CSRF (comportamento do painel)');
    eq(true, $c->panelStatus()['json']['authenticated'] ?? null, 'painel: autenticado');
    $p = $c->get('/painel/renovacoes.php');
    eq(200, $p['status'], 'renovacoes.php 200 com a sessão do painel');
    eq($c->csrf, $c->pageCsrf(), 'meta csrf-token da página = $_SESSION[csrf] do painel');
    eq(200, $c->apiGet('painel')['status'], 'API do módulo 200');
    // Token anterior ao login não vale mais.
    eq(403, $c->api('pausa_global', ['pausado' => true], $preLogin)['status'], 'CSRF pré-login rejeitado');
    $r = $c->api('pausa_global', ['pausado' => true]);
    eq(200, $r['status'], 'CSRF emitido pelo api.php aceito: ' . $r['body']);
    eq(true, settingDb($t, 'pausa_global'), 'gravado');
    // E o painel continua aceitando o mesmo token depois de usar o módulo.
    $s = $c->panel('settings', ['target_cents' => 200000, 'default_price_cents' => 3500, 'default_cost_cents' => 1000]);
    eq(200, $s['status'], 'api.php settings com o mesmo token após usar o módulo: ' . $s['body']);
    // Uma única sessão/cookie: o módulo não cria cookie próprio.
    eq(['gmax_session'], array_keys($c->cookies), 'só o cookie gmax_session');
});

webtest('web: logout do painel (api.php action=logout) → API do módulo 401 e página redireciona', function (T $t, GrWeb $web) {
    $c = $web->client()->login();
    eq(200, $c->apiGet('painel')['status'], 'logado');
    $r = $c->panel('logout');
    eq(200, $r['status'], 'logout no painel');
    eq(401, $c->apiGet('painel')['status'], 'API do módulo após logout');
    eq(401, $c->api('pausa_global', ['pausado' => true])['status'], 'mutação após logout');
    check(in_array($c->get('/painel/renovacoes.php')['status'], [302, 303], true), 'página redireciona após logout');
    eq(false, settingDb($t, 'pausa_global'), 'nada mudou');
});

webtest('web: sessão expirada ($_SESSION[last] > 3600 s) → 401 no módulo e no api.php; cookie regenerado continua forte', function (T $t, GrWeb $web) {
    // Ordem 1: módulo primeiro.
    $c = $web->client()->login();
    eq(200, $c->apiGet('painel')['status'], 'logado');
    eq(200, $c->get('/expirar-sessao.php')['status'], 'helper de teste envelheceu a sessão');
    $r = $c->apiGet('painel');
    eq(401, $r['status'], 'API do módulo com sessão expirada');
    assertStrongCookie(sessionCookie($r), 'regeneração pelo módulo');
    eq(401, $c->get('/painel/api.php?action=data')['status'], 'api.php data depois');
    eq(false, $c->panelStatus()['json']['authenticated'] ?? null, 'painel: não autenticado');
    // Ordem 2: painel primeiro.
    $c2 = $web->client()->login();
    $c2->get('/expirar-sessao.php');
    eq(401, $c2->get('/painel/api.php?action=data')['status'], 'api.php com sessão expirada');
    eq(401, $c2->apiGet('painel')['status'], 'módulo depois');
    // Ordem 3: página do módulo.
    $c3 = $web->client()->login();
    $c3->get('/expirar-sessao.php');
    check(in_array($c3->get('/painel/renovacoes.php')['status'], [302, 303], true), 'página redireciona com sessão expirada');
    // Sessão ativa (sem expirar) segue válida: atividade no módulo renova "last".
    $c4 = $web->client()->login();
    eq(200, $c4->apiGet('painel')['status'], 'sessão ativa ok');
});

webtest('web: Set-Cookie do módulo não enfraquece o cookie do painel (path=/, SameSite=Strict, HttpOnly)', function (T $t, GrWeb $web) {
    $r = $web->client()->get('/painel/renovacoes.php');
    assertStrongCookie(sessionCookie($r), 'renovacoes.php (visita nova)');
    $r = $web->client()->get('/painel/renovacoes-api.php?acao=painel');
    assertStrongCookie(sessionCookie($r), 'renovacoes-api.php (visita nova)');
    foreach ([$r] as $x) {
        foreach ($x['headers']['set-cookie'] ?? [] as $ck) {
            check(stripos($ck, 'PHPSESSID') !== 0, 'sem cookie PHPSESSID paralelo');
        }
    }
    // Comparação com o cookie emitido pelo próprio api.php.
    $p = $web->client()->get('/painel/api.php?action=status');
    $norm = fn($ck) => preg_replace('~^gmax_session=[^;]*~i', 'gmax_session=X', strtolower((string)$ck));
    eq($norm(sessionCookie($p)), $norm(sessionCookie($r)), 'mesmos atributos do cookie do api.php');
    // Webhook não abre sessão.
    $w = $web->client()->get('/painel/renovacoes-webhook.php');
    eq(null, sessionCookie($w), 'webhook sem cookie');
});

webtest('web: exigir_https=true (padrão) bloqueia HTTP no módulo, mesmo logado', function (T $t, GrWeb $web) {
    $c = $web->client()->login();
    $web->useTest($t, ['auth' => ['exigir_https' => true]]);
    eq(403, $c->apiGet('painel')['status'], 'API por HTTP → 403');
    eq(403, $c->get('/painel/renovacoes.php')['status'], 'página por HTTP → 403');
    eq(403, $c->api('pausa_global', ['pausado' => true])['status'], 'mutação por HTTP → 403');
    eq(false, settingDb($t, 'pausa_global'), 'nada mudou');
});

// ================================================================ cabeçalhos / CSRF / origem
webtest('web: página do módulo com cabeçalhos de segurança, sem inline, assets 200', function (T $t, GrWeb $web) {
    $c = $web->client()->login();
    $r = $c->get('/painel/renovacoes.php');
    eq(200, $r['status'], 'página 200');
    $csp = hdr($r, 'content-security-policy');
    foreach (["default-src 'self'", "script-src 'self'", "frame-ancestors 'none'", "base-uri 'self'"] as $d) {
        check(strpos($csp, $d) !== false, "CSP contém $d");
    }
    check(strpos($csp, 'unsafe-inline') === false && strpos($csp, 'unsafe-eval') === false, 'CSP sem unsafe-*');
    eq('DENY', hdr($r, 'x-frame-options'), 'X-Frame-Options');
    eq('nosniff', hdr($r, 'x-content-type-options'), 'nosniff');
    check(stripos(hdr($r, 'cache-control'), 'no-store') !== false, 'Cache-Control no-store');
    check(!preg_match('~<script(?![^>]*\bsrc=)[^>]*>~i', $r['body']), 'sem <script> inline');
    check(!preg_match('~<style\b|\sstyle="|\son[a-z]+="~i', $r['body']), 'sem style/handlers inline');
    $a = $c->apiGet('painel');
    check(hdr($a, 'content-security-policy') !== '' && hdr($a, 'x-frame-options') === 'DENY', 'cabeçalhos na API');
    preg_match_all('~(?:src|href)="(renovacoes-assets/[^"]+)"~', $r['body'], $m);
    check(count($m[1]) >= 2, 'css + js referenciados');
    foreach ($m[1] as $asset) {
        eq(200, $c->get('/painel/' . html_entity_decode($asset))['status'], "asset $asset");
    }
});

webtest('web: mutação via GET → 405 e nada muda', function (T $t, GrWeb $web) {
    $c = $web->client()->login();
    foreach (['pausa_global', 'salvar_config', 'executar_agora', 'confirmar_pagamento', 'consentimento'] as $a) {
        eq(405, $c->get('/painel/renovacoes-api.php?acao=' . $a . '&pausado=1&ignorar_horario=1')['status'], "GET $a");
    }
    eq(false, settingDb($t, 'pausa_global'), 'pausa_global intacta');
    eq(false, settingDb($t, 'pagamento_confirmado'), 'pagamento_confirmado intacto');
    eq(0, (int)$t->pdo()->query('SELECT COUNT(*) FROM gr_execucoes')->fetchColumn(), 'nenhuma execução');
});

webtest('web: POST sem CSRF / CSRF errado / de outra sessão → 403; correto → 200; corpo não-JSON → 400', function (T $t, GrWeb $web) {
    $c = $web->client()->login();
    eq(403, $c->api('pausa_global', ['pausado' => true], null)['status'], 'sem token');
    eq(403, $c->api('pausa_global', ['pausado' => true], str_repeat('a', 64))['status'], 'token errado');
    eq(403, $c->api('pausa_global', ['pausado' => true], '')['status'], 'token vazio');
    $outro = $web->client()->login();
    eq(403, $c->api('pausa_global', ['pausado' => true], $outro->csrf)['status'], 'token de outra sessão');
    eq(false, settingDb($t, 'pausa_global'), 'nada mudou após 403');
    $r = $c->api('pausa_global', ['pausado' => true]);
    eq(200, $r['status'], 'token correto');
    eq(true, settingDb($t, 'pausa_global'), 'gravado');
    $r = $c->api('pausa_global', ['pausado' => false], 'auto', ['Origin' => $web->base, 'Referer' => $web->base . '/painel/renovacoes.php']);
    eq(200, $r['status'], 'Origin do próprio site');
    eq(400, $c->request('POST', '/painel/renovacoes-api.php?acao=pausa_global', 'pausado=1', ['Content-Type' => 'application/x-www-form-urlencoded', 'X-CSRF-Token' => $c->csrf])['status'], 'form-urlencoded rejeitado');
});

webtest('web: Origin/Referer de outro site → 403 mesmo com token válido', function (T $t, GrWeb $web) {
    $c = $web->client()->login();
    eq(403, $c->api('pausa_global', ['pausado' => true], 'auto', ['Origin' => 'https://evil.example'])['status'], 'Origin externo');
    eq(403, $c->api('pausa_global', ['pausado' => true], 'auto', ['Origin' => 'http://127.0.0.1:1'])['status'], 'mesmo host, outra porta');
    eq(403, $c->api('pausa_global', ['pausado' => true], 'auto', ['Referer' => 'https://evil.example/x'])['status'], 'Referer externo');
    eq(403, $c->api('pausa_global', ['pausado' => true], 'auto', ['Origin' => 'null'])['status'], 'Origin null');
    eq(false, settingDb($t, 'pausa_global'), 'nada mudou');
});

// ================================================================ segredos
webtest('web: leituras do módulo não expõem segredos (config do módulo, config do painel, códigos/senhas dos pacotes)', function (T $t, GrWeb $web) {
    $ids = GrWeb::seed($t);
    $c = $web->client()->login();
    $c->api('executar_agora', ['ignorar_horario' => true]);
    $meta = $web->config['provedor']['meta'];
    $secrets = [$meta['access_token'], $meta['app_secret'], $meta['verify_token'], $web->setupSecret, $web->panelConfig,
        'SENHA-SECRETA-123', 'ACESSO-SECRETO-123', 'COD-CLIENTE-SECRETO', 'lista.exemplo', 'abc.m3u', 'password_hash'];
    $r = $c->apiGet('config');
    eq(200, $r['status'], 'config 200');
    foreach ($secrets as $s) {
        check(strpos($r['body'], $s) === false, "config não contém '$s'");
    }
    eq(true, $r['json']['provedor']['credenciais']['access_token'] ?? null, 'informa só que existe');
    $msgId = (int)$t->pdo()->query('SELECT MIN(id) FROM gr_mensagens')->fetchColumn();
    check($msgId > 0, 'há mensagem simulada');
    foreach ([['painel', []], ['historico', []], ['clientes', []], ['mensagem', ['id' => $msgId]], ['cliente_historico', ['id' => $ids['ana']]]] as [$a, $q]) {
        $r = $c->apiGet($a, $q);
        eq(200, $r['status'], "$a 200");
        foreach ($secrets as $s) {
            check(strpos($r['body'], $s) === false, "$a não contém '$s'");
        }
        check(strpos($r['body'], 'lease_token') === false, "$a sem lease_token");
    }
    $r = $c->apiGet('clientes');
    check(strpos($r['body'], '31988887777') === false && strpos($r['body'], '5531988887777') === false, 'telefone mascarado em clientes');
    foreach (glob($web->dir . '/logs/*') ?: [] as $f) {
        foreach ([$meta['access_token'], $meta['app_secret'], $web->setupSecret, 'SENHA-SECRETA-123'] as $s) {
            check(strpos((string)file_get_contents($f), $s) === false, 'log sem segredo');
        }
    }
    foreach ($t->messages() as $m) {
        foreach (['SENHA-SECRETA-123', 'ACESSO-SECRETO-123', 'COD-CLIENTE-SECRETO', 'lista.exemplo'] as $s) {
            check(strpos($m['corpo'], $s) === false, "mensagem sem '$s'");
        }
    }
});

// ================================================================ configurações / prévia
webtest('web: salvar_config rejeita URL/senha nos textos e no rodapé e não grava', function (T $t, GrWeb $web) {
    $c = $web->client()->login();
    $before = settingDb($t, 'textos');
    $r = $c->api('salvar_config', ['textos' => ['antes' => ['individual' => 'Olá {nome}, renove em https://pague.exemplo/x {valor}']]]);
    eq(200, $r['status'], 'HTTP 200 com erros de validação');
    eq(false, $r['json']['ok'] ?? null, 'ok=false');
    check((bool)preg_grep('/link|URL/i', $r['json']['erros'] ?? []), 'erro menciona links');
    eq($before, settingDb($t, 'textos'), 'textos não mudaram');
    eq(false, $c->api('salvar_config', ['rodape' => 'Mais em www.exemplo.com'])['json']['ok'] ?? null, 'rodapé com www');
    eq(false, $c->api('salvar_config', ['textos' => ['dia' => ['individual' => 'Olá {nome}! wa.me/5531999999999']]])['json']['ok'] ?? null, 'wa.me');
    eq(false, $c->api('salvar_config', ['textos' => ['dia' => ['individual' => 'Olá {nome}! Sua senha é 123']]])['json']['ok'] ?? null, 'senha');
    eq($before, settingDb($t, 'textos'), 'nada gravado');
    $novo = "Olá, {nome}! Seu pacote {pacote} vence em {data}. Valor: {valor}.\n{instrucoes_pagamento}";
    $r = $c->api('salvar_config', ['textos' => ['antes' => ['individual' => $novo]]]);
    eq(true, $r['json']['ok'] ?? null, 'texto válido aceito: ' . $r['body']);
    eq($novo, settingDb($t, 'textos')['antes']['individual'], 'gravado');
});

webtest('web: previa devolve o texto renderizado (GET e POST com CSRF)', function (T $t, GrWeb $web) {
    $c = $web->client()->login();
    $r = $c->apiGet('previa', ['etapa' => 'antes', 'texto' => 'Oi {nome}, {pacote} custa {valor}. {instrucoes_pagamento}', 'chave_pix' => 'pix-ficticio@exemplo.com', 'instrucoes_pagamento' => 'Pix: {chave_pix}']);
    eq(200, $r['status'], 'GET previa');
    $corpo = (string)($r['json']['corpo'] ?? '');
    check(strpos($corpo, '{nome}') === false && strpos($corpo, '{valor}') === false, 'placeholders substituídos: ' . $corpo);
    check(strpos($corpo, 'R$ 120,00') !== false, 'valor fictício: ' . $corpo);
    check(strpos($corpo, 'Pix: pix-ficticio@exemplo.com') !== false, 'instruções com chave em edição');
    eq([], $r['json']['erros'], 'sem erros');
    $r = $c->api('previa', ['etapa' => 'dia', 'variante' => 'grupo', 'texto' => 'Oi {nome}: {lista_pacotes} Total {total} http://x.y']);
    eq(200, $r['status'], 'POST previa');
    check(strpos((string)$r['json']['corpo'], 'R$ 155,00') !== false, 'total do grupo: ' . $r['json']['corpo']);
    check(count($r['json']['erros']) >= 1, 'erro de link na prévia');
    eq(403, $c->api('previa', ['etapa' => 'dia', 'texto' => 'x'], 'errado')['status'], 'POST previa com CSRF errado');
    eq(422, $c->apiGet('previa', ['etapa' => 'invalida', 'texto' => 'x'])['status'], 'etapa inválida → 422');
    eq(422, $c->apiGet('acao_que_nao_existe')['status'], 'ação desconhecida → 422');
});

// ================================================================ painel / agrupamento / envio
webtest('web: painel do módulo agrupa pacotes do mesmo cliente/data (total) e separa outra data (client_plans.due_date)', function (T $t, GrWeb $web) {
    $ana = $t->client('Ana Grupo', '31988887777');
    $t->consent($ana, '31988887777');
    $t->package($ana, 'Pacote Família', GrWeb::today(3), '120.00', ['pontos' => 4]);
    $t->package($ana, 'Pacote Quarto', GrWeb::today(3), '35.00');
    $t->package($ana, 'Pacote Escritório', GrWeb::today(6), '50.00');
    $outro = $t->client('Outro Cliente', '31977776666');
    $t->package($outro, 'Pacote Sala', GrWeb::today(3), '45.00');
    $r = $web->client()->login()->apiGet('painel');
    eq(200, $r['status'], 'painel 200');
    $d = $r['json'];
    $groups = array_values(array_filter($d['proximos'], fn($g) => $g['cliente_id'] === $ana));
    eq(2, count($groups), 'dois grupos para a Ana');
    usort($groups, fn($a, $b) => strcmp($a['vencimento'], $b['vencimento']));
    eq(GrWeb::today(3), $groups[0]['vencimento'], 'grupo 1 data (client_plans, não clients.due_date)');
    eq(2, count($groups[0]['itens']), 'grupo 1 com dois pacotes');
    eq('R$ 155,00', $groups[0]['total'], 'total = soma dos price_cents (sem multiplicar pontos)');
    eq(3, $groups[0]['dias'], 'dias');
    check(strpos((string)$groups[0]['previa'], 'Pacote Família') !== false && strpos((string)$groups[0]['previa'], 'Pacote Quarto') !== false && strpos((string)$groups[0]['previa'], 'R$ 155,00') !== false, 'prévia agrupada');
    eq(GrWeb::today(6), $groups[1]['vencimento'], 'grupo 2 data');
    eq('R$ 50,00', $groups[1]['total'], 'total grupo 2');
    $og = array_values(array_filter($d['proximos'], fn($g) => $g['cliente_id'] === $outro));
    eq(1, count($og), 'outro cliente em grupo próprio');
    eq('nenhum', $og[0]['consentimento'], 'cadastro existente NÃO é autorizado automaticamente');
    eq(3, $d['resumo']['proximos'], 'resumo conta grupos');
});

webtest('web: executar_agora cria mensagens SIMULADAS no histórico; sem autorização não recebe; repetir não duplica', function (T $t, GrWeb $web) {
    GrWeb::seed($t);
    $c = $web->client()->login();
    $r = $c->api('executar_agora', ['ignorar_horario' => true]);
    eq(200, $r['status'], 'HTTP 200: ' . $r['body']);
    eq('simulacao', $r['json']['modo'] ?? null, 'modo simulação');
    eq(3, $r['json']['mensagens_criadas'] ?? null, 'Ana(grupo) + Carla(depois) + Eduarda(dia): ' . $r['body']);
    $h = $c->apiGet('historico')['json'];
    eq(3, count($h['itens']), 'histórico com 3 mensagens');
    foreach ($h['itens'] as $m) {
        eq('simulacao', $m['modo'], 'modo da mensagem');
        eq('simulado', $m['provedor'], 'provedor simulado');
    }
    $ana = array_values(array_filter($h['itens'], fn($m) => $m['cliente'] === 'Ana Souza'));
    eq(1, count($ana), 'Ana: UMA mensagem para os dois pacotes da mesma data');
    check(!in_array('Bruno Lima', array_column($h['itens'], 'cliente'), true), 'sem autorização não recebe');
    check(!in_array('Gil Inativo', array_column($h['itens'], 'cliente'), true), 'cliente paused não recebe');
    eq(0, $c->api('executar_agora', ['ignorar_horario' => true])['json']['mensagens_criadas'] ?? null, 'segunda execução não cria nada');
});

// ================================================================ integração com o painel real
webtest('web: regressão do painel — api.php data idêntico antes/depois de instalar e usar o módulo; index.html com patch ok', function (T $t, GrWeb $web) {
    $ids = GrWeb::seed($t);
    $t->exclude($ids['daniel']);
    $c = $web->client()->login();
    // Lançamentos pelo próprio painel (com e sem vínculo de pacote).
    $planB = (string)$t->pdo()->query('SELECT id FROM client_plans WHERE client_id=' . $t->pdo()->quote($ids['bruno']))->fetchColumn();
    foreach ([
        ['kind' => 'revenue', 'amount_cents' => 4590, 'cost_cents' => 1000, 'client_id' => $ids['bruno'], 'plan_id' => $planB, 'date' => GrWeb::today(-10), 'description' => 'Recebimento antigo'],
        ['kind' => 'expense', 'amount_cents' => 2500, 'cost_cents' => 0, 'client_id' => '', 'plan_id' => '', 'date' => GrWeb::today(-3), 'description' => 'Despesa'],
    ] as $e) {
        $r = $c->panel('entry', $e + ['api_version' => 3]);
        eq(200, $r['status'], 'lançamento no painel: ' . $r['body']);
    }
    // "Antes da instalação": sem nenhuma tabela gr_*.
    foreach ($t->pdo()->query("SHOW TABLES LIKE 'gr\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $tb) {
        $t->pdo()->exec("DROP TABLE `$tb`");
    }
    $before = panelData($c);
    // Instalação (migrações gr_*) + uso intenso do módulo.
    (new \GmaxRenov\Migrator($t->app->db(), $t->clock))->migrate();
    setSettingDb($t, 'ativo', true);
    setSettingDb($t, 'ativado_em', '2026-01-01');
    eq(200, $c->api('consentimento', ['cliente_id' => $ids['bruno'], 'acao' => 'conceder', 'origem' => 'respondeu SIM no WhatsApp (teste)'])['status'], 'consentimento');
    eq(200, $c->api('pausa_cliente', ['cliente_id' => $ids['carla'], 'pausado' => true])['status'], 'pausa');
    eq(200, $c->api('executar_agora', ['ignorar_horario' => true])['status'], 'executar');
    eq(200, $c->api('salvar_config', ['rodape' => 'Responda SAIR para não receber.'])['status'], 'salvar');
    foreach (['painel', 'historico', 'clientes', 'config'] as $a) {
        eq(200, $c->apiGet($a)['status'], "leitura $a");
    }
    $after = panelData($c);
    eq(panelTotals($before), panelTotals($after), 'totais do painel iguais');
    eq(hash('sha256', json_encode($before)), hash('sha256', json_encode($after)), 'api.php data idêntico (hash)');
    eq(1, panelTotals($after)['excluidos'], 'excluído continua excluído');
    // index.html com o patch.
    $idx = $c->get('/painel/index.html');
    eq(200, $idx['status'], 'index.html 200');
    check(strpos($idx['body'], 'assets/app.js?v=vencimentos-3') !== false, 'index ainda carrega app.js?v=vencimentos-3');
    check(strpos($idx['body'], \GmaxRenov\PanelMenuPatch::LINK) !== false, 'link Renovações no menu');
    check(substr_count($idx['body'], 'gr-menu-link') === 1, 'link inserido uma vez');
    eq($web->indexOriginal, \GmaxRenov\PanelMenuPatch::revert($idx['body']), 'reversão devolve o index original byte a byte');
    eq(false, \GmaxRenov\PanelMenuPatch::plan($idx['body'])['ok'], 'reaplicar o patch não duplica');
    preg_match('~href="(renovacoes-assets/menu\.css[^"]*)"~', $idx['body'], $m);
    check(!empty($m[1]), 'menu.css referenciado');
    eq(200, $c->get('/painel/' . $m[1])['status'], 'menu.css 200');
    foreach (['assets/app.js?v=vencimentos-3', 'assets/style.css', 'assets/metrics.js', 'assets/pwa.js', 'manifest.webmanifest'] as $a) {
        eq(200, $c->get('/painel/' . $a)['status'], "recurso do painel $a");
    }
    preg_match('~<a class="nav gr-menu-link" href="([^"]+)"~', $idx['body'], $m);
    eq(200, $c->get('/painel/' . $m[1])['status'], 'link do menu abre a aba com a mesma sessão');
});

webtest('web: renovação pelo api.php REAL entre geração e despacho cancela só o pacote renovado; os demais seguem', function (T $t, GrWeb $web) {
    $cid = $t->client('Renata Quatro Pacotes', '31981112222');
    $t->consent($cid, '31981112222');
    $pA = $t->package($cid, 'Pacote A', GrWeb::today(3), '40.00');
    $pB = $t->package($cid, 'Pacote B', GrWeb::today(3), '35.00');
    $pC = $t->package($cid, 'Pacote C', GrWeb::today(3), '25.00');
    $pD = $t->package($cid, 'Pacote D', GrWeb::today(10), '60.00');
    $c = $web->client()->login();
    // Geração sem despacho: pausa global ativa.
    eq(200, $c->api('pausa_global', ['pausado' => true])['status'], 'pausa');
    $r = $c->api('executar_agora', ['ignorar_horario' => true]);
    eq(3, $r['json']['gerados']['novos'] ?? null, 'três lembretes gerados (A, B, C): ' . $r['body']);
    eq(0, (int)$t->pdo()->query('SELECT COUNT(*) FROM gr_mensagens')->fetchColumn(), 'nada despachado');
    // Renovação pelo painel: receita + new_due_date só do Pacote A.
    $r = $c->panel('entry', ['kind' => 'revenue', 'amount_cents' => 4000, 'cost_cents' => 0, 'client_id' => $cid, 'plan_id' => $pA,
        'new_due_date' => GrWeb::today(33), 'date' => GrWeb::today(), 'description' => 'Renovação Pacote A', 'api_version' => 3]);
    eq(200, $r['status'], 'api.php entry: ' . $r['body']);
    eq(GrWeb::today(33), $t->pdo()->query('SELECT due_date FROM client_plans WHERE id=' . $t->pdo()->quote($pA))->fetchColumn(), 'painel mudou só o A');
    // Despacho.
    eq(200, $c->api('pausa_global', ['pausado' => false])['status'], 'retoma');
    $r = $c->api('executar_agora', ['ignorar_horario' => true]);
    eq(200, $r['status'], 'executar: ' . $r['body']);
    $msgs = $t->messages();
    eq(1, count($msgs), 'uma mensagem (B+C)');
    check(strpos($msgs[0]['corpo'], 'Pacote B') !== false && strpos($msgs[0]['corpo'], 'Pacote C') !== false, 'cita B e C');
    check(strpos($msgs[0]['corpo'], 'Pacote A') === false, 'NÃO cita o pacote renovado: ' . $msgs[0]['corpo']);
    check(strpos($msgs[0]['corpo'], 'R$ 60,00') !== false, 'total B+C = R$ 60,00');
    check(strpos($msgs[0]['corpo'], 'Pacote D') === false, 'D (outra data) fora');
    $remA = $t->pdo()->query('SELECT status, motivo, mensagem_id FROM gr_lembretes WHERE pacote_id=' . $t->pdo()->quote($pA))->fetchAll(PDO::FETCH_ASSOC);
    eq(1, count($remA), 'um lembrete do A');
    eq('cancelado', $remA[0]['status'], 'lembrete do A cancelado');
    eq(null, $remA[0]['mensagem_id'], 'A sem mensagem');
    foreach ([$pB, $pC] as $p) {
        eq('em_mensagem', $t->pdo()->query('SELECT status FROM gr_lembretes WHERE pacote_id=' . $t->pdo()->quote($p))->fetchColumn(), 'B/C enviados');
    }
    foreach ([$pB => GrWeb::today(3), $pC => GrWeb::today(3), $pD => GrWeb::today(10)] as $p => $due) {
        eq($due, $t->pdo()->query('SELECT due_date FROM client_plans WHERE id=' . $t->pdo()->quote($p))->fetchColumn(), 'módulo não mexeu nos vencimentos');
    }
    // O painel ainda vê a renovação vinculada ao Pacote A.
    $d = panelData($c);
    $e = array_values(array_filter($d['entries'], fn($e) => $e['description'] === 'Renovação Pacote A'));
    eq($pA, $e[0]['plan_id'] ?? null, 'entry_plans liga a receita ao A');
});

webtest('web: cliente excluído pelo api.php (delete_client) some do módulo, não é selecionado nem restaurado', function (T $t, GrWeb $web) {
    $cid = $t->client('Zé Excluído', '31982223333');
    $t->consent($cid, '31982223333');
    $t->package($cid, 'Pacote Z', GrWeb::today(3), '50.00');
    $keep = $t->client('Maria Fica', '31983334444');
    $t->consent($keep, '31983334444');
    $t->package($keep, 'Pacote M', GrWeb::today(3), '30.00');
    $c = $web->client()->login();
    check(in_array($cid, array_column($c->apiGet('clientes')['json']['itens'], 'id'), true), 'antes: listado');
    eq(422, $c->panel('delete_client', ['id' => $cid, 'confirm_name' => 'Outro Nome', 'api_version' => 3])['status'], 'nome errado não exclui');
    $r = $c->panel('delete_client', ['id' => $cid, 'confirm_name' => 'Zé Excluído', 'api_version' => 3]);
    eq(200, $r['status'], 'excluído pelo painel: ' . $r['body']);
    check(!in_array($cid, array_column($c->apiGet('clientes')['json']['itens'], 'id'), true), 'some de Autorizações');
    check(!in_array($cid, array_column($c->apiGet('clientes', ['q' => 'Zé'])['json']['itens'], 'id'), true), 'nem pela busca');
    // Em Vencimentos o módulo pode exibir o pacote só como informação, marcado como excluído e sem envio/link manual.
    $p = $c->apiGet('painel')['json'];
    foreach (['hoje', 'proximos', 'vencidos'] as $k) {
        foreach ($p[$k] as $g) {
            if ($g['cliente_id'] !== $cid) {
                continue;
            }
            eq(['cliente_excluido'], array_values(array_unique(array_column($g['itens'], 'motivo'))), 'marcado como excluído');
            eq(null, $g['previa'], 'sem prévia para excluído');
            eq(null, $g['link_manual'], 'sem link de envio manual para excluído');
        }
    }
    $r = $c->api('executar_agora', ['ignorar_horario' => true]);
    eq(200, $r['status'], 'executar');
    $msgs = $t->messages();
    eq([$keep], array_column($msgs, 'cliente_id'), 'só o cliente ativo recebe');
    // Tentativas via módulo não podem "reativar" o excluído.
    $c->api('consentimento', ['cliente_id' => $cid, 'acao' => 'conceder', 'origem' => 'teste']);
    $c->api('pausa_cliente', ['cliente_id' => $cid, 'pausado' => false]);
    $c->api('executar_agora', ['ignorar_horario' => true]);
    eq(1, (int)$t->pdo()->query('SELECT COUNT(*) FROM client_exclusions WHERE client_id=' . $t->pdo()->quote($cid))->fetchColumn(), 'continua em client_exclusions');
    $d = panelData($c);
    check(in_array($cid, array_column($d['deleted_clients'], 'id'), true) && !in_array($cid, array_column($d['clients'], 'id'), true), 'painel continua mostrando como excluído');
    eq(1, count($t->messages()), 'nenhuma mensagem para o excluído');
});

webtest('web: módulo recusa registrar autorização para cliente excluído no painel', function (T $t, GrWeb $web) {
    $cid = $t->client('Zé Excluído', '31982223333');
    $t->package($cid, 'Pacote Z', GrWeb::today(3), '50.00');
    $c = $web->client()->login();
    eq(200, $c->panel('delete_client', ['id' => $cid, 'confirm_name' => 'Zé Excluído', 'api_version' => 3])['status'], 'excluído pelo painel');
    $r = $c->api('consentimento', ['cliente_id' => $cid, 'acao' => 'conceder', 'origem' => 'respondeu SIM']);
    check($r['status'] === 422, 'conceder a excluído → 422 (obtido HTTP ' . $r['status'] . ' ' . $r['body'] . ')');
    eq(0, (int)$t->pdo()->query("SELECT COUNT(*) FROM gr_contatos WHERE consentimento='concedido' AND cliente_id=" . $t->pdo()->quote($cid))->fetchColumn(), 'nenhuma autorização gravada para excluído');
});

// ================================================================ webhook
webtest('web: webhook GET verify — token certo devolve o challenge, errado 403', function (T $t, GrWeb $web) {
    $c = $web->client();
    $vt = $web->config['provedor']['meta']['verify_token'];
    $r = $c->get('/painel/renovacoes-webhook.php?' . http_build_query(['hub.mode' => 'subscribe', 'hub.verify_token' => $vt, 'hub.challenge' => '1158201444']));
    eq(200, $r['status'], 'token certo');
    eq('1158201444', $r['body'], 'challenge ecoado');
    $r = $c->get('/painel/renovacoes-webhook.php?' . http_build_query(['hub.mode' => 'subscribe', 'hub.verify_token' => 'errado', 'hub.challenge' => '1158201444']));
    eq(403, $r['status'], 'token errado');
    eq(403, $c->get('/painel/renovacoes-webhook.php')['status'], 'sem parâmetros');
    eq(403, $c->get('/painel/renovacoes-webhook.php?' . http_build_query(['hub.mode' => 'subscribe', 'hub.verify_token' => $vt, 'hub.challenge' => '<script>x</script>']))['status'], 'challenge com HTML');
    eq(405, $c->request('PUT', '/painel/renovacoes-webhook.php', '')['status'], 'PUT → 405');
});

webtest('web: webhook POST com HMAC válido atualiza status; inválido 401; repetido é idempotente', function (T $t, GrWeb $web) {
    $secret = $web->config['provedor']['meta']['app_secret'];
    $cid = $t->client('Cliente Webhook', '31988887777');
    $id = insertMetaMessage($t, $cid, 'wamid.TESTE123');
    $ts = time() - 60;
    $body = metaStatus('wamid.TESTE123', 'delivered', $ts);
    eq(401, webhookPost($web, $body, null)['status'], 'sem assinatura');
    eq(401, webhookPost($web, $body, 'segredo-errado')['status'], 'segredo errado');
    $r = $web->client()->request('POST', '/painel/renovacoes-webhook.php', $body, ['X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body . ' ', $secret)]);
    eq(401, $r['status'], 'corpo adulterado');
    eq('aceito', $t->pdo()->query("SELECT status FROM gr_mensagens WHERE id=$id")->fetchColumn(), 'nada mudou');
    $r = webhookPost($web, $body, $secret);
    eq(200, $r['status'], 'assinatura válida: ' . $r['body']);
    $m = $t->pdo()->query("SELECT status, entregue_em FROM gr_mensagens WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    eq('entregue', $m['status'], 'status entregue');
    eq(date('Y-m-d H:i:s', $ts), $m['entregue_em'], 'entregue_em = timestamp da Meta');
    eq(1, (int)$t->pdo()->query("SELECT COUNT(*) FROM gr_eventos WHERE mensagem_id=$id AND tipo='status'")->fetchColumn(), 'um evento');
    eq(200, webhookPost($web, $body, $secret)['status'], 'repetido');
    eq(1, (int)$t->pdo()->query("SELECT COUNT(*) FROM gr_eventos WHERE mensagem_id=$id AND tipo='status'")->fetchColumn(), 'sem duplicar');
    eq(200, webhookPost($web, metaStatus('wamid.TESTE123', 'read', $ts + 30), $secret)['status'], 'read');
    eq(200, webhookPost($web, metaStatus('wamid.TESTE123', 'delivered', $ts + 40), $secret)['status'], 'delivered atrasado');
    eq('lido', $t->pdo()->query("SELECT status FROM gr_mensagens WHERE id=$id")->fetchColumn(), 'não regride');
    eq(400, webhookPost($web, 'não é json', $secret)['status'], 'JSON inválido assinado → 400');
});

webtest('web: webhook "SAIR" assinado revoga a autorização; sem assinatura não revoga', function (T $t, GrWeb $web) {
    $cid = $t->client('Cliente Sair', '31911112222');
    $t->consent($cid, '31911112222');
    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
        'messages' => [['from' => '5531911112222', 'id' => 'wamid.IN1', 'type' => 'text', 'text' => ['body' => 'SAIR']]],
    ]]]]]]);
    eq(401, webhookPost($web, $body, 'forjado')['status'], 'forjado rejeitado');
    eq('concedido', $t->pdo()->query('SELECT consentimento FROM gr_contatos WHERE cliente_id=' . $t->pdo()->quote($cid))->fetchColumn(), 'continua autorizado');
    eq(200, webhookPost($web, $body, $web->config['provedor']['meta']['app_secret'])['status'], 'aceito');
    eq('revogado', $t->pdo()->query('SELECT consentimento FROM gr_contatos WHERE cliente_id=' . $t->pdo()->quote($cid))->fetchColumn(), 'revogado');
});

// ================================================================ navegador real
$nodeOk = trim((string)shell_exec('node -e "require(\'playwright\');console.log(1)" 2>/dev/null')) === '1';
if (!$nodeOk) {
    echo "  (pulado: Node/Playwright indisponível — teste de navegador não executado)\n";
} else {
    webtest('web (Chromium): login pelo formulário do painel, link Renovações no menu, aba sem erros de console/CSP, sem XSS, sem rolagem horizontal em 390px', function (T $t, GrWeb $web) {
        GrWeb::seed($t, true);
        $c = $web->client()->login(); // setup do admin (1ª vez); o navegador faz o login pelo formulário
        eq(200, $c->api('executar_agora', ['ignorar_horario' => true])['status'], 'popular o histórico');
        $out = $web->dir . '/capturas';
        exec('node ' . escapeshellarg(__DIR__ . '/web/capturas.js') . ' ' . escapeshellarg($web->base) . ' ' . escapeshellarg($out)
            . ' ' . escapeshellarg(GrWeb::ADMIN_USER) . ' ' . escapeshellarg(GrWeb::ADMIN_PASS) . ' 2>&1', $lines, $code);
        $rel = json_decode((string)end($lines), true);
        check(is_array($rel), 'relatório do navegador: ' . implode("\n", array_slice($lines, -5)));
        eq([], $rel['erros'], 'script do navegador sem exceção');
        foreach ($rel['perfis'] as $perfil => $p) {
            eq([], $p['console'], "$perfil: console da aba Renovações sem erros/avisos (inclui CSP)");
            eq([], $p['pageErrors'], "$perfil: sem exceções JS");
            eq([], $p['dialogs'], "$perfil: nenhum alert (XSS)");
            eq([], $p['http'], "$perfil: nenhuma resposta HTTP >= 400");
            eq([], $p['painel']['consoleErros'], "$perfil: painel sem erros de console após o patch");
            check($p['painel']['link'] !== null, "$perfil: link Renovações presente no menu do painel");
            check($p['painel']['chegouNaAba'], "$perfil: clicar no link abre a aba já autenticado");
            $w = $perfil === 'celular' ? 390 : 1280;
            foreach ($p['abas'] as $aba => $m) {
                check($m['scrollWidth'] <= $w, "$perfil/$aba: sem rolagem horizontal (scrollWidth={$m['scrollWidth']})");
            }
        }
        $txt = $rel['perfis']['celular']['abas']['vencimentos']['texto'];
        check(strpos($txt, '<img src=x onerror=alert(1)>Xss') !== false, 'nome com HTML aparece como texto');
        check(strpos($txt, 'Total do grupo: R$ 155,00') !== false, 'grupo com total na tela');
        eq(200, $rel['perfis']['celular']['executarAgora']['status'] ?? null, 'Executar agora pelo JS → 200');
    });
}

webtest('web: servidor não registrou avisos/erros PHP durante os testes', function (T $t, GrWeb $web) {
    eq('', $web->phpErrors(), 'log de erros PHP do servidor vazio');
});

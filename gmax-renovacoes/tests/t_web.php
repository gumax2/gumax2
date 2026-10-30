<?php
/**
 * Testes INDEPENDENTES em nível HTTP: sobem o servidor PHP embutido com uma cópia de src/ num docroot
 * temporário (ver tests/web/ambiente.php) e falam com renovacoes.php, renovacoes-api.php e
 * renovacoes-webhook.php como um navegador / como a Meta. Cada teste usa um banco grtest_* novo (classe T).
 * Nenhuma chamada de rede externa: provedor simulado; o webhook é chamado localmente.
 */
declare(strict_types=1);

require_once __DIR__ . '/web/ambiente.php';

echo "Web (HTTP real, servidor embutido)\n";

/** Executa um teste web: servidor compartilhado, banco próprio do teste. */
function webtest(string $name, callable $fn): void
{
    test($name, function (T $t) use ($fn) {
        $web = GrWeb::get();
        $web->useTest($t);
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

function insertMetaMessage(T $t, string $wamid, string $status = 'aceito'): int
{
    $now = date('Y-m-d H:i:s');
    $t->pdo()->prepare("INSERT INTO gr_mensagens (modo, ref, cliente_id, telefone, etapa, vencimento, data_prevista, corpo, status, provedor, provedor_msg_id, tentativas, criado_em, atualizado_em)
        VALUES ('real', ?, 1, '5531988887777', 'antes', ?, ?, 'Olá', ?, 'meta_cloud', ?, 1, ?, ?)")
        ->execute(['grw' . bin2hex(random_bytes(6)), GrWeb::today(3), GrWeb::today(), $status, $wamid, $now, $now]);
    return (int)$t->pdo()->lastInsertId();
}

// ---------------------------------------------------------------- autenticação
webtest('web: API sem sessão responde 401 em JSON (GET e POST)', function (T $t, GrWeb $web) {
    $c = $web->client();
    foreach (['painel', 'config', 'historico', 'clientes'] as $a) {
        $r = $c->apiGet($a);
        eq(401, $r['status'], "GET $a sem sessão");
        check(is_array($r['json']) && isset($r['json']['erro']), "corpo JSON com erro ($a)");
        check(str_starts_with(hdr($r, 'content-type'), 'application/json'), 'content-type JSON');
    }
    $r = $c->api('pausa_global', ['pausado' => true], 'qualquer');
    eq(401, $r['status'], 'POST mutação sem sessão');
    eq(false, settingDb($t, 'pausa_global'), 'pausa_global não mudou');
    // Cookie de sessão inventado (strict mode) também não entra.
    $c->cookies['PHPSESSID'] = 'inventado' . bin2hex(random_bytes(8));
    eq(401, $c->apiGet('painel')['status'], 'sessão forjada');
});

webtest('web: renovacoes.php sem sessão redireciona para o login e não mostra dados', function (T $t, GrWeb $web) {
    GrWeb::seed($t);
    $r = $web->client()->get('/painel/renovacoes.php');
    check(in_array($r['status'], [302, 303], true), 'redireciona (HTTP ' . $r['status'] . ')');
    eq('./', hdr($r, 'location'), 'Location = url_login');
    check(strpos($r['body'], 'csrf-token') === false && strpos($r['body'], 'Ana Souza') === false, 'sem página nem dados no corpo');
});

webtest('web: com login, página carrega com token CSRF e cabeçalhos de segurança', function (T $t, GrWeb $web) {
    $c = $web->client()->login();
    check(strlen((string)$c->csrf) >= 32, 'token CSRF presente na meta');
    $r = $c->get('/painel/renovacoes.php');
    eq(200, $r['status'], 'página 200');
    $csp = hdr($r, 'content-security-policy');
    check($csp !== '', 'CSP presente');
    foreach (["default-src 'self'", "script-src 'self'", "frame-ancestors 'none'", "base-uri 'self'"] as $d) {
        check(strpos($csp, $d) !== false, "CSP contém $d");
    }
    check(strpos($csp, 'unsafe-inline') === false && strpos($csp, 'unsafe-eval') === false, 'CSP sem unsafe-*');
    eq('DENY', hdr($r, 'x-frame-options'), 'X-Frame-Options');
    eq('nosniff', hdr($r, 'x-content-type-options'), 'nosniff');
    check(stripos(hdr($r, 'cache-control'), 'no-store') !== false, 'Cache-Control no-store');
    check(hdr($r, 'referrer-policy') !== '', 'Referrer-Policy');
    // Sem scripts/estilos inline (CSP bloquearia).
    check(!preg_match('~<script(?![^>]*\bsrc=)[^>]*>~i', $r['body']), 'sem <script> inline');
    check(!preg_match('~<style\b|\sstyle="|\son[a-z]+="~i', $r['body']), 'sem style/handlers inline');
    // Cookie de sessão: HttpOnly e SameSite.
    $nova = $web->client()->get('/painel/renovacoes.php'); // sessão nova criada pelo módulo (sem login)
    $all = strtolower(implode(' | ', $nova['headers']['set-cookie'] ?? []));
    check(strpos($all, 'httponly') !== false && strpos($all, 'samesite=lax') !== false, "cookie de sessão HttpOnly+SameSite ($all)");
    // A API também manda os cabeçalhos.
    $a = $c->apiGet('painel');
    check(hdr($a, 'content-security-policy') !== '' && hdr($a, 'x-frame-options') === 'DENY', 'cabeçalhos na API');
    // Os assets referenciados existem.
    preg_match_all('~(?:src|href)="(renovacoes-assets/[^"]+)"~', $r['body'], $m);
    eq(2, count($m[1]), 'css + js referenciados');
    foreach ($m[1] as $asset) {
        eq(200, $c->get('/painel/' . html_entity_decode($asset))['status'], "asset $asset");
    }
});

// ---------------------------------------------------------------- CSRF / método / origem
webtest('web: mutação via GET → 405 e nada muda', function (T $t, GrWeb $web) {
    $c = $web->client()->login();
    foreach (['pausa_global', 'salvar_config', 'executar_agora', 'confirmar_pagamento', 'consentimento'] as $a) {
        eq(405, $c->get('/painel/renovacoes-api.php?acao=' . $a . '&pausado=1&ignorar_horario=1')['status'], "GET $a");
    }
    eq(false, settingDb($t, 'pausa_global'), 'pausa_global intacta');
    eq(false, settingDb($t, 'pagamento_confirmado'), 'pagamento_confirmado intacto');
    eq(0, (int)$t->pdo()->query('SELECT COUNT(*) FROM gr_execucoes')->fetchColumn(), 'nenhuma execução');
});

webtest('web: POST sem CSRF ou com CSRF errado → 403; com CSRF correto → 200', function (T $t, GrWeb $web) {
    $c = $web->client()->login();
    eq(403, $c->api('pausa_global', ['pausado' => true], null)['status'], 'sem token');
    eq(403, $c->api('pausa_global', ['pausado' => true], str_repeat('a', 64))['status'], 'token errado');
    eq(403, $c->api('pausa_global', ['pausado' => true], '')['status'], 'token vazio');
    // Token de OUTRA sessão logada não vale nesta.
    $outro = $web->client()->login();
    eq(403, $c->api('pausa_global', ['pausado' => true], $outro->csrf)['status'], 'token de outra sessão');
    eq(false, settingDb($t, 'pausa_global'), 'nada mudou após 403');

    $r = $c->api('pausa_global', ['pausado' => true]);
    eq(200, $r['status'], 'token correto');
    eq(true, $r['json']['pausa_global'] ?? null, 'resposta confirma');
    eq(true, settingDb($t, 'pausa_global'), 'gravado no banco');
    // Mesmo site explícito continua aceito.
    $r = $c->api('pausa_global', ['pausado' => false], 'auto', ['Origin' => $web->base, 'Referer' => $web->base . '/painel/renovacoes.php']);
    eq(200, $r['status'], 'Origin do próprio site');
    eq(false, settingDb($t, 'pausa_global'), 'retomado');
    // Corpo que não é JSON → 400.
    eq(400, $c->request('POST', '/painel/renovacoes-api.php?acao=pausa_global', 'pausado=1', ['Content-Type' => 'application/x-www-form-urlencoded', 'X-CSRF-Token' => $c->csrf])['status'], 'form-urlencoded rejeitado');
});

webtest('web: Origin/Referer de outro site → 403 mesmo com token válido', function (T $t, GrWeb $web) {
    $c = $web->client()->login();
    eq(403, $c->api('pausa_global', ['pausado' => true], 'auto', ['Origin' => 'https://evil.example'])['status'], 'Origin externo');
    eq(403, $c->api('pausa_global', ['pausado' => true], 'auto', ['Origin' => 'http://127.0.0.1:1'])['status'], 'mesmo host, outra porta');
    eq(403, $c->api('pausa_global', ['pausado' => true], 'auto', ['Referer' => 'https://evil.example/x'])['status'], 'Referer externo');
    eq(403, $c->api('pausa_global', ['pausado' => true], 'auto', ['Origin' => 'null'])['status'], 'Origin null (iframe sandbox)');
    eq(false, settingDb($t, 'pausa_global'), 'nada mudou');
});

webtest('web: logout invalida o acesso à API', function (T $t, GrWeb $web) {
    $c = $web->client()->login();
    eq(200, $c->apiGet('painel')['status'], 'logado');
    $c->get('/logout.php');
    eq(401, $c->apiGet('painel')['status'], 'após logout');
});

// ---------------------------------------------------------------- segredos
webtest('web: action=config e demais leituras não expõem segredos do config.php nem dados sensíveis dos pacotes', function (T $t, GrWeb $web) {
    $ids = GrWeb::seed($t);
    $c = $web->client()->login();
    $c->api('executar_agora', ['ignorar_horario' => true]);
    $meta = $web->config['provedor']['meta'];
    $secrets = [$meta['access_token'], $meta['app_secret'], $meta['verify_token'], 'SENHA-SECRETA-123', 'lista.exemplo', 'abc.m3u'];
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
    // Telefones mascarados na lista de autorizações (número completo não aparece).
    $r = $c->apiGet('clientes');
    check(strpos($r['body'], '31988887777') === false && strpos($r['body'], '5531988887777') === false, 'telefone mascarado em clientes');
    // Log do módulo também não guarda segredos.
    foreach (glob($web->dir . '/logs/*') ?: [] as $f) {
        foreach ([$meta['access_token'], $meta['app_secret']] as $s) {
            check(strpos((string)file_get_contents($f), $s) === false, 'log sem segredo');
        }
    }
});

// ---------------------------------------------------------------- configurações / prévia
webtest('web: salvar_config rejeita URL no texto e no rodapé e não grava', function (T $t, GrWeb $web) {
    $c = $web->client()->login();
    $before = settingDb($t, 'textos');
    $r = $c->api('salvar_config', ['textos' => ['antes' => ['individual' => 'Olá {nome}, renove em https://pague.exemplo/x {valor}']]]);
    eq(200, $r['status'], 'HTTP 200 com erros de validação');
    eq(false, $r['json']['ok'] ?? null, 'ok=false');
    check((bool)preg_grep('/link|URL/i', $r['json']['erros'] ?? []), 'erro menciona links: ' . json_encode($r['json']['erros'] ?? null, JSON_UNESCAPED_UNICODE));
    eq($before, settingDb($t, 'textos'), 'textos não mudaram');

    $r = $c->api('salvar_config', ['rodape' => 'Mais em www.exemplo.com']);
    eq(false, $r['json']['ok'] ?? null, 'rodapé com www rejeitado');
    $r = $c->api('salvar_config', ['textos' => ['dia' => ['individual' => 'Olá {nome}! wa.me/5531999999999']]]);
    eq(false, $r['json']['ok'] ?? null, 'wa.me rejeitado');
    $r = $c->api('salvar_config', ['textos' => ['dia' => ['individual' => 'Olá {nome}! Sua senha é 123']]]);
    eq(false, $r['json']['ok'] ?? null, 'senha rejeitada');
    eq($before, settingDb($t, 'textos'), 'nada gravado');

    // Texto válido grava.
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
    check(strpos($corpo, 'Pacote Família') !== false && strpos($corpo, 'R$ 120,00') !== false, 'dados fictícios: ' . $corpo);
    check(strpos($corpo, 'Pix: pix-ficticio@exemplo.com') !== false, 'instruções com chave Pix em edição');
    eq([], $r['json']['erros'], 'sem erros');

    $r = $c->api('previa', ['etapa' => 'dia', 'variante' => 'grupo', 'texto' => 'Oi {nome}: {lista_pacotes} Total {total} http://x.y']);
    eq(200, $r['status'], 'POST previa');
    check(strpos((string)$r['json']['corpo'], 'R$ 155,00') !== false, 'total do grupo: ' . $r['json']['corpo']);
    check(count($r['json']['erros']) >= 1, 'erro de link apontado na prévia');
    eq(403, $c->api('previa', ['etapa' => 'dia', 'texto' => 'x'], 'errado')['status'], 'POST previa com CSRF errado');
    eq(422, $c->apiGet('previa', ['etapa' => 'invalida', 'texto' => 'x'])['status'], 'etapa inválida → 422');
    eq(422, $c->apiGet('acao_que_nao_existe')['status'], 'ação desconhecida → 422');
    // previa não grava nada.
    check(strpos(json_encode(settingDb($t, 'textos')), 'Oi {nome}') === false, 'prévia não salva');
});

// ---------------------------------------------------------------- painel / agrupamento
webtest('web: painel agrupa dois pacotes do mesmo cliente na mesma data (total) e separa outra data', function (T $t, GrWeb $web) {
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
    $groups = array_values(array_filter($d['proximos'], fn($g) => $g['cliente_id'] == $ana));
    eq(2, count($groups), 'dois grupos para a Ana (duas datas)');
    usort($groups, fn($a, $b) => strcmp($a['vencimento'], $b['vencimento']));
    eq(GrWeb::today(3), $groups[0]['vencimento'], 'grupo 1 data');
    eq(2, count($groups[0]['itens']), 'grupo 1 com dois pacotes');
    eq('R$ 155,00', $groups[0]['total'], 'total do grupo');
    eq(3, $groups[0]['dias'], 'dias até o vencimento');
    check(strpos((string)$groups[0]['previa'], 'Pacote Família') !== false && strpos((string)$groups[0]['previa'], 'Pacote Quarto') !== false && strpos((string)$groups[0]['previa'], 'R$ 155,00') !== false, 'prévia agrupada cita os dois e o total');
    eq(GrWeb::today(6), $groups[1]['vencimento'], 'grupo 2 data');
    eq(1, count($groups[1]['itens']), 'grupo 2 com um pacote');
    eq('R$ 50,00', $groups[1]['total'], 'total grupo 2');
    // Outro cliente na mesma data NÃO entra no grupo da Ana.
    $og = array_values(array_filter($d['proximos'], fn($g) => $g['cliente_id'] == $outro));
    eq(1, count($og), 'outro cliente em grupo próprio');
    eq('nenhum', $og[0]['consentimento'], 'outro cliente sem autorização');
    eq(3, $d['resumo']['proximos'], 'resumo conta grupos');
    check(strpos($r['body'], 'SENHA-SECRETA-123') === false, 'sem senha do pacote');
});

webtest('web: executar_agora (ignorar_horario) cria mensagens SIMULADAS visíveis no histórico; repetir não duplica', function (T $t, GrWeb $web) {
    GrWeb::seed($t);
    $c = $web->client()->login();
    $r = $c->api('executar_agora', ['ignorar_horario' => true]);
    eq(200, $r['status'], 'HTTP 200: ' . $r['body']);
    eq('simulacao', $r['json']['modo'] ?? null, 'modo simulação');
    eq(3, $r['json']['mensagens_criadas'] ?? null, 'Ana(grupo) + Carla(depois) + Eduarda(dia)');
    $h = $c->apiGet('historico')['json'];
    eq(3, count($h['itens']), 'histórico com 3 mensagens');
    foreach ($h['itens'] as $m) {
        eq('simulacao', $m['modo'], 'modo da mensagem');
        eq('simulado', $m['provedor'], 'provedor simulado');
    }
    $ana = array_values(array_filter($h['itens'], fn($m) => $m['cliente'] === 'Ana Souza'));
    eq(1, count($ana), 'Ana recebe UMA mensagem para os dois pacotes');
    check(strpos((string)$ana[0]['pacotes'], 'Pacote Família') !== false && strpos((string)$ana[0]['pacotes'], 'Pacote Quarto') !== false, 'pacotes agrupados');
    check(!in_array('Bruno Lima', array_column($h['itens'], 'cliente'), true), 'sem autorização não recebe');
    $r2 = $c->api('executar_agora', ['ignorar_horario' => true]);
    eq(0, $r2['json']['mensagens_criadas'] ?? null, 'segunda execução não cria nada');
    eq(3, (int)$t->pdo()->query('SELECT COUNT(*) FROM gr_mensagens')->fetchColumn(), 'continua 3');
});

// ---------------------------------------------------------------- webhook
webtest('web: webhook GET verify — token certo devolve o challenge, errado 403', function (T $t, GrWeb $web) {
    $c = $web->client();
    $vt = $web->config['provedor']['meta']['verify_token'];
    $r = $c->get('/painel/renovacoes-webhook.php?' . http_build_query(['hub.mode' => 'subscribe', 'hub.verify_token' => $vt, 'hub.challenge' => '1158201444']));
    eq(200, $r['status'], 'token certo');
    eq('1158201444', $r['body'], 'challenge ecoado');
    $r = $c->get('/painel/renovacoes-webhook.php?' . http_build_query(['hub.mode' => 'subscribe', 'hub.verify_token' => 'errado', 'hub.challenge' => '1158201444']));
    eq(403, $r['status'], 'token errado');
    check(strpos($r['body'], '1158201444') === false, 'não ecoa challenge');
    eq(403, $c->get('/painel/renovacoes-webhook.php')['status'], 'sem parâmetros');
    $r = $c->get('/painel/renovacoes-webhook.php?' . http_build_query(['hub.mode' => 'subscribe', 'hub.verify_token' => $vt, 'hub.challenge' => '<script>x</script>']));
    eq(403, $r['status'], 'challenge com HTML rejeitado (sem XSS refletido)');
    eq(405, $c->request('PUT', '/painel/renovacoes-webhook.php', '')['status'], 'PUT → 405');
});

webtest('web: webhook POST com HMAC válido atualiza status; inválido 401; repetido é idempotente', function (T $t, GrWeb $web) {
    $secret = $web->config['provedor']['meta']['app_secret'];
    $id = insertMetaMessage($t, 'wamid.TESTE123');
    $ts = time() - 60;
    $body = metaStatus('wamid.TESTE123', 'delivered', $ts);

    eq(401, webhookPost($web, $body, null)['status'], 'sem assinatura');
    eq(401, webhookPost($web, $body, 'segredo-errado')['status'], 'assinatura com segredo errado');
    $r = $web->client()->request('POST', '/painel/renovacoes-webhook.php', $body, ['X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body . ' ', $secret)]);
    eq(401, $r['status'], 'corpo adulterado');
    eq('aceito', $t->pdo()->query("SELECT status FROM gr_mensagens WHERE id=$id")->fetchColumn(), 'nada mudou com assinatura inválida');

    $r = webhookPost($web, $body, $secret);
    eq(200, $r['status'], 'assinatura válida: ' . $r['body']);
    $m = $t->pdo()->query("SELECT status, entregue_em FROM gr_mensagens WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    eq('entregue', $m['status'], 'status entregue');
    eq(date('Y-m-d H:i:s', $ts), $m['entregue_em'], 'entregue_em = timestamp da Meta (fuso do módulo)');
    $ev = (int)$t->pdo()->query("SELECT COUNT(*) FROM gr_eventos WHERE mensagem_id=$id AND tipo='status'")->fetchColumn();
    eq(1, $ev, 'um evento');

    $r = webhookPost($web, $body, $secret);
    eq(200, $r['status'], 'repetido aceita (Meta não reenvia de novo)');
    eq(1, (int)$t->pdo()->query("SELECT COUNT(*) FROM gr_eventos WHERE mensagem_id=$id AND tipo='status'")->fetchColumn(), 'sem evento duplicado');
    eq('entregue', $t->pdo()->query("SELECT status FROM gr_mensagens WHERE id=$id")->fetchColumn(), 'status igual');

    // Lido depois; e um "delivered" atrasado não regride.
    eq(200, webhookPost($web, metaStatus('wamid.TESTE123', 'read', $ts + 30), $secret)['status'], 'read');
    eq(200, webhookPost($web, metaStatus('wamid.TESTE123', 'delivered', $ts + 40), $secret)['status'], 'delivered atrasado');
    eq('lido', $t->pdo()->query("SELECT status FROM gr_mensagens WHERE id=$id")->fetchColumn(), 'continua lido');

    eq(400, webhookPost($web, 'não é json', $secret)['status'], 'JSON inválido assinado → 400');
    // Webhook não depende de sessão e não manda cookies.
    check(empty($r['headers']['set-cookie']), 'webhook não cria sessão');
});

webtest('web: webhook "SAIR" assinado revoga a autorização do cliente', function (T $t, GrWeb $web) {
    $cid = $t->client('Cliente Sair', '31911112222');
    $t->consent($cid, '31911112222');
    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
        'messages' => [['from' => '5531911112222', 'id' => 'wamid.IN1', 'type' => 'text', 'text' => ['body' => 'SAIR']]],
    ]]]]]]);
    eq(200, webhookPost($web, $body, $web->config['provedor']['meta']['app_secret'])['status'], 'aceito');
    eq('revogado', $t->pdo()->query("SELECT consentimento FROM gr_contatos WHERE cliente_id=$cid")->fetchColumn(), 'revogado');
});

// ---------------------------------------------------------------- XSS armazenado via API
webtest('web: nomes com HTML vêm como texto no JSON (escapados só na interface) e não quebram o JSON', function (T $t, GrWeb $web) {
    $ids = GrWeb::seed($t, true);
    $r = $web->client()->login()->apiGet('painel');
    eq(200, $r['status'], 'painel 200');
    check(is_array($r['json']), 'JSON válido');
    check(str_starts_with(hdr($r, 'content-type'), 'application/json') && hdr($r, 'x-content-type-options') === 'nosniff', 'não é interpretável como HTML');
});

// ---------------------------------------------------------------- navegador real
$nodeOk = trim((string)shell_exec('node -e "require(\'playwright\');console.log(1)" 2>/dev/null')) === '1';
if (!$nodeOk) {
    echo "  (pulado: Node/Playwright indisponível — teste de navegador não executado)\n";
} else {
    webtest('web (Chromium): sem erros de console/CSP, sem XSS, sem rolagem horizontal em 390px; botão Executar agora funciona', function (T $t, GrWeb $web) {
        GrWeb::seed($t, true);
        $c = $web->client()->login();
        eq(200, $c->api('executar_agora', ['ignorar_horario' => true])['status'], 'execução para popular o histórico');
        $out = $web->dir . '/capturas';
        exec('node ' . escapeshellarg(__DIR__ . '/web/capturas.js') . ' ' . escapeshellarg($web->base) . ' ' . escapeshellarg($out) . ' 2>&1', $lines, $code);
        $rel = json_decode((string)end($lines), true);
        check(is_array($rel), 'relatório do navegador: ' . implode("\n", array_slice($lines, -5)));
        eq([], $rel['erros'], 'script do navegador sem exceção');
        foreach ($rel['perfis'] as $perfil => $p) {
            eq([], $p['console'], "$perfil: console sem erros/avisos (inclui CSP)");
            eq([], $p['pageErrors'], "$perfil: sem exceções JS");
            eq([], $p['dialogs'], "$perfil: nenhum alert (XSS)");
            eq([], $p['http'], "$perfil: nenhuma resposta HTTP >= 400");
            foreach ($p['abas'] as $aba => $m) {
                $w = $perfil === 'celular' ? 390 : 1280;
                check($m['scrollWidth'] <= $w, "$perfil/$aba: sem rolagem horizontal (scrollWidth={$m['scrollWidth']})");
            }
        }
        $txt = $rel['perfis']['celular']['abas']['vencimentos']['texto'];
        check(strpos($txt, '<img src=x onerror=alert(1)>Xss') !== false, 'nome com HTML aparece como texto literal');
        check(strpos($txt, 'Total do grupo: R$ 155,00') !== false, 'grupo com total na tela');
        check(strpos($txt, 'sem autorização') !== false && strpos($txt, 'venceu há') !== false, 'sem autorização e vencido visíveis');
        eq(200, $rel['perfis']['celular']['executarAgora']['status'] ?? null, 'Executar agora pelo JS (CSRF do meta) → 200');
        eq(6, count(glob($out . '/celular-*.png')), 'capturas de celular geradas');
        eq(4, count(glob($out . '/desktop-*.png')), 'capturas de desktop geradas');
    });
}

webtest('web: servidor não registrou avisos/erros PHP durante os testes', function (T $t, GrWeb $web) {
    $log = $web->phpErrors();
    eq('', $log, 'log de erros PHP do servidor vazio');
});

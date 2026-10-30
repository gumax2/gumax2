<?php
/** Testes das regras de negócio, fila, provedores e webhooks (dados fictícios, sem rede). */
declare(strict_types=1);

use GmaxRenov\Contacts;
use GmaxRenov\Engine;
use GmaxRenov\Format;
use GmaxRenov\Migrator;
use GmaxRenov\Provider\MetaCloudProvider;
use GmaxRenov\Provider\SendResult;
use GmaxRenov\Provider\SimulatedProvider;
use GmaxRenov\Statuses;
use GmaxRenov\Templates;
use GmaxRenov\Webhook;

echo "Regras de vencimento e agrupamento\n";

test('cliente com dois pacotes e vencimentos diferentes gera lembretes separados', function (T $t) {
    $c = $t->client('Ana Teste');
    $t->consent($c);
    $p1 = $t->package($c, 'Pacote Sala', '2026-11-13', '60.00');
    $p2 = $t->package($c, 'Pacote Quarto', '2026-11-20', '40.00');
    $t->run('2026-11-10 09:30:00');
    $m = $t->messages();
    eq(1, count($m), 'só o pacote que vence em 3 dias');
    check(strpos($m[0]['corpo'], 'Pacote Sala') !== false && strpos($m[0]['corpo'], 'Pacote Quarto') === false, 'mensagem cita só o Pacote Sala');
    $t->run('2026-11-17 09:30:00');
    $m = $t->messages();
    eq(2, count($m), 'segundo pacote avisado no seu próprio dia');
    check(strpos($m[1]['corpo'], 'Pacote Quarto') !== false && strpos($m[1]['corpo'], 'Pacote Sala') === false, 'segunda mensagem só do Pacote Quarto');
    eq(['aceito', 'aceito'], array_column($m, 'status'), 'ambas aceitas pelo provedor simulado');
});

test('vários pacotes na mesma data viram UMA mensagem com pacotes e valores', function (T $t) {
    $c = $t->client('Bruno Teste');
    $t->consent($c);
    $t->package($c, 'Pacote A', '2026-11-13', '60.00', ['pontos' => 2]);
    $t->package($c, 'Pacote B', '2026-11-13', '45.50');
    $t->run();
    $m = $t->messages();
    eq(1, count($m), 'uma mensagem agrupada');
    $body = $m[0]['corpo'];
    check(strpos($body, 'Pacote A (2 pontos, mensal) — R$ 60,00') !== false, 'linha do pacote A com valor');
    check(strpos($body, 'Pacote B (1 ponto, mensal) — R$ 45,50') !== false, 'linha do pacote B com valor');
    check(strpos($body, 'R$ 105,50') !== false, 'total do grupo');
    eq(2, (int)$t->app->db()->value('SELECT COUNT(*) FROM gr_lembretes WHERE mensagem_id=?', [$m[0]['id']]), 'dois lembretes ligados à mensagem');
    $params = json_decode($m[0]['modelo_parametros'], true);
    foreach ($params as $p) {
        check(strpos($p, "\n") === false, 'parâmetros do modelo sem quebra de linha');
    }
});

test('preço total não é multiplicado pelos pontos', function (T $t) {
    $c = $t->client('Carla Teste');
    $t->consent($c);
    $t->package($c, 'Pacote Família', '2026-11-13', '120.00', ['pontos' => 4]);
    $t->run();
    $body = $t->messages()[0]['corpo'];
    check(strpos($body, 'R$ 120,00') !== false, 'informa R$ 120,00');
    check(strpos($body, '480') === false, 'não informa R$ 480');
});

test('ciclos trimestral e anual informam o valor do ciclo', function (T $t) {
    $c1 = $t->client('Davi Teste', '31999990001');
    $c2 = $t->client('Eva Teste', '31999990002');
    $t->consent($c1, '31999990001');
    $t->consent($c2, '31999990002');
    $t->package($c1, 'Plano Tri', '2026-11-13', '300.00', ['ciclo' => 'trimestral', 'pontos' => 3]);
    $t->package($c2, 'Plano Anual', '2026-11-13', '1000.00', ['ciclo' => 'anual']);
    $t->run();
    $m = $t->messages();
    eq(2, count($m), 'uma mensagem por cliente');
    $by = [];
    foreach ($m as $x) {
        $by[strpos($x['corpo'], 'Plano Tri') !== false ? 'tri' : 'anual'] = $x['corpo'];
    }
    check(strpos($by['tri'], '3 pontos, trimestral') !== false && strpos($by['tri'], 'R$ 300,00') !== false, 'trimestral (months=3) R$ 300,00');
    check(strpos($by['anual'], 'anual') !== false && strpos($by['anual'], 'R$ 1.000,00') !== false, 'anual (months=12) R$ 1.000,00');
});

test('mensagens não contêm senha nem URL da lista', function (T $t) {
    $c = $t->client('Fabio Teste');
    $t->consent($c);
    $t->package($c, 'Pacote X', '2026-11-13', '50.00');
    $t->run();
    $m = $t->messages()[0];
    check(strpos($m['corpo'] . $m['modelo_parametros'], 'SENHA-SECRETA') === false, 'sem senha');
    check(stripos($m['corpo'] . $m['modelo_parametros'], 'http') === false, 'sem URL');
    check(strpos($m['corpo'], 'gumaxskins@gmail.com') !== false, 'com a chave Pix configurada');
});

echo "Renovação e revalidação\n";

test('renovação entre a geração e o envio cancela só aquele pacote', function (T $t) {
    $c = $t->client('Gabi Teste');
    $t->consent($c);
    $p1 = $t->package($c, 'Pacote Renovado', '2026-11-13', '60.00');
    $p2 = $t->package($c, 'Pacote Mantido', '2026-11-13', '40.00');
    $t->app->settings()->set('pausa_global', true); // só gera
    $t->run();
    eq(0, count($t->messages()), 'nada enviado durante a pausa');
    eq(2, count(array_filter($t->reminders(), fn($r) => $r['status'] === 'pendente')), 'dois lembretes pendentes');
    // Você registra a renovação no painel: vencimento vai para 13/12.
    $t->pdo()->exec("UPDATE client_plans SET due_date='2026-12-13' WHERE id='$p1'");
    $t->app->settings()->set('pausa_global', false);
    $t->run('2026-11-10 10:00:00');
    $m = $t->messages();
    eq(1, count($m), 'uma mensagem');
    check(strpos($m[0]['corpo'], 'Pacote Mantido') !== false && strpos($m[0]['corpo'], 'Pacote Renovado') === false, 'só o pacote não renovado');
    $r = $t->app->db()->one('SELECT status, motivo FROM gr_lembretes WHERE pacote_id=?', [$p1]);
    eq(['status' => 'cancelado', 'motivo' => 'renovado'], $r, 'lembrete do pacote renovado cancelado');
});

test('renovação durante espera de nova tentativa cancela a mensagem', function (T $t) {
    $c = $t->client('Hugo Teste');
    $t->consent($c);
    $p = $t->package($c, 'Pacote H', '2026-11-13', '60.00');
    $t->app->setProvider(new SimulatedProvider([new SendResult(SendResult::TRANSIENT, null, '130429', 'limite')]));
    $t->run();
    eq('aguardando', $t->messages()[0]['status'], 'aguardando nova tentativa');
    $t->pdo()->exec("UPDATE client_plans SET due_date='2026-12-13' WHERE id='$p'");
    $prov = new SimulatedProvider();
    $t->app->setProvider($prov);
    $t->run('2026-11-10 10:30:00');
    eq('cancelado', $t->messages()[0]['status'], 'cancelada');
    eq(0, count($prov->sent), 'provedor não foi chamado');
});

test('renovação de um pacote não cancela avisos de outro pacote do cliente', function (T $t) {
    $c = $t->client('Iris Teste');
    $t->consent($c);
    $p1 = $t->package($c, 'P1', '2026-11-13', '60.00');
    $p2 = $t->package($c, 'P2', '2026-11-10', '40.00'); // vence hoje
    $t->app->settings()->set('pausa_global', true);
    $t->run();
    $t->pdo()->exec("UPDATE client_plans SET due_date='2026-12-13' WHERE id='$p1'");
    $t->app->settings()->set('pausa_global', false);
    $t->run('2026-11-10 10:00:00');
    $m = $t->messages();
    eq(1, count($m), 'só P2');
    check(strpos($m[0]['corpo'], 'vence hoje') !== false, 'mensagem do dia do vencimento');
});

test('aviso de 1 dia após não sai se o pacote foi renovado', function (T $t) {
    $c = $t->client('Joana Teste');
    $t->consent($c);
    $p = $t->package($c, 'Pacote J', '2026-12-09', '60.00'); // já renovado (vencimento original era 09/11)
    $t->run('2026-11-10 09:30:00');
    eq(0, count($t->messages()), 'nada enviado');
    $p2 = $t->package($c, 'Pacote K', '2026-11-09', '30.00'); // não renovado
    $t->run('2026-11-10 09:45:00');
    $m = $t->messages();
    eq(1, count($m), 'aviso pós-vencimento do não renovado');
    check(strpos($m[0]['corpo'], 'foi ontem, 09/11/2026') !== false, 'texto do dia seguinte');
});

echo "Bloqueios (cliente/pacote/consentimento/telefone)\n";

test('cliente excluído, cliente pausado e pacote pausado não recebem', function (T $t) {
    $a = $t->client('Excluido', '31999990001', ['excluido_em' => '2026-11-01 10:00:00']);
    $b = $t->client('Pausado', '31999990002', ['status' => 'pausado']);
    $c = $t->client('Arquivado', '31999990003', ['status' => 'arquivado']);
    $d = $t->client('Ok PacotePausado', '31999990004');
    foreach ([[$a, '31999990001'], [$b, '31999990002'], [$c, '31999990003'], [$d, '31999990004']] as [$id, $ph]) {
        $t->consent($id, $ph);
    }
    $t->package($a, 'PA', '2026-11-13', '10.00');
    $t->package($b, 'PB', '2026-11-13', '10.00');
    $t->package($c, 'PC', '2026-11-13', '10.00');
    $t->package($d, 'PD', '2026-11-13', '10.00', ['status' => 'pausado']);
    $t->package($d, 'PE', '2026-11-13', '10.00', ['status' => 'arquivado']);
    $t->run();
    eq(0, count($t->messages()), 'nenhuma mensagem');
    $reasons = array_column($t->reminders(), 'motivo');
    sort($reasons);
    eq(['cliente_excluido', 'cliente_inativo', 'cliente_inativo', 'pacote_inativo', 'pacote_inativo'], $reasons, 'motivos registrados');
});

test('cliente pausado no módulo e cliente excluído entre geração e envio', function (T $t) {
    $a = $t->client('Pausa Modulo', '31999990001');
    $b = $t->client('Exclui Depois', '31999990002');
    $t->consent($a, '31999990001');
    $t->consent($b, '31999990002');
    (new Contacts($t->app->db(), $t->clock))->setPaused($a, true, 'pediu um tempo');
    $t->package($a, 'PA', '2026-11-13', '10.00');
    $t->package($b, 'PB', '2026-11-13', '10.00');
    $t->app->settings()->set('pausa_global', true);
    $t->run();
    $t->exclude($b, '2026-11-10T09:40:00-03:00');
    $t->app->settings()->set('pausa_global', false);
    $t->run('2026-11-10 09:50:00');
    eq(0, count($t->messages()), 'nenhuma mensagem');
});

test('sem consentimento não envia; autorização no mesmo dia libera; revogação bloqueia', function (T $t) {
    $c = $t->client('Leo Teste');
    $t->package($c, 'PL', '2026-11-13', '10.00');
    $t->run();
    eq(0, count($t->messages()), 'sem autorização');
    eq('sem_consentimento', $t->reminders()[0]['motivo'], 'motivo');
    $t->consent($c);
    $t->run('2026-11-10 11:00:00');
    eq(1, count($t->messages()), 'enviado após autorização no mesmo dia');
    // revogação antes do próximo aviso
    (new Contacts($t->app->db(), $t->clock))->revoke($c, 'painel');
    $t->run('2026-11-13 09:30:00');
    eq(1, count($t->messages()), 'aviso do dia do vencimento não enviado após revogação');
    $r = $t->app->db()->one("SELECT motivo FROM gr_lembretes WHERE etapa='dia'");
    eq('consentimento_revogado', $r['motivo'], 'motivo revogado');
});

test('revogação entre a geração e o envio impede o envio', function (T $t) {
    $c = $t->client('Mia Teste');
    $t->consent($c);
    $t->package($c, 'PM', '2026-11-13', '10.00');
    $t->app->settings()->set('pausa_global', true);
    $t->run();
    (new Contacts($t->app->db(), $t->clock))->revoke($c, 'painel');
    $t->app->settings()->set('pausa_global', false);
    $t->run('2026-11-10 10:00:00');
    eq(0, count($t->messages()), 'nada enviado');
});

test('telefone inválido ou alterado após autorização bloqueia', function (T $t) {
    $a = $t->client('Tel Invalido', '1234');
    $b = $t->client('Tel Mudou', '31999990002');
    $t->consent($a, '31999990001'); // autorização registrada com outro número
    $t->consent($b, '31999990002');
    $t->pdo()->exec("UPDATE clients SET phone='31988887777' WHERE id='$b'");
    $t->package($a, 'PA', '2026-11-13', '10.00');
    $t->package($b, 'PB', '2026-11-13', '10.00');
    $t->run();
    eq(0, count($t->messages()), 'nada enviado');
    $reasons = array_column($t->reminders(), 'motivo');
    sort($reasons);
    eq(['telefone_invalido', 'telefone_mudou'], $reasons, 'motivos');
});

test('normalização de telefones brasileiros', function (T $t) {
    eq('5531999998888', Format::phone('(31) 99999-8888'), 'celular com máscara');
    eq('5531999998888', Format::phone('+55 31 99999-8888'), 'com +55');
    eq('5531999998888', Format::phone('031999998888'), 'com 0 na frente');
    eq('553133334444', Format::phone('(31) 3333-4444'), 'fixo');
    eq(null, Format::phone('31 89999-8888'), 'celular de 9 dígitos sem 9 inicial');
    eq(null, Format::phone('9999-8888'), 'sem DDD');
    eq(null, Format::phone(''), 'vazio');
    eq(null, Format::phone('00 31 99999-8888 ramal 2'), 'lixo');
});

echo "Datas (fuso, mês, ano, bissexto)\n";

test('ano bissexto: 29/02/2028 nas três etapas', function (T $t) {
    $c = $t->client('Bissexto');
    $t->consent($c);
    $t->package($c, 'PB', '2028-02-29', '10.00');
    $t->run('2028-02-26 09:30:00');
    $t->run('2028-02-29 09:30:00');
    $t->run('2028-03-01 09:30:00');
    eq(['antes', 'dia', 'depois'], array_column($t->messages(), 'etapa'), 'três etapas nos dias certos');
    $t->run('2028-02-28 09:30:00');
    eq(3, count($t->messages()), 'nada em 28/02');
}, '2028-02-26 09:30:00');

test('virada de mês e de ano', function (T $t) {
    $c = $t->client('Virada');
    $t->consent($c);
    $t->package($c, 'P Ano', '2027-01-01', '10.00');
    $t->package($c, 'P Mes', '2026-12-31', '20.00');
    $t->run('2026-12-29 09:30:00'); // 3 dias antes de 01/01/2027
    $t->run('2027-01-01 09:30:00'); // vence P Ano; 1 dia após P Mes
    $m = $t->messages();
    eq(3, count($m), 'três mensagens');
    $pairs = array_map(fn($x) => $x['vencimento'] . ' ' . $x['etapa'], $m);
    sort($pairs);
    eq(['2026-12-31 depois', '2027-01-01 antes', '2027-01-01 dia'], $pairs, 'vencimentos e etapas');
    check(strpos($m[0]['corpo'], '01/01/2027') !== false, 'data formatada');
}, '2026-12-29 09:30:00');

test('fuso America/Sao_Paulo: antes das 09h nada é gerado', function (T $t) {
    $c = $t->client('Cedo');
    $t->consent($c);
    $t->package($c, 'P', '2026-11-13', '10.00');
    $r = $t->run('2026-11-10 08:59:00');
    eq(0, count($t->reminders()), 'nada gerado antes do horário');
    check(strpos($r['motivo'], 'Antes do horário') !== false, 'motivo informado');
    $t->run('2026-11-10 09:00:00');
    eq(1, count($t->messages()), 'gerado às 09h');
    $t->app->settings()->set('horario', '14:30');
    $t->package($c, 'P2', '2026-11-14', '10.00');
    $t->run('2026-11-11 10:00:00');
    eq(1, count($t->messages()), 'horário configurável respeitado');
    eq('America/Sao_Paulo', date_default_timezone_get(), 'fuso do PHP');
});

test('fim da janela de envio: depois das 20h não envia', function (T $t) {
    $c = $t->client('Tarde');
    $t->consent($c);
    $t->package($c, 'P', '2026-11-13', '10.00');
    $t->run('2026-11-10 20:15:00');
    eq(0, count($t->messages()), 'nada enviado fora da janela');
    $t->run('2026-11-11 09:30:00');
    eq(0, count($t->messages()), 'não envia atrasado no dia seguinte');
    eq('fora_do_dia', $t->reminders()[0]['motivo'], 'expirado');
});

echo "Ativação sem disparo retroativo\n";

test('ativar o módulo não dispara lembretes antigos', function (T $t) {
    $c = $t->client('Antigo');
    $t->consent($c);
    $t->package($c, 'Venceu semana passada', '2026-11-02', '10.00');
    $t->package($c, 'Venceu anteontem', '2026-11-08', '10.00');
    $t->package($c, 'Vence em 2 dias', '2026-11-12', '10.00');
    $t->app->settings()->set('ativado_em', '2026-11-10');
    $t->run();
    eq(0, count($t->reminders()), 'nenhum lembrete retroativo');
});

test('ativação com data futura não gera nada antes dela', function (T $t) {
    $c = $t->client('Futuro');
    $t->consent($c);
    $t->package($c, 'P', '2026-11-13', '10.00');
    $t->app->settings()->set('ativado_em', '2026-11-11');
    $t->run();
    eq(0, count($t->reminders()), 'nada gerado');
});

test('lembretes pendentes de ontem expiram e não são enviados hoje', function (T $t) {
    $c = $t->client('Ontem');
    $t->consent($c);
    $t->package($c, 'P', '2026-11-13', '10.00');
    $t->app->settings()->set('pausa_global', true);
    $t->run();
    $t->app->settings()->set('pausa_global', false);
    $t->run('2026-11-11 09:30:00');
    eq(0, count($t->messages()), 'não enviado atrasado');
    eq(['cancelado'], array_column($t->reminders(), 'status'), 'cancelado');
});

echo "Duplicidade e concorrência\n";

test('agendamento repetido não reenvia', function (T $t) {
    $c = $t->client('Repete');
    $t->consent($c);
    $t->package($c, 'P', '2026-11-13', '10.00');
    for ($i = 0; $i < 5; $i++) {
        $t->run('2026-11-10 09:' . (30 + $i * 5) . ':00');
    }
    eq(1, count($t->messages()), 'uma mensagem');
    eq(1, count($t->provider->sent), 'provedor chamado uma vez');
    eq(1, count($t->reminders()), 'um lembrete');
});

test('trava impede execução simultânea no mesmo processo de teste', function (T $t) {
    $other = new PDO('mysql:host=' . T::env('GR_TEST_HOST', 'localhost') . ';dbname=' . $t->dbName, T::env('GR_TEST_USER', 'grtest'), T::env('GR_TEST_PASS', 'grtest'));
    $lock = $t->app->db()->lockName(Engine::LOCK);
    check(strpos($lock, $t->dbName) !== false, 'nome da trava inclui o banco (MySQL compartilhado)');
    eq('1', (string)$other->query("SELECT GET_LOCK('" . $lock . "', 0)")->fetchColumn(), 'outro trabalhador segura a trava');
    $r = $t->run();
    eq(false, $r['executado'], 'segunda execução sai sem fazer nada');
    $other->query("SELECT RELEASE_LOCK('" . $lock . "')");
    // A mesma trava em OUTRO banco (outra instalação no mesmo servidor) não bloqueia esta.
    $other->query("SELECT GET_LOCK('" . Engine::LOCK . ":outro_banco', 0)");
    eq(true, $t->run()['executado'], 'trava de outro banco não interfere');
});

$concurrency = function (bool $withLock) {
    return function (T $t) use ($withLock) {
        for ($i = 1; $i <= 6; $i++) {
            $c = $t->client("Paralelo $i", '3199999000' . $i);
            $t->consent($c, '3199999000' . $i);
            $t->package($c, "P$i", '2026-11-13', '10.00');
            $t->package($c, "Q$i", '2026-11-13', '15.00');
        }
        $cfg = $t->writeConfig(['testes_provedor_lento_ms' => 150]);
        $cron = GR_TEST_SRC . '/cron/renovacoes-cron.php';
        $cmd = 'GR_CONFIG=' . escapeshellarg($cfg) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cron) . " --agora='2026-11-10 09:30:00'" . ($withLock ? '' : ' --sem-trava');
        $procs = [];
        for ($w = 0; $w < 3; $w++) {
            $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$w]);
        }
        $outs = [];
        foreach ($procs as $w => $p) {
            $outs[] = stream_get_contents($pipes[$w][1]) . stream_get_contents($pipes[$w][2]);
            proc_close($p);
        }
        $db = $t->app->db();
        eq(6, (int)$db->value('SELECT COUNT(*) FROM gr_mensagens'), 'uma mensagem por cliente — saídas: ' . implode(' | ', $outs));
        eq(6, (int)$db->value("SELECT COUNT(*) FROM gr_mensagens WHERE status='aceito'"), 'todas aceitas');
        eq(6, (int)$db->value("SELECT COUNT(*) FROM gr_eventos WHERE tipo='envio'"), 'exatamente um envio por mensagem');
        eq(12, (int)$db->value("SELECT COUNT(*) FROM gr_lembretes WHERE status='em_mensagem'"), 'cada lembrete em uma única mensagem');
        eq(6, (int)$db->value('SELECT SUM(tentativas) FROM gr_mensagens'), 'uma tentativa por mensagem');
    };
};
test('três trabalhadores simultâneos (com trava do agendador)', $concurrency(true));
test('três trabalhadores simultâneos SEM a trava (reivindicação atômica)', $concurrency(false));

echo "Falhas, timeout e callbacks\n";

test('falha temporária: novas tentativas limitadas e depois "falhou"', function (T $t) {
    $c = $t->client('Temp');
    $t->consent($c);
    $t->package($c, 'P', '2026-11-13', '10.00');
    $tr = fn() => new SendResult(SendResult::TRANSIENT, null, '130429', 'limite de taxa');
    $t->app->setProvider(new SimulatedProvider([$tr, $tr, $tr, $tr]));
    $t->run('2026-11-10 09:30:00');
    $m = $t->messages()[0];
    eq('aguardando', $m['status'], 'aguardando');
    eq('2026-11-10 09:35:00', $m['proxima_tentativa_em'], 'nova tentativa em 5 min');
    $t->run('2026-11-10 09:32:00');
    eq(1, (int)$t->messages()[0]['tentativas'], 'não tenta antes da hora');
    $t->run('2026-11-10 09:36:00');
    eq('2026-11-10 09:46:00', $t->messages()[0]['proxima_tentativa_em'], 'espera dobra (10 min)');
    $t->run('2026-11-10 09:47:00');
    $m = $t->messages()[0];
    eq('falhou', $m['status'], 'falhou após 3 tentativas');
    eq(3, (int)$m['tentativas'], 'três tentativas');
    $t->run('2026-11-10 10:30:00');
    eq(3, (int)$t->messages()[0]['tentativas'], 'não tenta mais');
});

test('falha permanente não repete; erro de credencial ativa pausa global', function (T $t) {
    $a = $t->client('Perm', '31999990001');
    $b = $t->client('Cred', '31999990002');
    $c = $t->client('Depois', '31999990003');
    foreach ([[$a, '31999990001'], [$b, '31999990002'], [$c, '31999990003']] as [$id, $ph]) {
        $t->consent($id, $ph);
        $t->package($id, 'P' . $id, '2026-11-13', '10.00');
    }
    $prov = new SimulatedProvider([
        new SendResult(SendResult::PERMANENT, null, '131026', 'Número não alcançável'),
        new SendResult(SendResult::PERMANENT, null, '190', 'token expirado', true),
    ]);
    $t->app->setProvider($prov);
    $t->run();
    eq(['falhou', 'falhou', 'aguardando'], array_column($t->messages(), 'status'), 'terceira não foi enviada');
    eq(true, (bool)$t->app->settings()->get('pausa_global'), 'pausa global ativada');
    $t->run('2026-11-10 10:00:00');
    eq(2, count($prov->sent), 'nada mais enviado durante a pausa');
});

test('timeout gera "incerto" e não reenvia automaticamente', function (T $t) {
    $c = $t->client('Timeout');
    $t->consent($c);
    $t->package($c, 'P', '2026-11-13', '10.00');
    $prov = new SimulatedProvider([new SendResult(SendResult::UNCERTAIN, null, 'rede_28', 'timeout')]);
    $t->app->setProvider($prov);
    $t->run();
    $t->run('2026-11-10 10:00:00');
    $t->run('2026-11-10 11:00:00');
    eq('incerto', $t->messages()[0]['status'], 'incerto');
    eq(1, count($prov->sent), 'uma única chamada');
    // Você confere no WhatsApp e decide.
    $e = new Engine($t->app);
    eq('aceito', $e->resolve((int)$t->messages()[0]['id'], 'confirmar_enviado'), 'confirmado manualmente');
    eq('aceito', $t->messages()[0]['status'], 'aceito (não entregue)');
});

test('envio interrompido (processo caiu) vira "incerto"', function (T $t) {
    $c = $t->client('Caiu');
    $t->consent($c);
    $t->package($c, 'P', '2026-11-13', '10.00');
    $t->app->settings()->set('pausa_global', true);
    $t->run();
    $t->app->settings()->set('pausa_global', false);
    $e = new Engine($t->app);
    $id = $e->buildMessage('simulacao', $c, '2026-11-13', 'antes');
    $t->pdo()->exec("UPDATE gr_mensagens SET status='enviando', lease_token='x', lease_ate='2026-11-10 09:31:00' WHERE id=$id");
    $t->run('2026-11-10 09:40:00');
    eq('incerto', $t->messages()[0]['status'], 'incerto');
    eq(0, count($t->provider->sent), 'não reenviado');
});

test('reenvio manual após incerto só no mesmo dia', function (T $t) {
    $c = $t->client('Reenvio');
    $t->consent($c);
    $t->package($c, 'P', '2026-11-13', '10.00');
    $t->app->setProvider(new SimulatedProvider([new SendResult(SendResult::UNCERTAIN, null, 'rede_52', 'sem resposta')]));
    $t->run();
    $id = (int)$t->messages()[0]['id'];
    $t->clock->set('2026-11-11 09:30:00');
    $failed = false;
    try {
        (new Engine($t->app))->resolve($id, 'reenviar');
    } catch (InvalidArgumentException $e) {
        $failed = true;
    }
    check($failed, 'reenvio em outro dia é recusado');
    $t->clock->set('2026-11-10 10:00:00');
    eq('aceito', (new Engine($t->app))->resolve($id, 'reenviar'), 'reenvio no mesmo dia aceito');
});

function signed(T $t, array $payload): array
{
    $raw = json_encode($payload);
    return [$raw, 'sha256=' . hash_hmac('sha256', $raw, 'segredo-teste')];
}
function statusPayload(string $wamid, string $status, int $ts, array $extra = []): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp', 'statuses' => [array_merge(['id' => $wamid, 'status' => $status, 'timestamp' => (string)$ts, 'recipient_id' => '5531999990001'], $extra)],
    ]]]]]];
}

test('callbacks repetidos e fora de ordem', function (T $t) {
    $c = $t->client('Callback');
    $t->consent($c);
    $t->package($c, 'P', '2026-11-13', '10.00');
    $t->app->setProvider(new SimulatedProvider([new SendResult(SendResult::ACCEPTED, 'wamid.ABC')]));
    $t->run();
    $t->pdo()->exec("UPDATE gr_mensagens SET provedor='meta_cloud'");
    $hook = new Webhook($t->app);
    $base = 1794300000;
    foreach ([['read', 30], ['delivered', 20], ['delivered', 20], ['sent', 10], ['failed', 40]] as [$s, $d]) {
        [$raw, $sig] = signed($t, statusPayload('wamid.ABC', $s, $base + $d, $s === 'failed' ? ['errors' => [['code' => 131026, 'title' => 'x']]] : []));
        eq(200, $hook->handlePost($raw, $sig)[0], "callback $s aceito");
    }
    $m = $t->messages()[0];
    eq('lido', $m['status'], 'fica em "lido" (não regride, falha tardia ignorada)');
    check($m['entregue_em'] !== null && $m['lido_em'] !== null, 'datas de entrega e leitura');
    eq(4, (int)$t->app->db()->value("SELECT COUNT(*) FROM gr_eventos WHERE tipo='status'"), 'duplicado não registrado duas vezes');
});

test('"entregue" só com callback; falha via callback antes da entrega', function (T $t) {
    $c = $t->client('Entrega');
    $t->consent($c);
    $t->package($c, 'P', '2026-11-13', '10.00');
    $t->app->setProvider(new SimulatedProvider([new SendResult(SendResult::ACCEPTED, 'wamid.F1')]));
    $t->run();
    $t->pdo()->exec("UPDATE gr_mensagens SET provedor='meta_cloud'");
    eq('aceito', $t->messages()[0]['status'], 'sem callback fica "aceito"');
    [$raw, $sig] = signed($t, statusPayload('wamid.F1', 'failed', 1794300000, ['errors' => [['code' => 131049, 'title' => 'não entregue']]]));
    (new Webhook($t->app))->handlePost($raw, $sig);
    eq('falhou', $t->messages()[0]['status'], 'falhou pelo callback');
    check(strpos((string)$t->messages()[0]['erro_detalhe'], '131049') !== false, 'motivo da falha registrado');
});

test('callback antes de gravar o ID (órfão) é aplicado depois; incerto resolvido por referência', function (T $t) {
    $c = $t->client('Orfao');
    $t->consent($c);
    $t->package($c, 'P', '2026-11-13', '10.00');
    $hook = new Webhook($t->app);
    [$raw, $sig] = signed($t, statusPayload('wamid.ORF', 'delivered', 1794300000));
    $hook->handlePost($raw, $sig);
    $t->app->setProvider(new SimulatedProvider([function () {
        return new SendResult(SendResult::ACCEPTED, 'wamid.ORF');
    }]));
    $t->run();
    // simulado grava provedor 'simulado'; o órfão é aplicado pelo ID mesmo assim
    eq('entregue', $t->messages()[0]['status'], 'órfão aplicado');

    // Incerto + callback com biz_opaque_callback_data = ref
    $c2 = $t->client('Incerto Ref', '31999990002');
    $t->consent($c2, '31999990002');
    $t->package($c2, 'P2', '2026-11-13', '10.00');
    $t->app->setProvider(new SimulatedProvider([new SendResult(SendResult::UNCERTAIN, null, 'rede_28', 'timeout')]));
    $t->run('2026-11-10 10:00:00');
    $m = $t->messages()[1];
    eq('incerto', $m['status'], 'incerto');
    [$raw, $sig] = signed($t, statusPayload('wamid.REF', 'delivered', 1794300100, ['biz_opaque_callback_data' => $m['ref']]));
    $t->pdo()->exec("UPDATE gr_mensagens SET provedor='meta_cloud' WHERE id=" . (int)$m['id']);
    $hook->handlePost($raw, $sig);
    $m = $t->messages()[1];
    eq('entregue', $m['status'], 'incerto esclarecido pelo callback');
    eq('wamid.REF', $m['provedor_msg_id'], 'ID gravado');
});

test('webhook: assinatura inválida rejeitada e verificação GET', function (T $t) {
    $hook = new Webhook($t->app);
    [$raw] = signed($t, statusPayload('wamid.X', 'delivered', 1));
    eq(401, $hook->handlePost($raw, 'sha256=' . str_repeat('0', 64))[0], 'assinatura errada');
    eq(401, $hook->handlePost($raw, null)[0], 'sem assinatura');
    eq(401, $hook->handlePost($raw . ' ', 'sha256=' . hash_hmac('sha256', $raw, 'segredo-teste'))[0], 'corpo alterado');
    eq([200, 'abc123'], $hook->verify(['hub_mode' => 'subscribe', 'hub_verify_token' => 'verifica-teste', 'hub_challenge' => 'abc123']), 'desafio ok');
    eq(403, $hook->verify(['hub_mode' => 'subscribe', 'hub_verify_token' => 'errado', 'hub_challenge' => 'abc'])[0], 'token errado');
    eq(403, $hook->verify(['hub_mode' => 'subscribe', 'hub_verify_token' => 'verifica-teste', 'hub_challenge' => '<script>'])[0], 'desafio malicioso');
});

test('descadastro por resposta SAIR revoga a autorização', function (T $t) {
    $c = $t->client('Sair');
    $t->consent($c);
    $payload = ['entry' => [['changes' => [['field' => 'messages', 'value' => ['messages' => [['from' => '5531999990001', 'id' => 'wamid.IN1', 'type' => 'text', 'text' => ['body' => ' sair! ']]]]]]]]];
    [$raw, $sig] = signed($t, $payload);
    (new Webhook($t->app))->handlePost($raw, $sig);
    (new Webhook($t->app))->handlePost($raw, $sig); // repetido
    eq('revogado', (new Contacts($t->app->db(), $t->clock))->get($c)['consentimento'], 'revogado');
    eq(1, (int)$t->app->db()->value("SELECT COUNT(*) FROM gr_consentimento_log WHERE acao='revogado'"), 'registrado uma vez');
    eq(false, Contacts::isOptOut('pode me mandar a chave pix de novo?'), 'frase comum não descadastra');
});

test('classificação de respostas da Meta', function (T $t) {
    $k = fn(array $r) => MetaCloudProvider::classify($r + ['errno' => 0, 'http' => 0, 'body' => '', 'sent' => true])->kind;
    eq('aceito', $k(['http' => 200, 'body' => '{"messages":[{"id":"wamid.1"}]}']), '200 com id');
    eq('incerto', $k(['http' => 200, 'body' => '{}']), '200 sem id');
    eq('incerto', $k(['errno' => 28, 'sent' => true]), 'timeout após envio');
    eq('transitorio', $k(['errno' => 28, 'sent' => false]), 'timeout de conexão');
    eq('transitorio', $k(['errno' => 7, 'sent' => false]), 'sem conexão');
    eq('incerto', $k(['http' => 502, 'body' => '']), '5xx');
    eq('transitorio', $k(['http' => 429, 'body' => '{"error":{"code":130429}}']), 'limite');
    eq('permanente', $k(['http' => 400, 'body' => '{"error":{"code":131026,"message":"x"}}']), 'inalcançável');
    $r = MetaCloudProvider::classify(['errno' => 0, 'http' => 401, 'body' => '{"error":{"code":190,"message":"Invalid token EAAabcdefghijklmnop"}}', 'sent' => true]);
    check($r->credentialError && strpos((string)$r->detail, 'EAAabc') === false, 'credencial: sinaliza e oculta token');
});

test('payload do modelo da Meta (sem rede)', function (T $t) {
    $calls = [];
    $p = new MetaCloudProvider(['graph_versao' => 'v24.0', 'phone_number_id' => '123', 'access_token' => 'EAAsegredo123456789', 'enviar_ref_callback' => true], $t->app->log(),
        function ($url, $json, $headers) use (&$calls) {
            $calls[] = [$url, json_decode($json, true), $headers];
            return ['errno' => 0, 'http' => 200, 'body' => '{"messages":[{"id":"wamid.Z"}]}', 'sent' => true];
        });
    $r = $p->send(new \GmaxRenov\Provider\OutboundMessage('5531999990001', 'texto', 'gmax_lembrete_antes', 'pt_BR', ['Ana', 'Pacote', '13/11/2026', 'R$ 10,00', 'Pix'], 'grREF'));
    eq('aceito', $r->kind, 'aceito');
    eq('https://graph.facebook.com/v24.0/123/messages', $calls[0][0], 'URL');
    eq('template', $calls[0][1]['type'], 'tipo modelo');
    eq('gmax_lembrete_antes', $calls[0][1]['template']['name'], 'nome do modelo');
    eq(5, count($calls[0][1]['template']['components'][0]['parameters']), 'parâmetros');
    eq('grREF', $calls[0][1]['biz_opaque_callback_data'], 'referência');
});

echo "Modo simulação e liberação do envio real\n";

test('modo simulação nunca usa provedor real', function (T $t) {
    $app = new \GmaxRenov\App($t->config(), $t->clock);
    check(!$app->provider()->isReal(), 'driver simulado → simulado');
    $app2 = new \GmaxRenov\App($t->config(['provedor' => ['driver' => 'meta_cloud']]), $t->clock);
    check(!$app2->provider()->isReal(), 'meta_cloud + modo simulação → simulado');
    $s = $app2->settings();
    $s->set('modo', 'real');
    check(!$app2->provider()->isReal(), 'sem confirmação do Pix → simulado');
    $s->set('pagamento_confirmado', true);
    check(!$app2->provider()->isReal(), 'sem modelos aprovados → simulado');
    $s->set('modelos', ['antes' => ['individual' => 'a', 'grupo' => 'b'], 'dia' => ['individual' => 'c', 'grupo' => 'd'], 'depois' => ['individual' => 'e', 'grupo' => 'f']]);
    check($app2->provider()->isReal(), 'tudo configurado → real');
    eq('real', $app2->effectiveMode(), 'modo efetivo real');
});

test('modo real com "somente números de teste" bloqueia clientes', function (T $t) {
    $app = new \GmaxRenov\App($t->config(['provedor' => ['driver' => 'meta_cloud']]), $t->clock);
    $s = $app->settings();
    $s->set('modo', 'real');
    $s->set('pagamento_confirmado', true);
    $s->set('modelos', ['antes' => ['individual' => 'a', 'grupo' => 'b'], 'dia' => ['individual' => 'c', 'grupo' => 'd'], 'depois' => ['individual' => 'e', 'grupo' => 'f']]);
    $s->set('numeros_teste', ['31999990009']);
    $prov = new SimulatedProvider(); // substitui o real: nenhuma chamada de rede no teste
    $app->setProvider($prov);
    $a = $t->client('Cliente Real', '31999990001');
    $b = $t->client('Contato Interno', '31999990009');
    $t->consent($a, '31999990001');
    $t->consent($b, '31999990009');
    $t->package($a, 'PA', '2026-11-13', '10.00');
    $t->package($b, 'PB', '2026-11-13', '10.00');
    (new Engine($app))->run('teste');
    eq(1, count($prov->sent), 'só o contato interno');
    eq('5531999990009', $prov->sent[0]->to, 'número de teste');
    eq('somente_teste', $app->db()->value('SELECT motivo FROM gr_lembretes WHERE pacote_id=?', [$app->db()->value("SELECT id FROM client_plans WHERE label='PA'")]), 'cliente bloqueado');
});

test('mensagem de teste exige número da lista interna', function (T $t) {
    $e = new Engine($t->app);
    $failed = false;
    try {
        $e->sendTest('31999990001', 'antes');
    } catch (InvalidArgumentException $ex) {
        $failed = true;
    }
    check($failed, 'número fora da lista recusado');
    $t->app->settings()->set('numeros_teste', ['5531999990001']);
    $r = $e->sendTest('(31) 99999-0001', 'dia', true);
    eq('aceito', $r['status'], 'teste simulado aceito');
    eq(false, $r['real'], 'não é real');
});

echo "Validação de textos, migração e preservação financeira\n";

test('validação dos textos editáveis', function (T $t) {
    check(Templates::validate('Olá {nome}', false) === [], 'texto simples ok');
    check(Templates::validate('Acesse https://lista.com', false) !== [], 'URL recusada');
    check(Templates::validate('Sua senha é {senha}', false) !== [], 'senha recusada');
    check(Templates::validate('Olá {nome}, total {total}', true) !== [], 'grupo sem lista recusado');
    check(Templates::validate('Olá {codigo_acesso}', false) !== [], 'campo desconhecido');
});

test('migração reaplicável sem perder configurações', function (T $t) {
    $t->app->settings()->set('chave_pix', 'outra@exemplo.com');
    $c = $t->client('Mig');
    $t->consent($c);
    $m = new Migrator($t->app->db(), $t->clock);
    eq([], $m->migrate(), 'nada a aplicar');
    $t->pdo()->exec("DELETE FROM gr_migracoes");
    eq(['001_inicial'], $m->migrate(), 'reaplicada após remover registro');
    $t->app->settings()->refresh();
    eq('outra@exemplo.com', $t->app->settings()->get('chave_pix'), 'configuração preservada');
    eq('concedido', (new Contacts($t->app->db(), $t->clock))->get($c)['consentimento'], 'consentimento preservado');
    eq(1, (int)$t->app->db()->value('SELECT COUNT(*) FROM gr_contatos'), 'sem duplicação');
});

test('histórico financeiro e cadastros preservados (somente leitura)', function (T $t) {
    $c = $t->client('Financeiro');
    $t->consent($c);
    $p1 = $t->package($c, 'P1', '2026-11-13', '120.00', ['pontos' => 4, 'custo' => '70.00']);
    $p2 = $t->package($c, 'P2', '2026-11-10', '35.00');
    $t->renew($c, $p2, '2026-11-10', 3500); // lançamento real com entry_plans
    $pdo = $t->pdo();
    $pdo->exec("INSERT INTO entries (id,group_id,client_id,kind,amount_cents,date,description,created_at) VALUES ('e-c','g-c','$c','cost',7000,'2026-10-13','Custo','x'),('e-x','g-x',NULL,'expense',3000,'2026-10-01','Despesa','x')");
    $pdo->exec("INSERT INTO settings (id,target_cents,default_price_cents,default_cost_cents) VALUES (1,100000,3500,1000)");
    $before = (new \GmaxRenov\Integrity($t->app->db()))->snapshot();
    $t->run();
    $t->run('2026-11-10 12:00:00');
    $t->app->settings()->set('numeros_teste', ['31999990001']);
    (new Engine($t->app))->sendTest('31999990001', 'antes');
    (new Contacts($t->app->db(), $t->clock))->revoke($c, 'painel');
    $t->run('2026-11-11 09:30:00');
    (new Migrator($t->app->db(), $t->clock))->migrate();
    $after = (new \GmaxRenov\Integrity($t->app->db()))->snapshot();
    $cmp = \GmaxRenov\Integrity::compare($before, $after);
    check($cmp['iguais'], 'retratos iguais: ' . implode(' | ', $cmp['linhas']));
    eq(3500 - 7000 - 3000, $after['financeiro']['lucro_centavos'], 'lucro inalterado');
    eq(3, $after['tabelas']['entries']['linhas'], 'nenhuma receita registrada pelo envio');
    eq('2026-11-13', $pdo->query("SELECT due_date FROM client_plans WHERE id='$p1'")->fetchColumn(), 'vencimento não prorrogado');
});

test('código do módulo não escreve em tabelas do painel', function (T $t) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(GR_TEST_SRC . '/lib'));
    foreach ($files as $f) {
        if ($f->getExtension() !== 'php') {
            continue;
        }
        $src = file_get_contents($f->getPathname());
        check(!preg_match('/(?<!KEY )(INSERT\s+(IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE)\s+`?(?!gr_)[a-z]/i', $src, $mm), 'escrita fora de gr_* em ' . $f->getFilename() . ': ' . ($mm[0] ?? ''));
    }
});

echo "Correções da revisão de segurança\n";

test('descadastro: wa_id sem o 9º dígito, frases curtas, outro número ignorado, respostas registradas', function (T $t) {
    $c = $t->client('Nove');
    $t->consent($c); // 5531999990001
    $mk = fn(string $from, string $text, string $id, string $pnid = '123') => ['entry' => [['changes' => [['field' => 'messages', 'value' => [
        'metadata' => ['phone_number_id' => $pnid], 'messages' => [['from' => $from, 'id' => $id, 'type' => 'text', 'text' => ['body' => $text]]]]]]]]];
    $hook = new Webhook($t->app);
    [$raw, $sig] = signed($t, $mk('553199990001', 'Quero sair', 'w1', '999'));
    $hook->handlePost($raw, $sig);
    eq('concedido', (new Contacts($t->app->db(), $t->clock))->get($c)['consentimento'], 'evento de outro phone_number_id ignorado');
    [$raw, $sig] = signed($t, $mk('553199990001', 'segue o comprovante do pix', 'w2'));
    $hook->handlePost($raw, $sig);
    eq('concedido', (new Contacts($t->app->db(), $t->clock))->get($c)['consentimento'], 'mensagem comum não descadastra');
    eq(1, (int)$t->app->db()->value("SELECT COUNT(*) FROM gr_eventos WHERE tipo='recebida'"), 'resposta registrada');
    [$raw, $sig] = signed($t, $mk('553199990001', 'Não quero mais receber', 'w3'));
    $hook->handlePost($raw, $sig);
    eq('revogado', (new Contacts($t->app->db(), $t->clock))->get($c)['consentimento'], 'revogado mesmo com wa_id sem o 9');
    check(Contacts::isOptOut('quero sair'), '"quero sair"');
    check(Contacts::isOptOut('PARE'), '"PARE"');
    check(!Contacts::isOptOut('Vou sair de casa agora e depois te mando o comprovante do pagamento, ok?'), 'frase longa não');
    eq(['5531999990001', '553199990001'], Format::phoneVariants('5531999990001'), 'variantes do celular');
});

test('registrar envio manual antes das 09h impede o envio automático', function (T $t) {
    $c = $t->client('Manual Cedo');
    $t->consent($c);
    $t->package($c, 'P', '2026-11-13', '10.00');
    $t->clock->set('2026-11-10 08:00:00');
    $id = (new Engine($t->app))->manualForPending($c, '2026-11-13', 'antes');
    check($id > 0, 'registrado');
    $t->run('2026-11-10 09:30:00');
    eq(['manual'], array_column($t->messages(), 'status'), 'nenhum envio automático');
    eq(0, count($t->provider->sent), 'provedor não chamado');
});

test('decisão manual recusada se o estado mudou no meio', function (T $t) {
    $c = $t->client('Corrida');
    $t->consent($c);
    $t->package($c, 'P', '2026-11-13', '10.00');
    $t->app->setProvider(new SimulatedProvider([new SendResult(SendResult::UNCERTAIN, null, 'rede_28', 'timeout')]));
    $t->run();
    $id = (int)$t->messages()[0]['id'];
    $t->pdo()->exec("UPDATE gr_mensagens SET status='entregue' WHERE id=$id"); // callback chegou antes
    $failed = false;
    try {
        (new Engine($t->app))->resolve($id, 'marcar_falhou');
    } catch (InvalidArgumentException $e) {
        $failed = true;
    }
    check($failed, 'recusado');
    eq('entregue', $t->messages()[0]['status'], 'status preservado');
});

test('instruções de pagamento e rodapé não aceitam links nem senhas', function (T $t) {
    $api = new \GmaxRenov\Api($t->app);
    $r = $api->handle('salvar_config', ['instrucoes_pagamento' => 'Pague em bit.ly/abc']);
    eq(false, $r['ok'], 'link encurtado recusado');
    $r = $api->handle('salvar_config', ['rodape' => 'Sua senha continua a mesma']);
    eq(false, $r['ok'], 'senha recusada');
    $r = $api->handle('salvar_config', ['instrucoes_pagamento' => 'Pix (chave e-mail): gumaxskins@gmail.com']);
    eq(true, $r['ok'], 'e-mail como chave aceito');
});

echo "Correções do teste independente\n";

test('API rejeita formatos inválidos e cliente inexistente', function (T $t) {
    $api = new \GmaxRenov\Api($t->app);
    foreach ([['pausa_cliente', ['cliente_id' => 999999, 'pausado' => true]], ['pausa_cliente', []],
              ['historico', ['status' => ['x']]], ['clientes', ['q' => ['a']]], ['enviar_teste', ['numero' => [1]]],
              ['salvar_config', ['textos' => ['antes' => ['individual' => ['a']]]]]] as [$action, $in]) {
        $failed = false;
        try {
            $api->handle($action, $in);
        } catch (InvalidArgumentException $e) {
            $failed = true;
        }
        check($failed, "$action recusado com entrada inválida");
    }
    eq(0, (int)$t->app->db()->value('SELECT COUNT(*) FROM gr_contatos'), 'nenhum contato criado');
    eq(false, strpos($t->app->settings()->get('textos')['antes']['individual'], 'Array') === 0, 'texto não virou "Array"');
});

test('prévias usam a data coerente com cada etapa', function (T $t) {
    $api = new \GmaxRenov\Api($t->app);
    $tx = $t->app->settings()->get('textos');
    $r = $api->handle('previa', ['etapa' => 'dia', 'variante' => 'individual', 'texto' => $tx['dia']['individual']]);
    check(strpos($r['corpo'], 'vence hoje, 10/11/2026') !== false, 'no dia: hoje');
    $r = $api->handle('previa', ['etapa' => 'depois', 'variante' => 'individual', 'texto' => $tx['depois']['individual']]);
    check(strpos($r['corpo'], 'foi ontem, 09/11/2026') !== false, 'depois: ontem');
    $r = $api->handle('previa', ['etapa' => 'antes', 'variante' => 'individual', 'texto' => $tx['antes']['individual']]);
    check(strpos($r['corpo'], 'vence em 13/11/2026, daqui a 3 dias') !== false, 'antes: +3');
});

echo "Integração com a estrutura real do painel GMAX\n";

test('instalação em banco com dados existentes: estrutura, IDs, conteúdo e totais idênticos; reaplicar não muda nada', function (T $t) {
    $db = $t->app->db();
    // Simula banco em uso ANTES do módulo: remove as tabelas gr_* criadas pelo harness.
    foreach ($db->all("SELECT TABLE_NAME t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'gr\\_%'") as $r) {
        $db->pdo->exec('DROP TABLE `' . $r['t'] . '`');
    }
    for ($i = 0; $i < 30; $i++) {
        $c = $t->client("Cliente $i", '3199999' . str_pad((string)$i, 4, '0', STR_PAD_LEFT), ['status' => ['ativo', 'pausado', 'arquivado'][$i % 3]]);
        for ($j = 0; $j <= $i % 4; $j++) {
            $p = $t->package($c, "P$j", '2026-11-' . str_pad((string)(10 + $j), 2, '0', STR_PAD_LEFT), (string)(30 + $j * 5) . '.00', ['pontos' => $j + 1, 'ciclo' => ['mensal', 'trimestral', 'semestral', 'anual'][$j]]);
            if ($j === 0) {
                $t->renew($c, $p, '2026-11-10', 3000);
            }
        }
        if ($i % 7 === 0) {
            $t->exclude($c);
        }
    }
    $t->pdo()->exec("INSERT INTO settings (id,target_cents,default_price_cents,default_cost_cents) VALUES (1,100000,3500,1000)");
    $integ = new \GmaxRenov\Integrity($db);
    $before = $integ->snapshot();
    $m = new Migrator($db, $t->clock);
    eq(['001_inicial'], $m->migrate(), 'migração aplicada');
    $after1 = $integ->snapshot();
    eq([], $m->migrate(), 'reaplicar: nada a fazer');
    (new Migrator($db, $t->clock))->migrate();
    $after2 = $integ->snapshot();
    foreach ([$after1, $after2] as $k => $snap) {
        $cmp = \GmaxRenov\Integrity::compare($before, $snap);
        check($cmp['iguais'], "retrato $k igual ao anterior: " . implode(' | ', $cmp['linhas']));
    }
    eq(30, $after2['tabelas']['clients']['linhas'], '30 clientes');
    eq(5, $after2['tabelas']['client_exclusions']['linhas'], 'exclusões preservadas');
    eq(8, (int)$db->value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'gr\\_%'"), 'só 8 tabelas gr_* novas');
    eq(0, (int)$db->value('SELECT COUNT(*) FROM gr_contatos'), 'nenhum cliente autorizado automaticamente');
    $json = json_encode($after2);
    check(strpos($json, 'Cliente 1') === false && strpos($json, '31999990') === false, 'retrato sem dados pessoais');
});

test('cliente com 4 pacotes: renovar 1 pelo fluxo do painel altera só os avisos desse pacote', function (T $t) {
    $c = $t->client('Quatro Pacotes');
    $t->consent($c);
    $ids = [];
    foreach (['A', 'B', 'C', 'D'] as $i => $n) {
        $ids[$n] = $t->package($c, "Pacote $n", $i < 2 ? '2026-11-13' : '2026-11-10', '25.00');
    }
    $t->app->settings()->set('pausa_global', true);
    $t->run();
    eq(4, count(array_filter($t->reminders(), fn($r) => $r['status'] === 'pendente')), '4 lembretes pendentes (2 antes, 2 no dia)');
    $t->renew($c, $ids['A'], '2026-12-13'); // receita + entry_plans + due_date só do pacote A
    $t->app->settings()->set('pausa_global', false);
    $t->run('2026-11-10 10:00:00');
    $byPkg = [];
    foreach ($t->reminders() as $r) {
        $byPkg[$r['pacote_id']] = $r['status'] . ($r['motivo'] ? ':' . $r['motivo'] : '');
    }
    eq('cancelado:renovado', $byPkg[$ids['A']], 'A cancelado');
    eq('em_mensagem', $byPkg[$ids['B']], 'B enviado');
    eq('em_mensagem', $byPkg[$ids['C']], 'C enviado');
    eq('em_mensagem', $byPkg[$ids['D']], 'D enviado');
    $m = $t->messages();
    eq(2, count($m), 'duas mensagens: B (3 dias antes) e C+D agrupados (vence hoje)');
    $bodies = implode("\n", array_column($m, 'corpo'));
    check(strpos($bodies, 'Pacote A') === false, 'pacote renovado não aparece');
});

test('recebimento SEM nova data não conta como renovação; dados legados do cliente são ignorados', function (T $t) {
    $c = $t->client('Legado'); // clients.due_date=2026-01-01 e price=999,00 (legados)
    $t->consent($c);
    $p = $t->package($c, 'Pacote Real', '2026-11-13', '35.00');
    $t->pdo()->exec("INSERT INTO entries (id,group_id,client_id,kind,amount_cents,date,description,created_at) VALUES ('e1','g1','$c','revenue',3500,'2026-11-10','Pix','x')");
    $t->pdo()->exec("INSERT INTO entry_plans (group_id, plan_id) VALUES ('g1','$p')");
    $t->pdo()->exec("UPDATE clients SET due_date='2026-11-13' WHERE id='$c'");
    $t->run();
    $m = $t->messages();
    eq(1, count($m), 'aviso mantido (vencimento do pacote não mudou)');
    check(strpos($m[0]['corpo'], 'R$ 35,00') !== false && strpos($m[0]['corpo'], '999') === false, 'valor do pacote, não o legado do cliente');
    $c2 = $t->client('Só Legado', '31999990002');
    $t->consent($c2, '31999990002');
    $t->package($c2, 'Outro', '2026-12-20', '35.00');
    $t->pdo()->exec("UPDATE clients SET due_date='2026-11-13' WHERE id='$c2'");
    $t->run('2026-11-10 10:00:00');
    eq(1, count($t->messages()), 'clients.due_date legado não gera aviso');
});

test('preço e telefone alterados entre geração e envio: revalidação usa os dados atuais', function (T $t) {
    $a = $t->client('Preço Muda', '31999990001');
    $b = $t->client('Fone Muda', '31999990002');
    $t->consent($a, '31999990001');
    $t->consent($b, '31999990002');
    $pa = $t->package($a, 'PA', '2026-11-13', '35.00');
    $t->package($b, 'PB', '2026-11-13', '35.00');
    $t->app->settings()->set('pausa_global', true);
    $t->run();
    $t->pdo()->exec("UPDATE client_plans SET price_cents=4200 WHERE id='$pa'");
    $t->pdo()->exec("UPDATE clients SET phone='31988880000' WHERE id='$b'");
    $t->app->settings()->set('pausa_global', false);
    $t->run('2026-11-10 10:00:00');
    $m = $t->messages();
    eq(1, count($m), 'só o cliente com telefone inalterado');
    check(strpos($m[0]['corpo'], 'R$ 42,00') !== false, 'preço atualizado na mensagem');
    eq('telefone_mudou', $t->app->db()->value("SELECT motivo FROM gr_lembretes WHERE cliente_id=?", [$b]), 'telefone mudou exige nova autorização');
});

test('cliente excluído (client_exclusions) nunca é selecionado nem listado; restauração é do painel', function (T $t) {
    $c = $t->client('Na Lixeira');
    $t->consent($c);
    $t->package($c, 'P', '2026-11-13', '35.00');
    $t->exclude($c);
    $t->run();
    eq(0, count($t->messages()), 'sem mensagem');
    eq('cliente_excluido', $t->reminders()[0]['motivo'], 'motivo');
    $list = (new \GmaxRenov\Api($t->app))->handle('clientes', []);
    eq(0, count($list['itens']), 'fora da lista de autorizações');
    eq(1, (int)$t->pdo()->query('SELECT COUNT(*) FROM client_exclusions')->fetchColumn(), 'continua excluído');
});

test('patch do menu: insere, recusa repetir, reverte byte a byte e recusa index desconhecido', function (T $t) {
    $base = getenv('GR_PAINEL_BASE') ?: '/tmp/claude-0/-home-user-gumax2/4551f546-0fe0-5e94-ba09-9252bba9ea1c/scratchpad/base/gmax-base-painel';
    $file = $base . '/painel/index.html';
    $html = is_file($file) ? file_get_contents($file)
        : '<html><head></head><body><nav><button data-page="overview" class="nav active">Visão</button><button data-page="settings" class="nav">Config</button></nav></body></html>';
    $p = \GmaxRenov\PanelMenuPatch::plan($html);
    check($p['ok'], 'aplicável');
    check(strpos($p['novo'], 'href="renovacoes.php"') !== false && strpos($p['novo'], 'menu.css?v=1') !== false, 'link e css');
    check(strpos($p['novo'], 'app.js?v=vencimentos-3') === strpos($html, 'app.js?v=vencimentos-3') || strpos($html, 'app.js') === false || strpos($p['novo'], 'app.js?v=vencimentos-3') !== false, 'app.js intocado');
    eq(false, \GmaxRenov\PanelMenuPatch::plan($p['novo'])['ok'], 'não aplica duas vezes');
    eq($html, \GmaxRenov\PanelMenuPatch::revert($p['novo']), 'reversão exata');
    eq(false, \GmaxRenov\PanelMenuPatch::plan('<html><head></head><body>sem menu</body></html>')['ok'], 'index desconhecido: não altera');
});

test('controle negativo: a verificação de integridade detecta 1 centavo, 1 exclusão e 1 coluna nova', function (T $t) {
    $c = $t->client('Controle');
    $p = $t->package($c, 'P', '2026-11-13', '35.00');
    $i = new \GmaxRenov\Integrity($t->app->db());
    $base = $i->snapshot();
    $t->pdo()->exec("UPDATE client_plans SET price_cents=price_cents+1 WHERE id='$p'");
    eq(false, \GmaxRenov\Integrity::compare($base, $i->snapshot())['iguais'], '1 centavo detectado');
    $t->pdo()->exec("UPDATE client_plans SET price_cents=price_cents-1 WHERE id='$p'");
    eq(true, \GmaxRenov\Integrity::compare($base, $i->snapshot())['iguais'], 'volta a ficar igual');
    $t->exclude($c);
    eq(false, \GmaxRenov\Integrity::compare($base, $i->snapshot())['iguais'], 'exclusão detectada');
    $t->pdo()->exec("DELETE FROM client_exclusions");
    $t->pdo()->exec("ALTER TABLE clients ADD COLUMN extra INT NULL");
    $r = \GmaxRenov\Integrity::compare($base, $i->snapshot());
    eq(false, $r['iguais'], 'mudança de estrutura detectada');
    check(strpos(implode(' ', $r['linhas']), 'estrutura mudou') !== false, 'motivo informado');
});

test('migrações e SQL de instalação: só CREATE TABLE IF NOT EXISTS gr_* e INSERT IGNORE em gr_*', function (T $t) {
    foreach (glob(GR_TEST_SRC . '/migrations/*.php') as $f) {
        $m = require $f;
        foreach ($m['sql'] as $sql) {
            check((bool)preg_match('/^\s*CREATE TABLE IF NOT EXISTS gr_[a-z_]+\s*\(/', $sql), basename($f) . ': comando não permitido: ' . substr(trim($sql), 0, 60));
        }
    }
    $sql = file_get_contents(dirname(__DIR__) . '/sql/001_inicial.sql');
    $stmts = array_filter(array_map('trim', explode(";\n", preg_replace('/^--.*$/m', '', $sql))));
    foreach ($stmts as $st) {
        check((bool)preg_match('/^(SET NAMES utf8mb4|CREATE TABLE IF NOT EXISTS gr_[a-z_]+|INSERT IGNORE INTO gr_[a-z_]+)\b/', $st), 'sql/001_inicial.sql: ' . substr($st, 0, 60));
    }
    check(!preg_match('/\b(DROP|TRUNCATE|DELETE|ALTER|UPDATE|REPLACE)\b/i', $sql), 'sem comandos destrutivos');
});

echo "Correções da revisão de segurança — rodada 2\n";

test('R2-1: banco com a versão antiga (IDs numéricos) é recusado sem alterar nada', function (T $t) {
    $db = $t->app->db();
    $db->pdo->exec('ALTER TABLE gr_contatos MODIFY cliente_id BIGINT NOT NULL'); // simula instalação 1.0.0
    $db->pdo->exec("UPDATE gr_migracoes SET checksum = REPEAT('0', 64) WHERE versao = '001'");
    $failed = false;
    try {
        (new Migrator($db, $t->clock))->migrate();
    } catch (RuntimeException $e) {
        $failed = strpos($e->getMessage(), 'gr_contatos.cliente_id') !== false;
    }
    check($failed, 'migração recusada apontando a coluna');
});

test('R2-2: envio manual registrado em simulação impede o envio automático no modo real', function (T $t) {
    $c = $t->client('Manual Sim');
    $t->consent($c);
    $t->package($c, 'P', '2026-11-13', '10.00');
    $t->clock->set('2026-11-10 08:00:00');
    (new Engine($t->app))->manualForPending($c, '2026-11-13', 'antes'); // modo efetivo: simulação
    $app = new \GmaxRenov\App($t->config(['provedor' => ['driver' => 'meta_cloud']]), $t->clock);
    $s = $app->settings();
    $s->set('modo', 'real');
    $s->set('pagamento_confirmado', true);
    $s->set('somente_numeros_teste', false);
    $s->set('modelos', ['antes' => ['individual' => 'a', 'grupo' => 'b'], 'dia' => ['individual' => 'c', 'grupo' => 'd'], 'depois' => ['individual' => 'e', 'grupo' => 'f']]);
    $prov = new SimulatedProvider(); // substitui o real: nenhuma rede no teste
    $app->setProvider($prov);
    $t->clock->set('2026-11-10 09:30:00');
    (new Engine($app))->run('teste');
    eq(0, count($prov->sent), 'nada enviado no modo real');
    eq('enviado_manual', $app->db()->value("SELECT motivo FROM gr_lembretes WHERE modo='real'"), 'motivo registrado');
});

test('R2-3: vencimento inválido no painel não derruba a aba e é contado', function (T $t) {
    $c = $t->client('Data Ruim');
    $t->package($c, 'Ok', '2026-11-12', '10.00');
    $t->package($c, 'Ruim', '2026-11-3x', '10.00');
    $t->package($c, 'Fev30', '2026-02-30', '10.00');
    $r = (new \GmaxRenov\Api($t->app))->handle('painel', []);
    eq(2, $r['resumo']['datas_invalidas'], 'duas datas inválidas contadas');
    eq(1, $r['resumo']['proximos'], 'pacote válido listado');
});

test('R2-4: simulação nunca aparece como "Aceito pelo provedor"', function (T $t) {
    $c = $t->client('Rotulo');
    $t->consent($c);
    $t->package($c, 'P', '2026-11-13', '10.00');
    $t->run();
    $h = (new \GmaxRenov\Api($t->app))->handle('historico', []);
    eq('Simulado — nada foi enviado', $h['itens'][0]['status_texto'], 'rótulo honesto');
    eq('Aceito pelo provedor', \GmaxRenov\Statuses::label('aceito', 'real'), 'modo real mantém o rótulo');
});

test('CLI: integridade antes/depois + migrar + comparar, e menu recusa modo inválido', function (T $t) {
    $db = $t->app->db();
    foreach ($db->all("SELECT TABLE_NAME t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'gr\\_%'") as $r) {
        $db->pdo->exec('DROP TABLE `' . $r['t'] . '`');
    }
    $c = $t->client('CLI');
    $t->renew($c, $t->package($c, 'P', '2026-11-13', '35.00'), '2026-11-13', 3500);
    $cfg = $t->writeConfig();
    $dir = sys_get_temp_dir() . '/gr-cli-' . $t->dbName;
    @mkdir($dir, 0700);
    $admin = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(GR_TEST_SRC . '/bin/renovacoes-admin.php');
    $env = 'GR_CONFIG=' . escapeshellarg($cfg) . ' ';
    exec($env . "$admin integridade $dir/antes.json 2>&1", $o1, $c1);
    exec($env . "$admin migrar 2>&1", $o2, $c2);
    exec($env . "$admin migrar 2>&1", $o3, $c3);
    exec($env . "$admin integridade $dir/depois.json 2>&1", $o4, $c4);
    exec($env . "$admin integridade-comparar $dir/antes.json $dir/depois.json 2>&1", $o5, $c5);
    eq([0, 0, 0, 0, 0], [$c1, $c2, $c3, $c4, $c5], 'comandos ok: ' . implode(' | ', array_merge($o2, $o5)));
    check(strpos(implode("\n", $o5), 'dados do painel preservados') !== false, 'comparativo aprovado');
    eq('600', substr(sprintf('%o', fileperms("$dir/antes.json")), -3), 'arquivo com permissão 600');
    $idx = "$dir/index.html";
    file_put_contents($idx, '<html><head></head><body><nav><button data-page="a" class="nav">A</button></nav></body></html>');
    exec($env . "$admin menu aplicr " . escapeshellarg($idx) . ' 2>&1', $o6, $c6);
    eq(1, $c6, 'modo com erro de digitação recusado');
    check(strpos(file_get_contents($idx), 'renovacoes') === false, 'index.html intocado');
    exec($env . "$admin menu aplicar " . escapeshellarg($idx) . ' 2>&1', $o7, $c7);
    eq(0, $c7, 'aplicado');
    check(count(glob("$idx.antes-renovacoes-*")) === 1, 'cópia de segurança criada');
    array_map('unlink', glob("$dir/*"));
    rmdir($dir);
});

echo "Ajustes da revisão do Codex (caminho da hospedagem e .htaccess)\n";

test('documentação e exemplos usam o caminho real do painel (gmaxcanais.com.br/painel), nunca public_html', function (T $t) {
    $root = dirname(__DIR__);
    $files = array_merge([$root . '/LEIA_PRIMEIRO.md'], glob($root . '/docs/*.md'), glob($root . '/patch-painel/*'),
        iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS))));
    foreach ($files as $f) {
        $f = (string)$f;
        if (basename($f) === 'REVISAO_SEGURANCA.md' || basename($f) === 'TESTES_RESULTADO.md') {
            continue; // registros históricos
        }
        $s = file_get_contents($f);
        check(stripos($s, 'public_html') === false, 'public_html em ' . basename($f));
        check(strpos($s, '/home/USUARIO') === false, '/home/USUARIO em ' . basename($f));
    }
    foreach (['docs/INSTALACAO.md', 'docs/HTACCESS.md', 'patch-painel/LEIA.md', 'docs/BACKUP_E_RECUPERACAO.md'] as $d) {
        check(strpos(file_get_contents("$root/$d"), '/home1/gust1922/gmaxcanais.com.br/painel') !== false, "caminho do painel em $d");
    }
    check(strpos(file_get_contents("$root/docs/INSTALACAO.md"), '/home1/gust1922/gmax-painel/private/config.php') !== false, 'config privado do painel');
    // As páginas públicas localizam a biblioteca em dirname(painel, 2)/gmax-renovacoes.
    eq('/home1/gust1922/gmax-renovacoes', dirname('/home1/gust1922/gmaxcanais.com.br/painel', 2) . '/gmax-renovacoes', 'localização padrão da biblioteca');
    foreach (['renovacoes.php', 'renovacoes-api.php', 'renovacoes-webhook.php'] as $p) {
        check(strpos(file_get_contents("$root/src/publico/painel/$p"), "dirname(__DIR__, 2) . '/gmax-renovacoes'") !== false, "$p usa a localização padrão");
    }
});

test('.htaccess: 4 formatos liberam só os 3 arquivos, reversão exata; sem bloqueio não muda; desconhecido recusa', function (T $t) {
    $H = \GmaxRenov\HtaccessPatch::class;
    $cases = [
        'files24' => ["Options -Indexes\n<FilesMatch \"\\.php$\">\n  Require all denied\n</FilesMatch>\n<Files \"api.php\">\n  Require all granted\n</Files>\n", '', 'Files'],
        'files22' => ["<FilesMatch \"\\.(php|phtml)$\">\nOrder allow,deny\nDeny from all\n</FilesMatch>\n<FilesMatch \"^api\\.php$\">\nOrder allow,deny\nAllow from all\n</FilesMatch>", '', 'Files'], // sem quebra final
        'rewrite' => ["RewriteEngine On\nRewriteCond %{REQUEST_URI} !/api\\.php$\nRewriteRule \\.php$ - [F,L]\n", '', 'Rewrite'],
        'raiz' => ["RewriteEngine On\nRewriteRule ^painel/(?!api\\.php$).*\\.php$ - [F]\n", 'painel', 'Rewrite'],
    ];
    foreach ($cases as $k => [$h, $rel, $fmt]) {
        $r = $H::analyze($h, $rel);
        eq($fmt, $r['formato'], "$k: formato");
        eq(\GmaxRenov\HtaccessPatch::FILES, $r['bloqueia'], "$k: detecta os 3 bloqueados");
        check($r['plano'] !== null && strpos($r['plano'], $h === '' ? '' : trim(explode("\n", $h)[1])) !== false, "$k: regras originais mantidas");
        check(strpos($r['plano'], 'Require all granted' . "\n</FilesMatch>\n<FilesMatch") === false, "$k: nada além do bloco marcado");
        $after = $H::analyze($r['plano'], $rel);
        eq([], $after['bloqueia'], "$k: depois do patch nada bloqueado");
        check($after['aplicado'] && $after['plano'] === null, "$k: não aplica duas vezes");
        eq($h, $H::revert($r['plano']), "$k: reversão byte a byte");
        check(!preg_match('~granted|Allow from all|\[L\]~', str_replace(explode("\n", $h), '', preg_replace('~# BEGIN GMAX.*?# END GMAX Renovacoes~s', '', $r['plano']))), "$k: nenhuma liberação fora do bloco");
        preg_match('~# BEGIN GMAX.*?# END GMAX Renovacoes~s', $r['plano'], $blk);
        foreach (['renovacoes.php', 'renovacoes-api.php', 'renovacoes-webhook.php'] as $ok) {
            check((bool)preg_match('~renovacoes\(-api\|-webhook\)\?\\\\\.php\$~', $blk[0]), "$k: regex exata dos 3 nomes");
        }
    }
    foreach (['renovacoes.php', 'renovacoes-api.php', 'renovacoes-webhook.php'] as $n) {
        check(preg_match('~' . \GmaxRenov\HtaccessPatch::FILES_REGEX . '~', $n) === 1, "$n liberado");
    }
    foreach (['api2.php', 'renovacoes-caminho.php', 'xrenovacoes.php', 'renovacoes.php.bak', 'renovacoes-teste.php', 'suspeito.php', 'renovacoes.phtml'] as $n) {
        check(preg_match('~' . \GmaxRenov\HtaccessPatch::FILES_REGEX . '~', $n) === 0, "$n NÃO liberado");
    }
    eq('nenhum', $H::analyze("Options -Indexes\n")['formato'], 'sem bloqueio: nada a fazer');
    $u = $H::analyze("<FilesMatch \"\\.php$\">\nRequire all denied\n</FilesMatch>\n");
    check($u['plano'] === null && $u['formato'] === 'desconhecido', 'bloqueio sem exceção do api.php: recusa');
});

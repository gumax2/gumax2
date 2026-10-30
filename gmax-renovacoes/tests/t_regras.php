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
    check(strpos($m[0]['corpo'], 'trimestral') !== false && strpos($m[0]['corpo'], 'R$ 300,00') !== false, 'trimestral R$ 300,00');
    check(strpos($m[1]['corpo'], 'anual') !== false && strpos($m[1]['corpo'], 'R$ 1.000,00') !== false, 'anual R$ 1.000,00');
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
    $t->pdo()->exec("UPDATE pacotes SET vencimento='2026-12-13' WHERE id=$p1");
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
    $t->pdo()->exec("UPDATE pacotes SET vencimento='2026-12-13' WHERE id=$p");
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
    $t->pdo()->exec("UPDATE pacotes SET vencimento='2026-12-13' WHERE id=$p1");
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
    $t->pdo()->exec("UPDATE clientes SET excluido_em='2026-11-10 09:40:00' WHERE id=$b");
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
    $t->pdo()->exec("UPDATE clientes SET telefone='31988887777' WHERE id=$b");
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
    eq(['2027-01-01', '2027-01-01', '2026-12-31'], array_column($m, 'vencimento'), 'vencimentos');
    eq(['antes', 'dia', 'depois'], array_column($m, 'etapa'), 'etapas');
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
    eq('somente_teste', $app->db()->value("SELECT motivo FROM gr_lembretes WHERE pacote_id=(SELECT id FROM pacotes WHERE nome='PA')"), 'cliente bloqueado');
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
    $t->pdo()->exec("INSERT INTO recebimentos (cliente_id, pacote_id, valor, recebido_em) VALUES ($c,$p1,120.00,'2026-10-13'),($c,$p2,35.00,'2026-10-10')");
    $t->pdo()->exec("INSERT INTO custos (pacote_id, descricao, valor, data) VALUES ($p1,'Painel fornecedor',70.00,'2026-10-13')");
    $profit = fn() => $t->pdo()->query('SELECT (SELECT SUM(valor) FROM recebimentos) - (SELECT SUM(valor) FROM custos)')->fetchColumn();
    $before = $t->financialChecksum();
    $profitBefore = $profit();
    $t->run();
    $t->run('2026-11-10 12:00:00');
    $e = new Engine($t->app);
    $t->app->settings()->set('numeros_teste', ['31999990001']);
    $e->sendTest('31999990001', 'antes');
    (new Contacts($t->app->db(), $t->clock))->revoke($c, 'painel');
    $t->run('2026-11-11 09:30:00');
    eq($before, $t->financialChecksum(), 'clientes, pacotes, recebimentos e custos idênticos');
    eq($profitBefore, $profit(), 'lucro inalterado');
    eq(2, (int)$t->pdo()->query('SELECT COUNT(*) FROM recebimentos')->fetchColumn(), 'nenhuma receita registrada pelo envio');
    eq('2026-11-13', $t->pdo()->query("SELECT vencimento FROM pacotes WHERE id=$p1")->fetchColumn(), 'vencimento não prorrogado');
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

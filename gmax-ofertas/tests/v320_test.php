<?php
declare(strict_types=1);
/*
 * 3.2.0: autenticação centralizada, limites operacionais, continuação de tarefas e desempenho.
 * Execução: php -d allow_url_fopen=0 tests/v320_test.php
 */
require __DIR__ . '/harness.php';
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';
require __DIR__ . '/fakes.php';

use Gmax\Automation\{Jobs, Runner};
use Gmax\Connectors\Registry;
use Gmax\{Auth, Catalog, Db, Migrator, Queue, Ranking, Rules, Settings, Time};

/** Conector lento e determinístico: confirma o preço atual depois de $delayMs. */
final class SlowConnector implements Gmax\Connectors\Connector
{
    public array $seen = [];

    public function __construct(private int $delayMs)
    {
    }

    public function name(): string { return 'lento'; }
    public function configured(): bool { return true; }
    public function status(): string { return 'teste'; }

    public function fetch(array $row): array
    {
        usleep($this->delayMs * 1000);
        $this->seen[] = (int)$row['id'];
        return ['ok' => true, 'error' => null, 'data' => ['price_promo_cents' => (int)$row['price_promo_cents']], 'endpoint' => 'teste', 'http' => 200];
    }
}

$SP = new DateTimeZone('America/Sao_Paulo');
$ready = static function (int $extra = 0) use ($SP): PDO {
    Registry::resetOverrides();
    Runner::$budgetForTests = null;
    Time::freeze(null);
    $pdo = t_seed('hostgator');
    Migrator::up($pdo, 'teste');
    Settings::set($pdo, 'api_intervalo_ms', '0', 'teste');
    for ($i = 0; $i < $extra; $i++) {
        Catalog::insert($pdo, ['marketplace' => 'shopee', 'external_id' => (string)(55000000000 + $i), 'title' => "Extra $i", 'price_promo_cents' => 1000 + $i, 'category' => 'Cat' . ($i % 3)], 'aguardando_validacao', 't');
    }
    return $pdo;
};
$enableReview = static function (PDO $pdo, string $params = '{"limite":200}') use ($SP): void {
    Settings::set($pdo, 'automacoes_pausadas', '0', 't');
    Db::run($pdo, "UPDATE gmax3_jobs SET enabled=1, times='12:00', weekdays='1,2,3,4,5,6,7', params=? WHERE job='revisao_precos'", [$params]);
    Time::freeze((new DateTimeImmutable('today 12:05', $SP))->setTimezone(new DateTimeZone('UTC')));
};

echo "\n== Autenticação centralizada\n";
test('Limite global de tentativas (50/15 min) além do limite por IP; bloqueado não grava (sem crescimento)', function () use ($ready) {
    $pdo = $ready();
    for ($i = 0; $i < 60; $i++) {
        $_SERVER['REMOTE_ADDR'] = '10.0.' . intdiv($i, 4) . '.' . ($i % 4);
        Auth::login($pdo, 'errada');
    }
    eq(50, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_login_attempts'), 'para de gravar ao atingir o limite global');
    $_SERVER['REMOTE_ADDR'] = '192.168.9.9';
    ok(str_contains((string)Auth::login($pdo, T_PASSWORD), 'Muitas tentativas'), 'IP novo bloqueado pelo limite global, mesmo com a senha certa');
    Db::run($pdo, 'DELETE FROM gmax3_login_attempts');
    for ($i = 0; $i < 8; $i++) {
        Auth::login($pdo, 'errada');
    }
    eq(5, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_login_attempts'), 'por IP: no máximo 5 registros');
    unset($_SERVER['REMOTE_ADDR']);
});
test('Concorrência real: 12 processos tentando ao mesmo tempo não passam de 5 tentativas', function () use ($ready) {
    $pdo = $ready();
    $script = t_sandbox() . '/tentativa.php';
    file_put_contents($script, '<?php define("GMAX_APP_DIR", ' . var_export(t_app(), true) . '); require GMAX_APP_DIR . "/gmax-lib/bootstrap.php";'
        . '$_SERVER["REMOTE_ADDR"]="203.0.113.7"; while (microtime(true) < (float)$argv[1]) { usleep(1000); }'
        . 'echo Gmax\Auth::login(Gmax\Db::get(), "errada") ?? "ok";');
    $start = microtime(true) + 1.0;
    $procs = [];
    for ($i = 0; $i < 12; $i++) {
        $procs[] = proc_open([PHP_BINARY, $script, (string)$start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], t_app(), ['GMAX_SECRETS_DIR' => t_sandbox() . '/gmax-secrets', 'GMAX_TESTING' => '1', 'PATH' => getenv('PATH')]);
    }
    $outs = [];
    foreach ($procs as $i => $p) {
        $outs[] = stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]);
        proc_close($p);
    }
    $wrong = count(array_filter($outs, fn($o) => str_contains($o, 'Senha incorreta')));
    $blocked = count(array_filter($outs, fn($o) => str_contains($o, 'Muitas tentativas')));
    eq(12, $wrong + $blocked, implode(' | ', $outs));
    ok($wrong <= 5, "no máximo 5 verificações de senha ($wrong)");
    eq(5, (int)Db::value(Db::get(), "SELECT COUNT(*) FROM gmax3_login_attempts"));
});
test('Senha inicial: só cria se não existir usuário; nunca sobrescreve; mínimo de 14 caracteres', function () use ($ready) {
    $pdo = $ready();
    throws(fn() => Auth::createInitialPassword($pdo, 'outra-senha-bem-longa'), 'Já existe uma senha');
    ok(password_verify(T_PASSWORD, (string)Db::value($pdo, 'SELECT password_hash FROM users')), 'senha original intacta');
    $pdo->exec('DELETE FROM users');
    throws(fn() => Auth::createInitialPassword($pdo, 'curta'), '14 a 72');
    throws(fn() => Auth::createInitialPassword($pdo, str_repeat('ç', 40)), '72 bytes'); // 40 caracteres = 80 bytes
    Auth::createInitialPassword($pdo, 'senha-inicial-longa-123');
    ok(password_verify('senha-inicial-longa-123', (string)Db::value($pdo, 'SELECT password_hash FROM users')), 'criada');
});
$cli = static function (string ...$a): string {
    $p = proc_open([PHP_BINARY, 'gmax-admin-cli.php', ...$a], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, t_app(), ['GMAX_SECRETS_DIR' => t_sandbox() . '/gmax-secrets', 'GMAX_TESTING' => '1', 'PATH' => getenv('PATH')]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($p);
    return $out;
};
test('CLI criar-senha apaga o arquivo da senha mesmo em caso de erro; recusa link simbólico; ignora BOM', function () use ($ready, $cli) {
    $pdo = $ready();
    $f = t_sandbox() . '/gmax-secrets/senha-inicial.txt';
    file_put_contents($f, "nova-senha-muito-longa-1\n");
    $out = $cli('criar-senha');
    ok(str_contains($out, 'Já existe uma senha') && !is_file($f), $out);
    ok(password_verify(T_PASSWORD, (string)Db::value($pdo, 'SELECT password_hash FROM users')), 'senha existente preservada');
    $target = t_sandbox() . '/alvo.txt';
    file_put_contents($target, 'conteudo-de-outro-arquivo');
    symlink($target, $f);
    $out = $cli('criar-senha');
    ok(str_contains($out, 'link simbólico') && !is_link($f) && is_file($target), $out);
    $pdo->exec('DELETE FROM users');
    file_put_contents($f, "\xEF\xBB\xBFsenha-com-bom-123\r\n");
    chmod($f, 0600);
    $out = $cli('criar-senha');
    ok(str_contains($out, 'Senha inicial criada') && !is_file($f), $out);
    ok(password_verify('senha-com-bom-123', (string)Db::value($pdo, 'SELECT password_hash FROM users')), 'BOM e quebra de linha removidos');
    $pdo->exec('DELETE FROM users');
    file_put_contents($f, str_repeat('a', 73));
    ok(str_contains($cli('criar-senha'), '72'), 'senha acima de 72 bytes recusada');
    $pdo->exec('DELETE FROM users');
});

test('IPv6 agrupado por /64; tentativas antigas descartadas; desbloqueio pela CLI', function () use ($ready, $cli) {
    eq(Auth::clientKey('2001:db8:1:2::1'), Auth::clientKey('2001:db8:1:2:ffff::9'), 'mesma rede /64 = mesma origem');
    ok(Auth::clientKey('2001:db8:1:2::1') !== Auth::clientKey('2001:db8:1:3::1'), 'outra /64 = outra origem');
    eq('10.0.0.1', Auth::clientKey('10.0.0.1'));
    eq('::ffff:10.0.0.1', Auth::clientKey('::ffff:10.0.0.1'), 'IPv4 mapeado não é agrupado');
    $pdo = $ready();
    Db::run($pdo, "INSERT INTO gmax3_login_attempts(ip_hash,at) VALUES('velho', ?)", [Time::now()->modify('-2 days')->format('Y-m-d H:i:s')]);
    $_SERVER['REMOTE_ADDR'] = '2001:db8:9:9::1';
    Auth::login($pdo, 'errada');
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_login_attempts WHERE ip_hash='velho'"), 'tentativa de 2 dias atrás descartada');
    for ($i = 2; $i <= 6; $i++) {
        $_SERVER['REMOTE_ADDR'] = "2001:db8:9:9::$i";
        $msg = Auth::login($pdo, 'errada');
    }
    ok(str_contains((string)$msg, 'Muitas tentativas'), 'trocar o endereço dentro da /64 não escapa do limite');
    $out = $cli('desbloquear-login');
    ok(str_contains($out, 'Login desbloqueado'), $out);
    eq(0, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_login_attempts'));
    eq(1, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_audit WHERE action='login_desbloqueado'"), 'desbloqueio auditado');
    unset($_SERVER['REMOTE_ADDR']);
});

echo "\n== Ranking e Visão Geral otimizados\n";
test('Regras com contexto pré-carregado = regras originais, produto a produto', function () use ($ready) {
    $pdo = $ready(30);
    Settings::set($pdo, 'publicador_v21_aposentado', '0', 't');
    $ids = array_map('intval', $pdo->query("SELECT id FROM gmax3_catalog ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    Db::run($pdo, "UPDATE gmax3_catalog SET validation_status='aprovado', payment_condition='normal', price_verified_at=datetime('now'), price_verified_by='admin' WHERE id % 2 = 0");
    Db::run($pdo, "UPDATE gmax3_catalog SET external_id=NULL WHERE id IN (?, ?)", [$ids[1], $ids[2]]);
    Db::run($pdo, "INSERT INTO gmax3_pub_queue(catalog_id,channel,state,scheduled_for,created_by) VALUES(?, 'telegram_privado', 'agendado', datetime('now','+1 day'), 't'), (?, 'telegram_privado', 'confirmado', datetime('now','-1 day'), 't')", [$ids[3], $ids[4]]);
    Db::run($pdo, "UPDATE offers SET status='enviado' WHERE rowid=1");
    $ctx = Rules::context($pdo);
    foreach (Db::all($pdo, 'SELECT * FROM gmax3_catalog') as $r) {
        eq(Rules::blockers($pdo, $r, 'telegram_privado'), Rules::blockers($pdo, $r, 'telegram_privado', $ctx), 'blockers #' . $r['id']);
        eq(Rules::duplicate($pdo, $r), Rules::duplicate($pdo, $r, null, $ctx), 'duplicate #' . $r['id']);
    }
});
test('Visão Geral com cache: mesmo resultado; invalida ao editar produto ou preço; expira em 5 min', function () use ($ready) {
    $pdo = $ready(20);
    $fresh = Ranking::list($pdo, [], 60);
    eq(array_map(fn($r) => [$r['item']['id'], $r['score'], $r['confidence']], $fresh), array_map(fn($r) => [$r['item']['id'], $r['score'], $r['confidence']], Ranking::listCached($pdo, 60)));
    eq(array_map(fn($r) => $r['item']['id'], $fresh), array_map(fn($r) => $r['item']['id'], Ranking::listCached($pdo, 60)), 'vindo do cache');
    $last = (int)end($fresh)['item']['id'];
    Db::run($pdo, "UPDATE gmax3_catalog SET sales_min=999999, rating=5, rating_count=9999, updated_at=datetime('now','+1 minute') WHERE id=?", [$last]);
    eq($last, (int)Ranking::listCached($pdo, 60)[0]['item']['id'], 'edição invalida o cache na hora');
    $c = json_decode(Settings::get($pdo, 'cache_ranking_topo'), true);
    $c['at'] -= 301;
    $c['ids'] = array_reverse($c['ids']);
    Settings::store($pdo, 'cache_ranking_topo', json_encode($c), 't');
    eq($last, (int)Ranking::listCached($pdo, 60)[0]['item']['id'], 'cache vencido é recalculado');
});

echo "\n== Automações: prazo, continuação, interrupção e concorrência\n";
test('Tarefa longa: execução parcial (não "concluída"), continuação nos ciclos seguintes, cada produto uma vez', function () use ($ready, $enableReview) {
    $pdo = $ready(25);
    $enableReview($pdo);
    $slow = new SlowConnector(60);
    Registry::override('shopee', $slow);
    Registry::override('mercadolivre', new FakeMarketConnector('mercadolivre', ok: false));
    Runner::$budgetForTests = 0.5;
    $statuses = [];
    for ($i = 0; $i < 6 && count(array_unique($slow->seen)) < 30; $i++) {
        Runner::runDue($pdo, 'cron');
        $statuses[] = Db::value($pdo, "SELECT status FROM gmax3_job_runs WHERE job='revisao_precos' ORDER BY id DESC LIMIT 1");
    }
    eq('parcial', $statuses[0], 'primeira execução não é dada como concluída');
    eq(count($slow->seen), count(array_unique($slow->seen)), 'nenhum produto consultado duas vezes');
    eq(30, count(array_unique($slow->seen)), 'todos os 30 produtos Shopee revisados');
    eq('concluido', end($statuses), 'última execução concluída');
    eq([], Runner::continuations($pdo), 'continuação encerrada');
    ok((int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_job_runs WHERE slot_key LIKE '% #cont%'") >= 1, 'continuação registrada com chave própria');
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM (SELECT job, slot_key FROM gmax3_job_runs GROUP BY job, slot_key HAVING COUNT(*) > 1)"), 'sem execuções duplicadas');
});
test('Interrompida pelo servidor: vira falha com alerta e é retomada do ponto em que parou', function () use ($ready, $enableReview, $SP) {
    $pdo = $ready(5);
    $enableReview($pdo);
    $slot = (new DateTimeImmutable('today', $SP))->format('Y-m-d') . ' 12:00';
    Db::run($pdo, "INSERT INTO gmax3_job_runs(job,slot_key,trigger,status,started_at,actor) VALUES('revisao_precos',?,'agendado','executando',?,'cron')", [$slot, Time::now()->modify('-20 minutes')->format('Y-m-d H:i:s')]);
    $staleId = (int)$pdo->lastInsertId();
    $before = array_map('intval', array_column(Db::all($pdo, "SELECT id FROM gmax3_catalog WHERE marketplace='shopee' ORDER BY id LIMIT 3"), 'id'));
    foreach ($before as $id) {
        Db::run($pdo, "INSERT INTO gmax3_job_run_items(run_id,catalog_id,marketplace,outcome,reason,created_at) VALUES(?,?,'shopee','sem_alteracao','antes da interrupção',?)", [$staleId, $id, Time::nowSql()]);
    }
    $slow = new SlowConnector(1);
    Registry::override('shopee', $slow);
    Registry::override('mercadolivre', new FakeMarketConnector('mercadolivre', ok: false));
    Runner::runDue($pdo, 'cron');
    eq('falhou', Db::value($pdo, 'SELECT status FROM gmax3_job_runs WHERE slot_key=?', [$slot]));
    eq(1, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_alerts WHERE kind='falha_automacao' AND message LIKE '%interrompida%retomada%'"));
    eq('concluido', Db::value($pdo, 'SELECT status FROM gmax3_job_runs WHERE slot_key=?', [$slot . ' #cont1']), 'retomada executada');
    eq([], array_values(array_intersect($before, $slow->seen)), 'o que já tinha sido consultado antes da interrupção não é repetido');
    eq(7, count(array_unique($slow->seen)), 'restante consultado na retomada');
});
test('Concorrência: com a trava ocupada nada executa; pausa geral impede a continuação', function () use ($ready, $enableReview) {
    $pdo = $ready(20);
    $enableReview($pdo);
    Registry::override('shopee', new SlowConnector(60));
    Registry::override('mercadolivre', new FakeMarketConnector('mercadolivre', ok: false));
    Runner::$budgetForTests = 0.4;
    Runner::runDue($pdo, 'cron');
    ok(Runner::continuations($pdo) !== [], 'continuação pendente');
    $fh = fopen(Runner::lockFile(), 'c');
    flock($fh, LOCK_EX);
    $n = (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_job_runs');
    eq('ja_em_execucao', Runner::runDue($pdo, 'cron')['resultado'] ?? null);
    eq('ja_em_execucao', Runner::runManual($pdo, 'revisao_precos', 'admin')['resultado']);
    flock($fh, LOCK_UN);
    fclose($fh);
    eq($n, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_job_runs'), 'nada executado com a trava ocupada');
    Settings::set($pdo, 'automacoes_pausadas', '1', 't');
    eq('automacoes_pausadas', Runner::runDue($pdo, 'cron')['resultado']);
    eq($n, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_job_runs'), 'pausa impede a continuação');
});
test('Limite de continuações: alerta e encerramento (sem laço infinito)', function () use ($ready, $enableReview) {
    $pdo = $ready(60);
    $enableReview($pdo);
    Registry::override('shopee', new SlowConnector(100));
    Registry::override('mercadolivre', new FakeMarketConnector('mercadolivre', ok: false));
    Runner::$budgetForTests = 0.15;
    for ($i = 0; $i < 9; $i++) {
        Runner::runDue($pdo, 'cron');
    }
    eq(7, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_job_runs WHERE job='revisao_precos'"), '1 execução + 6 continuações');
    eq([], Runner::continuations($pdo));
    eq(1, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_alerts WHERE message LIKE '%limite de continuações%'"));
});
test('Execução manual pelo navegador: prazo menor que o limite do servidor', function () {
    Runner::$budgetForTests = null;
    $old = ini_get('max_execution_time');
    ini_set('max_execution_time', '30');
    eq(15.0, Runner::manualBudget(), 'limite do servidor (30 s) − folga de 15 s');
    ini_set('max_execution_time', '0');
    eq(Runner::TIME_BUDGET, Runner::manualBudget(), 'sem limite: orçamento padrão de 100 s');
    ini_set('max_execution_time', (string)$old);
});

echo "\n== Limites de seleção e de crescimento\n";
test('Seleção: teto de vagas livres na janela e no máximo 2 por categoria; nada é enviado', function () use ($ready) {
    $pdo = $ready(12);
    Settings::set($pdo, 'publicador_v21_aposentado', '1', 't');
    Db::run($pdo, "UPDATE gmax3_catalog SET payment_condition='normal', availability='disponivel', price_original_cents=price_promo_cents*2, sales_min=5000, rating=4.8, rating_count=900, seller_reputation='Loja oficial', affiliate_url='https://s.shopee.com.br/AbCd' || id, affiliate_norm='s.shopee.com.br/AbCd' || id, category=COALESCE(category,'Geral') WHERE marketplace='shopee'");
    foreach (Db::all($pdo, "SELECT id FROM gmax3_catalog WHERE marketplace='shopee'") as $r) {
        Catalog::markVerifiedByApi($pdo, (int)$r['id'], 'shopee', 't');
        Catalog::setStatus($pdo, (int)$r['id'], 'aprovado', 't');
    }
    [$cap] = Jobs::selectionBudget($pdo);
    eq(18, $cap, '3 dias × 6 por dia');
    Jobs::run('selecao', $pdo, ['pontuacao_minima' => 0, 'confianca_minima' => 0], microtime(true) + 30, 0, 't');
    $per = $pdo->query("SELECT c.category, COUNT(*) FROM gmax3_pub_queue q JOIN gmax3_catalog c ON c.id=q.catalog_id GROUP BY c.category")->fetchAll(PDO::FETCH_KEY_PAIR);
    ok($per !== [] && max($per) <= 2, json_encode($per));
    ok(array_sum($per) <= 18, 'dentro das vagas');
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_pub_queue WHERE state IN ('reservado','confirmado','incerto')"), 'nada enviado');
    Settings::set($pdo, 'limite_diario_telegram_privado', '1', 't');
    Db::run($pdo, "DELETE FROM gmax3_pub_queue");
    Settings::set($pdo, 'selecao_max_por_categoria', '20', 't');
    Jobs::run('selecao', $pdo, ['pontuacao_minima' => 0, 'confianca_minima' => 0], microtime(true) + 30, 0, 't');
    eq(3, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_pub_queue'), 'teto global: 3 dias × 1 por dia');
});
test('Limites de seleção configuráveis pelo painel, com validação; chaves de envio continuam só na CLI', function () use ($ready) {
    $pdo = $ready();
    Settings::setFromWeb($pdo, 'selecao_max_por_categoria', '3', 't');
    Settings::setFromWeb($pdo, 'selecao_horizonte_dias', '5', 't');
    eq([5 * 6], [Jobs::selectionBudget($pdo)[0]], '5 dias × 6');
    throws(fn() => Settings::setFromWeb($pdo, 'selecao_max_por_categoria', '0', 't'), 'inválido');
    throws(fn() => Settings::setFromWeb($pdo, 'selecao_horizonte_dias', '30', 't'), 'inválido');
    throws(fn() => Settings::setFromWeb($pdo, 'aprovacao_automatica', '1', 't'), 'não pode ser alterada');
});
test('Registro de "não processados" limitado a 100 linhas por execução (total no resumo)', function () use ($ready) {
    $pdo = $ready(160);
    Registry::override('shopee', new SlowConnector(0));
    Registry::override('mercadolivre', new FakeMarketConnector('mercadolivre', ok: false));
    Db::run($pdo, "UPDATE gmax3_jobs SET params='{\"limite\":5}' WHERE job='revisao_precos'");
    $r = Runner::runManual($pdo, 'revisao_precos', 't');
    eq(100, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_job_run_items WHERE run_id=? AND outcome='nao_processado'", [$r['execucao']]));
    ok(str_contains((string)Db::value($pdo, 'SELECT detail FROM gmax3_job_runs WHERE id=?', [$r['execucao']]), 'total: 160'), 'total informado');
    eq('concluido', $r['resultado'], 'limite configurado não é pendência de tempo');
});

Runner::$budgetForTests = null;
Time::freeze(null);
$pass = count(array_filter($GLOBALS['t_results'], fn($r) => $r[1]));
$total = count($GLOBALS['t_results']);
echo "\nResultado 3.2.0: $pass/$total testes aprovados\n";
file_put_contents(__DIR__ . '/../docs/resultado-testes-v320.json', json_encode([
    'executado_em' => date('c'), 'php' => PHP_VERSION, 'aprovados' => $pass, 'total' => $total,
    'testes' => array_map(fn($r) => ['nome' => $r[0], 'ok' => $r[1], 'erro' => $r[2], 'segundos' => round($r[3], 3)], $GLOBALS['t_results']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($pass === $total ? 0 : 1);

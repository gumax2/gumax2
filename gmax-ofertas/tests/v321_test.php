<?php
declare(strict_types=1);
/*
 * 3.2.1: índices, retenção (simulação e execução protegida), monitor de saúde, permissões,
 * interrupção/retomada com processos reais e concorrência.
 * Execução: php -d allow_url_fopen=0 tests/v321_test.php
 */
require __DIR__ . '/harness.php';
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';
require __DIR__ . '/fakes.php';

use Gmax\Automation\{Runner, ResumeValidation};
use Gmax\Connectors\Registry;
use Gmax\{Audit, Backup, Config, Db, Health, Migrator, Retention, Settings, Time};

$SP = new DateTimeZone('America/Sao_Paulo');
$ready = static function (): PDO {
    Registry::resetOverrides();
    Runner::$budgetForTests = null;
    Retention::$beforeCommit = null;
    Time::freeze(null);
    $pdo = t_seed('hostgator');
    Migrator::up($pdo, 'teste');
    Settings::set($pdo, 'api_intervalo_ms', '0', 't');
    return $pdo;
};
$env = static fn() => ['GMAX_SECRETS_DIR' => t_sandbox() . '/gmax-secrets', 'GMAX_TESTING' => '1', 'PATH' => getenv('PATH')];
$run = static function (array $cmd) use ($env): array {
    $p = proc_open([PHP_BINARY, ...$cmd], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, t_app(), $env());
    $o = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $o];
};
/** Linhas antigas (pelo relógio real do SQLite) e recentes no detalhe das execuções e nas descobertas. */
$oldRows = static function (PDO $pdo, int $old = 30, int $recent = 10): void {
    $pdo->exec("INSERT INTO gmax3_job_runs(job,slot_key,trigger,status,started_at) VALUES('revisao_precos','antigo','agendado','concluido',datetime('now','-120 days'))");
    $run = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare("INSERT INTO gmax3_job_run_items(run_id,catalog_id,marketplace,outcome,reason,created_at) VALUES(?,NULL,'shopee','sem_alteracao','teste',datetime('now',?))");
    $dis = $pdo->prepare("INSERT INTO gmax3_discoveries(run_id,marketplace,external_id,outcome,seen_at) VALUES(?,'shopee',?,'novo',datetime('now',?))");
    for ($i = 0; $i < $old; $i++) {
        $ins->execute([$run, '-' . (91 + $i) . ' days']);
        $dis->execute([$run, "old$i", '-' . (95 + $i) . ' days']);
    }
    for ($i = 0; $i < $recent; $i++) {
        $ins->execute([$run, '-' . $i . ' days']);
        $dis->execute([$run, "new$i", '-' . (80 + $i) . ' days']);
    }
};

echo "\n== Índices (migração 005)\n";
test('Consultas principais usam os índices novos (EXPLAIN QUERY PLAN)', function () use ($ready) {
    $pdo = $ready();
    $plan = static fn(string $sql, array $a = []) => implode(' | ', array_column(Db::all($pdo, "EXPLAIN QUERY PLAN $sql", $a), 'detail'));
    foreach ([
        ['SELECT * FROM gmax3_catalog ORDER BY updated_at DESC LIMIT 20', 'gmax3_catalog_updated'],
        ['SELECT MAX(updated_at) FROM gmax3_catalog', 'gmax3_catalog_updated'],
        ['SELECT catalog_id, COUNT(*), MIN(price_promo_cents) FROM gmax3_price_history WHERE verified = 1 AND price_promo_cents IS NOT NULL GROUP BY catalog_id', 'gmax3_price_history_verified'],
        ["SELECT * FROM gmax3_job_runs WHERE status='executando'", 'gmax3_job_runs_status'],
        ["SELECT * FROM gmax3_job_runs WHERE job='revisao_precos' ORDER BY id DESC LIMIT 1", 'gmax3_job_runs_job'],
        ["SELECT COUNT(*) FROM gmax3_job_run_items WHERE created_at < '2026-01-01'", 'gmax3_job_run_items_created'],
        ["SELECT COUNT(*) FROM gmax3_discoveries WHERE seen_at < '2026-01-01'", 'gmax3_discoveries_seen'],
        ["SELECT COUNT(*) FROM gmax3_pub_queue WHERE created_at >= '2026-01-01'", 'gmax3_pub_queue_created'],
    ] as [$sql, $idx]) {
        ok(str_contains($plan($sql), $idx), "$idx em: " . $plan($sql));
    }
});
test('Gatilhos: auditoria somente-inclusão; detalhe e descobertas só saem dentro de uma retenção autorizada', function () use ($ready, $oldRows) {
    $pdo = $ready();
    $oldRows($pdo, 3, 1);
    throws(fn() => $pdo->exec('DELETE FROM gmax3_audit'), 'somente-inclusão');
    throws(fn() => $pdo->exec("UPDATE gmax3_audit SET actor='x'"), 'somente-inclusão');
    throws(fn() => $pdo->exec('DELETE FROM gmax3_job_run_items'), 'somente-inclusão');
    throws(fn() => $pdo->exec("DELETE FROM gmax3_job_run_items WHERE created_at < datetime('now','-90 days')"), 'somente-inclusão');
    throws(fn() => $pdo->exec('DELETE FROM gmax3_discoveries'), 'somente-inclusão');
    throws(fn() => $pdo->exec("UPDATE gmax3_discoveries SET outcome='x'"), 'somente-inclusão');
    $pdo->exec("INSERT INTO gmax3_retention_runs(kind,status,created_at,actor,policy) VALUES('simulacao','simulado',datetime('now'),'t','{}')");
    throws(fn() => $pdo->exec('DELETE FROM gmax3_retention_runs'), 'não pode ser apagado');
    // Mesmo com uma execução "executando", linhas recentes continuam protegidas.
    $pdo->exec('BEGIN');
    $pdo->exec("INSERT INTO gmax3_retention_runs(kind,status,created_at,actor,policy) VALUES('execucao','executando',datetime('now'),'t','{}')");
    throws(fn() => $pdo->exec("DELETE FROM gmax3_job_run_items WHERE created_at > datetime('now','-1 day')"), 'somente-inclusão');
    $pdo->exec('ROLLBACK');
});

echo "\n== Retenção\n";
test('Simulação: conjunto exato (quantidade, intervalo e lista de IDs em arquivo); nada apagado; protegidos listados', function () use ($ready, $oldRows) {
    $pdo = $ready();
    $oldRows($pdo, 30, 10);
    $before = [(int)$pdo->query('SELECT COUNT(*) FROM gmax3_job_run_items')->fetchColumn(), (int)$pdo->query('SELECT COUNT(*) FROM gmax3_discoveries')->fetchColumn(), (int)$pdo->query('SELECT COUNT(*) FROM gmax3_audit')->fetchColumn()];
    $r = Retention::simulate($pdo, 't');
    eq(30, $r['eliminaveis']['gmax3_job_run_items']['quantidade']);
    eq(30, $r['eliminaveis']['gmax3_discoveries']['quantidade'], 'descobertas com 80–89 dias ficam');
    eq(60, $r['total_eliminavel']);
    $lines = gzfile(Retention::dir() . '/' . $r['lista']);
    eq(61, count($lines), 'cabeçalho + 60 IDs');
    $ids = array_map(fn($l) => (int)explode(',', $l)[1], array_slice(array_filter($lines, fn($l) => str_starts_with($l, 'gmax3_job_run_items')), 0));
    eq(array_map('intval', array_column(Db::all($pdo, "SELECT id FROM gmax3_job_run_items WHERE created_at < datetime('now','-90 days') ORDER BY id"), 'id')), $ids, 'lista = exatamente as linhas antigas');
    eq($before[0], (int)$pdo->query('SELECT COUNT(*) FROM gmax3_job_run_items')->fetchColumn(), 'nada apagado');
    eq($before[1], (int)$pdo->query('SELECT COUNT(*) FROM gmax3_discoveries')->fetchColumn());
    ok(isset($r['protegidos']['gmax3_audit'], $r['protegidos']['gmax3_pub_queue'], $r['protegidos']['gmax3_price_history']) && $r['protegidos']['gmax3_audit']['acao'] === 'preservado', 'auditoria, publicações e histórico preservados');
    eq('nenhum backup é apagado nesta versão', $r['backups']['acao']);
});
test('Execução: frase, simulação recente e idêntica; backup verificado com as linhas; exportação verificada; apaga só o simulado', function () use ($ready, $oldRows, $run) {
    $pdo = $ready();
    $oldRows($pdo, 30, 10);
    $sim = Retention::simulate($pdo, 't')['simulacao'];
    $audit = (int)$pdo->query('SELECT COUNT(*) FROM gmax3_audit')->fetchColumn();
    throws(fn() => Retention::execute($pdo, $sim, 'sim', 't'), 'Frase');
    // Linha antiga inserida DEPOIS da simulação: fica fora do conjunto simulado e não é apagada.
    $pdo->exec("INSERT INTO gmax3_discoveries(run_id,marketplace,external_id,outcome,seen_at) VALUES(1,'shopee','tarde','novo',datetime('now','-200 days'))");
    $r = Retention::execute($pdo, $sim, Retention::PHRASE, 't');
    eq(['gmax3_job_run_items' => 30, 'gmax3_discoveries' => 30], $r['apagadas'], 'exatamente o simulado');
    eq(1, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_discoveries WHERE external_id='tarde'"), 'linha fora da simulação preservada');
    eq(10, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_job_run_items')->fetchColumn(), 'recentes intactas');
    eq(0, (int)$pdo->query("SELECT COUNT(*) FROM gmax3_job_run_items WHERE created_at < datetime('now','-90 days')")->fetchColumn());
    ok((int)$pdo->query('SELECT COUNT(*) FROM gmax3_audit')->fetchColumn() > $audit, 'auditoria preservada e acrescida');
    $b = new PDO('sqlite:' . Backup::dir() . '/' . $r['backup']);
    eq(40, (int)$b->query('SELECT COUNT(*) FROM gmax3_job_run_items')->fetchColumn(), 'backup tem todas as linhas (recuperação possível)');
    $exp = gzfile(Retention::dir() . '/' . $r['exportacao']);
    eq(60, count($exp), 'exportação com todas as linhas apagadas (uma por linha)');
    eq($r['exportacao_sha256'], hash_file('sha256', Retention::dir() . '/' . $r['exportacao']));
    throws(fn() => Retention::execute($pdo, $sim, Retention::PHRASE, 't'), 'já foi executada');
    throws(fn() => $pdo->exec('DELETE FROM gmax3_job_run_items'), 'somente-inclusão'); // gatilho fechado de novo
    eq(0, (int)$pdo->query("SELECT COUNT(*) FROM gmax3_retention_runs WHERE status='executando'")->fetchColumn());
    Time::freeze(Time::now()->modify('+25 hours'));
    $sim2 = Retention::simulate($pdo, 't')['simulacao'];
    Time::freeze(Time::now()->modify('+25 hours'));
    throws(fn() => Retention::execute($pdo, $sim2, Retention::PHRASE, 't'), 'mais de 24 h');
    Time::freeze(null);
});
test('Retenção interrompida (processo morto antes do COMMIT): nada apagado, nenhum "executando" sobra; nova execução funciona', function () use ($ready, $oldRows, $run) {
    $pdo = $ready();
    $oldRows($pdo, 20, 5);
    $sim = Retention::simulate($pdo, 't')['simulacao'];
    $script = t_sandbox() . '/retencao_filho.php';
    file_put_contents($script, '<?php define("GMAX_APP_DIR", ' . var_export(t_app(), true) . '); require GMAX_APP_DIR . "/gmax-lib/bootstrap.php";'
        . 'Gmax\Retention::$beforeCommit = fn() => posix_kill(getmypid(), 9); Gmax\Retention::execute(Gmax\Db::get(), (int)$argv[1], Gmax\Retention::PHRASE, "t");');
    [$code] = $run([$script, (string)$sim]);
    ok($code !== 0, "morto (código $code)");
    Db::reset();
    $pdo = Db::get();
    eq(25, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_job_run_items')->fetchColumn(), 'transação desfeita: nada apagado');
    eq(0, (int)$pdo->query("SELECT COUNT(*) FROM gmax3_retention_runs WHERE kind='execucao'")->fetchColumn(), 'sem registro "executando" (o gatilho não fica aberto)');
    throws(fn() => $pdo->exec("DELETE FROM gmax3_job_run_items WHERE created_at < datetime('now','-90 days')"), 'somente-inclusão');
    eq(['gmax3_job_run_items' => 20, 'gmax3_discoveries' => 20], Retention::execute($pdo, $sim, Retention::PHRASE, 't')['apagadas']);
});
test('Nada apaga automaticamente: nenhuma tarefa, página ou Cron chama a execução; pelo navegador é recusada', function () {
    foreach (array_merge(glob(GMAX_APP_DIR . '/gmax-lib/Gmax/Automation/*.php'), glob(GMAX_APP_DIR . '/gmax-lib/pages/*.php'), [GMAX_APP_DIR . '/painel.php', GMAX_APP_DIR . '/gmax-automacoes-cli.php'], glob(GMAX_APP_DIR . '/gmax-lib/Gmax/AutoLab/*.php')) as $f) {
        ok(!str_contains((string)file_get_contents($f), 'Retention::execute'), basename($f) . ' não chama a execução');
    }
    ok(str_contains((string)file_get_contents(GMAX_APP_DIR . '/gmax-lib/Gmax/Retention.php'), "PHP_SAPI !== 'cli'"), 'execução exige CLI');
});

echo "\n== Interrupção, retomada e concorrência (processos reais)\n";
test('Revisão morta pelo servidor (SIGKILL): o Cron seguinte fecha na hora (sem esperar 15 min), alerta e continua sem repetir', function () use ($ready, $SP, $run) {
    $pdo = $ready();
    Settings::set($pdo, 'automacoes_pausadas', '0', 't');
    $slot = (new DateTimeImmutable('now', $SP))->modify('-1 minute')->format('H:i');
    Db::run($pdo, "UPDATE gmax3_jobs SET enabled=1, times=?, weekdays='1,2,3,4,5,6,7', params='{\"limite\":200}' WHERE job='revisao_precos'", [$slot]);
    for ($i = 0; $i < 10; $i++) {
        Gmax\Catalog::insert($pdo, ['marketplace' => 'shopee', 'external_id' => (string)(56000000000 + $i), 'title' => "Kill $i", 'price_promo_cents' => 1000 + $i], 'aprovado', 't');
    }
    $log = t_sandbox() . '/consultas-kill.log';
    @unlink($log);
    $script = t_sandbox() . '/revisao_filho.php';
    file_put_contents($script, '<?php define("GMAX_APP_DIR", ' . var_export(t_app(), true) . '); require GMAX_APP_DIR . "/gmax-lib/bootstrap.php";'
        . 'final class K implements Gmax\Connectors\Connector { private int $n = 0; public function name(): string { return "k"; } public function configured(): bool { return true; } public function status(): string { return "k"; }'
        . ' public function fetch(array $r): array { file_put_contents(' . var_export($log, true) . ', $r["id"] . "\n", FILE_APPEND | LOCK_EX); if ((int)$GLOBALS["argv"][1] > 0 && ++$this->n >= (int)$GLOBALS["argv"][1]) { posix_kill(getmypid(), 9); }'
        . ' return ["ok" => true, "error" => null, "data" => ["price_promo_cents" => (int)$r["price_promo_cents"]]]; } }'
        . '$k = new K(); Gmax\Connectors\Registry::override("shopee", $k); Gmax\Connectors\Registry::override("mercadolivre", $k); echo json_encode(Gmax\Automation\Runner::runDue(Gmax\Db::get(), "cron"));');
    [$code] = $run([$script, '4']);
    ok($code !== 0, "processo morto (código $code)");
    Db::reset();
    $pdo = Db::get();
    eq('executando', Db::value($pdo, "SELECT status FROM gmax3_job_runs WHERE job='revisao_precos' ORDER BY id LIMIT 1"));
    [$code2, $out] = $run([$script, '0']); // próximo Cron, poucos segundos depois
    eq(0, $code2, $out);
    Db::reset();
    $pdo = Db::get();
    eq(['falhou', 'concluido'], array_column(Db::all($pdo, "SELECT status FROM gmax3_job_runs WHERE job='revisao_precos' ORDER BY id"), 'status'), $out);
    ok(str_contains((string)Db::value($pdo, "SELECT slot_key FROM gmax3_job_runs WHERE job='revisao_precos' ORDER BY id DESC LIMIT 1"), '#cont1'), 'continuação');
    eq(1, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_alerts WHERE kind='falha_automacao' AND message LIKE '%interrompida%'"));
    $seen = array_map('intval', file($log, FILE_IGNORE_NEW_LINES));
    ok(count($seen) - count(array_unique($seen)) <= 1, 'só o produto em consulta na hora da queda pode repetir: ' . implode(',', $seen));
});
test('Dois Crons ao mesmo tempo: um executa, o outro recusa (trava); o AutoLab tem trava própria e não bloqueia as automações', function () use ($ready, $run) {
    $pdo = $ready();
    $script = t_sandbox() . '/cron_filho.php';
    file_put_contents($script, '<?php define("GMAX_APP_DIR", ' . var_export(t_app(), true) . '); require GMAX_APP_DIR . "/gmax-lib/bootstrap.php";'
        . 'while (microtime(true) < (float)$argv[1]) { usleep(1000); } $fh = fopen(Gmax\Automation\Runner::lockFile(), "c");'
        . 'if (!flock($fh, LOCK_EX | LOCK_NB)) { echo "ja_em_execucao"; exit; } echo "executou"; usleep(800000);');
    $start = microtime(true) + 0.8;
    $procs = [];
    for ($i = 0; $i < 2; $i++) {
        $procs[] = proc_open([PHP_BINARY, $script, (string)$start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], t_app(), ['GMAX_SECRETS_DIR' => t_sandbox() . '/gmax-secrets', 'GMAX_TESTING' => '1', 'PATH' => getenv('PATH')]);
    }
    $outs = [];
    foreach ($procs as $i => $p) {
        $outs[] = stream_get_contents($pipes[$i][1]);
        proc_close($p);
    }
    sort($outs);
    eq(['executou', 'ja_em_execucao'], $outs);
    $fh = fopen(Runner::lockFile(), 'c');
    flock($fh, LOCK_EX);
    eq('autolab_desativado', Gmax\AutoLab\AutoLab::tick($pdo)['resultado'], 'AutoLab roda com a trava das automações ocupada');
    flock($fh, LOCK_UN);
});
test('validar-retomada (para a hospedagem): cópia temporária, processo morto, retomada aprovada; dados reais intactos', function () use ($ready, $run) {
    $pdo = $ready();
    $hash = hash('sha256', json_encode([Db::all($pdo, 'SELECT * FROM gmax3_catalog ORDER BY id'), Db::all($pdo, 'SELECT * FROM gmax3_job_runs ORDER BY id'), Db::all($pdo, 'SELECT key, value FROM gmax3_settings ORDER BY key')]));
    [$code, $out] = $run(['gmax-automacoes-cli.php', 'validar-retomada']);
    ok(str_contains($out, '"aprovado": true'), $out);
    ok(str_contains($out, 'Etapa 1: processo encerrado à força'), 'processo real morto');
    Db::reset();
    $pdo = Db::get();
    eq($hash, hash('sha256', json_encode([Db::all($pdo, 'SELECT * FROM gmax3_catalog ORDER BY id'), Db::all($pdo, 'SELECT * FROM gmax3_job_runs ORDER BY id'), Db::all($pdo, 'SELECT key, value FROM gmax3_settings ORDER BY key')])), 'banco real inalterado');
    eq([], glob(t_sandbox() . '/gmax-secrets/gmax-validacao-*'), 'cópia temporária removida');
    // Modo em duas etapas (hospedagem sem proc_open).
    [$c1, $o1] = $run(['gmax-automacoes-cli.php', 'validar-retomada', 'etapa1']);
    ok($c1 !== 0 && str_contains($o1, 'encerrado à força'), $o1);
    [$c2, $o2] = $run(['gmax-automacoes-cli.php', 'validar-retomada', 'etapa2']);
    ok(str_contains($o2, '"aprovado": true'), $o2);
});

echo "\n== Monitor de saúde e permissões\n";
test('Monitor: tarefas, Cron, travas, banco, disco, backups e permissões; integridade sob demanda', function () use ($ready) {
    $pdo = $ready();
    $c = Health::collect($pdo);
    foreach (['Cron das automações', 'Execuções em andamento', 'Banco SQLite', 'Integridade do banco', 'Espaço em disco livre', 'Backups', 'Permissão do banco', 'Permissão de gmax-secrets', 'Usuário do PHP do site', 'PHP'] as $k) {
        ok(isset($c[$k]), "item $k");
    }
    ok(str_contains($c['Banco SQLite'][1], 'modo de diário delete'), 'modo atual (sem WAL): ' . $c['Banco SQLite'][1]);
    eq('ok', Health::integrity($pdo, 't'));
    eq('ok', Health::collect($pdo)['Integridade do banco'][0]);
    ok(count(Health::jobs($pdo)) >= 6, 'tarefas listadas');
    Gmax\Queue::heartbeat($pdo, 'automacoes', 'ativo');
    eq('ok', Health::collect($pdo)['Cron das automações'][0]);
});
test('Permissões: plano só é "seguro" com o mesmo usuário no site, no Cron e como dono; aplicar mantém o banco gravável', function () use ($ready) {
    $pdo = $ready();
    $db = Db::path($pdo);
    chmod($db, 0644);
    chmod(Config::secretsDir(), 0755);
    $plan = Health::permissionPlan($pdo);
    eq(false, $plan['seguro'], 'sem o usuário do site registrado');
    Settings::store($pdo, 'php_web_uid', (string)(posix_geteuid() + 1), 't');
    ok(str_contains(Health::permissionPlan($pdo)['motivo'], 'quebraria o site'), 'usuário diferente: não aplica');
    throws(fn() => Health::applyPermissions($pdo, 't'), 'Não aplicado');
    eq('0644', substr(sprintf('%o', fileperms($db)), -4), 'nada alterado');
    Settings::store($pdo, 'php_web_uid', (string)posix_geteuid(), 't');
    ok(Health::permissionPlan($pdo)['seguro'], 'mesmo usuário');
    Health::applyPermissions($pdo, 't');
    clearstatcache();
    eq(['0600', '0700'], [substr(sprintf('%o', fileperms($db)), -4), substr(sprintf('%o', fileperms(Config::secretsDir())), -4)]);
    Settings::store($pdo, 'teste_gravacao', 'ok', 't');
    eq('ok', Settings::get($pdo, 'teste_gravacao'), 'banco continua gravável');
    eq(1, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_audit WHERE action='permissoes_ajustadas'"));
});
test('SQLite continua no modo atual (sem WAL) e com espera de bloqueio', function () use ($ready) {
    $pdo = $ready();
    eq('delete', strtolower((string)$pdo->query('PRAGMA journal_mode')->fetchColumn()));
    eq(5000, (int)$pdo->query('PRAGMA busy_timeout')->fetchColumn());
    $src = (string)file_get_contents(GMAX_APP_DIR . '/gmax-lib/Gmax/Db.php');
    ok(!preg_match('/journal_mode\s*=\s*WAL/i', $src), 'nenhum código ativa WAL');
});

Time::freeze(null);
$pass = count(array_filter($GLOBALS['t_results'], fn($r) => $r[1]));
$total = count($GLOBALS['t_results']);
echo "\nResultado 3.2.1: $pass/$total testes aprovados\n";
file_put_contents(__DIR__ . '/../docs/resultado-testes-v321.json', json_encode([
    'executado_em' => date('c'), 'php' => PHP_VERSION, 'aprovados' => $pass, 'total' => $total,
    'testes' => array_map(fn($r) => ['nome' => $r[0], 'ok' => $r[1], 'erro' => $r[2], 'segundos' => round($r[3], 3)], $GLOBALS['t_results']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($pass === $total ? 0 : 1);

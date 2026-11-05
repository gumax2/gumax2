<?php
declare(strict_types=1);
/*
 * Benchmark reproduzível da 3.2.1 (índices da 005 e gravação em lote), em banco isolado de teste.
 * Uso: php -d memory_limit=512M tools/benchmark_operacao.php [produtos=10000] [linhas_execucao=200000]
 * Mede cada consulta COM os índices da 005 e depois SEM eles (removidos só neste banco de teste),
 * e a gravação de 400 linhas de registro uma a uma × num único lote. Gera docs/benchmark-operacao-3.2.1.json.
 */
require __DIR__ . '/../tests/harness.php';
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';
require __DIR__ . '/bench_seed.php';

use Gmax\{Catalog, Db, Migrator, Ranking};

$n = (int)($argv[1] ?? 10000);
$items = (int)($argv[2] ?? 200000);
$pdo = bench_seed($n);
mt_srand(7);
$pdo->exec('BEGIN');
$pdo->exec("INSERT INTO gmax3_job_runs(job,slot_key,trigger,status,started_at,finished_at) VALUES('revisao_precos','bench','agendado','concluido',datetime('now','-200 days'),datetime('now'))");
for ($r = 0; $r < 2000; $r++) {
    $pdo->prepare("INSERT INTO gmax3_job_runs(job,slot_key,trigger,status,started_at,finished_at) VALUES(?,?,'agendado',?,datetime('now',?),datetime('now'))")
        ->execute([['revisao_precos', 'descoberta', 'selecao', 'analise_semanal'][$r % 4], "b$r", $r % 9 ? 'concluido' : 'falhou', '-' . intdiv($r, 6) . ' days']);
}
$ins = $pdo->prepare("INSERT INTO gmax3_job_run_items(run_id,catalog_id,marketplace,outcome,reason,created_at) VALUES(?,?,'shopee',?,'bench',datetime('now',?))");
for ($i = 0; $i < $items; $i++) {
    $ins->execute([1 + $i % 2000, 1 + $i % $n, ['validado', 'sem_alteracao', 'fora_da_revisao', 'bloqueado'][$i % 4], '-' . intdiv($i * 365, $items) . ' days']);
}
$dis = $pdo->prepare("INSERT INTO gmax3_discoveries(run_id,marketplace,external_id,outcome,seen_at) VALUES(1,'shopee',?,'novo',datetime('now',?))");
for ($i = 0; $i < intdiv($items, 4); $i++) {
    $dis->execute(["d$i", '-' . intdiv($i * 365, intdiv($items, 4)) . ' days']);
}
$pdo->exec('COMMIT');
$pdo->exec('ANALYZE');

$cut = (string)$pdo->query("SELECT datetime('now','-90 days')")->fetchColumn(); // corte fixo para as duas medições
$queries = [
    'lista_de_ofertas_ordenada' => fn() => Catalog::search($pdo, []),
    'assinatura_do_cache' => fn() => Db::one($pdo, 'SELECT COUNT(*) n, MAX(updated_at) u, MAX(id) i FROM gmax3_catalog'),
    'historico_conferido_ranking' => fn() => Ranking::historyStats($pdo),
    'execucoes_em_andamento' => fn() => Db::all($pdo, "SELECT * FROM gmax3_job_runs WHERE status='executando'"),
    'ultima_execucao_por_tarefa' => fn() => array_map(fn($j) => Db::one($pdo, 'SELECT * FROM gmax3_job_runs WHERE job=? ORDER BY id DESC LIMIT 1', [$j]), ['revisao_precos', 'descoberta', 'selecao', 'analise_semanal']),
    'simulacao_retencao_contagem' => fn() => [Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_job_run_items WHERE created_at < ?', [$cut]), Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_discoveries WHERE seen_at < ?', [$cut])],
    'relatorio_fila_30_dias' => fn() => Db::all($pdo, "SELECT substr(created_at,1,10) d, state, COUNT(*) FROM gmax3_pub_queue WHERE created_at >= datetime('now','-30 days') GROUP BY d, state"),
    'ranking_potencial_pagina1' => fn() => Ranking::board($pdo, []),
];
$measure = static function () use ($queries): array {
    $out = [];
    foreach ($queries as $k => $f) {
        $best = INF;
        for ($i = 0; $i < 5; $i++) {
            $t = hrtime(true);
            $r = $f();
            $best = min($best, (hrtime(true) - $t) / 1e6);
        }
        $out[$k] = ['ms' => round($best, 2), 'digital' => hash('sha256', json_encode($r))];
    }
    return $out;
};
$with = $measure();
$m005 = require GMAX_APP_DIR . '/gmax-lib/migrations/005_operacao.php';
foreach (array_keys($m005['indexes']) as $idx) {
    $pdo->exec("DROP INDEX IF EXISTS $idx");
}
$pdo->exec('ANALYZE');
$without = $measure();

// Gravação: 400 linhas de registro uma a uma (autocommit = um fsync por linha) × um lote só.
$write = static function (bool $batch) use ($pdo): float {
    $st = $pdo->prepare("INSERT INTO gmax3_job_run_items(run_id,marketplace,outcome,reason,created_at) VALUES(1,'shopee','fora_da_revisao','lote',datetime('now'))");
    $t = hrtime(true);
    if ($batch) {
        $pdo->exec('BEGIN IMMEDIATE');
    }
    for ($i = 0; $i < 400; $i++) {
        $st->execute();
    }
    if ($batch) {
        $pdo->exec('COMMIT');
    }
    return round((hrtime(true) - $t) / 1e6, 1);
};
$writes = ['uma_a_uma_ms' => $write(false), 'em_lote_ms' => $write(true)];

$res = ['executado_em' => date('c'), 'php' => PHP_VERSION, 'sqlite' => $pdo->query('SELECT sqlite_version()')->fetchColumn(),
    'journal_mode' => $pdo->query('PRAGMA journal_mode')->fetchColumn(), 'base' => ['produtos' => $n, 'historico' => $n * 10, 'linhas_execucao' => $items, 'descobertas' => intdiv($items, 4), 'execucoes' => 2001],
    'consultas' => array_map(fn($k) => ['sem_indices_ms' => $without[$k]['ms'], 'com_indices_ms' => $with[$k]['ms'], 'mesmo_resultado' => $without[$k]['digital'] === $with[$k]['digital']], array_combine(array_keys($queries), array_keys($queries))),
    'gravacao_400_linhas' => $writes,
    'observacao' => 'Ambiente de desenvolvimento (não é a HostGator): use para comparar, não como tempo absoluto do servidor. O ganho de gravação em lote tende a ser MAIOR em disco de hospedagem (fsync mais lento).'];
file_put_contents(__DIR__ . '/../docs/benchmark-operacao-3.2.1.json', json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";

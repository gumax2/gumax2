<?php
declare(strict_types=1);
/*
 * Benchmark reproduzível do Ranking e da Visão Geral (3.2.0).
 * Uso: php tools/benchmark_ranking.php <rotulo> [1000,10000]
 * Gera docs/benchmark-ranking-<rotulo>.json com tempos, memória e uma "impressão digital"
 * do resultado (ids, pontuações, prontidão e pendências), para provar que a otimização
 * não muda o ranking. Usa um banco isolado de teste (nunca o de produção).
 */
require __DIR__ . '/../tests/harness.php';
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';
require __DIR__ . '/bench_seed.php';

use Gmax\{Catalog, Db, Migrator, Ranking, Settings};

$label = preg_replace('/[^a-z0-9_-]/i', '', $argv[1] ?? 'teste');
$sizes = array_map('intval', explode(',', $argv[2] ?? '1000,10000'));
$out = ['rotulo' => $label, 'php' => PHP_VERSION, 'sqlite' => null, 'executado_em' => date('c'), 'bases' => []];

$time = static function (callable $f, int $reps = 3): array {
    $best = INF;
    $r = null;
    for ($i = 0; $i < $reps; $i++) {
        gc_collect_cycles();
        $t = hrtime(true);
        $r = $f();
        $best = min($best, (hrtime(true) - $t) / 1e6);
    }
    return [round($best, 1), $r];
};

foreach ($sizes as $n) {
    $pdo = bench_seed($n);
    $out['sqlite'] = $pdo->query('SELECT sqlite_version()')->fetchColumn();
    $res = ['produtos' => $n, 'historico' => $n * 10];
    foreach ([
        'ranking_potencial_pagina1' => fn() => Ranking::board($pdo, []),
        'ranking_prontas' => fn() => Ranking::board($pdo, ['modo' => 'prontas']),
        'ranking_filtros_combinados' => fn() => Ranking::board($pdo, ['marketplace' => 'shopee', 'conferido' => 'api', 'link' => 'com', 'ordem' => 'preco', 'page' => '2']),
        'visao_geral_top5' => fn() => array_slice(Ranking::list($pdo, [], 60), 0, 5),
        'visao_geral_top5_cache' => fn() => array_slice(Ranking::listCached($pdo, 60), 0, 5),
        'busca_ofertas' => fn() => Catalog::search($pdo, ['q' => 'modelo 7']),
    ] as $k => $f) {
        gc_collect_cycles();
        $m0 = memory_get_usage();
        memory_reset_peak_usage();
        [$ms, $r] = $time($f);
        $res[$k] = ['ms' => $ms, 'pico_mb' => round((memory_get_peak_usage() - $m0) / 1048576, 1)];
        if (str_starts_with($k, 'ranking')) {
            $rows = $r['rows'];
            $res[$k]['impressao_digital'] = hash('sha256', json_encode([$r['total'], $r['prontas'], array_map(fn($x) => [(int)$x['item']['id'], $x['score'], $x['confidence'], $x['pronto'], $x['pendencias'], $x['destaques']], $rows)]));
            $res[$k]['total'] = $r['total'];
            $res[$k]['prontas'] = $r['prontas'];
        } elseif (str_starts_with($k, 'visao_geral_top5')) {
            $res[$k]['impressao_digital'] = hash('sha256', json_encode(array_map(fn($x) => [(int)$x['item']['id'], $x['score'], $x['confidence']], $r)));
        }
    }
    $out['bases'][] = $res;
    Db::reset();
}
@mkdir(__DIR__ . '/../docs', 0755, true);
file_put_contents(__DIR__ . "/../docs/benchmark-ranking-$label.json", json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";

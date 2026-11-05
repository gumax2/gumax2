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
    $pdo = t_seed('hostgator');
    Migrator::up($pdo, 'bench');
    $out['sqlite'] = $pdo->query('SELECT sqlite_version()')->fetchColumn();
    Settings::set($pdo, 'publicador_v21_aposentado', '1', 'bench');
    mt_srand(42);
    $pdo->exec('BEGIN');
    $st = $pdo->prepare('INSERT INTO gmax3_catalog(marketplace,dedup_key,codigo,external_id,title,category,affiliate_url,affiliate_norm,price_original_cents,price_promo_cents,payment_condition,
        price_source,price_verified_at,price_verified_by,sales_min,rating,rating_count,seller_reputation,availability,variation_pending,validation_status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $states = ['aprovado', 'aguardando_validacao', 'rascunho', 'arquivado', 'aguardando_validacao'];
    for ($i = 0; $i < $n; $i++) {
        $mp = $i % 2 ? 'shopee' : 'mercadolivre';
        $hasLink = $i % 7 !== 0;
        $ext = $mp === 'shopee' && $i % 11 === 0 ? null : ($mp === 'shopee' ? (string)(40000000000 + $i) : 'item:MLB' . (100000 + $i));
        $aff = $hasLink ? ($mp === 'shopee' ? "https://s.shopee.com.br/b$i" : "https://meli.la/b$i") : null;
        $st->execute([$mp, "bench$i", "B$i", $ext, "Produto $i marca teste modelo " . ($i % 97), 'Cat' . ($i % 20), $aff, $aff ? ($mp === 'shopee' ? 's.shopee.com.br/b' : 'meli.la/b') . $i : null,
            $i % 3 ? 20000 + $i : null, 10000 + $i, $i % 4 ? 'normal' : null, $i % 3 ? 'api_' . $mp : 'conferencia_manual',
            $i % 5 ? date('Y-m-d H:i:s', time() - 3600 * ($i % 60)) : null, $i % 3 ? 'api:' . $mp : 'admin', $i % 6 ? $i * 3 : null, $i % 4 ? 4.0 + ($i % 10) / 10 : null, $i % 4 ? 20 + $i % 900 : null,
            $i % 8 ? 'gold' : null, $i % 9 ? 'disponivel' : 'indisponivel', (int)($i % 13 === 0), $states[$i % 5], date('Y-m-d H:i:s', time() - $i * 60), date('Y-m-d H:i:s', time() - $i * 30)]);
    }
    $h = $pdo->prepare("INSERT INTO gmax3_price_history(catalog_id,price_promo_cents,verified,source,recorded_at) VALUES(?,?,1,'api',?)");
    $base = (int)$pdo->query('SELECT MIN(id) FROM gmax3_catalog WHERE dedup_key LIKE \'bench%\'')->fetchColumn();
    for ($i = 0; $i < $n; $i++) {
        for ($k = 0; $k < 10; $k++) {
            $h->execute([$base + $i, 9000 + $k * 10 + $i % 50, date('Y-m-d H:i:s', time() - 86400 * $k)]);
        }
    }
    $q = $pdo->prepare("INSERT INTO gmax3_pub_queue(catalog_id,channel,state,scheduled_for,message_text,created_at,created_by) VALUES(?,'telegram_privado',?,?,'x',?,'bench')");
    for ($i = 0; $i < min(300, $n / 10); $i++) {
        $q->execute([$base + $i * 3, $i % 4 ? 'confirmado' : 'agendado', date('Y-m-d H:i:s', time() + 3600 * $i), date('Y-m-d H:i:s', time() - 3600 * $i)]);
    }
    $pdo->exec('COMMIT');
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

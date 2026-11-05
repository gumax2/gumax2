<?php
declare(strict_types=1);
/* Base sintética reproduzível (mt_srand 42) para benchmarks; banco isolado de teste, nunca o de produção. */
use Gmax\{Migrator, Settings};

function bench_seed(int $n): PDO
{
    $pdo = t_seed('hostgator');
    Migrator::up($pdo, 'bench');
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
    return $pdo;
}

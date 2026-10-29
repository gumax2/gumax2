<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\{Catalog, Db, Money, Time, Url, View};
/** @var PDO $pdo @var int $staleHours */
$byCat = $pdo->query("SELECT COALESCE(category,'(sem categoria)') cat, marketplace, COUNT(*) n,
    SUM(validation_status='aprovado') aprov FROM gmax3_catalog WHERE validation_status <> 'arquivado' GROUP BY cat, marketplace ORDER BY n DESC")->fetchAll();
$discounts = [];
foreach ($pdo->query("SELECT marketplace, price_original_cents o, price_promo_cents p FROM gmax3_catalog WHERE validation_status <> 'arquivado' AND price_original_cents IS NOT NULL AND price_promo_cents IS NOT NULL")->fetchAll() as $r) {
    if ($d = Money::discount((int)$r['o'], (int)$r['p'])) {
        $discounts[$r['marketplace']][] = $d['percent'];
    }
}
$since = Time::now()->modify('-30 days')->format('Y-m-d H:i:s');
$pubs = Db::all($pdo, "SELECT substr(COALESCE(finished_at, created_at),1,10) d, state, COUNT(*) n FROM gmax3_pub_queue WHERE created_at >= ? GROUP BY d, state ORDER BY d DESC", [$since]);
$imports = $pdo->query("SELECT status, COUNT(*) n, SUM(inserted) ins, SUM(updated) upd, SUM(rejected) rej FROM gmax3_import_batches GROUP BY status")->fetchAll();
$staleSince = Time::now()->modify("-$staleHours hours")->format('Y-m-d H:i:s');
$quality = Db::one($pdo, "SELECT COUNT(*) total,
    SUM(price_verified_at IS NOT NULL AND price_verified_at >= ?) conferidos,
    SUM(price_original_cents IS NOT NULL) com_original,
    SUM(payment_condition IS NOT NULL) com_condicao,
    SUM(affiliate_url IS NOT NULL) com_link,
    SUM(variation_pending = 1) variacao
    FROM gmax3_catalog WHERE validation_status NOT IN ('arquivado','rejeitado')", [$staleSince]);
$pct = static fn($n) => $quality['total'] ? (int)round((int)$n * 100 / (int)$quality['total']) : 0;
?>
<section class="grid stats">
  <div class="stat"><span class="muted">Preço conferido (≤ <?= $staleHours ?> h)</span><strong><?= $pct($quality['conferidos']) ?>%</strong></div>
  <div class="stat"><span class="muted">Com preço original</span><strong><?= $pct($quality['com_original']) ?>%</strong></div>
  <div class="stat"><span class="muted">Com condição de pagamento</span><strong><?= $pct($quality['com_condicao']) ?>%</strong></div>
  <div class="stat"><span class="muted">Com link afiliado</span><strong><?= $pct($quality['com_link']) ?>%</strong></div>
  <div class="stat<?= $quality['variacao'] ? ' stat-warn' : '' ?>"><span class="muted">Variação pendente</span><strong><?= (int)$quality['variacao'] ?></strong></div>
</section>
<section class="grid two">
<div class="card">
  <h2>Produtos por categoria</h2>
  <table class="table compact"><thead><tr><th>Categoria</th><th>Loja</th><th>Produtos</th><th>Aprovados</th></tr></thead><tbody>
  <?php foreach ($byCat as $r): ?><tr><td><?= View::e($r['cat']) ?></td><td><?= View::mp($r['marketplace']) ?></td><td><?= (int)$r['n'] ?></td><td><?= (int)$r['aprov'] ?></td></tr><?php endforeach; ?>
  </tbody></table>
</div>
<div class="card">
  <h2>Desconto real (De → Por informados)</h2>
  <table class="table compact"><thead><tr><th>Loja</th><th>Produtos</th><th>Médio</th><th>Maior</th></tr></thead><tbody>
  <?php foreach (Url::MARKETPLACES as $mp => $l): $ds = $discounts[$mp] ?? []; ?>
    <tr><td><?= View::e($l) ?></td><td><?= count($ds) ?></td><td><?= $ds ? (int)round(array_sum($ds) / count($ds)) . '%' : '—' ?></td><td><?= $ds ? max($ds) . '%' : '—' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <p class="muted small">Inclui preços ainda não conferidos. Produtos sem preço original não entram no cálculo.</p>
</div>
<div class="card">
  <h2>Publicações (30 dias)</h2>
  <?php if (!$pubs): ?><p class="muted">Nenhuma publicação registrada.</p><?php else: ?>
  <table class="table compact"><thead><tr><th>Dia</th><th>Estado</th><th>Qtde</th></tr></thead><tbody>
  <?php foreach ($pubs as $p): ?><tr><td><?= View::e($p['d']) ?></td><td><?= View::badge($p['state']) ?></td><td><?= (int)$p['n'] ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
  <p class="muted small">Cliques e conversões dependem de relatórios oficiais dos programas de afiliados (fase P2); nenhum número é estimado aqui.</p>
</div>
<div class="card">
  <h2>Importações</h2>
  <table class="table compact"><thead><tr><th>Estado</th><th>Lotes</th><th>Novos</th><th>Atualizados</th><th>Rejeitados</th></tr></thead><tbody>
  <?php foreach ($imports as $i): ?><tr><td><?= View::e($i['status']) ?></td><td><?= (int)$i['n'] ?></td><td><?= (int)$i['ins'] ?></td><td><?= (int)$i['upd'] ?></td><td><?= (int)$i['rej'] ?></td></tr><?php endforeach; ?>
  </tbody></table>
</div>
</section>

<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\{Pricing, View};
/** @var array $row @var array $r @var PDO $pdo  Detalhes expansíveis de uma linha do ranking. */
$est = Pricing::latestEstimate($pdo, $r);
?>
<div class="rank-detail-body">
  <?php if ($row['pendencias']): ?>
    <p class="small"><strong>Pendências para divulgar (<?= count($row['pendencias']) ?>)</strong></p>
    <ul class="small pend-list"><?php foreach ($row['pendencias'] as $p): ?><li><?= View::e($p) ?></li><?php endforeach; ?></ul>
  <?php else: ?><p class="small ok">✓ Passa em todas as regras de publicação.</p><?php endif; ?>
  <?php if ($est): ?><p class="small">Preço original estimado: ≈ <?= View::e(\Gmax\Money::format((int)$est['de_estimado'])) ?> <?= View::originBadge('calculado') ?> (a partir de <?= View::e((string)$est['percentual']) ?>%; não é usado na pontuação nem na divulgação)</p><?php endif; ?>
  <ul class="criteria small"><?php foreach ($row['components'] as $c): ?><li><span><?= View::e($c['criterio']) ?> (peso <?= (int)$c['peso'] ?>)</span><span><?= $c['valor'] === null ? '<em class="muted">sem dado</em>' : (int)$c['valor'] . '/100' ?></span><small class="muted"><?= View::e($c['explicacao']) ?></small></li><?php endforeach; ?></ul>
  <?php if ($row['price_note']): ?><p class="small"><?= View::e($row['price_note']) ?></p><?php endif; ?>
</div>

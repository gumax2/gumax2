<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\{Catalog, Ranking, Url, View};
/** @var PDO $pdo @var int $staleHours */
$mpFilter = isset($_GET['marketplace']) && is_string($_GET['marketplace']) && isset(Url::MARKETPLACES[$_GET['marketplace']]) ? $_GET['marketplace'] : '';
$list = Ranking::list($pdo, ['marketplace' => $mpFilter]);
?>
<section class="card">
  <h2>Critérios</h2>
  <p class="muted small">Pontuação de 0 a 100 calculada apenas com dados existentes. Pesos:
  <?php foreach (Ranking::WEIGHTS as $k => $w): ?><span class="tag"><?= View::e($k) ?> <?= (int)$w ?></span><?php endforeach; ?>.
  Critério sem dado vale zero e reduz a <strong>confiança</strong> (percentual do peso com dado real). Desconto só existe quando há preço original maior que o promocional. Nada é estimado.</p>
  <form method="get" action="painel.php" class="inline-form"><input type="hidden" name="p" value="ranking">
    <select name="marketplace" aria-label="Marketplace"><option value="">Todos os marketplaces</option><?php foreach (Url::MARKETPLACES as $k => $l): ?><option value="<?= $k ?>"<?= $mpFilter === $k ? ' selected' : '' ?>><?= View::e($l) ?></option><?php endforeach; ?></select>
    <button class="btn" type="submit">Filtrar</button></form>
</section>
<section class="card">
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>#</th><th>Pontuação</th><th>Confiança</th><th>Produto</th><th>Preço</th><th>Estado</th><th>Destaques</th></tr></thead>
    <tbody>
    <?php foreach ($list as $i => $row): $r = $row['item']; ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td data-label="Pontuação"><strong class="score"><?= $row['score'] === null ? '—' : (int)$row['score'] ?></strong></td>
        <td data-label="Confiança"><span class="conf conf-<?= $row['confidence'] >= 70 ? 'alta' : ($row['confidence'] >= 40 ? 'media' : 'baixa') ?>"><?= (int)$row['confidence'] ?>%</span></td>
        <td data-label="Produto"><?= View::mp($r['marketplace']) ?> <a href="<?= View::e(View::url('oferta', ['id' => $r['id']])) ?>"><?= View::e($r['title']) ?></a><div class="small"><?= View::freshness($r, $staleHours) ?></div></td>
        <td data-label="Preço"><?= View::priceBlock($r) ?></td>
        <td data-label="Estado"><?= View::badge($r['validation_status']) ?></td>
        <td data-label="Destaques" class="small"><?php foreach ($row['components'] as $c): if ($c['valor'] !== null): ?><div><?= View::e($c['explicacao']) ?></div><?php endif; endforeach; ?>
          <?php if ($row['price_note']): ?><div class="muted"><?= View::e($row['price_note']) ?></div><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$list): ?><tr><td colspan="7" class="muted">Sem produtos para avaliar.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

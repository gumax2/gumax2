<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\{Catalog, Money, Url, View};
/** @var PDO $pdo @var int $staleHours */
$f = [];
foreach (['marketplace', 'status', 'category', 'q', 'stale', 'nolink', 'page'] as $k) {
    if (isset($_GET[$k]) && is_string($_GET[$k]) && $_GET[$k] !== '') {
        $f[$k] = mb_substr($_GET[$k], 0, 100);
    }
}
$perPage = 30;
$res = Catalog::search($pdo, $f, $perPage, $staleHours);
$cats = $pdo->query("SELECT DISTINCT category FROM gmax3_catalog WHERE category IS NOT NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$keep = array_diff_key($f, ['page' => 1]);
?>
<form class="card filters" method="get" action="painel.php" role="search">
  <input type="hidden" name="p" value="ofertas">
  <label>Buscar <input type="search" name="q" value="<?= View::e($f['q'] ?? '') ?>" placeholder="Produto, código, MLB…"></label>
  <label>Marketplace
    <select name="marketplace"><option value="">Todos</option><?php foreach (Url::MARKETPLACES as $k => $l): ?><option value="<?= $k ?>"<?= ($f['marketplace'] ?? '') === $k ? ' selected' : '' ?>><?= View::e($l) ?></option><?php endforeach; ?></select>
  </label>
  <label>Estado
    <select name="status"><option value="">Todos (exceto arquivados)</option><?php foreach (Catalog::STATUSES as $k => $l): ?><option value="<?= $k ?>"<?= ($f['status'] ?? '') === $k ? ' selected' : '' ?>><?= View::e($l) ?></option><?php endforeach; ?></select>
  </label>
  <label>Categoria
    <select name="category"><option value="">Todas</option><?php foreach ($cats as $c): ?><option<?= ($f['category'] ?? '') === $c ? ' selected' : '' ?>><?= View::e($c) ?></option><?php endforeach; ?></select>
  </label>
  <label class="check"><input type="checkbox" name="stale" value="1"<?= !empty($f['stale']) ? ' checked' : '' ?>> Preço desatualizado</label>
  <label class="check"><input type="checkbox" name="nolink" value="1"<?= !empty($f['nolink']) ? ' checked' : '' ?>> Sem link afiliado</label>
  <div class="filter-actions"><button type="submit" class="btn btn-primary">Filtrar</button> <a class="btn btn-ghost" href="<?= View::e(View::url('ofertas')) ?>">Limpar</a>
  <a class="btn btn-ghost" href="<?= View::e(View::url('oferta')) ?>">+ Novo produto</a> <a class="btn btn-ghost" href="<?= View::e(View::url('exportar')) ?>">Exportar CSV</a></div>
</form>

<form method="post" action="painel.php" class="card" data-loading id="bulkform">
  <?= View::csrfField() ?>
  <input type="hidden" name="action" value="bulk"><input type="hidden" name="back" value="ofertas">
  <div class="bulkbar">
    <span class="muted"><?= $res['total'] ?> produto(s)</span>
    <label class="check"><input type="checkbox" data-checkall="ids[]"> Selecionar página</label>
    <select name="op" required aria-label="Ação em lote">
      <option value="">Ação em lote…</option>
      <option value="aprovar">Aprovar</option>
      <option value="aguardando">Voltar para validação</option>
      <option value="rejeitar">Rejeitar</option>
      <option value="arquivar">Arquivar</option>
      <option value="enfileirar">Adicionar à fila (próximo horário)</option>
    </select>
    <button type="submit" class="btn">Aplicar</button>
  </div>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th></th><th>Produto</th><th>Loja</th><th>Preço</th><th>Condição</th><th>Estado</th><th>Link</th></tr></thead>
    <tbody>
    <?php foreach ($res['rows'] as $r): ?>
      <tr>
        <td><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" aria-label="Selecionar #<?= (int)$r['id'] ?>"></td>
        <td data-label="Produto"><a href="<?= View::e(View::url('oferta', ['id' => $r['id']])) ?>"><?= View::e($r['title']) ?></a>
          <div class="muted small">#<?= (int)$r['id'] ?> <?= View::e($r['codigo'] ?? '') ?> <?= View::e($r['category'] ?? '') ?></div>
          <div class="small"><?= View::freshness($r, $staleHours) ?></div></td>
        <td data-label="Loja"><?= View::mp($r['marketplace']) ?></td>
        <td data-label="Preço"><?= View::priceBlock($r) ?></td>
        <td data-label="Condição"><?= View::e(Catalog::PAYMENT[$r['payment_condition'] ?? ''] ?? 'não identificada') ?></td>
        <td data-label="Estado"><?= View::badge($r['validation_status']) ?><?= $r['variation_pending'] ? ' <span class="tag tag-warn">variação</span>' : '' ?></td>
        <td data-label="Link"><?= Url::isAffiliate($r['marketplace'], $r['affiliate_url']) ? '<span class="ok">✓ oficial</span>' : '<span class="warn">ausente</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$res['rows']): ?><tr><td colspan="7" class="muted">Nenhum produto encontrado com esses filtros.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</form>
<?= View::pager($res['total'], $perPage, (int)($f['page'] ?? 1), $keep, 'ofertas') ?>

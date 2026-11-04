<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\{Catalog, Db, Message, Money, Url, View};
/** @var PDO $pdo @var int $staleHours */
$f = [];
foreach (['marketplace', 'status', 'category', 'q', 'stale', 'nolink', 'avail', 'variation', 'page'] as $k) {
    if (isset($_GET[$k]) && is_string($_GET[$k]) && $_GET[$k] !== '') {
        $f[$k] = mb_substr($_GET[$k], 0, 100);
    }
}
$_SESSION['gmax3_ofertas_f'] = $f; // "Voltar aos resultados" (só na sessão do administrador)
$perPage = 30;
$res = Catalog::search($pdo, $f, $perPage, $staleHours);
$cats = $pdo->query("SELECT DISTINCT category FROM gmax3_catalog WHERE category IS NOT NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$keep = array_diff_key($f, ['page' => 1]);
$active = count(array_diff_key($keep, ['q' => 1]));
// Preço anterior confirmado (último registro conferido diferente do preço atual).
$prev = [];
if ($res['rows']) {
    $ids = array_map(static fn($r) => (int)$r['id'], $res['rows']);
    $cur = array_column($res['rows'], 'price_promo_cents', 'id');
    foreach (Db::all($pdo, 'SELECT catalog_id, price_promo_cents FROM gmax3_price_history WHERE verified=1 AND catalog_id IN (' . implode(',', $ids) . ') ORDER BY id DESC') as $h) {
        $cid = (int)$h['catalog_id'];
        if (!isset($prev[$cid]) && $h['price_promo_cents'] !== null && (int)$h['price_promo_cents'] !== (int)$cur[$cid]) {
            $prev[$cid] = (int)$h['price_promo_cents'];
        }
    }
}
$label = static fn(string $k, string $v) => match ($k) {
    'marketplace' => Url::MARKETPLACES[$v] ?? $v, 'status' => Catalog::STATUSES[$v] ?? $v, 'avail' => Catalog::AVAILABILITY[$v] ?? $v,
    'stale' => 'preço desatualizado', 'nolink' => 'sem link afiliado', 'variation' => 'variação pendente', default => $v,
};
?>
<details class="card filters-box" open<?= $active ? '' : ' data-collapse-mobile' ?>>
  <summary>Filtros<?= $active ? '<span class="filter-count">' . $active . '</span>' : '' ?></summary>
  <form class="filters" method="get" action="painel.php" role="search" aria-label="Filtrar ofertas">
    <input type="hidden" name="p" value="ofertas">
    <label>Buscar <input type="search" name="q" value="<?= View::e($f['q'] ?? '') ?>" placeholder="Produto, código, MLB, Item Id…" enterkeyhint="search"></label>
    <label>Marketplace
      <select name="marketplace"><option value="">Todos</option><?php foreach (Url::MARKETPLACES as $k => $l): ?><option value="<?= $k ?>"<?= ($f['marketplace'] ?? '') === $k ? ' selected' : '' ?>><?= View::e($l) ?></option><?php endforeach; ?></select>
    </label>
    <label>Estado
      <select name="status"><option value="">Todos (exceto arquivados)</option><?php foreach (Catalog::STATUSES as $k => $l): ?><option value="<?= $k ?>"<?= ($f['status'] ?? '') === $k ? ' selected' : '' ?>><?= View::e($l) ?></option><?php endforeach; ?></select>
    </label>
    <label>Categoria
      <select name="category"><option value="">Todas</option><?php foreach ($cats as $c): ?><option<?= ($f['category'] ?? '') === $c ? ' selected' : '' ?>><?= View::e($c) ?></option><?php endforeach; ?></select>
    </label>
    <label>Disponibilidade
      <select name="avail"><option value="">Todas</option><?php foreach (Catalog::AVAILABILITY as $k => $l): ?><option value="<?= $k ?>"<?= ($f['avail'] ?? '') === $k ? ' selected' : '' ?>><?= View::e($l) ?></option><?php endforeach; ?></select>
    </label>
    <label class="check"><input type="checkbox" name="stale" value="1"<?= !empty($f['stale']) ? ' checked' : '' ?>> Preço desatualizado</label>
    <label class="check"><input type="checkbox" name="nolink" value="1"<?= !empty($f['nolink']) ? ' checked' : '' ?>> Sem link afiliado</label>
    <label class="check"><input type="checkbox" name="variation" value="1"<?= !empty($f['variation']) ? ' checked' : '' ?>> Variação pendente</label>
    <div class="filter-actions"><button type="submit" class="btn btn-primary">Filtrar</button> <a class="btn btn-ghost" href="<?= View::e(View::url('ofertas')) ?>">Limpar</a>
    <a class="btn btn-ghost" href="<?= View::e(View::url('oferta')) ?>">+ Novo produto</a> <a class="btn btn-ghost" href="<?= View::e(View::url('exportar')) ?>">Exportar CSV</a></div>
  </form>
</details>

<div class="results-head" data-scroll-key="<?= View::e(substr(hash('sha256', http_build_query($f)), 0, 16)) ?>">
  <span><strong><?= $res['total'] ?></strong> produto(s)<?php foreach ($keep as $k => $v): ?> <span class="tag"><?= View::e($label($k, (string)$v)) ?></span><?php endforeach; ?></span>
  <a class="btn btn-sm only-mobile" href="<?= View::e(View::url('oferta')) ?>">+ Novo</a>
</div>

<?php if (!$res['rows']): ?>
  <div class="card empty"><strong>Nenhum produto encontrado</strong><?= $keep ? 'Nenhum produto corresponde aos filtros escolhidos. <a href="' . View::e(View::url('ofertas')) . '">Limpar filtros</a>.' : 'Importe um lote em <a href="' . View::e(View::url('importar')) . '">Importar</a> ou cadastre um produto.' ?></div>
<?php else: ?>

<ul class="offer-cards only-mobile" role="list" aria-label="Produtos">
<?php foreach ($res['rows'] as $r):
    $id = (int)$r['id'];
    $src = Catalog::verificationSource($r);
    $d = Money::discount($r['price_original_cents'] === null ? null : (int)$r['price_original_cents'], $r['price_promo_cents'] === null ? null : (int)$r['price_promo_cents']);
?>
  <li class="offer-card" id="p<?= $id ?>">
    <div class="offer-head">
      <span class="thumb thumb-<?= View::e($r['marketplace']) ?>" aria-hidden="true"><?= $r['marketplace'] === 'shopee' ? '🛍️' : '🛒' ?></span>
      <div>
        <a class="offer-title" href="<?= View::e(View::url('oferta', ['id' => $id])) ?>"><?= View::e($r['title']) ?></a>
        <div class="offer-meta"><?= View::mp($r['marketplace']) ?> <?= View::badge($r['validation_status']) ?>
          <?= $r['variation_pending'] ? '<span class="tag tag-warn">⚠ variação pendente</span>' : '' ?>
          <?= ($r['availability'] ?? '') === 'indisponivel' ? '<span class="tag tag-warn">indisponível</span>' : '' ?></div>
      </div>
    </div>
    <div>
      <?= View::priceBlock($r) ?>
      <div class="offer-facts">
        <?php if (isset($prev[$id])): ?><span>Antes (conferido): <?= View::e(Money::format($prev[$id])) ?></span><?php endif; ?>
        <?php if ($d): ?><span><?= $src ? 'Desconto validado' : 'Desconto não conferido' ?></span><?php endif; ?>
        <span><?= View::affiliateUrl($r) ? '<span class="ok">✓ link afiliado</span>' : '<span class="warn">sem link afiliado</span>' ?></span>
      </div>
      <div><?= View::freshness($r, $staleHours) ?></div>
    </div>
    <div class="offer-quick">
      <a class="btn btn-primary" href="<?= View::e(View::url('conferir', ['id' => $id])) ?>"><span aria-hidden="true">✓</span> Conferir preço<span class="sr-only"> de <?= View::e(mb_substr((string)$r['title'], 0, 80)) ?></span></a>
      <a class="btn" href="<?= View::e(View::url('oferta', ['id' => $id])) ?>">Editar<span class="sr-only"> <?= View::e(mb_substr((string)$r['title'], 0, 80)) ?></span></a>
    </div>
    <?= View::storeButtons($r, '', (string)$r['title']) ?>
    <div class="offer-more">
      <pre class="sr-only" id="msg-c<?= $id ?>"><?= View::e(Message::render($r, $pdo)) ?></pre>
      <button type="button" class="btn btn-sm" data-copy="#msg-c<?= $id ?>" aria-live="polite">Copiar mensagem<span class="sr-only"> de <?= View::e(mb_substr((string)$r['title'], 0, 80)) ?></span></button>
      <?php if ($r['validation_status'] !== 'arquivado'): ?>
        <?= View::actionButton('offer_status', ['id' => $id, 'status' => 'arquivado', 'back' => 'ofertas'], 'Arquivar', 'btn btn-ghost btn-sm', 'Arquivar "' . mb_substr((string)$r['title'], 0, 60) . '"? O histórico é preservado e o produto pode ser restaurado.') ?>
      <?php endif; ?>
    </div>
  </li>
<?php endforeach; ?>
</ul>

<form method="post" action="painel.php" class="card only-desktop" data-loading id="bulkform" data-confirm="Aplicar a ação escolhida aos produtos selecionados?">
  <?= View::csrfField() ?>
  <input type="hidden" name="action" value="bulk"><input type="hidden" name="back" value="ofertas">
  <div class="bulkbar">
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
    <thead><tr><th><span class="sr-only">Selecionar</span></th><th>Produto</th><th>Loja</th><th>Preço</th><th>Condição</th><th>Estado</th><th class="col-actions">Ações</th></tr></thead>
    <tbody>
    <?php foreach ($res['rows'] as $r): $id = (int)$r['id']; ?>
      <tr id="t<?= $id ?>">
        <td><input type="checkbox" name="ids[]" value="<?= $id ?>" aria-label="Selecionar #<?= $id ?>"></td>
        <td><a href="<?= View::e(View::url('oferta', ['id' => $id])) ?>"><?= View::e($r['title']) ?></a>
          <div class="muted small">#<?= $id ?> <?= View::e($r['codigo'] ?? '') ?> <?= View::e($r['category'] ?? '') ?></div>
          <div class="small"><?= View::freshness($r, $staleHours) ?></div></td>
        <td><?= View::mp($r['marketplace']) ?></td>
        <td><?= View::priceBlock($r) ?><?= isset($prev[$id]) ? '<div class="muted small">antes: ' . View::e(Money::format($prev[$id])) . '</div>' : '' ?></td>
        <td><?= View::e(Catalog::PAYMENT[$r['payment_condition'] ?? ''] ?? 'não identificada') ?></td>
        <td><?= View::badge($r['validation_status']) ?><?= $r['variation_pending'] ? ' <span class="tag tag-warn">variação</span>' : '' ?><?= ($r['availability'] ?? '') === 'indisponivel' ? ' <span class="tag tag-warn">indisponível</span>' : '' ?><?= View::affiliateUrl($r) ? '' : ' <span class="tag tag-warn">sem link afiliado</span>' ?></td>
        <td class="col-actions"><a class="btn btn-sm btn-primary btn-block" href="<?= View::e(View::url('conferir', ['id' => $id])) ?>">Conferir<span class="sr-only"> preço de <?= View::e(mb_substr((string)$r['title'], 0, 80)) ?></span></a><?= View::storeButtons($r, '', (string)$r['title']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</form>
<?php endif; ?>
<?= View::pager($res['total'], $perPage, (int)($f['page'] ?? 1), $keep, 'ofertas') ?>

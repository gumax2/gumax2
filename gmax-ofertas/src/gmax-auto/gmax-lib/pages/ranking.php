<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\{Catalog, Money, Pricing, Ranking, Url, View};
use Gmax\Connectors\EndpointGuard;
/** @var PDO $pdo @var int $staleHours */
$keys = ['modo', 'marketplace', 'category', 'status', 'preco_min', 'preco_max', 'score_min', 'conf_min', 'conferido', 'dias', 'stale', 'link', 'variation', 'avail', 'pronto', 'ordem', 'page'];
$f = [];
foreach ($keys as $k) {
    if (isset($_GET[$k]) && is_string($_GET[$k]) && $_GET[$k] !== '') {
        $f[$k] = mb_substr(trim($_GET[$k]), 0, 40);
    }
}
$_SESSION['gmax3_ranking_f'] = $f; // retorno ao mesmo modo, filtros e página depois de "Aprovar"
$mode = isset(Ranking::MODES[$f['modo'] ?? '']) ? $f['modo'] : 'potencial';
$res = Ranking::board($pdo, $f);
$keep = array_diff_key($f, ['page' => 1]);
$active = count(array_diff_key($keep, ['modo' => 1, 'ordem' => 1]));
$cats = $pdo->query("SELECT DISTINCT category FROM gmax3_catalog WHERE category IS NOT NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$sel = static fn(string $k, string $v) => ($f[$k] ?? '') === $v ? ' selected' : '';
$confClass = static fn(int $c) => $c >= 70 ? 'alta' : ($c >= 40 ? 'media' : 'baixa');
$mlSuspended = EndpointGuard::suspendedList($pdo);
$short = static fn(string $s) => mb_strlen($s) > 70 ? mb_substr($s, 0, 68) . '…' : $s;
$approveOk = static fn(array $row) => $row['item']['validation_status'] !== 'aprovado'
    && array_values(array_filter($row['pendencias'], static fn($p) => !str_starts_with($p, 'Oferta não aprovada'))) === [];
?>
<nav class="seg-tabs" aria-label="Modo do ranking">
  <?php foreach (Ranking::MODES as $k => $label): ?>
    <a href="<?= View::e(View::url('ranking', ['modo' => $k] + array_diff_key($keep, ['modo' => 1]))) ?>"<?= $mode === $k ? ' class="active" aria-current="page"' : '' ?>><?= View::e($label) ?><?= $k === 'prontas' ? ' <span class="filter-count">' . (int)$res['prontas'] . '</span>' : '' ?></a>
  <?php endforeach; ?>
</nav>
<p class="small muted"><?= $mode === 'prontas'
    ? 'Somente produtos que passam em <strong>todas</strong> as regras de publicação: aprovado, preço conferido dentro do prazo, “De” verificável, link afiliado oficial, disponível, variação definida, sem duplicidade e permitido no destino.'
    : 'Potencial comercial calculado só com dados existentes. Produtos não validados aparecem com suas pendências: <strong>pontuação alta não é autorização para publicar</strong>.' ?></p>
<p class="small sources"><span class="tag">Shopee: API de afiliados (preço e link oficial)</span>
  <span class="tag<?= $mlSuspended ? ' tag-warn' : '' ?>">Mercado Livre: <?= $mlSuspended ? 'consultas de anúncios suspensas (HTTP 403) — conferência manual' : 'conferência manual / API quando autorizada' ?></span></p>

<details class="card filters-box" open<?= $active ? '' : ' data-collapse-mobile' ?>>
  <summary>Filtros<?= $active ? '<span class="filter-count">' . $active . '</span>' : '' ?></summary>
  <form class="filters filters-rank" method="get" action="painel.php" aria-label="Filtrar ranking">
    <input type="hidden" name="p" value="ranking"><input type="hidden" name="modo" value="<?= View::e($mode) ?>">
    <label>Marketplace<select name="marketplace"><option value="">Todos</option><?php foreach (Url::MARKETPLACES as $k => $l): ?><option value="<?= $k ?>"<?= $sel('marketplace', $k) ?>><?= View::e($l) ?></option><?php endforeach; ?></select></label>
    <label>Categoria<select name="category"><option value="">Todas</option><?php foreach ($cats as $c): ?><option<?= $sel('category', (string)$c) ?>><?= View::e($c) ?></option><?php endforeach; ?></select></label>
    <label>Estado<select name="status"><option value="">Todos (exceto arquivados)</option><?php foreach (Catalog::STATUSES as $k => $l): if (in_array($k, ['rejeitado', 'arquivado'], true)) continue; ?><option value="<?= $k ?>"<?= $sel('status', $k) ?>><?= View::e($l) ?></option><?php endforeach; ?></select></label>
    <label>Disponibilidade<select name="avail"><option value="">Todas</option><?php foreach (Catalog::AVAILABILITY as $k => $l): ?><option value="<?= $k ?>"<?= $sel('avail', $k) ?>><?= View::e($l) ?></option><?php endforeach; ?></select></label>
    <label>Preço de (R$)<input name="preco_min" inputmode="decimal" placeholder="20,00" value="<?= View::e($f['preco_min'] ?? '') ?>"></label>
    <label>Preço até (R$)<input name="preco_max" inputmode="decimal" placeholder="300,00" value="<?= View::e($f['preco_max'] ?? '') ?>"></label>
    <label>Pontuação mínima<input name="score_min" inputmode="numeric" placeholder="50" maxlength="3" value="<?= View::e($f['score_min'] ?? '') ?>"></label>
    <label>Confiança mínima (%)<input name="conf_min" inputmode="numeric" placeholder="60" maxlength="3" value="<?= View::e($f['conf_min'] ?? '') ?>"></label>
    <label>Conferência do preço<select name="conferido"><option value="">Qualquer</option><option value="api"<?= $sel('conferido', 'api') ?>>Confirmado pela API</option><option value="manual"<?= $sel('conferido', 'manual') ?>>Conferido manualmente</option><option value="nunca"<?= $sel('conferido', 'nunca') ?>>Nunca conferido</option></select></label>
    <label>Conferido nos últimos<select name="dias"><option value="">Qualquer data</option><?php foreach ([1 => '24 horas', 3 => '3 dias', 7 => '7 dias', 30 => '30 dias'] as $d => $l): ?><option value="<?= $d ?>"<?= $sel('dias', (string)$d) ?>><?= $l ?></option><?php endforeach; ?></select></label>
    <label>Link afiliado<select name="link"><option value="">Com ou sem</option><option value="com"<?= $sel('link', 'com') ?>>Presente</option><option value="sem"<?= $sel('link', 'sem') ?>>Ausente</option></select></label>
    <label>Pronto para divulgar<select name="pronto"><option value="">Todos</option><option value="sim"<?= $sel('pronto', 'sim') ?>>Sim</option><option value="nao"<?= $sel('pronto', 'nao') ?>>Não (com pendências)</option></select></label>
    <label>Ordenar por<select name="ordem"><?php foreach (Ranking::SORTS as $k => $l): ?><option value="<?= $k ?>"<?= $sel('ordem', $k) ?>><?= View::e($l) ?></option><?php endforeach; ?></select></label>
    <label class="check"><input type="checkbox" name="stale" value="1"<?= !empty($f['stale']) ? ' checked' : '' ?>> Preço desatualizado</label>
    <label class="check"><input type="checkbox" name="variation" value="1"<?= !empty($f['variation']) ? ' checked' : '' ?>> Variação pendente</label>
    <div class="filter-actions"><button type="submit" class="btn btn-primary">Aplicar filtros</button>
      <a class="btn btn-ghost" href="<?= View::e(View::url('ranking', ['modo' => $mode])) ?>">Limpar tudo</a></div>
  </form>
</details>

<p class="results-head"><span><strong><?= (int)$res['total'] ?></strong> produto(s) · <?= (int)$res['prontas'] ?> pronto(s) para divulgar entre os filtrados</span></p>

<?php if (!$res['rows']): ?>
  <div class="card empty"><strong><?= $mode === 'prontas' ? 'Nenhum produto pronto para divulgar' : 'Nenhum produto encontrado' ?></strong>
    <?= $mode === 'prontas' ? 'Veja as pendências no modo <a href="' . View::e(View::url('ranking', ['modo' => 'potencial'])) . '">Potencial comercial</a>: em geral faltam conferência de preço, link afiliado ou aprovação.' : 'Ajuste ou limpe os filtros.' ?></div>
<?php else: ?>

<ol class="rank-cards only-mobile" role="list" aria-label="Ranking">
<?php foreach ($res['rows'] as $i => $row): $r = $row['item']; $id = (int)$r['id']; $pos = $res['offset'] + $i + 1;
    $d = Money::discount($r['price_original_cents'] === null ? null : (int)$r['price_original_cents'], $r['price_promo_cents'] === null ? null : (int)$r['price_promo_cents']); ?>
  <li class="rank-card<?= $row['pronto'] ? ' is-ready' : '' ?>" id="r<?= $id ?>">
    <div class="rank-top">
      <span class="rank-pos" aria-label="Posição <?= $pos ?>"><?= $pos ?></span>
      <div class="rank-main">
        <a class="offer-title" href="<?= View::e(View::url('oferta', ['id' => $id])) ?>"><?= View::e($r['title']) ?></a>
        <div class="offer-meta"><?= View::mp($r['marketplace']) ?> <?= View::badge($r['validation_status']) ?></div>
      </div>
      <div class="rank-score"><strong class="score"><?= $row['score'] === null ? '—' : (int)$row['score'] ?></strong><span class="conf conf-<?= $confClass($row['confidence']) ?>"><?= (int)$row['confidence'] ?>% conf.</span></div>
    </div>
    <div class="rank-price"><?= View::priceBlock($r) ?><?= $d ? ' ' . View::originBadge(Pricing::originalSource($r)) : '' ?></div>
    <div class="small"><?= View::freshness($r, $staleHours) ?></div>
    <p class="rank-status small"><?= $row['pronto'] ? '<span class="tag tag-ok">✓ Pronto para divulgar</span>' : '<span class="tag tag-warn">Pendência</span> ' . View::e($short($row['pendencias'][0])) . (count($row['pendencias']) > 1 ? ' <span class="muted">+' . (count($row['pendencias']) - 1) . '</span>' : '') ?></p>
    <div class="offer-quick">
      <a class="btn btn-primary" href="<?= View::e(View::url('conferir', ['id' => $id])) ?>">Conferência rápida<span class="sr-only"> de <?= View::e(mb_substr((string)$r['title'], 0, 80)) ?></span></a>
      <a class="btn" href="<?= View::e(View::url('oferta', ['id' => $id])) ?>">Editar<span class="sr-only"> <?= View::e(mb_substr((string)$r['title'], 0, 80)) ?></span></a>
    </div>
    <?= View::storeButtons($r, '', (string)$r['title']) ?>
    <?php if ($approveOk($row)): ?><?= View::actionButton('offer_status', ['id' => $id, 'status' => 'aprovado', 'back' => 'ranking'], 'Aprovar', 'btn btn-sm', 'Aprovar este produto? Todas as validações exigidas estão satisfeitas; o envio continua bloqueado pelas travas.') ?><?php endif; ?>
    <details class="rank-details"><summary>Detalhes da pontuação e pendências</summary><?php include __DIR__ . '/_ranking_detalhes.php'; ?></details>
  </li>
<?php endforeach; ?>
</ol>

<section class="card only-desktop">
  <div class="table-wrap">
  <table class="table rank-table">
    <thead><tr><th>#</th><th>Pont.</th><th>Conf.</th><th>Produto</th><th class="col-price">Preço</th><th>Situação</th><th>Destaques</th><th class="col-actions">Ações</th></tr></thead>
    <tbody>
    <?php foreach ($res['rows'] as $i => $row): $r = $row['item']; $id = (int)$r['id']; ?>
      <tr id="t<?= $id ?>"<?= $row['pronto'] ? ' class="row-ready"' : '' ?>>
        <td><?= $res['offset'] + $i + 1 ?></td>
        <td><strong class="score"><?= $row['score'] === null ? '—' : (int)$row['score'] ?></strong></td>
        <td><span class="conf conf-<?= $confClass($row['confidence']) ?>"><?= (int)$row['confidence'] ?>%</span></td>
        <td class="col-product"><?= View::mp($r['marketplace']) ?> <a href="<?= View::e(View::url('oferta', ['id' => $id])) ?>"><?= View::e($r['title']) ?></a>
          <div class="small"><?= View::freshness($r, $staleHours) ?> · <?= View::badge($r['validation_status']) ?></div>
          <details class="rank-details"><summary>Detalhes</summary><?php include __DIR__ . '/_ranking_detalhes.php'; ?></details></td>
        <td class="col-price"><?= View::priceBlock($r) ?><?= !empty($r['price_original_cents']) ? View::originBadge(Pricing::originalSource($r)) : '' ?></td>
        <td class="small"><?= $row['pronto'] ? '<span class="tag tag-ok">✓ Pronto</span>' : '<span class="tag tag-warn">' . count($row['pendencias']) . ' pendência(s)</span><div>' . View::e($short($row['pendencias'][0])) . '</div>' ?></td>
        <td class="small"><?php foreach (array_slice($row['destaques'], 0, 3) as $h): ?><span class="tag"><?= View::e($h) ?></span><?php endforeach; ?><?= $row['destaques'] ? '' : '<span class="muted">sem dados</span>' ?></td>
        <td class="col-actions"><a class="btn btn-sm btn-primary btn-block" href="<?= View::e(View::url('conferir', ['id' => $id])) ?>">Conferir<span class="sr-only"> preço de <?= View::e(mb_substr((string)$r['title'], 0, 80)) ?></span></a>
          <?= View::storeButtons($r, '', (string)$r['title']) ?>
          <?php if ($approveOk($row)): ?><?= View::actionButton('offer_status', ['id' => $id, 'status' => 'aprovado', 'back' => 'ranking'], 'Aprovar', 'btn btn-sm btn-block', 'Aprovar este produto? Todas as validações exigidas estão satisfeitas; o envio continua bloqueado pelas travas.') ?><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>
<?php if ($res['pages'] > 1): ?>
<nav class="pager" aria-label="Paginação do ranking">
  <?php for ($p = 1; $p <= $res['pages']; $p++): ?><?= $p === $res['page'] ? '<span class="current" aria-current="page">' . $p . '</span>' : '<a href="' . View::e(View::url('ranking', ['page' => $p] + $keep)) . '">' . $p . '</a>' ?><?php endfor; ?>
</nav>
<?php endif; ?>
<details class="card"><summary>Como a pontuação é calculada</summary>
  <p class="muted small">Pontuação de 0 a 100, calculada só com dados existentes. Pesos:
  <?php foreach (Ranking::WEIGHTS as $k => $w): ?><span class="tag"><?= View::e($k) ?> <?= (int)$w ?></span><?php endforeach; ?>.
  Critério sem dado vale zero e reduz a <strong>confiança</strong>. O desconto usa só o preço original gravado; preço original <strong>estimado</strong> pelo % nunca entra na pontuação nem na divulgação.</p>
</details>

<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\{Money, MlCandidates, Settings, Time, View};
/** @var PDO $pdo  Candidatos do catálogo do Mercado Livre (3.1.9): separados das ofertas. */
$f = [];
foreach (['status', 'preco', 'q', 'seg', 'ordem', 'pg'] as $k) {
    if (isset($_GET[$k]) && is_string($_GET[$k]) && $_GET[$k] !== '') {
        $f[$k] = mb_substr(trim($_GET[$k]), 0, 60);
    }
}
$f += ['status' => 'novo'];
if (isset($f['seg']) && !isset(Gmax\Text::SEGMENTS[$f['seg']])) {
    unset($f['seg']);
}
$on = MlCandidates::enabled($pdo);
$res = $on ? MlCandidates::browse($pdo, $f) : ['rows' => [], 'total' => 0, 'pages' => 1, 'page' => 1];
$rows = $res['rows'];
$segName = static fn(?string $k) => $k ? Gmax\Text::SEGMENTS[$k][0] : 'sem segmento';
$insp = json_decode(Settings::get($pdo, 'ml_campos_ultimo', ''), true);
$insp = is_array($insp) ? $insp : null;
$ind = $insp['indicadores'] ?? [];
$yes = static fn(?bool $v) => $v === null ? '<span class="muted">não testado</span>' : ($v ? '<span class="tag tag-ok">sim</span>' : '<span class="tag tag-warn">não</span>');
?>
<div class="alert alert-aviso" role="note"><strong>Candidatos não são ofertas.</strong> Vêm do catálogo oficial do Mercado Livre (<code>/products</code>). Não entram no ranking de prontas, na fila nem em mensagens. O <strong>ID de catálogo</strong> é diferente do <strong>ID de anúncio</strong>. Um preço só aparece se a API informar o anúncio vencedor e <strong>nunca</strong> é tratado como conferido.</div>

<section class="card">
  <h2>Viabilidade: campos devolvidos pela API (inspeção no servidor)</h2>
  <?php if (!$insp): ?><p class="muted small">Ainda não inspecionado. A inspeção é somente leitura e grava só os nomes e tipos dos campos, sem valores.</p><?php else: ?>
  <p class="small">Inspeção de <?= View::e(Time::show((string)$insp['em'])) ?> · termo “<?= View::e((string)$insp['termo']) ?>”</p>
  <div class="table-wrap"><table class="table compact"><tbody>
    <tr><td>Pesquisa por palavra-chave no catálogo</td><td><?= $yes($ind['busca_por_palavra'] ?? null) ?></td></tr>
    <tr><td>Resultados com imagem oficial</td><td><?= $yes($ind['busca_tem_imagem'] ?? null) ?></td></tr>
    <tr><td>Resultados da pesquisa com preço</td><td><?= $yes($ind['busca_tem_preco'] ?? null) ?></td></tr>
    <tr><td>Produto com anúncio vencedor (buy_box_winner)</td><td><?= $yes($ind['produto_tem_vencedor'] ?? null) ?></td></tr>
    <tr><td>Anúncio vencedor com preço</td><td><?= $yes($ind['vencedor_tem_preco'] ?? null) ?></td></tr>
    <tr><td>Anúncio vencedor com ID MLB de anúncio</td><td><?= $yes($ind['vencedor_tem_anuncio'] ?? null) ?></td></tr>
    <tr><td>Produto com link oficial (permalink)</td><td><?= $yes($ind['produto_tem_permalink'] ?? null) ?></td></tr>
  </tbody></table></div>
  <details><summary>Campos por recurso (estrutura real, sem valores)</summary>
    <?php foreach ($insp['recursos'] as $k => $r): ?>
      <p class="small"><strong><?= View::e($r['endpoint']) ?></strong> · HTTP <?= View::e((string)($r['http'] ?? '—')) ?><?= $r['mensagem_api'] ? ' · ' . View::e($r['mensagem_api']) : '' ?></p>
      <?php if ($r['campos']): ?><pre class="msg small"><?= View::e(implode("\n", $r['campos'])) ?></pre><?php endif; ?>
    <?php endforeach; ?>
  </details>
  <?php endif; ?>
  <form method="post" action="painel.php" class="inline-form" data-loading>
    <?= View::csrfField() ?><input type="hidden" name="action" value="ml_inspect"><input type="hidden" name="back" value="candidatos">
    <input name="termo" maxlength="60" placeholder="Termo de teste (ex.: fone de ouvido)" aria-label="Termo de teste">
    <button class="btn" type="submit">Inspecionar campos (somente leitura)</button>
  </form>
</section>

<section class="card">
  <h2>Buscar candidatos</h2>
  <?php if (!$on): ?><div class="alert alert-aviso">Aplique a migração 004 em Sistema para registrar candidatos.</div><?php else: ?>
  <p class="small muted">Pesquisa no catálogo os termos de “Pesquisa e limites” (Automações → Mercado Livre) e as tendências de busca do Mercado Livre. Usa o limite de requisições e a suspensão automática. Nada é publicado.</p>
  <?= View::actionButton('ml_candidates_run', ['back' => 'candidatos'], 'Buscar candidatos agora', 'btn btn-primary', 'Pesquisar candidatos no catálogo do Mercado Livre agora? (somente leitura; nenhuma oferta é criada)') ?>
  <?php endif; ?>
</section>

<?php if ($on): ?>
<details class="card filters-box" open data-collapse-mobile>
  <summary>Filtros</summary>
  <form class="filters" method="get" action="painel.php"><input type="hidden" name="p" value="candidatos">
    <label>Situação<select name="status"><?php foreach (MlCandidates::STATUSES + ['avaliados' => 'Já avaliados', 'todos' => 'Todos'] as $k => $l): ?><option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= View::e($l) ?></option><?php endforeach; ?></select></label>
    <label>Segmento<select name="seg"><option value="">Todos</option><?php foreach (Gmax\Text::SEGMENTS as $k => [$l]): ?><option value="<?= $k ?>"<?= ($f['seg'] ?? '') === $k ? ' selected' : '' ?>><?= View::e($l) ?></option><?php endforeach; ?></select></label>
    <label>Ordem<select name="ordem"><option value="">Preço informado e tendência</option><option value="segmento"<?= ($f['ordem'] ?? '') === 'segmento' ? ' selected' : '' ?>>Prioridade por segmento</option></select></label>
    <label>Preço da API<select name="preco"><option value="">Com ou sem</option><option value="com"<?= ($f['preco'] ?? '') === 'com' ? ' selected' : '' ?>>Informado (não conferido)</option><option value="sem"<?= ($f['preco'] ?? '') === 'sem' ? ' selected' : '' ?>>Sem preço</option></select></label>
    <label>Buscar<input type="search" name="q" value="<?= View::e($f['q'] ?? '') ?>" placeholder="Nome, termo ou ID de catálogo"></label>
    <div class="filter-actions"><button class="btn btn-primary" type="submit">Filtrar</button> <a class="btn btn-ghost" href="<?= View::e(View::url('candidatos')) ?>">Limpar</a></div>
  </form>
</details>
<?php if (!$rows): ?>
  <div class="card empty"><strong>Nenhum candidato</strong>Use “Buscar candidatos agora” ou configure termos de pesquisa do Mercado Livre.</div>
<?php else: ?>
<p class="small muted"><?= (int)$res['total'] ?> candidato(s)<?= $res['pages'] > 1 ? ' · página ' . $res['page'] . ' de ' . $res['pages'] : '' ?></p>
<ul class="cand-list" role="list">
<?php foreach ($rows as $c): ?>
  <li class="card cand">
    <div class="cand-head">
      <?php if ($c['picture_url']): ?><img class="cand-img" src="<?= View::e($c['picture_url']) ?>" alt="" loading="lazy" width="72" height="72" referrerpolicy="no-referrer"><?php else: ?><span class="thumb thumb-mercadolivre" aria-hidden="true">🛒</span><?php endif; ?>
      <div>
        <strong title="<?= View::e($c['name']) ?>"><?= View::e($c['_short']) ?></strong>
        <div class="small"><span class="tag"><?= View::e($segName($c['_seg'])) ?></span><?= $c['status'] !== 'novo' ? ' <span class="tag tag-ok">avaliado: ' . View::e(MlCandidates::STATUSES[$c['status']] ?? $c['status']) . '</span>' : '' ?></div>
        <div class="small muted">ID de catálogo <code><?= View::e($c['product_id']) ?></code> · <?= View::e($c['category_name'] ?: ($c['domain_id'] ?: 'categoria não informada')) ?></div>
        <div class="small"><?= $c['origem'] === 'tendencia' ? '<span class="tag">Tendência #' . (int)$c['trend_pos'] . '</span>' : '<span class="tag">Termo</span>' ?> <?= View::e($c['termo'] ?? '') ?> · visto em <?= View::e(Time::show($c['last_seen_at'])) ?></div>
      </div>
    </div>
    <div class="small">
      <?php if ($c['bb_price_cents']): ?>
        <strong><?= View::e(Money::format((int)$c['bb_price_cents'])) ?></strong> <span class="src src-imported">API catálogo · não conferido</span>
        <?= $c['bb_original_cents'] ? '<span class="muted"> · preço original informado pela API: ' . View::e(Money::format((int)$c['bb_original_cents'])) . ' (não conferido)</span>' : '' ?>
        <div class="muted">Anúncio vencedor <code><?= View::e(substr((string)$c['bb_item_id'], 5)) ?></code> em <?= View::e(Time::show((string)$c['bb_checked_at'])) ?><?= $c['bb_free_shipping'] ? ' · frete grátis' : '' ?><?= $c['bb_official_store'] ? ' · loja oficial' : '' ?></div>
      <?php elseif ($c['bb_checked_at']): ?><span class="muted">Sem anúncio vencedor/preço informado pela API (<?= View::e(Time::show((string)$c['bb_checked_at'])) ?>)</span>
      <?php else: ?><span class="muted">Detalhe ainda não consultado</span><?php endif; ?>
    </div>
    <div class="actions">
      <?php if ($c['status'] === 'novo'): ?>
        <?= View::actionButton('ml_candidate_draft', ['id' => $c['id'], 'back' => 'candidatos'], 'Criar rascunho para conferência', 'btn btn-sm btn-primary', 'Criar um RASCUNHO no catálogo? Ele não terá link afiliado nem preço conferido: gere o link oficial e faça a conferência antes de qualquer aprovação.') ?>
        <?= View::actionButton('ml_candidate_discard', ['id' => $c['id'], 'back' => 'candidatos'], 'Descartar', 'btn btn-sm btn-ghost', 'Descartar este candidato?') ?>
      <?php elseif ($c['catalog_id']): ?><a class="btn btn-sm" href="<?= View::e(View::url('oferta', ['id' => $c['catalog_id']])) ?>">Abrir rascunho #<?= (int)$c['catalog_id'] ?></a><?php endif; ?>
      <?php if ($c['permalink']): ?><a class="btn btn-sm btn-store btn-store-mercadolivre" href="<?= View::e($c['permalink']) ?>" target="_blank" rel="noopener noreferrer"><span aria-hidden="true">🛒</span> Abrir link oficial<span class="sr-only"> (abre em nova aba)</span></a><?php endif; ?>
    </div>
    <?php if ($c['_similar']): ?>
      <p class="small warn">Possível duplicado: <?php foreach ($c['_similar'] as $i => $sm): ?><?= $i ? ' · ' : '' ?><?= $sm['tipo'] === 'produto' ? '<a href="' . View::e(View::url('oferta', ['id' => $sm['id']])) . '">produto #' . $sm['id'] . '</a>' : 'candidato #' . $sm['id'] ?> (<?= (int)round($sm['grau'] * 100) ?>%)<?php endforeach; ?> — só sinalização, nada é excluído.</p>
    <?php endif; ?>
    <details class="small"><summary>Dados retornados pela API (gravados)</summary>
      <dl class="api-fields"><?php foreach ($c['_fields'] as $k => $v): ?><dt><?= View::e($k) ?></dt><dd><?= View::e(is_string($v) ? mb_substr($v, 0, 160) : (string)$v) ?></dd><?php endforeach; ?></dl>
      <p class="muted">Somente o que a API informou e foi gravado; campos ausentes não foram informados. Nada aqui é preço conferido.</p>
    </details>
  </li>
<?php endforeach; ?>
</ul>
<?php if ($res['pages'] > 1): $pl = static fn(int $n) => View::url('candidatos', ['pg' => (string)$n] + $f); ?>
<nav class="pager" aria-label="Páginas">
  <?php if ($res['page'] > 1): ?><a class="btn btn-sm" href="<?= View::e($pl($res['page'] - 1)) ?>">← Anterior</a><?php endif; ?>
  <span class="small muted">Página <?= $res['page'] ?> de <?= $res['pages'] ?></span>
  <?php if ($res['page'] < $res['pages']): ?><a class="btn btn-sm" href="<?= View::e($pl($res['page'] + 1)) ?>">Próxima →</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

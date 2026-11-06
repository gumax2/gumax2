<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\{Db, Funnel, Text, Time, Url, View};
/** @var PDO $pdo  Minha fila de trabalho (3.2.2): tudo o que precisa de você, na ordem de atendimento. */
$f = Funnel::filters($_GET);
$_SESSION['gmax3_fila_f'] = $f; // "salvar e avançar" e o retorno usam os mesmos filtros
$ctx = Funnel::context($pdo);
$analyzed = Funnel::analyzeAll($pdo, $ctx); // uma passada pelo catálogo para a lista e para o resumo
$all = Funnel::workQueue($pdo, $f, $ctx, $analyzed);
$per = Funnel::PER_PAGE;
$pg = max(1, min(10000, (int)($_GET['pg'] ?? 1)));
$total = count($all);
$pages = max(1, (int)ceil($total / $per));
$pg = min($pg, $pages);
$items = array_slice($all, ($pg - 1) * $per, $per);
$sum = Funnel::summary($pdo, $ctx, $analyzed);
$cats = array_column(Db::all($pdo, "SELECT DISTINCT category FROM gmax3_catalog WHERE category IS NOT NULL AND category <> '' AND validation_status NOT IN ('arquivado','rejeitado') ORDER BY category"), 'category');
$prioTag = [1 => 'tag-bad', 2 => 'tag-warn', 3 => ''];
$link = static fn(array $extra) => View::url('fila', array_filter($extra + $f, static fn($v) => $v !== null && $v !== ''));
?>
<?php
// 3.2.2.2: conferência progressiva por rodadas (persistente no SQLite).
$round = Gmax\Review::open($pdo, Gmax\Auth::actor());
$rs = $round ? Gmax\Review::stats($pdo, (int)$round['id']) : null;
$sec = is_string($_GET['sec'] ?? null) && isset(Gmax\Review::SECTIONS[$_GET['sec']]) ? $_GET['sec'] : null;
$secLink = static fn(string $k) => View::url('fila', ['sec' => $k]);
?>
<?php if (Gmax\Review::installed($pdo)): ?>
<section class="card round-panel" aria-label="Conferência progressiva">
  <?php if (!$round): $active = (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE validation_status NOT IN ('arquivado','rejeitado')"); ?>
    <h2>Conferência progressiva</h2>
    <p class="small">Analise cada um dos <strong><?= $active ?></strong> produtos uma vez. O sistema lembra o que você já examinou, mesmo depois de sair, e só volta a um produto quando você decidir revisar.</p>
    <?= View::actionButton('round_start', ['back' => 'fila'], 'Iniciar rodada de conferência', 'btn btn-primary') ?>
  <?php else: ?>
    <h2>Rodada #<?= (int)$round['id'] ?> · <?= (int)$rs['analisados'] ?> de <?= (int)$rs['total'] ?> analisados</h2>
    <progress max="<?= max(1, (int)$rs['total']) ?>" value="<?= (int)$rs['analisados'] ?>" aria-label="Progresso da rodada"></progress>
    <?php if ($rs['nao_analisado'] > 0): ?>
      <p><?= View::actionButton('round_continue', ['back' => 'fila'], 'Continuar conferência (' . (int)$rs['nao_analisado'] . ' restante(s))', 'btn btn-primary') ?></p>
    <?php else: ?>
      <p class="alert alert-ok" role="status"><strong>Todos os produtos desta rodada foram analisados.</strong> A rodada não recomeça sozinha.</p>
    <?php endif; ?>
    <nav class="chip-row" aria-label="Progresso da conferência">
      <?php foreach (['nao_analisado' => $rs['nao_analisado'], 'analisado' => $rs['analisados'], 'pulado' => $rs['pulado'], 'revisar' => $rs['revisar'], 'novos' => $rs['novos']] as $k => $n): ?>
        <a class="chip<?= $sec === $k ? ' chip-on' : '' ?>" href="<?= View::e($secLink($k)) ?>"><?= View::e(Gmax\Review::SECTIONS[$k]) ?> <strong><?= (int)$n ?></strong></a>
      <?php endforeach; ?>
      <a class="chip" href="<?= View::e(View::url('fila', ['sit' => 'disponivel_selecao'])) ?>">Prontos para divulgar <strong><?= (int)$sum['situacoes']['disponivel_selecao'] ?></strong></a>
    </nav>
    <p class="small actions">
      <?php if ($rs['novos']): ?><?= View::actionButton('round_extend', ['back' => 'fila'], 'Incluir ' . (int)$rs['novos'] . ' recém-chegado(s) nesta rodada', 'btn btn-sm') ?><?php endif; ?>
      <?php if ($rs['pulado']): ?><?= View::actionButton('round_requeue', ['which' => 'pulado', 'back' => 'fila'], 'Devolver pulados para análise', 'btn btn-sm btn-ghost') ?><?php endif; ?>
      <?php if ($rs['revisar']): ?><?= View::actionButton('round_requeue', ['which' => 'revisar', 'back' => 'fila'], 'Devolver os que precisam de revisão', 'btn btn-sm btn-ghost') ?><?php endif; ?>
      <?= View::actionButton('round_start', ['back' => 'fila'], 'Iniciar nova rodada', 'btn btn-sm btn-ghost', 'Iniciar uma NOVA rodada com todos os produtos ativos? A rodada atual é encerrada e fica no histórico; nenhum dado de produto é alterado.') ?>
    </p>
    <p class="muted small">Progresso da conferência ≠ pendências comerciais ≠ prontidão para divulgar. Um produto analisado pode continuar pendente.</p>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($round && $sec): // Lista de uma seção da rodada (paginada no SQLite)
    $sp = max(1, min(10000, (int)($_GET['pg'] ?? 1)));
    $list = Gmax\Review::section($pdo, (int)$round['id'], $sec, $per, ($sp - 1) * $per);
    $spages = max(1, (int)ceil($list['total'] / $per)); ?>
  <h2 class="kpi-group"><?= View::e(Gmax\Review::SECTIONS[$sec]) ?> (<?= (int)$list['total'] ?>)</h2>
  <?php if (!$list['rows']): ?><div class="card empty"><strong>Nada nesta seção</strong></div><?php endif; ?>
  <ol class="work-list">
  <?php foreach ($list['rows'] as $r): $id = (int)$r['id']; $fa = Funnel::analyze($pdo, $r, $ctx); ?>
    <li class="card work" id="p<?= $id ?>">
      <div class="work-head"><?= View::mp($r['marketplace']) ?> <?php if ($r['r_state']): ?><span class="tag"><?= View::e(Gmax\Review::STATES[$r['r_state']] ?? $r['r_state']) ?></span><?php endif; ?>
        <?= !empty($r['r_review']) && $r['r_state'] === 'analisado' ? '<span class="tag tag-warn">mudou depois da análise</span>' : '' ?>
        <a class="work-title" href="<?= View::e(View::url('oferta', ['id' => $id])) ?>"><?= View::e(Text::shortTitle((string)$r['title'])) ?></a></div>
      <p class="small"><?= $r['r_last_visit_at'] ? 'Última análise: ' . View::e(Time::show($r['r_last_visit_at'])) . ' · ' . (int)$r['r_visits'] . ' visita(s) · ' : '' ?>Pendências: <?= $fa['issues'] ? View::e(implode(' · ', $fa['issues'])) : 'nenhuma' ?></p>
      <div class="actions work-actions"><a class="btn btn-primary" href="<?= View::e(View::url('conferir', ['id' => $id, 'rodada' => $round['id']])) ?>"><?= $r['r_state'] && $r['r_state'] !== 'nao_analisado' ? 'Revisitar' : 'Analisar' ?></a><?= View::storeButtons($r, 'btn-sm', Text::shortTitle((string)$r['title'], 60)) ?></div>
    </li>
  <?php endforeach; ?>
  </ol>
  <?php if ($spages > 1): ?><nav class="pager" aria-label="Páginas">
    <?php if ($sp > 1): ?><a class="btn btn-sm" href="<?= View::e(View::url('fila', ['sec' => $sec, 'pg' => (string)($sp - 1)])) ?>">← Anterior</a><?php endif; ?>
    <span class="small muted">Página <?= $sp ?> de <?= $spages ?></span>
    <?php if ($sp < $spages): ?><a class="btn btn-sm" href="<?= View::e(View::url('fila', ['sec' => $sec, 'pg' => (string)($sp + 1)])) ?>">Próxima →</a><?php endif; ?>
  </nav><?php endif; ?>
  <p><a class="btn btn-ghost" href="<?= View::e(View::url('fila')) ?>">← Voltar às pendências comerciais</a></p>
<?php return; endif; ?>
<h2 class="kpi-group">Pendências comerciais</h2>
<p class="lead small">Tudo o que precisa de você, em ordem: primeiro o que bloqueia uma oferta aprovada, depois o que está aguardando validação. Para examinar o catálogo inteiro sem repetir produtos, use a <strong>conferência progressiva</strong> acima.</p>

<nav class="chip-row" aria-label="Situações">
  <a class="chip<?= !isset($f['sit']) ? ' chip-on' : '' ?>" href="<?= View::e(View::url('fila', array_diff_key($f, ['sit' => 1]))) ?>">Todas <strong><?= (int)$sum['total'] ?></strong></a>
  <?php foreach (Funnel::SITUATIONS as $k => $l): if (!$sum['situacoes'][$k] && ($f['sit'] ?? '') !== $k) { continue; } ?>
    <a class="chip<?= ($f['sit'] ?? '') === $k ? ' chip-on' : '' ?>" href="<?= View::e($link(['sit' => $k])) ?>"><?= View::e($l) ?> <strong><?= (int)$sum['situacoes'][$k] ?></strong></a>
  <?php endforeach; ?>
</nav>

<details class="card filters-box" data-collapse-mobile<?= array_diff_key($f, ['sit' => 1]) ? ' open' : '' ?>>
  <summary>Filtros<?= array_diff_key($f, ['sit' => 1]) ? ' (' . count(array_diff_key($f, ['sit' => 1])) . ')' : '' ?></summary>
  <form class="filters" method="get" action="painel.php"><input type="hidden" name="p" value="fila">
    <?php if (isset($f['sit'])): ?><input type="hidden" name="sit" value="<?= View::e($f['sit']) ?>"><?php endif; ?>
    <label>Loja<select name="mp"><option value="">Todas</option><?php foreach (Url::MARKETPLACES as $k => $l): ?><option value="<?= $k ?>"<?= ($f['mp'] ?? '') === $k ? ' selected' : '' ?>><?= View::e($l) ?></option><?php endforeach; ?></select></label>
    <label>Categoria<select name="cat"><option value="">Todas</option><?php foreach ($cats as $c): ?><option value="<?= View::e($c) ?>"<?= ($f['cat'] ?? '') === $c ? ' selected' : '' ?>><?= View::e($c) ?></option><?php endforeach; ?></select></label>
    <label>Prioridade<select name="prio"><option value="">Todas</option><?php foreach (Funnel::PRIORITIES as $k => $l): ?><option value="<?= $k ?>"<?= ($f['prio'] ?? '') === (string)$k ? ' selected' : '' ?>><?= View::e($l) ?></option><?php endforeach; ?></select></label>
    <label>Etapa<select name="etapa"><option value="">Todas</option><?php foreach (Funnel::STAGES as $k => [$l]): ?><option value="<?= $k ?>"<?= ($f['etapa'] ?? '') === $k ? ' selected' : '' ?>><?= View::e($l) ?></option><?php endforeach; ?></select></label>
    <div class="filter-actions"><button class="btn btn-primary" type="submit">Filtrar</button> <a class="btn btn-ghost" href="<?= View::e(View::url('fila')) ?>">Limpar</a></div>
  </form>
</details>

<?php if (!$items): ?>
  <div class="card empty"><strong><?= $f ? 'Nada com estes filtros' : 'Fila vazia' ?></strong><?= $f ? 'Limpe os filtros para ver todas as pendências.' : 'Nenhuma pendência agora. As automações continuam trabalhando.' ?></div>
<?php else: ?>
<p class="small muted"><?= $total ?> pendência(s)<?= $pages > 1 ? " · página $pg de $pages" : '' ?>
</p>
<ol class="work-list" start="<?= ($pg - 1) * $per + 1 ?>">
<?php foreach ($items as $i => ['item' => $r, 'a' => $a]): $pos = ($pg - 1) * $per + $i; $id = (int)$r['id']; $v = Funnel::version($r); ?>
  <li class="card work" id="p<?= $id ?>">
    <div class="work-head">
      <span class="tag <?= $prioTag[$a['priority']] ?>"><?= View::e(Funnel::PRIORITIES[$a['priority']]) ?></span>
      <span class="tag"><?= View::e(Funnel::STAGES[$a['stage']][0] ?? $a['stage']) ?></span>
      <?= View::mp($r['marketplace']) ?>
      <a class="work-title" href="<?= View::e(View::url('oferta', ['id' => $id])) ?>" title="<?= View::e($r['title']) ?>"><?= View::e(Text::shortTitle((string)$r['title'])) ?></a>
    </div>
    <p class="work-pending"><strong><?= View::e(Funnel::SITUATIONS[$a['situation']] ?? '') ?>:</strong> <?= View::e((string)$a['pending']) ?></p>
    <?php if (count($a['issues']) > 1): ?><p class="small muted work-also">Também: <?= View::e(implode(' · ', array_slice($a['issues'], 1))) ?></p><?php endif; ?>
    <div class="actions work-actions">
      <?php $act = $a['action']; if ($act['kind'] === 'link' && $act['page'] === 'conferir'): ?>
        <a class="btn btn-primary" href="<?= View::e(View::url('conferir', ['id' => $id] + ($round ? ['rodada' => $round['id']] : ['fila' => 1, 'pos' => $pos]))) ?>"><?= View::e($act['label']) ?></a>
      <?php elseif ($act['kind'] === 'link'): ?>
        <a class="btn btn-primary" href="<?= View::e(View::url($act['page'], $act['params'])) ?>"><?= View::e($act['label']) ?></a>
      <?php elseif ($act['kind'] === 'approve'): ?>
        <?= View::actionButton('fila_approve', ['id' => $id, 'v' => $v, 'pos' => $pos, 'back' => 'fila'], 'Aprovar', 'btn btn-primary') ?>
      <?php elseif ($act['kind'] === 'enqueue'): ?>
        <?= View::actionButton('fila_enqueue', ['id' => $id, 'v' => $v, 'pos' => $pos, 'back' => 'fila'], 'Agendar na fila', 'btn btn-primary', 'Agendar no próximo horário livre? O envio real continua bloqueado pelas travas de publicação.') ?>
      <?php elseif ($act['kind'] === 'link_set'): ?>
        <form method="post" action="painel.php" class="inline-form" data-loading>
          <?= View::csrfField() ?><input type="hidden" name="action" value="link_set"><input type="hidden" name="back" value="fila">
          <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="v" value="<?= View::e($v) ?>"><input type="hidden" name="pos" value="<?= $pos ?>">
          <label class="sr-only" for="lk<?= $id ?>">Link afiliado</label>
          <input id="lk<?= $id ?>" name="affiliate_url" type="url" inputmode="url" required maxlength="300" placeholder="<?= $r['marketplace'] === 'shopee' ? 'https://s.shopee.com.br/…' : 'https://meli.la/…' ?>">
          <button class="btn btn-primary" type="submit">Salvar link</button>
        </form>
      <?php endif; ?>
      <?php if ($act['kind'] !== 'link' || ($act['page'] ?? '') !== 'conferir'): ?>
        <a class="btn btn-ghost btn-sm" href="<?= View::e(View::url('conferir', ['id' => $id, 'fila' => 1, 'pos' => $pos])) ?>">Conferência rápida</a>
      <?php endif; ?>
      <?= View::storeButtons($r, 'btn-sm', Text::shortTitle((string)$r['title'], 60)) ?>
    </div>
    <?php $hist = Db::all($pdo, "SELECT at, actor, action FROM gmax3_audit WHERE entity='catalog' AND entity_id=? ORDER BY id DESC LIMIT 5", [(string)$id]); ?>
    <?php if ($hist): ?><details class="small"><summary>Histórico (<?= count($hist) ?>)</summary><ul class="log"><?php foreach ($hist as $h): ?><li><span class="muted"><?= View::e(Time::show($h['at'])) ?></span> <?= View::e(str_replace('_', ' ', $h['action'])) ?> · <?= View::e($h['actor']) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
  </li>
<?php endforeach; ?>
</ol>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Páginas">
  <?php if ($pg > 1): ?><a class="btn btn-sm" href="<?= View::e($link(['pg' => $pg - 1])) ?>">← Anterior</a><?php endif; ?>
  <span class="small muted">Página <?= $pg ?> de <?= $pages ?></span>
  <?php if ($pg < $pages): ?><a class="btn btn-sm" href="<?= View::e($link(['pg' => $pg + 1])) ?>">Próxima →</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>

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
<?php $seq = Gmax\Sequence::current(); $toCheck = count(array_filter($all, static fn($x) => ($x['a']['action']['page'] ?? '') === 'conferir')); ?>
<?php if ($seq && !$seq['finished']): [$k, $n] = Gmax\Sequence::progress($seq, null); ?>
  <div class="seq-bar card" role="status"><strong>Sequência em andamento: <?= count($seq['done']) ?> de <?= $n ?> visitados</strong>
    <span class="seq-actions"><?= View::actionButton('seq_resume', ['back' => 'fila'], 'Retomar sequência', 'btn btn-primary btn-sm') ?><?= View::actionButton('seq_end', ['back' => 'fila'], 'Encerrar', 'btn btn-ghost btn-sm') ?></span></div>
<?php elseif ($seq): $t = Gmax\Sequence::tally($seq); ?>
  <div class="seq-bar card" role="status"><strong>Sequência concluída</strong> <span class="small"><?= (int)$t['salvo'] ?> conferido(s) · <?= (int)$t['pulado'] ?> pulado(s) · <?= (int)$t['resolvido'] + (int)$t['removido'] ?> resolvido(s) fora</span>
    <span class="seq-actions"><?php if ($t['pulado']): ?><?= View::actionButton('seq_skipped', ['back' => 'fila'], 'Revisitar pulados (' . (int)$t['pulado'] . ')', 'btn btn-sm') ?><?php endif; ?><?= View::actionButton('seq_end', ['back' => 'fila'], 'Fechar', 'btn btn-ghost btn-sm') ?></span></div>
<?php endif; ?>
<p class="lead small">Tudo o que precisa de você, em ordem: primeiro o que bloqueia uma oferta aprovada, depois o que está aguardando validação. Resolva e use <strong>Salvar e ir para o próximo</strong>.</p>

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
  <?php if ($toCheck): ?> · <?= View::actionButton('seq_start', ['back' => 'fila'], 'Conferir em sequência (' . $toCheck . ')', 'btn btn-sm btn-primary', ($seq && !$seq['finished']) ? 'Iniciar uma NOVA sequência? A atual será substituída (nada é alterado nos produtos).' : null) ?><?php endif; ?></p>
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
        <?= View::actionButton('seq_start', ['from' => $id, 'back' => 'fila'], $act['label'], 'btn btn-primary', ($seq && !$seq['finished']) ? 'Iniciar uma nova sequência a partir deste produto? A atual será substituída (nada é alterado nos produtos).' : null) ?>
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

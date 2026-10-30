<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\Automation\{Jobs, RunLog};
use Gmax\Connectors\Registry;
use Gmax\{Db, Money, Time, Url, View};
/** @var PDO $pdo */
$f = [];
foreach (['run', 'q', 'mp', 'res', 'page'] as $k) {
    if (isset($_GET[$k]) && is_string($_GET[$k]) && $_GET[$k] !== '') {
        $f[$k] = mb_substr(trim($_GET[$k]), 0, 100);
    }
}
$runId = isset($f['run']) && ctype_digit($f['run']) ? (int)$f['run'] : 0;
$run = $runId ? Db::one($pdo, 'SELECT r.*, j.name FROM gmax3_job_runs r LEFT JOIN gmax3_jobs j ON j.job=r.job WHERE r.id=?', [$runId]) : null;
$runList = Db::all($pdo, 'SELECT r.id, r.started_at, r.status, j.name FROM gmax3_job_runs r LEFT JOIN gmax3_jobs j ON j.job=r.job ORDER BY r.id DESC LIMIT 100');
$logOn = RunLog::enabled($pdo);
$perPage = 100;
$pageNo = max(1, (int)($f['page'] ?? 1));
$where = [];
$args = [];
if ($runId) {
    $where[] = 'i.run_id = ?';
    $args[] = $runId;
}
if (isset($f['mp']) && isset(Url::MARKETPLACES[$f['mp']])) {
    $where[] = 'i.marketplace = ?';
    $args[] = $f['mp'];
}
if (isset($f['res']) && isset(RunLog::OUTCOMES[$f['res']])) {
    $where[] = 'i.outcome = ?';
    $args[] = $f['res'];
}
if (isset($f['q'])) {
    $q = ltrim($f['q'], '#');
    if (ctype_digit($q)) {
        $where[] = '(i.catalog_id = ? OR i.external_id LIKE ?)';
        array_push($args, (int)$q, '%' . $q . '%');
    } else {
        $where[] = "(i.title LIKE ? ESCAPE '\\' OR i.external_id LIKE ? ESCAPE '\\')";
        $like = '%' . addcslashes($q, '%_\\') . '%';
        array_push($args, $like, $like);
    }
}
$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$total = $logOn ? (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_job_run_items i$sqlWhere", $args) : 0;
$items = $logOn ? Db::all($pdo, "SELECT i.*, r.job FROM gmax3_job_run_items i LEFT JOIN gmax3_job_runs r ON r.id=i.run_id$sqlWhere
    ORDER BY i.run_id DESC, CASE i.outcome WHEN 'bloqueado' THEN 0 WHEN 'sem_alteracao' THEN 1 WHEN 'atualizado' THEN 2 WHEN 'validado' THEN 3 WHEN 'nao_processado' THEN 4 ELSE 5 END, i.id
    LIMIT $perPage OFFSET " . (($pageNo - 1) * $perPage), $args) : [];
$byOutcome = $run && $logOn ? array_column(Db::all($pdo, 'SELECT outcome, COUNT(*) n FROM gmax3_job_run_items WHERE run_id=? GROUP BY outcome', [$runId]), 'n', 'outcome') : [];
$keep = array_diff_key($f, ['page' => 1]);
$price = static fn($v) => $v === null ? '—' : Money::format((int)$v);
$isReview = $run && in_array($run['job'], ['revisao_precos', 'analise_semanal'], true);
?>
<p><a href="<?= View::e(View::url('automacoes')) ?>#historico">← Central de Automações</a></p>

<form class="card filters" method="get" action="painel.php" role="search">
  <input type="hidden" name="p" value="execucoes">
  <label>Execução
    <select name="run"><option value="">Todas</option><?php foreach ($runList as $r): ?><option value="<?= (int)$r['id'] ?>"<?= $runId === (int)$r['id'] ? ' selected' : '' ?>>#<?= (int)$r['id'] ?> · <?= View::e($r['name'] ?? '') ?> · <?= View::e(Time::show($r['started_at'])) ?></option><?php endforeach; ?></select>
  </label>
  <label>Produto <input type="search" name="q" value="<?= View::e($f['q'] ?? '') ?>" placeholder="Nome, #id, MLB…, Item Id"></label>
  <label>Marketplace
    <select name="mp"><option value="">Todos</option><?php foreach (Url::MARKETPLACES as $k => $l): ?><option value="<?= $k ?>"<?= ($f['mp'] ?? '') === $k ? ' selected' : '' ?>><?= View::e($l) ?></option><?php endforeach; ?></select>
  </label>
  <label>Resultado
    <select name="res"><option value="">Todos</option><?php foreach (RunLog::OUTCOMES as $k => $l): ?><option value="<?= $k ?>"<?= ($f['res'] ?? '') === $k ? ' selected' : '' ?>><?= View::e($l) ?></option><?php endforeach; ?></select>
  </label>
  <div class="filter-actions"><button type="submit" class="btn btn-primary">Filtrar</button> <a class="btn btn-ghost" href="<?= View::e(View::url('execucoes')) ?>">Limpar</a></div>
</form>

<?php if ($runId && !$run): ?>
  <div class="alert alert-erro">Execução #<?= $runId ?> não encontrada.</div>
<?php elseif ($run): ?>
<section class="card">
  <h2>Execução #<?= (int)$run['id'] ?>: <?= View::e($run['name'] ?? $run['job']) ?></h2>
  <p class="small">Gatilho: <?= View::e($run['trigger']) ?> · Início: <?= View::e(Time::show($run['started_at'])) ?> · Fim: <?= View::e($run['finished_at'] ? Time::show($run['finished_at']) : '—') ?>
    · Estado: <span class="badge run-<?= View::e($run['status']) ?>"><?= View::e($run['status']) ?></span> · Por: <?= View::e($run['actor'] ?? '—') ?></p>
  <div class="grid stats small-stats">
    <?php foreach (['found' => 'Encontrados', 'created' => 'Novos', 'updated' => 'Atualizados', 'approved' => 'Aprovados', 'blocked' => 'Bloqueados', 'errors' => 'Erros'] as $k => $l): ?>
      <div class="stat"><strong><?= (int)$run[$k] ?></strong><?= $l ?></div>
    <?php endforeach; ?>
  </div>
  <?php if ($byOutcome): ?>
    <p class="small"><?php foreach (RunLog::OUTCOMES as $k => $l): ?><?php if (!empty($byOutcome[$k])): ?><a class="tag" href="<?= View::e(View::url('execucoes', ['run' => $runId, 'res' => $k])) ?>"><?= View::e($l) ?>: <?= (int)$byOutcome[$k] ?></a><?php endif; ?><?php endforeach; ?></p>
  <?php endif; ?>
  <?php if ($run['detail']): ?><p class="small"><?= nl2br(View::e((string)$run['detail'])) ?></p><?php endif; ?>
  <?php if (!$byOutcome && $isReview): ?>
    <div class="alert alert-aviso">Esta execução foi feita antes do detalhamento por produto (3.1.2 com a migração 003) e só tem o resumo acima. Não é possível reconstruir quais produtos foram analisados nem os motivos sem inventar dados. A prévia abaixo mostra como a <strong>próxima</strong> revisão trataria o catálogo atual.</div>
  <?php elseif (!$byOutcome): ?>
    <p class="muted small">Esta tarefa não registra detalhamento por produto (apenas a revisão de preços e a análise semanal).</p>
  <?php endif; ?>
</section>
<?php if ($isReview): ?>
<?php
$drafts = $run['job'] === 'analise_semanal';
$plan = [];
$conns = [];
foreach (Jobs::reviewCandidates($pdo) as $r) {
    $why = Jobs::reviewExclusion($r, $drafts);
    if ($why === null) {
        $conn = $conns[$r['marketplace']] ??= Registry::get($r['marketplace'], $pdo);
        $why = $conn->configured() ? 'Será consultado na API (' . $conn->name() . ')' : 'Será BLOQUEADO: integração indisponível — ' . $conn->status();
        $plan[] = [$r, $conn->configured() ? 'elegivel' : 'bloqueado', $why];
    } else {
        $plan[] = [$r, 'fora_da_revisao', $why];
    }
}
$hidden = (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE validation_status IN ('arquivado','rejeitado')");
?>
<section class="card">
  <h2>Prévia da próxima revisão (catálogo atual, nenhuma API consultada)</h2>
  <p class="small muted">Elegíveis: <?= count(array_filter($plan, static fn($p) => $p[1] !== 'fora_da_revisao')) ?> · fora da revisão: <?= count(array_filter($plan, static fn($p) => $p[1] === 'fora_da_revisao')) ?> · arquivados/rejeitados (não listados): <?= $hidden ?>.</p>
  <div class="table-wrap"><table class="table compact"><thead><tr><th>Produto</th><th>Marketplace</th><th>Identificador</th><th>Estado</th><th>Previsão</th></tr></thead><tbody>
  <?php foreach (array_slice($plan, 0, 200) as [$r, $kind, $why]): ?>
    <tr><td><a href="<?= View::e(View::url('oferta', ['id' => $r['id']])) ?>">#<?= (int)$r['id'] ?> <?= View::e(mb_substr((string)$r['title'], 0, 60)) ?></a></td>
      <td><?= View::mp($r['marketplace']) ?></td><td><code><?= View::e($r['external_id'] ?? '—') ?></code></td><td><?= View::e($r['validation_status']) ?></td>
      <td class="small"><span class="tag<?= $kind === 'elegivel' ? '' : ' tag-warn' ?>"><?= $kind === 'elegivel' ? 'elegível' : ($kind === 'bloqueado' ? 'bloqueado' : 'fora da revisão') ?></span> <?= View::e($why) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$plan): ?><tr><td colspan="5" class="muted">Nenhum produto ativo no catálogo.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>
<?php endif; ?>
<?php endif; ?>

<section class="card">
  <h2>Produtos nas execuções (<?= $total ?>)</h2>
  <?php if (!$logOn): ?>
    <div class="alert alert-aviso">Aplique a migração 003 em Sistema para registrar os detalhes por produto.</div>
  <?php else: ?>
  <p class="muted small">Registro somente-inclusão: as linhas não podem ser alteradas nem apagadas. Preços e situação vêm apenas do que a API devolveu; consultas que falharam não registram preço nem validação. Tokens nunca são gravados.</p>
  <div class="table-wrap"><table class="table compact"><thead><tr><th>Exec.</th><th>Produto</th><th>Marketplace</th><th>Identificador</th><th>Resultado</th><th>Motivo</th><th>Consulta à API</th><th>Preço anterior</th><th>Preço da API</th><th>Consultado em</th></tr></thead><tbody>
  <?php foreach ($items as $i): ?>
    <tr>
      <td><a href="<?= View::e(View::url('execucoes', ['run' => $i['run_id']])) ?>">#<?= (int)$i['run_id'] ?></a></td>
      <td><?= $i['catalog_id'] ? '<a href="' . View::e(View::url('oferta', ['id' => $i['catalog_id']])) . '">#' . (int)$i['catalog_id'] . '</a> ' : '' ?><?= View::e(mb_substr((string)$i['title'], 0, 70)) ?></td>
      <td><?= View::mp($i['marketplace']) ?></td>
      <td><code><?= View::e($i['external_id'] ?? '—') ?></code></td>
      <td><span class="badge out-<?= View::e($i['outcome']) ?>"><?= View::e(RunLog::OUTCOMES[$i['outcome']] ?? $i['outcome']) ?></span></td>
      <td class="small"><?= View::e($i['reason']) ?></td>
      <td class="small"><?php if ($i['api_endpoint'] || $i['http_status'] !== null): ?><code><?= View::e($i['api_endpoint'] ?? '') ?></code> <?= $i['http_status'] !== null ? '<strong>HTTP ' . (int)$i['http_status'] . '</strong>' : '' ?><?= $i['api_error'] ? '<br>' . View::e($i['api_error']) : '' ?><?php else: ?>—<?php endif; ?></td>
      <td><?= $price($i['price_before_cents']) ?><?= $i['original_before_cents'] !== null ? '<br><span class="muted small">De ' . $price($i['original_before_cents']) . '</span>' : '' ?></td>
      <td><?= $price($i['price_api_cents']) ?><?= $i['original_api_cents'] !== null ? '<br><span class="muted small">De ' . $price($i['original_api_cents']) . '</span>' : '' ?><?= $i['availability_api'] ? '<br><span class="muted small">' . View::e($i['availability_api']) . '</span>' : '' ?></td>
      <td class="small"><?= View::e($i['checked_at'] ? Time::show($i['checked_at']) : '—') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$items): ?><tr><td colspan="10" class="muted">Nenhum registro para os filtros escolhidos.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?= View::pager($total, $perPage, $pageNo, $keep, 'execucoes') ?>
  <?php endif; ?>
</section>

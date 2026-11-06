<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\AutoLab\AutoLab;
use Gmax\{Db, Funnel, Health, Queue, Settings, Text, Time, View};
/** @var PDO $pdo @var int $staleHours @var bool $paused  Visão Geral (3.2.2): orientada a decisões. */
$ctx = Funnel::context($pdo);
$sum = Funnel::summary($pdo, $ctx);
$work = Funnel::workQueue($pdo, [], $ctx);
$now = array_slice(array_values(array_filter($work, static fn($w) => $w['a']['situation'] !== 'disponivel_selecao')), 0, 5);
$ready = array_values(array_filter($work, static fn($w) => $w['a']['situation'] === 'disponivel_selecao'));
$scheduled = (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_pub_queue WHERE state='agendado'");
$pending = Queue::unreconciled($pdo);
$health = Health::collect($pdo);
$s = $sum['situacoes'];
$al = AutoLab::installed($pdo) ? [
    'estado' => AutoLab::state($pdo), 'motivo' => Settings::get($pdo, 'autolab_motivo', ''), 'proximo' => AutoLab::nextSlot($pdo),
    'ultimo' => Db::one($pdo, "SELECT slot_key, kind, finished_at, telegram_message_id FROM gmax3_autolab_messages WHERE state='confirmado' ORDER BY id DESC LIMIT 1"),
    'incertos' => (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_autolab_messages WHERE state='incerto'"),
] : null;
$cands = Db::tableExists($pdo, 'gmax3_ml_candidates') ? (int)$pdo->query("SELECT COUNT(*) FROM gmax3_ml_candidates WHERE status='novo'")->fetchColumn() : 0;
$alerts = Db::tableExists($pdo, 'gmax3_alerts') ? (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_alerts WHERE resolved_at IS NULL') : 0;
$slot = static fn(?string $k) => $k ? substr($k, 8, 2) . '/' . substr($k, 5, 2) . ' ' . substr($k, 11, 5) : '—';

// Próximas ações recomendadas, na ordem em que valem a pena.
$next = [];
if ($pending) {
    $next[] = ['Reconciliar ' . count($pending) . ' envio(s) sem confirmação antes de qualquer publicação', View::url('publicacoes'), 'tag-bad'];
}
if ($sum['prioridades'][1]) {
    $next[] = ['Resolver ' . $sum['prioridades'][1] . ' pendência(s) de prioridade alta (bloqueiam ofertas aprovadas)', View::url('fila', ['prio' => '1']), 'tag-bad'];
}
if (($health['Cron das automações'][0] ?? 'ok') !== 'ok') {
    $next[] = ['Cron das automações sem chamada recente: confira no cPanel', View::url('diagnostico'), 'tag-warn'];
}
if ($al && $al['estado'] === 'ativo' && !$al['ultimo']) {
    $next[] = ['AutoLab: observe no grupo privado o 1º envio real (' . $slot($al['proximo']) . ') e confira em AutoLab', View::url('autolab'), 'tag-warn'];
}
if ($al && in_array($al['estado'], ['pausado', 'falha'], true)) {
    $next[] = ['AutoLab ' . $al['estado'] . ($al['motivo'] ? ': ' . $al['motivo'] : '') . ' — ele não é reativado sozinho', View::url('autolab'), 'tag-bad'];
}
if ($s['aguardando_aprovacao']) {
    $next[] = ['Aprovar ' . $s['aguardando_aprovacao'] . ' oferta(s) com dados completos', View::url('fila', ['sit' => 'aguardando_aprovacao']), ''];
}
if ($alerts) {
    $next[] = [$alerts . ' alerta(s) das automações aberto(s)', View::url('automacoes'), 'tag-warn'];
}
if ($cands) {
    $next[] = ['Avaliar ' . $cands . ' candidato(s) do Mercado Livre', View::url('candidatos'), ''];
}
$ind = [
    'aguardando_aprovacao' => 'Aguardando validação', 'preco_desatualizado' => 'Preços desatualizados', 'sem_link' => 'Links afiliados ausentes',
    'variacao' => 'Variações pendentes', 'inconsistencia' => 'Inconsistências',
];
$stageLink = static fn(string $k) => match ($k) {
    'descoberta' => View::url('candidatos'),
    'fila', 'publicacao', 'resultado' => View::url('publicacoes'),
    default => View::url('fila', ['etapa' => $k]),
};
$hl = static fn(string $lvl) => '<span class="tag ' . ['ok' => 'tag-ok', 'aviso' => 'tag-warn', 'falha' => 'tag-bad'][$lvl] . '">' . ['ok' => 'ok', 'aviso' => 'atenção', 'falha' => 'falha'][$lvl] . '</span>';
?>
<section class="decide">
  <div class="card">
    <h2>Precisa de você agora <span class="tag<?= $sum['total'] ? ' tag-warn' : ' tag-ok' ?>"><?= (int)($sum['total'] - $s['disponivel_selecao']) ?></span></h2>
    <?php if (!$now): ?><p class="empty">Nada pendente. As automações seguem trabalhando.</p><?php else: ?>
    <ul class="decide-list">
      <?php foreach ($now as ['item' => $r, 'a' => $a]): ?>
        <li><span class="tag <?= [1 => 'tag-bad', 2 => 'tag-warn', 3 => ''][$a['priority']] ?>"><?= View::e(Funnel::PRIORITIES[$a['priority']]) ?></span>
          <span class="grow"><a href="<?= View::e(View::url('fila', [], ) . '#p' . (int)$r['id']) ?>"><?= View::e(Text::shortTitle((string)$r['title'], 60)) ?></a><br><span class="small muted"><?= View::e((string)$a['pending']) ?></span></span></li>
      <?php endforeach; ?>
    </ul><?php endif; ?>
    <p><a class="btn btn-primary" href="<?= View::e(View::url('fila')) ?>">Abrir minha fila de trabalho</a></p>
  </div>
  <div class="card">
    <h2>Prontos para divulgar <span class="tag tag-ok"><?= count($ready) ?></span></h2>
    <p class="small muted">Aprovados, sem pendências, ainda não agendados<?= $scheduled ? " · $scheduled já agendado(s)" : '' ?>. Agendar não envia: a publicação comercial continua pausada.</p>
    <?php if ($ready): ?><ul class="decide-list"><?php foreach (array_slice($ready, 0, 3) as ['item' => $r]): ?>
      <li><span class="grow"><a href="<?= View::e(View::url('oferta', ['id' => $r['id']])) ?>"><?= View::e(Text::shortTitle((string)$r['title'], 60)) ?></a></span> <?= View::mp($r['marketplace']) ?></li>
    <?php endforeach; ?></ul><?php endif; ?>
    <p><a class="btn" href="<?= View::e(View::url('fila', ['sit' => 'disponivel_selecao'])) ?>">Ver prontos</a> <a class="btn btn-ghost" href="<?= View::e(View::url('ranking', ['modo' => 'prontas'])) ?>">Ranking das prontas</a></p>
  </div>
</section>

<section class="grid stats" aria-label="Pendências por tipo">
  <?php foreach ($ind as $k => $label): ?>
    <a class="stat<?= $s[$k] ? ($k === 'inconsistencia' ? ' stat-danger' : ' stat-warn') : '' ?>" href="<?= View::e(View::url('fila', ['sit' => $k])) ?>"><span class="muted"><?= View::e($label) ?></span><strong><?= (int)$s[$k] ?></strong></a>
  <?php endforeach; ?>
</section>

<section class="card">
  <h2>Funil comercial</h2>
  <?php $mpc = $pdo->query("SELECT marketplace, COUNT(*) FROM gmax3_catalog WHERE validation_status <> 'arquivado' GROUP BY marketplace")->fetchAll(PDO::FETCH_KEY_PAIR); ?>
  <p class="small">Catálogo ativo:<?php foreach (Gmax\Url::MARKETPLACES as $mp => $label): ?> <a class="mp-count" href="<?= View::e(View::url('ofertas', ['marketplace' => $mp])) ?>"><?= View::e($label) ?> <strong><?= (int)($mpc[$mp] ?? 0) ?></strong></a><?php endforeach; ?></p>
  <nav class="funnel" aria-label="Etapas do funil">
    <?php foreach (Funnel::STAGES as $k => [$label, $desc]): ?>
      <a href="<?= View::e($stageLink($k)) ?>" title="<?= View::e($desc) ?>"><strong><?= (int)$sum['etapas'][$k] ?></strong><span><?= View::e($label) ?></span></a>
    <?php endforeach; ?>
  </nav>
</section>

<section class="decide">
  <div class="card">
    <h2>Próximas ações recomendadas</h2>
    <?php if (!$next): ?><p class="empty">Nenhuma ação recomendada agora.</p><?php else: ?>
    <ol class="decide-list"><?php foreach ($next as [$txt, $url, $tone]): ?><li><?= $tone ? '<span class="tag ' . $tone . '">!</span>' : '' ?><a class="grow" href="<?= View::e($url) ?>"><?= View::e($txt) ?></a></li><?php endforeach; ?></ol><?php endif; ?>
  </div>
  <div class="card">
    <h2>Integrações e automações</h2>
    <ul class="status-list">
      <?php foreach (['Integração Shopee', 'Integração Mercado Livre', 'Cron das automações', 'Execuções em andamento', 'Falhas de tarefas (7 dias)', 'Alertas abertos'] as $k): if (!isset($health[$k])) { continue; } ?>
        <li><?= $hl($health[$k][0]) ?> <strong><?= View::e(str_replace('Integração ', '', $k)) ?>:</strong> <span class="small"><?= View::e($health[$k][1]) ?></span></li>
      <?php endforeach; ?>
      <?php if ($al): ?>
        <li><span class="tag <?= ['ativo' => 'tag-ok', 'pausado' => 'tag-warn', 'falha' => 'tag-bad'][$al['estado']] ?? '' ?>"><?= View::e($al['estado']) ?></span> <strong>AutoLab:</strong>
          <span class="small">último envio confirmado <?= $al['ultimo'] ? View::e($slot($al['ultimo']['slot_key'])) . ' (msg ' . (int)$al['ultimo']['telegram_message_id'] . ')' : '<strong>ainda nenhum</strong>' ?> · próximo <?= View::e($al['estado'] === 'ativo' ? $slot($al['proximo']) : '—') ?><?= $al['incertos'] ? ' · ' . $al['incertos'] . ' incerto(s)' : '' ?></span></li>
      <?php endif; ?>
      <li><?= $paused ? '<span class="tag tag-warn">pausada</span>' : '<span class="tag tag-bad">sem pausa</span>' ?> <strong>Publicação comercial</strong> <span class="small">· aprovação automática <?= Settings::get($pdo, 'aprovacao_automatica', '0') === '1' ? '<strong>LIGADA</strong>' : 'desligada' ?></span></li>
    </ul>
    <p><a class="btn btn-ghost btn-sm" href="<?= View::e(View::url('diagnostico')) ?>">Diagnóstico completo</a> <a class="btn btn-ghost btn-sm" href="<?= View::e(View::url('autolab')) ?>">AutoLab</a></p>
  </div>
</section>

<details class="card">
  <summary>Atalhos e atividade recente</summary>
  <p><a class="btn" href="<?= View::e(View::url('importar')) ?>">Importar lote</a> <a class="btn" href="<?= View::e(View::url('oferta')) ?>">Cadastrar produto</a> <a class="btn" href="<?= View::e(View::url('links')) ?>">Links em lote</a></p>
  <ul class="log">
    <?php foreach ($pdo->query('SELECT * FROM gmax3_audit ORDER BY id DESC LIMIT 8')->fetchAll() as $a): ?>
      <li><span class="muted small"><?= View::e(Time::show($a['at'])) ?></span> <?= View::e(str_replace('_', ' ', $a['action'])) ?> <?= $a['entity_id'] !== null ? '<span class="muted">#' . View::e($a['entity_id']) . '</span>' : '' ?></li>
    <?php endforeach; ?>
  </ul>
  <p class="muted small">Painel anterior do Mercado Livre: <a href="mercadolivre.php">mercadolivre.php</a>.</p>
</details>

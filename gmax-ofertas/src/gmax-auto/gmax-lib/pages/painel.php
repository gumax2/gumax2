<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\{Db, Queue, Ranking, Settings, Time, Url, View};
/** @var PDO $pdo @var int $staleHours @var bool $paused */
$byMp = $pdo->query("SELECT marketplace, validation_status, COUNT(*) n FROM gmax3_catalog GROUP BY marketplace, validation_status")->fetchAll();
$m = [];
foreach ($byMp as $r) {
    $m[$r['marketplace']][$r['validation_status']] = (int)$r['n'];
}
$total = static fn(string $mp) => array_sum($m[$mp] ?? []) - ($m[$mp]['arquivado'] ?? 0);
$staleSince = Time::now()->modify("-$staleHours hours")->format('Y-m-d H:i:s');
$count = static fn(string $where, array $args = []) => (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE validation_status <> 'arquivado' AND $where", $args);
$k = [
    'total' => $count('1=1'), 'rascunho' => $count("validation_status='rascunho'"), 'aguardando' => $count("validation_status='aguardando_validacao'"),
    'stale' => $count('(price_verified_at IS NULL OR price_verified_at < ?)', [$staleSince]), 'nolink' => $count("(affiliate_url IS NULL OR affiliate_url='')"),
    'indisponivel' => $count("availability='indisponivel'"), 'variacao' => $count('variation_pending=1'),
];
$queue = $pdo->query('SELECT state, COUNT(*) FROM gmax3_pub_queue GROUP BY state')->fetchAll(PDO::FETCH_KEY_PAIR);
$gates = Queue::gates($pdo);
$pending = Queue::unreconciled($pdo);
$recent = $pdo->query('SELECT * FROM gmax3_audit ORDER BY id DESC LIMIT 8')->fetchAll();
$top = array_slice(array_values(array_filter(Ranking::listCached($pdo, 60), static fn($r) => in_array($r['item']['validation_status'], ['aprovado', 'aguardando_validacao'], true))), 0, 5);
$tile = static function (string $label, int $n, array $filter, string $hint, string $tone = '') {
    return '<a class="stat' . ($n && $tone ? ' ' . $tone : '') . '" href="' . View::e(View::url('ofertas', $filter)) . '"><span class="muted">' . View::e($label) . '</span><strong>' . $n . '</strong><span class="small">' . View::e($hint) . '</span></a>';
};
?>
<h2 class="kpi-group">Catálogo</h2>
<section class="grid stats" aria-label="Catálogo">
  <?= $tile('Total de produtos', $k['total'], [], 'exceto arquivados') ?>
  <?php foreach (Url::MARKETPLACES as $mp => $label): ?>
    <?= $tile($label, $total($mp), ['marketplace' => $mp], (int)($m[$mp]['aprovado'] ?? 0) . ' aprovada(s) · ' . (int)($m[$mp]['aguardando_validacao'] ?? 0) . ' aguardando') ?>
  <?php endforeach; ?>
  <?= $tile('Rascunhos', $k['rascunho'], ['status' => 'rascunho'], 'completar cadastro') ?>
  <?= $tile('Aguardando validação', $k['aguardando'], ['status' => 'aguardando_validacao'], 'conferir e aprovar') ?>
</section>
<h2 class="kpi-group">Precisam de atenção</h2>
<section class="grid stats" aria-label="Precisam de atenção">
  <?= $tile('Preços desatualizados', $k['stale'], ['stale' => 1], "sem conferência há mais de {$staleHours} h", 'stat-warn') ?>
  <?= $tile('Sem link afiliado', $k['nolink'], ['nolink' => 1], 'associar link oficial', 'stat-warn') ?>
  <?= $tile('Anúncios indisponíveis', $k['indisponivel'], ['avail' => 'indisponivel'], 'pausados ou sem estoque', 'stat-danger') ?>
  <?= $tile('Variação pendente', $k['variacao'], ['variation' => 1], 'escolher cor/voltagem/tamanho', 'stat-warn') ?>
</section>

<section class="card">
  <h2>Melhores oportunidades comerciais</h2>
  <?php if (!$top): ?><p class="empty">Nenhuma oferta aprovada ou aguardando validação com pontuação.</p><?php else: ?>
  <ol class="toplist">
  <?php foreach ($top as $row): $r = $row['item']; ?>
    <li><a href="<?= View::e(View::url('oferta', ['id' => $r['id']])) ?>"><?= View::e($r['title']) ?></a>
      <div class="small"><?= View::mp($r['marketplace']) ?> <strong class="score"><?= $row['score'] === null ? '—' : (int)$row['score'] ?></strong> pts · confiança <?= (int)$row['confidence'] ?>% · <?= View::badge($r['validation_status']) ?>
      · <a href="<?= View::e(View::url('conferir', ['id' => $r['id']])) ?>">conferir preço</a></div></li>
  <?php endforeach; ?>
  </ol><?php endif; ?>
  <p><a href="<?= View::e(View::url('ranking')) ?>">Ranking completo →</a></p>
</section>

<section class="card">
  <h2>Situação das publicações</h2>
  <?php if ($pending): ?>
    <div class="alert alert-erro">Há envios não reconciliados. Nada será publicado até a conferência manual em <a href="<?= View::e(View::url('publicacoes')) ?>">Publicações</a>.</div>
  <?php endif; ?>
  <p><?= $paused ? '<span class="pill pill-paused">Pausado</span>' : '<span class="pill pill-ok">Sem pausa</span>' ?>
     <?= Settings::get($pdo, 'automacao_comercial') === '1' ? '<span class="pill pill-warn">Automação comercial autorizada</span>' : '<span class="pill pill-paused">Automação comercial desativada</span>' ?></p>
  <p class="muted">Fila: <?php foreach (Queue::STATES as $k => $label): if (!empty($queue[$k])): ?><span class="tag"><?= View::e($label) ?>: <?= (int)$queue[$k] ?></span><?php endif; endforeach; ?>
    <?= $queue ? '' : 'vazia' ?></p>
  <details><summary>Travas de envio ativas (<?= count($gates) ?>)</summary><ul><?php foreach ($gates as $g): ?><li><?= View::e($g) ?></li><?php endforeach; ?></ul></details>
</section>

<section class="grid two">
  <div class="card">
    <h2>Atalhos</h2>
    <p><a class="btn btn-primary" href="<?= View::e(View::url('importar')) ?>">Importar lote CSV/XLSX</a></p>
    <p><a class="btn" href="<?= View::e(View::url('oferta')) ?>">Cadastrar produto manualmente</a></p>
    <p><a class="btn" href="<?= View::e(View::url('ofertas', ['status' => 'aguardando_validacao'])) ?>">Validar ofertas pendentes</a></p>
    <p><a class="btn" href="<?= View::e(View::url('ranking')) ?>">Ver ranking de oportunidades</a></p>
    <p class="muted small">Painel anterior do Mercado Livre: <a href="mercadolivre.php">mercadolivre.php</a> (usa a sessão deste painel).</p>
  </div>
  <div class="card">
    <h2>Atividade recente</h2>
    <ul class="log">
      <?php foreach ($recent as $a): ?>
        <li><span class="muted small"><?= View::e(Time::show($a['at'])) ?></span> <?= View::e(str_replace('_', ' ', $a['action'])) ?> <?= $a['entity_id'] !== null ? '<span class="muted">#' . View::e($a['entity_id']) . '</span>' : '' ?></li>
      <?php endforeach; ?>
      <?php if (!$recent): ?><li class="muted">Sem registros.</li><?php endif; ?>
    </ul>
  </div>
</section>

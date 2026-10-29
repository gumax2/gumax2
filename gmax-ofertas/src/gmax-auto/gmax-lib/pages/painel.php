<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\{Db, Queue, Settings, Time, Url, View};
/** @var PDO $pdo @var int $staleHours @var bool $paused */
$byMp = $pdo->query("SELECT marketplace, validation_status, COUNT(*) n FROM gmax3_catalog GROUP BY marketplace, validation_status")->fetchAll();
$m = [];
foreach ($byMp as $r) {
    $m[$r['marketplace']][$r['validation_status']] = (int)$r['n'];
}
$total = static fn(string $mp) => array_sum($m[$mp] ?? []) - ($m[$mp]['arquivado'] ?? 0);
$staleSince = Time::now()->modify("-$staleHours hours")->format('Y-m-d H:i:s');
$stale = (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE validation_status='aprovado' AND (price_verified_at IS NULL OR price_verified_at < ?)", [$staleSince]);
$noLink = (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE (affiliate_url IS NULL OR affiliate_url='') AND validation_status NOT IN ('rejeitado','arquivado')");
$queue = $pdo->query('SELECT state, COUNT(*) FROM gmax3_pub_queue GROUP BY state')->fetchAll(PDO::FETCH_KEY_PAIR);
$gates = Queue::gates($pdo);
$pending = Queue::unreconciled($pdo);
$recent = $pdo->query('SELECT * FROM gmax3_audit ORDER BY id DESC LIMIT 8')->fetchAll();
?>
<section class="grid stats">
  <?php foreach (Url::MARKETPLACES as $mp => $label): ?>
  <a class="stat" href="<?= View::e(View::url('ofertas', ['marketplace' => $mp])) ?>">
    <span class="muted"><?= View::e($label) ?></span>
    <strong><?= $total($mp) ?></strong>
    <span class="small"><?= (int)($m[$mp]['aprovado'] ?? 0) ?> aprovada(s) · <?= (int)($m[$mp]['aguardando_validacao'] ?? 0) ?> aguardando · <?= (int)($m[$mp]['rascunho'] ?? 0) ?> rascunho(s)</span>
  </a>
  <?php endforeach; ?>
  <a class="stat<?= $stale ? ' stat-warn' : '' ?>" href="<?= View::e(View::url('ofertas', ['status' => 'aprovado', 'stale' => 1])) ?>">
    <span class="muted">Aprovadas com preço desatualizado</span><strong><?= $stale ?></strong><span class="small">conferência há mais de <?= $staleHours ?> h</span>
  </a>
  <a class="stat<?= $noLink ? ' stat-warn' : '' ?>" href="<?= View::e(View::url('links')) ?>">
    <span class="muted">Sem link afiliado</span><strong><?= $noLink ?></strong><span class="small">associar link oficial</span>
  </a>
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
    <p class="muted small">Painel anterior: <a href="index.php">index.php</a> · <a href="mercadolivre.php">mercadolivre.php</a> (continuam funcionando).</p>
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

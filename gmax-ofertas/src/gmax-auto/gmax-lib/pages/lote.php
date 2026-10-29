<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\{Db, Importer, Money, Time, Url, View};
/** @var PDO $pdo */
$id = isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$b = Db::one($pdo, 'SELECT * FROM gmax3_import_batches WHERE id=?', [$id]);
if (!$b) {
    echo '<div class="alert alert-erro">Lote não encontrado.</div>';
    return;
}
$items = Db::all($pdo, 'SELECT * FROM gmax3_import_items WHERE batch_id=? ORDER BY line', [$id]);
$labels = ['inserir' => 'Novo', 'atualizar' => 'Atualizar', 'sem_alteracao' => 'Sem alteração', 'rejeitado' => 'Rejeitado'];
?>
<section class="card">
  <h2>Lote #<?= (int)$b['id'] ?> — <?= View::e($b['filename']) ?></h2>
  <p><span class="badge st-<?= View::e($b['status']) ?>"><?= View::e($b['status']) ?></span> <?= View::e(Importer::FORMATS[$b['format']] ?? $b['format']) ?> · <?= View::e(Time::show($b['created_at'])) ?></p>
  <p class="grid stats small-stats">
    <span class="stat"><span class="muted">Linhas</span><strong><?= (int)$b['total'] ?></strong></span>
    <span class="stat"><span class="muted">Novos</span><strong><?= (int)$b['inserted'] ?></strong></span>
    <span class="stat"><span class="muted">Atualizar</span><strong><?= (int)$b['updated'] ?></strong></span>
    <span class="stat"><span class="muted">Sem alteração</span><strong><?= (int)$b['unchanged'] ?></strong></span>
    <span class="stat<?= $b['rejected'] ? ' stat-warn' : '' ?>"><span class="muted">Rejeitados</span><strong><?= (int)$b['rejected'] ?></strong></span>
  </p>
  <div class="actions">
  <?php if ($b['status'] === 'previa'): ?>
    <?= View::actionButton('import_apply', ['batch' => $id, 'back' => 'lote'], 'Confirmar importação', 'btn btn-primary', 'Gravar no catálogo os itens válidos desta prévia?') ?>
    <?= View::actionButton('import_discard', ['batch' => $id, 'back' => 'importar'], 'Descartar prévia', 'btn btn-ghost') ?>
    <p class="muted small">Linhas rejeitadas são ignoradas; corrija o arquivo e envie novamente se necessário.</p>
  <?php elseif ($b['status'] === 'aplicado'): ?>
    <?= View::actionButton('import_revert', ['batch' => $id, 'back' => 'lote'], 'Desfazer este lote', 'btn btn-danger', 'Remover os produtos criados e restaurar os atualizados por este lote?') ?>
  <?php endif; ?>
  <?php if ($b['note']): ?><p class="alert alert-aviso"><?= nl2br(View::e($b['note'])) ?></p><?php endif; ?>
  </div>
</section>
<section class="card">
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Linha</th><th>Resultado</th><th>Produto</th><th>Preços</th><th>Link afiliado</th><th>Observações</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it):
        $p = json_decode($it['payload_json'], true) ?: [];
        $errs = json_decode((string)$it['errors_json'], true) ?: [];
        $warns = json_decode((string)$it['warnings_json'], true) ?: [];
    ?>
      <tr class="<?= $it['action'] === 'rejeitado' ? 'row-bad' : '' ?>">
        <td data-label="Linha"><?= (int)$it['line'] ?></td>
        <td data-label="Resultado"><span class="badge act-<?= View::e($it['action']) ?>"><?= View::e($labels[$it['action']] ?? $it['action']) ?></span>
          <?php if ($it['catalog_id']): ?><a class="small" href="<?= View::e(View::url('oferta', ['id' => $it['catalog_id']])) ?>">#<?= (int)$it['catalog_id'] ?></a><?php endif; ?></td>
        <td data-label="Produto"><?= View::mp((string)($p['marketplace'] ?? '')) ?> <?= View::e($p['title'] ?? '') ?><div class="muted small"><?= View::e($p['codigo'] ?? '') ?> <?= View::e($p['external_id'] ?? '') ?></div></td>
        <td data-label="Preços"><?= View::priceBlock($p) ?></td>
        <td data-label="Link"><?= !empty($p['affiliate_url']) ? View::e($p['affiliate_url']) : '<span class="warn">—</span>' ?></td>
        <td data-label="Observações"><?php foreach ($errs as $e): ?><div class="err small">✗ <?= View::e($e) ?></div><?php endforeach; ?><?php foreach ($warns as $w): ?><div class="warn small">! <?= View::e($w) ?></div><?php endforeach; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

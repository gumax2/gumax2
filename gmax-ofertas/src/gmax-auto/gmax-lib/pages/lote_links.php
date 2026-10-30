<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\{Db, LinkImporter, LinkVerifier, Time, View};
/** @var PDO $pdo */
$id = isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
try {
    $b = LinkImporter::batch($pdo, $id);
} catch (Throwable) {
    echo '<div class="alert alert-erro">Lote de links não encontrado.</div>';
    return;
}
$items = Db::all($pdo, 'SELECT * FROM gmax3_import_items WHERE batch_id=? ORDER BY line', [$id]);
$applied = array_flip(array_column(array_filter($items, static fn($i) => $i['after_hash'] !== null), 'id'));
$verifLabel = [LinkVerifier::CONFERE => ['ok', 'Destino confere'], LinkVerifier::DIVERGE => ['err', 'Destino diverge'], LinkVerifier::INDETERMINADO => ['warn', 'Destino indeterminado']];
?>
<section class="card">
  <h2>Lote de links #<?= (int)$b['id'] ?> — <?= View::e($b['filename']) ?></h2>
  <p><span class="badge st-<?= View::e($b['status']) ?>"><?= View::e($b['status']) ?></span> · criado <?= View::e(Time::show($b['created_at'])) ?> por <?= View::e($b['actor']) ?>
    <?= $b['applied_at'] ? ' · aplicado ' . View::e(Time::show($b['applied_at'])) : '' ?><?= $b['reverted_at'] ? ' · desfeito ' . View::e(Time::show($b['reverted_at'])) : '' ?></p>
  <p class="grid stats small-stats">
    <span class="stat"><span class="muted">Linhas</span><strong><?= (int)$b['total'] ?></strong></span>
    <span class="stat"><span class="muted">Links novos</span><strong><?= (int)$b['inserted'] ?></strong></span>
    <span class="stat"><span class="muted">Alterações</span><strong><?= (int)$b['updated'] ?></strong></span>
    <span class="stat"><span class="muted">Já iguais</span><strong><?= (int)$b['unchanged'] ?></strong></span>
    <span class="stat<?= $b['rejected'] ? ' stat-warn' : '' ?>"><span class="muted">Erros / bloqueados</span><strong><?= (int)$b['rejected'] ?></strong></span>
  </p>
  <?php if ($b['status'] === 'previa'): ?>
    <div class="alert alert-aviso">O formato <code>https://meli.la/…</code> não prova que o link leva ao produto certo. Antes de aplicar, use <strong>Verificar destino dos links</strong> (o servidor consulta cada link curto sem abrir a página) ou confira os links manualmente. Links com destino divergente são bloqueados.</div>
    <div class="actions">
      <?= View::actionButton('link_batch_verify', ['batch' => $id, 'back' => 'lote_links'], 'Verificar destino dos links', 'btn') ?>
      <form method="post" action="painel.php" class="inline-form" data-loading data-confirm="Associar os links válidos desta prévia aos produtos? (um backup é feito antes)">
        <?= View::csrfField() ?><input type="hidden" name="action" value="link_batch_apply"><input type="hidden" name="batch" value="<?= (int)$id ?>"><input type="hidden" name="back" value="lote_links">
        <label class="check"><input type="checkbox" name="accept_unverified" value="1"> Conferi manualmente que cada link sem verificação "confere" leva ao produto da linha</label>
        <?php if ((int)$b['updated'] > 0): ?><label class="check"><input type="checkbox" name="replace" value="1"> Substituir links existentes (<?= (int)$b['updated'] ?>)</label><?php endif; ?>
        <button type="submit" class="btn btn-primary"<?= (int)$b['inserted'] + (int)$b['updated'] === 0 ? ' disabled' : '' ?>>Associar links</button>
      </form>
      <?= View::actionButton('link_batch_discard', ['batch' => $id, 'back' => 'links'], 'Descartar prévia', 'btn btn-ghost') ?>
    </div>
    <p class="muted small">Sem a verificação "Destino confere" ou a confirmação de conferência manual acima, o link <strong>não é associado</strong>. Só o link afiliado é gravado. Preços, desconto, condição de pagamento e disponibilidade não mudam, e os produtos continuam no estado atual (rascunhos continuam rascunhos).</p>
  <?php elseif ($b['status'] === 'aplicado'): ?>
    <?= View::actionButton('link_batch_revert', ['batch' => $id, 'back' => 'lote_links'], 'Desfazer: restaurar links anteriores', 'btn btn-danger', 'Restaurar os links que existiam antes deste lote?') ?>
  <?php endif; ?>
  <?php if ($b['note']): ?><div class="alert alert-aviso"><?= nl2br(View::e($b['note'])) ?></div><?php endif; ?>
</section>
<section class="card">
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Linha</th><th>Resultado</th><th>Anúncio</th><th>Produto cadastrado</th><th>Link</th><th>Verificação</th><th>Observações</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it):
        $p = json_decode($it['payload_json'], true) ?: [];
        $errs = json_decode((string)$it['errors_json'], true) ?: [];
        $warns = json_decode((string)$it['warnings_json'], true) ?: [];
        $v = $p['verificacao'] ?? null;
    ?>
      <tr class="<?= $it['action'] === 'rejeitado' ? 'row-bad' : '' ?>">
        <td data-label="Linha"><?= (int)$it['line'] ?><?= ($p['ranking'] ?? '') !== '' ? '<div class="muted small">rank ' . View::e($p['ranking']) . '</div>' : '' ?></td>
        <td data-label="Resultado"><span class="badge act-<?= View::e($it['action'] === 'novo_link' ? 'inserir' : ($it['action'] === 'alteracao' ? 'atualizar' : $it['action'])) ?>"><?= View::e(LinkImporter::LABELS[$it['action']] ?? $it['action']) ?></span>
          <?= isset($applied[$it['id']]) ? '<div class="ok small">aplicado</div>' : '' ?></td>
        <td data-label="Anúncio"><?= View::e($p['id_anuncio'] ?? '') ?></td>
        <td data-label="Produto"><?php if ($it['catalog_id']): ?><a href="<?= View::e(View::url('oferta', ['id' => $it['catalog_id']])) ?>">#<?= (int)$it['catalog_id'] ?> <?= View::e($p['produto_cadastrado'] ?? '') ?></a><?php else: ?><span class="muted">—</span><?php endif; ?>
          <?php if (($p['produto'] ?? '') !== '' && ($p['produto'] ?? '') !== ($p['produto_cadastrado'] ?? '')): ?><div class="muted small">planilha: <?= View::e($p['produto']) ?></div><?php endif; ?></td>
        <td data-label="Link"><code><?= View::e($p['url_afiliado'] ?? '') ?></code><?php if (!empty($p['link_anterior']) && $p['link_anterior'] !== ($p['url_afiliado'] ?? '')): ?><div class="muted small">atual: <?= View::e($p['link_anterior']) ?></div><?php endif; ?></td>
        <td data-label="Verificação"><?php if ($v): [$cls, $lbl] = $verifLabel[$v['resultado']] ?? ['warn', $v['resultado']]; ?><span class="<?= $cls ?> small"><?= View::e($lbl) ?></span><div class="muted small"><?= View::e($v['detalhe']) ?></div><?php else: ?><span class="muted small">não verificado</span><?php endif; ?></td>
        <td data-label="Observações"><?php foreach ($errs as $e): ?><div class="err small">✗ <?= View::e($e) ?></div><?php endforeach; ?><?php foreach ($warns as $w): ?><div class="warn small">! <?= View::e($w) ?></div><?php endforeach; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

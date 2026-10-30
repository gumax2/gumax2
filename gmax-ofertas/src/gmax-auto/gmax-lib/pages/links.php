<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\{Db, Url, View};
/** @var PDO $pdo */
$missing = $pdo->query("SELECT * FROM gmax3_catalog WHERE (affiliate_url IS NULL OR affiliate_url='') AND validation_status NOT IN ('rejeitado','arquivado') ORDER BY marketplace, id LIMIT 200")->fetchAll();
$invalid = array_filter($pdo->query("SELECT * FROM gmax3_catalog WHERE affiliate_url IS NOT NULL AND affiliate_url<>'' AND validation_status NOT IN ('rejeitado','arquivado')")->fetchAll(), static fn($r) => !Url::isAffiliate($r['marketplace'], $r['affiliate_url']));
$linkBatches = Db::all($pdo, 'SELECT * FROM gmax3_import_batches WHERE format=? ORDER BY id DESC LIMIT 10', [\Gmax\LinkImporter::FORMAT]);
?>
<section class="card" id="lote">
  <h2>Importar links em lote</h2>
  <form method="post" action="painel.php" enctype="multipart/form-data" data-loading class="stack">
    <?= View::csrfField() ?><input type="hidden" name="action" value="link_upload"><input type="hidden" name="back" value="links">
    <label>Arquivo CSV ou XLSX com as colunas <code>id_anuncio</code> e <code>url_afiliado</code> (opcionais: <code>ranking</code>, <code>produto</code>, <code>status_validacao</code>)
      <input type="file" name="arquivo" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></label>
    <button type="submit" class="btn btn-primary">Importar links em lote (gerar prévia)</button>
  </form>
  <p class="muted small">Cada link é associado pelo <strong>ID do anúncio</strong> (MLB) a um produto Mercado Livre já cadastrado, nunca pela posição da linha. Nenhum produto é criado, aprovado ou publicado, e preços e condições não mudam. A prévia mostra links novos, alterações, duplicidades e erros antes de qualquer gravação. Também aceita a planilha "Links para converter" preenchida.</p>
  <?php if ($linkBatches): ?>
  <div class="table-wrap"><table class="table compact"><thead><tr><th>Lote</th><th>Arquivo</th><th>Estado</th><th>Novos</th><th>Alterações</th><th>Iguais</th><th>Erros</th><th>Data</th></tr></thead><tbody>
  <?php foreach ($linkBatches as $b): ?><tr><td><a href="<?= View::e(View::url('lote_links', ['id' => $b['id']])) ?>">#<?= (int)$b['id'] ?></a></td><td><?= View::e($b['filename']) ?></td><td><span class="badge st-<?= View::e($b['status']) ?>"><?= View::e($b['status']) ?></span></td><td><?= (int)$b['inserted'] ?></td><td><?= (int)$b['updated'] ?></td><td><?= (int)$b['unchanged'] ?></td><td><?= (int)$b['rejected'] ?></td><td><?= View::e(\Gmax\Time::show($b['created_at'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</section>
<section class="card">
  <h2>Produtos sem link afiliado (<?= count($missing) ?>)</h2>
  <p class="muted small">Gere o link no gerador oficial do programa de afiliados a partir da URL do anúncio individual e cole aqui. O sistema não converte links automaticamente.</p>
  <?php foreach ($missing as $r): ?>
    <form method="post" action="painel.php" class="link-row" data-loading>
      <?= View::csrfField() ?><input type="hidden" name="action" value="link_set"><input type="hidden" name="back" value="links"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <div><?= View::mp($r['marketplace']) ?> <a href="<?= View::e(View::url('oferta', ['id' => $r['id']])) ?>"><?= View::e($r['title']) ?></a>
        <?php if ($r['original_url'] && Url::isProduct($r['marketplace'], $r['original_url'])): ?><div class="small"><a href="<?= View::e($r['original_url']) ?>" target="_blank" rel="noopener noreferrer nofollow">Abrir anúncio original ↗</a> <button type="button" class="btn btn-ghost btn-sm" data-copy-text="<?= View::e($r['original_url']) ?>">Copiar URL</button></div>
        <?php else: ?><div class="warn small">Sem URL individual: localize o anúncio antes de gerar o link.</div><?php endif; ?>
      </div>
      <input type="url" name="affiliate_url" required placeholder="<?= $r['marketplace'] === 'shopee' ? 'https://s.shopee.com.br/…' : 'https://meli.la/…' ?>" aria-label="Link afiliado para #<?= (int)$r['id'] ?>">
      <button type="submit" class="btn">Associar</button>
    </form>
  <?php endforeach; ?>
  <?php if (!$missing): ?><p class="ok">Todos os produtos ativos têm link afiliado.</p><?php endif; ?>
</section>
<?php if ($invalid): ?>
<section class="card">
  <h2>Links fora do padrão oficial (<?= count($invalid) ?>)</h2>
  <p class="muted small">Estes links vieram de cadastros antigos e não correspondem ao domínio do programa de afiliados. A publicação deles fica bloqueada.</p>
  <ul><?php foreach ($invalid as $r): ?><li><a href="<?= View::e(View::url('oferta', ['id' => $r['id']])) ?>">#<?= (int)$r['id'] ?> <?= View::e($r['title']) ?></a> — <code><?= View::e($r['affiliate_url']) ?></code></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>

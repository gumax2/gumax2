<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\{Importer, Time, View};
/** @var PDO $pdo */
$batches = $pdo->query('SELECT * FROM gmax3_import_batches ORDER BY id DESC LIMIT 30')->fetchAll();
?>
<section class="card">
  <h2>Enviar arquivo</h2>
  <form method="post" action="painel.php" enctype="multipart/form-data" data-loading class="stack">
    <?= View::csrfField() ?><input type="hidden" name="action" value="import_upload"><input type="hidden" name="back" value="importar">
    <label>Arquivo CSV ou XLSX (até 5 MB, 2.000 linhas)
      <input type="file" name="arquivo" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></label>
    <button type="submit" class="btn btn-primary">Ler arquivo e gerar prévia</button>
  </form>
  <p class="muted small">Etapa 1 gera uma prévia: nada é gravado no catálogo até você confirmar. Produtos repetidos são reconhecidos por código, ID do anúncio ou link afiliado e atualizados em vez de duplicados. Nenhuma importação publica mensagens.</p>
</section>

<section class="card">
  <h2>Modelos aceitos (detectados pelo cabeçalho)</h2>
  <ul>
    <?php foreach (Importer::FORMATS as $k => $label): ?><li><strong><?= View::e($label) ?></strong></li><?php endforeach; ?>
  </ul>
  <p><a class="btn" href="<?= View::e(View::url('modelo')) ?>">Baixar modelo GMAX unificado (CSV)</a></p>
  <p class="muted small">Planilhas do Excel podem ser enviadas diretamente (.xlsx). Para CSV, salve como "CSV UTF-8". Links do Mercado Livre precisam ser os links curtos <code>https://meli.la/…</code> gerados no programa de afiliados; páginas de busca/categoria são descartadas.</p>
</section>

<section class="card">
  <h2>Lotes recentes</h2>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>#</th><th>Arquivo</th><th>Formato</th><th>Estado</th><th>Novos</th><th>Atualizados</th><th>Sem alteração</th><th>Rejeitados</th><th>Data</th></tr></thead>
    <tbody>
    <?php foreach ($batches as $b): ?>
      <tr>
        <td><a href="<?= View::e(View::url($b['format'] === \Gmax\LinkImporter::FORMAT ? 'lote_links' : 'lote', ['id' => $b['id']])) ?>"><?= (int)$b['id'] ?></a></td>
        <td data-label="Arquivo"><?= View::e($b['filename']) ?></td>
        <td data-label="Formato"><?= View::e($b['format']) ?></td>
        <td data-label="Estado"><span class="badge st-<?= View::e($b['status']) ?>"><?= View::e($b['status']) ?></span></td>
        <td data-label="Novos"><?= (int)$b['inserted'] ?></td><td data-label="Atualizados"><?= (int)$b['updated'] ?></td><td data-label="Sem alteração"><?= (int)$b['unchanged'] ?></td><td data-label="Rejeitados"><?= (int)$b['rejected'] ?></td>
        <td data-label="Data"><?= View::e(Time::show($b['created_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$batches): ?><tr><td colspan="9" class="muted">Nenhum lote ainda.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

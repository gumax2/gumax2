<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\{Backup, Connectors\MercadoLivre, Connectors\ShopeeAffiliate, Migrator, Preflight, Settings, Time, View};
/** @var PDO $pdo @var bool $migrated */
$checks = Preflight::run($pdo);
$pending = Migrator::pending($pdo);
try {
    $backups = Backup::list();
} catch (Throwable $e) {
    $backups = [];
}
?>
<?php if ($pending): ?>
<section class="card card-alert">
  <h2>Migração pendente</h2>
  <p>A migração <strong><?= View::e(implode(', ', $pending)) ?></strong> cria as tabelas novas do painel (prefixo <code>gmax3_</code>) e <strong>copia</strong> os produtos Shopee e Mercado Livre existentes. As tabelas atuais (offers, slots, users, gmax_ml_offers, gmax_dispatches) não são alteradas. Um backup verificado é criado antes.</p>
  <?php if (Preflight::hasFailures($checks)): ?><div class="alert alert-erro">A verificação abaixo encontrou falhas. Corrija-as antes de migrar.</div><?php endif; ?>
  <form method="post" action="painel.php" class="inline-form" data-loading>
    <?= View::csrfField() ?><input type="hidden" name="action" value="migrate"><input type="hidden" name="back" value="sistema">
    <input name="confirm" maxlength="10" required placeholder="Digite MIGRAR" autocomplete="off" aria-label="Confirmação"><button type="submit" class="btn btn-primary"<?= Preflight::hasFailures($checks) ? ' disabled' : '' ?>>Fazer backup e migrar</button>
  </form>
</section>
<?php endif; ?>

<section class="card">
  <h2>Verificação do ambiente (somente leitura)</h2>
  <table class="table compact">
    <tbody>
    <?php foreach ($checks as [$lvl, $item, $detail]): ?>
      <tr class="chk-<?= View::e($lvl) ?>"><td><span class="chk chk-<?= View::e($lvl) ?>"><?= ['ok' => 'OK', 'aviso' => 'AVISO', 'falha' => 'FALHA'][$lvl] ?></span></td><td><?= View::e($item) ?></td><td class="small"><?= View::e($detail) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="card">
  <h2>Backups</h2>
  <?= View::actionButton('backup_create', ['back' => 'sistema'], 'Criar backup agora', 'btn') ?>
  <div class="table-wrap">
  <table class="table compact"><thead><tr><th>Arquivo</th><th>Tamanho</th><th>Data</th></tr></thead><tbody>
  <?php foreach (array_slice($backups, 0, 20) as $b): ?><tr><td><code><?= View::e($b['name']) ?></code></td><td><?= number_format($b['bytes'] / 1024, 0, ',', '.') ?> KB</td><td><?= View::e(date('d/m/Y H:i', $b['mtime'])) ?></td></tr><?php endforeach; ?>
  <?php if (!$backups): ?><tr><td colspan="3" class="muted">Nenhum backup ainda.</td></tr><?php endif; ?>
  </tbody></table>
  </div>
  <?php if ($migrated && $backups): ?>
  <details><summary>Restaurar dados do painel 3.0 a partir de um backup</summary>
    <form method="post" action="painel.php" class="stack" data-loading data-confirm="Restaurar os dados do painel 3.0 a partir do backup selecionado?">
      <?= View::csrfField() ?><input type="hidden" name="action" value="backup_restore"><input type="hidden" name="back" value="sistema">
      <select name="name" required aria-label="Backup"><?php foreach ($backups as $b): ?><option value="<?= View::e($b['name']) ?>"><?= View::e($b['name']) ?></option><?php endforeach; ?></select>
      <input name="confirm" maxlength="10" required placeholder="Digite RESTAURAR" autocomplete="off" aria-label="Confirmação">
      <button type="submit" class="btn btn-danger">Restaurar</button>
      <p class="muted small">Restaura somente as tabelas gmax3_ (catálogo, fila, lotes, configurações). As tabelas antigas não são tocadas. Um backup do estado atual é feito antes. A restauração completa do arquivo do banco é feita pelo cPanel (ver ROLLBACK.md).</p>
    </form>
  </details>
  <?php endif; ?>
</section>

<?php if ($migrated): $s = Settings::all($pdo); ?>
<section class="card">
  <h2>Configurações</h2>
  <form method="post" action="painel.php" class="form-grid" data-loading>
    <?= View::csrfField() ?><input type="hidden" name="action" value="settings_save"><input type="hidden" name="back" value="sistema">
    <label>Janela anti-duplicidade (dias) <input name="janela_duplicidade_dias" inputmode="numeric" value="<?= View::e($s['janela_duplicidade_dias'] ?? '7') ?>"></label>
    <label>Validade da conferência de preço (horas) <input name="validade_preco_horas" inputmode="numeric" value="<?= View::e($s['validade_preco_horas'] ?? '24') ?>"></label>
    <label class="span2">Horários de publicação (HH:MM separados por vírgula) <input name="horarios" value="<?= View::e($s['horarios'] ?? '') ?>"></label>
    <div class="span2"><button type="submit" class="btn">Salvar configurações</button></div>
  </form>
  <p class="muted small">Somente pela linha de comando, com frase de autorização: automação comercial = <strong><?= ($s['automacao_comercial'] ?? '0') === '1' ? 'AUTORIZADA' : 'desativada' ?></strong> · links Mercado Livre em grupo privado = <strong><?= ($s['ml_grupo_privado_permitido'] ?? '0') === '1' ? 'permitidos' : 'bloqueados' ?></strong>.</p>
</section>

<section class="card">
  <h2>Conectores oficiais</h2>
  <ul>
    <?php foreach ([new ShopeeAffiliate(), new MercadoLivre()] as $c): ?>
      <li><strong><?= View::e($c->name()) ?>:</strong> <?= View::e($c->status()) ?></li>
    <?php endforeach; ?>
  </ul>
  <p class="muted small">Os conectores apenas consultam dados oficiais. Nenhum deles gera ou converte links afiliados do Mercado Livre.</p>
</section>

<section class="card">
  <h2>Auditoria (últimos 100 eventos)</h2>
  <div class="table-wrap">
  <table class="table compact"><thead><tr><th>Quando</th><th>Quem</th><th>Ação</th><th>Alvo</th><th>Detalhe</th></tr></thead><tbody>
  <?php foreach ($pdo->query('SELECT * FROM gmax3_audit ORDER BY id DESC LIMIT 100') as $a): ?>
    <tr><td><?= View::e(Time::show($a['at'])) ?></td><td><?= View::e($a['actor']) ?></td><td><?= View::e($a['action']) ?></td><td><?= View::e(trim(($a['entity'] ?? '') . ' ' . ($a['entity_id'] ?? ''))) ?></td><td class="small"><?= View::e(mb_substr((string)$a['detail'], 0, 200)) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  </div>
</section>
<?php endif; ?>

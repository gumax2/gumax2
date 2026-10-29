<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\{Db, Queue, Settings, Time, View};
/** @var PDO $pdo @var bool $paused */
$rows = $pdo->query('SELECT q.*, c.title, c.marketplace FROM gmax3_pub_queue q LEFT JOIN gmax3_catalog c ON c.id=q.catalog_id ORDER BY q.id DESC LIMIT 200')->fetchAll();
$pending = Queue::unreconciled($pdo);
$gates = Queue::gates($pdo);
$legacy = Db::tableExists($pdo, 'gmax_dispatches') ? $pdo->query('SELECT * FROM gmax_dispatches ORDER BY rowid DESC LIMIT 50')->fetchAll() : [];
$heart = $pdo->query('SELECT * FROM gmax3_heartbeats ORDER BY name')->fetchAll();
?>
<section class="card">
  <h2>Controle</h2>
  <p><?= $paused ? '<span class="pill pill-paused">Pausado</span> ' . View::e(Settings::get($pdo, 'motivo_pausa')) : '<span class="pill pill-ok">Sem pausa</span>' ?></p>
  <div class="actions">
  <?php if (!$paused): ?>
    <form method="post" action="painel.php" class="inline-form" data-confirm="Ativar pausa emergencial?">
      <?= View::csrfField() ?><input type="hidden" name="action" value="pause"><input type="hidden" name="back" value="publicacoes">
      <input name="reason" maxlength="200" placeholder="Motivo (opcional)" aria-label="Motivo da pausa"><button class="btn btn-danger" type="submit">Pausa emergencial</button>
    </form>
  <?php else: ?>
    <form method="post" action="painel.php" class="inline-form" data-loading>
      <?= View::csrfField() ?><input type="hidden" name="action" value="resume"><input type="hidden" name="back" value="publicacoes">
      <input name="confirm" maxlength="10" placeholder="Digite RETOMAR" aria-label="Confirmação" required autocomplete="off"><button class="btn" type="submit">Remover pausa</button>
    </form>
  <?php endif; ?>
  </div>
  <details<?= $gates ? '' : ' open' ?>><summary>Travas de envio real (<?= count($gates) ?>)</summary><ul><?php foreach ($gates as $g): ?><li><?= View::e($g) ?></li><?php endforeach; ?></ul>
  <p class="muted small">Remover a pausa não habilita envios: a automação comercial só pode ser autorizada pela linha de comando, e o arquivo de ativação precisa existir na pasta privada.</p></details>
</section>

<?php if ($pending): ?>
<section class="card card-alert">
  <h2>Reconciliação necessária</h2>
  <p>Abra o supergrupo de homologação no Telegram e verifique se a mensagem foi publicada. Só então registre o resultado. Na dúvida, registre como <strong>publicado</strong>: assim a oferta nunca é repetida.</p>
  <ul><?php foreach ($pending as $p): ?><li><?= View::e($p) ?></li><?php endforeach; ?></ul>
  <?php foreach (Db::all($pdo, "SELECT q.*, c.title FROM gmax3_pub_queue q LEFT JOIN gmax3_catalog c ON c.id=q.catalog_id WHERE q.state IN ('reservado','incerto')") as $q): ?>
    <form method="post" action="painel.php" class="reconcile" data-loading>
      <?= View::csrfField() ?><input type="hidden" name="action" value="queue_reconcile"><input type="hidden" name="back" value="publicacoes"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
      <strong>Fila #<?= (int)$q['id'] ?></strong> — <?= View::e($q['title']) ?> <span class="muted small">(<?= View::e($q['note']) ?>)</span>
      <pre class="msg"><?= View::e($q['message_text']) ?></pre>
      <label><input type="radio" name="outcome" value="publicado" required> Está no grupo (publicado)</label>
      <label><input type="radio" name="outcome" value="nao_publicado"> NÃO está no grupo</label>
      <input name="message_id" inputmode="numeric" maxlength="12" placeholder="ID da mensagem (opcional)" aria-label="ID da mensagem">
      <input name="confirm" maxlength="10" placeholder="Digite CONFERI" required autocomplete="off" aria-label="Confirmação">
      <button class="btn" type="submit">Registrar</button>
    </form>
  <?php endforeach; ?>
  <?php if ($legacy): ?><p class="muted small">Pendências do publicador V2.1 (tabela gmax_dispatches) devem ser reconciliadas pelo procedimento do V2.1; este painel apenas as exibe.</p><?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
  <h2>Fila e histórico (3.0)</h2>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>#</th><th>Produto</th><th>Estado</th><th>Agendado</th><th>Concluído</th><th>Detalhe</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $q): ?>
      <tr>
        <td><?= (int)$q['id'] ?></td>
        <td data-label="Produto"><?= $q['marketplace'] ? View::mp($q['marketplace']) : '' ?> <a href="<?= View::e(View::url('oferta', ['id' => $q['catalog_id']])) ?>"><?= View::e($q['title'] ?? 'produto removido') ?></a></td>
        <td data-label="Estado"><?= View::badge($q['state']) ?></td>
        <td data-label="Agendado"><?= View::e(Time::show($q['scheduled_for'])) ?></td>
        <td data-label="Concluído"><?= View::e(Time::show($q['finished_at'])) ?></td>
        <td data-label="Detalhe" class="small"><?= View::e(mb_substr((string)$q['note'], 0, 220)) ?><?= $q['telegram_message_id'] ? ' · msg ' . (int)$q['telegram_message_id'] : '' ?></td>
        <td><?= in_array($q['state'], ['agendado', 'bloqueado'], true) ? View::actionButton('queue_cancel', ['id' => $q['id'], 'back' => 'publicacoes'], 'Cancelar', 'btn btn-ghost btn-sm') : '' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">Fila vazia.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="card">
  <h2>Registros do publicador V2.1 (somente leitura)</h2>
  <?php if (!$legacy): ?><p class="muted">Tabela gmax_dispatches vazia ou inexistente.</p><?php else: ?>
  <div class="table-wrap"><table class="table compact"><thead><tr><th>Janela</th><th>Item</th><th>Estado</th><th>HTTP</th><th>Mensagem</th><th>Nota</th></tr></thead><tbody>
  <?php foreach ($legacy as $d): ?><tr><td><?= View::e($d['slot'] ?? '') ?></td><td><?= View::e($d['item_id'] ?? '') ?></td><td><?= View::e($d['status'] ?? '') ?></td><td><?= View::e($d['http_code'] ?? '') ?></td><td><?= View::e($d['message_id'] ?? '') ?></td><td class="small"><?= View::e($d['response_note'] ?? '') ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>

<section class="card">
  <h2>Monitoramento dos agendadores</h2>
  <table class="table compact"><thead><tr><th>Processo</th><th>Última execução</th><th>Há</th></tr></thead><tbody>
  <?php foreach ($heart as $h): $age = Time::hoursSince($h['last_at']); ?>
    <tr><td><?= View::e($h['name']) ?></td><td><?= View::e(Time::show($h['last_at'])) ?></td><td class="<?= $age !== null && $age > 1 ? 'warn' : 'ok' ?>"><?= $age === null ? '—' : View::e(number_format($age * 60, 0, ',', '.')) . ' min' ?></td></tr>
  <?php endforeach; ?>
  <?php $lastLegacy = $legacy[0]['started_at'] ?? null; ?>
    <tr><td>Publicador V2.1 (último registro de envio)</td><td><?= View::e(Time::show($lastLegacy)) ?></td><td class="muted">—</td></tr>
  </tbody></table>
  <p class="muted small">O cron atual (cron.php / V2.1) não grava batimentos; o monitoramento completo começa quando a CLI 3.0 for agendada em modo simulação (ver INSTALACAO.md).</p>
</section>

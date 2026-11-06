<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\AutoLab\AutoLab;
use Gmax\{Config, Db, Money, Settings, Time, View};
/** @var PDO $pdo  GMAX AutoLab (3.2.1): homologação automática no Telegram privado, separada da publicação comercial. */
if (!AutoLab::installed($pdo)) {
    echo '<section class="card"><h2>GMAX AutoLab</h2><p>Aplique a migração <strong>006</strong> em Sistema para usar o AutoLab.</p></section>';
    return;
}
$st = AutoLab::state($pdo);
$labels = ['ativo' => ['Ativo', 'tag-ok'], 'pausado' => ['Pausado', 'tag-warn'], 'falha' => ['Com falha', 'tag-bad'], 'encerrado' => ['Período encerrado', 'tag-warn'], 'desativado' => ['Desativado', '']];
[$stLabel, $stClass] = $labels[$st] ?? [$st, ''];
$dest = json_decode(Settings::get($pdo, 'autolab_destino_info', ''), true);
$dest = is_array($dest) ? $dest : null;
$last = Db::one($pdo, 'SELECT * FROM gmax3_autolab_runs ORDER BY id DESC LIMIT 1');
$next = AutoLab::nextSlot($pdo);
$report = AutoLab::report($pdo, 7);
$tot = ['enviadas' => 0, 'bloqueadas' => 0, 'erros' => 0, 'incertas' => 0];
foreach ($report as $d) {
    foreach ($tot as $k => $_) {
        $tot[$k] += $d[$k];
    }
}
$msgs = Db::all($pdo, 'SELECT m.*, c.title AS cat_title FROM gmax3_autolab_messages m LEFT JOIN gmax3_catalog c ON c.id = m.catalog_id ORDER BY m.id DESC LIMIT 30');
$runs = Db::all($pdo, 'SELECT * FROM gmax3_autolab_runs ORDER BY id DESC LIMIT 15');
$kinds = ['oferta_validada' => 'Oferta validada', 'candidato' => 'Candidato', 'simulacao' => 'Simulação'];
$sources = ['api_shopee' => 'API Shopee (agora)', 'catalogo_ml' => 'Catálogo ML', 'catalogo_gmax' => 'Catálogo GMAX', 'simulacao' => 'Fictício'];
$lastOk = Db::one($pdo, "SELECT m.*, c.title AS cat_title FROM gmax3_autolab_messages m LEFT JOIN gmax3_catalog c ON c.id = m.catalog_id WHERE m.state='confirmado' ORDER BY m.id DESC LIMIT 1");
$since = Time::now()->modify('-7 days')->format('Y-m-d H:i:s');
// Motivos de falha/bloqueio dos últimos 7 dias, agrupados (o detalhe completo fica em "Execuções").
$reasons = Db::all($pdo, "SELECT status, detail, COUNT(*) n, MAX(started_at) ultima FROM gmax3_autolab_runs WHERE status IN ('bloqueado','falhou','sem_envio') AND started_at >= ? GROUP BY status, detail ORDER BY n DESC, ultima DESC LIMIT 8", [$since]);
$msgFail = Db::all($pdo, "SELECT state, note, COUNT(*) n FROM gmax3_autolab_messages WHERE state IN ('falhou','incerto') AND created_at >= ? GROUP BY state, note ORDER BY n DESC LIMIT 5", [$since]);
$slotFmt = static fn(?string $k) => $k ? substr($k, 8, 2) . '/' . substr($k, 5, 2) . ' ' . substr($k, 11, 5) : '—';
$stateTag = static fn(string $s) => '<span class="tag ' . (['confirmado' => 'tag-ok', 'incerto' => 'tag-warn', 'falhou' => 'tag-bad'][$s] ?? '') . '">' . View::e($s) . '</span>';
?>
<div class="alert alert-aviso" role="note"><strong>Homologação, não publicação comercial.</strong> O AutoLab envia só ao supergrupo privado de testes, só mensagens rotuladas <strong>TESTE</strong> ou <strong>SIMULAÇÃO</strong>, no máximo <?= AutoLab::dailyLimit($pdo) ?> por dia. Ele não usa a fila comercial nem altera produtos, preços ou links, e não libera nenhuma trava de publicação.</div>

<?php if ($st === 'ativo' && !$lastOk): ?>
<div class="alert alert-aviso" role="status"><strong>Primeiro envio real ainda não observado.</strong> O AutoLab está ativo, mas nenhuma mensagem foi confirmada pelo Telegram até agora. Próximo horário: <strong><?= View::e($slotFmt($next)) ?></strong>. Depois dele, confira no grupo privado a mensagem com a “Ref. AutoLab” correspondente.</div>
<?php endif; ?>
<section class="card">
  <h2>Estado</h2>
  <div class="grid stats">
    <div class="stat"><span class="muted">Estado</span><strong><span class="tag <?= $stClass ?>"><?= View::e($stLabel) ?></span></strong></div>
    <div class="stat"><span class="muted">Próximo horário</span><strong><?= $st === 'ativo' && $next ? View::e(substr($next, 8, 2) . '/' . substr($next, 5, 2) . ' ' . substr($next, 11)) : '—' ?></strong></div>
    <div class="stat"><span class="muted">Enviadas (7 dias)</span><strong><?= $tot['enviadas'] ?></strong></div>
    <div class="stat"><span class="muted">Bloqueadas</span><strong><?= $tot['bloqueadas'] ?></strong></div>
    <div class="stat"><span class="muted">Incertas</span><strong><?= $tot['incertas'] ?></strong></div>
    <div class="stat"><span class="muted">Erros</span><strong><?= $tot['erros'] ?></strong></div>
  </div>
  <p class="small"><strong>Último envio confirmado pelo Telegram:</strong>
    <?php if ($lastOk): ?><span class="tag tag-ok">✓ confirmado</span> <?= View::e($slotFmt($lastOk['slot_key'])) ?> · msg <?= (int)$lastOk['telegram_message_id'] ?> · <?= View::e($kinds[$lastOk['kind']] ?? $lastOk['kind']) ?>
      <?= $lastOk['catalog_id'] ? ' · produto #' . (int)$lastOk['catalog_id'] : ($lastOk['candidate_id'] ? ' · candidato ML #' . (int)$lastOk['candidate_id'] : '') ?> · preço: <?= View::e($sources[$lastOk['source']] ?? $lastOk['source']) ?><?= (int)$lastOk['price_confirmed'] ? ' (confirmado)' : ' (sem preço confirmado)' ?>
    <?php else: ?><span class="tag tag-warn">nenhum até agora</span><?php endif; ?></p>
  <?php if (($m = Settings::get($pdo, 'autolab_motivo', '')) !== ''): ?><p class="small"><strong>Motivo:</strong> <?= View::e($m) ?></p><?php endif; ?>
  <p class="small">Período autorizado até <strong><?= View::e(Time::show(Settings::get($pdo, 'autolab_ate', '') ?: null)) ?></strong> · horários <strong><?= View::e(implode(', ', AutoLab::times($pdo))) ?></strong> (Brasília) · última execução
    <?= $last ? View::e(Time::show($last['started_at'])) . ' — ' . View::e($last['status']) : '—' ?></p>
  <div class="actions">
    <?php if ($st === 'ativo'): ?>
      <?= View::actionButton('autolab_pause', ['back' => 'autolab'], 'PAUSAR AUTOLAB AGORA', 'btn btn-danger') ?>
    <?php elseif ($st === 'pausado'): ?>
      <?= View::actionButton('autolab_resume', ['back' => 'autolab'], 'Retomar AutoLab', 'btn', 'Retomar o AutoLab? Ele volta a enviar mensagens de TESTE ao grupo privado nos próximos horários.') ?>
    <?php endif; ?>
  </div>
  <?php if (in_array($st, ['desativado', 'encerrado', 'falha'], true)): ?>
  <p class="muted small">Ativar, renovar o período ou retomar após falha só pela linha de comando: <code>gmax-admin-cli.php autolab validar-destino</code> e depois <code>autolab ativar <?= AutoLab::ACTIVATION_PHRASE ?> --dias 7</code>.</p>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Motivos de falha e bloqueio (7 dias)</h2>
  <?php if (!$reasons && !$msgFail): ?><p class="muted small">Nenhuma falha ou bloqueio registrado.</p><?php else: ?>
  <ul class="small">
    <?php foreach ($reasons as $r): ?><li><span class="tag <?= $r['status'] === 'sem_envio' ? '' : 'tag-bad' ?>"><?= View::e($r['status']) ?> ×<?= (int)$r['n'] ?></span> <?= View::e((string)$r['detail']) ?> <span class="muted">(último <?= View::e(Time::show($r['ultima'])) ?>)</span></li><?php endforeach; ?>
    <?php foreach ($msgFail as $r): ?><li><span class="tag <?= $r['state'] === 'incerto' ? 'tag-warn' : 'tag-bad' ?>">envio <?= View::e($r['state']) ?> ×<?= (int)$r['n'] ?></span> <?= View::e((string)$r['note']) ?></li><?php endforeach; ?>
  </ul><?php endif; ?>
  <p class="muted small">Pausa por falha não é desfeita automaticamente: a retomada é sempre decisão sua.</p>
</section>

<section class="card">
  <h2>Destino de homologação</h2>
  <p class="small">Supergrupo autorizado: <code><?= View::e(Config::PRIVATE_CHAT_ID) ?></code> (fixo no código; não pode ser trocado pelo painel).</p>
  <?php if ($dest): ?>
  <ul class="small">
    <li>Última validação: <?= View::e(Time::show($dest['em'] ?? null)) ?> — <?= empty($dest['problemas']) ? '<span class="tag tag-ok">aprovado</span>' : '<span class="tag tag-bad">recusado</span>' ?></li>
    <li>Grupo: <?= View::e((string)($dest['title'] ?? '—')) ?> · <?= View::e((string)($dest['type'] ?? '—')) ?> · <?= !empty($dest['public_username']) ? '<span class="tag tag-bad">público</span>' : 'privado' ?></li>
    <li>Membros: <?= View::e((string)($dest['members'] ?? '—')) ?> (limite <?= AutoLab::maxMembers($pdo) ?>) · bot: <?= View::e((string)($dest['bot_status'] ?? '—')) ?></li>
    <?php foreach ($dest['problemas'] ?? [] as $p): ?><li><span class="tag tag-bad">problema</span> <?= View::e((string)$p) ?></li><?php endforeach; ?>
  </ul>
  <p class="muted small">O destino é verificado de novo antes de cada envio (grupo privado, membros, bot).</p>
  <?php else: ?><p class="muted small">Ainda não validado. Rode <code>gmax-admin-cli.php autolab validar-destino</code> (somente leitura, não envia nada).</p><?php endif; ?>
</section>

<section class="card">
  <h2>Relatório diário (7 dias)</h2>
  <?php $idle = array_filter($report, fn($d) => !$d['enviadas'] && !$d['incertas'] && !$d['falhas_envio'] && !$d['bloqueadas'] && !$d['sem_envio'] && !$d['erros']); ?>
  <ul class="small">
    <?php foreach ($report as $i => $d): if (isset($idle[$i])) { continue; } ?>
      <li><strong><?= View::e(substr($d['dia'], 8, 2) . '/' . substr($d['dia'], 5, 2)) ?></strong> · enviadas <?= $d['enviadas'] ?> · incertas <?= $d['incertas'] ?> · falhas <?= $d['falhas_envio'] ?> · bloqueadas <?= $d['bloqueadas'] ?> · sem envio <?= $d['sem_envio'] ?> · erros <?= $d['erros'] ?>
        <?= $d['tipos'] ? '<span class="muted">(' . View::e(implode(', ', array_map(fn($k, $n) => ($kinds[$k] ?? $k) . " $n", array_keys($d['tipos']), $d['tipos']))) . ')</span>' : '' ?></li>
    <?php endforeach; ?>
    <?php if ($idle): ?><li class="muted">Sem atividade: <?= View::e(implode(', ', array_map(fn($d) => substr($d['dia'], 8, 2) . '/' . substr($d['dia'], 5, 2), $idle))) ?></li><?php endif; ?>
  </ul>
</section>

<section class="card">
  <h2>Histórico das mensagens</h2>
  <?php if (!$msgs): ?><p class="muted">Nenhuma mensagem ainda.</p><?php endif; ?>
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>Horário</th><th>Tipo</th><th>Produto</th><th>Origem dos dados</th><th>Preço</th><th>Telegram</th></tr></thead>
    <tbody><?php foreach ($msgs as $m): ?>
      <tr>
        <td><?= View::e(substr($m['slot_key'], 8, 2) . '/' . substr($m['slot_key'], 5, 2) . ' ' . substr($m['slot_key'], 11)) ?></td>
        <td><?= View::e($kinds[$m['kind']] ?? $m['kind']) ?></td>
        <td class="small"><?php if ($m['catalog_id']): ?><a href="painel.php?p=oferta&amp;id=<?= (int)$m['catalog_id'] ?>">#<?= (int)$m['catalog_id'] ?></a> <?= View::e(mb_substr((string)$m['cat_title'], 0, 60)) ?><?php elseif ($m['candidate_id']): ?>Candidato ML #<?= (int)$m['candidate_id'] ?><?php else: ?>—<?php endif; ?></td>
        <td class="small"><?= View::e($sources[$m['source']] ?? $m['source']) ?></td>
        <td class="small"><?= $m['price_cents'] !== null ? View::e(Money::format((int)$m['price_cents'])) . ((int)$m['price_confirmed'] ? ' <span class="tag tag-ok">confirmado</span>' : ' <span class="tag tag-warn">não conferido</span>') : '<span class="muted">sem preço</span>' ?></td>
        <td class="small"><?= $stateTag((string)$m['state']) ?><?= $m['http_code'] ? ' HTTP ' . (int)$m['http_code'] : '' ?><?= $m['telegram_message_id'] ? ' · msg ' . (int)$m['telegram_message_id'] : '' ?><?= $m['note'] ? '<br><span class="muted">' . View::e((string)$m['note']) . '</span>' : '' ?></td>
      </tr>
      <tr><td colspan="6"><details><summary class="small">Texto enviado<?= $m['missing'] ? ' · faltando: ' . View::e(implode('; ', json_decode((string)$m['missing'], true) ?: [])) : '' ?></summary><pre class="msg small"><?= View::e((string)$m['text']) ?></pre></details></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</section>

<section class="card">
  <h2>Execuções</h2>
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>Horário</th><th>Resultado</th><th>Detalhe</th></tr></thead>
    <tbody><?php foreach ($runs as $r): ?>
      <tr><td><?= View::e($r['slot_key']) ?></td><td><?= View::e($r['status']) ?></td><td class="small"><?= View::e((string)$r['detail']) ?>
        <?php if ($r['checks']): ?><details><summary>verificações</summary><ul class="small"><?php foreach (json_decode((string)$r['checks'], true) ?: [] as $c): ?><li><?= View::e((string)$c) ?></li><?php endforeach; ?></ul></details><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</section>

<section class="card">
  <h2>Horários e limite</h2>
  <form method="post" action="painel.php" class="form-grid" data-loading>
    <?= View::csrfField() ?><input type="hidden" name="action" value="autolab_config"><input type="hidden" name="back" value="autolab">
    <label>Horários (Brasília, HH:MM separados por vírgula; intervalo mínimo de <?= AutoLab::MIN_GAP_MIN ?> min) <input name="horarios" value="<?= View::e(implode(',', AutoLab::times($pdo))) ?>" maxlength="40"></label>
    <label>Máximo de mensagens por dia (1–<?= AutoLab::MAX_PER_DAY ?>) <input name="limite" inputmode="numeric" maxlength="1" value="<?= AutoLab::dailyLimit($pdo) ?>"></label>
    <div class="span2"><button type="submit" class="btn">Salvar horários</button></div>
  </form>
  <p class="muted small">Destino, duração, limite de membros e uso de link afiliado não são alteráveis pelo painel.</p>
</section>

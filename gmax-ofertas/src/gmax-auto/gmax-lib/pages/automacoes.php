<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\Automation\{Alerts, Runner, Schedule};
use Gmax\Connectors\Registry;
use Gmax\{Db, Message, Queue, Ranking, Settings, Time, Url, View};
/** @var PDO $pdo @var int $staleHours */
$paused = Settings::get($pdo, 'automacoes_pausadas', '1') !== '0';
$jobs = Runner::jobs($pdo);
$since = Time::now()->modify('-7 days')->format('Y-m-d H:i:s');
$kpi = Db::one($pdo, "SELECT COUNT(*) runs, COALESCE(SUM(found),0) found, COALESCE(SUM(created),0) created, COALESCE(SUM(updated),0) updated,
    COALESCE(SUM(approved),0) approved, COALESCE(SUM(blocked),0) blocked, COALESCE(SUM(status='falhou'),0) failed FROM gmax3_job_runs WHERE started_at >= ?", [$since]);
$alerts = Db::all($pdo, "SELECT a.*, c.title FROM gmax3_alerts a LEFT JOIN gmax3_catalog c ON c.id=a.catalog_id WHERE a.resolved_at IS NULL ORDER BY a.last_seen_at DESC LIMIT 60");
$alertCount = (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_alerts WHERE resolved_at IS NULL');
$runs = Db::all($pdo, 'SELECT r.*, j.name FROM gmax3_job_runs r LEFT JOIN gmax3_jobs j ON j.job=r.job ORDER BY r.id DESC LIMIT 30');
$usage = Db::all($pdo, 'SELECT * FROM gmax3_api_usage WHERE day=?', [\Gmax\RateLimiter::day()]);
$usageBy = array_column($usage, null, 'connector');
$top = array_slice(array_values(array_filter(Ranking::list($pdo, [], 60), static fn($r) => in_array($r['item']['validation_status'], ['aprovado', 'aguardando_validacao'], true))), 0, 6);
$todayQueue = Queue::countOnDay($pdo, Time::now()->setTimezone(new DateTimeZone('America/Sao_Paulo')));
$limit = Settings::int($pdo, 'limite_diario_telegram_privado', 6);
$templates = Db::all($pdo, "SELECT * FROM gmax3_templates ORDER BY (marketplace='*' AND category='*') DESC, marketplace, category");
$heart = Db::one($pdo, "SELECT * FROM gmax3_heartbeats WHERE name='automacoes'");
?>
<section class="card">
  <div class="actions">
    <?php if ($paused): ?>
      <span class="pill pill-paused">Automações pausadas</span>
      <form method="post" action="painel.php" class="inline-form" data-loading>
        <?= View::csrfField() ?><input type="hidden" name="action" value="auto_resume"><input type="hidden" name="back" value="automacoes">
        <input name="confirm" maxlength="10" placeholder="Digite RETOMAR" required autocomplete="off" aria-label="Confirmação"><button class="btn" type="submit">Retomar automações</button>
      </form>
    <?php else: ?>
      <span class="pill pill-ok">Automações ativas</span>
      <?= View::actionButton('auto_pause', ['back' => 'automacoes'], 'Pausa geral das automações', 'btn btn-danger', 'Pausar todas as automações agendadas?') ?>
    <?php endif; ?>
    <?php if (!$paused && (!$heart || \Gmax\Time::hoursSince($heart['last_at']) > 0.5)): ?><span class="pill pill-warn">O Cron do executor não chama há mais de 30 min</span><?php endif; ?>
    <a class="small" href="painel.php?p=integracoes">Integrações (OAuth)</a> <span class="muted small">Cron: <?= $heart ? 'última chamada ' . View::e(Time::show($heart['last_at'])) . ' (' . View::e($heart['detail']) . ')' : 'ainda não configurado' ?></span>
  </div>
  <p class="muted small">As automações pesquisam, revisam e selecionam ofertas. <strong>Nenhuma delas envia mensagens</strong>: a publicação continua bloqueada pelas travas da fila (pausa de envios, autorização da automação comercial, arquivo de ativação e reconciliação). Aprovação automática: <strong><?= Settings::get($pdo, 'aprovacao_automatica', '0') === '1' ? 'AUTORIZADA (via linha de comando)' : 'desligada — aprovação manual' ?></strong>.</p>
</section>

<section class="grid stats">
  <div class="stat"><span class="muted">Execuções (7 dias)</span><strong><?= (int)$kpi['runs'] ?></strong><span class="small"><?= (int)$kpi['failed'] ?> com falha</span></div>
  <div class="stat"><span class="muted">Produtos encontrados</span><strong><?= (int)$kpi['found'] ?></strong><span class="small"><?= (int)$kpi['created'] ?> novos no catálogo</span></div>
  <div class="stat"><span class="muted">Produtos atualizados</span><strong><?= (int)$kpi['updated'] ?></strong><span class="small">preço/estado/fila</span></div>
  <div class="stat"><span class="muted">Ofertas aprovadas · bloqueadas</span><strong><?= (int)$kpi['approved'] ?> · <?= (int)$kpi['blocked'] ?></strong><span class="small">últimos 7 dias</span></div>
  <a class="stat<?= $alertCount ? ' stat-warn' : '' ?>" href="#alertas"><span class="muted">Precisam de você</span><strong><?= $alertCount ?></strong><span class="small">alertas abertos</span></a>
  <div class="stat"><span class="muted">Fila de hoje</span><strong><?= $todayQueue ?>/<?= $limit ?></strong><span class="small">limite diário do canal</span></div>
</section>

<section class="card">
  <h2>Automações</h2>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Tarefa</th><th>Agendamento</th><th>Próxima</th><th>Última execução</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($jobs as $j):
        [$t, $d] = Schedule::parse($j['times'], $j['weekdays']);
        $next = Schedule::next($j);
        $last = Db::one($pdo, 'SELECT * FROM gmax3_job_runs WHERE job=? ORDER BY id DESC LIMIT 1', [$j['job']]);
        $fid = 'job-' . $j['job'];
    ?>
      <tr>
        <td data-label="Tarefa"><strong><?= View::e($j['name']) ?></strong><div class="muted small"><?= View::e($j['job']) ?></div>
          <label class="check small"><input form="<?= $fid ?>" type="checkbox" name="enabled" value="1"<?= $j['enabled'] ? ' checked' : '' ?>> ativa</label></td>
        <td data-label="Agendamento"><input form="<?= $fid ?>" name="times" value="<?= View::e(implode(',', $t)) ?>" aria-label="Horários" maxlength="120">
          <div class="weekdays"><?php foreach (Schedule::DAY_NAMES as $n => $lbl): ?><label class="check small"><input form="<?= $fid ?>" type="checkbox" name="weekdays[]" value="<?= $n ?>"<?= in_array($n, $d, true) ? ' checked' : '' ?>><?= View::e($lbl) ?></label><?php endforeach; ?></div></td>
        <td data-label="Próxima"><?= $j['enabled'] && !$paused && $next ? View::e($next->format('d/m H:i')) : '<span class="muted">' . ($paused ? 'pausada' : 'inativa') . '</span>' ?></td>
        <td data-label="Última"><?php if ($last): ?><span class="badge run-<?= View::e($last['status']) ?>"><?= View::e($last['status']) ?></span> <span class="small"><?= View::e(Time::show($last['started_at'])) ?></span>
          <div class="muted small"><?= (int)$last['found'] ?> enc. · <?= (int)$last['created'] ?> novos · <?= (int)$last['updated'] ?> atual. · <?= (int)$last['blocked'] ?> bloq.</div><?php else: ?><span class="muted">nunca</span><?php endif; ?></td>
        <td><form id="<?= $fid ?>" method="post" action="painel.php" class="inline" data-loading><?= View::csrfField() ?><input type="hidden" name="action" value="auto_job_save"><input type="hidden" name="back" value="automacoes"><input type="hidden" name="job" value="<?= View::e($j['job']) ?>"><button class="btn btn-sm" type="submit">Salvar</button></form>
          <?= View::actionButton('auto_run', ['job' => $j['job'], 'back' => 'automacoes'], 'Executar agora', 'btn btn-sm', 'Executar "' . $j['name'] . '" agora? (nenhuma mensagem será enviada)') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small">Horários no fuso de São Paulo, separados por vírgula. O Cron do cPanel chama o executor a cada 15 minutos; cada horário roda uma única vez, mesmo com chamadas repetidas, e um horário perdido há mais de <?= Schedule::CATCH_UP_MINUTES / 60 ?> h é ignorado.</p>
</section>

<section class="grid two">
<div class="card">
  <h2>Melhores oportunidades agora</h2>
  <?php if (!$top): ?><p class="muted">Nenhuma oferta aprovada ou aguardando validação.</p><?php endif; ?>
  <ol class="toplist">
  <?php foreach ($top as $row): $r = $row['item']; ?>
    <li><a href="<?= View::e(View::url('oferta', ['id' => $r['id']])) ?>"><?= View::e($r['title']) ?></a>
      <div class="small"><?= View::mp($r['marketplace']) ?> <strong class="score"><?= $row['score'] === null ? '—' : (int)$row['score'] ?></strong> pts · confiança <?= (int)$row['confidence'] ?>% · <?= View::badge($r['validation_status']) ?></div></li>
  <?php endforeach; ?>
  </ol>
  <p><a href="<?= View::e(View::url('ranking')) ?>">Ranking completo →</a></p>
</div>
<div class="card">
  <h2>Integrações</h2>
  <table class="table compact"><thead><tr><th>Fonte</th><th>Situação</th><th>Hoje</th></tr></thead><tbody>
  <?php foreach (Registry::MARKETPLACES as $mp => $label): $conn = Registry::get($mp, $pdo); $u = $usageBy[$mp] ?? null; ?>
    <tr><td><?= View::e($label) ?></td><td class="small <?= $conn->configured() ? 'ok' : 'warn' ?>"><?= View::e($conn->status()) ?></td>
      <td class="small"><?= $u ? (int)$u['requests'] . ' req. · ' . (int)$u['failures'] . ' falha(s)' . ($u['last_error'] ? '<div class="err">' . View::e($u['last_error']) . '</div>' : '') : '—' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <p class="muted small">Credenciais ficam em <code>gmax-secrets/</code> (fora da pasta pública). Limite: <?= Settings::int($pdo, 'api_limite_diario', 500) ?> requisições/dia por fonte, intervalo mínimo de <?= Settings::int($pdo, 'api_intervalo_ms', 1100) ?> ms. Links do Mercado Livre não são convertidos automaticamente (não há API autorizada para isso); a Shopee fornece o link oficial na própria busca, quando liberada.</p>
</div>
</section>

<section class="card" id="alertas">
  <h2>Precisam de intervenção (<?= $alertCount ?>)</h2>
  <?php if (!$alerts): ?><p class="ok">Nenhum alerta aberto.</p><?php else: ?>
  <div class="table-wrap"><table class="table compact"><thead><tr><th>Tipo</th><th>Produto</th><th>Detalhe</th><th>Visto</th><th></th></tr></thead><tbody>
  <?php foreach ($alerts as $a): ?>
    <tr><td><span class="tag"><?= View::e(Alerts::KINDS[$a['kind']] ?? $a['kind']) ?></span></td>
      <td><?= $a['catalog_id'] ? '<a href="' . View::e(View::url('oferta', ['id' => $a['catalog_id']])) . '">#' . (int)$a['catalog_id'] . ' ' . View::e(mb_substr((string)$a['title'], 0, 50)) . '</a>' : '—' ?></td>
      <td class="small"><?= View::e($a['message']) ?></td><td class="small"><?= View::e(Time::show($a['last_seen_at'])) ?></td>
      <td><?= View::actionButton('auto_alert_resolve', ['id' => $a['id'], 'back' => 'automacoes'], 'Resolvido', 'btn btn-ghost btn-sm') ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>

<section class="grid two">
<div class="card">
  <h2>Pesquisa e limites</h2>
  <form method="post" action="painel.php" class="stack" data-loading>
    <?= View::csrfField() ?><input type="hidden" name="action" value="settings_save"><input type="hidden" name="back" value="automacoes">
    <label>Termos de pesquisa — Mercado Livre (um por linha: categorias ou palavras-chave)
      <textarea name="termos_descoberta_mercadolivre" rows="4" maxlength="2000"><?= View::e(Settings::get($pdo, 'termos_descoberta_mercadolivre')) ?></textarea></label>
    <label>Termos de pesquisa — Shopee
      <textarea name="termos_descoberta_shopee" rows="4" maxlength="2000"><?= View::e(Settings::get($pdo, 'termos_descoberta_shopee')) ?></textarea></label>
    <label>Limite diário de publicações (Telegram privado) <input name="limite_diario_telegram_privado" inputmode="numeric" value="<?= $limit ?>"></label>
    <label>Faixa de preço de maior conversão (R$, ex.: 20-300) <input name="faixa_preco_conversao" value="<?= View::e(Settings::get($pdo, 'faixa_preco_conversao', '20-300')) ?>"></label>
    <button class="btn" type="submit">Salvar</button>
  </form>
</div>
<div class="card">
  <h2>Modelos de mensagem</h2>
  <p class="muted small">Campos: <?php foreach (Message::FIELDS as $f): ?><code>{<?= $f ?>}</code> <?php endforeach; ?>. Uma linha cujo campo não tenha dado confirmado é omitida (sem "De" inventado). Precedência: marketplace + categoria → marketplace → padrão.</p>
  <?php foreach ($templates as $tpl): ?>
    <details><summary><?= View::e($tpl['marketplace'] === '*' ? 'Todas as lojas' : (Url::MARKETPLACES[$tpl['marketplace']] ?? $tpl['marketplace'])) ?> · <?= View::e($tpl['category'] === '*' ? 'todas as categorias' : $tpl['category']) ?></summary>
      <form method="post" action="painel.php" class="stack" data-loading>
        <?= View::csrfField() ?><input type="hidden" name="action" value="template_save"><input type="hidden" name="back" value="automacoes">
        <input type="hidden" name="marketplace" value="<?= View::e($tpl['marketplace']) ?>"><input type="hidden" name="category" value="<?= View::e($tpl['category']) ?>">
        <textarea name="body" rows="10" maxlength="1500" aria-label="Modelo"><?= View::e($tpl['body']) ?></textarea>
        <button class="btn btn-sm" type="submit">Salvar modelo</button>
      </form>
      <?php if (!($tpl['marketplace'] === '*' && $tpl['category'] === '*')): ?><?= View::actionButton('template_delete', ['id' => $tpl['id'], 'back' => 'automacoes'], 'Remover modelo', 'btn btn-ghost btn-sm', 'Remover este modelo?') ?><?php endif; ?>
    </details>
  <?php endforeach; ?>
  <details><summary>+ Novo modelo por loja/categoria</summary>
    <form method="post" action="painel.php" class="stack" data-loading>
      <?= View::csrfField() ?><input type="hidden" name="action" value="template_save"><input type="hidden" name="back" value="automacoes">
      <select name="marketplace" aria-label="Marketplace"><option value="*">Todas as lojas</option><?php foreach (Url::MARKETPLACES as $k => $l): ?><option value="<?= $k ?>"><?= View::e($l) ?></option><?php endforeach; ?></select>
      <input name="category" maxlength="80" placeholder="Categoria (vazio = todas)" aria-label="Categoria">
      <textarea name="body" rows="10" maxlength="1500" aria-label="Modelo"><?= View::e(Message::DEFAULT_TEMPLATE) ?></textarea>
      <button class="btn btn-sm" type="submit">Criar modelo</button>
    </form>
  </details>
</div>
</section>

<section class="card" id="historico">
  <h2>Histórico de tarefas</h2>
  <p class="small"><a href="<?= View::e(View::url('execucoes')) ?>">Ver todos os produtos analisados, com filtros por execução, produto, marketplace e resultado →</a></p>
  <div class="table-wrap"><table class="table compact"><thead><tr><th>#</th><th>Tarefa</th><th>Gatilho</th><th>Início</th><th>Estado</th><th>Enc.</th><th>Novos</th><th>Atual.</th><th>Aprov.</th><th>Bloq.</th><th>Erros</th><th>Detalhe</th><th></th></tr></thead><tbody>
  <?php foreach ($runs as $r): ?>
    <tr><td><?= (int)$r['id'] ?></td><td><?= View::e($r['name'] ?? $r['job']) ?></td><td><?= View::e($r['trigger']) ?></td><td><?= View::e(Time::show($r['started_at'])) ?></td>
      <td><span class="badge run-<?= View::e($r['status']) ?>"><?= View::e($r['status']) ?></span></td>
      <td><?= (int)$r['found'] ?></td><td><?= (int)$r['created'] ?></td><td><?= (int)$r['updated'] ?></td><td><?= (int)$r['approved'] ?></td><td><?= (int)$r['blocked'] ?></td><td><?= (int)$r['errors'] ?></td>
      <td class="small"><?= nl2br(View::e(mb_substr((string)$r['detail'], 0, 400))) ?></td>
      <td><a class="btn btn-ghost btn-sm" href="<?= View::e(View::url('execucoes', ['run' => $r['id']])) ?>">Ver detalhes</a></td></tr>
  <?php endforeach; ?>
  <?php if (!$runs): ?><tr><td colspan="13" class="muted">Nenhuma execução ainda.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>

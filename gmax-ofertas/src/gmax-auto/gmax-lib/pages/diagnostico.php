<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\{Health, Retention, Time, View};
/** @var PDO $pdo  Monitor de saúde e diagnóstico de tarefas (3.2.1). Somente leitura. */
Health::rememberWebUser($pdo);
$checks = Health::collect($pdo);
$jobs = Health::jobs($pdo);
$sim = Retention::last($pdo);
$tag = static fn(string $l) => '<span class="tag ' . ['ok' => 'tag-ok', 'aviso' => 'tag-warn', 'falha' => 'tag-bad'][$l] . '">' . ['ok' => 'ok', 'aviso' => 'atenção', 'falha' => 'falha'][$l] . '</span>';
$bad = count(array_filter($checks, fn($c) => $c[0] !== 'ok'));
?>
<section class="card">
  <h2>Saúde do sistema <?= $bad ? '<span class="tag tag-warn">' . $bad . ' ponto(s) de atenção</span>' : '<span class="tag tag-ok">tudo ok</span>' ?></h2>
  <ul class="health">
    <?php foreach ($checks as $name => [$lvl, $txt]): ?>
      <li><?= $tag($lvl) ?> <strong><?= View::e($name) ?>:</strong> <span class="small"><?= View::e($txt) ?></span></li>
    <?php endforeach; ?>
  </ul>
  <div class="actions">
    <?= View::actionButton('health_check', ['back' => 'diagnostico'], 'Verificar integridade do banco', 'btn btn-sm') ?>
  </div>
  <p class="muted small">Permissões: a correção para 0600/0700 só é segura quando o PHP do site e o do Cron rodam com o mesmo usuário dono dos arquivos. Veja o plano com <code>gmax-admin-cli.php permissoes</code> (não altera nada) e aplique com <code>--aplicar</code>.</p>
</section>

<section class="card">
  <h2>Diagnóstico das tarefas</h2>
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>Tarefa</th><th>Ativa</th><th>Última</th><th>Duração</th><th>7 dias</th><th>Próxima</th></tr></thead>
    <tbody><?php foreach ($jobs as $j): ?>
      <tr>
        <td><?= View::e($j['nome']) ?></td>
        <td><?= $j['ativa'] ? 'sim' : 'não' ?></td>
        <td><?= $j['ultima'] ? View::e($j['ultima']) . ' <span class="muted small">' . View::e(Time::show($j['ultima_em'])) . '</span>' : '—' ?><?= $j['continuacao'] ? '<br><span class="tag tag-warn">continuação pendente (' . (int)$j['continuacao']['rodada'] + 1 . ')</span>' : '' ?></td>
        <td><?= $j['duracao_s'] !== null ? (int)$j['duracao_s'] . ' s' : '—' ?></td>
        <td class="small"><?= View::e(implode(' · ', array_map(fn($k, $n) => "$k $n", array_keys($j['semana']), $j['semana'])) ?: '—') ?></td>
        <td class="small"><?= $j['proxima'] ? View::e(substr($j['proxima'], 8, 2) . '/' . substr($j['proxima'], 5, 2) . ' ' . substr($j['proxima'], 11)) : '—' ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <p class="muted small">Uma execução interrompida pelo servidor é fechada como "falhou" no Cron seguinte, com alerta, e a revisão continua de onde parou. Para validar isso na hospedagem sem tocar nos dados reais: <code>gmax-automacoes-cli.php validar-retomada</code> (usa uma cópia temporária do banco e nenhuma API).</p>
</section>

<section class="card">
  <h2>Retenção de registros (simulação)</h2>
  <p class="small">Nada é apagado automaticamente. Elimináveis: somente logs operacionais detalhados com mais de 90 dias. Auditoria, publicações, reconciliações, decisões administrativas, histórico comercial e AutoLab são preservados.</p>
  <?php if (!Retention::available($pdo)): ?><p class="muted">Aplique a migração 005.</p><?php else: ?>
  <div class="actions"><?= View::actionButton('retention_simulate', ['back' => 'diagnostico'], 'Simular retenção agora', 'btn btn-sm') ?></div>
  <?php if ($sim && is_array($sim['result'])): $r = $sim['result']; ?>
    <p class="small">Simulação #<?= (int)$sim['id'] ?> de <?= View::e(Time::show($sim['created_at'])) ?>: <strong><?= number_format((int)$r['total_eliminavel'], 0, ',', '.') ?> linha(s)</strong> seriam eliminadas.</p>
    <ul class="small">
      <?php foreach ($r['eliminaveis'] as $t => $s): ?>
        <li><strong><?= View::e($s['descricao']) ?></strong>: <?= number_format((int)$s['quantidade'], 0, ',', '.') ?> de <?= number_format((int)$s['total_na_tabela'], 0, ',', '.') ?> (anteriores a <?= View::e(Time::show($s['corte'])) ?>)<?= $s['quantidade'] ? ' · de ' . View::e(Time::show($s['primeira'])) . ' a ' . View::e(Time::show($s['ultima'])) : '' ?></li>
      <?php endforeach; ?>
      <?php foreach ($r['protegidos'] as $t => $p): ?>
        <li class="muted">Preservado — <?= View::e($p['descricao']) ?>: <?= number_format((int)$p['linhas'], 0, ',', '.') ?> linha(s)</li>
      <?php endforeach; ?>
      <li class="muted">Backups: <?= (int)$r['backups']['quantidade'] ?> arquivo(s), nenhum apagado.</li>
    </ul>
    <p class="muted small">A lista completa (tabela, ID, data) está em <code>gmax-secrets/gmax-retencao/<?= View::e((string)$r['lista']) ?></code>. A exclusão só acontece pela linha de comando, com frase de confirmação, backup verificado, teste de recuperação e exportação verificada antes.</p>
  <?php endif; endif; ?>
</section>

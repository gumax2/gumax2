<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\Connectors\EndpointGuard;
use Gmax\{Db, Settings, Time, View};
/** @var PDO $pdo @var array $s @var list<string> $mlbIds  Central de Integrações — diagnóstico do Mercado Livre (3.1.7). */
$diag = json_decode(Settings::get($pdo, 'ml_diagnostico_ultimo', ''), true);
$diag = is_array($diag) ? $diag : null;
$guard = EndpointGuard::state($pdo);
$history = Db::all($pdo, "SELECT at, actor, action, entity_id, detail FROM gmax3_audit WHERE action IN ('ml_diagnostico','ml_endpoint_suspenso','ml_endpoint_reativado','ml_item_testado','ml_oauth_testado','ml_oauth_falhou') ORDER BY id DESC LIMIT 10");
$usage = Db::all($pdo, "SELECT day, requests, failures, last_error FROM gmax3_api_usage WHERE connector='mercadolivre' ORDER BY day DESC LIMIT 5");
$typeLabel = ['ok' => ['OK', 'tag-ok'], 'implementacao' => ['Implementação/rede', 'tag-warn'], 'configuracao' => ['Configuração', 'tag-warn'], 'autorizacao' => ['Falta de autorização', 'tag-warn'], 'plataforma' => ['Restrição da plataforma', 'tag-warn'], 'anuncio' => ['Anúncio indisponível', 'tag-warn']];
$failed = $diag ? array_values(array_filter($diag['testes'], static fn($t) => !$t['ok'] && ($t['tipo'] ?? '') !== 'ok')) : [];
$support = null;
if ($diag && $failed) {
    $lines = ["Assunto: GMAX Smart Sales — HTTP 403 em recursos de leitura de anúncios (MLB)", '',
        'Aplicação: GMAX Smart Sales (Client ID / App ID: ' . ((new \Gmax\OAuth\MercadoLivreOAuth())->clientId() ?? 'ver DevCenter') . '; aplicativo não certificado; finalidade: negócios; 1 a 10 usuários)',
        'Permissões funcionais configuradas: Usuários (leitura e escrita), Comunicações pré e pós-venda (leitura), Publicação e sincronização (leitura). Conta reautorizada após salvar as permissões.',
        'Conta autorizada: usuário terminado em ' . ($s['user_id_end'] ?? '????') . ' (OAuth Authorization Code + PKCE S256; escopos concedidos: ' . (($s['scope'] ?? '') ?: 'ver DevCenter') . ')',
        'Uso pretendido: somente leitura (GET) de preço, disponibilidade e dados públicos de anúncios para conferência de ofertas do Programa de Afiliados; nenhuma publicação ou alteração.',
        'Data/hora do teste: ' . Time::show((string)$diag['em']) . ' (horário de Brasília)', '', 'Resultados:'];
    foreach ($diag['testes'] as $t) {
        $lines[] = sprintf('- %s %s (%s): HTTP %s%s', $t['metodo'], $t['endpoint'], $t['autenticado'] ? 'com token' : 'sem token', $t['http'] ?? 'sem resposta', $t['mensagem_api'] ? ' — "' . $t['mensagem_api'] . '"' : '');
    }
    $lines = array_merge($lines, ['', 'Perguntas:',
        '1. Os recursos /sites/MLB/search e /items/{id} (anúncios de outros vendedores) estão restritos para este tipo de aplicação? Desde quando?',
        '2. Qual permissão funcional, escopo ou aprovação é necessária para liberar a leitura desses recursos? Existe formulário ou processo de certificação?',
        '3. Quais endpoints oficiais vocês recomendam para consultar preço e disponibilidade de anúncios de terceiros para uso de afiliados (ex.: /products, /items/{id}/sale_price, multiget)?',
        '4. Existe API oficial do Programa de Afiliados (geração de links ou catálogo de produtos)?',
        '5. Há limite de requisições específico para esta aplicação?', '',
        'Nenhum token, segredo ou dado pessoal foi incluído nesta mensagem.']);
    $support = implode("\n", $lines);
}
?>
<section class="card" id="ml-diagnostico">
  <h2>Mercado Livre — diagnóstico dos recursos</h2>
  <p class="muted small">Testes somente de leitura. Comparam a mesma consulta com e sem token para separar <strong>restrição da plataforma</strong> de <strong>falta de autorização do aplicativo</strong>. Tokens e segredos nunca são exibidos nem gravados.</p>

  <h3>Situação dos recursos nas automações</h3>
  <div class="table-wrap"><table class="table compact"><thead><tr><th>Recurso</th><th>Situação</th><th>Respostas 403</th><th>Última mensagem da API</th><th></th></tr></thead><tbody>
  <?php foreach (EndpointGuard::GROUPS as $g => $label): $st = $guard[$g] ?? null; ?>
    <tr><td><?= View::e($label) ?></td>
      <td><?= !empty($st['suspended_at']) ? '<span class="tag tag-warn">Suspensa desde ' . View::e(Time::show($st['suspended_at'])) . '</span>' : '<span class="tag tag-ok">Ativa</span>' ?></td>
      <td><?= (int)($st['count'] ?? 0) ?></td><td class="small"><?= View::e($st['last_msg'] ?? '—') ?></td>
      <td><?php if (!empty($st['suspended_at'])): ?>
        <form method="post" action="painel.php" class="inline-form" data-loading><?= View::csrfField() ?><input type="hidden" name="action" value="ml_endpoint_reactivate"><input type="hidden" name="back" value="integracoes"><input type="hidden" name="grupo" value="<?= View::e($g) ?>">
          <input name="confirm" maxlength="10" placeholder="Digite REATIVAR" required autocomplete="off" aria-label="Confirmação para reativar <?= View::e($label) ?>"><button type="submit" class="btn btn-sm">Reativar</button></form>
      <?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <form method="post" action="painel.php" class="inline-form" data-loading>
    <?= View::csrfField() ?><input type="hidden" name="action" value="settings_save"><input type="hidden" name="back" value="integracoes">
    <label>Suspensão automática após 403 repetido
      <select name="ml_suspensao_403"><option value="1"<?= Settings::get($pdo, 'ml_suspensao_403', '1') === '1' ? ' selected' : '' ?>>Ativada</option><option value="0"<?= Settings::get($pdo, 'ml_suspensao_403', '1') === '0' ? ' selected' : '' ?>>Desativada</option></select></label>
    <label>Suspender após (respostas 403 seguidas) <input name="ml_403_limite" inputmode="numeric" maxlength="2" value="<?= EndpointGuard::threshold($pdo) ?>"></label>
    <button type="submit" class="btn btn-sm">Salvar</button>
  </form>
  <p class="muted small">Desativar a suspensão automática não reativa grupos já suspensos: use “Reativar” em cada um. A suspensão não desconecta a conta OAuth: só evita que a descoberta e a revisão repitam consultas já negadas. A conferência manual continua disponível para todos os produtos.</p>

  <h3>Diagnóstico completo</h3>
  <?php if (!$s['connected']): ?>
    <p class="warn small">Conecte a conta acima para executar o diagnóstico.</p>
  <?php else: ?>
  <form method="post" action="painel.php" class="inline-form" data-loading>
    <?= View::csrfField() ?><input type="hidden" name="action" value="ml_diagnose"><input type="hidden" name="back" value="integracoes">
    <input name="mlb" maxlength="30" placeholder="Anúncio para testar (MLB…), opcional" list="gmax-mlb-ids" aria-label="Anúncio para testar">
    <button class="btn btn-primary" type="submit">Executar diagnóstico (≈ 25 s)</button>
  </form>
  <?php endif; ?>
  <?php if ($diag): ?>
    <p class="small">Último diagnóstico: <?= View::e(Time::show((string)$diag['em'])) ?><?= $diag['mlb'] ? ' · anúncio ' . View::e($diag['mlb']) : ' · sem anúncio informado (testes de /items não executados)' ?></p>
    <ul class="log"><?php foreach ($diag['conclusoes'] as $c): [$tl, $tc] = $typeLabel[$c['tipo']] ?? [$c['tipo'], 'tag']; ?><li><span class="tag <?= $tc ?>"><?= View::e($tl) ?></span> <?= View::e($c['texto']) ?></li><?php endforeach; ?></ul>
    <div class="table-wrap"><table class="table compact"><thead><tr><th>Teste</th><th>Requisição</th><th>HTTP</th><th>Resultado</th><th>Mensagem da API</th><th>Causa provável</th><th>Orientação</th></tr></thead><tbody>
    <?php foreach ($diag['testes'] as $t): ?>
      <tr><td><?= View::e($t['titulo']) ?></td><td class="small"><code><?= View::e($t['metodo'] . ' ' . $t['endpoint']) ?></code> <?= $t['autenticado'] ? 'com token' : 'sem token' ?></td>
        <td><strong><?= $t['http'] !== null ? (int)$t['http'] : '—' ?></strong></td>
        <td><span class="tag <?= $t['ok'] ? 'tag-ok' : 'tag-warn' ?>"><?= View::e($t['resultado']) ?></span></td>
        <td class="small"><?= View::e($t['mensagem_api'] ?? $t['erro_rede'] ?? '—') ?></td><td class="small"><?= View::e($t['causa']) ?></td><td class="small"><?= View::e($t['orientacao']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>

  <h3>Recomendações</h3>
  <ol class="small">
    <li>No Mercado Livre Developers (DevCenter), abra o aplicativo <strong>GMAX Smart Sales</strong> e confira as <strong>permissões funcionais</strong> de leitura dos grupos de recursos de itens/buscas. Salve e <strong>reconecte a conta</strong> aqui (o token novo herda as permissões).</li>
    <li>Confira se a conta concluiu a validação de dados, se o aplicativo está ativo e se não há lista de IPs restringindo o servidor.</li>
    <li>Execute o diagnóstico novamente. Se a pesquisa e os anúncios continuarem negados <strong>com e sem token</strong>, é restrição da plataforma: envie a solicitação abaixo ao suporte do Mercado Livre Developers.</li>
    <li>Enquanto isso, use a <strong>conferência manual</strong> (Ofertas → Conferir preço). Os links afiliados do Mercado Livre continuam sendo gerados no portal de afiliados (não há API oficial de afiliados).</li>
  </ol>

  <?php if ($support): ?>
  <h3>Solicitação ao suporte (pronta para copiar)</h3>
  <pre class="msg" id="ml-suporte"><?= View::e($support) ?></pre>
  <button type="button" class="btn btn-sm" data-copy="#ml-suporte" aria-live="polite">Copiar solicitação</button>
  <?php endif; ?>

  <details><summary>Histórico de testes e erros (<?= count($history) ?>)</summary>
    <ul class="log small"><?php foreach ($history as $h): ?><li><span class="muted"><?= View::e(Time::show($h['at'])) ?></span> <?= View::e(str_replace('_', ' ', $h['action'])) ?> <?= View::e($h['entity_id'] ?? '') ?> <span class="muted"><?= View::e(mb_substr((string)$h['detail'], 0, 200)) ?></span></li><?php endforeach; ?>
    <?php if (!$history): ?><li class="muted">Sem registros.</li><?php endif; ?></ul>
    <?php if ($usage): ?><p class="small">Uso diário da API: <?php foreach ($usage as $u): ?><span class="tag"><?= View::e($u['day']) ?>: <?= (int)$u['requests'] ?> req., <?= (int)$u['failures'] ?> falha(s)</span><?php endforeach; ?></p><?php endif; ?>
  </details>
</section>

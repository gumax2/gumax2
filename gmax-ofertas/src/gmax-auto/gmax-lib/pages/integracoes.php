<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\OAuth\MercadoLivreOAuth;
use Gmax\{Time, View};
/** @var PDO $pdo */
$o = new MercadoLivreOAuth();
$s = $o->status();
$probe = $_SESSION['gmax3_ml_probe'] ?? null;
unset($_SESSION['gmax3_ml_probe']);
$itemProbe = $_SESSION['gmax3_ml_item_probe'] ?? null;
unset($_SESSION['gmax3_ml_item_probe']);
$mlbIds = $pdo->query("SELECT DISTINCT substr(external_id, 6) FROM gmax3_catalog WHERE external_id LIKE 'item:MLB%' ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_COLUMN);
$host = $_SERVER['HTTP_HOST'] ?? 'gmaxcanais.com.br';
$path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/gmax-auto/painel.php'), '/');
$suggested = 'https://' . preg_replace('/[^a-z0-9.\-]/i', '', $host) . $path . '/ml-oauth-callback.php';
?>
<section class="card">
  <h2>Mercado Livre — OAuth (somente leitura)</h2>
  <p class="muted small">Usado para pesquisar produtos e consultar preços. Nenhum anúncio é criado ou alterado. O fluxo usa Authorization Code + PKCE (S256) + state; o token é cifrado na pasta privada e renovado automaticamente (com rotação do refresh token).</p>
  <table class="table compact"><tbody>
    <tr><td>Aplicativo (gmax-secrets/mercadolivre-app.php)</td><td><?= $s['app'] ? '<span class="ok">configurado</span>' : '<span class="warn">' . View::e(implode('; ', $s['app_problems'])) . '</span>' ?></td></tr>
    <tr><td>Redirect URI configurada</td><td><code><?= View::e($s['redirect_uri'] ?: '—') ?></code></td></tr>
    <tr><td>Redirect URI deste servidor</td><td><code><?= View::e($suggested) ?></code><?= $s['redirect_uri'] && $s['redirect_uri'] !== $suggested ? ' <span class="warn small">(diferente da configurada: confira)</span>' : '' ?></td></tr>
    <tr><td>Conta conectada</td><td><?= $s['connected'] ? '<span class="ok">sim</span> · usuário …' . View::e($s['user_id_end']) : ($s['invalid'] ? '<span class="err">autorização expirada/revogada — reconecte</span>' : '<span class="warn">não</span>') ?></td></tr>
    <?php if ($s['connected']): ?>
    <tr><td>Token de acesso válido até</td><td><?= View::e(date('d/m/Y H:i', (int)$s['expires_at'])) ?> (renova sozinho)</td></tr>
    <tr><td>Última emissão/renovação</td><td><?= $s['refreshed_at'] ? View::e(date('d/m/Y H:i', (int)$s['refreshed_at'])) : '—' ?></td></tr>
    <tr><td>Escopos concedidos</td><td><code><?= View::e($s['scope'] ?: '—') ?></code></td></tr>
    <?php endif; ?>
  </tbody></table>
  <div class="actions">
    <?php if ($s['app']): ?>
      <?= View::actionButton('ml_oauth_start', ['back' => 'integracoes'], $s['connected'] ? 'Reconectar conta' : 'Conectar conta do Mercado Livre', 'btn btn-primary') ?>
      <?php if ($s['connected']): ?><?= View::actionButton('ml_oauth_test', ['back' => 'integracoes'], 'Testar permissões (conta e pesquisa)', 'btn') ?><?php endif; ?>
    <?php endif; ?>
  </div>
  <?php if ($probe): ?>
    <div class="alert <?= $probe['busca']['ok'] ? 'alert-ok' : 'alert-aviso' ?>">
      <strong>Teste de <?= View::e(Time::show($probe['em'])) ?>:</strong> conta (/users/me): <?= View::e($probe['conta']['detalhe']) ?> · pesquisa (/sites/MLB/search): <?= View::e($probe['busca']['detalhe']) ?>.
      <?php if (!$probe['busca']['ok']): ?><br>A pesquisa não está liberada para este aplicativo: a descoberta automática do Mercado Livre fica inativa; a revisão de preços por anúncio (/items) continua funcionando se a conta responder.<?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if ($s['connected']): ?>
  <h3>Consulta individual de anúncio (/items/{id})</h3>
  <p class="muted small">Testa, separadamente da pesquisa, se o aplicativo consegue ler um anúncio. Somente leitura: nada é gravado no catálogo.</p>
  <form method="post" action="painel.php" class="inline-form" data-loading>
    <?= View::csrfField() ?><input type="hidden" name="action" value="ml_item_test"><input type="hidden" name="back" value="integracoes">
    <input name="mlb" maxlength="30" placeholder="MLB1234567890" required list="gmax-mlb-ids" aria-label="Código do anúncio">
    <datalist id="gmax-mlb-ids"><?php foreach ($mlbIds as $m): ?><option value="<?= View::e($m) ?>"><?php endforeach; ?></datalist>
    <button class="btn" type="submit">Testar /items</button>
  </form>
  <?php if (!$mlbIds): ?><p class="small warn">Nenhum produto do catálogo tem código MLB de anúncio: a revisão de preços não tem o que consultar no Mercado Livre.</p><?php endif; ?>
  <?php if ($itemProbe): ?>
    <div class="alert <?= $itemProbe['ok'] ? 'alert-ok' : 'alert-aviso' ?>">
      <strong>Teste de <?= View::e(Time::show($itemProbe['em'])) ?>:</strong> <code><?= View::e($itemProbe['endpoint']) ?></code>
      · <?= $itemProbe['http'] !== null ? 'HTTP ' . (int)$itemProbe['http'] : 'sem resposta HTTP' ?> · <?= View::e($itemProbe['detalhe']) ?>
      <?php if ($itemProbe['ok']): ?><br>Preço: <?= $itemProbe['preco'] !== null ? View::e(\Gmax\Money::format((int)$itemProbe['preco'])) : 'não informado' ?>
        · De: <?= $itemProbe['preco_original'] !== null ? View::e(\Gmax\Money::format((int)$itemProbe['preco_original'])) : 'não informado' ?> · Situação: <?= View::e($itemProbe['situacao'] ?? '—') ?><?php endif; ?>
    </div>
  <?php endif; ?>
  <details><summary>Desconectar</summary>
    <form method="post" action="painel.php" class="inline-form" data-loading>
      <?= View::csrfField() ?><input type="hidden" name="action" value="ml_oauth_disconnect"><input type="hidden" name="back" value="integracoes">
      <input name="confirm" maxlength="12" placeholder="Digite DESCONECTAR" required autocomplete="off" aria-label="Confirmação"><button class="btn btn-danger" type="submit">Remover token do servidor</button>
    </form>
  </details>
  <?php endif; ?>
</section>
<section class="card">
  <h2>Como configurar</h2>
  <ol class="small">
    <li>No Mercado Livre Developers, cadastre como <strong>Redirect URI</strong> exatamente: <code><?= View::e($suggested) ?></code></li>
    <li>No cPanel, crie <code>/home1/gust1922/gmax-secrets/mercadolivre-app.php</code> (permissão 0600) com o App ID, a Secret Key e a mesma Redirect URI.</li>
    <li>Volte aqui e clique em <strong>Conectar conta do Mercado Livre</strong>. Depois, <strong>Testar permissões</strong>.</li>
  </ol>
  <p class="muted small">Nunca envie a Secret Key ou tokens por chat ou e-mail. O painel não exibe esses valores.</p>
</section>

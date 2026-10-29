<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\{Catalog, Db, Message, Money, Queue, Ranking, Rules, Time, Url, View};
/** @var PDO $pdo @var int $staleHours */
$id = isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$r = $id ? Catalog::get($pdo, $id) : null;
if ($id && !$r) {
    echo '<div class="alert alert-erro">Produto não encontrado.</div>';
    return;
}
$form = $_SESSION['gmax3_form'] ?? null;
unset($_SESSION['gmax3_form']);
$v = static function (string $field, ?string $fromRow = null) use ($form, $r): string {
    if (is_array($form) && isset($form[$field]) && is_string($form[$field])) {
        return $form[$field];
    }
    return (string)($r[$fromRow ?? $field] ?? '');
};
$mp = $v('marketplace') ?: 'mercadolivre';
?>
<div class="grid two-wide">
<form method="post" action="painel.php" class="card" data-loading data-discount>
  <?= View::csrfField() ?>
  <input type="hidden" name="action" value="offer_save"><input type="hidden" name="back" value="oferta">
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <h2><?= $r ? 'Editar produto #' . (int)$id : 'Novo produto' ?></h2>
  <div class="form-grid">
    <label>Marketplace
      <select name="marketplace" <?= $r ? 'disabled' : '' ?>><?php foreach (Url::MARKETPLACES as $k => $l): ?><option value="<?= $k ?>"<?= $mp === $k ? ' selected' : '' ?>><?= View::e($l) ?></option><?php endforeach; ?></select>
      <?php if ($r): ?><input type="hidden" name="marketplace" value="<?= View::e($mp) ?>"><?php endif; ?>
    </label>
    <label>Código GMAX <input name="codigo" value="<?= View::e($v('codigo')) ?>" maxlength="40" placeholder="ML-002"></label>
    <label class="span2">Nome do produto * <input name="title" required maxlength="300" value="<?= View::e($v('title')) ?>"></label>
    <label>Categoria <input name="category" maxlength="80" value="<?= View::e($v('category')) ?>" list="cats"></label>
    <label>Loja/vendedor <input name="store_name" maxlength="120" value="<?= View::e($v('store_name')) ?>"></label>
    <label class="span2">URL do anúncio individual <input name="original_url" type="url" maxlength="2048" value="<?= View::e($v('original_url')) ?>" placeholder="https://produto.mercadolivre.com.br/MLB-…"></label>
    <label class="span2">Link afiliado oficial <input name="affiliate_url" type="url" maxlength="300" value="<?= View::e($v('affiliate_url')) ?>" placeholder="https://meli.la/… ou https://s.shopee.com.br/…"></label>
    <label>Preço original (De) <input name="price_original" inputmode="decimal" value="<?= View::e($form['price_original'] ?? Money::input(isset($r['price_original_cents']) ? (int)$r['price_original_cents'] : null)) ?>" placeholder="199,90" data-price="orig"></label>
    <label>Preço promocional (Por) <input name="price_promo" inputmode="decimal" value="<?= View::e($form['price_promo'] ?? Money::input(isset($r['price_promo_cents']) ? (int)$r['price_promo_cents'] : null)) ?>" placeholder="119,90" data-price="promo"></label>
    <p class="span2 discount-live" data-discount-output aria-live="polite"></p>
    <label>Condição de pagamento
      <select name="payment_condition"><option value="">Não identificada</option><?php foreach (Catalog::PAYMENT as $k => $l): ?><option value="<?= $k ?>"<?= $v('payment_condition') === $k ? ' selected' : '' ?>><?= View::e($l) ?></option><?php endforeach; ?></select>
    </label>
    <label>Observação da condição <input name="payment_note" maxlength="200" value="<?= View::e($v('payment_note')) ?>" placeholder="ex.: cupom GMAX10"></label>
    <label>Disponibilidade
      <select name="availability"><?php foreach (Catalog::AVAILABILITY as $k => $l): ?><option value="<?= $k ?>"<?= ($v('availability') ?: 'desconhecida') === $k ? ' selected' : '' ?>><?= View::e($l) ?></option><?php endforeach; ?></select>
    </label>
    <label>Reputação do vendedor <input name="seller_reputation" maxlength="60" value="<?= View::e($v('seller_reputation')) ?>" placeholder="MercadoLíder Platinum"></label>
    <label>Vendas públicas <input name="sales_public" maxlength="40" value="<?= View::e($v('sales_public')) ?>" placeholder="+10 mil"></label>
    <label>Avaliação (0–5) <input name="rating" inputmode="decimal" maxlength="5" value="<?= View::e($v('rating')) ?>"></label>
    <label>Nº de avaliações <input name="rating_count" inputmode="numeric" maxlength="9" value="<?= View::e($v('rating_count')) ?>"></label>
    <label>Comissão informada <input name="commission_rate" maxlength="20" value="<?= View::e($v('commission_rate')) ?>"></label>
    <label class="check span2"><input type="checkbox" name="variation_pending" value="1"<?= $v('variation_pending') === '1' ? ' checked' : '' ?>> Variação pendente (cor, voltagem, tamanho) — bloqueia publicação</label>
    <label class="span2">Nota sobre a variação <input name="variation_note" maxlength="300" value="<?= View::e($v('variation_note')) ?>"></label>
    <label class="span2">Observações internas <textarea name="notes" maxlength="1000" rows="3"><?= View::e($v('notes')) ?></textarea></label>
    <label class="check span2"><input type="checkbox" name="confirm_price" value="1"> Conferi agora na loja: preço, condição e disponibilidade estão corretos</label>
  </div>
  <datalist id="cats"><?php foreach ($pdo->query('SELECT name FROM gmax3_categories ORDER BY name')->fetchAll(PDO::FETCH_COLUMN) as $c): ?><option value="<?= View::e($c) ?>"><?php endforeach; ?></datalist>
  <button type="submit" class="btn btn-primary">Salvar</button>
  <p class="muted small">Alterar preço ou link retira a aprovação e exige nova conferência. Nenhum valor é preenchido automaticamente.</p>
</form>

<?php if ($r):
    $blockers = Rules::blockers($pdo, $r, 'telegram_privado');
    $dup = Rules::duplicate($pdo, $r);
    $legacy = Catalog::legacyStatus($pdo, $r);
    $hist = Db::all($pdo, 'SELECT * FROM gmax3_price_history WHERE catalog_id=? ORDER BY id DESC LIMIT 20', [$id]);
    $queue = Db::all($pdo, 'SELECT * FROM gmax3_pub_queue WHERE catalog_id=? ORDER BY id DESC', [$id]);
    $pot = Db::value($pdo, 'SELECT potential FROM gmax3_categories WHERE name=?', [$r['category'] ?? '']);
    $rank = Ranking::evaluate($r, $pot === null ? null : (int)$pot, array_map(static fn($h) => $h['price_promo_cents'], array_filter($hist, static fn($h) => (int)$h['verified'] === 1)));
?>
<aside>
  <section class="card">
    <h2>Estado</h2>
    <p><?= View::mp($r['marketplace']) ?> <?= View::badge($r['validation_status']) ?></p>
    <p class="small"><?= View::freshness($r, $staleHours) ?></p>
    <?php if ($legacy !== null): ?><p class="muted small">Registro original (<?= View::e($r['legacy_table']) ?>): <?= View::e($legacy) ?></p><?php endif; ?>
    <div class="actions">
      <?= View::actionButton('offer_confirm_price', ['id' => $id, 'back' => 'oferta'], 'Confirmar preço agora', 'btn') ?>
      <?php if ($r['validation_status'] !== 'aprovado'): ?>
        <?= View::actionButton('offer_status', ['id' => $id, 'status' => 'aprovado', 'back' => 'oferta'], 'Aprovar', 'btn btn-primary') ?>
      <?php else: ?>
        <?= View::actionButton('offer_status', ['id' => $id, 'status' => 'aguardando_validacao', 'back' => 'oferta'], 'Retirar aprovação', 'btn') ?>
      <?php endif; ?>
      <?= View::actionButton('offer_status', ['id' => $id, 'status' => 'rejeitado', 'back' => 'oferta'], 'Rejeitar', 'btn btn-ghost') ?>
      <?= View::actionButton('offer_status', ['id' => $id, 'status' => 'arquivado', 'back' => 'oferta'], 'Arquivar', 'btn btn-ghost', 'Arquivar este produto?') ?>
    </div>
  </section>
  <section class="card">
    <h2>Prévia da mensagem</h2>
    <pre class="msg" id="msg-<?= (int)$id ?>"><?= View::e(Message::render($r)) ?></pre>
    <button type="button" class="btn" data-copy="#msg-<?= (int)$id ?>">Copiar mensagem</button>
    <?php if ($blockers || $dup): ?>
      <div class="alert alert-aviso"><strong>Publicação bloqueada:</strong><ul><?php foreach ($blockers as $b): ?><li><?= View::e($b) ?></li><?php endforeach; ?><?= $dup ? '<li>' . View::e($dup) . '</li>' : '' ?></ul></div>
    <?php else: ?>
      <form method="post" action="painel.php" data-loading class="stack">
        <?= View::csrfField() ?><input type="hidden" name="action" value="queue_add"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="back" value="oferta">
        <label>Agendar para (opcional) <input type="datetime-local" name="when"></label>
        <button type="submit" class="btn btn-primary">Adicionar à fila do Telegram privado</button>
        <p class="muted small">Entrar na fila não envia nada: o envio real continua bloqueado pelas travas de autorização.</p>
      </form>
    <?php endif; ?>
  </section>
  <section class="card">
    <h2>Pontuação comercial <?= $rank['score'] === null ? '—' : (int)$rank['score'] ?><span class="muted small"> · confiança <?= (int)$rank['confidence'] ?>%</span></h2>
    <ul class="criteria"><?php foreach ($rank['components'] as $c): ?><li><span><?= View::e($c['criterio']) ?> (peso <?= (int)$c['peso'] ?>)</span><span><?= $c['valor'] === null ? '<em class="muted">sem dado</em>' : (int)$c['valor'] . '/100' ?></span><small class="muted"><?= View::e($c['explicacao']) ?></small></li><?php endforeach; ?></ul>
    <?php if ($rank['price_note']): ?><p class="small"><?= View::e($rank['price_note']) ?></p><?php endif; ?>
  </section>
  <section class="card">
    <h2>Histórico de preços</h2>
    <table class="table compact"><thead><tr><th>Registro</th><th>De</th><th>Por</th><th>Fonte</th></tr></thead><tbody>
    <?php foreach ($hist as $h): ?><tr><td><?= View::e(Time::show($h['recorded_at'])) ?></td><td><?= View::e(Money::format($h['price_original_cents'] === null ? null : (int)$h['price_original_cents'])) ?></td><td><?= View::e(Money::format($h['price_promo_cents'] === null ? null : (int)$h['price_promo_cents'])) ?></td><td><?= View::e($h['source']) ?><?= $h['verified'] ? ' ✓' : '' ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <p class="muted small">✓ = conferido manualmente. Registros de planilha/pesquisa não são tratados como preço real.</p>
  </section>
  <?php if ($queue): ?>
  <section class="card">
    <h2>Publicações deste produto</h2>
    <ul class="log"><?php foreach ($queue as $q): ?><li>#<?= (int)$q['id'] ?> <?= View::badge($q['state']) ?> <span class="muted small"><?= View::e(Time::show($q['scheduled_for'])) ?></span></li><?php endforeach; ?></ul>
  </section>
  <?php endif; ?>
</aside>
<?php endif; ?>
</div>

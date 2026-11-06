<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\{Catalog, Money, Pricing, Time, Url, View};
/** @var PDO $pdo @var int $staleHours */
$id = isset($_GET['id']) && is_string($_GET['id']) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$r = $id ? Catalog::get($pdo, $id) : null;
$fromQueue = ($_GET['fila'] ?? '') === '1';
$pos = isset($_GET['pos']) && is_string($_GET['pos']) && ctype_digit($_GET['pos']) ? (int)$_GET['pos'] : 0;
if ($fromQueue) {
    $backUrl = View::url('fila', Gmax\Funnel::filters(is_array($_SESSION['gmax3_fila_f'] ?? null) ? $_SESSION['gmax3_fila_f'] : [])) . ($id ? '#p' . $id : '');
} else {
    $backF = is_array($_SESSION['gmax3_ofertas_f'] ?? null) ? $_SESSION['gmax3_ofertas_f'] : [];
    $backUrl = View::url('ofertas', $backF) . ($id ? '#p' . $id : '');
}
?>
<a class="back-link" href="<?= View::e($backUrl) ?>">← <?= $fromQueue ? 'Voltar à fila de trabalho' : 'Voltar aos resultados' ?></a>
<?php if (!$r): ?>
  <div class="card empty"><strong>Produto não encontrado</strong>Escolha um produto em <a href="<?= View::e(View::url('ofertas')) ?>">Ofertas</a> e toque em “Conferir preço”.</div>
<?php return; endif;
$form = $_SESSION['gmax3_form'] ?? null;
unset($_SESSION['gmax3_form']);
$fv = static fn(string $k, string $default) => is_array($form) && isset($form[$k]) && is_string($form[$k]) ? $form[$k] : $default;
$src = Catalog::verificationSource($r);
$store = View::storeUrl($r);
$pay = $fv('payment_condition', (string)($r['payment_condition'] ?? ''));
$avail = $fv('availability', (string)($r['availability'] ?? 'desconhecida'));
?>
<div class="check-flow">
  <section class="card">
    <h2><?= View::e($r['title']) ?></h2>
    <p class="offer-meta"><?= View::mp($r['marketplace']) ?> <?= View::badge($r['validation_status']) ?> <span class="muted small">#<?= (int)$id ?> · <?= View::e($r['external_id'] ?? 'sem ID do anúncio') ?></span></p>
    <dl class="check-current">
      <div><dt>Por (atual)</dt><dd><?= View::e(Money::format($r['price_promo_cents'] === null ? null : (int)$r['price_promo_cents'])) ?></dd></div>
      <div><dt>De (atual)</dt><dd><?= View::e(Money::format($r['price_original_cents'] === null ? null : (int)$r['price_original_cents'])) ?> <?= View::originBadge(Pricing::originalSource($r)) ?></dd></div>
      <div><dt>Condição</dt><dd><?= View::e(Catalog::PAYMENT[$r['payment_condition'] ?? ''] ?? 'não identificada') ?></dd></div>
      <div><dt>Disponibilidade</dt><dd><?= View::e(Catalog::AVAILABILITY[$r['availability'] ?? 'desconhecida'] ?? '—') ?></dd></div>
    </dl>
    <p><?= View::freshness($r, $staleHours) ?></p>
    <p class="small muted"><?= $src === 'api' ? 'A última confirmação veio da <strong>API oficial</strong>.' : ($src === 'manual' ? 'A última confirmação foi uma <strong>conferência manual</strong> (não é validação pela API).' : 'Este preço ainda não foi conferido.') ?></p>
    <?php if ($r['variation_pending']): ?><div class="alert alert-aviso">⚠ Variação pendente<?= $r['variation_note'] ? ': ' . View::e($r['variation_note']) : '' ?>. Escolha a variação na loja e registre abaixo.</div><?php endif; ?>
    <h3>1. Abra o anúncio</h3>
    <?php if ($store): ?>
      <a class="btn btn-lg btn-block btn-store btn-store-<?= View::e($r['marketplace']) ?>" href="<?= View::e($store) ?>" target="_blank" rel="noopener noreferrer">
        <span aria-hidden="true"><?= $r['marketplace'] === 'shopee' ? '🛍️' : '🛒' ?></span> <?= $r['marketplace'] === 'shopee' ? 'Conferir na Shopee' : 'Conferir no Mercado Livre' ?><span class="sr-only"> (abre em nova aba)</span></a>
      <p class="muted small">Abre o anúncio oficial no aplicativo da loja (se instalado) ou no navegador. Abrir o anúncio <strong>não</strong> confirma o preço.</p>
    <?php else: ?>
      <div class="alert alert-aviso">Sem URL do anúncio individual. <a href="<?= View::e(View::url('oferta', ['id' => $id])) ?>">Cadastre a URL original</a> (ex.: <?= $r['marketplace'] === 'shopee' ? 'shopee.com.br/product/LOJA/ITEM' : 'produto.mercadolivre.com.br/MLB-…' ?>) para conferir direto na loja.</div>
    <?php endif; ?>
    <?php if (View::affiliateUrl($r)): ?>
      <p class="small"><a class="btn btn-affiliate btn-sm" href="<?= View::e((string)View::affiliateUrl($r)) ?>" target="_blank" rel="noopener noreferrer"><span aria-hidden="true">🔗</span> Abrir link afiliado<span class="sr-only"> (abre em nova aba)</span></a>
      <span class="muted">Link de divulgação: use só para testar o link (registra um clique).</span></p>
    <?php else: ?>
      <p class="small warn">Sem link afiliado oficial: o produto não pode ser publicado.</p>
    <?php endif; ?>
    <ol class="check-steps"><li>Confira preço “Por” e “De”, cupom ou Pix.</li><li>Confira a variação (cor, voltagem, tamanho) e o vendedor.</li><li>Confira a disponibilidade.</li><li>Volte à GMAX e registre abaixo.</li></ol>
  </section>

  <form method="post" action="painel.php" class="card stack" data-loading>
    <?= View::csrfField() ?><input type="hidden" name="action" value="offer_check"><input type="hidden" name="back" value="conferir"><input type="hidden" name="id" value="<?= (int)$id ?>">
    <input type="hidden" name="v" value="<?= View::e(Gmax\Funnel::version($r)) ?>">
    <?php if ($fromQueue): ?><input type="hidden" name="fila" value="1"><input type="hidden" name="pos" value="<?= $pos ?>"><?php endif; ?>
    <h2>2. Registre o que encontrou na loja</h2>
    <p class="muted small">Campos com * são obrigatórios.</p>
    <?= View::priceCalculator(['uid' => 'cf', 'row' => $r, 'estimate' => Pricing::latestEstimate($pdo, $r), 'mode' => $fv('price_mode', 'original'), 'pct' => $fv('desconto_pct', ''),
        'promo' => $fv('price_promo', Money::input($r['price_promo_cents'] === null ? null : (int)$r['price_promo_cents'])),
        'original' => $fv('price_original', Money::input($r['price_original_cents'] === null ? null : (int)$r['price_original_cents']))]) ?>
    <fieldset class="chips">
      <legend>Condição do preço</legend>
      <?php foreach (['normal' => 'Normal', 'pix' => 'Pix', 'cartao' => 'Cartão', 'cupom' => 'Cupom', 'outro' => 'Outra'] as $k => $l): ?>
        <label><input type="radio" name="payment_condition" value="<?= $k ?>"<?= $pay === $k ? ' checked' : '' ?>> <?= $l ?></label>
      <?php endforeach; ?>
    </fieldset>
    <label>Detalhe da condição (cupom, parcelas…) <input name="payment_note" maxlength="200" value="<?= View::e($fv('payment_note', (string)($r['payment_note'] ?? ''))) ?>" placeholder="ex.: cupom GMAX10"></label>
    <fieldset class="chips">
      <legend>Disponibilidade *</legend>
      <?php foreach (Catalog::AVAILABILITY as $k => $l): ?>
        <label><input type="radio" name="availability" value="<?= $k ?>"<?= $avail === $k ? ' checked' : '' ?> required> <?= View::e($l) ?></label>
      <?php endforeach; ?>
    </fieldset>
    <label>Variação conferida <input name="variation_note" maxlength="300" value="<?= View::e($fv('variation_note', (string)($r['variation_note'] ?? ''))) ?>" placeholder="ex.: Preta, 110 V, tamanho M"></label>
    <?php if ($r['variation_pending']): ?><label class="check"><input type="checkbox" name="variation_ok" value="1"> Variação definida (remove a pendência)</label><?php endif; ?>
    <label>Vendedor <input name="store_name" maxlength="120" value="<?= View::e($fv('store_name', (string)($r['store_name'] ?? ''))) ?>"></label>
    <label>Observações da conferência <textarea name="observacao" rows="2" maxlength="300" placeholder="ex.: frete grátis acima de R$ 79"><?= View::e($fv('observacao', '')) ?></textarea></label>
    <div class="confirm-box">
      <label class="check"><input type="checkbox" name="confirmo" value="1" required> <span>Conferi <strong>agora, na loja</strong>, o preço, a condição, a variação e a disponibilidade informados acima.</span></label>
      <p class="muted small">Isto é uma <strong>conferência manual</strong> e fica registrada assim (não é validação pela API). Alterar o preço retira a aprovação do produto.</p>
    </div>
    <div class="form-actions">
      <?php if ($fromQueue): ?>
        <button type="submit" name="fila_next" value="1" class="btn btn-primary btn-lg btn-block">Salvar e ir para o próximo</button>
        <button type="submit" class="btn btn-block">Salvar e voltar à fila</button>
      <?php else: ?>
        <button type="submit" class="btn btn-primary btn-lg btn-block">Salvar conferência</button>
      <?php endif; ?>
    </div>
    <p class="muted small">Última conferência: <?= $r['price_verified_at'] ? View::e(Time::show($r['price_verified_at'])) . ' · ' . ($src === 'api' ? 'API ' . View::e(Url::MARKETPLACES[$r['marketplace']] ?? '') : 'manual') : 'nunca' ?></p>
  </form>
</div>

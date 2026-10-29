<?php
declare(strict_types=1);
/* GMAX Mercado Livre — visualização somente leitura.
   Não envia ofertas e não modifica a fila Shopee. */
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_start();
if (empty($_SESSION['auth'])) {
    header('Location: index.php');
    exit;
}
require __DIR__ . '/app.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
$db = db();
function ml_escape($s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function ml_money($cents): string {
    return $cents === null ? '—' : 'R$ ' . number_format(((int)$cents)/100,2,',','.');
}
function ml_safe_link($url): bool {
    if (!is_string($url) || $url === '') return false;
    $p = parse_url($url);
    if (!is_array($p) || ($p['scheme'] ?? '') !== 'https'
        || isset($p['user']) || isset($p['pass'])) return false;
    return in_array(strtolower($p['host'] ?? ''), [
        'meli.la','mercadolivre.com.br','www.mercadolivre.com.br',
        'produto.mercadolivre.com.br'
    ], true);
}
$table=$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='gmax_ml_offers'")->fetchColumn();
$offers=[];
$counts=[];
if ($table) {
    $offers=$db->query('SELECT codigo_gmax,categoria,produto,url_original,url_afiliado,
      preco_original_centavos,preco_promocional_centavos,condicao_preco,
      verificado_em,observacoes,status,updated_at
      FROM gmax_ml_offers ORDER BY updated_at DESC,codigo_gmax ASC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
    $counts=$db->query('SELECT status, COUNT(*) AS total FROM gmax_ml_offers GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
}
$total=array_sum(array_map('intval',$counts));
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Mercado Livre — GMAX Ofertas</title>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}
body{margin:0;background:#08111f;color:#e8f0fb;font:15px system-ui,-apple-system,Segoe UI,sans-serif}
main{max-width:1120px;margin:auto;padding:30px 18px}
a{color:#70dcf5;overflow-wrap:anywhere}
h1{margin:10px 0;font-size:29px}h2{font-size:18px;margin:0 0 14px}
.muted{color:#adc0d4}.card{background:#132238;border:1px solid #28415e;border-radius:14px;padding:20px;margin:18px 0}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px}
.stat{background:#0c1a2d;border-radius:10px;padding:14px}.stat strong{display:block;font-size:25px}
.offer{border-top:1px solid #35506a;padding:17px 0;display:grid;grid-template-columns:1fr auto;gap:14px}
.title{font-weight:700;font-size:17px}.price{font-size:21px;font-weight:800;color:#75e5c1}
.old{text-decoration:line-through;color:#abbccf}.tag{display:inline-block;background:#223b52;padding:4px 9px;border-radius:18px;font-size:12px;margin:5px 4px 5px 0}
.warning{color:#ffda96}.link{display:inline-block;margin-top:12px;background:#25b1d0;color:#051a26;text-decoration:none;font-weight:700;padding:9px 13px;border-radius:9px}
.controls{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
input{background:#0a1729;color:white;border:1px solid #506783;border-radius:8px;padding:10px;width:min(400px,100%)}
.small{font-size:12px}.discount{font-size:17px;color:#9af0cf;font-weight:bold}
@media(max-width:650px){.offer{grid-template-columns:1fr}main{padding:17px 12px}}
</style>
</head>
<body><main>
<p><a href="index.php">← Voltar ao painel GMAX</a></p>
<h1>GMAX <span style="color:#73e1bd">Ofertas</span> · Mercado Livre</h1>
<p class="muted">Visualização integrada ao banco atual · Consulta somente leitura · Nenhum disparo automático</p>
<div class="stats">
  <div class="stat"><span class="muted">Total importado</span><strong><?= $total ?></strong></div>
  <div class="stat"><span class="muted">Aguardando validação</span><strong><?= (int)($counts['aguardando_validacao']??0) ?></strong></div>
  <div class="stat"><span class="muted">Outros estados</span><strong><?= max(0,$total-(int)($counts['aguardando_validacao']??0)) ?></strong></div>
</div>
<div class="card">
<p class="warning"><strong>Preços do CSV não são consultados em tempo real.</strong> Confira a oferta e as condições no Mercado Livre antes de divulgar. Links só devem ser publicados em canais permitidos pelo programa de afiliados.</p>
<p class="muted small">Esta tela não altera o status das ofertas e não ativa o Telegram.</p>
</div>
<section class="card">
<h2>Ofertas importadas</h2>
<div class="controls"><input id="filtro" type="search" placeholder="Buscar por produto, código ou categoria" aria-label="Filtrar ofertas"><span class="muted" id="contagem"></span></div>
<?php if (!$table): ?>
<p class="warning">A tabela gmax_ml_offers ainda não foi encontrada neste banco. Confirme o log de importação.</p>
<?php elseif (!$offers): ?>
<p class="muted">Nenhuma oferta Mercado Livre importada.</p>
<?php endif; ?>
<?php foreach($offers as $r):
  $orig=$r['preco_original_centavos']===null?null:(int)$r['preco_original_centavos'];
  $promo=$r['preco_promocional_centavos']===null?null:(int)$r['preco_promocional_centavos'];
  $discount=($orig!==null&&$promo!==null&&$orig>$promo&&$orig>0)
    ? (int)round(($orig-$promo)/$orig*100) : null;
  $link=ml_safe_link($r['url_afiliado']) ? $r['url_afiliado'] : null;
  $search=mb_strtolower((string)$r['produto'].' '.(string)$r['categoria'].' '.(string)$r['codigo_gmax'],'UTF-8');
?>
<article class="offer" data-search="<?=ml_escape($search)?>">
<div>
  <div class="title"><?=ml_escape($r['produto'])?></div>
  <div><span class="tag"><?=ml_escape($r['categoria'])?></span><span class="tag"><?=ml_escape($r['status'])?></span><span class="tag"><?=ml_escape($r['codigo_gmax'])?></span></div>
  <?php if($orig!==null): ?><p class="old">De: <?=ml_money($orig)?></p><?php endif;?>
  <p class="price">Por: <?=ml_money($promo)?></p>
  <?php if($discount!==null):?><p class="discount"><?= $discount ?>% OFF · Economia de <?=ml_money($orig-$promo)?></p><?php endif;?>
  <?php if(!empty($r['condicao_preco'])):?><p><strong>Condição:</strong> <?=ml_escape($r['condicao_preco'])?></p><?php endif;?>
  <p class="muted small">Preço conferido em: <?=ml_escape($r['verificado_em'] ?: 'data não informada')?> · Cadastro atualizado em: <?=ml_escape($r['updated_at'])?></p>
  <?php if(!empty($r['observacoes'])):?><p class="muted small"><?=ml_escape($r['observacoes'])?></p><?php endif;?>
  <?php if($link):?><a class="link" href="<?=ml_escape($link)?>" target="_blank" rel="noopener noreferrer nofollow">Abrir link afiliado</a><?php else:?><p class="warning">Link afiliado ausente ou inválido</p><?php endif;?>
</div>
</article>
<?php endforeach;?>
</section>
</main>
<script>
const q=document.getElementById('filtro'), items=[...document.querySelectorAll('.offer')], count=document.getElementById('contagem');
function apply(){const v=q.value.trim().toLocaleLowerCase('pt-BR');let n=0;items.forEach(el=>{const show=el.dataset.search.includes(v);el.hidden=!show;if(show)n++});count.textContent=n+' exibida(s)'}
q.addEventListener('input',apply);apply();
</script>
</body></html>

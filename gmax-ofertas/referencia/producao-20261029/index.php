<?php
declare(strict_types=1);
require __DIR__.'/app.php';
ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');if(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')ini_set('session.cookie_secure','1');session_start();
try{$db=db();}catch(Throwable $e){http_response_code(500);exit('Este ambiente precisa ter a extensão PHP pdo_sqlite ativada.');}
$exists=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()>0;
if(!isset($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(20));
$notice='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!hash_equals($_SESSION['csrf'],$_POST['csrf']??'')){$error='Sessão inválida';}
 elseif(!$exists&&($_POST['action']??'')==='setup'){ $p=(string)($_POST['password']??'');if(strlen($p)<14){$error='Escolha senha de no mínimo 14 caracteres';}else{$db->prepare('INSERT INTO users(id,password_hash) VALUES(1,?)')->execute([password_hash($p,PASSWORD_DEFAULT)]);$exists=true;$_SESSION['auth']=true;session_regenerate_id(true);$notice='Painel protegido e configurado';}}
 elseif($exists&&($_POST['action']??'')==='login'){$hash=$db->query('SELECT password_hash FROM users WHERE id=1')->fetchColumn();if(password_verify((string)($_POST['password']??''),$hash)){$_SESSION['auth']=true;session_regenerate_id(true);}else{$error='Senha incorreta';}}
 elseif(($_POST['action']??'')==='logout'){$_SESSION=[];session_destroy();header('Location: index.php');exit;}
 elseif(!empty($_SESSION['auth'])&&($_POST['action']??'')==='import'){if(!isset($_FILES['csv'])||($_FILES['csv']['error']??1)!==UPLOAD_ERR_OK){$error='Selecione CSV válido';}elseif($_FILES['csv']['size']>5*1024*1024){$error='CSV acima de 5 MB';}else{try{[$ok,$bad]=importFile($db,$_FILES['csv']['tmp_name']);$notice="$ok ofertas importadas; $bad rejeitadas; duplicadas ignoradas";}catch(Throwable $e){$error=$e->getMessage();}}}
}
$auth=!empty($_SESSION['auth']);
$rows=$auth?$db->query('SELECT * FROM offers ORDER BY created_at DESC,item_id DESC LIMIT 150')->fetchAll(PDO::FETCH_ASSOC):[];
$stats=$auth?$db->query('SELECT status,COUNT(*) qty FROM offers GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR):[];
?>
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GMAX Ofertas — Automação</title><style>body{font-family:system-ui,sans-serif;background:#08111f;color:#e8f0fb;margin:0}main{max-width:1060px;margin:36px auto;padding:16px}h1{font-size:28px}h2{font-size:19px}.card{background:#132238;border:1px solid #28415e;padding:20px;border-radius:14px;margin:17px 0}.muted{color:#a3b8d0}.green{color:#73e1bd}.amber{color:#ffd58c}.btn,button{border:0;background:#1daaca;color:#05121c;padding:11px 18px;border-radius:9px;font-weight:700;cursor:pointer}input[type=password],input[type=file]{display:block;margin:12px 0;background:#0b1627;color:#fff;border:1px solid #435b71;padding:11px;max-width:100%}.offer{border-top:1px solid #345;padding:16px 0}.offer strong{display:block;margin-bottom:6px}a{color:#69d6f6;overflow-wrap:anywhere}.msg{white-space:pre-wrap;background:#0a1929;padding:12px;border-radius:8px;font-size:13px}.err{color:#ff9393}small{color:#aec2d8}</style></head><body><main><h1>GMAX <span class="green">Ofertas</span> · Automação</h1><p class="muted">Painel independente de homologação • America/Sao_Paulo</p>
<?php if($error):?><div class="card err"><?=h($error)?></div><?php endif;if($notice):?><div class="card green"><?=h($notice)?></div><?php endif;?>
<?php if(!$auth):?><section class="card"><h2><?=$exists?'Entrar no painel':'Configuração inicial do painel'?></h2><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="<?=$exists?'login':'setup'?>"><label>Senha de administrador <?=!$exists?'(mínimo de 14 caracteres)':''?><input type="password" name="password" required minlength="<?=$exists?1:14?>" autocomplete="new-password"></label><button><?=$exists?'Entrar':'Criar senha e iniciar'?></button></form></section>
<?php else:?><div class="card"><form method="post" style="float:right"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="logout"><button>Sair</button></form><h2>Visão geral</h2><p>Na fila: <b><?=intval($stats['pendente']??0)?></b> · Enviados: <b><?=intval($stats['enviado']??0)?></b></p><p class="amber"><b>Envios públicos DESATIVADOS.</b> Nenhuma mensagem será disparada ao importar o CSV.</p><p>Horários planejados: <b>08h, 10h, 12h, 15h, 18h e 20h</b>. Agendador depende de cron autorizado no ambiente de instalação.</p></div><section class="card"><h2>Importar links já convertidos pela Shopee</h2><p class="muted">Baixe o CSV em “Obter Link em Massa” da Central de Afiliados. Links comuns não são convertidos automaticamente nesta versão.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="import"><input type="file" name="csv" accept=".csv,text/csv" required><button>Importar e preparar mensagens</button></form></section><section class="card"><h2>Fila de publicações</h2><?php if(!$rows):?><p>Nenhuma oferta importada ainda.</p><?php endif;foreach($rows as $r):?><article class="offer"><strong><?=h($r['title'])?></strong><small><?=h($r['shop'])?> · <?=h($r['status'])?> · Comissão informada: <?=h($r['commission_rate'])?> (<?=h($r['commission'])?>)</small><p>Preço: <b>R$ <?=h($r['price'])?></b></p><p><a rel="noopener noreferrer" target="_blank" href="<?=h($r['affiliate_url'])?>"><?=h($r['affiliate_url'])?></a></p><div class="msg"><?=h(message($r))?></div><button type="button" class="gmax-copy" data-message="<?=h(base64_encode(message($r)))?>">Copiar mensagem</button></article><?php endforeach;?></section><?php endif;?></main><script>
document.addEventListener('click', async function(event) {
  const button = event.target.closest('button.gmax-copy');
  if (!button) return;
  let message;
  try {
    const binary = atob(button.dataset.message);
    const bytes = Uint8Array.from(binary, ch => ch.charCodeAt(0));
    message = new TextDecoder('utf-8').decode(bytes);
  } catch (e) {
    alert('Não foi possível preparar a mensagem.');
    return;
  }
  try {
    if (!navigator.clipboard || !window.isSecureContext) throw new Error('clipboard indisponível');
    await navigator.clipboard.writeText(message);
  } catch (e) {
    const field = document.createElement('textarea');
    field.value = message;
    field.setAttribute('readonly', '');
    field.style.position = 'fixed';
    field.style.left = '-9999px';
    document.body.appendChild(field);
    field.select();
    const ok = document.execCommand('copy');
    field.remove();
    if (!ok) { alert('Não foi possível copiar. Selecione o texto manualmente.'); return; }
  }
  const label = button.textContent;
  button.textContent = 'Copiado!';
  window.setTimeout(() => { button.textContent = label; }, 1600);
});
</script></body></html>

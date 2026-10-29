<?php
declare(strict_types=1);
/* GMAX Mercado Livre: importacao local CLI, sem publicar mensagens. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Apenas CLI'); }
require __DIR__ . '/app.php';
const ARQ_CSV = '/home1/gust1922/gmax-secrets/mercadolivre-lote.csv';
function say(string $t): void { echo $t . PHP_EOL; }
function moneyOrNull(string $v): ?int {
    $v=trim(str_replace(['R$', ' '], '', $v));
    if ($v==='') return null;
    if (str_contains($v, ',')) { $v=str_replace('.', '', $v); $v=str_replace(',', '.', $v); }
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $v)) return null;
    return (int)round(((float)$v)*100);
}
function safeUrl(string $raw): bool {
    $u=parse_url(trim($raw));
    if (!$u || ($u['scheme'] ?? '') !== 'https') return false;
    $host=strtolower($u['host'] ?? '');
    return in_array($host,['meli.la','mercadolivre.com.br','www.mercadolivre.com.br','produto.mercadolivre.com.br'],true);
}
if (!is_file(ARQ_CSV) || !is_readable(ARQ_CSV)) { say('ERRO: CSV privado ausente'); exit(1); }
$f=fopen(ARQ_CSV,'rb'); if (!$f) exit(1);
$header=fgetcsv($f,0,';', '"', '\\');
if (!$header) { say('ERRO: CSV vazio');exit(1); }
$header[0]=preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
$expected=['codigo_gmax','categoria','produto','url_original','url_afiliado','preco_original','preco_promocional','condicao_preco','verificado_em','observacoes'];
if ($header!==$expected) { say('ERRO: cabecalho diferente do modelo; exporte apenas a aba Ofertas ML em CSV com ;'); exit(1); }
$pdo=db();
$pdo->exec('CREATE TABLE IF NOT EXISTS gmax_ml_offers (codigo_gmax TEXT PRIMARY KEY,categoria TEXT NOT NULL,produto TEXT NOT NULL,url_original TEXT,url_afiliado TEXT NOT NULL,preco_original_centavos INTEGER,preco_promocional_centavos INTEGER,condicao_preco TEXT,verificado_em TEXT,observacoes TEXT,status TEXT NOT NULL DEFAULT "aguardando_validacao",updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$sql='INSERT INTO gmax_ml_offers (codigo_gmax,categoria,produto,url_original,url_afiliado,preco_original_centavos,preco_promocional_centavos,condicao_preco,verificado_em,observacoes,status) VALUES(?,?,?,?,?,?,?,?,?,?,"aguardando_validacao") ON CONFLICT(codigo_gmax) DO UPDATE SET categoria=excluded.categoria,produto=excluded.produto,url_original=excluded.url_original,url_afiliado=excluded.url_afiliado,preco_original_centavos=excluded.preco_original_centavos,preco_promocional_centavos=excluded.preco_promocional_centavos,condicao_preco=excluded.condicao_preco,verificado_em=excluded.verificado_em,observacoes=excluded.observacoes,status="aguardando_validacao",updated_at=CURRENT_TIMESTAMP';
$stmt=$pdo->prepare($sql);
$rows=[];$seen=[];$bad=[];$line=1;
while (($r=fgetcsv($f,0,';', '"', '\\'))!==false) {
    $line++;
    if (count($r)===1 && trim($r[0])==='') continue;
    if(count($r)!==10) {$bad[]="Linha $line: colunas incorretas";continue;}
    $r=array_map('trim',$r);
    if(implode('',$r)==='') continue;
    [$id,$category,$title,$orig,$affiliate,$original,$promo,$terms,$checked,$notes]=$r;
    $p=moneyOrNull($promo);$o=moneyOrNull($original);
    if (!preg_match('/^ML-[A-Za-z0-9_-]{3,40}$/D',$id)||isset($seen[$id])||$title===''||strlen($title)>400||!safeUrl($affiliate)||($orig!==''&&!safeUrl($orig))||$p===null||$p<=0||($original!==''&&$o===null)||($o!==null&&$o<$p)||($checked!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$checked))) {$bad[]="Linha $line: dados invalidos ou codigo repetido";continue;}
    $seen[$id]=true;
    $rows[]=[$id,$category?:'Outros',$title,$orig,$affiliate,$o,$p,$terms,$checked,$notes];
}
fclose($f);
if ($bad) { foreach($bad as $e) say('ERRO: '.$e);say('Importacao cancelada; nenhuma oferta alterada');exit(1); }
if (!$rows) { say('Nenhuma oferta completa para importar');exit(0); }
try { $pdo->beginTransaction();foreach($rows as $r) $stmt->execute($r);$pdo->commit();say('SUCESSO: '.count($rows).' ofertas ML cadastradas/atualizadas em tabela separada');say('STATUS: aguardando_validacao; NENHUMA MENSAGEM ENVIADA'); }
catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack();say('ERRO: '.get_class($e).' - '.$e->getMessage());exit(1); }

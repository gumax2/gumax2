<?php
declare(strict_types=1);
/** GMAX Ofertas: agendador seguro para grupo de homologacao (CLI somente).
 * Revisao 2.2 (pacote 3.0): versao instalada + travas do V2.1 restauradas + convivencia com a fila 3.0.
 * Mensagem identica a versao instalada. Sem arquivo de ativacao, continua apenas simulando. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Apenas CLI'); }
require __DIR__ . '/app.php';

const GMAX_DESTINO_PRIVADO = '-1004443626419';
const GMAX_SECRETS = '/home1/gust1922/gmax-secrets';
const GMAX_HORARIOS = ['08:00', '10:00', '12:00', '15:00', '18:00', '20:00'];

function gmax_log(string $event, array $extra = []): void {
    $record = array_merge(['at'=>(new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format(DATE_ATOM),'event'=>$event], $extra);
    $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($line !== false) echo $line . PHP_EOL;
}
function gmax_fail(string $reason): never { gmax_log('bloqueado', ['motivo'=>$reason]); exit(1); }

$now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
$minute = $now->format('H:i');
if (!in_array($minute, GMAX_HORARIOS, true)) { gmax_log('fora_do_horario', ['hora'=>$minute]); exit(0); }
$slot = $now->format('Y-m-d H:i');

// Gatilho duplo: o arquivo de permissao NAO acompanha este pacote.
// Envio desativado por padrao, inclusive se o cron executar automaticamente.
$enablePath = GMAX_SECRETS . '/gmax-publicacao-privada.php';
$enabled = false;
if (is_readable($enablePath)) {
    $activation = require $enablePath;
    $enabled = is_array($activation)
        && ($activation['enabled'] ?? false) === true
        && (string)($activation['chat_id'] ?? '') === GMAX_DESTINO_PRIVADO
        && (string)($activation['authorization'] ?? '') === 'AUTORIZO_AGENDAMENTO_PRIVADO';
}
if (!$enabled) {
    $pdo = db();
    $offer = $pdo->query('SELECT item_id,title,price,affiliate_url FROM offers WHERE status="pendente" ORDER BY created_at,item_id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    gmax_log('simulacao_sem_envio', ['slot'=>$slot,'item_id'=>$offer['item_id'] ?? null,'fila_vazia'=>!$offer]);
    exit(0);
}

$credentialsFile = GMAX_SECRETS . '/telegram.php';
if (!is_readable($credentialsFile)) gmax_fail('credenciais_indisponiveis');
$credentials = require $credentialsFile;
if (!is_array($credentials) || (string)($credentials['chat_id'] ?? '') !== GMAX_DESTINO_PRIVADO) gmax_fail('destino_incorreto');
$token = (string)($credentials['token'] ?? '');
if (!preg_match('/^\d+:[A-Za-z0-9_-]+$/D', $token)) gmax_fail('token_invalido');
if (is_file(GMAX_SECRETS . '/gmax-publicacao.pause')) gmax_fail('pausa_ativa');

// Mutex do proprio servidor para impedir dois workers concorrentes.
$mutex = fopen(GMAX_SECRETS . '/gmax-publicador.mutex', 'c');
if ($mutex === false || !flock($mutex, LOCK_EX | LOCK_NB)) gmax_fail('worker_ja_em_execucao');
$pdo = db();
$pdo->exec('CREATE TABLE IF NOT EXISTS gmax_dispatches (slot TEXT PRIMARY KEY, item_id TEXT NOT NULL, status TEXT NOT NULL, http_code INTEGER, message_id INTEGER, response_note TEXT, started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, finished_at TEXT)');
// [V2.1] Qualquer tentativa nao conciliada paralisa TODOS os horarios futuros.
// Um envio confirmado cujo item continua pendente tambem paralisa a fila.
$issues = $pdo->query("SELECT slot, item_id, status FROM gmax_dispatches
    WHERE status IN ('attempted', 'uncertain')
       OR (status = 'confirmed' AND EXISTS (
           SELECT 1 FROM offers WHERE offers.item_id = gmax_dispatches.item_id AND offers.status = 'pendente'
       )) LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($issues) {
    gmax_log('pausa_global_reconciliacao', ['slot_pendente'=>$issues['slot'],'item_id'=>$issues['item_id'],'estado'=>$issues['status']]);
    exit(2);
}
// [3.0] Painel 3.0 instalado: respeita a pausa do painel e as pendencias da fila 3.0.
$has3 = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='gmax3_pub_queue'")->fetchColumn();
if ($has3) {
    if ($pdo->query("SELECT value FROM gmax3_settings WHERE key='publicacao_pausada'")->fetchColumn() !== '0') gmax_fail('pausa_painel_3_0');
    if ($pdo->query("SELECT 1 FROM gmax3_pub_queue WHERE state IN ('reservado','incerto') LIMIT 1")->fetchColumn()) {
        gmax_log('pausa_global_reconciliacao_3_0');
        exit(2);
    }
}
$pdo->beginTransaction();
try {
    $prior = $pdo->prepare('SELECT status FROM gmax_dispatches WHERE slot = ?');
    $prior->execute([$slot]);
    if ($prior->fetchColumn() !== false) { $pdo->commit(); gmax_log('janela_ja_reservada', ['slot'=>$slot]); exit(0); }
    // [3.0] Pula (sem travar a fila) ofertas que a fila 3.0 ja agendou, reservou, publicou ou deixou
    // incerta. Correspondencia por ID do anuncio, codigo OU link afiliado normalizado.
    $offer = false;
    $q3 = $has3 ? $pdo->prepare("SELECT q.state FROM gmax3_pub_queue q JOIN gmax3_catalog c ON c.id=q.catalog_id
        WHERE c.marketplace='shopee' AND (c.external_id=? OR c.codigo=? OR c.affiliate_norm=?)
          AND q.state IN ('agendado','reservado','confirmado','incerto') LIMIT 1") : null;
    foreach ($pdo->query('SELECT item_id,title,price,affiliate_url,shop,status FROM offers WHERE status="pendente" ORDER BY created_at,item_id')->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
        if ($q3) {
            $u = parse_url((string)$candidate['affiliate_url']);
            $norm = is_array($u) ? strtolower($u['host'] ?? '') . rtrim($u['path'] ?? '', '/') : '';
            $q3->execute([(string)$candidate['item_id'], (string)$candidate['item_id'], $norm]);
            if ($q3->fetchColumn() !== false) { gmax_log('item_tratado_pela_fila_3_0_ignorado', ['item_id'=>$candidate['item_id']]); continue; }
        }
        $offer = $candidate;
        break;
    }
    if (!$offer) { $pdo->commit(); gmax_log('fila_vazia', ['slot'=>$slot]); exit(0); }
    if (!valid((string)$offer['affiliate_url'],'s.shopee.com.br')) { $pdo->commit(); gmax_fail('link_fora_do_dominio_autorizado'); }
    // [V2.1] Nunca reenviar um item que ja tenha sido reservado por outra janela.
    $itemCheck = $pdo->prepare('SELECT slot, status FROM gmax_dispatches WHERE item_id = ? LIMIT 1');
    $itemCheck->execute([(string)$offer['item_id']]);
    if ($itemCheck->fetch(PDO::FETCH_ASSOC)) { $pdo->commit(); gmax_fail('item_ja_processado_ou_reservado_revisao_manual'); }
    $insert = $pdo->prepare('INSERT INTO gmax_dispatches(slot,item_id,status,response_note) VALUES(?, ?, ?, ?)');
    $insert->execute([$slot, (string)$offer['item_id'], 'attempted', 'Estado conservador: reconciliar antes de repetir']);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    gmax_fail('falha_ao_reservar_janela_' . get_class($error));
}

// Nenhum processamento automatico reenvia uma janela "attempted".
$msg = "🛍️ GMAX OFERTAS | SHOPEE\n\n"
     . trim((string)$offer['title'])
     . "\n\n"
     . "🔎 Confira o preço atualizado, as variações "
     . "e a disponibilidade diretamente na Shopee.\n\n"
     . "🛒 Acesse a oferta:\n"
     . (string)$offer['affiliate_url']
     . "\n\n"
     . "🔗 Link de afiliado GMAX."
     . "\nO preço final é o apresentado na loja.";
$payload = http_build_query(['chat_id'=>GMAX_DESTINO_PRIVADO, 'text'=>$msg, 'disable_web_page_preview'=>'true']);
$ctx = stream_context_create(['http'=>[
    'method'=>'POST', 'header'=>"Content-Type: application/x-www-form-urlencoded\r\n",
    'content'=>$payload, 'timeout'=>20, 'ignore_errors'=>true,
]]);
$raw = @file_get_contents('https://api.telegram.org/bot' . $token . '/sendMessage',false,$ctx);
$headers = $http_response_header ?? [];
$http = 0;
if (isset($headers[0]) && preg_match('~HTTP/\S+\s+(\d{3})~', $headers[0], $m)) $http = (int)$m[1];
$response = is_string($raw) ? json_decode($raw,true) : null;
$sentId = (int)($response['result']['message_id'] ?? 0);
$sentChat = (string)($response['result']['chat']['id'] ?? '');
$confirmed = $http === 200 && is_array($response) && ($response['ok'] ?? false) === true && $sentChat === GMAX_DESTINO_PRIVADO && $sentId > 0;
// Nao registrar token, URL da API nem resposta integral do Telegram.
$note = is_array($response) ? substr((string)($response['description'] ?? ''), 0, 180) : 'Sem resposta JSON confirmada';
try {
    $pdo->beginTransaction();
    $upd = $pdo->prepare('UPDATE gmax_dispatches SET status=?,http_code=?,message_id=?,response_note=?,finished_at=CURRENT_TIMESTAMP WHERE slot=?');
    $upd->execute([$confirmed?'confirmed':'uncertain',$http,$sentId?:null,$note,$slot]);
    if ($confirmed) {
        $mark = $pdo->prepare('UPDATE offers SET status="enviado",sent_at=CURRENT_TIMESTAMP WHERE item_id=? AND status="pendente"');
        $mark->execute([$offer['item_id']]);
        if ($mark->rowCount() !== 1) throw new RuntimeException('Oferta nao atualizada apos confirmacao');
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    gmax_log('reconciliacao_manual_necessaria', ['slot'=>$slot,'http_code'=>$http,'message_id'=>$sentId?:null]);
    exit(2);
}
gmax_log($confirmed?'publicacao_confirmada':'envio_incerto_nao_repetir', ['slot'=>$slot,'item_id'=>$offer['item_id'],'http_code'=>$http,'message_id'=>$sentId?:null,'telegram_erro'=>$note]);

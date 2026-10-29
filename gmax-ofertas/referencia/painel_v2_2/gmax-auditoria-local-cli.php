<?php
declare(strict_types=1);
// Apenas CLI. Nenhuma chamada HTTP e nenhuma escrita no banco.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Apenas CLI'); }
require __DIR__ . '/app.php';
const GMAX_SECRETS_DIR = '/home1/gust1922/gmax-secrets';
const GMAX_CHAT_ID_AUDIT = '-1004443626419';
function out(string $key, string $value): void { echo $key . ': ' . $value . PHP_EOL; }
out('MODO', 'AUDITORIA LOCAL SEM ENVIO');
out('PHP', PHP_VERSION);
out('Publicador', is_file(__DIR__ . '/gmax-publicador-cli.php') ? 'ENCONTRADO' : 'AUSENTE');
$flag = GMAX_SECRETS_DIR . '/gmax-publicacao-privada.php';
out('Arquivo de ativacao', file_exists($flag) ? 'EXISTE - REVISAR ANTES DE CONTINUAR' : 'AUSENTE (BLOQUEADO)');
out('Pausa manual', is_file(GMAX_SECRETS_DIR . '/gmax-publicacao.pause') ? 'ATIVA' : 'AUSENTE');
$cf = GMAX_SECRETS_DIR . '/telegram.php';
if (!is_readable($cf)) { out('Credenciais', 'INDISPONIVEIS'); exit(1); }
$cfg = require $cf;
out('Destino configurado', (is_array($cfg) && (string)($cfg['chat_id'] ?? '') === GMAX_CHAT_ID_AUDIT) ? 'CORRETO' : 'DIVERGENTE');
out('Token', is_array($cfg) && !empty($cfg['token']) ? 'PRESENTE (OMITIDO)' : 'AUSENTE');
try {
    $db = db();
    $db->exec('PRAGMA query_only = ON');
    $counts = $db->query('SELECT status, COUNT(*) qtd FROM offers GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($counts as $status => $count) out('Ofertas ' . $status, (string)$count);
    $first = $db->query('SELECT item_id, affiliate_url FROM offers WHERE status="pendente" ORDER BY created_at,item_id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    out('Primeiro pendente', $first ? (string)$first['item_id'] : 'NENHUM');
    if ($first) out('Link do primeiro', valid((string)$first['affiliate_url'], 's.shopee.com.br') ? 'DOMINIO VALIDO, DESTINO NAO VERIFICADO' : 'INVALIDO');
    $exists = $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='gmax_dispatches'")->fetchColumn();
    if ($exists) {
        $stmt = $db->query('SELECT status,COUNT(*) FROM gmax_dispatches GROUP BY status');
        $found = false;
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $status => $count) {
            out('Despachos ' . $status, (string)$count);
            $found = true;
        }
        if (!$found) out('Despachos', 'TABELA VAZIA');
        $bad = $db->query("SELECT COUNT(*) FROM gmax_dispatches d WHERE status IN ('attempted','uncertain') OR (status='confirmed' AND EXISTS (SELECT 1 FROM offers o WHERE o.item_id=d.item_id AND o.status='pendente'))")->fetchColumn();
        out('Pendencias de conciliacao', (string)$bad);
    } else out('Despachos', 'TABELA AINDA NAO CRIADA');
} catch (Throwable $e) { out('Falha local', get_class($e) . ': ' . $e->getMessage()); exit(2); }
out('CONFIRMACAO', 'Nenhuma requisicao ao Telegram; nenhuma alteracao em ofertas ou despachos.');

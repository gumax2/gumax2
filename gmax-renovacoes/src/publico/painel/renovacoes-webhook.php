<?php
/**
 * GMAX Renovações — webhook da WhatsApp Cloud API (Meta).
 * Público por necessidade (a Meta precisa alcançar), mas:
 *  - GET só responde ao desafio com o verify_token correto;
 *  - POST só é processado com assinatura X-Hub-Signature-256 válida (App Secret).
 * Não exige sessão do painel nem expõe dados.
 */
declare(strict_types=1);

$grDir = is_file(__DIR__ . '/renovacoes-caminho.php') ? (require __DIR__ . '/renovacoes-caminho.php') : dirname(__DIR__, 2) . '/gmax-renovacoes';
require $grDir . '/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

try {
    $app = gr_app();
    $hook = new \GmaxRenov\Webhook($app);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        [$code, $body] = $hook->verify($_GET);
    } elseif ($method === 'POST') {
        $raw = (string)file_get_contents('php://input', false, null, 0, 1024 * 1024 + 1);
        [$code, $body] = $hook->handlePost($raw, $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null);
    } else {
        [$code, $body] = [405, 'method not allowed'];
    }
    http_response_code($code);
    echo $body;
} catch (\Throwable $e) {
    if (isset($app)) {
        $app->log()->error('Erro no webhook', ['erro' => $e->getMessage()]);
    }
    // 500 faz a Meta reenviar depois; o processamento é idempotente (chave_dedup).
    http_response_code(500);
    echo 'error';
}

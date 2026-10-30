<?php
/**
 * GMAX Renovações — API JSON da aba Renovações. Exige login do painel; ações que alteram dados exigem POST + CSRF.
 */
declare(strict_types=1);

$grDir = is_file(__DIR__ . '/renovacoes-caminho.php') ? (require __DIR__ . '/renovacoes-caminho.php') : dirname(__DIR__, 2) . '/gmax-renovacoes';
require $grDir . '/bootstrap.php';

use GmaxRenov\Api;
use GmaxRenov\Auth;

header('Content-Type: application/json; charset=utf-8');
Auth::securityHeaders();

function gr_json(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $app = gr_app();
    $auth = new Auth($app->config['auth'] ?? []);
    $auth->start();
    if (!$auth->loggedIn()) {
        gr_json(401, ['erro' => 'Sessão expirada. Entre no painel novamente.']);
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = (string)($_GET['acao'] ?? '');
    $in = $_GET;
    if (in_array($action, Api::MUTATIONS, true)) {
        if ($method !== 'POST') {
            gr_json(405, ['erro' => 'Método não permitido.']);
        }
        if (!Auth::sameOrigin() || !$auth->checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            gr_json(403, ['erro' => 'Falha de verificação de segurança (CSRF). Recarregue a página.']);
        }
        $raw = file_get_contents('php://input', false, null, 0, 256 * 1024);
        $in = json_decode((string)$raw, true);
        if (!is_array($in)) {
            gr_json(400, ['erro' => 'Requisição inválida.']);
        }
    } elseif ($action === 'previa' && $method === 'POST') {
        if (!Auth::sameOrigin() || !$auth->checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            gr_json(403, ['erro' => 'Falha de verificação de segurança (CSRF).']);
        }
        $in = json_decode((string)file_get_contents('php://input', false, null, 0, 256 * 1024), true) ?: [];
    }
    session_write_close(); // não segura a sessão do painel durante o processamento
    gr_json(200, (new Api($app))->handle($action, $in));
} catch (\InvalidArgumentException $e) {
    gr_json(422, ['erro' => $e->getMessage()]);
} catch (\Throwable $e) {
    if (isset($app)) {
        $app->log()->error('Erro na API', ['acao' => $action ?? '', 'erro' => $e->getMessage()]);
    }
    gr_json(500, ['erro' => 'Erro interno. Detalhes no log do módulo.']);
}

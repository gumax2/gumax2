<?php
declare(strict_types=1);
/*
 * GMAX Ofertas — retorno (callback) da autorização OAuth do Mercado Livre.
 * Endereço a cadastrar no Mercado Livre Developers (Redirect URI), por exemplo:
 *   https://gmaxcanais.com.br/gmax-auto/ml-oauth-callback.php
 * Só aceita o retorno se o administrador estiver logado na MESMA sessão que iniciou a
 * conexão (state + PKCE guardados na sessão). Não exibe nem registra tokens.
 */
require __DIR__ . '/gmax-lib/bootstrap.php';

use Gmax\{Audit, Auth, Db, View};
use Gmax\OAuth\MercadoLivreOAuth;

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

Auth::start();
if (!Auth::check()) {
    http_response_code(403);
    exit('Entre no painel (painel.php) e inicie a conexão por lá.');
}
$query = array_map(static fn($v) => is_string($v) ? mb_substr($v, 0, 500) : '', $_GET);
try {
    $oauth = new MercadoLivreOAuth();
    $r = $oauth->complete($_SESSION, $query);
    session_regenerate_id(true);
    Audit::log(Db::get(), Auth::actor(), 'ml_oauth_conectado', 'integracao', 'mercadolivre', [
        'conta_final' => $r['user_id'] ? substr($r['user_id'], -4) : null, 'expira' => date('c', $r['expires_at']), 'escopo' => $r['scope'],
    ]);
    View::flash('ok', 'Mercado Livre conectado. O token é renovado automaticamente e fica cifrado na pasta privada.');
} catch (Throwable $e) {
    try {
        Audit::log(Db::get(), Auth::actor(), 'ml_oauth_falhou', 'integracao', 'mercadolivre', ['motivo' => mb_substr($e->getMessage(), 0, 160)]);
    } catch (Throwable) {
    }
    View::flash('erro', 'Conexão com o Mercado Livre não concluída: ' . $e->getMessage());
}
header('Location: painel.php?p=integracoes', true, 303);

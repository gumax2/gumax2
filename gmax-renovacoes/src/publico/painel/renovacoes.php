<?php
/**
 * GMAX Renovações — aba "Renovações" do painel.
 * Usa o mesmo login do painel. Não altera clientes, pacotes, recebimentos nem custos.
 */
declare(strict_types=1);

$grDir = is_file(__DIR__ . '/renovacoes-caminho.php') ? (require __DIR__ . '/renovacoes-caminho.php') : dirname(__DIR__, 2) . '/gmax-renovacoes';
require $grDir . '/bootstrap.php';

use GmaxRenov\Auth;

Auth::securityHeaders();
$app = gr_app();
$auth = new Auth($app->config['auth'] ?? []);
$auth->start();
if (!$auth->loggedIn()) {
    header('Location: ' . $auth->loginUrl());
    exit;
}
$csrf = $auth->csrfToken();
$v = rawurlencode(GR_VERSION);
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
<title>Renovações — GMAX Central de Gestão</title>
<link rel="stylesheet" href="renovacoes-assets/renovacoes.css?v=<?= $v ?>">
</head>
<body>
<header class="gr-top">
  <a class="gr-back" href="./">← Painel</a>
  <h1>Renovações</h1>
  <span id="gr-clock" class="gr-muted"></span>
</header>

<div id="gr-banners"></div>

<nav class="gr-tabs" role="tablist">
  <button role="tab" data-tab="visao" class="active">Vencimentos</button>
  <button role="tab" data-tab="historico">Histórico</button>
  <button role="tab" data-tab="clientes">Autorizações</button>
  <button role="tab" data-tab="config">Configurações</button>
</nav>

<main>
  <section id="tab-visao" class="gr-tab active">
    <div class="gr-cards" id="gr-resumo"></div>
    <div class="gr-actions">
      <button id="gr-run" class="gr-btn">Executar agora</button>
      <label class="gr-check"><input type="checkbox" id="gr-run-ignore"> ignorar horário (só simulação)</label>
    </div>
    <div id="gr-run-out" class="gr-muted"></div>
    <h2>Vencem hoje</h2><div id="gr-hoje" class="gr-list"></div>
    <h2>Próximos 7 dias</h2><div id="gr-proximos" class="gr-list"></div>
    <h2>Vencidos (últimos 15 dias)</h2><div id="gr-vencidos" class="gr-list"></div>
  </section>

  <section id="tab-historico" class="gr-tab">
    <div class="gr-actions">
      <select id="gr-hist-status"><option value="">Todos os status</option></select>
      <button id="gr-hist-reload" class="gr-btn gr-btn-sec">Atualizar</button>
    </div>
    <div id="gr-hist" class="gr-list"></div>
    <div class="gr-actions"><button id="gr-hist-more" class="gr-btn gr-btn-sec">Carregar mais</button></div>
  </section>

  <section id="tab-clientes" class="gr-tab">
    <p class="gr-muted">Só recebe lembrete quem autorizou. Registre como a autorização foi obtida. O cliente pode pedir para sair a qualquer momento (respondendo SAIR ou falando com você).</p>
    <div class="gr-actions"><input type="search" id="gr-cli-q" placeholder="Buscar cliente ou telefone"></div>
    <div id="gr-cli" class="gr-list"></div>
  </section>

  <section id="tab-config" class="gr-tab">
    <form id="gr-cfg" autocomplete="off"></form>
  </section>
</main>

<dialog id="gr-dialog"><div id="gr-dialog-body"></div><form method="dialog"><button class="gr-btn gr-btn-sec">Fechar</button></form></dialog>
<div id="gr-toast" role="status" aria-live="polite"></div>
<script src="renovacoes-assets/renovacoes.js?v=<?= $v ?>"></script>
</body>
</html>

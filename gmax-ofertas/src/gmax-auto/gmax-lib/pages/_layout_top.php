<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\View;
/** @var string $page @var string $title @var array $flash @var bool $paused @var bool $migrated */
$nav = [
    'painel' => ['Visão geral', '◧'], 'ofertas' => ['Ofertas', '▤'], 'importar' => ['Importar', '⇪'], 'links' => ['Links afiliados', '⛓'],
    'ranking' => ['Ranking', '★'], 'candidatos' => ['Candidatos ML', '◎'], 'publicacoes' => ['Publicações', '✉'], 'automacoes' => ['Automações', '⟳'], 'autolab' => ['AutoLab', '⚗'], 'relatorios' => ['Relatórios', '▥'],
    'categorias' => ['Categorias', '◈'], 'sistema' => ['Sistema', '⚙'],
];
$active = ['oferta' => 'ofertas', 'conferir' => 'ofertas', 'lote' => 'importar', 'lote_links' => 'links', 'integracoes' => 'automacoes', 'execucoes' => 'automacoes'][$page] ?? $page;
$v = View::e(GMAX_VERSION);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#08111f"><meta name="color-scheme" content="dark">
<meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><meta name="apple-mobile-web-app-title" content="GMAX">
<title><?= View::e($title) ?> — GMAX Ofertas</title>
<link rel="manifest" href="gmax-assets/manifest.json?v=<?= $v ?>">
<link rel="icon" href="gmax-assets/icon-192.png" type="image/png"><link rel="apple-touch-icon" href="gmax-assets/apple-touch-icon.png">
<link rel="stylesheet" href="gmax-assets/painel.css?v=<?= $v ?>">
<noscript><link rel="stylesheet" href="gmax-assets/sem-js.css?v=<?= $v ?>"></noscript>
</head>
<body class="app">
<a class="skip" href="#conteudo">Pular para o conteúdo</a>
<header class="top">
  <div class="top-inner">
    <button type="button" class="nav-toggle" data-toggle="#mainnav" aria-controls="mainnav" aria-expanded="false" aria-label="Menu"><span aria-hidden="true">☰</span><span class="nav-toggle-label" aria-hidden="true">Menu</span></button>
    <a class="brand" href="painel.php">GMAX <span class="accent">Ofertas</span></a>
    <?php if ($migrated): ?>
    <form class="global-search" method="get" action="painel.php" role="search">
      <input type="hidden" name="p" value="ofertas">
      <label class="sr-only" for="gq">Buscar produtos</label>
      <input id="gq" type="search" name="q" placeholder="Buscar produto, código, MLB, Item Id…" maxlength="100" enterkeyhint="search" autocomplete="off" value="<?= View::e($page === 'ofertas' && is_string($_GET['q'] ?? null) ? $_GET['q'] : '') ?>">
    </form>
    <?php endif; ?>
    <div class="top-actions">
      <?php if ($migrated && !$paused): ?>
        <form method="post" action="painel.php" class="inline" data-confirm="Ativar PAUSA EMERGENCIAL de todas as publicações?">
          <?= View::csrfField() ?><input type="hidden" name="action" value="pause"><input type="hidden" name="back" value="<?= View::e($active) ?>">
          <input type="hidden" name="reason" value="botão do cabeçalho">
          <button type="submit" class="btn btn-danger btn-sm">Pausa emergencial</button>
        </form>
      <?php else: ?>
        <span class="pill pill-paused" title="Nenhum envio será processado"><span class="pill-long">Envios pausados</span><span class="pill-short">Envios pausados</span></span>
      <?php endif; ?>
      <?= View::actionButton('logout', [], 'Sair', 'btn btn-ghost btn-sm') ?>
    </div>
  </div>
</header>
<div class="net-status" data-net-status role="status" aria-live="polite"></div>
<div class="shell">
  <nav id="mainnav" class="sidenav" aria-label="Principal">
    <?php foreach ($nav as $k => [$label, $icon]): ?>
      <a href="<?= View::e(View::url($k)) ?>"<?= $active === $k ? ' class="active" aria-current="page"' : '' ?>><span class="nav-ico" aria-hidden="true"><?= $icon ?></span><?= View::e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <main class="wrap" id="conteudo" tabindex="-1">
  <h1 class="page-title"><?= View::e($title) ?></h1>
  <?php foreach ($flash as [$type, $msg]): ?>
    <div class="alert alert-<?= View::e($type) ?>" role="<?= $type === 'erro' ? 'alert' : 'status' ?>"><?= View::e($msg) ?></div>
  <?php endforeach; ?>

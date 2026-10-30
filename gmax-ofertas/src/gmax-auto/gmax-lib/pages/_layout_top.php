<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\View;
/** @var string $page @var string $title @var array $flash @var bool $paused @var bool $migrated */
$nav = ['painel' => 'Visão geral', 'automacoes' => 'Automações', 'ofertas' => 'Ofertas', 'importar' => 'Importar', 'links' => 'Links afiliados', 'ranking' => 'Ranking',
    'publicacoes' => 'Publicações', 'relatorios' => 'Relatórios', 'categorias' => 'Categorias', 'sistema' => 'Sistema'];
$active = ['oferta' => 'ofertas', 'lote' => 'importar', 'lote_links' => 'links'][$page] ?? $page;
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?= View::e($title) ?> — GMAX Ofertas</title>
<link rel="stylesheet" href="gmax-assets/painel.css?v=<?= View::e(GMAX_VERSION) ?>">
</head>
<body>
<header class="top">
  <div class="top-inner">
    <a class="brand" href="painel.php">GMAX <span class="accent">Ofertas</span></a>
    <button type="button" class="nav-toggle" data-toggle="#mainnav" aria-controls="mainnav" aria-expanded="false">Menu</button>
    <nav id="mainnav" class="mainnav" aria-label="Principal">
      <?php foreach ($nav as $k => $label): ?>
        <a href="<?= View::e(View::url($k)) ?>"<?= $active === $k ? ' class="active" aria-current="page"' : '' ?>><?= View::e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="top-actions">
      <?php if ($migrated && !$paused): ?>
        <form method="post" action="painel.php" class="inline" data-confirm="Ativar PAUSA EMERGENCIAL de todas as publicações?">
          <?= View::csrfField() ?><input type="hidden" name="action" value="pause"><input type="hidden" name="back" value="<?= View::e($active) ?>">
          <input type="hidden" name="reason" value="botão do cabeçalho">
          <button type="submit" class="btn btn-danger">Pausa emergencial</button>
        </form>
      <?php else: ?>
        <span class="pill pill-paused" title="Nenhum envio será processado">Envios pausados</span>
      <?php endif; ?>
      <?= View::actionButton('logout', [], 'Sair', 'btn btn-ghost') ?>
    </div>
  </div>
</header>
<main class="wrap">
  <h1 class="page-title"><?= View::e($title) ?></h1>
  <?php foreach ($flash as [$type, $msg]): ?>
    <div class="alert alert-<?= View::e($type) ?>" role="<?= $type === 'erro' ? 'alert' : 'status' ?>"><?= View::e($msg) ?></div>
  <?php endforeach; ?>

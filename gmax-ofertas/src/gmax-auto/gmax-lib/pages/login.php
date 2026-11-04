<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\View;
/** @var bool $hasUser @var array $flash */
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#08111f"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="GMAX">
<title>Entrar — GMAX Ofertas</title>
<link rel="manifest" href="gmax-assets/manifest.json?v=<?= View::e(GMAX_VERSION) ?>"><link rel="icon" href="gmax-assets/icon-192.png" type="image/png"><link rel="apple-touch-icon" href="gmax-assets/apple-touch-icon.png">
<link rel="stylesheet" href="gmax-assets/painel.css?v=<?= View::e(GMAX_VERSION) ?>">
</head>
<body class="login-page">
<main class="login-box">
  <h1>GMAX <span class="accent">Ofertas</span></h1>
  <p class="muted">Painel multiloja · Shopee + Mercado Livre</p>
  <?php foreach ($flash as [$type, $msg]): ?><div class="alert alert-<?= View::e($type) ?>" role="alert"><?= View::e($msg) ?></div><?php endforeach; ?>
  <?php if (!$hasUser): ?>
    <div class="alert alert-aviso">Nenhuma senha cadastrada. Faça a configuração inicial em <a href="index.php">index.php</a>.</div>
  <?php else: ?>
  <form method="post" action="painel.php" data-loading>
    <?= View::csrfField() ?>
    <input type="hidden" name="action" value="login">
    <label for="password">Senha de administrador</label>
    <input id="password" type="password" name="password" required autocomplete="current-password" autofocus>
    <button type="submit" class="btn btn-primary btn-block">Entrar</button>
  </form>
  <p class="muted small">Mesma senha do painel atual (index.php).</p>
  <?php endif; ?>
</main>
<script src="gmax-assets/painel.js?v=<?= View::e(GMAX_VERSION) ?>"></script>
</body>
</html>

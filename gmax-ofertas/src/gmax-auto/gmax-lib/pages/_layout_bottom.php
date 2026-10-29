<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\View;
?>
  <footer class="foot muted small">GMAX Ofertas <?= View::e(GMAX_VERSION) ?> · horário de São Paulo · nenhuma ação deste painel envia mensagens</footer>
</main>
<script src="gmax-assets/painel.js?v=<?= View::e(GMAX_VERSION) ?>"></script>
</body>
</html>

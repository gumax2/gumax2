<?php
declare(strict_types=1);
defined('GMAX_VERSION') || exit;
use Gmax\View;
/** @var PDO $pdo */
$cats = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM gmax3_catalog g WHERE g.category=c.name AND g.validation_status<>'arquivado') n FROM gmax3_categories c ORDER BY c.name")->fetchAll();
?>
<section class="card">
  <h2>Categorias e potencial comercial</h2>
  <p class="muted small">O potencial (0 a 100) é a sua avaliação estratégica da categoria e entra no ranking com peso 10. Deixe vazio enquanto não houver base para definir.</p>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Categoria</th><th>Produtos</th><th>Potencial</th><th>Ativa</th><th>Notas</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($cats as $i => $c): $fid = 'cat' . $i; ?>
      <tr>
        <td data-label="Categoria"><?= View::e($c['name']) ?></td>
        <td data-label="Produtos"><?= (int)$c['n'] ?></td>
        <td data-label="Potencial"><input form="<?= $fid ?>" name="potential" inputmode="numeric" maxlength="3" value="<?= View::e($c['potential']) ?>" aria-label="Potencial de <?= View::e($c['name']) ?>"></td>
        <td data-label="Ativa"><input form="<?= $fid ?>" type="checkbox" name="active" value="1"<?= $c['active'] ? ' checked' : '' ?> aria-label="Ativa"></td>
        <td data-label="Notas"><input form="<?= $fid ?>" name="notes" maxlength="300" value="<?= View::e($c['notes']) ?>" aria-label="Notas"></td>
        <td><form id="<?= $fid ?>" method="post" action="painel.php" data-loading><?= View::csrfField() ?><input type="hidden" name="action" value="category_save"><input type="hidden" name="back" value="categorias"><input type="hidden" name="name" value="<?= View::e($c['name']) ?>"><button class="btn btn-sm" type="submit">Salvar</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<section class="card">
  <h2>Nova categoria</h2>
  <form method="post" action="painel.php" class="inline-form" data-loading>
    <?= View::csrfField() ?><input type="hidden" name="action" value="category_save"><input type="hidden" name="back" value="categorias"><input type="hidden" name="active" value="1">
    <input name="name" maxlength="80" required placeholder="Nome" aria-label="Nome"><input name="potential" inputmode="numeric" maxlength="3" placeholder="Potencial 0–100" aria-label="Potencial">
    <button class="btn" type="submit">Adicionar</button>
  </form>
</section>

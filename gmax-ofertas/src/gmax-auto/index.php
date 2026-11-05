<?php
declare(strict_types=1);
/*
 * GMAX Ofertas 3.2.0 — o antigo index.php (login sem limite de tentativas, configuração de senha e
 * importação para a tabela antiga "offers") foi retirado. O acesso administrativo é centralizado no
 * painel.php (login com limite de tentativas, expiração e regeneração de sessão).
 * Redireciona sempre para o mesmo destino fixo e relativo; não lê nenhum parâmetro.
 * O arquivo original está em rollback/index.php.original no pacote da 3.2.0 (não extrair na pasta pública).
 */
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Location: painel.php', true, 302);
exit;

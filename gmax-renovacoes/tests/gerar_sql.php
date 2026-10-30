<?php
/** Gera sql/001_inicial.sql e sql/rollback_remover_modulo.sql a partir da migração PHP (fonte única). */
declare(strict_types=1);

require dirname(__DIR__) . '/src/privado/gmax-renovacoes/bootstrap.php';

$m = require GR_ROOT . '/migrations/001_inicial.php';
$q = static fn(string $s): string => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $s) . "'";

$out = "-- GMAX Renovações — Migração 001 (aditiva e reaplicável). Gerado por tests/gerar_sql.php.\n"
    . "-- Cria SOMENTE tabelas novas com prefixo gr_. Não altera clientes, pacotes, recebimentos nem custos.\n"
    . "-- Preferível: php bin/renovacoes-admin.php migrar. Use este arquivo no phpMyAdmin se não tiver SSH.\n"
    . "-- Pode ser executado mais de uma vez sem perda ou duplicação (IF NOT EXISTS / INSERT IGNORE).\n"
    . "SET NAMES utf8mb4;\n\n"
    . "CREATE TABLE IF NOT EXISTS gr_migracoes (\n  versao VARCHAR(10) NOT NULL PRIMARY KEY,\n  nome VARCHAR(100) NOT NULL,\n  checksum CHAR(64) NOT NULL,\n  aplicado_em DATETIME NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n";
foreach ($m['sql'] as $sql) {
    $out .= preg_replace('/\n {8}/', "\n", $sql) . ";\n\n";
}
foreach (\GmaxRenov\Settings::defaults() as $k => $v) {
    $out .= 'INSERT IGNORE INTO gr_configuracoes (chave, valor, atualizado_em) VALUES (' . $q($k) . ', ' . $q(json_encode($v, JSON_UNESCAPED_UNICODE)) . ", NOW());\n";
}
$out .= "\nINSERT IGNORE INTO gr_migracoes (versao, nome, checksum, aplicado_em) VALUES ('001', 'inicial', " . $q(hash('sha256', json_encode($m['sql']))) . ", NOW());\n";

$dir = dirname(__DIR__) . '/sql';
@mkdir($dir);
file_put_contents("$dir/001_inicial.sql", $out);
file_put_contents("$dir/rollback_remover_modulo.sql", "-- GMAX Renovações — REMOVE o módulo: apaga SOMENTE as tabelas gr_*.\n-- Faça backup antes (php bin/renovacoes-admin.php backup). Não toca nas tabelas do painel.\nDROP TABLE IF EXISTS gr_eventos, gr_mensagens, gr_lembretes, gr_consentimento_log, gr_contatos, gr_execucoes, gr_configuracoes, gr_migracoes;\n");
echo "sql/ gerado\n";

-- GMAX Renovações — REMOVE o módulo: apaga SOMENTE as tabelas gr_*.
-- Faça backup antes (php bin/renovacoes-admin.php backup). Não toca nas tabelas do painel.
DROP TABLE IF EXISTS gr_eventos, gr_mensagens, gr_lembretes, gr_consentimento_log, gr_contatos, gr_execucoes, gr_configuracoes, gr_migracoes;

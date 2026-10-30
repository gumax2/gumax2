<?php
declare(strict_types=1);
/*
 * Migração 003 — Detalhamento por produto das execuções (3.1.2).
 * Só cria uma tabela nova, somente-inclusão (gatilhos impedem alterar ou apagar linhas):
 * cada produto analisado, bloqueado, não processado ou fora da revisão fica registrado
 * com o motivo, o resultado da consulta à API (código HTTP, sem tokens) e os preços.
 * Não altera tabelas existentes. O "down" remove só esta tabela.
 */

return [
    'version' => '003',
    'description' => 'Detalhes das execuções: produtos analisados, motivos, consultas à API e preços',
    'tables' => ['gmax3_job_run_items'],
    'up' => static function (PDO $pdo): array {
        $pdo->exec(<<<'SQL'
CREATE TABLE gmax3_job_run_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  run_id INTEGER NOT NULL,
  catalog_id INTEGER,
  marketplace TEXT NOT NULL,
  external_id TEXT,
  title TEXT,
  outcome TEXT NOT NULL CHECK (outcome IN ('atualizado','validado','sem_alteracao','bloqueado','nao_processado','fora_da_revisao')),
  reason TEXT NOT NULL,
  api_endpoint TEXT,
  http_status INTEGER,
  api_error TEXT,
  price_before_cents INTEGER,
  price_api_cents INTEGER,
  original_before_cents INTEGER,
  original_api_cents INTEGER,
  availability_api TEXT,
  checked_at TEXT,
  created_at TEXT NOT NULL
);
CREATE INDEX gmax3_job_run_items_run ON gmax3_job_run_items(run_id, outcome);
CREATE INDEX gmax3_job_run_items_cat ON gmax3_job_run_items(catalog_id);
CREATE TRIGGER gmax3_job_run_items_no_update BEFORE UPDATE ON gmax3_job_run_items
BEGIN SELECT RAISE(ABORT, 'Histórico de execuções é somente-inclusão'); END;
CREATE TRIGGER gmax3_job_run_items_no_delete BEFORE DELETE ON gmax3_job_run_items
BEGIN SELECT RAISE(ABORT, 'Histórico de execuções é somente-inclusão'); END;
SQL);
        return ['tabela' => 'gmax3_job_run_items', 'execucoes_anteriores' => 'mantidas (sem detalhamento por produto)'];
    },
];

<?php
declare(strict_types=1);
/*
 * Migração 005 — Operação (3.2.1): índices, auditoria protegida e base da política de retenção.
 * - Só CRIA índices (nenhum dado é alterado). Escolhidos por EXPLAIN QUERY PLAN das consultas reais
 *   (tools/explain_indices.php): ordenação da lista de ofertas, assinatura do cache, histórico
 *   conferido do Ranking, diagnóstico das tarefas e as datas usadas na simulação de retenção.
 * - Cria gmax3_retention_runs (registro das simulações e execuções de retenção; não pode ser apagado).
 * - Auditoria passa a ser somente-inclusão (gatilhos impedem alterar ou apagar).
 * - Detalhe das execuções (gmax3_job_run_items) e registro de descobertas (gmax3_discoveries):
 *   continuam somente-inclusão; a ÚNICA exceção é apagar linhas com mais de 90 dias DURANTE uma
 *   execução de retenção autorizada pela linha de comando (linha 'executando' em gmax3_retention_runs).
 *   Nenhuma exclusão é feita por esta migração nem por qualquer tarefa automática.
 * O "down" remove só o que esta migração criou e devolve o gatilho original da 003.
 */

$indexes = [
    'gmax3_catalog_updated' => 'gmax3_catalog(updated_at)',
    'gmax3_catalog_verified_at' => 'gmax3_catalog(price_verified_at)',
    'gmax3_catalog_category' => 'gmax3_catalog(category)',
    'gmax3_price_history_verified' => 'gmax3_price_history(verified, catalog_id, price_promo_cents)',
    'gmax3_pub_queue_created' => 'gmax3_pub_queue(created_at)',
    'gmax3_job_runs_status' => 'gmax3_job_runs(status, started_at)',
    'gmax3_job_runs_job' => 'gmax3_job_runs(job, id)',
    'gmax3_job_run_items_created' => 'gmax3_job_run_items(created_at)',
    'gmax3_discoveries_seen' => 'gmax3_discoveries(seen_at)',
];

return [
    'version' => '005',
    'description' => 'Operação: índices, auditoria somente-inclusão e registro da política de retenção (sem exclusões)',
    'tables' => ['gmax3_retention_runs'],
    'indexes' => $indexes,
    'up' => static function (PDO $pdo) use ($indexes): array {
        foreach ($indexes as $name => $def) {
            $pdo->exec("CREATE INDEX IF NOT EXISTS $name ON $def");
        }
        $pdo->exec(<<<'SQL'
CREATE TABLE gmax3_retention_runs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind TEXT NOT NULL CHECK (kind IN ('simulacao','execucao')),
  status TEXT NOT NULL CHECK (status IN ('simulado','executando','executado','falhou')),
  simulation_id INTEGER,
  created_at TEXT NOT NULL,
  finished_at TEXT,
  actor TEXT NOT NULL,
  policy TEXT NOT NULL,
  result TEXT,
  backup_file TEXT,
  export_file TEXT,
  export_sha256 TEXT
);
CREATE TRIGGER gmax3_retention_runs_no_delete BEFORE DELETE ON gmax3_retention_runs
BEGIN SELECT RAISE(ABORT, 'Registro de retenção não pode ser apagado'); END;

CREATE TRIGGER gmax3_audit_no_update BEFORE UPDATE ON gmax3_audit
BEGIN SELECT RAISE(ABORT, 'Auditoria é somente-inclusão'); END;
CREATE TRIGGER gmax3_audit_no_delete BEFORE DELETE ON gmax3_audit
BEGIN SELECT RAISE(ABORT, 'Auditoria é somente-inclusão'); END;

DROP TRIGGER IF EXISTS gmax3_job_run_items_no_delete;
CREATE TRIGGER gmax3_job_run_items_no_delete BEFORE DELETE ON gmax3_job_run_items
WHEN NOT (OLD.created_at < datetime('now', '-90 days')
          AND EXISTS (SELECT 1 FROM gmax3_retention_runs WHERE kind='execucao' AND status='executando'))
BEGIN SELECT RAISE(ABORT, 'Histórico de execuções é somente-inclusão (exceto retenção autorizada de linhas com mais de 90 dias)'); END;

CREATE TRIGGER gmax3_discoveries_no_update BEFORE UPDATE ON gmax3_discoveries
BEGIN SELECT RAISE(ABORT, 'Registro de descobertas é somente-inclusão'); END;
CREATE TRIGGER gmax3_discoveries_no_delete BEFORE DELETE ON gmax3_discoveries
WHEN NOT (OLD.seen_at < datetime('now', '-90 days')
          AND EXISTS (SELECT 1 FROM gmax3_retention_runs WHERE kind='execucao' AND status='executando'))
BEGIN SELECT RAISE(ABORT, 'Registro de descobertas é somente-inclusão (exceto retenção autorizada de linhas com mais de 90 dias)'); END;
SQL);
        $pdo->exec('ANALYZE');
        return ['indices' => array_keys($indexes), 'tabela' => 'gmax3_retention_runs', 'exclusoes' => 0];
    },
    'down' => static function (PDO $pdo) use ($indexes): array {
        foreach (array_keys($indexes) as $name) {
            $pdo->exec("DROP INDEX IF EXISTS $name");
        }
        $pdo->exec(<<<'SQL'
DROP TRIGGER IF EXISTS gmax3_audit_no_update;
DROP TRIGGER IF EXISTS gmax3_audit_no_delete;
DROP TRIGGER IF EXISTS gmax3_discoveries_no_update;
DROP TRIGGER IF EXISTS gmax3_discoveries_no_delete;
DROP TRIGGER IF EXISTS gmax3_job_run_items_no_delete;
CREATE TRIGGER gmax3_job_run_items_no_delete BEFORE DELETE ON gmax3_job_run_items
BEGIN SELECT RAISE(ABORT, 'Histórico de execuções é somente-inclusão'); END;
DROP TRIGGER IF EXISTS gmax3_retention_runs_no_delete;
DROP TABLE IF EXISTS gmax3_retention_runs;
SQL);
        return ['indices_removidos' => array_keys($indexes), 'tabela_removida' => 'gmax3_retention_runs'];
    },
];

<?php
declare(strict_types=1);
/*
 * Migração 004 — Candidatos do catálogo do Mercado Livre (3.1.9).
 * Só cria uma tabela nova, SEPARADA do catálogo de ofertas (gmax3_catalog), e uma tarefa
 * de automação INATIVA. Candidato não é oferta: não entra no ranking de prontas, na fila
 * nem nas mensagens. IDs de catálogo (product_id) e de anúncio (bb_item_id) ficam em colunas distintas.
 */

return [
    'version' => '004',
    'description' => 'Candidatos do catálogo do Mercado Livre (descoberta sem preço confirmado)',
    'tables' => ['gmax3_ml_candidates'],
    'up' => static function (PDO $pdo): array {
        $pdo->exec(<<<'SQL'
CREATE TABLE gmax3_ml_candidates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  product_id TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  domain_id TEXT,
  category_id TEXT,
  category_name TEXT,
  picture_url TEXT,
  permalink TEXT,
  origem TEXT NOT NULL CHECK (origem IN ('termo','tendencia')),
  termo TEXT,
  trend_pos INTEGER,
  bb_item_id TEXT,
  bb_price_cents INTEGER,
  bb_original_cents INTEGER,
  bb_currency TEXT,
  bb_free_shipping INTEGER,
  bb_official_store INTEGER,
  bb_checked_at TEXT,
  status TEXT NOT NULL DEFAULT 'novo' CHECK (status IN ('novo','rascunho_criado','descartado')),
  catalog_id INTEGER,
  run_id INTEGER,
  first_seen_at TEXT NOT NULL,
  last_seen_at TEXT NOT NULL
);
CREATE INDEX gmax3_ml_candidates_status ON gmax3_ml_candidates(status, last_seen_at);
SQL);
        $pdo->prepare('INSERT OR IGNORE INTO gmax3_jobs(job,name,enabled,times,weekdays,params,updated_by) VALUES(?,?,0,?,?,?,?)')->execute([
            'candidatos_ml', 'Candidatos do catálogo Mercado Livre (sem preço confirmado)', '08:00', '1,2,3,4,5,6,7',
            '{"limite_por_termo":10,"tendencias":5,"detalhe_max":15}', 'migracao_004',
        ]);
        return ['tabela' => 'gmax3_ml_candidates', 'tarefa' => 'candidatos_ml (inativa)'];
    },
];

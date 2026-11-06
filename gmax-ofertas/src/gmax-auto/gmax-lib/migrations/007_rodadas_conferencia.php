<?php
declare(strict_types=1);
/*
 * Migração 007 — Conferência progressiva por rodadas (3.2.2.2). Só CRIA tabelas novas:
 * - gmax3_review_rounds: rodadas de conferência (uma aberta por administrador; histórico preservado);
 * - gmax3_review_items: progresso de cada produto na rodada (não analisado / analisado / pulado / revisar),
 *   com a "linha de base" dos dados no momento da análise (para sinalizar mudanças posteriores);
 * - gmax3_review_visits: histórico de cada visita (data, administrador, resultado), somente-inclusão.
 * O progresso da conferência é SEPARADO das pendências comerciais e da prontidão: nenhuma coluna de
 * gmax3_catalog é alterada e nenhum produto existente é reclassificado. O "down" remove só estas tabelas.
 */

return [
    'version' => '007',
    'description' => 'Conferência progressiva: rodadas, progresso por produto e histórico de visitas',
    'tables' => ['gmax3_review_visits', 'gmax3_review_items', 'gmax3_review_rounds'],
    'up' => static function (PDO $pdo): array {
        $pdo->exec(<<<'SQL'
CREATE TABLE gmax3_review_rounds (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  admin TEXT NOT NULL,
  status TEXT NOT NULL CHECK (status IN ('aberta','encerrada')),
  started_at TEXT NOT NULL,
  closed_at TEXT,
  total_initial INTEGER NOT NULL DEFAULT 0,
  note TEXT
);
CREATE UNIQUE INDEX gmax3_review_rounds_open ON gmax3_review_rounds(admin) WHERE status = 'aberta';
CREATE TABLE gmax3_review_items (
  round_id INTEGER NOT NULL,
  catalog_id INTEGER NOT NULL,
  position INTEGER NOT NULL,
  added_as TEXT NOT NULL DEFAULT 'inicial' CHECK (added_as IN ('inicial','extensao','revisao')),
  state TEXT NOT NULL DEFAULT 'nao_analisado' CHECK (state IN ('nao_analisado','analisado','pulado','revisar')),
  visits INTEGER NOT NULL DEFAULT 0,
  last_result TEXT,
  last_visit_at TEXT,
  last_visit_by TEXT,
  base_price_cents INTEGER,
  base_availability TEXT,
  base_variation INTEGER,
  base_affiliate TEXT,
  base_status TEXT,
  PRIMARY KEY (round_id, catalog_id)
);
CREATE INDEX gmax3_review_items_next ON gmax3_review_items(round_id, state, position);
CREATE TABLE gmax3_review_visits (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  round_id INTEGER NOT NULL,
  catalog_id INTEGER NOT NULL,
  at TEXT NOT NULL,
  admin TEXT NOT NULL,
  result TEXT NOT NULL CHECK (result IN ('analisado','pulado','revisar','revisitado')),
  action TEXT NOT NULL,
  detail TEXT
);
CREATE INDEX gmax3_review_visits_item ON gmax3_review_visits(round_id, catalog_id);
CREATE TRIGGER gmax3_review_visits_no_update BEFORE UPDATE ON gmax3_review_visits BEGIN SELECT RAISE(ABORT, 'Histórico de conferência é somente-inclusão'); END;
CREATE TRIGGER gmax3_review_visits_no_delete BEFORE DELETE ON gmax3_review_visits BEGIN SELECT RAISE(ABORT, 'Histórico de conferência é somente-inclusão'); END;
CREATE TRIGGER gmax3_review_items_no_delete BEFORE DELETE ON gmax3_review_items BEGIN SELECT RAISE(ABORT, 'Progresso de conferência não pode ser apagado'); END;
CREATE TRIGGER gmax3_review_rounds_no_delete BEFORE DELETE ON gmax3_review_rounds BEGIN SELECT RAISE(ABORT, 'Rodada de conferência não pode ser apagada'); END;
SQL);
        return ['tabelas' => ['gmax3_review_rounds', 'gmax3_review_items', 'gmax3_review_visits'], 'produtos_alterados' => 0];
    },
];

<?php
declare(strict_types=1);
/*
 * Migração 001 — modelo multiloja unificado.
 * NÃO altera nem apaga tabelas existentes (offers, slots, users, gmax_ml_offers,
 * gmax_dispatches). Apenas cria tabelas novas com prefixo gmax3_ e COPIA os produtos
 * existentes para o catálogo unificado, guardando a referência à linha original.
 * O "down" remove somente as tabelas gmax3_ listadas abaixo.
 */

use Gmax\Catalog;
use Gmax\Db;

return [
    'version' => '001',
    'description' => 'Catálogo multiloja, importação em lotes, fila de publicação auditável, ranking e configurações',
    'tables' => [
        'gmax3_catalog', 'gmax3_price_history', 'gmax3_categories', 'gmax3_import_batches', 'gmax3_import_items',
        'gmax3_pub_queue', 'gmax3_settings', 'gmax3_audit', 'gmax3_login_attempts', 'gmax3_heartbeats',
    ],
    'up' => static function (PDO $pdo): array {
        $pdo->exec(<<<'SQL'
CREATE TABLE gmax3_catalog (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  marketplace TEXT NOT NULL CHECK (marketplace IN ('shopee','mercadolivre')),
  dedup_key TEXT NOT NULL,
  codigo TEXT,
  external_id TEXT,
  title TEXT NOT NULL,
  category TEXT,
  store_name TEXT,
  original_url TEXT,
  affiliate_url TEXT,
  affiliate_norm TEXT,
  price_original_cents INTEGER CHECK (price_original_cents IS NULL OR price_original_cents > 0),
  price_promo_cents INTEGER CHECK (price_promo_cents IS NULL OR price_promo_cents > 0),
  payment_condition TEXT,
  payment_note TEXT,
  price_source TEXT,
  price_seen_at TEXT,
  price_verified_at TEXT,
  price_verified_by TEXT,
  sales_public TEXT,
  sales_min INTEGER,
  rating REAL,
  rating_count INTEGER,
  seller_reputation TEXT,
  availability TEXT NOT NULL DEFAULT 'desconhecida',
  commission_rate TEXT,
  commission_value TEXT,
  variation_pending INTEGER NOT NULL DEFAULT 0,
  variation_note TEXT,
  notes TEXT,
  validation_status TEXT NOT NULL DEFAULT 'aguardando_validacao'
    CHECK (validation_status IN ('rascunho','aguardando_validacao','aprovado','rejeitado','arquivado')),
  legacy_table TEXT,
  legacy_key TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (marketplace, dedup_key)
);
CREATE UNIQUE INDEX gmax3_catalog_codigo ON gmax3_catalog(marketplace, codigo) WHERE codigo IS NOT NULL;
CREATE UNIQUE INDEX gmax3_catalog_external ON gmax3_catalog(marketplace, external_id) WHERE external_id IS NOT NULL;
CREATE UNIQUE INDEX gmax3_catalog_affiliate ON gmax3_catalog(affiliate_norm) WHERE affiliate_norm IS NOT NULL;
CREATE INDEX gmax3_catalog_status ON gmax3_catalog(validation_status, marketplace);

CREATE TABLE gmax3_price_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  catalog_id INTEGER NOT NULL REFERENCES gmax3_catalog(id) ON DELETE CASCADE,
  price_original_cents INTEGER,
  price_promo_cents INTEGER,
  payment_condition TEXT,
  source TEXT NOT NULL,
  verified INTEGER NOT NULL DEFAULT 0,
  observed_at TEXT,
  recorded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX gmax3_price_history_item ON gmax3_price_history(catalog_id, recorded_at);

CREATE TABLE gmax3_categories (
  name TEXT PRIMARY KEY,
  potential INTEGER CHECK (potential IS NULL OR (potential BETWEEN 0 AND 100)),
  active INTEGER NOT NULL DEFAULT 1,
  notes TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE gmax3_import_batches (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  filename TEXT NOT NULL,
  format TEXT NOT NULL,
  sha256 TEXT NOT NULL,
  status TEXT NOT NULL CHECK (status IN ('previa','aplicado','revertido','descartado')),
  total INTEGER NOT NULL DEFAULT 0,
  inserted INTEGER NOT NULL DEFAULT 0,
  updated INTEGER NOT NULL DEFAULT 0,
  unchanged INTEGER NOT NULL DEFAULT 0,
  rejected INTEGER NOT NULL DEFAULT 0,
  actor TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  applied_at TEXT,
  reverted_at TEXT,
  note TEXT
);
CREATE TABLE gmax3_import_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  batch_id INTEGER NOT NULL REFERENCES gmax3_import_batches(id) ON DELETE CASCADE,
  line INTEGER NOT NULL,
  action TEXT NOT NULL,
  catalog_id INTEGER,
  payload_json TEXT NOT NULL,
  before_json TEXT,
  after_hash TEXT,
  errors_json TEXT,
  warnings_json TEXT
);
CREATE INDEX gmax3_import_items_batch ON gmax3_import_items(batch_id, line);

CREATE TABLE gmax3_pub_queue (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  catalog_id INTEGER NOT NULL REFERENCES gmax3_catalog(id),
  channel TEXT NOT NULL,
  state TEXT NOT NULL CHECK (state IN ('agendado','reservado','confirmado','incerto','falhou','bloqueado','cancelado')),
  scheduled_for TEXT NOT NULL,
  message_text TEXT,
  message_hash TEXT,
  attempts INTEGER NOT NULL DEFAULT 0,
  http_code INTEGER,
  telegram_message_id INTEGER,
  note TEXT,
  created_by TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reserved_at TEXT,
  finished_at TEXT,
  reconciled_by TEXT,
  reconciled_at TEXT
);
CREATE INDEX gmax3_pub_queue_state ON gmax3_pub_queue(state, scheduled_for);
CREATE INDEX gmax3_pub_queue_item ON gmax3_pub_queue(catalog_id, state);

CREATE TABLE gmax3_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_by TEXT);
CREATE TABLE gmax3_audit (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor TEXT NOT NULL,
  action TEXT NOT NULL,
  entity TEXT,
  entity_id TEXT,
  detail TEXT
);
CREATE INDEX gmax3_audit_at ON gmax3_audit(at);
CREATE TABLE gmax3_login_attempts (ip_hash TEXT NOT NULL, at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE INDEX gmax3_login_attempts_ip ON gmax3_login_attempts(ip_hash, at);
CREATE TABLE gmax3_heartbeats (name TEXT PRIMARY KEY, last_at TEXT NOT NULL, detail TEXT);
SQL);

        // Padrões conservadores: publicação pausada e automação comercial desligada.
        $defaults = [
            'publicacao_pausada' => '1',
            'motivo_pausa' => 'Instalação 3.0: pausado por padrão até autorização explícita',
            'automacao_comercial' => '0',
            'ml_grupo_privado_permitido' => '0',
            'janela_duplicidade_dias' => '7',
            'validade_preco_horas' => '24',
            'horarios' => '08:00,10:00,12:00,15:00,18:00,20:00',
        ];
        $st = $pdo->prepare('INSERT INTO gmax3_settings(key,value,updated_by) VALUES(?,?,?)');
        foreach ($defaults as $k => $v) {
            $st->execute([$k, $v, 'migracao_001']);
        }

        $copied = Catalog::syncLegacy($pdo, 'migracao_001');
        foreach (Db::all($pdo, "SELECT DISTINCT category FROM gmax3_catalog WHERE category IS NOT NULL AND category<>''") as $r) {
            Db::run($pdo, 'INSERT OR IGNORE INTO gmax3_categories(name) VALUES(?)', [$r['category']]);
        }
        return $copied;
    },
];

<?php
declare(strict_types=1);
/*
 * Migração 006 — GMAX AutoLab (3.2.1): homologação automática no Telegram privado.
 * Só CRIA duas tabelas próprias, separadas da fila comercial (gmax3_pub_queue não é tocada):
 * - gmax3_autolab_runs: uma linha por horário (slot_key único = idempotência entre Crons);
 * - gmax3_autolab_messages: uma mensagem de teste por horário, com o resultado do Telegram.
 * Registros não podem ser apagados (gatilhos). Estado e autorização do AutoLab ficam em
 * configurações próprias (autolab_*), que NÃO liberam as travas da publicação comercial.
 * Nada é ativado por esta migração: o AutoLab nasce "desativado".
 * O "down" remove só estas duas tabelas (as configurações autolab_* ficam e são ignoradas).
 */

return [
    'version' => '006',
    'description' => 'GMAX AutoLab: execuções e mensagens de homologação, separadas da fila comercial',
    'tables' => ['gmax3_autolab_runs', 'gmax3_autolab_messages'],
    'up' => static function (PDO $pdo): array {
        $pdo->exec(<<<'SQL'
CREATE TABLE gmax3_autolab_runs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slot_key TEXT NOT NULL UNIQUE,
  status TEXT NOT NULL CHECK (status IN ('executando','concluido','sem_envio','bloqueado','falhou')),
  started_at TEXT NOT NULL,
  finished_at TEXT,
  detail TEXT,
  checks TEXT
);
CREATE INDEX gmax3_autolab_runs_started ON gmax3_autolab_runs(started_at);
CREATE TABLE gmax3_autolab_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  run_id INTEGER NOT NULL,
  slot_key TEXT NOT NULL UNIQUE,
  kind TEXT NOT NULL CHECK (kind IN ('oferta_validada','candidato','simulacao')),
  marketplace TEXT,
  catalog_id INTEGER,
  candidate_id INTEGER,
  source TEXT NOT NULL,
  price_confirmed INTEGER NOT NULL DEFAULT 0,
  price_cents INTEGER,
  price_catalog_cents INTEGER,
  price_checked_at TEXT,
  link_kind TEXT NOT NULL DEFAULT 'nenhum' CHECK (link_kind IN ('afiliado','nenhum')),
  dedup_key TEXT NOT NULL,
  text TEXT NOT NULL,
  text_hash TEXT NOT NULL,
  state TEXT NOT NULL CHECK (state IN ('preparado','reservado','confirmado','falhou','incerto')),
  missing TEXT,
  http_code INTEGER,
  telegram_message_id INTEGER,
  note TEXT,
  created_at TEXT NOT NULL,
  reserved_at TEXT,
  finished_at TEXT
);
CREATE INDEX gmax3_autolab_messages_dedup ON gmax3_autolab_messages(dedup_key, created_at);
CREATE INDEX gmax3_autolab_messages_state ON gmax3_autolab_messages(state, created_at);
CREATE TRIGGER gmax3_autolab_runs_no_delete BEFORE DELETE ON gmax3_autolab_runs
BEGIN SELECT RAISE(ABORT, 'Registro do AutoLab não pode ser apagado'); END;
CREATE TRIGGER gmax3_autolab_messages_no_delete BEFORE DELETE ON gmax3_autolab_messages
BEGIN SELECT RAISE(ABORT, 'Registro do AutoLab não pode ser apagado'); END;
CREATE TRIGGER gmax3_autolab_messages_text_fixed BEFORE UPDATE OF text, text_hash, kind, slot_key ON gmax3_autolab_messages
BEGIN SELECT RAISE(ABORT, 'Texto de mensagem do AutoLab não pode ser alterado'); END;
SQL);
        return ['tabelas' => ['gmax3_autolab_runs', 'gmax3_autolab_messages'], 'estado_inicial' => 'desativado'];
    },
];

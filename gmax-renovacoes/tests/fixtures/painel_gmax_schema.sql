-- Estrutura do painel GMAX (cópia de schema/SOMENTE_REFERENCIA.sql da base de 15/10/2026), SEM dados.
-- Usada SOMENTE em bancos descartáveis de teste (grtest_*). Nunca executar no banco real.
CREATE TABLE IF NOT EXISTS admins (id INTEGER PRIMARY KEY, username VARCHAR(100) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL);
CREATE TABLE IF NOT EXISTS clients (id VARCHAR(36) PRIMARY KEY, name VARCHAR(160) NOT NULL, phone VARCHAR(30) NOT NULL DEFAULT '', user_code VARCHAR(80) NOT NULL DEFAULT '', service VARCHAR(10) NOT NULL, months INTEGER NOT NULL, points INTEGER NOT NULL DEFAULT 1, price_cents INTEGER NOT NULL, cost_cents INTEGER NOT NULL, due_date VARCHAR(10) NOT NULL, status VARCHAR(16) NOT NULL, notes TEXT NOT NULL, created_at VARCHAR(30) NOT NULL);
CREATE TABLE IF NOT EXISTS entries (id VARCHAR(36) PRIMARY KEY, group_id VARCHAR(36) NOT NULL, client_id VARCHAR(36), kind VARCHAR(10) NOT NULL, amount_cents INTEGER NOT NULL, date VARCHAR(10) NOT NULL, description VARCHAR(255) NOT NULL, created_at VARCHAR(30) NOT NULL);
CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY, target_cents INTEGER NOT NULL, default_price_cents INTEGER NOT NULL, default_cost_cents INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS login_attempts (ip_hash VARCHAR(64) PRIMARY KEY, attempts INTEGER NOT NULL, last_at INTEGER NOT NULL);


CREATE INDEX entries_date_idx ON entries(date);
CREATE INDEX entries_client_idx ON entries(client_id);

CREATE TABLE IF NOT EXISTS gmax_migrations (
 version VARCHAR(40) PRIMARY KEY, applied_at VARCHAR(30) NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS client_plans (
 id VARCHAR(36) PRIMARY KEY,
 client_id VARCHAR(36) NOT NULL,
 label VARCHAR(160) NOT NULL,
 user_code VARCHAR(80) NOT NULL DEFAULT '',
 service VARCHAR(10) NOT NULL,
 months INTEGER NOT NULL,
 points INTEGER NOT NULL DEFAULT 1,
 price_cents INTEGER NOT NULL,
 cost_cents INTEGER NOT NULL,
 due_date VARCHAR(10) NOT NULL,
 status VARCHAR(16) NOT NULL,
 notes TEXT NOT NULL,
 created_at VARCHAR(30) NOT NULL,
 INDEX gmax_plans_client_idx (client_id),
 INDEX gmax_plans_due_idx (due_date)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS client_exclusions (
 client_id VARCHAR(36) PRIMARY KEY, deleted_at VARCHAR(30) NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS entry_plans (
 group_id VARCHAR(36) PRIMARY KEY, plan_id VARCHAR(36) NOT NULL
) ENGINE=InnoDB;

INSERT INTO gmax_migrations (version, applied_at) VALUES ('independent-plans-v1', '2026-10-15T00:00:00-03:00');

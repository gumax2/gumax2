<?php
declare(strict_types=1);
/*
 * Migração 002 — Central de Automações (3.1).
 * Apenas cria tabelas novas gmax3_ e insere configurações padrão SEGURAS:
 * automações pausadas, aprovação automática desligada, nenhuma credencial.
 * Não altera tabelas existentes. O "down" remove só as tabelas desta migração.
 */

return [
    'version' => '002',
    'description' => 'Central de Automações: tarefas agendadas, execuções, alertas, modelos de mensagem, limites de API',
    'tables' => ['gmax3_jobs', 'gmax3_job_runs', 'gmax3_alerts', 'gmax3_templates', 'gmax3_api_usage', 'gmax3_discoveries'],
    'up' => static function (PDO $pdo): array {
        $pdo->exec(<<<'SQL'
CREATE TABLE gmax3_jobs (
  job TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  enabled INTEGER NOT NULL DEFAULT 0,
  times TEXT NOT NULL,
  weekdays TEXT NOT NULL DEFAULT '1,2,3,4,5,6,7',
  params TEXT,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by TEXT
);
CREATE TABLE gmax3_job_runs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  job TEXT NOT NULL,
  slot_key TEXT NOT NULL,
  trigger TEXT NOT NULL CHECK (trigger IN ('agendado','manual')),
  status TEXT NOT NULL CHECK (status IN ('executando','concluido','parcial','falhou','ignorado')),
  started_at TEXT NOT NULL,
  finished_at TEXT,
  found INTEGER NOT NULL DEFAULT 0,
  created INTEGER NOT NULL DEFAULT 0,
  updated INTEGER NOT NULL DEFAULT 0,
  approved INTEGER NOT NULL DEFAULT 0,
  blocked INTEGER NOT NULL DEFAULT 0,
  errors INTEGER NOT NULL DEFAULT 0,
  detail TEXT,
  actor TEXT,
  UNIQUE (job, slot_key)
);
CREATE INDEX gmax3_job_runs_started ON gmax3_job_runs(started_at);
CREATE TABLE gmax3_alerts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  catalog_id INTEGER,
  kind TEXT NOT NULL,
  message TEXT NOT NULL,
  dedup_key TEXT NOT NULL,
  created_at TEXT NOT NULL,
  last_seen_at TEXT NOT NULL,
  resolved_at TEXT,
  resolved_by TEXT
);
CREATE UNIQUE INDEX gmax3_alerts_open ON gmax3_alerts(dedup_key) WHERE resolved_at IS NULL;
CREATE TABLE gmax3_templates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  marketplace TEXT NOT NULL DEFAULT '*',
  category TEXT NOT NULL DEFAULT '*',
  body TEXT NOT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (marketplace, category)
);
CREATE TABLE gmax3_api_usage (
  connector TEXT NOT NULL,
  day TEXT NOT NULL,
  requests INTEGER NOT NULL DEFAULT 0,
  failures INTEGER NOT NULL DEFAULT 0,
  last_request_at TEXT,
  last_error TEXT,
  PRIMARY KEY (connector, day)
);
CREATE TABLE gmax3_discoveries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  run_id INTEGER,
  marketplace TEXT NOT NULL,
  external_id TEXT NOT NULL,
  query TEXT,
  catalog_id INTEGER,
  outcome TEXT NOT NULL,
  seen_at TEXT NOT NULL
);
CREATE INDEX gmax3_discoveries_ext ON gmax3_discoveries(marketplace, external_id);
SQL);
        $jobs = [
            ['descoberta', 'Descoberta de novas ofertas', '07:00', '1,2,3,4,5,6,7', '{"limite_por_termo":20,"limite_total":60}'],
            ['revisao_precos', 'Revisão de preços e disponibilidade', '12:00,18:00', '1,2,3,4,5,6,7', '{"limite":60}'],
            ['selecao', 'Seleção das melhores ofertas para a fila', '18:30', '1,2,3,4,5,6,7', '{"pontuacao_minima":55,"confianca_minima":60}'],
            ['analise_semanal', 'Análise aprofundada (até 100 produtos)', '07:30', '1', '{"limite":100}'],
            ['revisao_vencidas', 'Revisão de ofertas vencidas e renovação', '07:30', '5', '{"dias_vencimento":7}'],
            ['saude_catalogo', 'Saúde do catálogo: duplicidades, links e alertas', '06:30', '1,2,3,4,5,6,7', '{}'],
        ];
        $st = $pdo->prepare('INSERT INTO gmax3_jobs(job,name,enabled,times,weekdays,params,updated_by) VALUES(?,?,0,?,?,?,?)');
        foreach ($jobs as [$job, $name, $times, $days, $params]) {
            $st->execute([$job, $name, $times, $days, $params, 'migracao_002']);
        }
        $defaults = [
            'automacoes_pausadas' => '1',
            'aprovacao_automatica' => '0',
            'limite_diario_telegram_privado' => '6',
            'termos_descoberta_mercadolivre' => '',
            'termos_descoberta_shopee' => '',
            'api_intervalo_ms' => '1100',
            'api_limite_diario' => '500',
            'faixa_preco_conversao' => '20-300',
        ];
        $st = $pdo->prepare('INSERT OR IGNORE INTO gmax3_settings(key,value,updated_by) VALUES(?,?,?)');
        foreach ($defaults as $k => $v) {
            $st->execute([$k, $v, 'migracao_002']);
        }
        $pdo->prepare("INSERT INTO gmax3_templates(marketplace,category,body) VALUES('*','*',?)")->execute([\Gmax\Message::DEFAULT_TEMPLATE]);
        return ['tarefas' => count($jobs), 'automacoes' => 'pausadas', 'aprovacao_automatica' => 'desligada'];
    },
];

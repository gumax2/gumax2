<?php
declare(strict_types=1);

namespace Gmax;

use PDO;
use RuntimeException;

/**
 * Política de retenção (3.2.1). NADA é apagado automaticamente: nenhuma tarefa, página ou Cron chama execute().
 *
 * - Elimináveis (somente logs operacionais detalhados, com mais de 90 dias): detalhe por produto das
 *   execuções (gmax3_job_run_items) e registro de descobertas (gmax3_discoveries).
 * - Protegidos (nunca apagados por esta política): auditoria, publicações e fila, reconciliações, histórico
 *   de preços, catálogo, importações (decisões administrativas), alertas, resumo das execuções,
 *   candidatos, AutoLab, uso de API, tabelas antigas e o próprio registro de retenção.
 * - Fluxo: simulate() grava o conjunto EXATO (critério + lista de IDs em arquivo) → execute() só pela CLI,
 *   com frase, simulação de até 24 h e idêntica ao estado atual, backup verificado, teste de recuperação
 *   no backup e exportação verificada ANTES da exclusão, tudo numa transação (o gatilho da 005 só permite
 *   apagar dentro dela).
 */
final class Retention
{
    public const PHRASE = 'EXCLUIR_REGISTROS_OPERACIONAIS_SIMULADOS';
    public const MAX_SIMULATION_AGE_H = 24;
    public const POLICY = [
        'gmax3_job_run_items' => ['dias' => 90, 'coluna' => 'created_at', 'descricao' => 'Detalhe por produto das execuções (log operacional)'],
        'gmax3_discoveries' => ['dias' => 90, 'coluna' => 'seen_at', 'descricao' => 'Registro de descobertas (log operacional)'],
    ];
    public const PROTECTED = [
        'gmax3_audit' => ['at', 'Auditoria (retenção mínima de 365 dias; não excluída nesta versão)'],
        'gmax3_pub_queue' => ['created_at', 'Publicações, envios e reconciliações'],
        'gmax3_price_history' => ['recorded_at', 'Histórico comercial de preços'],
        'gmax3_catalog' => ['created_at', 'Catálogo de ofertas'],
        'gmax3_import_batches' => ['created_at', 'Importações (decisões administrativas)'],
        'gmax3_alerts' => ['created_at', 'Alertas e suas resoluções'],
        'gmax3_job_runs' => ['started_at', 'Resumo das execuções (prestação de contas)'],
        'gmax3_autolab_messages' => ['created_at', 'Mensagens de homologação do AutoLab'],
        'gmax3_ml_candidates' => ['first_seen_at', 'Candidatos do Mercado Livre'],
    ];
    /** Só para testes: chamado dentro da transação, depois das exclusões e antes do COMMIT. */
    public static $beforeCommit = null;

    public static function available(PDO $pdo): bool
    {
        return Db::tableExists($pdo, 'gmax3_retention_runs');
    }

    public static function dir(): string
    {
        $d = Config::secretsDir() . '/gmax-retencao';
        if (!is_dir($d) && !@mkdir($d, 0700, true) && !is_dir($d)) {
            throw new RuntimeException('Não foi possível criar a pasta privada gmax-retencao');
        }
        return $d;
    }

    /** Data de corte calculada pelo próprio SQLite (mesmo relógio do gatilho da 005). */
    private static function cutoff(PDO $pdo, int $days): string
    {
        return (string)$pdo->query("SELECT datetime('now', '-$days days')")->fetchColumn();
    }

    /** @return array<string,array> seleção por tabela: corte, maior ID, quantidade e intervalo de datas */
    private static function selection(PDO $pdo): array
    {
        $out = [];
        foreach (self::POLICY as $t => $p) {
            if (!Db::tableExists($pdo, $t)) {
                continue;
            }
            $cut = self::cutoff($pdo, $p['dias']);
            $col = $p['coluna'];
            $r = Db::one($pdo, "SELECT COUNT(*) n, MAX(id) max_id, MIN(id) min_id, MIN($col) primeira, MAX($col) ultima FROM $t WHERE $col < ?", [$cut]);
            $out[$t] = ['corte' => $cut, 'coluna' => $col, 'dias' => $p['dias'], 'descricao' => $p['descricao'], 'quantidade' => (int)$r['n'],
                'max_id' => $r['max_id'] !== null ? (int)$r['max_id'] : 0, 'min_id' => $r['min_id'] !== null ? (int)$r['min_id'] : 0,
                'primeira' => $r['primeira'], 'ultima' => $r['ultima'], 'total_na_tabela' => (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn()];
        }
        return $out;
    }

    private static function where(array $s): array
    {
        return ["id BETWEEN ? AND ? AND {$s['coluna']} < ?", [$s['min_id'], $s['max_id'], $s['corte']]];
    }

    /** Simulação: grava exatamente o que seria eliminado (critério e lista completa de IDs). Não apaga nada. */
    public static function simulate(PDO $pdo, string $actor): array
    {
        if (!self::available($pdo)) {
            throw new RuntimeException('Migração 005 pendente');
        }
        $sel = self::selection($pdo);
        $protected = [];
        foreach (self::PROTECTED as $t => [$col, $desc]) {
            if (Db::tableExists($pdo, $t)) {
                $protected[$t] = ['descricao' => $desc, 'linhas' => (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(),
                    'com_mais_de_365_dias' => (int)Db::value($pdo, "SELECT COUNT(*) FROM $t WHERE $col < ?", [self::cutoff($pdo, 365)]), 'acao' => 'preservado'];
            }
        }
        $backups = Backup::list();
        Db::run($pdo, "INSERT INTO gmax3_retention_runs(kind,status,created_at,actor,policy) VALUES('simulacao','simulado',?,?,?)", [
            Time::nowSql(), $actor, json_encode(self::POLICY, JSON_UNESCAPED_UNICODE),
        ]);
        $id = (int)$pdo->lastInsertId();
        // Lista completa (tabela, id, data) num arquivo privado: é exatamente o que execute() apagaria.
        $file = self::dir() . "/simulacao-$id.csv.gz";
        $gz = gzopen($file, 'wb6');
        gzwrite($gz, "tabela,id,data\n");
        foreach ($sel as $t => $s) {
            if ($s['quantidade'] === 0) {
                continue;
            }
            [$w, $a] = self::where($s);
            $st = $pdo->prepare("SELECT id, {$s['coluna']} d FROM $t WHERE $w ORDER BY id");
            $st->execute($a);
            while ($r = $st->fetch(PDO::FETCH_NUM)) {
                gzwrite($gz, "$t,{$r[0]},{$r[1]}\n");
            }
        }
        gzclose($gz);
        @chmod($file, 0600);
        $result = ['eliminaveis' => $sel, 'total_eliminavel' => array_sum(array_column($sel, 'quantidade')), 'protegidos' => $protected,
            'backups' => ['quantidade' => count($backups), 'bytes' => array_sum(array_column($backups, 'bytes')), 'acao' => 'nenhum backup é apagado nesta versão'],
            'lista' => basename($file), 'lista_sha256' => hash_file('sha256', $file)];
        Db::run($pdo, 'UPDATE gmax3_retention_runs SET result=?, finished_at=?, export_file=?, export_sha256=? WHERE id=?', [
            json_encode($result, JSON_UNESCAPED_UNICODE), Time::nowSql(), basename($file), $result['lista_sha256'], $id,
        ]);
        Audit::log($pdo, $actor, 'retencao_simulada', 'retencao', $id, ['total_eliminavel' => $result['total_eliminavel']]);
        return ['simulacao' => $id] + $result;
    }

    public static function last(PDO $pdo): ?array
    {
        if (!self::available($pdo)) {
            return null;
        }
        $r = Db::one($pdo, "SELECT * FROM gmax3_retention_runs WHERE kind='simulacao' ORDER BY id DESC LIMIT 1");
        if ($r) {
            $r['result'] = json_decode((string)$r['result'], true);
        }
        return $r;
    }

    /**
     * Execução (só CLI). Ordem: conferências → backup verificado → teste de recuperação no backup →
     * exportação verificada → exclusão numa única transação (com o registro "executando" dentro dela).
     */
    public static function execute(PDO $pdo, int $simId, string $phrase, string $actor): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('A execução da retenção só é permitida pela linha de comando');
        }
        if (!hash_equals(self::PHRASE, $phrase)) {
            throw new RuntimeException('Frase de confirmação incorreta: nada foi apagado');
        }
        $sim = Db::one($pdo, "SELECT * FROM gmax3_retention_runs WHERE id=? AND kind='simulacao'", [$simId]) ?? throw new RuntimeException('Simulação não encontrada');
        if (Db::value($pdo, "SELECT 1 FROM gmax3_retention_runs WHERE kind='execucao' AND simulation_id=? AND status='executado'", [$simId])) {
            throw new RuntimeException('Esta simulação já foi executada');
        }
        if (Time::hoursSince((string)$sim['created_at']) > self::MAX_SIMULATION_AGE_H) {
            throw new RuntimeException('Simulação com mais de 24 h: simule de novo e confira o resultado');
        }
        $planned = json_decode((string)$sim['result'], true)['eliminaveis'] ?? [];
        // O conjunto atual tem de ser idêntico ao simulado (mesmo critério, mesma quantidade).
        foreach ($planned as $t => $s) {
            [$w, $a] = self::where($s);
            $n = $s['quantidade'] > 0 ? (int)Db::value($pdo, "SELECT COUNT(*) FROM $t WHERE $w", $a) : 0;
            if ($n !== (int)$s['quantidade']) {
                throw new RuntimeException("$t: a simulação previa {$s['quantidade']} linha(s) e hoje há $n. Simule de novo.");
            }
        }
        $total = array_sum(array_map(static fn($s) => (int)$s['quantidade'], $planned));
        if ($total === 0) {
            return ['execucao' => null, 'apagadas' => 0, 'detalhe' => 'Nada a eliminar segundo a simulação'];
        }
        // 1. Backup verificado (integrity_check) + 2. teste de recuperação: o backup contém exatamente as linhas.
        $backup = Backup::create($pdo, 'antes-retencao-' . $simId);
        $b = new PDO('sqlite:' . $backup['file'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach ($planned as $t => $s) {
            if ($s['quantidade'] > 0) {
                [$w, $a] = self::where($s);
                $st = $b->prepare("SELECT COUNT(*) FROM $t WHERE $w");
                $st->execute($a);
                if ((int)$st->fetchColumn() !== (int)$s['quantidade']) {
                    throw new RuntimeException("Teste de recuperação falhou: o backup não contém as linhas de $t. Nada foi apagado.");
                }
            }
        }
        $b = null;
        // 3. Exportação completa (JSON por linha, gzip) e verificação por releitura.
        $export = self::dir() . "/execucao-sim$simId-" . gmdate('Ymd-His') . '.jsonl.gz';
        $gz = gzopen($export, 'wb6');
        $written = [];
        foreach ($planned as $t => $s) {
            $written[$t] = 0;
            if ($s['quantidade'] > 0) {
                [$w, $a] = self::where($s);
                $st = $pdo->prepare("SELECT * FROM $t WHERE $w ORDER BY id");
                $st->execute($a);
                while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                    gzwrite($gz, json_encode(['tabela' => $t, 'linha' => $r], JSON_UNESCAPED_UNICODE) . "\n");
                    $written[$t]++;
                }
            }
        }
        gzclose($gz);
        @chmod($export, 0600);
        $read = [];
        $gz = gzopen($export, 'rb');
        while (($line = gzgets($gz)) !== false) {
            $j = json_decode($line, true);
            if (!is_array($j) || !isset($j['tabela'], $j['linha']['id'])) {
                gzclose($gz);
                throw new RuntimeException('Exportação ilegível: nada foi apagado');
            }
            $read[$j['tabela']] = ($read[$j['tabela']] ?? 0) + 1;
        }
        gzclose($gz);
        foreach ($planned as $t => $s) {
            if ((int)($read[$t] ?? 0) !== (int)$s['quantidade'] || $written[$t] !== (int)$s['quantidade']) {
                throw new RuntimeException("Exportação incompleta em $t: nada foi apagado");
            }
        }
        $sha = hash_file('sha256', $export);
        // 4. Exclusão: o registro "executando" (que libera o gatilho) existe só dentro desta transação.
        $deleted = Db::immediate($pdo, static function (PDO $pdo) use ($planned, $simId, $actor, $backup, $export, $sha) {
            Db::run($pdo, "INSERT INTO gmax3_retention_runs(kind,status,simulation_id,created_at,actor,policy,backup_file,export_file,export_sha256) VALUES('execucao','executando',?,?,?,?,?,?,?)", [
                $simId, Time::nowSql(), $actor, json_encode(self::POLICY, JSON_UNESCAPED_UNICODE), basename($backup['file']), basename($export), $sha,
            ]);
            $runId = (int)$pdo->lastInsertId();
            $out = [];
            foreach ($planned as $t => $s) {
                if ($s['quantidade'] > 0) {
                    [$w, $a] = self::where($s);
                    $n = Db::run($pdo, "DELETE FROM $t WHERE $w", $a);
                    if ($n !== (int)$s['quantidade']) {
                        throw new RuntimeException("$t: esperado apagar {$s['quantidade']}, seriam $n. Transação desfeita.");
                    }
                    $out[$t] = $n;
                }
            }
            if (Config::testing() && self::$beforeCommit) {
                (self::$beforeCommit)();
            }
            Db::run($pdo, "UPDATE gmax3_retention_runs SET status='executado', finished_at=?, result=? WHERE id=?", [Time::nowSql(), json_encode(['apagadas' => $out], JSON_UNESCAPED_UNICODE), $runId]);
            return ['execucao' => $runId, 'apagadas' => $out];
        });
        Audit::log($pdo, $actor, 'retencao_executada', 'retencao', $deleted['execucao'], ['simulacao' => $simId, 'apagadas' => $deleted['apagadas'], 'backup' => basename($backup['file']), 'exportacao' => basename($export)]);
        return $deleted + ['backup' => basename($backup['file']), 'exportacao' => basename($export), 'exportacao_sha256' => $sha,
            'observacao' => 'O espaço só é devolvido ao disco num VACUUM; não foi executado (rode em horário calmo, se quiser).'];
    }
}

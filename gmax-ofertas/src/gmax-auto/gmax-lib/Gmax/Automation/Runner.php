<?php
declare(strict_types=1);

namespace Gmax\Automation;

use Gmax\Audit;
use Gmax\Config;
use Gmax\Db;
use Gmax\Queue;
use Gmax\Settings;
use Gmax\Time;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Executor das automações para hospedagem compartilhada:
 * - acionado pelo Cron do cPanel (a cada 15 min) ou pelo botão de execução manual;
 * - processo curto, com prazo por execução;
 * - trava de arquivo (flock) impede duas execuções simultâneas;
 * - cada horário agendado só executa uma vez (UNIQUE job+slot), mesmo com Cron repetido.
 */
final class Runner
{
    /** 3.2.0: 100 s por tarefa (antes 45 s: a revisão cabia em ~30 produtos por execução). */
    public const TIME_BUDGET = 100.0;
    /** Tarefas longas que continuam nos ciclos seguintes do Cron quando o prazo acaba. */
    public const CONTINUABLE = ['revisao_precos', 'analise_semanal'];
    /** No máximo 6 continuações (≈ 90 min de ciclos de 15 min) por horário agendado. */
    public const MAX_CONTINUATIONS = 6;
    private const CONT_KEY = 'automacoes_continuacao';
    /** Só nos testes automatizados (GMAX_TESTING=1): prazo curto para simular tarefas longas. */
    public static ?float $budgetForTests = null;

    public static function budget(): float
    {
        return Config::testing() && self::$budgetForTests !== null ? self::$budgetForTests : self::TIME_BUDGET;
    }
    /** Orçamento total de uma chamada do Cron; uma tarefa só começa se sobrar pelo menos MIN_START. */
    public const CALL_BUDGET = 150.0;
    public const MIN_START = 30.0;
    public const STALE_MINUTES = 15;

    public static function lockFile(): string
    {
        return Config::secretsDir() . '/gmax-automacoes.mutex';
    }

    /** @return list<array> tarefas cadastradas */
    public static function jobs(PDO $pdo): array
    {
        return Db::all($pdo, 'SELECT * FROM gmax3_jobs ORDER BY rowid');
    }

    /** Executa todas as tarefas vencidas. */
    public static function runDue(PDO $pdo, string $actor = 'cron'): array
    {
        if (Settings::get($pdo, 'automacoes_pausadas', '1') !== '0') {
            Queue::heartbeat($pdo, 'automacoes', 'pausadas');
            return ['resultado' => 'automacoes_pausadas'];
        }
        return self::locked($pdo, static function () use ($pdo, $actor) {
            Queue::heartbeat($pdo, 'automacoes', 'ativo');
            self::closeStale($pdo);
            $out = [];
            $callEnd = microtime(true) + self::CALL_BUDGET;
            foreach (self::jobs($pdo) as $job) {
                if (!(int)$job['enabled']) {
                    continue;
                }
                // Continuação de uma execução que ficou com itens pendentes por falta de tempo.
                if (($cont = self::continuation($pdo, $job['job'])) !== null) {
                    if ($callEnd - microtime(true) < self::MIN_START) {
                        $out[] = ['job' => $job['job'], 'slot' => $cont['slot'], 'resultado' => 'adiado_proxima_chamada'];
                        continue;
                    }
                    $out[] = self::execute($pdo, $job, $cont['slot'] . ' #cont' . ($cont['rodada'] + 1), 'agendado', $actor, min($callEnd, microtime(true) + self::budget()), $cont);
                }
                foreach (Schedule::dueSlots($pdo, $job) as $slot) {
                    if ($callEnd - microtime(true) < self::MIN_START) {
                        // Sem registrar a execução: o próximo Cron pega este horário (dentro da janela de atraso).
                        $out[] = ['job' => $job['job'], 'slot' => $slot, 'resultado' => 'adiado_proxima_chamada'];
                        continue;
                    }
                    $out[] = self::execute($pdo, $job, $slot, 'agendado', $actor, min($callEnd, microtime(true) + self::budget()));
                }
            }
            return ['resultado' => 'ok', 'execucoes' => $out];
        });
    }

    /** Execução manual controlada (não depende da pausa geral: nenhuma tarefa envia mensagens). */
    public static function runManual(PDO $pdo, string $jobKey, string $actor): array
    {
        $job = Db::one($pdo, 'SELECT * FROM gmax3_jobs WHERE job=?', [$jobKey]) ?? throw new RuntimeException('Tarefa não encontrada');
        $budget = self::manualBudget();
        return self::locked($pdo, static function () use ($pdo, $job, $actor, $budget) {
            self::closeStale($pdo);
            $slot = 'manual ' . Time::now()->format('Y-m-d H:i:s') . ' ' . bin2hex(random_bytes(3));
            return self::execute($pdo, $job, $slot, 'manual', $actor, microtime(true) + $budget);
        });
    }

    /**
     * Prazo seguro para a execução manual (pelo navegador): tenta ampliar o limite do PHP; se a
     * hospedagem não permitir, usa o limite real menos uma folga, para a execução terminar e
     * registrar o resultado em vez de ser interrompida pelo servidor no meio.
     */
    public static function manualBudget(): float
    {
        if (PHP_SAPI !== 'cli') {
            @ignore_user_abort(true); // fechar o navegador não interrompe a gravação do resultado
            @set_time_limit((int)self::TIME_BUDGET + 60);
        }
        $max = (int)ini_get('max_execution_time');
        return $max > 0 ? max(5.0, min(self::budget(), $max - 15.0)) : self::budget();
    }

    /** @return ?array{slot:string,rodada:int,desde:int,feitos:list<int>} */
    private static function continuation(PDO $pdo, string $job): ?array
    {
        $all = json_decode(Settings::get($pdo, self::CONT_KEY, '{}'), true);
        $c = is_array($all) ? ($all[$job] ?? null) : null;
        if (!is_array($c) || (int)$c['rodada'] >= self::MAX_CONTINUATIONS || time() - (int)$c['desde'] > 3 * 3600) {
            if ($c !== null) {
                self::setContinuation($pdo, $job, null);
            }
            return null;
        }
        $feitos = is_array($c['feitos'] ?? null) ? array_map('intval', $c['feitos']) : [];
        return ['slot' => (string)$c['slot'], 'rodada' => (int)$c['rodada'], 'desde' => (int)$c['desde'], 'feitos' => $feitos];
    }

    private static function setContinuation(PDO $pdo, string $job, ?array $c): void
    {
        $all = json_decode(Settings::get($pdo, self::CONT_KEY, '{}'), true);
        $all = is_array($all) ? $all : [];
        if ($c === null) {
            unset($all[$job]);
        } else {
            $all[$job] = $c;
        }
        Settings::store($pdo, self::CONT_KEY, json_encode($all), 'automacao');
    }

    /** @return array<string,array> continuações pendentes (para a Central de Automações) */
    public static function continuations(PDO $pdo): array
    {
        $all = json_decode(Settings::get($pdo, self::CONT_KEY, '{}'), true);
        return is_array($all) ? $all : [];
    }

    private static function locked(PDO $pdo, callable $fn): array
    {
        $fh = @fopen(self::lockFile(), 'c');
        if ($fh === false) {
            return ['resultado' => 'trava_indisponivel', 'detalhe' => 'Pasta privada sem permissão de escrita'];
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return ['resultado' => 'ja_em_execucao'];
        }
        try {
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Execuções interrompidas (processo morto pelo servidor) viram "falhou".
     * 3.2.1: só é chamado com a trava de execução obtida; toda execução nasce dentro dessa trava,
     * então qualquer "executando" encontrado aqui pertence a um processo que já morreu: é fechado
     * na hora (antes esperava 15 min), com alerta, e a continuação é agendada.
     */
    private static function closeStale(PDO $pdo): void
    {
        foreach (Db::all($pdo, "SELECT r.id, r.job, r.slot_key, j.name FROM gmax3_job_runs r LEFT JOIN gmax3_jobs j ON j.job=r.job WHERE r.status='executando'") as $run) {
            Db::run($pdo, "UPDATE gmax3_job_runs SET status='falhou', finished_at=?, detail=COALESCE(detail,'') || ' Interrompida (tempo esgotado no servidor).' WHERE id=? AND status='executando'", [Time::nowSql(), $run['id']]);
            Alerts::raise($pdo, 'falha_automacao', 'Tarefa ' . ($run['name'] ?? $run['job']) . ' foi interrompida pelo servidor (execução #' . $run['id'] . ')' . (in_array($run['job'], self::CONTINUABLE, true) ? ': retomada automática agendada.' : '.'));
            if (in_array($run['job'], self::CONTINUABLE, true)) {
                // Retoma do ponto em que parou: os produtos já consultados (registro detalhado da execução
                // interrompida e das rodadas anteriores) não são consultados de novo.
                $base = preg_replace('/ #cont\d+$/', '', (string)$run['slot_key']);
                $prev = self::continuations($pdo)[$run['job']] ?? null;
                $feitos = is_array($prev['feitos'] ?? null) ? array_map('intval', $prev['feitos']) : [];
                try {
                    $feitos = [...$feitos, ...array_map('intval', array_column(Db::all($pdo, "SELECT DISTINCT catalog_id FROM gmax3_job_run_items WHERE run_id=? AND catalog_id IS NOT NULL
                        AND outcome IN ('atualizado','validado','sem_alteracao','bloqueado')", [$run['id']]), 'catalog_id'))];
                } catch (\PDOException) {
                    // sem a migração 003 não há registro detalhado: a ordem por preço mais antigo continua valendo
                }
                self::setContinuation($pdo, $run['job'], ['slot' => $base, 'rodada' => is_array($prev) ? (int)$prev['rodada'] : 0,
                    'desde' => is_array($prev) ? (int)$prev['desde'] : time(), 'feitos' => array_values(array_unique($feitos))]);
            }
        }
    }

    private static function execute(PDO $pdo, array $job, string $slot, string $trigger, string $actor, float $deadline, ?array $cont = null): array
    {
        try {
            Db::run($pdo, 'INSERT INTO gmax3_job_runs(job,slot_key,trigger,status,started_at,actor) VALUES(?,?,?,?,?,?)', [$job['job'], $slot, $trigger, 'executando', Time::nowSql(), $actor]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                return ['job' => $job['job'], 'slot' => $slot, 'resultado' => 'ja_executada'];
            }
            throw $e;
        }
        $runId = (int)$pdo->lastInsertId();
        $params = json_decode((string)$job['params'], true) ?: [];
        unset($params['_ja_consultados']);
        if ($cont !== null) {
            $params['_ja_consultados'] = $cont['feitos'];
        }
        try {
            $c = Jobs::run($job['job'], $pdo, $params, $deadline, $runId, $actor);
            // Itens pendentes por falta de tempo: a execução NÃO é dada como concluída.
            $status = $c['errors'] > 0 || ($c['pending'] ?? 0) > 0 ? 'parcial' : 'concluido';
            if (in_array($job['job'], self::CONTINUABLE, true)) {
                $base = $cont['slot'] ?? preg_replace('/ #cont\d+$/', '', $slot);
                if (($c['pending'] ?? 0) > 0) {
                    $rodada = $cont ? $cont['rodada'] + 1 : 0;
                    if ($rodada < self::MAX_CONTINUATIONS) {
                        $feitos = array_values(array_unique([...($cont['feitos'] ?? []), ...($c['done_ids'] ?? [])]));
                        self::setContinuation($pdo, $job['job'], ['slot' => $base, 'rodada' => $rodada, 'desde' => $cont['desde'] ?? time(), 'feitos' => $feitos]);
                        $c['detail'][] = 'Continuação automática agendada para o próximo ciclo do Cron (' . ($rodada + 1) . '/' . self::MAX_CONTINUATIONS . ').';
                    } else {
                        self::setContinuation($pdo, $job['job'], null);
                        $c['detail'][] = 'Limite de continuações atingido: o restante fica para o próximo horário agendado.';
                        Alerts::raise($pdo, 'falha_automacao', 'Tarefa ' . $job['name'] . ': limite de continuações atingido com ' . $c['pending'] . ' item(ns) pendente(s).');
                    }
                } else {
                    self::setContinuation($pdo, $job['job'], null);
                }
            }
        } catch (Throwable $e) {
            $c = ['found' => 0, 'created' => 0, 'updated' => 0, 'approved' => 0, 'blocked' => 0, 'errors' => 1, 'pending' => 0, 'detail' => ['Erro: ' . get_class($e) . ' — ' . mb_substr($e->getMessage(), 0, 200)]];
            $status = 'falhou';
            Alerts::raise($pdo, 'falha_automacao', 'Tarefa ' . $job['name'] . ' falhou: ' . mb_substr($e->getMessage(), 0, 200));
        }
        try {
            RunLog::flush($pdo); // linhas informativas pendentes (se a tarefa terminou com erro)
        } catch (Throwable) {
        }
        Db::run($pdo, 'UPDATE gmax3_job_runs SET status=?, finished_at=?, found=?, created=?, updated=?, approved=?, blocked=?, errors=?, detail=? WHERE id=?', [
            $status, Time::nowSql(), $c['found'], $c['created'], $c['updated'], $c['approved'], $c['blocked'], $c['errors'],
            $c['detail'] ? mb_substr(implode("\n", array_slice($c['detail'], 0, 30)), 0, 4000) : null, $runId,
        ]);
        $summary = array_diff_key($c, ['detail' => 1, 'done_ids' => 1]);
        Audit::log($pdo, $actor, 'automacao_executada', 'job', $job['job'], ['execucao' => $runId, 'gatilho' => $trigger, 'estado' => $status] + $summary);
        return ['job' => $job['job'], 'slot' => $slot, 'resultado' => $status, 'execucao' => $runId] + $summary;
    }

    public static function setJob(PDO $pdo, string $jobKey, bool $enabled, string $times, string $weekdays, string $actor): void
    {
        Schedule::validate($times, $weekdays);
        [$t, $d] = Schedule::parse($times, $weekdays);
        $n = Db::run($pdo, 'UPDATE gmax3_jobs SET enabled=?, times=?, weekdays=?, updated_at=?, updated_by=? WHERE job=?', [$enabled ? 1 : 0, implode(',', $t), implode(',', $d), Time::nowSql(), $actor, $jobKey]);
        if ($n !== 1) {
            throw new RuntimeException('Tarefa não encontrada');
        }
        Audit::log($pdo, $actor, 'automacao_configurada', 'job', $jobKey, ['ativa' => $enabled, 'horarios' => $t, 'dias' => $d]);
    }
}

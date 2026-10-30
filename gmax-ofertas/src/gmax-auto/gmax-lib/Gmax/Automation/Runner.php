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
    public const TIME_BUDGET = 45.0;
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
            $deadline = microtime(true) + self::TIME_BUDGET;
            foreach (self::jobs($pdo) as $job) {
                if (!(int)$job['enabled']) {
                    continue;
                }
                foreach (Schedule::dueSlots($pdo, $job) as $slot) {
                    if (microtime(true) > $deadline) {
                        $out[] = ['job' => $job['job'], 'slot' => $slot, 'resultado' => 'adiado_prazo'];
                        break 2;
                    }
                    $out[] = self::execute($pdo, $job, $slot, 'agendado', $actor, $deadline);
                }
            }
            return ['resultado' => 'ok', 'execucoes' => $out];
        });
    }

    /** Execução manual controlada (não depende da pausa geral: nenhuma tarefa envia mensagens). */
    public static function runManual(PDO $pdo, string $jobKey, string $actor): array
    {
        $job = Db::one($pdo, 'SELECT * FROM gmax3_jobs WHERE job=?', [$jobKey]) ?? throw new RuntimeException('Tarefa não encontrada');
        return self::locked($pdo, static function () use ($pdo, $job, $actor) {
            self::closeStale($pdo);
            $slot = 'manual ' . Time::now()->format('Y-m-d H:i:s') . ' ' . bin2hex(random_bytes(3));
            return self::execute($pdo, $job, $slot, 'manual', $actor, microtime(true) + self::TIME_BUDGET);
        });
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

    /** Execuções interrompidas (processo morto pelo servidor) viram "falhou". */
    private static function closeStale(PDO $pdo): void
    {
        $limit = Time::now()->modify('-' . self::STALE_MINUTES . ' minutes')->format('Y-m-d H:i:s');
        Db::run($pdo, "UPDATE gmax3_job_runs SET status='falhou', finished_at=?, detail=COALESCE(detail,'') || ' Interrompida (tempo esgotado no servidor).' WHERE status='executando' AND started_at < ?", [Time::nowSql(), $limit]);
    }

    private static function execute(PDO $pdo, array $job, string $slot, string $trigger, string $actor, float $deadline): array
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
        try {
            $c = Jobs::run($job['job'], $pdo, $params, $deadline, $runId, $actor);
            $status = $c['errors'] > 0 ? 'parcial' : 'concluido';
        } catch (Throwable $e) {
            $c = ['found' => 0, 'created' => 0, 'updated' => 0, 'approved' => 0, 'blocked' => 0, 'errors' => 1, 'detail' => ['Erro: ' . get_class($e) . ' — ' . mb_substr($e->getMessage(), 0, 200)]];
            $status = 'falhou';
            Alerts::raise($pdo, 'falha_automacao', 'Tarefa ' . $job['name'] . ' falhou: ' . mb_substr($e->getMessage(), 0, 200));
        }
        Db::run($pdo, 'UPDATE gmax3_job_runs SET status=?, finished_at=?, found=?, created=?, updated=?, approved=?, blocked=?, errors=?, detail=? WHERE id=?', [
            $status, Time::nowSql(), $c['found'], $c['created'], $c['updated'], $c['approved'], $c['blocked'], $c['errors'],
            $c['detail'] ? mb_substr(implode("\n", array_slice($c['detail'], 0, 30)), 0, 4000) : null, $runId,
        ]);
        Audit::log($pdo, $actor, 'automacao_executada', 'job', $job['job'], ['execucao' => $runId, 'gatilho' => $trigger, 'estado' => $status] + array_diff_key($c, ['detail' => 1]));
        return ['job' => $job['job'], 'slot' => $slot, 'resultado' => $status, 'execucao' => $runId] + array_diff_key($c, ['detail' => 1]);
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

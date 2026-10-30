<?php
declare(strict_types=1);

namespace Gmax\Automation;

use DateTimeImmutable;
use DateTimeZone;
use Gmax\Config;
use Gmax\Db;
use Gmax\Time;
use PDO;
use RuntimeException;

/** Calendário das tarefas: horários HH:MM e dias da semana (1 = segunda … 7 = domingo), no fuso de São Paulo. */
final class Schedule
{
    /** Janela para executar um horário atrasado (ex.: Cron que rodou 20 min depois). Depois disso, o horário é perdido. */
    public const CATCH_UP_MINUTES = 120;
    public const DAY_NAMES = [1 => 'seg', 2 => 'ter', 3 => 'qua', 4 => 'qui', 5 => 'sex', 6 => 'sáb', 7 => 'dom'];

    /** @return array{0:list<string>,1:list<int>} */
    public static function parse(string $times, string $weekdays): array
    {
        $t = array_values(array_unique(array_filter(array_map('trim', explode(',', $times)))));
        $d = array_values(array_unique(array_map('intval', array_filter(array_map('trim', explode(',', $weekdays)), 'strlen'))));
        sort($t);
        sort($d);
        return [$t, $d];
    }

    public static function validate(string $times, string $weekdays): void
    {
        [$t, $d] = self::parse($times, $weekdays);
        if (!$t || count($t) > 12) {
            throw new RuntimeException('Informe de 1 a 12 horários');
        }
        foreach ($t as $x) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $x)) {
                throw new RuntimeException("Horário inválido: $x (use HH:MM)");
            }
        }
        if (!$d || array_diff($d, range(1, 7))) {
            throw new RuntimeException('Dias da semana inválidos (use 1 a 7, segunda = 1)');
        }
    }

    private static function local(?DateTimeImmutable $now): DateTimeImmutable
    {
        return ($now ?? Time::now())->setTimezone(new DateTimeZone(Config::TZ));
    }

    /** Horários vencidos e ainda não executados (dentro da janela de atraso). @return list<string> slot_key */
    public static function dueSlots(PDO $pdo, array $job, ?DateTimeImmutable $now = null): array
    {
        $now = self::local($now);
        [$times, $days] = self::parse($job['times'], $job['weekdays']);
        $due = [];
        foreach ([$now->modify('-1 day'), $now] as $day) {
            if (!in_array((int)$day->format('N'), $days, true)) {
                continue;
            }
            foreach ($times as $t) {
                [$h, $m] = array_map('intval', explode(':', $t));
                $slot = $day->setTime($h, $m);
                $late = ($now->getTimestamp() - $slot->getTimestamp()) / 60;
                if ($late >= 0 && $late <= self::CATCH_UP_MINUTES) {
                    $key = $slot->format('Y-m-d H:i');
                    if (Db::value($pdo, 'SELECT 1 FROM gmax3_job_runs WHERE job=? AND slot_key=?', [$job['job'], $key]) === null) {
                        $due[] = $key;
                    }
                }
            }
        }
        return $due;
    }

    public static function next(array $job, ?DateTimeImmutable $now = null): ?DateTimeImmutable
    {
        $now = self::local($now);
        [$times, $days] = self::parse($job['times'], $job['weekdays']);
        for ($i = 0; $i <= 7; $i++) {
            $day = $now->modify("+$i day");
            if (!in_array((int)$day->format('N'), $days, true)) {
                continue;
            }
            foreach ($times as $t) {
                [$h, $m] = array_map('intval', explode(':', $t));
                $slot = $day->setTime($h, $m);
                if ($slot > $now) {
                    return $slot;
                }
            }
        }
        return null;
    }
}

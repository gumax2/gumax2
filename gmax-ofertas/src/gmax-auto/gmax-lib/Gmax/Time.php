<?php
declare(strict_types=1);

namespace Gmax;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Datas gravadas em UTC (mesmo padrão do CURRENT_TIMESTAMP do SQLite); exibidas em America/Sao_Paulo. */
final class Time
{
    private static ?DateTimeImmutable $frozen = null;

    public static function freeze(?DateTimeImmutable $at): void
    {
        self::$frozen = $at;
    }

    public static function now(): DateTimeImmutable
    {
        return (self::$frozen ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
    }

    public static function nowSql(): string
    {
        return self::now()->format('Y-m-d H:i:s');
    }

    public static function parseUtc(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    public static function show(?string $utc, string $format = 'd/m/Y H:i'): string
    {
        $dt = self::parseUtc($utc);
        return $dt ? $dt->setTimezone(new DateTimeZone(Config::TZ))->format($format) : '—';
    }

    public static function hoursSince(?string $utc): ?float
    {
        $dt = self::parseUtc($utc);
        if (!$dt) {
            return null;
        }
        return (self::now()->getTimestamp() - $dt->getTimestamp()) / 3600;
    }

    /** Aceita AAAA-MM-DD ou DD/MM/AAAA (início da frase, ex.: "26/10/2026 (página indexada)"). */
    public static function parseDate(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) || preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $value, $n)) {
            [$y, $mo, $d] = isset($m[1]) ? [(int)$m[1], (int)$m[2], (int)$m[3]] : [(int)$n[3], (int)$n[2], (int)$n[1]];
            if (checkdate($mo, $d, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }
        return null;
    }
}

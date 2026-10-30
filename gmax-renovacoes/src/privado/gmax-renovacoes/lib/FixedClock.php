<?php
declare(strict_types=1);

namespace GmaxRenov;

/** Relógio fixo para testes e para a opção --agora da CLI (somente com 'permitir_relogio_teste'). */
final class FixedClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string $datetime, string $tz = 'America/Sao_Paulo')
    {
        $this->now = new \DateTimeImmutable($datetime, new \DateTimeZone($tz));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(string $datetime): void
    {
        $this->now = new \DateTimeImmutable($datetime, $this->now->getTimezone());
    }
}

<?php
declare(strict_types=1);

namespace GmaxRenov;

final class SystemClock implements Clock
{
    private \DateTimeZone $tz;

    public function __construct(string $tz = 'America/Sao_Paulo')
    {
        $this->tz = new \DateTimeZone($tz);
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->tz);
    }
}

<?php
declare(strict_types=1);

namespace GmaxRenov;

interface Clock
{
    public function now(): \DateTimeImmutable;
}

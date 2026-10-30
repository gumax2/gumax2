<?php
declare(strict_types=1);

namespace GmaxRenov\Provider;

interface Provider
{
    public function name(): string;

    /** true somente para provedores que fazem chamadas reais de rede. */
    public function isReal(): bool;

    public function send(OutboundMessage $m): SendResult;
}

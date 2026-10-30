<?php
declare(strict_types=1);

namespace GmaxRenov\Provider;

/**
 * Provedor simulado: NÃO faz nenhuma chamada de rede. Apenas devolve "aceito" com um ID fictício.
 * Opcionalmente segue um roteiro de resultados (usado nos testes).
 */
final class SimulatedProvider implements Provider
{
    /** @var array<int, SendResult|callable> */
    private array $script;
    public array $sent = [];
    private int $delayMs;

    public function __construct(array $script = [], int $delayMs = 0)
    {
        $this->script = $script;
        $this->delayMs = $delayMs;
    }

    public function name(): string
    {
        return 'simulado';
    }

    public function isReal(): bool
    {
        return false;
    }

    public function send(OutboundMessage $m): SendResult
    {
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }
        $this->sent[] = $m;
        if ($this->script) {
            $next = array_shift($this->script);
            return is_callable($next) ? $next($m) : $next;
        }
        return new SendResult(SendResult::ACCEPTED, 'sim.' . $m->ref);
    }
}

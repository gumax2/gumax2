<?php
declare(strict_types=1);

namespace GmaxRenov;

/** Configurações em memória (prévia de textos ainda não salvos). Somente leitura. */
final class ArraySettings extends Settings
{
    public function __construct(array $values)
    {
        $this->cache = $values + self::defaults();
    }

    public function all(): array
    {
        return $this->cache;
    }

    public function set(string $k, $v): void
    {
        throw new \LogicException('Configurações de prévia são somente leitura.');
    }

    public function refresh(): void
    {
    }

    public function seedDefaults(): void
    {
    }
}

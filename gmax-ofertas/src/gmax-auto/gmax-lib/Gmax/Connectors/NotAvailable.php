<?php
declare(strict_types=1);

namespace Gmax\Connectors;

/**
 * Marketplace preparado na arquitetura, mas sem integração autorizada configurada
 * (Amazon PA-API 5, AliExpress Affiliate API). Não faz requisições nem simula dados.
 * Para ativar: implementar o conector oficial e uma migração que inclua o marketplace no catálogo.
 */
final class NotAvailable implements Discovery, Connector
{
    public function __construct(private string $marketplace, private string $label, private string $reason)
    {
    }

    public function marketplace(): string
    {
        return $this->marketplace;
    }

    public function name(): string
    {
        return $this->label;
    }

    public function configured(): bool
    {
        return false;
    }

    public function status(): string
    {
        return $this->reason;
    }

    public function search(string $query, int $limit): array
    {
        return ['ok' => false, 'error' => $this->reason, 'items' => []];
    }

    public function fetch(array $catalogRow): array
    {
        return ['ok' => false, 'error' => $this->reason, 'data' => []];
    }
}

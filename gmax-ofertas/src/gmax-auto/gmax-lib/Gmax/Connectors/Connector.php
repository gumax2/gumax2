<?php
declare(strict_types=1);

namespace Gmax\Connectors;

/**
 * Conector de marketplace. Conectores apenas CONSULTAM dados oficiais e devolvem uma
 * proposta de atualização; nada é gravado no catálogo sem ação do administrador.
 */
interface Connector
{
    public function name(): string;

    /** Credenciais presentes e válidas? */
    public function configured(): bool;

    /** Situação legível para o painel. */
    public function status(): string;

    /**
     * Consulta dados de um produto do catálogo.
     * @return array{ok:bool,error:?string,data:array} data usa chaves do catálogo
     *   (price_promo_cents, price_original_cents, availability, commission_rate, title)
     */
    public function fetch(array $catalogRow): array;
}

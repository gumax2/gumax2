<?php
declare(strict_types=1);

namespace Gmax\Connectors;

/**
 * Descoberta de ofertas por fonte OFICIAL/autorizada. Cada item retornado usa as chaves do
 * catálogo e só contém campos que a fonte realmente informou (nada é estimado):
 * marketplace, external_id, title, original_url, affiliate_url (somente link oficial da API),
 * price_original_cents, price_promo_cents, availability, sales_public, sales_min, rating,
 * rating_count, seller_reputation, store_name, variation_pending, variation_note, notes.
 */
interface Discovery
{
    public function marketplace(): string;

    public function configured(): bool;

    /** @return array{ok:bool,error:?string,items:list<array>} */
    public function search(string $query, int $limit): array;
}

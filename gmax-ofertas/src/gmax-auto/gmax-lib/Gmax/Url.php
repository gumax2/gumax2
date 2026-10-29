<?php
declare(strict_types=1);

namespace Gmax;

/**
 * Validação de links por marketplace. Valida formato e domínio; não comprova que o
 * link comissiona nem que abre o produto correto (isso exige conferência humana).
 */
final class Url
{
    public const MARKETPLACES = ['shopee' => 'Shopee', 'mercadolivre' => 'Mercado Livre'];

    private const AFFILIATE_HOSTS = [
        'shopee' => ['s.shopee.com.br'],
        'mercadolivre' => ['meli.la'],
    ];
    private const PRODUCT_HOSTS = [
        'shopee' => ['shopee.com.br'],
        'mercadolivre' => ['produto.mercadolivre.com.br', 'www.mercadolivre.com.br', 'mercadolivre.com.br', 'articulo.mercadolivre.com.br'],
    ];
    private const LISTING_HOSTS = ['lista.mercadolivre.com.br'];

    /** @return array<string,mixed>|null partes da URL se for https limpa; null caso contrário */
    private static function parts(?string $url): ?array
    {
        if ($url === null) {
            return null;
        }
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\s\x00-\x1F\x7F<>"\'`]/', $url)) {
            return null;
        }
        $p = parse_url($url);
        if (!is_array($p) || strtolower($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['port'])) {
            return null;
        }
        $p['host'] = strtolower($p['host'] ?? '');
        $p['path'] = $p['path'] ?? '';
        return $p['host'] === '' ? null : $p;
    }

    public static function isAffiliate(string $marketplace, ?string $url): bool
    {
        $p = self::parts($url);
        return $p !== null
            && in_array($p['host'], self::AFFILIATE_HOSTS[$marketplace] ?? [], true)
            && preg_match('~^/[A-Za-z0-9_-]{4,64}/?$~', $p['path']) === 1;
    }

    public static function isListing(?string $url): bool
    {
        $p = self::parts($url);
        return $p !== null && in_array($p['host'], self::LISTING_HOSTS, true);
    }

    public static function isProduct(string $marketplace, ?string $url): bool
    {
        $p = self::parts($url);
        if ($p === null || !in_array($p['host'], self::PRODUCT_HOSTS[$marketplace] ?? [], true) || $p['path'] === '' || $p['path'] === '/') {
            return false;
        }
        if ($marketplace === 'mercadolivre') {
            return self::mlId($url) !== null;
        }
        return true;
    }

    /**
     * Identificador Mercado Livre extraído da URL de produto.
     * Anúncio: produto.mercadolivre.com.br/MLB-123-... => item:MLB123
     * Catálogo: www.mercadolivre.com.br/.../p/MLB123  => catalogo:MLB123
     */
    public static function mlId(?string $url): ?string
    {
        $p = self::parts($url);
        if ($p === null) {
            return null;
        }
        if (preg_match('~/p/(MLB\d{5,15})(?:[/?#]|$)~', $p['path'], $m)) {
            return 'catalogo:' . $m[1];
        }
        if (preg_match('~^/(MLB)-?(\d{5,15})(?:[-_/]|$)~', $p['path'], $m)) {
            return 'item:MLB' . $m[2];
        }
        return null;
    }

    /** Shopee: o ID do item precisa aparecer no fim do caminho (/123 ou -i.loja.123). */
    public static function shopeeMatchesItem(?string $url, string $itemId): bool
    {
        $p = self::parts($url);
        if ($p === null || !preg_match('/^\d{3,20}$/D', $itemId)) {
            return false;
        }
        return str_ends_with($p['path'], '/' . $itemId) || preg_match('/\.' . $itemId . '$/D', $p['path']) === 1;
    }

    /** ID do item Shopee em /product/LOJA/ITEM ou ...-i.LOJA.ITEM */
    public static function shopeeItemId(?string $url): ?string
    {
        $p = self::parts($url);
        if ($p === null) {
            return null;
        }
        if (preg_match('~/product/\d+/(\d{3,20})/?$~', $p['path'], $m) || preg_match('~-i\.\d+\.(\d{3,20})$~', $p['path'], $m)) {
            return $m[1];
        }
        return null;
    }

    public static function normalizeAffiliate(?string $url): ?string
    {
        $p = self::parts($url);
        return $p === null ? null : $p['host'] . rtrim($p['path'], '/');
    }
}

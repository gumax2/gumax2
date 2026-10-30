<?php
declare(strict_types=1);

namespace Gmax\Connectors;

use Gmax\Http;
use Gmax\RateLimiter;
use PDO;

/** Conectores por marketplace (com limite de requisições). Testes podem substituir via override(). */
final class Registry
{
    public const MARKETPLACES = [
        'mercadolivre' => 'Mercado Livre',
        'shopee' => 'Shopee',
        'amazon' => 'Amazon (preparado)',
        'aliexpress' => 'AliExpress (preparado)',
    ];

    /** @var array<string,object> */
    private static array $overrides = [];

    public static function override(string $marketplace, ?object $connector): void
    {
        if ($connector === null) {
            unset(self::$overrides[$marketplace]);
        } else {
            self::$overrides[$marketplace] = $connector;
        }
    }

    public static function resetOverrides(): void
    {
        self::$overrides = [];
    }

    /** @return (Discovery&Connector) */
    public static function get(string $marketplace, PDO $pdo): object
    {
        if (isset(self::$overrides[$marketplace])) {
            return self::$overrides[$marketplace];
        }
        return match ($marketplace) {
            'mercadolivre' => new MercadoLivre(new RateLimiter(new Http(), $pdo, 'mercadolivre')),
            'shopee' => new ShopeeAffiliate(new RateLimiter(new Http(), $pdo, 'shopee')),
            'amazon' => new NotAvailable('amazon', 'Amazon PA-API 5', 'Integração preparada; requer conta Associates aprovada e credenciais da PA-API'),
            'aliexpress' => new NotAvailable('aliexpress', 'AliExpress Affiliate API', 'Integração preparada; requer app aprovado no AliExpress Open Platform'),
            default => new NotAvailable($marketplace, $marketplace, 'Marketplace desconhecido'),
        };
    }
}

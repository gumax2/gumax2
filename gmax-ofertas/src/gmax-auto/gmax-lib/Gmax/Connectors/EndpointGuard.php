<?php
declare(strict_types=1);

namespace Gmax\Connectors;

use Gmax\Audit;
use Gmax\Automation\Alerts;
use Gmax\Settings;
use Gmax\Time;
use PDO;

/**
 * Suspensão automática de grupos de endpoints do Mercado Livre que respondem HTTP 403 repetidamente.
 * - Só as automações respeitam a suspensão; o diagnóstico supervisionado sempre consulta.
 * - Não desconecta o OAuth nem apaga tokens; um único alerta é aberto em vez de dezenas de erros.
 * - Reativação somente manual (Integrações → Reativar), registrada na auditoria.
 * Estado em gmax3_settings (chave ml_endpoints_estado), sem migração de banco.
 */
final class EndpointGuard
{
    public const GROUPS = [
        'search' => 'Pesquisa de anúncios (/sites/MLB/search)',
        'items' => 'Consulta de anúncios (/items)',
        'products' => 'Catálogo de produtos (/products)',
    ];
    private const KEY = 'ml_endpoints_estado';

    public static function group(string $path): ?string
    {
        return match (true) {
            str_starts_with($path, '/sites/') && str_contains($path, '/search') => 'search',
            str_starts_with($path, '/items') => 'items',
            str_starts_with($path, '/products') => 'products',
            default => null,
        };
    }

    public static function threshold(PDO $pdo): int
    {
        return max(2, min(20, Settings::int($pdo, 'ml_403_limite', 3)));
    }

    /** @return array<string,array{count:int,last_at:?string,last_msg:?string,suspended_at:?string}> */
    public static function state(PDO $pdo): array
    {
        $s = json_decode(Settings::get($pdo, self::KEY, '{}'), true);
        return is_array($s) ? array_intersect_key($s, self::GROUPS) : [];
    }

    private static function save(PDO $pdo, array $state, string $actor): void
    {
        Settings::store($pdo, self::KEY, json_encode($state, JSON_UNESCAPED_UNICODE), $actor);
    }

    public static function suspended(PDO $pdo, string $group): ?array
    {
        $g = self::state($pdo)[$group] ?? null;
        return $g && !empty($g['suspended_at']) ? $g : null;
    }

    /** @return array<string,array> grupos suspensos */
    public static function suspendedList(PDO $pdo): array
    {
        return array_filter(self::state($pdo), static fn($g) => !empty($g['suspended_at']));
    }

    /** Registra o resultado de uma consulta automática. 403 conta; 200 zera (se não suspenso); demais códigos não contam. */
    public static function enabled(PDO $pdo): bool
    {
        return Settings::get($pdo, 'ml_suspensao_403', '1') === '1';
    }

    /** Leitura e gravação do estado na mesma transação (evita que uma automação desfaça uma reativação). */
    private static function atomic(PDO $pdo, callable $fn): mixed
    {
        return $pdo->inTransaction() ? $fn() : \Gmax\Db::immediate($pdo, static fn() => $fn());
    }

    public static function record(PDO $pdo, string $group, int $status, ?string $apiMessage): void
    {
        if (!self::enabled($pdo)) {
            return;
        }
        self::atomic($pdo, static fn() => self::recordLocked($pdo, $group, $status, $apiMessage));
    }

    private static function recordLocked(PDO $pdo, string $group, int $status, ?string $apiMessage): void
    {
        $state = self::state($pdo);
        $g = $state[$group] ?? ['count' => 0, 'last_at' => null, 'last_msg' => null, 'suspended_at' => null];
        if ($status === 403) {
            $g['count']++;
            $g['last_at'] = Time::nowSql();
            $g['last_msg'] = $apiMessage !== null ? mb_substr($apiMessage, 0, 160) : null;
            if (empty($g['suspended_at']) && $g['count'] >= self::threshold($pdo)) {
                $g['suspended_at'] = Time::nowSql();
                Alerts::raise($pdo, 'integracao', 'Mercado Livre: ' . self::GROUPS[$group] . ' suspensa após ' . $g['count'] . ' respostas HTTP 403. A conta OAuth continua conectada; reative em Integrações quando a permissão for concedida.');
                Audit::log($pdo, 'automacao', 'ml_endpoint_suspenso', 'integracao', $group, ['respostas_403' => $g['count'], 'mensagem_api' => $g['last_msg']]);
            }
        } elseif ($status === 200 && empty($g['suspended_at'])) {
            $g['count'] = 0;
        } else {
            return;
        }
        $state[$group] = $g;
        self::save($pdo, $state, 'automacao');
    }

    /** Reativação supervisionada (administrador). */
    public static function reactivate(PDO $pdo, string $group, string $actor): bool
    {
        return self::atomic($pdo, static fn() => self::reactivateLocked($pdo, $group, $actor));
    }

    private static function reactivateLocked(PDO $pdo, string $group, string $actor): bool
    {
        $state = self::state($pdo);
        if (!isset(self::GROUPS[$group]) || empty($state[$group]['suspended_at'])) {
            return false;
        }
        $prev = $state[$group];
        unset($state[$group]);
        self::save($pdo, $state, $actor);
        Audit::log($pdo, $actor, 'ml_endpoint_reativado', 'integracao', $group, ['suspenso_desde' => $prev['suspended_at'], 'respostas_403' => $prev['count']]);
        return true;
    }
}

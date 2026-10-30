<?php
declare(strict_types=1);

namespace Gmax;

use PDO;
use RuntimeException;

/**
 * Limite de requisições por conector: intervalo mínimo entre chamadas e teto diário.
 * Também contabiliza falhas (exibidas na Central de Automações). Sem dados sensíveis.
 */
final class RateLimiter extends Http
{
    public function __construct(private Http $inner, private PDO $pdo, private string $connector)
    {
    }

    public static function day(): string
    {
        return Time::now()->setTimezone(new \DateTimeZone(Config::TZ))->format('Y-m-d');
    }

    private function acquire(): void
    {
        $day = self::day();
        $limit = Settings::int($this->pdo, 'api_limite_diario', 500);
        $row = Db::one($this->pdo, 'SELECT * FROM gmax3_api_usage WHERE connector=? AND day=?', [$this->connector, $day]);
        if ($row && (int)$row['requests'] >= $limit) {
            throw new RuntimeException("Limite diário de requisições atingido para {$this->connector} ($limit)");
        }
        $gapMs = Settings::int($this->pdo, 'api_intervalo_ms', 1100);
        if ($row && $row['last_request_at'] && $gapMs > 0) {
            $elapsed = (microtime(true) - (float)$row['last_request_at']) * 1000;
            if ($elapsed < $gapMs) {
                usleep((int)(($gapMs - $elapsed) * 1000));
            }
        }
        Db::run($this->pdo, 'INSERT INTO gmax3_api_usage(connector,day,requests,last_request_at) VALUES(?,?,1,?)
            ON CONFLICT(connector,day) DO UPDATE SET requests=requests+1, last_request_at=excluded.last_request_at', [$this->connector, $day, (string)microtime(true)]);
    }

    private function record(?string $error): void
    {
        if ($error !== null) {
            Db::run($this->pdo, 'UPDATE gmax3_api_usage SET failures=failures+1, last_error=? WHERE connector=? AND day=?', [mb_substr($error, 0, 200), $this->connector, self::day()]);
        }
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array
    {
        $this->acquire();
        try {
            $r = $this->inner->request($method, $url, $headers, $body, $timeout);
        } catch (\Throwable $e) {
            $this->record($e->getMessage());
            throw $e;
        }
        $this->record($r['error'] ?? (($r['status'] ?? 0) >= 400 ? 'HTTP ' . $r['status'] : null));
        return $r;
    }

    public function probe(string $url, int $timeout = 8): array
    {
        $this->acquire();
        $r = $this->inner->probe($url, $timeout);
        $this->record($r['error']);
        return $r;
    }
}

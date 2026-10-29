<?php
declare(strict_types=1);

namespace Gmax;

use RuntimeException;

/** Cliente HTTP mínimo e substituível (testes usam um cliente falso, sem rede). */
class Http
{
    /** @return array{status:int,body:?string,error:?string} */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array
    {
        if (Config::testing()) {
            throw new RuntimeException('Requisição de rede proibida em modo de teste');
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('~HTTP/\S+\s+(\d{3})~', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
        return ['status' => $status, 'body' => $raw === false ? null : $raw, 'error' => $raw === false ? 'Falha de rede ou timeout' : null];
    }
}

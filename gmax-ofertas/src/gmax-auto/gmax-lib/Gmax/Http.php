<?php
declare(strict_types=1);

namespace Gmax;

use RuntimeException;

/** Cliente HTTP mínimo e substituível (testes usam um cliente falso, sem rede). */
class Http
{
    /** Identificação explícita: sem isso o PHP envia a requisição SEM User-Agent (ini user_agent vazio). */
    public static function withUserAgent(array $headers): array
    {
        if (!array_filter($headers, static fn($h) => stripos((string)$h, 'User-Agent:') === 0)) {
            $headers[] = 'User-Agent: GMAX-Ofertas/' . (defined('GMAX_VERSION') ? GMAX_VERSION : '3') . ' (PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '; integracao-oficial)';
        }
        return $headers;
    }

    /** @return array{status:int,body:?string,error:?string} */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array
    {
        if (Config::testing()) {
            throw new RuntimeException('Requisição de rede proibida em modo de teste');
        }
        $headers = self::withUserAgent($headers);
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

    /**
     * Uma requisição GET SEM seguir redirecionamento, lendo só o cabeçalho (1 byte do corpo).
     * Usado para descobrir para onde um link curto aponta.
     * @return array{status:int,location:?string,error:?string}
     */
    public function probe(string $url, int $timeout = 8): array
    {
        if (Config::testing()) {
            throw new RuntimeException('Requisição de rede proibida em modo de teste');
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "User-Agent: GMAX-Ofertas-verificador/1.0\r\nAccept: text/html",
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 1,
        ]]);
        $raw = @file_get_contents($url, false, $ctx, 0, 1);
        $headers = $http_response_header ?? [];
        $status = 0;
        $location = null;
        if (isset($headers[0]) && preg_match('~HTTP/\S+\s+(\d{3})~', $headers[0], $m)) {
            $status = (int)$m[1];
        }
        foreach ($headers as $h) {
            if (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            }
        }
        return ['status' => $status, 'location' => $location, 'error' => $status === 0 ? 'Falha de rede ou timeout' : null];
    }
}

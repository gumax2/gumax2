<?php
declare(strict_types=1);
/* Dublês de teste: nenhum acesso de rede. */

final class FakeTransport implements Gmax\Transport
{
    public int $calls = 0;
    public array $sent = [];

    public function __construct(private Gmax\SendResult|Throwable $result)
    {
    }

    public function send(string $chatId, string $text): Gmax\SendResult
    {
        $this->calls++;
        $this->sent[] = [$chatId, $text];
        if ($this->result instanceof Throwable) {
            throw $this->result;
        }
        return $this->result;
    }
}

final class FakeHttp extends Gmax\Http
{
    public array $requests = [];

    public function __construct(private array $response)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        return $this->response;
    }
}

/** HTTP falso para o verificador de links: mapa URL => resposta de probe. */
final class FakeProbeHttp extends Gmax\Http
{
    public array $calls = [];

    public function __construct(private array $map)
    {
    }

    public function probe(string $url, int $timeout = 8): array
    {
        $this->calls[] = $url;
        return $this->map[$url] ?? ['status' => 0, 'location' => null, 'error' => 'Falha de rede ou timeout'];
    }
}

/** Conector falso de marketplace: busca e consulta programáveis, sem rede. */
final class FakeMarketConnector implements Gmax\Connectors\Discovery, Gmax\Connectors\Connector
{
    public int $searches = 0;
    public int $fetches = 0;

    public function __construct(public string $mp, public mixed $searchResult = ['ok' => true, 'error' => null, 'items' => []], public array $fetchMap = [], public bool $ok = true)
    {
    }

    public function marketplace(): string { return $this->mp; }
    public function name(): string { return 'Falso ' . $this->mp; }
    public function configured(): bool { return $this->ok; }
    public function status(): string { return $this->ok ? 'configurado (teste)' : 'não configurado (teste)'; }

    public function search(string $query, int $limit): array
    {
        $this->searches++;
        if ($this->searchResult instanceof Throwable) {
            throw $this->searchResult;
        }
        return $this->searchResult;
    }

    public function fetch(array $row): array
    {
        $this->fetches++;
        $r = $this->fetchMap[$row['external_id']] ?? ['ok' => false, 'error' => 'sem resposta', 'data' => []];
        if ($r instanceof Throwable) {
            throw $r;
        }
        return $r;
    }
}

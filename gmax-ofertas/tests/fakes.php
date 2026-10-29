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

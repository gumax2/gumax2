<?php
declare(strict_types=1);

namespace Gmax;

use RuntimeException;

/** Envio real ao Telegram. Recusa ser criado em modo de teste. Token nunca é registrado. */
final class TelegramTransport implements Transport
{
    private string $token;

    public function __construct(string $token)
    {
        if (Config::testing()) {
            throw new RuntimeException('Transporte real proibido em modo de teste');
        }
        if (!preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/D', $token)) {
            throw new RuntimeException('Token Telegram em formato inválido');
        }
        $this->token = $token;
    }

    /** Lê gmax-secrets/telegram.php e confere que o destino é o supergrupo autorizado. */
    public static function fromSecrets(): self
    {
        $file = Config::secretsDir() . '/telegram.php';
        if (!is_readable($file)) {
            throw new RuntimeException('Credenciais Telegram indisponíveis');
        }
        $cfg = require $file;
        if (!is_array($cfg) || (string)($cfg['chat_id'] ?? '') !== Config::PRIVATE_CHAT_ID) {
            throw new RuntimeException('Destino configurado diverge do supergrupo autorizado');
        }
        return new self((string)($cfg['token'] ?? ''));
    }

    public function send(string $chatId, string $text): SendResult
    {
        if ($chatId !== Config::PRIVATE_CHAT_ID) {
            return new SendResult(SendResult::FAILED, 0, null, 'Destino não autorizado (nenhuma requisição feita)');
        }
        $payload = http_build_query(['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => 'true']);
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 20,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents('https://api.telegram.org/bot' . $this->token . '/sendMessage', false, $ctx);
        $headers = $http_response_header ?? [];
        $http = 0;
        if (isset($headers[0]) && preg_match('~HTTP/\S+\s+(\d{3})~', $headers[0], $m)) {
            $http = (int)$m[1];
        }
        return self::classify($http, is_string($raw) ? $raw : null, $chatId);
    }

    /** Classificação conservadora da resposta (pública para ser testada sem rede). */
    public static function classify(int $http, ?string $raw, string $chatId): SendResult
    {
        $data = $raw !== null ? json_decode($raw, true) : null;
        $desc = is_array($data) ? mb_substr((string)($data['description'] ?? ''), 0, 180) : '';
        if ($http === 200 && is_array($data) && ($data['ok'] ?? false) === true) {
            $id = (int)($data['result']['message_id'] ?? 0);
            $chat = (string)($data['result']['chat']['id'] ?? '');
            if ($id > 0 && $chat === $chatId) {
                return new SendResult(SendResult::CONFIRMED, $http, $id, 'Telegram confirmou');
            }
            return new SendResult(SendResult::UNCERTAIN, $http, $id ?: null, 'Resposta ok sem confirmação completa do destino');
        }
        if ($http >= 400 && $http < 500 && is_array($data) && ($data['ok'] ?? null) === false) {
            return new SendResult(SendResult::FAILED, $http, null, 'Telegram recusou: ' . $desc);
        }
        return new SendResult(SendResult::UNCERTAIN, $http, null, $http === 0 ? 'Sem resposta (rede/timeout)' : 'Resposta inesperada: ' . $desc);
    }
}

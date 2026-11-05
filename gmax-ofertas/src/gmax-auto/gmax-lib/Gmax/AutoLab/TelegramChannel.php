<?php
declare(strict_types=1);

namespace Gmax\AutoLab;

use Gmax\{Config, SendResult, TelegramTransport};
use RuntimeException;

/**
 * Canal real do AutoLab. Três travas que nenhum teste ou configuração consegue afrouxar:
 * 1. o destino é SEMPRE Config::PRIVATE_CHAT_ID (outro chat_id = recusa sem requisição);
 * 2. o texto precisa começar com um rótulo de teste (AutoLab::LABELS), senão recusa sem requisição;
 * 3. em modo de teste (GMAX_TESTING) o transporte real nem é criado.
 * O token vem de gmax-secrets/telegram.php e nunca é registrado.
 */
final class TelegramChannel implements Channel
{
    private string $token;

    public function __construct()
    {
        if (Config::testing()) {
            throw new RuntimeException('Transporte real proibido em modo de teste');
        }
        $file = Config::secretsDir() . '/telegram.php';
        if (!is_readable($file)) {
            throw new RuntimeException('Credenciais Telegram indisponíveis');
        }
        $cfg = require $file;
        if (!is_array($cfg) || (string)($cfg['chat_id'] ?? '') !== Config::PRIVATE_CHAT_ID) {
            throw new RuntimeException('Destino configurado diverge do supergrupo autorizado');
        }
        $token = (string)($cfg['token'] ?? '');
        if (!preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/D', $token)) {
            throw new RuntimeException('Token Telegram em formato inválido');
        }
        $this->token = $token;
    }

    public function send(string $chatId, string $text): SendResult
    {
        if ($chatId !== Config::PRIVATE_CHAT_ID) {
            return new SendResult(SendResult::FAILED, 0, null, 'Destino não autorizado (nenhuma requisição feita)');
        }
        if (!AutoLab::labelled($text)) {
            return new SendResult(SendResult::FAILED, 0, null, 'Texto sem rótulo de teste (nenhuma requisição feita)');
        }
        [$http, $raw] = $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => 'true'], 20);
        return TelegramTransport::classify($http, $raw, $chatId);
    }

    public function probe(string $chatId): array
    {
        if ($chatId !== Config::PRIVATE_CHAT_ID) {
            return ['ok' => false, 'error' => 'Destino não autorizado (nenhuma requisição feita)'];
        }
        $get = function (string $method, array $p): array {
            [$http, $raw] = $this->call($method, $p, 10);
            $j = $raw !== null ? json_decode($raw, true) : null;
            return [$http, is_array($j) && ($j['ok'] ?? false) === true ? $j['result'] : null, is_array($j) ? (string)($j['description'] ?? '') : ''];
        };
        [$h, $me, $d] = $get('getMe', []);
        if (!is_array($me)) {
            return ['ok' => false, 'error' => 'getMe falhou' . ($h ? " (HTTP $h)" : ' (sem resposta)') . ($d ? ": $d" : ''), 'http' => $h];
        }
        [$h, $chat, $d] = $get('getChat', ['chat_id' => $chatId]);
        if (!is_array($chat)) {
            return ['ok' => false, 'error' => 'getChat falhou' . ($h ? " (HTTP $h)" : ' (sem resposta)') . ($d ? ": $d" : ''), 'http' => $h];
        }
        [$h, $count, $d] = $get('getChatMemberCount', ['chat_id' => $chatId]);
        [$h2, $member] = $get('getChatMember', ['chat_id' => $chatId, 'user_id' => (string)($me['id'] ?? '')]);
        return [
            'ok' => true, 'error' => null,
            'chat_id' => (string)($chat['id'] ?? ''),
            'type' => (string)($chat['type'] ?? ''),
            'title' => mb_substr((string)($chat['title'] ?? ''), 0, 120),
            'public_username' => !empty($chat['username']),
            'members' => is_int($count) ? $count : -1,
            'bot_status' => is_array($member) ? (string)($member['status'] ?? '') : '',
            'http' => 200,
        ];
    }

    /** @return array{0:int,1:?string} */
    private function call(string $method, array $params, int $timeout): array
    {
        if (!in_array($method, ['getMe', 'getChat', 'getChatMemberCount', 'getChatMember', 'sendMessage'], true)) {
            throw new RuntimeException('Método Telegram não permitido');
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: GMAX-AutoLab/3.2.1\r\n",
            'content' => http_build_query($params),
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents('https://api.telegram.org/bot' . $this->token . '/' . $method, false, $ctx);
        $http = 0;
        if (isset($http_response_header[0]) && preg_match('~HTTP/\S+\s+(\d{3})~', $http_response_header[0], $m)) {
            $http = (int)$m[1];
        }
        return [$http, is_string($raw) ? $raw : null];
    }
}

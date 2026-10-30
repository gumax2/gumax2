<?php
declare(strict_types=1);

namespace GmaxRenov\Provider;

use GmaxRenov\Log;

/**
 * WhatsApp Cloud API (Meta) — envio de mensagem de MODELO aprovado.
 * Mensagens iniciadas pela empresa fora da janela de 24h exigem modelo aprovado; por isso não enviamos texto livre.
 *
 * Credenciais vêm do config.php privado. O token nunca é registrado em log nem exposto ao navegador.
 */
final class MetaCloudProvider implements Provider
{
    private array $cfg;
    private Log $log;
    /** @var callable|null para testes: fn(string $url, string $json, array $headers): array{errno:int, http:int, body:string, sent:bool} */
    private $transport;

    public function __construct(array $cfg, Log $log, ?callable $transport = null)
    {
        $this->cfg = $cfg;
        $this->log = $log;
        $this->transport = $transport;
    }

    public function name(): string
    {
        return 'meta_cloud';
    }

    public function isReal(): bool
    {
        return true;
    }

    public function payload(OutboundMessage $m): array
    {
        $params = array_map(static fn($t) => ['type' => 'text', 'text' => $t], $m->params);
        $p = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $m->to,
            'type' => 'template',
            'template' => [
                'name' => $m->templateName,
                'language' => ['code' => $m->language],
                'components' => [['type' => 'body', 'parameters' => $params]],
            ],
        ];
        if (!empty($this->cfg['enviar_ref_callback'])) {
            // Referência devolvida nos webhooks de status (ajuda a esclarecer envios "incertos").
            $p['biz_opaque_callback_data'] = $m->ref;
        }
        return $p;
    }

    public function send(OutboundMessage $m): SendResult
    {
        if (!$m->templateName) {
            return new SendResult(SendResult::PERMANENT, null, 'sem_modelo', 'Modelo aprovado não configurado para esta etapa.');
        }
        $version = preg_replace('/[^v0-9.]/', '', (string)($this->cfg['graph_versao'] ?? 'v24.0'));
        $phoneId = preg_replace('/\D/', '', (string)($this->cfg['phone_number_id'] ?? ''));
        $url = "https://graph.facebook.com/$version/$phoneId/messages";
        $json = json_encode($this->payload($m), JSON_UNESCAPED_UNICODE);
        $headers = ['Authorization: Bearer ' . $this->cfg['access_token'], 'Content-Type: application/json'];

        $r = $this->transport ? ($this->transport)($url, $json, $headers) : $this->curl($url, $json, $headers);
        return self::classify($r);
    }

    /** @return array{errno:int, http:int, body:string, sent:bool} */
    private function curl(string $url, string $json, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(1, min(30, (int)($this->cfg['timeout_conexao'] ?? 5))),
            CURLOPT_TIMEOUT => max(5, min(120, (int)($this->cfg['timeout_total'] ?? 20))), // menor que o lease de 5 min
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // Se a conexão TLS foi estabelecida, a requisição pode ter chegado à Meta.
        $sent = (float)curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME) > 0;
        curl_close($ch);
        return ['errno' => $errno, 'http' => $http, 'body' => is_string($body) ? $body : '', 'sent' => $sent];
    }

    public static function classify(array $r): SendResult
    {
        $errno = (int)$r['errno'];
        if ($errno !== 0) {
            // Falha antes de conectar: com certeza não enviou.
            if (in_array($errno, [6, 7], true) || ($errno === 28 && empty($r['sent'])) || ($errno === 35 && empty($r['sent']))) {
                return new SendResult(SendResult::TRANSIENT, null, 'rede_' . $errno, 'Falha de conexão antes do envio.');
            }
            return new SendResult(SendResult::UNCERTAIN, null, 'rede_' . $errno, 'Sem resposta após o envio (timeout/conexão interrompida). Pode ter sido enviada.');
        }
        $http = (int)$r['http'];
        $data = json_decode((string)$r['body'], true);
        if ($http >= 200 && $http < 300) {
            $id = $data['messages'][0]['id'] ?? null;
            if ($id) {
                return new SendResult(SendResult::ACCEPTED, (string)$id);
            }
            return new SendResult(SendResult::UNCERTAIN, null, 'resposta_sem_id', 'Resposta de sucesso sem ID da mensagem.');
        }
        $err = $data['error'] ?? [];
        $code = (string)($err['code'] ?? $http);
        $detail = mb_substr(Log::redact((string)($err['message'] ?? ('HTTP ' . $http)) . (isset($err['error_data']['details']) ? ' — ' . $err['error_data']['details'] : '')), 0, 400);

        // Credencial/permissão: token expirado, sem permissão, conta bloqueada.
        if (in_array($code, ['190', '10', '200', '3', '131005', '131031'], true) || $http === 401) {
            return new SendResult(SendResult::PERMANENT, null, $code, $detail, true);
        }
        // Limites de taxa: a Meta rejeitou, então não foi enviado; pode tentar depois.
        if ($http === 429 || in_array($code, ['4', '80007', '130429', '131048', '131056'], true)) {
            return new SendResult(SendResult::TRANSIENT, null, $code, $detail);
        }
        if ($http >= 500) {
            return new SendResult(SendResult::UNCERTAIN, null, $code, 'Erro no provedor (HTTP ' . $http . '). Resultado incerto. ' . $detail);
        }
        return new SendResult(SendResult::PERMANENT, null, $code, $detail);
    }
}

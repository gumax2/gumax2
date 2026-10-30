<?php
declare(strict_types=1);

namespace GmaxRenov;

/**
 * Webhook da WhatsApp Cloud API.
 *  GET  — verificação (hub.mode=subscribe, hub.verify_token, hub.challenge)
 *  POST — status de mensagens e mensagens recebidas; assinatura X-Hub-Signature-256 (HMAC-SHA256 do corpo bruto com o App Secret)
 */
final class Webhook
{
    private App $app;
    private Db $db;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->db = $app->db();
    }

    private function meta(): array
    {
        return $this->app->config['provedor']['meta'] ?? [];
    }

    /** @return array{0:int,1:string} [código HTTP, corpo] */
    public function verify(array $query): array
    {
        $expected = (string)($this->meta()['verify_token'] ?? '');
        $mode = (string)($query['hub_mode'] ?? $query['hub.mode'] ?? '');
        $token = (string)($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
        $challenge = (string)($query['hub_challenge'] ?? $query['hub.challenge'] ?? '');
        if ($expected !== '' && $mode === 'subscribe' && hash_equals($expected, $token) && preg_match('/^[A-Za-z0-9_\-]{1,128}$/', $challenge)) {
            return [200, $challenge];
        }
        return [403, 'forbidden'];
    }

    public function validSignature(string $raw, ?string $header): bool
    {
        $secret = (string)($this->meta()['app_secret'] ?? '');
        if ($secret === '' || !$header || strncmp($header, 'sha256=', 7) !== 0) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $raw, $secret), substr($header, 7));
    }

    /** @return array{0:int,1:string} */
    public function handlePost(string $raw, ?string $signature): array
    {
        if (strlen($raw) > 1024 * 1024) {
            return [413, 'too large'];
        }
        if (!$this->validSignature($raw, $signature)) {
            $this->app->log()->error('Webhook com assinatura inválida rejeitado.');
            return [401, 'invalid signature'];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [400, 'bad json'];
        }
        $this->process($data);
        return [200, 'ok'];
    }

    public function process(array $data): array
    {
        $stats = ['status' => 0, 'duplicados' => 0, 'orfaos' => 0, 'descadastros' => 0];
        foreach ((array)($data['entry'] ?? []) as $entry) {
            foreach ((array)($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? '') !== 'messages') {
                    continue;
                }
                $v = $change['value'] ?? [];
                foreach ((array)($v['statuses'] ?? []) as $st) {
                    $this->onStatus($st, $stats);
                }
                foreach ((array)($v['messages'] ?? []) as $in) {
                    $this->onInbound($in, $stats);
                }
            }
        }
        return $stats;
    }

    private function onStatus(array $st, array &$stats): void
    {
        $pid = (string)($st['id'] ?? '');
        $status = Statuses::fromMeta((string)($st['status'] ?? ''));
        if ($pid === '' || $status === null) {
            return;
        }
        $ts = isset($st['timestamp']) && ctype_digit((string)$st['timestamp'])
            ? (new \DateTimeImmutable('@' . $st['timestamp']))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s')
            : null;
        $err = null;
        if (!empty($st['errors'][0])) {
            $e = $st['errors'][0];
            $err = mb_substr(($e['code'] ?? '') . ' ' . ($e['title'] ?? '') . (isset($e['error_data']['details']) ? ' — ' . $e['error_data']['details'] : ''), 0, 300);
        }
        $dedup = hash('sha256', 'st|' . $pid . '|' . $st['status'] . '|' . ($st['timestamp'] ?? '') . '|' . ($st['errors'][0]['code'] ?? ''));

        $msg = $this->db->one("SELECT id, provedor_msg_id FROM gr_mensagens WHERE provedor='meta_cloud' AND provedor_msg_id=?", [$pid]);
        if (!$msg && !empty($st['biz_opaque_callback_data'])) {
            $msg = $this->db->one('SELECT id, provedor_msg_id FROM gr_mensagens WHERE ref=?', [(string)$st['biz_opaque_callback_data']]);
            if ($msg && !$msg['provedor_msg_id']) {
                $this->db->exec('UPDATE gr_mensagens SET provedor_msg_id=? WHERE id=? AND provedor_msg_id IS NULL', [$pid, $msg['id']]);
            }
        }
        $engine = new Engine($this->app);
        if (!$msg) {
            // Pode chegar antes de gravarmos o ID; fica guardado e é aplicado depois.
            $detail = json_encode(['id' => $pid, 'status' => $status, 'erro' => $err, 'ts' => $ts], JSON_UNESCAPED_UNICODE);
            $engine->event(null, 'status_orfao', $status, $detail, $dedup, $ts) ? $stats['orfaos']++ : $stats['duplicados']++;
            return;
        }
        if (!$engine->event((int)$msg['id'], 'status', $status, 'Callback do provedor: ' . $status . ($err ? " ($err)" : ''), $dedup, $ts)) {
            $stats['duplicados']++;
            return;
        }
        $this->applyStatus((int)$msg['id'], $status, $err, $ts);
        $stats['status']++;
    }

    public function applyStatus(int $msgId, string $status, ?string $err, ?string $ts): void
    {
        for ($i = 0; $i < 3; $i++) {
            $m = $this->db->one('SELECT status FROM gr_mensagens WHERE id=?', [$msgId]);
            if (!$m) {
                return;
            }
            $next = Statuses::transition($m['status'], $status);
            if ($next === null) {
                return;
            }
            $extra = '';
            $params = [$next, $ts ?? $this->app->clock()->now()->format('Y-m-d H:i:s'), $this->app->clock()->now()->format('Y-m-d H:i:s')];
            if ($next === 'entregue') {
                $extra = ', entregue_em=COALESCE(entregue_em, ?)';
                $params[] = $ts;
            } elseif ($next === 'lido') {
                $extra = ', lido_em=COALESCE(lido_em, ?), entregue_em=COALESCE(entregue_em, ?)';
                $params[] = $ts;
                $params[] = $ts;
            } elseif ($next === 'falhou') {
                $extra = ", erro_codigo='callback', erro_detalhe=?";
                $params[] = $err ?? 'Falha informada pelo provedor.';
            }
            $params[] = $msgId;
            $params[] = $m['status'];
            // Atualização condicional: se outro callback mudou o status no meio, tenta de novo.
            if ($this->db->exec("UPDATE gr_mensagens SET status=?, status_em=?, atualizado_em=?$extra WHERE id=? AND status=?", $params)) {
                return;
            }
        }
    }

    private function onInbound(array $in, array &$stats): void
    {
        $from = Format::phone((string)($in['from'] ?? '')) ?? preg_replace('/\D/', '', (string)($in['from'] ?? ''));
        $text = (string)($in['text']['body'] ?? $in['button']['text'] ?? $in['interactive']['button_reply']['title'] ?? '');
        if ($from === '' || !Contacts::isOptOut($text)) {
            return; // não guardamos o conteúdo de outras mensagens
        }
        $dedup = hash('sha256', 'in|' . ($in['id'] ?? ''));
        $engine = new Engine($this->app);
        if (!$engine->event(null, 'descadastro', null, 'Pedido de descadastramento recebido de ' . Format::maskPhone($from), $dedup)) {
            return;
        }
        $ids = (new Contacts($this->db, $this->app->clock()))->revokeByPhone($from, 'whatsapp (resposta SAIR)');
        $stats['descadastros'] += count($ids);
    }
}

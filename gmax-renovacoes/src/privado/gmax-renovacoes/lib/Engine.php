<?php
declare(strict_types=1);

namespace GmaxRenov;

use GmaxRenov\Provider\OutboundMessage;
use GmaxRenov\Provider\SendResult;

/**
 * Agendador/fila dos lembretes.
 *
 * Fluxo de cada execução (cron a cada 15 min):
 *  1. recupera envios interrompidos  → "incerto" (nunca reenvia sozinho)
 *  2. expira o que ficou para trás   → não envia lembrete atrasado de dias anteriores
 *  3. varredura de renovações        → cancela avisos do pacote cujo vencimento mudou
 *  4. após o horário: gera lembretes do DIA (pacote + vencimento + etapa, chave única)
 *  5. dentro da janela e sem pausa: agrupa por cliente/vencimento/etapa, revalida e envia
 */
final class Engine
{
    public const LOCK = 'gmax_renov_agendador';
    private const CANCEL_REASONS = ['renovado', 'pacote_inexistente', 'cliente_inexistente', 'fora_do_dia'];

    private App $app;
    private Db $db;
    private Contacts $contacts;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->db = $app->db();
        $this->contacts = new Contacts($this->db, $app->clock());
    }

    private function now(): string
    {
        return $this->app->clock()->now()->format('Y-m-d H:i:s');
    }

    private function today(): string
    {
        return $this->app->clock()->now()->format('Y-m-d');
    }

    private function hm(): string
    {
        return $this->app->clock()->now()->format('H:i');
    }

    private function eligibilityOpts(string $mode): array
    {
        $s = $this->app->settings();
        $nums = array_values(array_filter(array_map([Format::class, 'phone'], (array)$s->get('numeros_teste'))));
        return [
            'somente_teste' => $mode === 'real' && (bool)$s->get('somente_numeros_teste'),
            'numeros_teste' => $nums,
        ];
    }

    /**
     * Execução completa protegida por trava (duas execuções simultâneas → a segunda sai sem fazer nada).
     */
    public function run(string $origin = 'cron', array $opt = []): array
    {
        if (!empty($opt['sem_trava'])) {
            return $this->runUnlocked($origin, $opt);
        }
        if (!$this->db->getLock(self::LOCK, 0)) {
            return ['executado' => false, 'motivo' => 'Outra execução do agendador está em andamento.'];
        }
        try {
            return $this->runUnlocked($origin, $opt);
        } finally {
            $this->db->releaseLock(self::LOCK);
        }
    }

    private function runUnlocked(string $origin, array $opt): array
    {
        $s = $this->app->settings();
        $s->refresh();
        $execId = $this->db->insert('INSERT INTO gr_execucoes (origem, iniciado_em) VALUES (?,?)', [$origin, $this->now()]);
        $res = ['executado' => true, 'modo' => $this->app->effectiveMode()];
        try {
            $res['recuperados_incertos'] = $this->recoverStale();
            $res['expirados'] = $this->expireOld();
            if (!$s->get('ativo')) {
                $res['motivo'] = 'Módulo desativado.';
            } else {
                $res['cancelados_renovacao'] = $this->sweep();
                $ignoreHour = !empty($opt['ignorar_horario']) && $res['modo'] === 'simulacao';
                if (!$ignoreHour && $this->hm() < (string)$s->get('horario')) {
                    $res['motivo'] = 'Antes do horário configurado (' . $s->get('horario') . ').';
                } else {
                    $res['gerados'] = $this->generate();
                    if ($s->get('pausa_global')) {
                        $res['motivo'] = 'Pausa global ativa: nada enviado.';
                    } elseif (!$ignoreHour && $this->hm() >= (string)$s->get('janela_fim')) {
                        $res['motivo'] = 'Fora da janela de envio (até ' . $s->get('janela_fim') . ').';
                    } else {
                        $res += $this->dispatch();
                    }
                }
            }
            $this->db->exec('UPDATE gr_execucoes SET finalizado_em=?, resultado=? WHERE id=?', [$this->now(), json_encode($res, JSON_UNESCAPED_UNICODE), $execId]);
        } catch (\Throwable $e) {
            $this->db->exec('UPDATE gr_execucoes SET finalizado_em=?, erro=? WHERE id=?', [$this->now(), mb_substr(Log::redact($e->getMessage()), 0, 500), $execId]);
            $this->app->log()->error('Falha na execução', ['erro' => $e->getMessage()]);
            throw $e;
        }
        return $res;
    }

    /** Envios que ficaram "enviando" (processo caiu no meio) viram "incerto". */
    public function recoverStale(): int
    {
        $rows = $this->db->all("SELECT id FROM gr_mensagens WHERE status='enviando' AND lease_ate < ?", [$this->now()]);
        $n = 0;
        foreach ($rows as $r) {
            $n += $this->db->exec(
                "UPDATE gr_mensagens SET status='incerto', erro_codigo='interrompido', erro_detalhe=?, lease_token=NULL, lease_ate=NULL, status_em=?, atualizado_em=? WHERE id=? AND status='enviando'",
                ['Execução interrompida durante o envio. Confira no WhatsApp antes de reenviar.', $this->now(), $this->now(), $r['id']]
            );
            $this->event((int)$r['id'], 'sistema', 'incerto', 'Envio interrompido: marcado como incerto (não será reenviado automaticamente).');
        }
        return $n;
    }

    /** Lembretes/mensagens de dias anteriores não são enviados atrasados. */
    public function expireOld(): int
    {
        $today = $this->today();
        $now = $this->now();
        $n = $this->db->exec(
            "UPDATE gr_lembretes SET status='cancelado', motivo='fora_do_dia', atualizado_em=? WHERE status='pendente' AND data_prevista < ?",
            [$now, $today]
        );
        foreach ($this->db->all("SELECT id FROM gr_mensagens WHERE status='aguardando' AND data_prevista < ?", [$today]) as $m) {
            $this->cancelMessage((int)$m['id'], 'fora_do_dia', 'Não enviada no dia previsto; expirada para não enviar atrasada.');
            $n++;
        }
        return $n;
    }

    /**
     * Varredura de renovações: se o vencimento do pacote mudou, cancela os avisos pendentes
     * daquele pacote e ciclo. Avisos de outros pacotes do mesmo cliente não são afetados.
     */
    public function sweep(): int
    {
        $rows = $this->db->all(
            "SELECT l.id, l.pacote_id, l.vencimento, l.mensagem_id FROM gr_lembretes l
             LEFT JOIN gr_mensagens m ON m.id = l.mensagem_id
             WHERE l.status='pendente' OR (l.status='em_mensagem' AND m.status='aguardando')"
        );
        $n = 0;
        foreach ($rows as $r) {
            $pkg = $this->app->panel()->package((int)$r['pacote_id']);
            if ($pkg === null || $pkg['vencimento'] !== $r['vencimento']) {
                $n += $this->cancelReminder((int)$r['id'], $pkg === null ? 'pacote_inexistente' : 'renovado');
                if ($r['mensagem_id']) {
                    $this->cancelMessageIfEmpty((int)$r['mensagem_id']);
                }
            }
        }
        return $n;
    }

    /** Gera os lembretes do dia. Idempotente: a chave única impede duplicação. */
    public function generate(): array
    {
        $s = $this->app->settings();
        $today = $this->today();
        $mode = $this->app->effectiveMode();
        $activated = $s->get('ativado_em');
        if ($activated && $today < substr((string)$activated, 0, 10)) {
            return ['novos' => 0, 'ignorados' => 0];
        }
        $byDue = [];
        foreach (Stages::all($s) as $st) {
            if ($st['ativo']) {
                $due = $this->app->clock()->now()->modify(($st['dias'] >= 0 ? '-' : '+') . abs($st['dias']) . ' days')->format('Y-m-d');
                $byDue[$due] = $st;
            }
        }
        $pkgs = $this->app->panel()->packagesDueOn(array_keys($byDue));
        $contacts = $this->contacts->many(array_unique(array_column($pkgs, 'cliente_id')));
        $opts = $this->eligibilityOpts($mode);
        $new = 0;
        $ignored = 0;
        $now = $this->now();
        foreach ($pkgs as $p) {
            $st = $byDue[$p['vencimento']] ?? null;
            if (!$st) {
                continue;
            }
            $reason = Eligibility::check($p, $contacts[$p['cliente_id']] ?? [], null, $opts);
            $inserted = $this->db->exec(
                'INSERT IGNORE INTO gr_lembretes (modo, pacote_id, cliente_id, vencimento, etapa, data_prevista, status, motivo,
                   snap_pacote, snap_valor_centavos, snap_pontos, snap_ciclo, criado_em, atualizado_em)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$mode, $p['pacote_id'], $p['cliente_id'], $p['vencimento'], $st['codigo'], $today,
                 $reason ? 'ignorado' : 'pendente', $reason,
                 mb_substr($p['pacote_nome'], 0, 190), $p['valor_centavos'], $p['pontos'], $p['ciclo'], $now, $now]
            );
            if ($inserted) {
                $reason ? $ignored++ : $new++;
                continue;
            }
            // Já existe: só reavalia os "ignorados" do próprio dia (ex.: autorização concedida depois das 09h).
            if ($reason === null) {
                $new += $this->db->exec(
                    "UPDATE gr_lembretes SET status='pendente', motivo=NULL, mensagem_id=NULL, atualizado_em=?
                     WHERE modo=? AND pacote_id=? AND vencimento=? AND etapa=? AND status='ignorado' AND data_prevista=?",
                    [$now, $mode, $p['pacote_id'], $p['vencimento'], $st['codigo'], $today]
                );
            } else {
                $this->db->exec(
                    "UPDATE gr_lembretes SET motivo=?, atualizado_em=? WHERE modo=? AND pacote_id=? AND vencimento=? AND etapa=? AND status='ignorado'",
                    [$reason, $now, $mode, $p['pacote_id'], $p['vencimento'], $st['codigo']]
                );
            }
        }
        return ['novos' => $new, 'ignorados' => $ignored];
    }

    public function dispatch(): array
    {
        $mode = $this->app->effectiveMode();
        $today = $this->today();
        $out = ['mensagens_criadas' => 0, 'aceitas' => 0, 'falhas' => 0, 'incertas' => 0, 'reagendadas' => 0, 'canceladas' => 0];
        $groups = $this->db->all(
            "SELECT cliente_id, vencimento, etapa FROM gr_lembretes WHERE modo=? AND status='pendente' AND data_prevista=?
             GROUP BY cliente_id, vencimento, etapa ORDER BY MIN(id)",
            [$mode, $today]
        );
        foreach ($groups as $g) {
            if ($this->buildMessage($mode, (int)$g['cliente_id'], $g['vencimento'], $g['etapa'])) {
                $out['mensagens_criadas']++;
            }
        }
        $ids = $this->db->all(
            "SELECT id FROM gr_mensagens WHERE modo=? AND status='aguardando' AND data_prevista=? AND (proxima_tentativa_em IS NULL OR proxima_tentativa_em <= ?) ORDER BY id",
            [$mode, $today, $this->now()]
        );
        foreach ($ids as $row) {
            $r = $this->attempt((int)$row['id']);
            $key = ['aceito' => 'aceitas', 'falhou' => 'falhas', 'incerto' => 'incertas', 'aguardando' => 'reagendadas', 'cancelado' => 'canceladas'][$r] ?? null;
            if ($key) {
                $out[$key]++;
            }
            if ($r === 'pausado') {
                $out['motivo'] = 'Envios pausados durante a execução (credencial ou pausa global).';
                break;
            }
        }
        return $out;
    }

    /**
     * Agrupa os lembretes pendentes de um cliente/vencimento/etapa numa única mensagem (após revalidar).
     */
    public function buildMessage(string $mode, int $clientId, string $due, string $stage, string $initialStatus = 'aguardando'): ?int
    {
        return $this->db->tx(function (Db $db) use ($mode, $clientId, $due, $stage, $initialStatus) {
            $rows = $db->all(
                "SELECT * FROM gr_lembretes WHERE modo=? AND cliente_id=? AND vencimento=? AND etapa=? AND status='pendente' ORDER BY pacote_id FOR UPDATE",
                [$mode, $clientId, $due, $stage]
            );
            $valid = $this->revalidate($rows, $mode);
            if (!$valid) {
                return null;
            }
            $items = array_column($valid, 'pkg');
            $built = Templates::build($this->app->settings(), $stage, $items);
            $tpl = $this->app->settings()->get('modelos')[$stage][$built['variante']] ?? null;
            $now = $this->now();
            $msgId = $db->insert(
                'INSERT INTO gr_mensagens (modo, ref, cliente_id, telefone, etapa, vencimento, data_prevista, corpo, modelo_nome, modelo_parametros,
                   status, provedor, criado_em, atualizado_em, status_em) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$mode, 'gr' . bin2hex(random_bytes(10)), $clientId, Format::phone($items[0]['telefone']), $stage, $due, $this->today(),
                 $built['corpo'], $tpl ?: null, json_encode($built['parametros'], JSON_UNESCAPED_UNICODE),
                 $initialStatus, $this->app->provider()->name(), $now, $now, $now]
            );
            $ids = array_map(static fn($v) => (int)$v['row']['id'], $valid);
            $in = implode(',', $ids);
            $linked = $db->exec("UPDATE gr_lembretes SET status='em_mensagem', mensagem_id=?, atualizado_em=? WHERE id IN ($in) AND status='pendente'", [$msgId, $now]);
            if ($linked !== count($ids)) {
                throw new \RuntimeException('Concorrência ao agrupar lembretes; transação desfeita.');
            }
            $this->event($msgId, 'sistema', $initialStatus, count($ids) > 1 ? 'Mensagem agrupada com ' . count($ids) . ' pacotes.' : 'Mensagem criada.');
            return $msgId;
        });
    }

    /**
     * Revalida os lembretes com dados frescos do painel. Inválidos são cancelados/ignorados com motivo.
     * @return array<int, array{row:array, pkg:array}>
     */
    private function revalidate(array $rows, string $mode): array
    {
        $valid = [];
        $opts = $this->eligibilityOpts($mode);
        $today = $this->today();
        foreach ($rows as $r) {
            $pkg = $this->app->panel()->package((int)$r['pacote_id']);
            $contact = $this->contacts->get((int)$r['cliente_id']);
            $reason = $r['data_prevista'] !== $today ? 'fora_do_dia' : Eligibility::check($pkg, $contact, $r['vencimento'], $opts);
            if ($reason === null && (int)$pkg['cliente_id'] !== (int)$r['cliente_id']) {
                $reason = 'cliente_inexistente'; // pacote foi transferido para outro cliente
            }
            if ($reason !== null) {
                in_array($reason, self::CANCEL_REASONS, true)
                    ? $this->cancelReminder((int)$r['id'], $reason)
                    : $this->db->exec("UPDATE gr_lembretes SET status='ignorado', motivo=?, atualizado_em=? WHERE id=?", [$reason, $this->now(), $r['id']]);
                continue;
            }
            $valid[] = ['row' => $r, 'pkg' => $pkg];
        }
        return $valid;
    }

    /**
     * Uma tentativa de envio. Retorna o status final da tentativa.
     */
    public function attempt(int $msgId, bool $manualRetry = false): string
    {
        $s = $this->app->settings();
        $now = $this->now();
        $token = bin2hex(random_bytes(12));
        $leaseUntil = $this->app->clock()->now()->modify('+5 minutes')->format('Y-m-d H:i:s');
        // Reivindicação atômica: só um trabalhador consegue mudar de "aguardando" para "enviando".
        $claimed = $this->db->exec(
            "UPDATE gr_mensagens SET status='enviando', lease_token=?, lease_ate=?, tentativas=tentativas+1, atualizado_em=?
             WHERE id=? AND status='aguardando' AND (proxima_tentativa_em IS NULL OR proxima_tentativa_em <= ?)",
            [$token, $leaseUntil, $now, $msgId, $now]
        );
        if (!$claimed) {
            return 'nao_reivindicada';
        }
        $msg = $this->db->one('SELECT * FROM gr_mensagens WHERE id=?', [$msgId]);

        // Revalidação imediatamente antes do envio: configurações, modo e dados do pacote/cliente.
        $s->refresh();
        if ($s->get('pausa_global') || !$s->get('ativo') || $msg['modo'] !== $this->app->effectiveMode()) {
            $this->db->exec(
                "UPDATE gr_mensagens SET status='aguardando', tentativas=tentativas-1, lease_token=NULL, lease_ate=NULL WHERE id=? AND lease_token=?",
                [$msgId, $token]
            );
            return 'pausado';
        }
        $rows = $this->db->all("SELECT * FROM gr_lembretes WHERE mensagem_id=? AND status='em_mensagem' ORDER BY pacote_id", [$msgId]);
        $before = count($rows);
        $valid = $this->revalidate($rows, $msg['modo']);
        if (!$valid) {
            $this->db->exec("UPDATE gr_mensagens SET lease_token=NULL WHERE id=?", [$msgId]);
            $this->cancelMessage($msgId, 'revalidacao', 'Cancelada na revalidação antes do envio (renovado, pausado, sem autorização…).', 'enviando');
            return 'cancelado';
        }
        $phone = Format::phone($valid[0]['pkg']['telefone']);
        $items = array_column($valid, 'pkg');
        $built = Templates::build($s, $msg['etapa'], $items);
        $tpl = $s->get('modelos')[$msg['etapa']][$built['variante']] ?? null;
        if (count($valid) !== $before || $built['corpo'] !== $msg['corpo'] || $phone !== $msg['telefone']) {
            $this->db->exec(
                'UPDATE gr_mensagens SET corpo=?, modelo_nome=?, modelo_parametros=?, telefone=? WHERE id=?',
                [$built['corpo'], $tpl ?: null, json_encode($built['parametros'], JSON_UNESCAPED_UNICODE), $phone, $msgId]
            );
            $this->event($msgId, 'sistema', null, 'Conteúdo atualizado na revalidação (dados do pacote mudaram).');
        }

        $provider = $this->app->provider();
        $out = new OutboundMessage($phone, $built['corpo'], $tpl ?: null, (string)$s->get('modelo_idioma'), $built['parametros'], $msg['ref']);
        try {
            $res = $provider->send($out);
        } catch (\Throwable $e) {
            $res = new SendResult(SendResult::UNCERTAIN, null, 'excecao', 'Erro inesperado durante o envio: ' . Log::redact($e->getMessage()));
        }
        $now = $this->now();
        $attempts = (int)$msg['tentativas'];
        switch ($res->kind) {
            case SendResult::ACCEPTED:
                $this->finish($msgId, $token, 'aceito', ['provedor_msg_id' => $res->providerId, 'enviado_em' => $now, 'erro_codigo' => null, 'erro_detalhe' => null]);
                $this->event($msgId, 'envio', 'aceito', 'Aceito pelo provedor ' . $provider->name() . ($provider->isReal() ? '' : ' (SIMULAÇÃO — nada foi enviado)') . '.');
                $this->applyOrphans($msgId, (string)$res->providerId);
                return 'aceito';
            case SendResult::TRANSIENT:
                $max = max(1, (int)$s->get('max_tentativas'));
                if ($attempts < $max) {
                    $wait = 5 * (2 ** ($attempts - 1)); // 5, 10, 20 min
                    $next = $this->app->clock()->now()->modify("+$wait minutes")->format('Y-m-d H:i:s');
                    $this->finish($msgId, $token, 'aguardando', ['proxima_tentativa_em' => $next, 'erro_codigo' => $res->code, 'erro_detalhe' => $res->detail]);
                    $this->event($msgId, 'envio', 'aguardando', "Falha temporária ({$res->code}). Nova tentativa às " . substr($next, 11, 5) . '.');
                    return 'aguardando';
                }
                $this->finish($msgId, $token, 'falhou', ['erro_codigo' => $res->code, 'erro_detalhe' => 'Limite de tentativas atingido. ' . $res->detail]);
                $this->event($msgId, 'envio', 'falhou', 'Limite de tentativas atingido.');
                return 'falhou';
            case SendResult::UNCERTAIN:
                $this->finish($msgId, $token, 'incerto', ['erro_codigo' => $res->code, 'erro_detalhe' => $res->detail]);
                $this->event($msgId, 'envio', 'incerto', (string)$res->detail);
                return 'incerto';
            default:
                $this->finish($msgId, $token, 'falhou', ['erro_codigo' => $res->code, 'erro_detalhe' => $res->detail]);
                $this->event($msgId, 'envio', 'falhou', (string)$res->detail);
                if ($res->credentialError) {
                    $s->set('pausa_global', true);
                    $this->app->log()->error('Erro de credencial no provedor; pausa global ativada.', ['codigo' => $res->code]);
                    return 'pausado';
                }
                return 'falhou';
        }
    }

    private function finish(int $msgId, string $token, string $status, array $fields): void
    {
        $set = ['status=?', 'lease_token=NULL', 'lease_ate=NULL', 'status_em=?', 'atualizado_em=?'];
        $params = [$status, $this->now(), $this->now()];
        foreach ($fields as $k => $v) {
            $set[] = "$k=?";
            $params[] = $v;
        }
        $params[] = $msgId;
        $params[] = $token;
        $n = $this->db->exec('UPDATE gr_mensagens SET ' . implode(', ', $set) . " WHERE id=? AND lease_token=? AND status='enviando'", $params);
        if ($n === 0 && $fields) {
            // Um callback (via referência) já avançou o status enquanto enviávamos: grava só os dados, sem regredir o status.
            $set = ['lease_token=NULL', 'lease_ate=NULL'];
            $params = [];
            foreach ($fields as $k => $v) {
                $set[] = "$k=COALESCE($k, ?)";
                $params[] = $v;
            }
            $params[] = $msgId;
            $this->db->exec('UPDATE gr_mensagens SET ' . implode(', ', $set) . ' WHERE id=?', $params);
        }
    }

    /** Callbacks que chegaram antes de gravarmos o ID do provedor. */
    private function applyOrphans(int $msgId, string $providerId): void
    {
        $orphans = $this->db->all("SELECT id, detalhe FROM gr_eventos WHERE tipo='status_orfao' AND mensagem_id IS NULL AND detalhe LIKE ?", ['%"' . $providerId . '"%']);
        foreach ($orphans as $o) {
            $d = json_decode((string)$o['detalhe'], true) ?: [];
            if (($d['id'] ?? '') !== $providerId) {
                continue;
            }
            $this->db->exec("UPDATE gr_eventos SET mensagem_id=?, tipo='status' WHERE id=?", [$msgId, $o['id']]);
            (new Webhook($this->app))->applyStatus($msgId, (string)$d['status'], $d['erro'] ?? null, $d['ts'] ?? null);
        }
    }

    public function cancelReminder(int $id, string $reason): int
    {
        return $this->db->exec(
            "UPDATE gr_lembretes SET status='cancelado', motivo=?, atualizado_em=? WHERE id=? AND status IN ('pendente','em_mensagem','ignorado')",
            [$reason, $this->now(), $id]
        );
    }

    public function cancelMessage(int $msgId, string $code, string $detail, string $fromStatus = 'aguardando'): void
    {
        $n = $this->db->exec(
            "UPDATE gr_mensagens SET status='cancelado', erro_codigo=?, erro_detalhe=?, lease_token=NULL, lease_ate=NULL, status_em=?, atualizado_em=? WHERE id=? AND status=?",
            [$code, $detail, $this->now(), $this->now(), $msgId, $fromStatus]
        );
        if ($n) {
            $this->db->exec("UPDATE gr_lembretes SET status='cancelado', motivo=COALESCE(motivo, ?), atualizado_em=? WHERE mensagem_id=? AND status='em_mensagem'", [$code, $this->now(), $msgId]);
            $this->event($msgId, 'sistema', 'cancelado', $detail);
        }
    }

    private function cancelMessageIfEmpty(int $msgId): void
    {
        $left = (int)$this->db->value("SELECT COUNT(*) FROM gr_lembretes WHERE mensagem_id=? AND status='em_mensagem'", [$msgId]);
        if ($left === 0) {
            $this->cancelMessage($msgId, 'renovado', 'Todos os pacotes da mensagem foram renovados antes do envio.');
        }
    }

    public function event(?int $msgId, string $type, ?string $status, string $detail, ?string $dedup = null, ?string $at = null): bool
    {
        return $this->db->exec(
            'INSERT IGNORE INTO gr_eventos (mensagem_id, tipo, status, detalhe, chave_dedup, ocorrido_em, registrado_em) VALUES (?,?,?,?,?,?,?)',
            [$msgId, $type, $status, mb_substr($detail, 0, 500), $dedup, $at, $this->now()]
        ) > 0;
    }

    // ---- Ações manuais (aba Renovações) ----

    /** Resolve um envio incerto/falho. 'reenviar' exige confirmação explícita e só no dia previsto. */
    public function resolve(int $msgId, string $decision): string
    {
        $m = $this->db->one('SELECT * FROM gr_mensagens WHERE id=?', [$msgId]);
        if (!$m) {
            throw new \InvalidArgumentException('Mensagem não encontrada.');
        }
        $now = $this->now();
        switch ($decision) {
            case 'confirmar_enviado':
                if ($m['status'] !== 'incerto') {
                    throw new \InvalidArgumentException('Só é possível confirmar mensagens com resultado incerto.');
                }
                $this->mustChange($this->db->exec("UPDATE gr_mensagens SET status='aceito', status_em=?, atualizado_em=? WHERE id=? AND status='incerto'", [$now, $now, $msgId]));
                $this->event($msgId, 'manual', 'aceito', 'Você confirmou que a mensagem foi enviada (entrega não confirmada pelo provedor).');
                return 'aceito';
            case 'marcar_falhou':
                if (!in_array($m['status'], ['incerto'], true)) {
                    throw new \InvalidArgumentException('Só é possível marcar como falha mensagens com resultado incerto.');
                }
                $this->mustChange($this->db->exec("UPDATE gr_mensagens SET status='falhou', erro_codigo='manual', status_em=?, atualizado_em=? WHERE id=? AND status='incerto'", [$now, $now, $msgId]));
                $this->event($msgId, 'manual', 'falhou', 'Você marcou a mensagem como não enviada.');
                return 'falhou';
            case 'reenviar':
                if (!in_array($m['status'], ['incerto', 'falhou'], true)) {
                    throw new \InvalidArgumentException('Reenvio só para mensagens incertas ou com falha.');
                }
                if ($m['data_prevista'] !== $this->today()) {
                    throw new \InvalidArgumentException('Reenvio só no dia previsto. Lembretes antigos não são reenviados.');
                }
                $this->mustChange($this->db->exec("UPDATE gr_mensagens SET status='aguardando', proxima_tentativa_em=NULL, atualizado_em=? WHERE id=? AND status IN ('incerto','falhou')", [$now, $msgId]));
                $this->event($msgId, 'manual', 'aguardando', 'Reenvio solicitado por você.');
                return $this->attempt($msgId, true);
            case 'cancelar':
                if ($m['status'] !== 'aguardando') {
                    throw new \InvalidArgumentException('Só é possível cancelar mensagens aguardando envio.');
                }
                $this->cancelMessage($msgId, 'manual', 'Cancelada por você.');
                $this->mustChange($this->db->value('SELECT status FROM gr_mensagens WHERE id=?', [$msgId]) === 'cancelado' ? 1 : 0);
                return 'cancelado';
            case 'registrar_manual':
                if (!in_array($m['status'], ['aguardando', 'incerto', 'falhou'], true)) {
                    throw new \InvalidArgumentException('Estado não permite registrar envio manual.');
                }
                $this->mustChange($this->db->exec("UPDATE gr_mensagens SET status='manual', status_em=?, atualizado_em=? WHERE id=? AND status=?", [$now, $now, $msgId, $m['status']]));
                $this->event($msgId, 'manual', 'manual', 'Você registrou que enviou manualmente pelo WhatsApp.');
                return 'manual';
        }
        throw new \InvalidArgumentException('Ação inválida.');
    }

    private function mustChange(int $rows): void
    {
        if ($rows === 0) {
            throw new \InvalidArgumentException('O estado da mensagem mudou enquanto você decidia. Recarregue e confira.');
        }
    }

    /**
     * Mensagem de teste com dados FICTÍCIOS para um número da lista de testes (contato interno autorizado).
     * Não usa clientes nem pacotes reais.
     */
    public function sendTest(string $phoneRaw, string $stage, bool $group = false): array
    {
        $s = $this->app->settings();
        $phone = Format::phone($phoneRaw);
        $allowed = array_values(array_filter(array_map([Format::class, 'phone'], (array)$s->get('numeros_teste'))));
        if ($phone === null || !in_array($phone, $allowed, true)) {
            throw new \InvalidArgumentException('O número precisa estar na lista de números de teste (contatos internos autorizados).');
        }
        if (!in_array($stage, Stages::CODES, true)) {
            throw new \InvalidArgumentException('Etapa inválida.');
        }
        $dias = 0;
        foreach (Stages::all($s) as $st) {
            if ($st['codigo'] === $stage) {
                $dias = $st['dias'];
            }
        }
        $items = self::sampleItems($phone, $this->app->clock()->now(), $group, $dias);
        $built = Templates::build($s, $stage, $items);
        $tpl = $s->get('modelos')[$stage][$built['variante']] ?? null;
        $now = $this->now();
        $token = bin2hex(random_bytes(12));
        $provider = $this->app->provider();
        $mode = $this->app->effectiveMode();
        $ref = 'grt' . bin2hex(random_bytes(10));
        $msgId = $this->db->insert(
            "INSERT INTO gr_mensagens (modo, ref, cliente_id, telefone, etapa, vencimento, data_prevista, corpo, modelo_nome, modelo_parametros,
               status, provedor, tentativas, lease_token, lease_ate, criado_em, atualizado_em, status_em) VALUES (?,?,0,?,?,?,?,?,?,?,'enviando',?,1,?,?,?,?,?)",
            [$mode, $ref, $phone, $stage, $items[0]['vencimento'], $this->today(), '[TESTE] ' . $built['corpo'], $tpl ?: null,
             json_encode($built['parametros'], JSON_UNESCAPED_UNICODE), $provider->name(), $token,
             $this->app->clock()->now()->modify('+5 minutes')->format('Y-m-d H:i:s'), $now, $now, $now]
        );
        try {
            $res = $provider->send(new OutboundMessage($phone, $built['corpo'], $tpl ?: null, (string)$s->get('modelo_idioma'), $built['parametros'], $ref));
        } catch (\Throwable $e) {
            $res = new SendResult(SendResult::UNCERTAIN, null, 'excecao', Log::redact($e->getMessage()));
        }
        $status = [SendResult::ACCEPTED => 'aceito', SendResult::UNCERTAIN => 'incerto'][$res->kind] ?? 'falhou';
        $this->finish($msgId, $token, $status, ['provedor_msg_id' => $res->providerId, 'erro_codigo' => $res->code, 'erro_detalhe' => $res->detail, 'enviado_em' => $status === 'aceito' ? $now : null]);
        $this->event($msgId, 'teste', $status, 'Mensagem de teste' . ($provider->isReal() ? '' : ' (SIMULAÇÃO — nada foi enviado)') . ': ' . ($res->detail ?? $status));
        return ['mensagem_id' => $msgId, 'status' => $status, 'real' => $provider->isReal(), 'detalhe' => $res->detail, 'corpo' => $built['corpo']];
    }

    /** Pacotes fictícios para prévias e testes. */
    public static function sampleItems(string $phone, \DateTimeImmutable $now, bool $group, int $stageDays = -3): array
    {
        // Vencimento coerente com a etapa: "antes" = hoje + N, "dia" = hoje, "depois" = hoje − N.
        $due = $now->modify(($stageDays > 0 ? '-' : '+') . abs($stageDays) . ' days')->format('Y-m-d');
        $base = ['cliente_id' => 0, 'cliente_nome' => 'Cliente Teste', 'telefone' => $phone, 'servico' => 'Lista', 'vencimento' => $due];
        $items = [$base + ['pacote_id' => 0, 'pacote_nome' => 'Pacote Família', 'pontos' => 4, 'ciclo' => 'Trimestral', 'valor_centavos' => 12000]];
        if ($group) {
            $items[] = $base + ['pacote_id' => 0, 'pacote_nome' => 'Pacote Quarto', 'pontos' => 1, 'ciclo' => 'Mensal', 'valor_centavos' => 3500];
        }
        return $items;
    }

    /** Registra envio manual de lembretes ainda pendentes (impede o envio automático do mesmo aviso). */
    public function manualForPending(int $clientId, string $due, string $stage): ?int
    {
        $mode = $this->app->effectiveMode();
        $today = $this->today();
        $st = null;
        foreach (Stages::all($this->app->settings()) as $s) {
            if ($s['codigo'] === $stage && $s['ativo']) {
                $st = $s;
            }
        }
        $expectedDue = $st ? $this->app->clock()->now()->modify(($st['dias'] >= 0 ? '-' : '+') . abs($st['dias']) . ' days')->format('Y-m-d') : null;
        if ($expectedDue !== $due) {
            throw new \InvalidArgumentException('Este aviso não é de hoje.');
        }
        // Cria os lembretes do dia se o agendador ainda não gerou (ex.: antes das 09h), para o automático não repetir.
        $now = $this->now();
        foreach ($this->app->panel()->packagesDueOn([$due]) as $p) {
            if ($p['cliente_id'] !== $clientId) {
                continue;
            }
            $this->db->exec(
                'INSERT IGNORE INTO gr_lembretes (modo, pacote_id, cliente_id, vencimento, etapa, data_prevista, status, snap_pacote, snap_valor_centavos, snap_pontos, snap_ciclo, criado_em, atualizado_em)
                 VALUES (?,?,?,?,?,?,\'pendente\',?,?,?,?,?,?)',
                [$mode, $p['pacote_id'], $clientId, $due, $stage, $today, mb_substr($p['pacote_nome'], 0, 190), $p['valor_centavos'], $p['pontos'], $p['ciclo'], $now, $now]
            );
        }
        $id = $this->buildMessage($mode, $clientId, $due, $stage, 'manual');
        if ($id) {
            $this->event($id, 'manual', 'manual', 'Você registrou que enviou manualmente pelo WhatsApp.');
        }
        return $id;
    }
}

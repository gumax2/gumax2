<?php
declare(strict_types=1);

namespace GmaxRenov;

/**
 * Ações JSON da aba Renovações. Autenticação e CSRF são verificados em renovacoes-api.php antes de chegar aqui.
 * Nenhuma ação devolve segredos do config.php.
 */
final class Api
{
    private App $app;
    private Engine $engine;
    private Contacts $contacts;

    public const MUTATIONS = ['salvar_config', 'consentimento', 'pausa_cliente', 'pausa_global', 'confirmar_pagamento',
        'resolver', 'registrar_manual', 'enviar_teste', 'executar_agora'];

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->engine = new Engine($app);
        $this->contacts = new Contacts($app->db(), $app->clock());
    }

    public function handle(string $action, array $in): array
    {
        self::checkShape($in);
        switch ($action) {
            case 'painel': return $this->dashboard();
            case 'historico': return $this->history($in);
            case 'mensagem': return $this->message((int)($in['id'] ?? 0));
            case 'clientes': return $this->clients($in);
            case 'cliente_historico': return ['itens' => $this->contacts->history((int)($in['id'] ?? 0))];
            case 'recebidas': return ['itens' => $this->app->db()->all("SELECT tipo, detalhe, registrado_em FROM gr_eventos WHERE tipo IN ('recebida','descadastro') ORDER BY id DESC LIMIT 30")];
            case 'config': return $this->config();
            case 'previa': return $this->preview($in);
            case 'salvar_config': return $this->saveConfig($in);
            case 'consentimento': return $this->consent($in);
            case 'pausa_cliente':
                if (!$this->app->panel()->client((int)($in['cliente_id'] ?? 0))) {
                    throw new \InvalidArgumentException('Cliente não encontrado.');
                }
                $this->contacts->setPaused((int)($in['cliente_id'] ?? 0), !empty($in['pausado']), (string)($in['motivo'] ?? ''));
                return ['ok' => true];
            case 'pausa_global':
                $this->app->settings()->set('pausa_global', !empty($in['pausado']));
                return ['ok' => true, 'pausa_global' => !empty($in['pausado'])];
            case 'confirmar_pagamento':
                $s = $this->app->settings();
                $s->set('pagamento_confirmado', true);
                $s->set('pagamento_confirmado_em', $this->app->clock()->now()->format('Y-m-d H:i:s'));
                return ['ok' => true];
            case 'resolver':
                return ['ok' => true, 'status' => $this->engine->resolve((int)($in['id'] ?? 0), (string)($in['decisao'] ?? ''))];
            case 'registrar_manual':
                $id = $this->engine->manualForPending((int)($in['cliente_id'] ?? 0), (string)($in['vencimento'] ?? ''), (string)($in['etapa'] ?? ''));
                if (!$id) {
                    throw new \InvalidArgumentException('Nenhum lembrete pendente elegível para registrar.');
                }
                return ['ok' => true, 'mensagem_id' => $id];
            case 'enviar_teste':
                return $this->engine->sendTest((string)($in['numero'] ?? ''), (string)($in['etapa'] ?? 'antes'), !empty($in['grupo']));
            case 'executar_agora':
                return $this->engine->run('painel', ['ignorar_horario' => !empty($in['ignorar_horario'])]);
        }
        throw new \InvalidArgumentException('Ação desconhecida.');
    }

    /**
     * Rejeita entradas com formato inesperado (ex.: lista onde se espera texto), antes de qualquer conversão.
     * Só 'textos', 'modelos', 'etapas' e 'numeros_teste' podem ter estrutura; as folhas são sempre escalares.
     */
    private static function checkShape(array $in): void
    {
        $nested = ['textos' => 2, 'modelos' => 2, 'etapas' => 2, 'numeros_teste' => 1];
        $walk = static function ($v, int $depth) use (&$walk): bool {
            if (!is_array($v)) {
                return $v === null || is_scalar($v);
            }
            if ($depth === 0) {
                return false;
            }
            foreach ($v as $x) {
                if (!$walk($x, $depth - 1)) {
                    return false;
                }
            }
            return true;
        };
        foreach ($in as $k => $v) {
            if (!$walk($v, $nested[$k] ?? 0)) {
                throw new \InvalidArgumentException('Campo com formato inválido: ' . preg_replace('/[^a-z_]/', '', (string)$k));
            }
        }
    }

    private function dashboard(): array
    {
        $s = $this->app->settings();
        $db0 = $this->app->db();
        if ($db0->getLock(Engine::LOCK, 0)) { // não concorre com o agendador
            try {
                $this->engine->sweep();
            } finally {
                $db0->releaseLock(Engine::LOCK);
            }
        }
        $now = $this->app->clock()->now();
        $today = $now->format('Y-m-d');
        $pkgs = $this->app->panel()->packagesDueBetween($now->modify('-15 days')->format('Y-m-d'), $now->modify('+7 days')->format('Y-m-d'));
        $contacts = $this->contacts->many(array_unique(array_column($pkgs, 'cliente_id')));
        $stages = array_values(array_filter(Stages::all($s), static fn($st) => $st['ativo']));
        $mode = $this->app->effectiveMode();
        $db = $this->app->db();

        // Agrupa por cliente + vencimento (mesma regra do envio).
        $groups = [];
        foreach ($pkgs as $p) {
            $groups[$p['cliente_id'] . '|' . $p['vencimento']][] = $p;
        }
        $out = ['proximos' => [], 'hoje' => [], 'vencidos' => []];
        foreach ($groups as $items) {
            $first = $items[0];
            $diff = (int)(new \DateTimeImmutable($today))->diff(new \DateTimeImmutable($first['vencimento']))->format('%r%a');
            $stageToday = null;
            $nextStage = null;
            foreach ($stages as $st) {
                $date = (new \DateTimeImmutable($first['vencimento']))->modify(($st['dias'] >= 0 ? '+' : '-') . abs($st['dias']) . ' days')->format('Y-m-d');
                if ($date === $today) {
                    $stageToday = $st + ['data' => $date];
                }
                if ($date >= $today && ($nextStage === null || $date < $nextStage['data'])) {
                    $nextStage = $st + ['data' => $date];
                }
            }
            $previewStage = $stageToday ?? $nextStage;
            $contact = $contacts[$first['cliente_id']] ?? [];
            $rows = [];
            $eligible = [];
            foreach ($items as $it) {
                $reason = Eligibility::check($it, $contact);
                $rem = $previewStage ? $db->one(
                    'SELECT l.status, l.motivo, l.mensagem_id, m.status AS msg_status FROM gr_lembretes l LEFT JOIN gr_mensagens m ON m.id=l.mensagem_id
                     WHERE l.modo=? AND l.pacote_id=? AND l.vencimento=? AND l.etapa=?',
                    [$mode, $it['pacote_id'], $it['vencimento'], $previewStage['codigo']]
                ) : null;
                $rows[] = [
                    'pacote_id' => $it['pacote_id'],
                    'pacote' => Templates::describe($it),
                    'servico' => $it['servico'],
                    'valor' => Format::money($it['valor_centavos']),
                    'motivo' => $reason,
                    'motivo_texto' => Eligibility::label($reason),
                    'lembrete' => $rem ? [
                        'status' => $rem['msg_status'] ?? $rem['status'],
                        'status_texto' => $rem['msg_status'] ? Statuses::LABELS[$rem['msg_status']] : ['pendente' => 'Aguardando envio', 'ignorado' => 'Não será enviado', 'cancelado' => 'Cancelado'][$rem['status']] ?? $rem['status'],
                        'motivo' => Eligibility::label($rem['motivo']),
                        'mensagem_id' => $rem['mensagem_id'] ? (int)$rem['mensagem_id'] : null,
                    ] : null,
                ];
                if ($reason === null) {
                    $eligible[] = $it;
                }
            }
            // Sem nenhum pacote elegível (sem telefone, inativo, sem autorização…): mostra o motivo, não a prévia.
            $preview = ($previewStage && $eligible) ? Templates::build($s, $previewStage['codigo'], $eligible) : null;
            $phone = Format::phone($first['telefone']);
            $entry = [
                'cliente_id' => $first['cliente_id'],
                'cliente' => $first['cliente_nome'],
                'telefone' => Format::maskPhone($phone),
                'vencimento' => $first['vencimento'],
                'vencimento_br' => Format::date($first['vencimento']),
                'dias' => $diff,
                'total' => Format::money(array_sum(array_column($items, 'valor_centavos'))),
                'itens' => $rows,
                'etapa_hoje' => $stageToday ? ['codigo' => $stageToday['codigo'], 'rotulo' => $stageToday['rotulo']] : null,
                'proxima_etapa' => $nextStage ? ['codigo' => $nextStage['codigo'], 'rotulo' => $nextStage['rotulo'], 'data' => Format::date($nextStage['data'])] : null,
                'previa' => $preview['corpo'] ?? null,
                'previa_etapa' => $previewStage['codigo'] ?? null,
                // Link para envio MANUAL pelo WhatsApp (você precisa tocar em enviar no aplicativo).
                'ja_enviado' => (bool)array_filter($rows, static fn($r) => in_array($r['lembrete']['status'] ?? '', ['enviando', 'aceito', 'entregue', 'lido', 'manual', 'incerto'], true)),
                'link_manual' => ($phone && $preview) ? 'https://wa.me/' . $phone . '?text=' . rawurlencode($preview['corpo']) : null,
                'consentimento' => $contact['consentimento'] ?? 'nenhum',
                'pausado' => !empty($contact['pausado']),
            ];
            $bucket = $diff > 0 ? 'proximos' : ($diff === 0 ? 'hoje' : 'vencidos');
            $out[$bucket][] = $entry;
        }
        $out['resumo'] = [
            'proximos' => count($out['proximos']),
            'hoje' => count($out['hoje']),
            'vencidos' => count($out['vencidos']),
            'falhas_hoje' => (int)$db->value("SELECT COUNT(*) FROM gr_mensagens WHERE status IN ('falhou','incerto') AND data_prevista=?", [$today]),
        ];
        $out['estado'] = $this->state();
        return $out;
    }

    private function state(): array
    {
        $s = $this->app->settings();
        $db = $this->app->db();
        $last = $db->one('SELECT origem, iniciado_em, finalizado_em, resultado, erro FROM gr_execucoes ORDER BY id DESC LIMIT 1');
        $lastCron = $db->value("SELECT MAX(iniciado_em) FROM gr_execucoes WHERE origem='cron'");
        return [
            'ativo' => (bool)$s->get('ativo'),
            'modo_configurado' => $s->get('modo'),
            'modo_efetivo' => $this->app->effectiveMode(),
            'bloqueios_envio_real' => $this->app->realSendingBlockers(),
            'pausa_global' => (bool)$s->get('pausa_global'),
            'horario' => $s->get('horario'),
            'janela_fim' => $s->get('janela_fim'),
            'agora' => $this->app->clock()->now()->format('d/m/Y H:i'),
            'ultima_execucao' => $last,
            'ultimo_cron' => $lastCron,
            'versao' => GR_VERSION,
        ];
    }

    private function history(array $in): array
    {
        $db = $this->app->db();
        $where = ['1=1'];
        $p = [];
        if (!empty($in['status']) && isset(Statuses::LABELS[$in['status']])) {
            $where[] = 'm.status = ?';
            $p[] = $in['status'];
        }
        if (!empty($in['cliente_id'])) {
            $where[] = 'm.cliente_id = ?';
            $p[] = (int)$in['cliente_id'];
        }
        $page = min(1000, max(0, (int)($in['pagina'] ?? 0)));
        $rows = $db->all(
            'SELECT m.id, m.modo, m.cliente_id, m.telefone, m.etapa, m.vencimento, m.data_prevista, m.status, m.provedor, m.tentativas,
                    m.erro_codigo, m.erro_detalhe, m.criado_em, m.enviado_em, m.entregue_em, m.lido_em, m.status_em,
                    (SELECT GROUP_CONCAT(snap_pacote SEPARATOR \', \') FROM gr_lembretes l WHERE l.mensagem_id = m.id) AS pacotes
             FROM gr_mensagens m WHERE ' . implode(' AND ', $where) . ' ORDER BY m.id DESC LIMIT 50 OFFSET ' . ($page * 50),
            $p
        );
        $names = [];
        foreach ($rows as &$r) {
            $cid = (int)$r['cliente_id'];
            if ($cid === 0) {
                $r['cliente'] = 'Teste interno';
            } else {
                $names[$cid] ??= ($this->app->panel()->client($cid)['nome'] ?? '(cliente excluído)');
                $r['cliente'] = $names[$cid];
            }
            $r['telefone'] = Format::maskPhone($r['telefone']);
            $r['status_texto'] = Statuses::LABELS[$r['status']] ?? $r['status'];
            $r['vencimento_br'] = Format::date($r['vencimento']);
        }
        return ['itens' => $rows, 'status_disponiveis' => Statuses::LABELS];
    }

    private function message(int $id): array
    {
        $db = $this->app->db();
        $m = $db->one('SELECT * FROM gr_mensagens WHERE id=?', [$id]);
        if (!$m) {
            throw new \InvalidArgumentException('Mensagem não encontrada.');
        }
        unset($m['lease_token']);
        $m['telefone'] = Format::maskPhone($m['telefone']);
        $m['status_texto'] = Statuses::LABELS[$m['status']] ?? $m['status'];
        $m['eventos'] = $db->all('SELECT tipo, status, detalhe, ocorrido_em, registrado_em FROM gr_eventos WHERE mensagem_id=? ORDER BY id', [$id]);
        $m['lembretes'] = $db->all('SELECT pacote_id, snap_pacote, snap_valor_centavos, status, motivo FROM gr_lembretes WHERE mensagem_id=?', [$id]);
        foreach ($m['lembretes'] as &$l) {
            $l['valor'] = Format::money((int)$l['snap_valor_centavos']);
            $l['motivo_texto'] = Eligibility::label($l['motivo']);
        }
        return $m;
    }

    private function clients(array $in): array
    {
        $rows = $this->app->panel()->clients(mb_substr(trim((string)($in['q'] ?? '')), 0, 80), 50, min(1000, max(0, (int)($in['pagina'] ?? 0))) * 50);
        $contacts = $this->contacts->many(array_map('intval', array_column($rows, 'id')));
        $out = [];
        foreach ($rows as $r) {
            $c = $contacts[(int)$r['id']] ?? [];
            $phone = Format::phone($r['telefone']);
            $out[] = [
                'id' => (int)$r['id'],
                'nome' => $r['nome'],
                'telefone' => Format::maskPhone($phone),
                'telefone_valido' => $phone !== null,
                'ativo' => $r['ativo'] && !$r['excluido_flag'],
                'consentimento' => $c['consentimento'] ?? 'nenhum',
                'consentimento_em' => $c['consentimento_em'] ?? null,
                'consentimento_origem' => $c['consentimento_origem'] ?? null,
                'telefone_mudou' => ($c['consentimento'] ?? '') === 'concedido' && ($c['telefone_consentido'] ?? null) !== $phone,
                'pausado' => !empty($c['pausado']),
            ];
        }
        return ['itens' => $out];
    }

    private function consent(array $in): array
    {
        $id = (int)($in['cliente_id'] ?? 0);
        $client = $this->app->panel()->client($id);
        if (!$client) {
            throw new \InvalidArgumentException('Cliente não encontrado.');
        }
        if (($in['acao'] ?? '') === 'conceder') {
            $phone = Format::phone($client['telefone']);
            if (!$phone) {
                throw new \InvalidArgumentException('Telefone do cliente ausente ou inválido. Corrija no cadastro do painel.');
            }
            $origin = trim((string)($in['origem'] ?? ''));
            if ($origin === '') {
                throw new \InvalidArgumentException('Informe como o cliente autorizou (ex.: "respondeu SIM no WhatsApp em 30/10").');
            }
            $this->contacts->grant($id, $phone, $origin, (string)($in['observacao'] ?? ''));
        } elseif (($in['acao'] ?? '') === 'revogar') {
            $this->contacts->revoke($id, 'painel', (string)($in['observacao'] ?? ''));
        } else {
            throw new \InvalidArgumentException('Ação inválida.');
        }
        return ['ok' => true];
    }

    private function config(): array
    {
        $s = $this->app->settings();
        $all = $s->all();
        $meta = $this->app->config['provedor']['meta'] ?? [];
        return [
            'config' => $all,
            'etapas' => Stages::all($s),
            'campos' => Templates::FIELDS,
            'estado' => $this->state(),
            'provedor' => [
                'driver' => $this->app->driver(),
                // Só informa SE existe, nunca o valor.
                'credenciais' => [
                    'phone_number_id' => !empty($meta['phone_number_id']),
                    'access_token' => !empty($meta['access_token']),
                    'app_secret' => !empty($meta['app_secret']),
                    'verify_token' => !empty($meta['verify_token']),
                ],
            ],
        ];
    }

    private function preview(array $in): array
    {
        $stage = (string)($in['etapa'] ?? 'antes');
        if (!in_array($stage, Stages::CODES, true)) {
            throw new \InvalidArgumentException('Etapa inválida.');
        }
        $group = ($in['variante'] ?? '') === 'grupo';
        $text = (string)($in['texto'] ?? '');
        $errors = Templates::validate($text, $group);
        $s = $this->app->settings();
        // Prévia com o texto em edição, sem salvar.
        $dias = 0;
        foreach (Stages::all($s) as $st) {
            if ($st['codigo'] === $stage) {
                $dias = $st['dias'];
            }
        }
        $items = Engine::sampleItems('5531999990000', $this->app->clock()->now(), $group, $dias);
        $overrides = $s->all();
        $overrides['textos'][$stage][$group ? 'grupo' : 'individual'] = $text;
        if (isset($in['instrucoes_pagamento'])) {
            $overrides['instrucoes_pagamento'] = (string)$in['instrucoes_pagamento'];
        }
        if (isset($in['chave_pix'])) {
            $overrides['chave_pix'] = (string)$in['chave_pix'];
        }
        if (isset($in['rodape'])) {
            $overrides['rodape'] = (string)$in['rodape'];
        }
        $built = Templates::build(new ArraySettings($overrides), $stage, $items);
        return ['corpo' => $built['corpo'], 'erros' => $errors, 'parametros' => $built['parametros'], 'chaves_parametros' => $built['chaves_parametros']];
    }

    private function saveConfig(array $in): array
    {
        $s = $this->app->settings();
        $errors = [];
        $hm = '/^([01]\d|2[0-3]):[0-5]\d$/';
        $horario = (string)($in['horario'] ?? $s->get('horario'));
        $fim = (string)($in['janela_fim'] ?? $s->get('janela_fim'));
        if (!preg_match($hm, $horario) || !preg_match($hm, $fim) || $fim <= $horario) {
            $errors[] = 'Horários inválidos: use HH:MM e o fim da janela deve ser depois do horário de início.';
        }
        $max = (int)($in['max_tentativas'] ?? $s->get('max_tentativas'));
        if ($max < 1 || $max > 5) {
            $errors[] = 'Tentativas: entre 1 e 5.';
        }
        $textos = $s->get('textos');
        foreach (Stages::CODES as $code) {
            foreach (['individual', 'grupo'] as $v) {
                if (isset($in['textos'][$code][$v])) {
                    $t = str_replace("\r\n", "\n", (string)$in['textos'][$code][$v]);
                    foreach (Templates::validate($t, $v === 'grupo') as $e) {
                        $errors[] = Stages::label($code, 0) . " ($v): $e";
                    }
                    $textos[$code][$v] = $t;
                }
            }
        }
        $instr = str_replace("\r\n", "\n", (string)($in['instrucoes_pagamento'] ?? $s->get('instrucoes_pagamento')));
        $pix = trim((string)($in['chave_pix'] ?? $s->get('chave_pix')));
        $rodape = trim((string)($in['rodape'] ?? $s->get('rodape')));
        foreach (['Instruções de pagamento' => $instr, 'Rodapé' => $rodape] as $label => $val) {
            foreach (Templates::forbiddenContent($val) as $e) {
                $errors[] = "$label: $e";
            }
        }
        if (preg_match('~https?://|www\.|/~i', $pix)) {
            $errors[] = 'Chave Pix: não inclua links.';
        }
        if (mb_strlen($instr) > 300) {
            $errors[] = 'Instruções de pagamento: até 300 caracteres.';
        }
        if ($pix === '' || mb_strlen($pix) > 140) {
            $errors[] = 'Chave Pix obrigatória (até 140 caracteres).';
        }
        $nums = [];
        foreach ((array)($in['numeros_teste'] ?? $s->get('numeros_teste')) as $n) {
            if (trim((string)$n) === '') {
                continue;
            }
            $p = Format::phone((string)$n);
            $p ? $nums[] = $p : $errors[] = "Número de teste inválido: $n";
        }
        $modelos = $s->get('modelos');
        foreach (Stages::CODES as $code) {
            foreach (['individual', 'grupo'] as $v) {
                if (isset($in['modelos'][$code][$v])) {
                    $name = trim((string)$in['modelos'][$code][$v]);
                    if ($name !== '' && !preg_match('/^[a-z0-9_]{1,512}$/', $name)) {
                        $errors[] = "Nome de modelo inválido ($code/$v): use letras minúsculas, números e _.";
                    }
                    $modelos[$code][$v] = $name;
                }
            }
        }
        $modo = (string)($in['modo'] ?? $s->get('modo'));
        if (!in_array($modo, ['simulacao', 'real'], true)) {
            $errors[] = 'Modo inválido.';
        }
        if ($errors) {
            return ['ok' => false, 'erros' => $errors];
        }

        if ($pix !== $s->get('chave_pix') || $instr !== $s->get('instrucoes_pagamento')) {
            // Mudou a forma de pagamento: exige nova confirmação antes de qualquer envio real.
            $s->set('pagamento_confirmado', false);
            $s->set('pagamento_confirmado_em', null);
        }
        $ativo = array_key_exists('ativo', $in) ? !empty($in['ativo']) : (bool)$s->get('ativo');
        if ($ativo && !$s->get('ativo')) {
            $s->set('ativado_em', $this->app->clock()->now()->format('Y-m-d'));
        }
        $s->set('ativo', $ativo);
        $s->set('modo', $modo);
        $s->set('horario', $horario);
        $s->set('janela_fim', $fim);
        $s->set('max_tentativas', $max);
        if (isset($in['etapas']) && is_array($in['etapas'])) {
            $s->set('etapas', Stages::sanitize($in['etapas']));
        }
        $s->set('textos', $textos);
        $s->set('instrucoes_pagamento', $instr);
        $s->set('chave_pix', $pix);
        $s->set('rodape', $rodape);
        $s->set('numeros_teste', array_values(array_unique($nums)));
        if (array_key_exists('somente_numeros_teste', $in)) {
            $s->set('somente_numeros_teste', !empty($in['somente_numeros_teste']));
        }
        $s->set('modelos', $modelos);
        return ['ok' => true, 'estado' => $this->state()];
    }
}

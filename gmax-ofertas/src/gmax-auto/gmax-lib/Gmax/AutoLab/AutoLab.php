<?php
declare(strict_types=1);

namespace Gmax\AutoLab;

use DateTimeImmutable;
use DateTimeZone;
use Gmax\{Audit, Config, Db, SendResult, Settings, Time};
use PDO;
use RuntimeException;
use Throwable;

/**
 * GMAX AutoLab — homologação automática no Telegram privado, isolada da publicação comercial.
 *
 * Isolamento (o que o AutoLab NUNCA faz):
 * - não lê nem grava gmax3_pub_queue; não altera produtos, preços ou links do catálogo;
 * - não usa o publicador antigo nem Queue::processNext; não mexe nas travas comerciais
 *   (publicacao_pausada, automacao_comercial, arquivo de ativação): a autorização do AutoLab é
 *   outra frase, guardada em outras chaves, e não satisfaz nenhuma trava comercial;
 * - só envia ao Config::PRIVATE_CHAT_ID e só textos que começam com um rótulo de teste.
 *
 * Ciclo (chamado pelo Cron existente, depois das automações): no máximo um envio por horário
 * configurado; horário registrado com chave única (dois Crons ao mesmo tempo não enviam duas vezes);
 * envio "incerto" nunca é repetido; dois incertos seguidos pausam o AutoLab.
 */
final class AutoLab
{
    public const ACTIVATION_PHRASE = 'AUTORIZO_AUTOLAB_HOMOLOGACAO';
    public const LABELS = ["TESTE — OFERTA VALIDADA\n", "TESTE — CANDIDATO\n", 'SIMULAÇÃO — '];
    public const MAX_PER_DAY = 4;
    public const MAX_DAYS = 30;
    public const MIN_GAP_MIN = 60;
    public const WINDOW_MIN = 120;
    public const MAX_MEMBERS_CAP = 50;
    public const MAX_UNCERTAIN_IN_ROW = 2;
    public const DEDUP_DAYS = 7;
    /** Qualquer coisa com cara de domínio (o Telegram transforma em link mesmo sem http). */
    public const DOMAIN_RE = '~\b(?:[a-z0-9-]+\.)+(?:[a-z]{2,})\b(?:/\S*)?~iu';
    public const DEFAULTS = ['autolab_horarios' => '10:00,18:00', 'autolab_limite_diario' => '2', 'autolab_max_membros' => '10', 'autolab_link_afiliado_shopee' => '1'];

    /** Fábrica do canal (testes injetam um canal falso; em produção, o Telegram real). */
    public static $channelFactory = null;

    public static function installed(PDO $pdo): bool
    {
        return Db::tableExists($pdo, 'gmax3_autolab_messages');
    }

    public static function setting(PDO $pdo, string $key): string
    {
        return Settings::get($pdo, $key, self::DEFAULTS[$key] ?? '');
    }

    public static function state(PDO $pdo): string
    {
        return Settings::get($pdo, 'autolab_estado', 'desativado');
    }

    public static function pauseFile(): string
    {
        return Config::secretsDir() . '/gmax-autolab.pause';
    }

    public static function labelled(string $text): bool
    {
        foreach (self::LABELS as $l) {
            if (str_starts_with($text, $l)) {
                return true;
            }
        }
        return false;
    }

    /** Problemas de conteúdo que impedem o envio (última barreira antes do Telegram). @return list<string> */
    public static function textProblems(string $text, string $kind): array
    {
        $p = [];
        if (!self::labelled($text)) {
            $p[] = 'texto sem rótulo de teste';
        }
        if (mb_strlen($text) > 3500) {
            $p[] = 'texto longo demais';
        }
        preg_match_all('~https?://[^\s]+~i', $text, $m);
        if ($kind !== 'oferta_validada' && $m[0]) {
            $p[] = 'candidato/simulação não pode ter link';
        }
        if (count($m[0]) > 1) {
            $p[] = 'mais de um link';
        }
        foreach ($m[0] as $url) {
            if (!\Gmax\Url::isAffiliate('shopee', $url)) {
                $p[] = 'link fora do padrão afiliado Shopee';
            }
        }
        // Domínios sem http(s):// também viram link no Telegram (ex.: meli.la/abc, t.me/x, www.site.com).
        $rest = (string)preg_replace('~https?://[^\s]+~i', ' ', $text);
        if (preg_match(self::DOMAIN_RE, $rest)) {
            $p[] = 'texto contém endereço de site além do link permitido';
        }
        return array_values(array_unique($p));
    }

    /** @return list<string> horários locais HH:MM */
    public static function times(PDO $pdo): array
    {
        $t = array_values(array_filter(array_map('trim', explode(',', self::setting($pdo, 'autolab_horarios')))));
        sort($t);
        return array_slice($t, 0, self::MAX_PER_DAY);
    }

    public static function dailyLimit(PDO $pdo): int
    {
        return max(1, min(self::MAX_PER_DAY, (int)self::setting($pdo, 'autolab_limite_diario')));
    }

    /** Valida e grava horários e limite diário (painel). Nunca acima dos limites de segurança. */
    public static function configure(PDO $pdo, string $times, string $limit, string $actor): void
    {
        $list = array_values(array_unique(array_filter(array_map('trim', explode(',', $times)))));
        sort($list);
        if (!preg_match('/^[1-9]$/D', $limit) || (int)$limit > self::MAX_PER_DAY) {
            throw new RuntimeException('Limite diário do AutoLab: de 1 a ' . self::MAX_PER_DAY . ' mensagens');
        }
        if (!$list || count($list) > self::MAX_PER_DAY) {
            throw new RuntimeException('Informe de 1 a ' . self::MAX_PER_DAY . ' horários (HH:MM, separados por vírgula)');
        }
        $prev = null;
        foreach ($list as $t) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $t)) {
                throw new RuntimeException("Horário inválido: $t");
            }
            [$h, $m] = array_map('intval', explode(':', $t));
            $min = $h * 60 + $m;
            if ($prev !== null && $min - $prev < self::MIN_GAP_MIN) {
                throw new RuntimeException('Horários do AutoLab precisam de pelo menos ' . self::MIN_GAP_MIN . ' minutos de intervalo');
            }
            $prev = $min;
        }
        Settings::set($pdo, 'autolab_horarios', implode(',', $list), $actor);
        Settings::set($pdo, 'autolab_limite_diario', $limit, $actor);
    }

    // ------------------------------------------------------------------ destino e autorização

    /** @return list<string> problemas do destino (vazio = destino aprovado) */
    public static function destinationProblems(array $p, int $maxMembers): array
    {
        if (empty($p['ok'])) {
            return ['Consulta ao Telegram falhou: ' . ($p['error'] ?? 'erro desconhecido')];
        }
        $out = [];
        if (($p['chat_id'] ?? '') !== Config::PRIVATE_CHAT_ID) {
            $out[] = 'O Telegram devolveu outro chat_id (' . ($p['chat_id'] ?? '?') . ')';
        }
        if (!in_array($p['type'] ?? '', ['supergroup', 'group'], true)) {
            $out[] = 'Destino não é grupo privado (tipo "' . ($p['type'] ?? '?') . '")';
        }
        if (!empty($p['public_username'])) {
            $out[] = 'O grupo tem @username público: deixou de ser privado';
        }
        $n = (int)($p['members'] ?? -1);
        if ($n < 0) {
            $out[] = 'Não foi possível contar os membros do grupo';
        } elseif ($n > $maxMembers) {
            $out[] = "O grupo tem $n membros (limite do AutoLab: $maxMembers)";
        }
        if (!in_array($p['bot_status'] ?? '', ['member', 'administrator', 'creator'], true)) {
            $out[] = 'O bot não é membro ativo do grupo (situação: "' . ($p['bot_status'] ?? '?') . '")';
        }
        return $out;
    }

    private static function channel(): Channel
    {
        $f = self::$channelFactory;
        return $f !== null ? $f() : new TelegramChannel();
    }

    public static function maxMembers(PDO $pdo): int
    {
        return max(2, min(self::MAX_MEMBERS_CAP, (int)self::setting($pdo, 'autolab_max_membros')));
    }

    /** Validação somente leitura do destino (nenhuma mensagem enviada). */
    public static function validateDestination(PDO $pdo, string $actor): array
    {
        $probe = self::channel()->probe(Config::PRIVATE_CHAT_ID);
        $problems = self::destinationProblems($probe, self::maxMembers($pdo));
        $info = array_intersect_key($probe, array_flip(['chat_id', 'type', 'title', 'public_username', 'members', 'bot_status']));
        if (!$problems) {
            Settings::store($pdo, 'autolab_destino_validado_em', Time::nowSql(), $actor);
        }
        Settings::store($pdo, 'autolab_destino_info', json_encode($info + ['problemas' => $problems, 'em' => Time::nowSql()], JSON_UNESCAPED_UNICODE), $actor);
        Audit::log($pdo, $actor, 'autolab_destino_validado', 'autolab', null, ['aprovado' => !$problems, 'problemas' => $problems, 'membros' => $info['members'] ?? null, 'tipo' => $info['type'] ?? null]);
        return ['aprovado' => !$problems, 'problemas' => $problems, 'destino' => $info];
    }

    /** Primeira ativação (e renovação do período): só pela CLI, com frase própria e destino validado nas últimas 24 h. */
    public static function activate(PDO $pdo, string $phrase, int $days, string $actor): array
    {
        if (!self::installed($pdo)) {
            throw new RuntimeException('Migração 006 (AutoLab) pendente');
        }
        if (!hash_equals(self::ACTIVATION_PHRASE, $phrase)) {
            throw new RuntimeException('Frase de autorização do AutoLab incorreta');
        }
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new RuntimeException('Duração do AutoLab: de 1 a ' . self::MAX_DAYS . ' dias');
        }
        $validated = Settings::get($pdo, 'autolab_destino_validado_em', '');
        if ($validated === '' || Time::hoursSince($validated) > 24) {
            throw new RuntimeException('Valide o destino antes (gmax-admin-cli.php autolab validar-destino): a validação vale 24 h');
        }
        $until = Time::now()->modify("+$days days")->format('Y-m-d H:i:s');
        Settings::set($pdo, 'autolab_ate', $until, $actor);
        Settings::set($pdo, 'autolab_autorizado_em', Time::nowSql(), $actor);
        Settings::store($pdo, 'autolab_incertos_seguidos', '0', $actor);
        Settings::set($pdo, 'autolab_motivo', '', $actor);
        Settings::set($pdo, 'autolab_estado', 'ativo', $actor);
        Audit::log($pdo, $actor, 'autolab_ativado', 'autolab', null, ['dias' => $days, 'ate' => $until, 'destino' => Config::PRIVATE_CHAT_ID]);
        return ['estado' => 'ativo', 'ate' => $until];
    }

    public static function pause(PDO $pdo, string $reason, string $actor, string $state = 'pausado'): void
    {
        // "Pausar" só vale a partir de "ativo": nunca transforma falha/desativado/encerrado (que exigem a CLI)
        // em "pausado", que o painel poderia retomar.
        if ($state === 'pausado' && self::state($pdo) !== 'ativo') {
            return;
        }
        if (in_array($state, ['desativado', 'encerrado'], true)) {
            Settings::set($pdo, 'autolab_ate', '', $actor); // retomar exige nova ativação pela CLI
        }
        Settings::set($pdo, 'autolab_estado', $state, $actor);
        Settings::set($pdo, 'autolab_motivo', mb_substr($reason, 0, 300), $actor);
        Audit::log($pdo, $actor, $state === 'falha' ? 'autolab_falha' : 'autolab_pausado', 'autolab', null, ['motivo' => $reason]);
    }

    /** Retomar pelo painel: só a partir de "pausado" e dentro do período autorizado. "falha" e "encerrado" exigem a CLI. */
    public static function resume(PDO $pdo, string $actor, bool $cli = false): void
    {
        $st = self::state($pdo);
        $allowed = $cli ? ['pausado', 'falha'] : ['pausado'];
        if (!in_array($st, $allowed, true)) {
            throw new RuntimeException($st === 'falha'
                ? 'AutoLab em falha: corrija a causa, valide o destino e retome pela CLI (autolab retomar)'
                : 'AutoLab não está pausado (estado: ' . $st . '). Para ativar ou renovar o período use a CLI.');
        }
        if (Time::now()->format('Y-m-d H:i:s') > Settings::get($pdo, 'autolab_ate', '')) {
            throw new RuntimeException('Período autorizado do AutoLab terminou: renove pela CLI (autolab ativar)');
        }
        if (is_file(self::pauseFile())) {
            throw new RuntimeException('Arquivo de pausa gmax-autolab.pause presente: apague-o antes de retomar');
        }
        Settings::store($pdo, 'autolab_incertos_seguidos', '0', $actor);
        Settings::set($pdo, 'autolab_motivo', '', $actor);
        Settings::set($pdo, 'autolab_estado', 'ativo', $actor);
        Audit::log($pdo, $actor, 'autolab_retomado', 'autolab');
    }

    // ------------------------------------------------------------------ ciclo

    private static function localTz(): DateTimeZone
    {
        return new DateTimeZone(Config::TZ);
    }

    /** Primeiro horário de hoje já vencido (até WINDOW_MIN de atraso) e ainda não registrado. */
    public static function dueSlot(PDO $pdo): ?string
    {
        $now = Time::now()->setTimezone(self::localTz());
        foreach (self::times($pdo) as $t) {
            $slot = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $t, self::localTz());
            $diff = ($now->getTimestamp() - $slot->getTimestamp()) / 60;
            $key = $slot->format('Y-m-d H:i');
            if ($diff >= 0 && $diff < self::WINDOW_MIN && Db::value($pdo, 'SELECT 1 FROM gmax3_autolab_runs WHERE slot_key=?', [$key]) === null) {
                return $key;
            }
        }
        return null;
    }

    /** Envios que chegaram (ou podem ter chegado) ao Telegram no dia local. */
    public static function sentOnDay(PDO $pdo, string $localDate): int
    {
        $start = (new DateTimeImmutable("$localDate 00:00", self::localTz()))->setTimezone(new DateTimeZone('UTC'));
        $end = $start->modify('+1 day');
        return (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_autolab_messages WHERE state IN ('reservado','confirmado','incerto') AND created_at >= ? AND created_at < ?", [
            $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Recuperação: só roda com a trava do AutoLab obtida, então qualquer linha "em andamento"
     * pertence a um processo que morreu (o servidor encerrou o PHP).
     */
    private static function recover(PDO $pdo): array
    {
        $now = Time::nowSql();
        $unc = Db::run($pdo, "UPDATE gmax3_autolab_messages SET state='incerto', finished_at=?, note='Processo interrompido durante o envio: resultado desconhecido, NÃO reenviado' WHERE state='reservado'", [$now]);
        $prep = Db::run($pdo, "UPDATE gmax3_autolab_messages SET state='falhou', finished_at=?, note='Processo interrompido antes do envio: nada foi enviado' WHERE state='preparado'", [$now]);
        $runs = Db::run($pdo, "UPDATE gmax3_autolab_runs SET status='falhou', finished_at=?, detail=COALESCE(detail,'') || ' Interrompida (processo encerrado pelo servidor); o horário não é repetido.' WHERE status='executando'", [$now]);
        if ($unc > 0) {
            self::countUncertain($pdo, $unc, 'recuperacao');
        }
        return array_filter(['incertos' => $unc, 'nao_enviados' => $prep, 'execucoes_interrompidas' => $runs]);
    }

    private static function countUncertain(PDO $pdo, int $n, string $actor): void
    {
        $k = (int)Settings::get($pdo, 'autolab_incertos_seguidos', '0') + $n;
        Settings::store($pdo, 'autolab_incertos_seguidos', (string)$k, $actor);
        if ($k >= self::MAX_UNCERTAIN_IN_ROW && self::state($pdo) === 'ativo') {
            self::pause($pdo, "$k envios seguidos sem confirmação do Telegram: confira o grupo e retome", $actor);
        }
    }

    private static function finishRun(PDO $pdo, int $runId, string $status, string $detail, array $checks = []): void
    {
        Db::run($pdo, 'UPDATE gmax3_autolab_runs SET status=?, finished_at=?, detail=?, checks=? WHERE id=?', [
            $status, Time::nowSql(), mb_substr($detail, 0, 2000), $checks ? json_encode($checks, JSON_UNESCAPED_UNICODE) : null, $runId,
        ]);
    }

    /** Um ciclo do AutoLab (Cron). Nunca lança exceção para o chamador. */
    public static function tick(PDO $pdo, string $actor = 'cron'): array
    {
        if (!self::installed($pdo)) {
            return ['resultado' => 'sem_migracao_006'];
        }
        $fh = @fopen(Config::secretsDir() . '/gmax-autolab.mutex', 'c');
        if ($fh === false) {
            return ['resultado' => 'trava_indisponivel'];
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return ['resultado' => 'ja_em_execucao'];
        }
        try {
            return self::cycle($pdo, $actor);
        } catch (Throwable $e) {
            return ['resultado' => 'erro', 'detalhe' => mb_substr($e->getMessage(), 0, 200)];
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private static function cycle(PDO $pdo, string $actor): array
    {
        $recovered = self::recover($pdo);
        $base = $recovered ? ['recuperado' => $recovered] : [];
        $state = self::state($pdo);
        if ($state !== 'ativo') {
            return $base + ['resultado' => 'autolab_' . $state];
        }
        if (is_file(self::pauseFile())) {
            self::pause($pdo, 'Arquivo de pausa gmax-autolab.pause presente', $actor);
            return $base + ['resultado' => 'autolab_pausado'];
        }
        if (Time::nowSql() > Settings::get($pdo, 'autolab_ate', '')) {
            self::pause($pdo, 'Período autorizado terminou', $actor, 'encerrado');
            return $base + ['resultado' => 'autolab_encerrado'];
        }
        $slot = self::dueSlot($pdo);
        if ($slot === null) {
            return $base + ['resultado' => 'nada_a_fazer'];
        }
        try {
            Db::run($pdo, "INSERT INTO gmax3_autolab_runs(slot_key,status,started_at) VALUES(?,'executando',?)", [$slot, Time::nowSql()]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                return $base + ['resultado' => 'horario_ja_executado', 'horario' => $slot];
            }
            throw $e;
        }
        $runId = (int)$pdo->lastInsertId();
        $msgId = 0;
        try {
            $day = substr($slot, 0, 10);
            if (self::sentOnDay($pdo, $day) >= self::dailyLimit($pdo)) {
                self::finishRun($pdo, $runId, 'sem_envio', 'Limite diário do AutoLab atingido (' . self::dailyLimit($pdo) . ')');
                return $base + ['resultado' => 'limite_diario', 'horario' => $slot];
            }
            $last = Db::value($pdo, "SELECT MAX(created_at) FROM gmax3_autolab_messages WHERE state IN ('reservado','confirmado','incerto')");
            if (is_string($last) && Time::hoursSince($last) * 60 < self::MIN_GAP_MIN) {
                self::finishRun($pdo, $runId, 'sem_envio', 'Intervalo mínimo de ' . self::MIN_GAP_MIN . ' min desde o último envio');
                return $base + ['resultado' => 'intervalo_minimo', 'horario' => $slot];
            }
            try {
                $channel = self::channel();
            } catch (Throwable $e) {
                self::finishRun($pdo, $runId, 'bloqueado', 'Canal Telegram indisponível: ' . $e->getMessage());
                self::pause($pdo, 'Canal Telegram indisponível: ' . $e->getMessage(), $actor, 'falha');
                return $base + ['resultado' => 'falha_canal', 'horario' => $slot];
            }
            $probe = $channel->probe(Config::PRIVATE_CHAT_ID);
            if ($problems = self::destinationProblems($probe, self::maxMembers($pdo))) {
                $critical = !empty($probe['ok']); // destino respondeu e está errado = crítico; rede fora = só pula o horário
                self::finishRun($pdo, $runId, 'bloqueado', 'Destino recusado: ' . implode('; ', $problems));
                if ($critical) {
                    self::pause($pdo, 'Destino recusado: ' . implode('; ', $problems), $actor, 'falha');
                }
                return $base + ['resultado' => $critical ? 'destino_recusado' : 'destino_sem_resposta', 'problemas' => $problems, 'horario' => $slot];
            }
            $c = Composer::compose($pdo, $slot);
            if ($problems = self::textProblems($c['text'], $c['kind'])) {
                self::finishRun($pdo, $runId, 'bloqueado', 'Mensagem recusada: ' . implode('; ', $problems), $c['checks']);
                return $base + ['resultado' => 'mensagem_recusada', 'problemas' => $problems];
            }
            Db::run($pdo, 'INSERT INTO gmax3_autolab_messages(run_id,slot_key,kind,marketplace,catalog_id,candidate_id,source,price_confirmed,price_cents,price_catalog_cents,price_checked_at,link_kind,dedup_key,text,text_hash,state,missing,created_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'preparado\',?,?)', [
                $runId, $slot, $c['kind'], $c['marketplace'], $c['catalog_id'], $c['candidate_id'], $c['source'], $c['price_confirmed'] ? 1 : 0, $c['price_cents'],
                $c['price_catalog_cents'], $c['price_checked_at'], $c['link_kind'], $c['dedup_key'], $c['text'], hash('sha256', $c['text']),
                $c['missing'] ? json_encode($c['missing'], JSON_UNESCAPED_UNICODE) : null, Time::nowSql(),
            ]);
            $msgId = (int)$pdo->lastInsertId();
            // Reserva conservadora: pausa feita há instantes vence; a partir daqui um erro vira "incerto".
            $reserved = Db::immediate($pdo, static function (PDO $pdo) use ($msgId) {
                if (self::state($pdo) !== 'ativo' || is_file(self::pauseFile())) {
                    return 0;
                }
                return Db::run($pdo, "UPDATE gmax3_autolab_messages SET state='reservado', reserved_at=? WHERE id=? AND state='preparado'", [Time::nowSql(), $msgId]);
            });
            if ($reserved !== 1) {
                Db::run($pdo, "UPDATE gmax3_autolab_messages SET state='falhou', finished_at=?, note='AutoLab pausado durante a preparação: nada enviado' WHERE id=? AND state='preparado'", [Time::nowSql(), $msgId]);
                self::finishRun($pdo, $runId, 'sem_envio', 'Pausado durante a preparação', $c['checks']);
                return $base + ['resultado' => 'pausado_durante_preparo'];
            }
            try {
                $res = $channel->send(Config::PRIVATE_CHAT_ID, $c['text']);
            } catch (Throwable $e) {
                $res = new SendResult(SendResult::UNCERTAIN, 0, null, 'Exceção durante o envio: ' . get_class($e));
            }
            Db::run($pdo, "UPDATE gmax3_autolab_messages SET state=?, http_code=?, telegram_message_id=?, finished_at=?, note=? WHERE id=? AND state='reservado'", [
                $res->outcome, $res->httpCode, $res->messageId, Time::nowSql(), mb_substr($res->note, 0, 300), $msgId,
            ]);
            Audit::log($pdo, $actor, 'autolab_envio_' . $res->outcome, 'autolab_message', $msgId, ['http' => $res->httpCode, 'message_id' => $res->messageId, 'tipo' => $c['kind']]);
            if ($res->outcome === SendResult::CONFIRMED) {
                Settings::store($pdo, 'autolab_incertos_seguidos', '0', $actor);
            } elseif ($res->outcome === SendResult::UNCERTAIN) {
                self::countUncertain($pdo, 1, $actor);
            } elseif (in_array($res->httpCode, [401, 403, 404], true) || ($res->httpCode === 400 && stripos($res->note, 'chat') !== false)) {
                self::pause($pdo, 'Telegram recusou o envio (HTTP ' . $res->httpCode . '): ' . $res->note, $actor, 'falha');
            }
            self::finishRun($pdo, $runId, 'concluido', 'Mensagem ' . $c['kind'] . ': ' . $res->outcome . ($res->note ? ' — ' . $res->note : ''), $c['checks']);
            return $base + ['resultado' => $res->outcome, 'horario' => $slot, 'tipo' => $c['kind'], 'mensagem' => $msgId];
        } catch (Throwable $e) {
            if ($msgId > 0) {
                $n = Db::run($pdo, "UPDATE gmax3_autolab_messages SET state='incerto', finished_at=?, note=? WHERE id=? AND state='reservado'", [Time::nowSql(), 'Erro após a reserva: ' . mb_substr($e->getMessage(), 0, 200), $msgId]);
                Db::run($pdo, "UPDATE gmax3_autolab_messages SET state='falhou', finished_at=?, note=? WHERE id=? AND state='preparado'", [Time::nowSql(), 'Erro antes do envio: ' . mb_substr($e->getMessage(), 0, 200), $msgId]);
                if ($n > 0) {
                    self::countUncertain($pdo, 1, $actor);
                }
            }
            self::finishRun($pdo, $runId, 'falhou', 'Erro: ' . get_class($e) . ' — ' . mb_substr($e->getMessage(), 0, 300));
            return $base + ['resultado' => 'falhou', 'detalhe' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    // ------------------------------------------------------------------ painel e relatórios

    public static function nextSlot(PDO $pdo): ?string
    {
        $now = Time::now()->setTimezone(self::localTz());
        for ($d = 0; $d < 2; $d++) {
            $day = $now->modify("+$d day")->format('Y-m-d');
            foreach (self::times($pdo) as $t) {
                $slot = new DateTimeImmutable("$day $t", self::localTz());
                $key = $slot->format('Y-m-d H:i');
                if ($slot->getTimestamp() + self::WINDOW_MIN * 60 > $now->getTimestamp() && Db::value($pdo, 'SELECT 1 FROM gmax3_autolab_runs WHERE slot_key=?', [$key]) === null) {
                    return $key;
                }
            }
        }
        return null;
    }

    /** Resumo por dia local (relatório diário). @return list<array> */
    public static function report(PDO $pdo, int $days = 7): array
    {
        $out = [];
        $today = Time::now()->setTimezone(self::localTz());
        for ($i = 0; $i < $days; $i++) {
            $day = $today->modify("-$i day")->format('Y-m-d');
            $start = (new DateTimeImmutable("$day 00:00", self::localTz()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $end = (new DateTimeImmutable("$day 00:00", self::localTz()))->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $m = $pdo->prepare('SELECT state, COUNT(*) FROM gmax3_autolab_messages WHERE created_at >= ? AND created_at < ? GROUP BY state');
            $m->execute([$start, $end]);
            $states = $m->fetchAll(PDO::FETCH_KEY_PAIR);
            $k = $pdo->prepare('SELECT kind, COUNT(*) FROM gmax3_autolab_messages WHERE created_at >= ? AND created_at < ? GROUP BY kind');
            $k->execute([$start, $end]);
            $r = $pdo->prepare("SELECT status, COUNT(*) FROM gmax3_autolab_runs WHERE started_at >= ? AND started_at < ? GROUP BY status");
            $r->execute([$start, $end]);
            $runs = $r->fetchAll(PDO::FETCH_KEY_PAIR);
            $out[] = ['dia' => $day, 'enviadas' => (int)($states['confirmado'] ?? 0), 'incertas' => (int)($states['incerto'] ?? 0), 'falhas_envio' => (int)($states['falhou'] ?? 0),
                'bloqueadas' => (int)($runs['bloqueado'] ?? 0), 'sem_envio' => (int)($runs['sem_envio'] ?? 0), 'erros' => (int)($runs['falhou'] ?? 0),
                'tipos' => $k->fetchAll(PDO::FETCH_KEY_PAIR)];
        }
        return $out;
    }
}

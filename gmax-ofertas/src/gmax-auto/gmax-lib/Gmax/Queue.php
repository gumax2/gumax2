<?php
declare(strict_types=1);

namespace Gmax;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * Fila de publicação segura.
 * agendado -> reservado -> confirmado | falhou | incerto
 * Um item "reservado" ou "incerto" pausa TODA a fila até reconciliação manual: na dúvida,
 * nada é republicado.
 */
final class Queue
{
    public const STATES = [
        'agendado' => 'Agendado',
        'reservado' => 'Reservado (tentativa em curso)',
        'confirmado' => 'Publicado (confirmado)',
        'incerto' => 'Envio incerto — reconciliar',
        'falhou' => 'Falhou (não publicado)',
        'bloqueado' => 'Bloqueado por regra',
        'cancelado' => 'Cancelado',
    ];

    /** Próximo horário livre (um item por horário), para enfileiramento em lote não virar rajada. */
    public static function nextSlot(PDO $pdo, ?DateTimeImmutable $from = null): string
    {
        $taken = array_flip($pdo->query("SELECT scheduled_for FROM gmax3_pub_queue WHERE state IN ('agendado','reservado')")->fetchAll(PDO::FETCH_COLUMN));
        $at = $from;
        for ($i = 0; $i < 200; $i++) {
            $slot = self::slotAfter($pdo, $at);
            if (!isset($taken[$slot])) {
                return $slot;
            }
            $at = new DateTimeImmutable($slot, new DateTimeZone('UTC'));
        }
        throw new RuntimeException('Sem horários livres nos próximos dias');
    }

    private static function slotAfter(PDO $pdo, ?DateTimeImmutable $from): string
    {
        $tz = new DateTimeZone(Config::TZ);
        $now = ($from ?? Time::now())->setTimezone($tz);
        $times = array_filter(explode(',', Settings::get($pdo, 'horarios', '08:00,10:00,12:00,15:00,18:00,20:00')));
        sort($times);
        for ($d = 0; $d < 60; $d++) {
            $day = $now->modify("+$d days");
            foreach ($times as $t) {
                [$h, $m] = array_map('intval', explode(':', $t));
                $slot = $day->setTime($h, $m);
                if ($slot > $now) {
                    return $slot->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                }
            }
        }
        return Time::now()->modify('+1 day')->format('Y-m-d H:i:s');
    }

    /** @return list<string> motivos de recusa (vazio = enfileirado) */
    public static function enqueue(PDO $pdo, int $catalogId, string $channel, ?string $scheduledUtc, string $actor): array
    {
        return Db::immediate($pdo, static function (PDO $pdo) use ($catalogId, $channel, $scheduledUtc, $actor) {
            $r = Catalog::get($pdo, $catalogId);
            if (!$r) {
                return ['Produto não encontrado'];
            }
            $reasons = Rules::blockers($pdo, $r, $channel);
            if ($dup = Rules::duplicate($pdo, $r)) {
                $reasons[] = $dup;
            }
            if ($reasons) {
                return $reasons;
            }
            $text = Message::render($r);
            Db::run($pdo, 'INSERT INTO gmax3_pub_queue(catalog_id,channel,state,scheduled_for,message_text,message_hash,created_by,created_at) VALUES(?,?,?,?,?,?,?,?)', [
                $catalogId, $channel, 'agendado', $scheduledUtc ?? self::nextSlot($pdo), $text, hash('sha256', $text), $actor, Time::nowSql(),
            ]);
            Audit::log($pdo, $actor, 'fila_adicionado', 'pub_queue', $pdo->lastInsertId(), ['catalog_id' => $catalogId, 'canal' => $channel]);
            return [];
        });
    }

    public static function cancel(PDO $pdo, int $id, string $actor): bool
    {
        $n = Db::run($pdo, "UPDATE gmax3_pub_queue SET state='cancelado', finished_at=?, note=COALESCE(note,'') || ' Cancelado manualmente.' WHERE id=? AND state IN ('agendado','bloqueado')", [Time::nowSql(), $id]);
        if ($n) {
            Audit::log($pdo, $actor, 'fila_cancelado', 'pub_queue', $id);
        }
        return $n === 1;
    }

    /**
     * Reconciliação manual de item reservado/incerto, após conferir o grupo no Telegram.
     * $published=true: a mensagem está no grupo (vira confirmado, nunca será reenviada).
     * $published=false: a mensagem NÃO está no grupo (vira falhou; pode ser reagendada).
     */
    public static function reconcile(PDO $pdo, int $id, bool $published, ?int $messageId, string $actor): void
    {
        Db::immediate($pdo, static function (PDO $pdo) use ($id, $published, $messageId, $actor) {
            $q = Db::one($pdo, 'SELECT * FROM gmax3_pub_queue WHERE id=?', [$id]) ?? throw new RuntimeException('Item da fila não encontrado');
            if (!in_array($q['state'], ['reservado', 'incerto'], true)) {
                throw new RuntimeException('Somente itens reservados ou incertos são reconciliados');
            }
            Db::run($pdo, 'UPDATE gmax3_pub_queue SET state=?, telegram_message_id=COALESCE(?, telegram_message_id), reconciled_by=?, reconciled_at=?, finished_at=COALESCE(finished_at, ?), note=? WHERE id=?', [
                $published ? 'confirmado' : 'falhou', $messageId, $actor, Time::nowSql(), Time::nowSql(),
                trim(($q['note'] ?? '') . ' Reconciliado manualmente: ' . ($published ? 'mensagem encontrada no grupo.' : 'mensagem NÃO encontrada no grupo.')), $id,
            ]);
            Audit::log($pdo, $actor, 'fila_reconciliado', 'pub_queue', $id, ['publicado' => $published, 'message_id' => $messageId]);
        });
    }

    /** Pendências que impedem qualquer envio (fila 3.0 e publicador V2.1). @return list<string> */
    public static function unreconciled(PDO $pdo): array
    {
        $out = [];
        foreach (Db::all($pdo, "SELECT id, state FROM gmax3_pub_queue WHERE state IN ('reservado','incerto') ORDER BY id") as $q) {
            $out[] = "Fila #{$q['id']} em estado {$q['state']}";
        }
        if (Db::tableExists($pdo, 'slots') && in_array('status', Db::columns($pdo, 'slots'), true)) {
            foreach (Db::all($pdo, "SELECT slot FROM slots WHERE status='tentativa'") as $s) {
                $out[] = "cron antigo (slots): janela {$s['slot']} com tentativa não reconciliada";
            }
        }
        if (Db::tableExists($pdo, 'gmax_dispatches')) {
            if (array_diff(['slot', 'item_id', 'status'], Db::columns($pdo, 'gmax_dispatches'))) {
                // Formato diferente do esperado: bloqueia de forma conservadora.
                $out[] = 'Tabela gmax_dispatches com colunas diferentes do publicador V2.1 conhecido — revisar manualmente';
                return $out;
            }
            $hasOffers = Db::tableExists($pdo, 'offers');
            $sql = "SELECT slot, item_id, status FROM gmax_dispatches WHERE status IN ('attempted','uncertain')"
                . ($hasOffers ? " OR (status='confirmed' AND EXISTS (SELECT 1 FROM offers o WHERE o.item_id=gmax_dispatches.item_id AND o.status='pendente'))" : '');
            foreach (Db::all($pdo, $sql) as $d) {
                $out[] = "Publicador V2.1: janela {$d['slot']} item {$d['item_id']} em estado {$d['status']}";
            }
        }
        return $out;
    }

    /** Travas globais de envio real. @return list<string> */
    public static function gates(PDO $pdo): array
    {
        $g = [];
        if (Settings::get($pdo, 'publicacao_pausada', '1') !== '0') {
            $g[] = 'Publicação pausada: ' . Settings::get($pdo, 'motivo_pausa', '');
        }
        if (is_file(Config::pauseFile())) {
            $g[] = 'Arquivo de pausa presente (gmax-publicacao.pause)';
        }
        if (Settings::get($pdo, 'automacao_comercial', '0') !== '1') {
            $g[] = 'Automação comercial desativada (exige autorização explícita via CLI)';
        }
        if (!self::activationValid()) {
            $g[] = 'Arquivo de ativação gmax3-publicacao-privada.php ausente ou inválido';
        }
        if (is_file(Config::legacyActivationFile())) {
            $g[] = 'Publicador V2.1 está ativado (gmax-publicacao-privada.php): os dois publicadores não podem operar juntos';
        }
        foreach (self::unreconciled($pdo) as $u) {
            $g[] = 'Pendência de reconciliação: ' . $u;
        }
        return $g;
    }

    public static function activationValid(): bool
    {
        $file = Config::activationFile();
        if (!is_readable($file)) {
            return false;
        }
        $a = require $file;
        return is_array($a)
            && ($a['enabled'] ?? false) === true
            && (string)($a['chat_id'] ?? '') === Config::PRIVATE_CHAT_ID
            && (string)($a['authorization'] ?? '') === Config::ACTIVATION_PHRASE;
    }

    /**
     * Processa no máximo UM item vencido.
     * $simulate=true: apenas relata o que seria feito; não muda estados nem chama o transporte.
     * @param callable():Transport|null $transportFactory criado somente se todas as travas liberarem
     */
    public static function processNext(PDO $pdo, bool $simulate, ?callable $transportFactory, string $actor): array
    {
        $lockPath = Config::lockFile();
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            return ['resultado' => 'mutex_indisponivel', 'detalhe' => 'Pasta privada sem permissão de escrita'];
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return ['resultado' => 'worker_ja_em_execucao'];
        }
        try {
            self::heartbeat($pdo, $simulate ? 'fila_simulacao' : 'fila_processador');
            $pending = self::unreconciled($pdo);
            if ($pending) {
                if (!$simulate && Settings::get($pdo, 'publicacao_pausada') !== '1') {
                    Settings::pause($pdo, 'Pausa automática: ' . $pending[0], $actor);
                }
                return ['resultado' => 'pausa_reconciliacao', 'pendencias' => $pending];
            }
            $now = Time::nowSql();
            $item = Db::one($pdo, "SELECT * FROM gmax3_pub_queue WHERE state='agendado' AND scheduled_for <= ? ORDER BY scheduled_for, id LIMIT 1", [$now]);
            if (!$item) {
                return ['resultado' => 'fila_vazia'];
            }
            $r = Catalog::get($pdo, (int)$item['catalog_id']);
            $reasons = $r ? Rules::blockers($pdo, $r, $item['channel']) : ['Produto removido do catálogo'];
            if ($r && ($dup = Rules::duplicate($pdo, $r, (int)$item['id']))) {
                $reasons[] = $dup;
            }
            if (Time::hoursSince($item['scheduled_for']) > 2) {
                $reasons[] = 'Horário agendado expirou há mais de 2 h: reagende para não publicar oferta antiga';
            }
            if ($reasons) {
                if (!$simulate) {
                    Db::run($pdo, "UPDATE gmax3_pub_queue SET state='bloqueado', finished_at=?, note=? WHERE id=? AND state='agendado'", [$now, mb_substr(implode(' | ', $reasons), 0, 1000), $item['id']]);
                    Audit::log($pdo, $actor, 'fila_bloqueado', 'pub_queue', $item['id'], ['motivos' => $reasons]);
                }
                return ['resultado' => 'bloqueado', 'fila_id' => (int)$item['id'], 'motivos' => $reasons];
            }
            $text = Message::render($r);
            if ($simulate) {
                return ['resultado' => 'simulacao_sem_envio', 'fila_id' => (int)$item['id'], 'mensagem' => $text, 'travas' => self::gates($pdo)];
            }
            if ($gates = self::gates($pdo)) {
                return ['resultado' => 'envio_bloqueado', 'fila_id' => (int)$item['id'], 'travas' => $gates];
            }
            if ($transportFactory === null) {
                throw new RuntimeException('Transporte não configurado');
            }
            $transport = $transportFactory();
            $reserved = Db::immediate($pdo, static function (PDO $pdo) use ($item, $text, $r) {
                // Rechecagem dentro da trava de escrita: pausa ou edição feitas há instantes vencem.
                $now = Catalog::get($pdo, (int)$r['id']);
                if (Settings::paused($pdo) || !$now || $now['updated_at'] !== $r['updated_at'] || $now['validation_status'] !== 'aprovado') {
                    return -1;
                }
                return Db::run($pdo, "UPDATE gmax3_pub_queue SET state='reservado', attempts=attempts+1, reserved_at=?, message_text=?, message_hash=?, note='Estado conservador: reconciliar antes de repetir' WHERE id=? AND state='agendado'", [
                    Time::nowSql(), $text, hash('sha256', $text), $item['id'],
                ]);
            });
            if ($reserved === -1) {
                return ['resultado' => 'abortado_mudanca_recente', 'fila_id' => (int)$item['id']];
            }
            if ($reserved !== 1) {
                return ['resultado' => 'concorrencia_item_ja_reservado', 'fila_id' => (int)$item['id']];
            }
            Audit::log($pdo, $actor, 'fila_reservado', 'pub_queue', $item['id']);
            try {
                $res = $transport->send(Config::PRIVATE_CHAT_ID, $text);
            } catch (\Throwable $e) {
                $res = new SendResult(SendResult::UNCERTAIN, 0, null, 'Exceção durante o envio: ' . get_class($e));
            }
            Db::run($pdo, 'UPDATE gmax3_pub_queue SET state=?, http_code=?, telegram_message_id=?, finished_at=?, note=? WHERE id=? AND state=\'reservado\'', [
                $res->outcome, $res->httpCode, $res->messageId, Time::nowSql(), $res->note, $item['id'],
            ]);
            Audit::log($pdo, $actor, 'fila_' . $res->outcome, 'pub_queue', $item['id'], ['http' => $res->httpCode, 'message_id' => $res->messageId, 'nota' => $res->note]);
            if ($res->outcome === SendResult::UNCERTAIN) {
                Settings::pause($pdo, "Pausa automática: envio incerto na fila #{$item['id']}", $actor);
            }
            return ['resultado' => $res->outcome, 'fila_id' => (int)$item['id'], 'http' => $res->httpCode, 'message_id' => $res->messageId, 'nota' => $res->note];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function heartbeat(PDO $pdo, string $name, string $detail = ''): void
    {
        Db::run($pdo, 'INSERT INTO gmax3_heartbeats(name,last_at,detail) VALUES(?,?,?) ON CONFLICT(name) DO UPDATE SET last_at=excluded.last_at, detail=excluded.detail', [$name, Time::nowSql(), $detail]);
    }
}

<?php
declare(strict_types=1);

namespace Gmax\Automation;

use Gmax\Audit;
use Gmax\Db;
use Gmax\Time;
use PDO;

/** Alertas que pedem intervenção humana. Um alerta aberto por (tipo, produto); reabrir só atualiza a data. */
final class Alerts
{
    public const KINDS = [
        'sem_link' => 'Sem link afiliado oficial',
        'link_invalido' => 'Link fora do padrão oficial',
        'preco_nao_confirmado' => 'Preço não confirmado por fonte oficial',
        'indisponivel' => 'Anúncio indisponível ou pausado',
        'variacao' => 'Variação pendente de escolha',
        'duplicado_possivel' => 'Possível produto duplicado',
        'aprovacao_pendente' => 'Oferta pronta aguardando aprovação manual',
        'integracao' => 'Falha ou ausência de integração',
        'falha_automacao' => 'Falha em tarefa automática',
        'arquivado_auto' => 'Arquivado automaticamente',
    ];

    public static function raise(PDO $pdo, string $kind, string $message, ?int $catalogId = null): void
    {
        $key = $kind . '|' . ($catalogId ?? '-') . ($catalogId === null ? '|' . md5($message) : '');
        $now = Time::nowSql();
        $open = Db::value($pdo, 'SELECT id FROM gmax3_alerts WHERE dedup_key=? AND resolved_at IS NULL', [$key]);
        if ($open !== null) {
            Db::run($pdo, 'UPDATE gmax3_alerts SET last_seen_at=?, message=? WHERE id=?', [$now, mb_substr($message, 0, 400), $open]);
            return;
        }
        Db::run($pdo, 'INSERT INTO gmax3_alerts(catalog_id,kind,message,dedup_key,created_at,last_seen_at) VALUES(?,?,?,?,?,?)', [$catalogId, $kind, mb_substr($message, 0, 400), $key, $now, $now]);
    }

    /** Fecha automaticamente um alerta cuja condição deixou de existir. */
    public static function clear(PDO $pdo, string $kind, int $catalogId): void
    {
        Db::run($pdo, "UPDATE gmax3_alerts SET resolved_at=?, resolved_by='automatico' WHERE dedup_key=? AND resolved_at IS NULL", [Time::nowSql(), "$kind|$catalogId"]);
    }

    public static function resolve(PDO $pdo, int $id, string $actor): bool
    {
        $n = Db::run($pdo, 'UPDATE gmax3_alerts SET resolved_at=?, resolved_by=? WHERE id=? AND resolved_at IS NULL', [Time::nowSql(), $actor, $id]);
        if ($n) {
            Audit::log($pdo, $actor, 'alerta_resolvido', 'alerta', $id);
        }
        return $n === 1;
    }
}

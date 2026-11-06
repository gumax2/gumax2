<?php
declare(strict_types=1);

namespace Gmax;

use PDO;
use RuntimeException;

/**
 * Conferência progressiva por rodadas (3.2.2.2), persistida no SQLite (migração 007).
 *
 * Três conceitos separados:
 *  A. PROGRESSO da conferência (esta classe): não analisado, analisado, pulado, precisa de revisão;
 *  B. PENDÊNCIAS comerciais (Funnel/Catalog::approvalIssues): variação, preço, link, aprovação…;
 *  C. PRONTIDÃO para publicar (Ranking::readiness/Rules): inalterada, sem flexibilização.
 * Analisar um produto NÃO altera A↔B↔C: ele pode continuar pendente e não pronto.
 *
 * Regras: a rodada só começa (ou recomeça) por ação explícita; a fotografia inclui todos os produtos
 * ativos existentes no início; produtos cadastrados depois ficam em "recém-chegados" até serem incluídos
 * explicitamente; mudança relevante feita por automação num produto analisado é SINALIZADA (derivado,
 * sem reescrever o progresso) e nunca o devolve sozinho aos "não analisados".
 */
final class Review
{
    public const STATES = ['nao_analisado' => 'Ainda não analisados', 'analisado' => 'Analisados nesta rodada', 'pulado' => 'Pulados', 'revisar' => 'Precisam de revisão'];
    public const SECTIONS = self::STATES + ['novos' => 'Recém-chegados'];
    /** Mudança de preço a partir da qual um produto analisado precisa de revisão. */
    public const PRICE_CHANGE_PCT = 1;

    public static function installed(PDO $pdo): bool
    {
        return Db::tableExists($pdo, 'gmax3_review_rounds');
    }

    public static function open(PDO $pdo, string $admin): ?array
    {
        return self::installed($pdo) ? Db::one($pdo, "SELECT * FROM gmax3_review_rounds WHERE admin=? AND status='aberta'", [$admin]) : null;
    }

    public static function last(PDO $pdo, string $admin): ?array
    {
        return self::installed($pdo) ? Db::one($pdo, 'SELECT * FROM gmax3_review_rounds WHERE admin=? ORDER BY id DESC LIMIT 1', [$admin]) : null;
    }

    /**
     * Condição SQL "precisa de revisão": marcado pelo usuário OU analisado e mudou de forma relevante
     * depois da análise (preço ≥ 1%, disponibilidade, variação reaberta, link ou estado). i = item, c = catálogo.
     */
    public static function reviewSql(): string
    {
        $p = self::PRICE_CHANGE_PCT;
        return "(i.state = 'revisar' OR (i.state = 'analisado' AND (
            (i.base_price_cents IS NULL) <> (c.price_promo_cents IS NULL)
            OR ABS(COALESCE(c.price_promo_cents,0) - COALESCE(i.base_price_cents,0)) * 100 >= $p * MAX(1, COALESCE(i.base_price_cents,0))
            OR COALESCE(c.availability,'') <> COALESCE(i.base_availability,'')
            OR (c.variation_pending = 1 AND COALESCE(i.base_variation,0) = 0)
            OR COALESCE(c.affiliate_url,'') <> COALESCE(i.base_affiliate,'')
            OR c.validation_status <> COALESCE(i.base_status, c.validation_status))))";
    }

    private const ACTIVE = "c.validation_status NOT IN ('arquivado','rejeitado')";

    /** Nova rodada (ação explícita): encerra a aberta (histórico preservado) e fotografa os produtos ativos. */
    public static function start(PDO $pdo, string $admin, string $actor): array
    {
        if (!self::installed($pdo)) {
            throw new RuntimeException('Aplique a migração 007 em Sistema para usar a conferência progressiva');
        }
        // Ordem: primeiro o que tem pendência (ordem da fila de trabalho), depois o restante, por ID.
        $ordered = [];
        foreach (Funnel::workQueue($pdo) as $x) {
            $ordered[(int)$x['item']['id']] = true;
        }
        foreach ($pdo->query("SELECT id FROM gmax3_catalog c WHERE " . self::ACTIVE . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ordered[(int)$id] ??= true;
        }
        $ids = array_keys($ordered);
        return Db::immediate($pdo, static function (PDO $pdo) use ($admin, $ids, $actor) {
            $old = Db::one($pdo, "SELECT id FROM gmax3_review_rounds WHERE admin=? AND status='aberta'", [$admin]);
            if ($old) {
                Db::run($pdo, "UPDATE gmax3_review_rounds SET status='encerrada', closed_at=? WHERE id=?", [Time::nowSql(), $old['id']]);
            }
            Db::run($pdo, "INSERT INTO gmax3_review_rounds(admin,status,started_at,total_initial) VALUES(?,'aberta',?,?)", [$admin, Time::nowSql(), count($ids)]);
            $rid = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT INTO gmax3_review_items(round_id,catalog_id,position,added_as) VALUES(?,?,?,'inicial')");
            foreach ($ids as $pos => $id) {
                $ins->execute([$rid, $id, $pos + 1]);
            }
            Audit::log($pdo, $actor, 'rodada_conferencia_iniciada', 'review_round', $rid, ['produtos' => count($ids), 'rodada_anterior' => $old['id'] ?? null]);
            return Db::one($pdo, 'SELECT * FROM gmax3_review_rounds WHERE id=?', [$rid]);
        });
    }

    /** Próximo produto ainda não analisado da rodada (SQL, sem carregar a lista inteira). */
    public static function nextId(PDO $pdo, int $roundId, ?int $exclude = null): ?int
    {
        $id = Db::value($pdo, "SELECT i.catalog_id FROM gmax3_review_items i JOIN gmax3_catalog c ON c.id = i.catalog_id
            WHERE i.round_id = ? AND i.state = 'nao_analisado' AND " . self::ACTIVE . ' AND i.catalog_id <> ? ORDER BY i.position LIMIT 1', [$roundId, $exclude ?? 0]);
        return $id === null || $id === false ? null : (int)$id;
    }

    public static function item(PDO $pdo, int $roundId, int $catalogId): ?array
    {
        return Db::one($pdo, 'SELECT * FROM gmax3_review_items WHERE round_id=? AND catalog_id=?', [$roundId, $catalogId]);
    }

    /**
     * Registra a análise (ou pulo, ou "revisar depois") NA RODADA. Não muda nenhum dado comercial do produto.
     * $result: analisado | pulado | revisar. Revisitar um analisado registra nova visita e atualiza a linha de base.
     */
    public static function record(PDO $pdo, int $roundId, int $catalogId, string $result, string $action, string $admin, ?string $detail = null): string
    {
        if (!in_array($result, ['analisado', 'pulado', 'revisar'], true)) {
            throw new RuntimeException('Resultado de análise inválido');
        }
        return Db::immediate($pdo, static function (PDO $pdo) use ($roundId, $catalogId, $result, $action, $admin, $detail) {
            $round = Db::one($pdo, 'SELECT * FROM gmax3_review_rounds WHERE id=?', [$roundId]);
            if (!$round || $round['status'] !== 'aberta') {
                throw new RuntimeException('Esta rodada de conferência foi encerrada. Abra a fila de trabalho e continue na rodada atual.');
            }
            $c = Catalog::get($pdo, $catalogId) ?? throw new RuntimeException('Produto não encontrado');
            $it = Db::one($pdo, 'SELECT * FROM gmax3_review_items WHERE round_id=? AND catalog_id=?', [$roundId, $catalogId]);
            if (!$it) { // produto revisitado que não estava na rodada (ex.: recém-chegado aberto à mão): entra como extensão
                $pos = (int)Db::value($pdo, 'SELECT COALESCE(MAX(position),0)+1 FROM gmax3_review_items WHERE round_id=?', [$roundId]);
                Db::run($pdo, "INSERT INTO gmax3_review_items(round_id,catalog_id,position,added_as) VALUES(?,?,?,'extensao')", [$roundId, $catalogId, $pos]);
                $it = ['state' => 'nao_analisado'];
            }
            $visitResult = $it['state'] === 'analisado' && $result === 'analisado' ? 'revisitado' : $result;
            $base = $result === 'analisado' ? [$c['price_promo_cents'], $c['availability'], (int)$c['variation_pending'], $c['affiliate_url'], $c['validation_status']] : null;
            Db::run($pdo, 'UPDATE gmax3_review_items SET state=?, visits=visits+1, last_result=?, last_visit_at=?, last_visit_by=?'
                . ($base ? ', base_price_cents=?, base_availability=?, base_variation=?, base_affiliate=?, base_status=?' : '') . ' WHERE round_id=? AND catalog_id=?',
                [$result, $action, Time::nowSql(), $admin, ...($base ?? []), $roundId, $catalogId]);
            Db::run($pdo, 'INSERT INTO gmax3_review_visits(round_id,catalog_id,at,admin,result,action,detail) VALUES(?,?,?,?,?,?,?)',
                [$roundId, $catalogId, Time::nowSql(), $admin, $visitResult, $action, $detail !== null ? mb_substr($detail, 0, 300) : null]);
            return $visitResult;
        });
    }

    /** Contagens da rodada (produtos arquivados/rejeitados depois do início saem das contagens). */
    public static function stats(PDO $pdo, int $roundId): array
    {
        $rv = self::reviewSql();
        $r = Db::one($pdo, "SELECT COUNT(*) total,
              SUM(i.state='nao_analisado') nao_analisado,
              SUM(i.state IN ('analisado','revisar')) analisados,
              SUM(i.state='pulado') pulado,
              SUM($rv) revisar
            FROM gmax3_review_items i JOIN gmax3_catalog c ON c.id = i.catalog_id WHERE i.round_id = ? AND " . self::ACTIVE, [$roundId]);
        $out = array_map('intval', $r ?? []);
        $out['novos'] = self::newcomersCount($pdo, $roundId);
        return $out;
    }

    public static function newcomersCount(PDO $pdo, int $roundId): int
    {
        return (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_catalog c WHERE ' . self::ACTIVE
            . ' AND NOT EXISTS (SELECT 1 FROM gmax3_review_items i WHERE i.round_id = ? AND i.catalog_id = c.id)', [$roundId]);
    }

    /** Lista paginada de uma seção (SQL com LIMIT/OFFSET). @return array{rows:list<array>,total:int} */
    public static function section(PDO $pdo, int $roundId, string $section, int $limit, int $offset): array
    {
        $rv = self::reviewSql();
        if ($section === 'novos') {
            $where = self::ACTIVE . ' AND NOT EXISTS (SELECT 1 FROM gmax3_review_items i WHERE i.round_id = ? AND i.catalog_id = c.id)';
            $total = (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog c WHERE $where", [$roundId]);
            $rows = Db::all($pdo, "SELECT c.*, NULL AS r_state, NULL AS r_visits, NULL AS r_last_visit_at FROM gmax3_catalog c WHERE $where ORDER BY c.id LIMIT $limit OFFSET $offset", [$roundId]);
            return ['rows' => $rows, 'total' => $total];
        }
        $cond = match ($section) {
            'nao_analisado' => "i.state = 'nao_analisado'",
            'analisado' => "i.state IN ('analisado','revisar')",
            'pulado' => "i.state = 'pulado'",
            'revisar' => $rv,
            default => throw new RuntimeException('Seção inválida'),
        };
        $where = 'i.round_id = ? AND ' . self::ACTIVE . " AND $cond";
        $total = (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_review_items i JOIN gmax3_catalog c ON c.id = i.catalog_id WHERE $where", [$roundId]);
        $rows = Db::all($pdo, "SELECT c.*, i.state AS r_state, i.visits AS r_visits, i.last_visit_at AS r_last_visit_at, i.position AS r_position, ($rv) AS r_review
            FROM gmax3_review_items i JOIN gmax3_catalog c ON c.id = i.catalog_id WHERE $where ORDER BY i.position LIMIT $limit OFFSET $offset", [$roundId]);
        return ['rows' => $rows, 'total' => $total];
    }

    /** O produto analisado mudou depois da análise? (mesma regra da seção "Precisam de revisão") */
    public static function needsReview(PDO $pdo, int $roundId, int $catalogId): bool
    {
        return (bool)Db::value($pdo, 'SELECT ' . self::reviewSql() . ' FROM gmax3_review_items i JOIN gmax3_catalog c ON c.id = i.catalog_id WHERE i.round_id = ? AND i.catalog_id = ?', [$roundId, $catalogId]);
    }

    /** Inclui os recém-chegados na rodada atual, ao final (extensão controlada e explícita). */
    public static function extend(PDO $pdo, int $roundId, string $actor): int
    {
        return Db::immediate($pdo, static function (PDO $pdo) use ($roundId, $actor) {
            $pos = (int)Db::value($pdo, 'SELECT COALESCE(MAX(position),0) FROM gmax3_review_items WHERE round_id=?', [$roundId]);
            $ids = $pdo->prepare('SELECT c.id FROM gmax3_catalog c WHERE ' . self::ACTIVE . ' AND NOT EXISTS (SELECT 1 FROM gmax3_review_items i WHERE i.round_id = ? AND i.catalog_id = c.id) ORDER BY c.id');
            $ids->execute([$roundId]);
            $ins = $pdo->prepare("INSERT INTO gmax3_review_items(round_id,catalog_id,position,added_as) VALUES(?,?,?,'extensao')");
            $n = 0;
            foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $ins->execute([$roundId, (int)$id, $pos + (++$n)]);
            }
            Audit::log($pdo, $actor, 'rodada_conferencia_estendida', 'review_round', $roundId, ['incluidos' => $n]);
            return $n;
        });
    }

    /** Devolve pulados (ou os que precisam de revisão) para "não analisados", por escolha explícita. */
    public static function requeue(PDO $pdo, int $roundId, string $which, string $actor): int
    {
        $cond = $which === 'pulado' ? "state = 'pulado'" : "catalog_id IN (SELECT i.catalog_id FROM gmax3_review_items i JOIN gmax3_catalog c ON c.id = i.catalog_id WHERE i.round_id = $roundId AND " . self::reviewSql() . ')';
        $n = Db::run($pdo, "UPDATE gmax3_review_items SET state = 'nao_analisado' WHERE round_id = ? AND $cond", [$roundId]);
        Audit::log($pdo, $actor, 'rodada_conferencia_revisao', 'review_round', $roundId, ['grupo' => $which, 'devolvidos' => $n]);
        return $n;
    }

    /** @return list<array> histórico de visitas do produto (todas as rodadas) */
    public static function visits(PDO $pdo, int $catalogId, int $limit = 10): array
    {
        return self::installed($pdo) ? Db::all($pdo, 'SELECT * FROM gmax3_review_visits WHERE catalog_id=? ORDER BY id DESC LIMIT ' . (int)$limit, [$catalogId]) : [];
    }
}

<?php
declare(strict_types=1);

namespace Gmax;

use Gmax\Connectors\MercadoLivre;
use PDO;
use RuntimeException;

/**
 * Candidatos do catálogo do Mercado Livre (3.1.9), SEPARADOS das ofertas.
 * - Candidato não é oferta: não entra no ranking de prontas, na fila nem em mensagens.
 * - product_id = ID de catálogo; bb_item_id = anúncio vencedor ("item:MLB…"), nunca misturados.
 * - Preço só se a API informar (anúncio vencedor), com data; nunca é tratado como conferido.
 * - "Criar rascunho" leva ao catálogo como RASCUNHO, sem link afiliado (o link oficial é
 *   gerado fora da GMAX) e com preço não conferido: a conferência manual continua obrigatória.
 */
final class MlCandidates
{
    public const STATUSES = ['novo' => 'Novo', 'rascunho_criado' => 'Rascunho criado', 'descartado' => 'Descartado'];

    public static function enabled(PDO $pdo): bool
    {
        return Db::tableExists($pdo, 'gmax3_ml_candidates');
    }

    /** @return string 'novo' | 'atualizado' */
    public static function upsert(PDO $pdo, array $c, string $origem, ?string $termo, ?int $trendPos, int $runId): string
    {
        if (!MercadoLivre::isCatalogId((string)($c['product_id'] ?? '')) || trim((string)($c['name'] ?? '')) === '') {
            throw new RuntimeException('Candidato sem ID de catálogo ou nome');
        }
        $now = Time::nowSql();
        $cur = Db::one($pdo, 'SELECT id FROM gmax3_ml_candidates WHERE product_id=?', [$c['product_id']]);
        if ($cur) {
            Db::run($pdo, 'UPDATE gmax3_ml_candidates SET name=?, domain_id=COALESCE(?,domain_id), picture_url=COALESCE(?,picture_url), last_seen_at=?, run_id=?,
                termo=COALESCE(termo,?), trend_pos=CASE WHEN ? IS NOT NULL AND (trend_pos IS NULL OR ? < trend_pos) THEN ? ELSE trend_pos END WHERE id=?',
                [$c['name'], $c['domain_id'] ?? null, $c['picture_url'] ?? null, $now, $runId ?: null, $termo, $trendPos, $trendPos, $trendPos, $cur['id']]);
            return 'atualizado';
        }
        Db::run($pdo, 'INSERT INTO gmax3_ml_candidates(product_id,name,domain_id,picture_url,origem,termo,trend_pos,run_id,first_seen_at,last_seen_at) VALUES(?,?,?,?,?,?,?,?,?,?)',
            [$c['product_id'], $c['name'], $c['domain_id'] ?? null, $c['picture_url'] ?? null, $origem, $termo, $trendPos, $runId ?: null, $now, $now]);
        return 'novo';
    }

    /** Grava o detalhe do produto de catálogo (permalink e anúncio vencedor, se a API informar). */
    public static function applyDetail(PDO $pdo, string $productId, array $d, ?string $categoryName): void
    {
        Db::run($pdo, 'UPDATE gmax3_ml_candidates SET permalink=COALESCE(?,permalink), picture_url=COALESCE(?,picture_url), category_id=COALESCE(?,category_id),
            category_name=COALESCE(?,category_name), bb_item_id=?, bb_price_cents=?, bb_original_cents=?, bb_currency=?, bb_free_shipping=?, bb_official_store=?, bb_checked_at=? WHERE product_id=?', [
            $d['permalink'], $d['picture_url'], $d['category_id'], $categoryName, $d['bb_item_id'], $d['bb_price_cents'], $d['bb_original_cents'], $d['bb_currency'],
            $d['bb_free_shipping'], $d['bb_official_store'], Time::nowSql(), $productId,
        ]);
    }

    /** @return list<array> */
    public static function list(PDO $pdo, array $f, int $limit = 60): array
    {
        $where = ['1=1'];
        $args = [];
        $st = (string)($f['status'] ?? 'novo');
        if (isset(self::STATUSES[$st])) {
            $where[] = 'status = ?';
            $args[] = $st;
        } elseif ($st === 'avaliados') {
            $where[] = "status <> 'novo'"; // já avaliados: rascunho criado ou descartado
        }
        if (($f['preco'] ?? '') === 'com') {
            $where[] = 'bb_price_cents IS NOT NULL';
        } elseif (($f['preco'] ?? '') === 'sem') {
            $where[] = 'bb_price_cents IS NULL';
        }
        if (!empty($f['q'])) {
            $q = '%' . addcslashes(mb_substr((string)$f['q'], 0, 60), '%_\\') . '%';
            $where[] = "(name LIKE ? ESCAPE '\\' OR termo LIKE ? ESCAPE '\\' OR product_id LIKE ? ESCAPE '\\')";
            array_push($args, $q, $q, $q);
        }
        return Db::all($pdo, 'SELECT * FROM gmax3_ml_candidates WHERE ' . implode(' AND ', $where)
            . ' ORDER BY (bb_price_cents IS NOT NULL) DESC, COALESCE(trend_pos, 999) ASC, last_seen_at DESC, id DESC LIMIT ' . max(1, min(2000, $limit)), $args);
    }

    /**
     * 3.2.2: lista enriquecida para a tela (sem nenhuma chamada à API):
     * título resumido, segmento (com prioridade pela ordem dos segmentos), semelhantes entre os
     * candidatos e com produtos do catálogo, campos efetivamente gravados e paginação.
     * @return array{rows:list<array>,total:int,pages:int,page:int}
     */
    public static function browse(PDO $pdo, array $f, int $per = 20): array
    {
        $all = self::list($pdo, $f, 2000);
        $segOrder = array_flip(array_keys(Text::SEGMENTS));
        foreach ($all as &$c) {
            $c['_seg'] = Text::segment((string)$c['name'], (string)($c['category_name'] ?? ''));
            $c['_tok'] = Text::tokens((string)$c['name']);
        }
        unset($c);
        if (!empty($f['seg'])) {
            $all = array_values(array_filter($all, static fn($c) => $c['_seg'] === $f['seg']));
        }
        if (($f['ordem'] ?? '') === 'segmento') {
            usort($all, static fn($a, $b) => [$segOrder[$a['_seg']] ?? 99, (int)$b['id']] <=> [$segOrder[$b['_seg']] ?? 99, (int)$a['id']]);
        }
        $total = count($all);
        $pages = max(1, (int)ceil($total / $per));
        $page = min(max(1, (int)($f['pg'] ?? 1)), $pages);
        $rows = array_slice($all, ($page - 1) * $per, $per);
        // Semelhantes: só para a página exibida, contra todos os candidatos e o catálogo (títulos já carregados).
        $catalog = [];
        foreach ($pdo->query("SELECT id, title FROM gmax3_catalog WHERE validation_status <> 'arquivado'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $catalog[] = [(int)$r['id'], Text::tokens((string)$r['title']), (string)$r['title']];
        }
        $jac = static function (array $x, array $y): float {
            if (!$x || !$y) {
                return 0.0;
            }
            $i = count(array_intersect_key($x, $y));
            return $i / (count($x) + count($y) - $i);
        };
        foreach ($rows as &$c) {
            $c['_similar'] = [];
            foreach ($all as $o) {
                if ($o['id'] !== $c['id'] && ($j = $jac($c['_tok'], $o['_tok'])) >= 0.6) {
                    $c['_similar'][] = ['tipo' => 'candidato', 'id' => (int)$o['id'], 'nome' => (string)$o['name'], 'grau' => $j];
                }
            }
            foreach ($catalog as [$id, $tok, $title]) {
                if ((int)($c['catalog_id'] ?? 0) !== $id && ($j = $jac($c['_tok'], $tok)) >= 0.6) {
                    $c['_similar'][] = ['tipo' => 'produto', 'id' => $id, 'nome' => $title, 'grau' => $j];
                }
            }
            usort($c['_similar'], static fn($a, $b) => $b['grau'] <=> $a['grau']);
            $c['_similar'] = array_slice($c['_similar'], 0, 3);
            $c['_short'] = Text::shortTitle((string)$c['name'], 70);
            $c['_fields'] = array_filter(array_intersect_key($c, array_flip(['product_id', 'name', 'domain_id', 'category_id', 'category_name', 'picture_url', 'permalink',
                'bb_item_id', 'bb_price_cents', 'bb_original_cents', 'bb_currency', 'bb_free_shipping', 'bb_official_store', 'bb_checked_at', 'trend_pos', 'termo'])),
                static fn($v) => $v !== null && $v !== '');
            unset($c['_tok']);
        }
        unset($c);
        return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /** Cria RASCUNHO no catálogo a partir do candidato. Não define link afiliado nem confirma preço. */
    public static function createDraft(PDO $pdo, int $id, string $actor): int
    {
        return Db::immediate($pdo, static function () use ($pdo, $id, $actor) {
            $c = Db::one($pdo, 'SELECT * FROM gmax3_ml_candidates WHERE id=?', [$id]) ?? throw new RuntimeException('Candidato não encontrado');
            if ($c['status'] !== 'novo') {
                throw new RuntimeException('Este candidato já foi tratado (' . (self::STATUSES[$c['status']] ?? $c['status']) . ').');
            }
            $row = ['marketplace' => 'mercadolivre', 'external_id' => 'catalogo:' . $c['product_id'], 'title' => $c['name'], 'category' => $c['category_name'] ?: null,
                'original_url' => $c['permalink'] && Url::isProduct('mercadolivre', $c['permalink']) ? $c['permalink'] : null];
            if ($c['bb_price_cents']) {
                // Preço do anúncio vencedor informado pela API do catálogo: NÃO conferido (price_verified_at continua vazio).
                $row['price_promo_cents'] = (int)$c['bb_price_cents'];
                $row['price_source'] = 'api_ml_catalogo';
                $row['price_seen_at'] = substr((string)$c['bb_checked_at'], 0, 10);
            }
            $row['notes'] = 'Candidato do catálogo Mercado Livre (ID de catálogo ' . $c['product_id'] . ').'
                . ($c['bb_item_id'] ? ' Anúncio vencedor na consulta: ' . substr($c['bb_item_id'], 5) . '.' : ' Sem anúncio vencedor informado pela API.')
                . ($c['bb_price_cents'] ? ' Preço informado pela API em ' . Time::show((string)$c['bb_checked_at']) . ': ' . Money::format((int)$c['bb_price_cents']) . ' — NÃO conferido.' : '')
                . ' Gere o link afiliado oficial na Central de Afiliados e faça a conferência na loja.';
            if ($m = Catalog::findMatches($pdo, $row)) {
                throw new RuntimeException('Produto já existe no catálogo (#' . $m[0]['id'] . ').');
            }
            $cid = Catalog::insert($pdo, $row, 'rascunho', $actor);
            Db::run($pdo, "UPDATE gmax3_ml_candidates SET status='rascunho_criado', catalog_id=? WHERE id=?", [$cid, $id]);
            Audit::log($pdo, $actor, 'ml_candidato_rascunho', 'catalog', $cid, ['candidato' => $id, 'catalogo' => $c['product_id'], 'preco_api' => $c['bb_price_cents']]);
            return $cid;
        });
    }

    public static function discard(PDO $pdo, int $id, string $actor): bool
    {
        $n = Db::run($pdo, "UPDATE gmax3_ml_candidates SET status='descartado' WHERE id=? AND status='novo'", [$id]);
        if ($n) {
            Audit::log($pdo, $actor, 'ml_candidato_descartado', 'ml_candidate', $id);
        }
        return $n > 0;
    }
}

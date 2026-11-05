<?php
declare(strict_types=1);

namespace Gmax;

use PDO;

/**
 * Ranking de oportunidades com critérios transparentes.
 * Cada critério só pontua quando o dado existe; critério "sem dado" vale zero (nunca é
 * estimado) e a CONFIANÇA mostra quanto do peso total tinha dado real. Nenhum preço, venda ou comissão é inventado.
 */
final class Ranking
{
    public const WEIGHTS = [
        'desconto' => 24,
        'popularidade' => 14,
        'avaliacao' => 12,
        'qualidade_dados' => 10,
        'reputacao' => 9,
        'disponibilidade' => 8,
        'comentarios' => 8,
        'preco' => 5,
        'categoria' => 5,
        'pagamento' => 5,
    ];
    /** Faixa de preço (em reais) com maior potencial de conversão; configurável em gmax3_settings. */
    public const DEFAULT_PRICE_BAND = [20, 300];

    private const REPUTATION = [
        'platinum' => 1.0, 'loja oficial' => 1.0, 'oficial' => 1.0, 'gold' => 0.85, 'mercadolider' => 0.8,
        'lider' => 0.8, 'verde' => 0.6, 'green' => 0.6, 'amarelo' => 0.3, 'laranja' => 0.15, 'vermelho' => 0.0,
    ];

    /** @return array{score:?int,confidence:int,components:list<array>,price_note:?string} */
    public static function evaluate(array $r, ?int $categoryPotential, array $verifiedHistory = [], array $priceBand = self::DEFAULT_PRICE_BAND, int $freshHours = 24): array
    {
        $c = [];
        $d = Money::discount(isset($r['price_original_cents']) ? (int)$r['price_original_cents'] : null, isset($r['price_promo_cents']) ? (int)$r['price_promo_cents'] : null);
        $c['desconto'] = $d === null
            ? [null, empty($r['price_original_cents']) ? 'Sem preço original informado' : 'Sem desconto real']
            : [min($d['percent'] / 60, 1.0), "{$d['percent']}% de desconto real (De " . Money::format((int)$r['price_original_cents']) . ')'
                . (empty($r['price_verified_at']) ? ' — preço de fonte não conferida' : (Pricing::originalSource($r) === 'importado' ? ' — De não confirmado' : ''))];

        $sales = isset($r['sales_min']) ? (int)$r['sales_min'] : null;
        $c['popularidade'] = $sales === null || $sales <= 0
            ? [null, 'Vendas públicas não informadas']
            : [min(log10($sales) / 5, 1.0), 'Vendas públicas informadas: ' . (($r['sales_public'] ?? '') ?: $sales)];

        $rating = isset($r['rating']) ? (float)$r['rating'] : null;
        $count = isset($r['rating_count']) ? (int)$r['rating_count'] : null;
        if ($rating === null) {
            $c['avaliacao'] = [null, 'Avaliação não informada'];
        } elseif ($count !== null && $count < 10) {
            $c['avaliacao'] = [null, "Nota $rating com apenas $count avaliação(ões): amostra insuficiente"];
        } else {
            $c['avaliacao'] = [max(0.0, min(1.0, ($rating - 3.5) / 1.5)), "Nota $rating" . ($count ? " ($count avaliações)" : '')];
        }

        $rep = Spreadsheet::normalizeHeader((string)($r['seller_reputation'] ?? ''));
        $repScore = null;
        foreach (self::REPUTATION as $k => $v) {
            if ($rep !== '' && str_contains($rep, $k)) {
                $repScore = $v;
                break;
            }
        }
        $c['reputacao'] = $repScore === null ? [null, 'Reputação do vendedor não informada'] : [$repScore, 'Reputação: ' . $r['seller_reputation']];

        $c['disponibilidade'] = match ($r['availability'] ?? 'desconhecida') {
            'disponivel' => [1.0, 'Disponível'],
            'estoque_baixo' => [0.5, 'Estoque baixo'],
            'indisponivel' => [0.0, 'Indisponível'],
            default => [null, 'Disponibilidade não informada'],
        };

        $c['categoria'] = $categoryPotential === null
            ? [null, 'Potencial da categoria não definido (configure em Categorias)']
            : [$categoryPotential / 100, "Potencial definido para {$r['category']}: $categoryPotential/100"];

        $c['pagamento'] = match ($r['payment_condition'] ?? null) {
            'normal' => [1.0, 'Preço sem condição especial'],
            'pix' => [0.9, 'Preço via Pix'],
            'cartao' => [0.8, 'Preço no cartão'],
            'cupom' => [0.6, 'Depende de cupom'],
            'outro' => [0.5, 'Condição especial: ' . ($r['payment_note'] ?? '')],
            default => [null, 'Condição de pagamento não identificada'],
        };

        $count = isset($r['rating_count']) ? (int)$r['rating_count'] : null;
        $c['comentarios'] = $count === null || $count <= 0
            ? [null, 'Quantidade de avaliações não informada']
            : [min(log10($count) / 4, 1.0), "$count avaliações publicadas (potencial de conversão)"];

        $promo = isset($r['price_promo_cents']) ? (int)$r['price_promo_cents'] : null;
        if ($promo === null) {
            $c['preco'] = [null, 'Sem preço'];
        } else {
            [$lo, $hi] = $priceBand;
            $reais = $promo / 100;
            $v = $reais >= $lo && $reais <= $hi ? 1.0 : (($reais >= $lo / 2 && $reais <= $hi * 2) ? 0.5 : 0.2);
            $c['preco'] = [$v, sprintf('Preço %s %s da faixa de maior conversão (R$ %d–%d, configurável)', Money::format($promo), $v === 1.0 ? 'dentro' : 'fora', $lo, $hi)];
        }

        $fields = ['price_original_cents', 'price_promo_cents', 'payment_condition', 'sales_min', 'rating', 'seller_reputation'];
        $filled = count(array_filter($fields, static fn($f) => isset($r[$f]) && $r[$f] !== ''));
        $filled += (($r['availability'] ?? 'desconhecida') !== 'desconhecida') ? 1 : 0;
        $filled += Url::isAffiliate((string)($r['marketplace'] ?? ''), $r['affiliate_url'] ?? null) ? 1 : 0;
        $age = Time::hoursSince($r['price_verified_at'] ?? null);
        $fresh = $age === null ? 0.3 : ($age <= $freshHours ? 1.0 : 0.5);
        $c['qualidade_dados'] = [round($filled / 8 * $fresh, 3), sprintf('%d de 8 informações essenciais; preço %s', $filled,
            $age === null ? 'nunca conferido' : ($age <= $freshHours ? 'conferido recentemente' : 'conferido há mais de ' . $freshHours . ' h'))];

        $sum = 0.0;
        $available = 0;
        $components = [];
        foreach (self::WEIGHTS as $name => $w) {
            [$v, $why] = $c[$name];
            if ($v !== null) {
                $sum += $v * $w;
                $available += $w;
            }
            $components[] = ['criterio' => $name, 'peso' => $w, 'valor' => $v === null ? null : round($v * 100), 'explicacao' => $why];
        }
        $total = array_sum(self::WEIGHTS);
        $priceNote = null;
        // Histórico: lista de preços conferidos OU agregado ['count' => n, 'min' => centavos] (3.2.0, mesma regra).
        [$hCount, $hMin] = isset($verifiedHistory['count'])
            ? [(int)$verifiedHistory['count'], (int)$verifiedHistory['min']]
            : [count($verifiedHistory), $verifiedHistory ? min(array_map('intval', $verifiedHistory)) : 0];
        if ($hCount >= 3 && !empty($r['price_promo_cents'])) {
            $min = $hMin;
            $priceNote = (int)$r['price_promo_cents'] <= $min
                ? 'Menor preço entre ' . $hCount . ' conferências registradas'
                : 'Já houve preço menor (' . Money::format($min) . ') em conferências anteriores';
        }
        return [
            // Critério sem dado vale zero: produto pouco documentado não supera um bem documentado.
            'score' => $available === 0 ? null : (int)round($sum / $total * 100),
            'confidence' => (int)round($available / $total * 100),
            'components' => $components,
            'price_note' => $priceNote,
        ];
    }

    public const MODES = ['potencial' => 'Potencial comercial', 'prontas' => 'Prontas para divulgar'];
    public const SORTS = ['score' => 'Pontuação', 'conf' => 'Confiança', 'preco' => 'Preço', 'data' => 'Última conferência'];
    public const PER_PAGE = 25;

    /**
     * Pendências para divulgar: as MESMAS regras que bloqueiam a fila (Rules::blockers e
     * Rules::duplicate) + "De" verificável quando usado na comunicação. Pontuação alta não é autorização.
     * @return list<string>
     */
    public static function readiness(PDO $pdo, array $r, string $channel = 'telegram_privado', ?array $ctx = null): array
    {
        $b = Rules::blockers($pdo, $r, $channel, $ctx);
        if (!empty($r['price_original_cents']) && Pricing::originalSource($r) === 'importado') {
            $b[] = 'Preço original (De) não confirmado: confira na loja ou retire o De antes de divulgar';
        }
        if ($dup = Rules::duplicate($pdo, $r, null, $ctx)) {
            $b[] = $dup;
        }
        return array_values(array_unique($b));
    }

    /** Quantidade e menor preço conferido por produto, agregados no SQLite (antes: todo o histórico em memória). */
    public static function historyStats(PDO $pdo, ?array $ids = null): array
    {
        $out = [];
        $in = $ids !== null ? ' AND catalog_id IN (' . (implode(',', array_map('intval', $ids)) ?: '0') . ')' : '';
        foreach ($pdo->query('SELECT catalog_id, COUNT(*) n, MIN(price_promo_cents) m FROM gmax3_price_history WHERE verified = 1 AND price_promo_cents IS NOT NULL' . $in . ' GROUP BY catalog_id') as $h) {
            $out[$h['catalog_id']] = ['count' => (int)$h['n'], 'min' => (int)$h['m']];
        }
        return $out;
    }

    /** Destaques comerciais curtos (somente dados existentes), do mais relevante ao menos. @return list<string> */
    public static function highlights(array $r): array
    {
        $h = [];
        $d = Money::discount(isset($r['price_original_cents']) ? (int)$r['price_original_cents'] : null, isset($r['price_promo_cents']) ? (int)$r['price_promo_cents'] : null);
        if ($d) {
            $h[] = $d['percent'] . '% OFF' . (Pricing::originalSource($r) === 'importado' ? ' (De não conferido)' : '');
        }
        if (!empty($r['sales_min']) && (int)$r['sales_min'] > 0) {
            $h[] = (($r['sales_public'] ?? '') ?: (int)$r['sales_min']) . ' vendas';
        }
        if (isset($r['rating']) && $r['rating'] !== null && $r['rating'] !== '') {
            $h[] = number_format((float)$r['rating'], 1, ',', '') . '★' . (!empty($r['rating_count']) ? ' (' . (int)$r['rating_count'] . ')' : '');
        }
        if (!empty($r['seller_reputation'])) {
            $h[] = mb_substr((string)$r['seller_reputation'], 0, 30);
        }
        if (($r['payment_condition'] ?? '') === 'pix') {
            $h[] = 'Pix';
        } elseif (($r['payment_condition'] ?? '') === 'cupom') {
            $h[] = 'com cupom';
        }
        return $h;
    }

    /**
     * Ranking com dois modos, filtros combináveis, ordenação e paginação.
     * Filtros: modo, marketplace, category, status, preco_min, preco_max (R$), score_min, conf_min,
     * conferido (api|manual|nunca), dias (conferido nos últimos N dias), stale, link (com|sem),
     * variation, avail, pronto (sim|nao), ordem, page.
     * @return array{rows:list<array>,total:int,prontas:int,page:int,pages:int}
     */
    public static function board(PDO $pdo, array $f): array
    {
        $mode = isset(self::MODES[$f['modo'] ?? '']) ? $f['modo'] : 'potencial';
        $where = ["validation_status NOT IN ('rejeitado','arquivado')"];
        $args = [];
        foreach (['marketplace' => Url::MARKETPLACES, 'status' => Catalog::STATUSES, 'avail' => Catalog::AVAILABILITY] as $k => $allowed) {
            if (!empty($f[$k]) && isset($allowed[$f[$k]])) {
                $where[] = ($k === 'status' ? 'validation_status' : ($k === 'avail' ? 'availability' : $k)) . ' = ?';
                $args[] = $f[$k];
            }
        }
        if (!empty($f['category'])) {
            $where[] = 'category = ?';
            $args[] = (string)$f['category'];
        }
        foreach (['preco_min' => '>=', 'preco_max' => '<='] as $k => $op) {
            if (isset($f[$k]) && $f[$k] !== '' && ($c = Money::tryParse((string)$f[$k])) !== null) {
                $where[] = "price_promo_cents $op ?";
                $args[] = $c;
            }
        }
        $conf = (string)($f['conferido'] ?? '');
        if ($conf === 'api') {
            $where[] = "price_verified_by LIKE 'api:%'";
        } elseif ($conf === 'manual') {
            $where[] = "price_verified_at IS NOT NULL AND price_verified_by NOT LIKE 'api:%'";
        } elseif ($conf === 'nunca') {
            $where[] = 'price_verified_at IS NULL';
        }
        $fresh = Settings::int($pdo, 'validade_preco_horas', 24);
        if (!empty($f['dias']) && ctype_digit((string)$f['dias'])) {
            $where[] = 'price_verified_at >= ?';
            $args[] = Time::now()->modify('-' . min(365, (int)$f['dias']) . ' days')->format('Y-m-d H:i:s');
        }
        if (!empty($f['stale'])) {
            $where[] = '(price_verified_at IS NULL OR price_verified_at < ?)';
            $args[] = Time::now()->modify("-$fresh hours")->format('Y-m-d H:i:s');
        }
        if (($f['link'] ?? '') === 'sem') {
            $where[] = "(affiliate_url IS NULL OR affiliate_url = '')";
        } elseif (($f['link'] ?? '') === 'com') {
            $where[] = "affiliate_url IS NOT NULL AND affiliate_url <> ''";
        }
        if (!empty($f['variation'])) {
            $where[] = 'variation_pending = 1';
        }
        $potentials = $pdo->query('SELECT name, potential FROM gmax3_categories WHERE potential IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
        $band = self::DEFAULT_PRICE_BAND;
        if (preg_match('/^(\d{1,5})-(\d{1,6})$/D', Settings::get($pdo, 'faixa_preco_conversao', ''), $m) && (int)$m[1] < (int)$m[2]) {
            $band = [(int)$m[1], (int)$m[2]];
        }
        $history = self::historyStats($pdo); // agregado no SQLite (antes: todo o histórico em memória)
        $ctx = Rules::context($pdo); // configurações, fila e tabelas antigas lidas uma vez (antes: 3 a 6 consultas por produto)
        $eval = static fn(array $r) => self::evaluate($r, isset($potentials[$r['category'] ?? '']) ? (int)$potentials[$r['category']] : null, $history[$r['id']] ?? [], $band, $fresh);
        // Passagem completa com linhas COMPACTAS (só o necessário para filtrar e ordenar); detalhes só da página exibida.
        $rows = [];
        $ready = 0;
        $st = $pdo->prepare('SELECT * FROM gmax3_catalog WHERE ' . implode(' AND ', $where));
        $st->execute($args);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $e = $eval($r);
            $ok = self::readiness($pdo, $r, 'telegram_privado', $ctx) === [];
            $ready += $ok ? 1 : 0;
            if (($mode === 'prontas' && !$ok) || (($f['pronto'] ?? '') === 'sim' && !$ok) || (($f['pronto'] ?? '') === 'nao' && $ok)) {
                continue;
            }
            if ((isset($f['score_min']) && ctype_digit((string)$f['score_min']) && ($e['score'] ?? 0) < (int)$f['score_min'])
                || (isset($f['conf_min']) && ctype_digit((string)$f['conf_min']) && $e['confidence'] < (int)$f['conf_min'])) {
                continue;
            }
            $rows[] = ['id' => (int)$r['id'], 'score' => $e['score'], 'confidence' => $e['confidence'],
                'price' => $r['price_promo_cents'] === null ? null : (int)$r['price_promo_cents'], 'verified' => (string)($r['price_verified_at'] ?? '')];
        }
        $key = static fn(array $x) => match ($f['ordem'] ?? 'score') {
            'conf' => [$x['confidence'], $x['score'] ?? -1],
            'preco' => [-($x['price'] ?? PHP_INT_MAX), $x['score'] ?? -1],
            'data' => [$x['verified'], $x['score'] ?? -1],
            default => [$x['score'] ?? -1, $x['confidence']],
        };
        usort($rows, static fn($a, $b) => $key($b) <=> $key($a) ?: $a['id'] <=> $b['id']);
        $total = count($rows);
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int)($f['page'] ?? 1)));
        $slice = array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        $items = self::itemsById($pdo, array_column($slice, 'id'));
        $full = [];
        foreach ($slice as $x) {
            $r = $items[$x['id']];
            $pend = self::readiness($pdo, $r, 'telegram_privado', $ctx);
            $full[] = ['item' => $r, 'pendencias' => $pend, 'pronto' => $pend === [], 'destaques' => self::highlights($r)] + $eval($r);
        }
        return ['rows' => $full, 'total' => $total, 'prontas' => $ready, 'page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * self::PER_PAGE];
    }

    /** @param list<int> $ids @return array<int,array> */
    private static function itemsById(PDO $pdo, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (Db::all($pdo, 'SELECT * FROM gmax3_catalog WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $r) {
            $out[(int)$r['id']] = $r;
        }
        return $out;
    }

    public const CACHE_TTL = 300;

    /** Assinatura do que altera a pontuação: catálogo, histórico conferido, categorias e configurações usadas. */
    private static function signature(PDO $pdo): string
    {
        $c = Db::one($pdo, 'SELECT COUNT(*) n, MAX(updated_at) u, MAX(id) i FROM gmax3_catalog');
        $h = Db::value($pdo, 'SELECT MAX(id) FROM gmax3_price_history');
        $g = Db::value($pdo, "SELECT group_concat(name || ':' || IFNULL(potential,'') || ':' || active, '|') FROM gmax3_categories");
        return hash('sha256', json_encode([$c, $h, $g, Settings::get($pdo, 'faixa_preco_conversao', ''), Settings::get($pdo, 'validade_preco_horas', '')]));
    }

    /**
     * list() com cache curto (só para telas: Visão Geral e Central). Invalida na hora quando muda
     * qualquer produto, preço conferido, categoria ou configuração de pontuação; senão vale 5 min.
     * Automações (seleção) continuam usando list() sem cache.
     */
    public static function listCached(PDO $pdo, int $limit = 60): array
    {
        $sig = self::signature($pdo);
        $c = json_decode(Settings::get($pdo, 'cache_ranking_topo', ''), true);
        if (is_array($c) && ($c['sig'] ?? '') === $sig && (int)($c['limit'] ?? 0) === $limit && time() - (int)($c['at'] ?? 0) < self::CACHE_TTL && is_array($c['ids'] ?? null)) {
            $items = self::itemsById($pdo, array_map('intval', $c['ids']));
            if (count($items) === count($c['ids'])) {
                return self::detailed($pdo, array_map('intval', $c['ids']), $items);
            }
        }
        $rows = self::list($pdo, [], $limit);
        Settings::store($pdo, 'cache_ranking_topo', json_encode(['sig' => $sig, 'limit' => $limit, 'at' => time(), 'ids' => array_map(static fn($r) => (int)$r['item']['id'], $rows)]), 'cache');
        return $rows;
    }

    /** Recalcula a avaliação completa (com componentes) para ids já ordenados. */
    private static function detailed(PDO $pdo, array $ids, array $items): array
    {
        $potentials = $pdo->query('SELECT name, potential FROM gmax3_categories WHERE potential IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
        $band = self::DEFAULT_PRICE_BAND;
        if (preg_match('/^(\d{1,5})-(\d{1,6})$/D', Settings::get($pdo, 'faixa_preco_conversao', ''), $m) && (int)$m[1] < (int)$m[2]) {
            $band = [(int)$m[1], (int)$m[2]];
        }
        $fresh = Settings::int($pdo, 'validade_preco_horas', 24);
        $history = self::historyStats($pdo, $ids);
        return array_map(static fn($id) => ['item' => $items[$id]] + self::evaluate($items[$id], isset($potentials[$items[$id]['category'] ?? '']) ? (int)$potentials[$items[$id]['category']] : null, $history[$id] ?? [], $band, $fresh), $ids);
    }

    /** @return list<array> produtos ordenados por pontuação (exclui rejeitados, arquivados e indisponíveis) */
    public static function list(PDO $pdo, array $filters = [], int $limit = 100): array
    {
        $where = "validation_status NOT IN ('rejeitado','arquivado') AND availability <> 'indisponivel'";
        $args = [];
        if (!empty($filters['marketplace']) && isset(Url::MARKETPLACES[$filters['marketplace']])) {
            $where .= ' AND marketplace = ?';
            $args[] = $filters['marketplace'];
        }
        $potentials = $pdo->query('SELECT name, potential FROM gmax3_categories WHERE potential IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
        $band = self::DEFAULT_PRICE_BAND;
        $cfg = Settings::get($pdo, 'faixa_preco_conversao', '');
        if (preg_match('/^(\d{1,5})-(\d{1,6})$/D', $cfg, $m) && (int)$m[1] < (int)$m[2]) {
            $band = [(int)$m[1], (int)$m[2]];
        }
        $fresh = Settings::int($pdo, 'validade_preco_horas', 24);
        $history = self::historyStats($pdo); // agregado no SQLite (antes: todo o histórico em memória)
        $eval = static fn(array $r) => self::evaluate($r, isset($potentials[$r['category'] ?? '']) ? (int)$potentials[$r['category']] : null, $history[$r['id']] ?? [], $band, $fresh);
        // Linhas compactas na passagem completa; detalhes só dos $limit primeiros (mesma ordem estável de antes).
        $out = [];
        $st = $pdo->prepare("SELECT * FROM gmax3_catalog WHERE $where");
        $st->execute($args);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $e = $eval($r);
            $out[] = ['id' => (int)$r['id'], 'score' => $e['score'], 'confidence' => $e['confidence']];
        }
        usort($out, static fn($a, $b) => [($b['score'] ?? -1), $b['confidence']] <=> [($a['score'] ?? -1), $a['confidence']]);
        $out = array_slice($out, 0, $limit);
        $items = self::itemsById($pdo, array_column($out, 'id'));
        return array_map(static fn($x) => ['item' => $items[$x['id']]] + $eval($items[$x['id']]), $out);
    }
}

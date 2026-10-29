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
        'desconto' => 30,
        'popularidade' => 20,
        'avaliacao' => 15,
        'reputacao' => 10,
        'disponibilidade' => 10,
        'categoria' => 10,
        'pagamento' => 5,
    ];

    private const REPUTATION = [
        'platinum' => 1.0, 'loja oficial' => 1.0, 'oficial' => 1.0, 'gold' => 0.85, 'mercadolider' => 0.8,
        'lider' => 0.8, 'verde' => 0.6, 'green' => 0.6, 'amarelo' => 0.3, 'laranja' => 0.15, 'vermelho' => 0.0,
    ];

    /** @return array{score:?int,confidence:int,components:list<array>,price_note:?string} */
    public static function evaluate(array $r, ?int $categoryPotential, array $verifiedHistory = []): array
    {
        $c = [];
        $d = Money::discount(isset($r['price_original_cents']) ? (int)$r['price_original_cents'] : null, isset($r['price_promo_cents']) ? (int)$r['price_promo_cents'] : null);
        $c['desconto'] = $d === null
            ? [null, empty($r['price_original_cents']) ? 'Sem preço original informado' : 'Sem desconto real']
            : [min($d['percent'] / 60, 1.0), "{$d['percent']}% de desconto real (De " . Money::format((int)$r['price_original_cents']) . ')'
                . (empty($r['price_verified_at']) ? ' — preço de fonte não conferida' : '')];

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
        if (count($verifiedHistory) >= 3 && !empty($r['price_promo_cents'])) {
            $min = min(array_map('intval', $verifiedHistory));
            $priceNote = (int)$r['price_promo_cents'] <= $min
                ? 'Menor preço entre ' . count($verifiedHistory) . ' conferências registradas'
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
        $history = [];
        foreach ($pdo->query('SELECT catalog_id, price_promo_cents FROM gmax3_price_history WHERE verified = 1')->fetchAll() as $h) {
            $history[$h['catalog_id']][] = $h['price_promo_cents'];
        }
        $out = [];
        foreach (Db::all($pdo, "SELECT * FROM gmax3_catalog WHERE $where", $args) as $r) {
            $pot = isset($potentials[$r['category'] ?? '']) ? (int)$potentials[$r['category']] : null;
            $out[] = ['item' => $r] + self::evaluate($r, $pot, $history[$r['id']] ?? []);
        }
        usort($out, static fn($a, $b) => [($b['score'] ?? -1), $b['confidence']] <=> [($a['score'] ?? -1), $a['confidence']]);
        return array_slice($out, 0, $limit);
    }
}

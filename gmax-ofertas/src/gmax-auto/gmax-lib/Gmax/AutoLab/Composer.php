<?php
declare(strict_types=1);

namespace Gmax\AutoLab;

use DateTimeImmutable;
use DateTimeZone;
use Gmax\Connectors\Registry;
use Gmax\{Config, Db, Money, Settings, Time, Url};
use PDO;
use Throwable;

/**
 * Escolhe o produto e monta a mensagem de homologação. Somente leitura sobre o catálogo:
 * a consulta à API é feita para a mensagem e NÃO altera produto, preço, histórico ou links.
 *
 * Ordem: (A) oferta Shopee confirmada agora pela API + link afiliado válido → TESTE — OFERTA VALIDADA;
 *        (A') o mesmo produto sem algum requisito → TESTE — CANDIDATO (com o que falta);
 *        (B) candidato do catálogo ML ou produto sem dados suficientes → TESTE — CANDIDATO (sem links);
 *        (C) nada disponível → SIMULAÇÃO (dados fictícios, sem links de compra).
 * Nunca: preço inventado, desconto sem preço anterior comprovado, preço antigo como atual,
 * ID de catálogo como ID de anúncio, link fictício, link afiliado do Mercado Livre.
 */
final class Composer
{
    public const MAX_API_CALLS = 3;

    private static function local(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(Config::TZ))->format('d/m H:i');
    }

    private static function clean(?string $s, int $max = 200): string
    {
        $s = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$s));
        // Sem links ou menções vindos dos dados do marketplace.
        $s = (string)preg_replace(['~https?://\S+~i', AutoLab::DOMAIN_RE, '~@\w+~u'], ['[link removido]', '[link removido]', ''], $s);
        return mb_substr($s, 0, $max);
    }

    /** @return array<string,true> chaves já usadas nos últimos DEDUP_DAYS dias */
    private static function used(PDO $pdo): array
    {
        $since = Time::now()->modify('-' . AutoLab::DEDUP_DAYS . ' days')->format('Y-m-d H:i:s');
        return array_fill_keys(array_map('strval', array_column(Db::all($pdo, 'SELECT DISTINCT dedup_key FROM gmax3_autolab_messages WHERE created_at >= ?', [$since]), 'dedup_key')), true);
    }

    private static function base(string $kind, string $dedup): array
    {
        return ['kind' => $kind, 'marketplace' => null, 'catalog_id' => null, 'candidate_id' => null, 'source' => 'simulacao', 'price_confirmed' => false,
            'price_cents' => null, 'price_catalog_cents' => null, 'price_checked_at' => null, 'link_kind' => 'nenhum', 'dedup_key' => $dedup, 'missing' => [], 'checks' => [], 'text' => ''];
    }

    public static function compose(PDO $pdo, string $slot): array
    {
        $used = self::used($pdo);
        $checks = [];
        $candidate = null;
        // (A) Shopee: só produtos com Item Id, fora de arquivados/rejeitados, não usados na semana.
        $rows = Db::all($pdo, "SELECT * FROM gmax3_catalog WHERE marketplace='shopee' AND validation_status NOT IN ('arquivado','rejeitado')
            AND external_id IS NOT NULL AND external_id <> '' ORDER BY (validation_status='aprovado') DESC, COALESCE(price_verified_at,'') DESC, id LIMIT 40");
        $calls = 0;
        foreach ($rows as $r) {
            if (isset($used['cat:' . $r['id']])) {
                continue;
            }
            if ($calls >= self::MAX_API_CALLS) {
                break;
            }
            $conn = Registry::get('shopee', $pdo);
            if (!$conn->configured()) {
                $checks[] = 'Shopee: integração não configurada (' . $conn->status() . ')';
                break;
            }
            if ($calls > 0) {
                usleep(max(0, min(5000, Settings::int($pdo, 'api_intervalo_ms', 1500))) * 1000);
            }
            $calls++;
            try {
                $res = $conn->fetch($r);
            } catch (Throwable $e) {
                $res = ['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()];
            }
            if (empty($res['ok'])) {
                $checks[] = "Shopee #{$r['id']}: consulta falhou (" . mb_substr((string)($res['error'] ?? ''), 0, 120) . ')';
                continue;
            }
            $c = self::fromShopee($pdo, $r, $res, $slot);
            $c['checks'] = [...$checks, ...$c['checks']];
            if ($c['kind'] === 'oferta_validada') {
                return $c;
            }
            $candidate ??= $c; // guarda o primeiro candidato Shopee e tenta outro produto validável
        }
        if ($candidate !== null) {
            return $candidate;
        }
        // (B) Candidatos do catálogo ML (3.1.9) e produtos ML do catálogo (API de anúncios bloqueada).
        if (Db::tableExists($pdo, 'gmax3_ml_candidates')) {
            foreach (Db::all($pdo, "SELECT * FROM gmax3_ml_candidates WHERE status='novo' ORDER BY last_seen_at DESC, id DESC LIMIT 40") as $m) {
                if (!isset($used['mlc:' . $m['id']])) {
                    $c = self::fromMlCandidate($m, $slot);
                    $c['checks'] = [...$checks, 'Candidato ML do catálogo (sem consulta de anúncio)'];
                    return $c;
                }
            }
        }
        foreach (Db::all($pdo, "SELECT * FROM gmax3_catalog WHERE marketplace='mercadolivre' AND validation_status NOT IN ('arquivado','rejeitado') ORDER BY updated_at DESC, id DESC LIMIT 40") as $r) {
            if (!isset($used['cat:' . $r['id']])) {
                $c = self::fromCatalogOnly($r, $slot);
                $c['checks'] = [...$checks, 'Produto Mercado Livre do catálogo: sem consulta à API de anúncios (bloqueada para o app)'];
                return $c;
            }
        }
        $c = self::simulation($slot);
        $c['checks'] = [...$checks, 'Sem produto real disponível: simulação de formatação'];
        return $c;
    }

    private static function fromShopee(PDO $pdo, array $r, array $res, string $slot): array
    {
        $d = $res['data'] ?? [];
        $now = Time::nowSql();
        $price = isset($d['price_promo_cents']) ? (int)$d['price_promo_cents'] : null;
        $missing = [];
        if ($price === null || $price <= 0) {
            $missing[] = 'preço único confirmado pela API' . (!empty($d['variation_note']) ? ' (' . self::clean((string)$d['variation_note'], 100) . ')' : '');
        }
        if (!empty($d['variation_pending']) || (int)($r['variation_pending'] ?? 0) === 1) {
            $missing[] = 'variação identificada';
        }
        if (($r['availability'] ?? '') === 'indisponivel') {
            $missing[] = 'disponibilidade (produto marcado como indisponível)';
        }
        $linkOk = Url::isAffiliate('shopee', $r['affiliate_url'] ?? null) && AutoLab::setting($pdo, 'autolab_link_afiliado_shopee') === '1';
        if (!$linkOk) {
            $missing[] = Url::isAffiliate('shopee', $r['affiliate_url'] ?? null) ? 'autorização de link afiliado no grupo de teste' : 'link afiliado Shopee válido (link curto da Central de Afiliados)';
        }
        $catalogPrice = isset($r['price_promo_cents']) ? (int)$r['price_promo_cents'] : null;
        $title = self::clean(($d['title'] ?? '') !== '' ? (string)$d['title'] : (string)$r['title']);
        $kind = $missing ? 'candidato' : 'oferta_validada';
        $c = self::base($kind, 'cat:' . $r['id']);
        $c = array_merge($c, ['marketplace' => 'shopee', 'catalog_id' => (int)$r['id'], 'source' => 'api_shopee', 'missing' => $missing,
            'price_confirmed' => $price !== null && $price > 0, 'price_cents' => $price, 'price_catalog_cents' => $catalogPrice, 'price_checked_at' => $now,
            'link_kind' => $kind === 'oferta_validada' ? 'afiliado' : 'nenhum']);
        $lines = [$kind === 'oferta_validada' ? 'TESTE — OFERTA VALIDADA' : 'TESTE — CANDIDATO',
            $kind === 'oferta_validada' ? 'Homologação GMAX AutoLab · não é publicação comercial' : 'Homologação GMAX AutoLab · não é oferta e não tem link de compra',
            '', $title, 'Loja: Shopee · Item ' . self::clean((string)$r['external_id'], 40)];
        if ($price !== null && $price > 0) {
            $lines[] = 'Preço: ' . Money::format($price) . ' — confirmado pela API Shopee em ' . self::local($now);
            $lines[] = 'Preço anterior e desconto: não informados pela API (não exibidos)';
            if ($catalogPrice !== null && $catalogPrice !== $price) {
                $lines[] = 'Observação: preço diferente do último registrado no catálogo (' . Money::format($catalogPrice) . ')';
                $c['checks'][] = 'Preço alterado: catálogo ' . Money::format($catalogPrice) . ' → API ' . Money::format($price);
            }
        } else {
            $lines[] = 'Preço: não confirmado (a API não informou um preço único)';
        }
        if ($kind === 'oferta_validada') {
            $lines[] = 'Disponibilidade: oferta ativa na API Shopee (estoque não informado pela API)';
            $lines[] = 'Link (afiliado Shopee): ' . (string)$r['affiliate_url'];
        } else {
            $lines[] = 'Faltando para virar oferta: ' . implode('; ', $missing);
        }
        $c['text'] = implode("\n", [...$lines, '', 'Ref. AutoLab ' . $slot]);
        $c['checks'][] = "Shopee #{$r['id']}: " . ($kind === 'oferta_validada' ? 'validado' : 'candidato (' . implode('; ', $missing) . ')');
        return $c;
    }

    private static function fromMlCandidate(array $m, string $slot): array
    {
        $c = self::base('candidato', 'mlc:' . $m['id']);
        $c = array_merge($c, ['marketplace' => 'mercadolivre', 'candidate_id' => (int)$m['id'], 'source' => 'catalogo_ml']);
        $lines = ['TESTE — CANDIDATO', 'Homologação GMAX AutoLab · não é oferta e não tem link de compra', '', self::clean((string)$m['name']),
            'Loja: Mercado Livre', 'ID de catálogo: ' . self::clean((string)$m['product_id'], 40) . ' (não é ID de anúncio)'];
        if (!empty($m['bb_item_id'])) {
            $lines[] = 'Anúncio vencedor informado pela API: ' . self::clean((string)$m['bb_item_id'], 40);
        }
        if (!empty($m['category_name'])) {
            $lines[] = 'Categoria: ' . self::clean((string)$m['category_name'], 80);
        }
        $missing = ['preço confirmado', 'disponibilidade confirmada', 'link afiliado (não usado no grupo para o Mercado Livre)'];
        $fresh = !empty($m['bb_price_cents']) && !empty($m['bb_checked_at']) && Time::hoursSince((string)$m['bb_checked_at']) <= 24;
        if ($fresh) {
            $lines[] = 'Preço informado pela API de catálogo em ' . self::local((string)$m['bb_checked_at']) . ': ' . Money::format((int)$m['bb_price_cents']) . ' — NÃO conferido';
            $c['price_cents'] = (int)$m['bb_price_cents'];
            $c['price_checked_at'] = (string)$m['bb_checked_at'];
        } else {
            $lines[] = 'Preço: não disponível' . (!empty($m['bb_price_cents']) ? ' (o último informado tem mais de 24 h e não é usado como atual)' : '');
        }
        if (empty($m['bb_item_id'])) {
            $missing[] = 'anúncio vencedor (ID MLB do anúncio)';
        }
        $c['missing'] = $missing;
        $lines[] = 'Faltando para virar oferta: ' . implode('; ', $missing);
        $c['text'] = implode("\n", [...$lines, '', 'Ref. AutoLab ' . $slot]);
        return $c;
    }

    private static function fromCatalogOnly(array $r, string $slot): array
    {
        $c = self::base('candidato', 'cat:' . $r['id']);
        $c = array_merge($c, ['marketplace' => (string)$r['marketplace'], 'catalog_id' => (int)$r['id'], 'source' => 'catalogo_gmax',
            'price_catalog_cents' => isset($r['price_promo_cents']) ? (int)$r['price_promo_cents'] : null]);
        $isListing = $r['marketplace'] === 'mercadolivre' && preg_match('/^(item:)?MLB\d+$/', (string)$r['external_id']) === 1;
        $lines = ['TESTE — CANDIDATO', 'Homologação GMAX AutoLab · não é oferta e não tem link de compra', '', self::clean((string)$r['title']),
            'Loja: ' . (Url::MARKETPLACES[$r['marketplace']] ?? $r['marketplace'])];
        if (!empty($r['codigo'])) {
            $lines[] = 'Código GMAX: ' . self::clean((string)$r['codigo'], 40);
        }
        if ($isListing) {
            $lines[] = 'ID do anúncio: ' . self::clean(preg_replace('/^item:/', '', (string)$r['external_id']), 40);
        } elseif (!empty($r['external_id'])) {
            $lines[] = 'ID de catálogo: ' . self::clean(preg_replace('/^catalogo:/', '', (string)$r['external_id']), 40) . ' (não é ID de anúncio)';
        }
        if (!empty($r['category'])) {
            $lines[] = 'Categoria: ' . self::clean((string)$r['category'], 80);
        }
        $lines[] = 'Preço: não confirmado agora (o último preço registrado não é usado como atual)';
        $c['missing'] = ['preço confirmado pela API', 'disponibilidade confirmada', 'link afiliado (não usado no grupo para o Mercado Livre)'];
        $lines[] = 'Faltando para virar oferta: ' . implode('; ', $c['missing']);
        $c['text'] = implode("\n", [...$lines, '', 'Ref. AutoLab ' . $slot]);
        return $c;
    }

    public static function simulation(string $slot): array
    {
        $cases = [
            ['Produto fictício de formatação com título bem longo para conferir quebra de linha no celular e no computador — modelo X200 128 GB', 12345, 'título longo'],
            ['Produto fictício sem preço anterior', 4990, 'sem "De" e sem desconto'],
            ['Produto fictício com acentuação: ação, coração, pão, maçã — e símbolos % & + /', 199900, 'acentos e símbolos'],
        ];
        $i = (int)(new DateTimeImmutable(substr($slot, 0, 10)))->format('z') % count($cases) + (str_ends_with($slot, '18:00') ? 1 : 0);
        [$title, $cents, $what] = $cases[$i % count($cases)];
        $c = self::base('simulacao', 'sim:' . $slot);
        $c['missing'] = ['produto real'];
        $c['text'] = implode("\n", ['SIMULAÇÃO — FORMATAÇÃO (dados fictícios)', 'Homologação GMAX AutoLab · produto inexistente, sem link de compra', '',
            $title, 'Preço fictício: ' . Money::format($cents), "Caso testado: $what", '', 'Ref. AutoLab ' . $slot]);
        return $c;
    }
}

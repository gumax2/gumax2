<?php
declare(strict_types=1);

namespace Gmax;

use PDO;

/** Regras que bloqueiam a publicação. Qualquer bloqueio impede a entrada e o envio da fila. */
final class Rules
{
    public const CHANNELS = ['telegram_privado' => 'Telegram — supergrupo privado de homologação'];

    /**
     * Contexto pré-carregado (3.2.0) para avaliar MUITOS produtos de uma vez (Ranking):
     * configurações, fila recente e tabelas antigas lidas uma única vez. Mesma lógica de
     * blockers()/duplicate(); válido só durante a requisição que o criou (nunca guardado).
     */
    public static function context(PDO $pdo): array
    {
        $days = Settings::int($pdo, 'janela_duplicidade_dias', 7);
        $since = Time::now()->modify("-$days days")->format('Y-m-d H:i:s');
        $byCatalog = $byNorm = [];
        foreach (Db::all($pdo, "SELECT q.id, q.state, q.catalog_id, c.affiliate_norm FROM gmax3_pub_queue q JOIN gmax3_catalog c ON c.id = q.catalog_id
            WHERE q.state IN ('agendado','reservado','incerto') OR (q.state = 'confirmado' AND COALESCE(q.finished_at, q.created_at) >= ?) ORDER BY q.id DESC", [$since]) as $q) {
            $byCatalog[(int)$q['catalog_id']][] = ['id' => (int)$q['id'], 'state' => $q['state']];
            if ($q['affiliate_norm'] !== null) {
                $byNorm[(string)$q['affiliate_norm']][] = ['id' => (int)$q['id'], 'state' => $q['state']];
            }
        }
        $offers = Db::tableExists($pdo, 'offers') ? Db::columns($pdo, 'offers') : [];
        $legacyStatus = $legacyByNorm = [];
        if ($offers && in_array('status', $offers, true)) {
            foreach ($pdo->query('SELECT item_id, status FROM offers')->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $legacyStatus[(string)$o['item_id']] ??= $o['status'];
            }
        }
        if ($offers && in_array('affiliate_url', $offers, true)) {
            foreach ($pdo->query('SELECT item_id, affiliate_url FROM offers')->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $legacyByNorm[(string)Url::normalizeAffiliate((string)$o['affiliate_url'])] ??= (string)$o['item_id'];
            }
        }
        $dispatched = [];
        if (Db::tableExists($pdo, 'gmax_dispatches') && in_array('item_id', Db::columns($pdo, 'gmax_dispatches'), true)) {
            $dispatched = array_flip(array_map('strval', $pdo->query('SELECT item_id FROM gmax_dispatches')->fetchAll(PDO::FETCH_COLUMN)));
        }
        return ['max_hours' => Settings::int($pdo, 'validade_preco_horas', 24), 'ml_allowed' => Settings::get($pdo, 'ml_grupo_privado_permitido', '0') === '1',
            'v21_retired' => Settings::get($pdo, 'publicador_v21_aposentado', '0') === '1', 'days' => $days, 'by_catalog' => $byCatalog, 'by_norm' => $byNorm,
            'offers_has_status' => $offers && in_array('status', $offers, true), 'legacy_status' => $legacyStatus, 'legacy_by_norm' => $legacyByNorm, 'dispatched' => $dispatched];
    }

    /** @return list<string> */
    public static function blockers(PDO $pdo, array $r, string $channel, ?array $ctx = null): array
    {
        $b = Catalog::approvalIssues($r);
        if (!isset(self::CHANNELS[$channel])) {
            $b[] = 'Canal desconhecido';
        }
        if ($r['validation_status'] !== 'aprovado') {
            $b[] = 'Oferta não aprovada (estado: ' . (Catalog::STATUSES[$r['validation_status']] ?? $r['validation_status']) . ')';
        }
        $maxHours = $ctx['max_hours'] ?? Settings::int($pdo, 'validade_preco_horas', 24);
        $age = Time::hoursSince($r['price_verified_at'] ?? null);
        if ($age === null) {
            $b[] = 'Preço não confirmado por conferência (dados de planilha/pesquisa não bastam)';
        } elseif ($age > $maxHours) {
            $b[] = sprintf('Preço confirmado há %.0f h (limite: %d h) — reconfira', $age, $maxHours);
        }
        if ($r['marketplace'] === 'mercadolivre' && $channel === 'telegram_privado' && !($ctx['ml_allowed'] ?? Settings::get($pdo, 'ml_grupo_privado_permitido', '0') === '1')) {
            $b[] = 'Regra do programa de afiliados Mercado Livre: links ML não são publicados em grupo privado sem confirmação das regras (configuração ml_grupo_privado_permitido)';
        }
        return array_values(array_unique($b));
    }

    /**
     * Duplicidade: mesmo produto (ou mesmo link afiliado) já confirmado, reservado ou com
     * envio incerto dentro da janela — ou já enviado pelo publicador antigo.
     */
    public static function duplicate(PDO $pdo, array $r, ?int $ignoreQueueId = null, ?array $ctx = null): ?string
    {
        if ($ctx !== null) {
            return self::duplicateFromContext($r, $ignoreQueueId, $ctx);
        }
        $days = Settings::int($pdo, 'janela_duplicidade_dias', 7);
        $since = Time::now()->modify("-$days days")->format('Y-m-d H:i:s');
        $dup = Db::one($pdo, "SELECT q.id, q.state, q.finished_at FROM gmax3_pub_queue q JOIN gmax3_catalog c ON c.id = q.catalog_id
            WHERE (q.catalog_id = ? OR (c.affiliate_norm IS NOT NULL AND c.affiliate_norm = ?)) AND q.id <> ?
              AND (q.state IN ('agendado','reservado','incerto') OR (q.state = 'confirmado' AND COALESCE(q.finished_at, q.created_at) >= ?))
            ORDER BY q.id DESC LIMIT 1", [$r['id'], $r['affiliate_norm'] ?? '', $ignoreQueueId ?? 0, $since]);
        if ($dup) {
            return "Duplicidade: item #{$r['id']} já está na fila ou foi publicado (fila #{$dup['id']}, estado {$dup['state']}) dentro da janela de $days dia(s)";
        }
        $legacyId = $r['marketplace'] === 'shopee' ? self::legacyShopeeId($pdo, $r) : null;
        if ($legacyId !== null) {
            $r['external_id'] = $legacyId;
            if (Db::tableExists($pdo, 'offers') && in_array('status', Db::columns($pdo, 'offers'), true)) {
                $legacy = Db::value($pdo, 'SELECT status FROM offers WHERE item_id=?', [$r['external_id']]);
                if ($legacy === 'enviado') {
                    return 'Duplicidade: item já marcado como enviado pelo publicador antigo (tabela offers)';
                }
                if ($legacy === 'pendente' && Settings::get($pdo, 'publicador_v21_aposentado', '0') !== '1') {
                    // O 3.0 não altera a tabela offers; publicar aqui deixaria o V2.1 enviar de novo.
                    return 'Item ainda pendente na fila do publicador antigo (tabela offers): publicar pelo 3.0 permitiria envio duplicado';
                }
            }
            if (Db::tableExists($pdo, 'gmax_dispatches') && in_array('item_id', Db::columns($pdo, 'gmax_dispatches'), true) && Db::value($pdo, 'SELECT status FROM gmax_dispatches WHERE item_id=? LIMIT 1', [$r['external_id']]) !== null) {
                return 'Duplicidade: item já registrado em gmax_dispatches (publicador antigo) — revisão manual';
            }
        }
        return null;
    }

    /** Mesma regra de duplicate(), lendo do contexto pré-carregado. */
    private static function duplicateFromContext(array $r, ?int $ignore, array $ctx): ?string
    {
        $dup = null;
        $cands = array_merge($ctx['by_catalog'][(int)$r['id']] ?? [], !empty($r['affiliate_norm']) ? ($ctx['by_norm'][(string)$r['affiliate_norm']] ?? []) : []);
        foreach ($cands as $q) {
            if ($q['id'] !== ($ignore ?? 0) && ($dup === null || $q['id'] > $dup['id'])) {
                $dup = $q;
            }
        }
        if ($dup) {
            return "Duplicidade: item #{$r['id']} já está na fila ou foi publicado (fila #{$dup['id']}, estado {$dup['state']}) dentro da janela de {$ctx['days']} dia(s)";
        }
        if ($r['marketplace'] !== 'shopee') {
            return null;
        }
        $legacyId = !empty($r['external_id']) ? (string)$r['external_id'] : (!empty($r['affiliate_norm']) ? ($ctx['legacy_by_norm'][(string)$r['affiliate_norm']] ?? null) : null);
        if ($legacyId === null) {
            return null;
        }
        if ($ctx['offers_has_status']) {
            $legacy = $ctx['legacy_status'][$legacyId] ?? null;
            if ($legacy === 'enviado') {
                return 'Duplicidade: item já marcado como enviado pelo publicador antigo (tabela offers)';
            }
            if ($legacy === 'pendente' && !$ctx['v21_retired']) {
                return 'Item ainda pendente na fila do publicador antigo (tabela offers): publicar pelo 3.0 permitiria envio duplicado';
            }
        }
        if (isset($ctx['dispatched'][$legacyId])) {
            return 'Duplicidade: item já registrado em gmax_dispatches (publicador antigo) — revisão manual';
        }
        return null;
    }

    /**
     * Item Shopee correspondente na tabela offers: pelo ID do anúncio ou, quando o produto foi
     * importado só com o link afiliado, pelo link normalizado. Evita que a checagem cruzada falhe.
     */
    public static function legacyShopeeId(PDO $pdo, array $r): ?string
    {
        if (!empty($r['external_id'])) {
            return (string)$r['external_id'];
        }
        if (empty($r['affiliate_norm']) || !Db::tableExists($pdo, 'offers') || !in_array('affiliate_url', Db::columns($pdo, 'offers'), true)) {
            return null;
        }
        foreach ($pdo->query('SELECT item_id, affiliate_url FROM offers')->fetchAll(PDO::FETCH_ASSOC) as $o) {
            if (Url::normalizeAffiliate((string)$o['affiliate_url']) === $r['affiliate_norm']) {
                return (string)$o['item_id'];
            }
        }
        return null;
    }
}

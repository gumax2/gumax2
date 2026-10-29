<?php
declare(strict_types=1);

namespace Gmax;

use PDO;

/** Regras que bloqueiam a publicação. Qualquer bloqueio impede a entrada e o envio da fila. */
final class Rules
{
    public const CHANNELS = ['telegram_privado' => 'Telegram — supergrupo privado de homologação'];

    /** @return list<string> */
    public static function blockers(PDO $pdo, array $r, string $channel): array
    {
        $b = Catalog::approvalIssues($r);
        if (!isset(self::CHANNELS[$channel])) {
            $b[] = 'Canal desconhecido';
        }
        if ($r['validation_status'] !== 'aprovado') {
            $b[] = 'Oferta não aprovada (estado: ' . (Catalog::STATUSES[$r['validation_status']] ?? $r['validation_status']) . ')';
        }
        $maxHours = Settings::int($pdo, 'validade_preco_horas', 24);
        $age = Time::hoursSince($r['price_verified_at'] ?? null);
        if ($age === null) {
            $b[] = 'Preço não confirmado por conferência (dados de planilha/pesquisa não bastam)';
        } elseif ($age > $maxHours) {
            $b[] = sprintf('Preço confirmado há %.0f h (limite: %d h) — reconfira', $age, $maxHours);
        }
        if ($r['marketplace'] === 'mercadolivre' && $channel === 'telegram_privado' && Settings::get($pdo, 'ml_grupo_privado_permitido', '0') !== '1') {
            $b[] = 'Regra do programa de afiliados Mercado Livre: links ML não são publicados em grupo privado sem confirmação das regras (configuração ml_grupo_privado_permitido)';
        }
        return array_values(array_unique($b));
    }

    /**
     * Duplicidade: mesmo produto (ou mesmo link afiliado) já confirmado, reservado ou com
     * envio incerto dentro da janela — ou já enviado pelo publicador V2.1.
     */
    public static function duplicate(PDO $pdo, array $r, ?int $ignoreQueueId = null): ?string
    {
        $days = Settings::int($pdo, 'janela_duplicidade_dias', 7);
        $since = Time::now()->modify("-$days days")->format('Y-m-d H:i:s');
        $dup = Db::one($pdo, "SELECT q.id, q.state, q.finished_at FROM gmax3_pub_queue q JOIN gmax3_catalog c ON c.id = q.catalog_id
            WHERE (q.catalog_id = ? OR (c.affiliate_norm IS NOT NULL AND c.affiliate_norm = ?)) AND q.id <> ?
              AND (q.state IN ('agendado','reservado','incerto') OR (q.state = 'confirmado' AND COALESCE(q.finished_at, q.created_at) >= ?))
            ORDER BY q.id DESC LIMIT 1", [$r['id'], $r['affiliate_norm'] ?? '', $ignoreQueueId ?? 0, $since]);
        if ($dup) {
            return "Duplicidade: item #{$r['id']} já está na fila ou foi publicado (fila #{$dup['id']}, estado {$dup['state']}) dentro da janela de $days dia(s)";
        }
        if ($r['marketplace'] === 'shopee' && !empty($r['external_id'])) {
            if (Db::tableExists($pdo, 'offers') && in_array('status', Db::columns($pdo, 'offers'), true)) {
                $legacy = Db::value($pdo, 'SELECT status FROM offers WHERE item_id=?', [$r['external_id']]);
                if ($legacy === 'enviado') {
                    return 'Duplicidade: item já marcado como enviado pelo publicador V2.1 (tabela offers)';
                }
                if ($legacy === 'pendente' && Settings::get($pdo, 'publicador_v21_aposentado', '0') !== '1') {
                    // O 3.0 não altera a tabela offers; publicar aqui deixaria o V2.1 enviar de novo.
                    return 'Item ainda pendente na fila do publicador V2.1 (tabela offers): publicar pelo 3.0 permitiria envio duplicado';
                }
            }
            if (Db::tableExists($pdo, 'gmax_dispatches') && in_array('item_id', Db::columns($pdo, 'gmax_dispatches'), true) && Db::value($pdo, 'SELECT status FROM gmax_dispatches WHERE item_id=? LIMIT 1', [$r['external_id']]) !== null) {
                return 'Duplicidade: item já registrado em gmax_dispatches (publicador V2.1) — revisão manual';
            }
        }
        return null;
    }
}

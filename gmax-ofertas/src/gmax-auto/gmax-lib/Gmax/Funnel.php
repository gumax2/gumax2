<?php
declare(strict_types=1);

namespace Gmax;

use PDO;

/**
 * Funil comercial (3.2.2): camada de APRESENTAÇÃO derivada dos dados existentes.
 * Não grava nada e não cria estados novos: a etapa é calculada a partir de validation_status,
 * da conferência de preço, do link afiliado e da fila comercial, com as MESMAS regras que já
 * bloqueiam a aprovação (Catalog::approvalIssues) e a fila (Rules::blockers / Ranking::readiness).
 */
final class Funnel
{
    public const STAGES = [
        'descoberta' => ['Descoberta', 'Candidatos encontrados pelas automações, ainda fora do catálogo'],
        'triagem' => ['Triagem', 'Rascunhos: decidir se vale completar o cadastro'],
        'conferencia' => ['Conferência', 'Preço, variação ou disponibilidade a confirmar'],
        'link' => ['Link afiliado', 'Falta o link oficial de afiliado'],
        'aprovacao' => ['Aprovação', 'Dados completos, aguardando sua aprovação'],
        'fila' => ['Fila', 'Aprovadas: prontas para agendar ou já agendadas'],
        'publicacao' => ['Publicação', 'Envio em andamento ou sem confirmação'],
        'resultado' => ['Resultado', 'Publicadas'],
    ];
    public const SITUATIONS = [
        'preco_desatualizado' => 'Preço desatualizado',
        'variacao' => 'Variação pendente',
        'sem_link' => 'Sem link afiliado',
        'aguardando_aprovacao' => 'Aguardando aprovação',
        'inconsistencia' => 'Inconsistência',
        'disponivel_selecao' => 'Disponível para seleção',
        'triagem' => 'Rascunho para triagem',
    ];
    public const PRIORITIES = [1 => 'Alta', 2 => 'Média', 3 => 'Baixa'];

    /** Contexto carregado UMA vez por requisição: regras, validade do preço e último estado na fila. */
    public static function context(PDO $pdo): array
    {
        $ctx = Rules::context($pdo);
        $q = [];
        foreach (Db::all($pdo, 'SELECT catalog_id, state FROM gmax3_pub_queue WHERE id IN (SELECT MAX(id) FROM gmax3_pub_queue GROUP BY catalog_id)') as $r) {
            $q[(int)$r['catalog_id']] = (string)$r['state'];
        }
        return $ctx + ['queue_state' => $q];
    }

    private static function stale(array $r, int $hours): bool
    {
        $at = $r['price_verified_at'] ?? null;
        return $at === null || $at === '' || Time::hoursSince((string)$at) > $hours;
    }

    /**
     * @return array{stage:string,situation:?string,pending:?string,issues:list<string>,priority:int,
     *               action:?array{label:string,kind:string,page?:string,params?:array}}
     * kind: link (abre página) | approve | enqueue (ações rápidas por POST)
     */
    public static function analyze(PDO $pdo, array $r, array $ctx): array
    {
        $id = (int)$r['id'];
        $st = (string)$r['validation_status'];
        $out = ['stage' => 'triagem', 'situation' => null, 'pending' => null, 'issues' => [], 'priority' => 3, 'action' => null];
        $open = static fn(string $label, string $page, array $p = []) => ['label' => $label, 'kind' => 'link', 'page' => $page, 'params' => $p];
        if (in_array($st, ['arquivado', 'rejeitado'], true)) {
            return ['stage' => 'encerrado'] + $out;
        }
        $queue = $ctx['queue_state'][$id] ?? null;
        if (in_array($queue, ['reservado', 'incerto'], true)) {
            return ['stage' => 'publicacao', 'situation' => 'inconsistencia', 'pending' => 'Envio sem confirmação: reconcilie em Publicações antes de qualquer outro envio',
                'issues' => [], 'priority' => 1, 'action' => $open('Reconciliar envio', 'publicacoes')];
        }
        // Pendências de conferência (mesmas regras da aprovação/fila, agrupadas por tipo).
        $conf = [];
        $situation = null;
        if (empty($r['price_promo_cents'])) {
            $conf[] = 'Preço promocional não informado';
            $situation ??= 'inconsistencia';
        } elseif (self::stale($r, (int)$ctx['max_hours'])) {
            $conf[] = $r['price_verified_at'] ? 'Preço conferido há mais de ' . (int)$ctx['max_hours'] . ' h' : 'Preço nunca conferido';
            $situation ??= 'preco_desatualizado';
        }
        if (!empty($r['variation_pending'])) {
            $conf[] = 'Variação (cor/voltagem/tamanho) pendente';
            $situation = $situation === 'preco_desatualizado' || $situation === null ? 'variacao' : $situation;
        }
        if (($r['availability'] ?? '') === 'indisponivel') {
            $conf[] = 'Marcado como indisponível: confira se voltou ao estoque';
            $situation = 'inconsistencia';
        }
        if (!empty($r['price_original_cents']) && !empty($r['price_promo_cents']) && (int)$r['price_original_cents'] < (int)$r['price_promo_cents']) {
            $conf[] = 'Preço original menor que o promocional';
            $situation = 'inconsistencia';
        }
        if (!empty($r['price_promo_cents']) && empty($r['payment_condition'])) {
            $conf[] = 'Condição de pagamento não identificada';
            $situation ??= 'inconsistencia';
        }
        if (!empty($r['price_original_cents']) && Pricing::originalSource($r) === 'importado') {
            $conf[] = 'Preço original (De) não confirmado';
            $situation ??= 'inconsistencia';
        }
        $linkOk = Url::isAffiliate((string)$r['marketplace'], $r['affiliate_url'] ?? null);
        $issues = $conf;
        if (!$linkOk) {
            $issues[] = 'Link afiliado ausente ou fora do domínio oficial';
        }
        if ($queue === 'confirmado' && !$conf) {
            return ['stage' => 'resultado', 'situation' => null, 'pending' => null, 'issues' => [], 'priority' => 3, 'action' => $open('Ver publicação', 'publicacoes')];
        }
        if ($st === 'rascunho') {
            return ['stage' => 'triagem', 'situation' => 'triagem', 'pending' => $issues ? 'Rascunho: ' . $issues[0] : 'Rascunho completo: envie para validação',
                'issues' => $issues, 'priority' => 3, 'action' => $open('Avaliar rascunho', 'oferta', ['id' => $id])];
        }
        $priority = $st === 'aprovado' || $queue === 'agendado' ? 1 : 2; // pendência em oferta aprovada/agendada bloqueia uma divulgação
        if ($conf) {
            return ['stage' => $queue === 'agendado' ? 'fila' : 'conferencia', 'situation' => $situation, 'pending' => $conf[0], 'issues' => $issues,
                'priority' => $priority, 'action' => $open('Conferir agora', 'conferir', ['id' => $id])];
        }
        if (!$linkOk) {
            return ['stage' => 'link', 'situation' => 'sem_link', 'pending' => 'Falta o link afiliado oficial', 'issues' => $issues,
                'priority' => $priority, 'action' => ['label' => 'Salvar link', 'kind' => 'link_set']];
        }
        if ($st !== 'aprovado') {
            return ['stage' => 'aprovacao', 'situation' => 'aguardando_aprovacao', 'pending' => 'Dados completos: aguardando aprovação', 'issues' => [],
                'priority' => 2, 'action' => ['label' => 'Aprovar', 'kind' => 'approve']];
        }
        if ($queue === 'agendado') {
            return ['stage' => 'fila', 'situation' => null, 'pending' => null, 'issues' => [], 'priority' => 3, 'action' => $open('Ver na fila', 'publicacoes')];
        }
        $block = array_values(array_filter(Ranking::readiness($pdo, $r, 'telegram_privado', $ctx), static fn($b) => !str_starts_with($b, 'Preço original (De)')));
        if ($block) {
            // Bloqueio que não se resolve com uma ação agora (ex.: publicado recentemente, regra do programa).
            return ['stage' => 'fila', 'situation' => null, 'pending' => $block[0], 'issues' => $block, 'priority' => 3, 'action' => $open('Ver detalhes', 'oferta', ['id' => $id])];
        }
        return ['stage' => 'fila', 'situation' => 'disponivel_selecao', 'pending' => 'Pronta: pode ser agendada (o envio continua bloqueado pelas travas)', 'issues' => [],
            'priority' => 2, 'action' => ['label' => 'Agendar na fila', 'kind' => 'enqueue']];
    }

    /** Filtros válidos da fila de trabalho. */
    public static function filters(array $in): array
    {
        $f = [];
        if (isset($in['mp']) && is_string($in['mp']) && isset(Url::MARKETPLACES[$in['mp']])) {
            $f['mp'] = $in['mp'];
        }
        if (isset($in['cat']) && is_string($in['cat']) && $in['cat'] !== '') {
            $f['cat'] = mb_substr($in['cat'], 0, 80);
        }
        if (isset($in['prio']) && is_string($in['prio']) && isset(self::PRIORITIES[(int)$in['prio']])) {
            $f['prio'] = (string)(int)$in['prio'];
        }
        if (isset($in['sit']) && is_string($in['sit']) && isset(self::SITUATIONS[$in['sit']])) {
            $f['sit'] = $in['sit'];
        }
        if (isset($in['etapa']) && is_string($in['etapa']) && isset(self::STAGES[$in['etapa']])) {
            $f['etapa'] = $in['etapa'];
        }
        return $f;
    }

    /**
     * Fila de trabalho: todos os produtos com algo a fazer, na ordem de atendimento
     * (prioridade, etapa do funil, produto mais antigo sem conferência primeiro).
     * @return list<array{item:array,a:array}>
     */
    public static function workQueue(PDO $pdo, array $f = [], ?array $ctx = null): array
    {
        $ctx ??= self::context($pdo);
        $where = "validation_status NOT IN ('arquivado','rejeitado')";
        $args = [];
        if (isset($f['mp'])) {
            $where .= ' AND marketplace = ?';
            $args[] = $f['mp'];
        }
        if (isset($f['cat'])) {
            $where .= ' AND category = ?';
            $args[] = $f['cat'];
        }
        $order = array_flip(array_keys(self::STAGES));
        $out = [];
        $st = $pdo->prepare("SELECT * FROM gmax3_catalog WHERE $where ORDER BY id");
        $st->execute($args);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $a = self::analyze($pdo, $r, $ctx);
            if ($a['situation'] === null || (isset($f['sit']) && $a['situation'] !== $f['sit']) || (isset($f['prio']) && $a['priority'] !== (int)$f['prio'])
                || (isset($f['etapa']) && $a['stage'] !== $f['etapa'])) {
                continue;
            }
            $out[] = ['item' => $r, 'a' => $a];
        }
        usort($out, static fn($x, $y) => [$x['a']['priority'], $order[$x['a']['stage']] ?? 9, (string)($x['item']['price_verified_at'] ?? ''), (int)$x['item']['id']]
            <=> [$y['a']['priority'], $order[$y['a']['stage']] ?? 9, (string)($y['item']['price_verified_at'] ?? ''), (int)$y['item']['id']]);
        return $out;
    }

    /**
     * Próximo produto depois de resolver o da posição $pos: o resolvido sai da fila, então o
     * próximo ocupa a mesma posição; se o produto continua pendente, avança uma posição.
     */
    public static function next(PDO $pdo, array $f, int $currentId, int $pos): ?array
    {
        $q = self::workQueue($pdo, $f);
        $ids = array_map(static fn($x) => (int)$x['item']['id'], $q);
        $i = array_search($currentId, $ids, true);
        $idx = $i === false ? max(0, $pos) : $i + 1;
        if (!isset($q[$idx])) {
            $idx = 0; // volta ao começo (itens que ficaram para trás)
            if (isset($q[$idx]) && (int)$q[$idx]['item']['id'] === $currentId) {
                return null;
            }
        }
        return $q[$idx] ?? null;
    }

    /** Contagem por etapa (inclui descoberta = candidatos ML novos) e por situação. */
    public static function summary(PDO $pdo, ?array $ctx = null): array
    {
        $ctx ??= self::context($pdo);
        $stages = array_fill_keys(array_keys(self::STAGES), 0);
        $sit = array_fill_keys(array_keys(self::SITUATIONS), 0);
        $prio = [1 => 0, 2 => 0, 3 => 0];
        $st = $pdo->query("SELECT * FROM gmax3_catalog WHERE validation_status NOT IN ('arquivado','rejeitado')");
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $a = self::analyze($pdo, $r, $ctx);
            if (isset($stages[$a['stage']])) {
                $stages[$a['stage']]++;
            }
            if ($a['situation'] !== null) {
                $sit[$a['situation']]++;
                $prio[$a['priority']]++;
            }
        }
        if (Db::tableExists($pdo, 'gmax3_ml_candidates')) {
            $stages['descoberta'] = (int)$pdo->query("SELECT COUNT(*) FROM gmax3_ml_candidates WHERE status='novo'")->fetchColumn();
        }
        return ['etapas' => $stages, 'situacoes' => $sit, 'prioridades' => $prio, 'total' => array_sum($sit)];
    }

    /** Versão do produto para impedir ação duplicada ou sobre dados desatualizados (outra aba, automação). */
    public static function version(array $r): string
    {
        return substr(hash('sha256', (string)$r['updated_at'] . '|' . (string)($r['price_verified_at'] ?? '') . '|' . $r['validation_status'] . '|' . ($r['affiliate_url'] ?? '')), 0, 16);
    }
}

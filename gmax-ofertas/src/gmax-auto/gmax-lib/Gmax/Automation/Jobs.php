<?php
declare(strict_types=1);

namespace Gmax\Automation;

use Gmax\Catalog;
use Gmax\Connectors\Registry;
use Gmax\Db;
use Gmax\Queue;
use Gmax\Ranking;
use Gmax\Rules;
use Gmax\Settings;
use Gmax\Spreadsheet;
use Gmax\Time;
use Gmax\Url;
use PDO;
use Throwable;

/**
 * Tarefas automáticas. NENHUMA delas envia mensagens: no máximo colocam ofertas elegíveis na
 * fila, que continua sujeita às travas de envio (pausa, autorização, ativação, reconciliação).
 * Cada tarefa respeita um prazo (processo PHP de curta duração) e devolve contadores.
 */
final class Jobs
{
    public const MARKETPLACES = ['mercadolivre', 'shopee'];

    /** @return array{found:int,created:int,updated:int,approved:int,blocked:int,errors:int,detail:list<string>} */
    private static function counters(): array
    {
        return ['found' => 0, 'created' => 0, 'updated' => 0, 'approved' => 0, 'blocked' => 0, 'errors' => 0, 'detail' => []];
    }

    public static function run(string $job, PDO $pdo, array $params, float $deadline, int $runId, string $actor): array
    {
        return match ($job) {
            'descoberta' => self::discovery($pdo, $params, $deadline, $runId, $actor),
            'revisao_precos' => self::review($pdo, (int)($params['limite'] ?? 60), $deadline, $actor, false),
            'selecao' => self::selection($pdo, $params, $actor),
            'analise_semanal' => self::weekly($pdo, $params, $deadline, $actor),
            'revisao_vencidas' => self::expired($pdo, $params, $actor),
            'saude_catalogo' => self::health($pdo, $actor),
            default => throw new \RuntimeException("Tarefa desconhecida: $job"),
        };
    }

    /** @return list<string> */
    public static function terms(PDO $pdo, string $marketplace): array
    {
        $raw = Settings::get($pdo, 'termos_descoberta_' . $marketplace, '');
        $t = array_values(array_unique(array_filter(array_map(static fn($x) => mb_substr(trim($x), 0, 80), preg_split('/[\n,;]+/u', $raw) ?: []))));
        return array_slice($t, 0, 30);
    }

    // ------------------------------------------------------------------ 1. descoberta
    private static function discovery(PDO $pdo, array $p, float $deadline, int $runId, string $actor): array
    {
        $c = self::counters();
        $perTerm = max(1, min(50, (int)($p['limite_por_termo'] ?? 20)));
        $total = max(1, min(200, (int)($p['limite_total'] ?? 60)));
        foreach (self::MARKETPLACES as $mp) {
            $terms = self::terms($pdo, $mp);
            if (!$terms) {
                $c['detail'][] = "$mp: nenhum termo de pesquisa configurado";
                continue;
            }
            $conn = Registry::get($mp, $pdo);
            if (!$conn->configured()) {
                $c['detail'][] = "$mp: integração não configurada — descoberta não executada";
                Alerts::raise($pdo, 'integracao', Registry::MARKETPLACES[$mp] . ': ' . $conn->status());
                continue;
            }
            foreach ($terms as $term) {
                if (microtime(true) > $deadline || $c['created'] >= $total) {
                    $c['detail'][] = 'Limite de tempo ou de produtos da execução atingido';
                    break 2;
                }
                try {
                    $res = $conn->search($term, $perTerm);
                } catch (Throwable $e) {
                    $res = ['ok' => false, 'error' => $e->getMessage(), 'items' => []];
                }
                if (!$res['ok']) {
                    $c['errors']++;
                    $c['detail'][] = "$mp \"$term\": " . $res['error'];
                    Alerts::raise($pdo, 'integracao', Registry::MARKETPLACES[$mp] . ' — busca "' . $term . '": ' . $res['error']);
                    continue;
                }
                foreach ($res['items'] as $item) {
                    $c['found']++;
                    $outcome = self::ingest($pdo, $item, $term, $actor, $c);
                    Db::run($pdo, 'INSERT INTO gmax3_discoveries(run_id,marketplace,external_id,query,catalog_id,outcome,seen_at) VALUES(?,?,?,?,?,?,?)', [
                        $runId, $mp, (string)$item['external_id'], $term, $outcome[1], $outcome[0], Time::nowSql(),
                    ]);
                    if ($c['created'] >= $total) {
                        break;
                    }
                }
            }
        }
        return $c;
    }

    /** @return array{0:string,1:?int} desfecho e id do produto */
    private static function ingest(PDO $pdo, array $item, string $term, string $actor, array &$c): array
    {
        if (trim((string)($item['title'] ?? '')) === '' || empty($item['external_id'])) {
            return ['incompleto', null];
        }
        if (($item['availability'] ?? '') === 'indisponivel') {
            return ['indisponivel', null];
        }
        if (!empty($item['affiliate_url']) && !Url::isAffiliate($item['marketplace'], $item['affiliate_url'])) {
            $item['affiliate_url'] = null; // nunca gravar link que não seja o oficial
        }
        if (!empty($item['original_url']) && !Url::isProduct($item['marketplace'], $item['original_url'])) {
            $item['original_url'] = null;
        }
        $matches = Catalog::findMatches($pdo, $item);
        if ($matches) {
            return ['existente', (int)$matches[0]['id']];
        }
        $item['notes'] = trim(($item['notes'] ?? '') . ' · Descoberto automaticamente pelo termo "' . $term . '"', ' ·');
        $item['price_source'] = 'api_' . $item['marketplace'];
        $id = Db::immediate($pdo, static fn() => Catalog::insert($pdo, $item, 'rascunho', $actor));
        if (!empty($item['price_promo_cents'])) {
            Catalog::markVerifiedByApi($pdo, $id, $item['marketplace'], $actor);
        }
        if (empty($item['affiliate_url'])) {
            Alerts::raise($pdo, 'sem_link', 'Gere o link afiliado oficial para: ' . $item['title'], $id);
        }
        $c['created']++;
        return ['novo', $id];
    }

    // ------------------------------------------------------------------ 2. revisão de preços
    private static function review(PDO $pdo, int $limit, float $deadline, string $actor, bool $includeDrafts): array
    {
        $c = self::counters();
        $states = $includeDrafts ? "'aprovado','aguardando_validacao','rascunho'" : "'aprovado','aguardando_validacao'";
        $rows = Db::all($pdo, "SELECT c.* FROM gmax3_catalog c
            WHERE c.validation_status IN ($states) AND c.external_id IS NOT NULL
            ORDER BY EXISTS(SELECT 1 FROM gmax3_pub_queue q WHERE q.catalog_id=c.id AND q.state='agendado') DESC,
                     (c.validation_status='aprovado') DESC, COALESCE(c.price_verified_at,'0000') ASC
            LIMIT " . max(1, min(200, $limit)));
        $connectors = [];
        foreach ($rows as $r) {
            if (microtime(true) > $deadline) {
                $c['detail'][] = 'Prazo da execução atingido; o restante fica para a próxima';
                break;
            }
            $conn = $connectors[$r['marketplace']] ??= Registry::get($r['marketplace'], $pdo);
            $c['found']++;
            if (!$conn->configured()) {
                $c['blocked']++;
                if ($r['validation_status'] === 'aprovado') {
                    Alerts::raise($pdo, 'preco_nao_confirmado', 'Sem integração oficial para confirmar o preço: confira manualmente antes de publicar', (int)$r['id']);
                }
                continue;
            }
            try {
                $res = $conn->fetch($r);
            } catch (Throwable $e) {
                $res = ['ok' => false, 'error' => $e->getMessage(), 'data' => []];
            }
            if (!$res['ok']) {
                $c['errors']++;
                $c['blocked']++;
                Alerts::raise($pdo, 'preco_nao_confirmado', 'Preço não confirmado pela API: ' . $res['error'], (int)$r['id']);
                continue;
            }
            $c['updated'] += self::applyFetched($pdo, $r, $res['data'], $actor) ? 1 : 0;
        }
        return $c;
    }

    /** Aplica dados oficiais. Preço confirmado => verificado agora; mudança de preço retira aprovação. */
    private static function applyFetched(PDO $pdo, array $r, array $d, string $actor): bool
    {
        $id = (int)$r['id'];
        $fields = array_intersect_key($d, array_flip(['price_promo_cents', 'price_original_cents', 'availability', 'variation_pending', 'variation_note', 'commission_rate']));
        if (array_key_exists('price_promo_cents', $d) && !array_key_exists('price_original_cents', $d)) {
            $fields['price_original_cents'] = null; // a fonte oficial não informou "De": não manter um "De" antigo
        }
        $res = Db::immediate($pdo, static fn() => Catalog::update($pdo, $r, $fields, 'api:' . $r['marketplace'], false));
        if (!empty($d['price_promo_cents'])) {
            Catalog::markVerifiedByApi($pdo, $id, $r['marketplace'], $actor);
            Alerts::clear($pdo, 'preco_nao_confirmado', $id);
        }
        if (($d['availability'] ?? null) === 'indisponivel') {
            Alerts::raise($pdo, 'indisponivel', 'A API informa anúncio pausado/indisponível', $id);
            foreach (Db::all($pdo, "SELECT id FROM gmax3_pub_queue WHERE catalog_id=? AND state='agendado'", [$id]) as $q) {
                Queue::cancel($pdo, (int)$q['id'], 'automacao');
            }
        } elseif (($d['availability'] ?? null) === 'disponivel') {
            Alerts::clear($pdo, 'indisponivel', $id);
        }
        if (!empty($d['variation_pending'])) {
            Alerts::raise($pdo, 'variacao', (string)($d['variation_note'] ?? 'Variação pendente'), $id);
        }
        return $res['changed'] !== [];
    }

    // ------------------------------------------------------------------ 3. seleção
    private static function selection(PDO $pdo, array $p, string $actor): array
    {
        $c = self::counters();
        $minScore = (int)($p['pontuacao_minima'] ?? 55);
        $minConf = (int)($p['confianca_minima'] ?? 60);
        $auto = Settings::get($pdo, 'aprovacao_automatica', '0') === '1';
        foreach (Ranking::list($pdo, [], 200) as $row) {
            $r = $row['item'];
            if (!in_array($r['validation_status'], ['aprovado', 'aguardando_validacao'], true)) {
                continue;
            }
            $c['found']++;
            $good = ($row['score'] ?? 0) >= $minScore && $row['confidence'] >= $minConf;
            if ($r['validation_status'] === 'aguardando_validacao') {
                $issues = array_filter(Rules::blockers($pdo, $r, 'telegram_privado'), static fn($b) => !str_starts_with($b, 'Oferta não aprovada'));
                $apiVerified = str_starts_with((string)$r['price_verified_by'], 'api:');
                if ($good && !$issues && $auto && $apiVerified) {
                    Catalog::setStatus($pdo, (int)$r['id'], 'aprovado', 'aprovacao_automatica');
                    $c['approved']++;
                    $r = Catalog::get($pdo, (int)$r['id']);
                } else {
                    if ($good && !$issues) {
                        Alerts::raise($pdo, 'aprovacao_pendente', sprintf('Pontuação %d (confiança %d%%): pronta para aprovação manual', $row['score'], $row['confidence']), (int)$r['id']);
                    }
                    continue;
                }
            }
            if (!$good) {
                continue;
            }
            $reasons = Queue::enqueue($pdo, (int)$r['id'], 'telegram_privado', null, 'selecao_automatica');
            if ($reasons) {
                $c['blocked']++;
                if (!str_contains(implode(' ', $reasons), 'Duplicidade')) {
                    $c['detail'][] = "#{$r['id']}: " . mb_substr(implode('; ', $reasons), 0, 160);
                }
            } else {
                $c['updated']++;
                Alerts::clear($pdo, 'aprovacao_pendente', (int)$r['id']);
            }
        }
        return $c;
    }

    // ------------------------------------------------------------------ 4. análise semanal
    private static function weekly(PDO $pdo, array $p, float $deadline, string $actor): array
    {
        $c = self::review($pdo, (int)($p['limite'] ?? 100), $deadline, $actor, true);
        $h = self::health($pdo, $actor);
        $c['blocked'] += $h['blocked'];
        $c['detail'] = array_merge($c['detail'], $h['detail']);
        return $c;
    }

    // ------------------------------------------------------------------ 5. vencidas
    private static function expired(PDO $pdo, array $p, string $actor): array
    {
        $c = self::counters();
        $days = max(1, min(60, (int)($p['dias_vencimento'] ?? 7)));
        $limit = Time::now()->modify("-$days days")->format('Y-m-d H:i:s');
        // Aprovadas com preço vencido voltam para validação (não são publicadas com preço antigo).
        foreach (Db::all($pdo, "SELECT * FROM gmax3_catalog WHERE validation_status='aprovado' AND (price_verified_at IS NULL OR price_verified_at < ?)", [$limit]) as $r) {
            Catalog::setStatus($pdo, (int)$r['id'], 'aguardando_validacao', 'automacao_vencidas');
            foreach (Db::all($pdo, "SELECT id FROM gmax3_pub_queue WHERE catalog_id=? AND state='agendado'", [$r['id']]) as $q) {
                Queue::cancel($pdo, (int)$q['id'], 'automacao_vencidas');
            }
            $c['updated']++;
        }
        // Indisponíveis há mais de N dias são arquivadas: histórico, auditoria e fila são preservados.
        foreach (Db::all($pdo, "SELECT * FROM gmax3_catalog WHERE availability='indisponivel' AND validation_status NOT IN ('arquivado','rejeitado') AND updated_at < ?", [$limit]) as $r) {
            Catalog::setStatus($pdo, (int)$r['id'], 'arquivado', 'automacao_vencidas');
            Alerts::raise($pdo, 'arquivado_auto', "Arquivado: indisponível há mais de $days dias (pode ser restaurado em Ofertas)", (int)$r['id']);
            $c['blocked']++;
        }
        $c['found'] = $c['updated'] + $c['blocked'];
        $sel = self::selection($pdo, [], $actor);
        $c['approved'] = $sel['approved'];
        $c['detail'][] = "Renovação da seleção: {$sel['updated']} oferta(s) para a fila";
        return $c;
    }

    // ------------------------------------------------------------------ 6. saúde do catálogo
    private static function health(PDO $pdo, string $actor): array
    {
        $c = self::counters();
        Db::immediate($pdo, static fn() => Catalog::syncLegacy($pdo, 'sincronizacao_legado'));
        $rows = Db::all($pdo, "SELECT * FROM gmax3_catalog WHERE validation_status NOT IN ('arquivado','rejeitado')");
        $byTitle = [];
        foreach ($rows as $r) {
            $c['found']++;
            $id = (int)$r['id'];
            $key = $r['marketplace'] . '|' . preg_replace('/\s+/', ' ', Spreadsheet::normalizeHeader((string)$r['title']));
            $byTitle[$key][] = $id;
            if (empty($r['affiliate_url'])) {
                if ($r['validation_status'] !== 'rascunho') {
                    Alerts::raise($pdo, 'sem_link', 'Produto sem link afiliado oficial', $id);
                    $c['blocked']++;
                }
            } elseif (!Url::isAffiliate($r['marketplace'], $r['affiliate_url'])) {
                Alerts::raise($pdo, 'link_invalido', 'Link não é o link curto oficial do programa de afiliados', $id);
                $c['blocked']++;
            } else {
                Alerts::clear($pdo, 'sem_link', $id);
                Alerts::clear($pdo, 'link_invalido', $id);
            }
            if (!empty($r['variation_pending'])) {
                Alerts::raise($pdo, 'variacao', (string)($r['variation_note'] ?: 'Variação pendente de escolha'), $id);
            } else {
                Alerts::clear($pdo, 'variacao', $id);
            }
        }
        foreach ($byTitle as $ids) {
            if (count($ids) > 1) {
                foreach ($ids as $id) {
                    Alerts::raise($pdo, 'duplicado_possivel', 'Mesmo nome de outros produtos: #' . implode(', #', array_diff($ids, [$id])), $id);
                }
                $c['blocked']++;
            }
        }
        return $c;
    }
}

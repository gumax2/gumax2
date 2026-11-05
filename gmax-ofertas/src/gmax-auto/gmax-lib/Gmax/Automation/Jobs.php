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
        // pending = itens dentro do limite que NÃO foram processados por falta de tempo (continuação agendada).
        return ['found' => 0, 'created' => 0, 'updated' => 0, 'approved' => 0, 'blocked' => 0, 'errors' => 0, 'pending' => 0, 'detail' => []];
    }

    public static function run(string $job, PDO $pdo, array $params, float $deadline, int $runId, string $actor): array
    {
        return match ($job) {
            'descoberta' => self::discovery($pdo, $params, $deadline, $runId, $actor),
            'revisao_precos' => self::review($pdo, (int)($params['limite'] ?? 60), $deadline, $actor, false, $runId, self::doneIds($params)),
            'selecao' => self::selection($pdo, $params, $actor),
            'analise_semanal' => self::weekly($pdo, $params, $deadline, $actor, $runId),
            'revisao_vencidas' => self::expired($pdo, $params, $actor),
            'saude_catalogo' => self::health($pdo, $actor),
            'candidatos_ml' => self::mlCandidates($pdo, $params, $deadline, $runId),
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
                if (!empty($res['suspended'])) {
                    // Restrição já conhecida (HTTP 403 repetido): uma linha, sem erro nem alerta por termo.
                    $c['detail'][] = "$mp: " . $res['error'];
                    continue 2;
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
            Catalog::markVerifiedByApi($pdo, $id, $item['marketplace'], $actor, (int)$item['price_promo_cents']);
        }
        if (empty($item['affiliate_url'])) {
            Alerts::raise($pdo, 'sem_link', 'Gere o link afiliado oficial para: ' . $item['title'], $id);
        }
        $c['created']++;
        return ['novo', $id];
    }

    // ------------------------------------------------------------------ 2. revisão de preços
    public const REVIEW_STATES = ['aprovado', 'aguardando_validacao'];
    private const FIELD_LABELS = ['price_promo_cents' => 'preço Por', 'price_original_cents' => 'preço De', 'availability' => 'disponibilidade',
        'variation_pending' => 'variação', 'variation_note' => 'nota de variação', 'commission_rate' => 'comissão', 'validation_status' => 'estado'];
    /** Máximo de linhas "fora da revisão" e "não processado" gravadas por execução (o total sempre vai no resumo). */
    private const MAX_EXCLUDED_LOG = 300;
    private const MAX_PENDING_LOG = 100;

    /** Produtos candidatos, na ordem de prioridade da revisão (arquivados e rejeitados ficam de fora). */
    public static function reviewCandidates(PDO $pdo): array
    {
        return Db::all($pdo, "SELECT c.* FROM gmax3_catalog c WHERE c.validation_status NOT IN ('arquivado','rejeitado')
            ORDER BY EXISTS(SELECT 1 FROM gmax3_pub_queue q WHERE q.catalog_id=c.id AND q.state='agendado') DESC,
                     (c.validation_status='aprovado') DESC, COALESCE(c.price_verified_at,'0000') ASC, c.id ASC");
    }

    /** Motivo pelo qual o produto não entra na revisão; null = elegível. */
    public static function reviewExclusion(array $r, bool $includeDrafts): ?string
    {
        $states = $includeDrafts ? [...self::REVIEW_STATES, 'rascunho'] : self::REVIEW_STATES;
        if (!in_array($r['validation_status'], $states, true)) {
            return 'Estado "' . $r['validation_status'] . '": a revisão só consulta produtos ' . ($includeDrafts ? 'aprovados, aguardando validação ou rascunhos' : 'aprovados ou aguardando validação');
        }
        if (trim((string)($r['external_id'] ?? '')) === '') {
            if ($r['marketplace'] === 'mercadolivre') {
                $short = parse_url((string)($r['affiliate_url'] ?? ''), PHP_URL_HOST) === 'meli.la';
                return empty($r['original_url']) && $short
                    ? 'Sem ID do anúncio (MLB): só há o link curto meli.la, que não é consultável em /items/{id}. Cadastre a URL original do anúncio (produto.mercadolivre.com.br/MLB-…) na oferta'
                    : 'Sem ID do anúncio (MLB): a URL original não contém o código MLB do anúncio';
            }
            return 'Sem identificador do produto no marketplace (Item Id): não há o que consultar na API';
        }
        return null;
    }

    /**
     * IDs já consultados nas rodadas anteriores da mesma execução (continuação). A ordem da revisão muda
     * conforme os preços são conferidos, então a continuação não pode depender da posição: pula pelo ID.
     * @return array<int,true>
     */
    private static function doneIds(array $params): array
    {
        $ids = $params['_ja_consultados'] ?? [];
        return is_array($ids) ? array_fill_keys(array_map('intval', $ids), true) : [];
    }

    private static function review(PDO $pdo, int $limit, float $deadline, string $actor, bool $includeDrafts, int $runId = 0, array $done = []): array
    {
        $c = self::counters();
        $c['done_ids'] = [];
        $limit = max(1, min(200, $limit));
        $connectors = [];
        $eligible = $excluded = $validated = $notProcessed = 0;
        $timeUp = false;
        foreach (self::reviewCandidates($pdo) as $r) {
            if (($why = self::reviewExclusion($r, $includeDrafts)) !== null) {
                if (++$excluded <= self::MAX_EXCLUDED_LOG) {
                    RunLog::defer($runId, $r, 'fora_da_revisao', $why);
                }
                continue;
            }
            $eligible++;
            if (isset($done[(int)$r['id']])) {
                continue; // consultado em rodada anterior desta execução: conta no limite, não repete a consulta
            }
            if ($eligible > $limit || microtime(true) > $deadline) {
                $byTime = $eligible <= $limit;
                $timeUp = $timeUp || $byTime;
                $c['pending'] += $byTime ? 1 : 0;
                if (++$notProcessed <= self::MAX_PENDING_LOG) {
                    RunLog::defer($runId, $r, 'nao_processado', !$byTime
                        ? "Limite de $limit produtos por execução atingido: fica para a próxima revisão"
                        : 'Prazo da execução atingido: continuação automática agendada');
                }
                continue;
            }
            $conn = $connectors[$r['marketplace']] ??= Registry::get($r['marketplace'], $pdo);
            $c['found']++;
            $c['done_ids'][] = (int)$r['id'];
            if (!$conn->configured()) {
                $c['blocked']++;
                RunLog::add($pdo, $runId, $r, 'bloqueado', 'Integração ' . (Registry::MARKETPLACES[$r['marketplace']] ?? $r['marketplace']) . ' indisponível: '
                    . $conn->status() . '. Nenhuma consulta feita; preço não validado');
                if ($r['validation_status'] === 'aprovado') {
                    Alerts::raise($pdo, 'preco_nao_confirmado', 'Sem integração oficial para confirmar o preço: confira manualmente antes de publicar', (int)$r['id']);
                }
                continue;
            }
            $checkedAt = Time::nowSql();
            try {
                $res = $conn->fetch($r);
            } catch (Throwable $e) {
                $res = ['ok' => false, 'error' => $e->getMessage(), 'data' => []];
            }
            $api = ['endpoint' => $res['endpoint'] ?? null, 'http' => $res['http'] ?? null, 'checked_at' => $checkedAt];
            if (!empty($res['suspended'])) {
                // Consulta suspensa por 403 repetido: bloqueado (preço não validado), sem contar erro nem abrir alerta por produto.
                $c['blocked']++;
                RunLog::add($pdo, $runId, $r, 'bloqueado', $res['error'] . ' Use a conferência manual.', $api);
                continue;
            }
            if (!$res['ok']) {
                $c['errors']++;
                $c['blocked']++;
                Alerts::raise($pdo, 'preco_nao_confirmado', 'Preço não confirmado pela API: ' . RunLog::sanitize((string)$res['error']), (int)$r['id']);
                $codes = array_filter([$api['http'] ? 'HTTP ' . $api['http'] : null, isset($res['gql_code']) ? 'GraphQL ' . $res['gql_code'] : null]);
                RunLog::add($pdo, $runId, $r, 'bloqueado', 'Consulta à API falhou' . ($codes ? ' (' . implode(', ', $codes) . ')' : '') . ': preço não validado e dados do produto mantidos. Detalhe na coluna da consulta', $api + ['error' => $res['error']]);
                continue;
            }
            $d = $res['data'];
            $out = self::applyFetched($pdo, $r, $d, $actor);
            $c['updated'] += $out['changed'] ? 1 : 0;
            $validated += $out['verified'] ? 1 : 0;
            $notes = [];
            if ($out['changed']) {
                $notes[] = 'Alterado: ' . implode(', ', array_map(static fn($f) => self::FIELD_LABELS[$f] ?? $f, $out['changed']));
            }
            $notes[] = $out['verified'] ? 'preço confirmado pela API' : 'preço NÃO validado: ' . $out['why'];
            if (($d['availability'] ?? null) === 'indisponivel') {
                $notes[] = 'API informa anúncio indisponível: aprovação retirada e agendamentos cancelados';
            }
            if (!empty($d['variation_pending'])) {
                $notes[] = (string)($d['variation_note'] ?? 'variação pendente');
            }
            $outcome = $out['changed'] ? 'atualizado' : ($out['verified'] ? 'validado' : 'sem_alteracao');
            RunLog::add($pdo, $runId, $r, $outcome, implode('; ', $notes), $api + [
                'price_api' => $d['price_promo_cents'] ?? null, 'original_api' => $d['price_original_cents'] ?? null, 'availability' => $d['availability'] ?? null,
            ]);
        }
        RunLog::flush($pdo);
        if ($timeUp) {
            $c['detail'][] = sprintf('Prazo da execução atingido: %d produto(s) pendente(s) ficam para a continuação automática', $c['pending']);
        }
        if ($notProcessed > self::MAX_PENDING_LOG) {
            $c['detail'][] = sprintf('Registro detalhado limitado a %d linhas de "não processado" (total: %d)', self::MAX_PENDING_LOG, $notProcessed);
        }
        $c['detail'][] = sprintf('Elegíveis: %d · fora da revisão: %d · não processados: %d · consultados: %d · preços confirmados pela API: %d',
            $eligible, $excluded, $notProcessed, $c['found'], $validated);
        if ($c['found'] > 0 && $validated === 0) {
            $c['detail'][] = 'Nenhum preço foi confirmado pela API nesta execução';
        }
        return $c;
    }

    /**
     * Aplica dados oficiais. Preço confirmado => verificado agora; mudança de preço retira aprovação.
     * @return array{changed:list<string>,verified:bool,why:string}
     */
    private static function applyFetched(PDO $pdo, array $r, array $d, string $actor): array
    {
        $id = (int)$r['id'];
        $fields = array_intersect_key($d, array_flip(['price_promo_cents', 'price_original_cents', 'availability', 'variation_pending', 'variation_note', 'commission_rate']));
        if (!empty($d['reports_original']) && array_key_exists('price_promo_cents', $d) && !array_key_exists('price_original_cents', $d)) {
            // Fonte que informa "De" quando existe e não informou: não há desconto vigente.
            $fields['price_original_cents'] = null;
        }
        $res = Db::immediate($pdo, static function () use ($pdo, $id, $fields) {
            $fresh = Catalog::get($pdo, $id); // relido dentro da trava: edições concorrentes não são sobrescritas às cegas
            return $fresh ? Catalog::update($pdo, $fresh, $fields, 'api:' . $fresh['marketplace'], false) : ['changed' => [], 'status' => ''];
        });
        $verified = false;
        $why = empty($d['variation_pending']) ? 'a API não devolveu preço' : 'a API devolveu uma faixa de preços entre variações';
        if (!empty($d['price_promo_cents'])) {
            if (Catalog::markVerifiedByApi($pdo, $id, $r['marketplace'], $actor, (int)$d['price_promo_cents'])) {
                Alerts::clear($pdo, 'preco_nao_confirmado', $id);
                $verified = true;
            } else {
                Alerts::raise($pdo, 'preco_nao_confirmado', 'Preço alterado durante a revisão: confirme novamente', $id);
                $why = 'o preço do produto foi alterado durante a revisão (edição concorrente)';
            }
        }
        if (($d['availability'] ?? null) === 'indisponivel') {
            Alerts::raise($pdo, 'indisponivel', 'A API informa anúncio pausado/indisponível', $id);
            if (Catalog::get($pdo, $id)['validation_status'] === 'aprovado') {
                Catalog::setStatus($pdo, $id, 'aguardando_validacao', 'automacao');
            }
            foreach (Db::all($pdo, "SELECT id FROM gmax3_pub_queue WHERE catalog_id=? AND state='agendado'", [$id]) as $q) {
                Queue::cancel($pdo, (int)$q['id'], 'automacao');
            }
        } elseif (($d['availability'] ?? null) === 'disponivel') {
            Alerts::clear($pdo, 'indisponivel', $id);
        }
        if (!empty($d['variation_pending'])) {
            Alerts::raise($pdo, 'variacao', (string)($d['variation_note'] ?? 'Variação pendente'), $id);
        }
        $changed = array_values(array_diff($res['changed'], ['price_verified_at', 'price_verified_by', 'price_seen_at', 'price_source']));
        return ['changed' => $changed, 'verified' => $verified, 'why' => $why];
    }

    // ------------------------------------------------------------------ 2b. candidatos do catálogo ML (3.1.9)
    /**
     * Descobre CANDIDATOS no catálogo oficial do Mercado Livre (/products/search + /products/{id}).
     * Nunca grava no catálogo de ofertas, nunca consulta endpoints suspensos (o guard do conector
     * barra) e nunca estima preço: o preço do anúncio vencedor só é guardado se a API informar.
     */
    private static function mlCandidates(PDO $pdo, array $p, float $deadline, int $runId): array
    {
        $c = self::counters();
        if (!\Gmax\MlCandidates::enabled($pdo)) {
            $c['detail'][] = 'Aplique a migração 004 (Sistema) para registrar candidatos do Mercado Livre';
            return $c;
        }
        $conn = Registry::get('mercadolivre', $pdo);
        if (!$conn->configured() || !method_exists($conn, 'catalogSearch')) {
            $c['detail'][] = 'Mercado Livre não conectado: candidatos não pesquisados';
            return $c;
        }
        $perTerm = max(1, min(30, (int)($p['limite_por_termo'] ?? 10)));
        $maxDetail = max(0, min(50, (int)($p['detalhe_max'] ?? 15)));
        $sources = array_map(static fn($t) => [$t, 'termo', null], self::terms($pdo, 'mercadolivre'));
        $tr = $conn->trends();
        if ($tr['ok']) {
            foreach (array_slice($tr['keywords'], 0, max(0, min(20, (int)($p['tendencias'] ?? 5)))) as $i => $k) {
                $sources[] = [$k, 'tendencia', $i + 1];
            }
        } else {
            $c['detail'][] = 'Tendências indisponíveis: ' . $tr['error'];
        }
        if (!$sources) {
            $c['detail'][] = 'Sem termos de pesquisa do Mercado Livre e sem tendências disponíveis';
            return $c;
        }
        foreach ($sources as [$term, $origem, $pos]) {
            if (microtime(true) > $deadline) {
                $c['detail'][] = 'Prazo da execução atingido; o restante fica para a próxima';
                break;
            }
            $res = $conn->catalogSearch($term, $perTerm);
            if (!empty($res['suspended'])) {
                $c['detail'][] = 'mercadolivre: ' . $res['error'];
                break;
            }
            if (!$res['ok']) {
                $c['errors']++;
                $c['detail'][] = "catálogo \"$term\": " . $res['error'];
                continue;
            }
            foreach ($res['items'] as $item) {
                $c['found']++;
                $out = \Gmax\MlCandidates::upsert($pdo, $item, $origem, $term, $pos, $runId);
                $out === 'novo' ? $c['created']++ : $c['updated']++;
            }
        }
        $since = \Gmax\Time::now()->modify('-24 hours')->format('Y-m-d H:i:s');
        $cats = [];
        foreach (Db::all($pdo, "SELECT product_id FROM gmax3_ml_candidates WHERE status='novo' AND (bb_checked_at IS NULL OR bb_checked_at < ?) ORDER BY last_seen_at DESC LIMIT $maxDetail", [$since]) as $row) {
            if (microtime(true) > $deadline) {
                break;
            }
            $d = $conn->catalogProduct((string)$row['product_id']);
            if (!empty($d['suspended'])) {
                $c['detail'][] = 'mercadolivre: ' . $d['error'];
                break;
            }
            if (!$d['ok']) {
                $c['errors']++;
                continue;
            }
            $name = null;
            if ($d['data']['category_id']) {
                $name = $cats[$d['data']['category_id']] ??= self::mlCategoryName($pdo, (string)$d['data']['category_id']);
            }
            \Gmax\MlCandidates::applyDetail($pdo, (string)$row['product_id'], $d['data'], $name);
        }
        $withPrice = (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_ml_candidates WHERE status='novo' AND bb_price_cents IS NOT NULL");
        $c['detail'][] = sprintf('Candidatos: %d encontrados (%d novos, %d atualizados); %d com preço do anúncio vencedor informado pela API (não conferido). Nenhuma oferta foi criada.', $c['found'], $c['created'], $c['updated'], $withPrice);
        return $c;
    }

    /** Nome da categoria via /categories/{id} (somente leitura); null se indisponível. */
    private static function mlCategoryName(PDO $pdo, string $cat): ?string
    {
        $conn = Registry::get('mercadolivre', $pdo);
        return method_exists($conn, 'categoryName') ? $conn->categoryName($cat) : null;
    }

    // ------------------------------------------------------------------ 3. seleção
    private static function selection(PDO $pdo, array $p, string $actor): array
    {
        $c = self::counters();
        $minScore = (int)($p['pontuacao_minima'] ?? 55);
        $minConf = (int)($p['confianca_minima'] ?? 60);
        $auto = Settings::get($pdo, 'aprovacao_automatica', '0') === '1';
        // Tetos (3.2.0): no máximo as vagas livres dos próximos N dias e N itens por categoria nessa janela.
        [$capacity, $perCat] = self::selectionBudget($pdo);
        $maxCat = max(1, min(20, Settings::int($pdo, 'selecao_max_por_categoria', 2)));
        $queued = 0;
        $skippedCat = 0;
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
            if ($queued >= $capacity) {
                $c['detail'][] = "Teto da seleção atingido: $capacity vaga(s) livre(s) na janela; o restante fica para a próxima seleção";
                break;
            }
            $cat = (string)($r['category'] ?? '') ?: '(sem categoria)';
            if (($perCat[$cat] ?? 0) >= $maxCat) {
                $skippedCat++;
                continue;
            }
            $reasons = Queue::enqueue($pdo, (int)$r['id'], 'telegram_privado', null, 'selecao_automatica');
            if (!$reasons) {
                $queued++;
                $perCat[$cat] = ($perCat[$cat] ?? 0) + 1;
            }
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
        if ($skippedCat) {
            $c['detail'][] = "$skippedCat item(ns) não enfileirado(s) pelo limite de $maxCat por categoria na janela";
        }
        return $c;
    }

    /**
     * Vagas livres nos próximos N dias (limite diário − já agendados/feitos por dia) e quantos
     * itens de cada categoria já estão agendados nessa janela.
     * @return array{0:int,1:array<string,int>}
     */
    public static function selectionBudget(PDO $pdo): array
    {
        $days = max(1, min(14, Settings::int($pdo, 'selecao_horizonte_dias', 3)));
        $limit = Settings::int($pdo, 'limite_diario_telegram_privado', 6);
        $tz = new \DateTimeZone('America/Sao_Paulo');
        $today = Time::now()->setTimezone($tz)->setTime(12, 0);
        $capacity = 0;
        for ($d = 0; $d < $days; $d++) {
            $capacity += max(0, $limit - Queue::countOnDay($pdo, $today->modify("+$d days")));
        }
        $until = $today->modify("+$days days")->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $perCat = [];
        foreach (Db::all($pdo, "SELECT COALESCE(NULLIF(c.category,''),'(sem categoria)') cat, COUNT(*) n FROM gmax3_pub_queue q JOIN gmax3_catalog c ON c.id=q.catalog_id
            WHERE q.state IN ('agendado','reservado') AND q.scheduled_for < ? GROUP BY cat", [$until]) as $x) {
            $perCat[(string)$x['cat']] = (int)$x['n'];
        }
        return [$capacity, $perCat];
    }

    // ------------------------------------------------------------------ 4. análise semanal
    private static function weekly(PDO $pdo, array $p, float $deadline, string $actor, int $runId = 0): array
    {
        $c = self::review($pdo, (int)($p['limite'] ?? 100), $deadline, $actor, true, $runId, self::doneIds($p));
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

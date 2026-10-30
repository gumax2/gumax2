<?php
declare(strict_types=1);

namespace Gmax;

use PDO;
use RuntimeException;

/** Catálogo unificado Shopee + Mercado Livre (tabela gmax3_catalog). */
final class Catalog
{
    public const STATUSES = [
        'rascunho' => 'Rascunho',
        'aguardando_validacao' => 'Aguardando validação',
        'aprovado' => 'Aprovado',
        'rejeitado' => 'Rejeitado',
        'arquivado' => 'Arquivado',
    ];
    public const PAYMENT = [
        'normal' => 'Preço normal (sem condição especial)',
        'pix' => 'Pagamento via Pix',
        'cupom' => 'Preço com cupom',
        'cartao' => 'Preço no cartão',
        'outro' => 'Outra condição (ver observação)',
    ];
    public const AVAILABILITY = [
        'desconhecida' => 'Não informada',
        'disponivel' => 'Disponível',
        'estoque_baixo' => 'Estoque baixo',
        'indisponivel' => 'Indisponível',
    ];

    /** Campos editáveis e importáveis (todos os demais são controlados pelo sistema). */
    public const DATA_FIELDS = [
        'codigo', 'external_id', 'title', 'category', 'store_name', 'original_url', 'affiliate_url',
        'price_original_cents', 'price_promo_cents', 'payment_condition', 'payment_note', 'price_source', 'price_seen_at',
        'sales_public', 'sales_min', 'rating', 'rating_count', 'seller_reputation', 'availability',
        'commission_rate', 'commission_value', 'variation_pending', 'variation_note', 'notes',
    ];
    private const PRICE_FIELDS = ['price_original_cents', 'price_promo_cents', 'payment_condition', 'payment_note'];

    public static function normalizePayment(string $text): array
    {
        $t = mb_strtolower(trim($text), 'UTF-8');
        if ($t === '') {
            return [null, null];
        }
        if (isset(self::PAYMENT[$t])) {
            return [$t, null];
        }
        $note = trim($text);
        return match (true) {
            str_contains($t, 'possível') || str_contains($t, 'possivel') => ['outro', $note],
            $t === 'pix' || str_contains($t, 'via pix') || str_starts_with($t, 'pix') => ['pix', null],
            str_contains($t, 'cupom') => ['cupom', $note === 'Com cupom' ? null : $note],
            str_contains($t, 'cart') => ['cartao', $note],
            in_array($t, ['normal', 'preço exibido', 'preco exibido', 'à vista', 'a vista'], true) => ['normal', null],
            default => ['outro', $note],
        };
    }

    /** Converte "+250 mil", "+10 mil", "+500", "1,2 mil" no mínimo informado publicamente. */
    public static function parseSales(string $text): ?int
    {
        $t = mb_strtolower(trim($text), 'UTF-8');
        if (!preg_match('/^\+?\s*(\d+(?:[.,]\d+)?)\s*(mil|k)?\b/u', $t, $m)) {
            return null;
        }
        $n = (float)str_replace(',', '.', $m[1]);
        if (!empty($m[2])) {
            $n *= 1000;
        } elseif (str_contains($m[1], '.') && preg_match('/^\d{1,3}\.\d{3}$/', $m[1])) {
            $n = (float)str_replace('.', '', $m[1]);
        }
        return (int)round($n);
    }

    public static function dedupKey(array $row): string
    {
        $mp = $row['marketplace'];
        if (!empty($row['external_id'])) {
            return ($mp === 'shopee' ? 'item:' : '') . $row['external_id'];
        }
        if (!empty($row['codigo'])) {
            return 'codigo:' . $row['codigo'];
        }
        if (!empty($row['affiliate_url'])) {
            return 'link:' . Url::normalizeAffiliate($row['affiliate_url']);
        }
        return 'titulo:' . hash('sha256', mb_strtolower((string)$row['title'], 'UTF-8'));
    }

    /**
     * Localiza produtos existentes por QUALQUER identificador (código, ID do anúncio,
     * link afiliado, chave). Mais de um candidato = conflito que exige revisão manual.
     * @return list<array>
     */
    public static function findMatches(PDO $pdo, array $row): array
    {
        $conds = ['(marketplace = ? AND dedup_key = ?)'];
        $args = [$row['marketplace'], self::dedupKey($row)];
        if (!empty($row['codigo'])) {
            $conds[] = '(marketplace = ? AND codigo = ?)';
            array_push($args, $row['marketplace'], $row['codigo']);
        }
        if (!empty($row['external_id'])) {
            $conds[] = '(marketplace = ? AND external_id = ?)';
            array_push($args, $row['marketplace'], $row['external_id']);
        }
        if (!empty($row['affiliate_url']) && ($norm = Url::normalizeAffiliate($row['affiliate_url']))) {
            $conds[] = 'affiliate_norm = ?';
            $args[] = $norm;
        }
        return Db::all($pdo, 'SELECT * FROM gmax3_catalog WHERE ' . implode(' OR ', $conds) . ' ORDER BY id', $args);
    }

    public static function get(PDO $pdo, int $id): ?array
    {
        return Db::one($pdo, 'SELECT * FROM gmax3_catalog WHERE id=?', [$id]);
    }

    public static function snapshotHash(array $row): string
    {
        unset($row['updated_at']);
        ksort($row);
        return hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE));
    }

    public static function insert(PDO $pdo, array $data, string $status, string $actor): int
    {
        $data = array_intersect_key($data, array_flip(self::DATA_FIELDS)) + ['marketplace' => $data['marketplace']];
        // Colunas com valor padrão no banco não recebem NULL.
        foreach (['variation_pending', 'availability'] as $f) {
            if (array_key_exists($f, $data) && $data[$f] === null) {
                unset($data[$f]);
            }
        }
        $data['dedup_key'] = self::dedupKey($data);
        $data['affiliate_norm'] = empty($data['affiliate_url']) ? null : Url::normalizeAffiliate($data['affiliate_url']);
        $data['validation_status'] = $status;
        $data['created_at'] = $data['updated_at'] = Time::nowSql();
        $cols = array_keys($data);
        Db::run($pdo, 'INSERT INTO gmax3_catalog(' . implode(',', $cols) . ') VALUES(' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($data));
        $id = (int)$pdo->lastInsertId();
        if (isset($data['price_promo_cents'])) {
            self::recordPrice($pdo, $id, $data, (string)($data['price_source'] ?? 'importacao'), false, $data['price_seen_at'] ?? null);
        }
        Audit::log($pdo, $actor, 'produto_criado', 'catalog', $id, ['marketplace' => $data['marketplace'], 'titulo' => $data['title']]);
        return $id;
    }

    /**
     * Atualiza campos de dados. Valores vazios do arquivo não apagam dados existentes
     * ($fillOnly=true). Mudança de preço ou link retira a aprovação e a confirmação de preço.
     * @return array{changed:list<string>,status:string}
     */
    public static function update(PDO $pdo, array $current, array $data, string $actor, bool $fillOnly): array
    {
        $changes = [];
        foreach (self::DATA_FIELDS as $f) {
            if (!array_key_exists($f, $data)) {
                continue;
            }
            $new = $data[$f];
            if ($fillOnly && ($new === null || $new === '')) {
                continue;
            }
            $old = $current[$f];
            $same = ($old === null && $new === null) || ($old !== null && $new !== null && (string)$old === (string)$new);
            if (!$same) {
                $changes[$f] = $new === '' ? null : $new;
            }
        }
        if (!array_intersect(array_keys($changes), self::PRICE_FIELDS)) {
            // Data/fonte do preço só mudam junto com o próprio preço.
            unset($changes['price_seen_at'], $changes['price_source']);
        }
        $status = (string)$current['validation_status'];
        if (!$changes) {
            return ['changed' => [], 'status' => $status];
        }
        $priceChanged = (bool)array_intersect(array_keys($changes), self::PRICE_FIELDS);
        $linkChanged = array_key_exists('affiliate_url', $changes);
        if (($priceChanged || $linkChanged) && $status === 'aprovado') {
            $changes['validation_status'] = $status = 'aguardando_validacao';
        }
        if ($priceChanged) {
            $changes['price_verified_at'] = null;
            $changes['price_verified_by'] = null;
        }
        if ($linkChanged) {
            $changes['affiliate_norm'] = empty($changes['affiliate_url']) ? null : Url::normalizeAffiliate($changes['affiliate_url']);
        }
        $changes['updated_at'] = Time::nowSql();
        $set = implode(',', array_map(static fn($c) => "$c = ?", array_keys($changes)));
        Db::run($pdo, "UPDATE gmax3_catalog SET $set WHERE id = ?", [...array_values($changes), $current['id']]);
        if ($priceChanged) {
            $merged = array_merge($current, $changes);
            self::recordPrice($pdo, (int)$current['id'], $merged, (string)($data['price_source'] ?? $merged['price_source'] ?? 'edicao'), false, $data['price_seen_at'] ?? null);
        }
        $fields = array_values(array_diff(array_keys($changes), ['updated_at', 'affiliate_norm']));
        Audit::log($pdo, $actor, 'produto_atualizado', 'catalog', $current['id'], ['campos' => $fields]);
        return ['changed' => $fields, 'status' => $status];
    }

    public static function recordPrice(PDO $pdo, int $id, array $row, string $source, bool $verified, ?string $observedAt): void
    {
        if (!isset($row['price_promo_cents'])) {
            return;
        }
        Db::run($pdo, 'INSERT INTO gmax3_price_history(catalog_id,price_original_cents,price_promo_cents,payment_condition,source,verified,observed_at,recorded_at) VALUES(?,?,?,?,?,?,?,?)', [
            $id, $row['price_original_cents'] ?? null, $row['price_promo_cents'], $row['payment_condition'] ?? null, $source, $verified ? 1 : 0, $observedAt, Time::nowSql(),
        ]);
    }

    /** Pendências que impedem a APROVAÇÃO (dados mínimos para publicar). */
    public static function approvalIssues(array $r): array
    {
        $issues = [];
        if (!Url::isAffiliate((string)$r['marketplace'], $r['affiliate_url'] ?? null)) {
            $issues[] = 'Link afiliado ausente ou fora do domínio oficial';
        }
        if (empty($r['price_promo_cents'])) {
            $issues[] = 'Preço promocional não informado';
        }
        if (!empty($r['price_original_cents']) && !empty($r['price_promo_cents']) && (int)$r['price_original_cents'] < (int)$r['price_promo_cents']) {
            $issues[] = 'Preço original menor que o promocional';
        }
        if (empty($r['payment_condition'])) {
            $issues[] = 'Condição de pagamento não identificada';
        }
        if (!empty($r['variation_pending'])) {
            $issues[] = 'Variação (cor/voltagem/tamanho) pendente de validação';
        }
        if (($r['availability'] ?? '') === 'indisponivel') {
            $issues[] = 'Produto marcado como indisponível';
        }
        return $issues;
    }

    public static function setStatus(PDO $pdo, int $id, string $status, string $actor, bool $confirmPrice = false): array
    {
        if (!isset(self::STATUSES[$status])) {
            throw new RuntimeException('Estado inválido');
        }
        $r = self::get($pdo, $id) ?? throw new RuntimeException('Produto não encontrado');
        if ($status === 'aprovado' && ($issues = self::approvalIssues($r))) {
            return $issues;
        }
        Db::run($pdo, 'UPDATE gmax3_catalog SET validation_status=?, updated_at=? WHERE id=?', [$status, Time::nowSql(), $id]);
        Audit::log($pdo, $actor, 'status_alterado', 'catalog', $id, ['de' => $r['validation_status'], 'para' => $status]);
        if ($confirmPrice) {
            self::confirmPrice($pdo, $id, $actor);
        }
        return [];
    }

    /**
     * Preço/condição confirmados por fonte OFICIAL autorizada (API do marketplace) neste instante.
     * Registra a origem ("api:mercadolivre") e grava o histórico como verificado.
     */
    public static function markVerifiedByApi(PDO $pdo, int $id, string $marketplace, string $actor, ?int $apiPromo = null): bool
    {
        $r = self::get($pdo, $id);
        if (!$r || empty($r['price_promo_cents'])) {
            return false;
        }
        // Só confirma se o preço gravado é exatamente o que a API devolveu (evita carimbar uma edição concorrente).
        if ($apiPromo !== null && (int)$r['price_promo_cents'] !== $apiPromo) {
            return false;
        }
        $now = Time::nowSql();
        Db::run($pdo, 'UPDATE gmax3_catalog SET price_verified_at=?, price_verified_by=?, price_source=?, price_seen_at=? WHERE id=?', [
            $now, 'api:' . $marketplace, 'api_' . $marketplace, substr($now, 0, 10), $id,
        ]);
        self::recordPrice($pdo, $id, $r, 'api_' . $marketplace, true, $now);
        Audit::log($pdo, $actor, 'preco_confirmado_api', 'catalog', $id, ['fonte' => 'api:' . $marketplace, 'por' => $r['price_promo_cents'], 'de' => $r['price_original_cents']]);
        return true;
    }

    /** O administrador declara que conferiu agora, na loja, o preço e a condição cadastrados. */
    public static function confirmPrice(PDO $pdo, int $id, string $actor): bool
    {
        $r = self::get($pdo, $id);
        if (!$r || empty($r['price_promo_cents'])) {
            return false;
        }
        $now = Time::nowSql();
        Db::run($pdo, 'UPDATE gmax3_catalog SET price_verified_at=?, price_verified_by=?, price_source=?, updated_at=? WHERE id=?', [$now, $actor, 'conferencia_manual', $now, $id]);
        self::recordPrice($pdo, $id, $r, 'conferencia_manual', true, $now);
        Audit::log($pdo, $actor, 'preco_confirmado', 'catalog', $id, ['por' => $r['price_promo_cents'], 'de' => $r['price_original_cents']]);
        return true;
    }

    /**
     * Copia (sem alterar) os produtos das tabelas legadas para o catálogo. Idempotente:
     * produtos já copiados são ignorados. Executado na migração e a cada carregamento do painel.
     * @return array{shopee:int,mercadolivre:int}
     */
    public static function syncLegacy(PDO $pdo, string $actor): array
    {
        $done = ['shopee' => 0, 'mercadolivre' => 0];
        if (Db::tableExists($pdo, 'offers')) {
            $cols = Db::columns($pdo, 'offers');
            if (array_diff(['item_id', 'title', 'affiliate_url'], $cols) === []) {
                foreach ($pdo->query('SELECT * FROM offers ORDER BY created_at, item_id')->fetchAll(PDO::FETCH_ASSOC) as $o) {
                    $row = [
                        'marketplace' => 'shopee',
                        'codigo' => (string)$o['item_id'],
                        'external_id' => (string)$o['item_id'],
                        'title' => (string)$o['title'],
                        'store_name' => ($o['shop'] ?? '') ?: null,
                        'original_url' => $o['original_url'] ?? null,
                        'affiliate_url' => $o['affiliate_url'],
                        'price_promo_cents' => Money::tryParse($o['price'] ?? null),
                        'price_source' => 'legado_offers',
                        'price_seen_at' => Time::parseDate((string)($o['created_at'] ?? '')),
                        'commission_rate' => ($o['commission_rate'] ?? '') ?: null,
                        'commission_value' => ($o['commission'] ?? '') ?: null,
                    ];
                    if ($matches = self::findMatches($pdo, $row)) {
                        // Produto importado só com o link: completa o ID do anúncio (sem tocar em offers).
                        if (count($matches) === 1 && empty($matches[0]['external_id'])
                            && Db::value($pdo, "SELECT 1 FROM gmax3_catalog WHERE marketplace='shopee' AND external_id=?", [$row['external_id']]) === null) {
                            Db::run($pdo, 'UPDATE gmax3_catalog SET external_id=?, legacy_table=COALESCE(legacy_table,?), legacy_key=COALESCE(legacy_key,?) WHERE id=?', [$row['external_id'], 'offers', $row['external_id'], $matches[0]['id']]);
                        }
                        continue;
                    }
                    $id = self::insertLegacy($pdo, $row, $actor, 'offers', (string)$o['item_id']);
                    if ($id === null) {
                        continue;
                    }
                    $done['shopee']++;
                }
            }
        }
        if (Db::tableExists($pdo, 'gmax_ml_offers')) {
            foreach ($pdo->query('SELECT * FROM gmax_ml_offers ORDER BY codigo_gmax')->fetchAll(PDO::FETCH_ASSOC) as $o) {
                [$payment, $note] = self::normalizePayment((string)($o['condicao_preco'] ?? ''));
                $mlId = Url::mlId($o['url_original'] ?? null);
                $row = [
                    'marketplace' => 'mercadolivre',
                    'codigo' => (string)$o['codigo_gmax'],
                    'external_id' => $mlId,
                    'title' => (string)$o['produto'],
                    'category' => ($o['categoria'] ?? '') ?: null,
                    'original_url' => ($o['url_original'] ?? '') ?: null,
                    'affiliate_url' => ($o['url_afiliado'] ?? '') ?: null,
                    'price_original_cents' => isset($o['preco_original_centavos']) ? (int)$o['preco_original_centavos'] : null,
                    'price_promo_cents' => isset($o['preco_promocional_centavos']) ? (int)$o['preco_promocional_centavos'] : null,
                    'payment_condition' => $payment,
                    'payment_note' => $note,
                    'price_source' => 'legado_gmax_ml_offers',
                    'price_seen_at' => Time::parseDate((string)($o['verificado_em'] ?? '')),
                    'notes' => ($o['observacoes'] ?? '') ?: null,
                ];
                if (self::findMatches($pdo, $row)) {
                    continue;
                }
                $id = self::insertLegacy($pdo, $row, $actor, 'gmax_ml_offers', (string)$o['codigo_gmax']);
                if ($id === null) {
                    continue;
                }
                $done['mercadolivre']++;
            }
        }
        return $done;
    }

    /** Assinatura barata das tabelas legadas (evita sincronizar a cada página). */
    public static function legacySignature(PDO $pdo): string
    {
        $parts = [];
        foreach (['offers', 'gmax_ml_offers'] as $t) {
            $parts[] = Db::tableExists($pdo, $t) ? $pdo->query("SELECT COUNT(*) || ':' || COALESCE(MAX(rowid),0) FROM $t")->fetchColumn() : '-';
        }
        return implode('|', $parts);
    }

    /** Insere cópia de linha legada; conflito de unicidade é ignorado (produto já representado). */
    private static function insertLegacy(PDO $pdo, array $row, string $actor, string $table, string $key): ?int
    {
        $pdo->exec('SAVEPOINT legado');
        try {
            $id = self::insert($pdo, $row, 'aguardando_validacao', $actor);
            Db::run($pdo, 'UPDATE gmax3_catalog SET legacy_table=?, legacy_key=? WHERE id=?', [$table, $key, $id]);
            $pdo->exec('RELEASE legado');
            return $id;
        } catch (\PDOException $e) {
            $pdo->exec('ROLLBACK TO legado');
            $pdo->exec('RELEASE legado');
            if (!str_contains($e->getMessage(), 'UNIQUE')) {
                throw $e;
            }
            return null;
        }
    }

    /** Estado do registro legado (ex.: "enviado" pelo publicador antigo). */
    public static function legacyStatus(PDO $pdo, array $r): ?string
    {
        if ($r['legacy_table'] === 'offers' && Db::tableExists($pdo, 'offers')) {
            return Db::value($pdo, 'SELECT status FROM offers WHERE item_id=?', [$r['legacy_key']]);
        }
        if ($r['legacy_table'] === 'gmax_ml_offers' && Db::tableExists($pdo, 'gmax_ml_offers')) {
            return Db::value($pdo, 'SELECT status FROM gmax_ml_offers WHERE codigo_gmax=?', [$r['legacy_key']]);
        }
        return null;
    }

    /**
     * Listagem com filtros. $f: marketplace, status, category, q, stale(1), nolink(1), page.
     * @return array{rows:list<array>,total:int}
     */
    public static function search(PDO $pdo, array $f, int $perPage = 30, int $staleHours = 48): array
    {
        $where = ['1=1'];
        $args = [];
        if (!empty($f['marketplace']) && isset(Url::MARKETPLACES[$f['marketplace']])) {
            $where[] = 'marketplace = ?';
            $args[] = $f['marketplace'];
        }
        if (!empty($f['status']) && isset(self::STATUSES[$f['status']])) {
            $where[] = 'validation_status = ?';
            $args[] = $f['status'];
        } else {
            $where[] = "validation_status <> 'arquivado'";
        }
        if (!empty($f['category'])) {
            $where[] = 'category = ?';
            $args[] = (string)$f['category'];
        }
        if (isset($f['q']) && trim((string)$f['q']) !== '') {
            $q = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr(trim((string)$f['q']), 0, 100)) . '%';
            $where[] = "(title LIKE ? ESCAPE '\\' OR codigo LIKE ? ESCAPE '\\' OR external_id LIKE ? ESCAPE '\\' OR category LIKE ? ESCAPE '\\')";
            array_push($args, $q, $q, $q, $q);
        }
        if (!empty($f['stale'])) {
            $where[] = '(price_verified_at IS NULL OR price_verified_at < ?)';
            $args[] = Time::now()->modify("-{$staleHours} hours")->format('Y-m-d H:i:s');
        }
        if (!empty($f['nolink'])) {
            $where[] = "(affiliate_url IS NULL OR affiliate_url = '')";
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE $sqlWhere", $args);
        $page = min(max(1, (int)($f['page'] ?? 1)), 100000);
        $offset = ($page - 1) * $perPage;
        $rows = Db::all($pdo, "SELECT * FROM gmax3_catalog WHERE $sqlWhere ORDER BY updated_at DESC, id DESC LIMIT $perPage OFFSET $offset", $args);
        return ['rows' => $rows, 'total' => $total];
    }
}

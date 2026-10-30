<?php
declare(strict_types=1);

namespace Gmax;

use PDO;
use RuntimeException;

/**
 * Importação em lote pelo navegador: arquivo -> prévia (nada é gravado no catálogo) ->
 * aplicação em transação única -> possibilidade de desfazer o lote.
 * Duplicidade: um produto é reconhecido por código GMAX, ID do anúncio, link afiliado ou chave.
 */
final class Importer
{
    public const FORMATS = [
        'shopee' => 'Shopee — CSV "Obter Link em Massa"',
        'gmax_ml' => 'Mercado Livre — modelo GMAX (codigo_gmax)',
        'gmax_unificado' => 'Modelo GMAX unificado (Shopee + Mercado Livre)',
        'ml_pesquisa' => 'Mercado Livre — planilha "Links para converter" (25 anúncios)',
        'ml_conferencia' => 'Mercado Livre — planilha "Conferência de links" (16 candidatos)',
    ];

    private const SIGNATURES = [
        'gmax_unificado' => ['marketplace', 'produto', 'url_afiliado', 'preco_promocional'],
        'gmax_ml' => ['codigo_gmax', 'produto', 'url_afiliado', 'preco_promocional'],
        'shopee' => ['item id', 'item name', 'price', 'offer link'],
        'ml_pesquisa' => ['id anuncio', 'url produto original', 'link afiliado (preencher)'],
        'ml_conferencia' => ['url de origem', 'link de afiliado informado', 'por (r$)'],
    ];

    public const UNIFIED_HEADER = [
        'marketplace', 'codigo', 'categoria', 'produto', 'loja', 'url_original', 'url_afiliado', 'preco_original', 'preco_promocional',
        'condicao_pagamento', 'observacao_pagamento', 'data_preco', 'vendas_publicas', 'avaliacao', 'num_avaliacoes',
        'reputacao_vendedor', 'disponibilidade', 'variacao_pendente', 'observacoes',
    ];

    public static function detect(array $header): ?string
    {
        $h = array_map([Spreadsheet::class, 'normalizeHeader'], $header);
        foreach (self::SIGNATURES as $format => $required) {
            if (array_diff($required, $h) === []) {
                return $format;
            }
        }
        return null;
    }

    /**
     * Lê e valida o arquivo, gravando apenas um lote em estado "prévia".
     * @return int id do lote
     */
    public static function preview(PDO $pdo, string $tmpPath, string $originalName, string $actor): int
    {
        $rows = Spreadsheet::read($tmpPath, $originalName);
        if (count($rows) < 2) {
            throw new RuntimeException('Arquivo sem linhas de produtos');
        }
        $header = array_shift($rows);
        $format = self::detect($header) ?? throw new RuntimeException('Cabeçalho não reconhecido. Use um dos modelos aceitos (veja "Modelos aceitos" abaixo).');
        $h = array_map([Spreadsheet::class, 'normalizeHeader'], $header);
        $sha = hash_file('sha256', $tmpPath) ?: '';
        $name = mb_substr((string)preg_replace('/[^\pL\pN ._()-]+/u', '_', basename($originalName)), 0, 120);

        return (int)Db::immediate($pdo, static function (PDO $pdo) use ($rows, $h, $format, $sha, $name, $actor) {
            Db::run($pdo, 'INSERT INTO gmax3_import_batches(filename,format,sha256,status,actor,created_at) VALUES(?,?,?,?,?,?)', [$name, $format, $sha, 'previa', $actor, Time::nowSql()]);
            $batchId = (int)$pdo->lastInsertId();
            $seen = [];
            $counts = ['total' => 0, 'inserir' => 0, 'atualizar' => 0, 'sem_alteracao' => 0, 'rejeitado' => 0];
            foreach ($rows as $i => $cells) {
                $line = $i + 2;
                if (implode('', $cells) === '') {
                    continue;
                }
                $assoc = [];
                foreach ($h as $idx => $col) {
                    $assoc[$col] = trim((string)($cells[$idx] ?? ''));
                }
                [$payload, $errors, $warnings] = self::normalize($format, $assoc);
                $counts['total']++;
                $action = 'rejeitado';
                $catalogId = null;
                if (!$errors) {
                    foreach (self::identities($payload) as $key) {
                        if (isset($seen[$key])) {
                            $errors[] = "Produto repetido no próprio arquivo (mesmo identificador da linha {$seen[$key]})";
                            break;
                        }
                    }
                }
                if (!$errors) {
                    foreach (self::identities($payload) as $key) {
                        $seen[$key] = $line;
                    }
                    [$action, $catalogId, $planError] = self::plan($pdo, $payload);
                    if ($planError) {
                        $errors[] = $planError;
                    }
                }
                $counts[$action]++;
                Db::run($pdo, 'INSERT INTO gmax3_import_items(batch_id,line,action,catalog_id,payload_json,errors_json,warnings_json) VALUES(?,?,?,?,?,?,?)', [
                    $batchId, $line, $action, $catalogId, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $errors ? json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $warnings ? json_encode($warnings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                ]);
            }
            Db::run($pdo, 'UPDATE gmax3_import_batches SET total=?,inserted=?,updated=?,unchanged=?,rejected=? WHERE id=?', [
                $counts['total'], $counts['inserir'], $counts['atualizar'], $counts['sem_alteracao'], $counts['rejeitado'], $batchId,
            ]);
            Audit::log($pdo, $actor, 'importacao_previa', 'import_batch', $batchId, ['arquivo' => $name, 'formato' => $format] + $counts);
            return $batchId;
        });
    }

    /** @return list<string> */
    private static function identities(array $p): array
    {
        $ids = [];
        if (!empty($p['codigo'])) {
            $ids[] = $p['marketplace'] . '|c|' . $p['codigo'];
        }
        if (!empty($p['external_id'])) {
            $ids[] = $p['marketplace'] . '|e|' . $p['external_id'];
        }
        if (!empty($p['affiliate_url'])) {
            $ids[] = 'a|' . Url::normalizeAffiliate($p['affiliate_url']);
        }
        return $ids;
    }

    /** @return array{0:string,1:?int,2:?string} ação, id existente, erro */
    private static function plan(PDO $pdo, array $payload): array
    {
        $matches = Catalog::findMatches($pdo, $payload);
        if (count($matches) > 1) {
            return ['rejeitado', null, 'Conflito: identificadores apontam para produtos diferentes (#' . implode(', #', array_column($matches, 'id')) . '). Revise manualmente.'];
        }
        if (!$matches) {
            return ['inserir', null, null];
        }
        $cur = $matches[0];
        if ($cur['marketplace'] !== $payload['marketplace']) {
            return ['rejeitado', (int)$cur['id'], 'Link afiliado já associado a produto de outro marketplace (#' . $cur['id'] . ')'];
        }
        foreach (['codigo', 'external_id'] as $f) {
            if (!empty($payload[$f]) && !empty($cur[$f]) && $payload[$f] !== $cur[$f]) {
                return ['rejeitado', (int)$cur['id'], "Conflito: produto #{$cur['id']} já possui $f diferente ({$cur[$f]})"];
            }
        }
        foreach (array_diff(Catalog::DATA_FIELDS, ['price_seen_at', 'price_source']) as $f) {
            if (array_key_exists($f, $payload) && $payload[$f] !== null && $payload[$f] !== '' && (string)$payload[$f] !== (string)($cur[$f] ?? '')) {
                return ['atualizar', (int)$cur['id'], null];
            }
        }
        return ['sem_alteracao', (int)$cur['id'], null];
    }

    /** @return array{0:array,1:list<string>,2:list<string>} */
    public static function normalize(string $format, array $r): array
    {
        $e = [];
        $w = [];
        $money = static function (string $label, string $v) use (&$e): ?int {
            $err = null;
            $c = Money::tryParse($v, $err);
            if ($err) {
                $e[] = "$label inválido: \"$v\"";
            }
            return $c;
        };
        $p = ['status' => null];
        switch ($format) {
            case 'shopee':
                $p['marketplace'] = 'shopee';
                $id = $r['item id'] ?? '';
                if (!preg_match('/^\d{3,20}$/D', $id)) {
                    $e[] = 'Item Id inválido';
                }
                $p['codigo'] = $id;
                $p['external_id'] = $id;
                $p['title'] = $r['item name'] ?? '';
                $p['store_name'] = $r['nome da loja'] ?? ($r['shop name'] ?? '');
                $p['original_url'] = $r['product link'] ?? '';
                $p['affiliate_url'] = $r['offer link'] ?? '';
                $p['price_promo_cents'] = $money('Preço', $r['price'] ?? '');
                $p['commission_rate'] = $r['commission rate'] ?? '';
                $p['commission_value'] = $r['commission'] ?? '';
                $p['price_source'] = 'csv_shopee';
                $p['price_seen_at'] = Time::now()->format('Y-m-d');
                if ($p['original_url'] !== '' && !Url::shopeeMatchesItem($p['original_url'], $id)) {
                    $e[] = 'Product Link não corresponde ao Item Id';
                }
                break;
            case 'gmax_ml':
                $p['marketplace'] = 'mercadolivre';
                $p['codigo'] = $r['codigo_gmax'] ?? '';
                if (!preg_match('/^ML-[A-Za-z0-9_-]{3,40}$/D', $p['codigo'])) {
                    $e[] = 'codigo_gmax inválido (use ML-001, ML-002…)';
                }
                $p['category'] = $r['categoria'] ?? '';
                $p['title'] = $r['produto'] ?? '';
                $p['original_url'] = $r['url_original'] ?? '';
                $p['affiliate_url'] = $r['url_afiliado'] ?? '';
                $p['price_original_cents'] = $money('Preço original', $r['preco_original'] ?? '');
                $p['price_promo_cents'] = $money('Preço promocional', $r['preco_promocional'] ?? '');
                [$p['payment_condition'], $p['payment_note']] = Catalog::normalizePayment($r['condicao_preco'] ?? '');
                $p['price_seen_at'] = self::date($r['verificado_em'] ?? '', $e);
                $p['notes'] = $r['observacoes'] ?? '';
                $p['price_source'] = 'csv_ml';
                break;
            case 'gmax_unificado':
                $mp = strtolower(str_replace([' ', '_', '-'], '', $r['marketplace'] ?? ''));
                $p['marketplace'] = match ($mp) {
                    'shopee' => 'shopee',
                    'mercadolivre', 'ml', 'meli' => 'mercadolivre',
                    default => '',
                };
                if ($p['marketplace'] === '') {
                    $e[] = 'marketplace deve ser shopee ou mercadolivre';
                    $p['marketplace'] = 'mercadolivre';
                }
                $p['codigo'] = $r['codigo'] ?? '';
                if ($p['codigo'] !== '' && !preg_match('/^[A-Za-z0-9_-]{2,40}$/D', $p['codigo'])) {
                    $e[] = 'codigo inválido (letras, números, - e _)';
                }
                $p['category'] = $r['categoria'] ?? '';
                $p['title'] = $r['produto'] ?? '';
                $p['store_name'] = $r['loja'] ?? '';
                $p['original_url'] = $r['url_original'] ?? '';
                $p['affiliate_url'] = $r['url_afiliado'] ?? '';
                $p['price_original_cents'] = $money('Preço original', $r['preco_original'] ?? '');
                $p['price_promo_cents'] = $money('Preço promocional', $r['preco_promocional'] ?? '');
                [$p['payment_condition'], $note] = Catalog::normalizePayment($r['condicao_pagamento'] ?? '');
                $p['payment_note'] = ($r['observacao_pagamento'] ?? '') !== '' ? $r['observacao_pagamento'] : $note;
                $p['price_seen_at'] = self::date($r['data_preco'] ?? '', $e);
                self::social($p, $r['vendas_publicas'] ?? '', $r['avaliacao'] ?? '', $r['num_avaliacoes'] ?? '', $e);
                $p['seller_reputation'] = $r['reputacao_vendedor'] ?? '';
                $av = Spreadsheet::normalizeHeader($r['disponibilidade'] ?? '');
                $p['availability'] = match ($av) {
                    '' => null,
                    'disponivel', 'sim', 'em estoque' => 'disponivel',
                    'estoque baixo', 'estoque_baixo', 'ultimas unidades' => 'estoque_baixo',
                    'indisponivel', 'nao', 'esgotado' => 'indisponivel',
                    default => 'desconhecida',
                };
                $vp = Spreadsheet::normalizeHeader($r['variacao_pendente'] ?? '');
                $p['variation_pending'] = in_array($vp, ['sim', '1', 's', 'verdadeiro', 'true'], true) ? 1 : null;
                $p['notes'] = $r['observacoes'] ?? '';
                $p['price_source'] = 'csv_gmax';
                break;
            case 'ml_pesquisa':
                $p['marketplace'] = 'mercadolivre';
                $p['category'] = $r['categoria'] ?? '';
                $p['title'] = $r['produto'] ?? '';
                $p['original_url'] = $r['url produto original'] ?? '';
                $p['affiliate_url'] = $r['link afiliado (preencher)'] ?? '';
                $p['price_original_cents'] = $money('Preço referência', $r['preco referencia (r$)'] ?? '');
                $p['price_promo_cents'] = $money('Preço oferta', $r['preco oferta (r$)'] ?? '');
                [$p['payment_condition'], $p['payment_note']] = Catalog::normalizePayment($r['condicao'] ?? '');
                self::social($p, $r['vendas indicadas'] ?? '', $r['nota'] ?? '', $r['avaliacoes'] ?? '', $e);
                $p['price_seen_at'] = Time::parseDate($r['coleta'] ?? '');
                $p['notes'] = trim(($r['observacoes'] ?? '') . (($r['coleta'] ?? '') !== '' ? ' · Coleta: ' . $r['coleta'] : ''), ' ·');
                $p['price_source'] = 'pesquisa_indexada';
                $declared = $r['id anuncio'] ?? '';
                if ($declared !== '' && preg_match('/^MLB-?(\d{5,15})$/D', $declared, $m)) {
                    $p['external_id'] = 'item:MLB' . $m[1];
                } elseif ($declared !== '') {
                    $e[] = 'ID anúncio inválido';
                }
                $note = mb_strtolower($r['observacoes'] ?? '', 'UTF-8');
                if (preg_match('/varia|voltagem|cor |tamanho|capacidade/u', $note)) {
                    $p['variation_pending'] = 1;
                    $w[] = 'Marcado como variação pendente: ' . ($r['observacoes'] ?? '');
                }
                break;
            case 'ml_conferencia':
                $p['marketplace'] = 'mercadolivre';
                $p['category'] = $r['categoria'] ?? '';
                $p['title'] = $r['produto'] ?? '';
                $p['original_url'] = $r['url de origem'] ?? '';
                $p['affiliate_url'] = $r['link de afiliado informado'] ?? '';
                $p['price_original_cents'] = $money('De', $r['de (r$)'] ?? '');
                $p['price_promo_cents'] = $money('Por', $r['por (r$)'] ?? '');
                [$p['payment_condition'], $p['payment_note']] = Catalog::normalizePayment($r['condicao'] ?? '');
                self::social($p, $r['vendas publicas'] ?? '', $r['avaliacao'] ?? '', '', $e);
                $p['notes'] = trim(($r['situacao da conciliacao'] ?? '') . ' · ' . ($r['observacao de seguranca'] ?? ''), ' ·');
                $p['price_source'] = 'pesquisa_historica';
                $p['status'] = 'rascunho';
                $w[] = 'Planilha de conferência: correspondência produto ↔ link não verificada; importado como rascunho';
                break;
            default:
                throw new RuntimeException('Formato desconhecido');
        }

        $mp = $p['marketplace'];
        $p['title'] = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$p['title']));
        if ($p['title'] === '' || mb_strlen($p['title']) > 300) {
            $e[] = 'Nome do produto vazio ou com mais de 300 caracteres';
        }
        foreach (['category' => 80, 'store_name' => 120, 'notes' => 1000, 'payment_note' => 200, 'seller_reputation' => 60, 'commission_rate' => 20, 'commission_value' => 30] as $f => $max) {
            if (isset($p[$f]) && is_string($p[$f])) {
                $p[$f] = mb_substr(trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $p[$f])), 0, $max);
            }
        }
        if (($p['affiliate_url'] ?? '') !== '' && !Url::isAffiliate($mp, $p['affiliate_url'])) {
            $e[] = $mp === 'shopee'
                ? 'Link afiliado deve ser https://s.shopee.com.br/…'
                : 'Link afiliado deve ser o link curto oficial https://meli.la/… (links comuns não comissionam)';
        }
        if (($p['original_url'] ?? '') !== '') {
            if ($mp === 'mercadolivre' && Url::isListing($p['original_url'])) {
                $w[] = 'URL de origem é página de busca/categoria: não identifica um anúncio e foi descartada';
                $p['original_url'] = '';
            } elseif (!Url::isProduct($mp, $p['original_url'])) {
                $e[] = 'URL do produto inválida para ' . Url::MARKETPLACES[$mp];
            } elseif ($mp === 'shopee') {
                $p['external_id'] ??= Url::shopeeItemId($p['original_url']);
            } elseif ($mp === 'mercadolivre') {
                $fromUrl = Url::mlId($p['original_url']);
                if (!empty($p['external_id']) && $p['external_id'] !== $fromUrl) {
                    $e[] = 'ID do anúncio não corresponde à URL do produto';
                }
                $p['external_id'] = $p['external_id'] ?? $fromUrl;
            }
        }
        $orig = $p['price_original_cents'] ?? null;
        $promo = $p['price_promo_cents'] ?? null;
        if ($promo !== null && $promo <= 0) {
            $e[] = 'Preço promocional deve ser maior que zero';
        }
        if ($orig !== null && $promo === null) {
            $e[] = 'Preço original informado sem preço promocional';
        }
        if ($orig !== null && $promo !== null && $orig < $promo) {
            $e[] = 'Preço original menor que o promocional';
        }
        if ($orig !== null && $promo !== null && $orig === $promo) {
            $w[] = 'Preço original igual ao promocional: sem desconto exibido';
        }
        if (($p['affiliate_url'] ?? '') === '') {
            $w[] = 'Sem link afiliado: importado como rascunho até associar o link';
            $p['status'] = 'rascunho';
        }
        if ($promo === null) {
            $w[] = 'Sem preço promocional: não pode ser aprovado';
        }
        if (empty($p['codigo']) && empty($p['external_id']) && empty($p['affiliate_url'])) {
            $e[] = 'Linha sem identificador (código, ID do anúncio/URL individual ou link afiliado)';
        }
        foreach ($p as $k => $v) {
            if ($v === '') {
                $p[$k] = null;
            }
        }
        $p['status'] ??= 'aguardando_validacao';
        return [$p, array_values(array_unique($e)), $w];
    }

    private static function date(string $v, array &$e): ?string
    {
        if (trim($v) === '') {
            return null;
        }
        $d = Time::parseDate($v);
        if ($d === null) {
            $e[] = "Data inválida: \"$v\" (use AAAA-MM-DD)";
        }
        return $d;
    }

    private static function social(array &$p, string $sales, string $rating, string $count, array &$e): void
    {
        $p['sales_public'] = $sales;
        $p['sales_min'] = $sales === '' ? null : Catalog::parseSales($sales);
        if ($rating !== '') {
            $v = (float)str_replace(',', '.', $rating);
            if ($v < 0 || $v > 5 || !is_numeric(str_replace(',', '.', $rating))) {
                $e[] = "Avaliação inválida: \"$rating\"";
            } else {
                $p['rating'] = round($v, 2);
            }
        }
        if ($count !== '') {
            if (!preg_match('/^\d+$/D', str_replace('.', '', $count))) {
                $e[] = "Número de avaliações inválido: \"$count\"";
            } else {
                $p['rating_count'] = (int)str_replace('.', '', $count);
            }
        }
    }

    /** Aplica o lote (somente se ainda estiver em prévia). */
    public static function apply(PDO $pdo, int $batchId, string $actor): array
    {
        return Db::immediate($pdo, static function (PDO $pdo) use ($batchId, $actor) {
            $batch = Db::one($pdo, 'SELECT * FROM gmax3_import_batches WHERE id=?', [$batchId]) ?? throw new RuntimeException('Lote não encontrado');
            if ($batch['format'] === LinkImporter::FORMAT) {
                throw new RuntimeException('Lote de links: use a tela Links afiliados');
            }
            if ($batch['status'] !== 'previa') {
                throw new RuntimeException('Este lote já foi ' . $batch['status']);
            }
            $counts = ['inserir' => 0, 'atualizar' => 0, 'sem_alteracao' => 0, 'rejeitado' => 0];
            $items = Db::all($pdo, 'SELECT * FROM gmax3_import_items WHERE batch_id=? ORDER BY line', [$batchId]);
            foreach ($items as $item) {
                if ($item['action'] === 'rejeitado') {
                    $counts['rejeitado']++;
                    continue;
                }
                $payload = json_decode($item['payload_json'], true);
                // Recalcula: o catálogo pode ter mudado desde a prévia.
                [$action, $catalogId, $err] = self::plan($pdo, $payload);
                $before = null;
                if ($err) {
                    $action = 'rejeitado';
                    Db::run($pdo, 'UPDATE gmax3_import_items SET errors_json=? WHERE id=?', [json_encode([$err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $item['id']]);
                } elseif ($action === 'inserir') {
                    $catalogId = Catalog::insert($pdo, $payload, (string)$payload['status'], $actor);
                } elseif ($action === 'atualizar') {
                    $cur = Catalog::get($pdo, (int)$catalogId);
                    $before = $cur;
                    $res = Catalog::update($pdo, $cur, $payload, $actor, true);
                    if (!$res['changed']) {
                        $action = 'sem_alteracao';
                        $before = null;
                    } elseif ($cur['validation_status'] === 'rascunho' && !empty(Catalog::get($pdo, (int)$catalogId)['affiliate_url']) && $payload['status'] !== 'rascunho') {
                        Db::run($pdo, "UPDATE gmax3_catalog SET validation_status='aguardando_validacao' WHERE id=?", [$catalogId]);
                    }
                }
                $after = $catalogId ? Catalog::get($pdo, (int)$catalogId) : null;
                Db::run($pdo, 'UPDATE gmax3_import_items SET action=?, catalog_id=?, before_json=?, after_hash=? WHERE id=?', [
                    $action, $catalogId, $before ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    $after && in_array($action, ['inserir', 'atualizar'], true) ? Catalog::snapshotHash($after) : null, $item['id'],
                ]);
                $counts[$action]++;
            }
            foreach (Db::all($pdo, "SELECT DISTINCT c.category FROM gmax3_import_items i JOIN gmax3_catalog c ON c.id=i.catalog_id WHERE i.batch_id=? AND c.category IS NOT NULL", [$batchId]) as $c) {
                Db::run($pdo, 'INSERT OR IGNORE INTO gmax3_categories(name) VALUES(?)', [$c['category']]);
            }
            Db::run($pdo, "UPDATE gmax3_import_batches SET status='aplicado', applied_at=?, inserted=?, updated=?, unchanged=?, rejected=? WHERE id=?", [
                Time::nowSql(), $counts['inserir'], $counts['atualizar'], $counts['sem_alteracao'], $counts['rejeitado'], $batchId,
            ]);
            Audit::log($pdo, $actor, 'importacao_aplicada', 'import_batch', $batchId, $counts);
            return $counts;
        });
    }

    public static function discard(PDO $pdo, int $batchId, string $actor): void
    {
        $n = Db::run($pdo, "UPDATE gmax3_import_batches SET status='descartado' WHERE id=? AND status='previa' AND format<>?", [$batchId, LinkImporter::FORMAT]);
        if ($n !== 1) {
            throw new RuntimeException('Somente prévias podem ser descartadas');
        }
        Audit::log($pdo, $actor, 'importacao_descartada', 'import_batch', $batchId);
    }

    /**
     * Desfaz um lote aplicado: remove os produtos criados e restaura os atualizados,
     * desde que não tenham sido alterados nem enviados para a fila depois da importação.
     */
    public static function revert(PDO $pdo, int $batchId, string $actor): array
    {
        return Db::immediate($pdo, static function (PDO $pdo) use ($batchId, $actor) {
            $batch = Db::one($pdo, 'SELECT * FROM gmax3_import_batches WHERE id=?', [$batchId]) ?? throw new RuntimeException('Lote não encontrado');
            if ($batch['format'] === LinkImporter::FORMAT) {
                throw new RuntimeException('Lote de links: use a tela Links afiliados');
            }
            if ($batch['status'] !== 'aplicado') {
                throw new RuntimeException('Somente lotes aplicados podem ser desfeitos');
            }
            $res = ['removidos' => 0, 'restaurados' => 0, 'ignorados' => []];
            foreach (Db::all($pdo, "SELECT * FROM gmax3_import_items WHERE batch_id=? AND action IN ('inserir','atualizar') ORDER BY line DESC", [$batchId]) as $item) {
                $cur = Catalog::get($pdo, (int)$item['catalog_id']);
                if (!$cur) {
                    continue;
                }
                $inQueue = (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_pub_queue WHERE catalog_id=?', [$cur['id']]);
                if ($inQueue > 0 || Catalog::snapshotHash($cur) !== $item['after_hash']) {
                    $res['ignorados'][] = "Linha {$item['line']} (#{$cur['id']}): alterado ou enviado à fila após a importação";
                    continue;
                }
                if ($item['action'] === 'inserir') {
                    Db::run($pdo, 'DELETE FROM gmax3_catalog WHERE id=?', [$cur['id']]);
                    $res['removidos']++;
                } else {
                    $before = json_decode((string)$item['before_json'], true);
                    $cols = array_diff(array_keys($before), ['id']);
                    $set = implode(',', array_map(static fn($c) => "$c = ?", $cols));
                    Db::run($pdo, "UPDATE gmax3_catalog SET $set WHERE id = ?", [...array_map(static fn($c) => $before[$c], $cols), $cur['id']]);
                    $res['restaurados']++;
                }
            }
            Db::run($pdo, "UPDATE gmax3_import_batches SET status='revertido', reverted_at=?, note=? WHERE id=?", [
                Time::nowSql(), $res['ignorados'] ? implode("\n", $res['ignorados']) : null, $batchId,
            ]);
            Audit::log($pdo, $actor, 'importacao_desfeita', 'import_batch', $batchId, $res);
            return $res;
        });
    }

    public static function template(): string
    {
        $rows = [
            self::UNIFIED_HEADER,
            ['mercadolivre', 'ML-002', 'Casa', 'Nome do produto exatamente como no anúncio', '', 'https://produto.mercadolivre.com.br/MLB-1234567890-exemplo-_JM', 'https://meli.la/XXXXXXX', '199,90', '119,90', 'pix', '', '2026-10-29', '+1 mil', '4,8', '320', 'MercadoLíder Platinum', 'disponivel', 'nao', 'Exemplo — substitua por dados reais conferidos'],
            ['shopee', '', 'Cozinha', 'Nome do produto', 'Nome da loja', 'https://shopee.com.br/produto-i.123456.7890123456', 'https://s.shopee.com.br/XXXXXXX', '', '49,90', 'normal', '', '2026-10-29', '', '', '', '', '', 'nao', ''],
        ];
        $f = fopen('php://temp', 'w+b');
        fwrite($f, "\xEF\xBB\xBF");
        foreach ($rows as $r) {
            fputcsv($f, $r, ';', '"', '');
        }
        rewind($f);
        return (string)stream_get_contents($f);
    }
}

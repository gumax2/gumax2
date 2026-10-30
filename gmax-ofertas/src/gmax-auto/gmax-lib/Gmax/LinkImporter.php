<?php
declare(strict_types=1);

namespace Gmax;

use PDO;
use RuntimeException;

/**
 * Associação de links afiliados em lote a produtos JÁ cadastrados.
 * - Casa por marketplace + ID do anúncio (nunca por posição da linha ou só pelo nome).
 * - Altera somente affiliate_url/affiliate_norm (+ updated_at); preços, condição e
 *   disponibilidade ficam intactos; nunca cria produto; nunca aprova.
 * - Só Mercado Livre: produtos Shopee não são alterados por este importador.
 * - Prévia -> (verificação opcional do destino) -> aplicação com backup -> desfazer.
 * Usa as tabelas de lotes existentes (formato "vinculo_links"): não exige migração.
 */
final class LinkImporter
{
    public const FORMAT = 'vinculo_links';
    public const LABELS = [
        'novo_link' => 'Link novo',
        'alteracao' => 'Altera link existente',
        'sem_alteracao' => 'Já associado (igual)',
        'rejeitado' => 'Erro / bloqueado',
    ];

    /** Cabeçalhos aceitos (normalizados) => campo. */
    private const COLUMNS = [
        'id_anuncio' => 'id', 'id anuncio' => 'id', 'mlb' => 'id',
        'url_afiliado' => 'url', 'link afiliado (preencher)' => 'url', 'link_afiliado' => 'url', 'link afiliado' => 'url',
        'produto' => 'produto', 'ranking' => 'ranking', 'status_validacao' => 'status', 'status validacao' => 'status',
        'marketplace' => 'marketplace',
    ];

    private const STOPWORDS = ['com', 'para', 'sem', 'por', 'kit', 'cor', 'the', 'and', 'tipo', 'novo', 'nova', 'original'];

    public static function preview(PDO $pdo, string $tmpPath, string $originalName, string $actor): int
    {
        $rows = Spreadsheet::read($tmpPath, $originalName);
        if (count($rows) < 2) {
            throw new RuntimeException('Arquivo sem linhas de links');
        }
        $header = array_map([Spreadsheet::class, 'normalizeHeader'], array_shift($rows));
        $map = [];
        foreach ($header as $i => $h) {
            if (isset(self::COLUMNS[$h]) && !isset($map[self::COLUMNS[$h]])) {
                $map[self::COLUMNS[$h]] = $i;
            }
        }
        if (!isset($map['id'], $map['url'])) {
            throw new RuntimeException('Cabeçalho não reconhecido: são obrigatórias as colunas id_anuncio e url_afiliado');
        }
        $sha = hash_file('sha256', $tmpPath) ?: '';
        $name = mb_substr((string)preg_replace('/[^\pL\pN ._()-]+/u', '_', basename($originalName)), 0, 120);

        return (int)Db::immediate($pdo, static function (PDO $pdo) use ($rows, $map, $sha, $name, $actor) {
            Db::run($pdo, 'INSERT INTO gmax3_import_batches(filename,format,sha256,status,actor,created_at) VALUES(?,?,?,?,?,?)', [$name, self::FORMAT, $sha, 'previa', $actor, Time::nowSql()]);
            $batchId = (int)$pdo->lastInsertId();
            $parsed = [];
            foreach ($rows as $i => $cells) {
                if (implode('', $cells) === '') {
                    continue;
                }
                $get = static fn(string $f) => isset($map[$f]) ? trim((string)($cells[$map[$f]] ?? '')) : '';
                $parsed[] = ['line' => $i + 2, 'id_anuncio' => $get('id'), 'url' => $get('url'), 'produto' => $get('produto'),
                    'ranking' => $get('ranking'), 'status_validacao' => $get('status'), 'marketplace' => $get('marketplace')];
            }
            // Duplicidades dentro do próprio arquivo (todas as ocorrências são bloqueadas).
            $count = static function (string $key) use ($parsed): array {
                $c = [];
                foreach ($parsed as $p) {
                    $k = $key === 'url' ? (Url::normalizeAffiliate($p['url']) ?? $p['url']) : self::itemKey($p['id_anuncio']);
                    if ($k !== null && $k !== '') {
                        $c[$k][] = $p['line'];
                    }
                }
                return $c;
            };
            $byUrl = $count('url');
            $byId = $count('id');
            $totals = ['novo_link' => 0, 'alteracao' => 0, 'sem_alteracao' => 0, 'rejeitado' => 0];
            foreach ($parsed as $p) {
                [$action, $catalogId, $payload, $errors, $warnings] = self::evaluate($pdo, $p, $byUrl, $byId);
                $totals[$action]++;
                Db::run($pdo, 'INSERT INTO gmax3_import_items(batch_id,line,action,catalog_id,payload_json,errors_json,warnings_json) VALUES(?,?,?,?,?,?,?)', [
                    $batchId, $p['line'], $action, $catalogId, self::json($payload), $errors ? self::json($errors) : null, $warnings ? self::json($warnings) : null,
                ]);
            }
            Db::run($pdo, 'UPDATE gmax3_import_batches SET total=?, inserted=?, updated=?, unchanged=?, rejected=? WHERE id=?', [
                count($parsed), $totals['novo_link'], $totals['alteracao'], $totals['sem_alteracao'], $totals['rejeitado'], $batchId,
            ]);
            Audit::log($pdo, $actor, 'links_lote_previa', 'import_batch', $batchId, ['arquivo' => $name] + $totals);
            return $batchId;
        });
    }

    /** "MLB-4047815501" / "MLB4047815501" / "4047815501" => "MLB4047815501" */
    public static function itemKey(string $id): ?string
    {
        $id = strtoupper(str_replace([' ', '_'], '', trim($id)));
        if (preg_match('/^(?:MLB-?)?(\d{6,15})$/D', $id, $m)) {
            return 'MLB' . $m[1];
        }
        return null;
    }

    /** @return array{0:string,1:?int,2:array,3:list<string>,4:list<string>} */
    private static function evaluate(PDO $pdo, array $p, array $byUrl, array $byId): array
    {
        $e = [];
        $w = [];
        $payload = ['id_anuncio' => $p['id_anuncio'], 'produto' => $p['produto'], 'url_afiliado' => $p['url'], 'ranking' => $p['ranking'],
            'status_validacao' => $p['status_validacao'], 'link_anterior' => null, 'produto_cadastrado' => null, 'verificacao' => null];
        $mp = strtolower(str_replace([' ', '_', '-'], '', $p['marketplace']));
        if ($mp !== '' && !in_array($mp, ['mercadolivre', 'ml', 'meli'], true)) {
            $e[] = $mp === 'shopee' ? 'Produtos Shopee não são alterados por este importador' : 'Marketplace desconhecido';
            return ['rejeitado', null, $payload, $e, $w];
        }
        $key = self::itemKey($p['id_anuncio']);
        if ($key === null) {
            $e[] = 'ID do anúncio inválido ou ausente ("' . mb_substr($p['id_anuncio'], 0, 40) . '"): use o formato MLB-1234567890';
        } elseif (count($byId[$key] ?? []) > 1) {
            $e[] = 'Mesmo anúncio repetido no arquivo (linhas ' . implode(', ', $byId[$key]) . '): nenhuma delas é aplicada';
        }
        $url = $p['url'];
        if ($url === '') {
            $e[] = 'Link afiliado vazio';
        } elseif (!Url::isAffiliate('mercadolivre', $url)) {
            $e[] = 'Link inválido: use o link curto oficial https://meli.la/… (links comuns ou de outro domínio não comissionam)';
        } elseif (count($byUrl[Url::normalizeAffiliate($url)] ?? []) > 1) {
            $e[] = 'Mesmo link afiliado em mais de uma linha (linhas ' . implode(', ', $byUrl[Url::normalizeAffiliate($url)]) . '): nenhuma delas é aplicada';
        }
        $st = Spreadsheet::normalizeHeader($p['status_validacao']);
        if ($st !== '' && preg_match('/invalid|erro|rejeit|nao corresponde|divergen|incorret|nao e produto/', $st)) {
            $e[] = 'Linha marcada como "' . $p['status_validacao'] . '" na planilha: não aplicada';
        } elseif ($st !== '' && preg_match('/pendente|conferir|verificar|duvid/', $st)) {
            $w[] = 'Planilha indica "' . $p['status_validacao'] . '": confira antes de aprovar';
        }
        if ($key === null) {
            return ['rejeitado', null, $payload, $e, $w];
        }
        $found = Db::all($pdo, "SELECT * FROM gmax3_catalog WHERE marketplace='mercadolivre' AND external_id IN (?, ?)", ['item:' . $key, 'catalogo:' . $key]);
        if (count($found) > 1) {
            $e[] = 'Mais de um produto cadastrado com o número ' . $key . ' (#' . implode(', #', array_column($found, 'id')) . '): associe manualmente';
            return ['rejeitado', null, $payload, $e, $w];
        }
        $row = $found[0] ?? null;
        if (!$row) {
            $e[] = 'Anúncio ' . $key . ' não está cadastrado: nenhum produto é criado por esta importação';
            return ['rejeitado', null, $payload, $e, $w];
        }
        $payload['produto_cadastrado'] = $row['title'];
        $payload['link_anterior'] = $row['affiliate_url'];
        if (in_array($row['validation_status'], ['rejeitado', 'arquivado'], true)) {
            $e[] = 'Produto #' . $row['id'] . ' está ' . $row['validation_status'];
        }
        if ($p['produto'] !== '') {
            $sim = self::nameOverlap($p['produto'], (string)$row['title']);
            $payload['similaridade'] = $sim;
            if ($sim !== null && $sim === 0.0) {
                $e[] = 'Nome na planilha ("' . mb_substr($p['produto'], 0, 60) . '") não tem nenhuma palavra em comum com o produto cadastrado: possível produto diferente';
            } elseif ($sim !== null && $sim < 0.34) {
                $w[] = 'Nome na planilha pouco parecido com o cadastrado: confira';
            }
        }
        if (!$e && $url !== '') {
            $other = Db::one($pdo, 'SELECT id, external_id FROM gmax3_catalog WHERE affiliate_norm=? AND id<>?', [Url::normalizeAffiliate($url), $row['id']]);
            if ($other) {
                $e[] = 'Este link já está associado a outro produto (#' . $other['id'] . ' ' . ($other['external_id'] ?? '') . ')';
            }
        }
        if ($e) {
            return ['rejeitado', (int)$row['id'], $payload, $e, $w];
        }
        if ((string)$row['affiliate_url'] === $url) {
            return ['sem_alteracao', (int)$row['id'], $payload, $e, $w];
        }
        if (!empty($row['affiliate_url'])) {
            $w[] = 'Substitui o link atual ' . $row['affiliate_url'] . ' (só é aplicado se você marcar "substituir links existentes")';
            return ['alteracao', (int)$row['id'], $payload, $e, $w];
        }
        return ['novo_link', (int)$row['id'], $payload, $e, $w];
    }

    /** Fração de palavras significativas em comum (sobre o menor conjunto); null se não houver como comparar. */
    public static function nameOverlap(string $a, string $b): ?float
    {
        $tok = static function (string $s): array {
            $s = Spreadsheet::normalizeHeader($s);
            preg_match_all('/[a-z0-9]{3,}/', $s, $m);
            return array_values(array_unique(array_diff($m[0], self::STOPWORDS)));
        };
        $x = $tok($a);
        $y = $tok($b);
        if (count($x) < 2 || count($y) < 2) {
            return null;
        }
        return round(count(array_intersect($x, $y)) / min(count($x), count($y)), 2);
    }

    /**
     * Consulta o destino de cada link da prévia (opcional; requer acesso de saída do servidor).
     * As consultas de rede acontecem ANTES de qualquer gravação; os resultados só são gravados
     * se o lote ainda estiver em prévia.
     */
    public static function verify(PDO $pdo, int $batchId, LinkVerifier $verifier, string $actor): array
    {
        if (self::batch($pdo, $batchId)['status'] !== 'previa') {
            throw new RuntimeException('Somente prévias podem ser verificadas');
        }
        $items = Db::all($pdo, "SELECT i.*, c.external_id AS ext FROM gmax3_import_items i LEFT JOIN gmax3_catalog c ON c.id=i.catalog_id
            WHERE i.batch_id=? AND i.action IN ('novo_link','alteracao','sem_alteracao') ORDER BY i.line", [$batchId]);
        @set_time_limit(60 + 20 * count($items));
        $checks = [];
        foreach ($items as $it) {
            $payload = json_decode($it['payload_json'], true);
            $checks[$it['id']] = $verifier->check((string)$payload['url_afiliado'], (string)$it['ext']) + ['verificado_em' => Time::nowSql()];
        }
        return Db::immediate($pdo, static function (PDO $pdo) use ($batchId, $items, $checks, $actor) {
            if (self::batch($pdo, $batchId)['status'] !== 'previa') {
                throw new RuntimeException('O lote foi aplicado ou descartado durante a verificação; resultados não gravados');
            }
            $res = [LinkVerifier::CONFERE => 0, LinkVerifier::DIVERGE => 0, LinkVerifier::INDETERMINADO => 0];
            foreach ($items as $it) {
                $v = $checks[$it['id']];
                $payload = json_decode($it['payload_json'], true);
                $payload['verificacao'] = $v;
                $res[$v['resultado']]++;
                $action = $it['action'];
                $errors = json_decode((string)$it['errors_json'], true) ?: [];
                if ($v['resultado'] === LinkVerifier::DIVERGE) {
                    $action = 'rejeitado';
                    $errors[] = 'Verificação do destino: ' . $v['detalhe'];
                }
                Db::run($pdo, 'UPDATE gmax3_import_items SET action=?, payload_json=?, errors_json=? WHERE id=?', [$action, self::json($payload), $errors ? self::json($errors) : null, $it['id']]);
            }
            self::recount($pdo, $batchId);
            Audit::log($pdo, $actor, 'links_lote_verificado', 'import_batch', $batchId, $res);
            return $res;
        });
    }

    /**
     * Aplica a prévia: backup verificado, depois atualiza SOMENTE o link afiliado.
     * Produtos continuam no mesmo estado (rascunho continua rascunho); um produto aprovado
     * que tiver o link trocado volta para "aguardando validação" (nunca aprovação automática).
     */
    public static function apply(PDO $pdo, int $batchId, bool $replace, string $actor, bool $acceptUnverified = false): array
    {
        $batch = self::batch($pdo, $batchId);
        if ($batch['status'] !== 'previa') {
            throw new RuntimeException('Este lote já foi ' . $batch['status']);
        }
        $backup = Backup::create($pdo, 'antes-links-lote-' . $batchId);
        $result = Db::immediate($pdo, static function (PDO $pdo) use ($batchId, $replace, $actor, $acceptUnverified) {
            // Rechecagem dentro da trava: outra aba pode ter aplicado ou descartado o lote.
            $now = self::batch($pdo, $batchId);
            if ($now['status'] !== 'previa') {
                throw new RuntimeException('Este lote já foi ' . $now['status']);
            }
            $done = ['aplicados' => 0, 'substituidos' => 0, 'ignorados' => []];
            foreach (Db::all($pdo, "SELECT * FROM gmax3_import_items WHERE batch_id=? AND action IN ('novo_link','alteracao') ORDER BY line", [$batchId]) as $it) {
                $payload = json_decode($it['payload_json'], true);
                $row = Catalog::get($pdo, (int)$it['catalog_id']);
                $url = (string)$payload['url_afiliado'];
                // Reconfere no momento da aplicação: o catálogo pode ter mudado desde a prévia.
                if (!$row || $row['marketplace'] !== 'mercadolivre') {
                    $done['ignorados'][] = "Linha {$it['line']}: produto não encontrado";
                    continue;
                }
                // O formato meli.la não prova o destino: sem verificação "confere", exige confirmação explícita.
                if (($payload['verificacao']['resultado'] ?? null) !== LinkVerifier::CONFERE && !$acceptUnverified) {
                    $done['ignorados'][] = "Linha {$it['line']}: destino do link não verificado (verifique ou confirme a conferência manual)";
                    continue;
                }
                if ($it['action'] === 'alteracao' && !$replace) {
                    $done['ignorados'][] = "Linha {$it['line']}: substituição não autorizada";
                    continue;
                }
                if ((string)$row['affiliate_url'] !== (string)($payload['link_anterior'] ?? '')) {
                    $done['ignorados'][] = "Linha {$it['line']}: o link do produto mudou depois da prévia";
                    continue;
                }
                if (Db::value($pdo, 'SELECT id FROM gmax3_catalog WHERE affiliate_norm=? AND id<>?', [Url::normalizeAffiliate($url), $row['id']]) !== null) {
                    $done['ignorados'][] = "Linha {$it['line']}: link já associado a outro produto";
                    continue;
                }
                $before = array_intersect_key($row, array_flip(['affiliate_url', 'affiliate_norm', 'validation_status', 'updated_at']));
                $status = $row['validation_status'] === 'aprovado' ? 'aguardando_validacao' : $row['validation_status'];
                $now = Time::nowSql();
                Db::run($pdo, 'UPDATE gmax3_catalog SET affiliate_url=?, affiliate_norm=?, validation_status=?, updated_at=? WHERE id=?', [
                    $url, Url::normalizeAffiliate($url), $status, $now, $row['id'],
                ]);
                Db::run($pdo, 'UPDATE gmax3_import_items SET before_json=?, after_hash=? WHERE id=?', [self::json($before), hash('sha256', $url . '|' . $now), $it['id']]);
                Audit::log($pdo, $actor, 'link_associado_lote', 'catalog', $row['id'], ['lote' => $batchId, 'de' => $row['affiliate_url'], 'para' => $url]);
                $it['action'] === 'alteracao' ? $done['substituidos']++ : $done['aplicados']++;
            }
            Db::run($pdo, "UPDATE gmax3_import_batches SET status='aplicado', applied_at=?, note=? WHERE id=?", [
                Time::nowSql(), $done['ignorados'] ? implode("\n", $done['ignorados']) : null, $batchId,
            ]);
            Audit::log($pdo, $actor, 'links_lote_aplicado', 'import_batch', $batchId, $done + ['conferencia_manual_confirmada' => $acceptUnverified]);
            return $done;
        });
        return $result + ['backup' => basename($backup['file'])];
    }

    /** Restaura os links anteriores, desde que o link não tenha sido alterado depois do lote. */
    public static function revert(PDO $pdo, int $batchId, string $actor): array
    {
        $batch = self::batch($pdo, $batchId);
        if ($batch['status'] !== 'aplicado') {
            throw new RuntimeException('Somente lotes aplicados podem ser desfeitos');
        }
        return Db::immediate($pdo, static function (PDO $pdo) use ($batchId, $actor) {
            if (self::batch($pdo, $batchId)['status'] !== 'aplicado') {
                throw new RuntimeException('Este lote já foi desfeito');
            }
            $res = ['restaurados' => 0, 'ignorados' => []];
            foreach (Db::all($pdo, 'SELECT * FROM gmax3_import_items WHERE batch_id=? AND after_hash IS NOT NULL ORDER BY line DESC', [$batchId]) as $it) {
                $row = Catalog::get($pdo, (int)$it['catalog_id']);
                $before = json_decode((string)$it['before_json'], true);
                if (!$row || hash('sha256', $row['affiliate_url'] . '|' . $row['updated_at']) !== $it['after_hash']) {
                    $res['ignorados'][] = "Linha {$it['line']}: produto alterado depois do lote (preservado)";
                    continue;
                }
                if (!empty($before['affiliate_norm']) && Db::value($pdo, 'SELECT id FROM gmax3_catalog WHERE affiliate_norm=? AND id<>?', [$before['affiliate_norm'], $row['id']]) !== null) {
                    $res['ignorados'][] = "Linha {$it['line']}: o link anterior agora pertence a outro produto";
                    continue;
                }
                Db::run($pdo, 'UPDATE gmax3_catalog SET affiliate_url=?, affiliate_norm=?, validation_status=?, updated_at=? WHERE id=?', [
                    $before['affiliate_url'], $before['affiliate_norm'], $before['validation_status'], Time::nowSql(), $row['id'],
                ]);
                Audit::log($pdo, $actor, 'link_restaurado_lote', 'catalog', $row['id'], ['lote' => $batchId, 'para' => $before['affiliate_url']]);
                $res['restaurados']++;
            }
            Db::run($pdo, "UPDATE gmax3_import_batches SET status='revertido', reverted_at=?, note=? WHERE id=?", [
                Time::nowSql(), $res['ignorados'] ? implode("\n", $res['ignorados']) : null, $batchId,
            ]);
            Audit::log($pdo, $actor, 'links_lote_desfeito', 'import_batch', $batchId, $res);
            return $res;
        });
    }

    public static function discard(PDO $pdo, int $batchId, string $actor): void
    {
        self::batch($pdo, $batchId);
        if (Db::run($pdo, "UPDATE gmax3_import_batches SET status='descartado' WHERE id=? AND status='previa'", [$batchId]) !== 1) {
            throw new RuntimeException('Somente prévias podem ser descartadas');
        }
        Audit::log($pdo, $actor, 'links_lote_descartado', 'import_batch', $batchId);
    }

    public static function batch(PDO $pdo, int $batchId): array
    {
        $b = Db::one($pdo, 'SELECT * FROM gmax3_import_batches WHERE id=?', [$batchId]);
        if (!$b || $b['format'] !== self::FORMAT) {
            throw new RuntimeException('Lote de links não encontrado');
        }
        return $b;
    }

    private static function recount(PDO $pdo, int $batchId): void
    {
        $c = $pdo->query('SELECT action, COUNT(*) FROM gmax3_import_items WHERE batch_id=' . $batchId . ' GROUP BY action')->fetchAll(PDO::FETCH_KEY_PAIR);
        Db::run($pdo, 'UPDATE gmax3_import_batches SET inserted=?, updated=?, unchanged=?, rejected=? WHERE id=?', [
            (int)($c['novo_link'] ?? 0), (int)($c['alteracao'] ?? 0), (int)($c['sem_alteracao'] ?? 0), (int)($c['rejeitado'] ?? 0), $batchId,
        ]);
    }

    private static function json(mixed $v): string
    {
        return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

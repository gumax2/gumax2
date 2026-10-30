<?php
declare(strict_types=1);

namespace GmaxRenov;

/**
 * Leitura (SOMENTE LEITURA) dos clientes e pacotes do painel, via mapeamento do config.php.
 * Este módulo nunca executa INSERT/UPDATE/DELETE nas tabelas do painel.
 *
 * Painel GMAX (padrão): clients + client_plans (um vencimento por pacote em client_plans.due_date)
 * + client_exclusions (cliente excluído = existe linha). clients.due_date é legado e NÃO é usado.
 *
 * Linha normalizada:
 *  pacote_id, cliente_id (texto), cliente_nome, telefone, pacote_nome, pontos, servico, ciclo,
 *  valor_centavos, vencimento (Y-m-d|null), pacote_status, pacote_ativo, pacote_excluido,
 *  cliente_status, cliente_ativo, cliente_excluido, cliente_existe
 */
final class PanelData
{
    private Db $db;
    private array $c;
    private array $p;

    public const CICLOS_PADRAO = ['1' => 'mensal', '3' => 'trimestral', '6' => 'semestral', '12' => 'anual'];

    public function __construct(Db $db, array $schema)
    {
        $this->db = $db;
        $this->c = ($schema['clientes'] ?? []) + [
            'tabela' => 'clients', 'id' => 'id', 'nome' => 'name', 'telefone' => 'phone',
            'status' => 'status', 'status_ativos' => ['active'],
            'excluido' => null, 'excluido_modo' => 'nao_nulo',
            // Exclusão por tabela separada (cliente excluído = existe linha nela).
            'exclusao_tabela' => 'client_exclusions', 'exclusao_cliente_id' => 'client_id',
        ];
        $this->p = ($schema['pacotes'] ?? []) + [
            'tabela' => 'client_plans', 'id' => 'id', 'cliente_id' => 'client_id', 'nome' => 'label',
            'pontos' => 'points', 'servico' => 'service', 'ciclo' => 'months',
            'preco' => 'price_cents', 'preco_formato' => 'centavos',
            'vencimento' => 'due_date', 'vencimento_formato' => 'texto_ymd',
            'status' => 'status', 'status_ativos' => ['active'], 'excluido' => null, 'excluido_modo' => 'nao_nulo',
            'ciclos' => self::CICLOS_PADRAO,
        ];
    }

    private function col(array $map, string $key, string $alias, string $tableAlias): string
    {
        $c = $map[$key] ?? null;
        if ($c === null || $c === '') {
            return "NULL AS $alias";
        }
        return "$tableAlias." . Db::ident($c) . " AS $alias";
    }

    /** Expressão SQL do vencimento, comparável com 'Y-m-d'. */
    private function dueExpr(): string
    {
        $col = 'p.' . Db::ident($this->p['vencimento']);
        switch ($this->p['vencimento_formato']) {
            case 'texto_ymd': // VARCHAR 'YYYY-MM-DD' (painel GMAX): compara direto, usa o índice
                return $col;
            case 'd/m/Y':
                return "DATE_FORMAT(STR_TO_DATE($col, '%d/%m/%Y'), '%Y-%m-%d')";
            case 'timestamp':
                return "DATE_FORMAT(FROM_UNIXTIME($col), '%Y-%m-%d')";
            default: // DATE ou DATETIME
                return "DATE_FORMAT($col, '%Y-%m-%d')";
        }
    }

    private function exclusionJoin(): string
    {
        if (empty($this->c['exclusao_tabela'])) {
            return '';
        }
        return ' LEFT JOIN ' . Db::ident($this->c['exclusao_tabela']) . ' x ON x.' . Db::ident($this->c['exclusao_cliente_id'])
            . ' = c.' . Db::ident($this->c['id']);
    }

    private function exclusionSelect(): string
    {
        return empty($this->c['exclusao_tabela']) ? 'NULL AS x_excluido' : 'x.' . Db::ident($this->c['exclusao_cliente_id']) . ' AS x_excluido';
    }

    private function baseSelect(): string
    {
        $p = $this->p;
        $c = $this->c;
        return 'SELECT '
            . 'p.' . Db::ident($p['id']) . ' AS pacote_id, '
            . 'p.' . Db::ident($p['cliente_id']) . ' AS cliente_id, '
            . $this->col($p, 'nome', 'pacote_nome', 'p') . ', '
            . $this->col($p, 'pontos', 'pontos', 'p') . ', '
            . $this->col($p, 'servico', 'servico', 'p') . ', '
            . $this->col($p, 'ciclo', 'ciclo', 'p') . ', '
            . $this->col($p, 'preco', 'preco', 'p') . ', '
            . $this->dueExpr() . ' AS vencimento, '
            . $this->col($p, 'status', 'pacote_status', 'p') . ', '
            . $this->col($p, 'excluido', 'pacote_excluido', 'p') . ', '
            . 'c.' . Db::ident($c['id']) . ' AS c_id, '
            . $this->col($c, 'nome', 'cliente_nome', 'c') . ', '
            . $this->col($c, 'telefone', 'telefone', 'c') . ', '
            . $this->col($c, 'status', 'cliente_status', 'c') . ', '
            . $this->col($c, 'excluido', 'cliente_excluido', 'c') . ', '
            . $this->exclusionSelect()
            . ' FROM ' . Db::ident($p['tabela']) . ' p'
            . ' LEFT JOIN ' . Db::ident($c['tabela']) . ' c ON c.' . Db::ident($c['id']) . ' = p.' . Db::ident($p['cliente_id'])
            . $this->exclusionJoin();
    }

    /** Pacotes cujo vencimento está em uma das datas (Y-m-d). A elegibilidade é avaliada em PHP. */
    public function packagesDueOn(array $dates): array
    {
        if (!$dates) {
            return [];
        }
        $in = implode(',', array_fill(0, count($dates), '?'));
        $rows = $this->db->all($this->baseSelect() . ' WHERE ' . $this->dueExpr() . " IN ($in) ORDER BY pacote_id", array_values($dates));
        return array_map([$this, 'normalize'], $rows);
    }

    public function packagesDueBetween(string $from, string $to): array
    {
        $rows = $this->db->all(
            $this->baseSelect() . ' WHERE ' . $this->dueExpr() . ' BETWEEN ? AND ? ORDER BY vencimento, cliente_nome, pacote_id',
            [$from, $to]
        );
        // Datas de texto inválidas (ex.: 2026-02-30) ficam fora; são contadas por invalidDueDates().
        return array_values(array_filter(array_map([$this, 'normalize'], $rows), static fn($r) => $r['vencimento'] !== null));
    }

    /** Quantidade de pacotes com vencimento inválido (não recebem aviso). Não devolve dados pessoais. */
    public function invalidDueDates(): int
    {
        $n = 0;
        $st = $this->db->pdo->query('SELECT ' . $this->dueExpr() . ' AS v FROM ' . Db::ident($this->p['tabela']) . ' p');
        while (($v = $st->fetchColumn()) !== false) {
            $n += self::validYmd($v) === null ? 1 : 0;
        }
        return $n;
    }

    public function package(string $id): ?array
    {
        if ($id === '') {
            return null;
        }
        $r = $this->db->one($this->baseSelect() . ' WHERE p.' . Db::ident($this->p['id']) . ' = ?', [$id]);
        return $r ? $this->normalize($r) : null;
    }

    private function clientSelect(): string
    {
        $c = $this->c;
        return 'SELECT c.' . Db::ident($c['id']) . ' AS id, '
            . $this->col($c, 'nome', 'nome', 'c') . ', ' . $this->col($c, 'telefone', 'telefone', 'c') . ', '
            . $this->col($c, 'status', 'status', 'c') . ', ' . $this->col($c, 'excluido', 'excluido', 'c') . ', '
            . $this->exclusionSelect()
            . ' FROM ' . Db::ident($c['tabela']) . ' c' . $this->exclusionJoin();
    }

    private function finishClient(array $r): array
    {
        $r['id'] = (string)$r['id'];
        $r['ativo'] = $this->statusOk($r['status'], $this->c);
        $r['excluido_flag'] = $this->deletedFlag($r['excluido'], $this->c) || $r['x_excluido'] !== null;
        unset($r['x_excluido']);
        return $r;
    }

    public function client(string $id): ?array
    {
        if ($id === '') {
            return null;
        }
        $r = $this->db->one($this->clientSelect() . ' WHERE c.' . Db::ident($this->c['id']) . ' = ?', [$id]);
        return $r ? $this->finishClient($r) : null;
    }

    /** Lista para a aba Autorizações. Clientes excluídos ficam de fora (continuam excluídos no painel). */
    public function clients(string $q = '', int $limit = 50, int $offset = 0): array
    {
        $c = $this->c;
        $where = [];
        $params = [];
        if (!empty($c['exclusao_tabela'])) {
            $where[] = 'x.' . Db::ident($c['exclusao_cliente_id']) . ' IS NULL';
        }
        if ($q !== '') {
            $cond = 'c.' . Db::ident($c['nome']) . ' LIKE ?';
            $params[] = '%' . $q . '%';
            $digits = preg_replace('/\D/', '', $q);
            if (!empty($c['telefone']) && strlen($digits) >= 3) {
                $cond .= ' OR c.' . Db::ident($c['telefone']) . ' LIKE ?';
                $params[] = '%' . $digits . '%';
            }
            $where[] = "($cond)";
        }
        $sql = $this->clientSelect() . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY c.' . Db::ident($c['nome']) . ', c.' . Db::ident($c['id'])
            . ' LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset);
        return array_map([$this, 'finishClient'], $this->db->all($sql, $params));
    }

    private function statusOk($value, array $map): bool
    {
        if (empty($map['status'])) {
            return true; // sem coluna de status mapeada
        }
        $allowed = array_map(static fn($v) => mb_strtolower(trim((string)$v)), (array)$map['status_ativos']);
        return in_array(mb_strtolower(trim((string)$value)), $allowed, true);
    }

    private function deletedFlag($value, array $map): bool
    {
        if (empty($map['excluido'])) {
            return false;
        }
        if (($map['excluido_modo'] ?? 'nao_nulo') === 'verdadeiro') {
            return !in_array(strtolower(trim((string)$value)), ['', '0', 'false', 'nao', 'não', 'n'], true);
        }
        return $value !== null && $value !== '' && $value !== '0000-00-00 00:00:00' && $value !== '0000-00-00';
    }

    private static function validYmd($v): ?string
    {
        $v = substr(trim((string)$v), 0, 10);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return ($d && $d->format('Y-m-d') === $v) ? $v : null;
    }

    private function normalize(array $r): array
    {
        $ciclo = $r['ciclo'];
        if ($ciclo !== null && isset($this->p['ciclos'][(string)$ciclo])) {
            $ciclo = $this->p['ciclos'][(string)$ciclo];
        }
        // Preço cadastrado é o TOTAL do pacote/ciclo: nunca multiplicar por pontos.
        $cents = ($this->p['preco_formato'] ?? 'reais') === 'centavos'
            ? (is_numeric($r['preco']) ? (int)$r['preco'] : 0)
            : Format::toCents($r['preco']);
        return [
            'pacote_id' => (string)$r['pacote_id'],
            'cliente_id' => (string)$r['cliente_id'],
            'cliente_nome' => trim((string)$r['cliente_nome']),
            'telefone' => (string)$r['telefone'],
            'pacote_nome' => trim((string)($r['pacote_nome'] ?? '')) ?: 'Pacote',
            'pontos' => $r['pontos'] === null ? null : (int)$r['pontos'],
            'servico' => $r['servico'] === null ? null : (string)$r['servico'],
            'ciclo' => $ciclo === null ? null : (string)$ciclo,
            'valor_centavos' => $cents,
            'vencimento' => self::validYmd($r['vencimento']),
            'pacote_status' => $r['pacote_status'],
            'pacote_ativo' => $this->statusOk($r['pacote_status'], $this->p),
            'pacote_excluido' => $this->deletedFlag($r['pacote_excluido'], $this->p),
            'cliente_existe' => $r['c_id'] !== null,
            'cliente_status' => $r['cliente_status'],
            'cliente_ativo' => $this->statusOk($r['cliente_status'], $this->c),
            'cliente_excluido' => $this->deletedFlag($r['cliente_excluido'], $this->c) || $r['x_excluido'] !== null,
        ];
    }

    /** Diagnóstico do mapeamento: confere se tabelas e colunas existem (não lê dados). */
    public function diagnose(): array
    {
        $out = [];
        $maps = ['clientes' => $this->c, 'pacotes' => $this->p];
        if (!empty($this->c['exclusao_tabela'])) {
            $maps['exclusoes'] = ['tabela' => $this->c['exclusao_tabela'], 'cliente_id' => $this->c['exclusao_cliente_id']];
        }
        $skip = ['tabela', 'status_ativos', 'excluido_modo', 'vencimento_formato', 'preco_formato', 'ciclos', 'exclusao_tabela', 'exclusao_cliente_id'];
        foreach ($maps as $name => $map) {
            $table = $map['tabela'];
            $exists = $this->db->tableExists($table);
            $cols = $exists ? $this->db->columns($table) : [];
            $missing = [];
            foreach ($map as $k => $v) {
                if (in_array($k, $skip, true) || $v === null || $v === '') {
                    continue;
                }
                if (!isset($cols[$v])) {
                    $missing[] = "$k → $v";
                }
            }
            $out[$name] = ['tabela' => $table, 'existe' => $exists, 'colunas' => array_keys($cols), 'faltando' => $missing];
        }
        return $out;
    }
}

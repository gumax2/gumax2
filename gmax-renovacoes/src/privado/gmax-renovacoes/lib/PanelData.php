<?php
declare(strict_types=1);

namespace GmaxRenov;

/**
 * Leitura (SOMENTE LEITURA) das tabelas de clientes e pacotes do painel, via mapeamento do config.php.
 * Este módulo nunca executa INSERT/UPDATE/DELETE nas tabelas do painel.
 *
 * Linha normalizada:
 *  pacote_id, cliente_id, cliente_nome, telefone, pacote_nome, pontos, servico, ciclo,
 *  valor_centavos, vencimento (Y-m-d|null), pacote_status, pacote_ativo, pacote_excluido,
 *  cliente_status, cliente_ativo, cliente_excluido, cliente_existe
 */
final class PanelData
{
    private Db $db;
    private array $c;
    private array $p;

    public function __construct(Db $db, array $schema)
    {
        $this->db = $db;
        $this->c = ($schema['clientes'] ?? []) + [
            'tabela' => 'clientes', 'id' => 'id', 'nome' => 'nome', 'telefone' => 'telefone',
            'status' => null, 'status_ativos' => [], 'excluido' => null, 'excluido_modo' => 'nao_nulo',
        ];
        $this->p = ($schema['pacotes'] ?? []) + [
            'tabela' => 'pacotes', 'id' => 'id', 'cliente_id' => 'cliente_id', 'nome' => 'nome',
            'pontos' => null, 'servico' => null, 'ciclo' => null, 'preco' => 'preco',
            'vencimento' => 'vencimento', 'vencimento_formato' => 'Y-m-d',
            'status' => null, 'status_ativos' => [], 'excluido' => null, 'excluido_modo' => 'nao_nulo',
            'ciclos' => [],
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

    /** Expressão SQL que converte a coluna de vencimento em DATE. */
    private function dueExpr(): string
    {
        $col = 'p.' . Db::ident($this->p['vencimento']);
        switch ($this->p['vencimento_formato']) {
            case 'd/m/Y':
                return "STR_TO_DATE($col, '%d/%m/%Y')";
            case 'timestamp':
                return "DATE(FROM_UNIXTIME($col))";
            default: // DATE, DATETIME ou texto Y-m-d
                return "DATE($col)";
        }
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
            . $this->col($c, 'excluido', 'cliente_excluido', 'c')
            . ' FROM ' . Db::ident($p['tabela']) . ' p'
            . ' LEFT JOIN ' . Db::ident($c['tabela']) . ' c ON c.' . Db::ident($c['id']) . ' = p.' . Db::ident($p['cliente_id']);
    }

    /** Pacotes cujo vencimento está em uma das datas (Y-m-d). Filtragem de elegibilidade é feita em PHP. */
    public function packagesDueOn(array $dates): array
    {
        if (!$dates) {
            return [];
        }
        $in = implode(',', array_fill(0, count($dates), '?'));
        $rows = $this->db->all($this->baseSelect() . ' WHERE ' . $this->dueExpr() . " IN ($in)", array_values($dates));
        return array_map([$this, 'normalize'], $rows);
    }

    public function packagesDueBetween(string $from, string $to): array
    {
        $rows = $this->db->all(
            $this->baseSelect() . ' WHERE ' . $this->dueExpr() . ' BETWEEN ? AND ? ORDER BY vencimento, cliente_nome',
            [$from, $to]
        );
        return array_map([$this, 'normalize'], $rows);
    }

    public function package(int $id): ?array
    {
        $r = $this->db->one($this->baseSelect() . ' WHERE p.' . Db::ident($this->p['id']) . ' = ?', [$id]);
        return $r ? $this->normalize($r) : null;
    }

    public function client(int $id): ?array
    {
        $c = $this->c;
        $r = $this->db->one(
            'SELECT ' . Db::ident($c['id']) . ' AS id, '
            . $this->col($c, 'nome', 'nome', 'c') . ', ' . $this->col($c, 'telefone', 'telefone', 'c') . ', '
            . $this->col($c, 'status', 'status', 'c') . ', ' . $this->col($c, 'excluido', 'excluido', 'c')
            . ' FROM ' . Db::ident($c['tabela']) . ' c WHERE c.' . Db::ident($c['id']) . ' = ?',
            [$id]
        );
        if (!$r) {
            return null;
        }
        $r['ativo'] = $this->statusOk($r['status'], $c);
        $r['excluido_flag'] = $this->deletedFlag($r['excluido'], $c);
        return $r;
    }

    public function clients(string $q = '', int $limit = 50, int $offset = 0): array
    {
        $c = $this->c;
        $sql = 'SELECT c.' . Db::ident($c['id']) . ' AS id, '
            . $this->col($c, 'nome', 'nome', 'c') . ', ' . $this->col($c, 'telefone', 'telefone', 'c') . ', '
            . $this->col($c, 'status', 'status', 'c') . ', ' . $this->col($c, 'excluido', 'excluido', 'c')
            . ' FROM ' . Db::ident($c['tabela']) . ' c';
        $params = [];
        if ($q !== '') {
            $sql .= ' WHERE c.' . Db::ident($c['nome']) . ' LIKE ?';
            $params[] = '%' . $q . '%';
            $digits = preg_replace('/\D/', '', $q);
            if (!empty($c['telefone']) && strlen($digits) >= 3) {
                $sql .= ' OR c.' . Db::ident($c['telefone']) . ' LIKE ?';
                $params[] = '%' . $digits . '%';
            }
        }
        $sql .= ' ORDER BY c.' . Db::ident($c['nome']) . ' LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset);
        $rows = $this->db->all($sql, $params);
        foreach ($rows as &$r) {
            $r['ativo'] = $this->statusOk($r['status'], $c);
            $r['excluido_flag'] = $this->deletedFlag($r['excluido'], $c);
        }
        return $rows;
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

    private function normalize(array $r): array
    {
        $ciclo = $r['ciclo'];
        if ($ciclo !== null && isset($this->p['ciclos'][(string)$ciclo])) {
            $ciclo = $this->p['ciclos'][(string)$ciclo];
        }
        return [
            'pacote_id' => (int)$r['pacote_id'],
            'cliente_id' => (int)$r['cliente_id'],
            'cliente_nome' => trim((string)$r['cliente_nome']),
            'telefone' => (string)$r['telefone'],
            'pacote_nome' => trim((string)($r['pacote_nome'] ?? '')) ?: ('Pacote #' . $r['pacote_id']),
            'pontos' => $r['pontos'] === null ? null : (int)$r['pontos'],
            'servico' => $r['servico'] === null ? null : (string)$r['servico'],
            'ciclo' => $ciclo === null ? null : (string)$ciclo,
            // Preço cadastrado é o TOTAL do pacote/ciclo: nunca multiplicar por pontos.
            'valor_centavos' => Format::toCents($r['preco']),
            'vencimento' => $r['vencimento'] ? substr((string)$r['vencimento'], 0, 10) : null,
            'pacote_status' => $r['pacote_status'],
            'pacote_ativo' => $this->statusOk($r['pacote_status'], $this->p),
            'pacote_excluido' => $this->deletedFlag($r['pacote_excluido'], $this->p),
            'cliente_existe' => $r['c_id'] !== null,
            'cliente_status' => $r['cliente_status'],
            'cliente_ativo' => $this->statusOk($r['cliente_status'], $this->c),
            'cliente_excluido' => $this->deletedFlag($r['cliente_excluido'], $this->c),
        ];
    }

    /** Diagnóstico do mapeamento: confere se tabelas e colunas existem. */
    public function diagnose(): array
    {
        $out = [];
        foreach (['clientes' => $this->c, 'pacotes' => $this->p] as $name => $map) {
            $table = $map['tabela'];
            $exists = $this->db->tableExists($table);
            $cols = $exists ? $this->db->columns($table) : [];
            $missing = [];
            foreach ($map as $k => $v) {
                if (in_array($k, ['tabela', 'status_ativos', 'excluido_modo', 'vencimento_formato', 'ciclos'], true) || $v === null || $v === '') {
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

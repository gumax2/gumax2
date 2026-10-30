<?php
declare(strict_types=1);

namespace GmaxRenov;

/**
 * Retrato de integridade das tabelas do painel, SEM dados pessoais:
 * por tabela → quantidade de linhas, hash dos IDs e hash do conteúdo completo (ordenado pela chave primária);
 * financeiro → totais de receita, custo, despesa e lucro (centavos) e soma de preços/custos dos pacotes.
 * Usado antes e depois da instalação para provar que nada foi alterado, apagado ou duplicado.
 */
final class Integrity
{
    public const TABELAS_PADRAO = ['clients', 'client_plans', 'client_exclusions', 'entries', 'entry_plans', 'settings', 'admins', 'gmax_migrations'];

    private Db $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function snapshot(array $tables = self::TABELAS_PADRAO): array
    {
        $out = ['gerado_em' => date('c'), 'banco' => hash('sha256', (string)$this->db->value('SELECT DATABASE()')), 'tabelas' => [], 'financeiro' => null];
        foreach ($tables as $t) {
            if (!$this->db->tableExists($t)) {
                $out['tabelas'][$t] = ['existe' => false];
                continue;
            }
            $pk = array_column($this->db->all('SHOW KEYS FROM ' . Db::ident($t) . " WHERE Key_name = 'PRIMARY'"), 'Column_name');
            $cols = array_keys($this->db->columns($t));
            $order = $pk ?: $cols;
            $orderSql = implode(', ', array_map([Db::class, 'ident'], $order));
            $hAll = hash_init('sha256');
            $hIds = hash_init('sha256');
            $n = 0;
            $st = $this->db->pdo->query('SELECT * FROM ' . Db::ident($t) . ' ORDER BY ' . $orderSql);
            while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
                $n++;
                $row = array_map(static fn($v) => $v === null ? null : (string)$v, $row);
                hash_update($hAll, json_encode(array_values($row), JSON_UNESCAPED_UNICODE) . "\n");
                hash_update($hIds, json_encode(array_map(static fn($k) => $row[$k], $order)) . "\n");
            }
            // Estrutura (colunas, índices, motor), sem o contador AUTO_INCREMENT.
            $create = (string)($this->db->one('SHOW CREATE TABLE ' . Db::ident($t))['Create Table'] ?? '');
            $create = preg_replace('/ AUTO_INCREMENT=\d+/', '', $create);
            $out['tabelas'][$t] = ['existe' => true, 'linhas' => $n, 'hash_ids' => hash_final($hIds), 'hash_conteudo' => hash_final($hAll), 'hash_estrutura' => hash('sha256', $create)];
        }
        if ($this->db->tableExists('entries')) {
            $tot = ['revenue' => 0, 'cost' => 0, 'expense' => 0];
            foreach ($this->db->all('SELECT kind, SUM(amount_cents) s, COUNT(*) n FROM entries GROUP BY kind') as $r) {
                $tot[$r['kind']] = (int)$r['s'];
            }
            $out['financeiro'] = [
                'receita_centavos' => $tot['revenue'],
                'custo_centavos' => $tot['cost'],
                'despesa_centavos' => $tot['expense'],
                'lucro_centavos' => $tot['revenue'] - $tot['cost'] - $tot['expense'],
            ];
            if ($this->db->tableExists('client_plans')) {
                $p = $this->db->one('SELECT COUNT(*) n, COALESCE(SUM(price_cents),0) preco, COALESCE(SUM(cost_cents),0) custo, COUNT(DISTINCT client_id) clientes FROM client_plans');
                $out['financeiro']['pacotes'] = (int)$p['n'];
                $out['financeiro']['soma_precos_pacotes_centavos'] = (int)$p['preco'];
                $out['financeiro']['soma_custos_pacotes_centavos'] = (int)$p['custo'];
            }
        }
        return $out;
    }

    /** @return array{iguais:bool, linhas:string[]} */
    public static function compare(array $a, array $b): array
    {
        $lines = [];
        $ok = true;
        foreach (array_unique(array_merge(array_keys($a['tabelas']), array_keys($b['tabelas']))) as $t) {
            $x = $a['tabelas'][$t] ?? null;
            $y = $b['tabelas'][$t] ?? null;
            $same = $x == $y;
            $ok = $ok && $same;
            $lines[] = sprintf('%-18s %s', $t, $same
                ? 'IGUAL (' . ($x['linhas'] ?? 0) . ' linhas)'
                : 'DIFERENTE: linhas ' . ($x['linhas'] ?? '-') . ' → ' . ($y['linhas'] ?? '-')
                    . (($x['hash_ids'] ?? '') !== ($y['hash_ids'] ?? '') ? ', IDs mudaram' : '')
                    . (($x['hash_conteudo'] ?? '') !== ($y['hash_conteudo'] ?? '') ? ', conteúdo mudou' : '')
                    . (($x['hash_estrutura'] ?? '') !== ($y['hash_estrutura'] ?? '') ? ', estrutura mudou' : ''));
        }
        $fs = ($a['financeiro'] ?? null) == ($b['financeiro'] ?? null);
        $ok = $ok && $fs;
        $lines[] = sprintf('%-18s %s', 'totais financeiros', $fs ? 'IGUAIS' : 'DIFERENTES');
        if (($a['banco'] ?? '') !== ($b['banco'] ?? '')) {
            $lines[] = 'ATENÇÃO: os dois retratos são de bancos diferentes.';
        }
        return ['iguais' => $ok, 'linhas' => $lines];
    }
}

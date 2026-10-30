<?php
declare(strict_types=1);

namespace GmaxRenov;

/**
 * Migrações versionadas, aditivas e reaplicáveis.
 * - Cada arquivo em migrations/ tem uma versão; versões já registradas não rodam de novo.
 * - Os comandos usam IF NOT EXISTS, então reaplicar (ou rodar após falha parcial) não duplica nada.
 * - Configurações padrão são inseridas com INSERT IGNORE: valores já salvos nunca são sobrescritos.
 */
final class Migrator
{
    private Db $db;
    private Clock $clock;

    public function __construct(Db $db, Clock $clock)
    {
        $this->db = $db;
        $this->clock = $clock;
    }

    public function migrate(): array
    {
        $done = [];
        if (!$this->db->getLock('gmax_renov_migracao', 30)) {
            throw new \RuntimeException('Outra migração está em andamento.');
        }
        try {
            $this->db->pdo->exec("CREATE TABLE IF NOT EXISTS gr_migracoes (
                versao VARCHAR(10) NOT NULL PRIMARY KEY,
                nome VARCHAR(100) NOT NULL,
                checksum CHAR(64) NOT NULL,
                aplicado_em DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $files = glob(GR_ROOT . '/migrations/*.php') ?: [];
            sort($files);
            foreach ($files as $file) {
                $m = require $file;
                $applied = $this->db->one('SELECT checksum FROM gr_migracoes WHERE versao = ?', [$m['versao']]);
                $checksum = hash('sha256', json_encode($m['sql']));
                if ($applied) {
                    if ($applied['checksum'] !== $checksum) {
                        $this->assertCompatible($m['versao']);
                    }
                    continue;
                }
                foreach ($m['sql'] as $sql) {
                    $this->db->pdo->exec($sql); // DDL faz commit implícito no MySQL; por isso tudo é idempotente.
                }
                $this->db->exec(
                    'INSERT IGNORE INTO gr_migracoes (versao, nome, checksum, aplicado_em) VALUES (?,?,?,?)',
                    [$m['versao'], $m['nome'], $checksum, $this->clock->now()->format('Y-m-d H:i:s')]
                );
                $done[] = $m['versao'] . '_' . $m['nome'];
            }
            (new Settings($this->db, $this->clock))->seedDefaults();
        } finally {
            $this->db->releaseLock('gmax_renov_migracao');
        }
        return $done;
    }

    /**
     * Migração já registrada com conteúdo diferente (ex.: pacote 1.0.0, IDs BIGINT): só segue se a estrutura
     * atual for compatível. Caso contrário, PARA sem alterar nada (IDs texto em coluna numérica misturariam clientes).
     */
    private function assertCompatible(string $version): void
    {
        $bad = $this->db->all(
            "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'gr\\_%' AND COLUMN_NAME IN ('cliente_id','pacote_id') AND DATA_TYPE <> 'varchar'"
        );
        if ($bad) {
            throw new \RuntimeException("Migração $version registrada com versão antiga (IDs numéricos em "
                . implode(', ', array_map(static fn($r) => $r['TABLE_NAME'] . '.' . $r['COLUMN_NAME'], $bad))
                . "). Nada foi alterado. Faça backup e execute sql/rollback_remover_modulo.sql (apaga só gr_*) antes de reinstalar.");
        }
    }

    public function pending(): array
    {
        $files = glob(GR_ROOT . '/migrations/*.php') ?: [];
        sort($files);
        $applied = $this->db->tableExists('gr_migracoes')
            ? array_column($this->db->all('SELECT versao FROM gr_migracoes'), 'versao')
            : [];
        $out = [];
        foreach ($files as $f) {
            $m = require $f;
            if (!in_array($m['versao'], $applied, true)) {
                $out[] = $m['versao'] . '_' . $m['nome'];
            }
        }
        return $out;
    }
}

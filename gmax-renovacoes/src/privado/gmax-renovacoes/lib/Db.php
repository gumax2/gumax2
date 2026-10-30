<?php
declare(strict_types=1);

namespace GmaxRenov;

use PDO;

final class Db
{
    public PDO $pdo;
    private ?string $lockDb = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public static function fromConfig(array $c): self
    {
        if (!empty($c['config_painel'])) {
            // Reutiliza as credenciais do config.php privado do painel (nada é copiado para cá).
            $file = (string)$c['config_painel'];
            if (!is_file($file)) {
                throw new \RuntimeException('config_painel não encontrado: confira o caminho no config.php do módulo.');
            }
            $pc = (static fn(string $f) => require $f)($file);
            if (!is_array($pc) || empty($pc['dsn'])) {
                throw new \RuntimeException('config_painel sem "dsn".');
            }
            $pdo = new PDO((string)$pc['dsn'], $pc['db_user'] ?? null, $pc['db_password'] ?? null, [PDO::ATTR_TIMEOUT => 10]);
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $pdo->exec('SET NAMES utf8mb4'); // só nesta conexão do módulo
            }
            return new self($pdo);
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'] ?? 'localhost',
            (int)($c['porta'] ?? 3306),
            $c['nome'] ?? '',
            $c['charset'] ?? 'utf8mb4'
        );
        if (!empty($c['socket'])) {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $c['socket'], $c['nome'] ?? '', $c['charset'] ?? 'utf8mb4');
        }
        $pdo = new PDO($dsn, $c['usuario'] ?? '', $c['senha'] ?? '', [PDO::ATTR_TIMEOUT => 10]);
        return new self($pdo);
    }

    public function all(string $sql, array $p = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    }

    public function one(string $sql, array $p = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    public function value(string $sql, array $p = [])
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Executa e devolve o número de linhas afetadas. */
    public function exec(string $sql, array $p = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        return $st->rowCount();
    }

    public function insert(string $sql, array $p = []): int
    {
        $this->exec($sql, $p);
        return (int)$this->pdo->lastInsertId();
    }

    public function tx(callable $fn)
    {
        $this->pdo->beginTransaction();
        try {
            $r = $fn($this);
            $this->pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Trava nomeada do MySQL (liberada automaticamente se a conexão cair). */
    public function getLock(string $name, int $timeout = 0): bool
    {
        return (int)$this->value('SELECT GET_LOCK(?, ?)', [$this->lockName($name), $timeout]) === 1;
    }

    public function releaseLock(string $name): void
    {
        $this->value('SELECT RELEASE_LOCK(?)', [$this->lockName($name)]);
    }

    /** GET_LOCK vale para o servidor MySQL inteiro (compartilhado na hospedagem): inclui o nome do banco. */
    public function lockName(string $name): string
    {
        $this->lockDb ??= (string)$this->value('SELECT DATABASE()');
        return substr($name . ':' . $this->lockDb, 0, 64);
    }

    /** Aspas em identificadores vindos do mapeamento do config (validados). */
    public static function ident(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new \RuntimeException('Identificador inválido no mapeamento do config.php: ' . $name); // vai para o log, não para o navegador
        }
        return '`' . $name . '`';
    }

    public function tableExists(string $table): bool
    {
        return (int)$this->value(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) > 0;
    }

    public function columns(string $table): array
    {
        return array_column($this->all(
            'SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$table]
        ), 'DATA_TYPE', 'COLUMN_NAME');
    }
}

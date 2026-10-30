<?php
/**
 * Mini-framework de testes (sem dependências). Cada teste recebe um banco MariaDB NOVO
 * com o esquema fictício do painel + migrações do módulo. Nenhuma chamada de rede é feita.
 */
declare(strict_types=1);

define('GR_TEST_SRC', dirname(__DIR__) . '/src/privado/gmax-renovacoes');
require_once GR_TEST_SRC . '/bootstrap.php';

use GmaxRenov\App;
use GmaxRenov\Contacts;
use GmaxRenov\Engine;
use GmaxRenov\FixedClock;
use GmaxRenov\Migrator;
use GmaxRenov\Provider\SimulatedProvider;

final class T
{
    public static array $results = [];
    public PDO $root;
    public string $dbName;
    public App $app;
    public FixedClock $clock;
    public SimulatedProvider $provider;
    public string $configFile;

    public static function env(string $k, string $d): string
    {
        return getenv($k) ?: $d;
    }

    public function __construct(string $now = '2026-11-10 09:30:00')
    {
        $this->root = new PDO('mysql:host=' . self::env('GR_TEST_HOST', 'localhost'), self::env('GR_TEST_USER', 'grtest'), self::env('GR_TEST_PASS', 'grtest'));
        $this->root->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->dbName = 'grtest_' . bin2hex(random_bytes(4));
        $this->root->exec("CREATE DATABASE `{$this->dbName}` CHARACTER SET utf8mb4");
        $this->root->exec("USE `{$this->dbName}`");
        foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents(__DIR__ . '/fixtures/painel_ficticio.sql'))))) as $sql) {
            $this->root->exec($sql);
        }
        $this->clock = new FixedClock($now);
        $this->app = new App($this->config(), $this->clock);
        (new Migrator($this->app->db(), $this->clock))->migrate();
        $this->provider = new SimulatedProvider();
        $this->app->setProvider($this->provider);
        $s = $this->app->settings();
        $s->set('ativo', true);
        $s->set('ativado_em', '2026-01-01');
    }

    public function config(array $extra = []): array
    {
        return array_replace_recursive([
            'fuso' => 'America/Sao_Paulo',
            'db' => ['host' => self::env('GR_TEST_HOST', 'localhost'), 'nome' => $this->dbName, 'usuario' => self::env('GR_TEST_USER', 'grtest'), 'senha' => self::env('GR_TEST_PASS', 'grtest')],
            'auth' => ['chave_sessao' => 'gr_teste_logado'],
            'esquema' => [
                'clientes' => ['tabela' => 'clientes', 'id' => 'id', 'nome' => 'nome', 'telefone' => 'telefone', 'status' => 'status', 'status_ativos' => ['ativo'], 'excluido' => 'excluido_em'],
                'pacotes' => ['tabela' => 'pacotes', 'id' => 'id', 'cliente_id' => 'cliente_id', 'nome' => 'nome', 'pontos' => 'pontos', 'servico' => 'servico', 'ciclo' => 'ciclo', 'preco' => 'preco_total', 'vencimento' => 'vencimento', 'status' => 'status', 'status_ativos' => ['ativo']],
            ],
            'provedor' => ['driver' => 'simulado', 'meta' => ['app_secret' => 'segredo-teste', 'verify_token' => 'verifica-teste', 'phone_number_id' => '123', 'access_token' => 'EAAtestetokenfalso123456']],
            'pasta_logs' => sys_get_temp_dir() . '/gr-test-logs',
            'permitir_relogio_teste' => true,
        ], $extra);
    }

    /** Grava um config.php para processos externos (CLI). */
    public function writeConfig(array $extra = []): string
    {
        $file = sys_get_temp_dir() . '/gr-config-' . $this->dbName . '.php';
        file_put_contents($file, '<?php return ' . var_export($this->config($extra), true) . ';');
        return $this->configFile = $file;
    }

    public function pdo(): PDO
    {
        return $this->app->db()->pdo;
    }

    public function client(string $name, ?string $phone = '31999990001', array $f = []): int
    {
        $st = $this->pdo()->prepare('INSERT INTO clientes (nome, telefone, status, excluido_em) VALUES (?,?,?,?)');
        $st->execute([$name, $phone, $f['status'] ?? 'ativo', $f['excluido_em'] ?? null]);
        return (int)$this->pdo()->lastInsertId();
    }

    public function package(int $clientId, string $name, string $due, string $price, array $f = []): int
    {
        $st = $this->pdo()->prepare('INSERT INTO pacotes (cliente_id, nome, pontos, servico, ciclo, preco_total, custo_total, vencimento, status, senha_acesso, url_lista) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$clientId, $name, $f['pontos'] ?? 1, $f['servico'] ?? 'Lista', $f['ciclo'] ?? 'mensal', $price, $f['custo'] ?? '10.00', $due, $f['status'] ?? 'ativo', 'SENHA-SECRETA-123', 'http://lista.exemplo/abc.m3u']);
        return (int)$this->pdo()->lastInsertId();
    }

    public function consent(int $clientId, string $phone = '31999990001'): void
    {
        (new Contacts($this->app->db(), $this->clock))->grant($clientId, \GmaxRenov\Format::phone($phone), 'teste');
    }

    public function run(?string $now = null, array $opt = []): array
    {
        if ($now) {
            $this->clock->set($now);
        }
        return (new Engine($this->app))->run('teste', $opt);
    }

    public function messages(): array
    {
        return $this->app->db()->all('SELECT * FROM gr_mensagens ORDER BY id');
    }

    public function reminders(): array
    {
        return $this->app->db()->all('SELECT * FROM gr_lembretes ORDER BY id');
    }

    public function financialChecksum(): string
    {
        $out = [];
        foreach (['clientes', 'pacotes', 'recebimentos', 'custos'] as $t) {
            $rows = $this->pdo()->query("SELECT * FROM `$t` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            $out[$t] = $rows;
        }
        return hash('sha256', json_encode($out));
    }

    public function drop(): void
    {
        $this->root->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        if (!empty($this->configFile)) {
            @unlink($this->configFile);
        }
    }
}

function check(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException('Falhou: ' . $msg);
    }
}

function eq($expected, $actual, string $msg): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("Falhou: $msg — esperado " . var_export($expected, true) . ', obtido ' . var_export($actual, true));
    }
}

/** Executa um teste com banco próprio. */
function test(string $name, callable $fn, string $now = '2026-11-10 09:30:00'): void
{
    $t = null;
    $start = microtime(true);
    try {
        $t = new T($now);
        $fn($t);
        T::$results[] = [$name, true, '', microtime(true) - $start];
        echo "  ✔ $name\n";
    } catch (Throwable $e) {
        T::$results[] = [$name, false, $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), microtime(true) - $start];
        echo "  ✘ $name\n      " . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    } finally {
        if ($t) {
            $t->drop();
        }
    }
}

<?php
/**
 * Mini-framework de testes (sem dependências). Cada teste recebe um banco MariaDB NOVO e DESCARTÁVEL (grtest_*)
 * com a ESTRUTURA REAL do painel GMAX (clients, client_plans, client_exclusions, entries, entry_plans...)
 * + migrações do módulo. Os registros de teste são fictícios e só existem nesse banco. Nenhuma chamada de rede.
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
        foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents(__DIR__ . '/fixtures/painel_gmax_schema.sql'))))) as $sql) {
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
            'auth' => ['exigir_https' => false], // demais valores: padrão do painel GMAX (gmax_session, admin, csrf, 3600 s)
            'esquema' => [], // padrão = painel GMAX (clients, client_plans, client_exclusions)
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

    private const STATUS = ['ativo' => 'active', 'pausado' => 'paused', 'arquivado' => 'archived'];
    private const MESES = ['mensal' => 1, 'trimestral' => 3, 'semestral' => 6, 'anual' => 12];

    /** Cliente fictício no formato real (id texto de 32 caracteres, como o api.php gera). */
    public function client(string $name, ?string $phone = '31999990001', array $f = []): string
    {
        $id = bin2hex(random_bytes(16));
        $st = $this->pdo()->prepare('INSERT INTO clients (id,name,phone,user_code,service,months,points,price_cents,cost_cents,due_date,status,notes,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        // due_date/price do cliente são legados: valores propositalmente diferentes para provar que não são usados.
        $st->execute([$id, $name, $phone ?? '', 'COD-CLIENTE-SECRETO', 'IPTV', 1, 1, 99900, 100, '2026-01-01', self::STATUS[$f['status'] ?? 'ativo'] ?? ($f['status'] ?? 'active'), 'obs', date('c')]);
        if (!empty($f['excluido_em'])) {
            $this->exclude($id, $f['excluido_em']);
        }
        return $id;
    }

    public function exclude(string $clientId, string $at = '2026-11-01T10:00:00-03:00'): void
    {
        $this->pdo()->prepare('INSERT INTO client_exclusions (client_id, deleted_at) VALUES (?,?)')->execute([$clientId, $at]);
    }

    /** Pacote fictício em client_plans; $price em reais ("120.00") vira price_cents (TOTAL do pacote). */
    public function package(string $clientId, string $name, string $due, string $price, array $f = []): string
    {
        $id = bin2hex(random_bytes(16));
        $st = $this->pdo()->prepare('INSERT INTO client_plans (id,client_id,label,user_code,service,months,points,price_cents,cost_cents,due_date,status,notes,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$id, $clientId, $name, 'ACESSO-SECRETO-123', $f['servico'] ?? 'IPTV', self::MESES[$f['ciclo'] ?? 'mensal'] ?? 1, $f['pontos'] ?? 1,
            \GmaxRenov\Format::toCents($price), \GmaxRenov\Format::toCents($f['custo'] ?? '10.00'), $due,
            self::STATUS[$f['status'] ?? 'ativo'] ?? ($f['status'] ?? 'active'), 'lista http://lista.exemplo/abc.m3u senha SENHA-SECRETA-123', date('c')]);
        return $id;
    }

    /** Renovação como o painel faz (api.php action=entry com new_due_date): receita + entry_plans + novo due_date só do pacote. */
    public function renew(string $clientId, string $planId, string $newDue, int $cents = 1000): void
    {
        $g = bin2hex(random_bytes(16));
        $this->pdo()->prepare('INSERT INTO entries (id,group_id,client_id,kind,amount_cents,date,description,created_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([bin2hex(random_bytes(16)), $g, $clientId, 'revenue', $cents, '2026-11-10', 'Renovação', date('c')]);
        $this->pdo()->prepare('INSERT INTO entry_plans (group_id, plan_id) VALUES (?,?)')->execute([$g, $planId]);
        $this->pdo()->prepare('UPDATE client_plans SET due_date=? WHERE id=? AND client_id=?')->execute([$newDue, $planId, $clientId]);
    }

    public function consent(string $clientId, string $phone = '31999990001'): void
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

    /** Retrato de integridade das tabelas do painel (mesma ferramenta usada na instalação). */
    public function financialChecksum(): string
    {
        $snap = (new \GmaxRenov\Integrity($this->app->db()))->snapshot();
        unset($snap['gerado_em']);
        return hash('sha256', json_encode($snap));
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

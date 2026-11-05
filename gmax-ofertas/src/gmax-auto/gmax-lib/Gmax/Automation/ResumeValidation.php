<?php
declare(strict_types=1);

namespace Gmax\Automation;

use Gmax\Connectors\{Connector, Registry};
use Gmax\{Config, Db, Time};
use PDO;
use RuntimeException;

/**
 * Validação operacional da retomada (3.2.1), para rodar NA HOSPEDAGEM sem risco:
 * - trabalha numa CÓPIA temporária do banco (VACUUM INTO) numa pasta temporária dentro de gmax-secrets;
 * - a trava, a pausa e os arquivos ficam nessa pasta (o Cron de produção não é bloqueado);
 * - não chama nenhuma API: um conector de validação só registra quais produtos foram "consultados";
 * - etapa 1: um processo PHP real começa a Revisão de preços e é encerrado à força (SIGKILL) no meio;
 * - etapa 2: outro processo roda o ciclo do Cron, que deve fechar a execução como "falhou", alertar e
 *   continuar sem consultar de novo o que já tinha sido consultado.
 * Ao final a pasta temporária é apagada (é só a cópia).
 */
final class ResumeValidation
{
    public const SYNTHETIC = 12;
    public const KILL_AFTER = 4;

    private static ?string $pointer = null;

    /** Arquivo que guarda a pasta da validação em andamento (fixado antes de child() trocar a pasta privada). */
    public static function pointer(): string
    {
        return self::$pointer ??= Config::secretsDir() . '/gmax-validacao-atual.txt';
    }

    /** Prepara a cópia e devolve a pasta temporária. */
    public static function prepare(PDO $live): string
    {
        $dir = Config::secretsDir() . '/gmax-validacao-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        if (!@mkdir($dir, 0700, true)) {
            throw new RuntimeException('Não foi possível criar a pasta temporária em gmax-secrets');
        }
        $live->prepare('VACUUM INTO ?')->execute([$dir . '/copia.sqlite']);
        $pdo = new PDO('sqlite:' . $dir . '/copia.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $now = gmdate('Y-m-d H:i:s');
        // Só na cópia: produtos sintéticos aprovados, tarefas desligadas exceto a revisão, agendada para agora.
        $ins = $pdo->prepare("INSERT INTO gmax3_catalog(marketplace,dedup_key,codigo,external_id,title,price_promo_cents,validation_status,availability,created_at,updated_at)
            VALUES('shopee',?,?,?,?,?,'aprovado','disponivel',?,?)");
        for ($i = 1; $i <= self::SYNTHETIC; $i++) {
            $ins->execute(["validacao$i", "VALIDACAO-$i", (string)(99000000000 + $i), "Produto de validação $i (cópia temporária)", 1000 + $i, $now, $now]);
        }
        $slot = (new \DateTimeImmutable('now', new \DateTimeZone(Config::TZ)))->modify('-1 minute')->format('H:i');
        $pdo->exec("UPDATE gmax3_jobs SET enabled=0");
        $pdo->prepare("UPDATE gmax3_jobs SET enabled=1, times=?, weekdays='1,2,3,4,5,6,7', params='{\"limite\":200}' WHERE job='revisao_precos'")->execute([$slot]);
        foreach (['automacoes_pausadas' => '0', 'api_intervalo_ms' => '0', 'automacoes_continuacao' => '{}'] as $k => $v) {
            $pdo->prepare('INSERT INTO gmax3_settings(key,value,updated_at,updated_by) VALUES(?,?,?,\'validacao\') ON CONFLICT(key) DO UPDATE SET value=excluded.value')->execute([$k, $v, $now]);
        }
        $pdo->exec("UPDATE gmax3_job_runs SET status='falhou' WHERE status='executando'");
        file_put_contents(self::pointer(), $dir);
        return $dir;
    }

    /** Executa o ciclo do Cron na cópia (processo filho). $kill > 0: morre depois de $kill consultas. */
    public static function child(string $dir, int $kill): array
    {
        self::pointer();
        if (!is_file($dir . '/copia.sqlite') || !str_starts_with(realpath($dir) ?: '', realpath(Config::secretsDir()) ?: '-')) {
            throw new RuntimeException('Pasta de validação inválida');
        }
        putenv('GMAX_SECRETS_DIR=' . $dir); // trava e pausa na pasta temporária: o Cron de produção não é bloqueado
        Db::use(new PDO('sqlite:' . $dir . '/copia.sqlite'));
        $c = new ValidationConnector($dir . '/consultas.log', $kill);
        Registry::override('shopee', $c);
        Registry::override('mercadolivre', $c);
        return Runner::runDue(Db::get(), 'validacao');
    }

    /** Confere o resultado depois das duas etapas e apaga a cópia. */
    public static function verify(string $dir): array
    {
        $pdo = new PDO('sqlite:' . $dir . '/copia.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $seen = array_values(array_filter(array_map('intval', file($dir . '/consultas.log', FILE_IGNORE_NEW_LINES) ?: [])));
        $synthetic = array_map('intval', $pdo->query("SELECT id FROM gmax3_catalog WHERE dedup_key LIKE 'validacao%'")->fetchAll(PDO::FETCH_COLUMN));
        $runs = $pdo->query("SELECT slot_key, status, detail FROM gmax3_job_runs WHERE job='revisao_precos' ORDER BY id DESC LIMIT 3")->fetchAll();
        $alert = (int)$pdo->query("SELECT COUNT(*) FROM gmax3_alerts WHERE kind='falha_automacao' AND message LIKE '%interrompida%'")->fetchColumn();
        $checks = [
            'a primeira execução foi encerrada à força no meio' => count(array_unique($seen)) > self::KILL_AFTER && in_array('falhou', array_column($runs, 'status'), true),
            'a execução interrompida virou "falhou" com alerta' => $alert >= 1,
            'a continuação rodou e terminou' => (bool)array_filter($runs, static fn($r) => str_contains($r['slot_key'], '#cont') && in_array($r['status'], ['concluido', 'parcial'], true)),
            // Só o produto que estava sendo consultado no instante da queda (sem resultado gravado) pode repetir.
            'nenhum produto já concluído foi consultado de novo' => count($seen) - count(array_unique($seen)) <= 1,
            'todos os produtos de validação foram consultados' => !array_diff($synthetic, $seen),
        ];
        $pdo = null;
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
        @unlink(self::pointer());
        return ['aprovado' => !in_array(false, $checks, true), 'verificacoes' => $checks, 'consultas' => count($seen), 'consultas_distintas' => count(array_unique($seen)), 'execucoes' => $runs];
    }
}

/** Conector da validação: não acessa rede; registra a consulta e confirma o preço do próprio catálogo. */
final class ValidationConnector implements Connector
{
    private int $n = 0;

    public function __construct(private string $log, private int $kill)
    {
    }

    public function name(): string { return 'validação (sem rede)'; }
    public function configured(): bool { return true; }
    public function status(): string { return 'validação local'; }

    public function fetch(array $row): array
    {
        usleep(150000);
        file_put_contents($this->log, $row['id'] . "\n", FILE_APPEND | LOCK_EX);
        if ($this->kill > 0 && ++$this->n >= $this->kill) {
            // Encerramento à força, como o servidor faria: sem finally, sem gravar o resultado.
            if (function_exists('posix_kill')) {
                posix_kill(getmypid(), 9);
            }
            exit(137);
        }
        return ['ok' => true, 'error' => null, 'data' => ['price_promo_cents' => (int)$row['price_promo_cents']], 'endpoint' => 'validacao', 'http' => 200];
    }
}

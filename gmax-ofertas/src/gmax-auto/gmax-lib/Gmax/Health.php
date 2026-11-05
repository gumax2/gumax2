<?php
declare(strict_types=1);

namespace Gmax;

use Gmax\Automation\{Runner, Schedule};
use Gmax\Connectors\Registry;
use PDO;
use Throwable;

/**
 * Monitor de saúde e diagnóstico de tarefas (3.2.1). Somente leitura, exceto:
 * - grava o usuário do PHP web (uid) quando aberto pelo navegador, para o comando de permissões
 *   saber se o PHP do site e o do Cron são o mesmo usuário;
 * - a verificação de integridade, que só roda por botão ou pela CLI.
 */
final class Health
{
    public const CRON_STALE_MIN = 45;

    /** @return array{0:string,1:string} [nível ok|aviso|falha, texto] */
    private static function level(bool $ok, bool $warnOnly, string $text): array
    {
        return [$ok ? 'ok' : ($warnOnly ? 'aviso' : 'falha'), $text];
    }

    public static function processUser(): array
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $name = $uid !== null && function_exists('posix_getpwuid') ? (posix_getpwuid($uid)['name'] ?? null) : (get_current_user() ?: null);
        return ['uid' => $uid, 'nome' => $name, 'sapi' => PHP_SAPI];
    }

    public static function rememberWebUser(PDO $pdo): void
    {
        if (PHP_SAPI !== 'cli') {
            $u = self::processUser();
            if ($u['uid'] !== null && Settings::get($pdo, 'php_web_uid', '') !== (string)$u['uid']) {
                Settings::store($pdo, 'php_web_uid', (string)$u['uid'], 'diagnostico');
                Settings::store($pdo, 'php_web_usuario', (string)$u['nome'], 'diagnostico');
            }
        }
    }

    /** Situação de uma trava (flock) sem segurá-la. */
    private static function lockState(string $file): string
    {
        $fh = @fopen($file, 'c');
        if ($fh === false) {
            return 'indisponível (sem permissão)';
        }
        $free = flock($fh, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($fh, LOCK_UN);
        }
        fclose($fh);
        return $free ? 'livre' : 'em uso agora';
    }

    public static function perms(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }
        clearstatcache(true, $path);
        $owner = fileowner($path);
        return ['modo' => substr(sprintf('%o', fileperms($path)), -4), 'dono_uid' => $owner,
            'dono' => function_exists('posix_getpwuid') ? (posix_getpwuid((int)$owner)['name'] ?? (string)$owner) : (string)$owner];
    }

    /** Tarefas: última execução, falhas e situação. */
    public static function jobs(PDO $pdo): array
    {
        $out = [];
        if (!Db::tableExists($pdo, 'gmax3_jobs')) {
            return $out;
        }
        $since = Time::now()->modify('-7 days')->format('Y-m-d H:i:s');
        $cont = Runner::continuations($pdo);
        foreach (Runner::jobs($pdo) as $j) {
            $last = Db::one($pdo, 'SELECT * FROM gmax3_job_runs WHERE job=? ORDER BY id DESC LIMIT 1', [$j['job']]);
            $st = $pdo->prepare('SELECT status, COUNT(*) FROM gmax3_job_runs WHERE job=? AND started_at >= ? GROUP BY status');
            $st->execute([$j['job'], $since]);
            $by = $st->fetchAll(PDO::FETCH_KEY_PAIR);
            $dur = $last && $last['finished_at'] ? (int)round((strtotime($last['finished_at'] . ' UTC') - strtotime($last['started_at'] . ' UTC'))) : null;
            $next = (int)$j['enabled'] ? Schedule::next($j) : null;
            $out[] = ['tarefa' => $j['job'], 'nome' => $j['name'], 'ativa' => (bool)(int)$j['enabled'], 'ultima' => $last ? $last['status'] : null,
                'ultima_em' => $last['started_at'] ?? null, 'duracao_s' => $dur, 'semana' => $by, 'continuacao' => $cont[$j['job']] ?? null,
                'proxima' => $next ? $next->format('Y-m-d H:i') : null, 'detalhe' => $last ? mb_substr((string)$last['detail'], 0, 300) : null];
        }
        return $out;
    }

    public static function collect(PDO $pdo): array
    {
        $c = [];
        // Cron
        $hb = Db::tableExists($pdo, 'gmax3_heartbeats') ? Db::one($pdo, "SELECT * FROM gmax3_heartbeats WHERE name='automacoes'") : null;
        $age = $hb ? Time::hoursSince($hb['last_at']) * 60 : null;
        $c['Cron das automações'] = self::level($age !== null && $age <= self::CRON_STALE_MIN, true, $hb
            ? 'última chamada há ' . (int)$age . ' min (' . Time::show($hb['last_at']) . ')' . ($age > self::CRON_STALE_MIN ? ' — o Cron pode estar parado' : '')
            : 'nenhuma chamada registrada (o Cron "executar-pendentes" ainda não rodou)');
        // Execuções
        if (Db::tableExists($pdo, 'gmax3_job_runs')) {
            $running = Db::all($pdo, "SELECT job, started_at FROM gmax3_job_runs WHERE status='executando'");
            $lock = self::lockState(Runner::lockFile());
            $c['Execuções em andamento'] = self::level(!$running || $lock === 'em uso agora', true, $running
                ? count($running) . ' marcada(s) "executando"; trava ' . $lock . ($lock === 'livre' ? ' — processo interrompido: será fechado e retomado no próximo Cron' : '')
                : 'nenhuma; trava ' . $lock);
            $fails = (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_job_runs WHERE status='falhou' AND started_at >= ?", [Time::now()->modify('-7 days')->format('Y-m-d H:i:s')]);
            $c['Falhas de tarefas (7 dias)'] = self::level($fails === 0, true, $fails . ' execução(ões) com falha');
        }
        if (Db::tableExists($pdo, 'gmax3_alerts')) {
            $open = (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_alerts WHERE resolved_at IS NULL');
            $c['Alertas abertos'] = self::level($open === 0, true, (string)$open);
        }
        // Integrações (sem rede: só a configuração)
        foreach (['shopee' => 'Shopee', 'mercadolivre' => 'Mercado Livre'] as $mp => $label) {
            try {
                $conn = Registry::get($mp, $pdo);
                $c["Integração $label"] = self::level($conn->configured(), true, $conn->status());
            } catch (Throwable $e) {
                $c["Integração $label"] = ['aviso', 'indisponível: ' . mb_substr($e->getMessage(), 0, 120)];
            }
        }
        // Banco
        $path = Db::path($pdo);
        $q = static fn(string $p) => $pdo->query("PRAGMA $p")->fetchColumn();
        $pages = (int)$q('page_count');
        $free = (int)$q('freelist_count');
        $size = (int)$q('page_size') * $pages;
        $c['Banco SQLite'] = ['ok', sprintf('%s · %.1f MB · %d%% de páginas livres · modo de diário %s · SQLite %s', basename($path), $size / 1048576, $pages ? (int)round(100 * $free / $pages) : 0,
            $q('journal_mode'), $pdo->query('SELECT sqlite_version()')->fetchColumn())];
        $chk = json_decode(Settings::get($pdo, 'diagnostico_integridade', ''), true);
        $c['Integridade do banco'] = is_array($chk)
            ? self::level($chk['resultado'] === 'ok', false, ($chk['resultado'] === 'ok' ? 'ok' : $chk['resultado']) . ' em ' . Time::show($chk['em']))
            : ['aviso', 'ainda não verificada (botão "Verificar integridade")'];
        // Disco e backups
        $dir = Config::secretsDir();
        $freeDisk = @disk_free_space(is_dir($dir) ? $dir : GMAX_APP_DIR);
        $c['Espaço em disco livre'] = $freeDisk !== false ? self::level($freeDisk > 200 * 1048576, true, sprintf('%.0f MB', $freeDisk / 1048576)) : ['aviso', 'não informado pela hospedagem'];
        try {
            $b = Backup::list();
            $newest = $b[0]['mtime'] ?? null;
            $c['Backups'] = self::level($newest !== null && time() - $newest < 8 * 86400, true, count($b) . ' arquivo(s), ' . sprintf('%.1f MB', array_sum(array_column($b, 'bytes')) / 1048576)
                . ($newest ? ' · mais recente ' . date('d/m/Y H:i', $newest) : ' · nenhum'));
        } catch (Throwable $e) {
            $c['Backups'] = ['aviso', mb_substr($e->getMessage(), 0, 120)];
        }
        // Permissões
        $c += self::permissionChecks($pdo);
        // Crescimento dos registros
        $sizes = [];
        foreach (['gmax3_job_run_items', 'gmax3_discoveries', 'gmax3_audit', 'gmax3_price_history', 'gmax3_autolab_messages'] as $t) {
            if (Db::tableExists($pdo, $t)) {
                $sizes[] = "$t " . number_format((int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(), 0, ',', '.');
            }
        }
        $c['Linhas por tabela'] = ['ok', implode(' · ', $sizes)];
        $u = self::processUser();
        $c['PHP'] = ['ok', sprintf('%s (%s) · usuário %s · max_execution_time %s · memory_limit %s', PHP_VERSION, PHP_SAPI, $u['nome'] ?? '?', ini_get('max_execution_time'), ini_get('memory_limit'))];
        return $c;
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function permissionChecks(PDO $pdo): array
    {
        $out = [];
        $db = self::perms(Db::path($pdo));
        $dir = self::perms(Config::secretsDir());
        $web = Settings::get($pdo, 'php_web_uid', '');
        $me = self::processUser();
        if ($db) {
            $out['Permissão do banco'] = self::level($db['modo'] === '0600', true, $db['modo'] . ' (dono ' . $db['dono'] . ')' . ($db['modo'] !== '0600' ? ' — recomendado 0600: veja "gmax-admin-cli.php permissoes"' : ''));
        }
        if ($dir) {
            $out['Permissão de gmax-secrets'] = self::level($dir['modo'] === '0700', true, $dir['modo'] . ' (dono ' . $dir['dono'] . ')' . ($dir['modo'] !== '0700' ? ' — recomendado 0700' : ''));
        }
        $same = $db && $web !== '' && (string)$db['dono_uid'] === $web;
        $out['Usuário do PHP do site'] = self::level($web === '' || $same, true, $web === '' ? 'ainda não registrado (abra esta página pelo navegador)'
            : 'uid ' . $web . ' (' . Settings::get($pdo, 'php_web_usuario', '?') . ')' . ($same ? ' = dono do banco: 0600/0700 são seguros' : ' ≠ dono do banco: NÃO restrinja as permissões'));
        return $out;
    }

    /** Verificação de integridade (quick_check), sob demanda. */
    public static function integrity(PDO $pdo, string $actor): string
    {
        $r = (string)$pdo->query('PRAGMA quick_check')->fetchColumn();
        Settings::store($pdo, 'diagnostico_integridade', json_encode(['resultado' => $r, 'em' => Time::nowSql()]), $actor);
        $pdo->exec('PRAGMA optimize');
        return $r;
    }

    /**
     * Plano de correção de permissões: só aplica quando o dono dos arquivos, o PHP do site e o PHP
     * desta linha de comando são o MESMO usuário (senão o site deixaria de ler o banco).
     * @return array{seguro:bool,motivo:string,acoes:list<array{0:string,1:string,2:string}>}
     */
    public static function permissionPlan(PDO $pdo): array
    {
        $me = self::processUser()['uid'];
        $web = Settings::get($pdo, 'php_web_uid', '');
        $db = Db::path($pdo);
        $dir = Config::secretsDir();
        $acts = [];
        foreach ([[$dir, '0700'], [$dir . '/gmax-backups', '0700'], [$dir . '/gmax-retencao', '0700'], [$db, '0600']] as [$p, $want]) {
            $cur = self::perms($p);
            if ($cur && $cur['modo'] !== $want) {
                $acts[] = [$p, $cur['modo'], $want];
            }
        }
        foreach (glob(Config::backupDir() . '/gmax-*.sqlite') ?: [] as $f) {
            $cur = self::perms($f);
            if ($cur && $cur['modo'] !== '0600') {
                $acts[] = [$f, $cur['modo'], '0600'];
            }
        }
        $owner = self::perms($db)['dono_uid'] ?? null;
        $why = match (true) {
            $me === null => 'extensão posix indisponível: não é possível confirmar o usuário',
            $web === '' => 'o usuário do PHP do site ainda não foi registrado: abra Sistema → Diagnóstico pelo navegador uma vez',
            (string)$owner !== (string)$me => 'o banco pertence a outro usuário (uid ' . $owner . ') e esta linha de comando roda como uid ' . $me,
            (string)$web !== (string)$me => 'o PHP do site roda como uid ' . $web . ' e esta linha de comando como uid ' . $me . ': restringir quebraria o site',
            default => '',
        };
        return ['seguro' => $why === '', 'motivo' => $why ?: 'dono dos arquivos = PHP do site = PHP do Cron (uid ' . $me . ')', 'acoes' => $acts];
    }

    /** Aplica o plano e confere em seguida que o banco continua gravável; se não, desfaz. */
    public static function applyPermissions(PDO $pdo, string $actor): array
    {
        $plan = self::permissionPlan($pdo);
        if (!$plan['seguro']) {
            throw new \RuntimeException('Não aplicado: ' . $plan['motivo']);
        }
        $done = [];
        foreach ($plan['acoes'] as [$p, $from, $to]) {
            if (@chmod($p, octdec($to))) {
                $done[] = [$p, $from, $to];
            }
        }
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            $pdo->exec('ROLLBACK');
        } catch (Throwable $e) {
            foreach ($done as [$p, $from]) {
                @chmod($p, octdec($from));
            }
            throw new \RuntimeException('Banco deixou de aceitar gravação após o ajuste: permissões anteriores restauradas');
        }
        Audit::log($pdo, $actor, 'permissoes_ajustadas', 'sistema', null, ['alteracoes' => array_map(static fn($a) => basename($a[0]) . " {$a[1]}→{$a[2]}", $done)]);
        return $done;
    }
}

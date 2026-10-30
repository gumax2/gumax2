<?php
/**
 * Ambiente de teste WEB sobre o PAINEL GMAX REAL (material de desenvolvimento, fora do repositório):
 *  - docroot temporário com painel/ = cópia da base real (index.html, api.php, assets/...) — env GR_PAINEL_BASE;
 *  - na CÓPIA, o $configPath fixo do api.php passa a apontar para um config.php temporário do painel
 *    (dsn do banco grtest_* do teste, require_https=false, setup_secret aleatório);
 *  - arquivos públicos do módulo + renovacoes-caminho.php; index.html recebe o PanelMenuPatch;
 *  - a biblioteca privada é copiada para fora do docroot e o config.php do módulo usa db.config_painel
 *    (mesmo config do painel: credenciais reaproveitadas, nada copiado);
 *  - login pelo fluxo REAL: api.php action=status (CSRF) → setup (1ª vez) / login.
 *  - sobe `php -S 127.0.0.1:PORTA` e derruba tudo no final. Nada aqui toca em src/ nem no repositório.
 * Requer tests/lib.php carregado antes (classe T).
 */
declare(strict_types=1);

final class GrWeb
{
    public const ADMIN_USER = 'admin.teste';
    public const ADMIN_PASS = 'senha-de-teste-forte-123';

    private static ?GrWeb $inst = null;

    public string $dir;
    public string $docroot;
    public string $lib;
    public string $base;
    public string $errorLog;
    public string $panelConfig;
    public string $setupSecret;
    public string $indexOriginal;
    public array $config = [];
    public array $panelCfg = [];
    /** @var resource|null */
    private $proc = null;

    public static function panelBase(): string
    {
        return getenv('GR_PAINEL_BASE') ?: '/tmp/claude-0/-home-user-gumax2/4551f546-0fe0-5e94-ba09-9252bba9ea1c/scratchpad/base/gmax-base-painel';
    }

    public static function available(): bool
    {
        return is_file(self::panelBase() . '/painel/api.php') && is_file(self::panelBase() . '/painel/index.html');
    }

    public static function get(): self
    {
        if (!self::$inst) {
            self::$inst = new self();
            register_shutdown_function([self::$inst, 'stop']);
        }
        return self::$inst;
    }

    private function __construct()
    {
        if (!self::available()) {
            throw new RuntimeException('Base do painel não encontrada em ' . self::panelBase() . ' (defina GR_PAINEL_BASE).');
        }
        $src = dirname(__DIR__, 2) . '/src';
        $this->dir = sys_get_temp_dir() . '/gr-web-' . bin2hex(random_bytes(4));
        $this->docroot = $this->dir . '/public_html';
        $this->lib = $this->dir . '/privado/gmax-renovacoes';
        $this->panelConfig = $this->dir . '/gmax-painel/private/config.php';
        mkdir(dirname($this->panelConfig), 0700, true);
        $this->setupSecret = bin2hex(random_bytes(20)); // 40 caracteres

        // 1) Painel real (cópia).
        self::copyDir(self::panelBase() . '/painel', $this->docroot . '/painel');
        $api = file_get_contents($this->docroot . '/painel/api.php');
        $n = 0;
        $api = preg_replace('~\$configPath\s*=\s*\'[^\']*\';~', '$configPath = ' . var_export($this->panelConfig, true) . ';', $api, -1, $n);
        if ($n !== 1) {
            throw new RuntimeException('$configPath não encontrado exatamente uma vez no api.php da base.');
        }
        file_put_contents($this->docroot . '/painel/api.php', $api);

        // 2) Módulo (público + privado).
        self::copyDir($src . '/publico/painel', $this->docroot . '/painel');
        self::copyDir($src . '/privado/gmax-renovacoes', $this->lib);
        @unlink($this->lib . '/config.php');
        file_put_contents($this->docroot . '/painel/renovacoes-caminho.php', '<?php return ' . var_export($this->lib, true) . ";\n");

        // 3) Link no menu com a ferramenta do próprio módulo.
        $this->indexOriginal = file_get_contents($this->docroot . '/painel/index.html');
        $plan = \GmaxRenov\PanelMenuPatch::plan($this->indexOriginal);
        if (!$plan['ok']) {
            throw new RuntimeException('PanelMenuPatch falhou: ' . $plan['motivo']);
        }
        file_put_contents($this->docroot . '/painel/index.html', $plan['novo']);

        // 4) Helper SOMENTE de teste: envelhece $_SESSION['last'] da sessão real (mesmos parâmetros do api.php).
        file_put_contents($this->docroot . '/expirar-sessao.php', "<?php\nini_set('session.use_strict_mode','1');\nsession_name('gmax_session');\nsession_set_cookie_params(['httponly'=>true,'secure'=>false,'samesite'=>'Strict','path'=>'/']);\nsession_start();\n\$_SESSION['last'] = time() - 3700;\nheader('Content-Type: text/plain');\necho 'expirada';\n");

        mkdir($this->dir . '/sessoes', 0700, true);
        mkdir($this->dir . '/logs', 0700, true);
        $this->errorLog = $this->dir . '/php-erros.log';
        touch($this->errorLog);

        $port = self::freePort();
        $this->base = "http://127.0.0.1:$port";
        $cmd = [PHP_BINARY,
            '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
            '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_reporting=' . E_ALL,
            '-d', 'error_log=' . $this->errorLog,
            '-d', 'session.save_path=' . $this->dir . '/sessoes',
            '-S', "127.0.0.1:$port", '-t', $this->docroot];
        $this->proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->dir . '/servidor.log', 'a'], 2 => ['file', $this->dir . '/servidor.log', 'a']], $pipes);
        if (!is_resource($this->proc)) {
            throw new RuntimeException('Não foi possível iniciar o servidor PHP embutido.');
        }
        for ($i = 0; $i < 100; $i++) {
            $s = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
            if ($s) {
                fclose($s);
                return;
            }
            usleep(50000);
        }
        $this->stop();
        throw new RuntimeException('Servidor PHP embutido não respondeu.');
    }

    /** Aponta painel e módulo para o banco do teste atual. O módulo lê as credenciais do config do painel. */
    public function useTest(T $t, array $extra = []): array
    {
        $this->panelCfg = [
            'dsn' => 'mysql:host=' . T::env('GR_TEST_HOST', 'localhost') . ';dbname=' . $t->dbName . ';charset=utf8mb4',
            'db_user' => T::env('GR_TEST_USER', 'grtest'),
            'db_password' => T::env('GR_TEST_PASS', 'grtest'),
            'require_https' => false,
            'timezone' => 'America/Sao_Paulo',
            'setup_secret' => $this->setupSecret,
        ];
        file_put_contents($this->panelConfig, '<?php return ' . var_export($this->panelCfg, true) . ";\n");
        $cfg = $t->config(['pasta_logs' => $this->dir . '/logs', 'permitir_relogio_teste' => false] + $extra);
        $cfg['db'] = ['config_painel' => $this->panelConfig]; // sem host/usuário/senha próprios
        $this->config = $cfg;
        file_put_contents($this->lib . '/config.php', '<?php return ' . var_export($cfg, true) . ";\n");
        clearstatcache();
        return $cfg;
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            $st = proc_get_status($this->proc);
            if (!empty($st['running']) && function_exists('posix_kill')) {
                @posix_kill($st['pid'], 15);
            }
            proc_terminate($this->proc, 9);
            proc_close($this->proc);
        }
        $this->proc = null;
        if (is_dir($this->dir)) {
            self::rmDir($this->dir);
        }
        if (self::$inst === $this) {
            self::$inst = null;
        }
    }

    /** Erros/avisos PHP registrados pelo servidor desde o início. */
    public function phpErrors(): string
    {
        return is_file($this->errorLog) ? (string)file_get_contents($this->errorLog) : '';
    }

    public function client(): GrHttp
    {
        return new GrHttp($this->base);
    }

    /** "Hoje" no fuso do módulo (o servidor web usa o relógio real). */
    public static function today(int $offsetDays = 0): string
    {
        return (new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo')))->modify(($offsetDays >= 0 ? '+' : '') . $offsetDays . ' days')->format('Y-m-d');
    }

    /**
     * Dados fictícios para o painel e para as capturas. Datas relativas a hoje (relógio real do servidor).
     * Retorna os ids criados.
     */
    public static function seed(T $t, bool $xss = false): array
    {
        $ids = [];
        // Autorizações registradas "agora" (relógio real), como na web.
        $t->clock->set((new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->modify('-2 days')->format('Y-m-d H:i:s'));
        // Cliente com dois pacotes no mesmo vencimento (+3 → etapa "antes" hoje) e um terceiro em outra data.
        $ids['ana'] = $t->client('Ana Souza', '31988887777');
        $t->consent($ids['ana'], '31988887777');
        $t->package($ids['ana'], 'Pacote Família', self::today(3), '120.00', ['pontos' => 4, 'ciclo' => 'trimestral']);
        $t->package($ids['ana'], 'Pacote Quarto', self::today(3), '35.00', ['pontos' => 1]);
        $t->package($ids['ana'], 'Pacote Escritório', self::today(6), '50.00', ['pontos' => 2]);
        // Sem autorização, vence hoje.
        $ids['bruno'] = $t->client('Bruno Lima', '(31) 97777-6666');
        $t->package($ids['bruno'], 'Pacote Sala', self::today(0), '45.90');
        // Vencido ontem (etapa "depois"), autorizado.
        $ids['carla'] = $t->client('Carla Mendes', '31966665555');
        $t->consent($ids['carla'], '31966665555');
        $t->package($ids['carla'], 'Pacote Básico', self::today(-1), '29.90');
        // Vencido há 5 dias (sem etapa hoje).
        $ids['daniel'] = $t->client('Daniel Rocha', '31955554444');
        $t->consent($ids['daniel'], '31955554444');
        $t->package($ids['daniel'], 'Pacote Premium', self::today(-5), '89.00', ['pontos' => 3]);
        // Autorizado, vence hoje, nome comprido (quebra de linha no celular).
        $ids['eduarda'] = $t->client('Eduarda Cristina de Albuquerque Vasconcelos', '31944443333');
        $t->consent($ids['eduarda'], '31944443333');
        $t->package($ids['eduarda'], 'Pacote Completo com Esportes e Filmes', self::today(0), '1250.00', ['pontos' => 6]);
        // Sem telefone válido.
        $ids['fabio'] = $t->client('Fábio Sem Telefone', null);
        $t->package($ids['fabio'], 'Pacote Único', self::today(2), '40.00');
        // Inativo no painel (não deve aparecer nos vencimentos).
        $ids['gil'] = $t->client('Gil Inativo', '31933332222', ['status' => 'pausado']);
        $t->package($ids['gil'], 'Pacote Antigo', self::today(3), '30.00');
        if ($xss) {
            $ids['xss'] = $t->client('<img src=x onerror=alert(1)>Xss', '31922221111');
            $t->package($ids['xss'], '<script>alert(2)</script>', self::today(1), '10.00');
        }
        return $ids;
    }

    public static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
        $name = stream_socket_get_name($s, false);
        fclose($s);
        return (int)substr($name, strrpos($name, ':') + 1);
    }

    public static function copyDir(string $from, string $to): void
    {
        if (!is_dir($to)) {
            mkdir($to, 0755, true);
        }
        foreach (scandir($from) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            is_dir("$from/$f") ? self::copyDir("$from/$f", "$to/$f") : copy("$from/$f", "$to/$f");
        }
    }

    public static function rmDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = "$dir/$f";
            is_dir($p) && !is_link($p) ? self::rmDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}

/** Cliente HTTP mínimo (curl) com "pote" de cookies próprio, como um navegador. */
final class GrHttp
{
    public string $base;
    public array $cookies = [];
    public ?string $csrf = null;

    public function __construct(string $base)
    {
        $this->base = $base;
    }

    /** @return array{status:int, headers:array<string,string[]>, body:string, json:mixed} */
    public function request(string $method, string $path, ?string $body = null, array $headers = []): array
    {
        $ch = curl_init($this->base . $path);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        if ($this->cookies) {
            $h[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $p)))][] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        if ($out === false) {
            throw new RuntimeException('curl: ' . curl_error($ch));
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        foreach ($respHeaders['set-cookie'] ?? [] as $c) {
            [$pair] = explode(';', $c, 2);
            [$k, $v] = array_map('trim', explode('=', $pair, 2)) + [1 => ''];
            if ($v === '' || $v === 'deleted') {
                unset($this->cookies[$k]);
            } else {
                $this->cookies[$k] = $v;
            }
        }
        return ['status' => $status, 'headers' => $respHeaders, 'body' => (string)$out, 'json' => json_decode((string)$out, true)];
    }

    public function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, null, $headers);
    }

    /** POST JSON para a API (cabeçalhos como os do renovacoes.js). */
    public function api(string $acao, array $data = [], ?string $csrf = 'auto', array $headers = []): array
    {
        $h = ['Content-Type' => 'application/json', 'Accept' => 'application/json'] + $headers;
        $tok = $csrf === 'auto' ? $this->csrf : $csrf;
        if ($tok !== null) {
            $h['X-CSRF-Token'] = $tok;
        }
        return $this->request('POST', '/painel/renovacoes-api.php?acao=' . rawurlencode($acao), json_encode($data), $h);
    }

    public function apiGet(string $acao, array $q = []): array
    {
        return $this->get('/painel/renovacoes-api.php?' . http_build_query(['acao' => $acao] + $q));
    }

    /** GET api.php?action=status: devolve o JSON e guarda o CSRF da sessão. */
    public function panelStatus(): array
    {
        $r = $this->get('/painel/api.php?action=status');
        if (!empty($r['json']['csrf'])) {
            $this->csrf = $r['json']['csrf'];
        }
        return $r;
    }

    /** POST para o api.php REAL do painel (JSON + X-CSRF-Token), como o app.js faz. */
    public function panel(string $action, array $data = [], ?string $csrf = 'auto'): array
    {
        $h = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        $tok = $csrf === 'auto' ? $this->csrf : $csrf;
        if ($tok !== null) {
            $h['X-CSRF-Token'] = $tok;
        }
        $r = $this->request('POST', '/painel/api.php?action=' . rawurlencode($action), json_encode($data), $h);
        if (!empty($r['json']['csrf'])) {
            $this->csrf = $r['json']['csrf'];
        }
        return $r;
    }

    /** Login pelo fluxo real do painel: status → setup (banco novo) ou login. */
    public function login(?string $setupSecret = null): self
    {
        $st = $this->panelStatus();
        if ($st['status'] !== 200 || empty($this->csrf)) {
            throw new RuntimeException('api.php status falhou (HTTP ' . $st['status'] . '): ' . substr($st['body'], 0, 200));
        }
        $creds = ['username' => GrWeb::ADMIN_USER, 'password' => GrWeb::ADMIN_PASS];
        $r = empty($st['json']['installed'])
            ? $this->panel('setup', $creds + ['secret' => $setupSecret ?? GrWeb::get()->setupSecret])
            : $this->panel('login', $creds);
        if ($r['status'] !== 200 || empty($r['json']['ok'])) {
            throw new RuntimeException('Login no painel falhou (HTTP ' . $r['status'] . '): ' . substr($r['body'], 0, 200));
        }
        return $this;
    }

    /** Lê o token CSRF que renovacoes.php imprime na meta (deve ser o mesmo do painel). */
    public function pageCsrf(): ?string
    {
        $p = $this->get('/painel/renovacoes.php');
        return preg_match('/<meta name="csrf-token" content="([^"]+)"/', $p['body'], $m) ? html_entity_decode($m[1], ENT_QUOTES) : null;
    }
}

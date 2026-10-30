<?php
/**
 * Ambiente de teste WEB (independente dos testes de regras):
 *  - monta um docroot temporário com painel/ = cópia de src/publico/painel + renovacoes-caminho.php;
 *  - copia a biblioteca privada para uma pasta temporária FORA do docroot e grava nela um config.php de teste;
 *  - adiciona login.php (só no docroot temporário) que simula o login do painel;
 *  - sobe `php -S 127.0.0.1:PORTA` em segundo plano e derruba tudo no final.
 * Requer tests/lib.php carregado antes (classe T). Nada aqui toca em src/.
 */
declare(strict_types=1);

final class GrWeb
{
    private static ?GrWeb $inst = null;

    public string $dir;
    public string $docroot;
    public string $lib;
    public string $base;
    public string $errorLog;
    public array $config = [];
    /** @var resource|null */
    private $proc = null;

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
        $src = dirname(__DIR__, 2) . '/src';
        $this->dir = sys_get_temp_dir() . '/gr-web-' . bin2hex(random_bytes(4));
        $this->docroot = $this->dir . '/public_html';
        $this->lib = $this->dir . '/privado/gmax-renovacoes';
        self::copyDir($src . '/publico/painel', $this->docroot . '/painel');
        self::copyDir($src . '/privado/gmax-renovacoes', $this->lib);
        @unlink($this->lib . '/config.php');
        mkdir($this->dir . '/sessoes', 0700, true);
        mkdir($this->dir . '/logs', 0700, true);
        file_put_contents($this->docroot . '/painel/renovacoes-caminho.php', '<?php return ' . var_export($this->lib, true) . ";\n");
        // Helper SOMENTE de teste: simula o login do painel (mesma sessão PHP padrão).
        file_put_contents($this->docroot . '/login.php', "<?php\nsession_start();\n\$_SESSION['gr_teste_logado'] = 1;\nheader('Content-Type: text/plain');\necho 'logado';\n");
        file_put_contents($this->docroot . '/logout.php', "<?php\nsession_start();\n\$_SESSION = [];\nsession_destroy();\necho 'saiu';\n");
        $this->errorLog = $this->dir . '/php-erros.log';
        touch($this->errorLog);

        $port = self::freePort();
        $this->base = "http://127.0.0.1:$port";
        $cmd = [PHP_BINARY,
            '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
            '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_reporting=' . E_ALL,
            '-d', 'error_log=' . $this->errorLog,
            '-d', 'session.save_path=' . $this->dir . '/sessoes',
            '-d', 'session.use_strict_mode=1',
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

    /** Aponta o servidor para o banco do teste atual (config.php da cópia privada). */
    public function useTest(T $t, array $extra = []): array
    {
        $this->config = $t->config(['pasta_logs' => $this->dir . '/logs', 'permitir_relogio_teste' => false] + $extra);
        file_put_contents($this->lib . '/config.php', '<?php return ' . var_export($this->config, true) . ";\n");
        clearstatcache();
        return $this->config;
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            $st = proc_get_status($this->proc);
            // proc_open com array não usa shell: o pid é o do próprio php -S.
            if (!empty($st['running'])) {
                posix_kill_safe($st['pid']);
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

function posix_kill_safe(int $pid): void
{
    if (function_exists('posix_kill')) {
        @posix_kill($pid, 15);
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

    /** Faz login pelo helper e lê o token CSRF da página (mesmo pote de cookies). */
    public function login(): self
    {
        $r = $this->get('/login.php');
        if ($r['status'] !== 200 || empty($this->cookies)) {
            throw new RuntimeException('Login de teste falhou.');
        }
        $p = $this->get('/painel/renovacoes.php');
        if (!preg_match('/<meta name="csrf-token" content="([^"]+)"/', $p['body'], $m)) {
            throw new RuntimeException('Token CSRF não encontrado na página (HTTP ' . $p['status'] . ').');
        }
        $this->csrf = html_entity_decode($m[1], ENT_QUOTES);
        return $this;
    }
}

<?php
/**
 * Testes no APACHE real (o servidor embutido do PHP ignora .htaccess).
 * Monta a estrutura da hospedagem em um diretório temporário:
 *   home1/gust1922/gmaxcanais.com.br/painel/   ← painel real (base 15/10) + arquivos do módulo + menu
 *   home1/gust1922/gmax-renovacoes/            ← biblioteca privada (localizada sem renovacoes-caminho.php)
 *   home1/gust1922/gmax-painel/private/config.php ← config do painel (banco descartável)
 * e testa quatro formatos de .htaccess que bloqueiam PHP e liberam só o api.php.
 * Requer: apache2 + libapache2-mod-php, MariaDB local e a base do painel (GR_PAINEL_BASE).
 */
declare(strict_types=1);

use GmaxRenov\HtaccessPatch;

final class GrApache
{
    public string $root;
    public string $home;
    public string $painel;
    public string $lib;
    public int $port;
    public string $base;
    private $proc = null;
    public string $panelSecret;
    public string $appSecret = 'segredo-webhook-apache';

    public static function available(): ?string
    {
        if (!is_file('/usr/sbin/apache2')) {
            return 'apache2 não instalado';
        }
        if (!is_file('/usr/lib/apache2/modules/libphp8.3.so')) {
            return 'mod_php (libapache2-mod-php8.3) não instalado';
        }
        $base = getenv('GR_PAINEL_BASE') ?: '/tmp/claude-0/-home-user-gumax2/4551f546-0fe0-5e94-ba09-9252bba9ea1c/scratchpad/base/gmax-base-painel';
        if (!is_file($base . '/painel/api.php')) {
            return 'base do painel não encontrada (defina GR_PAINEL_BASE)';
        }
        return null;
    }

    public function __construct(T $t, string $htaccess, string $htaccessAt = 'painel')
    {
        $src = dirname(__DIR__) . '/src';
        $baseDir = getenv('GR_PAINEL_BASE') ?: '/tmp/claude-0/-home-user-gumax2/4551f546-0fe0-5e94-ba09-9252bba9ea1c/scratchpad/base/gmax-base-painel';
        $this->root = sys_get_temp_dir() . '/gr-apache-' . bin2hex(random_bytes(4));
        $this->home = $this->root . '/home1/gust1922';
        $docroot = $this->home . '/gmaxcanais.com.br';
        $this->painel = $docroot . '/painel';
        $this->lib = $this->home . '/gmax-renovacoes';
        $private = $this->home . '/gmax-painel/private';
        GrWeb::copyDir($baseDir . '/painel', $this->painel);
        GrWeb::copyDir($src . '/publico/painel', $this->painel);
        GrWeb::copyDir($src . '/privado/gmax-renovacoes', $this->lib);
        @mkdir($private, 0755, true);
        $this->panelSecret = bin2hex(random_bytes(20));
        file_put_contents($private . '/config.php', '<?php return ' . var_export([
            'dsn' => 'mysql:host=localhost;dbname=' . $t->dbName . ';charset=utf8mb4',
            'db_user' => T::env('GR_TEST_USER', 'grtest'), 'db_password' => T::env('GR_TEST_PASS', 'grtest'),
            'require_https' => false, 'timezone' => 'America/Sao_Paulo', 'setup_secret' => $this->panelSecret,
        ], true) . ';');
        // Somente na CÓPIA de teste: aponta o caminho fixo do api.php para o config temporário.
        $api = file_get_contents($this->painel . '/api.php');
        $api = str_replace("'/home1/gust1922/gmax-painel/private/config.php'", var_export($private . '/config.php', true), $api, $n);
        if ($n !== 1) {
            throw new RuntimeException('Caminho do config não encontrado exatamente uma vez no api.php');
        }
        file_put_contents($this->painel . '/api.php', $api);
        // Menu
        $idx = file_get_contents($this->painel . '/index.html');
        file_put_contents($this->painel . '/index.html', \GmaxRenov\PanelMenuPatch::plan($idx)['novo']);
        // Config do módulo: banco lido do config do painel; sem renovacoes-caminho.php (testa a localização padrão).
        @mkdir($this->lib . '/logs', 0777, true);
        chmod($this->lib . '/logs', 0777);
        file_put_contents($this->lib . '/config.php', '<?php return ' . var_export([
            'fuso' => 'America/Sao_Paulo',
            'db' => ['config_painel' => $private . '/config.php'],
            'auth' => ['exigir_https' => false],
            'esquema' => [],
            'provedor' => ['driver' => 'simulado', 'meta' => ['app_secret' => $this->appSecret, 'verify_token' => 'verifica-apache']],
            'pasta_logs' => $this->lib . '/logs',
        ], true) . ';');
        // Arquivo PHP "suspeito" que deve continuar bloqueado.
        file_put_contents($this->painel . '/suspeito.php', "<?php echo 'EXECUTOU';");
        file_put_contents(($htaccessAt === 'raiz' ? $docroot : $this->painel) . '/.htaccess', $htaccess);
        // Permissões para o usuário do Apache (www-data) ler a árvore de teste.
        exec('chmod -R a+rX ' . escapeshellarg($this->root));
        $this->port = GrWeb::freePort();
        $this->base = "http://127.0.0.1:{$this->port}/painel/";
        $conf = $this->root . '/httpd.conf';
        $m = '/usr/lib/apache2/modules';
        file_put_contents($conf, "ServerRoot /etc/apache2\nServerName 127.0.0.1\nListen 127.0.0.1:{$this->port}\nPidFile {$this->root}/httpd.pid\n"
            . "LoadModule mpm_prefork_module $m/mod_mpm_prefork.so\nLoadModule authz_core_module $m/mod_authz_core.so\nLoadModule authz_host_module $m/mod_authz_host.so\n"
            . "LoadModule access_compat_module $m/mod_access_compat.so\nLoadModule rewrite_module $m/mod_rewrite.so\nLoadModule dir_module $m/mod_dir.so\n"
            . "LoadModule mime_module $m/mod_mime.so\nLoadModule php_module $m/libphp8.3.so\n"
            . "User www-data\nGroup www-data\nErrorLog {$this->root}/error.log\nLogLevel warn\nTypesConfig /etc/mime.types\n"
            . "DocumentRoot $docroot\nDirectoryIndex index.html\n<Directory />\n AllowOverride None\n Require all denied\n</Directory>\n"
            . "<Directory $docroot>\n AllowOverride All\n Options FollowSymLinks\n Require all granted\n</Directory>\n"
            . "<FilesMatch \"\\.php$\">\n SetHandler application/x-httpd-php\n</FilesMatch>\nStartServers 2\nMaxRequestWorkers 10\n");
        $this->proc = proc_open(['setsid', '/usr/sbin/apache2', '-f', $conf, '-DFOREGROUND'], [1 => ['file', $this->root . '/stdout.log', 'a'], 2 => ['file', $this->root . '/stderr.log', 'a']], $pipes);
        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', $this->port)) {
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('Apache não subiu: ' . @file_get_contents($this->root . '/stderr.log') . @file_get_contents($this->root . '/error.log'));
    }

    public function htaccessFile(string $at = 'painel'): string
    {
        return ($at === 'raiz' ? dirname($this->painel) : $this->painel) . '/.htaccess';
    }

    public function admin(array $args): array
    {
        exec('GR_CONFIG=' . escapeshellarg($this->lib . '/config.php') . ' ' . escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($this->lib . '/bin/renovacoes-admin.php') . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    }

    public function stop(): void
    {
        // Encerramento com prazo: TERM, espera até 5 s, depois KILL só nos processos desta árvore de teste.
        $pattern = escapeshellarg('[a]pache2 -f ' . $this->root . '/httpd.conf');
        exec("pkill -TERM -f $pattern 2>/dev/null");
        for ($i = 0; $i < 50; $i++) {
            $o = [];
            exec("pgrep -f $pattern", $o, $code);
            if ($code !== 0) {
                break;
            }
            usleep(100000);
        }
        exec("pkill -KILL -f $pattern 2>/dev/null");
        if ($this->proc) {
            @proc_close($this->proc);
        }
        exec('rm -rf ' . escapeshellarg($this->root));
    }
}

function apacheTest(string $name, string $htaccess, string $at, callable $fn): void
{
    test($name, function (T $t) use ($htaccess, $at, $fn) {
        $a = new GrApache($t, $htaccess, $at);
        try {
            $fn($t, $a);
        } finally {
            $errors = @file_get_contents($a->root . '/error.log') ?: '';
            $a->stop();
            check(!preg_match('~PHP (Fatal|Warning|Parse)~', $errors), 'erros de PHP no Apache: ' . substr($errors, 0, 400)); fwrite(STDERR, "[dbg 8079]\n");
        }
    });
}

$why = GrApache::available();
if ($why !== null) {
    echo "Apache (.htaccess)\n";
    foreach (['Files 2.4', 'Files 2.2', 'Rewrite na pasta', 'Rewrite na raiz', 'sem bloqueio'] as $n) {
        skipTest("apache: .htaccess $n", $why);
    }
    return;
}
require_once __DIR__ . '/web/ambiente.php'; // GrWeb::copyDir/freePort, GrHttp

echo "Apache real: .htaccess, sessão e webhook\n";

$variants = [
    'Files 2.4 (Require)' => ["Options -Indexes\n<FilesMatch \"\\.php$\">\n  Require all denied\n</FilesMatch>\n<Files \"api.php\">\n  Require all granted\n</Files>\n", 'painel'],
    'Files 2.2 (Order/Deny)' => ["<FilesMatch \"\\.(php|phtml)$\">\nOrder allow,deny\nDeny from all\n</FilesMatch>\n<FilesMatch \"^api\\.php$\">\nOrder allow,deny\nAllow from all\n</FilesMatch>\n", 'painel'],
    'Rewrite na pasta do painel' => ["Options -Indexes\nRewriteEngine On\nRewriteCond %{REQUEST_URI} !/api\\.php$\nRewriteRule \\.php$ - [F,L]\n", 'painel'],
    'Rewrite na raiz do domínio' => ["RewriteEngine On\nRewriteRule ^painel/(?!api\\.php$).*\\.php$ - [F]\n", 'raiz'],
];

foreach ($variants as $label => [$ht, $at]) {
    apacheTest("apache: .htaccess $label — bloqueia antes, patch libera só os 3 arquivos, sessão e assinatura mantidas, remover restaura", $ht, $at,
        function (T $t, GrApache $a) use ($ht, $at) {
            $http = new GrHttp(rtrim(str_replace('/painel/', '', $a->base), '/'));
            $hdr = static fn(array $r) => trim((string)(($r['headers']['x-gmax-renovacoes'] ?? [])[0] ?? '')) === '1';
            // ANTES: regra antiga bloqueia o módulo; api.php funciona; suspeito bloqueado.
            foreach (['renovacoes.php', 'renovacoes-api.php?acao=painel', 'renovacoes-webhook.php'] as $p) {
                $r = $http->get('/painel/' . $p);
                eq(403, $r['status'], "antes: $p bloqueado pelo servidor");
                check(!$hdr($r), "antes: $p não chegou ao módulo");
            }
            eq(200, $http->get('/painel/api.php?action=status')['status'], 'antes: api.php ok'); fwrite(STDERR, "[dbg 10158]\n");
            eq(403, $http->get('/painel/suspeito.php')['status'], 'antes: suspeito bloqueado'); fwrite(STDERR, "[dbg 10255]\n");
            // Diagnóstico
            [$c, $out] = $a->admin(['htaccess', 'verificar', $a->painel]); fwrite(STDERR, "[dbg 10378]\n");
            eq(2, $c, 'verificar indica patch necessário: ' . $out); fwrite(STDERR, "[dbg 10453]\n");
            check(strpos($out, 'renovacoes.php') !== false, 'lista os arquivos bloqueados'); fwrite(STDERR, "[dbg 10522]\n");
            [$c, $out] = $a->admin(['verificar-http', $a->base]); fwrite(STDERR, "[dbg 10615]\n");
            eq(2, $c, 'verificar-http aponta bloqueio antes do patch'); fwrite(STDERR, "[dbg 10681]\n");
            check(strpos($out, 'bloqueado pelo servidor') !== false, 'explica o bloqueio'); fwrite(STDERR, "[dbg 10753]\n");
            // Aplicar
            $file = $a->htaccessFile($at);
            [$c, $out] = $a->admin(['htaccess', 'aplicar', $file, $a->painel]); fwrite(STDERR, "[dbg 10911]\n");
            eq(0, $c, 'aplicar: ' . $out); fwrite(STDERR, "[dbg 10991]\n");
            check(count(glob($file . '.antes-renovacoes-*')) === 1, 'backup do .htaccess criado'); fwrite(STDERR, "[dbg 11034]\n");
            eq($ht, file_get_contents(glob($file . '.antes-renovacoes-*')[0]), 'backup idêntico ao original'); fwrite(STDERR, "[dbg 11133]\n");
            $patched = file_get_contents($file);
            check(strpos($patched, $ht === '' ? '' : explode("\n", $ht)[1]) !== false, 'regras originais mantidas'); fwrite(STDERR, "[dbg 11293]\n");
            // DEPOIS: módulo responde, mas exige sessão; webhook exige assinatura; nada mais foi liberado.
            $r = $http->get('/painel/renovacoes.php');
            check($hdr($r) && in_array($r['status'], [302, 303], true), 'página sem sessão → redireciona (módulo): ' . $r['status']); fwrite(STDERR, "[dbg 11573]\n");
            $r = $http->get('/painel/renovacoes-api.php?acao=painel');
            check($hdr($r) && $r['status'] === 401, 'API sem sessão → 401'); fwrite(STDERR, "[dbg 11778]\n");
            $r = $http->get('/painel/renovacoes-webhook.php');
            check($hdr($r) && $r['status'] === 403 && trim($r['body']) === 'forbidden', 'webhook GET sem token → 403 do módulo'); fwrite(STDERR, "[dbg 11918]\n");
            $r = $http->request('POST', '/painel/renovacoes-webhook.php', '{"entry":[]}', ['Content-Type' => 'application/json', 'X-Hub-Signature-256' => 'sha256=' . str_repeat('0', 64)]);
            check($hdr($r) && $r['status'] === 401, 'webhook assinatura falsa → 401'); fwrite(STDERR, "[dbg 12229]\n");
            $raw = '{"entry":[]}';
            $r = $http->request('POST', '/painel/renovacoes-webhook.php', $raw, ['Content-Type' => 'application/json', 'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $raw, $a->appSecret)]);
            check($hdr($r) && $r['status'] === 200, 'webhook assinatura válida → 200: ' . $r['status'] . ' ' . substr($r['body'], 0, 200)); fwrite(STDERR, "[dbg 12543]\n");
            eq(403, $http->get('/painel/suspeito.php')['status'], 'depois: suspeito.php continua bloqueado'); fwrite(STDERR, "[dbg 12631]\n");
            eq(403, $http->get('/painel/renovacoes-caminho.php')['status'], 'depois: renovacoes-caminho.php continua bloqueado'); fwrite(STDERR, "[dbg 12741]\n");
            file_put_contents($a->painel . '/xrenovacoes.php', "<?php echo 'X';");
            file_put_contents($a->painel . '/renovacoes-teste.php', "<?php echo 'X';");
            eq(403, $http->get('/painel/xrenovacoes.php')['status'], 'xrenovacoes.php bloqueado'); fwrite(STDERR, "[dbg 13042]\n");
            eq(403, $http->get('/painel/renovacoes-teste.php')['status'], 'renovacoes-teste.php bloqueado'); fwrite(STDERR, "[dbg 13141]\n");
            eq(200, $http->get('/painel/index.html')['status'], 'index.html ok'); fwrite(STDERR, "[dbg 13250]\n");
            eq(200, $http->get('/painel/renovacoes-assets/renovacoes.js')['status'], 'assets ok'); fwrite(STDERR, "[dbg 13332]\n");
            // Login real pelo api.php sob Apache e acesso à aba com a mesma sessão.
            $st = json_decode($http->get('/painel/api.php?action=status')['body'], true);
            $r = $http->request('POST', '/painel/api.php?action=setup', json_encode(['username' => 'admin', 'password' => 'senha-de-teste-123', 'secret' => $a->panelSecret]), ['Content-Type' => 'application/json', 'X-CSRF-Token' => $st['csrf']]);
            eq(200, $r['status'], 'login (setup) pelo api.php: ' . $r['body']); fwrite(STDERR, "[dbg 13850]\n");
            $r = $http->get('/painel/renovacoes.php');
            check($r['status'] === 200 && $hdr($r) && strpos($r['body'], 'Renovações') !== false, 'aba abre com a sessão do painel'); fwrite(STDERR, "[dbg 13985]\n");
            eq(200, $http->get('/painel/renovacoes-api.php?acao=painel')['status'], 'API com sessão → 200'); fwrite(STDERR, "[dbg 14119]\n");
            check(strpos($http->get('/painel/index.html')['body'], 'href="renovacoes.php"') !== false, 'link no menu'); fwrite(STDERR, "[dbg 14228]\n");
            [$c, $out] = $a->admin(['verificar-http', $a->base]); fwrite(STDERR, "[dbg 14348]\n");
            eq(0, $c, 'verificar-http depois do patch: ' . $out); fwrite(STDERR, "[dbg 14414]\n");
            // Remover
            [$c, $out] = $a->admin(['htaccess', 'remover', $file]); fwrite(STDERR, "[dbg 14503]\n");
            eq(0, $c, 'remover: ' . $out); fwrite(STDERR, "[dbg 14571]\n");
            eq($ht, file_get_contents($file), '.htaccess idêntico ao original após remover'); fwrite(STDERR, "[dbg 14614]\n");
            $r = (new GrHttp(rtrim(str_replace('/painel/', '', $a->base), '/')))->get('/painel/renovacoes.php');
            eq(403, $r['status'], 'após remover, volta a bloquear'); fwrite(STDERR, "[dbg 14821]\n");
        });
}

apacheTest('apache: sem regra de bloqueio — verificar não propõe mudança e o módulo responde', "Options -Indexes\n", 'painel', function (T $t, GrApache $a) {
    [$c, $out] = $a->admin(['htaccess', 'verificar', $a->painel]);
    eq(0, $c, 'nada a fazer: ' . $out);
    [$c, $out] = $a->admin(['htaccess', 'aplicar', $a->htaccessFile(), $a->painel]);
    eq(0, $c, 'aplicar não grava nada');
    eq("Options -Indexes\n", file_get_contents($a->htaccessFile()), '.htaccess intocado');
    check(count(glob($a->htaccessFile() . '.antes-renovacoes-*')) === 0, 'sem backup porque nada mudou');
});

apacheTest('apache: formato desconhecido (bloqueia tudo, inclusive api.php) — recusa sem alterar', "<FilesMatch \"\\.php$\">\nRequire all denied\n</FilesMatch>\n", 'painel', function (T $t, GrApache $a) {
    [$c, $out] = $a->admin(['htaccess', 'aplicar', $a->htaccessFile(), $a->painel]);
    eq(2, $c, 'recusado: ' . $out);
    eq("<FilesMatch \"\\.php$\">\nRequire all denied\n</FilesMatch>\n", file_get_contents($a->htaccessFile()), 'intocado');
});

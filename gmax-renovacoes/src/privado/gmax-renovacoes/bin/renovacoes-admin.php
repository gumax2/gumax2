<?php
/**
 * Ferramenta de linha de comando (SSH/Terminal do cPanel).
 *   php renovacoes-admin.php diagnostico   → confere config, mapeamento das tabelas e migrações (não altera nada)
 *   php renovacoes-admin.php migrar        → aplica migrações pendentes (aditivas, reaplicáveis)
 *   php renovacoes-admin.php backup [arq]  → exporta as tabelas gr_* para um arquivo .sql
 *   php renovacoes-admin.php status        → resumo da fila
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';

umask(0077); // arquivos gerados (backup, integridade) nascem legíveis só pelo dono
$cmd = $argv[1] ?? 'diagnostico';

function gr_write(string $file, string $data): void
{
    if (file_put_contents($file . '.tmp', $data) === false || !rename($file . '.tmp', $file)) {
        fwrite(STDERR, "Não foi possível gravar $file\n");
        exit(1);
    }
}

function gr_read_json(string $file): array
{
    $d = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    if (!is_array($d) || !isset($d['tabelas'])) {
        fwrite(STDERR, "Arquivo de integridade inválido: $file\n");
        exit(1);
    }
    return $d;
}
$app = gr_app();
$db = $app->db();

switch ($cmd) {
    case 'diagnostico':
        echo "GMAX Renovações " . GR_VERSION . "\n";
        echo "PHP " . PHP_VERSION . " | MySQL " . $db->value('SELECT VERSION()') . "\n";
        echo "Fuso: " . date_default_timezone_get() . " | agora: " . $app->clock()->now()->format('d/m/Y H:i') . "\n";
        echo "Pasta da biblioteca: " . GR_ROOT . "\n";
        if (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] && strpos(GR_ROOT, $_SERVER['DOCUMENT_ROOT']) === 0) {
            echo "ALERTA: a biblioteca está dentro da pasta pública.\n";
        }
        echo "Extensões: pdo_mysql=" . (extension_loaded('pdo_mysql') ? 'ok' : 'FALTA') . " curl=" . (extension_loaded('curl') ? 'ok' : 'FALTA') . " mbstring=" . (extension_loaded('mbstring') ? 'ok' : 'FALTA') . "\n\n";
        foreach ($app->panel()->diagnose() as $name => $d) {
            echo strtoupper($name) . ": tabela `{$d['tabela']}` " . ($d['existe'] ? 'encontrada' : 'NÃO ENCONTRADA') . "\n";
            if ($d['existe']) {
                echo "  colunas: " . implode(', ', $d['colunas']) . "\n";
                echo $d['faltando'] ? "  FALHA mapeamento: " . implode('; ', $d['faltando']) . "\n" : "  mapeamento ok\n";
            }
        }
        echo "Pacotes com vencimento inválido (não recebem aviso): " . $app->panel()->invalidDueDates() . "\n";
        $pending = (new \GmaxRenov\Migrator($db, $app->clock()))->pending();
        echo "\nMigrações pendentes: " . ($pending ? implode(', ', $pending) : 'nenhuma') . "\n";
        if (!$pending) {
            echo "Modo efetivo: " . $app->effectiveMode() . "\n";
            foreach ($app->realSendingBlockers() as $b) {
                echo "  - $b\n";
            }
        }
        break;

    case 'migrar':
        $done = (new \GmaxRenov\Migrator($db, $app->clock()))->migrate();
        echo $done ? 'Aplicadas: ' . implode(', ', $done) . "\n" : "Nada a aplicar (já atualizado).\n";
        break;

    case 'backup':
        $file = $argv[2] ?? (GR_ROOT . '/backup-gr-' . date('Ymd-His') . '.sql');
        $fh = fopen($file, 'x');
        if (!$fh) {
            fwrite(STDERR, "Não foi possível criar $file (já existe?)\n");
            exit(1);
        }
        fwrite($fh, "-- Backup das tabelas do módulo GMAX Renovações (gr_*) — " . date('c') . "\nSET NAMES utf8mb4;\n");
        foreach ($db->all("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'gr\\_%'") as $t) {
            $name = $t['TABLE_NAME'];
            $create = $db->one('SHOW CREATE TABLE ' . \GmaxRenov\Db::ident($name));
            fwrite($fh, "\n" . str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $create['Create Table']) . ";\n");
            $st = $db->pdo->query('SELECT * FROM ' . \GmaxRenov\Db::ident($name));
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $vals = array_map(static fn($v) => $v === null ? 'NULL' : $db->pdo->quote((string)$v), $row);
                fwrite($fh, 'INSERT IGNORE INTO `' . $name . '` (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', $vals) . ");\n");
            }
        }
        fclose($fh);
        chmod($file, 0600);
        echo "Backup gravado em $file\n";
        break;

    case 'status':
        foreach ($db->all('SELECT status, COUNT(*) n FROM gr_mensagens GROUP BY status') as $r) {
            echo str_pad($r['status'], 12) . $r['n'] . "\n";
        }
        break;

    case 'integridade':
        // Retrato SEM dados pessoais (contagens, hashes, totais). Rode antes e depois da instalação.
        $file = $argv[2] ?? (GR_ROOT . '/integridade-' . date('Ymd-His') . '.json');
        $snap = (new \GmaxRenov\Integrity($db))->snapshot();
        gr_write($file, json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        foreach ($snap['tabelas'] as $t => $i) {
            echo str_pad($t, 18) . ($i['existe'] ? $i['linhas'] . ' linhas' : 'não existe') . "\n";
        }
        echo "Retrato gravado em $file\n";
        break;

    case 'integridade-comparar':
        if (!isset($argv[2], $argv[3])) {
            echo "Uso: integridade-comparar antes.json depois.json\n";
            exit(1);
        }
        $r = \GmaxRenov\Integrity::compare(gr_read_json($argv[2]), gr_read_json($argv[3]));
        echo implode("\n", $r['linhas']), "\n", $r['iguais'] ? "RESULTADO: dados do painel preservados.\n" : "RESULTADO: HÁ DIFERENÇAS — não prossiga; veja BACKUP_E_RECUPERACAO.md.\n";
        exit($r['iguais'] ? 0 : 3);

    case 'menu':
        // php renovacoes-admin.php menu verificar|aplicar|remover /home1/gust1922/gmaxcanais.com.br/painel/index.html
        $mode = $argv[2] ?? '';
        $file = $argv[3] ?? '';
        if (!in_array($mode, ['verificar', 'aplicar', 'remover'], true)) {
            echo "Uso: menu verificar|aplicar|remover /caminho/painel/index.html\n";
            exit(1);
        }
        if (!is_file($file)) {
            echo "Informe o caminho do index.html do painel.\n";
            exit(1);
        }
        $html = file_get_contents($file);
        if ($mode === 'remover') {
            $new = \GmaxRenov\PanelMenuPatch::revert($html);
            if ($new === $html) {
                echo "Nada a remover.\n";
                break;
            }
        } else {
            $plan = \GmaxRenov\PanelMenuPatch::plan($html);
            if (!$plan['ok']) {
                echo $plan['motivo'], "\n";
                exit(1);
            }
            $new = $plan['novo'];
        }
        echo \GmaxRenov\PanelMenuPatch::diff($html, $new), "\n";
        if ($mode === 'verificar') {
            echo "(somente verificação; nada foi gravado)\n";
            break;
        }
        $bak = $file . '.antes-renovacoes-' . date('Ymd-His');
        if (!copy($file, $bak) || hash_file('sha256', $bak) !== hash('sha256', $html)) {
            fwrite(STDERR, "Cópia de segurança falhou; nada foi alterado.\n");
            exit(1);
        }
        @chmod($bak, 0644);
        $tmp = $file . '.renovacoes-tmp';
        if (file_put_contents($tmp, $new) === false || !@chmod($tmp, fileperms($file) & 0777) || !rename($tmp, $file)) {
            @unlink($tmp);
            fwrite(STDERR, "Falha ao gravar; o index.html original foi mantido.\n");
            exit(1);
        }
        echo "Gravado. Cópia de segurança: $bak\n";
        break;

    case 'htaccess':
        // htaccess verificar <pasta-do-painel> | aplicar <arquivo .htaccess> <pasta-do-painel> | remover <arquivo .htaccess>
        $mode = $argv[2] ?? '';
        if ($mode === 'verificar') {
            $painel = rtrim((string)realpath($argv[3] ?? ''), '/');
            if ($painel === '' || !is_dir($painel)) {
                echo "Uso: htaccess verificar /home1/gust1922/gmaxcanais.com.br/painel\n";
                exit(1);
            }
            $dir = $painel;
            $found = 0;
            $problem = false;
            for ($i = 0; $i < 4 && $dir !== '/' && $dir !== ''; $i++, $dir = dirname($dir)) {
                $f = $dir . '/.htaccess';
                if (!is_file($f)) {
                    continue;
                }
                $found++;
                $rel = ltrim(substr($painel, strlen($dir)), '/');
                $r = \GmaxRenov\HtaccessPatch::analyze((string)file_get_contents($f), $rel);
                echo "== $f\n   formato: {$r['formato']}\n   bloqueia: " . ($r['bloqueia'] ? implode(', ', $r['bloqueia']) : 'nenhum arquivo do módulo') . "\n";
                if ($r['motivo'] !== '') {
                    echo "   {$r['motivo']}\n";
                }
                if ($r['plano'] !== null) {
                    $problem = true;
                    echo "   Patch proposto (só acrescenta):\n" . \GmaxRenov\PanelMenuPatch::diff((string)file_get_contents($f), $r['plano']) . "\n";
                    echo "   Para aplicar: php " . $argv[0] . " htaccess aplicar $f $painel\n";
                }
                if ($r['formato'] === 'desconhecido') {
                    $problem = true;
                }
            }
            echo $found ? '' : "Nenhum .htaccess encontrado do painel até 3 pastas acima.\n";
            echo "Lembrete: confirme também pela web com: php " . $argv[0] . " verificar-http https://gmaxcanais.com.br/painel/\n";
            exit($problem ? 2 : 0);
        }
        if (!in_array($mode, ['aplicar', 'remover'], true) || !is_file($argv[3] ?? '') || basename($argv[3]) !== '.htaccess') {
            echo "Uso: htaccess verificar <pasta-do-painel> | aplicar <caminho/.htaccess> <pasta-do-painel> | remover <caminho/.htaccess>\n";
            exit(1);
        }
        $file = $argv[3];
        if (is_link($file)) {
            echo "O .htaccess é um link simbólico; nada foi alterado. Edite o arquivo de destino manualmente (docs/HTACCESS.md).\n";
            exit(2);
        }
        $orig = (string)file_get_contents($file);
        if ($mode === 'aplicar') {
            $painel = rtrim((string)realpath($argv[4] ?? ''), '/');
            $base = rtrim((string)realpath(dirname($file)), '/');
            if ($painel === '' || strpos($painel . '/', $base . '/') !== 0) {
                echo "A pasta do painel precisa estar dentro da pasta deste .htaccess.\n";
                exit(1);
            }
            $r = \GmaxRenov\HtaccessPatch::analyze($orig, ltrim(substr($painel, strlen($base)), '/'));
            if ($r['plano'] === null) {
                echo ($r['motivo'] ?: 'Nada a aplicar.') . "\n";
                exit($r['formato'] === 'desconhecido' ? 2 : 0);
            }
            $new = $r['plano'];
        } else {
            $new = \GmaxRenov\HtaccessPatch::revert($orig);
            if ($new === $orig) {
                echo "Nada a remover.\n";
                break;
            }
        }
        echo \GmaxRenov\PanelMenuPatch::diff($orig, $new), "\n";
        $bak = $file . '.antes-renovacoes-' . date('Ymd-His');
        if (!copy($file, $bak) || hash_file('sha256', $bak) !== hash('sha256', $orig)) {
            fwrite(STDERR, "Cópia de segurança falhou; nada foi alterado.\n");
            exit(1);
        }
        @chmod($bak, 0644);
        $tmp = $file . '.renovacoes-tmp';
        if (file_put_contents($tmp, $new) === false || !@chmod($tmp, fileperms($file) & 0777) || !rename($tmp, $file)) {
            @unlink($tmp);
            fwrite(STDERR, "Falha ao gravar; o .htaccess original foi mantido.\n");
            exit(1);
        }
        echo "Gravado. Cópia de segurança: $bak\nTeste agora: php " . $argv[0] . " verificar-http https://gmaxcanais.com.br/painel/\n";
        break;

    case 'verificar-http':
        // Confere pela web: módulo responde (cabeçalho X-GMAX-Renovacoes), exige sessão, webhook exige assinatura,
        // api.php continua funcionando e um PHP qualquer continua bloqueado.
        $base = rtrim($argv[2] ?? '', '/') . '/';
        if (!preg_match('~^https?://~', $base)) {
            echo "Uso: verificar-http https://gmaxcanais.com.br/painel/\n";
            exit(1);
        }
        $req = static function (string $method, string $url, ?string $body = null, array $headers = []): array {
            $ch = curl_init($url);
            $hdr = [];
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body,
                CURLOPT_HEADERFUNCTION => static function ($c, $l) use (&$hdr) { $hdr[] = $l; return strlen($l); }]);
            $b = (string)curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $mod = (bool)preg_grep('~^X-GMAX-Renovacoes:\s*1~i', $hdr);
            $loc = '';
            foreach ($hdr as $l) {
                if (stripos($l, 'Location:') === 0) {
                    $loc = trim(substr($l, 9));
                }
            }
            return ['code' => $code, 'mod' => $mod, 'body' => $b, 'loc' => $loc];
        };
        $checks = [
            ['Página sem sessão → redireciona ao login', 'GET', 'renovacoes.php', null, [], static fn($r) => $r['mod'] && in_array($r['code'], [302, 303], true)],
            ['API sem sessão → 401', 'GET', 'renovacoes-api.php?acao=painel', null, [], static fn($r) => $r['mod'] && $r['code'] === 401],
            ['Webhook GET sem token → 403 do módulo', 'GET', 'renovacoes-webhook.php', null, [], static fn($r) => $r['mod'] && $r['code'] === 403 && trim($r['body']) === 'forbidden'],
            ['Webhook POST sem assinatura → 401', 'POST', 'renovacoes-webhook.php', '{}', ['Content-Type: application/json'], static fn($r) => $r['mod'] && $r['code'] === 401],
            ['Webhook POST assinatura falsa → 401', 'POST', 'renovacoes-webhook.php', '{}', ['Content-Type: application/json', 'X-Hub-Signature-256: sha256=' . str_repeat('0', 64)], static fn($r) => $r['mod'] && $r['code'] === 401],
            ['Painel api.php continua respondendo', 'GET', 'api.php?action=status', null, [], static fn($r) => $r['code'] === 200 && strpos($r['body'], 'authenticated') !== false],
            ['PHP não autorizado continua bloqueado', 'GET', 'gr-verificacao-' . bin2hex(random_bytes(4)) . '.php', null, [], static fn($r) => !$r['mod'] && in_array($r['code'], [403, 404], true)],
        ];
        $fail = 0;
        foreach ($checks as [$label, $m, $path, $body, $h, $ok]) {
            $r = $req($m, $base . $path, $body, $h);
            $pass = $ok($r);
            $fail += $pass ? 0 : 1;
            $why = $pass ? '' : ($r['code'] === 403 && !$r['mod'] ? ' ← bloqueado pelo servidor (.htaccess?)' : '');
            printf("%s %-45s HTTP %d%s%s\n", $pass ? 'OK  ' : 'FALHA', $label, $r['code'], $r['mod'] ? ' (módulo)' : '', $why);
        }
        echo $fail ? "Há $fail falha(s). Veja docs/HTACCESS.md.\n" : "Tudo conforme.\n";
        exit($fail ? 2 : 0);

    default:
        echo "Comandos: diagnostico | migrar | integridade [arq] | integridade-comparar a b | menu verificar|aplicar|remover index.html | htaccess verificar|aplicar|remover | verificar-http URL | backup [arq] | status\n";
        exit(1);
}

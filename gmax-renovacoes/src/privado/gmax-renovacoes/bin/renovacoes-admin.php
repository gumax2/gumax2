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

$cmd = $argv[1] ?? 'diagnostico';
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
        $fh = fopen($file, 'w');
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
        file_put_contents($file, json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        chmod($file, 0600);
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
        $r = \GmaxRenov\Integrity::compare(json_decode(file_get_contents($argv[2]), true), json_decode(file_get_contents($argv[3]), true));
        echo implode("\n", $r['linhas']), "\n", $r['iguais'] ? "RESULTADO: dados do painel preservados.\n" : "RESULTADO: HÁ DIFERENÇAS — não prossiga; veja BACKUP_E_RECUPERACAO.md.\n";
        exit($r['iguais'] ? 0 : 3);

    case 'menu':
        // php renovacoes-admin.php menu verificar|aplicar|remover /caminho/public_html/painel/index.html
        $mode = $argv[2] ?? 'verificar';
        $file = $argv[3] ?? '';
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
        copy($file, $bak);
        file_put_contents($file, $new);
        echo "Gravado. Cópia de segurança: $bak\n";
        break;

    default:
        echo "Comandos: diagnostico | migrar | integridade [arq] | integridade-comparar a b | menu verificar|aplicar|remover index.html | backup [arq] | status\n";
        exit(1);
}

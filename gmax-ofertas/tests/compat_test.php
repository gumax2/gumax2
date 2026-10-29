<?php
declare(strict_types=1);
/*
 * Compatibilidade com os arquivos ATUAIS da HostGator (cópia de 29/10/2026) e conflitos
 * entre o publicador antigo e a fila 3.0. Sem rede: os processos rodam com allow_url_fopen=0,
 * então qualquer "envio" falha antes de sair da máquina (vira estado incerto).
 * Execução: php tests/compat_test.php
 */
require __DIR__ . '/harness.php';
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';

use Gmax\{Catalog, Db, Migrator, Queue, Settings};

$app = t_app();
$secrets = t_sandbox() . '/gmax-secrets';
$env = ['GMAX_SECRETS_DIR' => $secrets, 'GMAX_TESTING' => '1', 'PATH' => getenv('PATH')];
$run = static function (string $script) use ($env, $app): array {
    $p = proc_open([PHP_BINARY, '-d', 'allow_url_fopen=0', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $app, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
};
/** Cópia do publicador com o horário atual liberado (o original só roda em 6 horários fixos). */
$publisher = static function (string $source, string $name) use ($app): string {
    $now = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('H:i');
    $code = t_isolate((string)file_get_contents($source), t_sandbox());
    $code = str_replace("const GMAX_HORARIOS = ['08:00',", "const GMAX_HORARIOS = ['$now', '08:00',", $code);
    file_put_contents("$app/$name", $code);
    return $name;
};
$activate = static function () use ($secrets): void {
    file_put_contents("$secrets/gmax-publicacao-privada.php", "<?php return ['enabled'=>true,'chat_id'=>'-1004443626419','authorization'=>'AUTORIZO_AGENDAMENTO_PRIVADO'];");
    file_put_contents("$secrets/telegram.php", "<?php return ['chat_id'=>'-1004443626419','token'=>'123456789:TOKEN_FALSO_DE_TESTE_SEM_VALIDADE'];");
};
$dispatchCount = static fn(PDO $pdo, string $item) => (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax_dispatches WHERE item_id=?', [$item]);
$PROD = T_ROOT . '/referencia/producao-20261029/gmax-publicador-cli.php';
$HARD = T_ROOT . '/src/opcional/gmax-publicador-cli.php';

echo "\n== Arquivos atuais da HostGator\n";
test('Arquivos de produção carregam com o pacote 3.0 (mesmas funções db/h/valid/importFile/message/slot)', function () {
    foreach (['db', 'h', 'valid', 'importFile', 'message', 'slot'] as $fn) {
        ok(function_exists($fn), "função $fn");
    }
    eq('8.3', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, 'PHP de teste igual ao da HostGator');
});
test('slot() de produção continua só em prévia no cron (send=false) e exige telegram.php + env para enviar', function () use ($run) {
    t_seed();
    [$code, $out] = $run('cron.php');
    eq(0, $code, $out);
    $src = (string)file_get_contents(T_ROOT . '/referencia/producao-20261029/cron.php');
    ok(preg_match('/slot\(db\(\),\s*false\)/', $src) === 1, 'cron.php com false');
    $app = (string)file_get_contents(T_ROOT . '/referencia/producao-20261029/app.php');
    ok(str_contains($app, "getenv('GMAX_TELEGRAM_ENABLED')!=='true'"), 'trava por variável de ambiente mantida');
});

echo "\n== Conflito: publicador instalado × revisão 2.2 × fila 3.0\n";
test('RISCO CONFIRMADO: publicador instalado tenta de novo o MESMO item após envio incerto', function () use ($run, $publisher, $activate, $dispatchCount, $PROD) {
    $pdo = t_seed();
    $activate();
    $script = $publisher($PROD, 'pub-producao.php');
    $item = (string)Db::value($pdo, "SELECT item_id FROM offers WHERE status='pendente' ORDER BY created_at,item_id LIMIT 1");
    [, $out] = $run($script);
    ok(str_contains($out, 'envio_incerto_nao_repetir'), "1ª janela incerta (sem rede): $out");
    Db::run($pdo, "UPDATE gmax_dispatches SET slot='2000-01-01 08:00' WHERE item_id=? AND status='uncertain'", [$item]); // simula a janela seguinte
    [, $out] = $run($script);
    eq(2, $dispatchCount($pdo, $item), 'a versão instalada tentou reenviar o mesmo produto');
    unlink(t_sandbox() . '/gmax-secrets/gmax-publicacao-privada.php');
});
test('Revisão 2.2 (opcional/): após envio incerto a fila PAUSA e não tenta de novo', function () use ($run, $publisher, $activate, $dispatchCount, $HARD) {
    $pdo = t_seed();
    $activate();
    $script = $publisher($HARD, 'pub-revisao.php');
    $item = (string)Db::value($pdo, "SELECT item_id FROM offers WHERE status='pendente' ORDER BY created_at,item_id LIMIT 1");
    [, $out] = $run($script);
    ok(str_contains($out, 'envio_incerto_nao_repetir'), $out);
    Db::run($pdo, "UPDATE gmax_dispatches SET slot='2000-01-01 08:00' WHERE item_id=? AND status='uncertain'", [$item]);
    [$code, $out] = $run($script);
    eq(2, $code, $out);
    ok(str_contains($out, 'pausa_global_reconciliacao'), $out);
    eq(1, $dispatchCount($pdo, $item), 'nenhuma nova tentativa');
    unlink(t_sandbox() . '/gmax-secrets/gmax-publicacao-privada.php');
});
test('Revisão 2.2 respeita a pausa do painel 3.0 (pausado por padrão após a migração)', function () use ($run, $publisher, $activate, $HARD) {
    $pdo = t_seed();
    Migrator::up($pdo, 'teste');
    $activate();
    [$code, $out] = $run($publisher($HARD, 'pub-revisao.php'));
    eq(1, $code, $out);
    ok(str_contains($out, 'pausa_painel_3_0'), $out);
    eq(1, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax_dispatches'), 'nenhuma janela nova');
    unlink(t_sandbox() . '/gmax-secrets/gmax-publicacao-privada.php');
});
test('Revisão 2.2 não envia item já tratado pela fila 3.0', function () use ($run, $publisher, $activate, $HARD) {
    $pdo = t_seed();
    Migrator::up($pdo, 'teste');
    Settings::resume($pdo, 'teste');
    $item = (string)Db::value($pdo, "SELECT item_id FROM offers WHERE status='pendente' ORDER BY created_at,item_id LIMIT 1");
    $cid = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE external_id=?", [$item]);
    $pdo->exec("INSERT INTO gmax3_pub_queue(catalog_id,channel,state,scheduled_for) VALUES($cid,'telegram_privado','confirmado','2026-10-29 10:00:00')");
    $activate();
    [$code, $out] = $run($publisher($HARD, 'pub-revisao.php'));
    ok(str_contains($out, 'item_tratado_pela_fila_3_0_ignorado'), $out);
    eq(0, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax_dispatches WHERE item_id=?', [$item]));
    ok(!str_contains($out, '"event":"bloqueado"') || str_contains($out, 'envio_incerto'), 'não trava: segue para a próxima oferta');
    unlink(t_sandbox() . '/gmax-secrets/gmax-publicacao-privada.php');
});
test('Produto importado no 3.0 só com o link afiliado é reconhecido pelos dois publicadores', function () use ($run, $publisher, $activate, $HARD) {
    $pdo = t_seed();
    $item = (string)Db::value($pdo, "SELECT item_id FROM offers WHERE status='pendente' ORDER BY created_at,item_id LIMIT 1");
    $link = (string)Db::value($pdo, 'SELECT affiliate_url FROM offers WHERE item_id=?', [$item]);
    // Catálogo criado antes da migração copiar o legado: só link, sem ID.
    Migrator::up($pdo, 'teste');
    $cid = (int)Db::value($pdo, 'SELECT id FROM gmax3_catalog WHERE external_id=?', [$item]);
    Db::run($pdo, 'UPDATE gmax3_catalog SET external_id=NULL, codigo=NULL WHERE id=?', [$cid]);
    $r = Catalog::get($pdo, $cid);
    eq($item, Gmax\Rules::legacyShopeeId($pdo, $r), '3.0 encontra o item antigo pelo link');
    ok(str_contains((string)Gmax\Rules::duplicate($pdo, $r), 'pendente'), '3.0 bloqueia item ainda pendente no antigo');
    $pdo->exec("INSERT INTO gmax3_pub_queue(catalog_id,channel,state,scheduled_for) VALUES($cid,'telegram_privado','confirmado','2026-10-29 10:00:00')");
    Settings::resume($pdo, 'teste');
    $activate();
    [, $out] = $run($publisher($HARD, 'pub-revisao.php'));
    ok(str_contains($out, 'item_tratado_pela_fila_3_0_ignorado'), 'revisão 2.2 reconhece pelo link: ' . $out);
    eq(0, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax_dispatches WHERE item_id=?', [$item]), 'não reenvia');
    unlink(t_sandbox() . '/gmax-secrets/gmax-publicacao-privada.php');
    Db::run($pdo, 'UPDATE gmax3_catalog SET external_id=NULL WHERE id=?', [$cid]);
    Catalog::syncLegacy($pdo, 'teste');
    eq($item, Db::value($pdo, 'SELECT external_id FROM gmax3_catalog WHERE id=?', [$cid]), 'sincronização completa o ID ausente');
});
test('Fila 3.0 bloqueia quando o publicador antigo está ativado ou com envio incerto', function () use ($activate) {
    $pdo = t_seed();
    Migrator::up($pdo, 'teste');
    $activate();
    ok(str_contains(implode(' ', Queue::gates($pdo)), 'Publicador antigo está ativado'), 'ativação antiga bloqueia 3.0');
    unlink(t_sandbox() . '/gmax-secrets/gmax-publicacao-privada.php');
    $pdo->exec("INSERT INTO gmax_dispatches(slot,item_id,status) VALUES('2026-10-29 12:00','22812345602','uncertain')");
    ok(str_contains(implode(' ', Queue::unreconciled($pdo)), 'uncertain'), 'incerto do antigo bloqueia 3.0');
});
test('Sem arquivo de ativação, ambas as versões do publicador apenas simulam', function () use ($run, $publisher, $PROD, $HARD) {
    $pdo = t_seed();
    foreach ([$PROD => 'pub-producao.php', $HARD => 'pub-revisao.php'] as $src => $name) {
        [$code, $out] = $run($publisher($src, $name));
        eq(0, $code, $out);
        ok(str_contains($out, 'simulacao_sem_envio'), $out);
    }
    eq(1, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax_dispatches'), 'nenhum registro novo');
});

echo "\n== Diagnóstico de esquema\n";
test('Diagnóstico exporta só o esquema: nenhum título, link, preço ou hash de senha; banco intacto', function () use ($run) {
    $pdo = t_seed();
    Migrator::up($pdo, 'teste');
    $db = t_app() . '/storage/offers.sqlite';
    $before = hash_file('sha256', $db);
    [$code, $out] = $run('gmax-diagnostico-esquema.php');
    eq(0, $code, $out);
    foreach (['TABELA offers', 'TABELA gmax_ml_offers', 'TABELA gmax_dispatches', 'TABELA users', 'status=pendente', 'item_id'] as $must) {
        ok(str_contains($out, $must), "contém $must");
    }
    $secretsLike = ['Teste A', 's.shopee.com.br', 'meli.la', '1uPYV76', 'Parafusadeira', '49.90', '$2y$', '10431'];
    foreach ($secretsLike as $bad) {
        ok(!str_contains($out, $bad), "não vaza: $bad");
    }
    eq($before, hash_file('sha256', $db), 'banco não alterado');
});

$pass = count(array_filter($GLOBALS['t_results'], fn($r) => $r[1]));
$total = count($GLOBALS['t_results']);
echo "\nResultado compatibilidade: $pass/$total testes aprovados\n";
file_put_contents(__DIR__ . '/../docs/resultado-testes-compat.json', json_encode([
    'executado_em' => date('c'), 'php' => PHP_VERSION, 'aprovados' => $pass, 'total' => $total,
    'testes' => array_map(fn($r) => ['nome' => $r[0], 'ok' => $r[1], 'erro' => $r[2], 'segundos' => round($r[3], 3)], $GLOBALS['t_results']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($pass === $total ? 0 : 1);

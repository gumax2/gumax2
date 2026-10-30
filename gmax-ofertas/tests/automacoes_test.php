<?php
declare(strict_types=1);
/*
 * Central de Automações (3.1). Conectores falsos, sem rede, sem Telegram.
 * Execução: php -d allow_url_fopen=0 tests/automacoes_test.php
 */
require __DIR__ . '/harness.php';
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';
require __DIR__ . '/fakes.php';

use Gmax\Automation\{Alerts, Jobs, Runner, Schedule};
use Gmax\Connectors\{MercadoLivre, Registry, ShopeeAffiliate};
use Gmax\{Catalog, Config, Db, Message, Migrator, Queue, RateLimiter, Settings, Time};

$SP = new DateTimeZone('America/Sao_Paulo');
$at = static fn(string $local) => (new DateTimeImmutable($local, $SP))->setTimezone(new DateTimeZone('UTC'));
$ready = static function (): PDO {
    Registry::resetOverrides();
    Time::freeze(null);
    $pdo = t_seed('hostgator');
    Migrator::up($pdo, 'teste');
    Settings::set($pdo, 'api_intervalo_ms', '0', 'teste');
    return $pdo;
};
$mlItem = static fn(string $id, string $title, ?int $promo, ?int $orig = null, string $avail = 'disponivel') => [
    'marketplace' => 'mercadolivre', 'external_id' => "item:$id", 'title' => $title, 'price_promo_cents' => $promo, 'price_original_cents' => $orig,
    'availability' => $avail, 'original_url' => "https://produto.mercadolivre.com.br/" . str_replace('MLB', 'MLB-', $id) . '-x-_JM',
];
/** Produto Shopee pronto para publicar (aprovado, preço confirmado por API, link oficial). */
$approvedShopee = static function (PDO $pdo, string $item, int $price = 4990, ?int $orig = 9990): int {
    $id = (int)Db::value($pdo, 'SELECT id FROM gmax3_catalog WHERE external_id=?', [$item]);
    Db::run($pdo, "UPDATE gmax3_catalog SET payment_condition='normal', price_promo_cents=?, price_original_cents=?, availability='disponivel', sales_min=5000, rating=4.8, rating_count=900, seller_reputation='Loja oficial' WHERE id=?", [$price, $orig, $id]);
    Catalog::markVerifiedByApi($pdo, $id, 'shopee', 'teste');
    eq([], Catalog::setStatus($pdo, $id, 'aprovado', 'teste'));
    return $id;
};

echo "\n== Migração 002 e configuração segura\n";
test('Migração 002: tarefas criadas inativas, automações pausadas, aprovação automática desligada; reversível', function () use ($ready) {
    $pdo = $ready();
    eq(6, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_jobs')->fetchColumn());
    eq(0, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_jobs WHERE enabled=1')->fetchColumn(), 'todas inativas');
    eq('1', Settings::get($pdo, 'automacoes_pausadas'));
    eq('0', Settings::get($pdo, 'aprovacao_automatica'));
    eq('1', Settings::get($pdo, 'publicacao_pausada'), 'envios continuam pausados');
    $catalog = $pdo->query('SELECT COUNT(*) FROM gmax3_catalog')->fetchColumn();
    eq('002', Migrator::down($pdo, 'teste')['version']);
    ok(!Db::tableExists($pdo, 'gmax3_jobs') && Db::tableExists($pdo, 'gmax3_catalog'), 'remove só as tabelas da 002');
    eq($catalog, $pdo->query('SELECT COUNT(*) FROM gmax3_catalog')->fetchColumn(), 'catálogo intacto');
    Migrator::up($pdo, 'teste');
    eq(6, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_jobs')->fetchColumn(), 'reaplicável');
});

echo "\n== Calendário, idempotência e concorrência\n";
test('Horários: vence às 07:05, perdido depois de 2 h, respeita dia da semana', function () use ($ready, $at) {
    $pdo = $ready();
    $job = ['job' => 'descoberta', 'times' => '07:00', 'weekdays' => '1,2,3,4,5,6,7'];
    eq(['2026-11-02 07:00'], Schedule::dueSlots($pdo, $job, $at('2026-11-02 07:05')));
    eq([], Schedule::dueSlots($pdo, $job, $at('2026-11-02 06:59')));
    eq([], Schedule::dueSlots($pdo, $job, $at('2026-11-02 09:30')), 'perdido');
    $monday = ['job' => 'analise_semanal', 'times' => '07:30', 'weekdays' => '1'];
    eq(['2026-11-02 07:30'], Schedule::dueSlots($pdo, $monday, $at('2026-11-02 08:00')), 'segunda-feira 02/11/2026');
    eq([], Schedule::dueSlots($pdo, $monday, $at('2026-11-03 08:00')), 'terça não');
    eq('2026-11-09 07:30', Schedule::next($monday, $at('2026-11-02 08:00'))->format('Y-m-d H:i'));
    throws(fn() => Schedule::validate('25:00', '1'), 'Horário inválido');
    throws(fn() => Schedule::validate('07:00', '8'), 'Dias');
});
test('Pausa geral: nada executa; ativa: executa uma vez por horário (idempotente)', function () use ($ready, $at) {
    $pdo = $ready();
    Runner::setJob($pdo, 'saude_catalogo', true, '06:30', '1,2,3,4,5,6,7', 't');
    Time::freeze($at('2026-11-02 06:40'));
    eq('automacoes_pausadas', Runner::runDue($pdo)['resultado']);
    eq(0, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_job_runs')->fetchColumn());
    Settings::set($pdo, 'automacoes_pausadas', '0', 't');
    $r = Runner::runDue($pdo);
    eq('concluido', $r['execucoes'][0]['resultado']);
    eq([], Runner::runDue($pdo)['execucoes'], 'segunda chamada no mesmo horário não repete');
    Time::freeze($at('2026-11-02 06:50'));
    eq([], Runner::runDue($pdo)['execucoes'], 'Cron repetido 10 min depois também não');
    eq(1, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_job_runs')->fetchColumn());
    Time::freeze(null);
});
test('Concorrência: com a trava das automações ocupada, nada executa', function () use ($ready) {
    $pdo = $ready();
    $proc = proc_open([PHP_BINARY, '-r', 'flock($f=fopen($argv[1],"c"),LOCK_EX); echo "ok\n"; fflush(STDOUT); sleep(3);', Runner::lockFile()], [1 => ['pipe', 'w']], $pipes);
    fgets($pipes[1]);
    eq('ja_em_execucao', Runner::runManual($pdo, 'saude_catalogo', 't')['resultado']);
    proc_terminate($proc);
    proc_close($proc);
    eq(0, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_job_runs')->fetchColumn());
});
test('Execução interrompida pelo servidor é encerrada como falha na próxima chamada', function () use ($ready) {
    $pdo = $ready();
    $pdo->exec("INSERT INTO gmax3_job_runs(job,slot_key,trigger,status,started_at) VALUES('descoberta','2026-01-01 07:00','agendado','executando','2026-01-01 10:00:00')");
    Runner::runManual($pdo, 'saude_catalogo', 't');
    eq('falhou', Db::value($pdo, "SELECT status FROM gmax3_job_runs WHERE slot_key='2026-01-01 07:00'"));
});

echo "\n== Descoberta (fontes oficiais, sem dados inventados)\n";
test('Descoberta: insere novos como rascunho com origem e data; ignora existentes, indisponíveis e incompletos', function () use ($ready, $mlItem) {
    $pdo = $ready();
    Settings::set($pdo, 'termos_descoberta_mercadolivre', "air fryer\nfone bluetooth", 't');
    $existing = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE codigo='ML-001'");
    Db::run($pdo, "UPDATE gmax3_catalog SET external_id='item:MLB1000000001' WHERE id=?", [$existing]);
    $fake = new FakeMarketConnector('mercadolivre', ['ok' => true, 'error' => null, 'items' => [
        $mlItem('MLB2000000001', 'Air Fryer Teste 4L', 29990, 45990),
        $mlItem('MLB1000000001', 'Já cadastrado', 10431),
        $mlItem('MLB2000000002', 'Sem estoque', 5000, null, 'indisponivel'),
        ['marketplace' => 'mercadolivre', 'external_id' => 'item:MLB2000000003', 'title' => ''],
        $mlItem('MLB2000000004', 'Fone sem preço informado', null),
    ]]);
    Registry::override('mercadolivre', $fake);
    Registry::override('shopee', new FakeMarketConnector('shopee', ok: false));
    $r = Runner::runManual($pdo, 'descoberta', 't');
    eq(2, $r['created'], 'novos: air fryer + fone sem preço (como rascunho)');
    eq(2, $fake->searches, 'um por termo');
    $p = Db::one($pdo, "SELECT * FROM gmax3_catalog WHERE external_id='item:MLB2000000001'");
    eq('rascunho', $p['validation_status']);
    eq(45990, (int)$p['price_original_cents']);
    eq('api:mercadolivre', $p['price_verified_by'], 'origem registrada');
    ok($p['price_verified_at'] !== null, 'data registrada');
    ok(str_contains((string)$p['notes'], 'air fryer'), 'termo registrado');
    $np = Db::one($pdo, "SELECT * FROM gmax3_catalog WHERE external_id='item:MLB2000000004'");
    eq(null, $np['price_promo_cents'], 'preço não inventado');
    eq(null, $np['price_verified_at'], 'sem preço, nada é marcado como confirmado');
    eq(null, Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE external_id='item:MLB2000000002'"), 'indisponível não entra');
    ok(Db::value($pdo, "SELECT COUNT(*) FROM gmax3_alerts WHERE kind='sem_link' AND resolved_at IS NULL") >= 2, 'alerta de link para produtos ML');
    ok((int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_alerts WHERE kind='integracao'") === 0, 'shopee sem termos: nem tenta');
    $r2 = Runner::runManual($pdo, 'descoberta', 't');
    eq(0, $r2['created'], 'segunda descoberta não duplica');
    eq(1, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_catalog WHERE external_id='item:MLB2000000001'"));
});
test('Shopee: link oficial da API é aceito; link fora do padrão é descartado; integração ausente gera alerta', function () use ($ready) {
    $pdo = $ready();
    Settings::set($pdo, 'termos_descoberta_shopee', 'garrafa', 't');
    Settings::set($pdo, 'termos_descoberta_mercadolivre', 'garrafa', 't');
    Registry::override('mercadolivre', new FakeMarketConnector('mercadolivre', ok: false));
    Registry::override('shopee', new FakeMarketConnector('shopee', ['ok' => true, 'error' => null, 'items' => [
        ['marketplace' => 'shopee', 'external_id' => '33300000001', 'codigo' => '33300000001', 'title' => 'Garrafa A', 'price_promo_cents' => 3990, 'affiliate_url' => 'https://s.shopee.com.br/ApiOk01', 'availability' => 'disponivel'],
        ['marketplace' => 'shopee', 'external_id' => '33300000002', 'codigo' => '33300000002', 'title' => 'Garrafa B', 'price_promo_cents' => 2990, 'affiliate_url' => 'https://encurtador.com/xyz', 'availability' => 'disponivel'],
    ]]));
    Runner::runManual($pdo, 'descoberta', 't');
    eq('https://s.shopee.com.br/ApiOk01', Db::value($pdo, "SELECT affiliate_url FROM gmax3_catalog WHERE external_id='33300000001'"));
    eq(null, Db::value($pdo, "SELECT affiliate_url FROM gmax3_catalog WHERE external_id='33300000002'"), 'link não oficial descartado');
    ok((int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_alerts WHERE kind='integracao' AND message LIKE 'Mercado Livre%'") === 1, 'alerta de integração ML');
});
test('Falha da API na busca: execução parcial, erro registrado, nada inventado', function () use ($ready) {
    $pdo = $ready();
    Settings::set($pdo, 'termos_descoberta_mercadolivre', 'teste', 't');
    Registry::override('mercadolivre', new FakeMarketConnector('mercadolivre', new RuntimeException('timeout')));
    Registry::override('shopee', new FakeMarketConnector('shopee', ok: false));
    $n = (int)$pdo->query('SELECT COUNT(*) FROM gmax3_catalog')->fetchColumn();
    $r = Runner::runManual($pdo, 'descoberta', 't');
    eq('parcial', $r['resultado']);
    eq(1, $r['errors']);
    eq($n, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_catalog')->fetchColumn());
});

echo "\n== Revisão de preços antes de publicar\n";
test('Preço igual confirmado pela API: renova a confirmação e mantém aprovação', function () use ($ready, $approvedShopee) {
    $pdo = $ready();
    $id = $approvedShopee($pdo, '22812345602');
    Db::run($pdo, "UPDATE gmax3_catalog SET price_verified_at='2026-01-01 00:00:00' WHERE id=?", [$id]);
    Registry::override('shopee', new FakeMarketConnector('shopee', fetchMap: ['22812345602' => ['ok' => true, 'error' => null, 'data' => ['price_promo_cents' => 4990, 'price_original_cents' => 9990]]]));
    Registry::override('mercadolivre', new FakeMarketConnector('mercadolivre', ok: false));
    Runner::runManual($pdo, 'revisao_precos', 't');
    $p = Catalog::get($pdo, $id);
    eq('aprovado', $p['validation_status']);
    ok(Time::hoursSince($p['price_verified_at']) < 1, 'confirmação renovada');
    ok((int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_price_history WHERE catalog_id=? AND verified=1 AND source='api_shopee'", [$id]) >= 2, 'histórico verificado');
});
test('Preço mudou: atualiza Por e retira aprovação; "De" só é limpo por fonte que informa preço original', function () use ($ready, $approvedShopee) {
    $pdo = $ready();
    $id = $approvedShopee($pdo, '22812345602');
    Registry::override('shopee', new FakeMarketConnector('shopee', fetchMap: ['22812345602' => ['ok' => true, 'error' => null, 'data' => ['price_promo_cents' => 5990]]]));
    Runner::runManual($pdo, 'revisao_precos', 't');
    $p = Catalog::get($pdo, $id);
    eq(5990, (int)$p['price_promo_cents']);
    eq(9990, (int)$p['price_original_cents'], 'Shopee não informa "De": o valor cadastrado é mantido');
    eq('aguardando_validacao', $p['validation_status']);
    Registry::override('shopee', new FakeMarketConnector('shopee', fetchMap: ['22812345602' => ['ok' => true, 'error' => null, 'data' => ['price_promo_cents' => 5990, 'reports_original' => true]]]));
    Runner::runManual($pdo, 'revisao_precos', 't');
    eq(null, Catalog::get($pdo, $id)['price_original_cents'], 'fonte que informa "De" e não informou: sem desconto vigente');
});
test('Mesmo preço da API não retira aprovação de "De" cadastrado manualmente (revisão repetida)', function () use ($ready, $approvedShopee) {
    $pdo = $ready();
    $id = $approvedShopee($pdo, '22812345602');
    Registry::override('shopee', new FakeMarketConnector('shopee', fetchMap: ['22812345602' => ['ok' => true, 'error' => null, 'data' => ['price_promo_cents' => 4990]]]));
    Runner::runManual($pdo, 'revisao_precos', 't');
    Runner::runManual($pdo, 'revisao_precos', 't');
    eq('aprovado', Catalog::get($pdo, $id)['validation_status']);
    eq(9990, (int)Catalog::get($pdo, $id)['price_original_cents']);
});
test('Edição concorrente durante a revisão não é carimbada como confirmada pela API', function () use ($ready, $approvedShopee) {
    $pdo = $ready();
    $id = $approvedShopee($pdo, '22812345602');
    Db::run($pdo, "UPDATE gmax3_catalog SET price_verified_at='2026-01-01 00:00:00' WHERE id=?", [$id]);
    Registry::override('shopee', new FakeMarketConnector('shopee', fetchMap: ['22812345602' => function () use ($pdo, $id) {
        Db::run($pdo, 'UPDATE gmax3_catalog SET price_promo_cents=1234 WHERE id=?', [$id]); // admin edita no meio da revisão
        return ['ok' => true, 'error' => null, 'data' => ['price_promo_cents' => 4990]];
    }]));
    Runner::runManual($pdo, 'revisao_precos', 't');
    $p = Catalog::get($pdo, $id);
    ok($p['price_verified_by'] !== 'api:shopee' || (int)$p['price_promo_cents'] === 4990, 'nunca confirma um preço que a API não devolveu');
    ok(Time::hoursSince($p['price_verified_at']) === null || (int)$p['price_promo_cents'] === 4990, 'sem carimbo indevido');
});
test('API falhou: dados não mudam, alerta aberto e publicação bloqueada pelo preço vencido', function () use ($ready, $approvedShopee) {
    $pdo = $ready();
    $id = $approvedShopee($pdo, '22812345602');
    Db::run($pdo, "UPDATE gmax3_catalog SET price_verified_at='2026-01-01 00:00:00' WHERE id=?", [$id]);
    Registry::override('shopee', new FakeMarketConnector('shopee', fetchMap: ['22812345602' => new RuntimeException('HTTP 500')]));
    $r = Runner::runManual($pdo, 'revisao_precos', 't');
    ok($r['errors'] >= 1, 'erro contabilizado');
    eq(4990, (int)Catalog::get($pdo, $id)['price_promo_cents']);
    eq(1, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_alerts WHERE kind='preco_nao_confirmado' AND catalog_id=?", [$id]));
    ok(str_contains(implode(' ', Gmax\Rules::blockers($pdo, Catalog::get($pdo, $id), 'telegram_privado')), 'reconfira'), 'bloqueado');
});
test('Anúncio pausado: indisponível, agendamento cancelado, alerta', function () use ($ready, $approvedShopee) {
    $pdo = $ready();
    Settings::set($pdo, 'publicador_v21_aposentado', '1', 't');
    $id = $approvedShopee($pdo, '22812345602');
    eq([], Queue::enqueue($pdo, $id, 'telegram_privado', null, 't'));
    Registry::override('shopee', new FakeMarketConnector('shopee', fetchMap: ['22812345602' => ['ok' => true, 'error' => null, 'data' => ['price_promo_cents' => 4990, 'price_original_cents' => 9990, 'availability' => 'indisponivel']]]));
    Runner::runManual($pdo, 'revisao_precos', 't');
    eq('indisponivel', Catalog::get($pdo, $id)['availability']);
    eq('aguardando_validacao', Catalog::get($pdo, $id)['validation_status'], 'perde a aprovação');
    eq('cancelado', Db::value($pdo, 'SELECT state FROM gmax3_pub_queue WHERE catalog_id=?', [$id]));
    eq(1, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_alerts WHERE kind='indisponivel' AND catalog_id=?", [$id]));
});

echo "\n== Seleção, aprovação e limite diário\n";
test('Seleção coloca as melhores na fila respeitando o limite diário (nada é enviado)', function () use ($ready, $approvedShopee) {
    $pdo = $ready();
    Settings::set($pdo, 'publicador_v21_aposentado', '1', 't');
    Settings::set($pdo, 'limite_diario_telegram_privado', '2', 't');
    $links = ['22812345602', '22812345603', '22812345604', '22812345605'];
    foreach ($links as $i => $item) {
        $approvedShopee($pdo, $item, 3000 + $i * 100, 9000);
    }
    $r = Runner::runManual($pdo, 'selecao', 't');
    eq(4, $r['updated']);
    $byDay = [];
    foreach ($pdo->query('SELECT scheduled_for FROM gmax3_pub_queue')->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $d = (new DateTimeImmutable($s, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d');
        $byDay[$d] = ($byDay[$d] ?? 0) + 1;
    }
    ok(max($byDay) <= 2, 'no máximo 2 por dia: ' . json_encode($byDay));
    eq(4, (int)$pdo->query("SELECT COUNT(*) FROM gmax3_pub_queue WHERE state='agendado'")->fetchColumn(), 'só agendado, nada enviado');
    eq(0, (int)Runner::runManual($pdo, 'selecao', 't')['updated'], 'repetir não duplica');
});
test('Aprovação: manual por padrão (alerta); automática só com autorização e preço confirmado por API', function () use ($ready) {
    $pdo = $ready();
    Settings::set($pdo, 'publicador_v21_aposentado', '1', 't');
    $id = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE external_id='22812345603'");
    Db::run($pdo, "UPDATE gmax3_catalog SET payment_condition='normal', price_promo_cents=3990, price_original_cents=8990, availability='disponivel', sales_min=5000, rating=4.8, rating_count=900, seller_reputation='Loja oficial' WHERE id=?", [$id]);
    Catalog::markVerifiedByApi($pdo, $id, 'shopee', 't');
    Runner::runManual($pdo, 'selecao', 't');
    eq('aguardando_validacao', Catalog::get($pdo, $id)['validation_status'], 'sem autorização não aprova');
    eq(1, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_alerts WHERE kind='aprovacao_pendente' AND catalog_id=?", [$id]));
    Catalog::confirmPrice($pdo, $id, 'humano'); // conferência manual não basta para aprovação AUTOMÁTICA
    Settings::set($pdo, 'aprovacao_automatica', '1', 'cli');
    Runner::runManual($pdo, 'selecao', 't');
    eq('aguardando_validacao', Catalog::get($pdo, $id)['validation_status'], 'automática exige confirmação por API');
    Catalog::markVerifiedByApi($pdo, $id, 'shopee', 't');
    $r = Runner::runManual($pdo, 'selecao', 't');
    eq(1, $r['approved']);
    eq('aprovado', Catalog::get($pdo, $id)['validation_status']);
    eq('agendado', Db::value($pdo, 'SELECT state FROM gmax3_pub_queue WHERE catalog_id=?', [$id]));
});
test('Mercado Livre não entra na fila do grupo privado (regra do programa) mesmo com nota alta', function () use ($ready) {
    $pdo = $ready();
    $id = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE codigo='ML-001'");
    Db::run($pdo, "UPDATE gmax3_catalog SET availability='disponivel', sales_min=50000, rating=4.9, rating_count=3000, seller_reputation='MercadoLíder Platinum', external_id='item:MLB1000000009' WHERE id=?", [$id]);
    Catalog::markVerifiedByApi($pdo, $id, 'mercadolivre', 't');
    Catalog::setStatus($pdo, $id, 'aprovado', 't');
    $r = Runner::runManual($pdo, 'selecao', 't');
    eq(0, (int)$pdo->query('SELECT COUNT(*) FROM gmax3_pub_queue')->fetchColumn());
    ok($r['blocked'] >= 1, 'bloqueado');
});

echo "\n== Vencidas, saúde do catálogo e alertas\n";
test('Vencidas: aprovada com preço antigo volta à validação; indisponível antiga é arquivada sem perder histórico', function () use ($ready, $approvedShopee) {
    $pdo = $ready();
    Settings::set($pdo, 'publicador_v21_aposentado', '1', 't');
    $a = $approvedShopee($pdo, '22812345602');
    Queue::enqueue($pdo, $a, 'telegram_privado', null, 't');
    Db::run($pdo, "UPDATE gmax3_catalog SET price_verified_at='2026-01-01 00:00:00' WHERE id=?", [$a]);
    $b = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE external_id='22812345603'");
    Db::run($pdo, "UPDATE gmax3_catalog SET availability='indisponivel', updated_at='2026-01-01 00:00:00' WHERE id=?", [$b]);
    $hist = (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_price_history WHERE catalog_id=?', [$b]);
    Runner::runManual($pdo, 'revisao_vencidas', 't');
    eq('aguardando_validacao', Catalog::get($pdo, $a)['validation_status']);
    eq('cancelado', Db::value($pdo, 'SELECT state FROM gmax3_pub_queue WHERE catalog_id=?', [$a]));
    eq('arquivado', Catalog::get($pdo, $b)['validation_status']);
    eq($hist, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_price_history WHERE catalog_id=?', [$b]), 'histórico preservado');
    ok(Catalog::get($pdo, $b) !== null, 'registro não apagado');
});
test('Saúde: duplicidade por nome, link fora do padrão, alerta fecha sozinho quando resolvido', function () use ($ready) {
    $pdo = $ready();
    $a = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE external_id='22812345602'");
    $b = (int)Db::value($pdo, "SELECT id FROM gmax3_catalog WHERE external_id='22812345603'");
    Db::run($pdo, "UPDATE gmax3_catalog SET title='Garrafa Térmica Igual' WHERE id IN (?, ?)", [$a, $b]);
    Db::run($pdo, "UPDATE gmax3_catalog SET affiliate_url='https://shopee.com.br/produto-comum', affiliate_norm='x1' WHERE id=?", [$a]);
    Runner::runManual($pdo, 'saude_catalogo', 't');
    eq(2, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_alerts WHERE kind='duplicado_possivel' AND resolved_at IS NULL"));
    eq(1, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_alerts WHERE kind='link_invalido' AND catalog_id=? AND resolved_at IS NULL", [$a]));
    Db::run($pdo, "UPDATE gmax3_catalog SET affiliate_url='https://s.shopee.com.br/Fix0001', affiliate_norm='s.shopee.com.br/Fix0001' WHERE id=?", [$a]);
    Runner::runManual($pdo, 'saude_catalogo', 't');
    eq(0, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_alerts WHERE kind='link_invalido' AND resolved_at IS NULL"), 'fechado automaticamente');
    eq(1, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_alerts WHERE kind='duplicado_possivel' AND catalog_id=?", [$a]), 'alerta não duplica a cada execução');
});

echo "\n== Mensagens, limites de API e conectores oficiais\n";
test('Modelos por loja/categoria; linhas sem dado confirmado são omitidas; validação de modelo', function () use ($ready) {
    $pdo = $ready();
    $pdo->prepare("INSERT INTO gmax3_templates(marketplace,category,body) VALUES('shopee','*',?),('shopee','Casa',?)")->execute(["SHOPEE {titulo}\nPor: {por}\nDe: {de}\n{link}", "CASA {titulo}\nPor: {por}\n{link}"]);
    $r = ['marketplace' => 'shopee', 'category' => 'Casa', 'title' => 'X', 'price_promo_cents' => 1000, 'affiliate_url' => 'https://s.shopee.com.br/A1b2c3'];
    ok(str_starts_with(Message::render($r, $pdo), 'CASA X'), 'categoria vence');
    $msg = Message::render(['category' => 'Cozinha'] + $r, $pdo);
    ok(str_starts_with($msg, 'SHOPEE X') && !str_contains($msg, 'De:'), 'sem "De" quando não há preço original');
    ok(str_contains($msg, 'sujeitos a alterações'), 'aviso sempre presente');
    ok(str_contains(Message::render(['marketplace' => 'mercadolivre', 'payment_condition' => 'cupom', 'payment_note' => 'GMAX10'] + $r, $pdo), 'preço com cupom (GMAX10)'), 'condição especial informada');
    ok(in_array('O modelo precisa conter {link}', Message::validateTemplate("{titulo} {por}"), true), 'link obrigatório');
    ok(in_array('Campo desconhecido: {comissao}', Message::validateTemplate("{titulo} {por} {link} {comissao}"), true), 'campo desconhecido');
});
test('Limite diário de requisições por conector e registro de falhas', function () use ($ready) {
    $pdo = $ready();
    Settings::set($pdo, 'api_limite_diario', '2', 't');
    $http = new RateLimiter(new FakeHttp(['status' => 500, 'body' => null, 'error' => null]), $pdo, 'mercadolivre');
    $http->request('GET', 'https://api.mercadolibre.com/x');
    $http->request('GET', 'https://api.mercadolibre.com/x');
    throws(fn() => $http->request('GET', 'https://api.mercadolibre.com/x'), 'Limite diário');
    $u = Db::one($pdo, "SELECT * FROM gmax3_api_usage WHERE connector='mercadolivre'");
    eq(2, (int)$u['requests']);
    eq(2, (int)$u['failures']);
    eq('HTTP 500', $u['last_error']);
});
test('Mercado Livre (busca oficial): interpreta resposta; 403 vira "não autorizada"; vendas só se informadas', function () {
    $body = json_encode(['results' => [
        ['id' => 'MLB3000000001', 'title' => 'Mouse', 'price' => 57.99, 'original_price' => 214, 'permalink' => 'https://produto.mercadolivre.com.br/MLB-3000000001-mouse-_JM', 'available_quantity' => 5, 'seller' => ['nickname' => 'LOJA', 'seller_reputation' => ['power_seller_status' => 'platinum']]],
        ['id' => 'MLB3000000002', 'title' => 'Sem estoque', 'price' => 10, 'available_quantity' => 0],
    ]]);
    $c = new MercadoLivre(new FakeHttp(['status' => 200, 'body' => $body, 'error' => null]), str_repeat('T', 30));
    $r = $c->search('mouse', 10);
    eq(true, $r['ok']);
    eq(21400, $r['items'][0]['price_original_cents']);
    eq('MercadoLíder Platinum', $r['items'][0]['seller_reputation']);
    ok(!isset($r['items'][0]['sales_min']), 'vendas não informadas não são inventadas');
    eq('indisponivel', $r['items'][1]['availability']);
    $denied = (new MercadoLivre(new FakeHttp(['status' => 403, 'body' => '{}', 'error' => null]), str_repeat('T', 30)))->search('x', 5);
    ok(!$denied['ok'] && str_contains($denied['error'], 'não autorizada'), '403');
});
test('Shopee (busca oficial): link afiliado da API, desconto informado sem inventar preço original', function () {
    $body = json_encode(['data' => ['productOfferV2' => ['nodes' => [
        ['itemId' => 444000111, 'productName' => 'Garrafa', 'priceMin' => '39.90', 'priceMax' => '39.90', 'priceDiscountRate' => 30, 'sales' => 1200, 'ratingStar' => 4.7, 'offerLink' => 'https://s.shopee.com.br/Off1234', 'productLink' => 'https://shopee.com.br/product/1/444000111'],
    ]]]]);
    $c = new ShopeeAffiliate(new FakeHttp(['status' => 200, 'body' => $body, 'error' => null]), ['app_id' => '123456', 'secret' => 'segredo-de-teste-123']);
    $r = $c->search('garrafa', 5);
    $i = $r['items'][0];
    eq(3990, $i['price_promo_cents']);
    ok(!isset($i['price_original_cents']), '"De" não calculado a partir do %');
    eq('https://s.shopee.com.br/Off1234', $i['affiliate_url']);
    eq(1200, $i['sales_min']);
    ok(str_contains($i['notes'], '30%'), 'desconto informado como observação');
    eq(false, (new ShopeeAffiliate(new FakeHttp([]), null))->search('x', 1)['ok'], 'sem credenciais não consulta');
});
test('Amazon e AliExpress: preparados, sem requisições e sem dados simulados', function () use ($ready) {
    $pdo = $ready();
    foreach (['amazon', 'aliexpress'] as $mp) {
        $c = Registry::get($mp, $pdo);
        eq(false, $c->configured());
        eq([], $c->search('x', 5)['items']);
    }
});

echo "\n== Garantia: automações nunca enviam\n";
test('Todas as tarefas executadas em sequência: nenhum transporte criado, nenhum item reservado/enviado', function () use ($ready, $approvedShopee, $mlItem) {
    $pdo = $ready();
    Settings::set($pdo, 'publicador_v21_aposentado', '1', 't');
    Settings::set($pdo, 'termos_descoberta_mercadolivre', 'x', 't');
    $approvedShopee($pdo, '22812345602');
    Registry::override('mercadolivre', new FakeMarketConnector('mercadolivre', ['ok' => true, 'error' => null, 'items' => [$mlItem('MLB2000000099', 'Produto Z', 1000)]]));
    Registry::override('shopee', new FakeMarketConnector('shopee', fetchMap: ['22812345602' => ['ok' => true, 'error' => null, 'data' => ['price_promo_cents' => 4990, 'price_original_cents' => 9990, 'availability' => 'disponivel']]]));
    foreach (['saude_catalogo', 'descoberta', 'revisao_precos', 'selecao', 'analise_semanal', 'revisao_vencidas'] as $job) {
        $r = Runner::runManual($pdo, $job, 't');
        ok(in_array($r['resultado'], ['concluido', 'parcial'], true), "$job: " . json_encode($r));
    }
    eq(0, (int)$pdo->query("SELECT COUNT(*) FROM gmax3_pub_queue WHERE state NOT IN ('agendado','cancelado')")->fetchColumn(), 'nada reservado, confirmado ou incerto');
    eq('1', Settings::get($pdo, 'publicacao_pausada'));
    eq('0', Settings::get($pdo, 'automacao_comercial'));
    ok(!is_file(Config::activationFile()), 'nenhuma ativação criada');
});

Registry::resetOverrides();
$pass = count(array_filter($GLOBALS['t_results'], fn($r) => $r[1]));
$total = count($GLOBALS['t_results']);
echo "\nResultado automações: $pass/$total testes aprovados\n";
file_put_contents(__DIR__ . '/../docs/resultado-testes-automacoes.json', json_encode([
    'executado_em' => date('c'), 'php' => PHP_VERSION, 'aprovados' => $pass, 'total' => $total,
    'testes' => array_map(fn($r) => ['nome' => $r[0], 'ok' => $r[1], 'erro' => $r[2], 'segundos' => round($r[3], 3)], $GLOBALS['t_results']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($pass === $total ? 0 : 1);

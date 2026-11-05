<?php
declare(strict_types=1);
/*
 * GMAX AutoLab (3.2.1): homologação automática no Telegram privado, isolada da publicação comercial.
 * Execução: php -d allow_url_fopen=0 tests/autolab_test.php
 * Sem rede: o Telegram é um canal FALSO que registra o que seria enviado; o transporte real
 * recusa ser criado em modo de teste. Nenhum teste envia mensagem de verdade.
 */
require __DIR__ . '/harness.php';
define('GMAX_APP_DIR', t_app());
require GMAX_APP_DIR . '/gmax-lib/bootstrap.php';
require __DIR__ . '/fakes.php';

use Gmax\AutoLab\{AutoLab, Channel, Composer, TelegramChannel};
use Gmax\Connectors\Registry;
use Gmax\{Config, Db, Migrator, Queue, SendResult, Settings, Time};

final class FakeChannel implements Channel
{
    public array $sent = [];
    public array $probes = [];
    public ?Closure $onSend = null;

    public function __construct(public array $probeResult = [], public ?SendResult $result = null)
    {
        $this->probeResult = $probeResult ?: self::goodProbe();
    }

    public static function goodProbe(): array
    {
        return ['ok' => true, 'error' => null, 'chat_id' => Config::PRIVATE_CHAT_ID, 'type' => 'supergroup', 'title' => 'GMAX homologação', 'public_username' => false, 'members' => 3, 'bot_status' => 'administrator', 'http' => 200];
    }

    public function probe(string $chatId): array
    {
        $this->probes[] = $chatId;
        return $this->probeResult;
    }

    public function send(string $chatId, string $text): SendResult
    {
        $this->sent[] = [$chatId, $text];
        if ($this->onSend) {
            ($this->onSend)();
        }
        return $this->result ?? new SendResult(SendResult::CONFIRMED, 200, 1000 + count($this->sent), 'Telegram confirmou');
    }
}

$SP = new DateTimeZone('America/Sao_Paulo');
$at = static fn(string $hm, string $day = 'today') => (new DateTimeImmutable("$day $hm", $SP))->setTimezone(new DateTimeZone('UTC'));
$chan = null;
/** Banco migrado (001–006), Shopee com 5 produtos de link afiliado válido, canal falso. */
$ready = static function (?FakeChannel $c = null) use (&$chan, $at): PDO {
    Registry::resetOverrides();
    Time::freeze($at('10:05'));
    @unlink(AutoLab::pauseFile());
    $pdo = t_seed('hostgator');
    Migrator::up($pdo, 'teste');
    Settings::set($pdo, 'api_intervalo_ms', '0', 't');
    Db::run($pdo, "UPDATE gmax3_catalog SET affiliate_url='https://s.shopee.com.br/AbCd' || id, affiliate_norm='s.shopee.com.br/AbCd' || id, availability='disponivel', variation_pending=0 WHERE marketplace='shopee'");
    $chan = $c ?? new FakeChannel();
    AutoLab::$channelFactory = static fn() => $chan;
    return $pdo;
};
/** Shopee falsa: confirma o preço do catálogo (ou o informado) para cada produto. */
$shopee = static function (PDO $pdo, ?Closure $f = null): FakeMarketConnector {
    $map = [];
    foreach (Db::all($pdo, "SELECT * FROM gmax3_catalog WHERE marketplace='shopee'") as $r) {
        $map[$r['external_id']] = $f ? $f($r) : ['ok' => true, 'error' => null, 'data' => ['title' => $r['title'], 'price_promo_cents' => (int)$r['price_promo_cents']]];
    }
    $c = new FakeMarketConnector('shopee', fetchMap: $map);
    Registry::override('shopee', $c);
    return $c;
};
$activate = static function (PDO $pdo, int $days = 7): void {
    eq(true, AutoLab::validateDestination($pdo, 't')['aprovado'], 'destino validado');
    AutoLab::activate($pdo, AutoLab::ACTIVATION_PHRASE, $days, 't');
};
/** Impressão digital de tudo que é comercial: fila, catálogo, histórico e travas. */
$commercial = static function (PDO $pdo): string {
    $s = [];
    foreach (['gmax3_pub_queue', 'gmax3_catalog', 'gmax3_price_history'] as $t) {
        $s[$t] = Db::all($pdo, "SELECT * FROM $t ORDER BY id");
    }
    foreach (['publicacao_pausada', 'automacao_comercial', 'aprovacao_automatica', 'ml_grupo_privado_permitido', 'publicador_v21_aposentado', 'automacoes_pausadas'] as $k) {
        $s[$k] = Settings::get($pdo, $k, '(ausente)');
    }
    $s['gates'] = Queue::gates($pdo);
    $s['ativacao'] = [is_file(Config::activationFile()), is_file(Config::legacyActivationFile())];
    return hash('sha256', json_encode($s));
};
$lastMsg = static fn(PDO $pdo) => Db::one($pdo, 'SELECT * FROM gmax3_autolab_messages ORDER BY id DESC LIMIT 1');

echo "\n== Destino e autorização\n";
test('Destino permitido: validação somente leitura, ativação só com frase própria e destino validado', function () use ($ready, &$chan) {
    $pdo = $ready();
    throws(fn() => AutoLab::activate($pdo, AutoLab::ACTIVATION_PHRASE, 7, 't'), 'Valide o destino');
    $v = AutoLab::validateDestination($pdo, 't');
    eq(true, $v['aprovado']);
    eq([], $chan->sent, 'validar não envia nada');
    eq([Config::PRIVATE_CHAT_ID], $chan->probes, 'consulta só o destino autorizado');
    throws(fn() => AutoLab::activate($pdo, Config::ACTIVATION_PHRASE, 7, 't'), 'incorreta'); // frase comercial não serve
    throws(fn() => AutoLab::activate($pdo, AutoLab::ACTIVATION_PHRASE, 31, 't'), '1 a 30');
    AutoLab::activate($pdo, AutoLab::ACTIVATION_PHRASE, 7, 't');
    eq('ativo', AutoLab::state($pdo));
    Time::freeze(Time::now()->modify('+25 hours'));
    AutoLab::pause($pdo, 'x', 't', 'desativado');
    throws(fn() => AutoLab::activate($pdo, AutoLab::ACTIVATION_PHRASE, 7, 't'), 'vale 24 h');
});
test('Destino não autorizado é rejeitado: público, canal, outro chat, membros demais, bot fora — pausa em falha e nada é enviado', function () use ($ready, $activate, &$chan) {
    $cases = [
        'público (@username)' => ['public_username' => true],
        'canal' => ['type' => 'channel'],
        'outro chat_id' => ['chat_id' => '-100999'],
        'membros demais' => ['members' => 500],
        'bot fora do grupo' => ['bot_status' => 'left'],
    ];
    foreach ($cases as $why => $bad) {
        $pdo = $ready();
        $activate($pdo);
        $chan->probeResult = $bad + FakeChannel::goodProbe();
        $r = AutoLab::tick($pdo);
        eq('destino_recusado', $r['resultado'], $why);
        eq('falha', AutoLab::state($pdo), $why);
        eq([], $chan->sent, "$why: nada enviado");
        eq(0, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_autolab_messages'), $why);
        throws(fn() => AutoLab::resume($pdo, 'admin'), 'em falha'); // painel não retoma "falha"
    }
});
test('Canal real: recusa outro chat_id e texto sem rótulo SEM fazer requisição; não pode ser criado em modo de teste', function () {
    throws(fn() => new TelegramChannel(), 'proibido em modo de teste');
    $real = (new ReflectionClass(TelegramChannel::class))->newInstanceWithoutConstructor(); // sem token: qualquer requisição falharia
    eq(SendResult::FAILED, $real->send('-1001234567890', "TESTE — CANDIDATO\nx")->outcome, 'outro chat');
    ok(str_contains($real->send('-1001234567890', "TESTE — CANDIDATO\nx")->note, 'nenhuma requisição'), 'sem requisição');
    eq(SendResult::FAILED, $real->send(Config::PRIVATE_CHAT_ID, "🔥 OFERTA comercial\nhttps://s.shopee.com.br/AbCd1")->outcome, 'sem rótulo de teste');
    eq(false, $real->probe('-100555')['ok'], 'probe de outro chat recusado');
    eq('-1004443626419', Config::PRIVATE_CHAT_ID, 'destino é constante do código (não configurável pelo painel)');
    ok(!in_array('autolab_estado', Settings::WEB_EDITABLE, true) && !array_filter(Settings::WEB_EDITABLE, fn($k) => str_starts_with($k, 'autolab_') || str_contains($k, 'chat')), 'nenhuma chave do AutoLab ou de destino editável pelo painel');
});

echo "\n== Ciclo, mensagens e dados\n";
test('Oferta validada: preço confirmado pela API agora, sem "De" nem desconto, link afiliado Shopee; catálogo intacto', function () use ($ready, $activate, $shopee, &$chan, $lastMsg, $commercial) {
    $pdo = $ready();
    $shopee($pdo);
    $activate($pdo);
    $before = $commercial($pdo);
    $r = AutoLab::tick($pdo);
    eq(SendResult::CONFIRMED, $r['resultado'], json_encode($r));
    eq('oferta_validada', $r['tipo']);
    [$chat, $text] = $chan->sent[0];
    eq(Config::PRIVATE_CHAT_ID, $chat);
    ok(str_starts_with($text, "TESTE — OFERTA VALIDADA\n"), $text);
    ok(str_contains($text, 'confirmado pela API Shopee') && str_contains($text, 'não informados pela API'), 'origem e ausência de "De"');
    ok(!preg_match('/\bDe:|% OFF|desconto de/i', $text), 'sem desconto inventado');
    ok(preg_match('~https://s\.shopee\.com\.br/AbCd\d+~', $text) === 1, 'link afiliado Shopee');
    $m = $lastMsg($pdo);
    eq(['confirmado', 1, 'afiliado', 'api_shopee'], [$m['state'], (int)$m['price_confirmed'], $m['link_kind'], $m['source']]);
    eq($before, $commercial($pdo), 'fila, catálogo, histórico e travas comerciais inalterados');
});
test('Preço alterado: mensagem usa o preço confirmado agora e avisa a diferença; o catálogo NÃO é alterado', function () use ($ready, $activate, $shopee, &$chan, $lastMsg) {
    $pdo = $ready();
    $shopee($pdo, fn($r) => ['ok' => true, 'error' => null, 'data' => ['title' => $r['title'], 'price_promo_cents' => (int)$r['price_promo_cents'] + 500]]);
    $activate($pdo);
    $cat = Db::all($pdo, 'SELECT id, price_promo_cents, updated_at FROM gmax3_catalog ORDER BY id');
    AutoLab::tick($pdo);
    $m = $lastMsg($pdo);
    eq((int)$m['price_catalog_cents'] + 500, (int)$m['price_cents']);
    ok(str_contains($chan->sent[0][1], 'preço diferente do último registrado'), $chan->sent[0][1]);
    eq($cat, Db::all($pdo, 'SELECT id, price_promo_cents, updated_at FROM gmax3_catalog ORDER BY id'), 'catálogo intacto');
});
test('Dados ausentes (faixa de variações) e link inválido viram TESTE — CANDIDATO, sem preço inventado e sem link', function () use ($ready, $activate, $shopee, &$chan, $lastMsg) {
    $pdo = $ready();
    Db::run($pdo, "UPDATE gmax3_catalog SET affiliate_url='https://shopee.com.br/produto-123', affiliate_norm=NULL WHERE marketplace='shopee'");
    $shopee($pdo, fn($r) => ['ok' => true, 'error' => null, 'data' => ['title' => $r['title'], 'variation_pending' => 1, 'variation_note' => 'Faixa de preço entre variações: R$ 10,00 a R$ 20,00']]);
    $activate($pdo);
    AutoLab::tick($pdo);
    $text = $chan->sent[0][1];
    ok(str_starts_with($text, "TESTE — CANDIDATO\n"), $text);
    ok(!str_contains($text, 'http'), 'sem link');
    ok(str_contains($text, 'Preço: não confirmado') && str_contains($text, 'variação identificada') && str_contains($text, 'link afiliado Shopee válido'), $text);
    eq([0, null, 'nenhum'], [(int)$lastMsg($pdo)['price_confirmed'], $lastMsg($pdo)['price_cents'], $lastMsg($pdo)['link_kind']]);
});
test('Mercado Livre: candidato com ID de catálogo separado do anúncio, sem link afiliado, preço só se recente e marcado "NÃO conferido"', function () use ($ready, $activate, &$chan) {
    $pdo = $ready();
    Registry::override('shopee', new FakeMarketConnector('shopee', ok: false));
    Db::run($pdo, "INSERT INTO gmax3_ml_candidates(product_id,name,origem,bb_item_id,bb_price_cents,bb_checked_at,first_seen_at,last_seen_at) VALUES('MLB19876543','Fone teste','termo','MLB4444444444',9990,?,?,?)", [Time::nowSql(), Time::nowSql(), Time::nowSql()]);
    $activate($pdo);
    AutoLab::tick($pdo);
    $text = $chan->sent[0][1];
    ok(str_contains($text, 'ID de catálogo: MLB19876543 (não é ID de anúncio)') && str_contains($text, 'Anúncio vencedor informado pela API: MLB4444444444'), $text);
    ok(str_contains($text, 'NÃO conferido') && !str_contains($text, 'http') && !str_contains($text, 'meli.la'), $text);
    Db::run($pdo, "UPDATE gmax3_ml_candidates SET bb_checked_at=?", [Time::now()->modify('-3 days')->format('Y-m-d H:i:s')]);
    $c = Composer::compose($pdo, 'x');
    ok(!str_contains($c['text'], 'R$ 99,90'), 'preço antigo não é usado como atual: ' . $c['text']);
});
test('Sem produto real: SIMULAÇÃO rotulada, dados fictícios e nenhum link', function () use ($ready) {
    $pdo = $ready();
    $c = Composer::simulation('2026-11-05 10:00');
    ok(str_starts_with($c['text'], 'SIMULAÇÃO — FORMATAÇÃO (dados fictícios)') && !str_contains($c['text'], 'http'), $c['text']);
    eq([], AutoLab::textProblems($c['text'], 'simulacao'));
    eq(['candidato/simulação não pode ter link'], AutoLab::textProblems("TESTE — CANDIDATO\nhttps://s.shopee.com.br/AbCd1", 'candidato'));
    ok(in_array('link fora do padrão afiliado Shopee', AutoLab::textProblems("TESTE — OFERTA VALIDADA\nhttps://meli.la/abcd", 'oferta_validada'), true), 'link ML recusado');
    ok(in_array('texto sem rótulo de teste', AutoLab::textProblems("Oferta!\nx", 'candidato'), true), 'sem rótulo');
});
test('Falha de rede: API Shopee fora → candidato/simulação; Telegram sem resposta na validação → horário pulado sem pausar', function () use ($ready, $activate, &$chan) {
    $pdo = $ready();
    Registry::override('shopee', new FakeMarketConnector('shopee', fetchMap: [])); // toda consulta falha
    $activate($pdo);
    $chan->probeResult = ['ok' => false, 'error' => 'getMe falhou (sem resposta)'];
    eq('destino_sem_resposta', AutoLab::tick($pdo)['resultado']);
    eq('ativo', AutoLab::state($pdo), 'rede fora não é falha crítica');
    $chan->probeResult = FakeChannel::goodProbe();
    Time::freeze(Time::now()->setTime(21, 5)); // 18:05 em Brasília
    $r = AutoLab::tick($pdo);
    eq(SendResult::CONFIRMED, $r['resultado']);
    ok(in_array($r['tipo'], ['candidato', 'simulacao'], true), 'sem dados confiáveis não há oferta validada');
    ok(str_contains((string)Db::value($pdo, 'SELECT checks FROM gmax3_autolab_runs ORDER BY id DESC LIMIT 1'), 'consulta falhou'), 'falha registrada');
});

echo "\n== Duplicidade, limites e pausa\n";
test('Duplicidade: mesmo horário nunca envia duas vezes; mesmo produto não se repete na semana', function () use ($ready, $activate, $shopee, &$chan) {
    $pdo = $ready();
    $shopee($pdo);
    $activate($pdo);
    AutoLab::tick($pdo);
    eq('nada_a_fazer', AutoLab::tick($pdo)['resultado'], 'segundo Cron no mesmo horário');
    eq(1, count($chan->sent));
    Time::freeze(Time::now()->setTime(21, 5));
    AutoLab::tick($pdo);
    $ids = array_column(Db::all($pdo, 'SELECT dedup_key FROM gmax3_autolab_messages'), 'dedup_key');
    eq(count($ids), count(array_unique($ids)), 'produtos diferentes');
});
test('Concorrência real: 6 processos no mesmo horário → um único envio', function () use ($ready, $activate) {
    $pdo = $ready();
    Registry::override('shopee', new FakeMarketConnector('shopee', ok: false));
    $activate($pdo);
    $script = t_sandbox() . '/autolab_filho.php';
    file_put_contents($script, '<?php define("GMAX_APP_DIR", ' . var_export(t_app(), true) . '); require GMAX_APP_DIR . "/gmax-lib/bootstrap.php"; require ' . var_export(__DIR__ . '/fakes.php', true) . ';'
        . 'final class C implements Gmax\AutoLab\Channel { public function probe(string $c): array { return ["ok"=>true,"error"=>null,"chat_id"=>Gmax\Config::PRIVATE_CHAT_ID,"type"=>"supergroup","public_username"=>false,"members"=>3,"bot_status"=>"member"]; }'
        . ' public function send(string $c, string $t): Gmax\SendResult { if (($GLOBALS["argv"][2] ?? "") === "matar") { posix_kill(getmypid(), 9); } usleep(200000); return new Gmax\SendResult("confirmado", 200, 77, "ok"); } }'
        . 'Gmax\Time::freeze(new DateTimeImmutable($argv[3])); Gmax\Connectors\Registry::override("shopee", new FakeMarketConnector("shopee", ok: false));'
        . 'Gmax\AutoLab\AutoLab::$channelFactory = fn() => new C(); while (microtime(true) < (float)$argv[1]) { usleep(1000); }'
        . 'echo json_encode(Gmax\AutoLab\AutoLab::tick(Gmax\Db::get()));');
    $start = microtime(true) + 1.0;
    $procs = [];
    for ($i = 0; $i < 6; $i++) {
        $procs[] = proc_open([PHP_BINARY, $script, (string)$start, 'normal', Time::now()->format('c')], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], t_app(), ['GMAX_SECRETS_DIR' => t_sandbox() . '/gmax-secrets', 'GMAX_TESTING' => '1', 'PATH' => getenv('PATH')]);
    }
    $outs = [];
    foreach ($procs as $i => $p) {
        $outs[] = stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]);
        proc_close($p);
    }
    eq(1, count(array_filter($outs, fn($o) => str_contains($o, '"confirmado"'))), implode(' | ', $outs));
    eq(1, (int)Db::value(Db::get(), 'SELECT COUNT(*) FROM gmax3_autolab_messages'));
    $GLOBALS['autolab_script'] = $script;
});
test('Interrompida pelo servidor no meio do envio (processo morto): fica "incerto", NÃO é reenviada e o próximo horário segue', function () use ($ready, $activate, &$chan) {
    $pdo = $ready();
    Registry::override('shopee', new FakeMarketConnector('shopee', ok: false));
    $activate($pdo);
    $p = proc_open([PHP_BINARY, $GLOBALS['autolab_script'], '0', 'matar', Time::now()->format('c')], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, t_app(), ['GMAX_SECRETS_DIR' => t_sandbox() . '/gmax-secrets', 'GMAX_TESTING' => '1', 'PATH' => getenv('PATH')]);
    stream_get_contents($pipes[1]);
    $code = proc_close($p);
    ok($code !== 0, "processo morto pelo sinal 9 (código $code)");
    eq('reservado', Db::value($pdo, 'SELECT state FROM gmax3_autolab_messages'), 'morreu depois da reserva');
    $r = AutoLab::tick($pdo); // recuperação com a trava obtida
    eq(['incertos' => 1, 'execucoes_interrompidas' => 1], $r['recuperado'], json_encode($r));
    eq('incerto', Db::value($pdo, 'SELECT state FROM gmax3_autolab_messages'));
    eq('nada_a_fazer', $r['resultado'], 'o horário interrompido não é repetido');
    eq([], $chan->sent, 'nada reenviado');
    Time::freeze(Time::now()->setTime(21, 5));
    eq(SendResult::CONFIRMED, AutoLab::tick($pdo)['resultado'], 'retomada no horário seguinte');
    eq('0', Settings::get($pdo, 'autolab_incertos_seguidos'), 'confirmação zera a contagem de incertos');
});
test('Timeout do Telegram: "incerto" sem repetir; dois seguidos pausam o AutoLab; retomar exige estar pausado', function () use ($ready, $activate, &$chan, $at) {
    $pdo = $ready(new FakeChannel([], new SendResult(SendResult::UNCERTAIN, 0, null, 'Sem resposta (rede/timeout)')));
    Registry::override('shopee', new FakeMarketConnector('shopee', ok: false));
    $activate($pdo);
    eq(SendResult::UNCERTAIN, AutoLab::tick($pdo)['resultado']);
    eq('ativo', AutoLab::state($pdo), 'um incerto: registra e segue');
    eq('nada_a_fazer', AutoLab::tick($pdo)['resultado'], 'não repete o mesmo horário');
    Time::freeze($at('18:05'));
    AutoLab::tick($pdo);
    eq('pausado', AutoLab::state($pdo), 'dois incertos seguidos');
    eq(2, count($chan->sent), 'cada mensagem enviada uma única vez');
    eq(2, (int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_autolab_messages WHERE state='incerto'"));
    AutoLab::resume($pdo, 'admin');
    eq(['ativo', '0'], [AutoLab::state($pdo), Settings::get($pdo, 'autolab_incertos_seguidos')]);
});
test('Reconciliação: reservado órfão vira incerto; preparado órfão vira "falhou" (nada enviado); execução órfã vira falha', function () use ($ready, $activate) {
    $pdo = $ready();
    $activate($pdo);
    Db::run($pdo, "INSERT INTO gmax3_autolab_runs(slot_key,status,started_at) VALUES('2026-01-01 10:00','executando',?)", [Time::nowSql()]);
    foreach (['reservado' => '2026-01-01 10:00', 'preparado' => '2026-01-01 18:00'] as $st => $slot) {
        Db::run($pdo, "INSERT INTO gmax3_autolab_messages(run_id,slot_key,kind,source,dedup_key,text,text_hash,state,created_at) VALUES(1,?,'simulacao','simulacao',?,'SIMULAÇÃO — x','h',?,?)", [$slot, "sim:$slot", $st, Time::nowSql()]);
    }
    $r = AutoLab::tick($pdo);
    eq(['incertos' => 1, 'nao_enviados' => 1, 'execucoes_interrompidas' => 1], $r['recuperado']);
    eq(['falhou', 'incerto'], array_column(Db::all($pdo, 'SELECT state FROM gmax3_autolab_messages ORDER BY state'), 'state'));
});
test('Limite diário e intervalo mínimo: nunca acima do configurado; configuração nunca acima dos limites de segurança', function () use ($ready, $activate, &$chan, $at) {
    $pdo = $ready();
    Registry::override('shopee', new FakeMarketConnector('shopee', ok: false));
    $activate($pdo);
    AutoLab::configure($pdo, '10:00,18:00', '1', 't');
    AutoLab::tick($pdo);
    Time::freeze($at('18:05'));
    eq('limite_diario', AutoLab::tick($pdo)['resultado']);
    eq(1, count($chan->sent));
    throws(fn() => AutoLab::configure($pdo, '10:00,18:00', '9', 't'), 'de 1 a 4');
    throws(fn() => AutoLab::configure($pdo, '10:00,10:30', '2', 't'), '60 minutos');
    throws(fn() => AutoLab::configure($pdo, '08:00,10:00,12:00,14:00,16:00', '4', 't'), 'de 1 a 4 horários');
    throws(fn() => AutoLab::configure($pdo, '25:00', '1', 't'), 'inválido');
});
test('Pausa emergencial: painel, arquivo de pausa e pausa no meio da preparação — nada é enviado', function () use ($ready, $activate, &$chan) {
    $pdo = $ready();
    Registry::override('shopee', new FakeMarketConnector('shopee', ok: false));
    $activate($pdo);
    AutoLab::pause($pdo, 'Botão de emergência', 'admin');
    eq('autolab_pausado', AutoLab::tick($pdo)['resultado']);
    AutoLab::resume($pdo, 'admin');
    touch(AutoLab::pauseFile());
    eq('autolab_pausado', AutoLab::tick($pdo)['resultado'], 'arquivo de pausa');
    throws(fn() => AutoLab::resume($pdo, 'admin'), 'gmax-autolab.pause');
    unlink(AutoLab::pauseFile());
    AutoLab::resume($pdo, 'admin');
    $chan->probeResult = FakeChannel::goodProbe();
    $orig = $chan;
    $pausing = new class($pdo, $orig) implements Channel {
        public function __construct(private PDO $pdo, private FakeChannel $c) {}
        public function probe(string $id): array { AutoLab::pause($this->pdo, 'pausado no meio', 'admin'); return $this->c->probe($id); }
        public function send(string $id, string $t): SendResult { return $this->c->send($id, $t); }
    };
    AutoLab::$channelFactory = fn() => $pausing;
    eq('pausado_durante_preparo', AutoLab::tick($pdo)['resultado']);
    eq([], $chan->sent, 'nada enviado');
    eq('falhou', Db::value($pdo, 'SELECT state FROM gmax3_autolab_messages'), 'registrado como não enviado');
});
test('Período autorizado (7 dias): terminado, o AutoLab se encerra; renovar só pela CLI', function () use ($ready, $activate) {
    $pdo = $ready();
    $activate($pdo, 7);
    Time::freeze(Time::now()->modify('+8 days'));
    eq('autolab_encerrado', AutoLab::tick($pdo)['resultado']);
    throws(fn() => AutoLab::resume($pdo, 'admin'), 'não está pausado');
});

echo "\n== Isolamento e compatibilidade\n";
test('Isolamento da fila comercial: AutoLab ativo não libera nenhuma trava comercial; fila e processador seguem bloqueados', function () use ($ready, $activate, $shopee, $commercial) {
    $pdo = $ready();
    $shopee($pdo);
    $before = $commercial($pdo);
    $gates = Queue::gates($pdo);
    $activate($pdo);
    for ($d = 0; $d < 3; $d++) {
        AutoLab::tick($pdo);
        Time::freeze(Time::now()->modify('+8 hours'));
        AutoLab::tick($pdo);
        Time::freeze(Time::now()->modify('+16 hours'));
    }
    ok((int)Db::value($pdo, "SELECT COUNT(*) FROM gmax3_autolab_messages WHERE state='confirmado'") >= 3, 'AutoLab enviou');
    eq($before, $commercial($pdo), 'nada comercial mudou');
    eq($gates, Queue::gates($pdo), 'mesmas travas comerciais');
    ok(in_array('Automação comercial desativada (exige autorização explícita via CLI)', $gates, true), 'automação comercial continua desligada');
    $r = Queue::processNext($pdo, false, fn() => new FakeTransport(new SendResult(SendResult::CONFIRMED, 200, 1, 'x')), 't');
    ok(in_array($r['resultado'], ['fila_vazia', 'envio_bloqueado', 'bloqueado', 'pausa_reconciliacao'], true), 'processador comercial não envia: ' . $r['resultado']);
    $code = '';
    foreach (glob(GMAX_APP_DIR . '/gmax-lib/Gmax/AutoLab/*.php') as $f) {
        foreach (token_get_all((string)file_get_contents($f)) as $t) {
            $code .= is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($t) ? $t[1] : $t);
        }
    }
    foreach (['gmax3_pub_queue', 'Queue::', 'processNext', 'publicacao_pausada', 'automacao_comercial', 'activationFile', 'gmax-publicador'] as $needle) {
        ok(!str_contains($code, $needle), "código do AutoLab (sem comentários) não usa $needle");
    }
});
test('Compatibilidade: sem a migração 006 o Cron segue igual; 006 e 005 revertem separadamente', function () use ($ready) {
    $pdo = $ready();
    eq('006', Migrator::down($pdo, 'teste')['version']);
    eq(['resultado' => 'sem_migracao_006'], AutoLab::tick($pdo));
    ok(Db::tableExists($pdo, 'gmax3_retention_runs'), '005 continua');
    eq('005', Migrator::down($pdo, 'teste')['version']);
    eq(['005', '006'], Migrator::pending($pdo));
    Migrator::up($pdo, 'teste');
    ok(AutoLab::installed($pdo), 'reaplicada');
    Db::run($pdo, "INSERT INTO gmax3_autolab_runs(slot_key,status,started_at) VALUES('x','concluido',?)", [Time::nowSql()]);
    Db::run($pdo, "INSERT INTO gmax3_autolab_messages(run_id,slot_key,kind,source,dedup_key,text,text_hash,state,created_at) VALUES(1,'x','simulacao','simulacao','sim:x','SIMULAÇÃO — x','h','confirmado',?)", [Time::nowSql()]);
    throws(fn() => Db::run($pdo, 'DELETE FROM gmax3_autolab_messages'), 'não pode ser apagado');
    throws(fn() => Db::run($pdo, 'DELETE FROM gmax3_autolab_runs'), 'não pode ser apagado');
    throws(fn() => Db::run($pdo, "UPDATE gmax3_autolab_messages SET text='y'"), 'não pode ser alterado');
});
test('CLI: status, prévia sem envio e ativação recusada sem destino validado', function () use ($ready) {
    $pdo = $ready();
    $cli = static function (string ...$a): string {
        $p = proc_open([PHP_BINARY, 'gmax-admin-cli.php', 'autolab', ...$a], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, t_app(), ['GMAX_SECRETS_DIR' => t_sandbox() . '/gmax-secrets', 'GMAX_TESTING' => '1', 'PATH' => getenv('PATH')]);
        $o = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($p);
        return $o;
    };
    ok(str_contains($cli('status'), '"estado": "desativado"'), 'status');
    $prev = $cli('previa');
    ok(str_contains($prev, 'mensagem (não enviada)'), $prev);
    eq(0, (int)Db::value($pdo, 'SELECT COUNT(*) FROM gmax3_autolab_messages'), 'prévia não grava');
    ok(str_contains($cli('ativar', AutoLab::ACTIVATION_PHRASE), 'Valide o destino'), 'ativação exige validação');
    ok(str_contains($cli('validar-destino'), 'proibido em modo de teste'), 'validação real impossível em teste');
});

AutoLab::$channelFactory = null;
Time::freeze(null);
@unlink(AutoLab::pauseFile());
$pass = count(array_filter($GLOBALS['t_results'], fn($r) => $r[1]));
$total = count($GLOBALS['t_results']);
echo "\nResultado AutoLab: $pass/$total testes aprovados\n";
file_put_contents(__DIR__ . '/../docs/resultado-testes-autolab.json', json_encode([
    'executado_em' => date('c'), 'php' => PHP_VERSION, 'aprovados' => $pass, 'total' => $total,
    'testes' => array_map(fn($r) => ['nome' => $r[0], 'ok' => $r[1], 'erro' => $r[2], 'segundos' => round($r[3], 3)], $GLOBALS['t_results']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($pass === $total ? 0 : 1);

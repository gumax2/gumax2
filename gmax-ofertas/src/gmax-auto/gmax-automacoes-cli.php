<?php
declare(strict_types=1);
/*
 * GMAX Ofertas 3.1 — automações comerciais (somente CLI). NENHUMA tarefa envia mensagens.
 * Cron sugerido (a cada 15 minutos):
  *   0,15,30,45 * * * * /usr/local/bin/php /home1/gust1922/gmaxcanais.com.br/gmax-auto/gmax-automacoes-cli.php executar-pendentes > /dev/null 2>&1
 * Comandos:
 *   executar-pendentes            Executa as tarefas cujo horário venceu (respeita a pausa geral)
 *   executar <tarefa>             Execução manual de uma tarefa
 *   listar                        Tarefas, próximas execuções e últimos resultados
 *   pausar | retomar              Pausa geral das automações
 *   aprovacao-automatica --autorizar AUTORIZO_APROVACAO_AUTOMATICA | --desativar
 *   ml-oauth status | renovar | testar   Situação/renovação do token OAuth do Mercado Livre (sem exibir tokens)
 */
// Aceita o binário CGI usado por alguns Crons de cPanel, mas nunca uma requisição web.
if (PHP_SAPI !== 'cli' && !(str_starts_with(PHP_SAPI, 'cgi') && empty($_SERVER['REQUEST_METHOD']))) {
    if (!empty($_SERVER['REQUEST_METHOD'])) {
        http_response_code(403);
        exit('Apenas CLI');
    }
    fwrite(STDERR, 'ERRO: execute com o PHP de linha de comando (/usr/local/bin/php)' . PHP_EOL);
    exit(1);
}
require __DIR__ . '/gmax-lib/bootstrap.php';

use Gmax\Automation\{Runner, Schedule};
use Gmax\{Db, Migrator, Settings};

$args = array_slice($argv, 1);
$cmd = $args[0] ?? 'ajuda';
$print = static fn($d) => print((is_string($d) ? $d : json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) . PHP_EOL);
try {
    $pdo = Db::get();
    if (!in_array('002', Migrator::applied($pdo), true)) {
        throw new RuntimeException('Migração 002 pendente: aplique pelo painel (Sistema) antes de usar as automações');
    }
    switch ($cmd) {
        case 'executar-pendentes':
            $print(Runner::runDue($pdo, 'cron'));
            break;
        case 'executar':
            $print(Runner::runManual($pdo, (string)($args[1] ?? ''), 'cli'));
            break;
        case 'listar':
            foreach (Runner::jobs($pdo) as $j) {
                $next = Schedule::next($j);
                $last = Db::one($pdo, 'SELECT status, started_at FROM gmax3_job_runs WHERE job=? ORDER BY id DESC LIMIT 1', [$j['job']]);
                $print(sprintf('%-17s %-8s %-18s dias %-14s próxima %s · última %s', $j['job'], $j['enabled'] ? 'ATIVA' : 'inativa', $j['times'], $j['weekdays'],
                    $next ? $next->format('d/m H:i') : '—', $last ? $last['status'] . ' ' . $last['started_at'] . ' UTC' : '—'));
            }
            $print('Pausa geral: ' . (Settings::get($pdo, 'automacoes_pausadas', '1') === '1' ? 'SIM' : 'não'));
            break;
        case 'pausar':
            Settings::set($pdo, 'automacoes_pausadas', '1', 'cli');
            $print('Automações pausadas.');
            break;
        case 'retomar':
            Settings::set($pdo, 'automacoes_pausadas', '0', 'cli');
            $print('Automações retomadas (somente pesquisa, revisão e seleção; envios continuam bloqueados pelas travas próprias).');
            break;
        case 'ml-oauth':
            $o = new Gmax\OAuth\MercadoLivreOAuth(new Gmax\RateLimiter(new Gmax\Http(), $pdo, 'mercadolivre'));
            $sub = $args[1] ?? 'status';
            if ($sub === 'renovar') {
                $print($o->accessToken(true) !== null ? 'Token renovado.' : 'Não foi possível renovar: reconecte em Integrações.');
            } elseif ($sub === 'testar') {
                $print((new Gmax\Connectors\MercadoLivre(new Gmax\RateLimiter(new Gmax\Http(), $pdo, 'mercadolivre'), null, $o))->probePermissions());
            } else {
                $st = $o->status();
                unset($st['scope']);
                $print($st);
            }
            break;
        case 'aprovacao-automatica':
            if (in_array('--desativar', $args, true)) {
                Settings::set($pdo, 'aprovacao_automatica', '0', 'cli');
                $print('Aprovação automática DESATIVADA.');
            } elseif (($args[array_search('--autorizar', $args, true) + 1] ?? '') === 'AUTORIZO_APROVACAO_AUTOMATICA' && in_array('--autorizar', $args, true)) {
                Settings::set($pdo, 'aprovacao_automatica', '1', 'cli');
                $print('Aprovação automática AUTORIZADA (somente ofertas com preço confirmado por API, link oficial e pontuação/confiança mínimas).');
            } else {
                throw new RuntimeException('Use --autorizar AUTORIZO_APROVACAO_AUTOMATICA ou --desativar');
            }
            break;
        default:
            $print(trim((string)preg_replace('/^\s*\* ?/m', '', explode('*/', explode('/*', (string)file_get_contents(__FILE__), 2)[1])[0])));
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

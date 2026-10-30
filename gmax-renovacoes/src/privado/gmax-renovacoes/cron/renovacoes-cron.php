<?php
// Agendador dos lembretes. Rodar pelo Cron do cPanel a cada 15 minutos:
//   */15 * * * * /usr/local/bin/php /home1/gust1922/gmax-renovacoes/cron/renovacoes-cron.php >/dev/null 2>&1
/*
 * O horário de geração/envio (padrão 09:00, fuso America/Sao_Paulo) é controlado pela aba Renovações,
 * então o fuso do servidor não importa. Execuções repetidas ou simultâneas são seguras.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';

$opts = getopt('', ['agora:', 'sem-trava', 'config:']);
try {
    $app = gr_app($opts['config'] ?? null);
    if (isset($opts['agora'])) {
        // Somente para testes: exige 'permitir_relogio_teste' => true no config.
        if (empty($app->config['permitir_relogio_teste'])) {
            fwrite(STDERR, "--agora desabilitado neste servidor.\n");
            exit(2);
        }
        $app->setClock(new \GmaxRenov\FixedClock($opts['agora'], $app->config['fuso'] ?? 'America/Sao_Paulo'));
    }
    if (!empty($app->config['testes_provedor_lento_ms'])) {
        // Somente testes: provedor simulado com atraso para provocar concorrência.
        $app->setProvider(new \GmaxRenov\Provider\SimulatedProvider([], (int)$app->config['testes_provedor_lento_ms']));
    }
    $res = (new \GmaxRenov\Engine($app))->run('cron', ['sem_trava' => isset($opts['sem-trava']) && !empty($app->config['permitir_relogio_teste'])]);
    echo json_encode($res, JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, \GmaxRenov\Log::redact($e->getMessage()) . "\n");
    exit(1);
}

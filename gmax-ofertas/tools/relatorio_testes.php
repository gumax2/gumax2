<?php
declare(strict_types=1);
// Gera docs/TESTES.md a partir dos resultados reais gravados pelos testes.
$docs = __DIR__ . '/../docs';
$sets = ['Unidade e integração (tests/run.php)' => 'resultado-testes-unidade.json', 'HTTP ponta a ponta e CLI (tests/http_test.php)' => 'resultado-testes-http.json', 'Compatibilidade com a produção e conflito de publicadores (tests/compat_test.php)' => 'resultado-testes-compat.json', 'Links afiliados em lote — 3.0.3 (tests/links_test.php)' => 'resultado-testes-links.json', 'Central de Automações — 3.1 (tests/automacoes_test.php)' => 'resultado-testes-automacoes.json', 'OAuth Mercado Livre — 3.1.1 (tests/oauth_test.php)' => 'resultado-testes-oauth.json'];
echo "# Testes executados — GMAX Ofertas 3.1.2\n\n";
echo "Resultados **gerados automaticamente** a partir da última execução real (não editados à mão).\n\n";
echo "Condições: sem rede (`allow_url_fopen=0`), `GMAX_TESTING=1` (transporte Telegram real proibido), banco reproduzindo o estado declarado da produção (5 Shopee, ML-001, envio confirmado), **arquivos atuais da HostGator** (cópia de 29/10/2026).\n\n";
$all = 0;
$okAll = 0;
foreach ($sets as $title => $file) {
    $j = json_decode((string)@file_get_contents("$docs/$file"), true);
    if (!$j) {
        echo "## $title\n\n**NÃO EXECUTADO** (sem arquivo de resultado).\n\n";
        continue;
    }
    $all += $j['total'];
    $okAll += $j['aprovados'];
    echo "## $title\n\nExecutado em {$j['executado_em']} · PHP {$j['php']}" . (isset($j['sqlite']) ? " · SQLite {$j['sqlite']}" : '') . " · **{$j['aprovados']}/{$j['total']} aprovados**\n\n";
    echo "| Resultado | Teste | Tempo |\n|---|---|---|\n";
    foreach ($j['testes'] as $t) {
        echo '| ' . ($t['ok'] ? '✅' : '❌ ' . str_replace('|', '\\|', $t['erro'])) . ' | ' . str_replace('|', '\\|', $t['nome']) . ' | ' . $t['segundos'] . " s |\n";
    }
    echo "\n";
}
echo "## Total\n\n**$okAll/$all testes aprovados.**\n\n";
echo "## Não testado aqui (depende do servidor real)\n\n- Envio real ao Telegram (proibido nesta fase).\n- Microsoft Edge (o teste de navegador usa Chromium, o mesmo motor do Edge e do Chrome).\n- Apache/.htaccess da HostGator (teste manual no passo 4 da instalação).\n- Arquivos e dados reais de produção (verificação na página Sistema).\n\n";
echo "## Como reproduzir\n\n```\ncd desenvolvimento\nphp -d allow_url_fopen=0 tests/run.php\nphp tests/http_test.php\nphp tests/compat_test.php\nphp -d allow_url_fopen=0 tests/links_test.php\nphp -d allow_url_fopen=0 tests/automacoes_test.php\nphp -d allow_url_fopen=0 tests/oauth_test.php\n```\n";

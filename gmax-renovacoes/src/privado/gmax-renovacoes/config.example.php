<?php
/**
 * GMAX Renovações — configuração de exemplo para o painel GMAX — Central de Gestão. SEM SEGREDOS.
 * Copie para config.php NESTA MESMA PASTA (fora de public_html) e confira os caminhos. chmod 600 config.php
 *
 * Banco: o módulo LÊ o config.php privado do painel (dsn/db_user/db_password). Nenhuma senha é copiada para cá.
 * Login: reaproveita a sessão do painel (gmax_session, $_SESSION['admin'], $_SESSION['csrf'], 3600 s).
 */
return [
    'fuso' => 'America/Sao_Paulo',

    'db' => [
        // Mesmo caminho usado em painel/api.php ($configPath). Confira no servidor.
        'config_painel' => '/home1/gust1922/gmax-painel/private/config.php',
    ],

    // Valores padrão = os do api.php. Só altere se o painel for alterado.
    'auth' => [
        'sessao_nome' => 'gmax_session',
        'chave_sessao' => 'admin',
        'csrf_chave_sessao' => 'csrf',
        'chave_ultimo_acesso' => 'last',
        'expira_segundos' => 3600,
        'cookie_path' => '/',
        'cookie_samesite' => 'Strict',
        'exigir_https' => true,   // igual a require_https do painel
        'url_login' => './',      // index.html do painel (tela de login)
    ],

    // Mapeamento SOMENTE LEITURA. Vazio = padrão do painel GMAX:
    //   clients(id, name, phone, status) + client_exclusions(client_id) +
    //   client_plans(id, client_id, label, points, service, months, price_cents, due_date, status).
    // clients.due_date (legado) NÃO é usado. price_cents é o TOTAL do pacote/ciclo.
    'esquema' => [],

    'provedor' => [
        // 'simulado' = nenhum envio real (padrão e obrigatório nesta etapa).
        // 'meta_cloud' só depois da configuração e dos testes; ainda exige Modo Real + Pix confirmado + modelos aprovados.
        'driver' => 'simulado',
        'meta' => [
            'graph_versao' => 'v24.0',     // conferir a versão atual na documentação da Meta
            'phone_number_id' => '',
            'waba_id' => '',
            'access_token' => '',          // token de Usuário do Sistema. Preencher SÓ no servidor. NUNCA no Git.
            'app_secret' => '',            // valida X-Hub-Signature-256 do webhook
            'verify_token' => '',          // texto aleatório longo, o mesmo informado no painel da Meta
            'enviar_ref_callback' => true, // biz_opaque_callback_data: ajuda a reconciliar envios incertos
            'timeout_conexao' => 5,
            'timeout_total' => 20,
        ],
    ],

    'pasta_logs' => __DIR__ . '/logs',

    // NUNCA ative em produção (permite simular data/hora pela linha de comando).
    'permitir_relogio_teste' => false,
];

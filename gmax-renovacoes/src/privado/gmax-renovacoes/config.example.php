<?php
/**
 * GMAX Renovações — configuração de exemplo (SEM SEGREDOS).
 * Copie para config.php NESTA MESMA PASTA (fora de public_html) e preencha.
 * Permissão recomendada: chmod 600 config.php
 *
 * Itens marcados com PENDENTE dependem dos arquivos reais do painel (não estavam disponíveis).
 * Rode `php bin/renovacoes-admin.php diagnostico` para conferir o mapeamento.
 */
return [
    'fuso' => 'America/Sao_Paulo',

    // Mesmo banco do painel. Pode copiar host/nome/usuário/senha do config do painel.
    'db' => [
        'host' => 'localhost',
        'porta' => 3306,
        'nome' => 'USUARIO_banco',
        'usuario' => 'USUARIO_user',
        'senha' => '',            // preencher só no servidor
        'charset' => 'utf8mb4',
    ],

    // Reaproveita o login do painel. PENDENTE: confirmar a chave de sessão que o painel usa.
    'auth' => [
        'arquivo_painel' => null,      // ex.: '/home/USUARIO/public_html/painel/auth.php' (apenas se esse arquivo só valida a sessão)
        'sessao_nome' => null,         // ex.: 'GMAXSESSID' se o painel usa session_name() próprio
        'chave_sessao' => '',          // ex.: 'usuario_id' ou 'logado' — OBRIGATÓRIO; vazio = ninguém entra
        'csrf_chave_sessao' => null,   // ex.: 'csrf_token' para reutilizar o token do painel; null = token próprio
        'url_login' => './',           // para onde mandar quem não está logado
    ],

    // Mapeamento SOMENTE LEITURA das tabelas do painel. PENDENTE: ajustar aos nomes reais.
    'esquema' => [
        'clientes' => [
            'tabela' => 'clientes',
            'id' => 'id',
            'nome' => 'nome',
            'telefone' => 'telefone',
            'status' => 'status',                 // null se não existir
            'status_ativos' => ['ativo'],         // só estes valores recebem lembrete (pausado/arquivado ficam de fora)
            'excluido' => 'excluido_em',          // coluna da lixeira; null se a exclusão apaga a linha
            'excluido_modo' => 'nao_nulo',        // 'nao_nulo' (data preenchida = excluído) ou 'verdadeiro' (1 = excluído)
        ],
        'pacotes' => [
            'tabela' => 'pacotes',
            'id' => 'id',
            'cliente_id' => 'cliente_id',
            'nome' => 'nome',
            'pontos' => 'pontos',
            'servico' => 'servico',
            'ciclo' => 'ciclo',
            'preco' => 'preco_total',             // TOTAL do pacote/ciclo (nunca multiplicado pelos pontos)
            'vencimento' => 'vencimento',
            'vencimento_formato' => 'Y-m-d',      // 'Y-m-d' (DATE/DATETIME), 'd/m/Y' (texto) ou 'timestamp'
            'status' => 'status',
            'status_ativos' => ['ativo'],
            'excluido' => null,
            'excluido_modo' => 'nao_nulo',
            'ciclos' => [                          // opcional: traduz códigos de ciclo
                // '1' => 'Mensal', '3' => 'Trimestral', '6' => 'Semestral', '12' => 'Anual',
            ],
        ],
    ],

    'provedor' => [
        // 'simulado' = nenhum envio real (padrão). 'meta_cloud' = WhatsApp Cloud API oficial.
        // Mesmo com 'meta_cloud', o envio real só acontece com Modo = Real na aba e Pix confirmado.
        'driver' => 'simulado',
        'meta' => [
            'graph_versao' => 'v24.0',     // conferir a versão atual na documentação da Meta
            'phone_number_id' => '',
            'waba_id' => '',
            'access_token' => '',          // token de Usuário do Sistema (permanente). NUNCA commitar.
            'app_secret' => '',            // valida a assinatura X-Hub-Signature-256 do webhook
            'verify_token' => '',          // texto aleatório longo que você também informa no painel da Meta
            'enviar_ref_callback' => true, // envia biz_opaque_callback_data (referência devolvida nos status)
            'timeout_conexao' => 5,
            'timeout_total' => 20,
        ],
    ],

    'pasta_logs' => __DIR__ . '/logs',

    // NUNCA ative em produção (permite simular data/hora pela linha de comando).
    'permitir_relogio_teste' => false,
];

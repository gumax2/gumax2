<?php
/**
 * Migração 001 — tabelas próprias do módulo (prefixo gr_).
 * Aditiva: só cria tabelas novas com IF NOT EXISTS. NÃO altera tabelas do painel
 * (clientes, pacotes, recebimentos, custos).
 */
return [
    'versao' => '001',
    'nome' => 'inicial',
    'sql' => [
        "CREATE TABLE IF NOT EXISTS gr_configuracoes (
            chave VARCHAR(64) NOT NULL PRIMARY KEY,
            valor MEDIUMTEXT NOT NULL,
            atualizado_em DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Consentimento e pausa por cliente (cliente_id = id da tabela de clientes do painel; sem FK para não tocar nela).
        "CREATE TABLE IF NOT EXISTS gr_contatos (
            cliente_id BIGINT NOT NULL PRIMARY KEY,
            consentimento VARCHAR(12) NOT NULL DEFAULT 'nenhum',
            telefone_consentido VARCHAR(20) NULL,
            consentimento_origem VARCHAR(120) NULL,
            consentimento_em DATETIME NULL,
            revogado_em DATETIME NULL,
            pausado TINYINT(1) NOT NULL DEFAULT 0,
            pausado_motivo VARCHAR(190) NULL,
            atualizado_em DATETIME NOT NULL,
            KEY idx_tel (telefone_consentido)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS gr_consentimento_log (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            cliente_id BIGINT NOT NULL,
            acao VARCHAR(20) NOT NULL,
            telefone VARCHAR(20) NULL,
            origem VARCHAR(120) NULL,
            observacao VARCHAR(255) NULL,
            registrado_em DATETIME NOT NULL,
            KEY idx_cliente (cliente_id, registrado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Um lembrete por (modo, pacote, vencimento, etapa): é a identidade única que impede repetição.
        "CREATE TABLE IF NOT EXISTS gr_lembretes (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            modo VARCHAR(10) NOT NULL,
            pacote_id BIGINT NOT NULL,
            cliente_id BIGINT NOT NULL,
            vencimento DATE NOT NULL,
            etapa VARCHAR(10) NOT NULL,
            data_prevista DATE NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pendente',
            motivo VARCHAR(190) NULL,
            mensagem_id BIGINT NULL,
            snap_pacote VARCHAR(190) NULL,
            snap_valor_centavos BIGINT NULL,
            snap_pontos INT NULL,
            snap_ciclo VARCHAR(60) NULL,
            criado_em DATETIME NOT NULL,
            atualizado_em DATETIME NOT NULL,
            UNIQUE KEY uq_lembrete (modo, pacote_id, vencimento, etapa),
            KEY idx_fila (modo, status, data_prevista),
            KEY idx_cliente (cliente_id),
            KEY idx_msg (mensagem_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS gr_mensagens (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            modo VARCHAR(10) NOT NULL,
            ref VARCHAR(40) NOT NULL,
            cliente_id BIGINT NOT NULL,
            telefone VARCHAR(20) NOT NULL,
            etapa VARCHAR(10) NOT NULL,
            vencimento DATE NOT NULL,
            data_prevista DATE NOT NULL,
            corpo TEXT NOT NULL,
            modelo_nome VARCHAR(120) NULL,
            modelo_parametros TEXT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'aguardando',
            provedor VARCHAR(20) NOT NULL,
            provedor_msg_id VARCHAR(128) NULL,
            tentativas INT NOT NULL DEFAULT 0,
            proxima_tentativa_em DATETIME NULL,
            lease_token VARCHAR(40) NULL,
            lease_ate DATETIME NULL,
            erro_codigo VARCHAR(40) NULL,
            erro_detalhe VARCHAR(500) NULL,
            status_em DATETIME NULL,
            enviado_em DATETIME NULL,
            entregue_em DATETIME NULL,
            lido_em DATETIME NULL,
            criado_em DATETIME NOT NULL,
            atualizado_em DATETIME NOT NULL,
            UNIQUE KEY uq_ref (ref),
            UNIQUE KEY uq_provedor_msg (provedor, provedor_msg_id),
            KEY idx_fila (modo, status, data_prevista),
            KEY idx_cliente (cliente_id, criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Eventos (envio, callbacks, ações manuais). chave_dedup evita processar o mesmo callback duas vezes.
        "CREATE TABLE IF NOT EXISTS gr_eventos (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            mensagem_id BIGINT NULL,
            tipo VARCHAR(20) NOT NULL,
            status VARCHAR(20) NULL,
            detalhe VARCHAR(500) NULL,
            chave_dedup CHAR(64) NULL,
            ocorrido_em DATETIME NULL,
            registrado_em DATETIME NOT NULL,
            UNIQUE KEY uq_dedup (chave_dedup),
            KEY idx_msg (mensagem_id, registrado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS gr_execucoes (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            origem VARCHAR(20) NOT NULL,
            iniciado_em DATETIME NOT NULL,
            finalizado_em DATETIME NULL,
            resultado TEXT NULL,
            erro VARCHAR(500) NULL,
            KEY idx_ini (iniciado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];

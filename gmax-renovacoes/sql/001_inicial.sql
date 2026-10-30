-- GMAX Renovações — Migração 001 (aditiva e reaplicável). Gerado por tests/gerar_sql.php.
-- Cria SOMENTE tabelas novas com prefixo gr_. Não altera clientes, pacotes, recebimentos nem custos.
-- Preferível: php bin/renovacoes-admin.php migrar. Use este arquivo no phpMyAdmin se não tiver SSH.
-- Pode ser executado mais de uma vez sem perda ou duplicação (IF NOT EXISTS / INSERT IGNORE).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS gr_migracoes (
  versao VARCHAR(10) NOT NULL PRIMARY KEY,
  nome VARCHAR(100) NOT NULL,
  checksum CHAR(64) NOT NULL,
  aplicado_em DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gr_configuracoes (
    chave VARCHAR(64) NOT NULL PRIMARY KEY,
    valor MEDIUMTEXT NOT NULL,
    atualizado_em DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gr_contatos (
    cliente_id VARCHAR(64) NOT NULL PRIMARY KEY,
    consentimento VARCHAR(12) NOT NULL DEFAULT 'nenhum',
    telefone_consentido VARCHAR(20) NULL,
    consentimento_origem VARCHAR(120) NULL,
    consentimento_em DATETIME NULL,
    revogado_em DATETIME NULL,
    pausado TINYINT(1) NOT NULL DEFAULT 0,
    pausado_motivo VARCHAR(190) NULL,
    atualizado_em DATETIME NOT NULL,
    KEY idx_tel (telefone_consentido)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gr_consentimento_log (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cliente_id VARCHAR(64) NOT NULL,
    acao VARCHAR(20) NOT NULL,
    telefone VARCHAR(20) NULL,
    origem VARCHAR(120) NULL,
    observacao VARCHAR(255) NULL,
    registrado_em DATETIME NOT NULL,
    KEY idx_cliente (cliente_id, registrado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gr_lembretes (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    modo VARCHAR(10) NOT NULL,
    pacote_id VARCHAR(64) NOT NULL,
    cliente_id VARCHAR(64) NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gr_mensagens (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    modo VARCHAR(10) NOT NULL,
    ref VARCHAR(40) NOT NULL,
    cliente_id VARCHAR(64) NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gr_eventos (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gr_execucoes (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    origem VARCHAR(20) NOT NULL,
    iniciado_em DATETIME NOT NULL,
    finalizado_em DATETIME NULL,
    resultado TEXT NULL,
    erro VARCHAR(500) NULL,
    KEY idx_ini (iniciado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO gr_configuracoes (chave, valor, atualizado_em) VALUES ('ativo', 'false', NOW());
INSERT IGNORE INTO gr_configuracoes (chave, valor, atualizado_em) VALUES ('ativado_em', 'null', NOW());
INSERT IGNORE INTO gr_configuracoes (chave, valor, atualizado_em) VALUES ('modo', '"simulacao"', NOW());
INSERT IGNORE INTO gr_configuracoes (chave, valor, atualizado_em) VALUES ('pausa_global', 'false', NOW());
INSERT IGNORE INTO gr_configuracoes (chave, valor, atualizado_em) VALUES ('horario', '"09:00"', NOW());
INSERT IGNORE INTO gr_configuracoes (chave, valor, atualizado_em) VALUES ('janela_fim', '"20:00"', NOW());
INSERT IGNORE INTO gr_configuracoes (chave, valor, atualizado_em) VALUES ('max_tentativas', '3', NOW());
INSERT IGNORE INTO gr_configuracoes (chave, valor, atualizado_em) VALUES ('etapas', '[{"codigo":"antes","dias":-3,"ativo":true},{"codigo":"dia","dias":0,"ativo":true},{"codigo":"depois","dias":1,"ativo":true}]', NOW());
INSERT IGNORE INTO gr_configuracoes (chave, valor, atualizado_em) VALUES ('chave_pix', '"gumaxskins@gmail.com"', NOW());
INSERT IGNORE INTO gr_configuracoes (chave, valor, atualizado_em) VALUES ('instrucoes_pagamento', '"Pix (chave e-mail): {chave_pix}"', NOW());
INSERT IGNORE INTO gr_configuracoes (chave, valor, atualizado_em) VALUES ('pagamento_confirmado', 'false', NOW());
INSERT IGNORE INTO gr_configuracoes (chave, valor, atualizado_em) VALUES ('pagamento_confirmado_em', 'null', NOW());
INSERT IGNORE INTO gr_configuracoes (chave, valor, atualizado_em) VALUES ('rodape', '"Para não receber mais estes lembretes, responda SAIR."', NOW());
INSERT IGNORE INTO gr_configuracoes (chave, valor, atualizado_em) VALUES ('numeros_teste', '[]', NOW());
INSERT IGNORE INTO gr_configuracoes (chave, valor, atualizado_em) VALUES ('somente_numeros_teste', 'true', NOW());
INSERT IGNORE INTO gr_configuracoes (chave, valor, atualizado_em) VALUES ('textos', '{"antes":{"individual":"Olá, {nome}! 😊 Seu pacote {pacote} vence em {data}, daqui a {dias} dias.\\n\\nO valor da renovação é {valor}.\\n\\nJá estamos passando para você se organizar. Para renovar, utilize as instruções de pagamento abaixo:\\n{instrucoes_pagamento}\\n\\nApós pagar, envie o comprovante por aqui para confirmarmos. Se já renovou, fale conosco para conferirmos o cadastro.","grupo":"Olá, {nome}! 😊 Os seus pacotes abaixo vencem em {data}, daqui a {dias} dias:\\n{lista_pacotes}\\n\\nO valor total da renovação é {total}.\\n\\nJá estamos passando para você se organizar. Para renovar, utilize as instruções de pagamento abaixo:\\n{instrucoes_pagamento}\\n\\nApós pagar, envie o comprovante por aqui para confirmarmos. Se já renovou, fale conosco para conferirmos o cadastro."},"dia":{"individual":"Olá, {nome}! 📅 Seu pacote {pacote} vence hoje, {data}.\\n\\nO valor para renovação é {valor}.\\n{instrucoes_pagamento}\\n\\nApós o pagamento, envie o comprovante para confirmarmos sua renovação. Se já pagou, avise por aqui.","grupo":"Olá, {nome}! 📅 Os seus pacotes abaixo vencem hoje, {data}:\\n{lista_pacotes}\\n\\nO valor total para renovação é {total}.\\n{instrucoes_pagamento}\\n\\nApós o pagamento, envie o comprovante para confirmarmos sua renovação. Se já pagou, avise por aqui."},"depois":{"individual":"Olá, {nome}! O vencimento cadastrado do pacote {pacote} foi ontem, {data}, e ainda não registramos uma nova data de renovação.\\n\\nO valor para renovar é {valor}.\\n{instrucoes_pagamento}\\n\\nSe já pagou, envie o comprovante para conferirmos. Se precisar de ajuda, é só responder esta mensagem.","grupo":"Olá, {nome}! O vencimento cadastrado dos pacotes abaixo foi ontem, {data}, e ainda não registramos uma nova data de renovação:\\n{lista_pacotes}\\n\\nO valor total para renovar é {total}.\\n{instrucoes_pagamento}\\n\\nSe já pagou, envie o comprovante para conferirmos. Se precisar de ajuda, é só responder esta mensagem."}}', NOW());
INSERT IGNORE INTO gr_configuracoes (chave, valor, atualizado_em) VALUES ('modelos', '{"antes":{"individual":"","grupo":""},"dia":{"individual":"","grupo":""},"depois":{"individual":"","grupo":""}}', NOW());
INSERT IGNORE INTO gr_configuracoes (chave, valor, atualizado_em) VALUES ('modelo_idioma', '"pt_BR"', NOW());

INSERT IGNORE INTO gr_migracoes (versao, nome, checksum, aplicado_em) VALUES ('001', 'inicial', '9a1aaf147184bd58b8eda3fb388ddefc7cdcdfc8737c1535c70a5ec0a6dc830d', NOW());

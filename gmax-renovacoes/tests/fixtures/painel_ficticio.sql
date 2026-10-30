-- Esquema FICTÍCIO que imita um painel de gestão (clientes, pacotes, recebimentos, custos).
-- Usado só nos testes. Não representa dados reais.
CREATE TABLE clientes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  telefone VARCHAR(30) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'ativo',
  excluido_em DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pacotes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  nome VARCHAR(120) NOT NULL,
  pontos INT NOT NULL DEFAULT 1,
  servico VARCHAR(60) NULL,
  ciclo VARCHAR(20) NOT NULL DEFAULT 'mensal',
  preco_total DECIMAL(10,2) NOT NULL,
  custo_total DECIMAL(10,2) NOT NULL DEFAULT 0,
  vencimento DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'ativo',
  senha_acesso VARCHAR(60) NULL,
  url_lista VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recebimentos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  pacote_id INT NOT NULL,
  valor DECIMAL(10,2) NOT NULL,
  recebido_em DATE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE custos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pacote_id INT NULL,
  descricao VARCHAR(120) NOT NULL,
  valor DECIMAL(10,2) NOT NULL,
  data DATE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

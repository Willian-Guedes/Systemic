--oficina_db_mariadb.sql
-- ============================================================
--  Banco de Dados: oficina_db
--  Compatível com: MariaDB 10.5+
--  Gerado para importação via DBeaver
-- ============================================================

-- Criação e seleção do banco de dados
CREATE DATABASE IF NOT EXISTS oficina_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE oficina_db;

-- Desativa verificações de chave estrangeira durante a criação
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. Tabela: clientes
-- ============================================================
CREATE TABLE IF NOT EXISTS clientes (
    id_cliente    INT            NOT NULL AUTO_INCREMENT,
    nome_cliente  VARCHAR(255)   NOT NULL,
    foto_perfil VARCHAR(255) DEFAULT NULL, 
    CPF           VARCHAR(14)    NOT NULL,
    celular       VARCHAR(20)    NOT NULL,
    email         VARCHAR(255)   NOT NULL,
    senha         VARBINARY(255) NOT NULL,
    vip           TINYINT(1)     NOT NULL DEFAULT 0,
    PRIMARY KEY (id_cliente),
    UNIQUE KEY uq_clientes_cpf   (CPF),
    UNIQUE KEY uq_clientes_email (email)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. Tabela: veiculos
-- ============================================================
CREATE TABLE IF NOT EXISTS veiculos (
    id_veiculo  INT          NOT NULL AUTO_INCREMENT,
    marca       VARCHAR(100) NOT NULL,
    cor         VARCHAR(50)  NOT NULL,
    ano         VARCHAR(10)  NOT NULL,
    modelo      VARCHAR(100) NOT NULL,
    placa       VARCHAR(20)  NOT NULL,
    id_cliente  INT          NOT NULL,
    PRIMARY KEY (id_veiculo),
    UNIQUE KEY uq_veiculos_placa (placa),
    CONSTRAINT fk_veiculos_cliente
        FOREIGN KEY (id_cliente)
        REFERENCES clientes (id_cliente)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. Tabela: produtos
-- ============================================================
CREATE TABLE IF NOT EXISTS produtos (
    id_produto  INT            NOT NULL AUTO_INCREMENT,
    nome        VARCHAR(255)   NOT NULL,
    preco       DECIMAL(10, 2) NOT NULL,
    stock       INT            DEFAULT 0,
    imagem      TEXT           NOT NULL,
    categoria   VARCHAR(100)   NOT NULL,
    detalhes    TEXT           NOT NULL,
    PRIMARY KEY (id_produto)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. Tabela: funcionarios
-- ============================================================
CREATE TABLE IF NOT EXISTS funcionarios (
    id_funcionario   INT            NOT NULL AUTO_INCREMENT,
    nome_funcionario VARCHAR(255)   NOT NULL,
    email            VARCHAR(255)   NOT NULL,
    nivel_de_acesso  VARCHAR(50)    NOT NULL,
    senha            VARBINARY(255) NOT NULL,
    PRIMARY KEY (id_funcionario),
    UNIQUE KEY uq_funcionarios_email (email)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. Tabela: ordem
-- ============================================================
CREATE TABLE IF NOT EXISTS ordem (
    id_ordem        INT            NOT NULL AUTO_INCREMENT,
    id_funcionario  INT,
    id_cliente      INT            NOT NULL,
    id_veiculo      INT            NOT NULL,
    tipo_ordem      VARCHAR(100)   NOT NULL,
    diagnostico     TEXT,
    abertura        DATETIME,
    prazo           DATE,
    fechamento      DATETIME,
    conclusao_ordem TEXT,
    mao_de_obra     DECIMAL(10, 2),
    orcamento       DECIMAL(10, 2),
    status          VARCHAR(50)    DEFAULT 'EM ABERTO',
    PRIMARY KEY (id_ordem),
    CONSTRAINT fk_ordem_funcionario
        FOREIGN KEY (id_funcionario)
        REFERENCES funcionarios (id_funcionario)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_ordem_cliente
        FOREIGN KEY (id_cliente)
        REFERENCES clientes (id_cliente)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_ordem_veiculo
        FOREIGN KEY (id_veiculo)
        REFERENCES veiculos (id_veiculo)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. Tabela: logs
-- ============================================================
CREATE TABLE IF NOT EXISTS logs (
    id_log          INT       NOT NULL AUTO_INCREMENT,
    id_funcionario  INT,
    detalhe         TEXT,
    momento_acao    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_log),
    CONSTRAINT fk_logs_funcionario
        FOREIGN KEY (id_funcionario)
        REFERENCES funcionarios (id_funcionario)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. Tabela: logs_fun
-- ============================================================
CREATE TABLE IF NOT EXISTS logs_fun (
    id_logs_fun INT NOT NULL AUTO_INCREMENT,
    id_func     INT,
    id_log      INT,
    PRIMARY KEY (id_logs_fun),
    CONSTRAINT fk_logs_fun_func
        FOREIGN KEY (id_func)
        REFERENCES funcionarios (id_funcionario)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_logs_fun_log
        FOREIGN KEY (id_log)
        REFERENCES logs (id_log)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. Tabela: funcionario_ordems
-- ============================================================
CREATE TABLE IF NOT EXISTS funcionario_ordems (
    id_funcionario_ordem INT NOT NULL AUTO_INCREMENT,
    id_ordem             INT NOT NULL,
    id_funcionario       INT NOT NULL,
    PRIMARY KEY (id_funcionario_ordem),
    CONSTRAINT fk_fun_ord_ordem
        FOREIGN KEY (id_ordem)
        REFERENCES ordem (id_ordem)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_fun_ord_func
        FOREIGN KEY (id_funcionario)
        REFERENCES funcionarios (id_funcionario)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. Tabela: historico_ordems
-- ============================================================
CREATE TABLE IF NOT EXISTS historico_ordems (
    id_historico INT      NOT NULL AUTO_INCREMENT,
    id_ordem     INT      NOT NULL,
    id_cliente   INT      NOT NULL,
    id_veiculo   INT      NOT NULL,
    abertura     DATETIME,
    PRIMARY KEY (id_historico),
    CONSTRAINT fk_hist_ordem
        FOREIGN KEY (id_ordem)
        REFERENCES ordem (id_ordem)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_hist_cliente
        FOREIGN KEY (id_cliente)
        REFERENCES clientes (id_cliente)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_hist_veiculo
        FOREIGN KEY (id_veiculo)
        REFERENCES veiculos (id_veiculo)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. Tabela: fornecedores
-- ============================================================
CREATE TABLE IF NOT EXISTS fornecedores (
    id_fornecedor    INT         NOT NULL AUTO_INCREMENT,
    nome_fornecedor  VARCHAR(255) NOT NULL,
    cnpj             VARCHAR(20)  NOT NULL,
    PRIMARY KEY (id_fornecedor),
    UNIQUE KEY uq_fornecedores_cnpj (cnpj)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 11. Tabela: pecas
-- ============================================================
CREATE TABLE IF NOT EXISTS pecas (
    id_peca       INT          NOT NULL AUTO_INCREMENT,
    nome_peca     VARCHAR(255) NOT NULL,
    quantidade    INT          NOT NULL DEFAULT 0,
    tipo          VARCHAR(100),
    id_fornecedor INT          NOT NULL,
    PRIMARY KEY (id_peca),
    CONSTRAINT fk_pecas_fornecedor
        FOREIGN KEY (id_fornecedor)
        REFERENCES fornecedores (id_fornecedor)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 12. Tabela: ordem_pecas
-- ============================================================
CREATE TABLE IF NOT EXISTS ordem_pecas (
    id_ordem_peca     INT            NOT NULL AUTO_INCREMENT,
    id_ordem          INT            NOT NULL,
    id_peca           INT,
    nome_peca         VARCHAR(255)   NOT NULL,
    quantidade_trocas INT            NOT NULL DEFAULT 1,
    valor_unitario    DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (id_ordem_peca),
    CONSTRAINT fk_ord_pec_peca
        FOREIGN KEY (id_peca)
        REFERENCES pecas (id_peca)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_ord_pec_ordem
        FOREIGN KEY (id_ordem)
        REFERENCES ordem (id_ordem)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS agendamentos (
    id             INT          NOT NULL AUTO_INCREMENT,
    nome           VARCHAR(255) NOT NULL,
    telefone       VARCHAR(30)  NOT NULL,
    email          VARCHAR(255),
    placa          VARCHAR(10),
    marca          VARCHAR(100) NOT NULL,
    modelo         VARCHAR(100) NOT NULL,
    ano            SMALLINT,
    combustivel    VARCHAR(30),
    km             INT,
    servico        VARCHAR(100) NOT NULL,
    sintomas       VARCHAR(255),
    descricao      TEXT,
    data_preferida DATE         NOT NULL,
    turno          VARCHAR(10),
    status         VARCHAR(20)  NOT NULL DEFAULT 'pendente',
    criado_em      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reativa verificações de chave estrangeira
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- FIM DO SCRIPT
-- ============================================================

-- Transporte (CT-e / MDF-e) - V1 Fundação: cadastros comuns e numeração.
-- Módulo isolado: nenhuma tabela existente é alterada. As configurações ficam em
-- app_config (chave 'transporte'); os módulos são liberados por empresa em Módulos do SaaS
-- ("CT-e" e "MDF-e", desligados por padrão). A emissão entra nas próximas versões.

-- Veículos usados no CT-e (modal rodoviário) e no MDF-e: tração (caminhão, van, carro)
-- e reboques/semirreboques. Proprietário terceiro = dados do dono e RNTRC dele.
CREATE TABLE IF NOT EXISTS transp_veiculos (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  tipo VARCHAR(10) NOT NULL DEFAULT 'TRACAO',
  codigo_interno VARCHAR(10) NULL,
  placa VARCHAR(7) NOT NULL,
  renavam VARCHAR(11) NULL,
  uf CHAR(2) NOT NULL,
  tara_kg INT NOT NULL DEFAULT 0,
  capacidade_kg INT NULL,
  capacidade_m3 INT NULL,
  tipo_rodado CHAR(2) NOT NULL DEFAULT '06',
  tipo_carroceria CHAR(2) NOT NULL DEFAULT '00',
  proprietario VARCHAR(10) NOT NULL DEFAULT 'PROPRIO',
  prop_tipo_documento VARCHAR(5) NULL,
  prop_documento VARCHAR(14) NULL,
  prop_nome VARCHAR(60) NULL,
  prop_ie VARCHAR(14) NULL,
  prop_uf CHAR(2) NULL,
  prop_rntrc VARCHAR(8) NULL,
  prop_tipo CHAR(1) NULL,
  motorista_id INT NULL,
  observacoes VARCHAR(255) NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_transp_veic_company (company_id, status),
  KEY idx_transp_veic_placa (company_id, placa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Motoristas (condutores do MDF-e). CPF obrigatório.
CREATE TABLE IF NOT EXISTS transp_motoristas (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  nome VARCHAR(60) NOT NULL,
  cpf VARCHAR(11) NOT NULL,
  cnh VARCHAR(11) NULL,
  cnh_categoria VARCHAR(3) NULL,
  cnh_validade DATE NULL,
  telefone VARCHAR(20) NULL,
  observacoes VARCHAR(255) NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_transp_mot_company (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Numeração por modelo (57 = CT-e, 58 = MDF-e) e série. A emissão reserva o número
-- com SELECT ... FOR UPDATE nesta linha (nunca repete número).
CREATE TABLE IF NOT EXISTS transp_numeracao (
  company_id INT NOT NULL,
  modelo CHAR(2) NOT NULL,
  serie INT NOT NULL,
  proximo_numero INT NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (company_id, modelo, serie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auditoria administrativa do módulo (quem alterou o quê).
CREATE TABLE IF NOT EXISTS transp_auditoria (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  usuario_id INT NULL,
  acao VARCHAR(30) NOT NULL,
  entidade VARCHAR(40) NOT NULL,
  entidade_id INT NULL,
  dados_anteriores_json TEXT NULL,
  dados_novos_json TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_transp_aud_company (company_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

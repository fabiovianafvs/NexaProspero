-- Nexa Ponto - V2: coletores, marcação online e Armazenamento de Registro de Ponto (ARP).
-- A marcação gravada em ponto_arp_marcacoes NUNCA é alterada nem apagada: o sistema só
-- insere. NSR por estabelecimento (ponto_arp_nsr, gerado com bloqueio de linha) e código
-- hash SHA-256 encadeado conforme o leiaute do AFD (registro tipo 7 do REP-P).

-- PIN do colaborador (guardado só como hash) e controle de tentativas.
SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ponto_colaboradores' AND column_name='pin_hash')=0,
  'ALTER TABLE `ponto_colaboradores` ADD COLUMN `pin_hash` VARCHAR(255) NULL, ADD COLUMN `pin_tentativas` INT NOT NULL DEFAULT 0, ADD COLUMN `pin_bloqueado_ate` DATETIME NULL', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

-- Coletor: aparelho autorizado a registrar ponto (tablet/totem/computador da empresa).
-- O aparelho guarda um código secreto num cookie; aqui fica só o hash dele.
CREATE TABLE IF NOT EXISTS ponto_coletores (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  estabelecimento_id INT NULL,
  local_id INT NULL,
  uuid CHAR(36) NOT NULL,
  descricao VARCHAR(80) NOT NULL,
  tipo VARCHAR(15) NOT NULL DEFAULT 'TABLET',
  identificador_afd CHAR(2) NOT NULL DEFAULT '02',
  permitir_pin TINYINT(1) NOT NULL DEFAULT 1,
  permitir_rfid TINYINT(1) NOT NULL DEFAULT 0,
  token_hash CHAR(64) NULL,
  pareamento_hash CHAR(64) NULL,
  pareamento_expira DATETIME NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_acesso DATETIME NULL,
  ultimo_ip VARCHAR(45) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_ponto_coletor_uuid (company_id, uuid),
  UNIQUE KEY uk_ponto_coletor_token (token_hash),
  UNIQUE KEY uk_ponto_coletor_par (pareamento_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sequência de NSR e último hash por estabelecimento (cada CNPJ/CPF tem a sua, começando em 1).
CREATE TABLE IF NOT EXISTS ponto_arp_nsr (
  company_id INT NOT NULL,
  estabelecimento_id INT NOT NULL,
  ultimo_nsr BIGINT NOT NULL DEFAULT 0,
  ultimo_hash CHAR(64) NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (company_id, estabelecimento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marcações (ARP). data_hora/fuso = horário oficial do servidor no fuso do estabelecimento.
-- coletor_codigo segue o AFD: 01 aplicativo mobile, 02 navegador, 03 aplicativo desktop,
-- 04 dispositivo eletrônico, 05 outro. offline: 0 = online (AFD campo 7).
CREATE TABLE IF NOT EXISTS ponto_arp_marcacoes (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  estabelecimento_id INT NOT NULL,
  nsr BIGINT NOT NULL,
  colaborador_id INT NOT NULL,
  cpf CHAR(11) NOT NULL,
  `data` DATE NOT NULL,
  data_hora DATETIME NOT NULL,
  fuso CHAR(5) NOT NULL,
  data_hora_gravacao DATETIME NOT NULL,
  fuso_gravacao CHAR(5) NOT NULL,
  coletor_codigo CHAR(2) NOT NULL,
  offline TINYINT(1) NOT NULL DEFAULT 0,
  coletor_id INT NULL,
  usuario_id INT NULL,
  identificacao VARCHAR(10) NOT NULL,
  intencao VARCHAR(20) NULL,
  request_uuid CHAR(36) NOT NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  precisao_m INT NULL,
  localizacao VARCHAR(20) NOT NULL DEFAULT 'INDISPONIVEL',
  local_id INT NULL,
  distancia_m INT NULL,
  geofence VARCHAR(25) NOT NULL DEFAULT 'LOCALIZACAO_INDISPONIVEL',
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  hash_anterior CHAR(64) NULL,
  hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_ponto_arp_nsr (company_id, estabelecimento_id, nsr),
  UNIQUE KEY uk_ponto_arp_req (company_id, request_uuid),
  KEY idx_ponto_arp_dia (company_id, `data`),
  KEY idx_ponto_arp_col (company_id, colaborador_id, data_hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conferências do relógio do servidor com a Hora Legal Brasileira (NTP.br).
CREATE TABLE IF NOT EXISTS ponto_clock_status (
  id BIGINT NOT NULL AUTO_INCREMENT,
  checked_at DATETIME NOT NULL,
  offset_ms INT NULL,
  source VARCHAR(80) NOT NULL,
  status VARCHAR(12) NOT NULL,
  detalhe VARCHAR(200) NULL,
  PRIMARY KEY (id),
  KEY idx_ponto_clock (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

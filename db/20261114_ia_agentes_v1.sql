-- Nexa Próspero - Agentes de IA (v1)
-- Execuções dos agentes (ex.: preparo de entrada de notas) e consumo da IA por empresa.
-- Idempotente: pode ser aplicado mais de uma vez.

CREATE TABLE IF NOT EXISTS ai_agent_runs (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  agent VARCHAR(40) NOT NULL,
  ref_type VARCHAR(20) NOT NULL,
  ref_id BIGINT NOT NULL,
  xml_import_id BIGINT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'PROCESSANDO',
  trigger_type VARCHAR(10) NOT NULL DEFAULT 'manual',
  user_id INT NULL,
  title VARCHAR(255) NULL,
  summary VARCHAR(500) NULL,
  details_json MEDIUMTEXT NULL,
  error_message TEXT NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_ai_run_ref (company_id, agent, ref_type, ref_id),
  KEY idx_ai_run_xml (company_id, xml_import_id),
  KEY idx_ai_run_status (company_id, agent, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_usage_log (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  agent VARCHAR(40) NOT NULL,
  model VARCHAR(60) NOT NULL,
  key_source VARCHAR(10) NOT NULL DEFAULT 'platform',
  input_tokens INT NOT NULL DEFAULT 0,
  output_tokens INT NOT NULL DEFAULT 0,
  ok TINYINT(1) NOT NULL DEFAULT 1,
  ref VARCHAR(80) NULL,
  error_message VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ai_usage_company (company_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

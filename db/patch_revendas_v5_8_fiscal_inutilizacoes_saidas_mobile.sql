-- Nexa Próspero v5.8
-- Inutilização de NF-e/NFC-e, relatório fiscal, fluxo de Saídas Fiscais e navegação da Venda Mobile.
-- Este patch é seguro para reexecução. Não remove nem altera dados existentes.

CREATE TABLE IF NOT EXISTS fiscal_inutilizations (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  model VARCHAR(2) NOT NULL,
  series INT NOT NULL,
  start_number BIGINT NOT NULL,
  end_number BIGINT NOT NULL,
  fiscal_year CHAR(2) NOT NULL,
  environment VARCHAR(20) NOT NULL DEFAULT 'HOMOLOGACAO',
  justification VARCHAR(255) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'PENDENTE',
  status_code VARCHAR(10) NULL,
  status_message VARCHAR(255) NULL,
  protocol_number VARCHAR(60) NULL,
  event_datetime DATETIME NULL,
  request_xml LONGTEXT NULL,
  response_xml LONGTEXT NULL,
  created_by BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_fiscal_inut_company_date (company_id, event_datetime, created_at),
  KEY idx_fiscal_inut_range (company_id, model, series, fiscal_year, start_number, end_number),
  KEY idx_fiscal_inut_status (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

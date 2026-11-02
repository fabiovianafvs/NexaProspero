CREATE TABLE IF NOT EXISTS nexa_person_source_map (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  source_system VARCHAR(40) NOT NULL,
  source_code VARCHAR(60) NOT NULL,
  person_id INT NOT NULL,
  source_document VARCHAR(30) NULL,
  source_name VARCHAR(180) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_nexa_person_source (company_id,source_system,source_code),
  KEY idx_nexa_person_source_person (company_id,person_id),
  KEY idx_nexa_person_source_document (company_id,source_document)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_finance_import_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  import_type VARCHAR(40) NOT NULL,
  source_system VARCHAR(40) NOT NULL,
  original_file_name VARCHAR(255) NULL,
  source_file_sha256 CHAR(64) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'PREVIEW',
  rows_total INT NOT NULL DEFAULT 0,
  rows_valid INT NOT NULL DEFAULT 0,
  rows_imported INT NOT NULL DEFAULT 0,
  rows_skipped INT NOT NULL DEFAULT 0,
  rows_errors INT NOT NULL DEFAULT 0,
  amount_total DECIMAL(18,2) NOT NULL DEFAULT 0,
  paid_total DECIMAL(18,2) NOT NULL DEFAULT 0,
  open_total DECIMAL(18,2) NOT NULL DEFAULT 0,
  created_by_user_id INT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  notes TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_nexa_fin_import_company (company_id,import_type,started_at),
  KEY idx_nexa_fin_import_hash (company_id,source_system,source_file_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_finance_import_receivable_map (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  source_system VARCHAR(40) NOT NULL,
  source_key CHAR(64) NOT NULL,
  source_row_digest CHAR(64) NOT NULL,
  source_occurrence INT NOT NULL DEFAULT 1,
  source_code VARCHAR(60) NOT NULL,
  receivable_id INT NOT NULL,
  batch_id BIGINT UNSIGNED NULL,
  source_document_number VARCHAR(60) NULL,
  source_installment VARCHAR(30) NULL,
  source_issue_date DATE NULL,
  source_due_date DATE NULL,
  source_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  source_open_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_nexa_fin_rec_source (company_id,source_system,source_key),
  KEY idx_nexa_fin_rec_receivable (company_id,receivable_id),
  KEY idx_nexa_fin_rec_batch (company_id,batch_id),
  KEY idx_nexa_fin_rec_source_code (company_id,source_system,source_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES ('data_import_v11_45',20260930450,NOW(),'V11.45 - mapa de clientes e importacao segura de contas a receber')
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

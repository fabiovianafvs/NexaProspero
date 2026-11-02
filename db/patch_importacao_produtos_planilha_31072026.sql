-- Nexa Próspero
-- Central de Importação por conectores + produtos por planilha
-- Banco principal: nexaprospero
-- Data: 31/07/2026

START TRANSACTION;

CREATE TABLE IF NOT EXISTS product_import_tax_map_sources (
  company_id BIGINT NOT NULL,
  source_type VARCHAR(40) NOT NULL,
  source_tax_code VARCHAR(60) NOT NULL,
  target_tax_profile_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (company_id, source_type, source_tax_code),
  KEY idx_product_import_tax_sources_profile (company_id, source_type, target_tax_profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_import_product_map (
  company_id BIGINT NOT NULL,
  source_type VARCHAR(40) NOT NULL,
  source_code VARCHAR(80) NOT NULL,
  product_id BIGINT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (company_id, source_type, source_code),
  KEY idx_product_import_source_product (company_id, source_type, product_id),
  KEY idx_product_import_product (company_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_import_files (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT NOT NULL,
  source_type VARCHAR(40) NOT NULL,
  token CHAR(64) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_path VARCHAR(500) NOT NULL,
  extension VARCHAR(12) NOT NULL,
  sheet_name VARCHAR(180) NULL,
  row_count INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'READY',
  created_by BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  last_imported_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_product_import_file_token (token),
  KEY idx_product_import_files_company (company_id, created_at),
  KEY idx_product_import_files_expire (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql_add_source_type = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'product_import_runs' AND column_name = 'source_type') = 0,
  "ALTER TABLE product_import_runs ADD COLUMN source_type VARCHAR(40) NOT NULL DEFAULT 'PROSPERO_FIREBIRD' AFTER company_id",
  "SELECT 1"
);
PREPARE stmt_add_source_type FROM @sql_add_source_type;
EXECUTE stmt_add_source_type;
DEALLOCATE PREPARE stmt_add_source_type;

SET @sql_add_source_file = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'product_import_runs' AND column_name = 'source_file_name') = 0,
  "ALTER TABLE product_import_runs ADD COLUMN source_file_name VARCHAR(255) NULL AFTER source_database",
  "SELECT 1"
);
PREPARE stmt_add_source_file FROM @sql_add_source_file;
EXECUTE stmt_add_source_file;
DEALLOCATE PREPARE stmt_add_source_file;

INSERT IGNORE INTO product_import_tax_map_sources
  (company_id, source_type, source_tax_code, target_tax_profile_id, created_at, updated_at)
SELECT company_id, 'PROSPERO_FIREBIRD', source_tax_code, target_tax_profile_id, created_at, updated_at
FROM product_import_tax_map;

UPDATE product_import_runs
SET source_type = 'PROSPERO_FIREBIRD'
WHERE source_type IS NULL OR source_type = '';

COMMIT;

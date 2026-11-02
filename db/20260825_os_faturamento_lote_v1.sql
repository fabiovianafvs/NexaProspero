-- Nexa Próspero - Faturamento em Lote de O.S. v1
-- Banco alvo: nexaprospero
-- Compatível com MariaDB/MySQL.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS os_batch_invoice_sequences (
  company_id INT NOT NULL,
  last_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS os_batch_invoices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  invoice_number BIGINT UNSIGNED NOT NULL,
  person_id INT NOT NULL,
  person_name_snapshot VARCHAR(180) NOT NULL,
  person_document_snapshot VARCHAR(30) NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  status_id_filter INT NULL,
  issue_date DATE NOT NULL,
  due_date DATE NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'CONFERENCIA',
  total_products DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  total_services DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  addition_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  total_general DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  payment_method_id INT NULL,
  pedido_id BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  approved_at DATETIME NULL,
  approved_by INT NULL,
  billed_at DATETIME NULL,
  billed_by INT NULL,
  cancelled_at DATETIME NULL,
  cancelled_by INT NULL,
  created_by INT NULL,
  updated_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_os_batch_invoice_number (company_id, invoice_number),
  KEY idx_os_batch_invoice_person (company_id, person_id, issue_date),
  KEY idx_os_batch_invoice_status (company_id, status, issue_date),
  KEY idx_os_batch_invoice_pedido (company_id, pedido_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS os_batch_invoice_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  invoice_id BIGINT UNSIGNED NOT NULL,
  os_order_id BIGINT UNSIGNED NOT NULL,
  os_number_snapshot BIGINT UNSIGNED NOT NULL,
  service_date_snapshot DATE NOT NULL,
  status_name_snapshot VARCHAR(100) NULL,
  title_snapshot VARCHAR(180) NULL,
  total_products DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  total_services DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  addition_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  total_general DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_os_batch_invoice_order (company_id, invoice_id, os_order_id),
  KEY idx_os_batch_order_lookup (company_id, os_order_id, invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Liga a O.S. à fatura consolidada sem reutilizar pedido_id.
-- Isso impede que o Pedido consolidado seja confundido com o faturamento individual da O.S.
SET @has_batch_invoice_id := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'os_orders' AND column_name = 'batch_invoice_id'
);
SET @sql_batch_invoice_id := IF(
  @has_batch_invoice_id = 0,
  'ALTER TABLE os_orders ADD COLUMN batch_invoice_id BIGINT UNSIGNED NULL AFTER pedido_id',
  'SELECT 1'
);
PREPARE stmt_batch_invoice_id FROM @sql_batch_invoice_id;
EXECUTE stmt_batch_invoice_id;
DEALLOCATE PREPARE stmt_batch_invoice_id;

SET @has_idx_os_batch_invoice := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'os_orders' AND index_name = 'idx_os_batch_invoice'
);
SET @sql_idx_os_batch_invoice := IF(
  @has_idx_os_batch_invoice = 0,
  'ALTER TABLE os_orders ADD KEY idx_os_batch_invoice (company_id, batch_invoice_id)',
  'SELECT 1'
);
PREPARE stmt_idx_os_batch_invoice FROM @sql_idx_os_batch_invoice;
EXECUTE stmt_idx_os_batch_invoice;
DEALLOCATE PREPARE stmt_idx_os_batch_invoice;

CREATE TABLE IF NOT EXISTS nexa_schema_versions (
  module_name VARCHAR(80) NOT NULL,
  version_number BIGINT UNSIGNED NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes VARCHAR(255) NULL,
  PRIMARY KEY (module_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES ('ordem_servico_faturamento_lote',2026082501,NOW(),'Faturamento em lote de O.S. v1 - fatura de conferência e pedido consolidado')
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

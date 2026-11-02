-- Nexa Próspero V11.47.2 - Correção de cursor da migration V11.47 para MySQL 8.0
-- Aplicar exclusivamente pelo Atualizador do Banco.
-- Mantém o mesmo ID/versão da V11.47 porque a tentativa anterior falhou antes de registrar a versão.
-- Compatível com estado parcial: CREATE TABLE pode já ter sido executado e qualquer coluna pode já existir.

CREATE TABLE IF NOT EXISTS order_cancellation_effects (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  order_id BIGINT NOT NULL,
  fiscal_document_id BIGINT NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'MANUAL',
  status VARCHAR(20) NOT NULL DEFAULT 'PROCESSING',
  reason VARCHAR(255) NULL,
  order_status_before VARCHAR(30) NULL,
  stock_reversed_qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  finance_reversed_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  cash_reversed_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  processed_by INT NULL,
  processed_at DATETIME NULL,
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_order_cancellation_company_order (company_id, order_id),
  KEY idx_order_cancellation_fiscal (company_id, fiscal_document_id),
  KEY idx_order_cancellation_status (company_id, status, processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MySQL 8.0 não aceita ALTER TABLE ... ADD COLUMN IF NOT EXISTS.
-- Cada coluna é adicionada somente quando não existir, usando SQL preparado controlado.
-- Quando a coluna já existe usamos DO 0, que não produz result set e evita cursor pendente no PDO.

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='canceled_at')=0,
  'ALTER TABLE `pedidos` ADD COLUMN `canceled_at` DATETIME NULL', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='cancel_reason')=0,
  'ALTER TABLE `pedidos` ADD COLUMN `cancel_reason` VARCHAR(255) NULL', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='cancellation_source')=0,
  'ALTER TABLE `pedidos` ADD COLUMN `cancellation_source` VARCHAR(30) NULL', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='canceled_fiscal_document_id')=0,
  'ALTER TABLE `pedidos` ADD COLUMN `canceled_fiscal_document_id` BIGINT NULL', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='gestao_order_payments' AND column_name='receivable_ids')=0,
  'ALTER TABLE `gestao_order_payments` ADD COLUMN `receivable_ids` TEXT NULL', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='gestao_order_payments' AND column_name='canceled_at')=0,
  'ALTER TABLE `gestao_order_payments` ADD COLUMN `canceled_at` DATETIME NULL', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='gestao_order_payments' AND column_name='canceled_reason')=0,
  'ALTER TABLE `gestao_order_payments` ADD COLUMN `canceled_reason` VARCHAR(255) NULL', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='gestao_order_payments' AND column_name='reversal_movement_id')=0,
  'ALTER TABLE `gestao_order_payments` ADD COLUMN `reversal_movement_id` BIGINT NULL', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='gestao_order_payments' AND column_name='reversal_cash_operation_id')=0,
  'ALTER TABLE `gestao_order_payments` ADD COLUMN `reversal_cash_operation_id` BIGINT NULL', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='finance_receivables' AND column_name='canceled_at')=0,
  'ALTER TABLE `finance_receivables` ADD COLUMN `canceled_at` DATETIME NULL', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='finance_receivables' AND column_name='canceled_reason')=0,
  'ALTER TABLE `finance_receivables` ADD COLUMN `canceled_reason` VARCHAR(255) NULL', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_documents' AND column_name='internal_cancellation_status')=0,
  'ALTER TABLE `fiscal_documents` ADD COLUMN `internal_cancellation_status` VARCHAR(20) NULL', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_documents' AND column_name='internal_cancellation_message')=0,
  'ALTER TABLE `fiscal_documents` ADD COLUMN `internal_cancellation_message` VARCHAR(255) NULL', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_documents' AND column_name='internal_cancellation_at')=0,
  'ALTER TABLE `fiscal_documents` ADD COLUMN `internal_cancellation_at` DATETIME NULL', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

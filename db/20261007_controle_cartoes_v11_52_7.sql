-- Nexa Próspero V11.52.7 — Controle de Cartões
-- Agenda de recebimentos por parcela/taxa e base para futura conciliação com adquirentes/TEF.
-- Aplicar exclusivamente pelo Atualizador Controlado do Banco.

CREATE TABLE IF NOT EXISTS finance_card_payment_plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  payment_method_id INT NOT NULL,
  installments SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  first_receipt_days INT NOT NULL DEFAULT 30,
  interval_days INT NOT NULL DEFAULT 30,
  fee_percent DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_card_plan_method_installments (company_id,payment_method_id,installments),
  KEY idx_card_plan_company_method (company_id,payment_method_id,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_card_sales (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  order_id BIGINT NOT NULL,
  order_payment_id BIGINT NULL,
  person_id INT NULL,
  payment_method_id INT NOT NULL,
  sale_date DATE NOT NULL,
  gross_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  installments SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  fee_percent DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
  fee_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  net_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  acquirer_name VARCHAR(120) NULL,
  acquirer_cnpj VARCHAR(20) NULL,
  card_brand VARCHAR(20) NULL,
  tef_integration VARCHAR(10) NULL,
  nsu VARCHAR(100) NULL,
  authorization_code VARCHAR(100) NULL,
  provider_transaction_id VARCHAR(160) NULL,
  terminal_id VARCHAR(100) NULL,
  merchant_id VARCHAR(100) NULL,
  bank_account_id INT NULL,
  chart_account_id INT NULL,
  cost_center_id INT NULL,
  fee_chart_account_id INT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'ABERTO',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_card_sale_order_payment (company_id,order_payment_id),
  KEY idx_card_sale_order (company_id,order_id),
  KEY idx_card_sale_status (company_id,status,sale_date),
  KEY idx_card_sale_tef (company_id,nsu,authorization_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_card_installments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  card_sale_id BIGINT UNSIGNED NOT NULL,
  installment_no SMALLINT UNSIGNED NOT NULL,
  total_installments SMALLINT UNSIGNED NOT NULL,
  due_date DATE NOT NULL,
  gross_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  fee_percent DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
  fee_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  net_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  status VARCHAR(20) NOT NULL DEFAULT 'ABERTO',
  payment_date DATE NULL,
  received_at DATETIME NULL,
  bank_account_id INT NULL,
  credit_movement_id BIGINT NULL,
  fee_movement_id BIGINT NULL,
  settled_by INT NULL,
  settlement_ref VARCHAR(160) NULL,
  settlement_notes VARCHAR(500) NULL,
  acquirer_receivable_id VARCHAR(160) NULL,
  acquirer_nsu VARCHAR(100) NULL,
  acquirer_authorization_code VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_card_installment_sale_no (company_id,card_sale_id,installment_no),
  KEY idx_card_installment_due (company_id,status,due_date),
  KEY idx_card_installment_payment (company_id,payment_date),
  KEY idx_card_installment_acquirer (company_id,acquirer_receivable_id,acquirer_nsu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Colunas da forma de pagamento. Compatível com reexecução parcial no MySQL 8.0.
SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='finance_payment_methods' AND column_name='card_control_enabled')=0,
  'ALTER TABLE `finance_payment_methods` ADD COLUMN `card_control_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `allow_installments`', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='finance_payment_methods' AND column_name='card_acquirer_name')=0,
  'ALTER TABLE `finance_payment_methods` ADD COLUMN `card_acquirer_name` VARCHAR(120) NULL AFTER `card_control_enabled`', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='finance_payment_methods' AND column_name='card_fee_chart_account_id')=0,
  'ALTER TABLE `finance_payment_methods` ADD COLUMN `card_fee_chart_account_id` INT NULL AFTER `card_acquirer_name`', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='gestao_order_payments' AND column_name='card_sale_id')=0,
  'ALTER TABLE `gestao_order_payments` ADD COLUMN `card_sale_id` BIGINT NULL AFTER `receivable_ids`', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

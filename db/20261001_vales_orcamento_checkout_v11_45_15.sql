-- Nexa Próspero V11.45.15 - Vales, orçamento consumido e permissões de Checkout
-- Aplicar exclusivamente pelo Atualizador do Banco.

ALTER TABLE orcamentos
  ADD COLUMN converted_order_id BIGINT NULL AFTER status,
  ADD COLUMN converted_at DATETIME NULL AFTER converted_order_id,
  ADD COLUMN converted_by INT NULL AFTER converted_at,
  ADD KEY idx_orcamentos_company_converted (company_id, converted_order_id);

CREATE TABLE finance_vouchers (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  code VARCHAR(40) NOT NULL,
  person_id INT NOT NULL,
  source_type VARCHAR(40) NOT NULL DEFAULT 'DEVOLUCAO_CLIENTE',
  source_id BIGINT NULL,
  source_document_number VARCHAR(80) NULL,
  issue_date DATE NOT NULL,
  original_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  used_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  balance_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  status VARCHAR(20) NOT NULL DEFAULT 'ABERTO',
  notes TEXT NULL,
  created_by INT NULL,
  canceled_by INT NULL,
  canceled_at DATETIME NULL,
  cancel_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_finance_vouchers_company_code (company_id, code),
  UNIQUE KEY ux_finance_vouchers_source (company_id, source_type, source_id),
  KEY idx_finance_vouchers_person_balance (company_id, person_id, status, balance_amount),
  KEY idx_finance_vouchers_issue (company_id, issue_date, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE finance_voucher_usages (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  voucher_id BIGINT NOT NULL,
  order_id BIGINT NOT NULL,
  payment_method_id INT NULL,
  amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  status VARCHAR(20) NOT NULL DEFAULT 'ATIVO',
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reversed_at DATETIME NULL,
  reversed_by INT NULL,
  reverse_reason VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_finance_voucher_usages_voucher (company_id, voucher_id, status),
  KEY idx_finance_voucher_usages_order (company_id, order_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO finance_payment_methods
  (company_id,code,name,type,default_bank_account_id,default_chart_account_id,default_cost_center_id,generate_receivable,allow_installments,default_installments,first_due_days,keep_due_day,affects_cash,require_open_cash,allow_change,is_active,sort_order,notes)
SELECT c.id,'VALE','Vale','VALE',NULL,NULL,NULL,0,0,1,0,1,0,0,0,1,60,'Crédito do cliente gerado por devolução'
  FROM companies c
 WHERE NOT EXISTS (
   SELECT 1 FROM finance_payment_methods fpm
    WHERE fpm.company_id=c.id AND (UPPER(fpm.type)='VALE' OR UPPER(fpm.code)='VALE')
 );

UPDATE finance_payment_methods
   SET nfce_payment_type='21', nfce_payment_indicator='0'
 WHERE UPPER(type)='VALE' OR UPPER(code)='VALE';

INSERT INTO nexa_schema_versions (module_name, version_number, applied_at, notes)
VALUES ('vouchers_quote_checkout', 202610014515, NOW(), 'V11.45.15 - vales, consumo de orçamento e permissões de checkout')
ON DUPLICATE KEY UPDATE
  version_number = VALUES(version_number),
  applied_at = VALUES(applied_at),
  notes = VALUES(notes);

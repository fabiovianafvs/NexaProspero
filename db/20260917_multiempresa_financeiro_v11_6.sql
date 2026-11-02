-- Nexa Próspero - Multiempresa Financeiro V11.6
-- Data: 17/09/2026
-- Camada de parametrização financeira para formas de pagamento já existentes.
-- NÃO altera finance_payment_methods nem código de PDV/NFC-e/Boletos.

CREATE TABLE IF NOT EXISTS finance_payment_method_defaults (
  company_id INT NOT NULL,
  payment_method_id INT NOT NULL,
  bank_account_id INT NULL,
  updated_by_user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (company_id,payment_method_id),
  KEY idx_fin_pmd_bank (company_id,bank_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES ('multiempresa_contexto',2026091724,NOW(),'V11.6 - Parametrizacao financeira isolada por forma de pagamento; cadastro operacional do PDV/NFC-e/Boletos permanece inalterado')
ON DUPLICATE KEY UPDATE version_number=GREATEST(version_number,VALUES(version_number)),applied_at=NOW(),notes=VALUES(notes);

-- Nexa Próspero - V11.12 Acompanhamento Itaú Multiempresa protegido
-- Data: 18/09/2026
-- Escopo: consulta individual de PROCESSANDO_API / VERIFICAR_API pelo Nosso Número,
--         sem reenvio da cobrança e com auditoria completa da consulta.
-- Idempotente: não altera títulos financeiros, valores, Nosso Número ou cobranças existentes.

SET @db := DATABASE();

SET @sql := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='finance_bank_billing_configs' AND COLUMN_NAME='itau_cash_client_id'),
  'UPDATE nexa_schema_versions SET version_number=version_number WHERE 1=0',
  'ALTER TABLE finance_bank_billing_configs ADD COLUMN itau_cash_client_id VARCHAR(255) NULL AFTER api_client_secret'
);
PREPARE nexa_stmt FROM @sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='finance_bank_billing_configs' AND COLUMN_NAME='itau_cash_client_secret'),
  'UPDATE nexa_schema_versions SET version_number=version_number WHERE 1=0',
  'ALTER TABLE finance_bank_billing_configs ADD COLUMN itau_cash_client_secret VARCHAR(500) NULL AFTER itau_cash_client_id'
);
PREPARE nexa_stmt FROM @sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='finance_boleto_charges' AND COLUMN_NAME='api_last_check_at'),
  'UPDATE nexa_schema_versions SET version_number=version_number WHERE 1=0',
  'ALTER TABLE finance_boleto_charges ADD COLUMN api_last_check_at DATETIME NULL AFTER api_request_hash'
);
PREPARE nexa_stmt FROM @sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='finance_boleto_charges' AND COLUMN_NAME='api_last_check_by_user_id'),
  'UPDATE nexa_schema_versions SET version_number=version_number WHERE 1=0',
  'ALTER TABLE finance_boleto_charges ADD COLUMN api_last_check_by_user_id INT NULL AFTER api_last_check_at'
);
PREPARE nexa_stmt FROM @sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='finance_boleto_charges' AND COLUMN_NAME='api_last_check_http_status'),
  'UPDATE nexa_schema_versions SET version_number=version_number WHERE 1=0',
  'ALTER TABLE finance_boleto_charges ADD COLUMN api_last_check_http_status INT NULL AFTER api_last_check_by_user_id'
);
PREPARE nexa_stmt FROM @sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='finance_boleto_charges' AND COLUMN_NAME='api_last_check_result'),
  'UPDATE nexa_schema_versions SET version_number=version_number WHERE 1=0',
  'ALTER TABLE finance_boleto_charges ADD COLUMN api_last_check_result VARCHAR(30) NULL AFTER api_last_check_http_status'
);
PREPARE nexa_stmt FROM @sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='finance_boleto_charges' AND COLUMN_NAME='api_last_check_correlation_id'),
  'UPDATE nexa_schema_versions SET version_number=version_number WHERE 1=0',
  'ALTER TABLE finance_boleto_charges ADD COLUMN api_last_check_correlation_id VARCHAR(80) NULL AFTER api_last_check_result'
);
PREPARE nexa_stmt FROM @sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='finance_boleto_charges' AND COLUMN_NAME='api_last_check_message'),
  'UPDATE nexa_schema_versions SET version_number=version_number WHERE 1=0',
  'ALTER TABLE finance_boleto_charges ADD COLUMN api_last_check_message VARCHAR(500) NULL AFTER api_last_check_correlation_id'
);
PREPARE nexa_stmt FROM @sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='finance_boleto_charges' AND COLUMN_NAME='api_last_check_response'),
  'UPDATE nexa_schema_versions SET version_number=version_number WHERE 1=0',
  'ALTER TABLE finance_boleto_charges ADD COLUMN api_last_check_response LONGTEXT NULL AFTER api_last_check_message'
);
PREPARE nexa_stmt FROM @sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES ('finance_itau_multiempresa',20260918120,NOW(),'V11.12 - Acompanhamento Itaú Multiempresa protegido: consulta individual de estados incertos por Beneficiário + Carteira + Nosso Número, sem reenvio')
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

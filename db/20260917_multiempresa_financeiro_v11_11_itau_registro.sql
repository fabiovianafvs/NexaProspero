-- Nexa Próspero - V11.11 Registro/Reprocessamento Itaú Multiempresa protegido
-- Data: 17/09/2026
-- Escopo: auditoria e trava lógica das tentativas efetivas de registro/reprocessamento Itaú no F11.
-- Idempotente: não altera títulos, valores, Nosso Número existente nem dados bancários já registrados.

SET @db := DATABASE();

SET @sql := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='finance_boleto_charges' AND COLUMN_NAME='api_attempt_count'),
  'UPDATE nexa_schema_versions SET version_number=version_number WHERE 1=0',
  'ALTER TABLE finance_boleto_charges ADD COLUMN api_attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER api_correlation_id'
);
PREPARE nexa_stmt FROM @sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='finance_boleto_charges' AND COLUMN_NAME='api_last_action'),
  'UPDATE nexa_schema_versions SET version_number=version_number WHERE 1=0',
  'ALTER TABLE finance_boleto_charges ADD COLUMN api_last_action VARCHAR(20) NULL AFTER api_attempt_count'
);
PREPARE nexa_stmt FROM @sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='finance_boleto_charges' AND COLUMN_NAME='api_last_attempt_at'),
  'UPDATE nexa_schema_versions SET version_number=version_number WHERE 1=0',
  'ALTER TABLE finance_boleto_charges ADD COLUMN api_last_attempt_at DATETIME NULL AFTER api_last_action'
);
PREPARE nexa_stmt FROM @sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='finance_boleto_charges' AND COLUMN_NAME='api_last_attempt_by_user_id'),
  'UPDATE nexa_schema_versions SET version_number=version_number WHERE 1=0',
  'ALTER TABLE finance_boleto_charges ADD COLUMN api_last_attempt_by_user_id INT NULL AFTER api_last_attempt_at'
);
PREPARE nexa_stmt FROM @sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='finance_boleto_charges' AND COLUMN_NAME='api_request_hash'),
  'UPDATE nexa_schema_versions SET version_number=version_number WHERE 1=0',
  'ALTER TABLE finance_boleto_charges ADD COLUMN api_request_hash CHAR(64) NULL AFTER api_last_attempt_by_user_id'
);
PREPARE nexa_stmt FROM @sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES ('finance_itau_multiempresa',20260917110,NOW(),'V11.11 - Registro/Reprocessamento Itaú Multiempresa protegido: auditoria de tentativas, fingerprint do payload e trava lógica por status')
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

-- Nexa Próspero - Financeiro V11.6.1
-- Data: 17/09/2026
-- Hotfix do Estorno Protegido.
-- Objetivo: garantir, de forma idempotente, somente a estrutura de auditoria da baixa.
-- Não altera títulos, baixas existentes, movimentos existentes, PDV, Boletos ou Conciliação.

SET @db := DATABASE();

SET @sql := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='finance_settlements' AND COLUMN_NAME='status'),
  'SELECT 1',
  "ALTER TABLE finance_settlements ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'ATIVO' AFTER movement_id"
);
PREPARE nexa_stmt FROM @sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='finance_settlements' AND COLUMN_NAME='reversed_at'),
  'SELECT 1',
  'ALTER TABLE finance_settlements ADD COLUMN reversed_at DATETIME NULL AFTER status'
);
PREPARE nexa_stmt FROM @sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='finance_settlements' AND COLUMN_NAME='reversed_by_user_id'),
  'SELECT 1',
  'ALTER TABLE finance_settlements ADD COLUMN reversed_by_user_id INT NULL AFTER reversed_at'
);
PREPARE nexa_stmt FROM @sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='finance_settlements' AND COLUMN_NAME='reversal_reason'),
  'SELECT 1',
  'ALTER TABLE finance_settlements ADD COLUMN reversal_reason VARCHAR(500) NULL AFTER reversed_by_user_id'
);
PREPARE nexa_stmt FROM @sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='finance_settlements' AND COLUMN_NAME='reversal_movement_id'),
  'SELECT 1',
  'ALTER TABLE finance_settlements ADD COLUMN reversal_movement_id INT NULL AFTER reversal_reason'
);
PREPARE nexa_stmt FROM @sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

UPDATE finance_settlements SET status='ATIVO' WHERE status IS NULL OR status='';

SET @sql := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='finance_settlements' AND INDEX_NAME='idx_fin_set_status'),
  'SELECT 1',
  'CREATE INDEX idx_fin_set_status ON finance_settlements (company_id,status,origin_type,origin_id)'
);
PREPARE nexa_stmt FROM @sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='finance_settlements' AND INDEX_NAME='idx_fin_set_reversal_movement'),
  'SELECT 1',
  'CREATE INDEX idx_fin_set_reversal_movement ON finance_settlements (company_id,reversal_movement_id)'
);
PREPARE nexa_stmt FROM @sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES ('multiempresa_contexto',2026091725,NOW(),'V11.6.1 - Hotfix estorno: auditoria idempotente de finance_settlements e contra-lancamento ESTORNO_BAIXA; estorno usa a mesma permissao/escopo da baixa')
ON DUPLICATE KEY UPDATE version_number=GREATEST(version_number,VALUES(version_number)),applied_at=NOW(),notes=VALUES(notes);

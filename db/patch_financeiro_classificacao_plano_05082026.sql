-- Nexa Próspero
-- Plano de contas por classificação hierárquica
-- Data: 05/08/2026
-- Banco principal: nexaprospero

SET @schema_name := DATABASE();

SET @has_column := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'finance_chart_accounts'
    AND COLUMN_NAME = 'classification'
);

SET @sql_add_column := IF(
  @has_column = 0,
  'ALTER TABLE finance_chart_accounts ADD COLUMN classification VARCHAR(80) NULL AFTER code',
  'SELECT 1'
);
PREPARE stmt_add_column FROM @sql_add_column;
EXECUTE stmt_add_column;
DEALLOCATE PREPARE stmt_add_column;

UPDATE finance_chart_accounts
SET classification = code
WHERE classification IS NULL OR TRIM(classification) = '';

SET @has_index := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'finance_chart_accounts'
    AND INDEX_NAME = 'idx_fin_chart_classification'
);

SET @sql_add_index := IF(
  @has_index = 0,
  'CREATE INDEX idx_fin_chart_classification ON finance_chart_accounts (company_id, classification)',
  'SELECT 1'
);
PREPARE stmt_add_index FROM @sql_add_index;
EXECUTE stmt_add_index;
DEALLOCATE PREPARE stmt_add_index;

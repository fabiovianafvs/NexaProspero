-- Nexa Próspero
-- Correção: persistência de SEM GTIN, GTIN tributável e auditoria do cancelamento
-- Banco oficial: nexaprospero

SET @db_name := DATABASE();

SET @has_codigo_barras := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'produtos'
    AND COLUMN_NAME = 'codigo_barras'
);
SET @sql_codigo_barras := IF(
  @has_codigo_barras = 0,
  'ALTER TABLE produtos ADD COLUMN codigo_barras VARCHAR(80) NULL',
  'SELECT 1'
);
PREPARE stmt_codigo_barras FROM @sql_codigo_barras;
EXECUTE stmt_codigo_barras;
DEALLOCATE PREPARE stmt_codigo_barras;

SET @has_gtin_tributavel := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'produtos'
    AND COLUMN_NAME = 'gtin_tributavel'
);
SET @sql_gtin_tributavel := IF(
  @has_gtin_tributavel = 0,
  'ALTER TABLE produtos ADD COLUMN gtin_tributavel VARCHAR(20) NULL AFTER codigo_barras',
  'SELECT 1'
);
PREPARE stmt_gtin_tributavel FROM @sql_gtin_tributavel;
EXECUTE stmt_gtin_tributavel;
DEALLOCATE PREPARE stmt_gtin_tributavel;

UPDATE produtos
SET gtin_tributavel = 'SEM GTIN'
WHERE UPPER(TRIM(COALESCE(codigo_barras, ''))) = 'SEM GTIN'
  AND TRIM(COALESCE(gtin_tributavel, '')) = '';

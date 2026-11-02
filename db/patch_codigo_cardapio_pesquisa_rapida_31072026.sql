-- Nexa Próspero
-- Código do cardápio e pesquisa rápida no Comandas/PDV
-- Banco principal: nexaprospero

SET @schema_name := DATABASE();

SET @has_column := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = @schema_name
    AND table_name = 'produtos'
    AND column_name = 'codigo_cardapio'
);

SET @has_barcode_column := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = @schema_name
    AND table_name = 'produtos'
    AND column_name = 'codigo_barras'
);

SET @sql := IF(
  @has_column = 0,
  IF(
    @has_barcode_column > 0,
    'ALTER TABLE `produtos` ADD COLUMN `codigo_cardapio` VARCHAR(60) NULL AFTER `codigo_barras`',
    'ALTER TABLE `produtos` ADD COLUMN `codigo_cardapio` VARCHAR(60) NULL'
  ),
  'SELECT ''Coluna produtos.codigo_cardapio já existe'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE produtos
SET codigo_cardapio = NULL
WHERE codigo_cardapio IS NOT NULL
  AND TRIM(codigo_cardapio) = '';

SET @has_index := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = @schema_name
    AND table_name = 'produtos'
    AND index_name = 'ux_produtos_company_codigo_cardapio'
);

SET @sql := IF(
  @has_index = 0,
  'CREATE UNIQUE INDEX `ux_produtos_company_codigo_cardapio` ON `produtos` (`company_id`, `codigo_cardapio`)',
  'SELECT ''Índice ux_produtos_company_codigo_cardapio já existe'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT
  COUNT(*) AS total_produtos,
  SUM(CASE WHEN codigo_cardapio IS NOT NULL AND codigo_cardapio <> '' THEN 1 ELSE 0 END) AS produtos_com_codigo_cardapio
FROM produtos;

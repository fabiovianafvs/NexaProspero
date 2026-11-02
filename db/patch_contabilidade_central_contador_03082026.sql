-- Nexa Próspero - complemento do inventário contábil e Central do Contador
-- Data: 03/08/2026
-- Execute no banco principal nexaprospero quando a atualização anterior já estiver instalada.

SET NAMES utf8mb4;

DELIMITER $$
DROP PROCEDURE IF EXISTS nexa_add_column_if_missing$$
CREATE PROCEDURE nexa_add_column_if_missing(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.tables
    WHERE table_schema=DATABASE() AND table_name=p_table
  ) AND NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name=p_table AND column_name=p_column
  ) THEN
    SET @nexa_sql = CONCAT('ALTER TABLE `', REPLACE(p_table,'`','``'), '` ADD COLUMN `', REPLACE(p_column,'`','``'), '` ', p_definition);
    PREPARE nexa_stmt FROM @nexa_sql;
    EXECUTE nexa_stmt;
    DEALLOCATE PREPARE nexa_stmt;
  END IF;
END$$
DELIMITER ;

CALL nexa_add_column_if_missing('fiscal_inventories','initial_sheet','INT NOT NULL DEFAULT 1 AFTER `initial_page`');
CALL nexa_add_column_if_missing('fiscal_inventories','final_page','INT NULL AFTER `initial_sheet`');
CALL nexa_add_column_if_missing('fiscal_inventories','final_sheet','INT NULL AFTER `final_page`');
CALL nexa_add_column_if_missing('fiscal_inventories','page_count','INT NOT NULL DEFAULT 0 AFTER `final_sheet`');

DROP PROCEDURE IF EXISTS nexa_add_column_if_missing;

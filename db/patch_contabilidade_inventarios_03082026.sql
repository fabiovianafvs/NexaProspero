-- Nexa Prospero - Contabilidade por serie e inventarios persistentes
-- Data: 03/08/2026
-- Banco: MySQL/MariaDB
-- Execute no banco principal nexaprospero antes de publicar os arquivos PHP.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS fiscal_inventories (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  inventory_date DATE NOT NULL,
  stock_filter VARCHAR(20) NOT NULL DEFAULT 'positivos',
  cost_method VARCHAR(20) NOT NULL DEFAULT 'average',
  book_number INT NOT NULL DEFAULT 1,
  initial_page INT NOT NULL DEFAULT 1,
  initial_sheet INT NOT NULL DEFAULT 1,
  final_page INT NULL,
  final_sheet INT NULL,
  page_count INT NOT NULL DEFAULT 0,
  excluded_groups_json LONGTEXT NULL,
  item_count INT NOT NULL DEFAULT 0,
  total_quantity DECIMAL(18,4) NOT NULL DEFAULT 0,
  total_value DECIMAL(18,2) NOT NULL DEFAULT 0,
  warning_message TEXT NULL,
  notes TEXT NULL,
  generated_by_user_id INT NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_fiscal_inventories_company_date (company_id,inventory_date,id),
  KEY idx_fiscal_inventories_company_created (company_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_inventory_items (
  id BIGINT NOT NULL AUTO_INCREMENT,
  inventory_id BIGINT NOT NULL,
  company_id INT NOT NULL,
  product_id BIGINT NOT NULL,
  product_code VARCHAR(80) NULL,
  product_name VARCHAR(255) NOT NULL,
  product_group VARCHAR(160) NULL,
  unit VARCHAR(20) NULL,
  quantity DECIMAL(18,4) NOT NULL DEFAULT 0,
  last_cost DECIMAL(18,4) NOT NULL DEFAULT 0,
  average_cost DECIMAL(18,4) NOT NULL DEFAULT 0,
  valuation_cost DECIMAL(18,4) NOT NULL DEFAULT 0,
  total_value DECIMAL(18,2) NOT NULL DEFAULT 0,
  stock_source VARCHAR(30) NOT NULL DEFAULT 'ATUAL',
  cost_source VARCHAR(40) NOT NULL DEFAULT 'ATUAL',
  ncm VARCHAR(20) NULL,
  cest VARCHAR(20) NULL,
  cfop VARCHAR(10) NULL,
  icms_cst VARCHAR(10) NULL,
  icms_csosn VARCHAR(10) NULL,
  pis_cst VARCHAR(10) NULL,
  cofins_cst VARCHAR(10) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_fiscal_inventory_item (inventory_id,product_id),
  KEY idx_fiscal_inventory_items_company (company_id,inventory_id),
  KEY idx_fiscal_inventory_items_group (inventory_id,product_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

DROP PROCEDURE IF EXISTS nexa_update_accounting_file_index$$
CREATE PROCEDURE nexa_update_accounting_file_index()
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.tables
    WHERE table_schema=DATABASE() AND table_name='accounting_closure_files'
  ) THEN
    IF EXISTS (
      SELECT 1 FROM information_schema.statistics
      WHERE table_schema=DATABASE() AND table_name='accounting_closure_files' AND index_name='ux_accounting_closure_file_type'
    ) THEN
      ALTER TABLE accounting_closure_files DROP INDEX ux_accounting_closure_file_type;
    END IF;

    IF NOT EXISTS (
      SELECT 1 FROM information_schema.statistics
      WHERE table_schema=DATABASE() AND table_name='accounting_closure_files' AND index_name='ux_accounting_closure_file_type_name'
    ) THEN
      ALTER TABLE accounting_closure_files
        ADD UNIQUE KEY ux_accounting_closure_file_type_name (closure_id,file_type,file_name);
    END IF;
  END IF;
END$$

DELIMITER ;

CALL nexa_add_column_if_missing('accounting_closures','include_inventory','TINYINT(1) NOT NULL DEFAULT 0 AFTER `series`');
CALL nexa_add_column_if_missing('accounting_closures','inventory_id','BIGINT NULL AFTER `inventory_items_count`');

CALL nexa_add_column_if_missing('fiscal_inventories','initial_sheet','INT NOT NULL DEFAULT 1 AFTER `initial_page`');
CALL nexa_add_column_if_missing('fiscal_inventories','final_page','INT NULL AFTER `initial_sheet`');
CALL nexa_add_column_if_missing('fiscal_inventories','final_sheet','INT NULL AFTER `final_page`');
CALL nexa_add_column_if_missing('fiscal_inventories','page_count','INT NOT NULL DEFAULT 0 AFTER `final_sheet`');

CALL nexa_add_column_if_missing('produtos','custo_medio','DECIMAL(15,4) NOT NULL DEFAULT 0.0000');
CALL nexa_add_column_if_missing('produtos','custo_ultima_compra','DECIMAL(15,4) NOT NULL DEFAULT 0.0000');
CALL nexa_add_column_if_missing('produtos','data_ultima_compra','DATETIME NULL');

CALL nexa_add_column_if_missing('estoque_movimentos','custo_unitario','DECIMAL(15,4) NOT NULL DEFAULT 0.0000');
CALL nexa_add_column_if_missing('estoque_movimentos','valor_movimento','DECIMAL(15,2) NOT NULL DEFAULT 0.00');
CALL nexa_add_column_if_missing('estoque_movimentos','custo_medio_anterior','DECIMAL(15,4) NOT NULL DEFAULT 0.0000');
CALL nexa_add_column_if_missing('estoque_movimentos','custo_medio_posterior','DECIMAL(15,4) NOT NULL DEFAULT 0.0000');

CALL nexa_update_accounting_file_index();

DROP PROCEDURE IF EXISTS nexa_update_accounting_file_index;
DROP PROCEDURE IF EXISTS nexa_add_column_if_missing;

-- Conferencia rapida
SELECT 'fiscal_inventories' AS objeto, COUNT(*) AS registros FROM fiscal_inventories
UNION ALL
SELECT 'fiscal_inventory_items', COUNT(*) FROM fiscal_inventory_items;

-- Nexa Próspero
-- Personalização da landing da revenda e quantidade por módulo/serviço
-- Banco: MySQL / MariaDB

SET @schema_name := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='saas_modules' AND column_name='allow_quantity') = 0,
  'ALTER TABLE saas_modules ADD COLUMN allow_quantity TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='saas_modules' AND column_name='quantity_label') = 0,
  'ALTER TABLE saas_modules ADD COLUMN quantity_label VARCHAR(60) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='saas_modules' AND column_name='quantity_min') = 0,
  'ALTER TABLE saas_modules ADD COLUMN quantity_min INT UNSIGNED NOT NULL DEFAULT 1',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='saas_modules' AND column_name='quantity_max') = 0,
  'ALTER TABLE saas_modules ADD COLUMN quantity_max INT UNSIGNED NOT NULL DEFAULT 99',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='saas_modules' AND column_name='quantity_default') = 0,
  'ALTER TABLE saas_modules ADD COLUMN quantity_default INT UNSIGNED NOT NULL DEFAULT 1',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='saas_company_modules' AND column_name='quantity') = 0,
  'ALTER TABLE saas_company_modules ADD COLUMN quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE saas_modules
SET quantity_min = GREATEST(1, COALESCE(quantity_min, 1)),
    quantity_max = GREATEST(GREATEST(1, COALESCE(quantity_min, 1)), COALESCE(quantity_max, 99));

UPDATE saas_modules
SET quantity_default = LEAST(quantity_max, GREATEST(quantity_min, COALESCE(quantity_default, quantity_min))),
    quantity_label = NULLIF(TRIM(COALESCE(quantity_label, '')), '');

UPDATE saas_company_modules
SET quantity = 1.00
WHERE quantity IS NULL OR quantity < 1;

-- Nexa Próspero - Revendas v1
-- Data: 21/07/2026
-- Faça backup do banco antes de executar.
-- Script idempotente para MySQL/MariaDB.

CREATE TABLE IF NOT EXISTS saas_schema_migrations (
  migration_key VARCHAR(120) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (migration_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @migration_key := '2026-07-21-resellers-v2';
SET @seed_key := '2026-07-21-resellers-pricing-seed-v1';
SET @already_applied := (
  SELECT COUNT(*) FROM saas_schema_migrations WHERE migration_key = @migration_key
);
SET @already_seeded := (
  SELECT COUNT(*) FROM saas_schema_migrations WHERE migration_key = @seed_key
);

-- companies: identifica revendas e vincula seus clientes.
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='companies' AND column_name='is_reseller'),
  'SELECT 1',
  'ALTER TABLE companies ADD COLUMN is_reseller TINYINT(1) NOT NULL DEFAULT 0 AFTER module_mode'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='companies' AND column_name='reseller_company_id'),
  'SELECT 1',
  'ALTER TABLE companies ADD COLUMN reseller_company_id INT UNSIGNED NULL AFTER is_reseller'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='companies' AND column_name='reseller_status'),
  'SELECT 1',
  'ALTER TABLE companies ADD COLUMN reseller_status VARCHAR(20) NOT NULL DEFAULT ''inactive'' AFTER reseller_company_id'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='companies' AND column_name='reseller_brand_name'),
  'SELECT 1',
  'ALTER TABLE companies ADD COLUMN reseller_brand_name VARCHAR(150) NULL AFTER reseller_status'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='companies' AND column_name='reseller_whatsapp'),
  'SELECT 1',
  'ALTER TABLE companies ADD COLUMN reseller_whatsapp VARCHAR(30) NULL AFTER reseller_brand_name'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='companies' AND column_name='reseller_pix_key'),
  'SELECT 1',
  'ALTER TABLE companies ADD COLUMN reseller_pix_key VARCHAR(190) NULL AFTER reseller_whatsapp'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='companies' AND column_name='reseller_notes'),
  'SELECT 1',
  'ALTER TABLE companies ADD COLUMN reseller_notes TEXT NULL AFTER reseller_pix_key'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='companies' AND index_name='idx_companies_reseller_company'),
  'SELECT 1',
  'ALTER TABLE companies ADD KEY idx_companies_reseller_company (reseller_company_id)'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='companies' AND index_name='idx_companies_is_reseller'),
  'SELECT 1',
  'ALTER TABLE companies ADD KEY idx_companies_is_reseller (is_reseller)'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- saas_modules: custo Nexa, preço padrão e implantação.
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='saas_modules' AND column_name='monthly_cost'),
  'SELECT 1',
  'ALTER TABLE saas_modules ADD COLUMN monthly_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER description'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='saas_modules' AND column_name='monthly_price'),
  'SELECT 1',
  'ALTER TABLE saas_modules ADD COLUMN monthly_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER monthly_cost'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='saas_modules' AND column_name='setup_cost'),
  'SELECT 1',
  'ALTER TABLE saas_modules ADD COLUMN setup_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER monthly_price'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='saas_modules' AND column_name='setup_price'),
  'SELECT 1',
  'ALTER TABLE saas_modules ADD COLUMN setup_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER setup_cost'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='saas_modules' AND column_name='is_public_sale'),
  'SELECT 1',
  'ALTER TABLE saas_modules ADD COLUMN is_public_sale TINYINT(1) NOT NULL DEFAULT 0 AFTER setup_price'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS saas_reseller_module_prices (
  reseller_company_id INT UNSIGNED NOT NULL,
  module_id INT UNSIGNED NOT NULL,
  sale_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  setup_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (reseller_company_id, module_id),
  KEY idx_reseller_prices_module (module_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saas_reseller_invoices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reseller_company_id INT UNSIGNED NOT NULL,
  reference_month CHAR(7) NOT NULL,
  due_date DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_reseller_invoice_month (reseller_company_id, reference_month),
  KEY idx_reseller_invoice_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saas_reseller_invoice_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id BIGINT UNSIGNED NOT NULL,
  client_company_id INT UNSIGNED NOT NULL,
  module_id INT UNSIGNED NOT NULL,
  item_type VARCHAR(20) NOT NULL DEFAULT 'monthly',
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  unit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_reseller_invoice_item (invoice_id, client_company_id, module_id, item_type),
  KEY idx_reseller_invoice_items_client (client_company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saas_reseller_customer_charges (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reseller_company_id INT UNSIGNED NOT NULL,
  client_company_id INT UNSIGNED NOT NULL,
  reference_month CHAR(7) NULL,
  origin VARCHAR(20) NOT NULL DEFAULT 'manual',
  description VARCHAR(255) NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  due_date DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  paid_at DATETIME NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_reseller_customer_charge_month (reseller_company_id, client_company_id, reference_month),
  KEY idx_reseller_customer_charge_owner (reseller_company_id, status),
  KEY idx_reseller_customer_charge_client (client_company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='saas_reseller_customer_charges' AND column_name='reference_month'),
  'SELECT 1',
  'ALTER TABLE saas_reseller_customer_charges ADD COLUMN reference_month CHAR(7) NULL AFTER client_company_id'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='saas_reseller_customer_charges' AND column_name='origin'),
  'SELECT 1',
  'ALTER TABLE saas_reseller_customer_charges ADD COLUMN origin VARCHAR(20) NOT NULL DEFAULT ''manual'' AFTER reference_month'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='saas_reseller_customer_charges' AND index_name='uk_reseller_customer_charge_month'),
  'SELECT 1',
  'ALTER TABLE saas_reseller_customer_charges ADD UNIQUE KEY uk_reseller_customer_charge_month (reseller_company_id, client_company_id, reference_month)'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS saas_reseller_customer_charge_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  charge_id BIGINT UNSIGNED NOT NULL,
  module_id INT UNSIGNED NOT NULL,
  item_type VARCHAR(20) NOT NULL DEFAULT 'monthly',
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  unit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_reseller_customer_charge_item (charge_id, module_id, item_type),
  KEY idx_reseller_customer_charge_item_module (module_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Garante o módulo de impressão usado na landing, sem duplicar cadastros existentes.
INSERT INTO saas_modules (code, name, description, is_active, sort_order)
SELECT 'impressao', 'Agente de Impressão',
       'Agente para impressão automática em cozinha, bar, caixa e outros setores.', 1, 36
WHERE NOT EXISTS (SELECT 1 FROM saas_modules WHERE code='impressao');

-- Preços públicos iniciais da landing atual. Não substitui valores já preenchidos.
UPDATE saas_modules
SET monthly_price = CASE WHEN monthly_price = 0 THEN 59.90 ELSE monthly_price END,
    setup_price = CASE WHEN setup_price = 0 THEN 350.00 ELSE setup_price END,
    is_public_sale = CASE WHEN is_public_sale = 0 THEN 1 ELSE is_public_sale END
WHERE code = 'delivery' AND @already_seeded = 0;

UPDATE saas_modules
SET monthly_price = CASE WHEN monthly_price = 0 THEN 189.90 ELSE monthly_price END,
    setup_price = CASE WHEN setup_price = 0 THEN 375.00 ELSE setup_price END,
    is_public_sale = CASE WHEN is_public_sale = 0 THEN 1 ELSE is_public_sale END
WHERE code = 'gestao' AND @already_seeded = 0;

UPDATE saas_modules
SET monthly_price = CASE WHEN monthly_price = 0 THEN 59.90 ELSE monthly_price END,
    setup_price = CASE WHEN setup_price = 0 THEN 150.00 ELSE setup_price END,
    is_public_sale = CASE WHEN is_public_sale = 0 THEN 1 ELSE is_public_sale END
WHERE code = 'comandas' AND @already_seeded = 0;

UPDATE saas_modules
SET monthly_price = CASE WHEN monthly_price = 0 THEN 29.90 ELSE monthly_price END,
    setup_price = CASE WHEN setup_price = 0 THEN 0.00 ELSE setup_price END,
    is_public_sale = CASE WHEN is_public_sale = 0 THEN 1 ELSE is_public_sale END
WHERE code = 'impressao' AND @already_seeded = 0;

-- Plano interno e gratuito usado pelas empresas que são revendas.
INSERT INTO plans
  (name, slug, headline, summary, price, max_orders, trial_days, billing_days,
   badge_text, button_label, sort_order, is_featured, is_active, is_public,
   is_internal, internal_note)
SELECT
  'Revenda - faturamento próprio', 'revenda-faturamento-proprio', 'Revenda',
  'Cliente faturado diretamente pela revenda.', 0.00, NULL, 0, 30,
  'Revenda', 'Ativação pela revenda', 950, 0, 1, 0, 1,
  'Não cobrar diretamente pela Nexa Próspero; custo consolidado na revenda.'
WHERE NOT EXISTS (
  SELECT 1 FROM plans WHERE slug = 'revenda-faturamento-proprio'
);

INSERT IGNORE INTO saas_schema_migrations (migration_key) VALUES (@seed_key);
INSERT IGNORE INTO saas_schema_migrations (migration_key) VALUES (@migration_key);

SELECT 'Patch de revendas aplicado com sucesso.' AS resultado;

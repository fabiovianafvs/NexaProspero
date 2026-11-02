-- Nexa Próspero - Revendas v3
-- Preços dinâmicos do configurador principal e das revendas.
-- Idempotente: pode ser executado mais de uma vez.

CREATE TABLE IF NOT EXISTS saas_schema_migrations (
  migration_key VARCHAR(120) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (migration_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @migration_key := '20260721_revendas_v3_precos_configurador';

-- Plano comercial escolhido no configurador. A assinatura pode continuar sendo Trial
-- ou o plano interno de revenda sem perder a faixa de Delivery escolhida.
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='companies' AND column_name='commercial_plan_id'),
  'SELECT 1',
  'ALTER TABLE companies ADD COLUMN commercial_plan_id INT UNSIGNED NULL AFTER desired_plan_id'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='companies' AND index_name='idx_companies_commercial_plan'),
  'SELECT 1',
  'ALTER TABLE companies ADD KEY idx_companies_commercial_plan (commercial_plan_id)'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Custos e implantação dos planos/faixas de Delivery.
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='plans' AND column_name='monthly_cost'),
  'SELECT 1',
  'ALTER TABLE plans ADD COLUMN monthly_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER price'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='plans' AND column_name='setup_cost'),
  'SELECT 1',
  'ALTER TABLE plans ADD COLUMN setup_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER original_price'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='plans' AND column_name='setup_price'),
  'SELECT 1',
  'ALTER TABLE plans ADD COLUMN setup_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER setup_cost'
); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Preço regional das faixas de Delivery para cada revenda.
CREATE TABLE IF NOT EXISTS saas_reseller_plan_prices (
  reseller_company_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  sale_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  setup_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (reseller_company_id, plan_id),
  KEY idx_reseller_plan_prices_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Garante os três planos públicos de Delivery. Mantém os mesmos slugs usados pelo sistema.
INSERT INTO plans
  (name, slug, headline, summary, price, monthly_cost, original_price, setup_cost, setup_price,
   max_orders, trial_days, billing_days, badge_text, button_label, sort_order,
   is_featured, is_active, is_public, is_internal)
SELECT
  'Delivery Start', 'essencial', 'Delivery Start', 'Até 100 pedidos por mês.',
  59.90, 0.00, 79.90, 0.00, 350.00, 100, 0, 30, 'Oferta',
  'Escolher Delivery Start', 20, 1, 1, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM plans WHERE slug='essencial');

INSERT INTO plans
  (name, slug, headline, summary, price, monthly_cost, original_price, setup_cost, setup_price,
   max_orders, trial_days, billing_days, badge_text, button_label, sort_order,
   is_featured, is_active, is_public, is_internal)
SELECT
  'Delivery Pro', 'profissional', 'Delivery Pro', 'De 101 a 500 pedidos por mês.',
  139.90, 0.00, 159.90, 0.00, 350.00, 500, 0, 30, 'Escala',
  'Escolher Delivery Pro', 30, 0, 1, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM plans WHERE slug='profissional');

INSERT INTO plans
  (name, slug, headline, summary, price, monthly_cost, original_price, setup_cost, setup_price,
   max_orders, trial_days, billing_days, badge_text, button_label, sort_order,
   is_featured, is_active, is_public, is_internal)
SELECT
  'Delivery Premium', 'enterprise', 'Delivery Premium', 'De 501 a 1000 pedidos por mês.',
  199.00, 0.00, 229.90, 0.00, 350.00, 1000, 0, 30, 'Premium',
  'Escolher Delivery Premium', 40, 0, 1, 1, 0
WHERE NOT EXISTS (SELECT 1 FROM plans WHERE slug='enterprise');

UPDATE plans
SET name='Delivery Start', headline='Delivery Start', summary='Até 100 pedidos por mês.',
    price=59.90, setup_price=350.00, max_orders=100,
    is_active=1, is_public=1, is_internal=0, sort_order=20
WHERE slug='essencial';

UPDATE plans
SET name='Delivery Pro', headline='Delivery Pro', summary='De 101 a 500 pedidos por mês.',
    price=139.90, setup_price=350.00, max_orders=500,
    is_active=1, is_public=1, is_internal=0, sort_order=30
WHERE slug='profissional';

UPDATE plans
SET name='Delivery Premium', headline='Delivery Premium', summary='De 501 a 1000 pedidos por mês.',
    price=199.00, setup_price=350.00, max_orders=1000,
    is_active=1, is_public=1, is_internal=0, sort_order=40
WHERE slug='enterprise';

-- Produtos adicionais exibidos no configurador.
INSERT INTO saas_modules
  (code, name, description, monthly_cost, monthly_price, setup_cost, setup_price,
   is_public_sale, is_active, sort_order)
SELECT
  'impressao', 'Agente de Impressão',
  'Impressão automática na cozinha, bar, caixa e outros setores.',
  0.00, 29.90, 0.00, 0.00, 1, 1, 36
WHERE NOT EXISTS (SELECT 1 FROM saas_modules WHERE code='impressao');

UPDATE saas_modules
SET name='Gestão Fiscal',
    description='Fiscal, estoque, compras, vendas, caixa, financeiro e relatórios.',
    monthly_price=189.90, setup_price=375.00,
    is_public_sale=1, is_active=1
WHERE code='gestao';

UPDATE saas_modules
SET name='Comandas',
    description='Mesas, pedidos pelo smartphone, transferências e fechamento.',
    monthly_price=59.90, setup_price=150.00,
    is_public_sale=1, is_active=1
WHERE code='comandas';

UPDATE saas_modules
SET name='Agente de Impressão',
    description='Impressão automática na cozinha, bar, caixa e outros setores.',
    monthly_price=29.90, setup_price=0.00,
    is_public_sale=1, is_active=1
WHERE code='impressao';

-- O código delivery continua sendo o módulo operacional. O preço comercial vem da faixa/plano.
UPDATE saas_modules
SET monthly_price=0.00, setup_price=0.00, is_public_sale=0
WHERE code='delivery';

INSERT IGNORE INTO saas_schema_migrations (migration_key) VALUES (@migration_key);
SELECT 'Patch v3 de preços do configurador aplicado com sucesso.' AS resultado;

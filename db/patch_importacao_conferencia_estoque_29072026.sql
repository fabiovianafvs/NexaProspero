-- Nexa Próspero
-- Importação de produtos Firebird 5.0 + Conferência de estoque
-- Data: 29/07/2026
-- Execute no banco MySQL principal do Nexa antes de liberar os módulos.

START TRANSACTION;

-- Módulos internos: não aparecem no configurador/venda pública.
INSERT INTO saas_modules
  (code, name, description, item_type, monthly_cost, monthly_price, setup_cost, setup_price, is_public_sale, is_active, sort_order)
VALUES
  ('importacao_produtos', 'Importação de Produtos', 'Importação temporária de produtos de uma base Firebird para uma empresa específica.', 'module', 0.00, 0.00, 0.00, 0.00, 0, 1, 16),
  ('conferencia_estoque', 'Conferência de Estoque', 'Contagem física, prévia de diferenças e acerto auditado dos saldos de estoque.', 'module', 0.00, 0.00, 0.00, 0.00, 0, 1, 32)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  item_type = 'module',
  is_public_sale = 0,
  is_active = 1;

CREATE TABLE IF NOT EXISTS product_import_tax_map (
  company_id BIGINT NOT NULL,
  source_tax_code VARCHAR(60) NOT NULL,
  target_tax_profile_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (company_id, source_tax_code),
  KEY idx_product_import_tax_profile (company_id, target_tax_profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_import_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'RUNNING',
  source_database VARCHAR(500) NULL,
  source_company_code INT NULL,
  total_source INT NOT NULL DEFAULT 0,
  inserted_count INT NOT NULL DEFAULT 0,
  updated_count INT NOT NULL DEFAULT 0,
  skipped_count INT NOT NULL DEFAULT 0,
  error_count INT NOT NULL DEFAULT 0,
  details_json LONGTEXT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_product_import_runs_company (company_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS produtos_firebird_map (
  id INT NOT NULL AUTO_INCREMENT,
  company_id BIGINT NOT NULL,
  produto_id BIGINT NOT NULL,
  codigo_firebird VARCHAR(60) NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_produtos_firebird_map (company_id, produto_id),
  KEY idx_produtos_firebird_codigo (company_id, codigo_firebird)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_conferences (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(180) NOT NULL,
  counted_at DATETIME NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
  reference_at DATETIME NULL,
  processed_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  processed_by BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_stock_conferences_company (company_id, status, counted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_conference_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  conference_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  counted_qty DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  system_qty_reference DECIMAL(15,4) NULL,
  difference_qty DECIMAL(15,4) NULL,
  current_qty_before DECIMAL(15,4) NULL,
  current_qty_after DECIMAL(15,4) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_stock_conference_product (company_id, conference_id, product_id),
  KEY idx_stock_conference_items_conference (company_id, conference_id),
  KEY idx_stock_conference_items_product (company_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS estoque_movimentos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  produto_id BIGINT UNSIGNED NOT NULL,
  tipo VARCHAR(30) NOT NULL DEFAULT 'ajuste',
  origem_tipo VARCHAR(40) NULL,
  origem_id BIGINT UNSIGNED NULL,
  qtd DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  saldo_anterior DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  saldo_posterior DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  observacao TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_estoque_movimentos_company_produto (company_id, produto_id),
  KEY idx_estoque_movimentos_origem (company_id, origem_tipo, origem_id),
  KEY idx_estoque_movimentos_created (company_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

-- Observações:
-- 1) As colunas codigo_barras, unidade_estoque, estoque_atual, estoque_minimo,
--    estoque_maximo e estoque_reservado são verificadas/criadas defensivamente
--    pelos módulos PHP, usando os mesmos helpers já existentes no Nexa.
-- 2) Depois do patch, habilite os códigos importacao_produtos e/ou
--    conferencia_estoque para a empresa desejada em Gestão SaaS > Empresa.
-- 3) Ambos permanecem com is_public_sale=0 e não são exibidos no configurador público.

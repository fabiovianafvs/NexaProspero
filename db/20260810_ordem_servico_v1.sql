-- Nexa Próspero - Módulo Ordem de Serviço v1
-- Banco alvo: nexaprospero
-- Compatível com MariaDB/MySQL. Execute no banco da aplicação.

START TRANSACTION;

INSERT INTO saas_modules
(code, name, description, item_type, monthly_cost, monthly_price, setup_cost, setup_price, is_public_sale, sort_order, is_active)
VALUES
('ordem_servico', 'Ordem de Serviço', 'Ordens de serviço com campos personalizados, produtos, serviços, reservas, pagamentos, impressão e faturamento no PDV.', 'module', 0, 0, 0, 0, 0, 25, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  is_active = 1;

CREATE TABLE IF NOT EXISTS os_settings (
  company_id INT NOT NULL,
  default_reserve_product TINYINT(1) NOT NULL DEFAULT 0,
  allow_payment_plan TINYINT(1) NOT NULL DEFAULT 1,
  default_print_model VARCHAR(20) NOT NULL DEFAULT 'a4_half',
  print_customer_signature TINYINT(1) NOT NULL DEFAULT 1,
  print_responsible_signature TINYINT(1) NOT NULL DEFAULT 1,
  terms_text TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS os_sequences (
  company_id INT NOT NULL,
  last_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS os_statuses (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  code VARCHAR(50) NOT NULL,
  color VARCHAR(20) NOT NULL DEFAULT '#64748b',
  sort_order INT NOT NULL DEFAULT 0,
  is_initial TINYINT(1) NOT NULL DEFAULT 0,
  is_final TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_os_status_company_code (company_id, code),
  KEY idx_os_status_company_active (company_id, active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS os_custom_fields (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  label VARCHAR(120) NOT NULL,
  field_key VARCHAR(80) NOT NULL,
  input_type VARCHAR(20) NOT NULL DEFAULT 'text',
  placeholder VARCHAR(160) NULL,
  required TINYINT(1) NOT NULL DEFAULT 0,
  allow_quick_add TINYINT(1) NOT NULL DEFAULT 0,
  show_on_print TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_os_custom_field_key (company_id, field_key),
  KEY idx_os_custom_fields_company (company_id, active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS os_custom_field_options (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  field_id INT NOT NULL,
  label VARCHAR(160) NOT NULL,
  option_value VARCHAR(160) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_os_field_option (company_id, field_id, option_value),
  KEY idx_os_field_options (company_id, field_id, active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS os_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  os_number BIGINT UNSIGNED NOT NULL,
  person_id INT NULL,
  person_name_snapshot VARCHAR(180) NULL,
  person_document_snapshot VARCHAR(30) NULL,
  person_phone_snapshot VARCHAR(40) NULL,
  status_id INT NULL,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expected_at DATETIME NULL,
  closed_at DATETIME NULL,
  title VARCHAR(180) NULL,
  reported_problem TEXT NULL,
  diagnosis TEXT NULL,
  technical_notes TEXT NULL,
  customer_notes TEXT NULL,
  total_products DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  total_services DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  addition_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  total_general DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  billing_status VARCHAR(30) NOT NULL DEFAULT 'PENDENTE',
  pedido_id BIGINT UNSIGNED NULL,
  requires_product_fiscal TINYINT(1) NOT NULL DEFAULT 0,
  requires_service_fiscal TINYINT(1) NOT NULL DEFAULT 0,
  product_document_type VARCHAR(10) NULL,
  service_document_type VARCHAR(10) NULL,
  billed_at DATETIME NULL,
  created_by INT NULL,
  updated_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_os_company_number (company_id, os_number),
  KEY idx_os_company_status_opened (company_id, status_id, opened_at, id),
  KEY idx_os_company_person (company_id, person_id, opened_at),
  KEY idx_os_company_billing (company_id, billing_status, id),
  KEY idx_os_pedido (company_id, pedido_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS os_order_field_values (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  field_id INT NOT NULL,
  value_text LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_os_order_field (company_id, order_id, field_id),
  KEY idx_os_order_field_order (company_id, order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS os_order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  produto_id INT NOT NULL,
  item_type VARCHAR(20) NOT NULL DEFAULT 'PRODUTO',
  description_snapshot VARCHAR(180) NOT NULL,
  unidade_snapshot VARCHAR(20) NULL,
  quantity DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
  unit_price DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  reserve_stock TINYINT(1) NOT NULL DEFAULT 0,
  reserved_quantity DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  reservation_status VARCHAR(20) NOT NULL DEFAULT 'NAO_RESERVADO',
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_os_items_order (company_id, order_id, item_type, id),
  KEY idx_os_items_product (company_id, produto_id, reservation_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS os_order_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  payment_method_id INT NULL,
  payment_label VARCHAR(120) NULL,
  amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  due_date DATE NULL,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_os_payments_order (company_id, order_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS os_status_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  from_status_id INT NULL,
  to_status_id INT NULL,
  user_id INT NULL,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_os_status_history_order (company_id, order_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

-- IMPORTANTE:
-- O INSERT acima apenas cadastra o produto/módulo no SaaS.
-- Ele NÃO habilita Ordem de Serviço para nenhuma empresa automaticamente.
-- Habilite pelo configurador de módulos/plano existente do Nexa.

-- Registro de versão para o runtime do Nexa (v1.3)
CREATE TABLE IF NOT EXISTS nexa_schema_versions (
  module_name VARCHAR(80) NOT NULL,
  version_number BIGINT UNSIGNED NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes VARCHAR(255) NULL,
  PRIMARY KEY (module_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES ('ordem_servico',2026081002,NOW(),'Ordem de Serviço v1.3 - estrutura base')
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

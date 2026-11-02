-- Nexa Próspero V11.52 — Fundação da Gestão de Assinaturas SaaS
-- Integra exclusivamente a Central SaaS ao Admin da empresa faturadora configurada.
-- Nesta etapa NÃO gera Pedido, Contas a Receber, boleto, PIX ou NFS-e automaticamente.

CREATE TABLE IF NOT EXISTS saas_subscription_bridge_config (
  id TINYINT UNSIGNED NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  billing_company_id INT UNSIGNED NULL,
  trial_days INT UNSIGNED NOT NULL DEFAULT 7,
  charge_lead_days INT UNSIGNED NOT NULL DEFAULT 2,
  grace_days INT UNSIGNED NOT NULL DEFAULT 3,
  default_payment_method_id INT NULL,
  default_bank_account_id INT NULL,
  default_chart_account_id INT NULL,
  default_cost_center_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO saas_subscription_bridge_config
  (id, enabled, billing_company_id, trial_days, charge_lead_days, grace_days)
VALUES
  (1, 0, NULL, 7, 2, 3)
ON DUPLICATE KEY UPDATE id=VALUES(id);

CREATE TABLE IF NOT EXISTS saas_subscription_module_map (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  billing_company_id INT UNSIGNED NOT NULL,
  saas_module_id INT UNSIGNED NOT NULL,
  product_id INT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_saas_sub_module_map (billing_company_id, saas_module_id),
  KEY idx_saas_sub_module_product (billing_company_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saas_subscription_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  saas_company_id INT UNSIGNED NOT NULL,
  saas_subscription_id INT UNSIGNED NULL,
  billing_company_id INT UNSIGNED NOT NULL,
  person_id INT NOT NULL,
  contract_id BIGINT UNSIGNED NULL,
  lifecycle_status VARCHAR(30) NOT NULL DEFAULT 'TRIAL',
  trial_started_at DATETIME NULL,
  trial_ends_at DATETIME NULL,
  current_period_start DATETIME NULL,
  current_period_end DATETIME NULL,
  next_due_date DATE NULL,
  grace_until DATETIME NULL,
  activated_at DATETIME NULL,
  suspended_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  last_sync_at DATETIME NULL,
  last_error VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_saas_sub_link_company (saas_company_id),
  KEY idx_saas_sub_link_billing (billing_company_id, lifecycle_status),
  KEY idx_saas_sub_link_person (billing_company_id, person_id),
  KEY idx_saas_sub_link_contract (billing_company_id, contract_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saas_subscription_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  link_id BIGINT UNSIGNED NOT NULL,
  saas_module_id INT UNSIGNED NOT NULL,
  product_id INT NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  unit_price DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
  status VARCHAR(30) NOT NULL DEFAULT 'TRIAL',
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  contract_item_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_saas_sub_item_module (link_id, saas_module_id),
  KEY idx_saas_sub_item_product (product_id),
  KEY idx_saas_sub_item_status (link_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saas_subscription_changes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  link_id BIGINT UNSIGNED NOT NULL,
  saas_module_id INT UNSIGNED NOT NULL,
  action VARCHAR(20) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
  old_quantity DECIMAL(10,2) NULL,
  new_quantity DECIMAL(10,2) NULL,
  old_price DECIMAL(15,4) NULL,
  new_price DECIMAL(15,4) NULL,
  effective_at DATETIME NULL,
  order_id BIGINT UNSIGNED NULL,
  receivable_id BIGINT UNSIGNED NULL,
  requested_by_type VARCHAR(30) NULL,
  requested_by_id BIGINT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  applied_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  notes VARCHAR(500) NULL,
  PRIMARY KEY (id),
  KEY idx_saas_sub_change_link (link_id, status, requested_at),
  KEY idx_saas_sub_change_module (link_id, saas_module_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saas_billing_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_key VARCHAR(120) NOT NULL,
  event_type VARCHAR(50) NOT NULL,
  saas_company_id INT UNSIGNED NULL,
  link_id BIGINT UNSIGNED NULL,
  billing_company_id INT UNSIGNED NULL,
  origin_type VARCHAR(40) NULL,
  origin_id BIGINT NULL,
  payload_json LONGTEXT NULL,
  processed TINYINT(1) NOT NULL DEFAULT 0,
  processed_at DATETIME NULL,
  error_message VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_saas_billing_event_key (event_key),
  KEY idx_saas_billing_event_company (saas_company_id, created_at),
  KEY idx_saas_billing_event_link (link_id, created_at),
  KEY idx_saas_billing_event_processed (processed, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

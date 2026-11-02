-- Migração opcional do módulo Pix Inter no SaaS MySQL
-- O sistema também cria as estruturas automaticamente ao abrir as telas novas.

ALTER TABLE subscriptions MODIFY COLUMN status ENUM('trial','active','past_due','canceled','cancelled','pending_payment') NOT NULL DEFAULT 'trial';
ALTER TABLE companies MODIFY COLUMN status ENUM('active','blocked','suspended') NOT NULL DEFAULT 'active';

CREATE TABLE IF NOT EXISTS inter_pix_config (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  environment VARCHAR(20) NOT NULL DEFAULT 'sandbox',
  client_id VARCHAR(191) NOT NULL,
  client_secret VARCHAR(191) NOT NULL,
  oauth_scope VARCHAR(255) DEFAULT NULL,
  pix_key VARCHAR(191) NOT NULL,
  cert_path VARCHAR(255) DEFAULT NULL,
  key_path VARCHAR(255) DEFAULT NULL,
  ca_path VARCHAR(255) DEFAULT NULL,
  api_base_url VARCHAR(255) DEFAULT NULL,
  oauth_url VARCHAR(255) DEFAULT NULL,
  webhook_url VARCHAR(255) DEFAULT NULL,
  webhook_token VARCHAR(100) DEFAULT NULL,
  charge_expiration_seconds INT NOT NULL DEFAULT 86400,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saas_charges (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  subscription_id INT UNSIGNED NULL,
  plan_id INT UNSIGNED NOT NULL,
  gateway VARCHAR(40) NOT NULL DEFAULT 'inter',
  payment_method VARCHAR(20) NOT NULL DEFAULT 'pix',
  status VARCHAR(30) NOT NULL DEFAULT 'open',
  txid VARCHAR(100) NOT NULL,
  inter_loc_id BIGINT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  billing_days INT NOT NULL DEFAULT 30,
  expires_at DATETIME NULL,
  paid_at DATETIME NULL,
  pix_copy_paste LONGTEXT NULL,
  qr_image LONGTEXT NULL,
  inter_status VARCHAR(50) NULL,
  e2eid VARCHAR(191) NULL,
  payment_payload LONGTEXT NULL,
  inter_response LONGTEXT NULL,
  webhook_payload LONGTEXT NULL,
  payment_url VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_saas_charges_txid (txid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saas_webhook_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_name VARCHAR(80) DEFAULT NULL,
  txid VARCHAR(100) DEFAULT NULL,
  e2eid VARCHAR(191) DEFAULT NULL,
  headers_json LONGTEXT NULL,
  payload_json LONGTEXT NULL,
  processed TINYINT(1) NOT NULL DEFAULT 0,
  processed_at DATETIME NULL,
  notes VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

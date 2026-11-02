-- Nexa Brain v2.3.0 - Central de Ações Inteligentes
-- Preferencialmente execute o worker PHP migrate_brain_actions_v230.php,
-- que também semeia regras e pode realizar a primeira avaliação.

CREATE TABLE IF NOT EXISTS brain_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL DEFAULT 0,
  rule_code VARCHAR(80) NOT NULL,
  module_code VARCHAR(40) NOT NULL DEFAULT 'brain',
  name VARCHAR(180) NOT NULL,
  description TEXT NULL,
  default_severity ENUM('info','opportunity','warning','high','critical') NOT NULL DEFAULT 'warning',
  default_settings JSON NOT NULL,
  action_url VARCHAR(255) NULL,
  recommendation TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_brain_rules_company_code (company_id,rule_code),
  KEY idx_brain_rules_active (company_id,is_active,module_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brain_company_rule_settings (
  company_id INT UNSIGNED NOT NULL,
  rule_code VARCHAR(80) NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  severity_override ENUM('info','opportunity','warning','high','critical') NULL,
  settings JSON NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (company_id,rule_code),
  KEY idx_brain_company_rules_enabled (company_id,is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brain_alerts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  rule_code VARCHAR(80) NOT NULL,
  fingerprint CHAR(64) NOT NULL,
  module_code VARCHAR(40) NOT NULL DEFAULT 'brain',
  severity ENUM('info','opportunity','warning','high','critical') NOT NULL DEFAULT 'warning',
  entity_type VARCHAR(60) NOT NULL DEFAULT 'company',
  entity_id BIGINT NULL,
  reference_key VARCHAR(120) NULL,
  title VARCHAR(180) NOT NULL,
  description TEXT NOT NULL,
  recommendation TEXT NULL,
  action_url VARCHAR(255) NULL,
  metric_value DECIMAL(18,4) NULL,
  financial_impact DECIMAL(18,2) NULL,
  metadata JSON NULL,
  status ENUM('open','snoozed','resolved','ignored') NOT NULL DEFAULT 'open',
  first_detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  snoozed_until DATETIME NULL,
  last_action_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_brain_alert_company_fingerprint (company_id,fingerprint),
  KEY idx_brain_alert_open (company_id,status,severity,last_detected_at),
  KEY idx_brain_alert_rule (company_id,rule_code,status),
  KEY idx_brain_alert_entity (company_id,entity_type,entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brain_alert_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  alert_id BIGINT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  action_code VARCHAR(30) NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status VARCHAR(20) NULL,
  note VARCHAR(500) NULL,
  user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_brain_alert_history_alert (alert_id,created_at),
  KEY idx_brain_alert_history_company (company_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brain_processing_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  trigger_source VARCHAR(40) NOT NULL DEFAULT 'manual',
  status ENUM('processing','success','warning','error') NOT NULL DEFAULT 'processing',
  rules_evaluated INT UNSIGNED NOT NULL DEFAULT 0,
  alerts_detected INT UNSIGNED NOT NULL DEFAULT 0,
  alerts_created INT UNSIGNED NOT NULL DEFAULT 0,
  alerts_updated INT UNSIGNED NOT NULL DEFAULT 0,
  alerts_resolved INT UNSIGNED NOT NULL DEFAULT 0,
  duration_ms INT UNSIGNED NULL,
  details JSON NULL,
  error_message TEXT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_brain_processing_company (company_id,started_at),
  KEY idx_brain_processing_status (status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

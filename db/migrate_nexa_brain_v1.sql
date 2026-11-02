-- Nexa Brain v1 - estrutura preparada para evolução orientada a eventos
CREATE TABLE IF NOT EXISTS brain_event_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  event_type VARCHAR(100) NOT NULL,
  entity_type VARCHAR(80) DEFAULT NULL,
  entity_id BIGINT UNSIGNED DEFAULT NULL,
  payload JSON DEFAULT NULL,
  status ENUM('pending','published','failed') NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  published_at DATETIME DEFAULT NULL,
  last_error TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_brain_outbox_pending (status, available_at),
  KEY idx_brain_outbox_company (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brain_insight_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  insight_key VARCHAR(100) NOT NULL,
  severity ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
  title VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  metric_value DECIMAL(18,4) DEFAULT NULL,
  detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_brain_insight_company (company_id, detected_at),
  KEY idx_brain_insight_open (company_id, insight_key, resolved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

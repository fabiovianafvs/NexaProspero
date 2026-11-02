CREATE TABLE IF NOT EXISTS os_quote_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  quote_id BIGINT UNSIGNED NOT NULL,
  os_order_id BIGINT UNSIGNED NOT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_os_quote_company_quote (company_id,quote_id),
  UNIQUE KEY uk_os_quote_company_order (company_id,os_order_id),
  KEY idx_os_quote_order (company_id,os_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES ('budget_os_communication',20260928400,NOW(),'V11.40 - Orcamentos, O.S. e comunicacao com o cliente')
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

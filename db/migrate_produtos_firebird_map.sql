CREATE TABLE IF NOT EXISTS produtos_firebird_map (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT NOT NULL,
  produto_id BIGINT NOT NULL,
  codigo_firebird VARCHAR(60) NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_produtos_firebird_map (company_id, produto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

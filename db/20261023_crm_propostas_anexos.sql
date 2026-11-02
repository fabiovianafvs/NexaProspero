-- Nexa Próspero — CRM: propostas comerciais, itens e anexos privados.
-- Aplicar SOMENTE pelo Atualizador do Banco (platform_admin).
CREATE TABLE IF NOT EXISTS crm_proposals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  attendance_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  intro TEXT NULL,
  benefits TEXT NULL,
  scope_text TEXT NULL,
  terms_text TEXT NULL,
  notes TEXT NULL,
  valid_until DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'RASCUNHO',
  share_enabled TINYINT(1) NOT NULL DEFAULT 0,
  share_token CHAR(64) NOT NULL,
  created_by INT NOT NULL,
  updated_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY(id),
  UNIQUE KEY uk_crm_proposal_token(share_token),
  KEY idx_crm_proposal_att(company_id,attendance_id,id),
  KEY idx_crm_proposal_company(company_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_proposal_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  proposal_id BIGINT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  description VARCHAR(500) NOT NULL,
  quantity DECIMAL(14,3) NOT NULL DEFAULT 1,
  unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
  monthly_unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
  PRIMARY KEY(id),
  KEY idx_crm_proposal_items(company_id,proposal_id,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_attachments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  attendance_id BIGINT UNSIGNED NOT NULL,
  move_id BIGINT UNSIGNED NULL,
  original_name VARCHAR(240) NOT NULL,
  storage_name CHAR(64) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  UNIQUE KEY uk_crm_attachment_storage(company_id,storage_name),
  KEY idx_crm_attachment_att(company_id,attendance_id,created_at),
  KEY idx_crm_attachment_move(company_id,move_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

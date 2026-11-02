-- Nexa Próspero - V11.35.1 Série Fiscal por Usuário + Empresa
-- Data: 21/09/2026
-- Escopo global do schema; os dados permanecem isolados por user_id + company_id.
-- Não remove nem altera as colunas legadas de série em users.

CREATE TABLE IF NOT EXISTS user_company_fiscal_series (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  company_id INT NOT NULL,
  document_type VARCHAR(20) NOT NULL,
  environment VARCHAR(20) NOT NULL DEFAULT 'HOMOLOGACAO',
  fiscal_series_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_ucfs_user_company_doc_env (user_id, company_id, document_type, environment),
  KEY idx_ucfs_company_user (company_id, user_id),
  KEY idx_ucfs_series (fiscal_series_id),
  KEY idx_ucfs_lookup (company_id, document_type, environment)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

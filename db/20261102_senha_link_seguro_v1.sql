-- Nexa Próspero - Link seguro para o cliente criar a própria senha.
-- Substitui o envio de senha por WhatsApp e a cópia da senha em texto aberto.
-- Aplicar pelo Atualizador do Banco (platform_admin): primeiro na homologação, depois em produção.

CREATE TABLE IF NOT EXISTS user_password_tokens (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 company_id INT NOT NULL,
 user_id INT NOT NULL,
 token_hash CHAR(64) NOT NULL,
 purpose VARCHAR(20) NOT NULL DEFAULT 'setup',
 expires_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 created_by_user_id INT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 UNIQUE KEY uk_user_password_token_hash (token_hash),
 KEY idx_user_password_token_user (user_id, used_at),
 KEY idx_user_password_token_company (company_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Apaga as cópias de senha em texto aberto que existiam até esta versão.
SET @nexa_has_plain := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'last_plain_password');
SET @nexa_sql := IF(@nexa_has_plain > 0, 'UPDATE users SET last_plain_password = NULL WHERE last_plain_password IS NOT NULL', 'SELECT 1');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

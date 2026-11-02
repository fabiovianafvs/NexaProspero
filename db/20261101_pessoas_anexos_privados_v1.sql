-- Nexa Próspero - Pessoas: anexos em arquivos privados fora do projeto PHP.
-- Apresenta SOMENTE metadados no MySQL. Aplicar pelo Atualizador do Banco (platform_admin).
CREATE TABLE IF NOT EXISTS nexa_person_attachments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 company_id INT NOT NULL,
 person_id BIGINT UNSIGNED NOT NULL,
 storage_sha256 CHAR(64) NOT NULL,
 original_sha256 CHAR(64) NOT NULL,
 original_name VARCHAR(240) NOT NULL,
 mime_type VARCHAR(120) NOT NULL,
 size_original BIGINT UNSIGNED NOT NULL,
 size_stored BIGINT UNSIGNED NOT NULL,
 source_system VARCHAR(32) NULL,
 source_reference VARCHAR(100) NULL,
 created_by INT NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 UNIQUE KEY uk_person_attachment_source (company_id, source_system, source_reference),
 KEY idx_person_attachment_person (company_id, person_id, id),
 KEY idx_person_attachment_blob (company_id, storage_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_person_attachment_audit (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 company_id INT NOT NULL,
 person_id BIGINT UNSIGNED NOT NULL,
 attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 user_id INT NOT NULL DEFAULT 0,
 action_name VARCHAR(20) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 KEY idx_pa_audit_company (company_id,person_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

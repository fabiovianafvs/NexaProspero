-- Nexa Próspero - V11.8 Atualizador Controlado de Banco
-- Data: 17/09/2026
-- Escopo global do banco. Não altera dados operacionais de empresas.
-- Esta migration é idempotente e pode ser reaplicada com segurança.

CREATE TABLE IF NOT EXISTS nexa_schema_migration_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  migration_id VARCHAR(120) NOT NULL,
  module_name VARCHAR(80) NOT NULL,
  version_number BIGINT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  executed_by_user_id INT NULL,
  database_name VARCHAR(128) NULL,
  checksum_sha256 CHAR(64) NULL,
  error_message VARCHAR(1000) NULL,
  PRIMARY KEY (id),
  KEY idx_nexa_migration_log_version (module_name,version_number),
  KEY idx_nexa_migration_log_status (status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES ('schema_updater',2026091780,NOW(),'V11.8 - Atualizador controlado de banco: execução explícita, checksum, trava global, CSRF e histórico')
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

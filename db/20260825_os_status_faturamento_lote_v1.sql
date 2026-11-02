-- Nexa Próspero - Ordem de Serviço
-- Status autorizado para Faturamento em Lote
-- Banco alvo: nexaprospero
-- Compatível com MySQL 8 / MariaDB.

START TRANSACTION;

SET @has_allow_batch_billing := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'os_statuses'
    AND column_name = 'allow_batch_billing'
);
SET @sql_allow_batch_billing := IF(
  @has_allow_batch_billing = 0,
  'ALTER TABLE os_statuses ADD COLUMN allow_batch_billing TINYINT(1) NOT NULL DEFAULT 0 AFTER is_final',
  'SELECT 1'
);
PREPARE stmt_allow_batch_billing FROM @sql_allow_batch_billing;
EXECUTE stmt_allow_batch_billing;
DEALLOCATE PREPARE stmt_allow_batch_billing;

SET @has_idx_os_status_batch := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'os_statuses'
    AND index_name = 'idx_os_status_batch_billing'
);
SET @sql_idx_os_status_batch := IF(
  @has_idx_os_status_batch = 0,
  'ALTER TABLE os_statuses ADD KEY idx_os_status_batch_billing (company_id, active, allow_batch_billing, sort_order)',
  'SELECT 1'
);
PREPARE stmt_idx_os_status_batch FROM @sql_idx_os_status_batch;
EXECUTE stmt_idx_os_status_batch;
DEALLOCATE PREPARE stmt_idx_os_status_batch;

CREATE TABLE IF NOT EXISTS nexa_schema_versions (
  module_name VARCHAR(80) NOT NULL,
  version_number BIGINT UNSIGNED NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes VARCHAR(255) NULL,
  PRIMARY KEY (module_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'ordem_servico_faturamento_lote',
  2026082503,
  NOW(),
  'Faturamento em lote de O.S. v1.2 - somente status explicitamente habilitados podem compor a fatura'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

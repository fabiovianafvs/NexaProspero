-- Nexa Próspero - Ordem de Serviço
-- Faturamento em lote: Status Faturado + liberação do Pedido consolidado para fluxo fiscal padrão
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

SET @has_billed_status := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'os_statuses'
    AND column_name = 'is_billed_status'
);
SET @sql_billed_status := IF(
  @has_billed_status = 0,
  'ALTER TABLE os_statuses ADD COLUMN is_billed_status TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_batch_billing',
  'SELECT 1'
);
PREPARE stmt_billed_status FROM @sql_billed_status;
EXECUTE stmt_billed_status;
DEALLOCATE PREPARE stmt_billed_status;

SET @has_idx_os_status_billed := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'os_statuses'
    AND index_name = 'idx_os_status_billed'
);
SET @sql_idx_os_status_billed := IF(
  @has_idx_os_status_billed = 0,
  'ALTER TABLE os_statuses ADD KEY idx_os_status_billed (company_id, active, is_billed_status)',
  'SELECT 1'
);
PREPARE stmt_idx_os_status_billed FROM @sql_idx_os_status_billed;
EXECUTE stmt_idx_os_status_billed;
DEALLOCATE PREPARE stmt_idx_os_status_billed;

-- Conveniência: se a empresa já possuir um status chamado/codificado FATURADO
-- e ainda não houver status marcado, ele passa a ser o destino automaticamente.
UPDATE os_statuses s
JOIN (
  SELECT company_id, MIN(id) AS status_id
  FROM os_statuses
  WHERE active = 1
    AND (UPPER(TRIM(code)) = 'FATURADO' OR UPPER(TRIM(name)) = 'FATURADO')
  GROUP BY company_id
) c ON c.company_id = s.company_id AND c.status_id = s.id
LEFT JOIN (
  SELECT company_id, COUNT(*) AS qty
  FROM os_statuses
  WHERE active = 1 AND is_billed_status = 1
  GROUP BY company_id
) b ON b.company_id = s.company_id
SET s.is_billed_status = 1,
    s.updated_at = NOW()
WHERE COALESCE(b.qty,0) = 0;

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
  2026082504,
  NOW(),
  'Faturamento em lote de O.S. v1.3 - status Faturado automático e Pedido consolidado liberado ao fluxo fiscal padrão do PDV'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

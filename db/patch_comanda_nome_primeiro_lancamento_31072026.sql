-- Nexa Próspero
-- Comanda / Comanda Mobile: identificação no primeiro lançamento
-- Data: 31/07/2026
-- Banco: execute no banco principal atualmente configurado pelo Nexa.

SET @nexa_db := DATABASE();

SET @nexa_has_column := (
  SELECT COUNT(*)
    FROM information_schema.columns
   WHERE table_schema = @nexa_db
     AND table_name = 'comanda_settings'
     AND column_name = 'prompt_name_first_launch'
);

SET @nexa_sql := IF(
  @nexa_has_column = 0,
  'ALTER TABLE `comanda_settings` ADD COLUMN `prompt_name_first_launch` TINYINT(1) NOT NULL DEFAULT 0 AFTER `allow_named_comanda`',
  'SELECT ''Coluna prompt_name_first_launch já existente'' AS mensagem'
);

PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

CREATE TABLE IF NOT EXISTS nexa_schema_versions (
  module_name VARCHAR(80) NOT NULL,
  version_number BIGINT UNSIGNED NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes VARCHAR(255) NULL,
  PRIMARY KEY (module_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO nexa_schema_versions (module_name, version_number, applied_at, notes)
VALUES (
  'comanda_runtime',
  2026073101,
  NOW(),
  'Comandas com identificação opcional no primeiro lançamento'
)
ON DUPLICATE KEY UPDATE
  version_number = GREATEST(version_number, VALUES(version_number)),
  applied_at = NOW(),
  notes = VALUES(notes);

SELECT
  DATABASE() AS banco_atual,
  COUNT(*) AS coluna_instalada
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'comanda_settings'
  AND column_name = 'prompt_name_first_launch';

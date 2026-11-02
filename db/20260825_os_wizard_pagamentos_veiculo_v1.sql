-- Nexa Próspero - Ordem de Serviço
-- Assistente em 5 etapas + identificação semântica de campos do veículo
-- + cadastro/consulta local por placa.
-- Banco alvo: nexaprospero
-- Compatível com MySQL 8 / MariaDB.

START TRANSACTION;

-- Identifica semanticamente os campos personalizados (Placa, Marca, Modelo, Ano, Cor etc.).
SET @has_semantic_role := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'os_custom_fields'
    AND column_name = 'semantic_role'
);
SET @sql_semantic_role := IF(
  @has_semantic_role = 0,
  'ALTER TABLE os_custom_fields ADD COLUMN semantic_role VARCHAR(40) NULL AFTER input_type',
  'SELECT 1'
);
PREPARE stmt_semantic_role FROM @sql_semantic_role;
EXECUTE stmt_semantic_role;
DEALLOCATE PREPARE stmt_semantic_role;

SET @has_semantic_idx := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'os_custom_fields'
    AND index_name = 'idx_os_custom_fields_semantic'
);
SET @sql_semantic_idx := IF(
  @has_semantic_idx = 0,
  'ALTER TABLE os_custom_fields ADD KEY idx_os_custom_fields_semantic (company_id, semantic_role, active)',
  'SELECT 1'
);
PREPARE stmt_semantic_idx FROM @sql_semantic_idx;
EXECUTE stmt_semantic_idx;
DEALLOCATE PREPARE stmt_semantic_idx;

-- Cadastro interno de veículos. A placa é a chave por empresa.
-- Os dados são alimentados pelas próprias O.S.; não depende de API externa.
CREATE TABLE IF NOT EXISTS os_vehicles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  plate VARCHAR(10) NOT NULL,
  person_id INT NULL,
  vehicle_name VARCHAR(160) NULL,
  brand VARCHAR(100) NULL,
  model VARCHAR(120) NULL,
  year_model VARCHAR(20) NULL,
  color VARCHAR(60) NULL,
  chassis VARCHAR(60) NULL,
  renavam VARCHAR(30) NULL,
  fuel VARCHAR(50) NULL,
  odometer VARCHAR(30) NULL,
  data_json LONGTEXT NULL,
  last_order_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_os_vehicle_plate (company_id, plate),
  KEY idx_os_vehicle_person (company_id, person_id),
  KEY idx_os_vehicle_last_order (company_id, last_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_schema_versions (
  module_name VARCHAR(80) NOT NULL,
  version_number BIGINT UNSIGNED NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes VARCHAR(255) NULL,
  PRIMARY KEY (module_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'ordem_servico_wizard',
  2026082501,
  NOW(),
  'Nova O.S. em 5 etapas, pagamento no padrão PDV e identificação/consulta local de veículo pela placa'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

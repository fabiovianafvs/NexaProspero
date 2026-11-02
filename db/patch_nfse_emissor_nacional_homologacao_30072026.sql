-- Nexa Próspero
-- Emissor NFS-e Nacional / DPS - homologação transacional
-- Data: 30/07/2026
-- Pré-requisito: patch_nfse_nacional_servicos_29072026.sql e Fiscal operacional instalados.

SET NAMES utf8mb4;

DELIMITER $$
DROP PROCEDURE IF EXISTS nexa_nfse_add_column_if_missing$$
CREATE PROCEDURE nexa_nfse_add_column_if_missing(
  IN p_table VARCHAR(64),
  IN p_column VARCHAR(64),
  IN p_definition TEXT
)
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = p_table
  ) AND NOT EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_column
  ) THEN
    SET @nexa_nfse_ddl = CONCAT(
      'ALTER TABLE `', REPLACE(p_table, '`', '``'),
      '` ADD COLUMN `', REPLACE(p_column, '`', '``'), '` ', p_definition
    );
    PREPARE nexa_nfse_stmt FROM @nexa_nfse_ddl;
    EXECUTE nexa_nfse_stmt;
    DEALLOCATE PREPARE nexa_nfse_stmt;
  END IF;
END$$

DROP PROCEDURE IF EXISTS nexa_nfse_add_index_if_missing$$
CREATE PROCEDURE nexa_nfse_add_index_if_missing(
  IN p_table VARCHAR(64),
  IN p_index VARCHAR(64),
  IN p_definition TEXT
)
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = p_table
  ) AND NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = p_table AND index_name = p_index
  ) THEN
    SET @nexa_nfse_ddl = CONCAT(
      'ALTER TABLE `', REPLACE(p_table, '`', '``'),
      '` ADD ', p_definition
    );
    PREPARE nexa_nfse_stmt FROM @nexa_nfse_ddl;
    EXECUTE nexa_nfse_stmt;
    DEALLOCATE PREPARE nexa_nfse_stmt;
  END IF;
END$$
DELIMITER ;

-- Rastreabilidade completa da DPS e do retorno do Ambiente Nacional.
CALL nexa_nfse_add_column_if_missing('fiscal_nfse_details', 'pedido_id', 'BIGINT NULL AFTER `company_id`');
CALL nexa_nfse_add_column_if_missing('fiscal_nfse_details', 'status', 'VARCHAR(30) NOT NULL DEFAULT ''PREPARADA'' AFTER `pedido_id`');
CALL nexa_nfse_add_column_if_missing('fiscal_nfse_details', 'environment', 'VARCHAR(20) NOT NULL DEFAULT ''HOMOLOGACAO'' AFTER `status`');
CALL nexa_nfse_add_column_if_missing('fiscal_nfse_details', 'dps_xml', 'LONGTEXT NULL');
CALL nexa_nfse_add_column_if_missing('fiscal_nfse_details', 'signed_dps_xml', 'LONGTEXT NULL');
CALL nexa_nfse_add_column_if_missing('fiscal_nfse_details', 'response_xml', 'LONGTEXT NULL');
CALL nexa_nfse_add_column_if_missing('fiscal_nfse_details', 'request_payload', 'LONGTEXT NULL');
CALL nexa_nfse_add_column_if_missing('fiscal_nfse_details', 'response_payload', 'LONGTEXT NULL');
CALL nexa_nfse_add_column_if_missing('fiscal_nfse_details', 'request_hash', 'VARCHAR(64) NULL');
CALL nexa_nfse_add_column_if_missing('fiscal_nfse_details', 'api_http_status', 'INT NULL');
CALL nexa_nfse_add_column_if_missing('fiscal_nfse_details', 'api_error_code', 'VARCHAR(40) NULL');
CALL nexa_nfse_add_column_if_missing('fiscal_nfse_details', 'api_error_message', 'TEXT NULL');
CALL nexa_nfse_add_column_if_missing('fiscal_nfse_details', 'attempts', 'INT NOT NULL DEFAULT 0');
CALL nexa_nfse_add_column_if_missing('fiscal_nfse_details', 'last_attempt_at', 'DATETIME NULL');
CALL nexa_nfse_add_column_if_missing('fiscal_nfse_details', 'authorized_at', 'DATETIME NULL');
CALL nexa_nfse_add_column_if_missing('fiscal_nfse_details', 'consulted_at', 'DATETIME NULL');
CALL nexa_nfse_add_column_if_missing('fiscal_nfse_details', 'updated_at', 'DATETIME NULL');

-- Liga registros antigos ao pedido quando o vínculo já existe no documento fiscal.
UPDATE fiscal_nfse_details nd
INNER JOIN fiscal_documents d
        ON d.company_id = nd.company_id
       AND d.id = nd.fiscal_document_id
   SET nd.pedido_id = d.source_id,
       nd.environment = COALESCE(NULLIF(d.environment, ''), nd.environment, 'HOMOLOGACAO'),
       nd.status = COALESCE(NULLIF(d.status, ''), nd.status, 'PREPARADA')
 WHERE nd.pedido_id IS NULL
   AND d.source_type = 'PEDIDO';

-- Uma DPS por pedido/ambiente e uma numeração por série/ambiente.
CALL nexa_nfse_add_index_if_missing(
  'fiscal_nfse_details',
  'ux_nfse_details_order',
  'UNIQUE KEY `ux_nfse_details_order` (`company_id`,`pedido_id`,`environment`)'
);
CALL nexa_nfse_add_index_if_missing(
  'fiscal_nfse_details',
  'ux_nfse_details_dps',
  'UNIQUE KEY `ux_nfse_details_dps` (`company_id`,`environment`,`dps_series`,`dps_number`)'
);
CALL nexa_nfse_add_index_if_missing(
  'fiscal_nfse_details',
  'idx_nfse_details_dps_id',
  'KEY `idx_nfse_details_dps_id` (`company_id`,`dps_id`)'
);
CALL nexa_nfse_add_index_if_missing(
  'fiscal_nfse_details',
  'idx_nfse_details_status',
  'KEY `idx_nfse_details_status` (`company_id`,`environment`,`status`,`last_attempt_at`)'
);

DROP PROCEDURE IF EXISTS nexa_nfse_add_index_if_missing;
DROP PROCEDURE IF EXISTS nexa_nfse_add_column_if_missing;

-- A produção permanece bloqueada no código. Para habilitá-la futuramente, depois da
-- homologação formal, será necessário configurar a empresa em PRODUCAO e definir
-- NFSE_ENABLE_PRODUCTION=1 no ambiente do servidor.

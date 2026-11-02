-- =============================================================
-- Nexa Brain v2.2
-- Outbox Worker + Redis Streams + Cache + WebSocket
-- Otimização de Comandas
-- Pré-requisito: Nexa Brain v2.1 instalado.
-- =============================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS nexa22_add_column_if_missing$$
CREATE PROCEDURE nexa22_add_column_if_missing(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND COLUMN_NAME=p_column
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', REPLACE(p_table,'`','``'), '` ADD COLUMN `', REPLACE(p_column,'`','``'), '` ', p_definition);
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$

DROP PROCEDURE IF EXISTS nexa22_add_index_if_missing$$
CREATE PROCEDURE nexa22_add_index_if_missing(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_columns TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND INDEX_NAME=p_index
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', REPLACE(p_table,'`','``'), '` ADD KEY `', REPLACE(p_index,'`','``'), '` (', p_columns, ')');
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$

DELIMITER ;

CREATE TABLE IF NOT EXISTS nexa_schema_versions (
  module_name VARCHAR(80) NOT NULL,
  version_number BIGINT UNSIGNED NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes VARCHAR(255) NULL,
  PRIMARY KEY (module_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outbox com estado intermediário e lock do worker.
-- O CREATE também torna a migração recuperável caso a tabela da v2.1 tenha sido removida.
CREATE TABLE IF NOT EXISTS brain_event_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  event_type VARCHAR(100) NOT NULL,
  entity_type VARCHAR(80) DEFAULT NULL,
  entity_id BIGINT UNSIGNED DEFAULT NULL,
  payload JSON DEFAULT NULL,
  status ENUM('pending','processing','published','failed') NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  published_at DATETIME DEFAULT NULL,
  locked_by VARCHAR(180) DEFAULT NULL,
  locked_at DATETIME DEFAULT NULL,
  redis_stream_id VARCHAR(80) DEFAULT NULL,
  last_error TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_brain_outbox_claim (status,available_at,id),
  KEY idx_brain_outbox_lock (status,locked_at),
  KEY idx_brain_outbox_company (company_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE brain_event_outbox
  MODIFY COLUMN status ENUM('pending','processing','published','failed') NOT NULL DEFAULT 'pending';

CALL nexa22_add_column_if_missing('brain_event_outbox','locked_by','VARCHAR(180) NULL');
CALL nexa22_add_column_if_missing('brain_event_outbox','locked_at','DATETIME NULL');
CALL nexa22_add_column_if_missing('brain_event_outbox','redis_stream_id','VARCHAR(80) NULL');
CALL nexa22_add_index_if_missing('brain_event_outbox','idx_brain_outbox_claim','`status`,`available_at`,`id`');
CALL nexa22_add_index_if_missing('brain_event_outbox','idx_brain_outbox_lock','`status`,`locked_at`');

CREATE TABLE IF NOT EXISTS brain_worker_heartbeat (
  worker_id VARCHAR(220) NOT NULL,
  worker_type VARCHAR(40) NOT NULL DEFAULT 'outbox',
  status VARCHAR(30) NOT NULL DEFAULT 'starting',
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_events BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  PRIMARY KEY (worker_id),
  KEY idx_brain_worker_seen (worker_type,last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Índices específicos para reduzir o custo do quadro e processamento de comandas.
CALL nexa22_add_index_if_missing('comanda_items','idx_comanda_items_open_activity','`company_id`,`status`,`comanda_id`,`created_at`');
CALL nexa22_add_index_if_missing('comandas','idx_comandas_company_status_code','`company_id`,`status`,`codigo`');
CALL nexa22_add_index_if_missing('comanda_printer_groups','idx_comanda_printer_lookup','`company_id`,`is_active`,`name`');
CALL nexa22_add_index_if_missing('produtos','idx_produtos_company_active','`company_id`,`ativo`,`id`');

-- Desbloqueia eventos abandonados por atualização/interrupção anterior.
UPDATE brain_event_outbox
   SET status='pending', locked_by=NULL, locked_at=NULL, available_at=NOW()
 WHERE status='processing';

-- A migração confirma que os módulos já estão instalados e evita DDL em cada página.
INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes) VALUES
  ('brain_profitability',2026072302,NOW(),'Nexa Brain v2.2'),
  ('gestao_runtime',2026072302,NOW(),'Gestão sem DDL por requisição'),
  ('comanda_runtime',2026072302,NOW(),'Comandas sem DDL por requisição')
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

DROP PROCEDURE IF EXISTS nexa22_add_column_if_missing;
DROP PROCEDURE IF EXISTS nexa22_add_index_if_missing;

-- Diagnóstico rápido após a instalação:
SELECT module_name,version_number,applied_at FROM nexa_schema_versions ORDER BY module_name;
SELECT status,COUNT(*) AS quantidade FROM brain_event_outbox GROUP BY status ORDER BY status;

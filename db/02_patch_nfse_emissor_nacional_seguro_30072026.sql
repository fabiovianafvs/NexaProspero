-- Nexa Próspero - correção direta do schema do Emissor NFS-e Nacional
-- Use no MESMO banco configurado em DB_NAME pela aplicação.
-- Este arquivo não usa DELIMITER nem procedures, para funcionar melhor em phpMyAdmin/cPanel.

SET NAMES utf8mb4;

SELECT DATABASE() AS banco_selecionado;

-- Confirma que a tabela-base existe no banco selecionado.
SELECT table_schema, table_name
  FROM information_schema.tables
 WHERE table_schema = DATABASE()
   AND table_name = 'fiscal_nfse_details';

-- Cada bloco adiciona a coluna somente quando ela ainda não existe.
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND column_name='pedido_id'), 'SELECT ''pedido_id já existe''', 'ALTER TABLE `fiscal_nfse_details` ADD COLUMN `pedido_id` BIGINT NULL AFTER `company_id`'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND column_name='status'), 'SELECT ''status já existe''', 'ALTER TABLE `fiscal_nfse_details` ADD COLUMN `status` VARCHAR(30) NOT NULL DEFAULT ''PREPARADA'' AFTER `pedido_id`'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND column_name='environment'), 'SELECT ''environment já existe''', 'ALTER TABLE `fiscal_nfse_details` ADD COLUMN `environment` VARCHAR(20) NOT NULL DEFAULT ''HOMOLOGACAO'' AFTER `status`'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND column_name='dps_xml'), 'SELECT ''dps_xml já existe''', 'ALTER TABLE `fiscal_nfse_details` ADD COLUMN `dps_xml` LONGTEXT NULL'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND column_name='signed_dps_xml'), 'SELECT ''signed_dps_xml já existe''', 'ALTER TABLE `fiscal_nfse_details` ADD COLUMN `signed_dps_xml` LONGTEXT NULL'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND column_name='response_xml'), 'SELECT ''response_xml já existe''', 'ALTER TABLE `fiscal_nfse_details` ADD COLUMN `response_xml` LONGTEXT NULL'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND column_name='request_payload'), 'SELECT ''request_payload já existe''', 'ALTER TABLE `fiscal_nfse_details` ADD COLUMN `request_payload` LONGTEXT NULL'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND column_name='response_payload'), 'SELECT ''response_payload já existe''', 'ALTER TABLE `fiscal_nfse_details` ADD COLUMN `response_payload` LONGTEXT NULL'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND column_name='request_hash'), 'SELECT ''request_hash já existe''', 'ALTER TABLE `fiscal_nfse_details` ADD COLUMN `request_hash` VARCHAR(64) NULL'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND column_name='api_http_status'), 'SELECT ''api_http_status já existe''', 'ALTER TABLE `fiscal_nfse_details` ADD COLUMN `api_http_status` INT NULL'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND column_name='api_error_code'), 'SELECT ''api_error_code já existe''', 'ALTER TABLE `fiscal_nfse_details` ADD COLUMN `api_error_code` VARCHAR(40) NULL'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND column_name='api_error_message'), 'SELECT ''api_error_message já existe''', 'ALTER TABLE `fiscal_nfse_details` ADD COLUMN `api_error_message` TEXT NULL'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND column_name='attempts'), 'SELECT ''attempts já existe''', 'ALTER TABLE `fiscal_nfse_details` ADD COLUMN `attempts` INT NOT NULL DEFAULT 0'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND column_name='last_attempt_at'), 'SELECT ''last_attempt_at já existe''', 'ALTER TABLE `fiscal_nfse_details` ADD COLUMN `last_attempt_at` DATETIME NULL'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND column_name='authorized_at'), 'SELECT ''authorized_at já existe''', 'ALTER TABLE `fiscal_nfse_details` ADD COLUMN `authorized_at` DATETIME NULL'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND column_name='consulted_at'), 'SELECT ''consulted_at já existe''', 'ALTER TABLE `fiscal_nfse_details` ADD COLUMN `consulted_at` DATETIME NULL'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND column_name='updated_at'), 'SELECT ''updated_at já existe''', 'ALTER TABLE `fiscal_nfse_details` ADD COLUMN `updated_at` DATETIME NULL'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Preenche vínculo e estado dos registros antigos, quando houver.
UPDATE fiscal_nfse_details nd
INNER JOIN fiscal_documents d
        ON d.company_id = nd.company_id
       AND d.id = nd.fiscal_document_id
   SET nd.pedido_id = d.source_id,
       nd.environment = COALESCE(NULLIF(d.environment, ''), nd.environment, 'HOMOLOGACAO'),
       nd.status = COALESCE(NULLIF(d.status, ''), nd.status, 'PREPARADA')
 WHERE nd.pedido_id IS NULL
   AND d.source_type = 'PEDIDO';

-- Índices de segurança. São criados somente se não existirem e não houver duplicidade prévia.
SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND index_name='ux_nfse_details_order'),
  'SELECT ''ux_nfse_details_order já existe''',
  IF(EXISTS(SELECT 1 FROM (SELECT company_id,pedido_id,environment,COUNT(*) qtd FROM fiscal_nfse_details WHERE pedido_id IS NOT NULL GROUP BY company_id,pedido_id,environment HAVING COUNT(*)>1) x),
     'SELECT ''ATENÇÃO: há pedidos duplicados; ux_nfse_details_order não foi criado''',
     'ALTER TABLE `fiscal_nfse_details` ADD UNIQUE KEY `ux_nfse_details_order` (`company_id`,`pedido_id`,`environment`)')
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND index_name='ux_nfse_details_dps'),
  'SELECT ''ux_nfse_details_dps já existe''',
  IF(EXISTS(SELECT 1 FROM (SELECT company_id,environment,dps_series,dps_number,COUNT(*) qtd FROM fiscal_nfse_details WHERE dps_series IS NOT NULL AND dps_number IS NOT NULL GROUP BY company_id,environment,dps_series,dps_number HAVING COUNT(*)>1) x),
     'SELECT ''ATENÇÃO: há DPS duplicadas; ux_nfse_details_dps não foi criado''',
     'ALTER TABLE `fiscal_nfse_details` ADD UNIQUE KEY `ux_nfse_details_dps` (`company_id`,`environment`,`dps_series`,`dps_number`)')
); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND index_name='idx_nfse_details_dps_id'), 'SELECT ''idx_nfse_details_dps_id já existe''', 'ALTER TABLE `fiscal_nfse_details` ADD KEY `idx_nfse_details_dps_id` (`company_id`,`dps_id`)'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql = IF(EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND index_name='idx_nfse_details_status'), 'SELECT ''idx_nfse_details_status já existe''', 'ALTER TABLE `fiscal_nfse_details` ADD KEY `idx_nfse_details_status` (`company_id`,`environment`,`status`,`last_attempt_at`)'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Resultado final: devem aparecer as 17 colunas abaixo.
SELECT column_name, column_type, is_nullable, column_default
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name = 'fiscal_nfse_details'
   AND column_name IN (
     'pedido_id','status','environment','dps_xml','signed_dps_xml','response_xml',
     'request_payload','response_payload','request_hash','api_http_status',
     'api_error_code','api_error_message','attempts','last_attempt_at',
     'authorized_at','consulted_at','updated_at'
   )
 ORDER BY ordinal_position;

SELECT index_name, non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS colunas
  FROM information_schema.statistics
 WHERE table_schema = DATABASE()
   AND table_name = 'fiscal_nfse_details'
   AND index_name IN ('ux_nfse_details_order','ux_nfse_details_dps','idx_nfse_details_dps_id','idx_nfse_details_status')
 GROUP BY index_name, non_unique
 ORDER BY index_name;

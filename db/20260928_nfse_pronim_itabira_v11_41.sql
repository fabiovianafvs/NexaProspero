-- Nexa Próspero V11.41
-- NFS-e Pronim / GOVBR - Padrão Nacional 1.01 - perfil inicial Itabira/MG
-- O emissor Nacional existente permanece independente e não é alterado por esta migration.
-- Migration reexecutável: cada coluna é criada somente quando ainda não existe.

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_company_config' AND column_name='nfse_pronim_profile'),
  'SELECT ''nfse_pronim_profile já existe''',
  'ALTER TABLE `fiscal_company_config` ADD COLUMN `nfse_pronim_profile` VARCHAR(40) NULL AFTER `nfse_national_standard`'
);
PREPARE nexa_v1141_stmt FROM @sql;
EXECUTE nexa_v1141_stmt;
DEALLOCATE PREPARE nexa_v1141_stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_company_config' AND column_name='nfse_pronim_hom_base'),
  'SELECT ''nfse_pronim_hom_base já existe''',
  'ALTER TABLE `fiscal_company_config` ADD COLUMN `nfse_pronim_hom_base` VARCHAR(255) NULL AFTER `nfse_pronim_profile`'
);
PREPARE nexa_v1141_stmt FROM @sql;
EXECUTE nexa_v1141_stmt;
DEALLOCATE PREPARE nexa_v1141_stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_company_config' AND column_name='nfse_pronim_prod_base'),
  'SELECT ''nfse_pronim_prod_base já existe''',
  'ALTER TABLE `fiscal_company_config` ADD COLUMN `nfse_pronim_prod_base` VARCHAR(255) NULL AFTER `nfse_pronim_hom_base`'
);
PREPARE nexa_v1141_stmt FROM @sql;
EXECUTE nexa_v1141_stmt;
DEALLOCATE PREPARE nexa_v1141_stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='fiscal_nfse_details' AND column_name='provider_code'),
  'SELECT ''provider_code já existe''',
  'ALTER TABLE `fiscal_nfse_details` ADD COLUMN `provider_code` VARCHAR(30) NULL AFTER `environment`'
);
PREPARE nexa_v1141_stmt FROM @sql;
EXECUTE nexa_v1141_stmt;
DEALLOCATE PREPARE nexa_v1141_stmt;

UPDATE fiscal_company_config
   SET nfse_provider = 'NACIONAL'
 WHERE nfse_provider IS NULL OR TRIM(nfse_provider) = '';

UPDATE fiscal_company_config
   SET nfse_pronim_profile = COALESCE(NULLIF(nfse_pronim_profile,''), 'ITABIRA_MG'),
       nfse_pronim_hom_base = COALESCE(NULLIF(nfse_pronim_hom_base,''), 'https://reformatributaria.govbr.cloud/NFSe.Api.Teste/NotaNacional'),
       nfse_pronim_prod_base = COALESCE(NULLIF(nfse_pronim_prod_base,''), 'https://webapp1-itabira.cidade360.cloud/NFSe.Api/NotaNacional');

-- Até V11.40 só existia o provider Nacional no Nexa. Preserve a origem dos documentos antigos.
UPDATE fiscal_nfse_details
   SET provider_code = 'NACIONAL'
 WHERE provider_code IS NULL OR TRIM(provider_code) = '';

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES ('nfse_pronim',20260928410,NOW(),'V11.41 - NFSe Pronim GOVBR Padrao Nacional 1.01 - Itabira MG')
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

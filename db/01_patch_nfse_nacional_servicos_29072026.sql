-- Nexa Próspero
-- Base cadastral e diagnóstico para NFS-e Nacional / DPS
-- Data: 29/07/2026
-- Compatível com instalações que já possuem o módulo Fiscal operacional.

SET NAMES utf8mb4;

DELIMITER $$
DROP PROCEDURE IF EXISTS nexa_add_column_if_missing$$
CREATE PROCEDURE nexa_add_column_if_missing(
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
    SET @nexa_ddl = CONCAT(
      'ALTER TABLE `', REPLACE(p_table, '`', '``'),
      '` ADD COLUMN `', REPLACE(p_column, '`', '``'), '` ', p_definition
    );
    PREPARE nexa_stmt FROM @nexa_ddl;
    EXECUTE nexa_stmt;
    DEALLOCATE PREPARE nexa_stmt;
  END IF;
END$$
DELIMITER ;

-- Produto/serviço.
CALL nexa_add_column_if_missing('produtos', 'is_service', 'TINYINT(1) NOT NULL DEFAULT 0');

-- Identificação da empresa prestadora.
CALL nexa_add_column_if_missing('fiscal_company_config', 'municipal_registration', 'VARCHAR(30) NULL');

-- Regra de tributação aplicável a mercadoria ou serviço.
CALL nexa_add_column_if_missing('fiscal_tax_profiles', 'profile_type', 'VARCHAR(12) NOT NULL DEFAULT ''PRODUCT''');
CALL nexa_add_column_if_missing('fiscal_tax_profiles', 'nbs_code', 'VARCHAR(20) NULL');
CALL nexa_add_column_if_missing('fiscal_tax_profiles', 'nbs_description', 'VARCHAR(255) NULL');
CALL nexa_add_column_if_missing('fiscal_tax_profiles', 'iss_withheld', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL nexa_add_column_if_missing('fiscal_tax_profiles', 'pis_cofins_retention_type', 'VARCHAR(10) NULL');
CALL nexa_add_column_if_missing('fiscal_tax_profiles', 'ibs_cbs_cst', 'VARCHAR(10) NULL');

-- Retrato fiscal do serviço no cadastro do produto.
CALL nexa_add_column_if_missing('fiscal_product_tax', 'item_type', 'VARCHAR(12) NOT NULL DEFAULT ''PRODUCT''');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'nbs_code', 'VARCHAR(20) NULL');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'nbs_description', 'VARCHAR(255) NULL');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'lc116_service_code', 'VARCHAR(10) NULL');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'lc116_service_description', 'VARCHAR(255) NULL');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'iss_rate', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'iss_withheld', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'pis_cofins_retention_type', 'VARCHAR(10) NULL');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'irrf_rate', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'csll_rate', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'inss_rate', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'municipal_tax_code', 'VARCHAR(30) NULL');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'cnae', 'VARCHAR(20) NULL');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'fiscal_incentive', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'nfse_municipal_tax_type', 'VARCHAR(80) NULL');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'nfse_municipal_tax_nature', 'VARCHAR(80) NULL');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'nfse_municipal_iss_exigibility', 'VARCHAR(80) NULL');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'nfse_municipal_special_regime', 'VARCHAR(120) NULL');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'nfse_national_tax_type', 'VARCHAR(80) NULL');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'nfse_national_tax_code', 'VARCHAR(30) NULL');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'nfse_national_operation_indicator', 'VARCHAR(30) NULL');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'nfse_simples_tax_regime', 'VARCHAR(120) NULL');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'nfse_operation_payment_type', 'VARCHAR(120) NULL');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'nfse_rtc_special_regime', 'VARCHAR(120) NULL');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'municipal_benefit_type', 'VARCHAR(80) NULL');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'municipal_differentiated_rate', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'municipal_reduction_base_percent', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'municipal_benefit_number', 'VARCHAR(60) NULL');
CALL nexa_add_column_if_missing('fiscal_product_tax', 'ibs_cbs_cst', 'VARCHAR(10) NULL');

-- Catálogo NBS por empresa. company_id = 0 representa catálogo mestre compartilhado.
CREATE TABLE IF NOT EXISTS fiscal_nbs_codes (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id BIGINT NOT NULL DEFAULT 0,
  code VARCHAR(20) NOT NULL,
  description VARCHAR(255) NOT NULL,
  national_service_code VARCHAR(10) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  source VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ux_fiscal_nbs_codes_company_code (company_id, code),
  KEY idx_fiscal_nbs_codes_lookup (company_id, is_active, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Completa o catálogo caso a tabela já existisse em versão anterior.
CALL nexa_add_column_if_missing('fiscal_nbs_codes', 'company_id', 'BIGINT NOT NULL DEFAULT 0');
CALL nexa_add_column_if_missing('fiscal_nbs_codes', 'code', 'VARCHAR(20) NOT NULL');
CALL nexa_add_column_if_missing('fiscal_nbs_codes', 'description', 'VARCHAR(255) NOT NULL');
CALL nexa_add_column_if_missing('fiscal_nbs_codes', 'national_service_code', 'VARCHAR(10) NULL');
CALL nexa_add_column_if_missing('fiscal_nbs_codes', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
CALL nexa_add_column_if_missing('fiscal_nbs_codes', 'source', 'VARCHAR(120) NULL');
CALL nexa_add_column_if_missing('fiscal_nbs_codes', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
CALL nexa_add_column_if_missing('fiscal_nbs_codes', 'updated_at', 'DATETIME NULL');

-- Migração defensiva: regras antigas em modo ISS passam a ser regras de serviço.
UPDATE fiscal_tax_profiles
   SET profile_type = 'SERVICE', tax_mode = 'ISS'
 WHERE UPPER(COALESCE(tax_mode, '')) = 'ISS'
   AND UPPER(COALESCE(profile_type, 'PRODUCT')) <> 'SERVICE';

-- Produtos já vinculados a uma regra de serviço herdam a classificação e os campos disponíveis.
UPDATE fiscal_product_tax fpt
INNER JOIN fiscal_tax_profiles tp
        ON tp.company_id = fpt.company_id
       AND tp.id = fpt.tax_profile_id
   SET fpt.item_type = 'SERVICE',
       fpt.nbs_code = COALESCE(NULLIF(fpt.nbs_code, ''), tp.nbs_code),
       fpt.nbs_description = COALESCE(NULLIF(fpt.nbs_description, ''), tp.nbs_description),
       fpt.lc116_service_code = COALESCE(NULLIF(fpt.lc116_service_code, ''), tp.lc116_service_code),
       fpt.lc116_service_description = COALESCE(NULLIF(fpt.lc116_service_description, ''), tp.lc116_service_description),
       fpt.iss_rate = CASE WHEN COALESCE(fpt.iss_rate, 0) = 0 THEN COALESCE(tp.iss_rate, 0) ELSE fpt.iss_rate END,
       fpt.iss_withheld = COALESCE(fpt.iss_withheld, tp.iss_withheld, 0),
       fpt.pis_cofins_retention_type = COALESCE(NULLIF(fpt.pis_cofins_retention_type, ''), tp.pis_cofins_retention_type),
       fpt.irrf_rate = CASE WHEN COALESCE(fpt.irrf_rate, 0) = 0 THEN COALESCE(tp.irrf_rate, 0) ELSE fpt.irrf_rate END,
       fpt.csll_rate = CASE WHEN COALESCE(fpt.csll_rate, 0) = 0 THEN COALESCE(tp.csll_rate, 0) ELSE fpt.csll_rate END,
       fpt.inss_rate = CASE WHEN COALESCE(fpt.inss_rate, 0) = 0 THEN COALESCE(tp.inss_rate, 0) ELSE fpt.inss_rate END,
       fpt.municipal_tax_code = COALESCE(NULLIF(fpt.municipal_tax_code, ''), tp.municipal_tax_code),
       fpt.cnae = COALESCE(NULLIF(fpt.cnae, ''), tp.cnae),
       fpt.nfse_municipal_tax_type = COALESCE(NULLIF(fpt.nfse_municipal_tax_type, ''), tp.nfse_municipal_tax_type),
       fpt.nfse_municipal_tax_nature = COALESCE(NULLIF(fpt.nfse_municipal_tax_nature, ''), tp.nfse_municipal_tax_nature),
       fpt.nfse_municipal_iss_exigibility = COALESCE(NULLIF(fpt.nfse_municipal_iss_exigibility, ''), tp.nfse_municipal_iss_exigibility),
       fpt.nfse_municipal_special_regime = COALESCE(NULLIF(fpt.nfse_municipal_special_regime, ''), tp.nfse_municipal_special_regime),
       fpt.nfse_national_tax_type = COALESCE(NULLIF(fpt.nfse_national_tax_type, ''), tp.nfse_national_tax_type),
       fpt.nfse_national_tax_code = COALESCE(NULLIF(fpt.nfse_national_tax_code, ''), tp.nfse_national_tax_code),
       fpt.nfse_national_operation_indicator = COALESCE(NULLIF(fpt.nfse_national_operation_indicator, ''), tp.nfse_national_operation_indicator),
       fpt.nfse_simples_tax_regime = COALESCE(NULLIF(fpt.nfse_simples_tax_regime, ''), tp.nfse_simples_tax_regime),
       fpt.nfse_operation_payment_type = COALESCE(NULLIF(fpt.nfse_operation_payment_type, ''), tp.nfse_operation_payment_type),
       fpt.nfse_rtc_special_regime = COALESCE(NULLIF(fpt.nfse_rtc_special_regime, ''), tp.nfse_rtc_special_regime),
       fpt.municipal_benefit_type = COALESCE(NULLIF(fpt.municipal_benefit_type, ''), tp.municipal_benefit_type),
       fpt.municipal_differentiated_rate = CASE WHEN COALESCE(fpt.municipal_differentiated_rate, 0) = 0 THEN COALESCE(tp.municipal_differentiated_rate, 0) ELSE fpt.municipal_differentiated_rate END,
       fpt.municipal_reduction_base_percent = CASE WHEN COALESCE(fpt.municipal_reduction_base_percent, 0) = 0 THEN COALESCE(tp.municipal_reduction_base_percent, 0) ELSE fpt.municipal_reduction_base_percent END,
       fpt.municipal_benefit_number = COALESCE(NULLIF(fpt.municipal_benefit_number, ''), tp.municipal_benefit_number),
       fpt.ibs_cbs_cst = COALESCE(NULLIF(fpt.ibs_cbs_cst, ''), tp.ibs_cbs_cst),
       fpt.ibs_cbs_classification_code = COALESCE(NULLIF(fpt.ibs_cbs_classification_code, ''), tp.ibs_cbs_classification_code),
       fpt.ibs_cbs_classification_name = COALESCE(NULLIF(fpt.ibs_cbs_classification_name, ''), tp.ibs_cbs_classification_name),
       fpt.updated_at = NOW()
 WHERE UPPER(COALESCE(tp.profile_type, 'PRODUCT')) = 'SERVICE';

UPDATE produtos p
INNER JOIN fiscal_product_tax fpt
        ON fpt.company_id = p.company_id
       AND fpt.product_id = p.id
   SET p.is_service = 1
 WHERE UPPER(COALESCE(fpt.item_type, 'PRODUCT')) = 'SERVICE';

DROP PROCEDURE IF EXISTS nexa_add_column_if_missing;

-- Depois de executar:
-- 1) Configurações fiscais: habilite NFS-e, padrão nacional, inscrição municipal,
--    certificado A1 e série DPS/NFS-e no ambiente correto.
-- 2) Tributações: crie uma regra do tipo Serviço e preencha NBS, LC 116,
--    Código de Tributação Nacional, cIndOp, CST e classificação IBS/CBS.
-- 3) Produtos: marque Serviço e selecione a regra correspondente.
-- 4) Abra Fiscal > NFS-e Nacional para validar todas as pendências cadastrais.

-- Nexa Próspero | ICMS-ST V3 | CFOP/CST/CSOSN da NF-e por empresa + MVA global.
-- Executar somente pelo Atualizador de Banco, como platform_admin.
-- Não modifica cadastros nem configurações atuais de NFC-e.
CREATE TABLE fiscal_st_nfe_profiles (
  company_id BIGINT NOT NULL,
  mva_id BIGINT NOT NULL,
  cfop CHAR(4) NOT NULL,
  cst CHAR(2) NULL,
  csosn CHAR(3) NULL,
  updated_at DATETIME NULL,
  updated_by BIGINT NULL,
  PRIMARY KEY (company_id, mva_id),
  KEY idx_st_nfe_profile_mva (mva_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

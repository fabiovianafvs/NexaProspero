-- Nexa Próspero - Validação Multiempresa Fiscal/Tributação de Produtos V8

-- 1) Versão instalada.
SELECT module_name, version_number, applied_at, notes
FROM nexa_schema_versions
WHERE module_name = 'multiempresa_contexto';

-- 2) Empresas dos grupos de homologação e módulos necessários.
SELECT
  g.id AS group_id,
  g.name AS group_name,
  g.operation_mode,
  c.id AS company_id,
  c.name AS company_name,
  CASE
    WHEN c.module_mode = 'custom' AND COALESCE(scm_multi.enabled,0) = 1
      AND (scm_multi.expires_at IS NULL OR CAST(scm_multi.expires_at AS CHAR) = '0000-00-00 00:00:00' OR scm_multi.expires_at >= NOW())
    THEN 'HABILITADO' ELSE 'DESABILITADO'
  END AS multiempresa_status,
  CASE
    WHEN c.module_mode = 'custom' AND COALESCE(scm_fiscal.enabled,0) = 1
      AND (scm_fiscal.expires_at IS NULL OR CAST(scm_fiscal.expires_at AS CHAR) = '0000-00-00 00:00:00' OR scm_fiscal.expires_at >= NOW())
    THEN 'HABILITADO' ELSE 'DESABILITADO'
  END AS fiscal_status,
  (SELECT COUNT(*) FROM produtos p WHERE p.company_id = c.id) AS produtos,
  (SELECT COUNT(*) FROM fiscal_tax_profiles ftp WHERE ftp.company_id = c.id AND ftp.is_active = 1) AS tributacoes_ativas,
  (SELECT COUNT(*) FROM fiscal_product_tax fpt WHERE fpt.company_id = c.id) AS produtos_com_fiscal
FROM business_groups g
INNER JOIN business_group_companies bgc
  ON bgc.business_group_id = g.id AND bgc.is_active = 1
INNER JOIN companies c
  ON c.id = bgc.company_id
LEFT JOIN saas_modules sm_multi ON sm_multi.code = 'multiempresa'
LEFT JOIN saas_company_modules scm_multi
  ON scm_multi.company_id = c.id AND scm_multi.module_id = sm_multi.id
LEFT JOIN saas_modules sm_fiscal ON sm_fiscal.code = 'fiscal'
LEFT JOIN saas_company_modules scm_fiscal
  ON scm_fiscal.company_id = c.id AND scm_fiscal.module_id = sm_fiscal.id
WHERE g.is_active = 1
ORDER BY g.name, bgc.is_primary DESC, c.name;

-- 3) Últimos produtos que possuem configuração fiscal, para conferir isolamento.
SELECT
  fpt.company_id,
  c.name AS company_name,
  fpt.product_id,
  p.nome AS product_name,
  fpt.tax_profile_id,
  ftp.name AS tax_profile_name,
  fpt.item_type,
  fpt.ncm,
  fpt.cest,
  fpt.nbs_code,
  fpt.updated_at
FROM fiscal_product_tax fpt
INNER JOIN companies c ON c.id = fpt.company_id
INNER JOIN produtos p ON p.id = fpt.product_id AND p.company_id = fpt.company_id
LEFT JOIN fiscal_tax_profiles ftp ON ftp.id = fpt.tax_profile_id AND ftp.company_id = fpt.company_id
ORDER BY fpt.updated_at DESC, fpt.company_id, fpt.product_id
LIMIT 30;

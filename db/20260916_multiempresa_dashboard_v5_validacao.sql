-- Nexa Próspero - Validação Multiempresa Dashboard V5

-- 1) Confirma a versão do contexto Multiempresa.
SELECT module_name, version_number, applied_at, notes
FROM nexa_schema_versions
WHERE module_name = 'multiempresa_contexto';

-- 2) Empresas dos grupos de homologação e status da feature Multiempresa.
SELECT
  g.id AS group_id,
  g.name AS group_name,
  g.operation_mode,
  c.id AS company_id,
  c.name AS company_name,
  CASE
    WHEN c.module_mode = 'custom' AND COALESCE(scm.enabled,0) = 1
      AND (scm.expires_at IS NULL OR CAST(scm.expires_at AS CHAR) = '0000-00-00 00:00:00' OR scm.expires_at >= NOW())
    THEN 'HABILITADO'
    ELSE 'DESABILITADO'
  END AS multiempresa_status
FROM business_groups g
INNER JOIN business_group_companies bgc
  ON bgc.business_group_id = g.id AND bgc.is_active = 1
INNER JOIN companies c
  ON c.id = bgc.company_id
LEFT JOIN saas_modules sm
  ON sm.code = 'multiempresa'
LEFT JOIN saas_company_modules scm
  ON scm.company_id = c.id AND scm.module_id = sm.id
WHERE g.is_active = 1
ORDER BY g.name, bgc.is_primary DESC, c.name;

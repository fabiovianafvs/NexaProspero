-- Validação Multiempresa V2.1

SELECT id, code, name, item_type, is_public_sale, is_active, sort_order
FROM saas_modules
WHERE code = 'multiempresa';

SELECT
  g.id AS group_id,
  g.name AS group_name,
  g.operation_mode,
  c.id AS company_id,
  c.name AS company_name,
  c.module_mode,
  CASE
    WHEN c.module_mode = 'custom' AND COALESCE(scm.enabled,0) = 1
      AND (scm.expires_at IS NULL OR CAST(scm.expires_at AS CHAR) = '0000-00-00 00:00:00' OR scm.expires_at >= NOW())
    THEN 'HABILITADO'
    ELSE 'DESABILITADO'
  END AS multiempresa_status,
  scm.expires_at,
  scm.note
FROM business_groups g
INNER JOIN business_group_companies bgc
  ON bgc.business_group_id = g.id AND bgc.is_active = 1
INNER JOIN companies c
  ON c.id = bgc.company_id
LEFT JOIN saas_modules sm
  ON sm.code = 'multiempresa'
LEFT JOIN saas_company_modules scm
  ON scm.company_id = c.id AND scm.module_id = sm.id
ORDER BY g.name, bgc.is_primary DESC, c.name;

SELECT module_name, version_number, applied_at, notes
FROM nexa_schema_versions
WHERE module_name IN ('multiempresa_fundacao','multiempresa_modulo_saas')
ORDER BY module_name;

-- Nexa Próspero - Validação Multiempresa Gravação Protegida V7

-- 1) Versão instalada.
SELECT module_name, version_number, applied_at, notes
FROM nexa_schema_versions
WHERE module_name = 'multiempresa_contexto';

-- 2) Grupos/empresas com status da feature Multiempresa.
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
  END AS multiempresa_status,
  (SELECT COUNT(*) FROM produtos p WHERE p.company_id = c.id) AS produtos,
  (SELECT COUNT(*) FROM finance_people fp WHERE fp.company_id = c.id AND fp.active = 1 AND fp.is_customer = 1) AS clientes_ativos
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

-- 3) Perfis efetivos dos usuários por empresa para conferir Produtos/Clientes.
SELECT
  g.name AS group_name,
  g.operation_mode,
  u.id AS user_id,
  u.name AS user_name,
  u.company_id AS entry_company_id,
  c.id AS allowed_company_id,
  c.name AS allowed_company_name,
  CASE WHEN g.operation_mode = 'integrated' THEN bgp.name ELSE up.name END AS effective_profile_name,
  CASE WHEN g.operation_mode = 'integrated' THEN bgp.permissions_json ELSE up.permissions_json END AS permissions_json
FROM business_group_users bgu
INNER JOIN business_groups g
  ON g.id = bgu.business_group_id AND g.is_active = 1
INNER JOIN users u
  ON u.id = bgu.user_id AND u.is_active = 1
INNER JOIN user_company_access uca
  ON uca.user_id = u.id AND uca.is_active = 1
INNER JOIN business_group_companies bgc
  ON bgc.business_group_id = g.id
  AND bgc.company_id = uca.company_id
  AND bgc.is_active = 1
INNER JOIN companies c
  ON c.id = uca.company_id
LEFT JOIN business_group_profiles bgp
  ON bgp.id = bgu.group_profile_id
  AND bgp.business_group_id = g.id
  AND bgp.is_active = 1
LEFT JOIN user_profiles up
  ON up.id = uca.company_profile_id
  AND up.company_id = uca.company_id
  AND up.is_active = 1
WHERE bgu.is_active = 1
ORDER BY g.name, u.name, uca.is_default DESC, c.name;

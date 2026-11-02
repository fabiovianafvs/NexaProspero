-- Nexa Próspero - Validação Multiempresa Operações Fiscais V8.2

-- 1) Versão instalada.
SELECT module_name, version_number, applied_at, notes
FROM nexa_schema_versions
WHERE module_name = 'multiempresa_contexto';

-- 2) Operações fiscais por empresa nos grupos Multiempresa.
SELECT
  g.id AS group_id,
  g.name AS group_name,
  g.operation_mode,
  c.id AS company_id,
  c.name AS company_name,
  COUNT(fo.id) AS operacoes_total,
  SUM(CASE WHEN fo.is_active = 1 THEN 1 ELSE 0 END) AS operacoes_ativas
FROM business_groups g
INNER JOIN business_group_companies bgc
  ON bgc.business_group_id = g.id AND bgc.is_active = 1
INNER JOIN companies c
  ON c.id = bgc.company_id
LEFT JOIN fiscal_operations fo
  ON fo.company_id = c.id
WHERE g.is_active = 1
GROUP BY g.id, g.name, g.operation_mode, c.id, c.name
ORDER BY g.name, c.name;

-- 3) Últimas operações cadastradas, com a empresa proprietária.
SELECT
  fo.id,
  fo.company_id,
  c.name AS company_name,
  fo.code,
  fo.name,
  fo.direction,
  fo.operation_type,
  fo.cfop_in_state,
  fo.cfop_in_interstate,
  fo.cfop_out_state,
  fo.cfop_out_interstate,
  fo.is_active,
  fo.updated_at
FROM fiscal_operations fo
INNER JOIN companies c ON c.id = fo.company_id
ORDER BY COALESCE(fo.updated_at, fo.created_at) DESC, fo.id DESC
LIMIT 40;

-- 4) Segurança: vínculos de tributação que apontam para operação de outra empresa
-- ou para uma operação inexistente. O esperado é zero linhas.
SELECT
  m.company_id AS mapping_company_id,
  m.tax_profile_id,
  m.operation_id,
  fo.company_id AS operation_company_id
FROM fiscal_tax_profile_operation_cfops m
LEFT JOIN fiscal_operations fo ON fo.id = m.operation_id
WHERE fo.id IS NULL OR fo.company_id <> m.company_id
LIMIT 50;

-- 5) Segurança: vínculos de produto que apontam para operação de outra empresa
-- ou para uma operação inexistente. O esperado é zero linhas.
SELECT
  m.company_id AS mapping_company_id,
  m.product_id,
  m.operation_id,
  fo.company_id AS operation_company_id
FROM fiscal_product_operation_cfops m
LEFT JOIN fiscal_operations fo ON fo.id = m.operation_id
WHERE fo.id IS NULL OR fo.company_id <> m.company_id
LIMIT 50;

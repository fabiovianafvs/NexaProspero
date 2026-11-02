-- Nexa Próspero - Validação Multiempresa Tributações V8.1

-- 1) Versão instalada.
SELECT module_name, version_number, applied_at, notes
FROM nexa_schema_versions
WHERE module_name = 'multiempresa_contexto';

-- 2) Tributacões por empresa nos grupos Multiempresa.
SELECT
  g.id AS group_id,
  g.name AS group_name,
  g.operation_mode,
  c.id AS company_id,
  c.name AS company_name,
  COUNT(ftp.id) AS tributacoes_total,
  SUM(CASE WHEN ftp.is_active = 1 THEN 1 ELSE 0 END) AS tributacoes_ativas
FROM business_groups g
INNER JOIN business_group_companies bgc
  ON bgc.business_group_id = g.id AND bgc.is_active = 1
INNER JOIN companies c
  ON c.id = bgc.company_id
LEFT JOIN fiscal_tax_profiles ftp
  ON ftp.company_id = c.id
WHERE g.is_active = 1
GROUP BY g.id, g.name, g.operation_mode, c.id, c.name
ORDER BY g.name, c.name;

-- 3) Últimas tributações cadastradas, com a empresa proprietária.
SELECT
  ftp.id,
  ftp.company_id,
  c.name AS company_name,
  ftp.code,
  ftp.name,
  ftp.profile_type,
  ftp.tax_mode,
  ftp.is_active,
  ftp.updated_at
FROM fiscal_tax_profiles ftp
INNER JOIN companies c ON c.id = ftp.company_id
ORDER BY ftp.updated_at DESC, ftp.id DESC
LIMIT 30;

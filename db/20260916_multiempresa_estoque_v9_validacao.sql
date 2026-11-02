-- Nexa Próspero - Validação Multiempresa Estoque V9

-- 1) Versão instalada.
SELECT module_name, version_number, applied_at, notes
FROM nexa_schema_versions
WHERE module_name = 'multiempresa_contexto';

-- 2) Resumo de produtos/saldos por empresa dos grupos Multiempresa.
SELECT
  g.id AS group_id,
  g.name AS group_name,
  g.operation_mode,
  c.id AS company_id,
  c.name AS company_name,
  COUNT(p.id) AS produtos_total,
  SUM(CASE WHEN COALESCE(p.estoque_atual,0) > 0 THEN 1 ELSE 0 END) AS saldo_positivo,
  SUM(CASE WHEN COALESCE(p.estoque_atual,0) = 0 THEN 1 ELSE 0 END) AS saldo_zerado,
  SUM(CASE WHEN COALESCE(p.estoque_atual,0) < 0 THEN 1 ELSE 0 END) AS saldo_negativo
FROM business_groups g
INNER JOIN business_group_companies bgc
  ON bgc.business_group_id = g.id AND bgc.is_active = 1
INNER JOIN companies c
  ON c.id = bgc.company_id
LEFT JOIN produtos p
  ON p.company_id = c.id
WHERE g.is_active = 1
GROUP BY g.id, g.name, g.operation_mode, c.id, c.name
ORDER BY g.name, c.name;

-- 3) Quantidade de movimentos registrada por empresa.
SELECT
  c.id AS company_id,
  c.name AS company_name,
  COUNT(em.id) AS movimentos_total,
  MAX(em.created_at) AS ultimo_movimento
FROM companies c
INNER JOIN business_group_companies bgc
  ON bgc.company_id = c.id AND bgc.is_active = 1
INNER JOIN business_groups g
  ON g.id = bgc.business_group_id AND g.is_active = 1
LEFT JOIN estoque_movimentos em
  ON em.company_id = c.id
GROUP BY c.id, c.name
ORDER BY c.name;

-- 4) Segurança: movimentos vinculados a produto de outra empresa ou inexistente.
-- O esperado é zero linhas.
SELECT
  em.id AS movimento_id,
  em.company_id AS movimento_company_id,
  em.produto_id,
  p.company_id AS produto_company_id,
  em.tipo,
  em.created_at
FROM estoque_movimentos em
LEFT JOIN produtos p ON p.id = em.produto_id
WHERE p.id IS NULL OR p.company_id <> em.company_id
ORDER BY em.id DESC
LIMIT 50;

-- 5) Últimos movimentos das empresas Multiempresa, para conferência visual.
SELECT
  em.id,
  em.company_id,
  c.name AS company_name,
  em.produto_id,
  p.nome AS produto_nome,
  em.tipo,
  em.origem_tipo,
  em.origem_id,
  em.qtd,
  em.saldo_anterior,
  em.saldo_posterior,
  em.created_at
FROM estoque_movimentos em
INNER JOIN companies c ON c.id = em.company_id
LEFT JOIN produtos p ON p.id = em.produto_id AND p.company_id = em.company_id
INNER JOIN business_group_companies bgc ON bgc.company_id = em.company_id AND bgc.is_active = 1
INNER JOIN business_groups g ON g.id = bgc.business_group_id AND g.is_active = 1
ORDER BY em.created_at DESC, em.id DESC
LIMIT 50;

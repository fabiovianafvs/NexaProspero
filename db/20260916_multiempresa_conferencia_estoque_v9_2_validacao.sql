-- Nexa Próspero - Validação Multiempresa Conferência de Estoque V9.2

-- 1) Versão instalada.
SELECT module_name, version_number, applied_at, notes
FROM nexa_schema_versions
WHERE module_name = 'multiempresa_contexto';

-- 2) Conferências por empresa dos grupos Multiempresa.
SELECT
  g.id AS group_id,
  g.name AS group_name,
  g.operation_mode,
  c.id AS company_id,
  c.name AS company_name,
  COUNT(sc.id) AS conferencias_total,
  SUM(CASE WHEN sc.status = 'DRAFT' THEN 1 ELSE 0 END) AS em_digitacao,
  SUM(CASE WHEN sc.status = 'PROCESSED' THEN 1 ELSE 0 END) AS processadas
FROM business_groups g
INNER JOIN business_group_companies bgc
  ON bgc.business_group_id = g.id AND bgc.is_active = 1
INNER JOIN companies c
  ON c.id = bgc.company_id
LEFT JOIN stock_conferences sc
  ON sc.company_id = c.id
WHERE g.is_active = 1
GROUP BY g.id, g.name, g.operation_mode, c.id, c.name
ORDER BY g.name, c.name;

-- 3) Segurança: item de conferência apontando para conferência/produto de outra empresa.
-- O esperado é ZERO LINHAS.
SELECT
  i.id AS item_id,
  i.company_id AS item_company_id,
  i.conference_id,
  sc.company_id AS conference_company_id,
  i.product_id,
  p.company_id AS product_company_id
FROM stock_conference_items i
LEFT JOIN stock_conferences sc ON sc.id = i.conference_id
LEFT JOIN produtos p ON p.id = i.product_id
WHERE sc.id IS NULL
   OR p.id IS NULL
   OR sc.company_id <> i.company_id
   OR p.company_id <> i.company_id
ORDER BY i.id DESC
LIMIT 100;

-- 4) Segurança: movimento criado por conferência em empresa/produto divergente.
-- O esperado é ZERO LINHAS.
SELECT
  em.id AS movimento_id,
  em.company_id AS movimento_company_id,
  em.origem_id AS conferencia_id,
  sc.company_id AS conferencia_company_id,
  em.produto_id,
  p.company_id AS produto_company_id,
  em.qtd,
  em.created_at
FROM estoque_movimentos em
LEFT JOIN stock_conferences sc ON sc.id = em.origem_id
LEFT JOIN produtos p ON p.id = em.produto_id
WHERE em.origem_tipo = 'CONFERENCIA_ESTOQUE'
  AND (
    sc.id IS NULL
    OR p.id IS NULL
    OR sc.company_id <> em.company_id
    OR p.company_id <> em.company_id
  )
ORDER BY em.id DESC
LIMIT 100;

-- 5) Últimos movimentos de conferência por empresa.
SELECT
  em.id,
  em.company_id,
  c.name AS company_name,
  em.origem_id AS conferencia_id,
  sc.name AS conferencia_nome,
  em.produto_id,
  p.nome AS produto_nome,
  em.qtd,
  em.saldo_anterior,
  em.saldo_posterior,
  em.created_at
FROM estoque_movimentos em
INNER JOIN companies c ON c.id = em.company_id
LEFT JOIN stock_conferences sc ON sc.id = em.origem_id AND sc.company_id = em.company_id
LEFT JOIN produtos p ON p.id = em.produto_id AND p.company_id = em.company_id
WHERE em.origem_tipo = 'CONFERENCIA_ESTOQUE'
ORDER BY em.created_at DESC, em.id DESC
LIMIT 50;

-- Nexa Próspero - Validação Multiempresa Estoque V9.1

-- 1) Versão instalada.
SELECT module_name, version_number, applied_at, notes
FROM nexa_schema_versions
WHERE module_name = 'multiempresa_contexto';

-- 2) Resumo de estoque das empresas dos grupos Multiempresa.
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

-- 3) Últimos acertos manuais por empresa.
SELECT
  em.id,
  em.company_id,
  c.name AS company_name,
  em.produto_id,
  p.nome AS produto_nome,
  em.qtd,
  em.saldo_anterior,
  em.saldo_posterior,
  em.observacao,
  em.created_at
FROM estoque_movimentos em
INNER JOIN companies c ON c.id = em.company_id
LEFT JOIN produtos p ON p.id = em.produto_id AND p.company_id = em.company_id
WHERE em.origem_tipo = 'ACERTO_MANUAL'
ORDER BY em.created_at DESC, em.id DESC
LIMIT 50;

-- 4) Segurança: movimento apontando para produto de outra empresa ou inexistente.
-- O esperado é ZERO LINHAS.
SELECT
  em.id AS movimento_id,
  em.company_id AS movimento_company_id,
  em.produto_id,
  p.company_id AS produto_company_id,
  em.tipo,
  em.origem_tipo,
  em.created_at
FROM estoque_movimentos em
LEFT JOIN produtos p ON p.id = em.produto_id
WHERE p.id IS NULL OR p.company_id <> em.company_id
ORDER BY em.id DESC
LIMIT 50;

-- 5) Segurança: produtos dos grupos Multiempresa com parâmetros básicos de estoque.
SELECT
  p.company_id,
  c.name AS company_name,
  p.id AS produto_id,
  p.nome,
  COALESCE(p.estoque_atual,0) AS estoque_atual,
  COALESCE(p.estoque_minimo,0) AS estoque_minimo,
  COALESCE(p.estoque_maximo,0) AS estoque_maximo,
  COALESCE(p.estoque_reservado,0) AS estoque_reservado,
  COALESCE(NULLIF(p.unidade_estoque,''),'UN') AS unidade_estoque
FROM produtos p
INNER JOIN companies c ON c.id = p.company_id
INNER JOIN business_group_companies bgc ON bgc.company_id = p.company_id AND bgc.is_active = 1
INNER JOIN business_groups g ON g.id = bgc.business_group_id AND g.is_active = 1
ORDER BY p.company_id, p.id DESC
LIMIT 100;

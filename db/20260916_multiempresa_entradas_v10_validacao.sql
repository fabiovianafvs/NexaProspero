-- Nexa Próspero - Validação Multiempresa Entradas de Mercadorias V10

-- 1) Versão instalada.
SELECT module_name, version_number, applied_at, notes
FROM nexa_schema_versions
WHERE module_name = 'multiempresa_contexto';

-- 2) Entradas/XML por empresa dos grupos Multiempresa.
SELECT
  g.id AS group_id,
  g.name AS group_name,
  g.operation_mode,
  c.id AS company_id,
  c.name AS company_name,
  COUNT(x.id) AS xml_total,
  SUM(CASE WHEN x.status = 'IMPORTADO' THEN 1 ELSE 0 END) AS em_conferencia,
  SUM(CASE WHEN x.status = 'ENTRADA_GERADA' THEN 1 ELSE 0 END) AS entradas_geradas,
  COALESCE(SUM(x.total_invoice),0) AS valor_total
FROM business_groups g
INNER JOIN business_group_companies bgc
  ON bgc.business_group_id = g.id AND bgc.is_active = 1
INNER JOIN companies c
  ON c.id = bgc.company_id
LEFT JOIN fiscal_xml_imports x
  ON x.company_id = c.id
WHERE g.is_active = 1
GROUP BY g.id, g.name, g.operation_mode, c.id, c.name
ORDER BY g.name, c.name;

-- 3) Segurança: item XML apontando para produto de outra empresa.
-- O esperado é ZERO LINHAS.
SELECT
  xi.id AS item_id,
  xi.company_id AS item_company_id,
  xi.xml_import_id,
  x.company_id AS xml_company_id,
  xi.product_id,
  p.company_id AS product_company_id
FROM fiscal_xml_items xi
LEFT JOIN fiscal_xml_imports x
  ON x.id = xi.xml_import_id
LEFT JOIN produtos p
  ON p.id = xi.product_id
WHERE x.id IS NULL
   OR x.company_id <> xi.company_id
   OR (xi.product_id IS NOT NULL AND xi.product_id > 0 AND (p.id IS NULL OR p.company_id <> xi.company_id))
ORDER BY xi.id DESC
LIMIT 100;

-- 4) Segurança: movimento XML_NFE vinculado a produto/XML de empresa divergente.
-- O esperado é ZERO LINHAS.
SELECT
  em.id AS movimento_id,
  em.company_id AS movimento_company_id,
  em.origem_id AS xml_id,
  x.company_id AS xml_company_id,
  em.produto_id,
  p.company_id AS produto_company_id,
  em.qtd,
  em.saldo_anterior,
  em.saldo_posterior,
  em.created_at
FROM estoque_movimentos em
LEFT JOIN fiscal_xml_imports x
  ON x.id = em.origem_id
LEFT JOIN produtos p
  ON p.id = em.produto_id
WHERE em.origem_tipo = 'XML_NFE'
  AND (
    x.id IS NULL
    OR p.id IS NULL
    OR x.company_id <> em.company_id
    OR p.company_id <> em.company_id
  )
ORDER BY em.id DESC
LIMIT 100;

-- 5) Últimas entradas geradas e indicação de vínculo financeiro.
-- No contexto F11 da V10, as novas entradas devem poder aparecer com finance_payable_ids vazio/[].
SELECT
  x.id,
  x.company_id,
  c.name AS company_name,
  x.number,
  x.series,
  x.supplier_name,
  x.total_invoice,
  x.status,
  x.operation_id,
  x.finance_payable_ids,
  x.generated_at
FROM fiscal_xml_imports x
INNER JOIN companies c ON c.id = x.company_id
WHERE x.status = 'ENTRADA_GERADA'
ORDER BY COALESCE(x.generated_at,x.updated_at) DESC, x.id DESC
LIMIT 50;

-- 6) Últimos movimentos de entrada por XML, separados por empresa.
SELECT
  em.id,
  em.company_id,
  c.name AS company_name,
  em.origem_id AS xml_id,
  x.number AS nfe_numero,
  em.produto_id,
  p.nome AS produto_nome,
  em.qtd,
  em.saldo_anterior,
  em.saldo_posterior,
  em.created_at
FROM estoque_movimentos em
INNER JOIN companies c ON c.id = em.company_id
LEFT JOIN fiscal_xml_imports x ON x.id = em.origem_id AND x.company_id = em.company_id
LEFT JOIN produtos p ON p.id = em.produto_id AND p.company_id = em.company_id
WHERE em.origem_tipo = 'XML_NFE'
ORDER BY em.created_at DESC, em.id DESC
LIMIT 50;

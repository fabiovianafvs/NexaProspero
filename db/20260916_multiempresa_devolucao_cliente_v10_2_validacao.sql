-- Nexa Próspero - Validação Multiempresa Devolução de Cliente V10.2

-- 1) Versão instalada.
SELECT module_name, version_number, applied_at, notes
FROM nexa_schema_versions
WHERE module_name = 'multiempresa_contexto';

-- 2) Devoluções de cliente por empresa dos grupos Multiempresa.
SELECT
  g.id AS group_id,
  g.name AS group_name,
  g.operation_mode,
  c.id AS company_id,
  c.name AS company_name,
  COUNT(fd.id) AS devolucoes_total,
  SUM(CASE WHEN UPPER(COALESCE(fd.status,'')) = 'AUTORIZADA' THEN 1 ELSE 0 END) AS autorizadas,
  SUM(CASE WHEN UPPER(COALESCE(fd.status,'')) = 'PRE_NFE' THEN 1 ELSE 0 END) AS pre_nfe,
  COALESCE(SUM(fd.total_document),0) AS valor_total
FROM business_groups g
INNER JOIN business_group_companies bgc
  ON bgc.business_group_id = g.id AND bgc.is_active = 1
INNER JOIN companies c
  ON c.id = bgc.company_id
LEFT JOIN fiscal_documents fd
  ON fd.company_id = c.id AND UPPER(COALESCE(fd.source_type,'')) = 'DEVOLUCAO_CLIENTE'
WHERE g.is_active = 1
GROUP BY g.id, g.name, g.operation_mode, c.id, c.name
ORDER BY g.name, c.name;

-- 3) Segurança: item de devolução apontando para produto de outra empresa.
-- O esperado é ZERO LINHAS.
SELECT
  fdi.id AS item_id,
  fdi.company_id AS item_company_id,
  fdi.fiscal_document_id,
  fd.company_id AS document_company_id,
  fdi.product_id,
  p.company_id AS product_company_id
FROM fiscal_document_items fdi
INNER JOIN fiscal_documents fd
  ON fd.id = fdi.fiscal_document_id
LEFT JOIN produtos p
  ON p.id = fdi.product_id
WHERE UPPER(COALESCE(fd.source_type,'')) = 'DEVOLUCAO_CLIENTE'
  AND (
    fd.company_id <> fdi.company_id
    OR (fdi.product_id IS NOT NULL AND fdi.product_id > 0 AND (p.id IS NULL OR p.company_id <> fdi.company_id))
  )
ORDER BY fdi.id DESC
LIMIT 100;

-- 4) Segurança: movimento de estoque de devolução ligado a empresa/produto divergente.
-- O esperado é ZERO LINHAS.
SELECT
  em.id AS movimento_id,
  em.company_id AS movimento_company_id,
  em.origem_id AS fiscal_document_id,
  fd.company_id AS document_company_id,
  em.produto_id,
  p.company_id AS produto_company_id,
  em.qtd,
  em.saldo_anterior,
  em.saldo_posterior,
  em.created_at
FROM estoque_movimentos em
INNER JOIN fiscal_documents fd
  ON fd.id = em.origem_id AND UPPER(COALESCE(fd.source_type,'')) = 'DEVOLUCAO_CLIENTE'
LEFT JOIN produtos p
  ON p.id = em.produto_id
WHERE em.origem_tipo = 'FISCAL_DOCUMENTO'
  AND (
    fd.company_id <> em.company_id
    OR p.id IS NULL
    OR p.company_id <> em.company_id
  )
ORDER BY em.id DESC
LIMIT 100;

-- 5) Segurança: participante cadastrado da devolução apontando para pessoa de outra empresa.
-- O esperado é ZERO LINHAS.
SELECT
  fp.id AS participant_id,
  fp.company_id AS participant_company_id,
  fp.fiscal_document_id,
  fp.person_id,
  pe.company_id AS person_company_id,
  fp.name,
  fp.document
FROM fiscal_document_participants fp
INNER JOIN fiscal_documents fd
  ON fd.id = fp.fiscal_document_id AND fd.company_id = fp.company_id
LEFT JOIN finance_people pe
  ON pe.id = fp.person_id
WHERE UPPER(COALESCE(fd.source_type,'')) = 'DEVOLUCAO_CLIENTE'
  AND fp.role = 'DESTINATARIO'
  AND fp.person_id IS NOT NULL
  AND fp.person_id > 0
  AND (pe.id IS NULL OR pe.company_id <> fp.company_id)
ORDER BY fp.id DESC
LIMIT 100;

-- 6) Últimas devoluções para conferência visual.
SELECT
  fd.id,
  fd.company_id,
  c.name AS company_name,
  fd.series,
  fd.number,
  fd.status,
  fd.operation_nature,
  fd.total_document,
  fd.stock_generated,
  fd.finance_generated,
  fd.issue_datetime,
  fd.created_at
FROM fiscal_documents fd
INNER JOIN companies c ON c.id = fd.company_id
WHERE UPPER(COALESCE(fd.source_type,'')) = 'DEVOLUCAO_CLIENTE'
ORDER BY fd.created_at DESC, fd.id DESC
LIMIT 50;

-- 7) Últimos movimentos de estoque dessas devoluções.
SELECT
  em.id,
  em.company_id,
  c.name AS company_name,
  em.origem_id AS fiscal_document_id,
  em.produto_id,
  p.nome AS produto_nome,
  em.tipo,
  em.qtd,
  em.saldo_anterior,
  em.saldo_posterior,
  em.created_at
FROM estoque_movimentos em
INNER JOIN fiscal_documents fd
  ON fd.id = em.origem_id AND UPPER(COALESCE(fd.source_type,'')) = 'DEVOLUCAO_CLIENTE'
INNER JOIN companies c ON c.id = em.company_id
LEFT JOIN produtos p ON p.id = em.produto_id AND p.company_id = em.company_id
WHERE em.origem_tipo = 'FISCAL_DOCUMENTO'
ORDER BY em.created_at DESC, em.id DESC
LIMIT 50;

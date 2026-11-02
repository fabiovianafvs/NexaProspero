-- Nexa Próspero - Validação Multiempresa Financeiro V11

-- 1) Versão instalada.
SELECT module_name, version_number, applied_at, notes
FROM nexa_schema_versions
WHERE module_name = 'multiempresa_contexto';

-- 2) Resumo financeiro por empresa participante de grupo Multiempresa.
-- Os valores são apenas diagnóstico; empresas sem dados podem aparecer zeradas.
SELECT
  g.id AS group_id,
  g.name AS group_name,
  g.operation_mode,
  c.id AS company_id,
  c.name AS company_name,
  COALESCE((SELECT COUNT(*) FROM finance_receivables r WHERE r.company_id=c.id),0) AS receber_titulos,
  COALESCE((SELECT SUM(r.open_amount) FROM finance_receivables r WHERE r.company_id=c.id AND r.status IN ('ABERTO','PARCIAL')),0) AS receber_aberto,
  COALESCE((SELECT COUNT(*) FROM finance_payables p WHERE p.company_id=c.id),0) AS pagar_titulos,
  COALESCE((SELECT SUM(p.open_amount) FROM finance_payables p WHERE p.company_id=c.id AND p.status IN ('ABERTO','PARCIAL')),0) AS pagar_aberto,
  COALESCE((SELECT COUNT(*) FROM finance_movements m WHERE m.company_id=c.id),0) AS movimentos_total
FROM business_groups g
INNER JOIN business_group_companies bgc
  ON bgc.business_group_id=g.id AND bgc.is_active=1
INNER JOIN companies c
  ON c.id=bgc.company_id
WHERE g.is_active=1
ORDER BY g.name,c.name;

-- 3) Segurança: Contas a Receber apontando para pessoa de outra empresa.
-- O esperado é ZERO LINHAS.
SELECT
  r.id AS receivable_id,
  r.company_id AS receivable_company_id,
  r.person_id,
  p.company_id AS person_company_id,
  r.description,
  r.open_amount
FROM finance_receivables r
LEFT JOIN finance_people p ON p.id=r.person_id
WHERE r.person_id IS NOT NULL
  AND r.person_id > 0
  AND (p.id IS NULL OR p.company_id<>r.company_id)
ORDER BY r.id DESC
LIMIT 100;

-- 4) Segurança: Contas a Pagar apontando para pessoa de outra empresa.
-- O esperado é ZERO LINHAS.
SELECT
  py.id AS payable_id,
  py.company_id AS payable_company_id,
  py.person_id,
  p.company_id AS person_company_id,
  py.description,
  py.open_amount
FROM finance_payables py
LEFT JOIN finance_people p ON p.id=py.person_id
WHERE py.person_id IS NOT NULL
  AND py.person_id > 0
  AND (p.id IS NULL OR p.company_id<>py.company_id)
ORDER BY py.id DESC
LIMIT 100;

-- 5) Segurança: títulos apontando para plano de contas de outra empresa.
-- O esperado é ZERO LINHAS.
SELECT 'RECEBER' AS origem, r.id AS titulo_id, r.company_id, r.chart_account_id, ca.company_id AS conta_company_id
FROM finance_receivables r
LEFT JOIN finance_chart_accounts ca ON ca.id=r.chart_account_id
WHERE r.chart_account_id IS NOT NULL AND r.chart_account_id>0
  AND (ca.id IS NULL OR ca.company_id<>r.company_id)
UNION ALL
SELECT 'PAGAR' AS origem, p.id AS titulo_id, p.company_id, p.chart_account_id, ca.company_id AS conta_company_id
FROM finance_payables p
LEFT JOIN finance_chart_accounts ca ON ca.id=p.chart_account_id
WHERE p.chart_account_id IS NOT NULL AND p.chart_account_id>0
  AND (ca.id IS NULL OR ca.company_id<>p.company_id)
LIMIT 100;

-- 6) Segurança: movimentos apontando para plano, centro, banco ou pessoa de outra empresa.
-- O esperado é ZERO LINHAS.
SELECT
  m.id AS movement_id,
  m.company_id AS movement_company_id,
  m.person_id,
  pe.company_id AS person_company_id,
  m.chart_account_id,
  ca.company_id AS chart_company_id,
  m.cost_center_id,
  cc.company_id AS cost_company_id,
  m.bank_account_id,
  ba.company_id AS bank_company_id
FROM finance_movements m
LEFT JOIN finance_people pe ON pe.id=m.person_id
LEFT JOIN finance_chart_accounts ca ON ca.id=m.chart_account_id
LEFT JOIN finance_cost_centers cc ON cc.id=m.cost_center_id
LEFT JOIN finance_bank_accounts ba ON ba.id=m.bank_account_id
WHERE (m.person_id IS NOT NULL AND m.person_id>0 AND (pe.id IS NULL OR pe.company_id<>m.company_id))
   OR (m.chart_account_id IS NOT NULL AND m.chart_account_id>0 AND (ca.id IS NULL OR ca.company_id<>m.company_id))
   OR (m.cost_center_id IS NOT NULL AND m.cost_center_id>0 AND (cc.id IS NULL OR cc.company_id<>m.company_id))
   OR (m.bank_account_id IS NOT NULL AND m.bank_account_id>0 AND (ba.id IS NULL OR ba.company_id<>m.company_id))
ORDER BY m.id DESC
LIMIT 100;

-- 7) Últimos títulos a receber, separados por empresa, para conferência visual.
SELECT r.id,r.company_id,c.name AS company_name,r.issue_date,r.due_date,r.description,r.amount,r.open_amount,r.status
FROM finance_receivables r
INNER JOIN companies c ON c.id=r.company_id
INNER JOIN business_group_companies bgc ON bgc.company_id=r.company_id AND bgc.is_active=1
INNER JOIN business_groups g ON g.id=bgc.business_group_id AND g.is_active=1
ORDER BY r.id DESC
LIMIT 30;

-- 8) Últimos títulos a pagar, separados por empresa, para conferência visual.
SELECT p.id,p.company_id,c.name AS company_name,p.issue_date,p.due_date,p.description,p.amount,p.open_amount,p.status
FROM finance_payables p
INNER JOIN companies c ON c.id=p.company_id
INNER JOIN business_group_companies bgc ON bgc.company_id=p.company_id AND bgc.is_active=1
INNER JOIN business_groups g ON g.id=bgc.business_group_id AND g.is_active=1
ORDER BY p.id DESC
LIMIT 30;

-- 9) Últimos movimentos financeiros, separados por empresa, para conferência visual.
SELECT m.id,m.company_id,c.name AS company_name,m.movement_date,m.type,m.description,m.amount,m.origin_type,m.status
FROM finance_movements m
INNER JOIN companies c ON c.id=m.company_id
INNER JOIN business_group_companies bgc ON bgc.company_id=m.company_id AND bgc.is_active=1
INNER JOIN business_groups g ON g.id=bgc.business_group_id AND g.is_active=1
ORDER BY m.id DESC
LIMIT 30;

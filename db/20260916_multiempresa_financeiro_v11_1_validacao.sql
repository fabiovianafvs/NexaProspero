-- Nexa Próspero - Validação Multiempresa Financeiro V11.1

-- 1) Versão instalada.
SELECT module_name, version_number, applied_at, notes
FROM nexa_schema_versions
WHERE module_name = 'multiempresa_contexto';

-- 2) Resumo financeiro por empresa participante de grupo Multiempresa.
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
  COALESCE((SELECT COUNT(*) FROM finance_settlements s WHERE s.company_id=c.id),0) AS baixas_total,
  COALESCE((SELECT COUNT(*) FROM finance_movements m WHERE m.company_id=c.id),0) AS movimentos_total
FROM business_groups g
INNER JOIN business_group_companies bgc
  ON bgc.business_group_id=g.id AND bgc.is_active=1
INNER JOIN companies c
  ON c.id=bgc.company_id
WHERE g.is_active=1
ORDER BY g.name,c.name;

-- 3) Segurança: Contas a Receber apontando para pessoa de outra empresa.
-- Esperado: ZERO LINHAS.
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
-- Esperado: ZERO LINHAS.
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
-- Esperado: ZERO LINHAS.
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

-- 6) Segurança: títulos apontando para centro de custo de outra empresa.
-- Esperado: ZERO LINHAS.
SELECT 'RECEBER' AS origem, r.id AS titulo_id, r.company_id, r.cost_center_id, cc.company_id AS centro_company_id
FROM finance_receivables r
LEFT JOIN finance_cost_centers cc ON cc.id=r.cost_center_id
WHERE r.cost_center_id IS NOT NULL AND r.cost_center_id>0
  AND (cc.id IS NULL OR cc.company_id<>r.company_id)
UNION ALL
SELECT 'PAGAR' AS origem, p.id AS titulo_id, p.company_id, p.cost_center_id, cc.company_id AS centro_company_id
FROM finance_payables p
LEFT JOIN finance_cost_centers cc ON cc.id=p.cost_center_id
WHERE p.cost_center_id IS NOT NULL AND p.cost_center_id>0
  AND (cc.id IS NULL OR cc.company_id<>p.company_id)
LIMIT 100;

-- 7) Segurança: settlements apontando para título inexistente/empresa diferente.
-- Esperado: ZERO LINHAS.
SELECT s.id AS settlement_id,s.company_id,s.origin_type,s.origin_id
FROM finance_settlements s
LEFT JOIN finance_receivables r
  ON s.origin_type='RECEBER' AND r.company_id=s.company_id AND r.id=s.origin_id
LEFT JOIN finance_payables p
  ON s.origin_type='PAGAR' AND p.company_id=s.company_id AND p.id=s.origin_id
WHERE (s.origin_type='RECEBER' AND r.id IS NULL)
   OR (s.origin_type='PAGAR' AND p.id IS NULL)
ORDER BY s.id DESC
LIMIT 100;

-- 8) Últimos títulos a receber por empresa para conferência visual.
SELECT r.id,r.company_id,c.name AS company_name,r.issue_date,r.due_date,r.description,r.amount,r.paid_amount,r.open_amount,r.status
FROM finance_receivables r
INNER JOIN companies c ON c.id=r.company_id
INNER JOIN business_group_companies bgc ON bgc.company_id=r.company_id AND bgc.is_active=1
INNER JOIN business_groups g ON g.id=bgc.business_group_id AND g.is_active=1
ORDER BY r.id DESC
LIMIT 30;

-- 9) Últimos títulos a pagar por empresa para conferência visual.
SELECT p.id,p.company_id,c.name AS company_name,p.issue_date,p.due_date,p.description,p.amount,p.paid_amount,p.open_amount,p.status
FROM finance_payables p
INNER JOIN companies c ON c.id=p.company_id
INNER JOIN business_group_companies bgc ON bgc.company_id=p.company_id AND bgc.is_active=1
INNER JOIN business_groups g ON g.id=bgc.business_group_id AND g.is_active=1
ORDER BY p.id DESC
LIMIT 30;

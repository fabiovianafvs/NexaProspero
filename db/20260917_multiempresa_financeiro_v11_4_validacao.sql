-- Nexa Próspero - Validação Multiempresa Financeiro V11.4

-- 1) Versão instalada.
SELECT module_name,version_number,applied_at,notes
FROM nexa_schema_versions
WHERE module_name='multiempresa_contexto';

-- 2) Quantidade de contas analíticas ativas por empresa Multiempresa.
SELECT c.id company_id,c.name company_name,
       COUNT(ca.id) contas_analiticas_ativas
FROM companies c
INNER JOIN business_group_companies bgc ON bgc.company_id=c.id AND bgc.is_active=1
LEFT JOIN finance_chart_accounts ca ON ca.company_id=c.id AND ca.active=1 AND ca.is_analytic=1
GROUP BY c.id,c.name
ORDER BY c.name;

-- 3) Centros de custo ativos por empresa.
SELECT c.id company_id,c.name company_name,COUNT(cc.id) centros_ativos
FROM companies c
INNER JOIN business_group_companies bgc ON bgc.company_id=c.id AND bgc.is_active=1
LEFT JOIN finance_cost_centers cc ON cc.company_id=c.id AND cc.active=1
GROUP BY c.id,c.name
ORDER BY c.name;

-- 4) Clientes e fornecedores ativos por empresa.
SELECT c.id company_id,c.name company_name,
       SUM(CASE WHEN p.active=1 AND p.is_customer=1 THEN 1 ELSE 0 END) clientes_ativos,
       SUM(CASE WHEN p.active=1 AND p.is_supplier=1 THEN 1 ELSE 0 END) fornecedores_ativos
FROM companies c
INNER JOIN business_group_companies bgc ON bgc.company_id=c.id AND bgc.is_active=1
LEFT JOIN finance_people p ON p.company_id=c.id
GROUP BY c.id,c.name
ORDER BY c.name;

-- 5) Segurança: títulos apontando para plano de contas de outra empresa/inexistente.
-- Esperado: ZERO LINHAS.
SELECT 'RECEBER' origem,r.id titulo_id,r.company_id,r.chart_account_id
FROM finance_receivables r
WHERE r.chart_account_id IS NOT NULL AND r.chart_account_id>0
  AND NOT EXISTS (SELECT 1 FROM finance_chart_accounts ca WHERE ca.company_id=r.company_id AND ca.id=r.chart_account_id)
UNION ALL
SELECT 'PAGAR',p.id,p.company_id,p.chart_account_id
FROM finance_payables p
WHERE p.chart_account_id IS NOT NULL AND p.chart_account_id>0
  AND NOT EXISTS (SELECT 1 FROM finance_chart_accounts ca WHERE ca.company_id=p.company_id AND ca.id=p.chart_account_id)
LIMIT 100;

-- 6) Segurança: títulos apontando para centro de custo de outra empresa/inexistente.
-- Esperado: ZERO LINHAS.
SELECT 'RECEBER' origem,r.id titulo_id,r.company_id,r.cost_center_id
FROM finance_receivables r
WHERE r.cost_center_id IS NOT NULL AND r.cost_center_id>0
  AND NOT EXISTS (SELECT 1 FROM finance_cost_centers cc WHERE cc.company_id=r.company_id AND cc.id=r.cost_center_id)
UNION ALL
SELECT 'PAGAR',p.id,p.company_id,p.cost_center_id
FROM finance_payables p
WHERE p.cost_center_id IS NOT NULL AND p.cost_center_id>0
  AND NOT EXISTS (SELECT 1 FROM finance_cost_centers cc WHERE cc.company_id=p.company_id AND cc.id=p.cost_center_id)
LIMIT 100;

-- 7) Segurança: títulos apontando para pessoa de outra empresa/inexistente.
-- Esperado: ZERO LINHAS.
SELECT 'RECEBER' origem,r.id titulo_id,r.company_id,r.person_id
FROM finance_receivables r
WHERE r.person_id IS NOT NULL AND r.person_id>0
  AND NOT EXISTS (SELECT 1 FROM finance_people fp WHERE fp.company_id=r.company_id AND fp.id=r.person_id)
UNION ALL
SELECT 'PAGAR',p.id,p.company_id,p.person_id
FROM finance_payables p
WHERE p.person_id IS NOT NULL AND p.person_id>0
  AND NOT EXISTS (SELECT 1 FROM finance_people fp WHERE fp.company_id=p.company_id AND fp.id=p.person_id)
LIMIT 100;

-- Nexa Próspero - Validação Multiempresa Financeiro V11.5
-- Execute APÓS a migration V11.5 e a cópia dos PHPs.
-- Consultas de validação: não alteram dados.

-- 1) Versão instalada. Esperado: version_number >= 2026091723.
SELECT module_name,version_number,applied_at,notes
FROM nexa_schema_versions
WHERE module_name='multiempresa_contexto';

-- 2) Resumo das regras de Despesas Fixas por empresa.
SELECT c.id AS company_id,
       c.name AS company_name,
       COUNT(f.id) AS regras_total,
       SUM(CASE WHEN f.active=1 THEN 1 ELSE 0 END) AS regras_ativas
FROM companies c
INNER JOIN business_group_companies bgc ON bgc.company_id=c.id AND bgc.is_active=1
LEFT JOIN finance_fixed_expenses f ON f.company_id=c.id
GROUP BY c.id,c.name
ORDER BY c.name;

-- 3) Horizonte das regras ativas.
-- provisoes_abertas deve normalmente ficar igual ao horizonte configurado.
-- Uma quantidade maior pode permanecer apenas quando uma provisão futura já foi
-- modificada/baixada e, por segurança, não pôde ser recolhida automaticamente.
SELECT f.company_id,
       f.id AS fixed_expense_id,
       f.description,
       f.horizon_count,
       SUM(CASE WHEN p.status IN ('ABERTO','PARCIAL') AND COALESCE(p.open_amount,0)>0.009 THEN 1 ELSE 0 END) AS provisoes_abertas,
       MAX(ft.reference_month) AS ultima_competencia_gerada
FROM finance_fixed_expenses f
LEFT JOIN finance_fixed_expense_titles ft
       ON ft.company_id=f.company_id AND ft.fixed_expense_id=f.id
LEFT JOIN finance_payables p
       ON p.company_id=ft.company_id AND p.id=ft.payable_id
WHERE f.active=1
GROUP BY f.company_id,f.id,f.description,f.horizon_count
ORDER BY f.company_id,f.id;

-- 4) Segurança: vínculo de provisão sem regra ou sem título na MESMA empresa.
-- Esperado: ZERO LINHAS.
SELECT ft.id AS vinculo_id,ft.company_id,ft.fixed_expense_id,ft.payable_id,
       CASE WHEN f.id IS NULL THEN 'REGRA_INEXISTENTE' ELSE 'TITULO_INEXISTENTE' END AS problema
FROM finance_fixed_expense_titles ft
LEFT JOIN finance_fixed_expenses f
       ON f.company_id=ft.company_id AND f.id=ft.fixed_expense_id
LEFT JOIN finance_payables p
       ON p.company_id=ft.company_id AND p.id=ft.payable_id
WHERE f.id IS NULL OR p.id IS NULL
LIMIT 100;

-- 5) Segurança: regra ativa apontando para fornecedor inválido/inativo ou de outra empresa.
-- Esperado: ZERO LINHAS.
SELECT f.company_id,f.id AS fixed_expense_id,f.person_id,f.description
FROM finance_fixed_expenses f
WHERE f.active=1
  AND NOT EXISTS (
    SELECT 1 FROM finance_people p
    WHERE p.company_id=f.company_id AND p.id=f.person_id AND p.active=1 AND p.is_supplier=1
  )
LIMIT 100;

-- 6) Segurança: regra ativa apontando para Plano de Contas inválido/não analítico.
-- Esperado: ZERO LINHAS.
SELECT f.company_id,f.id AS fixed_expense_id,f.chart_account_id,f.description
FROM finance_fixed_expenses f
WHERE f.active=1
  AND NOT EXISTS (
    SELECT 1 FROM finance_chart_accounts ca
    WHERE ca.company_id=f.company_id AND ca.id=f.chart_account_id AND ca.active=1 AND ca.is_analytic=1
  )
LIMIT 100;

-- 7) Segurança: Centro de Custo informado, porém inválido/não analítico.
-- Esperado: ZERO LINHAS.
SELECT f.company_id,f.id AS fixed_expense_id,f.cost_center_id,f.description
FROM finance_fixed_expenses f
WHERE f.active=1
  AND f.cost_center_id IS NOT NULL AND f.cost_center_id>0
  AND NOT EXISTS (
    SELECT 1 FROM finance_cost_centers cc
    WHERE cc.company_id=f.company_id AND cc.id=f.cost_center_id AND cc.active=1 AND cc.is_analytic=1
  )
LIMIT 100;

-- 8) Segurança: Caixa/Banco preferencial informado, porém inválido/inativo.
-- Esperado: ZERO LINHAS.
SELECT f.company_id,f.id AS fixed_expense_id,f.bank_account_id,f.description
FROM finance_fixed_expenses f
WHERE f.active=1
  AND f.bank_account_id IS NOT NULL AND f.bank_account_id>0
  AND NOT EXISTS (
    SELECT 1 FROM finance_bank_accounts b
    WHERE b.company_id=f.company_id AND b.id=f.bank_account_id AND b.active=1
  )
LIMIT 100;

-- 9) Auditoria: provisões canceladas automaticamente por estorno protegido V11.5.
-- Apenas informativo; pode retornar linhas após testes de estorno.
SELECT p.company_id,p.id AS payable_id,ft.fixed_expense_id,ft.reference_month,p.status,p.notes,p.updated_at
FROM finance_payables p
INNER JOIN finance_fixed_expense_titles ft
        ON ft.company_id=p.company_id AND ft.payable_id=p.id
WHERE p.status='CANCELADO'
  AND p.notes LIKE '%V11.5: provisão futura cancelada automaticamente%'
ORDER BY p.updated_at DESC,p.id DESC
LIMIT 100;

-- 10) Auditoria das baixas ativas/estornadas em títulos de Despesas Fixas.
SELECT s.company_id,ft.fixed_expense_id,s.origin_id AS payable_id,
       s.id AS settlement_id,COALESCE(s.status,'ATIVO') AS settlement_status,
       s.settlement_date,s.reversed_at,s.reversal_reason
FROM finance_settlements s
INNER JOIN finance_fixed_expense_titles ft
        ON ft.company_id=s.company_id AND ft.payable_id=s.origin_id
WHERE s.origin_type='PAGAR'
ORDER BY s.company_id,ft.fixed_expense_id,s.id DESC
LIMIT 200;

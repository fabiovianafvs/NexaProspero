-- Nexa Próspero - Validação Multiempresa Financeiro V11.2

-- 1) Versão instalada.
SELECT module_name, version_number, applied_at, notes
FROM nexa_schema_versions
WHERE module_name = 'multiempresa_contexto';

-- 2) Resumo de títulos, baixas e movimentos por empresa participante de grupo Multiempresa.
SELECT
  g.id AS group_id,
  g.name AS group_name,
  g.operation_mode,
  c.id AS company_id,
  c.name AS company_name,
  COALESCE((SELECT COUNT(*) FROM finance_receivables r WHERE r.company_id=c.id),0) AS receber_titulos,
  COALESCE((SELECT COUNT(*) FROM finance_payables p WHERE p.company_id=c.id),0) AS pagar_titulos,
  COALESCE((SELECT COUNT(*) FROM finance_settlements s WHERE s.company_id=c.id),0) AS baixas_total,
  COALESCE((SELECT SUM(s.amount+s.fine_amount+s.interest_amount-s.discount_amount) FROM finance_settlements s WHERE s.company_id=c.id),0) AS baixas_valor,
  COALESCE((SELECT COUNT(*) FROM finance_movements m WHERE m.company_id=c.id),0) AS movimentos_total
FROM business_groups g
INNER JOIN business_group_companies bgc
  ON bgc.business_group_id=g.id AND bgc.is_active=1
INNER JOIN companies c
  ON c.id=bgc.company_id
WHERE g.is_active=1
ORDER BY g.name,c.name;

-- 3) Segurança: settlement sem título correspondente NA MESMA EMPRESA.
-- Esperado: ZERO LINHAS.
SELECT s.id AS settlement_id,s.company_id,s.origin_type,s.origin_id
FROM finance_settlements s
WHERE (s.origin_type='RECEBER' AND NOT EXISTS (
        SELECT 1 FROM finance_receivables r
        WHERE r.company_id=s.company_id AND r.id=s.origin_id
      ))
   OR (s.origin_type='PAGAR' AND NOT EXISTS (
        SELECT 1 FROM finance_payables p
        WHERE p.company_id=s.company_id AND p.id=s.origin_id
      ))
ORDER BY s.id DESC
LIMIT 100;

-- 4) Segurança: settlement apontando para movimento inexistente ou de outra empresa.
-- Esperado: ZERO LINHAS.
SELECT s.id AS settlement_id,s.company_id,s.movement_id
FROM finance_settlements s
WHERE s.movement_id IS NOT NULL
  AND s.movement_id > 0
  AND NOT EXISTS (
    SELECT 1 FROM finance_movements m
    WHERE m.company_id=s.company_id AND m.id=s.movement_id
  )
ORDER BY s.id DESC
LIMIT 100;

-- 5) Segurança: pagamentos de settlement ligados a settlement/movimento de outra empresa.
-- Esperado: ZERO LINHAS.
SELECT sp.id AS settlement_payment_id,sp.company_id,sp.settlement_id,sp.movement_id
FROM finance_settlement_payments sp
WHERE NOT EXISTS (
        SELECT 1 FROM finance_settlements s
        WHERE s.company_id=sp.company_id AND s.id=sp.settlement_id
      )
   OR (sp.movement_id IS NOT NULL AND sp.movement_id>0 AND NOT EXISTS (
        SELECT 1 FROM finance_movements m
        WHERE m.company_id=sp.company_id AND m.id=sp.movement_id
      ))
ORDER BY sp.id DESC
LIMIT 100;

-- 6) Segurança: caixa/banco de settlement, pagamento ou movimento não pertence à mesma empresa.
-- Esperado: ZERO LINHAS.
SELECT 'SETTLEMENT' AS origem,s.id AS registro_id,s.company_id,s.bank_account_id
FROM finance_settlements s
WHERE s.bank_account_id IS NOT NULL AND s.bank_account_id>0
  AND NOT EXISTS (
    SELECT 1 FROM finance_bank_accounts b
    WHERE b.company_id=s.company_id AND b.id=s.bank_account_id
  )
UNION ALL
SELECT 'SETTLEMENT_PAYMENT',sp.id,sp.company_id,sp.bank_account_id
FROM finance_settlement_payments sp
WHERE sp.bank_account_id IS NOT NULL AND sp.bank_account_id>0
  AND NOT EXISTS (
    SELECT 1 FROM finance_bank_accounts b
    WHERE b.company_id=sp.company_id AND b.id=sp.bank_account_id
  )
UNION ALL
SELECT 'MOVEMENT',m.id,m.company_id,m.bank_account_id
FROM finance_movements m
WHERE m.bank_account_id IS NOT NULL AND m.bank_account_id>0
  AND NOT EXISTS (
    SELECT 1 FROM finance_bank_accounts b
    WHERE b.company_id=m.company_id AND b.id=m.bank_account_id
  )
LIMIT 100;

-- 7) Segurança: forma de pagamento registrada na divisão da baixa não pertence à empresa.
-- Esperado: ZERO LINHAS.
SELECT sp.id AS settlement_payment_id,sp.company_id,sp.payment_method_id
FROM finance_settlement_payments sp
WHERE sp.payment_method_id IS NOT NULL AND sp.payment_method_id>0
  AND NOT EXISTS (
    SELECT 1 FROM finance_payment_methods pm
    WHERE pm.company_id=sp.company_id AND pm.id=sp.payment_method_id
  )
ORDER BY sp.id DESC
LIMIT 100;

-- 8) Segurança: movimento RECEBER/PAGAR sem título correspondente na mesma empresa.
-- RECEBER_MULTI/PAGAR_MULTI possuem origin_id NULL por desenho e não entram nesta checagem.
-- Esperado: ZERO LINHAS.
SELECT m.id AS movement_id,m.company_id,m.origin_type,m.origin_id
FROM finance_movements m
WHERE (m.origin_type='RECEBER' AND m.origin_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM finance_receivables r
        WHERE r.company_id=m.company_id AND r.id=m.origin_id
      ))
   OR (m.origin_type='PAGAR' AND m.origin_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM finance_payables p
        WHERE p.company_id=m.company_id AND p.id=m.origin_id
      ))
ORDER BY m.id DESC
LIMIT 100;

-- 9) Segurança de integridade do título: paid/open/status incompatíveis com baixas.
-- Pode apontar legado antigo que já estava inconsistente; analisar antes de corrigir.
SELECT 'RECEBER' AS origem,r.id AS titulo_id,r.company_id,r.amount,r.paid_amount,r.open_amount,r.status,
       COALESCE((SELECT SUM(s.amount+s.fine_amount+s.interest_amount-s.discount_amount)
                 FROM finance_settlements s
                 WHERE s.company_id=r.company_id AND s.origin_type='RECEBER' AND s.origin_id=r.id),0) AS baixado_por_settlement
FROM finance_receivables r
INNER JOIN business_group_companies bgc ON bgc.company_id=r.company_id AND bgc.is_active=1
WHERE r.paid_amount < -0.009 OR r.open_amount < -0.009
UNION ALL
SELECT 'PAGAR',p.id,p.company_id,p.amount,p.paid_amount,p.open_amount,p.status,
       COALESCE((SELECT SUM(s.amount+s.fine_amount+s.interest_amount-s.discount_amount)
                 FROM finance_settlements s
                 WHERE s.company_id=p.company_id AND s.origin_type='PAGAR' AND s.origin_id=p.id),0)
FROM finance_payables p
INNER JOIN business_group_companies bgc ON bgc.company_id=p.company_id AND bgc.is_active=1
WHERE p.paid_amount < -0.009 OR p.open_amount < -0.009
LIMIT 100;

-- 10) Últimas baixas por empresa para conferência visual.
SELECT
  s.id AS settlement_id,
  s.company_id,
  c.name AS company_name,
  s.origin_type,
  s.origin_id,
  s.settlement_date,
  s.payment_method,
  s.bank_account_id,
  ROUND(s.amount+s.fine_amount+s.interest_amount-s.discount_amount,2) AS valor_baixa,
  s.movement_id
FROM finance_settlements s
INNER JOIN companies c ON c.id=s.company_id
INNER JOIN business_group_companies bgc ON bgc.company_id=s.company_id AND bgc.is_active=1
ORDER BY s.id DESC
LIMIT 40;

-- 11) Últimos movimentos de baixa por empresa para conferência visual.
SELECT
  m.id AS movement_id,
  m.company_id,
  c.name AS company_name,
  m.movement_date,
  m.type,
  m.origin_type,
  m.origin_id,
  m.bank_account_id,
  m.amount,
  m.description
FROM finance_movements m
INNER JOIN companies c ON c.id=m.company_id
INNER JOIN business_group_companies bgc ON bgc.company_id=m.company_id AND bgc.is_active=1
WHERE m.origin_type IN ('RECEBER','PAGAR','RECEBER_MULTI','PAGAR_MULTI')
ORDER BY m.id DESC
LIMIT 40;

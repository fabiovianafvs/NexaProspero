-- Nexa Próspero - Validação V11.6.1 Estorno Protegido
-- Somente leitura.

SELECT module_name,version_number,applied_at,notes
FROM nexa_schema_versions
WHERE module_name='multiempresa_contexto';

SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='finance_settlements'
  AND COLUMN_NAME IN ('status','reversed_at','reversed_by_user_id','reversal_reason','reversal_movement_id')
ORDER BY ORDINAL_POSITION;

-- Esperado: ZERO LINHAS. Baixa ativa sem título de origem.
SELECT s.id,s.company_id,s.origin_type,s.origin_id
FROM finance_settlements s
LEFT JOIN finance_receivables r ON s.origin_type='RECEBER' AND r.company_id=s.company_id AND r.id=s.origin_id
LEFT JOIN finance_payables p ON s.origin_type='PAGAR' AND p.company_id=s.company_id AND p.id=s.origin_id
WHERE COALESCE(s.status,'ATIVO')='ATIVO'
  AND ((s.origin_type='RECEBER' AND r.id IS NULL) OR (s.origin_type='PAGAR' AND p.id IS NULL));

-- Esperado: ZERO LINHAS para baixas novas com distribuição por pagamentos.
SELECT s.id AS settlement_id,s.company_id,
       ROUND(s.amount+s.fine_amount+s.interest_amount-s.discount_amount,2) AS baixa_total,
       ROUND(COALESCE(SUM(sp.amount),0),2) AS pagamentos_total
FROM finance_settlements s
JOIN finance_settlement_payments sp ON sp.company_id=s.company_id AND sp.settlement_id=s.id
WHERE COALESCE(s.status,'ATIVO')='ATIVO'
GROUP BY s.id,s.company_id,s.amount,s.fine_amount,s.interest_amount,s.discount_amount
HAVING ABS(baixa_total-pagamentos_total)>0.01;

-- Auditoria dos últimos estornos.
SELECT s.id AS settlement_id,s.company_id,s.origin_type,s.origin_id,s.status,
       s.reversed_at,s.reversed_by_user_id,s.reversal_reason,s.reversal_movement_id
FROM finance_settlements s
WHERE s.status='ESTORNADO'
ORDER BY s.reversed_at DESC,s.id DESC
LIMIT 50;

-- Contra-lançamentos do estorno. O movimento original permanece preservado.
SELECT m.id,m.company_id,m.movement_date,m.type,m.amount,m.origin_type,m.origin_id,m.description,m.notes
FROM finance_movements m
WHERE m.origin_type='ESTORNO_BAIXA'
ORDER BY m.id DESC
LIMIT 100;

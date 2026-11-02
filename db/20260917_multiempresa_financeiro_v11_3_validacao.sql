-- Nexa Próspero - Validação Multiempresa Financeiro V11.3
SELECT module_name,version_number,applied_at,notes FROM nexa_schema_versions WHERE module_name='multiempresa_contexto';
SHOW COLUMNS FROM finance_settlements WHERE Field IN ('status','reversed_at','reversed_by_user_id','reversal_reason','reversal_movement_id');
SELECT company_id,status,COUNT(*) qtd,ROUND(SUM(amount+fine_amount+interest_amount-discount_amount),2) total FROM finance_settlements GROUP BY company_id,status ORDER BY company_id,status;
-- Esperado ZERO LINHAS:
SELECT s.id,s.company_id,s.origin_type,s.origin_id FROM finance_settlements s LEFT JOIN finance_receivables r ON s.origin_type='RECEBER' AND r.id=s.origin_id AND r.company_id=s.company_id LEFT JOIN finance_payables p ON s.origin_type='PAGAR' AND p.id=s.origin_id AND p.company_id=s.company_id WHERE COALESCE(s.status,'ATIVO')='ATIVO' AND ((s.origin_type='RECEBER' AND r.id IS NULL) OR (s.origin_type='PAGAR' AND p.id IS NULL));
-- Esperado ZERO LINHAS:
SELECT m.id,m.company_id,m.bank_account_id,b.company_id bank_company_id FROM finance_movements m JOIN finance_bank_accounts b ON b.id=m.bank_account_id WHERE m.bank_account_id IS NOT NULL AND m.bank_account_id>0 AND b.company_id<>m.company_id;
-- Esperado ZERO LINHAS:
SELECT id,company_id,origin_type,origin_id,status,reversed_at,reversed_by_user_id,reversal_reason FROM finance_settlements WHERE status='ESTORNADO' AND (reversed_at IS NULL OR COALESCE(TRIM(reversal_reason),'')='');
SELECT id,company_id,movement_date,type,amount,origin_type,origin_id,status FROM finance_movements WHERE origin_type='ESTORNO_BAIXA' ORDER BY id DESC LIMIT 50;
SELECT b.company_id,c.name company_name,b.id,b.name,b.type,b.bank_name,b.initial_balance,b.active FROM finance_bank_accounts b JOIN companies c ON c.id=b.company_id ORDER BY b.company_id,b.name;

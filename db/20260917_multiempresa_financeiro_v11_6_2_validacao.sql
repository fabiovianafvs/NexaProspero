-- Nexa Próspero - Financeiro V11.6.2 - Validação
SELECT DATABASE() AS banco_em_uso;

SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='finance_settlements'
  AND COLUMN_NAME IN ('status','reversed_at','reversed_by_user_id','reversal_reason','reversal_movement_id')
ORDER BY ORDINAL_POSITION;

SELECT INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS colunas
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='finance_settlements'
  AND INDEX_NAME IN ('idx_fin_set_status','idx_fin_set_reversal_movement')
GROUP BY INDEX_NAME;

SELECT id,company_id,origin_type,origin_id,settlement_date,amount,fine_amount,interest_amount,discount_amount,movement_id,status,reversed_at,reversal_movement_id,reversal_reason
FROM finance_settlements
ORDER BY id DESC
LIMIT 20;

SELECT id,company_id,movement_date,type,origin_type,origin_id,amount,status,description
FROM finance_movements
WHERE origin_type='ESTORNO_BAIXA'
ORDER BY id DESC
LIMIT 20;

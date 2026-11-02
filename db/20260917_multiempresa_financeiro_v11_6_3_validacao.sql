-- Nexa Próspero V11.6.3 - validação do schema de estorno
SELECT DATABASE() AS banco_em_uso;
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='finance_settlements'
  AND COLUMN_NAME IN ('status','reversed_at','reversed_by_user_id','reversal_reason','reversal_movement_id')
ORDER BY FIELD(COLUMN_NAME,'status','reversed_at','reversed_by_user_id','reversal_reason','reversal_movement_id');

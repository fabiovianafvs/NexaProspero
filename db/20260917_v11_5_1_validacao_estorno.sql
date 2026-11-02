-- Nexa Próspero V11.5.1 - validação do estorno protegido
-- Não altera dados. Serve para confirmar que a migration V11.3 está instalada.
SELECT module_name, version_number, applied_at, notes
  FROM nexa_schema_versions
 WHERE module_name='multiempresa_contexto';

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
  FROM INFORMATION_SCHEMA.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 'finance_settlements'
   AND COLUMN_NAME IN ('status','reversed_at','reversed_by_user_id','reversal_reason','reversal_movement_id')
 ORDER BY ORDINAL_POSITION;

-- Esperado: nenhuma linha com baixa ATIVA apontando para movimento inexistente.
SELECT s.id AS settlement_id, s.company_id, s.origin_type, s.origin_id, s.movement_id
  FROM finance_settlements s
  LEFT JOIN finance_movements m
    ON m.company_id=s.company_id AND m.id=s.movement_id
 WHERE COALESCE(s.status,'ATIVO')='ATIVO'
   AND COALESCE(s.movement_id,0)>0
   AND m.id IS NULL;

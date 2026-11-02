-- Nexa Próspero v1.9.2 - dados fiscais/identidade em Pessoas
-- Idempotência principal é feita pelo finance_ensure_schema()/worker.
-- Execute preferencialmente: php workers/migrate_people_fiscal_fields_v1.php

SET @db := DATABASE();
SET @tbl := 'finance_people';

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME=@tbl AND COLUMN_NAME='state_registration')=0,
  'ALTER TABLE finance_people ADD COLUMN state_registration VARCHAR(30) NULL AFTER document', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME=@tbl AND COLUMN_NAME='municipal_registration')=0,
  'ALTER TABLE finance_people ADD COLUMN municipal_registration VARCHAR(30) NULL AFTER state_registration', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME=@tbl AND COLUMN_NAME='identity_document')=0,
  'ALTER TABLE finance_people ADD COLUMN identity_document VARCHAR(40) NULL AFTER municipal_registration', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;


SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME=@tbl AND COLUMN_NAME='identity_issuer')=0,
  'ALTER TABLE finance_people ADD COLUMN identity_issuer VARCHAR(40) NULL AFTER identity_document', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME=@tbl AND COLUMN_NAME='identity_state')=0,
  'ALTER TABLE finance_people ADD COLUMN identity_state VARCHAR(2) NULL AFTER identity_issuer', 'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

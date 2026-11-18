-- Despesas Fixas: periodicidade Mensal (como antes), Diária ou A cada N dias.
-- Idempotente. Só acrescenta colunas; as despesas existentes continuam MENSAL.
--
-- frequency     MENSAL | DIARIA | PERIODO
-- interval_days N do "a cada N dias" (PERIODO)
-- period_mode   CORRIDOS (conta N dias a partir do 1º vencimento) | FIXOS (os mesmos dias todo mês)

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='finance_fixed_expenses')=1
  AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='finance_fixed_expenses' AND column_name='frequency')=0,
  'ALTER TABLE `finance_fixed_expenses`
     ADD COLUMN `frequency` VARCHAR(10) NOT NULL DEFAULT ''MENSAL'' AFTER `due_day`,
     ADD COLUMN `interval_days` SMALLINT UNSIGNED NULL AFTER `frequency`,
     ADD COLUMN `period_mode` VARCHAR(10) NOT NULL DEFAULT ''CORRIDOS'' AFTER `interval_days`',
  'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

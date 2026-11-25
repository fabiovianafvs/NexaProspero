-- Pessoas - Código integração v1: código da pessoa em outro sistema (ex.: ID do sistema
-- anterior numa importação por planilha). Aparece no cadastro de Pessoas, entra na pesquisa
-- e é preenchido pela coluna "Código Integração" da importação de clientes.
-- Idempotente: pode ser aplicado mais de uma vez.

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='finance_people')=1
  AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='finance_people' AND column_name='integration_code')=0,
  'ALTER TABLE `finance_people` ADD COLUMN `integration_code` VARCHAR(60) NULL',
  'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='finance_people' AND column_name='integration_code')=1
  AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='finance_people' AND index_name='idx_finance_people_integration')=0,
  'ALTER TABLE `finance_people` ADD INDEX `idx_finance_people_integration` (`company_id`, `integration_code`)',
  'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

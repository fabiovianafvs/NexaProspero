-- Nexa Próspero - Multiempresa Isolado V12: nota fiscal em PRODUÇÃO pelo F11.
-- Liberação por Grupo Empresarial (SaaS > Grupos Empresariais). Desligada por padrão:
-- sem marcar a opção, o F11 continua emitindo somente em HOMOLOGAÇÃO. Idempotente.

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='business_groups' AND column_name='f11_fiscal_production')=0,
  'ALTER TABLE `business_groups` ADD COLUMN `f11_fiscal_production` TINYINT(1) NOT NULL DEFAULT 0 AFTER `operation_mode`', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

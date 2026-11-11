-- Nexa Próspero - Multiempresa Isolado V13: troca completa de empresa pelo F11.
-- "Administrador nesta empresa" por usuário/empresa em Acessos multiempresa
-- (acesso total na empresa escolhida, sem precisar de perfil). Idempotente.

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='user_company_access' AND column_name='is_admin')=0,
  'ALTER TABLE `user_company_access` ADD COLUMN `is_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `company_profile_id`', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

-- Etiquetas dos contatos (ex.: VIP, Inadimplente, Revenda), separadas por vírgula.
-- Idempotente: só cria a coluna se a tabela existir e a coluna ainda não existir.
SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='person_contacts')=1
  AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='person_contacts' AND column_name='tags')=0,
  'ALTER TABLE `person_contacts` ADD COLUMN `tags` VARCHAR(255) NULL AFTER `notes`', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

-- WhatsApp - Atendimento v4: editar mensagens enviadas (e mostrar quando o cliente edita).
-- Requer 20261118_whatsapp_atendimento_v3. Idempotente: pode ser aplicado mais de uma vez.
--
-- whatsapp_bot_messages:
--   edited_at      quando a mensagem foi editada (a tela mostra "Editada")
--   edit_original  texto antes da 1ª edição (histórico; não aparece para o cliente)

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='whatsapp_bot_messages')=1
  AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='whatsapp_bot_messages' AND column_name='edited_at')=0,
  'ALTER TABLE `whatsapp_bot_messages`
     ADD COLUMN `edited_at` DATETIME NULL AFTER `deleted_for_all`,
     ADD COLUMN `edit_original` TEXT NULL AFTER `edited_at`',
  'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

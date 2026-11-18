-- WhatsApp - Atendimento v3: responder (citar), encaminhar, apagar mensagens e grupos do WhatsApp.
-- Requer 20261110_whatsapp_atendimento_v1. Idempotente: pode ser aplicado mais de uma vez.
--
-- whatsapp_bot_messages:
--   reply_to_id       mensagem citada (id desta tabela), quando a resposta cita outra
--   quoted_message_id id da mensagem citada no WhatsApp (mensagens recebidas)
--   forwarded         1 = mensagem encaminhada
--   deleted_at/by     mensagem apagada (o registro fica, só o conteúdo some da tela)
--   deleted_for_all   1 = apagada também no celular do cliente
--   participant_*     quem escreveu, nas conversas de grupo
-- whatsapp_bot_sessions:
--   group_name        nome do grupo (conversas de grupo usam status 'GRUPO')

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='whatsapp_bot_messages')=1
  AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='whatsapp_bot_messages' AND column_name='reply_to_id')=0,
  'ALTER TABLE `whatsapp_bot_messages`
     ADD COLUMN `reply_to_id` BIGINT NULL AFTER `send_ok`,
     ADD COLUMN `quoted_message_id` VARCHAR(80) NULL AFTER `reply_to_id`,
     ADD COLUMN `forwarded` TINYINT(1) NOT NULL DEFAULT 0 AFTER `quoted_message_id`,
     ADD COLUMN `deleted_at` DATETIME NULL AFTER `forwarded`,
     ADD COLUMN `deleted_by` INT NULL AFTER `deleted_at`,
     ADD COLUMN `deleted_for_all` TINYINT(1) NOT NULL DEFAULT 0 AFTER `deleted_by`,
     ADD COLUMN `participant_phone` VARCHAR(20) NULL AFTER `deleted_for_all`,
     ADD COLUMN `participant_name` VARCHAR(120) NULL AFTER `participant_phone`',
  'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='whatsapp_bot_sessions')=1
  AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='whatsapp_bot_sessions' AND column_name='group_name')=0,
  'ALTER TABLE `whatsapp_bot_sessions` ADD COLUMN `group_name` VARCHAR(150) NULL AFTER `push_name`',
  'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

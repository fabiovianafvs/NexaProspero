-- Situação das mensagens enviadas (✓ enviada, ✓✓ entregue, ✓✓ azul lida) no Atendimento WhatsApp.
-- wa_status: 1 = enviada ao WhatsApp, 2 = entregue no celular, 3 = lida. NULL = sem informação.
SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='whatsapp_bot_messages')=1
  AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='whatsapp_bot_messages' AND column_name='wa_status')=0,
  'ALTER TABLE `whatsapp_bot_messages` ADD COLUMN `wa_status` TINYINT NULL AFTER `send_ok`', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

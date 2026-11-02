-- Nexa Próspero V11.52.8 — CRM: Temperatura do Lead e Mensagens
-- Adiciona temperatura atual/histórica ao CRM e auditoria de mensagens enviadas em tramitações.
-- Aplicar exclusivamente pelo Atualizador Controlado do Banco.

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='crm_attendances' AND column_name='lead_temperature')=0,
  'ALTER TABLE `crm_attendances` ADD COLUMN `lead_temperature` TINYINT UNSIGNED NOT NULL DEFAULT 20 AFTER `request_text`', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='crm_attendance_moves' AND column_name='lead_temperature')=0,
  'ALTER TABLE `crm_attendance_moves` ADD COLUMN `lead_temperature` TINYINT UNSIGNED NULL AFTER `movement_text`', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='crm_attendance_moves' AND column_name='message_channel')=0,
  'ALTER TABLE `crm_attendance_moves` ADD COLUMN `message_channel` VARCHAR(20) NULL AFTER `lead_temperature`', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='crm_attendance_moves' AND column_name='message_model_id')=0,
  'ALTER TABLE `crm_attendance_moves` ADD COLUMN `message_model_id` INT NULL AFTER `message_channel`', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='crm_attendance_moves' AND column_name='message_to')=0,
  'ALTER TABLE `crm_attendance_moves` ADD COLUMN `message_to` VARCHAR(60) NULL AFTER `message_model_id`', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='crm_attendance_moves' AND column_name='message_text')=0,
  'ALTER TABLE `crm_attendance_moves` ADD COLUMN `message_text` TEXT NULL AFTER `message_to`', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='crm_attendance_moves' AND column_name='message_status')=0,
  'ALTER TABLE `crm_attendance_moves` ADD COLUMN `message_status` VARCHAR(20) NULL AFTER `message_text`', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='crm_attendance_moves' AND column_name='message_error')=0,
  'ALTER TABLE `crm_attendance_moves` ADD COLUMN `message_error` VARCHAR(500) NULL AFTER `message_status`', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='crm_attendance_moves' AND column_name='message_sent_at')=0,
  'ALTER TABLE `crm_attendance_moves` ADD COLUMN `message_sent_at` DATETIME NULL AFTER `message_error`', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='crm_attendances' AND index_name='idx_crm_att_temperature')=0,
  'ALTER TABLE `crm_attendances` ADD KEY `idx_crm_att_temperature` (`company_id`,`lead_temperature`)', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql; EXECUTE nexa_stmt; DEALLOCATE PREPARE nexa_stmt;

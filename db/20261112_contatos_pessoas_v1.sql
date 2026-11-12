-- Nexa Próspero - Contatos das pessoas (quem chama, contador, financeiro, compras...).
-- Usados no cadastro de pessoas (aba Contatos), na busca de contato do Atendimento
-- WhatsApp e para reconhecer de qual cliente é a conversa que chega. Idempotente.

CREATE TABLE IF NOT EXISTS `person_contacts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT NOT NULL,
  `person_id` INT NULL,
  `name` VARCHAR(120) NOT NULL,
  `role` VARCHAR(60) NULL,
  `whatsapp` VARCHAR(30) NULL,
  `phone` VARCHAR(30) NULL,
  `email` VARCHAR(150) NULL,
  `notes` VARCHAR(500) NULL,
  `whatsapp_digits` VARCHAR(20) NULL,
  `phone_digits` VARCHAR(20) NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_person_contacts_person` (`company_id`, `person_id`),
  KEY `idx_person_contacts_wa` (`company_id`, `whatsapp_digits`),
  KEY `idx_person_contacts_phone` (`company_id`, `phone_digits`),
  KEY `idx_person_contacts_name` (`company_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversa do WhatsApp ligada ao contato (além da pessoa).
SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='whatsapp_bot_sessions')=1
  AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='whatsapp_bot_sessions' AND column_name='contact_id')=0,
  'ALTER TABLE `whatsapp_bot_sessions` ADD COLUMN `contact_id` INT UNSIGNED NULL AFTER `person_id`', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

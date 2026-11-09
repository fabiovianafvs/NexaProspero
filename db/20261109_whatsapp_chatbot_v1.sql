-- Nexa Próspero - Chatbot do WhatsApp (v1)
-- Conversas do atendimento automático por empresa e histórico das mensagens.
-- Idempotente: pode ser aplicado mais de uma vez.

CREATE TABLE IF NOT EXISTS whatsapp_bot_sessions (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  contact VARCHAR(80) NOT NULL,
  phone VARCHAR(20) NULL,
  push_name VARCHAR(120) NULL,
  state VARCHAR(40) NOT NULL DEFAULT 'MENU',
  data_json TEXT NULL,
  paused_until DATETIME NULL,
  doc_attempts INT NOT NULL DEFAULT 0,
  doc_attempts_since DATETIME NULL,
  last_message_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_wa_bot_contact (company_id, contact),
  KEY idx_wa_bot_last (company_id, last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_bot_messages (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  contact VARCHAR(80) NOT NULL,
  direction VARCHAR(3) NOT NULL,
  message_id VARCHAR(80) NULL,
  body TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_wa_bot_msg (company_id, message_id),
  KEY idx_wa_bot_msg_contact (company_id, contact, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Módulo comercial do SaaS: o chatbot só funciona nas empresas em que a
-- plataforma liberar o módulo "Chatbot WhatsApp" (plano ou personalização).
INSERT INTO saas_modules
  (code, name, description, monthly_cost, monthly_price, setup_cost, setup_price,
   is_public_sale, is_active, sort_order)
SELECT
  'whatsapp_chatbot', 'Chatbot WhatsApp',
  'Atendimento automático no WhatsApp: 2ª via de boleto, consulta de pedido, abertura de atendimento no CRM, mensagens automáticas e transferência para atendente.',
  0.00, 0.00, 0.00, 0.00, 0, 1, 29
WHERE NOT EXISTS (SELECT 1 FROM saas_modules WHERE code='whatsapp_chatbot');

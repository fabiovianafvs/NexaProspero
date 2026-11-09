-- Nexa Próspero - WhatsApp: fluxo do chatbot em árvore, setores e tela de atendimento.
-- Requer 20261109_whatsapp_chatbot_v1 (tabelas whatsapp_bot_sessions/messages).
-- Idempotente: pode ser aplicado mais de uma vez.

CREATE TABLE IF NOT EXISTS whatsapp_departments (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  name VARCHAR(80) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wa_dep_company (company_id, active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_department_users (
  company_id INT NOT NULL,
  department_id INT NOT NULL,
  user_id INT NOT NULL,
  PRIMARY KEY (department_id, user_id),
  KEY idx_wa_dep_user (company_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Opções do menu do robô (árvore: parent_id NULL = menu inicial).
-- action: submenu | text | boleto | pedido | crm | setor
CREATE TABLE IF NOT EXISTS whatsapp_bot_nodes (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  parent_id INT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  label VARCHAR(80) NOT NULL,
  action VARCHAR(20) NOT NULL DEFAULT 'text',
  message TEXT NULL,
  department_id INT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wa_node_parent (company_id, parent_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversa = sessão do contato. status: BOT | PENDENTE | ATENDIMENTO | FINALIZADO
SET @nexa_has_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'whatsapp_bot_sessions' AND column_name = 'status');
SET @nexa_sql := IF(@nexa_has_col = 0,
  'ALTER TABLE whatsapp_bot_sessions
     ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT ''BOT'' AFTER state,
     ADD COLUMN department_id INT NULL AFTER status,
     ADD COLUMN user_id INT NULL AFTER department_id,
     ADD COLUMN person_id INT NULL AFTER user_id,
     ADD COLUMN unread INT NOT NULL DEFAULT 0 AFTER person_id,
     ADD COLUMN last_preview VARCHAR(200) NULL AFTER unread,
     ADD COLUMN last_direction VARCHAR(3) NULL AFTER last_preview,
     ADD COLUMN queued_at DATETIME NULL AFTER last_direction,
     ADD COLUMN accepted_at DATETIME NULL AFTER queued_at,
     ADD COLUMN closed_at DATETIME NULL AFTER accepted_at,
     ADD COLUMN human_at DATETIME NULL AFTER closed_at,
     ADD COLUMN crm_attendance_id INT NULL AFTER human_at,
     ADD KEY idx_wa_bot_status (company_id, status, last_message_at)',
  'SELECT 1');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

-- Mensagens: quem enviou, tipo e arquivo (guardado fora da pasta pública).
SET @nexa_has_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'whatsapp_bot_messages' AND column_name = 'sender');
SET @nexa_sql := IF(@nexa_has_col = 0,
  'ALTER TABLE whatsapp_bot_messages
     ADD COLUMN session_id BIGINT NULL AFTER contact,
     ADD COLUMN sender VARCHAR(12) NULL AFTER direction,
     ADD COLUMN user_id INT NULL AFTER sender,
     ADD COLUMN msg_type VARCHAR(20) NULL AFTER message_id,
     ADD COLUMN media_path VARCHAR(255) NULL AFTER body,
     ADD COLUMN media_mime VARCHAR(100) NULL AFTER media_path,
     ADD COLUMN media_name VARCHAR(180) NULL AFTER media_mime,
     ADD COLUMN media_size INT NULL AFTER media_name,
     ADD COLUMN send_ok TINYINT(1) NOT NULL DEFAULT 1 AFTER media_size,
     ADD KEY idx_wa_bot_msg_session (company_id, session_id, id)',
  'SELECT 1');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

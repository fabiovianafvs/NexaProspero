-- Chat interno: conversas entre os usuários da empresa (diretas e em grupo).
-- Idempotente: pode ser aplicado mais de uma vez. Não altera tabelas existentes.
--
-- chat_conversations: kind DIRETA (duas pessoas; direct_key = "menor-maior" ids) ou GRUPO (nome).
-- chat_members:       quem participa; last_read_id = última mensagem lida (contador de não lidas);
--                     left_at = saiu do grupo (o histórico fica).
-- chat_messages:      texto e/ou arquivo (guardado fora da pasta pública); user_id NULL = aviso do sistema;
--                     deleted_at = apagada (o conteúdo some da tela).

CREATE TABLE IF NOT EXISTS chat_conversations (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  kind VARCHAR(10) NOT NULL DEFAULT 'DIRETA',
  name VARCHAR(80) NULL,
  direct_key VARCHAR(40) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_message_id BIGINT NULL,
  last_message_at DATETIME NULL,
  last_preview VARCHAR(200) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_chat_direct (company_id, direct_key),
  KEY idx_chat_conv_last (company_id, last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_members (
  conversation_id BIGINT NOT NULL,
  company_id INT NOT NULL,
  user_id INT NOT NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  last_read_id BIGINT NOT NULL DEFAULT 0,
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  left_at DATETIME NULL,
  PRIMARY KEY (conversation_id, user_id),
  KEY idx_chat_member_user (company_id, user_id, left_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  conversation_id BIGINT NOT NULL,
  user_id INT NULL,
  body TEXT NULL,
  media_path VARCHAR(255) NULL,
  media_mime VARCHAR(100) NULL,
  media_name VARCHAR(180) NULL,
  media_size INT NULL,
  reply_to_id BIGINT NULL,
  deleted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_chat_msg_conv (company_id, conversation_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

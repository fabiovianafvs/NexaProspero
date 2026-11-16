-- Painel do Atendimento (WhatsApp > Painel do Atendimento).
-- whatsapp_bot_sessions guarda só a situação ATUAL de cada contato; aqui fica o
-- histórico: um registro por atendimento (da entrada na fila/aceite até finalizar)
-- e os assuntos escolhidos no menu do robô / fluxos iniciados.

CREATE TABLE IF NOT EXISTS whatsapp_attendances (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  session_id BIGINT NOT NULL,
  contact VARCHAR(80) NOT NULL,
  department_id INT NULL,
  user_id INT NULL,
  first_user_id INT NULL,
  topic VARCHAR(160) NULL,
  origin VARCHAR(12) NOT NULL DEFAULT 'robo',
  opened_at DATETIME NOT NULL,
  queued_at DATETIME NULL,
  accepted_at DATETIME NULL,
  last_out_at DATETIME NULL,
  closed_at DATETIME NULL,
  close_reason VARCHAR(20) NULL,
  transfers INT NOT NULL DEFAULT 0,
  msgs_in INT NOT NULL DEFAULT 0,
  msgs_out INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_wa_att_open (company_id, session_id, closed_at),
  KEY idx_wa_att_period (company_id, opened_at),
  KEY idx_wa_att_user (company_id, user_id, opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_chat_events (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  session_id BIGINT NOT NULL,
  event VARCHAR(20) NOT NULL,
  label VARCHAR(160) NOT NULL,
  ref_id INT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_wa_evt_period (company_id, event, created_at),
  KEY idx_wa_evt_session (company_id, session_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

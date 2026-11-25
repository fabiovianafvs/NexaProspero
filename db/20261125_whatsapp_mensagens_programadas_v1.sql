-- WhatsApp - Mensagens programadas v1: no Atendimento, o atendente escreve uma mensagem
-- e escolhe o dia e a hora; o agendador (workers/whatsapp_campanhas_worker.php, timer
-- nexa-whatsapp-campanhas, a cada minuto) envia sozinho na hora marcada.
-- Idempotente; não altera tabelas existentes.
--
-- whatsapp_scheduled_messages: uma linha por mensagem programada.
--   send_at  = dia e hora como o atendente digitou (horário da empresa), para mostrar na tela;
--   due_utc  = o mesmo momento em UTC, usado pelo agendador (vale para qualquer fuso);
--   status   = AGENDADA / ENVIANDO / ENVIADA / FALHOU / CANCELADA / SIMULADA;
--   message_row_id = mensagem gravada na conversa (whatsapp_bot_messages.id) depois do envio.

CREATE TABLE IF NOT EXISTS whatsapp_scheduled_messages (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  session_id BIGINT NOT NULL,
  user_id INT NULL,
  body TEXT NOT NULL,
  send_at DATETIME NOT NULL,
  due_utc DATETIME NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'AGENDADA',
  attempts TINYINT NOT NULL DEFAULT 0,
  next_try_utc DATETIME NULL,
  message_row_id BIGINT NULL,
  error VARCHAR(255) NULL,
  cancelled_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  sent_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_wa_sched_due (status, due_utc),
  KEY idx_wa_sched_chat (company_id, session_id, status),
  KEY idx_wa_sched_msg (company_id, message_row_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

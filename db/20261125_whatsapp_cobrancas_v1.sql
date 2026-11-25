-- WhatsApp - Cobranças v1: régua de cobrança do Contas a Receber pelo WhatsApp
-- (ex.: 5 dias antes, no dia, 5 dias depois, 30 e 60 dias depois do vencimento).
-- Requer "WhatsApp - Campanhas v1". Idempotente; não altera tabelas existentes.
--
-- whatsapp_billing_sends: um registro por título + etapa da régua + vencimento (não repete
-- o mesmo lembrete; se o vencimento for prorrogado, a régua recomeça para a nova data).
-- A mensagem vai pela fila whatsapp_outbox (outbox_id; o Pix copia e cola em pix_outbox_id).

CREATE TABLE IF NOT EXISTS whatsapp_billing_sends (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  receivable_id INT NOT NULL,
  person_id INT NULL,
  step_days SMALLINT NOT NULL,
  due_date DATE NOT NULL,
  run_date DATE NOT NULL,
  name VARCHAR(150) NOT NULL DEFAULT '',
  phone VARCHAR(30) NOT NULL DEFAULT '',
  amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  message TEXT NOT NULL,
  has_boleto TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(12) NOT NULL DEFAULT 'NA_FILA',
  outbox_id BIGINT NULL,
  pix_outbox_id BIGINT NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_wa_billing_step (company_id, receivable_id, step_days, due_date),
  KEY idx_wa_billing_run (company_id, run_date),
  KEY idx_wa_billing_status (company_id, status),
  KEY idx_wa_billing_outbox (outbox_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- WhatsApp - Campanhas v1: mensagens programadas para um público (aniversariantes,
-- quem comprou nos últimos dias, quem não compra há algum tempo ou todos) e campanha
-- automática de aniversário (todo dia, na hora marcada).
-- Idempotente: pode ser aplicado mais de uma vez. Não altera tabelas existentes.
--
-- whatsapp_campaigns:            a campanha (tipo UNICA = envio programado; ANIVERSARIO = automática diária).
-- whatsapp_campaign_recipients:  a lista de quem recebe (prévia conferida antes de agendar) e a situação
--                                de cada envio (a mensagem vai pela fila whatsapp_outbox, espaçada).
-- whatsapp_optouts:              números que pediram para não receber campanhas (responderam PARAR
--                                ou foram incluídos na tela).

CREATE TABLE IF NOT EXISTS whatsapp_campaigns (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  kind VARCHAR(12) NOT NULL DEFAULT 'UNICA',
  audience VARCHAR(20) NOT NULL DEFAULT 'TODOS',
  audience_json TEXT NULL,
  message TEXT NOT NULL,
  scheduled_at DATETIME NULL,
  send_time CHAR(5) NULL,
  days_before TINYINT NOT NULL DEFAULT 0,
  status VARCHAR(12) NOT NULL DEFAULT 'RASCUNHO',
  preview_at DATETIME NULL,
  last_run_date DATE NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_by INT NULL,
  confirmed_by INT NULL,
  confirmed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wa_camp_company (company_id, status),
  KEY idx_wa_camp_due (status, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_campaign_recipients (
  id BIGINT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  campaign_id BIGINT NOT NULL,
  run_date DATE NOT NULL,
  person_id INT NULL,
  name VARCHAR(150) NOT NULL DEFAULT '',
  phone VARCHAR(30) NOT NULL,
  message TEXT NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'PREVISTO',
  outbox_id BIGINT NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  queued_at DATETIME NULL,
  sent_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_wa_camp_rec (campaign_id, run_date, phone),
  KEY idx_wa_camp_rec_status (company_id, campaign_id, status),
  KEY idx_wa_camp_rec_outbox (outbox_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_optouts (
  company_id INT NOT NULL,
  phone VARCHAR(30) NOT NULL,
  source VARCHAR(10) NOT NULL DEFAULT 'CLIENTE',
  user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (company_id, phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

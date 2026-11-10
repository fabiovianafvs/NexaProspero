-- Nexa Próspero - WhatsApp: fluxos com frase de gatilho (campanhas/tráfego),
-- perguntas em sequência e opção "repetir meu último pedido".
-- Requer 20261110_whatsapp_atendimento_v1. Idempotente.

CREATE TABLE IF NOT EXISTS whatsapp_bot_flows (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  name VARCHAR(80) NOT NULL,
  triggers TEXT NULL,
  match_mode VARCHAR(10) NOT NULL DEFAULT 'contem',
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wa_flow_company (company_id, active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Passos do fluxo, em ordem. step_type: pergunta | text | setor | menu | boleto | pedido | crm | repetir_pedido
CREATE TABLE IF NOT EXISTS whatsapp_bot_flow_steps (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  flow_id INT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  step_type VARCHAR(20) NOT NULL DEFAULT 'pergunta',
  message TEXT NULL,
  var_name VARCHAR(40) NULL,
  answer_type VARCHAR(12) NOT NULL DEFAULT 'texto',
  department_id INT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wa_flow_step (company_id, flow_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Opção do menu que inicia um fluxo.
SET @nexa_has_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'whatsapp_bot_nodes' AND column_name = 'flow_id');
SET @nexa_sql := IF(@nexa_has_col = 0, 'ALTER TABLE whatsapp_bot_nodes ADD COLUMN flow_id INT NULL AFTER department_id', 'SELECT 1');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

-- Respostas dadas pelo cliente no fluxo (mostradas ao atendente).
SET @nexa_has_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'whatsapp_bot_sessions' AND column_name = 'answers_json');
SET @nexa_sql := IF(@nexa_has_col = 0, 'ALTER TABLE whatsapp_bot_sessions ADD COLUMN answers_json TEXT NULL AFTER data_json', 'SELECT 1');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

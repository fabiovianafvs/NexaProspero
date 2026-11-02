-- Nexa Próspero V11.45.19.15
-- Módulo CRM / Atendimento

CREATE TABLE IF NOT EXISTS crm_statuses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  color CHAR(7) NOT NULL DEFAULT '#2563EB',
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_crm_status_company_name (company_id, name),
  KEY idx_crm_status_company_active (company_id, active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_service_types (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_crm_type_company_name (company_id, name),
  KEY idx_crm_type_company_active (company_id, active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_attendances (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  person_id INT NULL,
  customer_name VARCHAR(180) NULL,
  phone VARCHAR(60) NULL,
  document VARCHAR(40) NULL,
  email VARCHAR(180) NULL,
  address VARCHAR(500) NULL,
  status_id INT UNSIGNED NULL,
  service_type_id INT UNSIGNED NULL,
  request_text TEXT NOT NULL,
  state VARCHAR(20) NOT NULL DEFAULT 'PENDENTE',
  pending_reason VARCHAR(500) NULL,
  next_contact_date DATE NULL,
  responsible_user_id INT NOT NULL,
  created_by INT NULL,
  closed_by INT NULL,
  closed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_crm_att_company_state (company_id, state, next_contact_date),
  KEY idx_crm_att_company_created (company_id, created_at),
  KEY idx_crm_att_company_status (company_id, status_id),
  KEY idx_crm_att_company_responsible (company_id, responsible_user_id),
  KEY idx_crm_att_person (company_id, person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crm_attendance_moves (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  attendance_id BIGINT UNSIGNED NOT NULL,
  movement_text TEXT NOT NULL,
  state_result VARCHAR(20) NOT NULL DEFAULT 'PENDENTE',
  pending_reason VARCHAR(500) NULL,
  next_contact_date DATE NULL,
  responsible_user_id INT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_crm_move_attendance (company_id, attendance_id, created_at),
  KEY idx_crm_move_next_contact (company_id, next_contact_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO saas_modules
  (code, name, description, item_type, monthly_cost, monthly_price, setup_cost, setup_price, is_public_sale, sort_order, is_active)
VALUES
  ('crm', 'CRM', 'Atendimentos, contatos futuros, tramitações, responsáveis, status e tipos de atendimento.', 'module', 0.00, 0.00, 0.00, 0.00, 0, 28, 1)
ON DUPLICATE KEY UPDATE code=VALUES(code);

INSERT INTO crm_statuses (company_id,name,color,active,sort_order)
SELECT c.id,'Novo','#2563EB',1,10 FROM companies c
WHERE NOT EXISTS (SELECT 1 FROM crm_statuses s WHERE s.company_id=c.id AND s.name='Novo');

INSERT INTO crm_statuses (company_id,name,color,active,sort_order)
SELECT c.id,'Em atendimento','#F59E0B',1,20 FROM companies c
WHERE NOT EXISTS (SELECT 1 FROM crm_statuses s WHERE s.company_id=c.id AND s.name='Em atendimento');

INSERT INTO crm_statuses (company_id,name,color,active,sort_order)
SELECT c.id,'Aguardando retorno','#7C3AED',1,30 FROM companies c
WHERE NOT EXISTS (SELECT 1 FROM crm_statuses s WHERE s.company_id=c.id AND s.name='Aguardando retorno');

INSERT INTO crm_statuses (company_id,name,color,active,sort_order)
SELECT c.id,'Concluído','#16A34A',1,40 FROM companies c
WHERE NOT EXISTS (SELECT 1 FROM crm_statuses s WHERE s.company_id=c.id AND s.name='Concluído');

INSERT INTO crm_service_types (company_id,name,active,sort_order)
SELECT c.id,'Telefone',1,10 FROM companies c
WHERE NOT EXISTS (SELECT 1 FROM crm_service_types t WHERE t.company_id=c.id AND t.name='Telefone');

INSERT INTO crm_service_types (company_id,name,active,sort_order)
SELECT c.id,'WhatsApp',1,20 FROM companies c
WHERE NOT EXISTS (SELECT 1 FROM crm_service_types t WHERE t.company_id=c.id AND t.name='WhatsApp');

INSERT INTO crm_service_types (company_id,name,active,sort_order)
SELECT c.id,'E-mail',1,30 FROM companies c
WHERE NOT EXISTS (SELECT 1 FROM crm_service_types t WHERE t.company_id=c.id AND t.name='E-mail');

INSERT INTO crm_service_types (company_id,name,active,sort_order)
SELECT c.id,'Presencial',1,40 FROM companies c
WHERE NOT EXISTS (SELECT 1 FROM crm_service_types t WHERE t.company_id=c.id AND t.name='Presencial');

-- A versão do schema é registrada pelo Atualizador Controlado somente após
-- todos os comandos desta migration concluírem com sucesso.

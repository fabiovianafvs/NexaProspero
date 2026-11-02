-- Nexa Catálogo v30 - Migração Multiempresa (tenant por slug)
-- Antes de rodar: faça backup do banco.

START TRANSACTION;

-- 1) Empresas (tenants)
CREATE TABLE IF NOT EXISTS companies (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  email VARCHAR(150) NULL,
  whatsapp VARCHAR(30) NULL,
  status ENUM('active','blocked') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_companies_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Usuários (login real)
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(150) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('platform_admin','company_admin','company_user') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_users_email (email),
  KEY ix_users_company (company_id),
  CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Planos / Assinaturas (licenciamento)
CREATE TABLE IF NOT EXISTS plans (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  features_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_plans_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriptions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  status ENUM('trial','active','past_due','canceled') NOT NULL DEFAULT 'trial',
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ends_at DATETIME NULL,
  next_billing_at DATETIME NULL,
  payment_provider VARCHAR(50) NULL,
  external_ref VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_sub_company (company_id),
  KEY ix_sub_plan (plan_id),
  CONSTRAINT fk_sub_company FOREIGN KEY (company_id) REFERENCES companies(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_sub_plan FOREIGN KEY (plan_id) REFERENCES plans(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Empresa padrão para migrar dados legados
INSERT INTO companies (id, name, slug, status)
VALUES (1, 'Empresa Padrão', 'empresa-padrao', 'active')
ON DUPLICATE KEY UPDATE name=VALUES(name), status=VALUES(status);

SET @DEFAULT_COMPANY_ID := (SELECT id FROM companies WHERE slug='empresa-padrao' LIMIT 1);

-- 5) app_config: agora é por empresa (company_id + cfg_key)
-- Estrutura atual (v29): cfg_key (PK), cfg_json, updated_at
ALTER TABLE app_config
  ADD COLUMN company_id INT UNSIGNED NOT NULL DEFAULT 1 FIRST;

UPDATE app_config SET company_id = @DEFAULT_COMPANY_ID WHERE company_id IS NULL OR company_id = 0;

ALTER TABLE app_config
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (company_id, cfg_key),
  ADD KEY ix_app_config_key (cfg_key),
  ADD CONSTRAINT fk_app_config_company FOREIGN KEY (company_id) REFERENCES companies(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

-- 6) Multiempresa nas tabelas existentes
ALTER TABLE produtos
  ADD COLUMN company_id INT UNSIGNED NOT NULL DEFAULT 1 FIRST,
  ADD KEY ix_produtos_company (company_id),
  ADD CONSTRAINT fk_produtos_company FOREIGN KEY (company_id) REFERENCES companies(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE pedidos
  ADD COLUMN company_id INT UNSIGNED NOT NULL DEFAULT 1 FIRST,
  ADD KEY ix_pedidos_company (company_id),
  ADD CONSTRAINT fk_pedidos_company FOREIGN KEY (company_id) REFERENCES companies(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE pedido_itens
  ADD COLUMN company_id INT UNSIGNED NOT NULL DEFAULT 1 FIRST,
  ADD KEY ix_pedido_itens_company (company_id),
  ADD CONSTRAINT fk_pedido_itens_company FOREIGN KEY (company_id) REFERENCES companies(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE pedidos_exportacao
  ADD COLUMN company_id INT UNSIGNED NOT NULL DEFAULT 1 FIRST,
  ADD KEY ix_pedidos_export_company (company_id),
  ADD CONSTRAINT fk_pedidos_export_company FOREIGN KEY (company_id) REFERENCES companies(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

-- Ajusta dados legados
UPDATE produtos SET company_id = @DEFAULT_COMPANY_ID WHERE company_id IS NULL OR company_id = 0;
UPDATE pedidos SET company_id = @DEFAULT_COMPANY_ID WHERE company_id IS NULL OR company_id = 0;
UPDATE pedido_itens SET company_id = @DEFAULT_COMPANY_ID WHERE company_id IS NULL OR company_id = 0;
UPDATE pedidos_exportacao SET company_id = @DEFAULT_COMPANY_ID WHERE company_id IS NULL OR company_id = 0;

-- 7) Planos iniciais (opcional)
INSERT INTO plans (name, price, is_active) VALUES
('Trial', 0.00, 1),
('Mensal', 79.90, 1)
ON DUPLICATE KEY UPDATE id=id;

-- 8) Assinatura Trial para a empresa padrão (opcional)
SET @PLAN_TRIAL := (SELECT id FROM plans WHERE name='Trial' LIMIT 1);
INSERT INTO subscriptions (company_id, plan_id, status)
SELECT @DEFAULT_COMPANY_ID, @PLAN_TRIAL, 'trial'
WHERE @PLAN_TRIAL IS NOT NULL;

COMMIT;

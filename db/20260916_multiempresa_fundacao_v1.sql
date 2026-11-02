-- Nexa Próspero - Fundação Multiempresa v1
-- Data: 16/09/2026
-- Banco alvo: nexaprospero
-- Compatível com MariaDB/MySQL.
--
-- OBJETIVO DESTA ETAPA
-- 1) Criar a estrutura de Grupo Empresarial na Central SaaS.
-- 2) Preparar acessos de um mesmo usuário a várias empresas.
-- 3) Preparar perfil integrado para grupos da mesma atividade.
-- 4) NÃO alterar o fluxo operacional atual do ERP nesta etapa.
--
-- IMPORTANTE
-- Nenhuma tabela operacional (pedidos, estoque, financeiro, fiscal, O.S.) é alterada aqui.
-- users.company_id e users.profile_id continuam sendo a referência legada/principal.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS business_groups (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  operation_mode ENUM('integrated','isolated') NOT NULL DEFAULT 'integrated',
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_business_groups_active (is_active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada empresa pertence, no máximo, a um Grupo Empresarial.
CREATE TABLE IF NOT EXISTS business_group_companies (
  business_group_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (business_group_id, company_id),
  UNIQUE KEY uk_business_group_company_single (company_id),
  KEY idx_business_group_companies_active (business_group_id, is_active, is_primary),
  CONSTRAINT fk_bgc_group FOREIGN KEY (business_group_id) REFERENCES business_groups(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_bgc_company FOREIGN KEY (company_id) REFERENCES companies(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Perfil compartilhado no Grupo Empresarial quando operation_mode = integrated.
-- Nesta primeira etapa a tabela é apenas preparada; o perfil atual por empresa continua intacto.
CREATE TABLE IF NOT EXISTS business_group_profiles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_group_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  permissions_json LONGTEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_business_group_profile_name (business_group_id, name),
  KEY idx_business_group_profiles_active (business_group_id, is_active),
  CONSTRAINT fk_bgp_group FOREIGN KEY (business_group_id) REFERENCES business_groups(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vínculo do usuário ao Grupo Empresarial e ao perfil integrado do grupo.
CREATE TABLE IF NOT EXISTS business_group_users (
  business_group_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  group_profile_id INT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (business_group_id, user_id),
  KEY idx_business_group_users_user (user_id, is_active),
  KEY idx_business_group_users_profile (group_profile_id),
  CONSTRAINT fk_bgu_group FOREIGN KEY (business_group_id) REFERENCES business_groups(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_bgu_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_bgu_profile FOREIGN KEY (group_profile_id) REFERENCES business_group_profiles(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Empresas em que o usuário pode trabalhar.
-- company_profile_id será usado no modo isolado (ramos distintos), mantendo perfil por empresa.
-- No modo integrado, o perfil virá de business_group_users.group_profile_id.
CREATE TABLE IF NOT EXISTS user_company_access (
  user_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  company_profile_id INT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (user_id, company_id),
  KEY idx_user_company_access_company (company_id, is_active),
  KEY idx_user_company_access_user_default (user_id, is_active, is_default),
  CONSTRAINT fk_uca_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_uca_company FOREIGN KEY (company_id) REFERENCES companies(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nexa_schema_versions (
  module_name VARCHAR(80) NOT NULL,
  version_number BIGINT UNSIGNED NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes VARCHAR(255) NULL,
  PRIMARY KEY (module_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES ('multiempresa_fundacao',2026091601,NOW(),'Fundação Multiempresa v1 - grupos, perfis integrados e acessos por empresa; sem ativação operacional')
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

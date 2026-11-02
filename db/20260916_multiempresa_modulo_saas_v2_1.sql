-- Nexa Próspero - Multiempresa V2.1
-- Módulo/feature flag comercial na Central SaaS
-- Data: 16/09/2026
-- Banco alvo: nexaprospero
--
-- Esta migração NÃO ativa Multiempresa em nenhuma empresa.
-- Ela apenas cadastra o módulo "multiempresa" no catálogo SaaS.
-- A liberação continua sendo feita manualmente na Central SaaS > Empresa > Módulos.

START TRANSACTION;

INSERT INTO saas_modules
  (code, name, description, item_type, monthly_cost, monthly_price, setup_cost, setup_price,
   allow_quantity, quantity_label, quantity_min, quantity_max, quantity_default,
   is_public_sale, is_active, sort_order, created_at)
VALUES
  ('multiempresa', 'Multiempresa',
   'Acesso do mesmo usuário a múltiplas empresas, com suporte a grupos integrados ou isolados e troca segura de contexto.',
   'module', 0.00, 0.00, 0.00, 0.00,
   0, NULL, 1, 99, 1,
   0, 1, 27, NOW())
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  item_type = VALUES(item_type),
  is_active = 1,
  sort_order = VALUES(sort_order);

CREATE TABLE IF NOT EXISTS nexa_schema_versions (
  module_name VARCHAR(80) NOT NULL,
  version_number BIGINT UNSIGNED NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes VARCHAR(255) NULL,
  PRIMARY KEY (module_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES ('multiempresa_modulo_saas',2026091601,NOW(),'V2.1 - módulo Multiempresa cadastrado no SaaS; desabilitado por padrão nas empresas')
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

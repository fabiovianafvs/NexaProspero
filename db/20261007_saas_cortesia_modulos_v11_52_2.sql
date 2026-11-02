-- Nexa Próspero V11.52.2 — Política de faturamento/cortesia por módulo SaaS
-- Permite manter o módulo habilitado no SaaS sem incluí-lo nas cobranças futuras.
-- Nenhuma cobrança já gerada é alterada por esta estrutura.

CREATE TABLE IF NOT EXISTS saas_subscription_billing_policy (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  link_id BIGINT UNSIGNED NOT NULL,
  saas_module_id INT UNSIGNED NOT NULL,
  billing_mode VARCHAR(20) NOT NULL DEFAULT 'BILLABLE',
  courtesy_note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_saas_sub_billing_policy (link_id, saas_module_id),
  KEY idx_saas_sub_billing_mode (link_id, billing_mode),
  KEY idx_saas_sub_billing_module (saas_module_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

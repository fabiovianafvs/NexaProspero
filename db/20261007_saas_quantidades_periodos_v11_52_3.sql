-- Nexa Próspero V11.52.3 — Quantidades e períodos de renovação SaaS
-- Prepara cobrança antecipada mensal/trimestral/semestral/anual com desconto configurável.
-- Confirmar vínculo NÃO gera Pedido, Contas a Receber, boleto, PIX ou NFS-e.

ALTER TABLE saas_subscription_billing_policy
  ADD COLUMN billing_quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00 AFTER billing_mode;

ALTER TABLE saas_subscription_links
  ADD COLUMN renewal_term_months SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER grace_until,
  ADD COLUMN renewal_discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER renewal_term_months;

UPDATE saas_subscription_billing_policy bp
INNER JOIN saas_subscription_items si
  ON si.link_id=bp.link_id AND si.saas_module_id=bp.saas_module_id
SET bp.billing_quantity=CASE WHEN si.quantity>0 THEN si.quantity ELSE 1 END;

CREATE TABLE IF NOT EXISTS saas_subscription_term_options (
  term_months SMALLINT UNSIGNED NOT NULL,
  code VARCHAR(20) NOT NULL,
  label VARCHAR(60) NOT NULL,
  discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (term_months),
  UNIQUE KEY uk_saas_subscription_term_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO saas_subscription_term_options
  (term_months,code,label,discount_percent,active,sort_order,created_at,updated_at)
VALUES
  (1,'MONTHLY','Mensal',0.00,1,10,NOW(),NOW()),
  (3,'QUARTERLY','Trimestral',0.00,1,20,NOW(),NOW()),
  (6,'SEMIANNUAL','Semestral',0.00,1,30,NOW(),NOW()),
  (12,'ANNUAL','Anual',0.00,1,40,NOW(),NOW())
ON DUPLICATE KEY UPDATE
  label=VALUES(label),
  active=VALUES(active),
  sort_order=VALUES(sort_order),
  updated_at=NOW();

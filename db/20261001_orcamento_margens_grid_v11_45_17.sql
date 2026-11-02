-- Nexa Próspero V11.45.17 - Margens, negociação e grid avançado de orçamentos
-- Aplicar exclusivamente pelo Atualizador do Banco.

ALTER TABLE orcamento_settings
  ADD COLUMN minimum_sale_margin_percent DECIMAL(9,4) NULL AFTER delivery_enabled,
  ADD COLUMN margin_warning_band_percent DECIMAL(9,4) NOT NULL DEFAULT 5.0000 AFTER minimum_sale_margin_percent;

ALTER TABLE orcamento_items
  ADD COLUMN cost_snapshot DECIMAL(15,4) NULL AFTER unit_price;

UPDATE orcamento_items oi
LEFT JOIN produtos p
  ON p.company_id = oi.company_id
 AND p.id = oi.produto_id
SET oi.cost_snapshot = COALESCE(p.custo, 0)
WHERE oi.cost_snapshot IS NULL;

INSERT INTO nexa_schema_versions (module_name, version_number, applied_at, notes)
VALUES ('budget_margin_negotiation', 202610014517, NOW(), 'V11.45.17 - grid avancado de orcamentos, snapshot de custo e painel de negociacao por margem')
ON DUPLICATE KEY UPDATE
  version_number = VALUES(version_number),
  applied_at = VALUES(applied_at),
  notes = VALUES(notes);

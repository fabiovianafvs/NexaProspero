-- Nexa Próspero V11.45.19 - Totais do orçamento e fluxo rápido por teclado
-- Aplicar exclusivamente pelo Atualizador do Banco.

ALTER TABLE orcamentos
  ADD COLUMN discount_mode VARCHAR(10) NOT NULL DEFAULT 'AMOUNT' AFTER total_services,
  ADD COLUMN discount_percent DECIMAL(9,4) NOT NULL DEFAULT 0 AFTER discount_mode,
  ADD COLUMN discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER discount_percent,
  ADD COLUMN addition_mode VARCHAR(10) NOT NULL DEFAULT 'AMOUNT' AFTER discount_amount,
  ADD COLUMN addition_percent DECIMAL(9,4) NOT NULL DEFAULT 0 AFTER addition_mode,
  ADD COLUMN addition_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER addition_percent,
  ADD COLUMN freight_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER addition_amount;

INSERT INTO nexa_schema_versions (module_name, version_number, applied_at, notes)
VALUES ('budget_totals_keyboard', 202610014519, NOW(), 'V11.45.19 - desconto, acrescimo e frete gerais no orcamento, limite por usuario e fluxo rapido por teclado')
ON DUPLICATE KEY UPDATE
  version_number = VALUES(version_number),
  applied_at = VALUES(applied_at),
  notes = VALUES(notes);

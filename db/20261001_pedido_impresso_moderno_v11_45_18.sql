-- Nexa Próspero V11.45.18 - Novos impressos do Pedido A4/72mm (opt-in)
-- Aplicar exclusivamente pelo Atualizador do Banco.
-- O modelo legado permanece padrão para todas as empresas até habilitação explícita.

ALTER TABLE fiscal_company_config
  ADD COLUMN order_modern_print_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER order_print_disabled,
  ADD COLUMN order_modern_print_default VARCHAR(10) NOT NULL DEFAULT 'A4' AFTER order_modern_print_enabled;

INSERT INTO nexa_schema_versions (module_name, version_number, applied_at, notes)
VALUES ('order_modern_print', 202610014518, NOW(), 'V11.45.18 - novos impressos do pedido A4 e 72mm habilitados somente por configuracao, preservando modelo legado')
ON DUPLICATE KEY UPDATE
  version_number = VALUES(version_number),
  applied_at = VALUES(applied_at),
  notes = VALUES(notes);

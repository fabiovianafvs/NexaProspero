-- Nexa Próspero V11.45.3 - Referência de produtos e pesquisa
-- Aplicar exclusivamente pelo Atualizador do Banco.

ALTER TABLE produtos
  ADD COLUMN referencia VARCHAR(180) NULL;

CREATE INDEX idx_produtos_company_referencia
  ON produtos (company_id, referencia);

INSERT INTO nexa_schema_versions (module_name, version_number, applied_at, notes)
VALUES ('product_reference', 20260930453, NOW(), 'V11.45.3 - referência de produto, importação e pesquisas')
ON DUPLICATE KEY UPDATE
  version_number = VALUES(version_number),
  applied_at = VALUES(applied_at),
  notes = VALUES(notes);

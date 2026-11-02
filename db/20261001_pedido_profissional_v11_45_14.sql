-- Nexa Próspero V11.45.14 - Profissional no Pedido Detalhado
-- Aplicar exclusivamente pelo Atualizador do Banco.
--
-- O profissional é uma pessoa da própria empresa marcada com is_professional=1.
-- O campo é independente do vendedor e preserva o vínculo comercial no cabeçalho do pedido.

ALTER TABLE pedidos
  ADD COLUMN professional_id INT NULL AFTER seller_id,
  ADD KEY idx_pedidos_company_professional (company_id, professional_id);

INSERT INTO nexa_schema_versions (module_name, version_number, applied_at, notes)
VALUES ('order_professional', 202610014514, NOW(), 'V11.45.14 - profissional no Pedido Detalhado')
ON DUPLICATE KEY UPDATE
  version_number = VALUES(version_number),
  applied_at = VALUES(applied_at),
  notes = VALUES(notes);

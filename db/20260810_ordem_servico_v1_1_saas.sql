-- Nexa Próspero - Correção SaaS do módulo Ordem de Serviço v1.1
-- Pode ser executado isoladamente para fazer o módulo aparecer imediatamente no SaaS.
-- Banco: nexaprospero

INSERT INTO saas_modules
(code, name, description, item_type, monthly_cost, monthly_price, setup_cost, setup_price, is_public_sale, sort_order, is_active)
VALUES
('ordem_servico', 'Ordem de Serviço', 'Ordens de serviço com campos personalizados, produtos, serviços, reservas, pagamentos, impressão e faturamento no PDV.', 'module', 0.00, 0.00, 0.00, 0.00, 0, 25, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  item_type = 'module',
  sort_order = VALUES(sort_order),
  is_active = 1;

-- Controle de Cartões: baixas do Contas a Receber pagas com cartão.
-- Idempotente. Só acrescenta colunas em finance_card_sales para ligar a venda no cartão
-- ao título (receivable_id) e à baixa (settlement_id). Vendas de pedidos continuam iguais
-- (origin_type PEDIDO); as do Contas a Receber ficam com origin_type RECEBER e order_id 0.

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='finance_card_sales')=1
  AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='finance_card_sales' AND column_name='settlement_id')=0,
  'ALTER TABLE `finance_card_sales`
     ADD COLUMN `origin_type` VARCHAR(20) NOT NULL DEFAULT ''PEDIDO'' AFTER `company_id`,
     ADD COLUMN `receivable_id` INT NULL AFTER `order_payment_id`,
     ADD COLUMN `settlement_id` BIGINT NULL AFTER `receivable_id`,
     ADD KEY `idx_card_sale_settlement` (`company_id`,`settlement_id`),
     ADD KEY `idx_card_sale_receivable` (`company_id`,`receivable_id`)',
  'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

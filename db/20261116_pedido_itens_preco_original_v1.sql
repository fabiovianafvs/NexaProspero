-- Duplicata / impressão do pedido: preço unitário ORIGINAL do item (antes do desconto).
-- preco_unit continua sendo o preço cobrado (já com desconto do item); preco_original
-- guarda o preço de tabela/informado no momento da venda, para mostrar ao cliente
-- "valor que era x valor que está pagando". Itens antigos ficam NULL (sem mudança).
SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='pedido_itens')=1
  AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedido_itens' AND column_name='preco_original')=0,
  'ALTER TABLE `pedido_itens` ADD COLUMN `preco_original` DECIMAL(15,6) NULL', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

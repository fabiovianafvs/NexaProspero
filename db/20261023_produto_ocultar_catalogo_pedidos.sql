-- Nexa Próspero - 23/10/2026
-- Ocultar produto individualmente somente no catálogo público de pedidos.
-- Campo desligado por padrão e isolado por registro/empresa.
ALTER TABLE produtos ADD COLUMN hide_order_catalog_product TINYINT(1) NOT NULL DEFAULT 0;

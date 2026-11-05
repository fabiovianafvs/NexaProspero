-- Nexa Próspero - Valor mensal negociado por módulo da assinatura SaaS.
-- Permite conferir e alterar o valor unitário/mês de cada módulo ao ativar a
-- assinatura (Central SaaS ou CRM). Vazio (NULL) = usa o preço do Serviço.
-- Não gera Pedido, Contas a Receber, boleto, PIX ou NFS-e.
-- Aplicar pelo Atualizador do Banco (platform_admin): primeiro na homologação, depois em produção.

SET @nexa_has_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'saas_subscription_billing_policy' AND column_name = 'billing_unit_price');
SET @nexa_sql := IF(@nexa_has_col = 0,
  'ALTER TABLE saas_subscription_billing_policy ADD COLUMN billing_unit_price DECIMAL(15,4) NULL DEFAULT NULL AFTER billing_quantity',
  'SELECT 1');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

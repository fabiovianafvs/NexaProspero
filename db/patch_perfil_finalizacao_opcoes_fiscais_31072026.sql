-- Nexa Próspero
-- Permissão "Não permitir finalizar a venda" + opções fiscais independentes
-- Banco alvo: nexaprospero
-- Data: 31/07/2026

SET @nexa_db := DATABASE();
SET @nexa_table_exists := (
  SELECT COUNT(*)
    FROM information_schema.tables
   WHERE table_schema = @nexa_db
     AND table_name = 'fiscal_company_config'
);
SET @nexa_column_exists := (
  SELECT COUNT(*)
    FROM information_schema.columns
   WHERE table_schema = @nexa_db
     AND table_name = 'fiscal_company_config'
     AND column_name = 'save_order_enabled'
);

SET @nexa_sql := IF(
  @nexa_table_exists = 0,
  'SELECT ''Tabela fiscal_company_config não encontrada. Instale primeiro o módulo Fiscal.'' AS aviso',
  IF(
    @nexa_column_exists > 0,
    'SELECT ''Coluna save_order_enabled já instalada.'' AS aviso',
    'ALTER TABLE fiscal_company_config ADD COLUMN save_order_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER fiscal_emission_enabled'
  )
);
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

-- Preserva o comportamento anterior: a opção "Somente gravar pedido" fica
-- habilitada até que a empresa a desmarque nas Configurações Fiscais.
SET @nexa_sql := IF(
  @nexa_table_exists > 0,
  'UPDATE fiscal_company_config SET save_order_enabled = COALESCE(save_order_enabled, 1), fiscal_emission_enabled = CASE WHEN COALESCE(nfe_enabled,0)=1 OR COALESCE(nfce_enabled,0)=1 OR COALESCE(nfse_enabled,0)=1 THEN 1 ELSE 0 END',
  'SELECT 1'
);
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

SELECT
  table_name,
  column_name,
  column_type,
  column_default
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'fiscal_company_config'
  AND column_name = 'save_order_enabled';

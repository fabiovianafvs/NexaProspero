-- Nexa Próspero V11.52.9 — Vinculação completa e próximo reajuste
-- Permite registrar a data do próximo reajuste anual da assinatura SaaS.
-- A vinculação/criação de Pessoa e Contrato NÃO gera Pedido, Contas a Receber, boleto, PIX ou NFS-e.
-- MySQL 8.0: usa SQL preparado idempotente, sem ADD COLUMN IF NOT EXISTS.

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='saas_subscription_links' AND column_name='next_adjustment_date')=0,
  'ALTER TABLE `saas_subscription_links` ADD COLUMN `next_adjustment_date` DATE NULL AFTER `next_due_date`', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

-- Quando já existir uma data de reajuste nos itens ativos do contrato, aproveita-a no vínculo SaaS.
UPDATE saas_subscription_links l
INNER JOIN (
  SELECT company_id, contract_id, MIN(next_adjustment_date) AS next_adjustment_date
  FROM contract_items
  WHERE status='ATIVO' AND next_adjustment_date IS NOT NULL
  GROUP BY company_id, contract_id
) ci ON ci.company_id=l.billing_company_id AND ci.contract_id=l.contract_id
SET l.next_adjustment_date=COALESCE(l.next_adjustment_date,ci.next_adjustment_date),
    l.updated_at=NOW()
WHERE l.next_adjustment_date IS NULL;

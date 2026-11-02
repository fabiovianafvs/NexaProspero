-- Nexa Próspero V11.52.5 — Status de cobrança dos contratos
-- Contratos existentes permanecem MANUAL por padrão.
-- Contratos já vinculados à ponte SaaS passam a AUTOMATICA.

ALTER TABLE contracts
  ADD COLUMN billing_mode VARCHAR(20) NOT NULL DEFAULT 'MANUAL' AFTER default_billing_type,
  ADD KEY idx_contract_company_billing_mode (company_id,billing_mode,status);

UPDATE contracts c
INNER JOIN saas_subscription_links l
  ON l.billing_company_id=c.company_id
 AND l.contract_id=c.id
SET c.billing_mode='AUTOMATICA',
    c.updated_at=NOW()
WHERE c.status<>'CANCELADO';

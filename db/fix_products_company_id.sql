-- Ajuste rápido: garantir que produtos antigos pertençam à Empresa Padrão (company_id = 1)
-- Use apenas se você importou produtos antigos e eles ficaram com company_id NULL/0.

UPDATE produtos
SET company_id = 1
WHERE company_id IS NULL OR company_id = 0;

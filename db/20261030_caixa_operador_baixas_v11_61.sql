-- V11.61: operador responsável pela baixa, sempre auditado em empresa.
-- Histórico antigo permanece NULL. Executar exclusivamente pelo Atualizador do Banco.
ALTER TABLE finance_settlements
  ADD COLUMN settled_by_user_id INT NULL,
  ADD INDEX idx_fin_settle_operator (company_id, settled_by_user_id, settlement_date);

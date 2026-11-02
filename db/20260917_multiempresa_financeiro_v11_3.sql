-- Nexa Próspero - Multiempresa Financeiro V11.3
-- Data: 17/09/2026
-- Banco alvo: nexaprospero
-- Estorno protegido de baixas + Caixa/Banco Multiempresa.
-- Boletos/Itaú, Conciliação e Despesas Fixas permanecem separados.

START TRANSACTION;
ALTER TABLE finance_settlements
  ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'ATIVO' AFTER movement_id,
  ADD COLUMN reversed_at DATETIME NULL AFTER status,
  ADD COLUMN reversed_by_user_id INT NULL AFTER reversed_at,
  ADD COLUMN reversal_reason VARCHAR(500) NULL AFTER reversed_by_user_id,
  ADD COLUMN reversal_movement_id INT NULL AFTER reversal_reason;
CREATE INDEX idx_fin_set_status ON finance_settlements (company_id,status,origin_type,origin_id);
CREATE INDEX idx_fin_set_reversal_movement ON finance_settlements (company_id,reversal_movement_id);
UPDATE finance_settlements SET status='ATIVO' WHERE status IS NULL OR status='';
INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes) VALUES ('multiempresa_contexto',2026091721,NOW(),'V11.3 - Estorno protegido/auditavel de baixas e cadastro basico de Caixa/Banco via F11; Boletos/Itau, Conciliacao e Despesas Fixas permanecem separados') ON DUPLICATE KEY UPDATE version_number=GREATEST(version_number,VALUES(version_number)),applied_at=NOW(),notes=VALUES(notes);
COMMIT;

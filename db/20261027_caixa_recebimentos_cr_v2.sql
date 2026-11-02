-- Caixa / Contas a Receber v2: a integracao passa a estar ativa por padrao.
-- Execucao controlada pelo Atualizador do Banco, APOS v1.
-- Mantem inalteradas as decisoes explicitas por empresa (enabled=0 ou 1).
-- Sem DDL em login, sem lancamentos retroativos, sem impacto fiscal.
ALTER TABLE gestao_cash_receivable_settings
  MODIFY COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1;

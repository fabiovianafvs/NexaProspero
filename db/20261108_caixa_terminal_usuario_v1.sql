-- Nexa Próspero - "Ativar Caixa Terminal" por usuário (users.cash_terminal_enabled).
-- Usuário com Caixa Terminal: abre e fecha caixa, e as formas de pagamento que
-- exigem caixa só funcionam com o caixa dele aberto; o caixa físico é exclusivo.
-- Demais usuários (padrão): vendem e lançam sem abrir caixa.
-- Aplicar pelo Atualizador do Banco: primeiro na homologação, depois em produção.

SET @nexa_has_col := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'cash_terminal_enabled');
SET @nexa_sql := IF(@nexa_has_col = 0,
  'ALTER TABLE users ADD COLUMN cash_terminal_enabled TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

-- Movimentação de estoque: quem fez o movimento.
-- usuario_id: usuário do painel que fez a venda/entrada/acerto (gravado ao fim do POST).
-- conexao_id: preenchido pelo próprio MySQL com CONNECTION_ID() em cada movimento novo,
-- para o painel saber quais movimentos a requisição criou. Movimentos antigos ficam NULL.
SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='estoque_movimentos')=1
  AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='estoque_movimentos' AND column_name='usuario_id')=0,
  'ALTER TABLE `estoque_movimentos` ADD COLUMN `usuario_id` INT NULL', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='estoque_movimentos')=1
  AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='estoque_movimentos' AND column_name='conexao_id')=0,
  'ALTER TABLE `estoque_movimentos` ADD COLUMN `conexao_id` BIGINT UNSIGNED NULL', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

-- O padrão entra depois de a coluna existir (só metadados, instantâneo): os movimentos antigos continuam NULL.
SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='estoque_movimentos' AND column_name='conexao_id' AND COALESCE(column_default,'')='')=1,
  'ALTER TABLE `estoque_movimentos` ALTER COLUMN `conexao_id` SET DEFAULT (CONNECTION_ID())', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

SET @nexa_sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='estoque_movimentos')=1
  AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='estoque_movimentos' AND index_name='idx_estoque_mov_conexao')=0,
  'ALTER TABLE `estoque_movimentos` ADD KEY `idx_estoque_mov_conexao` (`conexao_id`, `usuario_id`)', 'DO 0');
PREPARE nexa_stmt FROM @nexa_sql;
EXECUTE nexa_stmt;
DEALLOCATE PREPARE nexa_stmt;

-- Nexa Próspero - Multiempresa Estoque V9.1
-- Data: 16/09/2026
-- Banco alvo: nexaprospero
--
-- NÃO cria nem altera tabelas operacionais.
-- Homologa gravação protegida de estoque básico (stock_write) para a empresa
-- selecionada pelo F11, mantendo COMPANY_ID global legado.
--
-- Homologado nesta etapa:
-- - saldo inicial no cadastro de produto novo;
-- - estoque mínimo e máximo;
-- - reserva manual;
-- - unidade de estoque;
-- - acerto manual de saldo com histórico em estoque_movimentos.
--
-- Ainda fora do contexto cruzado:
-- - conferência/inventário;
-- - entradas, devoluções e saídas automáticas;
-- - produção/ficha técnica;
-- - PDV e baixas automáticas;
-- - Financeiro e emissão fiscal operacional.

START TRANSACTION;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'multiempresa_contexto',
  2026091612,
  NOW(),
  'V9.1 - Gravacao protegida do Estoque basico homologada no contexto F11: saldo inicial, minimo/maximo, reserva e acerto manual com historico; COMPANY_ID preservado'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

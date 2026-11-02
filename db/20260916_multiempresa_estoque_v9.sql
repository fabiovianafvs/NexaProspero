-- Nexa Próspero - Multiempresa Estoque V9
-- Data: 16/09/2026
-- Banco alvo: nexaprospero
--
-- NÃO cria nem altera tabelas operacionais.
-- Homologa SOMENTE LEITURA de saldo e histórico de estoque (stock_read)
-- para a empresa selecionada pelo F11, mantendo COMPANY_ID global legado.
--
-- Ainda fora do contexto cruzado:
-- - acerto manual de estoque;
-- - conferência/acerto de inventário;
-- - entradas/saídas/baixas operacionais;
-- - produção/ficha técnica;
-- - PDV, Financeiro, emissão fiscal e O.S.

START TRANSACTION;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'multiempresa_contexto',
  2026091611,
  NOW(),
  'V9 - Consulta de saldo e historico de Estoque homologada no contexto F11; sem gravacao de estoque; COMPANY_ID preservado'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

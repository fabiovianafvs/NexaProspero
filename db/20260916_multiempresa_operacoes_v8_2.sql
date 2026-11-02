-- Nexa Próspero - Multiempresa Operações Fiscais V8.2
-- Data: 16/09/2026
-- Banco alvo: nexaprospero
--
-- NÃO cria nem altera tabelas operacionais.
-- Homologa o cadastro geral de Operações fiscais/CFOPs (fiscal_operations)
-- para a empresa selecionada pelo F11, mantendo COMPANY_ID global legado.
--
-- Ainda fora do contexto cruzado:
-- - emissão/documentos/entradas/saídas fiscais;
-- - acerto/movimentação de estoque;
-- - Financeiro operacional;
-- - PDV e O.S.;
-- - demais tabelas fiscais ainda não homologadas.

START TRANSACTION;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'multiempresa_contexto',
  2026091610,
  NOW(),
  'V8.2 - Cadastro geral de Operacoes fiscais/CFOPs homologado no contexto F11; COMPANY_ID preservado; Fiscal operacional, Estoque, Financeiro e PDV permanecem legados'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

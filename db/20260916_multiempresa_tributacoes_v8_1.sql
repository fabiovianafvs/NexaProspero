-- Nexa Próspero - Multiempresa Tributações V8.1
-- Data: 16/09/2026
-- Banco alvo: nexaprospero
--
-- Hotfix funcional da V8.
-- NÃO cria nem altera tabelas operacionais.
-- Homologa o cadastro geral de Tributações (fiscal_tax_profiles) para usar
-- a empresa selecionada pelo F11, mantendo COMPANY_ID global legado.
--
-- Ainda fora do contexto cruzado:
-- - cadastro geral de Operações fiscais;
-- - demais telas/tabelas do módulo Fiscal;
-- - Estoque, Produção, Financeiro operacional, PDV e O.S.

START TRANSACTION;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'multiempresa_contexto',
  2026091609,
  NOW(),
  'V8.1 - Cadastro geral de Tributacoes homologado no contexto F11; Operacoes e demais telas fiscais permanecem legadas; COMPANY_ID preservado'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

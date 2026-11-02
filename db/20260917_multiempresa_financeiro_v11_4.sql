-- Nexa Próspero - Multiempresa Financeiro V11.4
-- Data: 17/09/2026
-- Banco alvo: nexaprospero
-- Cadastros Financeiros Auxiliares Multiempresa.
-- NÃO cria nem altera tabelas operacionais.
-- Homologa Plano de Contas, Centro de Custo e cadastro financeiro protegido de Fornecedor no F11.
-- Formas de Pagamento, Boletos/Itaú, Conciliação e Despesas Fixas permanecem separados.

START TRANSACTION;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'multiempresa_contexto',
  2026091722,
  NOW(),
  'V11.4 - Financeiro Multiempresa: Plano de Contas, Centro de Custo e cadastro financeiro protegido de Fornecedor via F11; fluxo V7 de Clientes preservado; Formas de Pagamento/PDV/Fiscal permanecem fora do escopo'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

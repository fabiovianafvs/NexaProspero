-- Nexa Próspero - Multiempresa Devolução a Fornecedor V10.3
-- Data: 16/09/2026
-- Banco alvo: nexaprospero
--
-- NÃO cria nem altera tabelas operacionais.
-- Homologa Devolução a Fornecedor / Saída de Estoque para a empresa selecionada pelo F11,
-- mantendo COMPANY_ID global legado e preservando o fluxo atual fora do contexto Multiempresa.
--
-- Homologado nesta etapa:
-- - listagem da rotina fiscal_saidas.php na empresa contextual;
-- - fornecedores, entradas de origem, produtos e operações somente da empresa contextual;
-- - operação limitada a DEVOLUCAO_FORNECEDOR no contexto cruzado;
-- - baixa de estoque somente na empresa contextual;
-- - validação de itens/quantidades contra a entrada de origem quando informada.
--
-- Proteções mantidas no contexto F11:
-- - operação que gere Financeiro é recusada;
-- - edição/transmissão/consulta SEFAZ/download/impressão fiscal continuam bloqueados;
-- - PDV, produção e demais rotinas fiscais/financeiras permanecem no legado.

START TRANSACTION;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'multiempresa_contexto',
  2026091617,
  NOW(),
  'V10.3 - Devolução a Fornecedor/Saída de Estoque homologada no contexto F11 com fornecedor/entradas/produtos/operação/estoque isolados; financeiro e emissão fiscal permanecem bloqueados'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

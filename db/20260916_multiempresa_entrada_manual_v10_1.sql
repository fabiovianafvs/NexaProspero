-- Nexa Próspero - Multiempresa Entrada Manual de Mercadorias V10.1
-- Data: 16/09/2026
-- Banco alvo: nexaprospero
--
-- NÃO cria nem altera tabelas operacionais.
-- Homologa a Entrada Manual compacta para a empresa selecionada pelo F11,
-- mantendo COMPANY_ID global legado e preservando o fluxo atual fora do contexto Multiempresa.
--
-- Homologado nesta etapa:
-- - busca/cadastro de fornecedor na empresa contextual;
-- - busca de produtos e tributação somente da empresa contextual;
-- - criação do lançamento manual em fiscal_xml_imports/fiscal_xml_items da empresa contextual;
-- - conferência e geração da entrada usando o mesmo motor seguro da V10;
-- - movimentação de estoque somente da empresa contextual;
-- - edição/desfazimento da entrada dentro da empresa contextual.
--
-- Proteções mantidas no contexto F11:
-- - Contas a Pagar continua bloqueado;
-- - devoluções continuam fora do contexto cruzado;
-- - PDV, produção, baixas automáticas e demais rotinas financeiras/fiscais permanecem no legado.

START TRANSACTION;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'multiempresa_contexto',
  2026091615,
  NOW(),
  'V10.1 - Entrada Manual de Mercadorias homologada no contexto F11 com fornecedor/produtos/estoque isolados; Contas a Pagar continua bloqueado no contexto cruzado'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

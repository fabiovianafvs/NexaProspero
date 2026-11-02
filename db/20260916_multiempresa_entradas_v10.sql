-- Nexa Próspero - Multiempresa Entradas de Mercadorias V10
-- Data: 16/09/2026
-- Banco alvo: nexaprospero
--
-- NÃO cria nem altera tabelas operacionais.
-- Homologa o escopo goods_entries para a empresa selecionada pelo F11,
-- mantendo COMPANY_ID global legado.
--
-- Homologado nesta etapa:
-- - RADAR NF-e em modo consulta no contexto cruzado;
-- - preparação de entrada a partir de XML completo já armazenado;
-- - importação manual de XML da empresa selecionada;
-- - validação do destinatário do XML contra o CNPJ fiscal da empresa contextual;
-- - conferência/vínculo dos itens somente com produtos da empresa contextual;
-- - cadastro rápido de produto durante a entrada na empresa contextual;
-- - geração de entrada com movimentação de estoque na empresa contextual;
-- - desfazimento da entrada/estoque na empresa contextual.
--
-- Proteções mantidas no contexto F11:
-- - busca/manifestação/consulta por chave na SEFAZ permanece bloqueada;
-- - Contas a Pagar não é gerado pelo F11 nesta V10;
-- - entrada manual e devoluções continuam fora do contexto cruzado;
-- - PDV, produção e demais rotinas fiscais/financeiras permanecem no fluxo legado.

START TRANSACTION;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'multiempresa_contexto',
  2026091614,
  NOW(),
  'V10 - Entradas de Mercadorias por XML homologadas no contexto F11 com estoque isolado por empresa; financeiro e SEFAZ externos bloqueados no contexto cruzado'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

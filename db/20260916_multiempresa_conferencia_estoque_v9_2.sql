-- Nexa Próspero - Multiempresa Conferência de Estoque V9.2
-- Data: 16/09/2026
-- Banco alvo: nexaprospero
--
-- NÃO cria nem altera tabelas operacionais.
-- Homologa o escopo stock_conference para a empresa selecionada pelo F11,
-- mantendo COMPANY_ID global legado.
--
-- Homologado nesta etapa:
-- - lista de conferências por empresa;
-- - criação/edição/exclusão de conferência em digitação;
-- - busca de produtos e importação TXT do coletor por empresa;
-- - contagem física;
-- - prévia de diferenças na empresa contextual;
-- - processamento da conferência;
-- - atualização de produtos.estoque_atual somente da empresa contextual;
-- - movimentos CONFERENCIA_ESTOQUE somente da empresa contextual.
--
-- Ainda fora do contexto cruzado:
-- - Entradas de mercadoria/XML/manual;
-- - devoluções e outras saídas automáticas;
-- - produção/ficha técnica;
-- - PDV e baixas automáticas;
-- - financeiro e emissão fiscal operacional.

START TRANSACTION;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'multiempresa_contexto',
  2026091613,
  NOW(),
  'V9.2 - Conferencia de Estoque homologada no contexto F11: contagem, importacao TXT, previa e processamento isolados por empresa; COMPANY_ID preservado'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

-- Nexa Próspero - Multiempresa Devolução de Cliente V10.2
-- Data: 16/09/2026
-- Banco alvo: nexaprospero
--
-- NÃO cria nem altera tabelas operacionais.
-- Homologa Devolução de Cliente / Outras Entradas para a empresa selecionada pelo F11,
-- mantendo COMPANY_ID global legado e preservando o fluxo atual fora do contexto Multiempresa.
--
-- Homologado nesta etapa:
-- - listagem de devoluções/outras entradas da empresa contextual;
-- - leitura de notas de venda de origem somente da empresa contextual;
-- - clientes, produtos, tributação e operação fiscal somente da empresa contextual;
-- - geração da pré-NF-e interna DEVOLUCAO_CLIENTE na empresa contextual;
-- - retorno de estoque pela operação fiscal, sempre na empresa contextual.
--
-- Proteções mantidas no contexto F11:
-- - edição/transmissão/consulta SEFAZ/impressão da pré-NF-e continuam bloqueadas;
-- - devolução a fornecedor/saídas fiscais continuam fora do contexto cruzado;
-- - Financeiro, PDV, produção e demais rotinas fiscais operacionais permanecem no legado.

START TRANSACTION;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'multiempresa_contexto',
  2026091616,
  NOW(),
  'V10.2 - Devolução de Cliente/Outras Entradas homologada no contexto F11 com cliente/produtos/operação/estoque isolados; emissão fiscal permanece bloqueada no contexto cruzado'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

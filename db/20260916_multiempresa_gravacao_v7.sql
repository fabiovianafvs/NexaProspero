-- Nexa Próspero - Multiempresa Gravação Protegida V7
-- Data: 16/09/2026
-- Banco alvo: nexaprospero
--
-- Esta V7 NÃO cria nem altera tabelas operacionais.
-- Homologa gravação protegida dos cadastros básicos de Produtos e Clientes
-- quando a aba está em contexto F11 válido.
--
-- Continua fora desta homologação cruzada:
-- - acerto/movimentação de estoque;
-- - duplicação de produto;
-- - ficha técnica/composição/produção;
-- - configuração fiscal do produto;
-- - inativação de pessoas;
-- - demais papéis de Pessoas;
-- - Financeiro operacional, PDV, Fiscal, O.S. e demais módulos.
--
-- COMPANY_ID global permanece legado.

START TRANSACTION;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'multiempresa_contexto',
  2026091607,
  NOW(),
  'V7 - gravacao protegida de Produtos e Clientes no contexto F11; COMPANY_ID global preservado; estoque/fiscal/PDV/financeiro operacional permanecem legados'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

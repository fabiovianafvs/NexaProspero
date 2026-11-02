-- Nexa Próspero - Multiempresa Fiscal/Tributação de Produtos V8
-- Data: 16/09/2026
-- Banco alvo: nexaprospero
--
-- Esta V8 NÃO cria nem altera tabelas operacionais.
-- Homologa o bloco Fiscal/Tributação existente dentro do cadastro de Produtos
-- para operar na empresa selecionada por um contexto F11 válido.
--
-- Continua fora desta homologação cruzada:
-- - cadastros gerais do módulo Fiscal (regras, operações e tabelas);
-- - acerto/movimentação de estoque;
-- - duplicação de produto;
-- - ficha técnica/composição/produção;
-- - documentos/emissões/entradas/saídas fiscais;
-- - Financeiro operacional, PDV e O.S.
--
-- COMPANY_ID global permanece legado.

START TRANSACTION;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'multiempresa_contexto',
  2026091608,
  NOW(),
  'V8 - Fiscal/Tributacao do cadastro de Produtos homologado no contexto F11; tabelas e operacao fiscal geral permanecem legadas; COMPANY_ID preservado'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

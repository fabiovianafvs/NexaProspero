-- Nexa Próspero - Multiempresa Cadastros Básicos V6
-- Data: 16/09/2026
-- Banco alvo: nexaprospero
--
-- Esta V6 NÃO cria nem altera tabelas operacionais.
-- Homologa SOMENTE CONSULTA/LISTAGEM de Produtos e Clientes no contexto F11.
-- Inclusões, edições, duplicações, exclusões/inativações e ajustes continuam
-- bloqueados quando a aba estiver em contexto de outra empresa.
-- COMPANY_ID global permanece legado.

START TRANSACTION;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'multiempresa_contexto',
  2026091606,
  NOW(),
  'V6 - consultas de Produtos e Clientes homologadas no contexto F11; gravacoes cruzadas bloqueadas; COMPANY_ID global preservado'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

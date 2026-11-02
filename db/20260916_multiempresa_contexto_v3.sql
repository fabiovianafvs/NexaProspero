-- Nexa Próspero - Multiempresa Contexto Seguro V3
-- Data: 16/09/2026
-- Banco alvo: nexaprospero
--
-- Esta V3 NÃO cria nem altera tabelas operacionais.
-- Registra apenas a instalação da camada de contexto seguro por aba.

START TRANSACTION;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'multiempresa_contexto',
  2026091603,
  NOW(),
  'V3 - contexto seguro por aba preparado; COMPANY_ID legado preservado; sem F11/ativacao operacional'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

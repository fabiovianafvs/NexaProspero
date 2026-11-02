-- Nexa Próspero - Multiempresa Dashboard Operacional V5
-- Data: 16/09/2026
-- Banco alvo: nexaprospero
--
-- Esta V5 NÃO cria nem altera tabelas operacionais.
-- Registra a primeira ativação operacional controlada do contexto Multiempresa.
-- Somente o Dashboard passa a consultar a empresa selecionada pelo F11.
-- COMPANY_ID global permanece legado para todo o restante do ERP.

START TRANSACTION;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'multiempresa_contexto',
  2026091605,
  NOW(),
  'V5 - Dashboard homologado para o contexto operacional F11; COMPANY_ID global preservado; demais modulos permanecem legados'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

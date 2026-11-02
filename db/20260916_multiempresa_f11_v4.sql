-- Nexa Próspero - Multiempresa F11 / Contexto V4
-- Data: 16/09/2026
-- Banco alvo: nexaprospero
--
-- Esta V4 NÃO cria nem altera tabelas operacionais.
-- Registra a instalação do seletor F11 e propagação do contexto seguro por aba.
-- COMPANY_ID permanece legado e nenhum módulo operacional é convertido nesta etapa.

START TRANSACTION;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'multiempresa_contexto',
  2026091604,
  NOW(),
  'V4 - F11 e identificacao/propagacao do contexto por aba; COMPANY_ID legado preservado; modulos operacionais ainda nao convertidos'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

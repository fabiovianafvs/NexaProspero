-- Nexa Próspero - Multiempresa Financeiro V11
-- Data: 16/09/2026
-- Banco alvo: nexaprospero
--
-- NÃO cria nem altera tabelas operacionais.
-- Homologa SOMENTE LEITURA do Financeiro para a empresa selecionada pelo F11,
-- mantendo COMPANY_ID global legado e preservando integralmente o fluxo atual
-- para empresas sem o módulo Multiempresa habilitado.
--
-- Homologado nesta etapa, em contexto cruzado F11:
-- - Dashboard Financeiro;
-- - Contas a Receber (consulta/filtros);
-- - Contas a Pagar (consulta/filtros);
-- - Movimento Financeiro (consulta/filtros);
-- - Resultado por Plano de Contas.
--
-- Proteções mantidas:
-- - nenhum POST financeiro é aceito nessas telas pelo F11;
-- - criação/edição/baixa/cancelamento/movimento manual continuam bloqueados;
-- - Despesas Fixas, Boletos, Conciliação e Tabelas Auxiliares continuam fora do escopo;
-- - Financeiro operacional gerado por Entradas/Devoluções continua bloqueado no contexto cruzado.

START TRANSACTION;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'multiempresa_contexto',
  2026091618,
  NOW(),
  'V11 - Financeiro Multiempresa homologado somente para consulta via F11: dashboard, receber, pagar, movimentos e resultado; toda gravação financeira permanece bloqueada'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

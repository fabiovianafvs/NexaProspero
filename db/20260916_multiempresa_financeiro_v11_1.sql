-- Nexa Próspero - Multiempresa Financeiro V11.1
-- Data: 16/09/2026
-- Banco alvo: nexaprospero
--
-- NÃO cria nem altera tabelas operacionais.
-- Libera, no contexto cruzado F11, SOMENTE criação e edição protegida de títulos
-- em Contas a Receber e Contas a Pagar da empresa selecionada.
--
-- Permanecem bloqueados pelo F11 nesta etapa:
-- - baixa individual e múltipla;
-- - cancelamento de títulos;
-- - movimento financeiro manual;
-- - caixa/banco;
-- - boletos/cobranças;
-- - conciliação;
-- - despesas fixas;
-- - tabelas auxiliares do Financeiro.
--
-- Proteções de escrita:
-- - pessoa, plano de contas e centro de custo precisam pertencer à empresa do F11;
-- - edição cruzada somente de título ABERTO, sem valor baixado e sem settlement;
-- - Receber com cobrança bancária ativa não pode ser editado pelo F11;
-- - Pagar originado de Despesa Fixa não pode ser editado pelo F11;
-- - criação cruzada nasce ABERTA, sem baixa/multa/juros/desconto de liquidação;
-- - redirects preservam mc_ctx por aba.

START TRANSACTION;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'multiempresa_contexto',
  2026091619,
  NOW(),
  'V11.1 - Financeiro Multiempresa: criação/edição protegida de títulos ABERTOS em Contas a Receber/Pagar via F11; baixas, cancelamentos, caixa/banco, boletos, conciliação e movimentos continuam bloqueados'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

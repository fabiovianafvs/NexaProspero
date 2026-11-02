-- Nexa Próspero - Multiempresa Financeiro V11.5
-- Data: 17/09/2026
-- Banco alvo: nexaprospero
-- Despesas Fixas Multiempresa protegidas.
-- Esta etapa NÃO cria nem altera tabelas operacionais: reutiliza a estrutura de
-- Despesas Fixas já existente e homologa seu uso pela empresa selecionada no F11.
-- Fora do contexto F11, o comportamento legado permanece inalterado.
-- Formas de Pagamento, PDV/NFC-e, Boletos/Itaú e Conciliação continuam separados.

START TRANSACTION;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'multiempresa_contexto',
  2026091723,
  NOW(),
  'V11.5 - Despesas Fixas Multiempresa protegidas via F11; regras, provisoes, baixas/cancelamentos protegidos e estorno com permissao adicional; edicao direta da provisao permanece bloqueada; legado fora do F11 preservado'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

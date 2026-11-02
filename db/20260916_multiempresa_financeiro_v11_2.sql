-- Nexa Próspero - Multiempresa Financeiro V11.2
-- Data: 16/09/2026
-- Banco alvo: nexaprospero
--
-- NÃO cria nem altera tabelas operacionais.
-- Libera, no contexto cruzado F11, baixas individuais e múltiplas protegidas em
-- Contas a Receber e Contas a Pagar da empresa selecionada.
--
-- A V11.2 passa a gerar, sempre com o company_id contextual:
-- - finance_settlements;
-- - finance_settlement_payments, quando disponível;
-- - finance_movements;
-- - atualização de paid_amount/open_amount/status do título da mesma empresa.
--
-- Proteções específicas do contexto F11:
-- - exige permissão efetiva financeiro_baixar_receber / financeiro_baixar_pagar;
-- - título precisa pertencer à empresa contextual e estar ABERTO/PARCIAL;
-- - pessoa, plano de contas e centro de custo do título são revalidados por empresa;
-- - caixa/banco informado precisa existir e estar ativo na empresa contextual;
-- - forma de pagamento é validada pela empresa contextual;
-- - Conta a Receber com boleto/cobrança ativa não pode ser baixada pelo F11;
-- - Conta a Pagar originada de Despesa Fixa não pode ser baixada pelo F11;
-- - recibo/WhatsApp de baixa continuam no fluxo legado e não são abertos automaticamente no F11;
-- - cancelamento, estorno, movimento manual, configuração de caixa/banco, boletos,
--   conciliação e despesas fixas continuam bloqueados no contexto cruzado.

START TRANSACTION;

INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
VALUES (
  'multiempresa_contexto',
  2026091620,
  NOW(),
  'V11.2 - Financeiro Multiempresa: baixas individuais/multiplas protegidas de Receber/Pagar via F11 com settlement/payment/movement isolados por empresa; boleto ativo, despesa fixa, cancelamento e estorno permanecem bloqueados'
)
ON DUPLICATE KEY UPDATE
  version_number=GREATEST(version_number,VALUES(version_number)),
  applied_at=NOW(),
  notes=VALUES(notes);

COMMIT;

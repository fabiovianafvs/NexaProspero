-- Nexa Próspero - Status de assinatura "Bonificada" (complimentary).
-- Empresa bonificada: uso próprio, cortesia ou permuta de serviço. Fica ativa,
-- sem vencimento, sem contrato e sem cobrança na empresa faturadora.
-- Aplicar pelo Atualizador do Banco (platform_admin): primeiro na homologação, depois em produção.

ALTER TABLE subscriptions
  MODIFY COLUMN status ENUM('trial','active','past_due','canceled','cancelled','pending_payment','complimentary') NOT NULL DEFAULT 'trial';

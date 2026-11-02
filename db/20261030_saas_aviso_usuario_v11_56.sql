-- V11.56: Avisos de vencimento SaaS configuráveis por usuário.
-- DEFAULT 1: o administrador criado pelo SaaS e os novos usuários recebem aviso habilitado.
ALTER TABLE users ADD COLUMN show_contract_due_notice TINYINT(1) NOT NULL DEFAULT 1;

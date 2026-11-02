-- Integração isolada Contas a Receber x Caixa, desligada por empresa inicialmente.
-- Sem alterações em tabelas legadas, PDV, estoque ou fiscal.
CREATE TABLE IF NOT EXISTS gestao_cash_receivable_settings (
    company_id INT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    updated_by_user_id INT NULL,
    updated_at DATETIME NULL,
    PRIMARY KEY (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gestao_cash_receivable_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT NOT NULL,
    settlement_id INT NOT NULL,
    settlement_payment_id INT NOT NULL,
    cash_operation_id INT NOT NULL,
    reversal_cash_operation_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reversed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gestao_cash_cr_payment (company_id, settlement_payment_id),
    UNIQUE KEY uq_gestao_cash_cr_operation (company_id, cash_operation_id),
    KEY idx_gestao_cash_cr_settlement (company_id, settlement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

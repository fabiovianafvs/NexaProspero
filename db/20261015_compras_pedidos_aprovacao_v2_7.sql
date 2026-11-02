-- Nexa Compras 2.7: auditoria de aprovacao de pedidos; nenhuma movimentacao de estoque/financeiro/fiscal.
CREATE TABLE IF NOT EXISTS compras_pedidos_aprovacoes (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id INT NOT NULL,
 pedido_id BIGINT UNSIGNED NOT NULL,
 user_id INT NOT NULL,
 status_anterior VARCHAR(20) NOT NULL,
 status_novo VARCHAR(20) NOT NULL,
 aprovado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 observacao VARCHAR(500) NULL,
 UNIQUE KEY uk_compras_pedido_aprovacao (company_id,pedido_id),
 KEY idx_compras_aprovacao_usuario (company_id,user_id,aprovado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nexa Compras 2.8: controle ADMINISTRATIVO de entregas, sem movimentos de estoque, financeiro ou fiscal.
CREATE TABLE IF NOT EXISTS compras_pedido_recebimentos (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id INT NOT NULL,
 pedido_id BIGINT UNSIGNED NOT NULL,
 pedido_item_id BIGINT UNSIGNED NOT NULL,
 quantidade DECIMAL(16,3) NOT NULL,
 recebido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 registrado_por INT NOT NULL,
 observacao VARCHAR(500) NULL,
 KEY idx_compras_recebimento_saldo (company_id,pedido_id,pedido_item_id),
 KEY idx_compras_recebimento_usuario (company_id,registrado_por,recebido_em),
 CONSTRAINT fk_compras_recebimento_pedido FOREIGN KEY (pedido_id) REFERENCES compras_pedidos(id),
 CONSTRAINT fk_compras_recebimento_item FOREIGN KEY (pedido_item_id) REFERENCES compras_pedido_itens(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

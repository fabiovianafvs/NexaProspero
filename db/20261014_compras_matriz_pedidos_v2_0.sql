-- Compras 2.0: fornecedores da cotacao, escolhas e pedidos de compra EM RASCUNHO.
CREATE TABLE IF NOT EXISTS compras_cotacao_fornecedores (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id INT NOT NULL, cotacao_id BIGINT UNSIGNED NOT NULL,
 nome VARCHAR(150) NOT NULL, prazo_dias INT UNSIGNED NULL,
 observacoes VARCHAR(500) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uk_cot_fornecedor (cotacao_id,nome),
 KEY idx_cot_fornecedor_empresa(company_id,cotacao_id),
 CONSTRAINT fk_cot_fornecedor FOREIGN KEY(cotacao_id) REFERENCES compras_cotacoes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS compras_cotacao_escolhas (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id INT NOT NULL,cotacao_id BIGINT UNSIGNED NOT NULL,
 lista_item_id BIGINT UNSIGNED NOT NULL, fornecedor_nome VARCHAR(150) NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uk_cot_escolha(cotacao_id,lista_item_id),
 CONSTRAINT fk_cot_escolha FOREIGN KEY(cotacao_id) REFERENCES compras_cotacoes(id) ON DELETE CASCADE,
 CONSTRAINT fk_cot_escolha_item FOREIGN KEY(lista_item_id) REFERENCES compras_lista_itens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS compras_pedidos (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id INT NOT NULL,cotacao_id BIGINT UNSIGNED NOT NULL,
 fornecedor_nome VARCHAR(150) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'rascunho',
 created_by INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uk_pedido_cot_fornecedor(cotacao_id,fornecedor_nome),
 KEY idx_pedido_empresa(company_id,id),
 CONSTRAINT fk_pedido_cot FOREIGN KEY(cotacao_id) REFERENCES compras_cotacoes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS compras_pedido_itens (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id INT NOT NULL,pedido_id BIGINT UNSIGNED NOT NULL,
 lista_item_id BIGINT UNSIGNED NOT NULL,produto_id BIGINT UNSIGNED NOT NULL,
 quantidade DECIMAL(16,3) NOT NULL,preco_unitario DECIMAL(17,4) NOT NULL,
 desconto_percentual DECIMAL(7,3) NOT NULL DEFAULT 0,
 UNIQUE KEY uk_pedido_item(pedido_id,lista_item_id),
 CONSTRAINT fk_pedido_item_pedido FOREIGN KEY(pedido_id) REFERENCES compras_pedidos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

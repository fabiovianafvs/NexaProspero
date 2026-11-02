-- Compras 1.9: cotacoes de listas, sem integracao de pedido, estoque, financeiro ou fiscal.
CREATE TABLE IF NOT EXISTS compras_cotacoes (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id INT NOT NULL,
 lista_id BIGINT UNSIGNED NOT NULL,
 titulo VARCHAR(150) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'rascunho',
 observacoes VARCHAR(1000) NULL,
 created_by INT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_compras_cotacoes_empresa (company_id,created_at),
 KEY idx_compras_cotacoes_lista (lista_id),
 CONSTRAINT fk_compras_cotacoes_lista FOREIGN KEY (lista_id) REFERENCES compras_listas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS compras_cotacao_ofertas (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id INT NOT NULL,
 cotacao_id BIGINT UNSIGNED NOT NULL,
 lista_item_id BIGINT UNSIGNED NOT NULL,
 fornecedor_nome VARCHAR(150) NOT NULL,
 preco_unitario DECIMAL(17,4) NOT NULL,
 desconto_percentual DECIMAL(7,3) NOT NULL DEFAULT 0,
 prazo_dias INT UNSIGNED NULL,
 observacoes VARCHAR(500) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uk_cotacao_item_fornecedor(cotacao_id,lista_item_id,fornecedor_nome),
 KEY idx_cotacao_empresa(company_id,cotacao_id),
 CONSTRAINT fk_compras_oferta_cotacao FOREIGN KEY (cotacao_id) REFERENCES compras_cotacoes(id) ON DELETE CASCADE,
 CONSTRAINT fk_compras_oferta_lista_item FOREIGN KEY (lista_item_id) REFERENCES compras_lista_itens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

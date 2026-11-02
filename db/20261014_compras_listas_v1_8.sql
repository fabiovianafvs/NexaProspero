-- Nexa Compras fase 1.8: rascunhos isolados, nenhuma alteração em PDV/fiscal/estoque.
CREATE TABLE IF NOT EXISTS compras_listas (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 company_id INT NOT NULL,
 nome VARCHAR(120) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'rascunho',
 created_by INT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 KEY idx_compras_listas_empresa(company_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS compras_lista_itens (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 lista_id BIGINT UNSIGNED NOT NULL,
 company_id INT NOT NULL,
 produto_id INT NOT NULL,
 quantidade DECIMAL(15,3) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uk_compras_lista_produto(lista_id,produto_id),
 KEY idx_compras_item_empresa(company_id,produto_id),
 CONSTRAINT fk_compras_item_lista FOREIGN KEY (lista_id) REFERENCES compras_listas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

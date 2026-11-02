-- Vinculo documental de NF-e a pedidos de compra. Nenhum movimento operacional.
CREATE TABLE IF NOT EXISTS compras_pedido_nfe (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id INT NOT NULL,
 pedido_id BIGINT UNSIGNED NOT NULL,
 chave_nfe CHAR(44) NOT NULL,
 numero VARCHAR(20) NOT NULL,
 serie VARCHAR(10) NOT NULL,
 emitente_nome VARCHAR(255) NOT NULL,
 emitente_cnpj VARCHAR(14) NULL,
 xml_sha256 CHAR(64) NOT NULL,
 vinculado_por INT NOT NULL,
 vinculado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 observacao VARCHAR(500) NULL,
 UNIQUE KEY uk_compras_nfe_company_chave (company_id,chave_nfe),
 KEY idx_compras_nfe_pedido (company_id,pedido_id),
 CONSTRAINT fk_compras_nfe_pedido FOREIGN KEY (pedido_id) REFERENCES compras_pedidos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS compras_pedido_nfe_itens (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id INT NOT NULL,
 nfe_vinculo_id BIGINT UNSIGNED NOT NULL,
 pedido_item_id BIGINT UNSIGNED NOT NULL,
 quantidade DECIMAL(16,3) NOT NULL,
 valor_produtos DECIMAL(18,2) NOT NULL,
 KEY idx_compras_nfe_item (company_id,pedido_item_id),
 CONSTRAINT fk_compras_nfe_itens_vinculo FOREIGN KEY (nfe_vinculo_id) REFERENCES compras_pedido_nfe(id),
 CONSTRAINT fk_compras_nfe_itens_pedido_item FOREIGN KEY (pedido_item_id) REFERENCES compras_pedido_itens(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

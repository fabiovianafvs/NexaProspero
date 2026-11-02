-- Migration de auditoria. Executar uma única vez pelo Atualizador do Banco ou fluxo administrativo homologado.
CREATE TABLE IF NOT EXISTS contract_item_adjustments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 company_id INT NOT NULL,
 contract_id BIGINT UNSIGNED NOT NULL,
 contract_item_id BIGINT UNSIGNED NOT NULL,
 old_adjustment_date DATE NOT NULL,
 new_adjustment_date DATE NOT NULL,
 percent_adjusted DECIMAL(9,4) NOT NULL,
 old_unit_price DECIMAL(15,4) NOT NULL,
 new_unit_price DECIMAL(15,4) NOT NULL,
 old_total DECIMAL(15,2) NOT NULL,
 new_total DECIMAL(15,2) NOT NULL,
 created_by INT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uk_adjustment_once(company_id,contract_item_id,old_adjustment_date),
 KEY idx_adjustment_contract(company_id,contract_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

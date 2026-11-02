-- Nexa Próspero - vínculo persistente de produtos recebidos por XML
-- Data: 2026-09-15
-- Observação: fiscal_xml_ensure_schema() também cria esta tabela automaticamente.

CREATE TABLE IF NOT EXISTS fiscal_xml_product_links (
  id INT NOT NULL AUTO_INCREMENT,
  company_id BIGINT NOT NULL,
  supplier_doc VARCHAR(20) NOT NULL DEFAULT '',
  supplier_product_code VARCHAR(80) NULL,
  supplier_ean VARCHAR(30) NULL,
  product_id BIGINT NOT NULL,
  conversion_factor DECIMAL(15,6) NOT NULL DEFAULT 1.000000,
  last_xml_import_id INT NULL,
  last_xml_item_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_fiscal_xml_product_link_code (company_id, supplier_doc, supplier_product_code),
  KEY idx_fiscal_xml_product_link_ean (company_id, supplier_doc, supplier_ean),
  KEY idx_fiscal_xml_product_link_product (company_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

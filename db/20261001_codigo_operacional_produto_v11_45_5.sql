-- Nexa Próspero V11.45.5 - Código operacional do produto por empresa
-- Aplicar exclusivamente pelo Atualizador do Banco.
--
-- O numero_cadastro é o código visível/operacional do produto dentro da empresa,
-- não o ID interno (produtos.id). Ele precisa preservar zeros à esquerda e
-- códigos legados longos vindos de importações.

CREATE TABLE IF NOT EXISTS product_import_product_map (
  company_id BIGINT NOT NULL,
  source_type VARCHAR(40) NOT NULL,
  source_code VARCHAR(80) NOT NULL,
  product_id BIGINT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (company_id, source_type, source_code),
  KEY idx_product_import_source_product (company_id, source_type, product_id),
  KEY idx_product_import_product (company_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE produtos
  MODIFY COLUMN numero_cadastro VARCHAR(60) NULL;

-- Corrige automaticamente os produtos já importados por planilha nas versões
-- 11.45.3/11.45.4, usando o mapa idempotente gravado durante a importação.
UPDATE produtos p
INNER JOIN product_import_product_map m
        ON m.company_id = p.company_id
       AND m.product_id = p.id
       AND m.source_type = 'PROSPERO_EXCEL'
SET p.numero_cadastro = LEFT(m.source_code, 60)
WHERE m.source_code IS NOT NULL
  AND TRIM(m.source_code) <> '';

CREATE INDEX idx_produtos_company_numero_cadastro_txt
  ON produtos (company_id, numero_cadastro);

INSERT INTO nexa_schema_versions (module_name, version_number, applied_at, notes)
VALUES ('product_company_code', 20261001455, NOW(), 'V11.45.5 - codigo operacional exato do produto por empresa')
ON DUPLICATE KEY UPDATE
  version_number = VALUES(version_number),
  applied_at = VALUES(applied_at),
  notes = VALUES(notes);

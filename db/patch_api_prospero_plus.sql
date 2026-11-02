-- Patch: API externa para integração com Próspero Plus
-- As configurações da API são salvas na tabela app_config com a chave external_api.
-- Este patch cria apenas a tabela de logs da API; app_config já existe no projeto.

CREATE TABLE IF NOT EXISTS external_api_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NULL,
  endpoint VARCHAR(80) NOT NULL,
  method VARCHAR(12) NOT NULL,
  status_code INT NOT NULL DEFAULT 200,
  request_json LONGTEXT NULL,
  response_json LONGTEXT NULL,
  ip VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_external_api_logs_company (company_id),
  KEY idx_external_api_logs_endpoint (endpoint),
  KEY idx_external_api_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recomendado para integração de produtos com o código/referência do Próspero Plus.
CREATE TABLE IF NOT EXISTS produtos_firebird_map (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT NOT NULL,
  produto_id BIGINT NOT NULL,
  codigo_firebird VARCHAR(60) NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_produtos_firebird_map (company_id, produto_id),
  KEY idx_produtos_firebird_codigo (company_id, codigo_firebird)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Se preferir gravar o código diretamente em produtos, execute manualmente se a coluna ainda não existir:
-- ALTER TABLE produtos ADD COLUMN codigo_firebird VARCHAR(60) NULL;

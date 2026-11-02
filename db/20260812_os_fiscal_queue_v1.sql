-- Nexa Próspero - Ordem de Serviço v1.9
-- Fila fiscal EXCLUSIVA da O.S.
-- Este script NÃO altera a fila fiscal do PDV e NÃO altera os motores NF-e/NFC-e/NFS-e.

CREATE TABLE IF NOT EXISTS os_fiscal_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  os_order_id BIGINT UNSIGNED NOT NULL,
  pedido_id BIGINT UNSIGNED NULL,
  document_type VARCHAR(10) NOT NULL,
  fiscal_document_id BIGINT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'PENDENTE',
  priority INT NOT NULL DEFAULT 5,
  attempts INT NOT NULL DEFAULT 0,
  max_attempts INT NOT NULL DEFAULT 3,
  requested_by INT NULL,
  request_payload LONGTEXT NULL,
  response_payload LONGTEXT NULL,
  error_message TEXT NULL,
  scheduled_at DATETIME NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_os_fiscal_queue_pending (status, scheduled_at, priority, id),
  KEY idx_os_fiscal_queue_order (company_id, os_order_id, document_type, id),
  KEY idx_os_fiscal_queue_pedido (company_id, pedido_id, id),
  KEY idx_os_fiscal_queue_document (company_id, fiscal_document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

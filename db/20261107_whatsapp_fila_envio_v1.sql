-- Nexa Próspero - Fila de envio do WhatsApp (whatsapp_outbox).
-- Mensagens automáticas (pedido da loja, venda do PDV, status do pedido) entram
-- nesta fila e são enviadas por um processo separado. Assim, se o WhatsApp
-- travar, salvar pedidos, vender e emitir notas continua instantâneo.
-- Aplicar pelo Atualizador do Banco (platform_admin): primeiro na homologação, depois em produção.

CREATE TABLE IF NOT EXISTS whatsapp_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id INT UNSIGNED NOT NULL,
  pedido_id INT UNSIGNED NULL,
  tipo VARCHAR(40) NOT NULL,
  destino VARCHAR(30) NOT NULL,
  mensagem TEXT NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'PENDENTE',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  KEY idx_whatsapp_outbox_queue (status, next_attempt_at),
  KEY idx_whatsapp_outbox_company (company_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

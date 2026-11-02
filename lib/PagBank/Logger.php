<?php
declare(strict_types=1);

namespace Nexa\PagBank;

use PDO;
use Throwable;

final class Logger
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS pagbank_api_logs (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          operation VARCHAR(80) NOT NULL,
          method VARCHAR(10) NOT NULL,
          endpoint VARCHAR(500) NOT NULL,
          reference_id VARCHAR(100) NULL,
          external_id VARCHAR(100) NULL,
          http_status INT NOT NULL DEFAULT 0,
          success TINYINT(1) NOT NULL DEFAULT 0,
          request_json LONGTEXT NULL,
          response_json LONGTEXT NULL,
          error_message LONGTEXT NULL,
          duration_ms INT NOT NULL DEFAULT 0,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY idx_pagbank_log_reference (reference_id),
          KEY idx_pagbank_log_external (external_id),
          KEY idx_pagbank_log_created (created_at),
          KEY idx_pagbank_log_success (success)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function write(array $data): int
    {
        try {
            $st = $this->pdo->prepare('INSERT INTO pagbank_api_logs (operation,method,endpoint,reference_id,external_id,http_status,success,request_json,response_json,error_message,duration_ms) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([
                (string)($data['operation'] ?? 'request'),
                strtoupper((string)($data['method'] ?? 'GET')),
                (string)($data['endpoint'] ?? ''),
                ($data['reference_id'] ?? '') !== '' ? (string)$data['reference_id'] : null,
                ($data['external_id'] ?? '') !== '' ? (string)$data['external_id'] : null,
                (int)($data['http_status'] ?? 0),
                !empty($data['success']) ? 1 : 0,
                isset($data['request']) ? self::json($data['request']) : null,
                isset($data['response']) ? self::json($data['response']) : null,
                ($data['error'] ?? '') !== '' ? (string)$data['error'] : null,
                (int)($data['duration_ms'] ?? 0),
            ]);
            return (int)$this->pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('PagBank Logger: ' . $e->getMessage());
            return 0;
        }
    }

    private static function json($value): string
    {
        if (is_string($value)) return $value;
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}

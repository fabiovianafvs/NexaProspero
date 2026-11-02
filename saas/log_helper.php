<?php
declare(strict_types=1);

if (!function_exists('platform_log')) {
  function platform_log(PDO $pdo, string $action, ?int $companyId = null, ?int $userId = null, array $meta = []): void {
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS platform_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT NULL,
        user_id BIGINT NULL,
        action VARCHAR(190) NOT NULL,
        meta_json LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_platform_logs_company (company_id),
        KEY idx_platform_logs_user (user_id),
        KEY idx_platform_logs_created (created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

      $stmt = $pdo->prepare('INSERT INTO platform_logs (company_id, user_id, action, meta_json) VALUES (?, ?, ?, ?)');
      $json = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
      $stmt->execute([$companyId, $userId, $action, $json]);
    } catch (Throwable $e) {
      // Não quebra o fluxo por causa do log.
    }
  }
}

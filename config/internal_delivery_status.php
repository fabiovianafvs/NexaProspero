<?php
declare(strict_types=1);

if (!function_exists('internal_delivery_status_ensure_schema')) {
  function internal_delivery_status_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS gestao_internal_delivery_statuses (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      company_id BIGINT UNSIGNED NOT NULL,
      name VARCHAR(120) NOT NULL,
      code VARCHAR(80) NOT NULL,
      color VARCHAR(20) NOT NULL DEFAULT '#0B1F5B',
      sort_order INT NOT NULL DEFAULT 50,
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_internal_delivery_company_code (company_id, code),
      KEY idx_internal_delivery_company_active (company_id, active, sort_order, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try { $pdo->exec("ALTER TABLE pedidos ADD COLUMN internal_delivery_status_id BIGINT UNSIGNED NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE pedidos ADD KEY idx_pedidos_internal_delivery (company_id, internal_delivery_status_id)"); } catch (Throwable $e) {}
  }
}

if (!function_exists('internal_delivery_status_slug')) {
  function internal_delivery_status_slug(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : $value;
    if (is_string($ascii) && $ascii !== '') $value = $ascii;
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?: '';
    return trim($value, '_');
  }
}

if (!function_exists('internal_delivery_status_options')) {
  function internal_delivery_status_options(PDO $pdo, int $companyId, bool $includeInactive = false): array {
    internal_delivery_status_ensure_schema($pdo);
    $sql = 'SELECT id,name,code,color,sort_order,active FROM gestao_internal_delivery_statuses WHERE company_id = ?';
    $params = [$companyId];
    if (!$includeInactive) $sql .= ' AND active = 1';
    $sql .= ' ORDER BY sort_order ASC, name ASC, id ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('internal_delivery_status_find')) {
  function internal_delivery_status_find(PDO $pdo, int $companyId, int $id): ?array {
    if ($id <= 0) return null;
    internal_delivery_status_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM gestao_internal_delivery_statuses WHERE company_id = ? AND id = ? LIMIT 1');
    $st->execute([$companyId, $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }
}

if (!function_exists('internal_delivery_status_label')) {
  function internal_delivery_status_label(PDO $pdo, int $companyId, int $id): string {
    $row = internal_delivery_status_find($pdo, $companyId, $id);
    return trim((string)($row['name'] ?? ''));
  }
}

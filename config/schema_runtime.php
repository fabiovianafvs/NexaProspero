<?php
declare(strict_types=1);

/**
 * Controle leve de versão de schema para impedir DDL/INFORMATION_SCHEMA
 * em todas as requisições web. As migrações marcam cada módulo como pronto.
 */

if (!function_exists('nexa_schema_versions_ensure')) {
  function nexa_schema_versions_ensure(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS nexa_schema_versions (
      module_name VARCHAR(80) NOT NULL,
      version_number BIGINT UNSIGNED NOT NULL,
      applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      notes VARCHAR(255) NULL,
      PRIMARY KEY (module_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }
}

if (!function_exists('nexa_schema_version_ready')) {
  function nexa_schema_version_ready(PDO $pdo, string $module, int $requiredVersion): bool {
    if ($module === '' || $requiredVersion <= 0) return false;
    try {
      $st = $pdo->prepare('SELECT version_number FROM nexa_schema_versions WHERE module_name=? LIMIT 1');
      $st->execute([$module]);
      $version = $st->fetchColumn();
      return $version !== false && (int)$version >= $requiredVersion;
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('nexa_schema_version_mark')) {
  function nexa_schema_version_mark(PDO $pdo, string $module, int $version, string $notes = ''): void {
    if ($module === '' || $version <= 0) return;
    try {
      nexa_schema_versions_ensure($pdo);
      $pdo->prepare("INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
        VALUES (?,?,NOW(),NULLIF(?,''))
        ON DUPLICATE KEY UPDATE version_number=GREATEST(version_number,VALUES(version_number)),applied_at=NOW(),notes=VALUES(notes)")
        ->execute([$module, $version, mb_substr($notes, 0, 255, 'UTF-8')]);
    } catch (Throwable $e) {
      error_log('[Nexa Schema] Falha ao marcar versão: ' . $e->getMessage());
    }
  }
}

<?php
declare(strict_types=1);

if (!function_exists('fiscal_contingency_table_exists_light')) {
  function fiscal_contingency_table_exists_light(PDO $pdo, string $table): bool {
    try {
      $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
      $st->execute([$table]);
      return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('fiscal_contingency_column_exists_light')) {
  function fiscal_contingency_column_exists_light(PDO $pdo, string $table, string $column): bool {
    try {
      $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
      $st->execute([$table, $column]);
      return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('fiscal_contingency_login_alert_enabled')) {
  function fiscal_contingency_login_alert_enabled(PDO $pdo, int $companyId): bool {
    if (!fiscal_contingency_table_exists_light($pdo, 'fiscal_company_config')) return false;
    if (!fiscal_contingency_column_exists_light($pdo, 'fiscal_company_config', 'contingency_login_alert_enabled')) return false;
    try {
      $st = $pdo->prepare('SELECT contingency_enabled, contingency_login_alert_enabled FROM fiscal_company_config WHERE company_id=? LIMIT 1');
      $st->execute([$companyId]);
      $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      return !empty($row['contingency_enabled']) && !empty($row['contingency_login_alert_enabled']);
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('fiscal_contingency_pending_login_count')) {
  function fiscal_contingency_pending_login_count(PDO $pdo, int $companyId): int {
    if ($companyId <= 0 || !fiscal_contingency_login_alert_enabled($pdo, $companyId)) return 0;
    if (!fiscal_contingency_table_exists_light($pdo, 'fiscal_documents')) return 0;
    try {
      $st = $pdo->prepare("SELECT COUNT(*) FROM fiscal_documents
        WHERE company_id=? AND direction='SAIDA' AND model='65'
          AND UPPER(status) IN ('CONTINGENCIA','EM_CONTINGENCIA','PENDENTE_CONTINGENCIA','OFFLINE')");
      $st->execute([$companyId]);
      return max(0, (int)$st->fetchColumn());
    } catch (Throwable $e) { return 0; }
  }
}

if (!function_exists('fiscal_contingency_post_login_url')) {
  function fiscal_contingency_post_login_url(PDO $pdo, int $companyId, string $defaultUrl): string {
    if (function_exists('access_can_page') && !access_can_page($pdo, $companyId, 'fiscal_lote_contingencia.php')) return $defaultUrl;
    $count = fiscal_contingency_pending_login_count($pdo, $companyId);
    if ($count <= 0) return $defaultUrl;
    return 'fiscal_lote_contingencia.php?contingencia_login=1&pending=' . $count;
  }
}

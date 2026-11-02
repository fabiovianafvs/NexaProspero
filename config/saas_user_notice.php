<?php
declare(strict_types=1);
/** V11.56: Leitura sem DDL do aviso de vencimento por usuário. */
require_once __DIR__.'/schema_runtime.php';
if (!function_exists('nexa_user_contract_notice_enabled')) {
  function nexa_user_contract_notice_enabled(PDO $pdo, int $companyId, int $userId): bool {
    if ($companyId <= 0 || $userId <= 0) return false;
    // Antes da migration mantém o comportamento anterior, evitando erro SQL.
    if (!nexa_schema_version_ready($pdo,'saas_user_contract_notice',20261030001)) return true;
    try {
      $st=$pdo->prepare('SELECT show_contract_due_notice FROM users WHERE id=? AND company_id=? AND is_active=1 LIMIT 1');
      $st->execute([$userId,$companyId]);$v=$st->fetchColumn();
      return $v!==false && (int)$v===1;
    } catch(Throwable $e) {
      error_log('[Nexa][Aviso contrato] '.$e->getMessage());
      return false;
    }
  }
}

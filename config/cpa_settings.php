<?php
declare(strict_types=1);

/**
 * Configurações CPA (Contas a Pagar) por empresa — app_config, chave "cpa_settings".
 *
 * - settle_individually: na baixa de várias contas a pagar, lança um movimento
 *   financeiro por parcela (1) ou um único movimento com o total (0).
 *   Até ser salva pela primeira vez, herda a opção antiga (finance_settings.
 *   settle_titles_individually), que era compartilhada com o Contas a Receber.
 *
 * Sem DDL: usa a tabela app_config já existente.
 */

require_once __DIR__ . '/config_store.php';

if (!function_exists('nexa_cpa_settings')) {
  function nexa_cpa_settings(PDO $pdo, int $companyId, bool $refresh = false): array {
    static $cache = [];
    if ($companyId <= 0) return ['settle_individually' => false, 'saved' => false];
    if (!$refresh && isset($cache[$companyId])) return $cache[$companyId];
    $cfg = ['settle_individually' => false, 'saved' => false];
    try {
      $stored = config_store_get($pdo, $companyId, 'cpa_settings');
      if (is_array($stored) && array_key_exists('settle_individually', $stored)) {
        $cfg = ['settle_individually' => !empty($stored['settle_individually']), 'saved' => true];
      } elseif (function_exists('finance_settings')) {
        $legacy = finance_settings($pdo, $companyId, false);
        $cfg['settle_individually'] = !empty($legacy['settle_titles_individually']);
      }
    } catch (Throwable $e) {
      error_log('[Nexa][cpa_settings] ' . $e->getMessage());
    }
    return $cache[$companyId] = $cfg;
  }
}

if (!function_exists('nexa_cpa_settings_save')) {
  function nexa_cpa_settings_save(PDO $pdo, int $companyId, array $changes): array {
    $cfg = ['settle_individually' => !empty($changes['settle_individually'])];
    config_store_set($pdo, $companyId, 'cpa_settings', $cfg);
    return nexa_cpa_settings($pdo, $companyId, true);
  }
}

<?php
declare(strict_types=1);

/**
 * Configurações de caixa por empresa (app_config, chave "cash_settings").
 *
 * - disable_user_cash: "Desabilitar caixa usuário". Quando ligado, o mesmo
 *   Caixa pode ser usado por vários usuários e vendas, baixas, pagamentos e
 *   lançamentos não exigem abertura de caixa. Os lançamentos continuam indo
 *   normalmente para o Movimento Financeiro, na conta escolhida.
 * - planos de contas padrão: transferência (saída/entrada), sangria e aporte.
 *
 * Sem DDL: usa a tabela app_config já existente.
 */

require_once __DIR__ . '/config_store.php';

if (!function_exists('nexa_cash_settings_defaults')) {
  function nexa_cash_settings_defaults(): array {
    return [
      'disable_user_cash' => false,
      'chart_transfer_out' => 0,
      'chart_transfer_in' => 0,
      'chart_sangria' => 0,
      'chart_aporte' => 0,
    ];
  }
}

if (!function_exists('nexa_cash_settings')) {
  function nexa_cash_settings(PDO $pdo, int $companyId, bool $refresh = false): array {
    static $cache = [];
    if ($companyId <= 0) return nexa_cash_settings_defaults();
    if (!$refresh && isset($cache[$companyId])) return $cache[$companyId];
    $cfg = nexa_cash_settings_defaults();
    try {
      $stored = config_store_get($pdo, $companyId, 'cash_settings') ?: [];
      $cfg['disable_user_cash'] = !empty($stored['disable_user_cash']);
      foreach (['chart_transfer_out', 'chart_transfer_in', 'chart_sangria', 'chart_aporte'] as $k) {
        $cfg[$k] = max(0, (int)($stored[$k] ?? 0));
      }
    } catch (Throwable $e) {
      error_log('[Nexa][cash_settings] ' . $e->getMessage());
    }
    return $cache[$companyId] = $cfg;
  }
}

if (!function_exists('nexa_cash_settings_save')) {
  function nexa_cash_settings_save(PDO $pdo, int $companyId, array $changes): array {
    $cfg = array_merge(nexa_cash_settings($pdo, $companyId, true), array_intersect_key($changes, nexa_cash_settings_defaults()));
    $cfg['disable_user_cash'] = !empty($cfg['disable_user_cash']);
    foreach (['chart_transfer_out', 'chart_transfer_in', 'chart_sangria', 'chart_aporte'] as $k) $cfg[$k] = max(0, (int)$cfg[$k]);
    config_store_set($pdo, $companyId, 'cash_settings', $cfg);
    return nexa_cash_settings($pdo, $companyId, true);
  }
}

if (!function_exists('nexa_cash_user_control_disabled')) {
  /** true = "Desabilitar caixa usuário" ligado: não exige caixa aberto nem caixa exclusivo. */
  function nexa_cash_user_control_disabled(PDO $pdo, int $companyId): bool {
    return !empty(nexa_cash_settings($pdo, $companyId)['disable_user_cash']);
  }
}

if (!function_exists('nexa_cash_requires_open_session')) {
  /** A forma de pagamento exige caixa aberto nesta empresa? */
  function nexa_cash_requires_open_session(PDO $pdo, int $companyId, array $paymentMethod): bool {
    return !empty($paymentMethod['require_open_cash']) && !nexa_cash_user_control_disabled($pdo, $companyId);
  }
}

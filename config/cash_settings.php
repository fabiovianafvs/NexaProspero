<?php
declare(strict_types=1);

/**
 * Configurações de caixa por empresa (app_config, chave "cash_settings").
 *
 * - Controle de caixa POR USUÁRIO: "Ativar Caixa Terminal" no cadastro do
 *   usuário (users.cash_terminal_enabled). Só quem tem Caixa Terminal abre e
 *   fecha caixa, precisa do caixa aberto nas formas de pagamento que exigem
 *   caixa e tem o caixa físico exclusivo. Os demais (padrão) vendem e lançam
 *   sem abrir caixa; os lançamentos vão normalmente para o Movimento Financeiro.
 * - disable_user_cash: configuração antiga por empresa, usada só enquanto a
 *   migração 20261108_caixa_terminal_usuario_v1 não for aplicada.
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
  /** Configuração antiga por empresa ("Desabilitar caixa usuário"); só vale antes da migração. */
  function nexa_cash_user_control_disabled(PDO $pdo, int $companyId): bool {
    return !empty(nexa_cash_settings($pdo, $companyId)['disable_user_cash']);
  }
}

if (!function_exists('nexa_cash_terminal_ready')) {
  /** A coluna users.cash_terminal_enabled existe? (migração aplicada) */
  function nexa_cash_terminal_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
      $st = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'cash_terminal_enabled'");
      return $ready = ((int)$st->fetchColumn() > 0);
    } catch (Throwable $e) {
      return $ready = false;
    }
  }
}

if (!function_exists('nexa_cash_user_terminal_enabled')) {
  /**
   * O usuário trabalha com Caixa Terminal (abre/fecha caixa)?
   * Antes da migração mantém o comportamento antigo da empresa.
   */
  function nexa_cash_user_terminal_enabled(PDO $pdo, int $companyId, int $userId): bool {
    if (!nexa_cash_terminal_ready($pdo)) return !nexa_cash_user_control_disabled($pdo, $companyId);
    if ($userId <= 0) return false;
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];
    try {
      $st = $pdo->prepare('SELECT cash_terminal_enabled FROM users WHERE id = ? LIMIT 1');
      $st->execute([$userId]);
      return $cache[$userId] = ((int)$st->fetchColumn() === 1);
    } catch (Throwable $e) {
      return $cache[$userId] = false;
    }
  }
}

if (!function_exists('nexa_cash_requires_open_session')) {
  /** A forma de pagamento exige caixa aberto para ESTE usuário? */
  function nexa_cash_requires_open_session(PDO $pdo, int $companyId, array $paymentMethod, int $userId = 0): bool {
    return !empty($paymentMethod['require_open_cash']) && nexa_cash_user_terminal_enabled($pdo, $companyId, $userId);
  }
}

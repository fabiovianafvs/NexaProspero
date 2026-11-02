<?php
declare(strict_types=1);

/**
 * Preferências de período inicial das telas operacionais.
 *
 * A leitura é propositalmente sem DDL para manter o caminho normal das telas leve
 * e seguro também no contexto Multiempresa/F11. A gravação usa app_config, que já
 * faz parte do schema do Nexa.
 */

if (!function_exists('nexa_screen_performance_defaults')) {
  function nexa_screen_performance_defaults(): array {
    return [
      'orders_days' => 10,
      'movements_days' => 30,
      'receivables_days' => 30,
    ];
  }
}

if (!function_exists('nexa_screen_performance_sanitize_days')) {
  function nexa_screen_performance_sanitize_days($value, int $fallback): int {
    if ($value === null || $value === '') return $fallback;
    $days = filter_var($value, FILTER_VALIDATE_INT);
    if ($days === false) return $fallback;
    return max(0, min(3650, (int)$days));
  }
}

if (!function_exists('nexa_screen_performance_get')) {
  function nexa_screen_performance_get(PDO $pdo, int $companyId): array {
    $defaults = nexa_screen_performance_defaults();
    if ($companyId <= 0) return $defaults;
    try {
      $st = $pdo->prepare('SELECT cfg_json FROM app_config WHERE company_id=? AND cfg_key=? LIMIT 1');
      $st->execute([$companyId, 'screen_performance']);
      $json = trim((string)($st->fetchColumn() ?: ''));
      if ($json === '') return $defaults;
      $saved = json_decode($json, true);
      if (!is_array($saved)) return $defaults;
      return [
        'orders_days' => nexa_screen_performance_sanitize_days($saved['orders_days'] ?? null, $defaults['orders_days']),
        'movements_days' => nexa_screen_performance_sanitize_days($saved['movements_days'] ?? null, $defaults['movements_days']),
        'receivables_days' => nexa_screen_performance_sanitize_days($saved['receivables_days'] ?? null, $defaults['receivables_days']),
      ];
    } catch (Throwable $e) {
      return $defaults;
    }
  }
}

if (!function_exists('nexa_screen_performance_save')) {
  function nexa_screen_performance_save(PDO $pdo, int $companyId, array $input): array {
    if ($companyId <= 0) throw new RuntimeException('Empresa inválida para salvar as configurações.');
    $defaults = nexa_screen_performance_defaults();
    $value = [
      'orders_days' => nexa_screen_performance_sanitize_days($input['orders_days'] ?? null, $defaults['orders_days']),
      'movements_days' => nexa_screen_performance_sanitize_days($input['movements_days'] ?? null, $defaults['movements_days']),
      'receivables_days' => nexa_screen_performance_sanitize_days($input['receivables_days'] ?? null, $defaults['receivables_days']),
    ];
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('Não foi possível serializar as configurações.');
    $st = $pdo->prepare('REPLACE INTO app_config (company_id,cfg_key,cfg_json) VALUES (?,?,?)');
    $st->execute([$companyId, 'screen_performance', $json]);
    return $value;
  }
}

if (!function_exists('nexa_screen_performance_period')) {
  /**
   * 0 dias = somente hoje.
   * N dias = hoje + N-1 dias anteriores (10 => 10 datas de calendário, inclusive hoje).
   */
  function nexa_screen_performance_period(int $days, ?string $today = null): array {
    $days = max(0, min(3650, $days));
    $today = $today && preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) ? $today : date('Y-m-d');
    $to = new DateTimeImmutable($today . ' 12:00:00');
    $span = $days <= 0 ? 0 : ($days - 1);
    $from = $span > 0 ? $to->modify('-' . $span . ' days') : $to;
    return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
  }
}

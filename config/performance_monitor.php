<?php
declare(strict_types=1);

require_once __DIR__ . '/nexa_env.php';

if (!function_exists('nexa_performance_monitor_start')) {
  function nexa_performance_monitor_start(string $route, array $context = []): void {
    $now = hrtime(true);
    $GLOBALS['__nexa_perf'] = ['route'=>$route,'context'=>$context,'started_at'=>$now,'last_at'=>$now,'phases'=>[]];
    $thresholdMs = max(100, (int)(getenv('NEXA_SLOW_REQUEST_MS') ?: 1200));
    register_shutdown_function(static function () use ($thresholdMs): void {
      $state = $GLOBALS['__nexa_perf'] ?? null;
      if (!is_array($state)) return;
      $elapsedMs = round((hrtime(true) - (int)$state['started_at']) / 1_000_000, 1);
      $phases = (array)($state['phases'] ?? []);
      if (!headers_sent()) {
        $timing = ['nexa;dur=' . number_format($elapsedMs, 1, '.', '') . ';desc="Nexa PHP"'];
        foreach ($phases as $phase) {
          $name = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string)($phase['name'] ?? 'fase'));
          $timing[] = $name . ';dur=' . number_format((float)($phase['duration_ms'] ?? 0), 1, '.', '');
        }
        header('Server-Timing: ' . implode(', ', $timing));
      }
      if ($elapsedMs < $thresholdMs) return;
      $context = (array)($state['context'] ?? []);
      if (defined('COMPANY_ID')) $context['company_id'] = (int)COMPANY_ID;
      $payload = array_merge($context, [
        'route'=>(string)($state['route'] ?? ''),
        'elapsed_ms'=>$elapsedMs,
        'phases'=>$phases,
        'peak_memory_mb'=>round(memory_get_peak_usage(true)/1048576,1),
        'method'=>(string)($_SERVER['REQUEST_METHOD'] ?? 'CLI'),
        'uri'=>mb_substr((string)($_SERVER['REQUEST_URI'] ?? ''),0,300,'UTF-8'),
      ]);
      error_log('[Nexa Slow Request] ' . (json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '{}'));
    });
  }
}

if (!function_exists('nexa_performance_monitor_mark')) {
  function nexa_performance_monitor_mark(string $name): void {
    if (empty($GLOBALS['__nexa_perf']) || !is_array($GLOBALS['__nexa_perf'])) return;
    $now = hrtime(true);
    $last = (int)($GLOBALS['__nexa_perf']['last_at'] ?? $now);
    $started = (int)($GLOBALS['__nexa_perf']['started_at'] ?? $now);
    $GLOBALS['__nexa_perf']['phases'][] = [
      'name'=>$name,
      'duration_ms'=>round(($now-$last)/1_000_000,1),
      'total_ms'=>round(($now-$started)/1_000_000,1),
    ];
    $GLOBALS['__nexa_perf']['last_at']=$now;
  }
}

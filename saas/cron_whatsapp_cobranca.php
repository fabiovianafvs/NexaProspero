<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');

require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/saas_whatsapp_service.php';

try {
  $summary = saas_whatsapp_send_due_notices($pdo);
  echo 'WHATSAPP_BILLING_CRON' . PHP_EOL;
  foreach ($summary as $key => $value) {
    echo strtoupper((string)$key) . ': ' . (is_bool($value) ? ($value ? '1' : '0') : (string)$value) . PHP_EOL;
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo 'ERRO: ' . $e->getMessage() . PHP_EOL;
}

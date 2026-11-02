<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit("Somente CLI.\n");
}

require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/resellers.php';

try {
  $result = reseller_ensure_schema($pdo);
  $required = ['manual_note','granted_by','granted_reason','updated_at'];
  $missing = [];
  foreach ($required as $column) {
    if (!reseller_column_exists($pdo, 'subscriptions', $column)) $missing[] = $column;
  }
  if ($missing) {
    fwrite(STDERR, '[ERRO] Colunas ainda ausentes em subscriptions: ' . implode(', ', $missing) . PHP_EOL);
    foreach ((array)($result['errors'] ?? []) as $error) fwrite(STDERR, ' - ' . $error . PHP_EOL);
    exit(1);
  }
  echo "[OK] subscriptions.manual_note\n";
  echo "[OK] subscriptions.granted_by\n";
  echo "[OK] subscriptions.granted_reason\n";
  echo "[OK] subscriptions.updated_at\n";
  echo "[OK] Estrutura comercial de revendas revisada.\n";
} catch (Throwable $e) {
  fwrite(STDERR, '[ERRO] ' . $e->getMessage() . PHP_EOL);
  exit(1);
}

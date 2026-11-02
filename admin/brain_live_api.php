<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/brain.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
try {
  $companyId = (int)COMPANY_ID;
  $dashboard = brain_dashboard_get($pdo, $companyId);
  echo json_encode([
    'ok' => true,
    'generatedAt' => $dashboard['generatedAt'] ?? null,
    'snapshot' => $dashboard['snapshot'] ?? [],
    'cache' => $dashboard['_cache'] ?? [],
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(503);
  echo json_encode(['ok'=>false,'message'=>'Indicadores temporariamente indisponíveis.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

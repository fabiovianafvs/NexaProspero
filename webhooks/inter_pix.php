<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config/pdo.php'; require_once __DIR__ . '/../config/saas_billing.php'; require_once __DIR__ . '/../config/inter_pix.php';
saas_billing_ensure_schema($pdo);
try {
  $cfg = inter_pix_get_config($pdo, false) ?: [];
  $expectedToken = trim((string)($cfg['webhook_token'] ?? ''));
  $receivedToken = trim((string)($_GET['token'] ?? ''));
  if ($expectedToken !== '' && !hash_equals($expectedToken, $receivedToken)) { http_response_code(403); echo json_encode(['ok'=>false,'message'=>'Token do webhook inválido.']); exit; }
  $raw = file_get_contents('php://input') ?: '{}';
  $payload = json_decode($raw, true); if (!is_array($payload)) $payload = [];
  $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
  $result = saas_process_inter_webhook($pdo, $headers, $payload);
  echo json_encode(['ok'=>(bool)$result['processed'],'message'=>$result['message']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) { http_response_code(500); echo json_encode(['ok'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
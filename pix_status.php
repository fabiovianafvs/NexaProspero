<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/config/pdo.php';
require_once __DIR__ . '/config/saas_billing.php';
saas_billing_ensure_schema($pdo);
$companySlug = trim((string)($_GET['company'] ?? '')); $chargeId = (int)($_GET['charge'] ?? 0);
$company = $companySlug !== '' ? saas_find_company_by_slug_any_status($pdo, $companySlug) : null;
if (!$company || $chargeId <= 0) { echo json_encode(['ok' => false, 'message' => 'Dados inválidos.']); exit; }
try { $charge = saas_get_charge($pdo, $chargeId, (int)$company['id']); if (!$charge) throw new RuntimeException('Cobrança não encontrada.'); if (strtolower((string)$charge['status']) !== 'paid') $charge = saas_sync_charge_status($pdo, (int)$charge['id']); echo json_encode(['ok'=>true,'paid'=>strtolower((string)$charge['status'])==='paid','status'=>(string)$charge['status'],'label'=>saas_charge_status_label((string)$charge['status']),'paid_at'=>(string)($charge['paid_at'] ?? '')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); } catch (Throwable $e) { echo json_encode(['ok'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
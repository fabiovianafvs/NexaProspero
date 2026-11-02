<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/voucher_module.php';

header('Content-Type: application/json; charset=utf-8');
try {
  $scope = strtolower(trim((string)($_GET['scope'] ?? $_POST['scope'] ?? 'finance')));
  if (in_array($scope, ['pdv','pdv_resumido','pdv_mobile','pdv_checkout'], true)) {
    $companyId = function_exists('multi_company_operational_company_id') ? (int)multi_company_operational_company_id($pdo, 'sales_pdv_order') : (int)COMPANY_ID;
  } elseif (in_array($scope, ['comanda','comanda_mobile','comanda_checkout'], true)) {
    $companyId = function_exists('multi_company_operational_company_id') ? (int)multi_company_operational_company_id($pdo, 'sales_comandas') : (int)COMPANY_ID;
  } elseif ($scope === 'detailed_order') {
    $companyId = function_exists('multi_company_operational_company_id') ? (int)multi_company_operational_company_id($pdo, 'sales_detailed_order') : (int)COMPANY_ID;
  } else {
    $companyId = function_exists('multi_company_operational_company_id') ? (int)multi_company_operational_company_id($pdo, 'finance_read') : (int)COMPANY_ID;
  }
  $personId = (int)($_GET['person_id'] ?? $_POST['person_id'] ?? 0);
  if ($companyId <= 0) throw new RuntimeException('Empresa inválida.');
  if ($personId <= 0) throw new RuntimeException('Selecione um cliente cadastrado antes de usar Vale.');
  voucher_assert_ready($pdo);
  echo json_encode(['ok'=>true,'rows'=>voucher_available($pdo,$companyId,$personId)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}

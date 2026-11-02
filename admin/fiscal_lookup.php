<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/fiscal_module.php';
header('Content-Type: application/json; charset=utf-8');

try {
  $lookupCompanyId = function_exists('multi_company_operational_company_id')
    ? multi_company_operational_company_id($pdo, 'product_fiscal')
    : (int)COMPANY_ID;

  // V8: este endpoint é somente leitura e acompanha o contexto do cadastro de
  // produto. Sem módulo Fiscal na empresa alvo, não expõe dados/configurações.
  if ($lookupCompanyId <= 0 || !saas_company_has_module($pdo, $lookupCompanyId, 'fiscal')) {
    echo json_encode(['ok'=>false,'message'=>'Módulo Fiscal não habilitado para a empresa selecionada.'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($lookupCompanyId !== (int)COMPANY_ID
      && function_exists('multi_company_context_permission_allowed')
      && !multi_company_context_permission_allowed($pdo, 'produtos')) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'message'=>'Acesso não autorizado para Produtos na empresa selecionada.'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
  }

  fiscal_ensure_schema($pdo);
  $type = strtolower(trim((string)($_GET['type'] ?? '')));

  if ($type === 'ncm') {
    $ncm = preg_replace('/\D+/', '', (string)($_GET['ncm'] ?? ''));
    $row = fiscal_lookup_ncm($pdo, $lookupCompanyId, $ncm);
    $cests = $ncm !== '' ? fiscal_cests_by_ncm($pdo, $lookupCompanyId, $ncm, 200) : [];
    if ($row) $row['cests'] = $cests;
    echo json_encode(['ok'=>(bool)$row,'data'=>$row ?: null], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($type === 'cests_by_ncm') {
    $ncm = preg_replace('/\D+/', '', (string)($_GET['ncm'] ?? ''));
    $rows = fiscal_cests_by_ncm($pdo, $lookupCompanyId, $ncm, 200);
    echo json_encode(['ok'=>true,'data'=>$rows], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($type === 'nbs') {
    $code = preg_replace('/\D+/', '', (string)($_GET['code'] ?? ''));
    $row = null;
    if ($code !== '') {
      foreach (fiscal_search_nbs_codes($pdo, $lookupCompanyId, $code, 50) as $candidate) {
        $candidateCode = preg_replace('/\D+/', '', (string)($candidate['code'] ?? ''));
        if ($candidateCode === $code) { $row = $candidate; break; }
      }
    }
    echo json_encode(['ok'=>(bool)$row,'data'=>$row ?: null], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
  }

  echo json_encode(['ok'=>false,'message'=>'Tipo não suportado.'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}

<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/commercial_rules.php';
header('Content-Type: application/json; charset=utf-8');
try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Método inválido.');

  $salesChannel = strtolower(trim((string)($_POST['sales_channel'] ?? '')));
  $salesScope = $salesChannel === 'summary' ? 'sales_pdv_summary' : 'sales_pdv_order';
  $salesPermission = $salesChannel === 'summary' ? 'gestao_pdv_resumido' : 'gestao_pdv';
  $salesLabel = $salesChannel === 'summary' ? 'PDV Resumido' : 'Pedido PDV';
  $companyId = function_exists('multi_company_operational_company_id')
    ? (int)multi_company_operational_company_id($pdo, $salesScope)
    : (int)COMPANY_ID;
  $cross = function_exists('multi_company_scope_is_cross_company')
    && multi_company_scope_is_cross_company($pdo, $salesScope);

  if ($cross) {
    $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
    if (function_exists('multi_company_context_permission_allowed')
        && !multi_company_context_permission_allowed($pdo, $salesPermission)) {
      throw new RuntimeException('Sem permissão para operar o ' . $salesLabel . ' na empresa selecionada.');
    }
    if (!function_exists('multi_company_context_csrf_valid')
        || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? ''))) {
      throw new RuntimeException('Contexto Multiempresa expirou. Atualize a tela e tente novamente.');
    }
  }

  commercial_ensure_schema($pdo);
  $code=trim((string)($_POST['code'] ?? ''));
  $password=(string)($_POST['password'] ?? '');
  if($code===''||$password==='') throw new RuntimeException('Informe código do usuário e senha.');
  $uid=commercial_authorize_credit($pdo,$companyId,$code,$password);
  if($uid<=0) throw new RuntimeException('Usuário/senha inválidos ou usuário sem permissão para liberar limite excedido nesta empresa.');
  echo json_encode(['ok'=>true,'user_id'=>$uid],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch(Throwable $e) {
  http_response_code(422);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}

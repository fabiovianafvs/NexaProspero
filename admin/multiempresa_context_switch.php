<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap_public.php';
require_once __DIR__ . '/../config/multi_company.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function mc_v4_json(array $payload, int $status = 200): never {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  mc_v4_json(['ok' => false, 'reason' => 'metodo_nao_permitido'], 405);
}

$userId = (int)(auth_user_id() ?? 0);
$role = (string)($_SESSION['user_role'] ?? '');
// V13: a empresa de entrada é sempre a do login/endereço, mesmo se esta aba estiver em outra empresa.
$authCompanyId = (int)(auth_login_company_id() ?? 0);
$entryCompanyId = defined('MULTI_COMPANY_ENTRY_ID') ? (int)MULTI_COMPANY_ENTRY_ID : (int)COMPANY_ID;

if ($userId <= 0 || !in_array($role, ['company_admin','company_user'], true) || $authCompanyId !== $entryCompanyId) {
  mc_v4_json(['ok' => false, 'reason' => 'sessao_sem_acesso_empresa'], 403);
}

if (!multi_company_feature_enabled($pdo, $entryCompanyId)) {
  mc_v4_json(['ok' => false, 'reason' => 'multiempresa_desabilitado'], 403);
}

if (!multi_company_context_csrf_valid((string)($_POST['csrf'] ?? ''))) {
  mc_v4_json(['ok' => false, 'reason' => 'csrf_invalido'], 419);
}

$targetCompanyId = (int)($_POST['target_company_id'] ?? 0);
if ($targetCompanyId <= 0) {
  mc_v4_json(['ok' => false, 'reason' => 'empresa_destino_invalida'], 422);
}

// Voltar para a empresa de entrada significa retornar explicitamente ao fluxo
// legado daquela aba. Nenhum novo token é necessário.
if ($targetCompanyId === $entryCompanyId) {
  // Não revoga o token anterior: outra aba pode estar usando o mesmo token
  // após uma duplicação/abertura em nova aba. Esta aba apenas remove mc_ctx da URL.
  $company = multi_company_context_company_row($pdo, $entryCompanyId);
  mc_v4_json([
    'ok' => true,
    'token' => '',
    'company_id' => $entryCompanyId,
    'company_name' => (string)($company['name'] ?? ('Empresa ' . $entryCompanyId)),
    'operation_mode' => '',
    'reason' => 'legacy',
  ]);
}

$issued = multi_company_context_issue($pdo, $userId, $entryCompanyId, $targetCompanyId);
if (empty($issued['ok'])) {
  mc_v4_json(['ok' => false, 'reason' => (string)($issued['reason'] ?? 'acesso_negado')], 403);
}

$company = multi_company_context_company_row($pdo, $targetCompanyId);
$ctx = (array)($issued['context'] ?? []);
mc_v4_json([
  'ok' => true,
  'token' => (string)($issued['token'] ?? ''),
  'company_id' => $targetCompanyId,
  'company_name' => (string)($company['name'] ?? ('Empresa ' . $targetCompanyId)),
  'operation_mode' => (string)($ctx['operation_mode'] ?? ''),
  'reason' => 'ok',
]);

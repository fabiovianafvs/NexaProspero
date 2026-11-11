<?php
declare(strict_types=1);

// Cadastro de produtos: consulta dados pelo código de barras (JSON) e
// grava o token do Cosmos da empresa (só administrador).
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/product_gtin_lookup.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$out = static function (array $d, int $code = 200): void {
  http_response_code($code);
  echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
};

$cid = function_exists('multi_company_operational_company_id') ? (int)multi_company_operational_company_id($pdo, 'product_fiscal') : (int)COMPANY_ID;
if ($cid <= 0) $cid = (int)COMPANY_ID;
if ($cid !== (int)COMPANY_ID && function_exists('multi_company_context_permission_allowed') && !multi_company_context_permission_allowed($pdo, 'produtos')) {
  $out(['ok' => false, 'message' => 'Acesso não autorizado para Produtos na empresa selecionada.'], 403);
}

try {
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string)($_POST['action'] ?? '') === 'save_token') {
    if (!access_is_company_master()) $out(['ok' => false, 'message' => 'Somente o administrador da empresa pode alterar o token.'], 403);
    $csrf = (string)($_SESSION['nexa_gtin_csrf'] ?? '');
    if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $out(['ok' => false, 'message' => 'Sessão expirada. Atualize a página.'], 403);
    product_gtin_save_token($pdo, (int)COMPANY_ID, (string)($_POST['token'] ?? ''));
    $out(['ok' => true, 'message' => 'Token salvo.']);
  }

  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  $gtin = product_gtin_digits($_GET['gtin'] ?? '');
  if ($gtin === '') $out(['ok' => false, 'message' => 'Informe o código de barras.'], 400);
  $data = product_gtin_lookup($pdo, $cid, $gtin);
  if (!$data) {
    $cfg = product_gtin_config($pdo, $cid);
    $out(['ok' => false, 'not_found' => true, 'message' => 'Produto não encontrado nas bases consultadas' . ($cfg['cosmos_token'] === '' ? ' (configure o token do Cosmos para consultar o catálogo brasileiro completo).' : '.')]);
  }
  $out(['ok' => true, 'data' => $data]);
} catch (Throwable $e) {
  $out(['ok' => false, 'message' => $e->getMessage()], 422);
}

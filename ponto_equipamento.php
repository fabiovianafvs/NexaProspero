<?php
declare(strict_types=1);

// Nexa Ponto V9: endereço chamado pelos equipamentos (Control iD em modo online e outras marcas
// pela API). Rotas (.htaccess):
//   /equipamento/controlid/<código secreto>/<ação>.fcgi  → m=controlid, k=código, a=ação
//   /equipamento/api/<código secreto>/<ação>             → m=api, k=código, a=ação
// A empresa vem do endereço (subdomínio); o equipamento, do código secreto (guardado só como hash).
// Nunca usa sessão nem login: cada equipamento só identifica pessoas e responde liberar/negar.
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/pdo.php';
require_once __DIR__ . '/config/tenant.php';
require_once __DIR__ . '/saas/saas_guard.php';
require_once __DIR__ . '/config/saas_modules.php';
$company = tenant_resolve_company($pdo, (string)($_GET['company'] ?? ''), true);
$cid = tenant_company_id($company);

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function ponto_equip_json(int $code, array $body): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$modo = (string)($_GET['m'] ?? '');
$token = (string)($_GET['k'] ?? '');
$acao = strtolower((string)($_GET['a'] ?? ''));
if (!in_array($modo, ['controlid', 'api'], true) || !preg_match('/^[a-z_]{3,40}$/', $acao)) ponto_equip_json(404, ['ok' => false]);

if (!empty(saas_company_is_blocked($pdo, $company)['blocked']) || (function_exists('saas_company_has_module') && !saas_company_has_module($pdo, $cid, 'ponto_eletronico'))) {
  ponto_equip_json(403, ['ok' => false, 'mensagem' => 'Ponto eletrônico indisponível para esta empresa.']);
}

require_once __DIR__ . '/config/ponto_equipamentos.php';
if (!ponto_ready($pdo) || !ponto_v2_ready($pdo) || !ponto_v9_ready($pdo)) ponto_equip_json(503, ['ok' => false, 'mensagem' => 'Equipamentos ainda não instalados.']);

$eq = ponto_equip_por_token($pdo, $cid, $token, $modo === 'controlid' ? 'CONTROLID' : 'API');
if (!$eq) {
  usleep(300000);
  ponto_equip_json(404, ['ok' => false, 'mensagem' => 'Equipamento não encontrado ou desativado.']);
}

// Número de série conferido quando informado no cadastro (Control iD manda device_id).
if ($modo === 'controlid' && !empty($eq['equip_serial']) && isset($_REQUEST['device_id']) && (string)$_REQUEST['device_id'] !== (string)$eq['equip_serial']) {
  ponto_equip_json(403, ['result' => ['event' => 1, 'message' => 'Equipamento não autorizado']]);
}

$in = $_POST;
if (!$in) {
  $raw = (string)file_get_contents('php://input', false, null, 0, 65536);
  $j = json_decode($raw, true);
  if (is_array($j)) $in = $j;
}
if ($modo === 'api') $in += $_GET;

try {
  [$code, $body] = $modo === 'controlid'
    ? ponto_controlid_atender($pdo, $cid, $eq, preg_replace('/\.fcgi$/', '', $acao) ?? $acao, $in)
    : ponto_equip_api_atender($pdo, $cid, $eq, $acao, $in);
  ponto_equip_json($code, $body);
} catch (Throwable $e) {
  error_log('[Nexa Ponto equipamento] ' . $e->getMessage());
  ponto_equip_json(500, $modo === 'controlid' ? ['result' => ['event' => 6, 'message' => 'Erro no servidor']] : ['ok' => false, 'mensagem' => 'Erro no servidor.']);
}

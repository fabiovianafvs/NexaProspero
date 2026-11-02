<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/external_api.php';
require_once __DIR__ . '/../config/whatsapp_service.php';

external_api_handle_options();

/** @var PDO $pdo */
$auth = external_api_authenticate($pdo, 'whatsapp');
$companyId = (int)$auth['company_id'];
$method = external_api_method();

try {
  if ($method === 'GET') {
    $cfg = whatsapp_load_config($pdo, $companyId);
    $res = whatsapp_api_request($cfg, 'GET', '/status?company_id=' . rawurlencode(whatsapp_service_company_id($cfg, $companyId)));
    external_api_log($pdo, $companyId, 'whatsapp', $method, 200, [], $res);
    external_api_json_response($res);
  }

  if ($method !== 'POST') {
    external_api_json_response(['ok' => false, 'error' => 'Método não permitido. Use GET para status ou POST para enviar mensagem.'], 405);
  }

  $data = external_api_read_json();
  if (!$data) $data = $_POST;

  $to = (string)($data['to'] ?? $data['telefone'] ?? $data['whatsapp'] ?? '');
  $message = trim((string)($data['message'] ?? $data['mensagem'] ?? $data['texto'] ?? ''));
  $pedidoId = isset($data['pedido_id']) ? (int)$data['pedido_id'] : null;

  $destino = whatsapp_normalize_phone($to);
  if ($destino === '') external_api_json_response(['ok' => false, 'error' => 'Informe o número de destino em "to".'], 400);
  if ($message === '') external_api_json_response(['ok' => false, 'error' => 'Informe a mensagem em "message".'], 400);

  $cfg = whatsapp_load_config($pdo, $companyId);
  $cfg['timeout_seconds'] = max(30, (int)($cfg['timeout_seconds'] ?? 30));

  // API externa envia pela conexão configurada no Admin, mesmo que a automação do checkout esteja desativada.
  $res = whatsapp_api_request($cfg, 'POST', '/send', [
    'company_id' => whatsapp_service_company_id($cfg, $companyId),
    'to' => $destino,
    'message' => $message,
  ]);

  $ok = !empty($res['ok']);
  whatsapp_log($pdo, $companyId, $pedidoId, 'external_api', $destino, $message, $ok ? 'sent' : 'error', $ok ? '' : (string)($res['error'] ?? 'Falha no envio'), $res);

  $payload = [
    'ok' => $ok,
    'message' => $ok ? 'Mensagem enviada.' : 'Não foi possível enviar a mensagem.',
    'destino' => $destino,
    'service_response' => $res,
  ];
  external_api_log($pdo, $companyId, 'whatsapp', $method, $ok ? 200 : 500, $data, $payload);
  external_api_json_response($payload, $ok ? 200 : 500);
} catch (Throwable $e) {
  $payload = ['ok' => false, 'error' => $e->getMessage()];
  external_api_log($pdo, $companyId ?? null, 'whatsapp', $method ?? 'POST', 500, $data ?? [], $payload);
  external_api_json_response($payload, 500);
}

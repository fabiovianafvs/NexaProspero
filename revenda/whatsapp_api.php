<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$cfg = reseller_whatsapp_load_config($pdo, RESELLER_COMPANY_ID);
$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'status');

try {
  if ($action === 'test') {
    $to = (string)($_POST['to'] ?? $cfg['test_phone'] ?? '');
    $res = reseller_whatsapp_send_message(
      $pdo,
      RESELLER_COMPANY_ID,
      $to,
      "Teste de conexão WhatsApp da revenda\nEnviado em " . date('d/m/Y H:i'),
      'test'
    );
    echo json_encode([
      'ok' => !empty($res['ok']),
      'message' => !empty($res['ok']) ? 'Mensagem de teste enviada.' : 'Não foi possível enviar a mensagem de teste.',
      'result' => $res,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ((string)($cfg['provider'] ?? '') !== 'unofficial') {
    throw new RuntimeException('Status e QR Code estão disponíveis somente para a API Não Oficial.');
  }

  $instance = (string)$cfg['unofficial_instance_id'];

  if ($action === 'status') {
    $res = saas_whatsapp_unofficial_request($cfg, 'GET', '/status?company_id=' . rawurlencode($instance));
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'qr') {
    $res = saas_whatsapp_unofficial_request($cfg, 'GET', '/qr?company_id=' . rawurlencode($instance));
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'restart') {
    $res = saas_whatsapp_unofficial_request($cfg, 'POST', '/admin/session/restart', ['company_id' => $instance]);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  // Mantido para compatibilidade com a tela antiga da revenda.
  if ($action === 'new_qr') {
    $res = saas_whatsapp_unofficial_request($cfg, 'POST', '/admin/session/new-qr', ['company_id' => $instance]);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'reconnect') {
    $oldInstanceId = $instance;
    $logout = null;
    try {
      $logout = saas_whatsapp_unofficial_request($cfg, 'POST', '/logout', ['company_id' => $oldInstanceId]);
    } catch (Throwable $e) {
      $logout = ['ok' => false, 'error' => $e->getMessage()];
    }

    // Rotaciona SOMENTE a instância desta revenda.
    $rotation = reseller_whatsapp_rotate_unofficial_instance_id($pdo, RESELLER_COMPANY_ID);
    $cfg = $rotation['config'];
    $newInstanceId = (string)$rotation['new_instance_id'];

    $tokenSync = null;
    $apiToken = trim((string)($cfg['api_token'] ?? ''));
    if ($apiToken !== '') {
      $tokenSync = saas_whatsapp_register_unofficial_token($cfg, (int)$newInstanceId, $apiToken);
    }

    usleep(1800000);
    try {
      $qr = saas_whatsapp_unofficial_request($cfg, 'GET', '/qr?company_id=' . rawurlencode($newInstanceId));
    } catch (Throwable $e) {
      $qr = ['ok' => false, 'error' => $e->getMessage()];
    }

    $qr['old_instance_id'] = $oldInstanceId;
    $qr['new_instance_id'] = $newInstanceId;
    $qr['message'] = 'A conexão da revenda foi migrada de company-' . $oldInstanceId . ' para company-' . $newInstanceId . '. Leia o novo QR Code para conectar novamente.';
    $qr['repair'] = [
      'logout' => $logout,
      'token_sync' => $tokenSync,
      'old_instance_id' => $oldInstanceId,
      'new_instance_id' => $newInstanceId,
      'message' => $qr['message'],
    ];
    echo json_encode($qr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'logout') {
    $res = saas_whatsapp_unofficial_request($cfg, 'POST', '/logout', ['company_id' => $instance]);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  throw new RuntimeException('Ação inválida.');
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

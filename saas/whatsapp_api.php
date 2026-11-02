<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/saas_whatsapp_service.php';

header('Content-Type: application/json; charset=utf-8');

$cfg = saas_whatsapp_load_config($pdo);
$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'status');

try {
  if ($action === 'status') {
    if (($cfg['provider'] ?? '') !== 'unofficial') {
      echo json_encode(['ok' => false, 'error' => 'QR Code disponível somente para API Não Oficial.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    }
    $res = saas_whatsapp_unofficial_request($cfg, 'GET', '/status?company_id=' . rawurlencode(saas_whatsapp_unofficial_instance_id($cfg)));
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'qr') {
    if (($cfg['provider'] ?? '') !== 'unofficial') {
      echo json_encode(['ok' => false, 'error' => 'QR Code disponível somente para API Não Oficial.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    }
    $res = saas_whatsapp_unofficial_request($cfg, 'GET', '/qr?company_id=' . rawurlencode(saas_whatsapp_unofficial_instance_id($cfg)));
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'logout') {
    if (($cfg['provider'] ?? '') !== 'unofficial') {
      echo json_encode(['ok' => false, 'error' => 'Desconectar QR Code disponível somente para API Não Oficial.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    }
    $res = saas_whatsapp_unofficial_request($cfg, 'POST', '/logout', ['company_id' => saas_whatsapp_unofficial_instance_id($cfg)]);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'restart') {
    if (($cfg['provider'] ?? '') !== 'unofficial') {
      echo json_encode(['ok' => false, 'error' => 'Reinício de sessão disponível somente para API Não Oficial.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    }
    $instanceId = saas_whatsapp_unofficial_instance_id($cfg);
    $res = saas_whatsapp_unofficial_request($cfg, 'POST', '/admin/session/restart', ['company_id' => $instanceId]);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'reconnect') {
    if (($cfg['provider'] ?? '') !== 'unofficial') {
      echo json_encode(['ok' => false, 'error' => 'Reconectar QR Code disponível somente para API Não Oficial.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    }
    $oldInstanceId = saas_whatsapp_unofficial_instance_id($cfg);
    $logout = null;
    try {
      $logout = saas_whatsapp_unofficial_request($cfg, 'POST', '/logout', ['company_id' => $oldInstanceId]);
    } catch (Throwable $e) {
      $logout = ['ok' => false, 'error' => $e->getMessage()];
    }

    $rotation = saas_whatsapp_rotate_unofficial_instance_id($pdo);
    $cfg = $rotation['config'];
    $instanceId = (string)$rotation['new_instance_id'];

    $tokenSync = null;
    $apiToken = trim((string)($cfg['api_token'] ?? ''));
    if ($apiToken !== '') {
      $tokenSync = saas_whatsapp_register_unofficial_token($cfg, (int)$instanceId, $apiToken);
    }

    usleep(1800000);
    try {
      $qr = saas_whatsapp_unofficial_request($cfg, 'GET', '/qr?company_id=' . rawurlencode($instanceId));
    } catch (Throwable $e) {
      $qr = ['ok' => false, 'error' => $e->getMessage()];
    }
    $qr['old_instance_id'] = $oldInstanceId;
    $qr['new_instance_id'] = $instanceId;
    $qr['message'] = 'A conexão central foi migrada de company-' . $oldInstanceId . ' para company-' . $instanceId . '. Leia o novo QR Code para conectar novamente.';
    $qr['repair'] = [
      'logout' => $logout,
      'token_sync' => $tokenSync,
      'old_instance_id' => $oldInstanceId,
      'new_instance_id' => $instanceId,
      'message' => $qr['message'],
    ];
    echo json_encode($qr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'test') {
    $to = (string)($_POST['to'] ?? $cfg['test_phone'] ?? '');
    $msg = "Teste de conexão WhatsApp SaaS - Nexa Próspero\nEnviado em " . date('d/m/Y H:i');
    $res = saas_whatsapp_send_message($pdo, $to, $msg, 'test', null, null, null, null, []);
    echo json_encode([
      'ok' => !empty($res['ok']),
      'message' => !empty($res['ok']) ? 'Mensagem de teste enviada.' : 'Não foi possível enviar a mensagem de teste.',
      'result' => $res,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  echo json_encode(['ok' => false, 'error' => 'Ação inválida.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

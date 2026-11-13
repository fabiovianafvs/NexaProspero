<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/whatsapp_service.php';

header('Content-Type: application/json; charset=utf-8');

$waCompanyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'whatsapp_config')
  : (int)COMPANY_ID;
$waCrossContext = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'whatsapp_config');
$cfg = whatsapp_load_config($pdo, $waCompanyId, true, true);
$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'status');
$serviceCompanyId = whatsapp_service_company_id($cfg, $waCompanyId);

if ($waCrossContext && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  if (!function_exists('multi_company_context_csrf_valid')
      || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'V11.22.1: sessão de segurança expirada. Atualize a Central WhatsApp antes de executar esta ação.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
}

function whatsapp_admin_friendly_error(string $error): string {
  if (stripos($error, 'browser is already running') !== false || stripos($error, 'userDataDir') !== false || stripos($error, 'Use a different') !== false) {
    return 'A sessão desta empresa ficou presa no navegador do WhatsApp. Clique em Corrigir conexão para desconectar esta sessão e gerar um novo QR Code. Se persistir, reinicie o serviço Node/PM2 do WhatsApp no servidor.';
  }
  if (stripos($error, 'detached Frame') !== false || stripos($error, 'detached frame') !== false) {
    return 'O navegador interno do WhatsApp perdeu o contexto da página. O serviço tenta reconstruir a sessão automaticamente no próximo envio, sem polling contínuo. Se persistir, use Corrigir conexão.';
  }
  return $error;
}

try {
  if ($action === 'peek_status') {
    // Consulta leve para telas que apenas exibem o indicador de conexão.
    // O serviço atual expõe as sessões já carregadas em /admin/sessions.
    // Importante: esta rota NÃO cria/inicializa uma nova sessão Chromium.
    $status = 'disconnected';
    $technical = 'not_loaded';
    $lastError = '';
    $updatedAt = '';
    $found = false;

    try {
      $list = whatsapp_api_request($cfg, 'GET', '/admin/sessions');
      foreach ((array)($list['items'] ?? []) as $item) {
        if (!is_array($item)) continue;
        if ((string)($item['company_id'] ?? '') !== $serviceCompanyId) continue;
        $found = true;
        $technical = strtolower(trim((string)($item['status'] ?? 'disconnected')));
        $status = $technical === 'connected' ? 'connected' : 'disconnected';
        $lastError = (string)($item['last_error'] ?? '');
        $updatedAt = (string)($item['updated_at'] ?? '');
        break;
      }
    } catch (Throwable $sessionsError) {
      // Compatibilidade com instalações antigas do serviço que ainda não
      // possuem /admin/sessions. Nesse caso consulta a mesma rota usada pela
      // própria tela Admin > WhatsApp, evitando divergência visual de status.
      $res = whatsapp_api_request($cfg, 'GET', '/status?company_id=' . rawurlencode($serviceCompanyId));
      $technical = strtolower(trim((string)($res['status'] ?? 'disconnected')));
      $connected = !empty($res['ready']) || !empty($res['connected']) || in_array($technical, ['ready', 'connected'], true);
      $status = $connected ? 'connected' : 'disconnected';
      $lastError = (string)($res['lastError'] ?? $res['last_error'] ?? '');
      $updatedAt = (string)($res['updated_at'] ?? '');
      $found = true;
    }

    echo json_encode([
      'ok' => true,
      'status' => $status,
      'technical_status' => $technical,
      'connected' => $status === 'connected',
      'found' => $found,
      'service_company_id' => $serviceCompanyId,
      'last_error' => $lastError,
      'updated_at' => $updatedAt,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'status') {
    $res = whatsapp_api_request($cfg, 'GET', '/status?company_id=' . rawurlencode($serviceCompanyId));
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'qr') {
    $res = whatsapp_api_request($cfg, 'GET', '/qr?company_id=' . rawurlencode($serviceCompanyId));
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'logout') {
    $res = whatsapp_api_request($cfg, 'POST', '/logout', ['company_id' => $serviceCompanyId]);
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'reconnect') {
    $oldServiceCompanyId = $serviceCompanyId;
    $logout = null;
    try {
      $logout = whatsapp_api_request($cfg, 'POST', '/logout', ['company_id' => $oldServiceCompanyId]);
    } catch (Throwable $e) {
      $logout = ['ok' => false, 'error' => whatsapp_admin_friendly_error($e->getMessage())];
    }

    $tokenRetire = null;
    try {
      $tokenRetire = whatsapp_api_request($cfg, 'POST', '/tokens/remove', ['company_id' => $oldServiceCompanyId]);
    } catch (Throwable $e) {
      $tokenRetire = ['ok' => false, 'error' => whatsapp_admin_friendly_error($e->getMessage())];
    }

    // Em vez de insistir na pasta travada session-company-X, cria um novo identificador
    // usado apenas no serviço WhatsApp Web. O ID real da empresa no Nexa não muda.
    $rotation = whatsapp_rotate_service_company_id($pdo, $waCompanyId);
    $cfg = $rotation['config'];
    $serviceCompanyId = (string)$rotation['new_service_company_id'];

    $tokenSync = null;
    $apiToken = trim((string)($cfg['api_token'] ?? ''));
    if ($apiToken !== '') {
      $tokenSync = whatsapp_register_company_token($cfg, $serviceCompanyId, $apiToken);
    }

    // Dá tempo para o Puppeteer/Chrome liberar o perfil antigo e iniciar o novo.
    usleep(1800000);

    try {
      $qr = whatsapp_api_request($cfg, 'GET', '/qr?company_id=' . rawurlencode($serviceCompanyId));
    } catch (Throwable $e) {
      $qr = ['ok' => false, 'error' => whatsapp_admin_friendly_error($e->getMessage())];
    }

    if (isset($qr['lastError']) && is_string($qr['lastError'])) {
      $qr['lastErrorFriendly'] = whatsapp_admin_friendly_error($qr['lastError']);
    }
    $qr['repair'] = [
      'logout' => $logout,
      'token_sync' => $tokenSync,
      'old_token_retire' => $tokenRetire,
      'old_service_company_id' => $oldServiceCompanyId,
      'new_service_company_id' => $serviceCompanyId,
      'message' => 'A conexão foi migrada de company-' . $oldServiceCompanyId . ' para company-' . $serviceCompanyId . '. Leia o novo QR Code para conectar novamente.',
    ];
    echo json_encode($qr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'test') {
    $to = (string)($_POST['to'] ?? $cfg['store_phone'] ?? '');
    $destino = whatsapp_normalize_phone($to);
    if ($destino === '') {
      echo json_encode(['ok' => false, 'error' => 'Informe um número de WhatsApp válido para o teste.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    }

    $msg = "Teste de conexão WhatsApp - Nexa Próspero\nEnviado em " . date('d/m/Y H:i');

    try {
      // O teste deve funcionar mesmo que a automação ainda esteja desmarcada.
      // A opção "Ativar automação" controla os envios automáticos de pedido/status.
      $res = whatsapp_api_request($cfg, 'POST', '/send', [
        'company_id' => $serviceCompanyId,
        'to' => $destino,
        'message' => $msg,
      ]);
      $ok = !empty($res['ok']);
      whatsapp_log($pdo, $waCompanyId, null, 'test', $destino, $msg, $ok ? 'sent' : 'error', $ok ? '' : (string)($res['error'] ?? 'Falha no envio'), $res);
      echo json_encode([
        'ok' => $ok,
        'message' => $ok ? 'Mensagem de teste enviada.' : 'Não foi possível enviar a mensagem.',
        'service_response' => $res,
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    } catch (Throwable $e) {
      whatsapp_log($pdo, $waCompanyId, null, 'test', $destino, $msg, 'error', $e->getMessage());
      echo json_encode([
        'ok' => false,
        'error' => whatsapp_admin_friendly_error($e->getMessage()),
        'message' => 'Não foi possível enviar a mensagem de teste.',
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    }
  }

  echo json_encode(['ok' => false, 'error' => 'Ação inválida.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => whatsapp_admin_friendly_error($e->getMessage())], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

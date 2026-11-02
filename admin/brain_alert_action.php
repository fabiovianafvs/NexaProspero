<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/brain.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Método não permitido.');
}

$companyId = (int)COMPANY_ID;
$userId = (int)(auth_user_id() ?? 0);
$message = '';
$type = 'ok';
$returnUrl = brain_actions_safe_url((string)($_POST['return'] ?? 'brain.php'),'brain.php');
$returnPage = strtolower(basename((string)(parse_url($returnUrl,PHP_URL_PATH) ?: '')));
if (!in_array($returnPage,['brain.php','brain_alertas.php'],true)) $returnUrl = 'brain.php';

try {
  brain_actions_csrf_check((string)($_POST['csrf'] ?? ''), 'brain_actions');
  $action = trim((string)($_POST['action'] ?? ''));
  if ($action === 'evaluate') {
    $result = brain_actions_evaluate_company($pdo,$companyId,'manual_web');
    $message = 'Análise concluída: ' . (int)$result['alerts_detected'] . ' alerta(s) detectado(s), '
      . (int)$result['created'] . ' criado(s) e ' . (int)$result['resolved'] . ' resolvido(s).';
    if (!empty($result['errors'])) { $message .= ' Alguns módulos apresentaram avisos.'; $type = 'warn'; }
  } else {
    $alertId = (int)($_POST['alert_id'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));
    $result = brain_actions_transition($pdo,$companyId,$alertId,$action,$userId,$note);
    $message = match ($action) {
      'resolve' => 'Alerta marcado como resolvido.',
      'snooze' => 'Alerta adiado por 24 horas.',
      'ignore' => 'Alerta ignorado. Ele não será reaberto automaticamente.',
      'reopen' => 'Alerta reaberto.',
      default => 'Ação concluída.',
    };
  }
  try { brain_dashboard_rebuild_cache($pdo,$companyId); } catch (Throwable $e) {
    $message .= ' O cache será atualizado pelo worker.';
    $type = $type === 'ok' ? 'warn' : $type;
  }
} catch (Throwable $e) {
  $message = $e->getMessage();
  $type = 'err';
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$_SESSION['brain_flash'] = ['type'=>$type,'message'=>$message];
header('Location: ' . $returnUrl);
exit;

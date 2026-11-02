<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap_public.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/saas_modules.php';
require_once __DIR__ . '/../config/access_control.php';

require_company_admin(COMPANY_ID);
saas_require_admin_page_module($pdo, (int)COMPANY_ID, 'parametros.php');
access_require_page($pdo, (int)COMPANY_ID, 'parametros.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  header('Location: parametros.php');
  exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
$expected = session_status() === PHP_SESSION_ACTIVE ? (string)($_SESSION['nexa_cert_alert_config_csrf'] ?? '') : '';
$sent = (string)($_POST['csrf_token'] ?? '');
if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
  header('Location: parametros.php?cert_alert_error=csrf');
  exit;
}

$days = max(1, min(90, (int)($_POST['warning_days'] ?? 10)));
$enabled = !empty($_POST['enabled']);
$url = trim((string)($_POST['purchase_url'] ?? ''));
if ($url !== '' && !preg_match('~^https?://~i', $url)) $url = 'https://' . ltrim($url, '/');
if ($url !== '') {
  $valid = filter_var($url, FILTER_VALIDATE_URL);
  $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
  if ($valid === false || !in_array($scheme, ['http','https'], true)) {
    header('Location: parametros.php?cert_alert_error=url');
    exit;
  }
  if (strlen($url) > 500) $url = substr($url, 0, 500);
}

$payload = [
  'enabled' => $enabled,
  'warning_days' => $days,
  'purchase_url' => $url,
];

try {
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  $st = $pdo->prepare("INSERT INTO app_config (company_id,cfg_key,cfg_json,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE cfg_json=VALUES(cfg_json), updated_at=NOW()");
  $st->execute([(int)COMPANY_ID, 'fiscal_certificate_alert', $json]);
  header('Location: parametros.php?cert_alert_saved=1');
  exit;
} catch (Throwable $e) {
  error_log('fiscal_certificado_alerta_config.php: ' . $e->getMessage());
  header('Location: parametros.php?cert_alert_error=save');
  exit;
}

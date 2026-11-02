<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Permite a validação pela landing pública e pelos subdomínios oficiais.
$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($origin !== '' && preg_match('~^https://(?:[a-z0-9-]+\.)*nexaprospero\.com\.br$~i', $origin)) {
  header('Access-Control-Allow-Origin: ' . $origin);
  header('Vary: Origin');
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
  header('Access-Control-Allow-Methods: GET, OPTIONS');
  header('Access-Control-Allow-Headers: Accept, Content-Type');
  http_response_code(204);
  exit;
}

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/company_identity.php';

function subdomain_json(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$name = trim((string)($_GET['name'] ?? ''));
if ($name === '') {
  subdomain_json([
    'ok' => false,
    'available' => false,
    'subdomain' => '',
    'message' => 'Informe o nome fantasia.',
  ], 422);
}
if ((function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 180) {
  subdomain_json([
    'ok' => false,
    'available' => false,
    'subdomain' => '',
    'message' => 'O nome fantasia é muito longo.',
  ], 422);
}

try {
  $status = company_subdomain_availability($pdo, $name);
  $roots = function_exists('configured_root_domains') ? configured_root_domains() : [];
  $rootDomain = (string)($roots[0] ?? 'nexaprospero.com.br');
  $fullHost = $status['subdomain'] !== '' ? $status['subdomain'] . '.' . $rootDomain : '';

  subdomain_json([
    'ok' => true,
    'available' => $status['available'],
    'subdomain' => $status['subdomain'],
    'host' => $fullHost,
    'url' => $fullHost !== '' ? 'https://' . $fullHost : '',
    'message' => $status['message'],
  ], $status['available'] ? 200 : 409);
} catch (Throwable $e) {
  subdomain_json([
    'ok' => false,
    'available' => false,
    'subdomain' => company_subdomain_normalize($name),
    'message' => 'Não foi possível validar o endereço agora. Tente novamente.',
  ], 500);
}

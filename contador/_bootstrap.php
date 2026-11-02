<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/accounting_module.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_name('PROSPERO_CONTADOR');
  session_set_cookie_params([
    'lifetime'=>0,
    'path'=>'/',
    'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off',
    'httponly'=>true,
    'samesite'=>'Lax',
  ]);
  session_start();
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
try { accounting_ensure_schema($pdo); } catch (Throwable $e) { http_response_code(500); echo 'Não foi possível inicializar a Central do Contador.'; exit; }

function accountant_e($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function accountant_id(): int { return (int)($_SESSION['accountant_id'] ?? 0); }
function accountant_name(): string { return (string)($_SESSION['accountant_name'] ?? 'Contador'); }
function accountant_require_login(): int {
  $id=accountant_id();
  if ($id<=0) { header('Location: login.php'); exit; }
  return $id;
}
function accountant_csrf(): string {
  if (empty($_SESSION['accountant_csrf'])) $_SESSION['accountant_csrf']=bin2hex(random_bytes(24));
  return (string)$_SESSION['accountant_csrf'];
}
function accountant_check_csrf(string $token): void {
  if ($token==='' || !hash_equals(accountant_csrf(),$token)) throw new RuntimeException('A sessão expirou. Atualize a página e tente novamente.');
}

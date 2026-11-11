<?php
declare(strict_types=1);

function auth_start(): void {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }

  // Evita cache em páginas autenticadas
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Cache-Control: post-check=0, pre-check=0', false);
  header('Pragma: no-cache');
  header('Expires: 0');
}

function auth_login(array $user): void {
  auth_start();
  $_SESSION['user_id'] = (int)$user['id'];
  $_SESSION['user_role'] = (string)$user['role'];
  $_SESSION['user_company_id'] = isset($user['company_id']) ? (int)$user['company_id'] : null;
}

function auth_logout(): void {
  auth_start();
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
      $params['path'], $params['domain'], $params['secure'], $params['httponly']
    );
  }
  session_destroy();
}

function auth_user_id(): ?int {
  auth_start();
  return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function auth_role(): string {
  auth_start();
  $role = (string)($_SESSION['user_role'] ?? '');
  // Multiempresa Isolado (F11): na empresa selecionada o papel é o definido em
  // Acessos multiempresa (Administrador nesta empresa ou usuário com perfil).
  if (in_array($role, ['company_admin', 'company_user'], true)
      && defined('MULTI_COMPANY_SWAP_ACTIVE') && MULTI_COMPANY_SWAP_ACTIVE === true
      && defined('MULTI_COMPANY_SWAP_IS_ADMIN')) {
    return MULTI_COMPANY_SWAP_IS_ADMIN === true ? 'company_admin' : 'company_user';
  }
  return $role;
}

function auth_company_id(): ?int {
  $v = auth_login_company_id();
  // Multiempresa Isolado (F11): nesta aba o usuário trabalha na empresa selecionada,
  // como se tivesse entrado nela. A sessão continua com a empresa do login.
  if ($v !== null && defined('MULTI_COMPANY_SWAP_ACTIVE') && MULTI_COMPANY_SWAP_ACTIVE === true
      && defined('MULTI_COMPANY_ENTRY_ID') && $v === (int)MULTI_COMPANY_ENTRY_ID && defined('COMPANY_ID')) {
    return (int)COMPANY_ID;
  }
  return $v;
}

/** Empresa em que o usuário fez login (ignora a troca de empresa do F11). */
function auth_login_company_id(): ?int {
  auth_start();
  if (!array_key_exists('user_company_id', $_SESSION)) return null;
  $v = $_SESSION['user_company_id'];
  return $v === null ? null : (int)$v;
}

function require_company_admin(int $companyId): void {
  auth_start();
  $role = auth_role();
  $uid = auth_user_id();
  $cid = auth_company_id();

  // O administrador master da plataforma pode acessar o painel de qualquer empresa.
  if ($uid && $role === 'platform_admin') {
    return;
  }

  if (!$uid || !in_array($role, ['company_admin', 'company_user'], true) || (int)$cid !== (int)$companyId) {
    header('Location: login.php');
    exit;
  }
}

function require_platform_admin(): void {
  auth_start();
  $role = auth_role();
  $uid = auth_user_id();

  if (!$uid || $role !== 'platform_admin') {
    header('Location: /saas/login.php');
    exit;
  }
}


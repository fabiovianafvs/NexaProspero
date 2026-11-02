<?php
declare(strict_types=1);

if (!function_exists('support_ensure_schema')) {
  function support_ensure_schema(PDO $pdo): void {
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS saas_support_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_by_user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_saas_support_users_email (email),
  KEY idx_saas_support_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS saas_support_whatsapp_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  support_user_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NULL,
  session_company_id VARCHAR(32) NOT NULL,
  action VARCHAR(40) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  detail VARCHAR(500) NULL,
  ip_address VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_support_wa_user (support_user_id),
  KEY idx_support_wa_company (company_id),
  KEY idx_support_wa_session (session_company_id),
  KEY idx_support_wa_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
  }
}

if (!function_exists('support_session_start')) {
  function support_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    ini_set('session.use_strict_mode', '1');
    session_name('NEXA_SUPPORT_SESSID');
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
      || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
      'lifetime' => 0,
      'path' => '/suporte',
      'secure' => $secure,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
    session_start();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
  }
}

if (!function_exists('support_login')) {
  function support_login(array $user): void {
    support_session_start();
    session_regenerate_id(true);
    $_SESSION['support_user_id'] = (int)$user['id'];
    $_SESSION['support_user_name'] = (string)$user['name'];
    $_SESSION['support_user_email'] = (string)$user['email'];
  }
}

if (!function_exists('support_logout')) {
  function support_logout(): void {
    support_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
      $params = session_get_cookie_params();
      setcookie(session_name(), '', time() - 42000, [
        'path' => $params['path'] ?: '/suporte',
        'domain' => $params['domain'] ?: '',
        'secure' => (bool)$params['secure'],
        'httponly' => (bool)$params['httponly'],
        'samesite' => 'Lax',
      ]);
    }
    session_destroy();
  }
}

if (!function_exists('support_user_id')) {
  function support_user_id(): ?int {
    support_session_start();
    return isset($_SESSION['support_user_id']) ? (int)$_SESSION['support_user_id'] : null;
  }
}

if (!function_exists('support_user_name')) {
  function support_user_name(): string {
    support_session_start();
    return trim((string)($_SESSION['support_user_name'] ?? ''));
  }
}

if (!function_exists('require_support_user')) {
  function require_support_user(PDO $pdo): array {
    support_session_start();
    $id = support_user_id();
    if (!$id) {
      header('Location: /suporte/login.php');
      exit;
    }

    support_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT id, name, email, is_active FROM saas_support_users WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if (!$user || (int)$user['is_active'] !== 1) {
      support_logout();
      header('Location: /suporte/login.php?inactive=1');
      exit;
    }

    $_SESSION['support_user_name'] = (string)$user['name'];
    $_SESSION['support_user_email'] = (string)$user['email'];
    return $user;
  }
}

if (!function_exists('support_digits')) {
  function support_digits(string $value): string {
    return preg_replace('/\D+/', '', $value) ?? '';
  }
}

if (!function_exists('support_cnpj_format')) {
  function support_cnpj_format(string $value): string {
    $d = support_digits($value);
    if (strlen($d) !== 14) return $value !== '' ? $value : '—';
    return substr($d,0,2) . '.' . substr($d,2,3) . '.' . substr($d,5,3) . '/' . substr($d,8,4) . '-' . substr($d,12,2);
  }
}


if (!function_exists('support_csrf_token')) {
  function support_csrf_token(): string {
    support_session_start();
    if (empty($_SESSION['support_csrf_token']) || !is_string($_SESSION['support_csrf_token'])) {
      $_SESSION['support_csrf_token'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['support_csrf_token'];
  }
}

if (!function_exists('support_verify_csrf')) {
  function support_verify_csrf(string $token): bool {
    support_session_start();
    $expected = (string)($_SESSION['support_csrf_token'] ?? '');
    return $expected !== '' && $token !== '' && hash_equals($expected, $token);
  }
}

if (!function_exists('support_whatsapp_audit')) {
  function support_whatsapp_audit(PDO $pdo, int $supportUserId, ?int $companyId, string $sessionCompanyId, string $action, bool $success, string $detail = ''): void {
    try {
      support_ensure_schema($pdo);
      $ip = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
      if (str_contains($ip, ',')) $ip = trim(explode(',', $ip)[0]);
      $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
      $st = $pdo->prepare('INSERT INTO saas_support_whatsapp_audit (support_user_id, company_id, session_company_id, action, success, detail, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
      $st->execute([
        $supportUserId,
        $companyId ?: null,
        mb_substr($sessionCompanyId, 0, 32),
        mb_substr($action, 0, 40),
        $success ? 1 : 0,
        $detail !== '' ? mb_substr($detail, 0, 500) : null,
        $ip !== '' ? mb_substr($ip, 0, 64) : null,
        $ua !== '' ? mb_substr($ua, 0, 255) : null,
      ]);
    } catch (Throwable $e) {}
  }
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/pdo.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/config_store.php';
require_once __DIR__ . '/saas_modules.php';

function external_api_default_config(): array {
  return [
    'enabled' => false,
    'token' => '',
    'allow_whatsapp' => true,
    'allow_products' => true,
    'allow_orders' => true,
  ];
}

function external_api_load_config(PDO $pdo, int $companyId): array {
  $cfg = external_api_default_config();
  try {
    $over = config_store_get($pdo, $companyId, 'external_api');
    if (is_array($over)) $cfg = config_deep_merge($cfg, $over);
  } catch (Throwable $e) {}

  $cfg['enabled'] = !empty($cfg['enabled']);
  $cfg['token'] = trim((string)($cfg['token'] ?? ''));
  $cfg['allow_whatsapp'] = !empty($cfg['allow_whatsapp']);
  $cfg['allow_products'] = !empty($cfg['allow_products']);
  $cfg['allow_orders'] = !empty($cfg['allow_orders']);
  return $cfg;
}

function external_api_save_config(PDO $pdo, int $companyId, array $cfg): void {
  $clean = [
    'enabled' => !empty($cfg['enabled']),
    'token' => trim((string)($cfg['token'] ?? '')),
    'allow_whatsapp' => !empty($cfg['allow_whatsapp']),
    'allow_products' => !empty($cfg['allow_products']),
    'allow_orders' => !empty($cfg['allow_orders']),
  ];
  config_store_set($pdo, $companyId, 'external_api', $clean);
}

function external_api_generate_token(): string {
  return bin2hex(random_bytes(32));
}

function external_api_header_token(): string {
  $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
  $token = '';

  foreach ($headers as $k => $v) {
    $lk = strtolower((string)$k);
    if ($lk === 'x-api-token' || $lk === 'x-api-key') {
      $token = trim((string)$v);
      break;
    }
    if ($lk === 'authorization') {
      $auth = trim((string)$v);
      if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        $token = trim((string)$m[1]);
        break;
      }
    }
  }

  if ($token === '') {
    $token = trim((string)($_SERVER['HTTP_X_API_TOKEN'] ?? $_SERVER['HTTP_X_API_KEY'] ?? ''));
  }
  if ($token === '' && isset($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/^Bearer\s+(.+)$/i', (string)$_SERVER['HTTP_AUTHORIZATION'], $m)) {
    $token = trim((string)$m[1]);
  }

  // Aceita query token apenas como fallback para testes locais.
  if ($token === '') $token = trim((string)($_GET['token'] ?? ''));

  return $token;
}

function external_api_json_response(array $payload, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function external_api_read_json(): array {
  $raw = (string)file_get_contents('php://input');
  if (trim($raw) === '') return [];
  $data = json_decode($raw, true);
  if (!is_array($data)) {
    external_api_json_response(['ok' => false, 'error' => 'JSON inválido.'], 400);
  }
  return $data;
}

function external_api_method(): string {
  $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
  if ($method === 'POST' && isset($_POST['_method'])) {
    $method = strtoupper((string)$_POST['_method']);
  }
  return $method;
}

function external_api_handle_options(): void {
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Token, X-API-Key');
  if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'OPTIONS') {
    http_response_code(204);
    exit;
  }
}

function external_api_ensure_logs(PDO $pdo): void {
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS external_api_logs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      company_id INT UNSIGNED NULL,
      endpoint VARCHAR(80) NOT NULL,
      method VARCHAR(12) NOT NULL,
      status_code INT NOT NULL DEFAULT 200,
      request_json LONGTEXT NULL,
      response_json LONGTEXT NULL,
      ip VARCHAR(80) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_external_api_logs_company (company_id),
      KEY idx_external_api_logs_endpoint (endpoint),
      KEY idx_external_api_logs_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  } catch (Throwable $e) {}
}

function external_api_log(PDO $pdo, ?int $companyId, string $endpoint, string $method, int $statusCode, array $request = [], array $response = []): void {
  try {
    external_api_ensure_logs($pdo);
    $st = $pdo->prepare('INSERT INTO external_api_logs (company_id, endpoint, method, status_code, request_json, response_json, ip) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $st->execute([
      $companyId ?: null,
      mb_substr($endpoint, 0, 80),
      mb_substr($method, 0, 12),
      $statusCode,
      $request ? json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
      $response ? json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
      mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 80),
    ]);
  } catch (Throwable $e) {}
}

function external_api_authenticate(PDO $pdo, string $scope): array {
  $token = external_api_header_token();
  if ($token === '') {
    external_api_json_response(['ok' => false, 'error' => 'Token não informado. Use Authorization: Bearer SEU_TOKEN ou X-API-Token.'], 401);
  }

  try {
    config_store_init($pdo);
    $st = $pdo->prepare("SELECT company_id, cfg_json FROM app_config WHERE cfg_key = 'external_api'");
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    external_api_json_response(['ok' => false, 'error' => 'Não foi possível carregar configurações da API.'], 500);
  }

  foreach ($rows as $row) {
    $cfg = json_decode((string)($row['cfg_json'] ?? ''), true);
    if (!is_array($cfg)) continue;
    $saved = trim((string)($cfg['token'] ?? ''));
    if ($saved === '') continue;
    if (!hash_equals($saved, $token)) continue;

    $companyId = (int)($row['company_id'] ?? 0);
    $cfg = external_api_load_config($pdo, $companyId);
    if (empty($cfg['enabled'])) {
      external_api_json_response(['ok' => false, 'error' => 'API externa desativada para esta empresa.'], 403);
    }

    try { saas_ensure_modules_schema($pdo); } catch (Throwable $e) {}
    if (function_exists('saas_company_has_module') && !saas_company_has_module($pdo, $companyId, 'api')) {
      external_api_json_response(['ok' => false, 'error' => 'Módulo API externa não liberado para esta empresa.'], 403);
    }

    $scopeKey = 'allow_' . $scope;
    if (array_key_exists($scopeKey, $cfg) && empty($cfg[$scopeKey])) {
      external_api_json_response(['ok' => false, 'error' => 'Permissão insuficiente para este recurso.'], 403);
    }

    $stc = $pdo->prepare("SELECT * FROM companies WHERE id = ? LIMIT 1");
    $stc->execute([$companyId]);
    $company = $stc->fetch(PDO::FETCH_ASSOC);
    if (!$company) {
      external_api_json_response(['ok' => false, 'error' => 'Empresa vinculada ao token não encontrada.'], 403);
    }

    if (!defined('COMPANY_ID')) define('COMPANY_ID', $companyId);
    if (!defined('COMPANY_SLUG')) define('COMPANY_SLUG', (string)($company['slug'] ?? ''));

    return ['company_id' => $companyId, 'company' => $company, 'config' => $cfg];
  }

  external_api_json_response(['ok' => false, 'error' => 'Token inválido.'], 401);
}

function external_api_mysql_has_column(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

function external_api_table_exists(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

function external_api_money_to_float($value): float {
  if (is_int($value) || is_float($value)) return (float)$value;
  $value = trim((string)$value);
  if ($value === '') return 0.0;
  $value = str_replace(['R$', ' '], '', $value);
  if (str_contains($value, ',') && str_contains($value, '.')) {
    $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);
  } else {
    $value = str_replace(',', '.', $value);
  }
  return is_numeric($value) ? (float)$value : 0.0;
}

function external_api_clean_phone(string $value): string {
  return substr(preg_replace('/\D+/', '', $value) ?? '', 0, 30);
}

function external_api_ensure_product_map(PDO $pdo): void {
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS produtos_firebird_map (
      id INT AUTO_INCREMENT PRIMARY KEY,
      company_id BIGINT NOT NULL,
      produto_id BIGINT NOT NULL,
      codigo_firebird VARCHAR(60) NULL,
      created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY ux_produtos_firebird_map (company_id, produto_id),
      KEY idx_produtos_firebird_codigo (company_id, codigo_firebird)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  } catch (Throwable $e) {}
}

function external_api_product_code_column(PDO $pdo): ?string {
  return external_api_mysql_has_column($pdo, 'produtos', 'codigo_firebird') ? 'codigo_firebird' : null;
}

function external_api_product_by_code(PDO $pdo, int $companyId, string $codigo): ?array {
  $codigo = trim($codigo);
  if ($codigo === '') return null;

  if (external_api_product_code_column($pdo)) {
    $st = $pdo->prepare("SELECT * FROM produtos WHERE company_id = ? AND codigo_firebird = ? LIMIT 1");
    $st->execute([$companyId, $codigo]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
  }

  external_api_ensure_product_map($pdo);
  $st = $pdo->prepare("
    SELECT p.*, m.codigo_firebird
    FROM produtos_firebird_map m
    JOIN produtos p ON p.id = m.produto_id AND p.company_id = m.company_id
    WHERE m.company_id = ? AND m.codigo_firebird = ?
    LIMIT 1
  ");
  $st->execute([$companyId, $codigo]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function external_api_save_product_code(PDO $pdo, int $companyId, int $produtoId, string $codigo): void {
  $codigo = trim($codigo);
  if ($codigo === '' || $produtoId <= 0) return;

  if (external_api_product_code_column($pdo)) {
    $st = $pdo->prepare("UPDATE produtos SET codigo_firebird = ? WHERE id = ? AND company_id = ?");
    $st->execute([$codigo, $produtoId, $companyId]);
    return;
  }

  external_api_ensure_product_map($pdo);
  $st = $pdo->prepare("INSERT INTO produtos_firebird_map (company_id, produto_id, codigo_firebird) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE codigo_firebird = VALUES(codigo_firebird)");
  $st->execute([$companyId, $produtoId, $codigo]);
}

function external_api_product_response(PDO $pdo, array $row): array {
  $out = [
    'id' => (int)($row['id'] ?? 0),
    'codigo_firebird' => (string)($row['codigo_firebird'] ?? ''),
    'nome' => (string)($row['nome'] ?? ''),
    'descricao' => (string)($row['descricao'] ?? ''),
    'categoria' => (string)($row['categoria'] ?? ''),
    'preco' => (float)($row['preco'] ?? 0),
    'ativo' => (int)($row['ativo'] ?? 0) === 1,
    'imagem' => (string)($row['imagem'] ?? ''),
  ];

  if ($out['codigo_firebird'] === '') {
    try {
      external_api_ensure_product_map($pdo);
      $st = $pdo->prepare("SELECT codigo_firebird FROM produtos_firebird_map WHERE company_id = ? AND produto_id = ? LIMIT 1");
      $st->execute([(int)($row['company_id'] ?? 0), (int)($row['id'] ?? 0)]);
      $out['codigo_firebird'] = (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) {}
  }

  return $out;
}

function external_api_order_item_obs_column(PDO $pdo): ?string {
  foreach (['complemento','observacao','obs','observacoes'] as $col) {
    if (external_api_mysql_has_column($pdo, 'pedido_itens', $col)) return $col;
  }
  try {
    $pdo->exec("ALTER TABLE pedido_itens ADD COLUMN complemento TEXT NULL AFTER total");
    return 'complemento';
  } catch (Throwable $e) {}
  return null;
}

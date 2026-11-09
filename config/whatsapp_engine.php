<?php
declare(strict_types=1);

// Motor de WhatsApp leve (Baileys, sem Chrome) rodando na VPS própria do
// WhatsApp. A URL, o token administrativo e o segredo do webhook ficam no banco
// (app_config da plataforma, company_id = 0, chave 'whatsapp_engine'), gravados
// pelo instalador deploy/whatsapp-engine/instalar_motor_whatsapp.sh.
//
// A troca é feita empresa por empresa: a empresa passa a usar o motor novo
// quando o seu service_url (config 'whatsapp') aponta para a URL do motor.

require_once __DIR__ . '/config_store.php';
require_once __DIR__ . '/whatsapp_service.php';

const WHATSAPP_ENGINE_CFG_KEY = 'whatsapp_engine';
const WHATSAPP_LEGACY_SERVICE_URL = 'http://127.0.0.1:3333';

if (!function_exists('whatsapp_engine_config')) {
  function whatsapp_engine_config(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cfg = ['url' => '', 'token' => '', 'webhook_secret' => '', 'installed_at' => ''];
    try {
      $saved = config_store_get($pdo, 0, WHATSAPP_ENGINE_CFG_KEY);
      if (is_array($saved)) $cfg = array_merge($cfg, $saved);
    } catch (Throwable $e) {}
    $cfg['url'] = rtrim(trim((string)$cfg['url']), '/');
    $cfg['token'] = trim((string)$cfg['token']);
    $cfg['webhook_secret'] = trim((string)$cfg['webhook_secret']);
    return $cache = $cfg;
  }
}

if (!function_exists('whatsapp_engine_ready')) {
  function whatsapp_engine_ready(array $engine): bool {
    return $engine['url'] !== '' && $engine['token'] !== '';
  }
}

if (!function_exists('whatsapp_engine_is_url')) {
  function whatsapp_engine_is_url(array $engine, string $url): bool {
    return $engine['url'] !== '' && rtrim(trim($url), '/') === $engine['url'];
  }
}

/**
 * Token administrativo do motor quando a URL informada é a do motor novo.
 * Usado no registro de tokens das empresas (o motor fica em outra VPS, então
 * não existe a liberação "sem token" do 127.0.0.1 do serviço antigo).
 */
if (!function_exists('whatsapp_engine_admin_token_for_url')) {
  function whatsapp_engine_admin_token_for_url(string $url): string {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return '';
    $engine = whatsapp_engine_config($pdo);
    return whatsapp_engine_is_url($engine, $url) ? $engine['token'] : '';
  }
}

if (!function_exists('whatsapp_engine_request')) {
  function whatsapp_engine_request(array $engine, string $method, string $path, array $payload = [], int $timeout = 15): array {
    return whatsapp_api_request(['service_url' => $engine['url'], 'api_token' => $engine['token'], 'timeout_seconds' => $timeout], $method, $path, $payload);
  }
}

if (!function_exists('whatsapp_engine_health')) {
  function whatsapp_engine_health(array $engine): array {
    if (!whatsapp_engine_ready($engine)) return ['ok' => false, 'error' => 'Motor ainda não instalado.'];
    try {
      return whatsapp_engine_request($engine, 'GET', '/health', [], 4);
    } catch (Throwable $e) {
      return ['ok' => false, 'error' => $e->getMessage()];
    }
  }
}

/**
 * Passa a empresa para o motor novo. A sessão do serviço antigo é desconectada
 * (libera o aparelho no celular) e a empresa precisa ler o QR Code uma vez.
 * O token da empresa e o identificador da sessão são mantidos.
 */
if (!function_exists('whatsapp_engine_migrate_company')) {
  function whatsapp_engine_migrate_company(PDO $pdo, int $companyId, int $userId = 0): array {
    $engine = whatsapp_engine_config($pdo);
    if (!whatsapp_engine_ready($engine)) throw new RuntimeException('O motor novo ainda não foi instalado.');
    $health = whatsapp_engine_health($engine);
    if (empty($health['ok'])) throw new RuntimeException('O motor novo não respondeu: ' . (string)($health['error'] ?? 'sem resposta'));

    $cfg = whatsapp_load_config($pdo, $companyId, false);
    if (whatsapp_engine_is_url($engine, (string)$cfg['service_url'])) throw new RuntimeException('Esta empresa já está no motor novo.');
    $oldUrl = (string)$cfg['service_url'] !== '' ? (string)$cfg['service_url'] : WHATSAPP_LEGACY_SERVICE_URL;
    $sid = whatsapp_service_company_id($cfg, $companyId);
    $token = trim((string)$cfg['api_token']);
    if ($token === '') $token = whatsapp_generate_service_token();

    whatsapp_engine_request($engine, 'POST', '/tokens/upsert', ['company_id' => $sid, 'token' => $token]);

    $oldLogout = '';
    try {
      whatsapp_api_request(array_merge($cfg, ['timeout_seconds' => 15]), 'POST', '/logout', ['company_id' => $sid]);
      $oldLogout = 'ok';
    } catch (Throwable $e) {
      $oldLogout = $e->getMessage();
    }

    $cfg['service_url'] = $engine['url'];
    $cfg['api_token'] = $token;
    whatsapp_save_config($pdo, $companyId, $cfg);
    config_store_set($pdo, $companyId, 'whatsapp_engine_migration', [
      'engine' => 'baileys',
      'from_url' => $oldUrl,
      'migrated_at' => date('Y-m-d H:i:s'),
      'user_id' => $userId,
      'old_logout' => mb_substr($oldLogout, 0, 200),
    ]);

    // Já deixa o QR Code sendo gerado no motor novo.
    try { whatsapp_engine_request($engine, 'POST', '/admin/session/start', ['company_id' => $sid], 10); } catch (Throwable $e) {}
    return ['session_company_id' => $sid, 'old_logout' => $oldLogout];
  }
}

/** Volta a empresa para o serviço antigo (também exige ler o QR Code de novo). */
if (!function_exists('whatsapp_engine_revert_company')) {
  function whatsapp_engine_revert_company(PDO $pdo, int $companyId, int $userId = 0): array {
    $engine = whatsapp_engine_config($pdo);
    $cfg = whatsapp_load_config($pdo, $companyId, false);
    if (!whatsapp_engine_is_url($engine, (string)$cfg['service_url'])) throw new RuntimeException('Esta empresa não está no motor novo.');
    $sid = whatsapp_service_company_id($cfg, $companyId);
    try { whatsapp_api_request(array_merge($cfg, ['timeout_seconds' => 15]), 'POST', '/logout', ['company_id' => $sid]); } catch (Throwable $e) {}

    $migration = config_store_get($pdo, $companyId, 'whatsapp_engine_migration') ?: [];
    $back = rtrim(trim((string)($migration['from_url'] ?? '')), '/');
    if ($back === '' || whatsapp_engine_is_url($engine, $back)) $back = WHATSAPP_LEGACY_SERVICE_URL;
    $cfg['service_url'] = $back;
    whatsapp_save_config($pdo, $companyId, $cfg);
    // O serviço antigo guarda os tokens: garante o da empresa (liberado no 127.0.0.1).
    if (trim((string)$cfg['api_token']) !== '') {
      try { whatsapp_register_company_token($cfg, $sid, (string)$cfg['api_token']); } catch (Throwable $e) {}
    }
    $migration['reverted_at'] = date('Y-m-d H:i:s');
    $migration['reverted_by'] = $userId;
    config_store_set($pdo, $companyId, 'whatsapp_engine_migration', $migration);
    return ['session_company_id' => $sid, 'service_url' => $back];
  }
}

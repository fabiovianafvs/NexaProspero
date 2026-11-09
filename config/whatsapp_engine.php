<?php
declare(strict_types=1);

// Motor de WhatsApp leve (Baileys, sem Chrome) rodando na VPS própria do
// WhatsApp. A URL, o token administrativo e o segredo do webhook são da
// infraestrutura (não de uma empresa) e ficam em /etc/nexa/whatsapp_engine.env
// (root:www-data 640), gravado pelo instalador
// deploy/whatsapp-engine/instalar_motor_whatsapp.sh. Na homologação o motor
// nunca é usado (WhatsApp fica sem configuração lá).
//
// A troca é feita empresa por empresa: a empresa passa a usar o motor novo
// quando o seu service_url (config 'whatsapp') aponta para a URL do motor.

require_once __DIR__ . '/config_store.php';
require_once __DIR__ . '/whatsapp_service.php';

const WHATSAPP_LEGACY_SERVICE_URL = 'http://127.0.0.1:3333';

if (!function_exists('whatsapp_engine_env_file')) {
  function whatsapp_engine_env_file(): string {
    return (string)(getenv('NEXA_WA_ENGINE_FILE') ?: '/etc/nexa/whatsapp_engine.env');
  }
}

if (!function_exists('whatsapp_engine_config')) {
  function whatsapp_engine_config(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cfg = ['url' => '', 'token' => '', 'webhook_secret' => '', 'installed_at' => ''];
    if (strtolower(trim((string)(getenv('NEXA_AMBIENTE') ?: ''))) === 'homologacao') return $cache = $cfg;
    $file = whatsapp_engine_env_file();
    $lines = is_file($file) && is_readable($file) ? @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
    $map = ['NEXA_WA_URL' => 'url', 'NEXA_WA_TOKEN' => 'token', 'NEXA_WA_SECRET' => 'webhook_secret', 'NEXA_WA_INSTALLED_AT' => 'installed_at'];
    foreach (is_array($lines) ? $lines : [] as $line) {
      $pos = strpos((string)$line, '=');
      if ($pos === false) continue;
      $name = trim(substr((string)$line, 0, $pos));
      if (isset($map[$name])) $cfg[$map[$name]] = trim(trim(substr((string)$line, $pos + 1)), "\"'");
    }
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

/** O motor aceita tokens de 32 a 256 caracteres; fora disso (ex.: token antigo digitado à mão) gera um novo. */
if (!function_exists('whatsapp_engine_token_valid')) {
  function whatsapp_engine_token_valid(string $token): bool {
    $len = strlen(trim($token));
    return $len >= 32 && $len <= 256;
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
  function whatsapp_engine_migrate_company(PDO $pdo, int $companyId, int $userId = 0, bool $logoutOld = true, bool $startSession = true): array {
    $engine = whatsapp_engine_config($pdo);
    if (!whatsapp_engine_ready($engine)) throw new RuntimeException('O motor novo ainda não foi instalado.');
    static $healthOk = false;
    if (!$healthOk) {
      $health = whatsapp_engine_health($engine);
      if (empty($health['ok'])) throw new RuntimeException('O motor novo não respondeu: ' . (string)($health['error'] ?? 'sem resposta'));
      $healthOk = true;
    }

    $cfg = whatsapp_load_config($pdo, $companyId, false);
    if (whatsapp_engine_is_url($engine, (string)$cfg['service_url'])) throw new RuntimeException('Esta empresa já está no motor novo.');
    $oldUrl = (string)$cfg['service_url'] !== '' ? (string)$cfg['service_url'] : WHATSAPP_LEGACY_SERVICE_URL;
    $sid = whatsapp_service_company_id($cfg, $companyId);
    $token = trim((string)$cfg['api_token']);
    if (!whatsapp_engine_token_valid($token)) $token = whatsapp_generate_service_token();

    whatsapp_engine_request($engine, 'POST', '/tokens/upsert', ['company_id' => $sid, 'token' => $token]);

    $oldLogout = 'não solicitado';
    if ($logoutOld) {
      try {
        whatsapp_api_request(array_merge($cfg, ['timeout_seconds' => 15]), 'POST', '/logout', ['company_id' => $sid]);
        $oldLogout = 'ok';
      } catch (Throwable $e) {
        $oldLogout = $e->getMessage();
      }
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
    if ($startSession) {
      try { whatsapp_engine_request($engine, 'POST', '/admin/session/start', ['company_id' => $sid], 10); } catch (Throwable $e) {}
    }
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

/** URL padrão do serviço para configurações novas: o motor novo, quando instalado. */
if (!function_exists('whatsapp_default_service_url')) {
  function whatsapp_default_service_url(): string {
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO) {
      $engine = whatsapp_engine_config($pdo);
      if (whatsapp_engine_ready($engine)) return $engine['url'];
    }
    return (string)(getenv('WHATSAPP_SERVICE_URL') ?: WHATSAPP_LEGACY_SERVICE_URL);
  }
}

/**
 * Passa TODOS os WhatsApps não oficiais para o motor novo: empresas, Central
 * SaaS e revendas. Não desconecta o serviço antigo (ele será desligado) e não
 * abre sessões: cada um lê o QR Code quando abrir a tela do WhatsApp.
 */
if (!function_exists('whatsapp_engine_migrate_all')) {
  function whatsapp_engine_migrate_all(PDO $pdo, int $userId = 0): array {
    $engine = whatsapp_engine_config($pdo);
    if (!whatsapp_engine_ready($engine)) throw new RuntimeException('O motor novo ainda não foi instalado.');
    $health = whatsapp_engine_health($engine);
    if (empty($health['ok'])) throw new RuntimeException('O motor novo não respondeu: ' . (string)($health['error'] ?? 'sem resposta'));
    $out = ['companies' => 0, 'saas' => 0, 'resellers' => 0, 'errors' => []];

    $st = $pdo->query("SELECT ac.company_id, ac.cfg_json FROM app_config ac JOIN companies c ON c.id = ac.company_id WHERE ac.cfg_key = 'whatsapp'");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $cfg = json_decode((string)$r['cfg_json'], true);
      if (!is_array($cfg) || whatsapp_engine_is_url($engine, (string)($cfg['service_url'] ?? ''))) continue;
      try {
        whatsapp_engine_migrate_company($pdo, (int)$r['company_id'], $userId, false, false);
        $out['companies']++;
      } catch (Throwable $e) {
        $out['errors'][] = 'Empresa #' . (int)$r['company_id'] . ': ' . $e->getMessage();
      }
    }

    // Central SaaS (instância técnica própria).
    try {
      require_once __DIR__ . '/saas_whatsapp_service.php';
      $saas = saas_whatsapp_load_config($pdo);
      if ($saas['provider'] === 'unofficial' && !whatsapp_engine_is_url($engine, (string)$saas['service_url'])) {
        $token = whatsapp_engine_token_valid((string)$saas['api_token']) ? (string)$saas['api_token'] : whatsapp_generate_service_token();
        whatsapp_engine_request($engine, 'POST', '/tokens/upsert', ['company_id' => saas_whatsapp_unofficial_instance_id($saas), 'token' => $token]);
        $pdo->prepare('UPDATE saas_whatsapp_config SET service_url = ?, api_token = ? WHERE id = 1')->execute([$engine['url'], $token]);
        $out['saas']++;
      }
    } catch (Throwable $e) {
      $out['errors'][] = 'Central SaaS: ' . $e->getMessage();
    }

    // Revendas.
    try {
      require_once __DIR__ . '/reseller_integrations.php';
      $rs = $pdo->query("SELECT reseller_company_id, service_url, api_token, unofficial_instance_id FROM saas_reseller_whatsapp_config WHERE COALESCE(provider,'unofficial') = 'unofficial'");
      foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (whatsapp_engine_is_url($engine, (string)$r['service_url'])) continue;
        $resellerId = (int)$r['reseller_company_id'];
        try {
          $sid = preg_replace('/\D+/', '', (string)($r['unofficial_instance_id'] ?? '')) ?: '';
          if ($sid === '' || $sid === '0') $sid = reseller_whatsapp_default_instance_id($resellerId);
          $token = whatsapp_engine_token_valid(trim((string)$r['api_token'])) ? trim((string)$r['api_token']) : whatsapp_generate_service_token();
          whatsapp_engine_request($engine, 'POST', '/tokens/upsert', ['company_id' => $sid, 'token' => $token]);
          $pdo->prepare('UPDATE saas_reseller_whatsapp_config SET service_url = ?, api_token = ? WHERE reseller_company_id = ?')->execute([$engine['url'], $token, $resellerId]);
          $out['resellers']++;
        } catch (Throwable $e) {
          $out['errors'][] = 'Revenda #' . $resellerId . ': ' . $e->getMessage();
        }
      }
    } catch (Throwable $e) {
      $out['errors'][] = 'Revendas: ' . $e->getMessage();
    }
    return $out;
  }
}

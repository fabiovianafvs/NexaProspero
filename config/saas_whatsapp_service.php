<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/saas_billing.php';
require_once __DIR__ . '/password_setup.php';

if (!function_exists('saas_whatsapp_default_unofficial_instance_id')) {
  function saas_whatsapp_default_unofficial_instance_id(): int {
    // Faixa reservada para a Central SaaS. Mantém distância dos IDs reais de empresas
    // e continua compatível com o company_id numérico exigido pelo serviço Node.
    return 3900000001;
  }
}

if (!function_exists('saas_whatsapp_db_has_column')) {
  function saas_whatsapp_db_has_column(PDO $pdo, string $table, string $column): bool {
    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
      $st->execute([$table, $column]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('saas_whatsapp_ensure_column')) {
  function saas_whatsapp_ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
    try {
      if (!saas_whatsapp_db_has_column($pdo, $table, $column)) {
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $definition);
      }
    } catch (Throwable $e) {}
  }
}

if (!function_exists('saas_whatsapp_ensure_schema')) {
  function saas_whatsapp_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS saas_whatsapp_config (
      id TINYINT UNSIGNED NOT NULL DEFAULT 1,
      enabled TINYINT(1) NOT NULL DEFAULT 0,
      provider ENUM('unofficial','official') NOT NULL DEFAULT 'unofficial',
      service_url VARCHAR(255) NULL,
      api_token LONGTEXT NULL,
      unofficial_company_id INT UNSIGNED NOT NULL DEFAULT 3900000001,
      official_graph_version VARCHAR(20) NOT NULL DEFAULT 'v20.0',
      official_phone_number_id VARCHAR(100) NULL,
      official_access_token LONGTEXT NULL,
      official_template_credentials VARCHAR(100) NULL,
      official_template_billing VARCHAR(100) NULL,
      official_language_code VARCHAR(10) NOT NULL DEFAULT 'pt_BR',
      auto_send_credentials TINYINT(1) NOT NULL DEFAULT 1,
      auto_send_billing TINYINT(1) NOT NULL DEFAULT 1,
      billing_notice_days INT NOT NULL DEFAULT 3,
      block_grace_days INT NOT NULL DEFAULT 2,
      default_country_code VARCHAR(5) NOT NULL DEFAULT '55',
      test_phone VARCHAR(30) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Mantém compatibilidade caso a tabela já exista de uma versão anterior.
    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_config', 'enabled', "enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER id");
    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_config', 'provider', "provider ENUM('unofficial','official') NOT NULL DEFAULT 'unofficial' AFTER enabled");
    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_config', 'service_url', "service_url VARCHAR(255) NULL AFTER provider");
    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_config', 'api_token', "api_token LONGTEXT NULL AFTER service_url");
    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_config', 'unofficial_company_id', "unofficial_company_id INT UNSIGNED NOT NULL DEFAULT 3900000001 AFTER api_token");
    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_config', 'official_graph_version', "official_graph_version VARCHAR(20) NOT NULL DEFAULT 'v20.0' AFTER unofficial_company_id");
    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_config', 'official_phone_number_id', "official_phone_number_id VARCHAR(100) NULL AFTER official_graph_version");
    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_config', 'official_access_token', "official_access_token LONGTEXT NULL AFTER official_phone_number_id");
    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_config', 'official_template_credentials', "official_template_credentials VARCHAR(100) NULL AFTER official_access_token");
    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_config', 'official_template_billing', "official_template_billing VARCHAR(100) NULL AFTER official_template_credentials");
    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_config', 'official_language_code', "official_language_code VARCHAR(10) NOT NULL DEFAULT 'pt_BR' AFTER official_template_billing");
    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_config', 'auto_send_credentials', "auto_send_credentials TINYINT(1) NOT NULL DEFAULT 1 AFTER official_language_code");
    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_config', 'auto_send_billing', "auto_send_billing TINYINT(1) NOT NULL DEFAULT 1 AFTER auto_send_credentials");
    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_config', 'billing_notice_days', "billing_notice_days INT NOT NULL DEFAULT 3 AFTER auto_send_billing");
    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_config', 'block_grace_days', "block_grace_days INT NOT NULL DEFAULT 2 AFTER billing_notice_days");
    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_config', 'default_country_code', "default_country_code VARCHAR(5) NOT NULL DEFAULT '55' AFTER block_grace_days");
    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_config', 'test_phone', "test_phone VARCHAR(30) NULL AFTER default_country_code");

    $defaultInstanceId = saas_whatsapp_default_unofficial_instance_id();
    $pdo->exec("INSERT INTO saas_whatsapp_config (id, service_url, provider, unofficial_company_id, billing_notice_days, block_grace_days)
      VALUES (1, 'http://127.0.0.1:3333', 'unofficial', " . $defaultInstanceId . ", 3, 2)
      ON DUPLICATE KEY UPDATE id = id");
    // A v3.5+ do serviço não aceita company_id=0. Corrige apenas a configuração antiga inválida.
    $pdo->exec("UPDATE saas_whatsapp_config SET unofficial_company_id = " . $defaultInstanceId . " WHERE id = 1 AND (unofficial_company_id IS NULL OR unofficial_company_id = 0)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS saas_whatsapp_logs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      company_id INT UNSIGNED NULL,
      subscription_id BIGINT UNSIGNED NULL,
      charge_id BIGINT UNSIGNED NULL,
      tipo VARCHAR(50) NOT NULL,
      provider VARCHAR(20) NULL,
      destino VARCHAR(30) NULL,
      mensagem LONGTEXT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'pending',
      erro VARCHAR(255) NULL,
      response_json LONGTEXT NULL,
      reference_date DATE NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_saas_whatsapp_logs_company (company_id),
      KEY idx_saas_whatsapp_logs_tipo (tipo),
      KEY idx_saas_whatsapp_logs_status (status),
      KEY idx_saas_whatsapp_logs_reference (reference_date),
      KEY idx_saas_whatsapp_logs_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_logs', 'subscription_id', "subscription_id BIGINT UNSIGNED NULL AFTER company_id");
    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_logs', 'charge_id', "charge_id BIGINT UNSIGNED NULL AFTER subscription_id");
    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_logs', 'provider', "provider VARCHAR(20) NULL AFTER tipo");
    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_logs', 'reference_date', "reference_date DATE NULL AFTER response_json");


    $pdo->exec("CREATE TABLE IF NOT EXISTS saas_whatsapp_templates (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      tipo ENUM('credentials','billing_notice') NOT NULL DEFAULT 'billing_notice',
      titulo VARCHAR(120) NOT NULL,
      dias_referencia INT NULL,
      body LONGTEXT NOT NULL,
      ativo TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_saas_whatsapp_templates_tipo (tipo),
      KEY idx_saas_whatsapp_templates_dias (dias_referencia),
      KEY idx_saas_whatsapp_templates_ativo (ativo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    saas_whatsapp_ensure_column($pdo, 'saas_whatsapp_templates', 'dias_referencia', "dias_referencia INT NULL AFTER titulo");

    // Senhas não são mais guardadas em texto aberto: o cliente recebe um link para criar a senha.

    saas_whatsapp_seed_default_templates($pdo);
  }
}

if (!function_exists('saas_whatsapp_default_service_url')) {
  /** Motor novo (leve) quando instalado; senão o serviço local antigo. */
  function saas_whatsapp_default_service_url(): string {
    if (!function_exists('whatsapp_default_service_url')) require_once __DIR__ . '/whatsapp_engine.php';
    return whatsapp_default_service_url();
  }
}

if (!function_exists('saas_whatsapp_default_config')) {
  function saas_whatsapp_default_config(): array {
    return [
      'enabled' => false,
      'provider' => 'unofficial',
      'service_url' => getenv('SAAS_WHATSAPP_SERVICE_URL') ?: saas_whatsapp_default_service_url(),
      'api_token' => getenv('SAAS_WHATSAPP_API_TOKEN') ?: (getenv('WHATSAPP_API_TOKEN') ?: ''),
      'unofficial_company_id' => (int)(getenv('SAAS_WHATSAPP_COMPANY_ID') ?: saas_whatsapp_default_unofficial_instance_id()),
      'official_graph_version' => getenv('SAAS_WHATSAPP_GRAPH_VERSION') ?: 'v20.0',
      'official_phone_number_id' => getenv('SAAS_WHATSAPP_PHONE_NUMBER_ID') ?: '',
      'official_access_token' => getenv('SAAS_WHATSAPP_ACCESS_TOKEN') ?: '',
      'official_template_credentials' => getenv('SAAS_WHATSAPP_TEMPLATE_CREDENTIALS') ?: '',
      'official_template_billing' => getenv('SAAS_WHATSAPP_TEMPLATE_BILLING') ?: '',
      'official_language_code' => getenv('SAAS_WHATSAPP_LANGUAGE_CODE') ?: 'pt_BR',
      'auto_send_credentials' => true,
      'auto_send_billing' => true,
      'billing_notice_days' => 3,
      'block_grace_days' => 2,
      'default_country_code' => '55',
      'test_phone' => '',
    ];
  }
}

if (!function_exists('saas_whatsapp_load_config')) {
  function saas_whatsapp_load_config(PDO $pdo): array {
    saas_whatsapp_ensure_schema($pdo);
    $cfg = saas_whatsapp_default_config();
    try {
      $row = $pdo->query('SELECT * FROM saas_whatsapp_config WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
      foreach ($row as $k => $v) $cfg[$k] = $v;
    } catch (Throwable $e) {}

    $cfg['enabled'] = !empty($cfg['enabled']);
    $cfg['provider'] = in_array((string)($cfg['provider'] ?? ''), ['unofficial','official'], true) ? (string)$cfg['provider'] : 'unofficial';
    $cfg['service_url'] = rtrim(trim((string)($cfg['service_url'] ?? '')), '/');
    if ($cfg['service_url'] === '') $cfg['service_url'] = saas_whatsapp_default_service_url();
    $cfg['api_token'] = trim((string)($cfg['api_token'] ?? ''));
    $cfg['unofficial_company_id'] = max(1, (int)($cfg['unofficial_company_id'] ?? saas_whatsapp_default_unofficial_instance_id()));
    $cfg['official_graph_version'] = trim((string)($cfg['official_graph_version'] ?? 'v20.0')) ?: 'v20.0';
    if ($cfg['official_graph_version'][0] !== 'v') $cfg['official_graph_version'] = 'v' . $cfg['official_graph_version'];
    $cfg['official_phone_number_id'] = trim((string)($cfg['official_phone_number_id'] ?? ''));
    $cfg['official_access_token'] = trim((string)($cfg['official_access_token'] ?? ''));
    $cfg['official_template_credentials'] = trim((string)($cfg['official_template_credentials'] ?? ''));
    $cfg['official_template_billing'] = trim((string)($cfg['official_template_billing'] ?? ''));
    $cfg['official_language_code'] = trim((string)($cfg['official_language_code'] ?? 'pt_BR')) ?: 'pt_BR';
    $cfg['auto_send_credentials'] = !empty($cfg['auto_send_credentials']);
    $cfg['auto_send_billing'] = !empty($cfg['auto_send_billing']);
    $cfg['billing_notice_days'] = max(1, min(15, (int)($cfg['billing_notice_days'] ?? 3)));
    $cfg['block_grace_days'] = max(0, min(30, (int)($cfg['block_grace_days'] ?? 2)));
    $cfg['default_country_code'] = preg_replace('/\D+/', '', (string)($cfg['default_country_code'] ?? '55')) ?: '55';
    $cfg['test_phone'] = saas_whatsapp_digits((string)($cfg['test_phone'] ?? ''));
    return $cfg;
  }
}

if (!function_exists('saas_whatsapp_save_config')) {
  function saas_whatsapp_save_config(PDO $pdo, array $input): void {
    saas_whatsapp_ensure_schema($pdo);
    $provider = (string)($input['provider'] ?? 'unofficial');
    if (!in_array($provider, ['unofficial','official'], true)) $provider = 'unofficial';

    $serviceUrl = rtrim(trim((string)($input['service_url'] ?? '')), '/');
    if ($serviceUrl === '') $serviceUrl = saas_whatsapp_default_service_url();

    $graphVersion = trim((string)($input['official_graph_version'] ?? 'v20.0')) ?: 'v20.0';
    if ($graphVersion[0] !== 'v') $graphVersion = 'v' . $graphVersion;

    $data = [
      'enabled' => !empty($input['enabled']) ? 1 : 0,
      'provider' => $provider,
      'service_url' => $serviceUrl,
      'api_token' => trim((string)($input['api_token'] ?? '')),
      'unofficial_company_id' => max(1, (int)($input['unofficial_company_id'] ?? saas_whatsapp_default_unofficial_instance_id())),
      'official_graph_version' => $graphVersion,
      'official_phone_number_id' => trim((string)($input['official_phone_number_id'] ?? '')),
      'official_access_token' => trim((string)($input['official_access_token'] ?? '')),
      'official_template_credentials' => trim((string)($input['official_template_credentials'] ?? '')),
      'official_template_billing' => trim((string)($input['official_template_billing'] ?? '')),
      'official_language_code' => trim((string)($input['official_language_code'] ?? 'pt_BR')) ?: 'pt_BR',
      'auto_send_credentials' => !empty($input['auto_send_credentials']) ? 1 : 0,
      'auto_send_billing' => !empty($input['auto_send_billing']) ? 1 : 0,
      'billing_notice_days' => max(1, min(15, (int)($input['billing_notice_days'] ?? 3))),
      'block_grace_days' => max(0, min(30, (int)($input['block_grace_days'] ?? 2))),
      'default_country_code' => preg_replace('/\D+/', '', (string)($input['default_country_code'] ?? '55')) ?: '55',
      'test_phone' => saas_whatsapp_digits((string)($input['test_phone'] ?? '')),
    ];

    $sql = 'INSERT INTO saas_whatsapp_config
      (id, enabled, provider, service_url, api_token, unofficial_company_id, official_graph_version, official_phone_number_id, official_access_token, official_template_credentials, official_template_billing, official_language_code, auto_send_credentials, auto_send_billing, billing_notice_days, block_grace_days, default_country_code, test_phone)
      VALUES
      (1, :enabled, :provider, :service_url, :api_token, :unofficial_company_id, :official_graph_version, :official_phone_number_id, :official_access_token, :official_template_credentials, :official_template_billing, :official_language_code, :auto_send_credentials, :auto_send_billing, :billing_notice_days, :block_grace_days, :default_country_code, :test_phone)
      ON DUPLICATE KEY UPDATE
        enabled = VALUES(enabled), provider = VALUES(provider), service_url = VALUES(service_url), api_token = VALUES(api_token), unofficial_company_id = VALUES(unofficial_company_id),
        official_graph_version = VALUES(official_graph_version), official_phone_number_id = VALUES(official_phone_number_id), official_access_token = VALUES(official_access_token),
        official_template_credentials = VALUES(official_template_credentials), official_template_billing = VALUES(official_template_billing), official_language_code = VALUES(official_language_code),
        auto_send_credentials = VALUES(auto_send_credentials), auto_send_billing = VALUES(auto_send_billing), billing_notice_days = VALUES(billing_notice_days),
        block_grace_days = VALUES(block_grace_days), default_country_code = VALUES(default_country_code), test_phone = VALUES(test_phone)';
    $pdo->prepare($sql)->execute($data);
  }
}

if (!function_exists('saas_whatsapp_digits')) {
  function saas_whatsapp_digits(string $value): string {
    return preg_replace('/\D+/', '', $value) ?? '';
  }
}

if (!function_exists('saas_whatsapp_normalize_phone')) {
  function saas_whatsapp_normalize_phone(string $value, string $countryCode = '55'): string {
    $n = saas_whatsapp_digits($value);
    $countryCode = saas_whatsapp_digits($countryCode) ?: '55';
    if ($n === '') return '';
    if ((strlen($n) === 10 || strlen($n) === 11) && !str_starts_with($n, $countryCode)) {
      $n = $countryCode . $n;
    }
    return $n;
  }
}

if (!function_exists('saas_whatsapp_generate_token')) {
  function saas_whatsapp_generate_token(): string {
    return bin2hex(random_bytes(32));
  }
}

if (!function_exists('saas_whatsapp_cache_admin_password')) {
  /** Obsoleta: senhas não são mais guardadas em texto aberto. Mantida só por compatibilidade. */
  function saas_whatsapp_cache_admin_password(PDO $pdo, int $userId, string $password): void {
  }
}

if (!function_exists('saas_whatsapp_get_admin_cached_password')) {
  /** Obsoleta: senhas não são mais guardadas em texto aberto. Sempre retorna vazio. */
  function saas_whatsapp_get_admin_cached_password(PDO $pdo, int $companyId): string {
    return '';
  }
}


if (!function_exists('saas_whatsapp_unofficial_instance_id')) {
  function saas_whatsapp_unofficial_instance_id(array $cfg): string {
    // Importante: envie como string. No serviço Node, company_id = 0 numérico pode cair em fallback por ser falsy.
    // Como string "0", a instância central permanece exatamente a mesma usada em /status e /qr.
    return (string)max(1, (int)($cfg['unofficial_company_id'] ?? saas_whatsapp_default_unofficial_instance_id()));
  }
}


if (!function_exists('saas_whatsapp_generate_unofficial_instance_id')) {
  function saas_whatsapp_generate_unofficial_instance_id(int $oldInstanceId = 0): int {
    // Central SaaS usa a faixa reservada 3.900.000.001-3.999.999.999.
    // Ao corrigir a conexão, troca o perfil sem aproximar-se do limite do INT UNSIGNED.
    if ($oldInstanceId >= 3800000000) {
      $new = random_int(3900000001, 3999999999);
      return $new === $oldInstanceId ? 3900000001 : $new;
    }
    $base = max(1, $oldInstanceId);
    if ($base < 1000000) $base = $base * 1000000;
    return $base + random_int(100000, 999999);
  }
}

if (!function_exists('saas_whatsapp_rotate_unofficial_instance_id')) {
  function saas_whatsapp_rotate_unofficial_instance_id(PDO $pdo): array {
    $cfg = saas_whatsapp_load_config($pdo);
    $old = (int)saas_whatsapp_unofficial_instance_id($cfg);
    $new = saas_whatsapp_generate_unofficial_instance_id($old);
    $guard = 0;
    while ($new === $old && $guard < 5) {
      $new = saas_whatsapp_generate_unofficial_instance_id($old);
      $guard++;
    }
    $cfg['unofficial_company_id'] = $new;
    saas_whatsapp_save_config($pdo, $cfg);
    $cfg = saas_whatsapp_load_config($pdo);
    return ['old_instance_id' => (string)$old, 'new_instance_id' => saas_whatsapp_unofficial_instance_id($cfg), 'config' => $cfg];
  }
}




if (!function_exists('saas_whatsapp_default_templates')) {
  function saas_whatsapp_default_templates(): array {
    return [
      [
        'tipo' => 'credentials',
        'titulo' => 'Credenciais da nova empresa',
        'dias_referencia' => null,
        'body' => "Olá, {{empresa}}!\n\nSeu catálogo Nexa Próspero foi criado com sucesso.\n\nCatálogo Delivery:\n{{link_catalogo_delivery}}\n\nCatálogo somente produtos:\n{{link_catalogo}}\n\nPainel Administrativo:\n{{link_admin}}\n\nUsuário do admin:\n{{usuario}}\n\nCrie sua senha de acesso (link válido por 48 horas):\n{{link_criar_senha}}\n\n{{bloco_treinamento}}{{bloco_pagamento}}",
      ],
      [
        'tipo' => 'billing_notice',
        'titulo' => 'Faltam 3 dias para vencer',
        'dias_referencia' => 3,
        'body' => "Olá, {{empresa}}!\n\nFaltam {{dias}} dias para o vencimento da sua assinatura Nexa Próspero.\n\nPlano: {{plano}}\nValor: {{valor}}\nVencimento: {{vencimento}}\n\nPara evitar bloqueio do catálogo e do painel administrativo, realize o pagamento pelo link abaixo:\n{{link_pagamento}}\n\nCaso já tenha efetuado o pagamento, desconsidere esta mensagem.",
      ],
      [
        'tipo' => 'billing_notice',
        'titulo' => 'Faltam 2 dias para vencer',
        'dias_referencia' => 2,
        'body' => "Olá, {{empresa}}!\n\nSua assinatura Nexa Próspero vence em {{dias}} dias, no dia {{vencimento}}.\n\nPlano: {{plano}}\nValor: {{valor}}\n\nLink para pagamento:\n{{link_pagamento}}\n\nEvite o bloqueio automático mantendo o pagamento em dia.",
      ],
      [
        'tipo' => 'billing_notice',
        'titulo' => 'Vence amanhã',
        'dias_referencia' => 1,
        'body' => "Olá, {{empresa}}!\n\nLembrete: sua assinatura Nexa Próspero vence amanhã, {{vencimento}}.\n\nPlano: {{plano}}\nValor: {{valor}}\n\nPague pelo link abaixo para evitar transtornos:\n{{link_pagamento}}",
      ],
      [
        'tipo' => 'billing_notice',
        'titulo' => 'Vence hoje',
        'dias_referencia' => 0,
        'body' => "Olá, {{empresa}}!\n\nHoje vence sua assinatura Nexa Próspero.\n\nPlano: {{plano}}\nValor: {{valor}}\n\nEste é um lembrete para pagamento ainda hoje, evitando bloqueio do catálogo e do painel administrativo.\n\nLink para pagamento:\n{{link_pagamento}}",
      ],
      [
        'tipo' => 'billing_notice',
        'titulo' => 'Sistema vencido',
        'dias_referencia' => -1,
        'body' => "Olá, {{empresa}}!\n\nSua assinatura Nexa Próspero está vencida há {{dias_vencido}} dia(s).\n\nPlano: {{plano}}\nValor: {{valor}}\nVencimento: {{vencimento}}\n\nPara evitar bloqueio do sistema, regularize o pagamento pelo link abaixo:\n{{link_pagamento}}",
      ],
      [
        'tipo' => 'billing_notice',
        'titulo' => 'Último dia antes do bloqueio',
        'dias_referencia' => -2,
        'body' => "Olá, {{empresa}}!\n\nEste é o último aviso antes do bloqueio automático do seu sistema Nexa Próspero.\n\nSua assinatura venceu em {{vencimento}}.\nPlano: {{plano}}\nValor: {{valor}}\n\nRegularize agora pelo link abaixo:\n{{link_pagamento}}\n\nCaso já tenha efetuado o pagamento, desconsidere esta mensagem.",
      ],
    ];
  }
}

if (!function_exists('saas_whatsapp_seed_default_templates')) {
  function saas_whatsapp_seed_default_templates(PDO $pdo): void {
    try {
      $check = $pdo->query('SELECT COUNT(*) FROM saas_whatsapp_templates')->fetchColumn();
      if ((int)$check > 0) return;
      $st = $pdo->prepare('INSERT INTO saas_whatsapp_templates (tipo, titulo, dias_referencia, body, ativo) VALUES (?, ?, ?, ?, 1)');
      foreach (saas_whatsapp_default_templates() as $tpl) {
        $st->execute([$tpl['tipo'], $tpl['titulo'], $tpl['dias_referencia'], $tpl['body']]);
      }
    } catch (Throwable $e) {}
  }
}

if (!function_exists('saas_whatsapp_load_templates')) {
  function saas_whatsapp_load_templates(PDO $pdo): array {
    saas_whatsapp_ensure_schema($pdo);
    try {
      $st = $pdo->query("SELECT * FROM saas_whatsapp_templates ORDER BY FIELD(tipo, 'credentials', 'billing_notice'), COALESCE(dias_referencia, 9999) DESC, id ASC");
      return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('saas_whatsapp_get_template')) {
  function saas_whatsapp_get_template(PDO $pdo, int $id): ?array {
    saas_whatsapp_ensure_schema($pdo);
    try {
      $st = $pdo->prepare('SELECT * FROM saas_whatsapp_templates WHERE id = ? LIMIT 1');
      $st->execute([$id]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      return $row ?: null;
    } catch (Throwable $e) {
      return null;
    }
  }
}

if (!function_exists('saas_whatsapp_save_template')) {
  function saas_whatsapp_save_template(PDO $pdo, array $input): int {
    saas_whatsapp_ensure_schema($pdo);
    $id = max(0, (int)($input['id'] ?? 0));
    $tipo = (string)($input['tipo'] ?? 'billing_notice');
    if (!in_array($tipo, ['credentials', 'billing_notice'], true)) $tipo = 'billing_notice';
    $titulo = trim((string)($input['titulo'] ?? '')) ?: ($tipo === 'credentials' ? 'Credenciais' : 'Aviso de cobrança');
    $diasRaw = trim((string)($input['dias_referencia'] ?? ''));
    $diasRef = $tipo === 'billing_notice' && $diasRaw !== '' ? (int)$diasRaw : null;
    $body = trim((string)($input['body'] ?? ''));
    if ($body === '') throw new RuntimeException('Informe o texto do modelo de mensagem.');
    $ativo = !empty($input['ativo']) ? 1 : 0;

    if ($id > 0) {
      $st = $pdo->prepare('UPDATE saas_whatsapp_templates SET tipo = ?, titulo = ?, dias_referencia = ?, body = ?, ativo = ? WHERE id = ?');
      $st->execute([$tipo, $titulo, $diasRef, $body, $ativo, $id]);
      return $id;
    }
    $st = $pdo->prepare('INSERT INTO saas_whatsapp_templates (tipo, titulo, dias_referencia, body, ativo) VALUES (?, ?, ?, ?, ?)');
    $st->execute([$tipo, $titulo, $diasRef, $body, $ativo]);
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('saas_whatsapp_set_template_active')) {
  function saas_whatsapp_set_template_active(PDO $pdo, int $id, bool $active): void {
    saas_whatsapp_ensure_schema($pdo);
    $st = $pdo->prepare('UPDATE saas_whatsapp_templates SET ativo = ? WHERE id = ?');
    $st->execute([$active ? 1 : 0, $id]);
  }
}

if (!function_exists('saas_whatsapp_reset_default_templates')) {
  function saas_whatsapp_reset_default_templates(PDO $pdo): void {
    saas_whatsapp_ensure_schema($pdo);
    $pdo->exec('DELETE FROM saas_whatsapp_templates');
    $st = $pdo->prepare('INSERT INTO saas_whatsapp_templates (tipo, titulo, dias_referencia, body, ativo) VALUES (?, ?, ?, ?, 1)');
    foreach (saas_whatsapp_default_templates() as $tpl) {
      $st->execute([$tpl['tipo'], $tpl['titulo'], $tpl['dias_referencia'], $tpl['body']]);
    }
  }
}

if (!function_exists('saas_whatsapp_find_message_template')) {
  function saas_whatsapp_find_message_template(PDO $pdo, string $tipo, ?int $daysLeft = null): ?array {
    saas_whatsapp_ensure_schema($pdo);
    try {
      if ($tipo === 'credentials') {
        $st = $pdo->prepare("SELECT * FROM saas_whatsapp_templates WHERE tipo = 'credentials' AND ativo = 1 ORDER BY id ASC LIMIT 1");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
      }

      if ($daysLeft !== null) {
        $st = $pdo->prepare("SELECT * FROM saas_whatsapp_templates WHERE tipo = 'billing_notice' AND ativo = 1 AND dias_referencia = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$daysLeft]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;

        if ($daysLeft < 0) {
          $st = $pdo->prepare("SELECT * FROM saas_whatsapp_templates WHERE tipo = 'billing_notice' AND ativo = 1 AND dias_referencia = -1 ORDER BY id DESC LIMIT 1");
          $st->execute();
          $row = $st->fetch(PDO::FETCH_ASSOC);
          if ($row) return $row;
        }
      }

      $st = $pdo->prepare("SELECT * FROM saas_whatsapp_templates WHERE tipo = 'billing_notice' AND ativo = 1 AND dias_referencia IS NULL ORDER BY id DESC LIMIT 1");
      $st->execute();
      $row = $st->fetch(PDO::FETCH_ASSOC);
      return $row ?: null;
    } catch (Throwable $e) {
      return null;
    }
  }
}

if (!function_exists('saas_whatsapp_render_template')) {
  function saas_whatsapp_render_template(string $template, array $vars): string {
    $replace = [];
    foreach ($vars as $key => $value) {
      $replace['{{' . $key . '}}'] = (string)$value;
      $replace['{{ ' . $key . ' }}'] = (string)$value;
    }
    return trim(strtr($template, $replace));
  }
}

if (!function_exists('saas_whatsapp_register_unofficial_token')) {
  function saas_whatsapp_register_unofficial_token(array $currentCfg, int $instanceId, string $newToken): array {
    $newToken = trim($newToken);
    if ($newToken === '') return ['ok' => false, 'error' => 'Token vazio.'];
    // Motor novo (outra VPS): o registro usa o token administrativo do motor.
    if (!function_exists('whatsapp_engine_admin_token_for_url')) require_once __DIR__ . '/whatsapp_engine.php';
    $engineToken = whatsapp_engine_admin_token_for_url((string)($currentCfg['service_url'] ?? ''));
    if ($engineToken !== '') $currentCfg['api_token'] = $engineToken;
    try {
      return saas_whatsapp_unofficial_request($currentCfg, 'POST', '/tokens/upsert', [
        'company_id' => (string)$instanceId,
        'token' => $newToken,
      ]);
    } catch (Throwable $e) {
      return ['ok' => false, 'error' => $e->getMessage()];
    }
  }
}

if (!function_exists('saas_whatsapp_log')) {
  function saas_whatsapp_log(PDO $pdo, ?int $companyId, ?int $subscriptionId, ?int $chargeId, string $tipo, string $provider, string $destino, string $mensagem, string $status, string $erro = '', array $response = [], ?string $referenceDate = null): void {
    try {
      saas_whatsapp_ensure_schema($pdo);
      $st = $pdo->prepare('INSERT INTO saas_whatsapp_logs (company_id, subscription_id, charge_id, tipo, provider, destino, mensagem, status, erro, response_json, reference_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
      $st->execute([
        $companyId ?: null,
        $subscriptionId ?: null,
        $chargeId ?: null,
        mb_substr($tipo, 0, 50),
        mb_substr($provider, 0, 20),
        $destino !== '' ? mb_substr($destino, 0, 30) : null,
        $mensagem,
        mb_substr($status, 0, 30),
        $erro !== '' ? mb_substr($erro, 0, 255) : null,
        $response ? json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        $referenceDate ?: null,
      ]);
    } catch (Throwable $e) {}
  }
}

if (!function_exists('saas_whatsapp_unofficial_request')) {
  function saas_whatsapp_unofficial_request(array $cfg, string $method, string $path, array $payload = []): array {
    $base = rtrim((string)($cfg['service_url'] ?? ''), '/');
    if ($base === '') throw new RuntimeException('URL do serviço não oficial não configurada.');
    $url = $base . '/' . ltrim($path, '/');
    $token = trim((string)($cfg['api_token'] ?? ''));

    $headers = ['Content-Type: application/json'];
    if ($token !== '') {
      $headers[] = 'X-API-Token: ' . $token;
      $headers[] = 'Authorization: Bearer ' . $token;
      $headers[] = 'apikey: ' . $token;
    }

    $method = strtoupper($method);
    $timeout = str_starts_with('/' . ltrim($path, '/'), '/send') ? 35 : 15;
    $body = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
      ]);
      if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?: '{}');
      $raw = curl_exec($ch);
      $err = curl_error($ch);
      $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      if ($raw === false) throw new RuntimeException($err ?: 'Falha ao conectar no serviço WhatsApp.');
    } else {
      $opts = [
        'http' => [
          'method' => $method,
          'header' => implode("\r\n", $headers),
          'timeout' => $timeout,
          'ignore_errors' => true,
        ],
      ];
      if ($method !== 'GET') $opts['http']['content'] = $body ?: '{}';
      $raw = @file_get_contents($url, false, stream_context_create($opts));
      $code = 0;
      if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
          if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int)$m[1]; break; }
        }
      }
      if ($raw === false) throw new RuntimeException('Falha ao conectar no serviço WhatsApp.');
    }

    $data = json_decode((string)$raw, true);
    if (!is_array($data)) $data = ['ok' => false, 'raw' => (string)$raw];
    if ($code >= 400) {
      $msg = (string)($data['error'] ?? $data['message'] ?? ('HTTP ' . $code));
      throw new RuntimeException($msg);
    }
    return $data;
  }
}

if (!function_exists('saas_whatsapp_official_request')) {
  function saas_whatsapp_official_request(array $cfg, array $payload): array {
    $version = trim((string)($cfg['official_graph_version'] ?? 'v20.0')) ?: 'v20.0';
    if ($version[0] !== 'v') $version = 'v' . $version;
    $phoneNumberId = trim((string)($cfg['official_phone_number_id'] ?? ''));
    $token = trim((string)($cfg['official_access_token'] ?? ''));
    if ($phoneNumberId === '') throw new RuntimeException('Phone Number ID da API Oficial não configurado.');
    if ($token === '') throw new RuntimeException('Token da API Oficial não configurado.');

    $url = 'https://graph.facebook.com/' . rawurlencode($version) . '/' . rawurlencode($phoneNumberId) . '/messages';
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $token];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 35,
      ]);
      $raw = curl_exec($ch);
      $err = curl_error($ch);
      $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      if ($raw === false) throw new RuntimeException($err ?: 'Falha ao conectar na API Oficial do WhatsApp.');
    } else {
      $raw = @file_get_contents($url, false, stream_context_create([
        'http' => [
          'method' => 'POST',
          'header' => implode("\r\n", $headers),
          'content' => $body,
          'timeout' => 35,
          'ignore_errors' => true,
        ],
      ]));
      $code = 0;
      if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
          if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int)$m[1]; break; }
        }
      }
      if ($raw === false) throw new RuntimeException('Falha ao conectar na API Oficial do WhatsApp.');
    }

    $data = json_decode((string)$raw, true);
    if (!is_array($data)) $data = ['ok' => false, 'raw' => (string)$raw];
    if ($code >= 400) {
      $msg = (string)($data['error']['message'] ?? $data['error'] ?? $data['message'] ?? ('HTTP ' . $code));
      throw new RuntimeException($msg);
    }
    $data['ok'] = true;
    return $data;
  }
}

if (!function_exists('saas_whatsapp_template_for_type')) {
  function saas_whatsapp_template_for_type(array $cfg, string $tipo): string {
    return match ($tipo) {
      'credentials' => trim((string)($cfg['official_template_credentials'] ?? '')),
      'billing_notice' => trim((string)($cfg['official_template_billing'] ?? '')),
      default => '',
    };
  }
}

if (!function_exists('saas_whatsapp_send_message')) {
  function saas_whatsapp_send_message(PDO $pdo, string $to, string $message, string $tipo = 'manual', ?int $companyId = null, ?int $subscriptionId = null, ?int $chargeId = null, ?string $referenceDate = null, array $templateParams = []): array {
    $cfg = saas_whatsapp_load_config($pdo);
    $provider = (string)$cfg['provider'];
    $destino = saas_whatsapp_normalize_phone($to, (string)$cfg['default_country_code']);
    $message = trim($message);

    if (empty($cfg['enabled'])) {
      saas_whatsapp_log($pdo, $companyId, $subscriptionId, $chargeId, $tipo, $provider, $destino, $message, 'skipped', 'WhatsApp SaaS desativado.', [], $referenceDate);
      return ['ok' => false, 'skipped' => true, 'error' => 'WhatsApp SaaS desativado.'];
    }
    if ($destino === '' || $message === '') {
      saas_whatsapp_log($pdo, $companyId, $subscriptionId, $chargeId, $tipo, $provider, $destino, $message, 'error', 'Destino ou mensagem vazia.', [], $referenceDate);
      return ['ok' => false, 'error' => 'Destino ou mensagem vazia.'];
    }

    try {
      if ($provider === 'official') {
        $templateName = saas_whatsapp_template_for_type($cfg, $tipo);
        if ($templateName !== '' && $templateParams) {
          $params = [];
          foreach ($templateParams as $param) {
            $params[] = ['type' => 'text', 'text' => (string)$param];
          }
          $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $destino,
            'type' => 'template',
            'template' => [
              'name' => $templateName,
              'language' => ['code' => (string)$cfg['official_language_code']],
              'components' => [[
                'type' => 'body',
                'parameters' => $params,
              ]],
            ],
          ];
        } else {
          $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $destino,
            'type' => 'text',
            'text' => ['preview_url' => true, 'body' => $message],
          ];
        }
        $res = saas_whatsapp_official_request($cfg, $payload);
      } else {
        $res = saas_whatsapp_unofficial_request($cfg, 'POST', '/send', [
          'company_id' => saas_whatsapp_unofficial_instance_id($cfg),
          'to' => $destino,
          'message' => $message,
        ]);
      }

      $ok = !empty($res['ok']) || isset($res['messages']) || isset($res['message_id']);
      saas_whatsapp_log($pdo, $companyId, $subscriptionId, $chargeId, $tipo, $provider, $destino, $message, $ok ? 'sent' : 'error', $ok ? '' : (string)($res['error'] ?? 'Falha no envio'), $res, $referenceDate);
      return ['ok' => $ok, 'provider' => $provider, 'destino' => $destino, 'service_response' => $res];
    } catch (Throwable $e) {
      saas_whatsapp_log($pdo, $companyId, $subscriptionId, $chargeId, $tipo, $provider, $destino, $message, 'error', $e->getMessage(), [], $referenceDate);
      return ['ok' => false, 'provider' => $provider, 'destino' => $destino, 'error' => $e->getMessage()];
    }
  }
}

if (!function_exists('saas_whatsapp_company_links')) {
  function saas_whatsapp_company_links(array $company): array {
    return [
      'catalog_delivery' => company_catalog_url($company),
      'catalog_public' => company_public_catalog_url($company),
      'admin' => company_admin_url($company),
    ];
  }
}

if (!function_exists('saas_whatsapp_build_credentials_message')) {
  function saas_whatsapp_build_credentials_message(PDO $pdo, array $company, string $adminEmail, string $password, string $paymentUrl = '', array $eadInfo = [], array $signupContext = []): string {
    $name = trim((string)($company['name'] ?? 'Cliente')) ?: 'Cliente';
    $links = saas_whatsapp_company_links($company);
    $paymentUrl = trim($paymentUrl);

    // Link fixo/fallback da plataforma de treinamento.
    // Mantemos o fallback explícito para que a mensagem de WhatsApp nunca saia com
    // "Link de treinamento:" em branco, mesmo quando a integração EAD não retornar
    // login_url ou quando um modelo antigo usar {{link_treinamento}} diretamente.
    $defaultTrainingUrl = 'https://ead.prospero.tec.br/login.php';
    $eadLoginUrl = trim((string)($eadInfo['login_url'] ?? ''));
    if ($eadLoginUrl === '' && function_exists('ead_integration_login_url')) {
      $eadLoginUrl = trim((string)ead_integration_login_url());
    }
    if ($eadLoginUrl === '') {
      $eadLoginUrl = $defaultTrainingUrl;
    }
    $eadCourses = [];
    if (!empty($eadInfo['cursos_liberados']) && is_array($eadInfo['cursos_liberados'])) {
      foreach ($eadInfo['cursos_liberados'] as $curso) {
        $curso = trim((string)$curso);
        if ($curso !== '') $eadCourses[] = $curso;
      }
    }
    $eadCourseLabel = $eadCourses ? implode(', ', $eadCourses) : 'Sistema de Gestão Nexa Próspero';
    $trainingBlock = '';
    if ($eadLoginUrl !== '') {
      $trainingBlock = "Plataforma de Treinamentos Próspero EAD:
{$eadLoginUrl}

Curso liberado:
{$eadCourseLabel}

Use o mesmo usuário e senha acima para acessar o treinamento.

";
    }

    $vars = [
      'empresa' => $name,
      'cliente' => $name,
      'nome_empresa' => $name,
      'subdominio' => (string)($company['subdomain'] ?? ''),
      'link_catalogo_delivery' => $links['catalog_delivery'],
      'link_catalogo' => $links['catalog_public'],
      'link_catalogo_produtos' => $links['catalog_public'],
      'link_admin' => $links['admin'],
      'usuario' => $adminEmail,
      'email' => $adminEmail,
      'senha' => $password,
      'senha_inicial' => $password,
      'link_criar_senha' => $password,
      'link_ead' => $eadLoginUrl,
      'link_treinamento' => $eadLoginUrl,
      'curso_treinamento' => $eadCourseLabel,
      'cursos_treinamento' => $eadCourseLabel,
      'bloco_treinamento' => $trainingBlock,
      'link_pagamento' => $paymentUrl,
      'bloco_pagamento' => $paymentUrl !== '' ? "Link de pagamento Pix:\n{$paymentUrl}\n\n" : '',
    ];

    $tpl = saas_whatsapp_find_message_template($pdo, 'credentials');
    if ($tpl && trim((string)($tpl['body'] ?? '')) !== '') {
      $body = (string)$tpl['body'];
      $rendered = saas_whatsapp_render_template($body, $vars);
      if ($trainingBlock !== '' && strpos($body, '{{bloco_treinamento') === false && strpos($body, '{{ bloco_treinamento') === false) {
        $rendered .= "

" . trim($trainingBlock);
      }
      // Proteção para modelos personalizados antigos que tenham o texto
      // "Link de treinamento:" e, por algum motivo, não tenham renderizado a URL.
      if ($eadLoginUrl !== '' && preg_match('/Link de treinamento:\s*(?:\r?\n)?(?:$|\r?\n)/i', $rendered)) {
        $rendered = preg_replace('/Link de treinamento:\s*(?:\r?\n)?/i', "Link de treinamento:
" . $eadLoginUrl . "
", $rendered, 1);
      }
      $rendered = trim($rendered);
      $planLabel = trim((string)($signupContext['plan_label'] ?? ''));
      $deliveryTier = trim((string)($signupContext['delivery_tier'] ?? ''));
      $deliveryLabels = ['start' => 'Delivery Start — até 100 pedidos/mês', 'pro' => 'Delivery Pro — 101 a 500 pedidos/mês', 'premium' => 'Delivery Premium — 501 a 1.000 pedidos/mês'];
      if ($planLabel !== '') $rendered .= "\n\n*Plano selecionado*\n" . $planLabel . "\n";
      if (isset($deliveryLabels[$deliveryTier])) $rendered .= $deliveryLabels[$deliveryTier] . "\n";
      $moduleLabels = $signupContext['module_labels'] ?? [];
      if (is_array($moduleLabels) && $moduleLabels) {
        $rendered .= "\n*Módulos liberados*\n";
        foreach ($moduleLabels as $label) $rendered .= "✅ " . trim((string)$label) . "\n";
      }
      if (!empty($signupContext['trial_start']) || !empty($signupContext['trial_end'])) {
        $rendered .= "\n*Período de teste*\n";
        if (!empty($signupContext['trial_start'])) $rendered .= "Início: " . $signupContext['trial_start'] . "\n";
        if (!empty($signupContext['trial_end'])) $rendered .= "Término: " . $signupContext['trial_end'] . "\n";
      }
      return password_setup_adjust_message(trim($rendered), $password);
    }

    $msg = "Olá, {$name}!\n\n";
    $msg .= "Seu catálogo Nexa Próspero foi criado com sucesso.\n\n";
    $msg .= "Catálogo Delivery:\n{$links['catalog_delivery']}\n\n";
    $msg .= "Catálogo somente produtos:\n{$links['catalog_public']}\n\n";
    $msg .= "Painel Administrativo:\n{$links['admin']}\n\n";
    $msg .= "Usuário do admin:\n{$adminEmail}\n\n";
    $msg .= "Senha inicial:\n{$password}\n\n";
    if ($trainingBlock !== '') {
      $msg .= $trainingBlock;
    }
    if ($paymentUrl !== '') {
      $msg .= "Link de pagamento Pix:\n{$paymentUrl}\n\n";
    }
    $msg .= "Recomendamos alterar a senha após o primeiro acesso.\n";
    $planLabel = trim((string)($signupContext['plan_label'] ?? ''));
    $deliveryTier = trim((string)($signupContext['delivery_tier'] ?? ''));
    $deliveryLabels = ['start' => 'Delivery Start — até 100 pedidos/mês', 'pro' => 'Delivery Pro — 101 a 500 pedidos/mês', 'premium' => 'Delivery Premium — 501 a 1.000 pedidos/mês'];
    if ($planLabel !== '') $msg .= "\nPlano selecionado:\n{$planLabel}\n";
    if (isset($deliveryLabels[$deliveryTier])) $msg .= $deliveryLabels[$deliveryTier] . "\n";
    $moduleLabels = $signupContext['module_labels'] ?? [];
    if (is_array($moduleLabels) && $moduleLabels) {
      $msg .= "\nMódulos liberados:\n";
      foreach ($moduleLabels as $label) $msg .= "✅ " . trim((string)$label) . "\n";
    }
    if (!empty($signupContext['trial_start']) || !empty($signupContext['trial_end'])) {
      $msg .= "\nPeríodo de teste:\n";
      if (!empty($signupContext['trial_start'])) $msg .= "Início: " . $signupContext['trial_start'] . "\n";
      if (!empty($signupContext['trial_end'])) $msg .= "Término: " . $signupContext['trial_end'] . "\n";
    }
    return password_setup_adjust_message(trim($msg), $password);
  }
}

if (!function_exists('saas_whatsapp_send_credentials')) {
  function saas_whatsapp_send_credentials(PDO $pdo, int $companyId, string $password, string $paymentUrl = '', array $eadInfo = [], array $signupContext = []): array {
    $cfg = saas_whatsapp_load_config($pdo);
    if (empty($cfg['auto_send_credentials'])) {
      saas_whatsapp_log($pdo, $companyId, null, null, 'credentials', (string)$cfg['provider'], '', '', 'skipped', 'Envio automático de credenciais desativado.');
      return ['ok' => false, 'skipped' => true, 'error' => 'Envio automático de credenciais desativado.'];
    }

    $st = $pdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
    $st->execute([$companyId]);
    $company = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$company) return ['ok' => false, 'error' => 'Empresa não encontrada.'];

    $st = $pdo->prepare("SELECT * FROM users WHERE company_id = ? AND role = 'company_admin' ORDER BY id ASC LIMIT 1");
    $st->execute([$companyId]);
    $admin = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$admin) return ['ok' => false, 'error' => 'Usuário admin não encontrado.'];

    $to = (string)($company['whatsapp'] ?? '');
    if ($to === '' && isset($company['celular'])) $to = (string)$company['celular'];
    $adminEmail = (string)$admin['email'];
    $message = saas_whatsapp_build_credentials_message($pdo, $company, $adminEmail, $password, $paymentUrl, $eadInfo, $signupContext);
    $links = saas_whatsapp_company_links($company);

    return saas_whatsapp_send_message(
      $pdo,
      $to,
      $message,
      'credentials',
      $companyId,
      null,
      null,
      null,
      [
        (string)($company['name'] ?? 'Cliente'),
        $links['catalog_delivery'],
        $links['catalog_public'],
        $links['admin'],
        $adminEmail,
        $password,
        (string)($eadInfo['login_url'] ?? (function_exists('ead_integration_login_url') ? ead_integration_login_url() : 'https://ead.prospero.tec.br/login.php')),
        $paymentUrl,
      ]
    );
  }
}

if (!function_exists('saas_whatsapp_build_billing_message')) {
  function saas_whatsapp_build_billing_message(PDO $pdo, array $row): string {
    $company = trim((string)($row['company_name'] ?? 'Cliente')) ?: 'Cliente';
    $plan = trim((string)($row['plan_name'] ?? 'Plano contratado')) ?: 'Plano contratado';
    $amount = money((float)($row['price'] ?? 0));
    $dueRaw = (string)($row['due_at'] ?? $row['ends_at'] ?? $row['next_billing_at'] ?? '');
    try { $dueLabel = (new DateTimeImmutable($dueRaw))->format('d/m/Y'); } catch (Throwable $e) { $dueLabel = $dueRaw ?: 'em breve'; }
    $days = (int)($row['days_left'] ?? 0);
    if ($days === 0) $when = 'vence hoje';
    elseif ($days === 1) $when = 'vence amanhã';
    elseif ($days > 1) $when = 'vence em ' . $days . ' dias';
    else $when = 'venceu há ' . abs($days) . ' dia(s)';
    $paymentUrl = trim((string)($row['payment_url'] ?? ''));

    $vars = [
      'empresa' => $company,
      'cliente' => $company,
      'nome_empresa' => $company,
      'plano' => $plan,
      'valor' => $amount,
      'vencimento' => $dueLabel,
      'dias' => (string)abs($days),
      'dias_restantes' => (string)max(0, $days),
      'dias_vencido' => (string)max(0, abs(min(0, $days))),
      'quando' => $when,
      'link_pagamento' => $paymentUrl,
      'pix' => $paymentUrl,
    ];

    $tpl = saas_whatsapp_find_message_template($pdo, 'billing_notice', $days);
    if ($tpl && trim((string)($tpl['body'] ?? '')) !== '') {
      $body = (string)$tpl['body'];
      $rendered = saas_whatsapp_render_template($body, $vars);
      $rendered = trim($rendered);
      $moduleLabels = $signupContext['module_labels'] ?? [];
      if (is_array($moduleLabels) && $moduleLabels) {
        $rendered .= "\n\n*Módulos liberados*\n";
        foreach ($moduleLabels as $label) $rendered .= "✅ " . trim((string)$label) . "\n";
      }
      if (!empty($signupContext['trial_start']) || !empty($signupContext['trial_end'])) {
        $rendered .= "\n*Período de teste*\n";
        if (!empty($signupContext['trial_start'])) $rendered .= "Início: " . $signupContext['trial_start'] . "\n";
        if (!empty($signupContext['trial_end'])) $rendered .= "Término: " . $signupContext['trial_end'] . "\n";
      }
      return trim($rendered);
    }

    $msg = "Olá, {$company}!\n\n";
    $msg .= "Passando para lembrar que sua assinatura do sistema Nexa Próspero {$when}, em {$dueLabel}.\n\n";
    $msg .= "Plano: {$plan}\nValor: {$amount}\n\n";
    if ($paymentUrl !== '') {
      $msg .= "Para evitar bloqueio do catálogo e do painel administrativo, realize o pagamento pelo link abaixo:\n{$paymentUrl}\n\n";
    } else {
      $msg .= "Para evitar bloqueio do catálogo e do painel administrativo, regularize o pagamento com nossa equipe.\n\n";
    }
    $msg .= "Caso já tenha efetuado o pagamento, desconsidere esta mensagem.";
    return $msg;
  }
}

if (!function_exists('saas_whatsapp_send_billing_notice')) {
  function saas_whatsapp_send_billing_notice(PDO $pdo, array $row): array {
    $companyId = (int)$row['company_id'];
    $to = (string)($row['whatsapp'] ?? '');
    $message = saas_whatsapp_build_billing_message($pdo, $row);
    $dueRaw = (string)($row['due_at'] ?? $row['ends_at'] ?? $row['next_billing_at'] ?? '');
    $referenceDate = null;
    try { $referenceDate = (new DateTimeImmutable($dueRaw))->format('Y-m-d'); } catch (Throwable $e) {}
    $dueLabel = $referenceDate ? (new DateTimeImmutable($referenceDate))->format('d/m/Y') : '';
    $days = (int)($row['days_left'] ?? 0);
    $when = $days === 0 ? 'vence hoje' : ($days === 1 ? 'vence amanhã' : ('vence em ' . $days . ' dias'));

    return saas_whatsapp_send_message(
      $pdo,
      $to,
      $message,
      'billing_notice',
      $companyId,
      (int)($row['subscription_id'] ?? 0),
      (int)($row['charge_id'] ?? 0),
      $referenceDate,
      [
        (string)($row['company_name'] ?? 'Cliente'),
        $when,
        $dueLabel,
        (string)($row['plan_name'] ?? ''),
        money((float)($row['price'] ?? 0)),
        (string)($row['payment_url'] ?? ''),
      ]
    );
  }
}

if (!function_exists('saas_whatsapp_send_due_notices')) {
  function saas_whatsapp_send_due_notices(PDO $pdo): array {
    saas_billing_ensure_schema($pdo);
    $cfg = saas_whatsapp_load_config($pdo);
    $summary = ['checked' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => 0, 'charges_created' => 0, 'charge_errors' => 0, 'disabled' => false];
    if (empty($cfg['enabled']) || empty($cfg['auto_send_billing'])) {
      $summary['disabled'] = true;
      return $summary;
    }

    $days = max(1, min(15, (int)$cfg['billing_notice_days']));
    $graceDays = max(0, min(30, (int)$cfg['block_grace_days']));
    $sql = "
      SELECT
        c.id AS company_id,
        c.name AS company_name,
        c.email,
        c.whatsapp,
        c.status AS company_status,
        s.id AS subscription_id,
        s.plan_id,
        s.status AS subscription_status,
        s.ends_at,
        s.next_billing_at,
        COALESCE(s.next_billing_at, s.ends_at) AS due_at,
        DATEDIFF(DATE(COALESCE(s.next_billing_at, s.ends_at)), CURDATE()) AS days_left,
        p.name AS plan_name,
        p.price,
        (
          SELECT sc.id
          FROM saas_charges sc
          WHERE sc.company_id = c.id
            AND sc.status IN ('open','processing')
          ORDER BY sc.id DESC
          LIMIT 1
        ) AS charge_id,
        (
          SELECT sc.payment_url
          FROM saas_charges sc
          WHERE sc.company_id = c.id
            AND sc.status IN ('open','processing')
          ORDER BY sc.id DESC
          LIMIT 1
        ) AS payment_url
      FROM companies c
      INNER JOIN (
        SELECT s1.*
        FROM subscriptions s1
        INNER JOIN (
          SELECT company_id, MAX(id) AS max_id
          FROM subscriptions
          GROUP BY company_id
        ) x ON x.max_id = s1.id
      ) s ON s.company_id = c.id
      LEFT JOIN plans p ON p.id = s.plan_id
      WHERE c.status = 'active'
        AND s.status IN ('trial','active','pending_payment')
        AND c.whatsapp IS NOT NULL
        AND c.whatsapp <> ''
        AND COALESCE(s.next_billing_at, s.ends_at) IS NOT NULL
        AND DATE(COALESCE(s.next_billing_at, s.ends_at)) BETWEEN DATE_SUB(CURDATE(), INTERVAL {$graceDays} DAY) AND DATE_ADD(CURDATE(), INTERVAL {$days} DAY)
        AND NOT EXISTS (
          SELECT 1
          FROM saas_whatsapp_logs l
          WHERE l.company_id = c.id
            AND l.tipo = 'billing_notice'
            AND DATE(l.created_at) = CURDATE()
        )
      ORDER BY due_at ASC, c.id ASC
    ";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
      $summary['checked']++;

      // Se não houver cobrança aberta e o plano tiver valor, tenta gerar Pix antes de enviar o aviso.
      if (empty($row['payment_url']) && (float)($row['price'] ?? 0) > 0) {
        try {
          $company = saas_find_company($pdo, (int)$row['company_id']);
          $plan = saas_find_plan($pdo, (int)$row['plan_id']);
          if ($company && $plan) {
            $charge = saas_create_pix_charge($pdo, $company, $plan, (int)$row['subscription_id'], 'Renovação da assinatura do plano ' . (string)($plan['name'] ?? ''));
            $row['charge_id'] = (int)($charge['id'] ?? 0);
            $row['payment_url'] = (string)($charge['payment_url'] ?? '');
            $summary['charges_created']++;
          }
        } catch (Throwable $e) {
          $summary['charge_errors']++;
          saas_whatsapp_log($pdo, (int)$row['company_id'], (int)$row['subscription_id'], null, 'billing_charge_create', (string)$cfg['provider'], '', '', 'error', $e->getMessage());
        }
      }

      $res = saas_whatsapp_send_billing_notice($pdo, $row);
      if (!empty($res['ok'])) $summary['sent']++;
      elseif (!empty($res['skipped'])) $summary['skipped']++;
      else $summary['errors']++;
    }
    return $summary;
  }
}

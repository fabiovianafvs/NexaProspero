<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/resellers.php';
require_once __DIR__ . '/saas_whatsapp_service.php';
require_once __DIR__ . '/pagbank.php';

if (!function_exists('reseller_integrations_color')) {
  function reseller_integrations_color(mixed $value, string $fallback): string {
    $value = strtolower(trim((string)$value));
    return preg_match('/^#[0-9a-f]{6}$/', $value) ? $value : $fallback;
  }
}

if (!function_exists('reseller_integrations_ensure_schema')) {
  function reseller_integrations_ensure_schema(PDO $pdo): array {
    static $cached = null;
    if (is_array($cached)) return $cached;
    $errors = [];
    try { reseller_ensure_schema($pdo); } catch (Throwable $e) { $errors[] = $e->getMessage(); }

    foreach ([
      'reseller_logo' => "VARCHAR(255) NULL",
      'reseller_primary_color' => "VARCHAR(20) NULL",
      'reseller_secondary_color' => "VARCHAR(20) NULL",
      'reseller_menu_text_color' => "VARCHAR(20) NULL",
    ] as $column => $definition) {
      reseller_add_column($pdo, 'companies', $column, $definition, $errors);
    }

    reseller_exec($pdo, "CREATE TABLE IF NOT EXISTS saas_reseller_whatsapp_config (
      reseller_company_id INT UNSIGNED NOT NULL,
      enabled TINYINT(1) NOT NULL DEFAULT 0,
      provider VARCHAR(20) NOT NULL DEFAULT 'unofficial',
      service_url VARCHAR(255) NULL,
      api_token LONGTEXT NULL,
      unofficial_instance_id VARCHAR(80) NULL,
      official_graph_version VARCHAR(20) NOT NULL DEFAULT 'v20.0',
      official_phone_number_id VARCHAR(100) NULL,
      official_access_token LONGTEXT NULL,
      official_language_code VARCHAR(10) NOT NULL DEFAULT 'pt_BR',
      auto_send_credentials TINYINT(1) NOT NULL DEFAULT 1,
      auto_send_billing TINYINT(1) NOT NULL DEFAULT 1,
      auto_send_status TINYINT(1) NOT NULL DEFAULT 1,
      billing_notice_days INT NOT NULL DEFAULT 3,
      block_grace_days INT NOT NULL DEFAULT 2,
      default_country_code VARCHAR(5) NOT NULL DEFAULT '55',
      test_phone VARCHAR(30) NULL,
      cron_token VARCHAR(100) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (reseller_company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $errors);

    reseller_exec($pdo, "CREATE TABLE IF NOT EXISTS saas_reseller_whatsapp_templates (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      reseller_company_id INT UNSIGNED NOT NULL,
      tipo VARCHAR(30) NOT NULL DEFAULT 'billing_notice',
      titulo VARCHAR(120) NOT NULL,
      dias_referencia INT NULL,
      body LONGTEXT NOT NULL,
      ativo TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_reseller_wa_tpl_owner (reseller_company_id,tipo,dias_referencia,ativo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $errors);

    reseller_exec($pdo, "CREATE TABLE IF NOT EXISTS saas_reseller_whatsapp_logs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      reseller_company_id INT UNSIGNED NOT NULL,
      client_company_id INT UNSIGNED NULL,
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
      PRIMARY KEY (id),
      KEY idx_reseller_wa_logs_owner (reseller_company_id,created_at),
      KEY idx_reseller_wa_logs_charge (charge_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $errors);

    reseller_exec($pdo, "CREATE TABLE IF NOT EXISTS saas_reseller_pagbank_config (
      reseller_company_id INT UNSIGNED NOT NULL,
      environment VARCHAR(20) NOT NULL DEFAULT 'sandbox',
      token LONGTEXT NULL,
      webhook_url VARCHAR(255) NULL,
      webhook_token VARCHAR(100) NULL,
      pix_expiration_minutes INT NOT NULL DEFAULT 1440,
      max_installments INT NOT NULL DEFAULT 12,
      soft_descriptor VARCHAR(17) DEFAULT 'NEXAPROSPERO',
      pix_enabled TINYINT(1) NOT NULL DEFAULT 1,
      card_enabled TINYINT(1) NOT NULL DEFAULT 1,
      active TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (reseller_company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $errors);

    if (reseller_table_exists($pdo, 'saas_reseller_pagbank_config')) {
      reseller_add_column($pdo, 'saas_reseller_pagbank_config', 'max_installments', "INT NOT NULL DEFAULT 12", $errors);
      reseller_add_column($pdo, 'saas_reseller_pagbank_config', 'card_enabled', "TINYINT(1) NOT NULL DEFAULT 1", $errors);
    }

    if (reseller_table_exists($pdo, 'saas_reseller_customer_charges')) {
      foreach ([
        'payment_gateway' => "VARCHAR(30) NULL",
        'payment_method' => "VARCHAR(20) NULL",
        'gateway_reference' => "VARCHAR(120) NULL",
        'gateway_status' => "VARCHAR(50) NULL",
        'payment_url' => "VARCHAR(500) NULL",
        'pix_copy_paste' => "LONGTEXT NULL",
        'qr_image' => "LONGTEXT NULL",
        'public_token' => "VARCHAR(100) NULL",
        'payment_payload' => "LONGTEXT NULL",
      ] as $column => $definition) {
        reseller_add_column($pdo, 'saas_reseller_customer_charges', $column, $definition, $errors);
      }
      reseller_add_index($pdo, 'saas_reseller_customer_charges', 'idx_reseller_charge_gateway_ref', '`gateway_reference`', $errors);
    }

    return $cached = ['ok' => !$errors, 'errors' => $errors];
  }
}

if (!function_exists('reseller_theme_config')) {
  function reseller_theme_config(array $reseller): array {
    return [
      'brand_name' => trim((string)($reseller['reseller_brand_name'] ?? $reseller['name'] ?? 'Revenda')) ?: 'Revenda',
      'logo' => trim((string)($reseller['reseller_logo'] ?? '')),
      'primary' => reseller_integrations_color($reseller['reseller_primary_color'] ?? '', '#0b1f5b'),
      'secondary' => reseller_integrations_color($reseller['reseller_secondary_color'] ?? '', '#2454d6'),
      'menu_text' => reseller_integrations_color($reseller['reseller_menu_text_color'] ?? '', '#ffffff'),
    ];
  }
}

if (!function_exists('reseller_theme_logo_url')) {
  function reseller_theme_logo_url(array $reseller): string {
    $logo = trim((string)($reseller['reseller_logo'] ?? ''));
    if ($logo === '') return '/assets/logo-nexa-prospero.jpg';
    if (preg_match('#^https?://#i', $logo)) return $logo;
    return '/' . ltrim($logo, '/');
  }
}

if (!function_exists('reseller_save_theme')) {
  function reseller_save_theme(PDO $pdo, int $resellerCompanyId, array $input, array $file = []): array {
    reseller_integrations_ensure_schema($pdo);
    $company = reseller_company_by_id($pdo, $resellerCompanyId);
    if (!$company || !reseller_is_active_company($company)) throw new RuntimeException('Revenda inválida ou inativa.');

    $brand = trim((string)($input['brand_name'] ?? $company['reseller_brand_name'] ?? $company['name'] ?? ''));
    if ($brand === '') throw new RuntimeException('Informe o nome comercial da revenda.');
    $primary = reseller_integrations_color($input['primary_color'] ?? '', '#0b1f5b');
    $secondary = reseller_integrations_color($input['secondary_color'] ?? '', '#2454d6');
    $text = reseller_integrations_color($input['menu_text_color'] ?? '', '#ffffff');
    $logo = trim((string)($company['reseller_logo'] ?? ''));

    if (!empty($input['remove_logo'])) $logo = '';
    if (!empty($file['tmp_name']) && is_uploaded_file((string)$file['tmp_name'])) {
      $max = 3 * 1024 * 1024;
      if ((int)($file['size'] ?? 0) > $max) throw new RuntimeException('A logomarca deve ter no máximo 3 MB.');
      $finfo = new finfo(FILEINFO_MIME_TYPE);
      $mime = (string)$finfo->file((string)$file['tmp_name']);
      $extensions = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
      if (!isset($extensions[$mime])) throw new RuntimeException('Envie uma imagem PNG, JPG ou WEBP.');
      $relativeDir = 'uploads/resellers/' . $resellerCompanyId;
      $absoluteDir = dirname(__DIR__) . '/' . $relativeDir;
      if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) throw new RuntimeException('Não foi possível criar a pasta da logomarca.');
      $filename = 'logo-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $extensions[$mime];
      if (!move_uploaded_file((string)$file['tmp_name'], $absoluteDir . '/' . $filename)) throw new RuntimeException('Não foi possível salvar a logomarca.');
      $logo = $relativeDir . '/' . $filename;
    }

    $st = $pdo->prepare('UPDATE companies SET reseller_brand_name=?,reseller_logo=?,reseller_primary_color=?,reseller_secondary_color=?,reseller_menu_text_color=? WHERE id=? AND is_reseller=1');
    $st->execute([$brand, $logo !== '' ? $logo : null, $primary, $secondary, $text, $resellerCompanyId]);
    return reseller_company_by_id($pdo, $resellerCompanyId) ?: $company;
  }
}

if (!function_exists('reseller_whatsapp_default_instance_id')) {
  function reseller_whatsapp_default_instance_id(int $resellerCompanyId): string {
    // Faixa reservada para revendas no serviço WebJS. Preserva as instâncias antigas
    // já configuradas; esta regra vale para novas configurações ou IDs vazios.
    $id = max(1, $resellerCompanyId);
    return (string)(3000000000 + $id);
  }
}

if (!function_exists('reseller_whatsapp_default_config')) {
  function reseller_whatsapp_default_config(int $resellerCompanyId): array {
    return [
      'reseller_company_id' => $resellerCompanyId,
      'enabled' => false,
      'provider' => 'unofficial',
      'service_url' => getenv('SAAS_WHATSAPP_SERVICE_URL') ?: (function_exists('saas_whatsapp_default_service_url') ? saas_whatsapp_default_service_url() : (getenv('WHATSAPP_SERVICE_URL') ?: 'http://127.0.0.1:3333')),
      'api_token' => '',
      'unofficial_instance_id' => reseller_whatsapp_default_instance_id($resellerCompanyId),
      'official_graph_version' => 'v20.0',
      'official_phone_number_id' => '',
      'official_access_token' => '',
      'official_language_code' => 'pt_BR',
      'auto_send_credentials' => true,
      'auto_send_billing' => true,
      'auto_send_status' => true,
      'billing_notice_days' => 3,
      'block_grace_days' => 2,
      'default_country_code' => '55',
      'test_phone' => '',
      'cron_token' => bin2hex(random_bytes(24)),
    ];
  }
}

if (!function_exists('reseller_whatsapp_load_config')) {
  function reseller_whatsapp_load_config(PDO $pdo, int $resellerCompanyId): array {
    reseller_integrations_ensure_schema($pdo);
    $cfg = reseller_whatsapp_default_config($resellerCompanyId);
    $st = $pdo->prepare('SELECT * FROM saas_reseller_whatsapp_config WHERE reseller_company_id=? LIMIT 1');
    $st->execute([$resellerCompanyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($row as $k => $v) $cfg[$k] = $v;
    $cfg['enabled'] = !empty($cfg['enabled']);
    $cfg['provider'] = in_array((string)$cfg['provider'], ['unofficial','official'], true) ? (string)$cfg['provider'] : 'unofficial';
    $cfg['service_url'] = rtrim(trim((string)$cfg['service_url']), '/');
    if ($cfg['service_url'] === '') $cfg['service_url'] = (string)reseller_whatsapp_default_config($resellerCompanyId)['service_url'];
    $rawInstanceId = preg_replace('/\D+/', '', trim((string)$cfg['unofficial_instance_id'])) ?: '';
    $cfg['unofficial_instance_id'] = ($rawInstanceId !== '' && $rawInstanceId !== '0') ? $rawInstanceId : reseller_whatsapp_default_instance_id($resellerCompanyId);
    $cfg['auto_send_credentials'] = !empty($cfg['auto_send_credentials']);
    $cfg['auto_send_billing'] = !empty($cfg['auto_send_billing']);
    $cfg['auto_send_status'] = !empty($cfg['auto_send_status']);
    $cfg['billing_notice_days'] = max(1, min(15, (int)$cfg['billing_notice_days']));
    $cfg['block_grace_days'] = max(0, min(30, (int)$cfg['block_grace_days']));
    $cfg['default_country_code'] = saas_whatsapp_digits((string)$cfg['default_country_code']) ?: '55';
    if (trim((string)$cfg['cron_token']) === '') $cfg['cron_token'] = bin2hex(random_bytes(24));
    return $cfg;
  }
}

if (!function_exists('reseller_whatsapp_save_config')) {
  function reseller_whatsapp_save_config(PDO $pdo, int $resellerCompanyId, array $input): array {
    $old = reseller_whatsapp_load_config($pdo, $resellerCompanyId);
    $provider = in_array((string)($input['provider'] ?? ''), ['unofficial','official'], true) ? (string)$input['provider'] : 'unofficial';
    $apiToken = trim((string)($input['api_token'] ?? $old['api_token'] ?? ''));
    $instanceId = preg_replace('/\D+/', '', trim((string)($input['unofficial_instance_id'] ?? $old['unofficial_instance_id'] ?? ''))) ?: '';
    if ($instanceId === '' || $instanceId === '0') $instanceId = reseller_whatsapp_default_instance_id($resellerCompanyId);
    $cronToken = trim((string)($input['cron_token'] ?? $old['cron_token'] ?? '')) ?: bin2hex(random_bytes(24));
    $data = [
      'enabled' => !empty($input['enabled']) ? 1 : 0,
      'provider' => $provider,
      'service_url' => rtrim(trim((string)($input['service_url'] ?? $old['service_url'] ?? '')), '/'),
      'api_token' => $apiToken,
      'unofficial_instance_id' => $instanceId,
      'official_graph_version' => trim((string)($input['official_graph_version'] ?? 'v20.0')) ?: 'v20.0',
      'official_phone_number_id' => trim((string)($input['official_phone_number_id'] ?? '')),
      'official_access_token' => trim((string)($input['official_access_token'] ?? '')),
      'official_language_code' => trim((string)($input['official_language_code'] ?? 'pt_BR')) ?: 'pt_BR',
      'auto_send_credentials' => !empty($input['auto_send_credentials']) ? 1 : 0,
      'auto_send_billing' => !empty($input['auto_send_billing']) ? 1 : 0,
      'auto_send_status' => !empty($input['auto_send_status']) ? 1 : 0,
      'billing_notice_days' => max(1, min(15, (int)($input['billing_notice_days'] ?? 3))),
      'block_grace_days' => max(0, min(30, (int)($input['block_grace_days'] ?? 2))),
      'default_country_code' => saas_whatsapp_digits((string)($input['default_country_code'] ?? '55')) ?: '55',
      'test_phone' => saas_whatsapp_digits((string)($input['test_phone'] ?? '')),
      'cron_token' => $cronToken,
    ];
    $sql = "INSERT INTO saas_reseller_whatsapp_config
      (reseller_company_id,enabled,provider,service_url,api_token,unofficial_instance_id,official_graph_version,official_phone_number_id,official_access_token,official_language_code,auto_send_credentials,auto_send_billing,auto_send_status,billing_notice_days,block_grace_days,default_country_code,test_phone,cron_token)
      VALUES (:reseller_company_id,:enabled,:provider,:service_url,:api_token,:unofficial_instance_id,:official_graph_version,:official_phone_number_id,:official_access_token,:official_language_code,:auto_send_credentials,:auto_send_billing,:auto_send_status,:billing_notice_days,:block_grace_days,:default_country_code,:test_phone,:cron_token)
      ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),provider=VALUES(provider),service_url=VALUES(service_url),api_token=VALUES(api_token),unofficial_instance_id=VALUES(unofficial_instance_id),official_graph_version=VALUES(official_graph_version),official_phone_number_id=VALUES(official_phone_number_id),official_access_token=VALUES(official_access_token),official_language_code=VALUES(official_language_code),auto_send_credentials=VALUES(auto_send_credentials),auto_send_billing=VALUES(auto_send_billing),auto_send_status=VALUES(auto_send_status),billing_notice_days=VALUES(billing_notice_days),block_grace_days=VALUES(block_grace_days),default_country_code=VALUES(default_country_code),test_phone=VALUES(test_phone),cron_token=VALUES(cron_token)";
    $pdo->prepare($sql)->execute(['reseller_company_id'=>$resellerCompanyId] + $data);
    reseller_whatsapp_seed_templates($pdo, $resellerCompanyId);
    return reseller_whatsapp_load_config($pdo, $resellerCompanyId);
  }
}

if (!function_exists('reseller_whatsapp_default_templates')) {
  function reseller_whatsapp_default_templates(): array {
    return [
      ['credentials','Credenciais do novo cliente',null,"Olá, {{empresa}}!\n\nSeu ambiente foi criado com sucesso pela {{revenda}}.\n\nPainel administrativo:\n{{link_admin}}\n\nCatálogo de pedidos:\n{{link_catalogo_delivery}}\n\nUsuário: {{usuario}}\nCrie sua senha de acesso (link válido por 48 horas):\n{{link_criar_senha}}\n\n{{bloco_treinamento}}Atendimento da revenda: {{whatsapp_revenda}}"],
      ['billing_notice','Cobrança disponível',null,"Olá, {{empresa}}!\n\nSua cobrança está disponível.\nDescrição: {{descricao}}\nValor: {{valor}}\nVencimento: {{vencimento}}\n\nLink para pagamento:\n{{link_pagamento}}"],
      ['billing_notice','Faltam 3 dias para vencer',3,"Olá, {{empresa}}!\n\nSua cobrança vence em {{dias}} dias.\nValor: {{valor}}\nVencimento: {{vencimento}}\n\nPagamento:\n{{link_pagamento}}"],
      ['billing_notice','Vence hoje',0,"Olá, {{empresa}}!\n\nSua cobrança vence hoje.\nValor: {{valor}}\n\nPagamento:\n{{link_pagamento}}"],
      ['billing_notice','Cobrança vencida',-1,"Olá, {{empresa}}!\n\nSua cobrança está vencida há {{dias_vencido}} dia(s).\nValor: {{valor}}\nVencimento: {{vencimento}}\n\nPagamento:\n{{link_pagamento}}"],
      ['status_notice','Cliente bloqueado',-100,"Olá, {{empresa}}.\n\nSeu acesso foi bloqueado pela revenda. Entre em contato para regularizar sua situação."],
      ['status_notice','Cliente reativado',100,"Olá, {{empresa}}!\n\nSeu acesso foi reativado e já está disponível novamente."],
    ];
  }
}

if (!function_exists('reseller_whatsapp_seed_templates')) {
  function reseller_whatsapp_seed_templates(PDO $pdo, int $resellerCompanyId): void {
    reseller_integrations_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT COUNT(*) FROM saas_reseller_whatsapp_templates WHERE reseller_company_id=?');
    $st->execute([$resellerCompanyId]);
    if ((int)$st->fetchColumn() > 0) return;
    $ins = $pdo->prepare('INSERT INTO saas_reseller_whatsapp_templates (reseller_company_id,tipo,titulo,dias_referencia,body,ativo) VALUES (?,?,?,?,?,1)');
    foreach (reseller_whatsapp_default_templates() as $row) $ins->execute([$resellerCompanyId,$row[0],$row[1],$row[2],$row[3]]);
  }
}

if (!function_exists('reseller_whatsapp_templates')) {
  function reseller_whatsapp_templates(PDO $pdo, int $resellerCompanyId): array {
    reseller_whatsapp_seed_templates($pdo, $resellerCompanyId);
    $st = $pdo->prepare("SELECT * FROM saas_reseller_whatsapp_templates WHERE reseller_company_id=? ORDER BY FIELD(tipo,'credentials','billing_notice','status_notice'),COALESCE(dias_referencia,9999) DESC,id");
    $st->execute([$resellerCompanyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('reseller_whatsapp_save_template')) {
  function reseller_whatsapp_save_template(PDO $pdo, int $resellerCompanyId, array $input): int {
    $id = max(0, (int)($input['id'] ?? 0));
    $tipo = in_array((string)($input['tipo'] ?? ''), ['credentials','billing_notice','status_notice'], true) ? (string)$input['tipo'] : 'billing_notice';
    $title = trim((string)($input['titulo'] ?? '')) ?: 'Mensagem';
    $daysRaw = trim((string)($input['dias_referencia'] ?? ''));
    $days = $daysRaw === '' ? null : (int)$daysRaw;
    $body = trim((string)($input['body'] ?? ''));
    if ($body === '') throw new RuntimeException('Informe o texto da mensagem.');
    $active = !empty($input['ativo']) ? 1 : 0;
    if ($id > 0) {
      $st = $pdo->prepare('UPDATE saas_reseller_whatsapp_templates SET tipo=?,titulo=?,dias_referencia=?,body=?,ativo=? WHERE id=? AND reseller_company_id=?');
      $st->execute([$tipo,$title,$days,$body,$active,$id,$resellerCompanyId]);
      return $id;
    }
    $st = $pdo->prepare('INSERT INTO saas_reseller_whatsapp_templates (reseller_company_id,tipo,titulo,dias_referencia,body,ativo) VALUES (?,?,?,?,?,?)');
    $st->execute([$resellerCompanyId,$tipo,$title,$days,$body,$active]);
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('reseller_whatsapp_find_template')) {
  function reseller_whatsapp_find_template(PDO $pdo, int $resellerCompanyId, string $type, ?int $days = null): ?array {
    reseller_whatsapp_seed_templates($pdo, $resellerCompanyId);
    if ($days !== null) {
      $st = $pdo->prepare('SELECT * FROM saas_reseller_whatsapp_templates WHERE reseller_company_id=? AND tipo=? AND ativo=1 AND dias_referencia=? ORDER BY id DESC LIMIT 1');
      $st->execute([$resellerCompanyId,$type,$days]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row) return $row;
      if ($days < 0) {
        $st->execute([$resellerCompanyId,$type,-1]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
      }
    }
    $st = $pdo->prepare('SELECT * FROM saas_reseller_whatsapp_templates WHERE reseller_company_id=? AND tipo=? AND ativo=1 AND dias_referencia IS NULL ORDER BY id DESC LIMIT 1');
    $st->execute([$resellerCompanyId,$type]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('reseller_whatsapp_log')) {
  function reseller_whatsapp_log(PDO $pdo, int $resellerCompanyId, ?int $clientCompanyId, ?int $chargeId, string $type, array $cfg, string $to, string $message, string $status, string $error = '', array $response = [], ?string $referenceDate = null): void {
    try {
      $st = $pdo->prepare('INSERT INTO saas_reseller_whatsapp_logs (reseller_company_id,client_company_id,charge_id,tipo,provider,destino,mensagem,status,erro,response_json,reference_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
      $st->execute([$resellerCompanyId,$clientCompanyId ?: null,$chargeId ?: null,mb_substr($type,0,50),mb_substr((string)$cfg['provider'],0,20),mb_substr($to,0,30),$message,mb_substr($status,0,30),$error !== '' ? mb_substr($error,0,255) : null,$response ? json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null,$referenceDate]);
    } catch (Throwable $e) {}
  }
}

if (!function_exists('reseller_whatsapp_send_message')) {
  function reseller_whatsapp_send_message(PDO $pdo, int $resellerCompanyId, string $to, string $message, string $type = 'manual', ?int $clientCompanyId = null, ?int $chargeId = null, ?string $referenceDate = null): array {
    $cfg = reseller_whatsapp_load_config($pdo, $resellerCompanyId);
    $destino = saas_whatsapp_normalize_phone($to, (string)$cfg['default_country_code']);
    $message = trim($message);
    if (empty($cfg['enabled'])) {
      reseller_whatsapp_log($pdo,$resellerCompanyId,$clientCompanyId,$chargeId,$type,$cfg,$destino,$message,'skipped','WhatsApp da revenda desativado.',[],$referenceDate);
      return ['ok'=>false,'skipped'=>true,'error'=>'WhatsApp da revenda desativado.'];
    }
    if ($destino === '' || $message === '') return ['ok'=>false,'error'=>'Destino ou mensagem vazia.'];
    try {
      if ((string)$cfg['provider'] === 'official') {
        $res = saas_whatsapp_official_request($cfg, [
          'messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$destino,'type'=>'text','text'=>['preview_url'=>true,'body'=>$message]
        ]);
      } else {
        $res = saas_whatsapp_unofficial_request($cfg, 'POST', '/send', [
          'company_id'=>(string)$cfg['unofficial_instance_id'],'to'=>$destino,'message'=>$message
        ]);
      }
      $ok = !empty($res['ok']) || isset($res['messages']) || isset($res['message_id']);
      reseller_whatsapp_log($pdo,$resellerCompanyId,$clientCompanyId,$chargeId,$type,$cfg,$destino,$message,$ok?'sent':'error',$ok?'':(string)($res['error']??'Falha no envio'),$res,$referenceDate);
      return ['ok'=>$ok,'provider'=>$cfg['provider'],'destino'=>$destino,'service_response'=>$res];
    } catch (Throwable $e) {
      reseller_whatsapp_log($pdo,$resellerCompanyId,$clientCompanyId,$chargeId,$type,$cfg,$destino,$message,'error',$e->getMessage(),[],$referenceDate);
      return ['ok'=>false,'provider'=>$cfg['provider'],'destino'=>$destino,'error'=>$e->getMessage()];
    }
  }
}

if (!function_exists('reseller_whatsapp_company_phone')) {
  function reseller_whatsapp_company_phone(array $company): string {
    foreach (['whatsapp','phone','telefone','celular'] as $field) {
      $value = trim((string)($company[$field] ?? ''));
      if ($value !== '') return $value;
    }
    return '';
  }
}

if (!function_exists('reseller_whatsapp_credentials_message')) {
  function reseller_whatsapp_credentials_message(PDO $pdo, int $resellerCompanyId, array $company, string $adminEmail, string $password, array $eadInfo = [], array $signupContext = []): string {
    $links = saas_whatsapp_company_links($company);
    $eadUrl = trim((string)($eadInfo['login_url'] ?? 'https://ead.prospero.tec.br/login.php')) ?: 'https://ead.prospero.tec.br/login.php';
    $training = "Treinamentos Próspero EAD:\n{$eadUrl}\n\nUse o mesmo usuário e senha para acessar os treinamentos.\n\n";
    $reseller = reseller_company_by_id($pdo, $resellerCompanyId) ?: [];
    $resellerBrand = trim((string)($reseller['reseller_brand_name'] ?? $reseller['name'] ?? 'Revenda')) ?: 'Revenda';
    $resellerWhatsapp = trim((string)($reseller['reseller_whatsapp'] ?? ''));
    $vars = [
      'empresa'=>(string)($company['name'] ?? 'Cliente'),
      'link_admin'=>$links['admin'],
      'link_catalogo_delivery'=>$links['catalog_delivery'],
      'link_catalogo'=>$links['catalog_public'],
      'usuario'=>$adminEmail,
      'senha'=>$password,
      'link_criar_senha'=>$password,
      'link_treinamento'=>$eadUrl,
      'bloco_treinamento'=>$training,
      'revenda'=>$resellerBrand,
      'whatsapp_revenda'=>$resellerWhatsapp !== '' ? $resellerWhatsapp : 'consulte sua revenda',
    ];
    $tpl = reseller_whatsapp_find_template($pdo,$resellerCompanyId,'credentials');
    $message = saas_whatsapp_render_template((string)($tpl['body'] ?? ''), $vars);
    // Mantém inclusive modelos antigos vinculados à identidade da revenda.
    if (stripos($message, $resellerBrand) === false) {
      $message .= "\n\nAtendimento: {$resellerBrand}";
    }
    if ($resellerWhatsapp !== '' && strpos(preg_replace('/\D+/', '', $message) ?: '', preg_replace('/\D+/', '', $resellerWhatsapp) ?: '') === false) {
      $message .= "\nWhatsApp da revenda: {$resellerWhatsapp}";
    }
    $labels = (array)($signupContext['module_labels'] ?? []);
    if ($labels) {
      $message .= "\n\nMódulos liberados:\n";
      foreach ($labels as $label) $message .= '✅ ' . trim((string)$label) . "\n";
    }
    return password_setup_adjust_message(trim($message), $password);
  }
}

if (!function_exists('reseller_whatsapp_send_credentials')) {
  function reseller_whatsapp_send_credentials(PDO $pdo, int $resellerCompanyId, int $clientCompanyId, string $password, array $eadInfo = [], array $signupContext = []): array {
    $cfg = reseller_whatsapp_load_config($pdo,$resellerCompanyId);
    if (empty($cfg['auto_send_credentials'])) return ['ok'=>false,'skipped'=>true,'error'=>'Envio automático de credenciais desativado.'];
    $st = $pdo->prepare('SELECT * FROM companies WHERE id=? AND reseller_company_id=? LIMIT 1');
    $st->execute([$clientCompanyId,$resellerCompanyId]);
    $company = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$company) return ['ok'=>false,'error'=>'Cliente não encontrado.'];
    $st = $pdo->prepare("SELECT email FROM users WHERE company_id=? AND role='company_admin' ORDER BY id LIMIT 1");
    $st->execute([$clientCompanyId]);
    $email = (string)($st->fetchColumn() ?: '');
    $message = reseller_whatsapp_credentials_message($pdo,$resellerCompanyId,$company,$email,$password,$eadInfo,$signupContext);
    return reseller_whatsapp_send_message($pdo,$resellerCompanyId,reseller_whatsapp_company_phone($company),$message,'credentials',$clientCompanyId,null);
  }
}

if (!function_exists('reseller_pagbank_load_config')) {
  function reseller_pagbank_load_config(PDO $pdo, int $resellerCompanyId): array {
    reseller_integrations_ensure_schema($pdo);
    $defaults = ['reseller_company_id'=>$resellerCompanyId,'environment'=>'sandbox','token'=>'','webhook_url'=>'','webhook_token'=>bin2hex(random_bytes(24)),'pix_expiration_minutes'=>1440,'max_installments'=>12,'soft_descriptor'=>'NEXAPROSPERO','pix_enabled'=>1,'card_enabled'=>1,'active'=>0];
    $st = $pdo->prepare('SELECT * FROM saas_reseller_pagbank_config WHERE reseller_company_id=? LIMIT 1');
    $st->execute([$resellerCompanyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $cfg = array_merge($defaults,$row);
    $cfg['environment'] = in_array((string)$cfg['environment'],['sandbox','production'],true)?(string)$cfg['environment']:'sandbox';
    $cfg['api_base_url'] = pagbank_default_api_base((string)$cfg['environment']);
    $cfg['token'] = trim((string)$cfg['token']);
    $cfg['webhook_token'] = trim((string)$cfg['webhook_token']) ?: bin2hex(random_bytes(24));
    $cfg['pix_expiration_minutes'] = max(5,min(43200,(int)$cfg['pix_expiration_minutes']));
    $cfg['max_installments'] = max(1,min(18,(int)($cfg['max_installments'] ?? 12)));
    $cfg['soft_descriptor'] = substr(preg_replace('/[^A-Za-z0-9 ]/','',(string)$cfg['soft_descriptor']),0,17) ?: 'NEXAPROSPERO';
    $cfg['pix_enabled'] = !empty($cfg['pix_enabled']);
    $cfg['card_enabled'] = !empty($cfg['card_enabled']);
    $cfg['active'] = !empty($cfg['active']);
    return $cfg;
  }
}

if (!function_exists('reseller_pagbank_save_config')) {
  function reseller_pagbank_save_config(PDO $pdo, int $resellerCompanyId, array $input): array {
    $old = reseller_pagbank_load_config($pdo,$resellerCompanyId);
    $cfg = [
      'environment'=>in_array((string)($input['environment']??''),['sandbox','production'],true)?(string)$input['environment']:'sandbox',
      'token'=>trim((string)($input['token']??$old['token']??'')),
      'webhook_url'=>trim((string)($input['webhook_url']??'')),
      'webhook_token'=>trim((string)($input['webhook_token']??$old['webhook_token']??'')) ?: bin2hex(random_bytes(24)),
      'pix_expiration_minutes'=>max(5,min(43200,(int)($input['pix_expiration_minutes']??1440))),
      'max_installments'=>max(1,min(18,(int)($input['max_installments']??12))),
      'soft_descriptor'=>substr(preg_replace('/[^A-Za-z0-9 ]/','',(string)($input['soft_descriptor']??'NEXAPROSPERO')),0,17) ?: 'NEXAPROSPERO',
      'pix_enabled'=>!empty($input['pix_enabled'])?1:0,
      'card_enabled'=>!empty($input['card_enabled'])?1:0,
      'active'=>!empty($input['active'])?1:0,
    ];
    $sql = "INSERT INTO saas_reseller_pagbank_config (reseller_company_id,environment,token,webhook_url,webhook_token,pix_expiration_minutes,max_installments,soft_descriptor,pix_enabled,card_enabled,active) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE environment=VALUES(environment),token=VALUES(token),webhook_url=VALUES(webhook_url),webhook_token=VALUES(webhook_token),pix_expiration_minutes=VALUES(pix_expiration_minutes),max_installments=VALUES(max_installments),soft_descriptor=VALUES(soft_descriptor),pix_enabled=VALUES(pix_enabled),card_enabled=VALUES(card_enabled),active=VALUES(active)";
    $pdo->prepare($sql)->execute([$resellerCompanyId,$cfg['environment'],$cfg['token'],$cfg['webhook_url'],$cfg['webhook_token'],$cfg['pix_expiration_minutes'],$cfg['max_installments'],$cfg['soft_descriptor'],$cfg['pix_enabled'],$cfg['card_enabled'],$cfg['active']]);
    return reseller_pagbank_load_config($pdo,$resellerCompanyId);
  }
}

if (!function_exists('reseller_pagbank_require_config')) {
  function reseller_pagbank_require_config(PDO $pdo, int $resellerCompanyId, string $method = 'pix'): array {
    $cfg = reseller_pagbank_load_config($pdo,$resellerCompanyId);
    if (empty($cfg['active'])) throw new RuntimeException('O PagBank da revenda não está ativo.');
    if ($method === 'card' && empty($cfg['card_enabled'])) throw new RuntimeException('O cartão está desativado no PagBank da revenda.');
    if ($method !== 'card' && empty($cfg['pix_enabled'])) throw new RuntimeException('O Pix está desativado no PagBank da revenda.');
    if (trim((string)$cfg['token']) === '') throw new RuntimeException('Informe o token do PagBank da revenda.');
    return $cfg;
  }
}

if (!function_exists('reseller_pagbank_webhook_url')) {
  function reseller_pagbank_webhook_url(PDO $pdo, int $resellerCompanyId, array $cfg): string {
    $url = trim((string)($cfg['webhook_url'] ?? ''));
    if ($url === '') $url = current_scheme() . '://' . current_host_name() . '/webhooks/reseller_pagbank.php';
    $url .= (str_contains($url,'?')?'&':'?') . 'reseller=' . $resellerCompanyId . '&token=' . rawurlencode((string)$cfg['webhook_token']);
    return $url;
  }
}

if (!function_exists('reseller_charge_payment_url')) {
  function reseller_charge_payment_url(array $reseller, int $chargeId, string $token): string {
    $host = current_host_name();
    if (!empty($reseller['subdomain'])) {
      $roots = configured_root_domains();
      if (!empty($roots[0])) $host = (string)$reseller['subdomain'] . '.' . (string)$roots[0];
    }
    return current_scheme() . '://' . $host . '/revenda/pagamento.php?c=' . $chargeId . '&t=' . rawurlencode($token);
  }
}

if (!function_exists('reseller_pagbank_create_charge')) {
  function reseller_pagbank_create_charge(PDO $pdo, int $resellerCompanyId, int $chargeId, string $method = 'pix'): array {
    $method = strtolower(trim($method)) === 'card' ? 'card' : 'pix';
    $cfg = reseller_pagbank_require_config($pdo,$resellerCompanyId,$method);
    $st = $pdo->prepare('SELECT ch.*,c.name,c.email,c.cnpj,c.cpf,c.whatsapp,c.whatsapp AS phone,c.subdomain,c.slug,c.custom_domain FROM saas_reseller_customer_charges ch INNER JOIN companies c ON c.id=ch.client_company_id WHERE ch.id=? AND ch.reseller_company_id=? LIMIT 1');
    $st->execute([$chargeId,$resellerCompanyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) throw new RuntimeException('Cobrança não encontrada.');
    if ((string)$row['status'] === 'paid') throw new RuntimeException('Esta cobrança já está paga.');
    $reference = 'RV' . $resellerCompanyId . 'C' . $chargeId . strtoupper(substr(bin2hex(random_bytes(5)),0,10));
    $fakePlan = ['id'=>0,'name'=>substr((string)$row['description'],0,100),'price'=>(float)$row['amount']];
    $token = trim((string)($row['public_token'] ?? '')) ?: bin2hex(random_bytes(24));
    $reseller = reseller_company_by_id($pdo,$resellerCompanyId) ?: [];
    $publicPage = reseller_charge_payment_url($reseller,$chargeId,$token);
    $webhookUrl = reseller_pagbank_webhook_url($pdo,$resellerCompanyId,$cfg);
    if ($method === 'card') {
      $gateway = pagbank_create_card_checkout($cfg,$row,$fakePlan,$reference,$webhookUrl,$publicPage,(string)$row['description']);
      $paymentUrl = trim((string)($gateway['pay_url'] ?? $gateway['payment_url'] ?? ''));
      if ($paymentUrl === '') throw new RuntimeException('O PagBank não retornou o endereço do checkout de cartão.');
    } else {
      $gateway = pagbank_create_pix_order($cfg,$row,$fakePlan,$reference,$webhookUrl,(string)$row['description']);
      $paymentUrl = $publicPage;
    }
    if (empty($gateway['id'])) throw new RuntimeException('O PagBank não retornou o identificador da cobrança.');
    $payload = json_encode($gateway,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $pdo->prepare("UPDATE saas_reseller_customer_charges SET payment_gateway='pagbank',payment_method=?,gateway_reference=?,gateway_status=?,payment_url=?,pix_copy_paste=?,qr_image=?,public_token=?,payment_payload=?,updated_at=NOW() WHERE id=? AND reseller_company_id=?")
      ->execute([$method,(string)$gateway['id'],(string)($gateway['status']??'WAITING'),$paymentUrl,(string)($gateway['copy_paste']??''),(string)($gateway['qr_image']??''),$token,$payload,$chargeId,$resellerCompanyId]);
    $row['payment_method'] = $method;
    $row['gateway_reference'] = (string)$gateway['id'];
    $row['gateway_status'] = (string)($gateway['status']??'WAITING');
    $row['payment_url'] = $paymentUrl;
    $row['pix_copy_paste'] = (string)($gateway['copy_paste']??'');
    $row['qr_image'] = (string)($gateway['qr_image']??'');
    return $row;
  }
}

if (!function_exists('reseller_whatsapp_charge_message')) {
  function reseller_whatsapp_charge_message(PDO $pdo, int $resellerCompanyId, array $charge, ?int $days = null): string {
    $tpl = reseller_whatsapp_find_template($pdo,$resellerCompanyId,'billing_notice',$days);
    $due = trim((string)($charge['due_date'] ?? ''));
    $dueBr = $due !== '' ? (new DateTimeImmutable($due))->format('d/m/Y') : 'a combinar';
    $daysOverdue = $days !== null && $days < 0 ? abs($days) : 0;
    return saas_whatsapp_render_template((string)($tpl['body'] ?? ''), [
      'empresa'=>(string)($charge['client_name'] ?? $charge['name'] ?? 'Cliente'),
      'descricao'=>(string)($charge['description'] ?? 'Mensalidade'),
      'valor'=>'R$ ' . number_format((float)($charge['amount'] ?? 0),2,',','.'),
      'vencimento'=>$dueBr,
      'dias'=>$days !== null ? max(0,$days) : '',
      'dias_vencido'=>$daysOverdue,
      'link_pagamento'=>(string)($charge['payment_url'] ?? ''),
    ]);
  }
}

if (!function_exists('reseller_whatsapp_send_charge')) {
  function reseller_whatsapp_send_charge(PDO $pdo, int $resellerCompanyId, int $chargeId, ?int $days = null): array {
    $cfg = reseller_whatsapp_load_config($pdo,$resellerCompanyId);
    if (empty($cfg['auto_send_billing']) && $days !== null) return ['ok'=>false,'skipped'=>true,'error'=>'Avisos automáticos desativados.'];
    $st = $pdo->prepare('SELECT ch.*,c.name AS client_name,c.whatsapp,c.whatsapp AS phone FROM saas_reseller_customer_charges ch INNER JOIN companies c ON c.id=ch.client_company_id WHERE ch.id=? AND ch.reseller_company_id=? LIMIT 1');
    $st->execute([$chargeId,$resellerCompanyId]);
    $charge = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$charge) return ['ok'=>false,'error'=>'Cobrança não encontrada.'];
    if (empty($charge['payment_url']) && !empty(reseller_pagbank_load_config($pdo,$resellerCompanyId)['active'])) {
      try { $charge = array_merge($charge,reseller_pagbank_create_charge($pdo,$resellerCompanyId,$chargeId)); } catch (Throwable $e) {}
    }
    $message = reseller_whatsapp_charge_message($pdo,$resellerCompanyId,$charge,$days);
    return reseller_whatsapp_send_message($pdo,$resellerCompanyId,reseller_whatsapp_company_phone($charge),$message,'billing_notice',(int)$charge['client_company_id'],$chargeId,$charge['due_date']??null);
  }
}

if (!function_exists('reseller_whatsapp_send_status')) {
  function reseller_whatsapp_send_status(PDO $pdo, int $resellerCompanyId, int $clientCompanyId, string $status): array {
    $cfg = reseller_whatsapp_load_config($pdo,$resellerCompanyId);
    if (empty($cfg['auto_send_status'])) return ['ok'=>false,'skipped'=>true,'error'=>'Avisos de status desativados.'];
    $st = $pdo->prepare('SELECT * FROM companies WHERE id=? AND reseller_company_id=? LIMIT 1');
    $st->execute([$clientCompanyId,$resellerCompanyId]);
    $company = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$company) return ['ok'=>false,'error'=>'Cliente não encontrado.'];
    $key = $status === 'blocked' ? -100 : 100;
    $tpl = reseller_whatsapp_find_template($pdo,$resellerCompanyId,'status_notice',$key);
    $message = saas_whatsapp_render_template((string)($tpl['body'] ?? ''), ['empresa'=>(string)$company['name']]);
    return reseller_whatsapp_send_message($pdo,$resellerCompanyId,reseller_whatsapp_company_phone($company),$message,'status_notice',$clientCompanyId,null);
  }
}

if (!function_exists('reseller_pagbank_process_webhook')) {
  function reseller_pagbank_process_webhook(PDO $pdo, int $resellerCompanyId, array $payload): array {
    reseller_integrations_ensure_schema($pdo);

    $references = [];
    $collectReferences = static function ($value) use (&$collectReferences, &$references): void {
      if (!is_array($value)) return;
      foreach ($value as $key => $item) {
        if (in_array((string)$key, ['id', 'reference_id'], true) && is_scalar($item)) {
          $candidate = trim((string)$item);
          if ($candidate !== '') $references[$candidate] = true;
        }
        if (is_array($item)) $collectReferences($item);
      }
    };
    $collectReferences($payload);

    if (!$references) return ['processed'=>false,'message'=>'A notificação do PagBank não contém identificadores reconhecidos.'];

    $values = array_keys($references);
    $placeholders = implode(',', array_fill(0, count($values), '?'));
    $params = array_merge([$resellerCompanyId], $values);
    $st = $pdo->prepare("SELECT * FROM saas_reseller_customer_charges WHERE reseller_company_id=? AND gateway_reference IN ($placeholders) ORDER BY id DESC LIMIT 1");
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) return ['processed'=>false,'message'=>'Cobrança da revenda não localizada.'];

    $paid = pagbank_payload_is_paid($payload);
    $statuses = [];
    $collectStatuses = static function ($value) use (&$collectStatuses, &$statuses): void {
      if (!is_array($value)) return;
      foreach ($value as $key => $item) {
        if ((string)$key === 'status' && is_scalar($item)) {
          $candidate = strtoupper(trim((string)$item));
          if ($candidate !== '') $statuses[$candidate] = true;
        }
        if (is_array($item)) $collectStatuses($item);
      }
    };
    $collectStatuses($payload);
    $gatewayStatus = (string)(array_key_first($statuses) ?? 'WAITING');
    $canceled = false;
    foreach (array_keys($statuses) as $candidateStatus) {
      if (in_array($candidateStatus, ['CANCELED','CANCELLED','DECLINED','FAILED','EXPIRED'], true)) {
        $canceled = true;
        $gatewayStatus = $candidateStatus;
        break;
      }
    }
    if ($paid) $gatewayStatus = 'PAID';
    $status = $paid ? 'paid' : ($canceled ? 'canceled' : 'open');

    $pdo->prepare('UPDATE saas_reseller_customer_charges SET status=?,gateway_status=?,paid_at=CASE WHEN ?="paid" THEN COALESCE(paid_at,NOW()) ELSE paid_at END,payment_payload=?,updated_at=NOW() WHERE id=?')
      ->execute([$status,$gatewayStatus,$status,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$row['id']]);
    if ($paid) $pdo->prepare("UPDATE companies SET status='active' WHERE id=? AND reseller_company_id=?")->execute([(int)$row['client_company_id'],$resellerCompanyId]);
    return ['processed'=>true,'message'=>$paid?'Pagamento confirmado.':'Status atualizado.','charge_id'=>(int)$row['id']];
  }
}

if (!function_exists('reseller_whatsapp_send_due_notices')) {
  function reseller_whatsapp_send_due_notices(PDO $pdo, int $resellerCompanyId): array {
    $cfg = reseller_whatsapp_load_config($pdo,$resellerCompanyId);
    if (empty($cfg['enabled']) || empty($cfg['auto_send_billing'])) return ['sent'=>0,'skipped'=>0,'errors'=>['Automação de WhatsApp desativada.']];
    $window = max(1,(int)$cfg['billing_notice_days']);
    $grace = max(0,(int)$cfg['block_grace_days']);
    $st = $pdo->prepare("SELECT ch.*,c.name AS client_name,c.whatsapp,c.whatsapp AS phone,DATEDIFF(ch.due_date,CURRENT_DATE) AS days_left FROM saas_reseller_customer_charges ch INNER JOIN companies c ON c.id=ch.client_company_id WHERE ch.reseller_company_id=? AND ch.status='open' AND ch.due_date IS NOT NULL AND DATEDIFF(ch.due_date,CURRENT_DATE) BETWEEN -30 AND ? ORDER BY ch.due_date,ch.id");
    $st->execute([$resellerCompanyId,$window]);
    $sent=0;$skipped=0;$errors=[];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $days=(int)$row['days_left'];
      $check=$pdo->prepare("SELECT 1 FROM saas_reseller_whatsapp_logs WHERE reseller_company_id=? AND charge_id=? AND tipo='billing_notice' AND reference_date=CURRENT_DATE AND status='sent' LIMIT 1");
      $check->execute([$resellerCompanyId,(int)$row['id']]);
      if ($check->fetchColumn()) { $skipped++; continue; }
      $res=reseller_whatsapp_send_charge($pdo,$resellerCompanyId,(int)$row['id'],$days);
      if (!empty($res['ok'])) $sent++; else $errors[]=(string)($res['error']??'Falha no envio da cobrança #'.$row['id']);
      if ($days < -$grace) {
        $block = $pdo->prepare("UPDATE companies SET status='blocked' WHERE id=? AND reseller_company_id=? AND status<>'blocked'");
        $block->execute([(int)$row['client_company_id'],$resellerCompanyId]);
        if ($block->rowCount() > 0) reseller_whatsapp_send_status($pdo,$resellerCompanyId,(int)$row['client_company_id'],'blocked');
      }
    }
    return ['sent'=>$sent,'skipped'=>$skipped,'errors'=>$errors];
  }
}


if (!function_exists('reseller_prepare_charge_delivery')) {
  function reseller_prepare_charge_delivery(PDO $pdo, int $resellerCompanyId, int $chargeId, bool $sendWhatsapp = true): array {
    $messages = [];
    $errors = [];
    $pg = reseller_pagbank_load_config($pdo, $resellerCompanyId);
    if (!empty($pg['active']) && (!empty($pg['pix_enabled']) || !empty($pg['card_enabled']))) {
      try {
        $st = $pdo->prepare('SELECT payment_url,status FROM saas_reseller_customer_charges WHERE id=? AND reseller_company_id=? LIMIT 1');
        $st->execute([$chargeId,$resellerCompanyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((string)($row['status'] ?? '') !== 'paid' && trim((string)($row['payment_url'] ?? '')) === '') {
          $method = !empty($pg['pix_enabled']) ? 'pix' : 'card';
          reseller_pagbank_create_charge($pdo,$resellerCompanyId,$chargeId,$method);
          $messages[] = $method === 'card' ? 'Checkout de cartão criado' : 'Pix criado';
        }
      } catch (Throwable $e) { $errors[] = 'PagBank: ' . $e->getMessage(); }
    }
    if ($sendWhatsapp) {
      try {
        $wa = reseller_whatsapp_load_config($pdo,$resellerCompanyId);
        if (!empty($wa['enabled']) && !empty($wa['auto_send_billing'])) {
          $res = reseller_whatsapp_send_charge($pdo,$resellerCompanyId,$chargeId,null);
          if (!empty($res['ok'])) $messages[] = 'WhatsApp enviado';
          elseif (empty($res['skipped'])) $errors[] = 'WhatsApp: ' . (string)($res['error'] ?? 'falha no envio');
        }
      } catch (Throwable $e) { $errors[] = 'WhatsApp: ' . $e->getMessage(); }
    }
    return ['messages'=>$messages,'errors'=>$errors];
  }
}

// -----------------------------------------------------------------------------
// WhatsApp da Revenda - recursos operacionais isolados.
// Estes helpers reproduzem recursos já estabilizados no SaaS sem alterar o
// serviço/configuração das empresas nem a Central SaaS.
// -----------------------------------------------------------------------------
if (!function_exists('reseller_whatsapp_generate_unofficial_instance_id')) {
  function reseller_whatsapp_generate_unofficial_instance_id(int $resellerCompanyId, int $oldInstanceId = 0): int {
    // Faixa reservada exclusivamente para sessões de revendas.
    // Mantém distância da faixa da Central SaaS e do ID padrão 3.000.000.000 + revenda.
    $min = 3100000000;
    $max = 3799999999;
    $seed = $min + (($resellerCompanyId % 50000) * 10000);
    if ($seed > ($max - 9999)) $seed = $min;
    $new = $seed + random_int(1, 9999);
    if ($new === $oldInstanceId) $new = $seed + (($new - $seed + 1) % 9999) + 1;
    return $new;
  }
}

if (!function_exists('reseller_whatsapp_rotate_unofficial_instance_id')) {
  function reseller_whatsapp_rotate_unofficial_instance_id(PDO $pdo, int $resellerCompanyId): array {
    $cfg = reseller_whatsapp_load_config($pdo, $resellerCompanyId);
    $old = (int)($cfg['unofficial_instance_id'] ?? reseller_whatsapp_default_instance_id($resellerCompanyId));
    $new = reseller_whatsapp_generate_unofficial_instance_id($resellerCompanyId, $old);
    $guard = 0;
    while ($new === $old && $guard < 5) {
      $new = reseller_whatsapp_generate_unofficial_instance_id($resellerCompanyId, $old);
      $guard++;
    }
    $cfg['unofficial_instance_id'] = (string)$new;
    reseller_whatsapp_save_config($pdo, $resellerCompanyId, $cfg);
    $cfg = reseller_whatsapp_load_config($pdo, $resellerCompanyId);
    return [
      'old_instance_id' => (string)$old,
      'new_instance_id' => (string)$cfg['unofficial_instance_id'],
      'config' => $cfg,
    ];
  }
}

if (!function_exists('reseller_whatsapp_get_template')) {
  function reseller_whatsapp_get_template(PDO $pdo, int $resellerCompanyId, int $id): ?array {
    if ($id <= 0) return null;
    reseller_integrations_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM saas_reseller_whatsapp_templates WHERE id=? AND reseller_company_id=? LIMIT 1');
    $st->execute([$id, $resellerCompanyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    return $row ?: null;
  }
}

if (!function_exists('reseller_whatsapp_set_template_active')) {
  function reseller_whatsapp_set_template_active(PDO $pdo, int $resellerCompanyId, int $id, bool $active): void {
    if ($id <= 0) throw new RuntimeException('Modelo inválido.');
    reseller_integrations_ensure_schema($pdo);
    $st = $pdo->prepare('UPDATE saas_reseller_whatsapp_templates SET ativo=? WHERE id=? AND reseller_company_id=?');
    $st->execute([$active ? 1 : 0, $id, $resellerCompanyId]);
    if ($st->rowCount() === 0 && !reseller_whatsapp_get_template($pdo, $resellerCompanyId, $id)) {
      throw new RuntimeException('Modelo não encontrado para esta revenda.');
    }
  }
}

if (!function_exists('reseller_whatsapp_reset_default_templates')) {
  function reseller_whatsapp_reset_default_templates(PDO $pdo, int $resellerCompanyId): void {
    reseller_integrations_ensure_schema($pdo);
    $pdo->beginTransaction();
    try {
      $pdo->prepare('DELETE FROM saas_reseller_whatsapp_templates WHERE reseller_company_id=?')->execute([$resellerCompanyId]);
      $ins = $pdo->prepare('INSERT INTO saas_reseller_whatsapp_templates (reseller_company_id,tipo,titulo,dias_referencia,body,ativo) VALUES (?,?,?,?,?,1)');
      foreach (reseller_whatsapp_default_templates() as $row) {
        $ins->execute([$resellerCompanyId, $row[0], $row[1], $row[2], $row[3]]);
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

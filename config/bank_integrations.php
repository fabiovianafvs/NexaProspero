<?php
declare(strict_types=1);

/**
 * Nexa Próspero - Hub genérico de integrações bancárias.
 *
 * Princípio:
 * - a conta bancária é o vínculo contábil/financeiro;
 * - credenciais são perfis reutilizáveis (quando o banco autoriza);
 * - cada serviço (Boleto, Pix Recebimentos, Extrato etc.) escolhe seu perfil;
 * - conectores específicos podem consumir bank_integration_runtime_config().
 */

if (!function_exists('bank_integration_table_exists')) {
  function bank_integration_table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  }
}

if (!function_exists('bank_integration_crypto_key')) {
  function bank_integration_crypto_key(): string {
    $secret = trim((string)(getenv('NEXA_BANK_SECRET_KEY') ?: ''));
    if ($secret === '') {
      $db = require __DIR__ . '/db.php';
      $secret = (string)($db['name'] ?? '') . '|' . (string)($db['user'] ?? '') . '|' . (string)($db['pass'] ?? '') . '|NEXA-BANK-INTEGRATIONS-V1';
    }
    return hash('sha256', $secret, true);
  }
}

if (!function_exists('bank_integration_secret_encrypt')) {
  function bank_integration_secret_encrypt(string $plain): string {
    if ($plain === '') return '';
    if (!function_exists('openssl_encrypt')) return 'plain:' . base64_encode($plain);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', bank_integration_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Não foi possível proteger a credencial bancária.');
    return 'v1:' . base64_encode($iv . $tag . $cipher);
  }
}

if (!function_exists('bank_integration_secret_decrypt')) {
  function bank_integration_secret_decrypt(string $secret): string {
    if ($secret === '') return '';
    if (str_starts_with($secret, 'plain:')) {
      $raw = base64_decode(substr($secret, 6), true);
      return $raw === false ? '' : $raw;
    }
    if (!str_starts_with($secret, 'v1:') || !function_exists('openssl_decrypt')) return '';
    $raw = base64_decode(substr($secret, 3), true);
    if ($raw === false || strlen($raw) < 29) return '';
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', bank_integration_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
  }
}

if (!function_exists('bank_integration_providers')) {
  function bank_integration_providers(): array {
    return [
      'ITAU'      => 'Itaú',
      'SICOOB'    => 'Sicoob',
      'CAIXA'     => 'Caixa Econômica Federal',
      'BRADESCO'  => 'Bradesco',
      'SANTANDER' => 'Santander',
      'INTER'     => 'Banco Inter',
      'CORA'      => 'Banco Cora',
      'GENERIC'   => 'Outro / Genérico',
    ];
  }
}

if (!function_exists('bank_integration_services')) {
  function bank_integration_services(): array {
    return [
      'BOLETO'            => 'Boleto / Bolecode',
      'PIX_RECEBIMENTO'   => 'Pix Recebimentos (QR Code)',
      'PIX_PAGAMENTO'     => 'Pix Pagamentos',
      'EXTRATO'           => 'Extrato / Movimentações',
      'CONCILIACAO'       => 'Conciliação bancária',
      'PAGAMENTOS'        => 'Pagamentos / Transferências',
    ];
  }
}

if (!function_exists('bank_integration_auth_types')) {
  function bank_integration_auth_types(): array {
    return [
      'OAUTH_MTLS' => 'OAuth 2.0 + mTLS',
      'OAUTH'      => 'OAuth 2.0 Client Credentials',
      'API_KEY'    => 'API Key',
      'BASIC'      => 'Usuário e senha',
      'CERT'       => 'Certificado / mTLS',
      'CUSTOM'     => 'Personalizada',
    ];
  }
}

if (!function_exists('bank_integration_connector_status')) {
  function bank_integration_connector_status(string $provider, string $service): string {
    $provider = strtoupper(trim($provider));
    $service = strtoupper(trim($service));
    if ($provider === 'ITAU' && in_array($service, ['BOLETO','PIX_RECEBIMENTO'], true)) return 'ATIVO';
    return 'PREPARADO';
  }
}

if (!function_exists('bank_integration_ensure_schema')) {
  function bank_integration_ensure_schema(PDO $pdo, bool $allowDdl = true): bool {
    if (!$allowDdl || !empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'])) {
      return bank_integration_table_exists($pdo, 'finance_bank_credentials')
        && bank_integration_table_exists($pdo, 'finance_bank_services');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS finance_bank_credentials (
      id INT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      bank_account_id INT NOT NULL,
      name VARCHAR(120) NOT NULL,
      provider VARCHAR(30) NOT NULL DEFAULT 'GENERIC',
      environment VARCHAR(20) NOT NULL DEFAULT 'HOMOLOGACAO',
      auth_type VARCHAR(30) NOT NULL DEFAULT 'OAUTH_MTLS',
      client_id VARCHAR(255) NULL,
      client_secret_enc LONGTEXT NULL,
      api_key_enc LONGTEXT NULL,
      username VARCHAR(255) NULL,
      password_enc LONGTEXT NULL,
      cert_path VARCHAR(500) NULL,
      cert_password_enc LONGTEXT NULL,
      key_path VARCHAR(500) NULL,
      ca_path VARCHAR(500) NULL,
      oauth_url VARCHAR(500) NULL,
      api_base_url VARCHAR(500) NULL,
      token_scope VARCHAR(255) NULL,
      notes VARCHAR(500) NULL,
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY(id),
      KEY idx_bank_cred_company_account(company_id,bank_account_id,active),
      KEY idx_bank_cred_provider(company_id,provider,environment)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS finance_bank_services (
      id INT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      bank_account_id INT NOT NULL,
      service_code VARCHAR(40) NOT NULL,
      enabled TINYINT(1) NOT NULL DEFAULT 0,
      provider VARCHAR(30) NOT NULL DEFAULT 'GENERIC',
      integration_mode VARCHAR(20) NOT NULL DEFAULT 'API',
      environment VARCHAR(20) NOT NULL DEFAULT 'HOMOLOGACAO',
      credential_id INT NULL,
      default_payment_method_id INT NULL,
      api_base_url VARCHAR(500) NULL,
      webhook_url VARCHAR(500) NULL,
      settings_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY(id),
      UNIQUE KEY uk_bank_service(company_id,bank_account_id,service_code),
      KEY idx_bank_service_provider(company_id,provider,service_code,enabled),
      KEY idx_bank_service_credential(company_id,credential_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    return true;
  }
}

if (!function_exists('bank_integration_bank')) {
  function bank_integration_bank(PDO $pdo, int $companyId, int $bankId): array {
    if ($bankId <= 0) return [];
    $st = $pdo->prepare('SELECT * FROM finance_bank_accounts WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId, $bankId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('bank_integration_credentials')) {
  function bank_integration_credentials(PDO $pdo, int $companyId, int $bankId, bool $activeOnly = false): array {
    if (!bank_integration_table_exists($pdo, 'finance_bank_credentials')) return [];
    $sql = 'SELECT * FROM finance_bank_credentials WHERE company_id=? AND bank_account_id=?';
    if ($activeOnly) $sql .= ' AND active=1';
    $sql .= ' ORDER BY active DESC, provider, name, id';
    $st = $pdo->prepare($sql);
    $st->execute([$companyId, $bankId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('bank_integration_credential')) {
  function bank_integration_credential(PDO $pdo, int $companyId, int $credentialId): array {
    if ($credentialId <= 0 || !bank_integration_table_exists($pdo, 'finance_bank_credentials')) return [];
    $st = $pdo->prepare('SELECT * FROM finance_bank_credentials WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId, $credentialId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$row) return [];
    $row['client_secret'] = bank_integration_secret_decrypt((string)($row['client_secret_enc'] ?? ''));
    $row['api_key'] = bank_integration_secret_decrypt((string)($row['api_key_enc'] ?? ''));
    $row['password'] = bank_integration_secret_decrypt((string)($row['password_enc'] ?? ''));
    $row['cert_password'] = bank_integration_secret_decrypt((string)($row['cert_password_enc'] ?? ''));
    return $row;
  }
}

if (!function_exists('bank_integration_save_credential')) {
  function bank_integration_save_credential(PDO $pdo, int $companyId, int $bankId, array $data): int {
    $providers = bank_integration_providers();
    $authTypes = bank_integration_auth_types();
    $id = (int)($data['id'] ?? 0);
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Informe um nome para o perfil de credenciais.');
    $provider = strtoupper(trim((string)($data['provider'] ?? 'GENERIC')));
    if (!isset($providers[$provider])) $provider = 'GENERIC';
    $environment = strtoupper(trim((string)($data['environment'] ?? 'HOMOLOGACAO')));
    if (!in_array($environment, ['HOMOLOGACAO','PRODUCAO'], true)) $environment = 'HOMOLOGACAO';
    $authType = strtoupper(trim((string)($data['auth_type'] ?? 'OAUTH_MTLS')));
    if (!isset($authTypes[$authType])) $authType = 'CUSTOM';

    $old = $id > 0 ? bank_integration_credential($pdo, $companyId, $id) : [];
    if ($id > 0 && (!$old || (int)$old['bank_account_id'] !== $bankId)) throw new RuntimeException('Perfil de credenciais inválido para esta conta bancária.');

    $clientSecretRaw = trim((string)($data['client_secret'] ?? ''));
    $apiKeyRaw = trim((string)($data['api_key'] ?? ''));
    $passwordRaw = (string)($data['password'] ?? '');
    $certPasswordRaw = (string)($data['cert_password'] ?? '');
    $clientSecretEnc = $clientSecretRaw !== '' ? bank_integration_secret_encrypt($clientSecretRaw) : (string)($old['client_secret_enc'] ?? '');
    $apiKeyEnc = $apiKeyRaw !== '' ? bank_integration_secret_encrypt($apiKeyRaw) : (string)($old['api_key_enc'] ?? '');
    $passwordEnc = $passwordRaw !== '' ? bank_integration_secret_encrypt($passwordRaw) : (string)($old['password_enc'] ?? '');
    $certPasswordEnc = $certPasswordRaw !== '' ? bank_integration_secret_encrypt($certPasswordRaw) : (string)($old['cert_password_enc'] ?? '');

    $args = [
      $companyId, $bankId, $name, $provider, $environment, $authType,
      trim((string)($data['client_id'] ?? '')) ?: null,
      $clientSecretEnc !== '' ? $clientSecretEnc : null,
      $apiKeyEnc !== '' ? $apiKeyEnc : null,
      trim((string)($data['username'] ?? '')) ?: null,
      $passwordEnc !== '' ? $passwordEnc : null,
      trim((string)($data['cert_path'] ?? '')) ?: null,
      $certPasswordEnc !== '' ? $certPasswordEnc : null,
      trim((string)($data['key_path'] ?? '')) ?: null,
      trim((string)($data['ca_path'] ?? '')) ?: null,
      trim((string)($data['oauth_url'] ?? '')) ?: null,
      trim((string)($data['api_base_url'] ?? '')) ?: null,
      trim((string)($data['token_scope'] ?? '')) ?: null,
      trim((string)($data['notes'] ?? '')) ?: null,
      !empty($data['active']) ? 1 : 0,
    ];

    if ($id > 0) {
      $sql = 'UPDATE finance_bank_credentials SET name=?,provider=?,environment=?,auth_type=?,client_id=?,client_secret_enc=?,api_key_enc=?,username=?,password_enc=?,cert_path=?,cert_password_enc=?,key_path=?,ca_path=?,oauth_url=?,api_base_url=?,token_scope=?,notes=?,active=?,updated_at=NOW() WHERE company_id=? AND bank_account_id=? AND id=?';
      $pdo->prepare($sql)->execute([
        $name,$provider,$environment,$authType,$args[6],$args[7],$args[8],$args[9],$args[10],$args[11],$args[12],$args[13],$args[14],$args[15],$args[16],$args[17],$args[18],$args[19],$companyId,$bankId,$id
      ]);
      return $id;
    }

    $pdo->prepare('INSERT INTO finance_bank_credentials(company_id,bank_account_id,name,provider,environment,auth_type,client_id,client_secret_enc,api_key_enc,username,password_enc,cert_path,cert_password_enc,key_path,ca_path,oauth_url,api_base_url,token_scope,notes,active) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute($args);
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('bank_integration_services_for_bank')) {
  function bank_integration_services_for_bank(PDO $pdo, int $companyId, int $bankId): array {
    if (!bank_integration_table_exists($pdo, 'finance_bank_services')) return [];
    $st = $pdo->prepare('SELECT * FROM finance_bank_services WHERE company_id=? AND bank_account_id=? ORDER BY service_code');
    $st->execute([$companyId, $bankId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) $out[(string)$row['service_code']] = $row;
    return $out;
  }
}

if (!function_exists('bank_integration_save_service')) {
  function bank_integration_save_service(PDO $pdo, int $companyId, int $bankId, array $data): void {
    $services = bank_integration_services();
    $providers = bank_integration_providers();
    $service = strtoupper(trim((string)($data['service_code'] ?? '')));
    if (!isset($services[$service])) throw new RuntimeException('Serviço bancário inválido.');
    $provider = strtoupper(trim((string)($data['provider'] ?? 'GENERIC')));
    if (!isset($providers[$provider])) $provider = 'GENERIC';
    $mode = strtoupper(trim((string)($data['integration_mode'] ?? 'API')));
    if (!in_array($mode, ['API','CNAB240','CNAB400','OFX','MANUAL'], true)) $mode = 'API';
    $environment = strtoupper(trim((string)($data['environment'] ?? 'HOMOLOGACAO')));
    if (!in_array($environment, ['HOMOLOGACAO','PRODUCAO'], true)) $environment = 'HOMOLOGACAO';
    $credentialId = (int)($data['credential_id'] ?? 0) ?: null;
    if ($credentialId) {
      $cred = bank_integration_credential($pdo, $companyId, $credentialId);
      if (!$cred || (int)$cred['bank_account_id'] !== $bankId || empty($cred['active'])) throw new RuntimeException('Perfil de credenciais inválido ou inativo.');
      $credProvider = strtoupper(trim((string)($cred['provider'] ?? 'GENERIC')));
      if ($provider !== 'GENERIC' && $credProvider !== 'GENERIC' && $credProvider !== $provider) {
        throw new RuntimeException('O perfil selecionado pertence ao provedor ' . $credProvider . ', mas o serviço está configurado para ' . $provider . '.');
      }
    }
    $paymentId = (int)($data['default_payment_method_id'] ?? 0) ?: null;
    if ($paymentId) {
      $st = $pdo->prepare('SELECT 1 FROM finance_payment_methods WHERE company_id=? AND id=? AND is_active=1 LIMIT 1');
      $st->execute([$companyId, $paymentId]);
      if (!$st->fetchColumn()) throw new RuntimeException('Forma de pagamento padrão inválida para esta empresa.');
    }
    $settings = $data['settings'] ?? [];
    if (!is_array($settings)) $settings = [];
    $settingsJson = $settings ? json_encode($settings, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;

    $pdo->prepare('INSERT INTO finance_bank_services(company_id,bank_account_id,service_code,enabled,provider,integration_mode,environment,credential_id,default_payment_method_id,api_base_url,webhook_url,settings_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),provider=VALUES(provider),integration_mode=VALUES(integration_mode),environment=VALUES(environment),credential_id=VALUES(credential_id),default_payment_method_id=VALUES(default_payment_method_id),api_base_url=VALUES(api_base_url),webhook_url=VALUES(webhook_url),settings_json=VALUES(settings_json),updated_at=NOW()')
      ->execute([
        $companyId,$bankId,$service,!empty($data['enabled'])?1:0,$provider,$mode,$environment,$credentialId,$paymentId,
        trim((string)($data['api_base_url'] ?? '')) ?: null,
        trim((string)($data['webhook_url'] ?? '')) ?: null,
        $settingsJson,
      ]);
  }
}

if (!function_exists('bank_integration_service_config')) {
  function bank_integration_service_config(PDO $pdo, int $companyId, int $bankId, string $serviceCode): array {
    if (!bank_integration_table_exists($pdo, 'finance_bank_services')) return [];
    $st = $pdo->prepare('SELECT * FROM finance_bank_services WHERE company_id=? AND bank_account_id=? AND service_code=? LIMIT 1');
    $st->execute([$companyId, $bankId, strtoupper(trim($serviceCode))]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('bank_integration_runtime_config')) {
  function bank_integration_runtime_config(PDO $pdo, int $companyId, int $bankId, string $serviceCode): array {
    $service = bank_integration_service_config($pdo, $companyId, $bankId, $serviceCode);
    if (!$service || empty($service['enabled'])) return [];
    $credential = !empty($service['credential_id']) ? bank_integration_credential($pdo, $companyId, (int)$service['credential_id']) : [];
    $provider = strtoupper(trim((string)($service['provider'] ?? ($credential['provider'] ?? 'GENERIC'))));
    $environment = strtoupper(trim((string)($service['environment'] ?? ($credential['environment'] ?? 'HOMOLOGACAO'))));
    $settings = [];
    if (!empty($service['settings_json'])) {
      $decoded = json_decode((string)$service['settings_json'], true);
      if (is_array($decoded)) $settings = $decoded;
    }
    return [
      'service_id' => (int)($service['id'] ?? 0),
      'service_code' => strtoupper((string)($service['service_code'] ?? $serviceCode)),
      'enabled' => (int)($service['enabled'] ?? 0),
      'provider' => $provider,
      'integration_mode' => strtoupper((string)($service['integration_mode'] ?? 'API')),
      'environment' => $environment,
      'credential_id' => (int)($service['credential_id'] ?? 0),
      'default_payment_method_id' => (int)($service['default_payment_method_id'] ?? 0),
      'api_base_url' => trim((string)($service['api_base_url'] ?? '')) ?: trim((string)($credential['api_base_url'] ?? '')),
      'webhook_url' => trim((string)($service['webhook_url'] ?? '')),
      'api_client_id' => (string)($credential['client_id'] ?? ''),
      'api_client_secret' => (string)($credential['client_secret'] ?? ''),
      'api_key' => (string)($credential['api_key'] ?? ''),
      'username' => (string)($credential['username'] ?? ''),
      'password' => (string)($credential['password'] ?? ''),
      'cert_path' => (string)($credential['cert_path'] ?? ''),
      'cert_password' => (string)($credential['cert_password'] ?? ''),
      'key_path' => (string)($credential['key_path'] ?? ''),
      'ca_path' => (string)($credential['ca_path'] ?? ''),
      'oauth_url' => (string)($credential['oauth_url'] ?? ''),
      'token_scope' => (string)($credential['token_scope'] ?? ''),
      'auth_type' => strtoupper((string)($credential['auth_type'] ?? 'CUSTOM')),
      'settings' => $settings,
      'bank_account_id' => $bankId,
    ];
  }
}

if (!function_exists('bank_integration_import_legacy_billing')) {
  function bank_integration_import_legacy_billing(PDO $pdo, int $companyId, int $bankId): array {
    if (!function_exists('billing_bank_config')) throw new RuntimeException('Módulo legado de cobrança não carregado.');
    $legacy = billing_bank_config($pdo, $companyId, $bankId);
    if (!$legacy) throw new RuntimeException('Esta conta ainda não possui configuração antiga de Cobrança/Boleto para importar.');

    $provider = strtoupper(trim((string)($legacy['provider'] ?? 'GENERIC')));
    if (!isset(bank_integration_providers()[$provider])) $provider = 'GENERIC';
    $environment = strtoupper(trim((string)($legacy['environment'] ?? 'HOMOLOGACAO')));
    $existing = bank_integration_credentials($pdo, $companyId, $bankId, false);
    $credentialId = 0;
    foreach ($existing as $row) {
      if ((string)$row['name'] === 'Credencial importada da cobrança' && strtoupper((string)$row['provider']) === $provider) {
        $credentialId = (int)$row['id'];
        break;
      }
    }
    $credentialId = bank_integration_save_credential($pdo, $companyId, $bankId, [
      'id' => $credentialId,
      'name' => 'Credencial importada da cobrança',
      'provider' => $provider,
      'environment' => $environment,
      'auth_type' => 'OAUTH_MTLS',
      'client_id' => (string)($legacy['api_client_id'] ?? ''),
      'client_secret' => (string)($legacy['api_client_secret'] ?? ''),
      'cert_path' => (string)($legacy['cert_path'] ?? ''),
      'key_path' => (string)($legacy['key_path'] ?? ''),
      'ca_path' => (string)($legacy['ca_path'] ?? ''),
      'oauth_url' => (string)($legacy['oauth_url'] ?? ''),
      'api_base_url' => (string)($legacy['api_base_url'] ?? ''),
      'notes' => 'Importada automaticamente da configuração legada de Cobrança/Boleto. Reutilize em outros serviços somente se o banco autorizar os mesmos produtos/APIs para esta credencial.',
      'active' => 1,
    ]);

    bank_integration_save_service($pdo, $companyId, $bankId, [
      'service_code' => 'BOLETO',
      'enabled' => !empty($legacy['enabled']) ? 1 : 0,
      'provider' => $provider,
      'integration_mode' => (string)($legacy['integration_mode'] ?? 'API'),
      'environment' => $environment,
      'credential_id' => $credentialId,
      'default_payment_method_id' => (int)($legacy['default_payment_method_id'] ?? 0),
      'api_base_url' => (string)($legacy['api_base_url'] ?? ''),
      'settings' => [
        'convenio' => (string)($legacy['convenio'] ?? ''),
        'carteira' => (string)($legacy['carteira'] ?? ''),
        'variacao' => (string)($legacy['variacao'] ?? ''),
      ],
    ]);

    return ['credential_id'=>$credentialId,'provider'=>$provider,'environment'=>$environment];
  }
}

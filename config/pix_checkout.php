<?php
declare(strict_types=1);

/**
 * Nexa Próspero - Pix dinâmico no PDV Checkout.
 *
 * Primeira implementação homologável: Itaú Pix Recebimentos (cob imediata).
 * A configuração operacional fica na Forma de Pagamento; credenciais OAuth/mTLS
 * continuam vinculadas à conta bancária em finance_bank_billing_configs.
 */

require_once __DIR__ . '/finance_module.php';
require_once __DIR__ . '/billing_module.php';
require_once __DIR__ . '/itau_boleto.php';
require_once __DIR__ . '/bank_integrations.php';

if (!function_exists('pix_checkout_table_exists')) {
  function pix_checkout_table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  }
}

if (!function_exists('pix_checkout_column_exists')) {
  function pix_checkout_column_exists(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
  }
}

if (!function_exists('pix_checkout_add_column')) {
  function pix_checkout_add_column(PDO $pdo, string $table, string $column, string $definition): void {
    if (!pix_checkout_column_exists($pdo, $table, $column)) {
      $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
  }
}

if (!function_exists('pix_checkout_ensure_schema')) {
  function pix_checkout_ensure_schema(PDO $pdo, bool $allowDdl = true): bool {
    if (!$allowDdl || !empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'])) {
      return pix_checkout_table_exists($pdo, 'gestao_pix_charges')
        && pix_checkout_column_exists($pdo, 'finance_payment_methods', 'pix_auto_qr_enabled');
    }

    if (!pix_checkout_table_exists($pdo, 'finance_payment_methods')) return false;

    pix_checkout_add_column($pdo, 'finance_payment_methods', 'pix_auto_qr_enabled', "TINYINT(1) NOT NULL DEFAULT 0");
    pix_checkout_add_column($pdo, 'finance_payment_methods', 'pix_provider', "VARCHAR(30) NULL AFTER pix_auto_qr_enabled");
    pix_checkout_add_column($pdo, 'finance_payment_methods', 'pix_key', "VARCHAR(180) NULL AFTER pix_provider");
    pix_checkout_add_column($pdo, 'finance_payment_methods', 'pix_expiration_seconds', "INT NOT NULL DEFAULT 300 AFTER pix_key");
    pix_checkout_add_column($pdo, 'finance_payment_methods', 'pix_api_base_url', "VARCHAR(500) NULL AFTER pix_expiration_seconds");

    $pdo->exec("CREATE TABLE IF NOT EXISTS gestao_pix_charges (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      payment_method_id INT NOT NULL,
      bank_account_id INT NULL,
      user_id INT NULL,
      cash_session_id INT NULL,
      provider VARCHAR(30) NOT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'CRIANDO',
      provider_status VARCHAR(60) NULL,
      txid VARCHAR(100) NOT NULL,
      amount DECIMAL(15,2) NOT NULL DEFAULT 0,
      pix_key VARCHAR(180) NULL,
      pix_copy_paste LONGTEXT NULL,
      qr_image LONGTEXT NULL,
      e2eid VARCHAR(100) NULL,
      order_id BIGINT NULL,
      expires_at DATETIME NULL,
      request_payload LONGTEXT NULL,
      response_payload LONGTEXT NULL,
      error_message VARCHAR(1000) NULL,
      last_check_at DATETIME NULL,
      paid_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY(id),
      UNIQUE KEY uk_gestao_pix_txid(company_id,provider,txid),
      KEY idx_gestao_pix_company_status(company_id,status,created_at),
      KEY idx_gestao_pix_order(company_id,order_id),
      KEY idx_gestao_pix_method(company_id,payment_method_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    return true;
  }
}

if (!function_exists('pix_checkout_itau_default_base')) {
  function pix_checkout_itau_default_base(string $environment = 'PRODUCAO'): string {
    $env = strtoupper(trim($environment));
    if (in_array($env, ['HOMOLOGACAO','SANDBOX','TESTE'], true)) {
      return 'https://devportal.itau.com.br/sandboxapi/pix_recebimentos_ext_v2/v2';
    }
    return 'https://secure.api.itau/pix_recebimentos/v2';
  }
}

if (!function_exists('pix_checkout_method_config')) {
  function pix_checkout_method_config(PDO $pdo, int $companyId, int $paymentMethodId): array {
    if ($paymentMethodId <= 0) return [];
    $st = $pdo->prepare('SELECT * FROM finance_payment_methods WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId, $paymentMethodId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$row) return [];
    $provider = strtoupper(trim((string)($row['pix_provider'] ?? '')));
    if ($provider === '' && !empty($row['pix_auto_qr_enabled'])) $provider = 'ITAU';
    $row['pix_provider'] = $provider;
    $row['pix_expiration_seconds'] = max(60, min(86400, (int)($row['pix_expiration_seconds'] ?? 300)));
    return $row;
  }
}

if (!function_exists('pix_checkout_bank_config')) {
  function pix_checkout_bank_config(PDO $pdo, int $companyId, array $method): array {
    $bankId = (int)($method['default_bank_account_id'] ?? 0);
    if ($bankId <= 0) throw new RuntimeException('A forma Pix automática precisa de um Banco/Caixa padrão configurado.');

    $methodProvider = strtoupper(trim((string)($method['pix_provider'] ?? '')));
    $hub = function_exists('bank_integration_runtime_config')
      ? bank_integration_runtime_config($pdo, $companyId, $bankId, 'PIX_RECEBIMENTO')
      : [];
    if ($hub) {
      $hubProvider = strtoupper(trim((string)($hub['provider'] ?? '')));
      $providerCompatible = in_array($methodProvider, ['', 'BANK', 'AUTO'], true) || $methodProvider === $hubProvider;
      if ($providerCompatible) {
        $mode = strtoupper(trim((string)($hub['integration_mode'] ?? 'API')));
        if ($mode !== 'API') throw new RuntimeException('O serviço Pix Recebimentos desta conta precisa usar integração API.');
        return $hub;
      }
    }

    // Compatibilidade V11.38.0: contas que ainda não foram migradas para o Hub
    // continuam podendo reutilizar a configuração antiga de Cobrança/Boleto.
    $cfg = billing_bank_config($pdo, $companyId, $bankId);
    if (!$cfg) throw new RuntimeException('A conta bancária vinculada não possui Pix Recebimentos configurado no Hub nem uma configuração bancária legada compatível.');
    $mode = strtoupper(trim((string)($cfg['integration_mode'] ?? '')));
    if ($mode !== 'API') throw new RuntimeException('A conta bancária vinculada precisa usar integração API para o Pix automático.');
    if (array_key_exists('enabled', $cfg) && empty($cfg['enabled'])) throw new RuntimeException('A integração bancária da conta selecionada está desabilitada.');
    return $cfg;
  }
}

if (!function_exists('pix_checkout_resolve_provider')) {
  function pix_checkout_resolve_provider(PDO $pdo, int $companyId, array $method): string {
    $provider = strtoupper(trim((string)($method['pix_provider'] ?? '')));
    if (!in_array($provider, ['', 'BANK', 'AUTO'], true)) return $provider;
    $cfg = pix_checkout_bank_config($pdo, $companyId, $method);
    $provider = strtoupper(trim((string)($cfg['provider'] ?? '')));
    if ($provider === '') throw new RuntimeException('O serviço Pix Recebimentos da conta bancária não possui provedor definido.');
    return $provider;
  }
}

if (!function_exists('pix_checkout_txid')) {
  function pix_checkout_txid(int $companyId): string {
    $random = strtoupper(bin2hex(random_bytes(8)));
    $raw = 'NEXA' . $companyId . date('ymdHis') . $random;
    $raw = preg_replace('/[^A-Za-z0-9]/', '', $raw) ?: '';
    if (strlen($raw) < 26) $raw .= strtoupper(bin2hex(random_bytes(10)));
    return substr($raw, 0, 35);
  }
}

if (!function_exists('pix_checkout_qr_data_uri')) {
  function pix_checkout_qr_data_uri(string $emv): string {
    $emv = trim($emv);
    if ($emv === '') return '';
    try {
      $autoload = __DIR__ . '/../vendor/autoload.php';
      if (is_file($autoload)) require_once $autoload;
      if (!class_exists('Com\\Tecnick\\Barcode\\Barcode')) return '';
      $barcode = new \Com\Tecnick\Barcode\Barcode();
      $bin = $barcode->getBarcodeObj('QRCODE,H', $emv, -5, -5, 'black', [-2,-2,-2,-2])->setBackgroundColor('white')->getPngData(false);
      return 'data:image/png;base64,' . base64_encode($bin);
    } catch (Throwable $e) {
      return '';
    }
  }
}

if (!function_exists('pix_checkout_first_text')) {
  function pix_checkout_first_text(array $source, array $keys): string {
    foreach ($keys as $key) {
      if (!array_key_exists($key, $source)) continue;
      $v = $source[$key];
      if (is_scalar($v) && trim((string)$v) !== '') return trim((string)$v);
    }
    return '';
  }
}

if (!function_exists('pix_checkout_itau_api_config')) {
  function pix_checkout_itau_api_config(PDO $pdo, int $companyId, array $method): array {
    $bankCfg = pix_checkout_bank_config($pdo, $companyId, $method);
    $provider = strtoupper(trim((string)($bankCfg['provider'] ?? '')));
    if ($provider !== 'ITAU') throw new RuntimeException('O perfil bancário selecionado para Pix Recebimentos não é Itaú. Provedor configurado: ' . ($provider ?: 'não informado') . '.');
    $bankCfg['provider'] = 'ITAU';
    $bankCfg['integration_mode'] = 'API';
    $base = trim((string)($method['pix_api_base_url'] ?? ''));
    if ($base === '') $base = trim((string)($bankCfg['api_base_url'] ?? ''));
    if ($base === '') $base = pix_checkout_itau_default_base((string)($bankCfg['environment'] ?? 'PRODUCAO'));
    $bankCfg['api_base_url'] = rtrim($base, '/');
    return $bankCfg;
  }
}

if (!function_exists('pix_checkout_itau_token')) {
  function pix_checkout_itau_token(array $cfg): array {
    $clientId = trim((string)($cfg['api_client_id'] ?? ''));
    $oauthUrl = trim((string)($cfg['oauth_url'] ?? ''));
    $certPath = trim((string)($cfg['cert_path'] ?? ''));
    $cacheKey = 'nexa_pix_itau_' . hash('sha256', $clientId . '|' . $oauthUrl . '|' . $certPath);

    if (function_exists('apcu_fetch')) {
      $cached = apcu_fetch($cacheKey, $ok);
      if ($ok && is_array($cached) && (int)($cached['expires_at'] ?? 0) > time() + 30 && !empty($cached['access_token'])) return $cached;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
      $cached = $_SESSION['_nexa_pix_token_cache'][$cacheKey] ?? null;
      if (is_array($cached) && (int)($cached['expires_at'] ?? 0) > time() + 30 && !empty($cached['access_token'])) return $cached;
    }

    $token = itau_boleto_get_token($cfg);
    $ttl = max(30, (int)($token['expires_at'] ?? 0) - time() - 20);
    if (function_exists('apcu_store')) @apcu_store($cacheKey, $token, $ttl);
    if (session_status() === PHP_SESSION_ACTIVE) {
      if (!isset($_SESSION['_nexa_pix_token_cache']) || !is_array($_SESSION['_nexa_pix_token_cache'])) $_SESSION['_nexa_pix_token_cache'] = [];
      $_SESSION['_nexa_pix_token_cache'][$cacheKey] = $token;
    }
    return $token;
  }
}

if (!function_exists('pix_checkout_itau_parse_charge')) {
  function pix_checkout_itau_parse_charge(array $chargeJson, array $qrJson = []): array {
    $merged = array_merge($chargeJson, $qrJson);
    $copy = pix_checkout_first_text($merged, ['pixCopiaECola','qrcode','qrCode','emv','payload']);
    $qrBase64 = pix_checkout_first_text($merged, ['base64','imagemQrcode','imagemQRCode','qrcodeBase64','qrImage']);
    $qrImage = '';
    if ($qrBase64 !== '') {
      $qrImage = strpos($qrBase64, 'data:') === 0 ? $qrBase64 : 'data:image/png;base64,' . $qrBase64;
    }
    if ($qrImage === '' && $copy !== '') $qrImage = pix_checkout_qr_data_uri($copy);
    $status = strtoupper(trim((string)($chargeJson['status'] ?? $qrJson['status'] ?? '')));
    $pixRows = is_array($chargeJson['pix'] ?? null) ? $chargeJson['pix'] : [];
    $paid = $status === 'CONCLUIDA' || !empty($pixRows);
    $e2eid = '';
    if ($pixRows && is_array($pixRows[0] ?? null)) {
      $e2eid = pix_checkout_first_text($pixRows[0], ['endToEndId','end_to_end_id','e2eid']);
    }
    return [
      'provider_status' => $status,
      'paid' => $paid,
      'copy_paste' => $copy,
      'qr_image' => $qrImage,
      'e2eid' => $e2eid,
    ];
  }
}

if (!function_exists('pix_checkout_itau_create')) {
  function pix_checkout_itau_create(PDO $pdo, int $companyId, array $method, string $txid, float $amount): array {
    $cfg = pix_checkout_itau_api_config($pdo, $companyId, $method);
    $pixKey = trim((string)($method['pix_key'] ?? ''));
    if ($pixKey === '') throw new RuntimeException('Informe a Chave Pix na configuração desta forma de pagamento.');
    $token = pix_checkout_itau_token($cfg);
    $accessToken = (string)($token['access_token'] ?? '');
    if ($accessToken === '') throw new RuntimeException('Não foi possível obter o token da API Pix Itaú.');

    $expiration = max(60, min(86400, (int)($method['pix_expiration_seconds'] ?? 300)));
    $payload = [
      'calendario' => ['expiracao' => $expiration],
      'valor' => ['original' => number_format($amount, 2, '.', '')],
      'chave' => $pixKey,
      'solicitacaoPagador' => 'Pagamento Nexa Prospero',
    ];
    $base = rtrim((string)$cfg['api_base_url'], '/');
    $headers = itau_boleto_api_headers($cfg, $accessToken);
    $resp = itau_boleto_http($cfg, 'PUT', $base . '/cob/' . rawurlencode($txid), $headers, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    if ($resp['status'] < 200 || $resp['status'] >= 300 || !is_array($resp['json'])) {
      throw new RuntimeException('O Itaú recusou a criação do Pix. HTTP ' . (int)$resp['status'] . ': ' . itau_boleto_response_message($resp));
    }

    $qrJson = [];
    try {
      $qrResp = itau_boleto_http($cfg, 'GET', $base . '/cob/' . rawurlencode($txid) . '/qrcode', itau_boleto_api_headers($cfg, $accessToken));
      if ($qrResp['status'] >= 200 && $qrResp['status'] < 300 && is_array($qrResp['json'])) $qrJson = $qrResp['json'];
    } catch (Throwable $ignored) {}

    $parsed = pix_checkout_itau_parse_charge($resp['json'], $qrJson);
    return $parsed + [
      'request_payload' => $payload,
      'response_payload' => ['charge'=>$resp['json'], 'qrcode'=>$qrJson],
      'expires_at' => date('Y-m-d H:i:s', time() + $expiration),
      'bank_account_id' => (int)($method['default_bank_account_id'] ?? 0),
      'pix_key' => $pixKey,
    ];
  }
}

if (!function_exists('pix_checkout_itau_status')) {
  function pix_checkout_itau_status(PDO $pdo, int $companyId, array $method, string $txid): array {
    $cfg = pix_checkout_itau_api_config($pdo, $companyId, $method);
    $token = pix_checkout_itau_token($cfg);
    $accessToken = (string)($token['access_token'] ?? '');
    $base = rtrim((string)$cfg['api_base_url'], '/');
    $resp = itau_boleto_http($cfg, 'GET', $base . '/cob/' . rawurlencode($txid), itau_boleto_api_headers($cfg, $accessToken));
    if ($resp['status'] < 200 || $resp['status'] >= 300 || !is_array($resp['json'])) {
      throw new RuntimeException('Não foi possível consultar o Pix no Itaú. HTTP ' . (int)$resp['status'] . ': ' . itau_boleto_response_message($resp));
    }
    $parsed = pix_checkout_itau_parse_charge($resp['json']);
    return $parsed + ['response_payload'=>$resp['json']];
  }
}

if (!function_exists('pix_checkout_create_charge')) {
  function pix_checkout_create_charge(PDO $pdo, int $companyId, int $userId, int $paymentMethodId, float $amount, ?int $cashSessionId = null): array {
    if ($amount <= 0) throw new RuntimeException('O valor do Pix precisa ser maior que zero.');
    $method = pix_checkout_method_config($pdo, $companyId, $paymentMethodId);
    if (!$method || empty($method['pix_auto_qr_enabled'])) throw new RuntimeException('Esta forma de pagamento não está habilitada para QR Code Pix automático.');
    $provider = pix_checkout_resolve_provider($pdo, $companyId, $method);
    if ($provider !== 'ITAU') throw new RuntimeException('O provedor ' . ($provider ?: 'não informado') . ' está configurado no Hub, porém o conector Pix Recebimentos ainda não foi homologado nesta versão.');

    $txid = pix_checkout_txid($companyId);
    $st = $pdo->prepare("INSERT INTO gestao_pix_charges (company_id,payment_method_id,bank_account_id,user_id,cash_session_id,provider,status,txid,amount,pix_key,created_at,updated_at) VALUES (?,?,?,?,?,?,'CRIANDO',?,?,?,NOW(),NOW())");
    $st->execute([$companyId,$paymentMethodId,(int)($method['default_bank_account_id']??0)?:null,$userId,$cashSessionId?:null,$provider,$txid,$amount,trim((string)($method['pix_key']??''))?:null]);
    $id = (int)$pdo->lastInsertId();

    try {
      $result = pix_checkout_itau_create($pdo, $companyId, $method, $txid, $amount);
      $status = !empty($result['paid']) ? 'PAGO' : 'AGUARDANDO';
      $pdo->prepare("UPDATE gestao_pix_charges SET status=?,provider_status=?,bank_account_id=?,pix_key=?,pix_copy_paste=?,qr_image=?,e2eid=?,expires_at=?,request_payload=?,response_payload=?,paid_at=CASE WHEN ?='PAGO' THEN NOW() ELSE paid_at END,error_message=NULL,updated_at=NOW() WHERE company_id=? AND id=?")
        ->execute([
          $status,(string)($result['provider_status']??''),(int)($result['bank_account_id']??0)?:null,(string)($result['pix_key']??''),
          (string)($result['copy_paste']??''),(string)($result['qr_image']??''),(string)($result['e2eid']??'')?:null,(string)($result['expires_at']??'')?:null,
          json_encode($result['request_payload']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($result['response_payload']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
          $status,$companyId,$id
        ]);
    } catch (Throwable $e) {
      $pdo->prepare("UPDATE gestao_pix_charges SET status='ERRO',error_message=?,updated_at=NOW() WHERE company_id=? AND id=?")->execute([mb_substr($e->getMessage(),0,1000,'UTF-8'),$companyId,$id]);
      throw $e;
    }
    return pix_checkout_get_charge($pdo, $companyId, $id);
  }
}

if (!function_exists('pix_checkout_get_charge')) {
  function pix_checkout_get_charge(PDO $pdo, int $companyId, int $chargeId): array {
    if ($chargeId <= 0) return [];
    $st = $pdo->prepare('SELECT * FROM gestao_pix_charges WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId,$chargeId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('pix_checkout_sync_charge')) {
  function pix_checkout_sync_charge(PDO $pdo, int $companyId, int $chargeId): array {
    $charge = pix_checkout_get_charge($pdo, $companyId, $chargeId);
    if (!$charge) throw new RuntimeException('Cobrança Pix não encontrada.');
    if (strtoupper((string)$charge['status']) === 'PAGO') return $charge;
    if (!empty($charge['expires_at']) && strtotime((string)$charge['expires_at']) < time()) {
      $pdo->prepare("UPDATE gestao_pix_charges SET status='EXPIRADO',updated_at=NOW() WHERE company_id=? AND id=? AND status<>'PAGO'")->execute([$companyId,$chargeId]);
      return pix_checkout_get_charge($pdo,$companyId,$chargeId);
    }
    $method = pix_checkout_method_config($pdo, $companyId, (int)$charge['payment_method_id']);
    $provider = strtoupper((string)$charge['provider']);
    if ($provider !== 'ITAU') throw new RuntimeException('O conector de consulta Pix para o provedor ' . ($provider ?: 'não informado') . ' ainda não foi homologado.');
    try {
      $result = pix_checkout_itau_status($pdo, $companyId, $method, (string)$charge['txid']);
      $paid = !empty($result['paid']);
      $status = $paid ? 'PAGO' : 'AGUARDANDO';
      $pdo->prepare("UPDATE gestao_pix_charges SET status=?,provider_status=?,pix_copy_paste=COALESCE(NULLIF(?,''),pix_copy_paste),qr_image=COALESCE(NULLIF(?,''),qr_image),e2eid=COALESCE(NULLIF(?,''),e2eid),response_payload=?,last_check_at=NOW(),paid_at=CASE WHEN ?='PAGO' THEN COALESCE(paid_at,NOW()) ELSE paid_at END,error_message=NULL,updated_at=NOW() WHERE company_id=? AND id=?")
        ->execute([$status,(string)($result['provider_status']??''),(string)($result['copy_paste']??''),(string)($result['qr_image']??''),(string)($result['e2eid']??''),json_encode($result['response_payload']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$status,$companyId,$chargeId]);
    } catch (Throwable $e) {
      $pdo->prepare('UPDATE gestao_pix_charges SET last_check_at=NOW(),error_message=?,updated_at=NOW() WHERE company_id=? AND id=?')->execute([mb_substr($e->getMessage(),0,1000,'UTF-8'),$companyId,$chargeId]);
      throw $e;
    }
    return pix_checkout_get_charge($pdo, $companyId, $chargeId);
  }
}

if (!function_exists('pix_checkout_public_charge')) {
  function pix_checkout_public_charge(array $charge): array {
    $status = strtoupper((string)($charge['status'] ?? ''));
    return [
      'id'=>(int)($charge['id']??0),
      'status'=>$status,
      'paid'=>$status==='PAGO',
      'expired'=>$status==='EXPIRADO',
      'provider_status'=>(string)($charge['provider_status']??''),
      'txid'=>(string)($charge['txid']??''),
      'amount'=>(float)($charge['amount']??0),
      'copy_paste'=>(string)($charge['pix_copy_paste']??''),
      'qr_image'=>(string)($charge['qr_image']??''),
      'e2eid'=>(string)($charge['e2eid']??''),
      'expires_at'=>(string)($charge['expires_at']??''),
      'message'=>(string)($charge['error_message']??''),
    ];
  }
}

if (!function_exists('pix_checkout_parse_money')) {
  function pix_checkout_parse_money($raw): float {
    $value = trim((string)$raw);
    if ($value === '') return 0.0;
    $value = preg_replace('/[^0-9,.-]/', '', $value) ?? '0';
    if (strpos($value, ',') !== false) {
      $value = str_replace('.', '', $value);
      $value = str_replace(',', '.', $value);
    }
    return round((float)$value, 2);
  }
}

if (!function_exists('pix_checkout_validate_post_payments')) {
  function pix_checkout_validate_post_payments(PDO $pdo, int $companyId, array $post): array {
    $methods = array_values((array)($post['payment_method_id'] ?? []));
    $amounts = array_values((array)($post['payment_amount'] ?? []));
    $charges = array_values((array)($post['payment_pix_charge_id'] ?? []));
    $verified = [];
    foreach ($methods as $i => $methodRaw) {
      $methodId = (int)$methodRaw;
      if ($methodId <= 0) continue;
      $method = pix_checkout_method_config($pdo, $companyId, $methodId);
      if (!$method || empty($method['pix_auto_qr_enabled'])) continue;
      $amountRaw = (string)($amounts[$i] ?? '0');
      $amount = pix_checkout_parse_money($amountRaw);
      $chargeId = (int)($charges[$i] ?? 0);
      if ($chargeId <= 0) throw new RuntimeException('A forma ' . (string)($method['name'] ?? 'Pix') . ' exige confirmação do QR Code Pix antes de finalizar.');
      $charge = pix_checkout_get_charge($pdo, $companyId, $chargeId);
      if (!$charge || (int)$charge['payment_method_id'] !== $methodId) throw new RuntimeException('Cobrança Pix inválida para a forma de pagamento selecionada.');
      if (abs((float)$charge['amount'] - $amount) > 0.01) throw new RuntimeException('O valor do Pix foi alterado após a geração do QR Code. Gere uma nova cobrança.');
      if (strtoupper((string)$charge['status']) !== 'PAGO') $charge = pix_checkout_sync_charge($pdo, $companyId, $chargeId);
      if (strtoupper((string)$charge['status']) !== 'PAGO') throw new RuntimeException('O Pix ainda não foi confirmado pelo banco.');
      $verified[] = $chargeId;
    }
    return array_values(array_unique($verified));
  }
}

if (!function_exists('pix_checkout_link_order')) {
  function pix_checkout_link_order(PDO $pdo, int $companyId, int $orderId, array $chargeIds): void {
    if ($orderId <= 0 || !$chargeIds) return;
    $st = $pdo->prepare("UPDATE gestao_pix_charges SET order_id=?,updated_at=NOW() WHERE company_id=? AND id=? AND status='PAGO'");
    foreach ($chargeIds as $id) $st->execute([$orderId,$companyId,(int)$id]);
  }
}

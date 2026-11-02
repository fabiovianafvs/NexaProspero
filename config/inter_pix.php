<?php
declare(strict_types=1);

if (!function_exists('inter_pix_db_has_table')) {
  function inter_pix_db_has_table(PDO $pdo, string $table): bool {
    $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  }
}

if (!function_exists('inter_pix_db_has_column')) {
  function inter_pix_db_has_column(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
  }
}

if (!function_exists('inter_pix_ensure_schema')) {
  function inter_pix_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS inter_pix_config (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      environment VARCHAR(20) NOT NULL DEFAULT 'sandbox',
      client_id VARCHAR(191) NOT NULL,
      client_secret VARCHAR(191) NOT NULL,
      oauth_scope VARCHAR(255) DEFAULT NULL,
      pix_key VARCHAR(191) NOT NULL,
      cert_path VARCHAR(255) DEFAULT NULL,
      key_path VARCHAR(255) DEFAULT NULL,
      ca_path VARCHAR(255) DEFAULT NULL,
      api_base_url VARCHAR(255) DEFAULT NULL,
      oauth_url VARCHAR(255) DEFAULT NULL,
      webhook_url VARCHAR(255) DEFAULT NULL,
      webhook_token VARCHAR(100) DEFAULT NULL,
      charge_expiration_seconds INT NOT NULL DEFAULT 86400,
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_inter_pix_active (active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }
}

if (!function_exists('inter_pix_default_api_base')) {
  function inter_pix_default_api_base(string $environment): string {
    $env = strtolower(trim($environment));
    return $env === 'production'
      ? 'https://cdpj.partners.bancointer.com.br'
      : 'https://cdpj-sandbox.partners.uatinter.co';
  }
}

if (!function_exists('inter_pix_default_oauth_url')) {
  function inter_pix_default_oauth_url(string $environment): string {
    return rtrim(inter_pix_default_api_base($environment), '/') . '/oauth/v2/token';
  }
}

if (!function_exists('inter_pix_normalize_config')) {
  function inter_pix_normalize_config(array $row): array {
    $environment = strtolower(trim((string)($row['environment'] ?? 'sandbox')));
    if ($environment === '') {
      $environment = 'sandbox';
    }
    $apiBase = trim((string)($row['api_base_url'] ?? ''));
    if ($apiBase === '') {
      $apiBase = inter_pix_default_api_base($environment);
    }
    $oauthUrl = trim((string)($row['oauth_url'] ?? ''));
    if ($oauthUrl === '') {
      $oauthUrl = inter_pix_default_oauth_url($environment);
    }
    $scope = trim((string)($row['oauth_scope'] ?? ''));
    if ($scope === '') {
      $scope = 'cob.read cob.write cobv.read cobv.write pix.read pix.write webhook.read webhook.write';
    }

    return [
      'id' => isset($row['id']) ? (int)$row['id'] : 0,
      'environment' => $environment,
      'client_id' => trim((string)($row['client_id'] ?? '')),
      'client_secret' => trim((string)($row['client_secret'] ?? '')),
      'oauth_scope' => $scope,
      'pix_key' => trim((string)($row['pix_key'] ?? '')),
      'cert_path' => trim((string)($row['cert_path'] ?? '')),
      'key_path' => trim((string)($row['key_path'] ?? '')),
      'ca_path' => trim((string)($row['ca_path'] ?? '')),
      'api_base_url' => rtrim($apiBase, '/'),
      'oauth_url' => $oauthUrl,
      'webhook_url' => trim((string)($row['webhook_url'] ?? '')),
      'webhook_token' => trim((string)($row['webhook_token'] ?? '')),
      'charge_expiration_seconds' => max(300, (int)($row['charge_expiration_seconds'] ?? 86400)),
      'active' => !empty($row['active']) ? 1 : 0,
    ];
  }
}

if (!function_exists('inter_pix_get_config')) {
  function inter_pix_get_config(PDO $pdo, bool $onlyActive = false): ?array {
    inter_pix_ensure_schema($pdo);
    $sql = 'SELECT * FROM inter_pix_config';
    if ($onlyActive) {
      $sql .= ' WHERE active = 1';
    }
    $sql .= ' ORDER BY id DESC LIMIT 1';
    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: null;
    return $row ? inter_pix_normalize_config($row) : null;
  }
}

if (!function_exists('inter_pix_require_config')) {
  function inter_pix_require_config(PDO $pdo): array {
    $cfg = inter_pix_get_config($pdo, true);
    if (!$cfg) {
      throw new RuntimeException('Configure a integração Pix do Banco Inter na Central SaaS antes de gerar cobranças.');
    }
    foreach (['client_id','client_secret','pix_key'] as $required) {
      if (trim((string)($cfg[$required] ?? '')) === '') {
        throw new RuntimeException('A configuração do Banco Inter está incompleta. Preencha Client ID, Client Secret e Chave Pix.');
      }
    }
    return $cfg;
  }
}

if (!function_exists('inter_pix_save_config')) {
  function inter_pix_save_config(PDO $pdo, array $data): int {
    inter_pix_ensure_schema($pdo);
    $cfg = inter_pix_normalize_config($data);
    $existing = inter_pix_get_config($pdo, false);
    if ($existing && (int)$existing['id'] > 0) {
      $st = $pdo->prepare('UPDATE inter_pix_config SET environment=?, client_id=?, client_secret=?, oauth_scope=?, pix_key=?, cert_path=?, key_path=?, ca_path=?, api_base_url=?, oauth_url=?, webhook_url=?, webhook_token=?, charge_expiration_seconds=?, active=? WHERE id=?');
      $st->execute([
        $cfg['environment'], $cfg['client_id'], $cfg['client_secret'], $cfg['oauth_scope'], $cfg['pix_key'],
        $cfg['cert_path'] !== '' ? $cfg['cert_path'] : null,
        $cfg['key_path'] !== '' ? $cfg['key_path'] : null,
        $cfg['ca_path'] !== '' ? $cfg['ca_path'] : null,
        $cfg['api_base_url'], $cfg['oauth_url'],
        $cfg['webhook_url'] !== '' ? $cfg['webhook_url'] : null,
        $cfg['webhook_token'] !== '' ? $cfg['webhook_token'] : null,
        $cfg['charge_expiration_seconds'], $cfg['active'], (int)$existing['id'],
      ]);
      return (int)$existing['id'];
    }

    $st = $pdo->prepare('INSERT INTO inter_pix_config (environment, client_id, client_secret, oauth_scope, pix_key, cert_path, key_path, ca_path, api_base_url, oauth_url, webhook_url, webhook_token, charge_expiration_seconds, active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([
      $cfg['environment'], $cfg['client_id'], $cfg['client_secret'], $cfg['oauth_scope'], $cfg['pix_key'],
      $cfg['cert_path'] !== '' ? $cfg['cert_path'] : null,
      $cfg['key_path'] !== '' ? $cfg['key_path'] : null,
      $cfg['ca_path'] !== '' ? $cfg['ca_path'] : null,
      $cfg['api_base_url'], $cfg['oauth_url'],
      $cfg['webhook_url'] !== '' ? $cfg['webhook_url'] : null,
      $cfg['webhook_token'] !== '' ? $cfg['webhook_token'] : null,
      $cfg['charge_expiration_seconds'], $cfg['active'],
    ]);
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('inter_pix_mask_secret')) {
  function inter_pix_mask_secret(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    if (strlen($value) <= 6) return str_repeat('*', strlen($value));
    return substr($value, 0, 3) . str_repeat('*', max(0, strlen($value) - 6)) . substr($value, -3);
  }
}

if (!function_exists('inter_pix_compose_headers')) {
  function inter_pix_compose_headers(array $headers): array {
    $out = [];
    foreach ($headers as $k => $v) {
      $out[] = $k . ': ' . $v;
    }
    return $out;
  }
}

if (!function_exists('inter_pix_http')) {
  function inter_pix_http(array $cfg, string $method, string $url, array $headers = [], ?string $body = null, bool $useBasicAuth = false): array {
    $ch = curl_init($url);
    if ($ch === false) {
      throw new RuntimeException('Não foi possível iniciar a conexão cURL.');
    }

    $responseHeaders = [];
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CUSTOMREQUEST => strtoupper($method),
      CURLOPT_HTTPHEADER => inter_pix_compose_headers($headers),
      CURLOPT_HEADERFUNCTION => static function ($curl, $headerLine) use (&$responseHeaders) {
        $len = strlen($headerLine);
        $headerLine = trim($headerLine);
        if ($headerLine === '' || !str_contains($headerLine, ':')) return $len;
        [$name, $value] = explode(':', $headerLine, 2);
        $responseHeaders[strtolower(trim($name))] = trim($value);
        return $len;
      },
      CURLOPT_TIMEOUT => 60,
      CURLOPT_CONNECTTIMEOUT => 20,
    ]);

    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    if (!empty($cfg['cert_path']) && is_file((string)$cfg['cert_path'])) curl_setopt($ch, CURLOPT_SSLCERT, (string)$cfg['cert_path']);
    if (!empty($cfg['key_path']) && is_file((string)$cfg['key_path'])) curl_setopt($ch, CURLOPT_SSLKEY, (string)$cfg['key_path']);
    if (!empty($cfg['ca_path']) && is_file((string)$cfg['ca_path'])) curl_setopt($ch, CURLOPT_CAINFO, (string)$cfg['ca_path']);
    if ($useBasicAuth) curl_setopt($ch, CURLOPT_USERPWD, (string)$cfg['client_id'] . ':' . (string)$cfg['client_secret']);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
      throw new RuntimeException('Falha na comunicação com o Banco Inter: ' . ($error !== '' ? $error : 'sem resposta') . ' (' . $errno . ').');
    }

    $json = json_decode($raw, true);
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $raw, 'json' => is_array($json) ? $json : null];
  }
}

if (!function_exists('inter_pix_pick_first')) {
  function inter_pix_pick_first(array $source, array $keys): mixed {
    foreach ($keys as $key) {
      if (array_key_exists($key, $source) && $source[$key] !== null && $source[$key] !== '') return $source[$key];
    }
    foreach ($source as $value) {
      if (is_array($value)) {
        $found = inter_pix_pick_first($value, $keys);
        if ($found !== null && $found !== '') return $found;
      }
    }
    return null;
  }
}

if (!function_exists('inter_pix_error_message')) {
  function inter_pix_error_message(array $response): string {
    $json = $response['json'] ?? null;
    if (is_array($json)) {
      foreach (['title','message','detail','descricao','mensagem'] as $key) {
        if (!empty($json[$key])) return (string)$json[$key];
      }
      if (!empty($json['violacoes']) && is_array($json['violacoes'])) {
        $first = $json['violacoes'][0] ?? null;
        if (is_array($first)) {
          foreach (['razao','mensagem','propriedade'] as $key) {
            if (!empty($first[$key])) return (string)$first[$key];
          }
        }
      }
    }
    return trim((string)($response['body'] ?? ''));
  }
}

if (!function_exists('inter_pix_get_token')) {
  function inter_pix_get_token(array $cfg, ?string $scope = null): string {
    $scope = trim((string)($scope ?: $cfg['oauth_scope'] ?: ''));
    $body = http_build_query(['grant_type' => 'client_credentials', 'scope' => $scope]);
    $resp = inter_pix_http($cfg, 'POST', (string)$cfg['oauth_url'], [
      'Accept' => 'application/json',
      'Content-Type' => 'application/x-www-form-urlencoded',
    ], $body, true);
    if ($resp['status'] < 200 || $resp['status'] >= 300 || empty($resp['json']['access_token'])) {
      throw new RuntimeException('Não foi possível obter o token OAuth do Inter. ' . inter_pix_error_message($resp));
    }
    return (string)$resp['json']['access_token'];
  }
}

if (!function_exists('inter_pix_fetch_qrcode')) {
  function inter_pix_fetch_qrcode(array $cfg, string $token, int $locId): array {
    $url = rtrim((string)$cfg['api_base_url'], '/') . '/pix/v2/loc/' . $locId . '/qrcode';
    $resp = inter_pix_http($cfg, 'GET', $url, [
      'Accept' => 'application/json',
      'Authorization' => 'Bearer ' . $token,
    ]);
    if ($resp['status'] >= 200 && $resp['status'] < 300 && is_array($resp['json'])) return $resp['json'];
    return [];
  }
}

if (!function_exists('inter_pix_parse_charge_response')) {
  function inter_pix_parse_charge_response(array $chargeResponse, array $qrcodeResponse = []): array {
    $locId = inter_pix_pick_first($chargeResponse, ['id']);
    if ($locId === null && !empty($chargeResponse['loc']) && is_array($chargeResponse['loc'])) {
      $locId = inter_pix_pick_first($chargeResponse['loc'], ['id']);
    }
    $copyPaste = inter_pix_pick_first(array_merge($qrcodeResponse, $chargeResponse), ['pixCopiaECola', 'qrcode', 'qrCode']);
    $qrImage = inter_pix_pick_first(array_merge($qrcodeResponse, $chargeResponse), ['imagemQrcode', 'imagemQRCode', 'imagem', 'qrImage', 'qrcodeBase64']);
    return [
      'txid' => (string)(inter_pix_pick_first($chargeResponse, ['txid']) ?? ''),
      'status' => (string)(inter_pix_pick_first($chargeResponse, ['status']) ?? ''),
      'loc_id' => $locId !== null ? (int)$locId : null,
      'location' => (string)(inter_pix_pick_first($chargeResponse, ['location']) ?? inter_pix_pick_first($qrcodeResponse, ['linkVisualizacao']) ?? ''),
      'copy_paste' => is_string($copyPaste) ? trim($copyPaste) : '',
      'qr_image' => is_string($qrImage) ? trim($qrImage) : '',
      'payload' => $chargeResponse,
      'qrcode_payload' => $qrcodeResponse,
    ];
  }
}

if (!function_exists('inter_pix_create_immediate_charge')) {
  function inter_pix_create_immediate_charge(array $cfg, array $data): array {
    $token = inter_pix_get_token($cfg);
    $txid = (string)($data['txid'] ?? '');
    if ($txid === '') throw new InvalidArgumentException('TXID inválido para criar cobrança Pix.');
    $amount = number_format((float)($data['amount'] ?? 0), 2, '.', '');
    $expiration = max(300, (int)($data['expiration'] ?? $cfg['charge_expiration_seconds'] ?? 86400));
    $payerName = trim((string)($data['payer_name'] ?? 'Cliente'));
    $payerDocument = preg_replace('/\D+/', '', (string)($data['payer_document'] ?? ''));
    $devedor = ['nome' => mb_substr($payerName !== '' ? $payerName : 'Cliente', 0, 200, 'UTF-8')];
    if (strlen($payerDocument) === 11) $devedor['cpf'] = $payerDocument;
    elseif (strlen($payerDocument) === 14) $devedor['cnpj'] = $payerDocument;

    $payload = [
      'calendario' => ['expiracao' => $expiration],
      'devedor' => $devedor,
      'valor' => ['original' => $amount],
      'chave' => (string)$cfg['pix_key'],
      'solicitacaoPagador' => mb_substr((string)($data['description'] ?? 'Pagamento de assinatura SaaS'), 0, 140, 'UTF-8'),
    ];

    $url = rtrim((string)$cfg['api_base_url'], '/') . '/pix/v2/cob/' . rawurlencode($txid);
    $resp = inter_pix_http($cfg, 'PUT', $url, [
      'Accept' => 'application/json',
      'Content-Type' => 'application/json',
      'Authorization' => 'Bearer ' . $token,
    ], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    if ($resp['status'] < 200 || $resp['status'] >= 300 || !is_array($resp['json'])) {
      throw new RuntimeException('O Inter recusou a criação da cobrança Pix. ' . inter_pix_error_message($resp));
    }

    $locId = 0;
    if (!empty($resp['json']['loc']) && is_array($resp['json']['loc'])) $locId = (int)($resp['json']['loc']['id'] ?? 0);
    $qr = $locId > 0 ? inter_pix_fetch_qrcode($cfg, $token, $locId) : [];
    $parsed = inter_pix_parse_charge_response($resp['json'], $qr);
    $parsed['request_payload'] = $payload;
    return $parsed;
  }
}

if (!function_exists('inter_pix_get_charge')) {
  function inter_pix_get_charge(array $cfg, string $txid): array {
    $token = inter_pix_get_token($cfg);
    $url = rtrim((string)$cfg['api_base_url'], '/') . '/pix/v2/cob/' . rawurlencode($txid);
    $resp = inter_pix_http($cfg, 'GET', $url, [
      'Accept' => 'application/json',
      'Authorization' => 'Bearer ' . $token,
    ]);
    if ($resp['status'] < 200 || $resp['status'] >= 300 || !is_array($resp['json'])) {
      throw new RuntimeException('Não foi possível consultar a cobrança Pix. ' . inter_pix_error_message($resp));
    }
    return $resp['json'];
  }
}

if (!function_exists('inter_pix_list_received_pix')) {
  function inter_pix_list_received_pix(array $cfg, string $txid, ?DateTimeImmutable $start = null, ?DateTimeImmutable $end = null): array {
    $token = inter_pix_get_token($cfg);
    $start = $start ?: new DateTimeImmutable('-7 days');
    $end = $end ?: new DateTimeImmutable('now');
    $query = http_build_query([
      'inicio' => $start->format(DATE_ATOM),
      'fim' => $end->format(DATE_ATOM),
      'txid' => $txid,
    ]);
    $url = rtrim((string)$cfg['api_base_url'], '/') . '/pix/v2/pix?' . $query;
    $resp = inter_pix_http($cfg, 'GET', $url, [
      'Accept' => 'application/json',
      'Authorization' => 'Bearer ' . $token,
    ]);
    if ($resp['status'] < 200 || $resp['status'] >= 300 || !is_array($resp['json'])) return [];
    return $resp['json'];
  }
}

if (!function_exists('inter_pix_charge_paid')) {
  function inter_pix_charge_paid(array $chargeData): bool {
    $status = strtoupper(trim((string)($chargeData['status'] ?? '')));
    if (in_array($status, ['CONCLUIDA', 'REMOVIDA_PELO_USUARIO_RECEBEDOR'], true)) return $status === 'CONCLUIDA';
    if (!empty($chargeData['pix']) && is_array($chargeData['pix'])) return true;
    return false;
  }
}

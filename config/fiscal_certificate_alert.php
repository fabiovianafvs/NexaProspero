<?php
declare(strict_types=1);

/**
 * V11.36.5 - Alerta leve de vencimento de certificado fiscal.
 *
 * Este arquivo NÃO executa DDL e NÃO altera configuração fiscal. Apenas lê:
 *   - app_config / business / fiscal_certificate_alert
 *   - fiscal_certificates da empresa operacional
 * e, quando possível, extrai o notAfter real do A1 para mostrar data + hora.
 */

if (!function_exists('nexa_cert_alert_table_exists')) {
  function nexa_cert_alert_table_exists(PDO $pdo, string $table): bool {
    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
      $st->execute([$table]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('nexa_cert_alert_business_config')) {
  function nexa_cert_alert_business_config(PDO $pdo, int $companyId): array {
    $cfg = [];
    if ($companyId <= 0 || !nexa_cert_alert_table_exists($pdo, 'app_config')) return $cfg;
    try {
      $st = $pdo->prepare("SELECT cfg_json FROM app_config WHERE company_id=? AND cfg_key='business' LIMIT 1");
      $st->execute([$companyId]);
      $raw = $st->fetchColumn();
      if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $cfg = $decoded;
      }
    } catch (Throwable $e) {}
    return $cfg;
  }
}


if (!function_exists('nexa_cert_alert_settings')) {
  function nexa_cert_alert_settings(PDO $pdo, int $companyId): array {
    $defaults = ['enabled' => true, 'warning_days' => 10, 'purchase_url' => ''];
    if ($companyId <= 0 || !nexa_cert_alert_table_exists($pdo, 'app_config')) return $defaults;
    try {
      $st = $pdo->prepare("SELECT cfg_json FROM app_config WHERE company_id=? AND cfg_key='fiscal_certificate_alert' LIMIT 1");
      $st->execute([$companyId]);
      $raw = $st->fetchColumn();
      if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return array_merge($defaults, $decoded);
      }
    } catch (Throwable $e) {}
    return $defaults;
  }
}

if (!function_exists('nexa_cert_alert_expiration_timestamp')) {
  function nexa_cert_alert_expiration_timestamp(array $cert, string $absolutePath, DateTimeZone $tz): int {
    $password = (string)($cert['password_secret'] ?? '');
    $ext = strtolower(pathinfo($absolutePath !== '' ? $absolutePath : (string)($cert['file_path'] ?? ''), PATHINFO_EXTENSION));
    try {
      if ($absolutePath !== '' && is_file($absolutePath) && is_readable($absolutePath) && function_exists('openssl_x509_parse')) {
        $raw = @file_get_contents($absolutePath);
        if (is_string($raw) && $raw !== '') {
          $pem = '';
          if (in_array($ext, ['pfx','p12'], true) && function_exists('openssl_pkcs12_read') && $password !== '') {
            $bundle = [];
            if (@openssl_pkcs12_read($raw, $bundle, $password) && !empty($bundle['cert'])) {
              $pem = (string)$bundle['cert'];
            }
          } elseif (!in_array($ext, ['pfx','p12'], true)) {
            $pem = $raw;
          }
          if ($pem !== '') {
            $parsed = @openssl_x509_parse($pem);
            if (is_array($parsed) && !empty($parsed['validTo_time_t'])) return max(0, (int)$parsed['validTo_time_t']);
          }
        }
      }
    } catch (Throwable $e) {}

    $dbDate = trim((string)($cert['valid_until'] ?? ''));
    if ($dbDate !== '') {
      try {
        $dt = new DateTimeImmutable($dbDate . ' 23:59:59', $tz);
        return $dt->getTimestamp();
      } catch (Throwable $e) {}
    }
    return 0;
  }
}

if (!function_exists('nexa_fiscal_certificate_alert_context')) {
  function nexa_fiscal_certificate_alert_context(PDO $pdo, int $companyId): ?array {
    if ($companyId <= 0 || !nexa_cert_alert_table_exists($pdo, 'fiscal_certificates')) return null;

    $business = nexa_cert_alert_business_config($pdo, $companyId);
    $alertCfg = nexa_cert_alert_settings($pdo, $companyId);

    // Habilitado por padrão para clientes existentes. A configuração geral pode desligar explicitamente.
    $enabled = !empty($alertCfg['enabled']);
    if (!$enabled) return null;
    $warningDays = max(1, min(90, (int)($alertCfg['warning_days'] ?? 10)));
    $purchaseUrl = trim((string)($alertCfg['purchase_url'] ?? ''));
    if ($purchaseUrl !== '' && !preg_match('~^https?://~i', $purchaseUrl)) $purchaseUrl = 'https://' . ltrim($purchaseUrl, '/');
    if ($purchaseUrl !== '' && filter_var($purchaseUrl, FILTER_VALIDATE_URL) === false) $purchaseUrl = '';

    $tzName = trim((string)($business['timezone'] ?? 'America/Sao_Paulo')) ?: 'America/Sao_Paulo';
    try { $tz = new DateTimeZone($tzName); } catch (Throwable $e) { $tz = new DateTimeZone('America/Sao_Paulo'); }

    try {
      $st = $pdo->prepare("SELECT * FROM fiscal_certificates WHERE company_id=? AND is_active=1 ORDER BY valid_until DESC, id DESC LIMIT 1");
      $st->execute([$companyId]);
      $cert = $st->fetch(PDO::FETCH_ASSOC);
      if (!$cert) return null;
    } catch (Throwable $e) {
      return null;
    }

    $filePath = trim((string)($cert['file_path'] ?? ''));
    $absolute = '';
    if ($filePath !== '') {
      $candidate = dirname(__DIR__) . '/' . ltrim($filePath, '/');
      $absolute = realpath($candidate) ?: $candidate;
    }
    $expiresTs = nexa_cert_alert_expiration_timestamp($cert, $absolute, $tz);
    if ($expiresTs <= 0) return null;

    $now = new DateTimeImmutable('now', $tz);
    $expires = (new DateTimeImmutable('@' . $expiresTs))->setTimezone($tz);
    $today = $now->format('Y-m-d');
    $expiryDate = $expires->format('Y-m-d');
    $expired = $expiresTs <= $now->getTimestamp();
    $sameDay = $today === $expiryDate;

    $startToday = new DateTimeImmutable($today . ' 00:00:00', $tz);
    $startExpiry = new DateTimeImmutable($expiryDate . ' 00:00:00', $tz);
    $days = (int)$startToday->diff($startExpiry)->format('%r%a');
    if (!$expired && !$sameDay && $days > $warningDays) return null;

    $companyName = '';
    if (nexa_cert_alert_table_exists($pdo, 'companies')) {
      try {
        $stName = $pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1');
        $stName->execute([$companyId]);
        $companyName = trim((string)($stName->fetchColumn() ?: ''));
      } catch (Throwable $e) {}
    }

    return [
      'company_id' => $companyId,
      'company_name' => $companyName,
      'certificate_id' => (int)($cert['id'] ?? 0),
      'display_name' => trim((string)($cert['display_name'] ?? '')),
      'expires_timestamp' => $expiresTs,
      'expires_br' => $expires->format('d/m/Y \à\s H:i:s'),
      'expiry_date' => $expiryDate,
      'warning_days' => $warningDays,
      'days_remaining' => $days,
      'expired' => $expired,
      'today' => !$expired && $sameDay,
      'purchase_url' => $purchaseUrl,
      'ack_key' => 'nexa_cert_alert_ack_' . $companyId . '_' . (int)($cert['id'] ?? 0) . '_' . $today,
    ];
  }
}

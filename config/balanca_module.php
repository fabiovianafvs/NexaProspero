<?php
declare(strict_types=1);

require_once __DIR__ . '/config_store.php';

if (!function_exists('balanca_column_exists')) {
  function balanca_column_exists(PDO $pdo, string $table, string $column): bool {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) return false;
    try {
      $st = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?');
      $st->execute([$column]);
      if ($st->fetch(PDO::FETCH_ASSOC)) return true;
    } catch (Throwable $e) {}
    try {
      $st = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
      $st->execute([$table, $column]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('balanca_add_column_if_missing')) {
  function balanca_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) return;
    if (balanca_column_exists($pdo, $table, $column)) return;
    try { $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition); } catch (Throwable $e) {}
  }
}

if (!function_exists('balanca_ensure_schema')) {
  function balanca_ensure_schema(PDO $pdo): array {
    $cols = [
      'produto_pesavel' => balanca_column_exists($pdo, 'produtos', 'produto_pesavel'),
    ];

    if (!$pdo->inTransaction()) {
      if (!$cols['produto_pesavel']) {
        balanca_add_column_if_missing($pdo, 'produtos', 'produto_pesavel', 'TINYINT(1) NOT NULL DEFAULT 0');
        $cols['produto_pesavel'] = balanca_column_exists($pdo, 'produtos', 'produto_pesavel');
      }
    }

    return $cols;
  }
}

if (!function_exists('balanca_bool')) {
  function balanca_bool($value): bool {
    if (is_bool($value)) return $value;
    if (is_numeric($value)) return ((int)$value) === 1;
    $value = strtolower(trim((string)$value));
    return in_array($value, ['1','true','yes','sim','on'], true);
  }
}

if (!function_exists('balanca_default_config')) {
  function balanca_default_config(): array {
    return [
      'enabled' => false,
      'model' => 'prix_3_fit',
      'connection' => 'webserial',
      'protocol' => 'auto',
      'request_mode' => 'enq',
      'baud_rate' => 2400,
      'data_bits' => 8,
      'parity' => 'none',
      'stop_bits' => 1,
      'decimal_places' => 3,
      'stable_only' => true,
      'timeout_ms' => 2500,
      'min_weight' => 0.001,
      'auto_fill_quantity' => true,
      'port_hint' => 'COM3 / USB Serial / Bluetooth SPP',
    ];
  }
}

if (!function_exists('balanca_sanitize_config')) {
  function balanca_sanitize_config(array $cfg): array {
    $defaults = balanca_default_config();
    $cfg = array_merge($defaults, $cfg);

    $models = ['prix_3_fit','prix_3','toledo','filizola','urano','urano_udc_coe','generic_serial'];
    $protocols = ['auto','prt1','prt2','prt3','prt5','urano12','urano_std01','urano_std02','urano_std03','urano_std04','urano_std05','generic_line'];
    $connections = ['webserial','manual'];
    $parities = ['none','even','odd'];
    $dataBits = [7,8];
    $stopBits = [1,2];
    $requestModes = ['enq','continuous','manual'];

    $cfg['enabled'] = balanca_bool($cfg['enabled'] ?? false);
    $cfg['model'] = in_array((string)$cfg['model'], $models, true) ? (string)$cfg['model'] : $defaults['model'];
    $cfg['connection'] = in_array((string)$cfg['connection'], $connections, true) ? (string)$cfg['connection'] : $defaults['connection'];
    $cfg['protocol'] = in_array((string)$cfg['protocol'], $protocols, true) ? (string)$cfg['protocol'] : $defaults['protocol'];
    $cfg['request_mode'] = in_array((string)$cfg['request_mode'], $requestModes, true) ? (string)$cfg['request_mode'] : $defaults['request_mode'];
    $cfg['baud_rate'] = max(1200, min(115200, (int)($cfg['baud_rate'] ?? $defaults['baud_rate'])));
    $cfg['data_bits'] = in_array((int)$cfg['data_bits'], $dataBits, true) ? (int)$cfg['data_bits'] : $defaults['data_bits'];
    $cfg['parity'] = in_array((string)$cfg['parity'], $parities, true) ? (string)$cfg['parity'] : $defaults['parity'];
    $cfg['stop_bits'] = in_array((int)$cfg['stop_bits'], $stopBits, true) ? (int)$cfg['stop_bits'] : $defaults['stop_bits'];
    $cfg['decimal_places'] = max(0, min(4, (int)($cfg['decimal_places'] ?? $defaults['decimal_places'])));
    $cfg['stable_only'] = balanca_bool($cfg['stable_only'] ?? true);
    $cfg['timeout_ms'] = max(500, min(10000, (int)($cfg['timeout_ms'] ?? $defaults['timeout_ms'])));
    $cfg['min_weight'] = max(0, (float)str_replace(',', '.', (string)($cfg['min_weight'] ?? $defaults['min_weight'])));
    $cfg['auto_fill_quantity'] = balanca_bool($cfg['auto_fill_quantity'] ?? true);
    $cfg['port_hint'] = substr(trim((string)($cfg['port_hint'] ?? $defaults['port_hint'])), 0, 120);

    return $cfg;
  }
}

if (!function_exists('balanca_config')) {
  function balanca_config(PDO $pdo, int $companyId): array {
    $cfg = balanca_default_config();
    try {
      $biz = function_exists('load_business_config') ? load_business_config($companyId) : (config_store_get($pdo, $companyId, 'business') ?: []);
      if (isset($biz['scale']) && is_array($biz['scale'])) $cfg = array_merge($cfg, (array)$biz['scale']);
      if (isset($biz['balanca']) && is_array($biz['balanca'])) $cfg = array_merge($cfg, (array)$biz['balanca']);
    } catch (Throwable $e) {}
    return balanca_sanitize_config($cfg);
  }
}

if (!function_exists('balanca_public_config')) {
  function balanca_public_config(array $cfg): array {
    return [
      'enabled' => !empty($cfg['enabled']),
      'model' => (string)($cfg['model'] ?? 'prix_3_fit'),
      'connection' => (string)($cfg['connection'] ?? 'webserial'),
      'protocol' => (string)($cfg['protocol'] ?? 'auto'),
      'requestMode' => (string)($cfg['request_mode'] ?? 'enq'),
      'baudRate' => (int)($cfg['baud_rate'] ?? 2400),
      'dataBits' => (int)($cfg['data_bits'] ?? 8),
      'parity' => (string)($cfg['parity'] ?? 'none'),
      'stopBits' => (int)($cfg['stop_bits'] ?? 1),
      'decimalPlaces' => (int)($cfg['decimal_places'] ?? 3),
      'stableOnly' => !empty($cfg['stable_only']),
      'timeoutMs' => (int)($cfg['timeout_ms'] ?? 2500),
      'minWeight' => (float)($cfg['min_weight'] ?? 0.001),
      'autoFillQuantity' => !empty($cfg['auto_fill_quantity']),
      'portHint' => (string)($cfg['port_hint'] ?? ''),
    ];
  }
}

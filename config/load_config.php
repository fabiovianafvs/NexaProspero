<?php
declare(strict_types=1);

require_once __DIR__ . '/pdo.php';
require_once __DIR__ . '/config_store.php';

/**
 * Carrega configs padrão (arquivos PHP) e aplica overrides salvos no banco.
 * Keys usadas na tabela app_config:
 *  - app
 *  - business
 *  - firebird
 */

function load_app_config(int $companyId): array {
  $defaults = require __DIR__ . '/app.php';
  try {
    global $pdo;
    if ($pdo instanceof PDO) {
      $over = config_store_get($pdo, $companyId, 'app');
      if ($over) return config_deep_merge($defaults, $over);
    }
  } catch (Throwable $e) { /* ignora */ }
  return $defaults;
}

function business_delivery_fees_ensure_schema(PDO $pdo): void {
  if ($pdo->inTransaction()) return;

  $pdo->exec("CREATE TABLE IF NOT EXISTS company_delivery_fees (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    district VARCHAR(150) NOT NULL DEFAULT '',
    fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_company_delivery_fees_company (company_id),
    KEY idx_company_delivery_fees_default (company_id, is_default),
    KEY idx_company_delivery_fees_district (company_id, district)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function business_load_delivery_fees_from_db(PDO $pdo, int $companyId): ?array {
  try {
    $stCheck = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
    $stCheck->execute(['company_delivery_fees']);
    if (!$stCheck->fetchColumn()) return null;

    $st = $pdo->prepare('SELECT district, fee, is_default FROM company_delivery_fees WHERE company_id = ? AND active = 1 ORDER BY is_default DESC, district ASC, id ASC');
    $st->execute([$companyId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return null;

    $out = ['default' => 0.0, 'bairros' => []];
    foreach ($rows as $r) {
      $fee = (float)($r['fee'] ?? 0);
      if (!empty($r['is_default'])) {
        $out['default'] = $fee;
        continue;
      }
      $district = trim((string)($r['district'] ?? ''));
      if ($district !== '') $out['bairros'][$district] = $fee;
    }
    return $out;
  } catch (Throwable $e) {
    return null;
  }
}

function business_save_delivery_fees_to_db(PDO $pdo, int $companyId, float $defaultFee, array $districtFees): void {
  business_delivery_fees_ensure_schema($pdo);

  $pdo->prepare('DELETE FROM company_delivery_fees WHERE company_id = ?')->execute([$companyId]);
  $st = $pdo->prepare('INSERT INTO company_delivery_fees (company_id, district, fee, is_default, active) VALUES (?, ?, ?, ?, 1)');
  $st->execute([$companyId, '', max(0, $defaultFee), 1]);

  foreach ($districtFees as $district => $fee) {
    $district = trim((string)$district);
    if ($district === '') continue;
    $st->execute([$companyId, $district, max(0, (float)$fee), 0]);
  }
}


if (!function_exists('business_normalize_order_types')) {
  function business_normalize_order_types(array $cfg): array {
    $orderTypes = (array)($cfg['order_types'] ?? []);
    $legacyBool = static function(string $key, bool $default) use ($orderTypes): bool {
      return array_key_exists($key, $orderTypes) ? !empty($orderTypes[$key]) : $default;
    };
    $normalizeSet = static function(array $source, array $defaults): array {
      $out = [];
      foreach ($defaults as $key => $default) {
        $out[$key] = array_key_exists($key, $source) ? !empty($source[$key]) : (bool)$default;
      }
      return $out;
    };

    $checkoutDefaults = [
      'entrega' => $legacyBool('entrega', true),
      'retirada' => $legacyBool('retirada', true),
    ];
    $pdvDefaults = [
      'balcao' => $legacyBool('balcao', true),
      'retirada' => $legacyBool('retirada', true),
      'entrega' => $legacyBool('entrega', true),
    ];

    $checkout = $normalizeSet(isset($orderTypes['checkout']) && is_array($orderTypes['checkout']) ? $orderTypes['checkout'] : [], $checkoutDefaults);
    $pdv = $normalizeSet(isset($orderTypes['pdv']) && is_array($orderTypes['pdv']) ? $orderTypes['pdv'] : [], $pdvDefaults);

    if (!$checkout['entrega'] && !$checkout['retirada']) $checkout['retirada'] = true;
    if (!$pdv['balcao'] && !$pdv['retirada'] && !$pdv['entrega']) $pdv['balcao'] = true;

    $orderTypes['checkout'] = $checkout;
    $orderTypes['pdv'] = $pdv;
    // Campos legados para telas antigas: ficam habilitados se estiverem disponíveis em algum dos módulos.
    $orderTypes['entrega'] = !empty($checkout['entrega']) || !empty($pdv['entrega']);
    $orderTypes['retirada'] = !empty($checkout['retirada']) || !empty($pdv['retirada']);
    $orderTypes['balcao'] = !empty($pdv['balcao']);
    $cfg['order_types'] = $orderTypes;
    return $cfg;
  }
}

function load_business_config(int $companyId): array {
  $normalize_banner = function(array $cfg): array {
    if (!isset($cfg['banner']) || !is_array($cfg['banner'])) return $cfg;
    $b = $cfg['banner'];
    if (!empty($b['title'])) $b['text'] = (string)$b['title'];
    if (!empty($b['subtitle'])) $b['hint'] = (string)$b['subtitle'];
    if (!empty($b['link_url'])) $b['link'] = (string)$b['link_url'];
    if (!empty($b['link_text'])) $b['cta'] = (string)$b['link_text'];
    $cfg['banner'] = $b;
    return $cfg;
  };

  $defaults = require __DIR__ . '/business.php';
  try {
    global $pdo;
    if ($pdo instanceof PDO) {
      $over = config_store_get($pdo, $companyId, 'business');
      $cfg = $over ? config_deep_merge($defaults, $over) : $defaults;
      $dbFees = business_load_delivery_fees_from_db($pdo, $companyId);
      if (is_array($dbFees)) {
        $cfg['delivery_fees'] = $dbFees;
      }
      return $normalize_banner(business_normalize_order_types($cfg));
    }
  } catch (Throwable $e) { /* ignora */ }
  return $normalize_banner(business_normalize_order_types($defaults));
}

function load_firebird_config(int $companyId): array {
  $defaults = require __DIR__ . '/firebird.php';

  $normalize = function(array $cfg): array {
    if (isset($cfg['password']) && !isset($cfg['pass'])) {
      $cfg['pass'] = (string)$cfg['password'];
    }

    if (isset($cfg['mapping']) && is_array($cfg['mapping'])) {
      $pt = trim((string)($cfg['mapping']['pedido_table'] ?? ''));
      $it = trim((string)($cfg['mapping']['item_table'] ?? ''));
      if (!isset($cfg['tables']) || !is_array($cfg['tables'])) $cfg['tables'] = [];
      if ($pt !== '' && empty($cfg['tables']['orders'])) $cfg['tables']['orders'] = $pt;
      if ($it !== '' && empty($cfg['tables']['items']))  $cfg['tables']['items']  = $it;
    }

    if (!isset($cfg['tables']) || !is_array($cfg['tables'])) $cfg['tables'] = [];
    foreach (['orders','items'] as $k) {
      if (isset($cfg['tables'][$k])) $cfg['tables'][$k] = trim((string)$cfg['tables'][$k]);
    }

    foreach (['host','database','user','pass','charset'] as $k) {
      if (isset($cfg[$k])) $cfg[$k] = trim((string)$cfg[$k]);
    }
    if (isset($cfg['port'])) $cfg['port'] = (int)$cfg['port'];
    if (isset($cfg['empresa_codigo'])) $cfg['empresa_codigo'] = (int)$cfg['empresa_codigo'];
    if (isset($cfg['cliente_padrao'])) $cfg['cliente_padrao'] = (int)$cfg['cliente_padrao'];

    if (!isset($cfg['fields']) || !is_array($cfg['fields'])) $cfg['fields'] = [];
    foreach (['orders','items'] as $grp) {
      if (!isset($cfg['fields'][$grp]) || !is_array($cfg['fields'][$grp])) $cfg['fields'][$grp] = [];
      foreach ($cfg['fields'][$grp] as $k => $v) {
        $cfg['fields'][$grp][$k] = trim((string)$v);
      }
    }

    if (!isset($cfg['defaults']) || !is_array($cfg['defaults'])) $cfg['defaults'] = [];
    if (!isset($cfg['defaults']['orders']) || !is_array($cfg['defaults']['orders'])) $cfg['defaults']['orders'] = [];
    if (!isset($cfg['defaults']['items']) || !is_array($cfg['defaults']['items'])) $cfg['defaults']['items'] = [];
    foreach (['orders','items'] as $grp) {
      foreach ($cfg['defaults'][$grp] as $k => $v) {
        $cfg['defaults'][$grp][$k] = is_string($v) ? trim($v) : $v;
      }
    }

    return $cfg;
  };

  try {
    global $pdo;
    if ($pdo instanceof PDO) {
      $over = config_store_get($pdo, $companyId, 'firebird');
      if ($over) return $normalize(config_deep_merge($defaults, $over));
    }
  } catch (Throwable $e) { /* ignora */ }

  return $normalize($defaults);
}


<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/saas_modules.php';

if (!function_exists('reseller_money_value')) {
  function reseller_money_value(mixed $value): float {
    if (is_float($value) || is_int($value)) return max(0, (float)$value);
    $raw = trim((string)$value);
    if ($raw === '') return 0.0;
    if (str_contains($raw, ',') && str_contains($raw, '.')) {
      $raw = str_replace('.', '', $raw);
    }
    $raw = str_replace(',', '.', $raw);
    return max(0, round((float)$raw, 2));
  }
}

if (!function_exists('reseller_column_exists')) {
  function reseller_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
      $st->execute([$table, $column]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('reseller_table_exists')) {
  function reseller_table_exists(PDO $pdo, string $table): bool {
    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
      $st->execute([$table]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('reseller_index_exists')) {
  function reseller_index_exists(PDO $pdo, string $table, string $index): bool {
    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1');
      $st->execute([$table, $index]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('reseller_add_index')) {
  function reseller_add_index(PDO $pdo, string $table, string $index, string $columns, array &$errors): void {
    if (reseller_index_exists($pdo, $table, $index)) return;
    try {
      $pdo->exec("ALTER TABLE `{$table}` ADD KEY `{$index}` ({$columns})");
    } catch (Throwable $e) {
      $errors[] = "{$table}.{$index}: " . $e->getMessage();
    }
  }
}

if (!function_exists('reseller_add_unique_index')) {
  function reseller_add_unique_index(PDO $pdo, string $table, string $index, string $columns, array &$errors): void {
    if (reseller_index_exists($pdo, $table, $index)) return;
    try {
      $pdo->exec("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$index}` ({$columns})");
    } catch (Throwable $e) {
      $errors[] = "{$table}.{$index}: " . $e->getMessage();
    }
  }
}

if (!function_exists('reseller_add_column')) {
  function reseller_add_column(PDO $pdo, string $table, string $column, string $definition, array &$errors): void {
    if (reseller_column_exists($pdo, $table, $column)) return;
    try {
      $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    } catch (Throwable $e) {
      $errors[] = "{$table}.{$column}: " . $e->getMessage();
    }
  }
}

if (!function_exists('reseller_exec')) {
  function reseller_exec(PDO $pdo, string $sql, array &$errors): void {
    try { $pdo->exec($sql); } catch (Throwable $e) { $errors[] = $e->getMessage(); }
  }
}

if (!function_exists('reseller_ensure_schema')) {
  function reseller_ensure_schema(PDO $pdo): array {
    static $cachedResult = null;
    if (is_array($cachedResult)) return $cachedResult;

    $errors = [];
    $migrationKey = '2026-07-21-resellers-v2';
    $seedKey = '2026-07-21-resellers-pricing-seed-v1';

    // A marca de migração sozinha não garante que versões posteriores tenham todas
    // as colunas comerciais. A validação abaixo é executada uma vez por requisição.

    try { saas_ensure_modules_schema($pdo); } catch (Throwable $e) { $errors[] = $e->getMessage(); }

    // Metadados opcionais usados pelo fluxo comercial de revendas.
    // Bancos anteriores ao módulo de Revendas podem não possuir essas colunas.
    // A rotina é idempotente e mantém compatibilidade com instalações antigas.
    if (reseller_table_exists($pdo, 'subscriptions')) {
      reseller_add_column($pdo, 'subscriptions', 'manual_note', "TEXT NULL", $errors);
      reseller_add_column($pdo, 'subscriptions', 'granted_by', "VARCHAR(120) NULL", $errors);
      reseller_add_column($pdo, 'subscriptions', 'granted_reason', "VARCHAR(255) NULL", $errors);
      reseller_add_column($pdo, 'subscriptions', 'updated_at', "DATETIME NULL DEFAULT NULL", $errors);
    }

    if (reseller_table_exists($pdo, 'companies')) {
      reseller_add_column($pdo, 'companies', 'is_reseller', "TINYINT(1) NOT NULL DEFAULT 0 AFTER module_mode", $errors);
      reseller_add_column($pdo, 'companies', 'reseller_company_id', "INT UNSIGNED NULL AFTER is_reseller", $errors);
      reseller_add_column($pdo, 'companies', 'reseller_status', "VARCHAR(20) NOT NULL DEFAULT 'inactive' AFTER reseller_company_id", $errors);
      reseller_add_column($pdo, 'companies', 'reseller_brand_name', "VARCHAR(150) NULL AFTER reseller_status", $errors);
      reseller_add_column($pdo, 'companies', 'reseller_whatsapp', "VARCHAR(30) NULL AFTER reseller_brand_name", $errors);
      reseller_add_column($pdo, 'companies', 'reseller_pix_key', "VARCHAR(190) NULL AFTER reseller_whatsapp", $errors);
      reseller_add_column($pdo, 'companies', 'reseller_notes', "TEXT NULL AFTER reseller_pix_key", $errors);
      reseller_add_index($pdo, 'companies', 'idx_companies_reseller_company', '`reseller_company_id`', $errors);
      reseller_add_index($pdo, 'companies', 'idx_companies_is_reseller', '`is_reseller`', $errors);
    }

    if (reseller_table_exists($pdo, 'saas_modules')) {
      reseller_add_column($pdo, 'saas_modules', 'item_type', "VARCHAR(20) NOT NULL DEFAULT 'module' AFTER description", $errors);
      reseller_add_column($pdo, 'saas_modules', 'monthly_cost', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER item_type", $errors);
      reseller_add_column($pdo, 'saas_modules', 'monthly_price', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER monthly_cost", $errors);
      reseller_add_column($pdo, 'saas_modules', 'setup_cost', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER monthly_price", $errors);
      reseller_add_column($pdo, 'saas_modules', 'setup_price', "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER setup_cost", $errors);
      reseller_add_column($pdo, 'saas_modules', 'is_public_sale', "TINYINT(1) NOT NULL DEFAULT 0 AFTER setup_price", $errors);
      reseller_add_column($pdo, 'saas_modules', 'allow_quantity', "TINYINT(1) NOT NULL DEFAULT 0", $errors);
      reseller_add_column($pdo, 'saas_modules', 'quantity_label', "VARCHAR(60) NULL", $errors);
      reseller_add_column($pdo, 'saas_modules', 'quantity_min', "INT UNSIGNED NOT NULL DEFAULT 1", $errors);
      reseller_add_column($pdo, 'saas_modules', 'quantity_max', "INT UNSIGNED NOT NULL DEFAULT 99", $errors);
      reseller_add_column($pdo, 'saas_modules', 'quantity_default', "INT UNSIGNED NOT NULL DEFAULT 1", $errors);
    }
    if (reseller_table_exists($pdo, 'saas_company_modules')) {
      reseller_add_column($pdo, 'saas_company_modules', 'quantity', "DECIMAL(10,2) NOT NULL DEFAULT 1.00", $errors);
    }

    reseller_exec($pdo, "
      CREATE TABLE IF NOT EXISTS saas_schema_migrations (
        migration_key VARCHAR(120) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (migration_key)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    reseller_exec($pdo, "
      CREATE TABLE IF NOT EXISTS saas_reseller_module_prices (
        reseller_company_id INT UNSIGNED NOT NULL,
        module_id INT UNSIGNED NOT NULL,
        sale_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        setup_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (reseller_company_id, module_id),
        KEY idx_reseller_prices_module (module_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    reseller_exec($pdo, "
      CREATE TABLE IF NOT EXISTS saas_reseller_invoices (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        reseller_company_id INT UNSIGNED NOT NULL,
        reference_month CHAR(7) NOT NULL,
        due_date DATE NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_reseller_invoice_month (reseller_company_id, reference_month),
        KEY idx_reseller_invoice_status (status)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    reseller_exec($pdo, "
      CREATE TABLE IF NOT EXISTS saas_reseller_invoice_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        invoice_id BIGINT UNSIGNED NOT NULL,
        client_company_id INT UNSIGNED NOT NULL,
        module_id INT UNSIGNED NOT NULL,
        item_type VARCHAR(20) NOT NULL DEFAULT 'monthly',
        description VARCHAR(255) NOT NULL,
        quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
        unit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_reseller_invoice_item (invoice_id, client_company_id, module_id, item_type),
        KEY idx_reseller_invoice_items_client (client_company_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    reseller_exec($pdo, "
      CREATE TABLE IF NOT EXISTS saas_reseller_customer_charges (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        reseller_company_id INT UNSIGNED NOT NULL,
        client_company_id INT UNSIGNED NOT NULL,
        reference_month CHAR(7) NULL,
        origin VARCHAR(20) NOT NULL DEFAULT 'manual',
        description VARCHAR(255) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        due_date DATE NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        paid_at DATETIME NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_reseller_customer_charge_month (reseller_company_id, client_company_id, reference_month),
        KEY idx_reseller_customer_charge_owner (reseller_company_id, status),
        KEY idx_reseller_customer_charge_client (client_company_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    if (reseller_table_exists($pdo, 'saas_reseller_customer_charges')) {
      reseller_add_column($pdo, 'saas_reseller_customer_charges', 'reference_month', "CHAR(7) NULL AFTER client_company_id", $errors);
      reseller_add_column($pdo, 'saas_reseller_customer_charges', 'origin', "VARCHAR(20) NOT NULL DEFAULT 'manual' AFTER reference_month", $errors);
      reseller_add_unique_index($pdo, 'saas_reseller_customer_charges', 'uk_reseller_customer_charge_month', '`reseller_company_id`,`client_company_id`,`reference_month`', $errors);
    }

    reseller_exec($pdo, "
      CREATE TABLE IF NOT EXISTS saas_reseller_customer_charge_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        charge_id BIGINT UNSIGNED NOT NULL,
        module_id INT UNSIGNED NOT NULL,
        item_type VARCHAR(20) NOT NULL DEFAULT 'monthly',
        description VARCHAR(255) NOT NULL,
        quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
        unit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_reseller_customer_charge_item (charge_id, module_id, item_type),
        KEY idx_reseller_customer_charge_item_module (module_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    // Valores iniciais equivalentes aos preços existentes na landing. Executa uma única vez.
    $alreadySeeded = false;
    try {
      $stSeed = $pdo->prepare('SELECT 1 FROM saas_schema_migrations WHERE migration_key = ? LIMIT 1');
      $stSeed->execute([$seedKey]);
      $alreadySeeded = (bool)$stSeed->fetchColumn();
    } catch (Throwable $e) {}

    if (!$alreadySeeded && reseller_table_exists($pdo, 'saas_modules') && reseller_column_exists($pdo, 'saas_modules', 'monthly_price')) {
      $defaults = [
        'delivery' => [59.90, 350.00, 1],
        'gestao' => [189.90, 375.00, 1],
        'comandas' => [59.90, 150.00, 1],
        'impressao' => [29.90, 0.00, 1],
      ];
      $st = $pdo->prepare('UPDATE saas_modules SET monthly_price = CASE WHEN monthly_price = 0 THEN ? ELSE monthly_price END, setup_price = CASE WHEN setup_price = 0 THEN ? ELSE setup_price END, is_public_sale = CASE WHEN is_public_sale = 0 THEN ? ELSE is_public_sale END WHERE code = ?');
      foreach ($defaults as $code => $values) {
        try { $st->execute([$values[0], $values[1], $values[2], $code]); } catch (Throwable $e) { $errors[] = $e->getMessage(); }
      }
    }

    $errors = array_values(array_filter($errors, static function (string $message): bool {
      foreach (['Duplicate key name', 'Duplicate column name'] as $ignore) {
        if (stripos($message, $ignore) !== false) return false;
      }
      return true;
    }));

    $ok = reseller_table_exists($pdo, 'saas_reseller_module_prices')
      && reseller_table_exists($pdo, 'saas_reseller_invoices')
      && reseller_table_exists($pdo, 'saas_reseller_invoice_items')
      && reseller_table_exists($pdo, 'saas_reseller_customer_charges')
      && reseller_table_exists($pdo, 'saas_reseller_customer_charge_items')
      && reseller_column_exists($pdo, 'saas_reseller_customer_charges', 'reference_month')
      && reseller_column_exists($pdo, 'companies', 'is_reseller');

    if ($ok && !$errors) {
      try {
        $stMigration = $pdo->prepare('INSERT IGNORE INTO saas_schema_migrations (migration_key) VALUES (?)');
        $stMigration->execute([$seedKey]);
        $stMigration->execute([$migrationKey]);
      } catch (Throwable $e) {
        $errors[] = $e->getMessage();
        $ok = false;
      }
    }

    return $cachedResult = ['ok' => $ok, 'errors' => $errors];
  }
}

if (!function_exists('reseller_company_by_id')) {
  function reseller_company_by_id(PDO $pdo, int $companyId): ?array {
    if ($companyId <= 0) return null;
    try {
      $st = $pdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
      $st->execute([$companyId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      return $row ?: null;
    } catch (Throwable $e) { return null; }
  }
}

if (!function_exists('reseller_is_active_company')) {
  function reseller_is_active_company(array $company): bool {
    return !empty($company['is_reseller'])
      && strtolower((string)($company['reseller_status'] ?? 'inactive')) === 'active'
      && !in_array(strtolower((string)($company['status'] ?? 'active')), ['blocked','suspended'], true);
  }
}

if (!function_exists('reseller_resolve_context')) {
  function reseller_resolve_context(PDO $pdo, bool $hostOnly = false): ?array {
    try { reseller_ensure_schema($pdo); } catch (Throwable $e) {}
    $slug = '';
    if (!$hostOnly) {
      $slug = trim((string)($_GET['revenda'] ?? $_POST['revenda'] ?? ''));
    }
    if ($slug === '') {
      $host = current_host_name();
      foreach (configured_root_domains() as $root) {
        $suffix = '.' . $root;
        if (str_ends_with($host, $suffix)) {
          $candidate = substr($host, 0, -strlen($suffix));
          if ($candidate !== '' && !str_contains($candidate, '.') && !in_array($candidate, ['www','app'], true)) $slug = $candidate;
          break;
        }
      }
    }
    if ($slug === '') return null;
    try {
      $st = $pdo->prepare("SELECT * FROM companies WHERE (slug = ? OR subdomain = ?) AND is_reseller = 1 LIMIT 1");
      $st->execute([$slug, $slug]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      return $row && reseller_is_active_company($row) ? $row : null;
    } catch (Throwable $e) { return null; }
  }
}



if (!function_exists('reseller_ensure_pricing_schema')) {
  function reseller_ensure_pricing_schema(PDO $pdo): array {
    static $cachedResult = null;
    if (is_array($cachedResult)) return $cachedResult;
    $errors = [];
    try { reseller_ensure_schema($pdo); } catch (Throwable $e) { $errors[] = $e->getMessage(); }

    // Não usa AFTER nas colunas comerciais. Bancos mais antigos podem não ter
    // original_price/desired_plan_id e o MySQL abortaria toda a correção por causa
    // da posição de uma coluna que ainda não existe.
    $plansHadOriginalPrice = reseller_column_exists($pdo, 'plans', 'original_price');
    $plansHadSetupPrice = reseller_column_exists($pdo, 'plans', 'setup_price');

    reseller_add_column($pdo, 'companies', 'commercial_plan_id', 'INT UNSIGNED NULL', $errors);
    reseller_add_index($pdo, 'companies', 'idx_companies_commercial_plan', '`commercial_plan_id`', $errors);
    reseller_add_column($pdo, 'plans', 'original_price', 'DECIMAL(10,2) NULL', $errors);
    reseller_add_column($pdo, 'plans', 'monthly_cost', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00', $errors);
    reseller_add_column($pdo, 'plans', 'setup_cost', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00', $errors);
    reseller_add_column($pdo, 'plans', 'setup_price', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00', $errors);

    // Somente ao criar as colunas pela primeira vez, aplica os valores comerciais
    // já usados no configurador. Valores editados anteriormente não são alterados.
    $plansHaveSlug = reseller_column_exists($pdo, 'plans', 'slug');
    if (!$plansHadOriginalPrice && reseller_column_exists($pdo, 'plans', 'original_price')) {
      $sqlOriginal = $plansHaveSlug
        ? "UPDATE plans SET original_price = CASE LOWER(COALESCE(slug,'')) WHEN 'essencial' THEN 79.90 WHEN 'profissional' THEN 159.90 WHEN 'enterprise' THEN 229.90 ELSE original_price END WHERE LOWER(COALESCE(slug,'')) IN ('essencial','profissional','enterprise')"
        : "UPDATE plans SET original_price = CASE LOWER(COALESCE(name,'')) WHEN 'essencial' THEN 79.90 WHEN 'delivery start' THEN 79.90 WHEN 'profissional' THEN 159.90 WHEN 'delivery pro' THEN 159.90 WHEN 'enterprise' THEN 229.90 WHEN 'delivery premium' THEN 229.90 ELSE original_price END";
      reseller_exec($pdo, $sqlOriginal, $errors);
    }
    if (!$plansHadSetupPrice && reseller_column_exists($pdo, 'plans', 'setup_price')) {
      $sqlSetup = $plansHaveSlug
        ? "UPDATE plans SET setup_price = 350.00 WHERE LOWER(COALESCE(slug,'')) IN ('essencial','profissional','enterprise')"
        : "UPDATE plans SET setup_price = 350.00 WHERE LOWER(COALESCE(name,'')) IN ('essencial','delivery start','profissional','delivery pro','enterprise','delivery premium')";
      reseller_exec($pdo, $sqlSetup, $errors);
    }

    reseller_exec($pdo, "CREATE TABLE IF NOT EXISTS saas_reseller_plan_prices (
      reseller_company_id INT UNSIGNED NOT NULL,
      plan_id INT UNSIGNED NOT NULL,
      sale_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      setup_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (reseller_company_id, plan_id),
      KEY idx_reseller_plan_prices_plan (plan_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", $errors);

    return $cachedResult = [
      'ok' => !$errors
        && reseller_column_exists($pdo, 'companies', 'commercial_plan_id')
        && reseller_column_exists($pdo, 'plans', 'original_price')
        && reseller_column_exists($pdo, 'plans', 'monthly_cost')
        && reseller_column_exists($pdo, 'plans', 'setup_cost')
        && reseller_column_exists($pdo, 'plans', 'setup_price')
        && reseller_table_exists($pdo, 'saas_reseller_plan_prices'),
      'errors' => $errors,
    ];
  }
}

if (!function_exists('reseller_plan_catalog')) {
  function reseller_plan_catalog(PDO $pdo, int $resellerCompanyId = 0, bool $publicOnly = true): array {
    try { reseller_ensure_pricing_schema($pdo); } catch (Throwable $e) {}
    $publicFilter = $publicOnly ? 'AND COALESCE(p.is_public,1)=1' : '';
    $sql = "
      SELECT p.*,
        COALESCE(rp.sale_price, p.price, 0) AS effective_sale_price,
        COALESCE(rp.setup_price, p.setup_price, 0) AS effective_setup_price,
        COALESCE(rp.is_active, 1) AS reseller_price_active
      FROM plans p
      LEFT JOIN saas_reseller_plan_prices rp
        ON rp.plan_id=p.id AND rp.reseller_company_id=?
      WHERE p.is_active=1
        AND LOWER(COALESCE(p.slug,'')) IN ('essencial','profissional','enterprise')
        {$publicFilter}
      ORDER BY COALESCE(p.sort_order,0) ASC, p.id ASC
    ";
    try {
      $st = $pdo->prepare($sql);
      $st->execute([$resellerCompanyId]);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($rows as &$row) {
        $slug = strtolower((string)($row['slug'] ?? ''));
        $row['public_key'] = $slug;
        $row['delivery_key'] = match ($slug) {
          'essencial' => 'start',
          'profissional' => 'pro',
          'enterprise' => 'premium',
          default => '',
        };
      }
      unset($row);
      return $rows;
    } catch (Throwable $e) { return []; }
  }
}

if (!function_exists('reseller_save_plan_prices')) {
  function reseller_save_plan_prices(PDO $pdo, int $resellerCompanyId, array $prices): void {
    if ($resellerCompanyId <= 0) throw new RuntimeException('Revenda inválida.');
    reseller_ensure_pricing_schema($pdo);
    $st = $pdo->prepare("INSERT INTO saas_reseller_plan_prices (reseller_company_id,plan_id,sale_price,setup_price,is_active,updated_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE sale_price=VALUES(sale_price),setup_price=VALUES(setup_price),is_active=VALUES(is_active),updated_at=NOW()");
    foreach ($prices as $planId => $row) {
      $planId = (int)$planId;
      if ($planId <= 0 || !is_array($row)) continue;
      $st->execute([$resellerCompanyId, $planId, reseller_money_value($row['sale_price'] ?? 0), reseller_money_value($row['setup_price'] ?? 0), !empty($row['is_active']) ? 1 : 0]);
    }
  }
}

if (!function_exists('reseller_module_catalog')) {
  function reseller_module_catalog(PDO $pdo, int $resellerCompanyId = 0, bool $publicOnly = true): array {
    try { reseller_ensure_schema($pdo); } catch (Throwable $e) {}
    $where = $publicOnly ? 'AND m.is_public_sale = 1' : '';
    $sql = "
      SELECT m.*,
        COALESCE(rp.sale_price, m.monthly_price, 0) AS effective_sale_price,
        COALESCE(rp.setup_price, m.setup_price, 0) AS effective_setup_price,
        COALESCE(rp.is_active, 1) AS reseller_price_active
      FROM saas_modules m
      LEFT JOIN saas_reseller_module_prices rp
        ON rp.module_id = m.id AND rp.reseller_company_id = ?
      WHERE m.is_active = 1 {$where}
      ORDER BY m.sort_order ASC, m.name ASC, m.id ASC
    ";
    try {
      $st = $pdo->prepare($sql);
      $st->execute([$resellerCompanyId]);
      return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
  }
}

if (!function_exists('reseller_save_prices')) {
  function reseller_save_prices(PDO $pdo, int $resellerCompanyId, array $prices): void {
    if ($resellerCompanyId <= 0) throw new RuntimeException('Revenda inválida.');
    reseller_ensure_schema($pdo);
    $st = $pdo->prepare("INSERT INTO saas_reseller_module_prices (reseller_company_id,module_id,sale_price,setup_price,is_active,updated_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE sale_price=VALUES(sale_price),setup_price=VALUES(setup_price),is_active=VALUES(is_active),updated_at=NOW()");
    foreach ($prices as $moduleId => $row) {
      $moduleId = (int)$moduleId;
      if ($moduleId <= 0 || !is_array($row)) continue;
      $st->execute([$resellerCompanyId, $moduleId, reseller_money_value($row['sale_price'] ?? 0), reseller_money_value($row['setup_price'] ?? 0), !empty($row['is_active']) ? 1 : 0]);
    }
  }
}

if (!function_exists('reseller_ensure_internal_plan')) {
  function reseller_ensure_internal_plan(PDO $pdo): array {
    $slug = 'revenda-faturamento-proprio';
    $st = $pdo->prepare('SELECT * FROM plans WHERE slug = ? LIMIT 1');
    $st->execute([$slug]);
    $plan = $st->fetch(PDO::FETCH_ASSOC);
    if ($plan) return $plan;
    $pdo->prepare("INSERT INTO plans (name,slug,headline,summary,price,max_orders,trial_days,billing_days,badge_text,button_label,sort_order,is_featured,is_active,is_public,is_internal,internal_note) VALUES ('Revenda - faturamento próprio', ?, 'Revenda', 'Cliente faturado diretamente pela revenda.', 0, NULL, 0, 30, 'Revenda', 'Ativação pela revenda', 950, 0, 1, 0, 1, 'Não cobrar diretamente pela Nexa Próspero; custo consolidado na revenda.')")->execute([$slug]);
    $st->execute([$slug]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: ['id' => (int)$pdo->lastInsertId(), 'slug' => $slug, 'price' => 0, 'billing_days' => 30, 'trial_days' => 0, 'name' => 'Revenda - faturamento próprio'];
  }
}

if (!function_exists('reseller_activate')) {
  function reseller_activate(PDO $pdo, int $companyId, array $data = []): void {
    reseller_ensure_schema($pdo);
    $company = reseller_company_by_id($pdo, $companyId);
    if (!$company) throw new RuntimeException('Empresa não encontrada.');
    $brand = trim((string)($data['brand_name'] ?? $company['name'] ?? ''));
    $whatsapp = preg_replace('/\D+/', '', (string)($data['whatsapp'] ?? $company['whatsapp'] ?? '')) ?: null;
    $pix = trim((string)($data['pix_key'] ?? ''));
    $notes = trim((string)($data['notes'] ?? ''));
    $pdo->prepare("UPDATE companies SET is_reseller=1,reseller_status='active',reseller_brand_name=?,reseller_whatsapp=?,reseller_pix_key=?,reseller_notes=?,status='active' WHERE id=?")
      ->execute([$brand !== '' ? $brand : null, $whatsapp, $pix !== '' ? $pix : null, $notes !== '' ? $notes : null, $companyId]);

    $plan = reseller_ensure_internal_plan($pdo);
    $stSubscription = $pdo->prepare('SELECT id,plan_id FROM subscriptions WHERE company_id = ? ORDER BY id DESC LIMIT 1');
    $stSubscription->execute([$companyId]);
    $latestSubscription = $stSubscription->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($latestSubscription && (int)$latestSubscription['plan_id'] === (int)$plan['id']) {
      $set = ["status='active'", 'ends_at=NULL', 'next_billing_at=NULL', "payment_provider='reseller'"];
      $params = [];
      if (reseller_column_exists($pdo, 'subscriptions', 'manual_note')) { $set[] = 'manual_note=?'; $params[] = 'Revenda sem cobrança direta'; }
      if (reseller_column_exists($pdo, 'subscriptions', 'granted_by')) { $set[] = 'granted_by=?'; $params[] = 'Central SaaS'; }
      if (reseller_column_exists($pdo, 'subscriptions', 'granted_reason')) { $set[] = 'granted_reason=?'; $params[] = 'Parceria comercial / revenda'; }
      if (reseller_column_exists($pdo, 'subscriptions', 'updated_at')) $set[] = 'updated_at=NOW()';
      $params[] = (int)$latestSubscription['id'];
      $pdo->prepare('UPDATE subscriptions SET ' . implode(',', $set) . ' WHERE id=?')->execute($params);
    } else {
      $cols = ['company_id','plan_id','status','started_at','ends_at','next_billing_at','payment_provider'];
      $expr = ['?','?',"'active'",'NOW()','NULL','NULL',"'reseller'"];
      $params = [$companyId, (int)$plan['id']];
      if (reseller_column_exists($pdo, 'subscriptions', 'manual_note')) { $cols[]='manual_note'; $expr[]='?'; $params[]='Revenda sem cobrança direta'; }
      if (reseller_column_exists($pdo, 'subscriptions', 'granted_by')) { $cols[]='granted_by'; $expr[]='?'; $params[]='Central SaaS'; }
      if (reseller_column_exists($pdo, 'subscriptions', 'granted_reason')) { $cols[]='granted_reason'; $expr[]='?'; $params[]='Parceria comercial / revenda'; }
      if (reseller_column_exists($pdo, 'subscriptions', 'updated_at')) { $cols[]='updated_at'; $expr[]='NOW()'; }
      $pdo->prepare('INSERT INTO subscriptions (' . implode(',', $cols) . ') VALUES (' . implode(',', $expr) . ')')->execute($params);
    }
    $moduleIds = array_map(static fn(array $m): int => (int)$m['id'], saas_all_modules($pdo, true));
    saas_save_company_modules_custom($pdo, $companyId, $moduleIds, null, 'Todos os módulos liberados para operação da revenda, sem cobrança direta.');
  }
}

if (!function_exists('reseller_save_contact_whatsapp')) {
  function reseller_save_contact_whatsapp(PDO $pdo, int $resellerCompanyId, string $phone): string {
    reseller_ensure_schema($pdo);
    $digits = preg_replace('/\D+/', '', $phone) ?: '';
    if ($digits !== '' && strlen($digits) < 10) throw new RuntimeException('Informe um WhatsApp válido para atendimento da revenda.');
    $pdo->prepare('UPDATE companies SET reseller_whatsapp=? WHERE id=? AND is_reseller=1')
      ->execute([$digits !== '' ? $digits : null, $resellerCompanyId]);
    return $digits;
  }
}

if (!function_exists('reseller_deactivate')) {
  function reseller_deactivate(PDO $pdo, int $companyId): void {
    reseller_ensure_schema($pdo);
    $plan = reseller_ensure_internal_plan($pdo);
    $pdo->beginTransaction();
    try {
      $pdo->prepare("UPDATE companies SET is_reseller=0,reseller_status='inactive' WHERE id=?")->execute([$companyId]);
      $set = ["status='canceled'", 'ends_at=NOW()', 'next_billing_at=NULL'];
      $params = [];
      if (reseller_column_exists($pdo, 'subscriptions', 'manual_note')) { $set[]='manual_note=?'; $params[]='Revenda desativada pela Central SaaS'; }
      if (reseller_column_exists($pdo, 'subscriptions', 'updated_at')) $set[]='updated_at=NOW()';
      $params[] = $companyId;
      $params[] = (int)$plan['id'];
      $pdo->prepare('UPDATE subscriptions SET ' . implode(',', $set) . " WHERE company_id=? AND plan_id=? AND status IN ('active','trial','past_due','pending_payment')")->execute($params);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('reseller_attach_client')) {
  function reseller_attach_client(PDO $pdo, int $clientCompanyId, int $resellerCompanyId): void {
    if ($clientCompanyId <= 0 || $resellerCompanyId <= 0) throw new RuntimeException('Empresa ou revenda inválida.');
    $pdo->prepare("UPDATE companies SET reseller_company_id=?,is_reseller=0,reseller_status='inactive',status='active' WHERE id=?")
      ->execute([$resellerCompanyId, $clientCompanyId]);
  }
}

if (!function_exists('reseller_client_companies')) {
  function reseller_client_companies(PDO $pdo, int $resellerCompanyId): array {
    try {
      $st = $pdo->prepare("SELECT c.*, (SELECT COUNT(*) FROM saas_reseller_customer_charges ch WHERE ch.client_company_id=c.id AND ch.reseller_company_id=? AND ch.status='open') AS open_charges FROM companies c WHERE c.reseller_company_id=? ORDER BY c.name ASC,c.id DESC");
      $st->execute([$resellerCompanyId, $resellerCompanyId]);
      return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
  }
}

if (!function_exists('reseller_generate_customer_charges')) {
  function reseller_generate_customer_charges(PDO $pdo, int $resellerCompanyId, string $referenceMonth, ?string $dueDate = null): array {
    reseller_ensure_schema($pdo);
    reseller_ensure_pricing_schema($pdo);
    if ($resellerCompanyId <= 0) throw new RuntimeException('Revenda inválida.');
    if (!preg_match('/^\d{4}-\d{2}$/', $referenceMonth)) throw new RuntimeException('Competência inválida. Use AAAA-MM.');

    $clients = reseller_client_companies($pdo, $resellerCompanyId);
    $catalog = reseller_module_catalog($pdo, $resellerCompanyId, false);
    $moduleByCode = [];
    foreach ($catalog as $module) $moduleByCode[(string)$module['code']] = $module;
    $planById = [];
    foreach (reseller_plan_catalog($pdo, $resellerCompanyId, true) as $planRow) $planById[(int)$planRow['id']] = $planRow;
    $deliveryModule = $moduleByCode['delivery'] ?? null;

    $generated = 0;
    $grandTotal = 0.0;
    $skippedPaid = 0;
    $chargeIds = [];

    $pdo->beginTransaction();
    try {
      $findCharge = $pdo->prepare('SELECT id,status FROM saas_reseller_customer_charges WHERE reseller_company_id=? AND client_company_id=? AND reference_month=? LIMIT 1');
      $insertCharge = $pdo->prepare("INSERT INTO saas_reseller_customer_charges (reseller_company_id,client_company_id,reference_month,origin,description,amount,due_date,status) VALUES (?,?,?,'automatic',?,0,?,'open')");
      $updateCharge = $pdo->prepare("UPDATE saas_reseller_customer_charges SET origin='automatic',description=?,amount=0,due_date=?,status='open',paid_at=NULL,updated_at=NOW() WHERE id=?");
      $insertItem = $pdo->prepare("INSERT INTO saas_reseller_customer_charge_items (charge_id,module_id,item_type,description,quantity,unit_amount,total_amount) VALUES (?,?,?,?,?,?,?)");
      $priorSetup = $pdo->prepare("SELECT COUNT(*) FROM saas_reseller_customer_charge_items i INNER JOIN saas_reseller_customer_charges ch ON ch.id=i.charge_id WHERE ch.reseller_company_id=? AND ch.client_company_id=? AND i.module_id=? AND i.item_type='setup' AND ch.status<>'canceled' AND ch.id<>?");

      foreach ($clients as $client) {
        $clientId = (int)$client['id'];
        $findCharge->execute([$resellerCompanyId, $clientId, $referenceMonth]);
        $existing = $findCharge->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existing && (string)$existing['status'] === 'paid') {
          $skippedPaid++;
          continue;
        }

        $description = 'Mensalidade ' . $referenceMonth . ' - ' . (string)$client['name'];
        if ($existing) {
          $chargeId = (int)$existing['id'];
          $updateCharge->execute([$description, $dueDate ?: null, $chargeId]);
        } else {
          $insertCharge->execute([$resellerCompanyId, $clientId, $referenceMonth, $description, $dueDate ?: null]);
          $chargeId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare('DELETE FROM saas_reseller_customer_charge_items WHERE charge_id=?')->execute([$chargeId]);
        $chargeTotal = 0.0;
        $clientCodes = saas_company_module_codes($pdo, $clientId);
        $clientQuantities = saas_company_module_quantities($pdo, $clientId);

        $commercialPlanId = (int)($client['commercial_plan_id'] ?? $client['desired_plan_id'] ?? 0);
        $deliveryPlan = $planById[$commercialPlanId] ?? null;
        if ($deliveryPlan && $deliveryModule && in_array('delivery', $clientCodes, true) && !empty($deliveryPlan['reseller_price_active'])) {
          $salePrice = reseller_money_value($deliveryPlan['effective_sale_price'] ?? 0);
          if ($salePrice > 0) {
            $itemDescription = 'Mensalidade ' . (string)($deliveryPlan['name'] ?? 'Delivery');
            $insertItem->execute([$chargeId, (int)$deliveryModule['id'], 'monthly', $itemDescription, 1, $salePrice, $salePrice]);
            $chargeTotal += $salePrice;
          }
          $setupPrice = reseller_money_value($deliveryPlan['effective_setup_price'] ?? 0);
          if ($setupPrice > 0) {
            $priorSetup->execute([$resellerCompanyId, $clientId, (int)$deliveryModule['id'], $chargeId]);
            if ((int)$priorSetup->fetchColumn() === 0) {
              $itemDescription = 'Implantação ' . (string)($deliveryPlan['name'] ?? 'Delivery');
              $insertItem->execute([$chargeId, (int)$deliveryModule['id'], 'setup', $itemDescription, 1, $setupPrice, $setupPrice]);
              $chargeTotal += $setupPrice;
            }
          }
        }

        foreach ($clientCodes as $code) {
          if ($code === 'delivery') continue;
          $module = $moduleByCode[$code] ?? null;
          if (!$module || empty($module['reseller_price_active'])) continue;

          $quantity = !empty($module['allow_quantity']) ? max(1.0, (float)($clientQuantities[$code] ?? 1)) : 1.0;
          $quantitySuffix = $quantity > 1 ? ' (' . rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.') . 'x)' : '';

          $salePrice = reseller_money_value($module['effective_sale_price'] ?? 0);
          if ($salePrice > 0) {
            $itemTotal = round($salePrice * $quantity, 2);
            $itemDescription = 'Mensalidade ' . (string)$module['name'] . $quantitySuffix;
            $insertItem->execute([$chargeId, (int)$module['id'], 'monthly', $itemDescription, $quantity, $salePrice, $itemTotal]);
            $chargeTotal += $itemTotal;
          }

          $setupPrice = reseller_money_value($module['effective_setup_price'] ?? 0);
          if ($setupPrice > 0) {
            $priorSetup->execute([$resellerCompanyId, $clientId, (int)$module['id'], $chargeId]);
            if ((int)$priorSetup->fetchColumn() === 0) {
              $itemTotal = round($setupPrice * $quantity, 2);
              $itemDescription = 'Implantação ' . (string)$module['name'] . $quantitySuffix;
              $insertItem->execute([$chargeId, (int)$module['id'], 'setup', $itemDescription, $quantity, $setupPrice, $itemTotal]);
              $chargeTotal += $itemTotal;
            }
          }
        }

        if ($chargeTotal <= 0) {
          $pdo->prepare('DELETE FROM saas_reseller_customer_charges WHERE id=?')->execute([$chargeId]);
          continue;
        }

        $pdo->prepare('UPDATE saas_reseller_customer_charges SET amount=?,updated_at=NOW() WHERE id=?')->execute([$chargeTotal, $chargeId]);
        $generated++;
        $chargeIds[] = $chargeId;
        $grandTotal += $chargeTotal;
      }

      $pdo->commit();
      return ['generated' => $generated, 'amount' => round($grandTotal, 2), 'skipped_paid' => $skippedPaid, 'charge_ids' => $chargeIds];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('reseller_generate_invoice')) {
  function reseller_generate_invoice(PDO $pdo, int $resellerCompanyId, string $referenceMonth, ?string $dueDate = null): int {
    reseller_ensure_schema($pdo);
    reseller_ensure_pricing_schema($pdo);
    if (!preg_match('/^\d{4}-\d{2}$/', $referenceMonth)) throw new RuntimeException('Competência inválida. Use AAAA-MM.');
    if ($resellerCompanyId <= 0) throw new RuntimeException('Revenda inválida.');
    $clients = reseller_client_companies($pdo, $resellerCompanyId);
    $pdo->beginTransaction();
    try {
      $find = $pdo->prepare('SELECT id,status FROM saas_reseller_invoices WHERE reseller_company_id=? AND reference_month=? LIMIT 1');
      $find->execute([$resellerCompanyId, $referenceMonth]);
      $existingInvoice = $find->fetch(PDO::FETCH_ASSOC) ?: null;
      if ($existingInvoice && (string)$existingInvoice['status'] !== 'draft') {
        throw new RuntimeException('Esta competência já foi emitida, paga ou cancelada e não pode ser recalculada.');
      }
      if ($existingInvoice) {
        $invoiceId = (int)$existingInvoice['id'];
        $pdo->prepare('UPDATE saas_reseller_invoices SET due_date=?,updated_at=NOW() WHERE id=?')->execute([$dueDate ?: null, $invoiceId]);
      } else {
        $st = $pdo->prepare("INSERT INTO saas_reseller_invoices (reseller_company_id,reference_month,due_date,status,amount) VALUES (?,?,?,'draft',0)");
        $st->execute([$resellerCompanyId, $referenceMonth, $dueDate ?: null]);
        $invoiceId = (int)$pdo->lastInsertId();
      }
      $pdo->prepare('DELETE FROM saas_reseller_invoice_items WHERE invoice_id=?')->execute([$invoiceId]);
      $moduleByCode = [];
      foreach (saas_all_modules($pdo, true) as $m) $moduleByCode[(string)$m['code']] = $m;
      $planById = [];
      foreach (reseller_plan_catalog($pdo, 0, true) as $planRow) $planById[(int)$planRow['id']] = $planRow;
      $deliveryModule = $moduleByCode['delivery'] ?? null;
      $ins = $pdo->prepare("INSERT INTO saas_reseller_invoice_items (invoice_id,client_company_id,module_id,item_type,description,quantity,unit_amount,total_amount) VALUES (?,?,?,?,?,?,?,?)");
      $total = 0.0;
      foreach ($clients as $client) {
        $clientId = (int)$client['id'];
        $clientCodes = saas_company_module_codes($pdo, $clientId);
        $clientQuantities = saas_company_module_quantities($pdo, $clientId);
        $commercialPlanId = (int)($client['commercial_plan_id'] ?? $client['desired_plan_id'] ?? 0);
        $deliveryPlan = $planById[$commercialPlanId] ?? null;

        if ($deliveryPlan && $deliveryModule && in_array('delivery', $clientCodes, true)) {
          $cost = reseller_money_value($deliveryPlan['monthly_cost'] ?? 0);
          if ($cost > 0) {
            $description = 'Mensalidade ' . (string)($deliveryPlan['name'] ?? 'Delivery') . ' - ' . (string)$client['name'];
            $ins->execute([$invoiceId, $clientId, (int)$deliveryModule['id'], 'monthly', $description, 1, $cost, $cost]);
            $total += $cost;
          }
        }

        foreach ($clientCodes as $code) {
          if ($code === 'delivery') continue;
          $m = $moduleByCode[$code] ?? null;
          if (!$m) continue;
          $quantity = !empty($m['allow_quantity']) ? max(1.0, (float)($clientQuantities[$code] ?? 1)) : 1.0;
          $cost = reseller_money_value($m['monthly_cost'] ?? 0);
          if ($cost > 0) {
            $itemTotal = round($cost * $quantity, 2);
            $quantitySuffix = $quantity > 1 ? ' (' . rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.') . 'x)' : '';
            $description = 'Mensalidade ' . (string)$m['name'] . $quantitySuffix . ' - ' . (string)$client['name'];
            $ins->execute([$invoiceId, $clientId, (int)$m['id'], 'monthly', $description, $quantity, $cost, $itemTotal]);
            $total += $itemTotal;
          }
        }
      }
      $pdo->prepare('UPDATE saas_reseller_invoices SET amount=?,updated_at=NOW() WHERE id=?')->execute([$total, $invoiceId]);
      $pdo->commit();
      return $invoiceId;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

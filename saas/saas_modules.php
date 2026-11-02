<?php
declare(strict_types=1);

/**
 * Controle de módulos comercializáveis por plano e por empresa.
 *
 * Regras:
 * 1) O plano libera módulos padrão em saas_plan_modules.
 * 2) A empresa pode personalizar em saas_company_modules.
 * 3) A personalização da empresa tem prioridade sobre o plano.
 * 4) Se a estrutura ainda não existir, o sistema mantém Delivery como fallback.
 */

if (!function_exists('saas_sql_identifier_ok')) {
  function saas_sql_identifier_ok(string $name): bool {
    return (bool)preg_match('/^[a-zA-Z0-9_]+$/', $name);
  }
}

if (!function_exists('saas_module_table_exists')) {
  function saas_module_table_exists(PDO $pdo, string $table): bool {
    if (!saas_sql_identifier_ok($table)) return false;

    try {
      $st = $pdo->prepare('SHOW TABLES LIKE ?');
      $st->execute([$table]);
      if ($st->fetchColumn() !== false) return true;
    } catch (Throwable $e) {}

    try {
      $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
      return true;
    } catch (Throwable $e) {}

    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
      $st->execute([$table]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('saas_module_column_exists')) {
  function saas_module_column_exists(PDO $pdo, string $table, string $column): bool {
    if (!saas_sql_identifier_ok($table) || !saas_sql_identifier_ok($column)) return false;

    try {
      $st = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?');
      $st->execute([$column]);
      if ($st->fetch(PDO::FETCH_ASSOC)) return true;
    } catch (Throwable $e) {}

    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
      $st->execute([$table, $column]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('saas_add_column_if_missing')) {
  function saas_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition, array &$errors = []): void {
    if (!saas_sql_identifier_ok($table) || !saas_sql_identifier_ok($column)) return;
    if (saas_module_column_exists($pdo, $table, $column)) return;

    try {
      $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
    } catch (Throwable $e) {
      $errors[] = 'Não foi possível criar a coluna ' . $table . '.' . $column . ': ' . $e->getMessage();
    }
  }
}

if (!function_exists('saas_try_exec')) {
  function saas_try_exec(PDO $pdo, string $sql, array &$errors = []): void {
    try {
      $pdo->exec($sql);
    } catch (Throwable $e) {
      $errors[] = $e->getMessage();
    }
  }
}

if (!function_exists('saas_modules_available')) {
  function saas_modules_available(PDO $pdo): bool {
    return saas_module_table_exists($pdo, 'saas_modules')
      && saas_module_table_exists($pdo, 'saas_plan_modules')
      && saas_module_table_exists($pdo, 'saas_company_modules');
  }
}

if (!function_exists('saas_ensure_modules_schema')) {
  function saas_ensure_modules_schema(PDO $pdo): array {
    $errors = [];

    saas_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS saas_modules (
        id INT NOT NULL AUTO_INCREMENT,
        code VARCHAR(50) NOT NULL,
        name VARCHAR(100) NOT NULL,
        description TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_saas_modules_code (code)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    saas_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS saas_plan_modules (
        plan_id INT NOT NULL,
        module_id INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (plan_id, module_id),
        KEY idx_saas_plan_modules_module (module_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    saas_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS saas_company_modules (
        company_id INT NOT NULL,
        module_id INT NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        expires_at DATETIME NULL,
        note VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (company_id, module_id),
        KEY idx_saas_company_modules_module (module_id),
        KEY idx_saas_company_modules_company (company_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    if (saas_module_table_exists($pdo, 'saas_modules')) {
      saas_add_column_if_missing($pdo, 'saas_modules', 'code', 'VARCHAR(50) NOT NULL', $errors);
      saas_add_column_if_missing($pdo, 'saas_modules', 'name', 'VARCHAR(100) NOT NULL', $errors);
      saas_add_column_if_missing($pdo, 'saas_modules', 'description', 'TEXT NULL', $errors);
      saas_add_column_if_missing($pdo, 'saas_modules', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1', $errors);
      saas_add_column_if_missing($pdo, 'saas_modules', 'sort_order', 'INT NOT NULL DEFAULT 0', $errors);
      saas_add_column_if_missing($pdo, 'saas_modules', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', $errors);
      saas_try_exec($pdo, 'ALTER TABLE saas_modules ADD UNIQUE KEY uk_saas_modules_code (code)', $errors);
    }

    if (saas_module_table_exists($pdo, 'saas_plan_modules')) {
      saas_add_column_if_missing($pdo, 'saas_plan_modules', 'plan_id', 'INT NOT NULL', $errors);
      saas_add_column_if_missing($pdo, 'saas_plan_modules', 'module_id', 'INT NOT NULL', $errors);
      saas_add_column_if_missing($pdo, 'saas_plan_modules', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', $errors);
    }

    if (saas_module_table_exists($pdo, 'saas_company_modules')) {
      saas_add_column_if_missing($pdo, 'saas_company_modules', 'company_id', 'INT NOT NULL', $errors);
      saas_add_column_if_missing($pdo, 'saas_company_modules', 'module_id', 'INT NOT NULL', $errors);
      saas_add_column_if_missing($pdo, 'saas_company_modules', 'enabled', 'TINYINT(1) NOT NULL DEFAULT 1', $errors);
      saas_add_column_if_missing($pdo, 'saas_company_modules', 'expires_at', 'DATETIME NULL', $errors);
      saas_add_column_if_missing($pdo, 'saas_company_modules', 'note', 'VARCHAR(255) NULL', $errors);
      saas_add_column_if_missing($pdo, 'saas_company_modules', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', $errors);
      saas_add_column_if_missing($pdo, 'saas_company_modules', 'updated_at', 'DATETIME NULL', $errors);
    }

    if (saas_module_table_exists($pdo, 'saas_modules')) {
      try { saas_seed_default_modules($pdo); } catch (Throwable $e) { $errors[] = $e->getMessage(); }
    }

    if (saas_modules_available($pdo) && saas_module_table_exists($pdo, 'plans')) {
      try {
        $deliveryId = saas_module_id_by_code($pdo, 'delivery');
        if ($deliveryId > 0) {
          $count = (int)$pdo->query('SELECT COUNT(*) FROM saas_plan_modules')->fetchColumn();
          if ($count === 0) {
            $st = $pdo->query('SELECT id FROM plans WHERE is_active = 1');
            $ins = $pdo->prepare('INSERT IGNORE INTO saas_plan_modules (plan_id, module_id) VALUES (?, ?)');
            foreach (($st ? $st->fetchAll(PDO::FETCH_COLUMN) : []) as $pid) {
              $ins->execute([(int)$pid, $deliveryId]);
            }
          }
        }
      } catch (Throwable $e) {
        $errors[] = $e->getMessage();
      }
    }

    // Erros de índice duplicado são esperados quando a estrutura já está ok.
    $errors = array_values(array_filter($errors, function ($msg) {
      return stripos((string)$msg, 'Duplicate key name') === false
        && stripos((string)$msg, 'Multiple primary key') === false
        && stripos((string)$msg, 'Duplicate column name') === false;
    }));

    return [
      'ok' => saas_modules_available($pdo),
      'errors' => $errors,
    ];
  }
}

if (!function_exists('saas_seed_default_modules')) {
  function saas_seed_default_modules(PDO $pdo): void {
    if (!saas_module_table_exists($pdo, 'saas_modules')) return;

    $defaults = [
      ['delivery', 'Delivery', 'Catálogo, carrinho, pedidos online, produtos e WhatsApp.', 10],
      ['exportacao', 'Exportação / Firebird', 'Fila de exportação, execução manual e integração Firebird/Próspero.', 15],
      ['api', 'API externa', 'Token e endpoints externos para integração com produtos, pedidos e WhatsApp.', 18],
      ['financeiro', 'Financeiro', 'Contas a receber, contas a pagar, caixa, bancos, plano de contas e centro de custo.', 20],
      ['gestao', 'Gestão completa', 'Clientes, vendedores, pedido manual, dashboards, DRE e relatórios gerenciais.', 30],
      ['composicao_produtos', 'Composição de Produtos', 'Ficha técnica, matérias-primas, consumo automático e estoque teórico proporcional.', 33],
      ['ordem_producao', 'Ordem de Produção', 'Ficha técnica de produção, consumo de matérias-primas, variações/perdas e entrada de produtos acabados.', 34],
      ['fiscal', 'Gestão fiscal', 'NF-e, NFC-e, entrada por XML, XMLs, Sintegra, SPED e painel do contador.', 40],
    ];

    $sql = "
      INSERT INTO saas_modules (code, name, description, sort_order, is_active)
      VALUES (?, ?, ?, ?, 1)
      ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        description = VALUES(description),
        sort_order = VALUES(sort_order),
        is_active = 1
    ";
    $st = $pdo->prepare($sql);
    foreach ($defaults as $m) {
      $st->execute($m);
    }
  }
}

if (!function_exists('saas_all_modules')) {
  function saas_all_modules(PDO $pdo, bool $activeOnly = true): array {
    if (!saas_module_table_exists($pdo, 'saas_modules')) {
      try { saas_ensure_modules_schema($pdo); } catch (Throwable $e) {}
    }

    if (!saas_module_table_exists($pdo, 'saas_modules')) {
      return [[
        'id' => 0,
        'code' => 'delivery',
        'name' => 'Delivery',
        'description' => 'Catálogo, carrinho, pedidos online e WhatsApp.',
        'is_active' => 1,
        'sort_order' => 10,
      ]];
    }

    try { saas_seed_default_modules($pdo); } catch (Throwable $e) {}

    $where = $activeOnly ? 'WHERE is_active = 1' : '';
    $st = $pdo->query("SELECT * FROM saas_modules {$where} ORDER BY sort_order ASC, name ASC, id ASC");
    return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
  }
}

if (!function_exists('saas_module_id_by_code')) {
  function saas_module_id_by_code(PDO $pdo, string $code): int {
    if (!saas_module_table_exists($pdo, 'saas_modules')) return 0;
    $st = $pdo->prepare('SELECT id FROM saas_modules WHERE code = ? LIMIT 1');
    $st->execute([$code]);
    return (int)($st->fetchColumn() ?: 0);
  }
}

if (!function_exists('saas_latest_subscription_module_plan_id')) {
  function saas_latest_subscription_module_plan_id(PDO $pdo, int $companyId): int {
    try {
      $st = $pdo->prepare('SELECT plan_id FROM subscriptions WHERE company_id = ? ORDER BY id DESC LIMIT 1');
      $st->execute([$companyId]);
      return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
      return 0;
    }
  }
}

if (!function_exists('saas_any_plan_modules_configured')) {
  function saas_any_plan_modules_configured(PDO $pdo): bool {
    if (!saas_module_table_exists($pdo, 'saas_plan_modules')) return false;
    try {
      return (int)$pdo->query('SELECT COUNT(*) FROM saas_plan_modules')->fetchColumn() > 0;
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('saas_plan_module_ids')) {
  function saas_plan_module_ids(PDO $pdo, int $planId): array {
    if ($planId <= 0 || !saas_module_table_exists($pdo, 'saas_plan_modules')) return [];
    try {
      $st = $pdo->prepare('SELECT module_id FROM saas_plan_modules WHERE plan_id = ?');
      $st->execute([$planId]);
      return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('saas_plan_module_codes')) {
  function saas_plan_module_codes(PDO $pdo, int $planId): array {
    if ($planId <= 0 || !saas_modules_available($pdo)) return [];
    try {
      $st = $pdo->prepare('
        SELECT m.code
        FROM saas_plan_modules pm
        JOIN saas_modules m ON m.id = pm.module_id
        WHERE pm.plan_id = ? AND m.is_active = 1
        ORDER BY m.sort_order ASC, m.name ASC
      ');
      $st->execute([$planId]);
      return array_values(array_filter(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN))));
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('saas_company_module_overrides')) {
  function saas_company_module_overrides(PDO $pdo, int $companyId): array {
    if ($companyId <= 0 || !saas_module_table_exists($pdo, 'saas_company_modules')) return [];
    try {
      $st = $pdo->prepare('
        SELECT module_id, enabled, expires_at, note
        FROM saas_company_modules
        WHERE company_id = ?
      ');
      $st->execute([$companyId]);
      $out = [];
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $expires = trim((string)($r['expires_at'] ?? ''));
        $expired = $expires !== '' && strtotime($expires) !== false && strtotime($expires) < time();
        if ($expired) continue;
        $out[(int)$r['module_id']] = $r;
      }
      return $out;
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('saas_company_module_state')) {
  function saas_company_module_state(PDO $pdo, int $companyId): array {
    $modules = saas_all_modules($pdo, true);
    if (!saas_modules_available($pdo)) {
      return [[
        'id' => 0,
        'code' => 'delivery',
        'name' => 'Delivery',
        'description' => 'Catálogo, carrinho, pedidos online e WhatsApp.',
        'from_plan' => true,
        'override' => null,
        'enabled' => true,
        'expires_at' => null,
        'note' => null,
      ]];
    }

    $planId = saas_latest_subscription_module_plan_id($pdo, $companyId);
    $planIds = array_fill_keys(saas_plan_module_ids($pdo, $planId), true);

    if (!$planIds && !saas_any_plan_modules_configured($pdo)) {
      $deliveryId = saas_module_id_by_code($pdo, 'delivery');
      if ($deliveryId > 0) $planIds[$deliveryId] = true;
    }

    $overrides = saas_company_module_overrides($pdo, $companyId);
    $state = [];
    foreach ($modules as $m) {
      $mid = (int)$m['id'];
      $fromPlan = isset($planIds[$mid]);
      $override = array_key_exists($mid, $overrides) ? (int)$overrides[$mid]['enabled'] : null;
      $enabled = $override === null ? $fromPlan : ($override === 1);
      $state[] = [
        'id' => $mid,
        'code' => (string)$m['code'],
        'name' => (string)$m['name'],
        'description' => (string)($m['description'] ?? ''),
        'from_plan' => $fromPlan,
        'override' => $override,
        'enabled' => $enabled,
        'expires_at' => $overrides[$mid]['expires_at'] ?? null,
        'note' => $overrides[$mid]['note'] ?? null,
      ];
    }
    return $state;
  }
}

if (!function_exists('saas_company_module_codes')) {
  function saas_company_module_codes(PDO $pdo, int $companyId): array {
    $codes = [];
    foreach (saas_company_module_state($pdo, $companyId) as $m) {
      if (!empty($m['enabled'])) $codes[] = (string)$m['code'];
    }
    return array_values(array_unique($codes));
  }
}

if (!function_exists('saas_company_has_module')) {
  function saas_company_has_module(PDO $pdo, int $companyId, string $code): bool {
    return in_array($code, saas_company_module_codes($pdo, $companyId), true);
  }
}

if (!function_exists('saas_company_has_any_module')) {
  function saas_company_has_any_module(PDO $pdo, int $companyId): bool {
    return count(saas_company_module_codes($pdo, $companyId)) > 0;
  }
}

if (!function_exists('saas_save_plan_modules')) {
  function saas_save_plan_modules(PDO $pdo, int $planId, array $moduleIds): void {
    if ($planId <= 0 || !saas_modules_available($pdo)) return;
    $moduleIds = array_values(array_unique(array_filter(array_map('intval', $moduleIds), fn($v) => $v > 0)));
    $pdo->prepare('DELETE FROM saas_plan_modules WHERE plan_id = ?')->execute([$planId]);
    if (!$moduleIds) return;
    $st = $pdo->prepare('INSERT IGNORE INTO saas_plan_modules (plan_id, module_id) VALUES (?, ?)');
    foreach ($moduleIds as $moduleId) {
      $st->execute([$planId, $moduleId]);
    }
  }
}

if (!function_exists('saas_save_company_modules_custom')) {
  function saas_save_company_modules_custom(PDO $pdo, int $companyId, array $selectedModuleIds, ?string $expiresAt = null, string $note = ''): void {
    if ($companyId <= 0 || !saas_modules_available($pdo)) return;
    $selected = array_fill_keys(array_values(array_unique(array_filter(array_map('intval', $selectedModuleIds), fn($v) => $v > 0))), true);
    $expiresSql = null;
    if ($expiresAt && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt)) {
      $expiresSql = $expiresAt . ' 23:59:59';
    }

    $modules = saas_all_modules($pdo, true);
    $st = $pdo->prepare('
      INSERT INTO saas_company_modules (company_id, module_id, enabled, expires_at, note, updated_at)
      VALUES (?, ?, ?, ?, ?, NOW())
      ON DUPLICATE KEY UPDATE
        enabled = VALUES(enabled),
        expires_at = VALUES(expires_at),
        note = VALUES(note),
        updated_at = NOW()
    ');
    foreach ($modules as $m) {
      $mid = (int)$m['id'];
      $st->execute([$companyId, $mid, isset($selected[$mid]) ? 1 : 0, $expiresSql, $note !== '' ? $note : null]);
    }
  }
}

if (!function_exists('saas_reset_company_modules')) {
  function saas_reset_company_modules(PDO $pdo, int $companyId): void {
    if ($companyId <= 0 || !saas_module_table_exists($pdo, 'saas_company_modules')) return;
    $pdo->prepare('DELETE FROM saas_company_modules WHERE company_id = ?')->execute([$companyId]);
  }
}

if (!function_exists('saas_module_label')) {
  function saas_module_label(string $code): string {
    return match ($code) {
      'delivery' => 'Delivery',
      'exportacao' => 'Exportação / Firebird',
      'api' => 'API externa',
      'financeiro' => 'Financeiro',
      'gestao' => 'Gestão',
      'fiscal' => 'Fiscal',
      default => ucfirst(str_replace(['_', '-'], ' ', $code)),
    };
  }
}

if (!function_exists('saas_render_module_block_page')) {
  function saas_render_module_block_page(string $moduleCode, string $message = ''): void {
    http_response_code(403);
    $moduleName = saas_module_label($moduleCode);
    $message = $message !== '' ? $message : 'Este recurso não está liberado para o plano ou para os módulos contratados desta empresa.';
    ?>
    <!doctype html>
    <html lang="pt-br">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Módulo indisponível</title>
      <style>
        body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f5f7fb;color:#0f172a;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
        .box{width:min(92vw,640px);background:#fff;border:1px solid #e8eef5;border-radius:22px;padding:28px;box-shadow:0 12px 32px rgba(2,6,23,.08)}
        .tag{display:inline-flex;padding:8px 12px;border-radius:999px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-weight:800;margin-bottom:14px}
        h1{margin:0 0 12px;font-size:30px;color:#0b1f5b}p{margin:0 0 10px;color:#475569;line-height:1.6}.btn{display:inline-flex;margin-top:16px;padding:12px 18px;border-radius:999px;background:#0b1f5b;color:#fff;text-decoration:none;font-weight:800}
      </style>
    </head>
    <body>
      <div class="box">
        <div class="tag">Módulo <?= htmlspecialchars($moduleName, ENT_QUOTES, 'UTF-8') ?></div>
        <h1>Recurso não liberado</h1>
        <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <p>Entre em contato com a equipe Nexa Próspero para liberar este módulo.</p>
        <a class="btn" href="dashboard.php">Voltar ao painel</a>
      </div>
    </body>
    </html>
    <?php
    exit;
  }
}

if (!function_exists('saas_require_company_module')) {
  function saas_require_company_module(PDO $pdo, int $companyId, string $moduleCode, string $contextLabel = ''): void {
    if (!saas_company_has_module($pdo, $companyId, $moduleCode)) {
      $ctx = $contextLabel !== '' ? $contextLabel : saas_module_label($moduleCode);
      saas_render_module_block_page($moduleCode, 'O acesso a ' . $ctx . ' depende da liberação do módulo ' . saas_module_label($moduleCode) . '.');
    }
  }
}

if (!function_exists('saas_admin_page_required_modules')) {
  function saas_admin_page_required_modules(string $page): array {
    $page = strtolower(basename($page));
    return match ($page) {
      'dashboard.php' => ['*'],
      'pedidos.php', 'pedido.php', 'pedido_editar.php', 'pedido_status.php', 'pedido_print.php',
      'produtos.php', 'produto_form.php', 'whatsapp.php', 'whatsapp_api.php' => ['delivery'],
      'exportacao.php', 'firebird.php', 'firebird_run.php' => ['exportacao'],
      'api.php' => ['api'],
      default => [],
    };
  }
}

if (!function_exists('saas_require_admin_page_module')) {
  function saas_require_admin_page_module(PDO $pdo, int $companyId, string $page): void {
    $required = saas_admin_page_required_modules($page);
    if (!$required) return;

    if (in_array('*', $required, true)) {
      if (!saas_company_has_any_module($pdo, $companyId)) {
        saas_render_module_block_page('delivery', 'Nenhum módulo está liberado para esta empresa.');
      }
      return;
    }

    foreach ($required as $code) {
      if (saas_company_has_module($pdo, $companyId, $code)) return;
    }

    saas_render_module_block_page((string)$required[0]);
  }
}

if (!function_exists('saas_admin_nav_allowed')) {
  function saas_admin_nav_allowed(PDO $pdo, int $companyId, string $key): bool {
    return match ($key) {
      'dashboard' => saas_company_has_any_module($pdo, $companyId),
      'pedidos', 'produtos', 'whatsapp' => saas_company_has_module($pdo, $companyId, 'delivery'),
      'export' => saas_company_has_module($pdo, $companyId, 'exportacao'),
      'api' => saas_company_has_module($pdo, $companyId, 'api'),
      'financeiro' => saas_company_has_module($pdo, $companyId, 'financeiro'),
      'gestao' => saas_company_has_module($pdo, $companyId, 'gestao'),
      'fiscal' => saas_company_has_module($pdo, $companyId, 'fiscal'),
      'params' => true,
      default => true,
    };
  }
}

if (!function_exists('saas_admin_default_url')) {
  function saas_admin_default_url(PDO $pdo, int $companyId): string {
    if (saas_company_has_module($pdo, $companyId, 'delivery')) return 'dashboard.php';
    if (saas_company_has_module($pdo, $companyId, 'exportacao')) return 'exportacao.php';
    if (saas_company_has_module($pdo, $companyId, 'api')) return 'api.php';
    if (saas_company_has_module($pdo, $companyId, 'financeiro')) return 'modulo_em_breve.php?modulo=financeiro';
    if (saas_company_has_module($pdo, $companyId, 'gestao')) return 'modulo_em_breve.php?modulo=gestao';
    if (saas_company_has_module($pdo, $companyId, 'fiscal')) return 'modulo_em_breve.php?modulo=fiscal';
    return 'parametros.php';
  }
}

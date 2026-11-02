<?php
declare(strict_types=1);

require_once __DIR__ . '/finance_module.php';
require_once __DIR__ . '/gestao_module.php';
require_once __DIR__ . '/balanca_module.php';
require_once __DIR__ . '/schema_runtime.php';
require_once __DIR__ . '/product_menu_code.php';

if (!function_exists('comanda_column_exists')) {
  function comanda_column_exists(PDO $pdo, string $table, string $column): bool {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) return false;
    if (!isset($GLOBALS['comanda_column_exists_cache']) || !is_array($GLOBALS['comanda_column_exists_cache'])) $GLOBALS['comanda_column_exists_cache'] = [];
    $cache =& $GLOBALS['comanda_column_exists_cache'];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return (bool)$cache[$key];
    try {
      $st = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?');
      $st->execute([$column]);
      if ($st->fetch(PDO::FETCH_ASSOC)) return $cache[$key] = true;
    } catch (Throwable $e) {}
    try {
      $st = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
      $st->execute([$table, $column]);
      $exists = (bool)$st->fetchColumn();
      return $cache[$key] = $exists;
    } catch (Throwable $e) { return $cache[$key] = false; }
  }
}

if (!function_exists('comanda_add_column_if_missing')) {
  function comanda_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) return;
    if (comanda_column_exists($pdo, $table, $column)) return;
    try {
      $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
      if (!isset($GLOBALS['comanda_column_exists_cache']) || !is_array($GLOBALS['comanda_column_exists_cache'])) $GLOBALS['comanda_column_exists_cache'] = [];
      $GLOBALS['comanda_column_exists_cache'][$table . '.' . $column] = true;
    } catch (Throwable $e) {
      // Se outra requisição criou a coluna simultaneamente, atualiza o cache consultando novamente.
      if (!isset($GLOBALS['comanda_column_exists_cache']) || !is_array($GLOBALS['comanda_column_exists_cache'])) $GLOBALS['comanda_column_exists_cache'] = [];
      unset($GLOBALS['comanda_column_exists_cache'][$table . '.' . $column]);
      comanda_column_exists($pdo, $table, $column);
    }
  }
}

if (!function_exists('comanda_index_exists')) {
  function comanda_index_exists(PDO $pdo, string $table, string $index): bool {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $index)) return false;
    try {
      $st = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? LIMIT 1');
      $st->execute([$table, $index]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('comanda_add_index_if_missing')) {
  function comanda_add_index_if_missing(PDO $pdo, string $table, string $index, string $definition): void {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $index)) return;
    if (comanda_index_exists($pdo, $table, $index)) return;
    try { $pdo->exec('ALTER TABLE `' . $table . '` ADD ' . $definition); } catch (Throwable $e) {}
  }
}

if (!function_exists('comanda_ensure_schema')) {
  function comanda_ensure_schema(PDO $pdo): void {
    // V11.29 Multiempresa: no contexto F11 cruzado o schema já deve estar aplicado
    // globalmente pelo Atualizador do Banco. Nunca executar CREATE/ALTER apenas por
    // navegar/operar Comandas de outra empresa.
    if (!empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'])) return;
    static $done = false;
    if ($done) return;
    $done = true;
    $schemaVersion = 2026073101;
    if (nexa_schema_version_ready($pdo, 'comanda_runtime', $schemaVersion)) return;
    try { gestao_ensure_schema($pdo); } catch (Throwable $e) {}
    try { if (function_exists('balanca_ensure_schema')) balanca_ensure_schema($pdo); } catch (Throwable $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS comanda_settings (
      company_id INT NOT NULL,
      show_only_occupied TINYINT(1) NOT NULL DEFAULT 0,
      allow_transfer TINYINT(1) NOT NULL DEFAULT 1,
      allow_multi_transfer TINYINT(1) NOT NULL DEFAULT 1,
      allow_item_transfer TINYINT(1) NOT NULL DEFAULT 1,
      allow_named_comanda TINYINT(1) NOT NULL DEFAULT 1,
      prompt_name_first_launch TINYINT(1) NOT NULL DEFAULT 0,
      allow_customer TINYINT(1) NOT NULL DEFAULT 1,
      auto_print_kitchen TINYINT(1) NOT NULL DEFAULT 0,
      direct_print_kitchen TINYINT(1) NOT NULL DEFAULT 0,
      inactive_alert_minutes INT NOT NULL DEFAULT 10,
      public_qr_enabled TINYINT(1) NOT NULL DEFAULT 1,
      public_qr_require_name TINYINT(1) NOT NULL DEFAULT 1,
      public_qr_require_phone TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS comandas (
      id INT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      codigo VARCHAR(30) NOT NULL,
      matricula VARCHAR(60) NULL,
      nome VARCHAR(120) NULL,
      cliente_id INT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'LIVRE',
      opened_at DATETIME NULL,
      closed_at DATETIME NULL,
      last_order_id BIGINT NULL,
      public_token VARCHAR(64) NULL,
      public_order_enabled TINYINT(1) NOT NULL DEFAULT 1,
      public_session_version INT NOT NULL DEFAULT 1,
      notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_comandas_codigo (company_id, codigo),
      UNIQUE KEY ux_comandas_public_token (public_token),
      KEY idx_comandas_matricula (company_id, matricula),
      KEY idx_comandas_status (company_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS comanda_items (
      id INT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      comanda_id INT NOT NULL,
      produto_id INT NOT NULL,
      nome VARCHAR(180) NOT NULL,
      qtd DECIMAL(12,3) NOT NULL DEFAULT 1.000,
      preco_unit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
      total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
      observacao TEXT NULL,
      grupo_impressao VARCHAR(120) NULL,
      printer_name VARCHAR(180) NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'ABERTO',
      printed_at DATETIME NULL,
      pedido_id BIGINT NULL,
      public_order_id BIGINT NULL,
      source VARCHAR(30) NOT NULL DEFAULT 'operador',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      KEY idx_comanda_items_comanda (company_id, comanda_id, status),
      KEY idx_comanda_items_pedido (company_id, pedido_id),
      KEY idx_comanda_items_public_order (company_id, public_order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS comanda_printer_groups (
      id INT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      name VARCHAR(120) NOT NULL,
      printer_name VARCHAR(180) NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_comanda_printer_group (company_id, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS comanda_public_orders (
      id BIGINT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      comanda_id INT NOT NULL,
      request_token VARCHAR(64) NOT NULL,
      customer_name VARCHAR(120) NOT NULL,
      customer_phone VARCHAR(30) NOT NULL,
      subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
      status VARCHAR(20) NOT NULL DEFAULT 'RECEBIDO',
      remote_ip VARCHAR(64) NULL,
      user_agent VARCHAR(255) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      printed_at DATETIME NULL,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_comanda_public_request (company_id, request_token),
      KEY idx_comanda_public_orders (company_id, comanda_id, created_at),
      KEY idx_comanda_public_status (company_id, status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS comanda_print_jobs (
      id BIGINT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      comanda_id INT NOT NULL,
      public_order_id BIGINT NULL,
      group_name VARCHAR(120) NOT NULL,
      printer_name VARCHAR(180) NULL,
      item_ids_json TEXT NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'PENDENTE',
      attempts INT NOT NULL DEFAULT 0,
      claimed_at DATETIME NULL,
      printed_at DATETIME NULL,
      error_message VARCHAR(255) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_comanda_print_job_group (company_id, public_order_id, group_name),
      KEY idx_comanda_print_queue (company_id, group_name, status, created_at),
      KEY idx_comanda_print_order (company_id, public_order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS comanda_print_agents (
      id BIGINT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      name VARCHAR(120) NOT NULL,
      token_hash CHAR(64) NOT NULL,
      token_hint VARCHAR(20) NULL,
      allowed_groups_json TEXT NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      machine_name VARCHAR(120) NULL,
      agent_version VARCHAR(40) NULL,
      last_ip VARCHAR(64) NULL,
      last_seen_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_comanda_print_agent_token (token_hash),
      KEY idx_comanda_print_agents_company (company_id, is_active, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS comanda_transfers (
      id INT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      from_comanda_id INT NOT NULL,
      to_comanda_id INT NOT NULL,
      item_id INT NULL,
      quantity DECIMAL(12,3) NULL,
      notes VARCHAR(255) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_comanda_transfer_company (company_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
      'show_only_occupied' => 'TINYINT(1) NOT NULL DEFAULT 0',
      'allow_transfer' => 'TINYINT(1) NOT NULL DEFAULT 1',
      'allow_multi_transfer' => 'TINYINT(1) NOT NULL DEFAULT 1',
      'allow_item_transfer' => 'TINYINT(1) NOT NULL DEFAULT 1',
      'allow_named_comanda' => 'TINYINT(1) NOT NULL DEFAULT 1',
      'prompt_name_first_launch' => 'TINYINT(1) NOT NULL DEFAULT 0',
      'allow_customer' => 'TINYINT(1) NOT NULL DEFAULT 1',
      'auto_print_kitchen' => 'TINYINT(1) NOT NULL DEFAULT 0',
      'direct_print_kitchen' => 'TINYINT(1) NOT NULL DEFAULT 0',
      'inactive_alert_minutes' => 'INT NOT NULL DEFAULT 10',
      'public_qr_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
      'public_qr_require_name' => 'TINYINT(1) NOT NULL DEFAULT 1',
      'public_qr_require_phone' => 'TINYINT(1) NOT NULL DEFAULT 1',
      'updated_at' => 'DATETIME NULL',
    ] as $c => $d) comanda_add_column_if_missing($pdo, 'comanda_settings', $c, $d);

    foreach ([
      'matricula' => 'VARCHAR(60) NULL AFTER codigo', 'nome' => 'VARCHAR(120) NULL', 'cliente_id' => 'INT NULL', 'status' => "VARCHAR(20) NOT NULL DEFAULT 'LIVRE'",
      'opened_at' => 'DATETIME NULL', 'closed_at' => 'DATETIME NULL', 'last_order_id' => 'BIGINT NULL', 'public_token' => 'VARCHAR(64) NULL', 'public_order_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1', 'public_session_version' => 'INT NOT NULL DEFAULT 1', 'notes' => 'TEXT NULL', 'updated_at' => 'DATETIME NULL'
    ] as $c => $d) comanda_add_column_if_missing($pdo, 'comandas', $c, $d);

    foreach ([
      'observacao' => 'TEXT NULL', 'grupo_impressao' => 'VARCHAR(120) NULL', 'printer_name' => 'VARCHAR(180) NULL',
      'status' => "VARCHAR(20) NOT NULL DEFAULT 'ABERTO'", 'printed_at' => 'DATETIME NULL', 'pedido_id' => 'BIGINT NULL', 'public_order_id' => 'BIGINT NULL', 'source' => "VARCHAR(30) NOT NULL DEFAULT 'operador'", 'updated_at' => 'DATETIME NULL'
    ] as $c => $d) comanda_add_column_if_missing($pdo, 'comanda_items', $c, $d);

    foreach ([
      'agent_id' => 'BIGINT NULL',
      'next_attempt_at' => 'DATETIME NULL',
      'claimed_by' => 'VARCHAR(120) NULL'
    ] as $c => $d) comanda_add_column_if_missing($pdo, 'comanda_print_jobs', $c, $d);

    comanda_add_index_if_missing($pdo, 'comandas', 'ux_comandas_public_token', 'UNIQUE KEY `ux_comandas_public_token` (`public_token`)');
    comanda_add_index_if_missing($pdo, 'comanda_items', 'idx_comanda_items_public_order', 'KEY `idx_comanda_items_public_order` (`company_id`,`public_order_id`)');
    comanda_add_index_if_missing($pdo, 'comanda_print_jobs', 'ux_comanda_print_job_group', 'UNIQUE KEY `ux_comanda_print_job_group` (`company_id`,`public_order_id`,`group_name`)');
    comanda_add_index_if_missing($pdo, 'comanda_print_jobs', 'idx_comanda_print_agent', 'KEY `idx_comanda_print_agent` (`company_id`,`agent_id`,`status`)');
    comanda_add_index_if_missing($pdo, 'comanda_print_jobs', 'idx_comanda_print_retry', 'KEY `idx_comanda_print_retry` (`company_id`,`status`,`next_attempt_at`,`created_at`)');

    if (finance_table_exists($pdo, 'produtos')) {
      comanda_add_column_if_missing($pdo, 'produtos', 'grupo_impressao', 'VARCHAR(120) NULL');
      comanda_add_column_if_missing($pdo, 'produtos', 'imprimir_cozinha', 'TINYINT(1) NOT NULL DEFAULT 1');
    }

    comanda_seed_defaults($pdo, defined('COMPANY_ID') ? (int)COMPANY_ID : 0);
    nexa_schema_version_mark($pdo, 'comanda_runtime', $schemaVersion, 'Comandas com identificação obrigatória opcional no primeiro lançamento');
  }
}

if (!function_exists('comanda_seed_defaults')) {
  function comanda_seed_defaults(PDO $pdo, int $companyId): void {
    if ($companyId <= 0) return;
    try { $pdo->prepare('INSERT IGNORE INTO comanda_settings (company_id) VALUES (?)')->execute([$companyId]); } catch (Throwable $e) {}
    try {
      $count = (int)(finance_query_one($pdo, 'SELECT COUNT(*) AS qtd FROM comandas WHERE company_id = ?', [$companyId])['qtd'] ?? 0);
      if ($count === 0) {
        $st = $pdo->prepare('INSERT INTO comandas (company_id, codigo, nome, status) VALUES (?, ?, ?, \'LIVRE\')');
        for ($i = 1; $i <= 20; $i++) $st->execute([$companyId, str_pad((string)$i, 2, '0', STR_PAD_LEFT), 'Comanda ' . $i]);
      }
    } catch (Throwable $e) {}
    try {
      $st = $pdo->prepare("INSERT IGNORE INTO comanda_printer_groups (company_id, name, printer_name, sort_order) VALUES (?, ?, ?, ?)");
      $st->execute([$companyId, 'Cozinha', '', 10]);
      $st->execute([$companyId, 'Bebidas', '', 20]);
    } catch (Throwable $e) {}
    try { comanda_ensure_public_tokens($pdo, $companyId); } catch (Throwable $e) {}
  }
}

if (!function_exists('comanda_settings')) {
  function comanda_settings(PDO $pdo, int $companyId): array {
    comanda_ensure_schema($pdo);
    $row = finance_query_one($pdo, 'SELECT * FROM comanda_settings WHERE company_id = ? LIMIT 1', [$companyId]);
    if (!$row) { comanda_seed_defaults($pdo, $companyId); $row = finance_query_one($pdo, 'SELECT * FROM comanda_settings WHERE company_id = ? LIMIT 1', [$companyId]); }
    return $row ?: ['show_only_occupied'=>0,'allow_transfer'=>1,'allow_multi_transfer'=>1,'allow_item_transfer'=>1,'allow_named_comanda'=>1,'prompt_name_first_launch'=>0,'allow_customer'=>1,'auto_print_kitchen'=>0,'direct_print_kitchen'=>0,'inactive_alert_minutes'=>10,'public_qr_enabled'=>1,'public_qr_require_name'=>1,'public_qr_require_phone'=>1];
  }
}

if (!function_exists('comanda_h')) {
  function comanda_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('comanda_money')) {
  function comanda_money(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
}

if (!function_exists('comanda_money_in')) {
  function comanda_money_in($value): float {
    $s = trim((string)$value);
    if ($s === '') return 0.0;
    $s = str_replace(['R$', ' '], '', $s);
    if (str_contains($s, ',')) {
      $s = str_replace('.', '', $s);
      $s = str_replace(',', '.', $s);
    }
    $s = preg_replace('/[^0-9.\-]+/', '', $s) ?: '0';
    return (float)$s;
  }
}

if (!function_exists('comanda_rows')) {
  function comanda_rows(PDO $pdo, int $companyId, bool $onlyOccupied = false): array {
    comanda_ensure_schema($pdo);
    $where = ['c.company_id = ?'];
    $params = [$companyId];
    if ($onlyOccupied) $where[] = "c.status = 'OCUPADA'";
    $sql = "SELECT c.*, fp.name AS cliente_nome,
        COALESCE(ci.total_aberto,0) AS total_aberto,
        COALESCE(ci.itens_abertos,0) AS itens_abertos,
        ci.last_activity_at
      FROM comandas c
      LEFT JOIN finance_people fp ON fp.company_id = c.company_id AND fp.id = c.cliente_id
      LEFT JOIN (
        SELECT company_id, comanda_id, SUM(total) AS total_aberto, COUNT(*) AS itens_abertos, MAX(created_at) AS last_activity_at
        FROM comanda_items
        WHERE company_id = ? AND status = 'ABERTO'
        GROUP BY company_id, comanda_id
      ) ci ON ci.company_id = c.company_id AND ci.comanda_id = c.id
      WHERE " . implode(' AND ', $where) . "
      ORDER BY CAST(c.codigo AS UNSIGNED) ASC, c.codigo ASC, c.id ASC";
    array_unshift($params, $companyId);
    $st = $pdo->prepare($sql); $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('comanda_find')) {
  function comanda_find(PDO $pdo, int $companyId, int $id = 0, string $codigo = ''): ?array {
    comanda_ensure_schema($pdo);
    if ($id > 0) $row = finance_query_one($pdo, 'SELECT * FROM comandas WHERE company_id = ? AND id = ? LIMIT 1', [$companyId, $id]);
    else $row = finance_query_one($pdo, 'SELECT * FROM comandas WHERE company_id = ? AND codigo = ? LIMIT 1', [$companyId, trim($codigo)]);
    return $row ?: null;
  }
}

if (!function_exists('comanda_find_by_code_or_matricula')) {
  function comanda_find_by_code_or_matricula(PDO $pdo, int $companyId, string $value): ?array {
    comanda_ensure_schema($pdo);
    $value = trim($value);
    if ($value === '') return null;
    if (comanda_column_exists($pdo, 'comandas', 'matricula')) {
      $row = finance_query_one($pdo, 'SELECT * FROM comandas WHERE company_id = ? AND (codigo = ? OR matricula = ?) LIMIT 1', [$companyId, $value, $value]);
      if ($row) return $row;
    }
    return comanda_find($pdo, $companyId, 0, $value);
  }
}

if (!function_exists('comanda_get_or_create')) {
  function comanda_get_or_create(PDO $pdo, int $companyId, string $codigo): array {
    comanda_ensure_schema($pdo);
    $codigo = trim($codigo);
    if ($codigo === '') throw new RuntimeException('Informe o número/código da comanda.');
    $row = comanda_find_by_code_or_matricula($pdo, $companyId, $codigo);
    if ($row) return $row;
    $st = $pdo->prepare("INSERT INTO comandas (company_id, codigo, nome, status) VALUES (?, ?, ?, 'LIVRE')");
    $st->execute([$companyId, $codigo, 'Comanda ' . $codigo]);
    return comanda_find($pdo, $companyId, (int)$pdo->lastInsertId()) ?: ['id'=>(int)$pdo->lastInsertId(),'codigo'=>$codigo,'status'=>'LIVRE'];
  }
}

if (!function_exists('comanda_items')) {
  function comanda_items(PDO $pdo, int $companyId, int $comandaId, bool $openOnly = true): array {
    comanda_ensure_schema($pdo);
    $numberSelect = comanda_column_exists($pdo, 'produtos', 'numero_cadastro') ? ', p.numero_cadastro' : ', NULL AS numero_cadastro';
    $legacySelect = comanda_column_exists($pdo, 'produtos', 'codigo_firebird') ? ', p.codigo_firebird' : ', NULL AS codigo_firebird';
    $sql = 'SELECT ci.*, p.categoria, p.grupo_producao, p.grupo_impressao' . $numberSelect . $legacySelect . ' FROM comanda_items ci LEFT JOIN produtos p ON p.company_id = ci.company_id AND p.id = ci.produto_id WHERE ci.company_id = ? AND ci.comanda_id = ?';
    if ($openOnly) $sql .= " AND ci.status = 'ABERTO'";
    $sql .= ' ORDER BY ci.id ASC';
    $st = $pdo->prepare($sql); $st->execute([$companyId, $comandaId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('comanda_totals')) {
  function comanda_totals(array $items): array {
    $qty = 0.0; $total = 0.0;
    foreach ($items as $it) { $qty += (float)($it['qtd'] ?? 0); $total += (float)($it['total'] ?? 0); }
    return ['qty'=>$qty, 'total'=>round($total, 2), 'count'=>count($items)];
  }
}

if (!function_exists('comanda_product_print_group')) {
  function comanda_product_print_group(array $product): string {
    foreach (['grupo_impressao','grupo_producao','categoria'] as $col) {
      $v = trim((string)($product[$col] ?? ''));
      if ($v !== '') return $v;
    }
    return 'Cozinha';
  }
}

if (!function_exists('comanda_printer_groups')) {
  function comanda_printer_groups(PDO $pdo, int $companyId, bool $activeOnly = true): array {
    comanda_ensure_schema($pdo);
    $sql = 'SELECT * FROM comanda_printer_groups WHERE company_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order ASC, name ASC';
    $st = $pdo->prepare($sql); $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('comanda_printer_for_group')) {
  function comanda_printer_for_group(PDO $pdo, int $companyId, string $group): string {
    $row = finance_query_one($pdo, 'SELECT printer_name FROM comanda_printer_groups WHERE company_id = ? AND name = ? AND is_active = 1 LIMIT 1', [$companyId, $group]);
    return trim((string)($row['printer_name'] ?? ''));
  }
}

if (!function_exists('comanda_products')) {
  function comanda_products(PDO $pdo, int $companyId): array {
    comanda_ensure_schema($pdo);
    product_menu_code_ensure_schema($pdo, empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']));
    $extra = comanda_column_exists($pdo, 'produtos', 'grupo_impressao') ? ', grupo_impressao' : ", NULL AS grupo_impressao";
    $scaleExtra = comanda_column_exists($pdo, 'produtos', 'produto_pesavel') ? ', COALESCE(produto_pesavel,0) AS produto_pesavel' : ', 0 AS produto_pesavel';
    $kitchenExtra = comanda_column_exists($pdo, 'produtos', 'imprimir_cozinha') ? ', COALESCE(imprimir_cozinha,1) AS imprimir_cozinha' : ', 1 AS imprimir_cozinha';
    $barcodeExtra = '';
    foreach (['codigo_barras','ean','gtin','barcode'] as $bcCol) {
      if (comanda_column_exists($pdo, 'produtos', $bcCol)) $barcodeExtra .= ', `' . str_replace('`', '``', $bcCol) . '`';
    }
    $numberExtra = comanda_column_exists($pdo, 'produtos', 'numero_cadastro') ? ', numero_cadastro' : ', NULL AS numero_cadastro';
    $codeExtra = comanda_column_exists($pdo, 'produtos', 'codigo_firebird') ? ', codigo_firebird' : ', NULL AS codigo_firebird';
    $menuCodeExtra = product_menu_code_column_exists($pdo) ? ', codigo_cardapio' : ', NULL AS codigo_cardapio';
    $referenceExtra = comanda_column_exists($pdo, 'produtos', 'referencia') ? ', referencia' : ', NULL AS referencia';
    $descriptionExtra = comanda_column_exists($pdo, 'produtos', 'descricao') ? ', descricao' : ", '' AS descricao";
    $sort = comanda_column_exists($pdo, 'produtos', 'sort_order') ? 'CASE WHEN COALESCE(sort_order,0)<=0 THEN 999999 ELSE sort_order END ASC,' : '';
    $st = $pdo->prepare("SELECT id, nome, categoria, preco, imagem, grupo_producao{$extra}{$scaleExtra}{$kitchenExtra}{$barcodeExtra}{$numberExtra}{$codeExtra}{$menuCodeExtra}{$referenceExtra}{$descriptionExtra} FROM produtos WHERE company_id = ? AND COALESCE(ativo,1) = 1 ORDER BY categoria ASC, {$sort} nome ASC LIMIT 1500");
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('comanda_add_item')) {
  function comanda_add_item(PDO $pdo, int $companyId, int $comandaId, int $productId, float $qty = 1.0, string $obs = '', ?float $priceOverride = null): int {
    if ($qty <= 0) throw new RuntimeException('Quantidade inválida.');
    $cmd = comanda_find($pdo, $companyId, $comandaId);
    if (!$cmd) throw new RuntimeException('Comanda não encontrada.');
    $extra = comanda_column_exists($pdo, 'produtos', 'grupo_impressao') ? ', grupo_impressao' : ", NULL AS grupo_impressao";
    $kitchenExtra = comanda_column_exists($pdo, 'produtos', 'imprimir_cozinha') ? ', COALESCE(imprimir_cozinha,1) AS imprimir_cozinha' : ', 1 AS imprimir_cozinha';
    $prod = finance_query_one($pdo, "SELECT id, nome, preco, categoria, grupo_producao{$extra}{$kitchenExtra} FROM produtos WHERE company_id = ? AND id = ? LIMIT 1", [$companyId, $productId]);
    if (!$prod) throw new RuntimeException('Produto não encontrado.');
    $group = comanda_product_print_group($prod);
    $printer = comanda_printer_for_group($pdo, $companyId, $group);
    $price = $priceOverride !== null && $priceOverride >= 0 ? (float)$priceOverride : (float)($prod['preco'] ?? 0);
    $total = round($qty * $price, 2);
    $pdo->prepare("INSERT INTO comanda_items (company_id, comanda_id, produto_id, nome, qtd, preco_unit, total, observacao, grupo_impressao, printer_name, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ABERTO')")
      ->execute([$companyId, $comandaId, $productId, (string)$prod['nome'], $qty, $price, $total, trim($obs) ?: null, $group, $printer ?: null]);
    $itemId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE comandas SET status='OCUPADA', opened_at=COALESCE(opened_at, NOW()), updated_at=NOW() WHERE company_id = ? AND id = ?")->execute([$companyId, $comandaId]);
    return $itemId;
  }
}

if (!function_exists('comanda_add_items_batch')) {
  /**
   * Lança vários itens com uma única leitura da comanda, produtos e grupos de impressão.
   * Retorna IDs gerais e IDs que devem seguir para a cozinha.
   */
  function comanda_add_items_batch(PDO $pdo, int $companyId, int $comandaId, array $rows): array {
    $cmd = comanda_find($pdo, $companyId, $comandaId);
    if (!$cmd) throw new RuntimeException('Comanda não encontrada.');

    $normalized = [];
    $productIds = [];
    foreach ($rows as $row) {
      $productId = (int)($row['product_id'] ?? 0);
      $qty = (float)($row['qty'] ?? 0);
      if ($productId <= 0 || $qty <= 0) continue;
      $normalized[] = [
        'product_id' => $productId,
        'qty' => $qty,
        'obs' => trim((string)($row['obs'] ?? '')),
        'price_override' => array_key_exists('price_override', $row) && $row['price_override'] !== null ? max(0.0, (float)$row['price_override']) : null,
      ];
      $productIds[] = $productId;
    }
    if (!$normalized) return ['added'=>0,'item_ids'=>[],'kitchen_item_ids'=>[]];

    $productIds = array_values(array_unique($productIds));
    $ph = implode(',', array_fill(0, count($productIds), '?'));
    $extra = comanda_column_exists($pdo, 'produtos', 'grupo_impressao') ? ', grupo_impressao' : ", NULL AS grupo_impressao";
    $kitchenExtra = comanda_column_exists($pdo, 'produtos', 'imprimir_cozinha') ? ', COALESCE(imprimir_cozinha,1) AS imprimir_cozinha' : ', 1 AS imprimir_cozinha';
    $stProducts = $pdo->prepare("SELECT id, nome, preco, categoria, grupo_producao{$extra}{$kitchenExtra} FROM produtos WHERE company_id=? AND id IN ($ph)");
    $stProducts->execute(array_merge([$companyId], $productIds));
    $products = [];
    foreach (($stProducts->fetchAll(PDO::FETCH_ASSOC) ?: []) as $product) $products[(int)$product['id']] = $product;

    $printers = [];
    $stPrinters = $pdo->prepare('SELECT name, printer_name FROM comanda_printer_groups WHERE company_id=? AND is_active=1');
    $stPrinters->execute([$companyId]);
    foreach (($stPrinters->fetchAll(PDO::FETCH_ASSOC) ?: []) as $printer) $printers[(string)$printer['name']] = trim((string)($printer['printer_name'] ?? ''));

    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
      $insert = $pdo->prepare("INSERT INTO comanda_items (company_id, comanda_id, produto_id, nome, qtd, preco_unit, total, observacao, grupo_impressao, printer_name, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ABERTO')");
      $itemIds = [];
      $kitchenItemIds = [];
      foreach ($normalized as $row) {
        $product = $products[$row['product_id']] ?? null;
        if (!$product) continue;
        $group = comanda_product_print_group($product);
        $price = $row['price_override'] !== null ? (float)$row['price_override'] : (float)($product['preco'] ?? 0);
        $total = round($row['qty'] * $price, 2);
        $insert->execute([$companyId, $comandaId, $row['product_id'], (string)$product['nome'], $row['qty'], $price, $total, $row['obs'] !== '' ? $row['obs'] : null, $group, ($printers[$group] ?? '') ?: null]);
        $itemId = (int)$pdo->lastInsertId();
        $itemIds[] = $itemId;
        if ((int)($product['imprimir_cozinha'] ?? 1) === 1) $kitchenItemIds[] = $itemId;
      }
      if ($itemIds) {
        $pdo->prepare("UPDATE comandas SET status='OCUPADA', opened_at=COALESCE(opened_at,NOW()), updated_at=NOW() WHERE company_id=? AND id=?")->execute([$companyId, $comandaId]);
        if (function_exists('brain_event_enqueue')) brain_event_enqueue($pdo, $companyId, 'comanda.items.added', 'comanda', $comandaId, ['comanda_id'=>$comandaId,'item_ids'=>$itemIds,'items_added'=>count($itemIds)]);
      }
      if ($ownTransaction) $pdo->commit();
      return ['added'=>count($itemIds),'item_ids'=>$itemIds,'kitchen_item_ids'=>$kitchenItemIds];
    } catch (Throwable $e) {
      if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('comanda_product_should_print_kitchen')) {
  function comanda_product_should_print_kitchen(PDO $pdo, int $companyId, int $productId): bool {
    if ($productId <= 0) return false;
    if (!comanda_column_exists($pdo, 'produtos', 'imprimir_cozinha')) return true;
    $row = finance_query_one($pdo, 'SELECT COALESCE(imprimir_cozinha,1) AS imprimir_cozinha FROM produtos WHERE company_id = ? AND id = ? LIMIT 1', [$companyId, $productId]);
    return ((int)($row['imprimir_cozinha'] ?? 1) === 1);
  }
}

if (!function_exists('comanda_cancel_item')) {
  function comanda_cancel_item(PDO $pdo, int $companyId, int $itemId): void {
    $pdo->prepare("UPDATE comanda_items SET status='CANCELADO', updated_at=NOW() WHERE company_id = ? AND id = ?")->execute([$companyId, $itemId]);
  }
}

if (!function_exists('comanda_update_header')) {
  function comanda_update_header(PDO $pdo, int $companyId, int $comandaId, string $nome = '', int $clienteId = 0, string $notes = ''): void {
    $pdo->prepare('UPDATE comandas SET nome = NULLIF(?, \'\'), cliente_id = NULLIF(?, 0), notes = NULLIF(?, \'\'), updated_at=NOW() WHERE company_id = ? AND id = ?')
      ->execute([trim($nome), $clienteId, trim($notes), $companyId, $comandaId]);
  }
}

if (!function_exists('comanda_update_first_launch_name')) {
  /**
   * Atualiza somente a identificação visual da comanda. Não altera cliente
   * vinculado nem observações já existentes.
   */
  function comanda_update_first_launch_name(PDO $pdo, int $companyId, int $comandaId, string $name): void {
    $name = trim((string)(preg_replace('/\s+/u', ' ', $name) ?? $name));
    if ($name === '') throw new RuntimeException('Informe o nome da pessoa/comanda.');
    $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($length > 120) throw new RuntimeException('O nome da pessoa/comanda deve ter no máximo 120 caracteres.');

    $st = $pdo->prepare('UPDATE comandas SET nome=?, updated_at=NOW() WHERE company_id=? AND id=?');
    $st->execute([$name, $companyId, $comandaId]);
    if ($st->rowCount() <= 0 && !comanda_find($pdo, $companyId, $comandaId)) {
      throw new RuntimeException('Comanda não encontrada para identificação.');
    }
  }
}

if (!function_exists('comanda_transfer_all')) {
  function comanda_transfer_all(PDO $pdo, int $companyId, int $fromId, int $toId): int {
    if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) throw new RuntimeException('Comandas de origem/destino inválidas.');
    $from = comanda_find($pdo, $companyId, $fromId);
    $to = comanda_find($pdo, $companyId, $toId);
    if (!$from || !$to) throw new RuntimeException('Comanda de origem ou destino não encontrada.');
    if (strtoupper((string)($to['status'] ?? '')) === 'BLOQUEADA') throw new RuntimeException('A comanda de destino está bloqueada.');
    $sourceOpen = (int)(finance_query_one($pdo, "SELECT COUNT(*) AS qtd FROM comanda_items WHERE company_id=? AND comanda_id=? AND status='ABERTO'", [$companyId, $fromId])['qtd'] ?? 0);
    if ($sourceOpen <= 0) throw new RuntimeException('A comanda de origem não possui itens em aberto.');
    $targetOpen = (int)(finance_query_one($pdo, "SELECT COUNT(*) AS qtd FROM comanda_items WHERE company_id=? AND comanda_id=? AND status='ABERTO'", [$companyId, $toId])['qtd'] ?? 0);

    $pdo->beginTransaction();
    try {
      $stMove = $pdo->prepare('UPDATE comanda_items SET comanda_id = ?, updated_at=NOW() WHERE company_id = ? AND comanda_id = ? AND status = \'ABERTO\'');
      $stMove->execute([$toId, $companyId, $fromId]);
      $count = $stMove->rowCount();
      if ($targetOpen <= 0) {
        $pdo->prepare("UPDATE comandas SET nome=?, cliente_id=?, notes=?, status='OCUPADA', opened_at=COALESCE(opened_at,NOW()), updated_at=NOW() WHERE company_id=? AND id=?")
          ->execute([$from['nome'] ?? null, $from['cliente_id'] ?? null, $from['notes'] ?? null, $companyId, $toId]);
      } else {
        $pdo->prepare("UPDATE comandas SET status='OCUPADA', opened_at=COALESCE(opened_at,NOW()), updated_at=NOW() WHERE company_id=? AND id=?")->execute([$companyId, $toId]);
      }
      comanda_refresh_status($pdo, $companyId, $fromId);
      $pdo->prepare('UPDATE comandas SET nome=NULL, notes=NULL, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$companyId, $fromId]);
      $pdo->prepare('INSERT INTO comanda_transfers (company_id, from_comanda_id, to_comanda_id, notes) VALUES (?, ?, ?, ?)')->execute([$companyId, $fromId, $toId, 'Transferência total']);
      $pdo->commit();
      return $count;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
  }
}

if (!function_exists('comanda_transfer_multiple')) {
  function comanda_transfer_multiple(PDO $pdo, int $companyId, array $fromIds, int $toId): int {
    $fromIds = array_values(array_unique(array_filter(array_map('intval', $fromIds), static fn(int $id): bool => $id > 0 && $id !== $toId)));
    if ($toId <= 0 || !$fromIds) throw new RuntimeException('Selecione ao menos uma comanda de origem e uma comanda de destino diferente.');
    $to = comanda_find($pdo, $companyId, $toId);
    if (!$to) throw new RuntimeException('Comanda de destino não encontrada.');
    if (strtoupper((string)($to['status'] ?? '')) === 'BLOQUEADA') throw new RuntimeException('A comanda de destino está bloqueada.');

    $placeholders = implode(',', array_fill(0, count($fromIds), '?'));
    $params = array_merge([$companyId], $fromIds);
    $rows = finance_query_all($pdo, "SELECT id, codigo FROM comandas WHERE company_id=? AND id IN ($placeholders)", $params);
    if (count($rows) !== count($fromIds)) throw new RuntimeException('Uma ou mais comandas de origem não foram encontradas.');

    $countRow = finance_query_one($pdo, "SELECT COUNT(*) AS qtd FROM comanda_items WHERE company_id=? AND status='ABERTO' AND comanda_id IN ($placeholders)", $params);
    $openCount = (int)($countRow['qtd'] ?? 0);
    if ($openCount <= 0) throw new RuntimeException('As comandas selecionadas não possuem itens em aberto.');

    $pdo->beginTransaction();
    try {
      $moveParams = array_merge([$toId, $companyId], $fromIds);
      $stMove = $pdo->prepare("UPDATE comanda_items SET comanda_id=?, updated_at=NOW() WHERE company_id=? AND status='ABERTO' AND comanda_id IN ($placeholders)");
      $stMove->execute($moveParams);
      $count = $stMove->rowCount();
      $pdo->prepare("UPDATE comandas SET status='OCUPADA', opened_at=COALESCE(opened_at,NOW()), updated_at=NOW() WHERE company_id=? AND id=?")->execute([$companyId, $toId]);
      foreach ($fromIds as $fromId) {
        comanda_refresh_status($pdo, $companyId, $fromId);
        $pdo->prepare('UPDATE comandas SET nome=NULL, notes=NULL, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$companyId, $fromId]);
        $pdo->prepare('INSERT INTO comanda_transfers (company_id, from_comanda_id, to_comanda_id, notes) VALUES (?, ?, ?, ?)')->execute([$companyId, $fromId, $toId, 'Junção de comandas']);
      }
      $pdo->commit();
      return $count;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
  }
}

if (!function_exists('comanda_transfer_item')) {
  function comanda_transfer_item(PDO $pdo, int $companyId, int $itemId, int $toId): void {
    $item = finance_query_one($pdo, 'SELECT * FROM comanda_items WHERE company_id=? AND id=? AND status=\'ABERTO\' LIMIT 1', [$companyId, $itemId]);
    if (!$item) throw new RuntimeException('Item não encontrado para transferência.');
    $to = comanda_find($pdo, $companyId, $toId);
    if (!$to) throw new RuntimeException('Comanda de destino não encontrada.');
    if (strtoupper((string)($to['status'] ?? '')) === 'BLOQUEADA') throw new RuntimeException('A comanda de destino está bloqueada.');
    $fromId = (int)$item['comanda_id'];
    if ($fromId === $toId) throw new RuntimeException('A comanda de destino deve ser diferente da origem.');

    $pdo->beginTransaction();
    try {
      $pdo->prepare('UPDATE comanda_items SET comanda_id=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$toId, $companyId, $itemId]);
      $pdo->prepare("UPDATE comandas SET status='OCUPADA', opened_at=COALESCE(opened_at,NOW()), updated_at=NOW() WHERE company_id=? AND id=?")->execute([$companyId, $toId]);
      comanda_refresh_status($pdo, $companyId, $fromId);
      $pdo->prepare('INSERT INTO comanda_transfers (company_id, from_comanda_id, to_comanda_id, item_id, quantity, notes) VALUES (?, ?, ?, ?, ?, ?)')->execute([$companyId, $fromId, $toId, $itemId, (float)$item['qtd'], 'Transferência de item']);
      $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
  }
}

if (!function_exists('comanda_refresh_status')) {
  function comanda_refresh_status(PDO $pdo, int $companyId, int $comandaId): void {
    $count = (int)(finance_query_one($pdo, "SELECT COUNT(*) AS qtd FROM comanda_items WHERE company_id=? AND comanda_id=? AND status='ABERTO'", [$companyId, $comandaId])['qtd'] ?? 0);
    if ($count <= 0) {
      $settings = comanda_settings($pdo, $companyId);
      $clearName = !empty($settings['prompt_name_first_launch']);
      $sql = $clearName
        ? "UPDATE comandas SET status='LIVRE', nome=NULL, cliente_id=NULL, opened_at=NULL, updated_at=NOW() WHERE company_id=? AND id=?"
        : "UPDATE comandas SET status='LIVRE', cliente_id=NULL, opened_at=NULL, updated_at=NOW() WHERE company_id=? AND id=?";
      $pdo->prepare($sql)->execute([$companyId, $comandaId]);
    }
    else $pdo->prepare("UPDATE comandas SET status='OCUPADA', opened_at=COALESCE(opened_at,NOW()), updated_at=NOW() WHERE company_id=? AND id=?")->execute([$companyId, $comandaId]);
  }
}

if (!function_exists('comanda_finalize_to_order')) {
  function comanda_finalize_to_order(PDO $pdo, int $companyId, int $userId, int $comandaId, array $post): int {
    $cmd = comanda_find($pdo, $companyId, $comandaId);
    if (!$cmd) throw new RuntimeException('Comanda não encontrada.');
    $items = comanda_items($pdo, $companyId, $comandaId, true);
    if (!$items) throw new RuntimeException('A comanda não possui itens em aberto.');
    $payload = $post;
    $payload['customer_id'] = (int)($post['customer_id'] ?? ($cmd['cliente_id'] ?? 0));
    $payload['customer_name'] = trim((string)($post['customer_name'] ?? '')) ?: (trim((string)($cmd['nome'] ?? '')) ?: 'Consumidor final');
    $payload['order_type'] = 'balcao';
    $payload['discount_amount'] = (string)($post['discount_amount'] ?? '0');
    $payload['addition_amount'] = (string)($post['addition_amount'] ?? '0');
    $payload['delivery_fee'] = (string)($post['delivery_fee'] ?? '0');
    $payload['source'] = 'comanda';
    $payload['notes'] = trim((string)($post['notes'] ?? '')) ?: ('Comanda ' . (string)$cmd['codigo']);
    $payload['item_product_id'] = [];
    $payload['item_qty'] = [];
    $payload['item_price'] = [];
    $payload['item_obs'] = [];
    foreach ($items as $it) {
      $payload['item_product_id'][] = (int)$it['produto_id'];
      $payload['item_qty'][] = number_format((float)$it['qtd'], 3, ',', '.');
      $payload['item_price'][] = number_format((float)$it['preco_unit'], 2, ',', '.');
      $payload['item_obs'][] = trim((string)($it['observacao'] ?? ''));
    }
    $orderId = gestao_finalize_pdv_order($pdo, $companyId, $userId, $payload);
    $pdo->prepare("UPDATE comanda_items SET status='FECHADO', pedido_id=?, updated_at=NOW() WHERE company_id=? AND comanda_id=? AND status='ABERTO'")->execute([$orderId, $companyId, $comandaId]);
    $settings = comanda_settings($pdo, $companyId);
    $clearName = !empty($settings['prompt_name_first_launch']);
    $sql = $clearName
      ? "UPDATE comandas SET status='LIVRE', nome=NULL, cliente_id=NULL, opened_at=NULL, closed_at=NOW(), last_order_id=?, public_session_version=COALESCE(public_session_version,1)+1, updated_at=NOW() WHERE company_id=? AND id=?"
      : "UPDATE comandas SET status='LIVRE', cliente_id=NULL, opened_at=NULL, closed_at=NOW(), last_order_id=?, public_session_version=COALESCE(public_session_version,1)+1, updated_at=NOW() WHERE company_id=? AND id=?";
    $pdo->prepare($sql)->execute([$orderId, $companyId, $comandaId]);
    return $orderId;
  }
}

if (!function_exists('comanda_random_public_token')) {
  function comanda_random_public_token(): string {
    try { return bin2hex(random_bytes(24)); } catch (Throwable $e) { return hash('sha256', uniqid((string)mt_rand(), true)); }
  }
}

if (!function_exists('comanda_ensure_public_tokens')) {
  function comanda_ensure_public_tokens(PDO $pdo, int $companyId): void {
    if ($companyId <= 0 || !comanda_column_exists($pdo, 'comandas', 'public_token')) return;
    $st = $pdo->prepare("SELECT id FROM comandas WHERE company_id=? AND (public_token IS NULL OR public_token='') ORDER BY id ASC");
    $st->execute([$companyId]);
    $upd = $pdo->prepare("UPDATE comandas SET public_token=?, updated_at=NOW() WHERE company_id=? AND id=? AND (public_token IS NULL OR public_token='')");
    foreach (($st->fetchAll(PDO::FETCH_COLUMN) ?: []) as $idRaw) {
      $id = (int)$idRaw;
      for ($i=0; $i<5; $i++) {
        try { $upd->execute([comanda_random_public_token(), $companyId, $id]); break; } catch (Throwable $e) { if ($i === 4) throw $e; }
      }
    }
  }
}

if (!function_exists('comanda_regenerate_public_token')) {
  function comanda_regenerate_public_token(PDO $pdo, int $companyId, int $comandaId): string {
    if ($comandaId <= 0) throw new RuntimeException('Comanda inválida.');
    for ($i=0; $i<6; $i++) {
      $token = comanda_random_public_token();
      try {
        $st = $pdo->prepare('UPDATE comandas SET public_token=?, updated_at=NOW() WHERE company_id=? AND id=?');
        $st->execute([$token, $companyId, $comandaId]);
        if ($st->rowCount() > 0) return $token;
        throw new RuntimeException('Comanda não encontrada.');
      } catch (Throwable $e) { if ($i === 5) throw $e; }
    }
    throw new RuntimeException('Não foi possível gerar o QR Code.');
  }
}

if (!function_exists('comanda_find_by_public_token')) {
  function comanda_find_by_public_token(PDO $pdo, int $companyId, string $token): ?array {
    comanda_ensure_schema($pdo);
    $token = strtolower(trim($token));
    if ($token === '' || !preg_match('/^[a-f0-9]{32,64}$/', $token)) return null;
    $row = finance_query_one($pdo, 'SELECT * FROM comandas WHERE company_id=? AND public_token=? AND COALESCE(public_order_enabled,1)=1 LIMIT 1', [$companyId, $token]);
    return $row ?: null;
  }
}

if (!function_exists('comanda_public_order_rows')) {
  function comanda_public_order_rows(PDO $pdo, int $companyId, int $limit = 100): array {
    comanda_ensure_schema($pdo);
    $limit = max(1, min(500, $limit));
    $sql = "SELECT po.*, c.codigo AS comanda_codigo, c.nome AS comanda_nome,
      (SELECT COUNT(*) FROM comanda_items ci WHERE ci.company_id=po.company_id AND ci.public_order_id=po.id) AS item_count,
      (SELECT COUNT(*) FROM comanda_print_jobs pj WHERE pj.company_id=po.company_id AND pj.public_order_id=po.id AND pj.status='PENDENTE') AS pending_jobs
      FROM comanda_public_orders po JOIN comandas c ON c.company_id=po.company_id AND c.id=po.comanda_id
      WHERE po.company_id=? ORDER BY po.id DESC LIMIT " . $limit;
    $st = $pdo->prepare($sql); $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('comanda_print_queue_create')) {
  /**
   * Garante um trabalho independente para cada setor existente no pedido.
   * Além do mapa recebido pela tela pública, reconstrói os grupos a partir dos
   * itens gravados. Isso evita perder o segundo setor quando o pedido possui,
   * por exemplo, BOLOS e BEBIDAS.
   */
  function comanda_print_queue_create(PDO $pdo, int $companyId, int $comandaId, int $publicOrderId, array $groupItems): int {
    $merged = [];
    $groupNames = [];
    $append = static function(string $group, array $ids) use (&$merged, &$groupNames): void {
      $group = trim($group);
      if ($group === '') $group = 'Cozinha';
      if (function_exists('mb_substr')) $group = mb_substr($group, 0, 120, 'UTF-8'); else $group = substr($group, 0, 120);
      $key = function_exists('mb_strtolower') ? mb_strtolower($group, 'UTF-8') : strtolower($group);
      if (!isset($merged[$key])) { $merged[$key] = []; $groupNames[$key] = $group; }
      foreach ($ids as $idRaw) {
        $id = (int)$idRaw;
        if ($id > 0) $merged[$key][$id] = $id;
      }
    };

    foreach ($groupItems as $group => $ids) $append((string)$group, (array)$ids);

    // A fonte definitiva é o que foi efetivamente gravado no pedido.
    // Produtos desmarcados para impressão na cozinha não entram na fila.
    try {
      $printFilter = comanda_column_exists($pdo, 'produtos', 'imprimir_cozinha') ? ' AND COALESCE(p.imprimir_cozinha,1)=1' : '';
      $sqlItems = "SELECT ci.id, COALESCE(NULLIF(TRIM(ci.grupo_impressao),''), NULLIF(TRIM(p.grupo_impressao),''), NULLIF(TRIM(p.grupo_producao),''), NULLIF(TRIM(p.categoria),''), 'Cozinha') AS group_name
        FROM comanda_items ci
        LEFT JOIN produtos p ON p.company_id=ci.company_id AND p.id=ci.produto_id
        WHERE ci.company_id=? AND ci.comanda_id=? AND ci.public_order_id=?" . $printFilter . " ORDER BY ci.id ASC";
      $stItems = $pdo->prepare($sqlItems);
      $stItems->execute([$companyId, $comandaId, $publicOrderId]);
      foreach (($stItems->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) $append((string)($row['group_name'] ?? 'Cozinha'), [(int)($row['id'] ?? 0)]);
    } catch (Throwable $e) {
      // Mantém o mapa recebido caso a estrutura antiga ainda esteja em atualização.
    }

    $st = $pdo->prepare("INSERT INTO comanda_print_jobs (company_id, comanda_id, public_order_id, group_name, printer_name, item_ids_json, status, created_at)
      VALUES (?, ?, ?, ?, NULLIF(?,''), ?, 'PENDENTE', NOW())
      ON DUPLICATE KEY UPDATE
        printer_name=IF(status='IMPRESSO',printer_name,VALUES(printer_name)),
        item_ids_json=VALUES(item_ids_json),
        status=IF(status='IMPRESSO','IMPRESSO',status),
        error_message=IF(status='IMPRESSO',error_message,NULL),
        updated_at=NOW()");
    foreach ($merged as $key => $idMap) {
      $ids = array_values($idMap);
      if (!$ids) continue;
      $group = (string)($groupNames[$key] ?? 'Cozinha');
      $printer = comanda_printer_for_group($pdo, $companyId, $group);
      $st->execute([$companyId, $comandaId, $publicOrderId, $group, $printer, json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    $row = finance_query_one($pdo, 'SELECT COUNT(*) AS qtd FROM comanda_print_jobs WHERE company_id=? AND public_order_id=?', [$companyId, $publicOrderId]);
    return (int)($row['qtd'] ?? 0);
  }
}


if (!function_exists('comanda_print_queue_create_for_items')) {
  /**
   * Envia itens lançados pelo operador diretamente para a mesma fila usada
   * pelo cardápio QR Code. Cada lançamento cria trabalhos independentes por
   * setor, sem abrir a janela de impressão do navegador.
   */
  function comanda_print_queue_create_for_items(PDO $pdo, int $companyId, int $comandaId, array $itemIds): int {
    comanda_ensure_schema($pdo);
    $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn($id) => $id > 0)));
    if ($companyId <= 0 || $comandaId <= 0 || !$itemIds) return 0;

    $ph = implode(',', array_fill(0, count($itemIds), '?'));
    $printFilter = comanda_column_exists($pdo, 'produtos', 'imprimir_cozinha') ? ' AND COALESCE(p.imprimir_cozinha,1)=1' : '';
    $sql = "SELECT ci.id,
      COALESCE(NULLIF(TRIM(ci.grupo_impressao),''), NULLIF(TRIM(p.grupo_impressao),''), NULLIF(TRIM(p.grupo_producao),''), NULLIF(TRIM(p.categoria),''), 'Cozinha') AS group_name
      FROM comanda_items ci
      LEFT JOIN produtos p ON p.company_id=ci.company_id AND p.id=ci.produto_id
      WHERE ci.company_id=? AND ci.comanda_id=? AND ci.id IN ($ph) AND ci.status='ABERTO'" . $printFilter . "
      ORDER BY ci.id ASC";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$companyId, $comandaId], $itemIds));

    $groups = [];
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
      $group = trim((string)($row['group_name'] ?? '')) ?: 'Cozinha';
      if (function_exists('mb_substr')) $group = mb_substr($group, 0, 120, 'UTF-8'); else $group = substr($group, 0, 120);
      $key = function_exists('mb_strtolower') ? mb_strtolower($group, 'UTF-8') : strtolower($group);
      if (!isset($groups[$key])) $groups[$key] = ['name'=>$group, 'ids'=>[]];
      $groups[$key]['ids'][] = (int)$row['id'];
    }
    if (!$groups) return 0;

    $ins = $pdo->prepare("INSERT INTO comanda_print_jobs
      (company_id, comanda_id, public_order_id, group_name, printer_name, item_ids_json, status, attempts, next_attempt_at, created_at)
      VALUES (?, ?, NULL, ?, NULLIF(?,''), ?, 'PENDENTE', 0, NOW(), NOW())");
    $created = 0;
    foreach ($groups as $data) {
      $group = (string)$data['name'];
      $ids = array_values(array_unique(array_map('intval', (array)$data['ids'])));
      if (!$ids) continue;
      $printer = comanda_printer_for_group($pdo, $companyId, $group);
      $ins->execute([$companyId, $comandaId, $group, $printer, json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
      $created++;
    }
    return $created;
  }
}

if (!function_exists('comanda_print_queue_reconcile_recent')) {
  /**
   * Reconstrói os trabalhos de impressão de todos os pedidos recentes.
   * A conferência é feita mesmo quando o pedido foi marcado como IMPRESSO,
   * pois uma versão anterior poderia ter concluído o pedido depois de imprimir
   * apenas o primeiro grupo. Trabalhos já impressos são preservados e somente
   * os grupos ausentes são incluídos na fila.
   */
  function comanda_print_queue_reconcile_recent(PDO $pdo, int $companyId, int $limit = 100): void {
    $limit = max(1, min(300, $limit));
    try {
      $st = $pdo->prepare("SELECT id, comanda_id FROM comanda_public_orders
        WHERE company_id=? AND created_at>=DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY id DESC LIMIT " . $limit);
      $st->execute([$companyId]);
      foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $order) {
        $orderId = (int)($order['id'] ?? 0);
        $comandaId = (int)($order['comanda_id'] ?? 0);
        if ($orderId <= 0 || $comandaId <= 0) continue;

        $jobs = comanda_print_queue_create($pdo, $companyId, $comandaId, $orderId, []);
        if ($jobs <= 0) continue;

        $notPrinted = (int)(finance_query_one($pdo, "SELECT COUNT(*) AS qtd FROM comanda_print_jobs WHERE company_id=? AND public_order_id=? AND status<>'IMPRESSO'", [$companyId, $orderId])['qtd'] ?? 0);
        if ($notPrinted > 0) {
          $pdo->prepare("UPDATE comanda_public_orders SET status='AGUARDANDO_IMPRESSAO', printed_at=NULL, updated_at=NOW() WHERE company_id=? AND id=?")
            ->execute([$companyId, $orderId]);
        } else {
          $pdo->prepare("UPDATE comanda_public_orders SET status='IMPRESSO', printed_at=COALESCE(printed_at,NOW()), updated_at=NOW() WHERE company_id=? AND id=?")
            ->execute([$companyId, $orderId]);
        }
      }
    } catch (Throwable $e) {}
  }
}

if (!function_exists('comanda_print_job')) {
  function comanda_print_job(PDO $pdo, int $companyId, int $jobId): ?array {
    $row = finance_query_one($pdo, "SELECT pj.*, c.codigo AS comanda_codigo, c.nome AS comanda_nome, po.customer_name, po.customer_phone FROM comanda_print_jobs pj JOIN comandas c ON c.company_id=pj.company_id AND c.id=pj.comanda_id LEFT JOIN comanda_public_orders po ON po.company_id=pj.company_id AND po.id=pj.public_order_id WHERE pj.company_id=? AND pj.id=? LIMIT 1", [$companyId, $jobId]);
    return $row ?: null;
  }
}

if (!function_exists('comanda_print_job_items')) {
  function comanda_print_job_items(PDO $pdo, int $companyId, array $job): array {
    $ids = json_decode((string)($job['item_ids_json'] ?? '[]'), true);
    $ids = array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []))));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$companyId], $ids);
    $st = $pdo->prepare("SELECT * FROM comanda_items WHERE company_id=? AND id IN ($ph) ORDER BY id ASC");
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('comanda_print_job_next')) {
  function comanda_print_job_next(PDO $pdo, int $companyId, string $group = ''): ?array {
    comanda_ensure_schema($pdo);
    $group = trim($group);
    $pdo->beginTransaction();
    try {
      $sql = "SELECT id FROM comanda_print_jobs WHERE company_id=? AND status='PENDENTE' AND (next_attempt_at IS NULL OR next_attempt_at<=NOW())";
      $params = [$companyId];
      if ($group !== '') { $sql .= ' AND group_name=?'; $params[] = $group; }
      $sql .= ' ORDER BY created_at ASC, id ASC LIMIT 1 FOR UPDATE';
      $st = $pdo->prepare($sql); $st->execute($params);
      $id = (int)($st->fetchColumn() ?: 0);
      if ($id <= 0) { $pdo->commit(); return null; }
      $pdo->prepare("UPDATE comanda_print_jobs SET status='PROCESSANDO', attempts=attempts+1, agent_id=NULL, claimed_by='Navegador', claimed_at=NOW(), next_attempt_at=NULL, updated_at=NOW() WHERE company_id=? AND id=?")->execute([$companyId, $id]);
      $pdo->commit();
      return comanda_print_job($pdo, $companyId, $id);
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
  }
}

if (!function_exists('comanda_print_job_mark')) {
  function comanda_print_job_mark(PDO $pdo, int $companyId, int $jobId, string $status, string $error = ''): void {
    $job = comanda_print_job($pdo, $companyId, $jobId);
    if (!$job) throw new RuntimeException('Trabalho de impressão não encontrado.');
    $status = strtoupper(trim($status));
    if (!in_array($status, ['IMPRESSO','PENDENTE','ERRO'], true)) $status = 'ERRO';
    $printed = $status === 'IMPRESSO' ? 'NOW()' : 'NULL';
    $release = $status === 'PENDENTE' ? ", agent_id=NULL, claimed_by=NULL, claimed_at=NULL, next_attempt_at=NULL" : ", next_attempt_at=NULL";
    $sql = "UPDATE comanda_print_jobs SET status=?, printed_at=$printed, error_message=NULLIF(?,''), updated_at=NOW()$release WHERE company_id=? AND id=?";
    $pdo->prepare($sql)->execute([$status, trim($error), $companyId, $jobId]);
    if ($job && (int)($job['public_order_id'] ?? 0) > 0 && $status !== 'IMPRESSO') {
      $orderStatus = $status === 'PENDENTE' ? 'AGUARDANDO_IMPRESSAO' : 'ERRO_IMPRESSAO';
      try { $pdo->prepare('UPDATE comanda_public_orders SET status=?, printed_at=NULL, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$orderStatus, $companyId, (int)$job['public_order_id']]); } catch (Throwable $e) {}
    }
    if ($status === 'IMPRESSO') {
      if ($job) {
        $ids = json_decode((string)($job['item_ids_json'] ?? '[]'), true);
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []))));
        if ($ids) {
          $ph = implode(',', array_fill(0, count($ids), '?'));
          $params = array_merge([$companyId], $ids);
          try { $pdo->prepare("UPDATE comanda_items SET printed_at=COALESCE(printed_at,NOW()), updated_at=NOW() WHERE company_id=? AND id IN ($ph)")->execute($params); } catch (Throwable $e) {}
        }
      }
      if ($job && (int)($job['public_order_id'] ?? 0) > 0) {
        $pending = (int)(finance_query_one($pdo, "SELECT COUNT(*) AS qtd FROM comanda_print_jobs WHERE company_id=? AND public_order_id=? AND status<>'IMPRESSO'", [$companyId, (int)$job['public_order_id']])['qtd'] ?? 0);
        if ($pending <= 0) $pdo->prepare("UPDATE comanda_public_orders SET status='IMPRESSO', printed_at=NOW(), updated_at=NOW() WHERE company_id=? AND id=?")->execute([$companyId, (int)$job['public_order_id']]);
      }
    }
  }
}


if (!function_exists('comanda_print_job_reprint')) {
  function comanda_print_job_reprint(PDO $pdo, int $companyId, int $jobId): void {
    $job = comanda_print_job($pdo, $companyId, $jobId);
    if (!$job) throw new RuntimeException('Trabalho de impressão não encontrado.');
    $printer = comanda_printer_for_group($pdo, $companyId, (string)($job['group_name'] ?? ''));
    $pdo->prepare("UPDATE comanda_print_jobs SET status='PENDENTE', attempts=0, agent_id=NULL, claimed_by=NULL, claimed_at=NULL, printed_at=NULL, next_attempt_at=NOW(), printer_name=NULLIF(?,''), error_message='Reimpressão solicitada pelo monitor', updated_at=NOW() WHERE company_id=? AND id=?")
      ->execute([$printer, $companyId, $jobId]);
    if ((int)($job['public_order_id'] ?? 0) > 0) {
      try {
        $pdo->prepare("UPDATE comanda_public_orders SET status='AGUARDANDO_IMPRESSAO', printed_at=NULL, updated_at=NOW() WHERE company_id=? AND id=?")
          ->execute([$companyId, (int)$job['public_order_id']]);
      } catch (Throwable $e) {}
    }
  }
}

if (!function_exists('comanda_print_agent_normalize_groups')) {
  function comanda_print_agent_normalize_groups(array $groups): array {
    $out = [];
    foreach ($groups as $group) {
      $name = trim((string)$group);
      if ($name === '') continue;
      if (function_exists('mb_substr')) $name = mb_substr($name, 0, 120, 'UTF-8'); else $name = substr($name, 0, 120);
      $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
      $out[$key] = $name;
    }
    return array_values($out);
  }
}

if (!function_exists('comanda_print_agent_groups')) {
  function comanda_print_agent_groups(array $agent): array {
    $raw = json_decode((string)($agent['allowed_groups_json'] ?? '[]'), true);
    return comanda_print_agent_normalize_groups(is_array($raw) ? $raw : []);
  }
}

if (!function_exists('comanda_print_agent_sync_reported_groups')) {
  /**
   * Sincroniza os setores realmente mapeados no computador do agente.
   * O token continua limitado à empresa, mas a lista local passa a ser a
   * fonte de verdade para decidir quais filas essa estação consegue atender.
   */
  function comanda_print_agent_sync_reported_groups(PDO $pdo, array $agent, array $reportedGroups): array {
    $reported = comanda_print_agent_normalize_groups($reportedGroups);
    if (!$reported) return $agent;

    $current = comanda_print_agent_groups($agent);
    $normalizeForCompare = static function(array $groups): array {
      $out = [];
      foreach ($groups as $group) {
        $value = trim((string)$group);
        if ($value === '') continue;
        $out[] = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
      }
      sort($out, SORT_NATURAL | SORT_FLAG_CASE);
      return array_values(array_unique($out));
    };

    if ($normalizeForCompare($current) !== $normalizeForCompare($reported)) {
      $json = json_encode($reported, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      $pdo->prepare('UPDATE comanda_print_agents SET allowed_groups_json=?, updated_at=NOW() WHERE company_id=? AND id=?')
        ->execute([$json, (int)$agent['company_id'], (int)$agent['id']]);
      $agent['allowed_groups_json'] = $json;
    }
    return $agent;
  }
}

if (!function_exists('comanda_print_agent_rows')) {
  function comanda_print_agent_rows(PDO $pdo, int $companyId): array {
    comanda_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT a.*,
      (SELECT COUNT(*) FROM comanda_print_jobs pj WHERE pj.company_id=a.company_id AND pj.agent_id=a.id AND pj.status='IMPRESSO') AS printed_jobs,
      (SELECT COUNT(*) FROM comanda_print_jobs pj WHERE pj.company_id=a.company_id AND pj.agent_id=a.id AND pj.status='ERRO') AS error_jobs
      FROM comanda_print_agents a WHERE a.company_id=? ORDER BY a.is_active DESC, a.name ASC, a.id ASC");
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('comanda_print_agent_token')) {
  function comanda_print_agent_token(): string {
    try { return 'nexa_' . bin2hex(random_bytes(32)); }
    catch (Throwable $e) { return 'nexa_' . hash('sha256', uniqid((string)mt_rand(), true) . microtime(true)); }
  }
}

if (!function_exists('comanda_print_agent_create')) {
  function comanda_print_agent_create(PDO $pdo, int $companyId, string $name, array $groups): array {
    comanda_ensure_schema($pdo);
    $name = trim($name);
    if ($name === '') throw new RuntimeException('Informe o nome da estação/agente.');
    $groups = comanda_print_agent_normalize_groups($groups);
    if (!$groups) throw new RuntimeException('Selecione ao menos um setor para o agente.');
    $token = comanda_print_agent_token();
    $hash = hash('sha256', $token);
    $hint = substr($token, 0, 10) . '...' . substr($token, -4);
    $st = $pdo->prepare("INSERT INTO comanda_print_agents (company_id,name,token_hash,token_hint,allowed_groups_json,is_active,created_at) VALUES (?,?,?,?,?,1,NOW())");
    $st->execute([$companyId, $name, $hash, $hint, json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    return ['id'=>(int)$pdo->lastInsertId(), 'token'=>$token, 'hint'=>$hint, 'groups'=>$groups];
  }
}

if (!function_exists('comanda_print_agent_regenerate_token')) {
  function comanda_print_agent_regenerate_token(PDO $pdo, int $companyId, int $agentId): array {
    comanda_ensure_schema($pdo);
    if ($agentId <= 0) throw new RuntimeException('Agente inválido.');
    $token = comanda_print_agent_token();
    $hash = hash('sha256', $token);
    $hint = substr($token, 0, 10) . '...' . substr($token, -4);
    $st = $pdo->prepare("UPDATE comanda_print_agents SET token_hash=?, token_hint=?, last_seen_at=NULL, updated_at=NOW() WHERE company_id=? AND id=?");
    $st->execute([$hash, $hint, $companyId, $agentId]);
    if ($st->rowCount() <= 0) throw new RuntimeException('Agente não encontrado.');
    return ['id'=>$agentId, 'token'=>$token, 'hint'=>$hint];
  }
}

if (!function_exists('comanda_print_agent_authenticate')) {
  function comanda_print_agent_authenticate(PDO $pdo, string $token): ?array {
    comanda_ensure_schema($pdo);
    $token = trim($token);
    if ($token === '' || !str_starts_with($token, 'nexa_') || strlen($token) < 40) return null;
    $hash = hash('sha256', $token);
    $row = finance_query_one($pdo, "SELECT * FROM comanda_print_agents WHERE token_hash=? AND is_active=1 LIMIT 1", [$hash]);
    return $row ?: null;
  }
}

if (!function_exists('comanda_print_agent_touch')) {
  function comanda_print_agent_touch(PDO $pdo, array $agent, string $machineName = '', string $version = '', string $ip = ''): void {
    $machineName = trim($machineName);
    $version = trim($version);
    $ip = trim($ip);
    if (function_exists('mb_substr')) {
      $machineName = mb_substr($machineName, 0, 120, 'UTF-8');
      $version = mb_substr($version, 0, 40, 'UTF-8');
    } else {
      $machineName = substr($machineName, 0, 120);
      $version = substr($version, 0, 40);
    }
    $ip = substr($ip, 0, 64);
    $pdo->prepare("UPDATE comanda_print_agents SET machine_name=NULLIF(?,''), agent_version=NULLIF(?,''), last_ip=NULLIF(?,''), last_seen_at=NOW(), updated_at=NOW() WHERE company_id=? AND id=?")
      ->execute([$machineName, $version, $ip, (int)$agent['company_id'], (int)$agent['id']]);
  }
}

if (!function_exists('comanda_print_agent_allowed_groups')) {
  function comanda_print_agent_allowed_groups(array $agent, array $reportedGroups = []): array {
    $allowed = comanda_print_agent_groups($agent);
    if (!$reportedGroups) return $allowed;
    $reported = comanda_print_agent_normalize_groups($reportedGroups);
    $map = [];
    foreach ($allowed as $g) $map[function_exists('mb_strtolower') ? mb_strtolower($g, 'UTF-8') : strtolower($g)] = $g;
    $out = [];
    foreach ($reported as $g) {
      $key = function_exists('mb_strtolower') ? mb_strtolower($g, 'UTF-8') : strtolower($g);
      if (isset($map[$key])) $out[] = $map[$key];
    }
    return $out;
  }
}

if (!function_exists('comanda_print_job_agent_payload')) {
  function comanda_print_job_agent_payload(PDO $pdo, int $companyId, array $job): array {
    $items = comanda_print_job_items($pdo, $companyId, $job);
    $company = finance_query_one($pdo, 'SELECT name, slug FROM companies WHERE id=? LIMIT 1', [$companyId]) ?: [];
    $createdAt = (string)($job['created_at'] ?? '');
    $outItems = [];
    foreach ($items as $item) {
      $outItems[] = [
        'id'=>(int)($item['id'] ?? 0),
        'name'=>(string)($item['nome'] ?? 'Item'),
        'qty'=>(float)($item['qtd'] ?? 0),
        'observation'=>(string)($item['observacao'] ?? ''),
        'created_at'=>(string)($item['created_at'] ?? $createdAt),
      ];
    }
    return [
      'id'=>(int)$job['id'],
      'group'=>(string)$job['group_name'],
      'printer_hint'=>(string)($job['printer_name'] ?? ''),
      'comanda'=>(string)($job['comanda_codigo'] ?? ''),
      'customer_name'=>(string)(trim((string)($job['customer_name'] ?? '')) !== '' ? $job['customer_name'] : ($job['comanda_nome'] ?? '')),
      'customer_phone'=>(string)($job['customer_phone'] ?? ''),
      'company_name'=>(string)($company['name'] ?? ''),
      'created_at'=>$createdAt,
      'attempts'=>(int)($job['attempts'] ?? 0),
      'items'=>$outItems,
    ];
  }
}

if (!function_exists('comanda_print_job_next_for_agent')) {
  function comanda_print_job_next_for_agent(PDO $pdo, array $agent, array $reportedGroups = []): ?array {
    comanda_ensure_schema($pdo);
    $companyId = (int)$agent['company_id'];
    $groups = comanda_print_agent_allowed_groups($agent, $reportedGroups);
    if (!$groups) return null;
    try {
      $pdo->prepare("UPDATE comanda_print_jobs pj LEFT JOIN comanda_print_agents pa ON pa.company_id=pj.company_id AND pa.id=pj.agent_id SET pj.status='PENDENTE', pj.agent_id=NULL, pj.claimed_by=NULL, pj.claimed_at=NULL, pj.next_attempt_at=NOW(), pj.error_message='Recolocado automaticamente na fila após perda de comunicação', pj.updated_at=NOW() WHERE pj.company_id=? AND pj.status='PROCESSANDO' AND pj.claimed_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND (pj.agent_id IS NULL OR pa.id IS NULL OR pa.is_active<>1 OR pa.last_seen_at IS NULL OR pa.last_seen_at < DATE_SUB(NOW(), INTERVAL 90 SECOND))")
        ->execute([$companyId]);
    } catch (Throwable $e) {}
    $pdo->beginTransaction();
    try {
      $ph = implode(',', array_fill(0, count($groups), '?'));
      $params = array_merge([$companyId], $groups);
      $sql = "SELECT id FROM comanda_print_jobs WHERE company_id=? AND status='PENDENTE' AND (next_attempt_at IS NULL OR next_attempt_at<=NOW()) AND group_name IN ($ph) ORDER BY created_at ASC,id ASC LIMIT 1 FOR UPDATE";
      $st = $pdo->prepare($sql); $st->execute($params);
      $id = (int)($st->fetchColumn() ?: 0);
      if ($id <= 0) { $pdo->commit(); return null; }
      $pdo->prepare("UPDATE comanda_print_jobs SET status='PROCESSANDO', attempts=attempts+1, agent_id=?, claimed_by=?, claimed_at=NOW(), next_attempt_at=NULL, error_message=NULL, updated_at=NOW() WHERE company_id=? AND id=?")
        ->execute([(int)$agent['id'], (string)$agent['name'], $companyId, $id]);
      $pdo->commit();
      $job = comanda_print_job($pdo, $companyId, $id);
      return $job ? comanda_print_job_agent_payload($pdo, $companyId, $job) : null;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}


if (!function_exists('comanda_print_jobs_next_batch_for_agent')) {
  /**
   * Reserva em uma única operação todos os trabalhos pendentes que esta
   * estação consegue atender. Antes da reserva, reconstrói os grupos dos
   * pedidos mais antigos a partir dos itens gravados. Assim, um mesmo pedido
   * com BOLOS, BEBIDAS, COZINHA, SOBREMESAS ou qualquer quantidade de grupos
   * gera um trabalho independente para cada setor.
   */
  function comanda_print_jobs_next_batch_for_agent(PDO $pdo, array $agent, array $reportedGroups = [], int $limit = 25): array {
    comanda_ensure_schema($pdo);
    $companyId = (int)$agent['company_id'];
    $groups = comanda_print_agent_allowed_groups($agent, $reportedGroups);
    $limit = max(1, min(50, $limit));
    if (!$groups) return [];

    // Libera apenas trabalhos realmente abandonados. Um agente online nunca
    // perde a reserva enquanto tenta confirmar o envio ao spooler.
    try {
      $pdo->prepare("UPDATE comanda_print_jobs pj
        LEFT JOIN comanda_print_agents pa ON pa.company_id=pj.company_id AND pa.id=pj.agent_id
        SET pj.status='PENDENTE', pj.agent_id=NULL, pj.claimed_by=NULL, pj.claimed_at=NULL,
            pj.next_attempt_at=NOW(), pj.error_message='Recolocado automaticamente na fila após perda de comunicação', pj.updated_at=NOW()
        WHERE pj.company_id=? AND pj.status='PROCESSANDO'
          AND pj.claimed_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
          AND (pj.agent_id IS NULL OR pa.id IS NULL OR pa.is_active<>1 OR pa.last_seen_at IS NULL OR pa.last_seen_at < DATE_SUB(NOW(), INTERVAL 90 SECOND))")
        ->execute([$companyId]);
    } catch (Throwable $e) {}

    $ph = implode(',', array_fill(0, count($groups), '?'));
    $baseParams = array_merge([$companyId], $groups);
    $baseWhere = "company_id=? AND status='PENDENTE' AND (next_attempt_at IS NULL OR next_attempt_at<=NOW()) AND group_name IN ($ph)";

    // Recria todos os grupos dos pedidos que estão próximos de serem
    // processados. Isto corrige inclusive pedidos antigos em que somente o
    // primeiro grupo havia sido criado por versões anteriores.
    try {
      $stOrders = $pdo->prepare("SELECT public_order_id, MAX(comanda_id) AS comanda_id, MIN(created_at) AS first_created
        FROM comanda_print_jobs
        WHERE $baseWhere AND public_order_id IS NOT NULL AND public_order_id>0
        GROUP BY public_order_id
        ORDER BY first_created ASC
        LIMIT 20");
      $stOrders->execute($baseParams);
      foreach (($stOrders->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $orderId = (int)($row['public_order_id'] ?? 0);
        $comandaId = (int)($row['comanda_id'] ?? 0);
        if ($orderId > 0 && $comandaId > 0) comanda_print_queue_create($pdo, $companyId, $comandaId, $orderId, []);
      }
    } catch (Throwable $e) {}

    $pdo->beginTransaction();
    try {
      $stIds = $pdo->prepare("SELECT id FROM comanda_print_jobs
        WHERE $baseWhere
        ORDER BY COALESCE(public_order_id,id) ASC, created_at ASC, id ASC
        LIMIT " . $limit . " FOR UPDATE");
      $stIds->execute($baseParams);
      $ids = array_values(array_filter(array_map('intval', $stIds->fetchAll(PDO::FETCH_COLUMN) ?: [])));
      if (!$ids) { $pdo->commit(); return []; }

      $idPh = implode(',', array_fill(0, count($ids), '?'));
      $updParams = array_merge([(int)$agent['id'], (string)$agent['name'], $companyId], $ids);
      $pdo->prepare("UPDATE comanda_print_jobs
        SET status='PROCESSANDO', attempts=attempts+1, agent_id=?, claimed_by=?, claimed_at=NOW(),
            next_attempt_at=NULL, error_message=NULL, updated_at=NOW()
        WHERE company_id=? AND id IN ($idPh) AND status='PENDENTE'")
        ->execute($updParams);
      $pdo->commit();

      $jobs = [];
      foreach ($ids as $id) {
        $job = comanda_print_job($pdo, $companyId, $id);
        if (!$job || strtoupper((string)($job['status'] ?? '')) !== 'PROCESSANDO' || (int)($job['agent_id'] ?? 0) !== (int)$agent['id']) continue;
        $payload = comanda_print_job_agent_payload($pdo, $companyId, $job);
        // Um trabalho sem itens nunca deve consumir a fila silenciosamente.
        // Ele é devolvido para erro, permitindo diagnóstico no Monitor.
        if (empty($payload['items'])) {
          comanda_print_job_mark($pdo, $companyId, $id, 'ERRO', 'O trabalho não possui itens vinculados. Reconstrua a fila no Monitor.');
          continue;
        }
        $jobs[] = $payload;
      }
      return $jobs;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}


if (!function_exists('comanda_print_job_finish_for_agent')) {
  function comanda_print_job_finish_for_agent(PDO $pdo, array $agent, int $jobId, bool $ok, string $error = '', string $printerName = ''): array {
    $companyId = (int)$agent['company_id'];
    $job = finance_query_one($pdo, 'SELECT * FROM comanda_print_jobs WHERE company_id=? AND id=? LIMIT 1', [$companyId, $jobId]);
    if (!$job) throw new RuntimeException('Trabalho de impressão não encontrado.');
    $jobStatus = strtoupper((string)($job['status'] ?? ''));
    $jobAgentId = (int)($job['agent_id'] ?? 0);
    $agentId = (int)$agent['id'];
    $printerName = trim($printerName);
    if ($printerName !== '') {
      if (function_exists('mb_substr')) $printerName = mb_substr($printerName, 0, 180, 'UTF-8'); else $printerName = substr($printerName, 0, 180);
      try {
        $pdo->prepare("UPDATE comanda_print_jobs SET printer_name=?, updated_at=NOW() WHERE company_id=? AND id=?")
          ->execute([$printerName, $companyId, $jobId]);
      } catch (Throwable $e) {}
    }

    // A confirmação positiva é idempotente. Isso permite concluir um trabalho
    // que já chegou ao spooler, mesmo que a resposta anterior tenha se perdido.
    if ($ok) {
      if ($jobStatus === 'IMPRESSO') return ['status'=>'IMPRESSO','retry'=>false,'already_confirmed'=>true];
      $reservedByThisAgent = $jobStatus === 'PROCESSANDO' && $jobAgentId === $agentId;
      $releasedAfterTimeout = $jobStatus === 'PENDENTE' && $jobAgentId === 0;
      if (!$reservedByThisAgent && !$releasedAfterTimeout) {
        throw new RuntimeException('Este trabalho não pode ser confirmado por este agente.');
      }
      comanda_print_job_mark($pdo, $companyId, $jobId, 'IMPRESSO', '');
      return ['status'=>'IMPRESSO','retry'=>false];
    }

    if ($jobStatus === 'IMPRESSO') return ['status'=>'IMPRESSO','retry'=>false,'already_confirmed'=>true];
    if ($jobAgentId !== $agentId || $jobStatus !== 'PROCESSANDO') {
      throw new RuntimeException('Este trabalho não está reservado para este agente.');
    }
    $error = trim($error);
    if (function_exists('mb_substr')) $error = mb_substr($error, 0, 255, 'UTF-8'); else $error = substr($error, 0, 255);
    $attempts = max(1, (int)($job['attempts'] ?? 1));
    if ($attempts < 5) {
      $delay = min(120, 10 * $attempts);
      $sql = "UPDATE comanda_print_jobs SET status='PENDENTE', agent_id=NULL, claimed_by=NULL, claimed_at=NULL, next_attempt_at=DATE_ADD(NOW(), INTERVAL " . (int)$delay . " SECOND), error_message=NULLIF(?,''), updated_at=NOW() WHERE company_id=? AND id=?";
      $pdo->prepare($sql)->execute([$error ?: 'Falha na impressão automática; nova tentativa agendada.', $companyId, $jobId]);
      if ((int)($job['public_order_id'] ?? 0) > 0) {
        try { $pdo->prepare("UPDATE comanda_public_orders SET status='AGUARDANDO_IMPRESSAO', printed_at=NULL, updated_at=NOW() WHERE company_id=? AND id=?")->execute([$companyId, (int)$job['public_order_id']]); } catch (Throwable $e) {}
      }
      return ['status'=>'PENDENTE','retry'=>true,'retry_after'=>$delay];
    }
    comanda_print_job_mark($pdo, $companyId, $jobId, 'ERRO', $error ?: 'A impressão falhou após cinco tentativas.');
    return ['status'=>'ERRO','retry'=>false];
  }
}

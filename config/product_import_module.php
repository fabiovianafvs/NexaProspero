<?php
declare(strict_types=1);

require_once __DIR__ . '/load_config.php';
require_once __DIR__ . '/config_store.php';
require_once __DIR__ . '/firebird_runner.php';
require_once __DIR__ . '/fiscal_module.php';
require_once __DIR__ . '/company_sequences.php';
require_once __DIR__ . '/stock_module.php';

if (!function_exists('product_import_h')) {
  function product_import_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('product_import_substr')) {
  function product_import_substr($value, int $max): string {
    $value = (string)$value;
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
  }
}

if (!function_exists('product_import_decimal')) {
  function product_import_decimal($value): float {
    if (is_float($value) || is_int($value)) return (float)$value;
    $value = trim((string)$value);
    if ($value === '') return 0.0;
    if (str_contains($value, ',') && str_contains($value, '.')) {
      if ((int)strrpos($value, ',') > (int)strrpos($value, '.')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
      } else {
        $value = str_replace(',', '', $value);
      }
    } else {
      $value = str_replace(',', '.', $value);
    }
    return is_numeric($value) ? (float)$value : 0.0;
  }
}

if (!function_exists('product_import_digits')) {
  function product_import_digits($value, int $max = 20): string {
    return substr((string)(preg_replace('/\D+/', '', (string)$value) ?? ''), 0, $max);
  }
}

if (!function_exists('product_import_is_probable_barcode')) {
  function product_import_is_probable_barcode($value): bool {
    $text = trim((string)$value);
    if ($text === '' || !ctype_digit($text)) return false;
    $length = strlen($text);
    return $length >= 8 && $length <= 20;
  }
}

if (!function_exists('product_import_resolve_barcode')) {
  function product_import_resolve_barcode($explicitBarcode, $sourceCode = ''): string {
    $barcode = product_import_digits($explicitBarcode, 80);
    if ($barcode !== '') return $barcode;
    $source = trim((string)$sourceCode);
    return product_import_is_probable_barcode($source) ? $source : '';
  }
}

if (!function_exists('product_import_source_number_candidate')) {
  function product_import_source_number_candidate($sourceCode): int {
    $text = trim((string)$sourceCode);
    if ($text === '' || !ctype_digit($text)) return 0;
    if (strlen($text) > 1 && $text[0] === '0') return 0;
    if (product_import_is_probable_barcode($text)) return 0;
    if (strlen($text) > 10) return 0;
    $value = (int)$text;
    if ($value <= 0 || $value > 2147483647) return 0;
    return $value;
  }
}

if (!function_exists('product_import_company_code_ready')) {
  function product_import_company_code_ready(PDO $pdo): bool {
    try {
      $st = $pdo->prepare("SELECT DATA_TYPE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='produtos' AND column_name='numero_cadastro' LIMIT 1");
      $st->execute();
      return in_array(strtolower((string)$st->fetchColumn()), ['varchar','char'], true);
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('product_import_table_has_column')) {
  function product_import_table_has_column(PDO $pdo, string $table, string $column): bool {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) return false;
    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
      $st->execute([$table, $column]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('product_import_category_visibility_defaults')) {
  /**
   * Retorna a visibilidade coerente da categoria de destino.
   * Uma categoria só é considerada intencionalmente oculta quando TODOS os
   * produtos já existentes nela estão ocultos. Categorias mistas são tratadas
   * como visíveis para não propagar inconsistências antigas da importação.
   */
  function product_import_category_visibility_defaults(PDO $pdo, int $companyId, string $category, int $excludeProductId = 0): array {
    $out = [
      'category_hide_order_catalog' => 0,
      'category_hide_product_catalog' => 0,
    ];
    if ($companyId <= 0) return $out;
    $category = trim($category);
    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':' . $companyId . ':' . md5($category);
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

    $hasOrder = product_import_table_has_column($pdo, 'produtos', 'category_hide_order_catalog');
    $hasProduct = product_import_table_has_column($pdo, 'produtos', 'category_hide_product_catalog');
    if (!$hasOrder && !$hasProduct) return $out;

    $select = ['COUNT(*) AS total'];
    if ($hasOrder) $select[] = 'SUM(CASE WHEN COALESCE(category_hide_order_catalog,0) <> 0 THEN 1 ELSE 0 END) AS hide_order';
    if ($hasProduct) $select[] = 'SUM(CASE WHEN COALESCE(category_hide_product_catalog,0) <> 0 THEN 1 ELSE 0 END) AS hide_product';
    $sql = "SELECT " . implode(', ', $select) . " FROM produtos WHERE company_id=? AND COALESCE(TRIM(categoria),'')=?";
    $params = [$companyId, trim($category)];
    if ($excludeProductId > 0) {
      $sql .= ' AND id<>?';
      $params[] = $excludeProductId;
    }
    try {
      $st = $pdo->prepare($sql);
      $st->execute($params);
      $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      $total = (int)($row['total'] ?? 0);
      if ($total > 0) {
        if ($hasOrder && (int)($row['hide_order'] ?? 0) === $total) $out['category_hide_order_catalog'] = 1;
        if ($hasProduct && (int)($row['hide_product'] ?? 0) === $total) $out['category_hide_product_catalog'] = 1;
      }
    } catch (Throwable $e) {
      // Em caso de dúvida, novos itens entram visíveis; a manutenção pode normalizar depois.
    }
    return $cache[$cacheKey] = $out;
  }
}

if (!function_exists('product_import_apply_visibility_defaults')) {
  function product_import_apply_visibility_defaults(PDO $pdo, int $companyId, string $category, array &$data, int $excludeProductId = 0): void {
    $defaults = product_import_category_visibility_defaults($pdo, $companyId, $category, $excludeProductId);
    // product_import_ensure_schema() prepara estes campos antes dos lotes.
    $data['category_hide_order_catalog'] = (int)($defaults['category_hide_order_catalog'] ?? 0);
    $data['category_hide_product_catalog'] = (int)($defaults['category_hide_product_catalog'] ?? 0);
  }
}

if (!function_exists('product_import_ensure_schema')) {
  function product_import_ensure_schema(PDO $pdo): void {
    if ($pdo->inTransaction()) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS product_import_tax_map (
      company_id BIGINT NOT NULL,
      source_tax_code VARCHAR(60) NOT NULL,
      target_tax_profile_id INT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (company_id, source_tax_code),
      KEY idx_product_import_tax_profile (company_id, target_tax_profile_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS product_import_runs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'RUNNING',
      source_database VARCHAR(500) NULL,
      source_company_code INT NULL,
      total_source INT NOT NULL DEFAULT 0,
      inserted_count INT NOT NULL DEFAULT 0,
      updated_count INT NOT NULL DEFAULT 0,
      skipped_count INT NOT NULL DEFAULT 0,
      error_count INT NOT NULL DEFAULT 0,
      details_json LONGTEXT NULL,
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      finished_at DATETIME NULL,
      PRIMARY KEY (id),
      KEY idx_product_import_runs_company (company_id, started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS produtos_firebird_map (
      id INT AUTO_INCREMENT PRIMARY KEY,
      company_id BIGINT NOT NULL,
      produto_id BIGINT NOT NULL,
      codigo_firebird VARCHAR(60) NULL,
      created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY ux_produtos_firebird_map (company_id, produto_id),
      KEY idx_produtos_firebird_codigo (company_id, codigo_firebird)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!product_import_table_has_column($pdo, 'produtos', 'codigo_barras')) {
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN codigo_barras VARCHAR(80) NULL'); } catch (Throwable $e) {}
    }
    if (!product_import_table_has_column($pdo, 'produtos', 'category_hide_order_catalog')) {
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN category_hide_order_catalog TINYINT(1) NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
    }
    if (!product_import_table_has_column($pdo, 'produtos', 'category_hide_product_catalog')) {
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN category_hide_product_catalog TINYINT(1) NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
    }
    if (function_exists('fiscal_xml_ensure_product_cost_column')) {
      fiscal_xml_ensure_product_cost_column($pdo);
    }
    stock_ensure_schema($pdo, true);
    company_sequence_ensure_schema($pdo, false);
    fiscal_ensure_schema($pdo);
    product_import_extended_ensure_schema($pdo);
  }
}

if (!function_exists('product_import_config')) {
  function product_import_config(PDO $pdo, int $companyId): array {
    $base = load_firebird_config($companyId);
    $defaults = [
      'enabled' => true,
      'host' => (string)($base['host'] ?? '127.0.0.1'),
      'port' => (int)($base['port'] ?? 3050),
      'database' => (string)($base['database'] ?? ''),
      'user' => (string)($base['user'] ?? 'SYSDBA'),
      'pass' => (string)($base['pass'] ?? 'masterkey'),
      'charset' => (string)($base['charset'] ?? 'UTF8'),
      'empresa_codigo' => (int)($base['empresa_codigo'] ?? 1),
      'tables' => [
        'products' => 'CAD_PRODUTOS',
        'groups' => 'CAD_GRUPO',
      ],
      'fields' => [
        'products' => [
          'company' => 'EMPRESA',
          'code' => 'CODIGO',
          'description' => 'DESCRICAO',
          'group' => 'GRUPO',
          'unit' => 'UNIDADE',
          'barcode' => 'CODIGO_BARRA',
          'ncm' => 'NCM',
          'cest' => 'CEST',
          'tax_code' => 'COD_TRIBUTACAO',
          'cost' => 'PRC_CUSTO',
          'price' => 'PRC_VENDA',
        ],
        'groups' => [
          'company' => 'EMPRESA',
          'code' => 'CODIGO',
          'description' => 'DESCRICAO',
        ],
      ],
    ];
    try {
      $over = config_store_get($pdo, $companyId, 'firebird_import');
      if ($over) $defaults = config_deep_merge($defaults, $over);
    } catch (Throwable $e) {}
    $defaults['port'] = max(1, (int)($defaults['port'] ?? 3050));
    $defaults['empresa_codigo'] = max(0, (int)($defaults['empresa_codigo'] ?? 0));
    return $defaults;
  }
}

if (!function_exists('product_import_save_config')) {
  function product_import_save_config(PDO $pdo, int $companyId, array $post): array {
    $current = product_import_config($pdo, $companyId);
    $postedPassword = (string)($post['fb_password'] ?? '');
    $cfg = [
      'enabled' => true,
      'host' => trim((string)($post['fb_host'] ?? '127.0.0.1')),
      'port' => max(1, (int)($post['fb_port'] ?? 3050)),
      'database' => trim((string)($post['fb_database'] ?? '')),
      'user' => trim((string)($post['fb_user'] ?? 'SYSDBA')),
      'pass' => $postedPassword !== '' ? $postedPassword : (string)($current['pass'] ?? ''),
      'charset' => trim((string)($post['fb_charset'] ?? 'UTF8')) ?: 'UTF8',
      'empresa_codigo' => max(0, (int)($post['fb_empresa_codigo'] ?? 0)),
      'tables' => [
        'products' => strtoupper(trim((string)($post['fb_table_products'] ?? 'CAD_PRODUTOS'))) ?: 'CAD_PRODUTOS',
        'groups' => strtoupper(trim((string)($post['fb_table_groups'] ?? 'CAD_GRUPO'))) ?: 'CAD_GRUPO',
      ],
    ];
    if ($cfg['host'] === '' || $cfg['database'] === '' || $cfg['user'] === '') {
      throw new RuntimeException('Informe servidor, caminho do banco de dados e usuário do Firebird.');
    }
    config_store_set($pdo, $companyId, 'firebird_import', $cfg);
    return product_import_config($pdo, $companyId);
  }
}

if (!function_exists('product_import_source_context')) {
  function product_import_source_context(PDO $pdoFb, array $cfg): array {
    $productsTable = fbr_ident((string)($cfg['tables']['products'] ?? 'CAD_PRODUTOS'));
    $groupsTable = fbr_ident((string)($cfg['tables']['groups'] ?? 'CAD_GRUPO'));
    if (!fbr_table_exists($pdoFb, $productsTable)) throw new RuntimeException('Tabela ' . $productsTable . ' não encontrada no Firebird.');

    $productFields = fbr_table_fields($pdoFb, $productsTable);
    $groupExists = fbr_table_exists($pdoFb, $groupsTable);
    $groupFields = $groupExists ? fbr_table_fields($pdoFb, $groupsTable) : [];
    $pmap = (array)($cfg['fields']['products'] ?? []);
    $gmap = (array)($cfg['fields']['groups'] ?? []);

    $required = ['code', 'description'];
    foreach ($required as $key) {
      $field = strtoupper(trim((string)($pmap[$key] ?? '')));
      if ($field === '' || !in_array($field, $productFields, true)) {
        throw new RuntimeException('Campo obrigatório de produto não encontrado no Firebird: ' . ($field ?: $key) . '.');
      }
    }

    return [
      'products_table' => $productsTable,
      'groups_table' => $groupsTable,
      'product_fields' => $productFields,
      'group_fields' => $groupFields,
      'group_exists' => $groupExists,
      'product_map' => $pmap,
      'group_map' => $gmap,
    ];
  }
}

if (!function_exists('product_import_source_where')) {
  function product_import_source_where(array $ctx, array $cfg, array &$params): string {
    $companyField = strtoupper(trim((string)($ctx['product_map']['company'] ?? 'EMPRESA')));
    $companyCode = (int)($cfg['empresa_codigo'] ?? 0);
    if ($companyCode > 0 && in_array($companyField, $ctx['product_fields'], true)) {
      $params[] = $companyCode;
      return ' WHERE p.' . fbr_ident($companyField) . ' = ?';
    }
    return '';
  }
}

if (!function_exists('product_import_source_select_sql')) {
  function product_import_source_select_sql(array $ctx, array $cfg, int $start = 0, int $limit = 0): array {
    $pmap = $ctx['product_map'];
    $productFields = $ctx['product_fields'];
    $select = [];
    $aliases = [
      'code' => 'SOURCE_CODE', 'description' => 'SOURCE_DESCRIPTION', 'group' => 'SOURCE_GROUP',
      'unit' => 'SOURCE_UNIT', 'barcode' => 'SOURCE_BARCODE', 'ncm' => 'SOURCE_NCM',
      'cest' => 'SOURCE_CEST', 'tax_code' => 'SOURCE_TAX_CODE', 'cost' => 'SOURCE_COST', 'price' => 'SOURCE_PRICE',
    ];
    foreach ($aliases as $key => $alias) {
      $field = strtoupper(trim((string)($pmap[$key] ?? '')));
      if ($field !== '' && in_array($field, $productFields, true)) {
        $select[] = 'p.' . fbr_ident($field) . ' AS ' . $alias;
      } else {
        $select[] = "NULL AS {$alias}";
      }
    }

    $join = '';
    $groupDescription = 'NULL AS GROUP_DESCRIPTION';
    $pGroup = strtoupper(trim((string)($pmap['group'] ?? 'GRUPO')));
    $gCode = strtoupper(trim((string)($ctx['group_map']['code'] ?? 'CODIGO')));
    $gDesc = strtoupper(trim((string)($ctx['group_map']['description'] ?? 'DESCRICAO')));
    if ($ctx['group_exists'] && in_array($pGroup, $productFields, true) && in_array($gCode, $ctx['group_fields'], true) && in_array($gDesc, $ctx['group_fields'], true)) {
      $join = ' LEFT JOIN ' . $ctx['groups_table'] . ' g ON g.' . fbr_ident($gCode) . ' = p.' . fbr_ident($pGroup);
      $pCompany = strtoupper(trim((string)($pmap['company'] ?? 'EMPRESA')));
      $gCompany = strtoupper(trim((string)($ctx['group_map']['company'] ?? 'EMPRESA')));
      if (in_array($pCompany, $productFields, true) && in_array($gCompany, $ctx['group_fields'], true)) {
        $join .= ' AND g.' . fbr_ident($gCompany) . ' = p.' . fbr_ident($pCompany);
      }
      $groupDescription = 'g.' . fbr_ident($gDesc) . ' AS GROUP_DESCRIPTION';
    }
    $select[] = $groupDescription;

    $params = [];
    $where = product_import_source_where($ctx, $cfg, $params);
    $orderField = fbr_ident(strtoupper(trim((string)($pmap['code'] ?? 'CODIGO'))));
    $sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . $ctx['products_table'] . ' p' . $join . $where . ' ORDER BY p.' . $orderField;
    if ($limit > 0) {
      $from = max(1, $start + 1);
      $to = $from + $limit - 1;
      $sql .= ' ROWS ' . $from . ' TO ' . $to;
    }
    return [$sql, $params];
  }
}

if (!function_exists('product_import_connect')) {
  function product_import_connect(array $cfg): PDO {
    if (!extension_loaded('pdo_firebird')) {
      throw new RuntimeException('A extensão PDO Firebird não está habilitada no servidor PHP.');
    }
    return fbr_pdo($cfg);
  }
}

if (!function_exists('product_import_test_connection')) {
  function product_import_test_connection(array $cfg): array {
    try {
      $pdoFb = product_import_connect($cfg);
      $ctx = product_import_source_context($pdoFb, $cfg);
      $params = [];
      $where = product_import_source_where($ctx, $cfg, $params);
      $st = $pdoFb->prepare('SELECT COUNT(*) FROM ' . $ctx['products_table'] . ' p' . $where);
      $st->execute($params);
      return ['ok' => true, 'count' => (int)$st->fetchColumn(), 'message' => 'Conexão realizada e tabela de produtos localizada.'];
    } catch (Throwable $e) {
      return ['ok' => false, 'error' => $e->getMessage()];
    }
  }
}

if (!function_exists('product_import_source_summary')) {
  function product_import_source_summary(array $cfg, int $previewLimit = 30): array {
    $pdoFb = product_import_connect($cfg);
    $ctx = product_import_source_context($pdoFb, $cfg);
    $params = [];
    $where = product_import_source_where($ctx, $cfg, $params);
    $st = $pdoFb->prepare('SELECT COUNT(*) FROM ' . $ctx['products_table'] . ' p' . $where);
    $st->execute($params);
    $count = (int)$st->fetchColumn();

    [$sql, $previewParams] = product_import_source_select_sql($ctx, $cfg, 0, max(1, min(100, $previewLimit)));
    $st = $pdoFb->prepare($sql);
    $st->execute($previewParams);
    $rows = product_import_clean_source_rows($st->fetchAll(PDO::FETCH_ASSOC) ?: []);

    $taxCodes = [];
    $taxField = strtoupper(trim((string)($ctx['product_map']['tax_code'] ?? 'COD_TRIBUTACAO')));
    if ($taxField !== '' && in_array($taxField, $ctx['product_fields'], true)) {
      $taxSql = 'SELECT DISTINCT p.' . fbr_ident($taxField) . ' AS SOURCE_TAX_CODE FROM ' . $ctx['products_table'] . ' p' . $where . ' ORDER BY p.' . fbr_ident($taxField);
      $stTax = $pdoFb->prepare($taxSql);
      $stTax->execute($params);
      foreach (($stTax->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $r = product_import_clean_source_row($r);
        $code = trim((string)($r['SOURCE_TAX_CODE'] ?? reset($r) ?? ''));
        if ($code !== '') $taxCodes[] = $code;
      }
    }

    return ['count' => $count, 'rows' => $rows, 'tax_codes' => array_values(array_unique($taxCodes)), 'context' => $ctx];
  }
}

if (!function_exists('product_import_tax_mapping')) {
  function product_import_tax_mapping(PDO $pdo, int $companyId): array {
    product_import_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT source_tax_code, target_tax_profile_id FROM product_import_tax_map WHERE company_id=? ORDER BY source_tax_code');
    $st->execute([$companyId]);
    $out = [];
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) $out[(string)$r['source_tax_code']] = (int)($r['target_tax_profile_id'] ?? 0);
    return $out;
  }
}

if (!function_exists('product_import_save_tax_mapping')) {
  function product_import_save_tax_mapping(PDO $pdo, int $companyId, array $map): void {
    product_import_ensure_schema($pdo);
    $validProfiles = [];
    foreach (fiscal_tax_profiles($pdo, $companyId, false, 'PRODUCT') as $p) $validProfiles[(int)$p['id']] = true;
    $st = $pdo->prepare('INSERT INTO product_import_tax_map (company_id, source_tax_code, target_tax_profile_id, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE target_tax_profile_id=VALUES(target_tax_profile_id), updated_at=NOW()');
    foreach ($map as $source => $targetRaw) {
      $source = trim((string)$source);
      if ($source === '') continue;
      $target = (int)$targetRaw;
      $st->execute([$companyId, $source, ($target > 0 && isset($validProfiles[$target])) ? $target : null]);
    }
  }
}

if (!function_exists('product_import_find_existing')) {
  function product_import_find_existing(PDO $pdo, int $companyId, string $sourceCode, string $barcode, bool $hasDirectCode, bool $hasNumber): int {
    if ($hasDirectCode) {
      $st = $pdo->prepare('SELECT id FROM produtos WHERE company_id=? AND codigo_firebird=? LIMIT 1');
      $st->execute([$companyId, $sourceCode]);
      $id = (int)($st->fetchColumn() ?: 0);
      if ($id > 0) return $id;
    }
    $st = $pdo->prepare('SELECT produto_id FROM produtos_firebird_map WHERE company_id=? AND codigo_firebird=? LIMIT 1');
    $st->execute([$companyId, $sourceCode]);
    $id = (int)($st->fetchColumn() ?: 0);
    if ($id > 0) return $id;

    if ($hasNumber && trim($sourceCode) !== '') {
      $st = $pdo->prepare('SELECT id FROM produtos WHERE company_id=? AND numero_cadastro=? LIMIT 1');
      $st->execute([$companyId, product_import_substr(trim($sourceCode), 60)]);
      $id = (int)($st->fetchColumn() ?: 0);
      if ($id > 0) return $id;
    }
    if ($barcode !== '' && product_import_table_has_column($pdo, 'produtos', 'codigo_barras')) {
      $st = $pdo->prepare('SELECT id FROM produtos WHERE company_id=? AND codigo_barras=? LIMIT 1');
      $st->execute([$companyId, $barcode]);
      $id = (int)($st->fetchColumn() ?: 0);
      if ($id > 0) return $id;
    }
    return 0;
  }
}

if (!function_exists('product_import_apply_tax_profile')) {
  /**
   * Aplica uma regra fiscal somente quando existe um vínculo válido da empresa.
   * Sem mapeamento, preserva a tributação já cadastrada e atualiza apenas NCM/CEST
   * quando esses campos vierem preenchidos na origem.
   */
  function product_import_apply_tax_profile(PDO $pdo, int $companyId, int $productId, int $profileId, string $ncm, string $cest): void {
    if ($companyId <= 0 || $productId <= 0) return;

    $profile = $profileId > 0 ? fiscal_tax_profile($pdo, $companyId, $profileId) : null;
    if ($profileId > 0 && !$profile) {
      throw new RuntimeException('A tributação vinculada não pertence à empresa atual ou não existe mais.');
    }

    // Sem regra mapeada: jamais sobrescreve tax_profile_id nem os demais campos fiscais.
    if (!$profile) {
      $updates = [];
      $values = [];
      if ($ncm !== '') { $updates[] = 'ncm=?'; $values[] = $ncm; }
      if ($cest !== '') { $updates[] = 'cest=?'; $values[] = $cest; }
      if (!$updates) return;

      $check = $pdo->prepare('SELECT id FROM fiscal_product_tax WHERE company_id=? AND product_id=? LIMIT 1');
      $check->execute([$companyId, $productId]);
      if ($check->fetchColumn()) {
        $values[] = $companyId;
        $values[] = $productId;
        $pdo->prepare('UPDATE fiscal_product_tax SET ' . implode(', ', $updates) . ', updated_at=NOW() WHERE company_id=? AND product_id=?')->execute($values);
        return;
      }

      // Produto novo sem mapeamento: cria somente o registro mínimo, sem inventar tributação.
      $post = [
        'fiscal_item_type' => 'PRODUCT',
        'fiscal_tax_profile_id' => 0,
        'fiscal_ncm' => $ncm,
        'fiscal_cest' => $cest,
      ];
      fiscal_save_product_tax_from_post($pdo, $companyId, $productId, $post);
      return;
    }

    $post = [
      'fiscal_item_type' => 'PRODUCT',
      'fiscal_tax_profile_id' => $profileId,
      'fiscal_ncm' => $ncm,
      'fiscal_cest' => $cest,
      'fiscal_origem' => (string)($profile['origem'] ?? '0'),
      'fiscal_tax_mode' => (string)($profile['tax_mode'] ?? ''),
      'fiscal_icms_rate' => $profile['icms_rate'] ?? 0,
      'fiscal_icms_st_rate' => $profile['icms_st_rate'] ?? 0,
      'fiscal_icms_cst' => (string)($profile['icms_cst'] ?? ''),
      'fiscal_icms_csosn' => (string)($profile['icms_csosn'] ?? ''),
      'fiscal_ipi_rate' => $profile['ipi_rate'] ?? 0,
      'fiscal_ipi_cst' => (string)($profile['ipi_cst'] ?? ''),
      'fiscal_pis_rate' => $profile['pis_out_rate'] ?? 0,
      'fiscal_pis_cst' => (string)($profile['pis_out_cst'] ?? ''),
      'fiscal_cofins_rate' => $profile['cofins_out_rate'] ?? 0,
      'fiscal_cofins_cst' => (string)($profile['cofins_out_cst'] ?? ''),
      'fiscal_cbs_rate' => $profile['cbs_rate'] ?? 0,
      'fiscal_ibs_uf_rate' => $profile['ibs_uf_rate'] ?? 0,
      'fiscal_ibs_mun_rate' => $profile['ibs_mun_rate'] ?? 0,
      'fiscal_ibs_cbs_rate_mode' => (string)($profile['ibs_cbs_rate_mode'] ?? 'AUTO'),
      'fiscal_ibs_cbs_cst' => (string)($profile['ibs_cbs_cst'] ?? ''),
      'fiscal_ibs_cbs_classification_code' => (string)($profile['ibs_cbs_classification_code'] ?? ''),
      'fiscal_ibs_cbs_classification_name' => (string)($profile['ibs_cbs_classification_name'] ?? ''),
      'fiscal_cfop_sale_state' => (string)($profile['cfop_sale_state'] ?? ''),
      'fiscal_cfop_sale_interstate' => (string)($profile['cfop_sale_interstate'] ?? ''),
      'fiscal_cfop_return_state' => (string)($profile['cfop_return_state'] ?? ''),
      'fiscal_cfop_return_interstate' => (string)($profile['cfop_return_interstate'] ?? ''),
      'fiscal_cfop_entry_state' => (string)($profile['cfop_entry_state'] ?? ''),
      'fiscal_cfop_entry_interstate' => (string)($profile['cfop_entry_interstate'] ?? ''),
    ];
    fiscal_save_product_tax_from_post($pdo, $companyId, $productId, $post);
    if (function_exists('fiscal_xml_sync_product_operation_cfops_from_profile')) {
      fiscal_xml_sync_product_operation_cfops_from_profile($pdo, $companyId, $productId, $profileId);
    }
  }
}

if (!function_exists('product_import_apply_company_code')) {
  /**
   * Grava no numero_cadastro o código operacional exato da origem.
   * O campo pertence ao contexto da empresa e não é o ID interno do banco.
   */
  function product_import_apply_company_code(PDO $pdo, int $companyId, int $productId, string $sourceCode): string {
    $code = product_import_substr(trim($sourceCode), 60);
    if ($code === '') throw new RuntimeException('Código do produto vazio na origem.');
    $st = $pdo->prepare('SELECT id FROM produtos WHERE company_id=? AND numero_cadastro=? AND id<>? LIMIT 1');
    $st->execute([$companyId, $code, $productId]);
    $collisionId = (int)($st->fetchColumn() ?: 0);
    if ($collisionId > 0) {
      throw new RuntimeException('O código ' . $code . ' já pertence a outro produto desta empresa (#' . $collisionId . ').');
    }
    return $code;
  }
}

if (!function_exists('product_import_sync_sequence')) {
  function product_import_sync_sequence(PDO $pdo, int $companyId): void {
    if (!company_sequence_has_column($pdo, 'produtos')) return;
    try {
      $st = $pdo->prepare("SELECT COALESCE(MAX(CASE WHEN numero_cadastro REGEXP '^[0-9]{1,10}$' AND CAST(numero_cadastro AS UNSIGNED) <= 4294967294 THEN CAST(numero_cadastro AS UNSIGNED) ELSE 0 END),0)+1 FROM produtos WHERE company_id=?");
      $st->execute([$companyId]);
      $next = max(1, (int)$st->fetchColumn());
      $pdo->prepare("INSERT INTO company_sequences (company_id, entity, next_value) VALUES (?, 'produtos', ?) ON DUPLICATE KEY UPDATE next_value=GREATEST(next_value, VALUES(next_value))")
        ->execute([$companyId, $next]);
    } catch (Throwable $e) {}
  }
}

if (!function_exists('product_import_execute')) {
  function product_import_execute(PDO $pdo, int $companyId, array $cfg, array $options = []): array {
    product_import_ensure_schema($pdo);
    $createNew = !array_key_exists('create_new', $options) || !empty($options['create_new']);
    $updateExisting = !array_key_exists('update_existing', $options) || !empty($options['update_existing']);
    if (!$createNew && !$updateExisting) {
      throw new RuntimeException('Selecione ao menos uma opção: criar produtos novos ou atualizar produtos existentes.');
    }
    $chunkSize = max(50, min(1000, (int)($options['chunk_size'] ?? 300)));
    $pdoFb = product_import_connect($cfg);
    $ctx = product_import_source_context($pdoFb, $cfg);

    $params = [];
    $where = product_import_source_where($ctx, $cfg, $params);
    $stCount = $pdoFb->prepare('SELECT COUNT(*) FROM ' . $ctx['products_table'] . ' p' . $where);
    $stCount->execute($params);
    $total = (int)$stCount->fetchColumn();

    $taxMap = product_import_tax_mapping_for_source($pdo, $companyId, 'PROSPERO_FIREBIRD');
    $profiles = [];
    foreach (fiscal_tax_profiles($pdo, $companyId, false, 'PRODUCT') as $profile) $profiles[(int)$profile['id']] = $profile;

    $hasDirectCode = product_import_table_has_column($pdo, 'produtos', 'codigo_firebird');
    $hasNumber = company_sequence_has_column($pdo, 'produtos');
    if ($hasNumber && !product_import_company_code_ready($pdo)) {
      throw new RuntimeException('Aplique primeiro a migration V11.45.5 — Código operacional do produto. O campo numero_cadastro ainda está no formato numérico antigo.');
    }
    $hasBarcode = product_import_table_has_column($pdo, 'produtos', 'codigo_barras');
    $hasCost = product_import_table_has_column($pdo, 'produtos', 'custo');
    $hasUnit = product_import_table_has_column($pdo, 'produtos', 'unidade_estoque');
    $hasReference = product_import_table_has_column($pdo, 'produtos', 'referencia');

    $run = $pdo->prepare('INSERT INTO product_import_runs (company_id,status,source_database,source_company_code,total_source) VALUES (?,\'RUNNING\',?,?,?)');
    $run->execute([$companyId, substr((string)($cfg['database'] ?? ''), 0, 500), (int)($cfg['empresa_codigo'] ?? 0), $total]);
    $runId = (int)$pdo->lastInsertId();

    $stats = ['total' => $total, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'run_id' => $runId, 'messages' => []];
    try {
      for ($offset = 0; $offset < $total; $offset += $chunkSize) {
        [$sql, $sourceParams] = product_import_source_select_sql($ctx, $cfg, $offset, $chunkSize);
        $stSource = $pdoFb->prepare($sql);
        $stSource->execute($sourceParams);
        $rows = product_import_clean_source_rows($stSource->fetchAll(PDO::FETCH_ASSOC) ?: []);
        if (!$rows) break;

        $chunkStats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
        $pdo->beginTransaction();
        try {
          foreach ($rows as $row) {
            $sourceCode = trim(product_import_clean_utf8($row['SOURCE_CODE'] ?? ''));
            $name = trim(product_import_clean_utf8($row['SOURCE_DESCRIPTION'] ?? ''));
            if ($sourceCode === '' || $name === '') {
              $chunkStats['skipped']++;
              continue;
            }
            $barcode = product_import_resolve_barcode($row['SOURCE_BARCODE'] ?? '', $sourceCode);
            $category = trim(product_import_clean_utf8($row['GROUP_DESCRIPTION'] ?? ''));
            if ($category === '') $category = trim(product_import_clean_utf8($row['SOURCE_GROUP'] ?? ''));
            $unit = stock_unit_clean(product_import_clean_utf8($row['SOURCE_UNIT'] ?? 'UN')) ?: 'UN';
            $ncm = product_import_digits($row['SOURCE_NCM'] ?? '', 12);
            $cest = product_import_digits($row['SOURCE_CEST'] ?? '', 12);
            $sourceTax = trim(product_import_clean_utf8($row['SOURCE_TAX_CODE'] ?? ''));
            $cost = product_import_decimal($row['SOURCE_COST'] ?? 0);
            $price = product_import_decimal($row['SOURCE_PRICE'] ?? 0);
            $reference = trim((string)($row['SOURCE_REFERENCE'] ?? ''));
            $productId = product_import_find_existing_for_source($pdo, $companyId, 'PROSPERO_FIREBIRD', $sourceCode, $barcode, $hasDirectCode, $hasNumber);

            $data = [
              'nome' => product_import_substr($name, 255),
              'descricao' => $name,
              'categoria' => product_import_substr($category, 150),
              'preco' => $price,
              'ativo' => 1,
            ];
            if ($hasDirectCode) $data['codigo_firebird'] = product_import_substr($sourceCode, 60);
            if ($hasBarcode) $data['codigo_barras'] = $barcode !== '' ? $barcode : null;
            if ($hasCost) $data['custo'] = $cost;
            if ($hasUnit) $data['unidade_estoque'] = $unit;
            if ($hasReference) $data['referencia'] = $reference !== '' ? product_import_substr($reference, 180) : null;

            $currentCategory = null;
            if ($productId > 0) {
              try {
                $stCurrentCategory = $pdo->prepare('SELECT categoria FROM produtos WHERE company_id=? AND id=? LIMIT 1');
                $stCurrentCategory->execute([$companyId, $productId]);
                $currentCategory = $stCurrentCategory->fetchColumn();
              } catch (Throwable $e) { $currentCategory = null; }
            }

            if ($productId > 0) {
              if (!$updateExisting) { $chunkStats['skipped']++; continue; }
              if ($currentCategory !== null && trim((string)$currentCategory) !== trim((string)$data['categoria'])) {
                product_import_apply_visibility_defaults($pdo, $companyId, (string)$data['categoria'], $data, $productId);
              }
              if ($hasNumber) {
                $data['numero_cadastro'] = product_import_apply_company_code($pdo, $companyId, $productId, $sourceCode);
              }
              $sets = [];
              $vals = [];
              foreach ($data as $col => $value) { $sets[] = '`' . $col . '`=?'; $vals[] = $value; }
              $vals[] = $companyId; $vals[] = $productId;
              $pdo->prepare('UPDATE produtos SET ' . implode(',', $sets) . ' WHERE company_id=? AND id=?')->execute($vals);
              $chunkStats['updated']++;
            } else {
              if (!$createNew) { $chunkStats['skipped']++; continue; }
              product_import_apply_visibility_defaults($pdo, $companyId, (string)$data['categoria'], $data);
              $insert = ['company_id' => $companyId] + $data;
              if ($hasNumber) {
                $insert['numero_cadastro'] = product_import_apply_company_code($pdo, $companyId, 0, $sourceCode);
              }
              $cols = array_keys($insert);
              $pdo->prepare('INSERT INTO produtos (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($insert));
              $productId = (int)$pdo->lastInsertId();
              $chunkStats['inserted']++;
            }

            $pdo->prepare('INSERT INTO produtos_firebird_map (company_id,produto_id,codigo_firebird) VALUES (?,?,?) ON DUPLICATE KEY UPDATE codigo_firebird=VALUES(codigo_firebird)')
              ->execute([$companyId, $productId, product_import_substr($sourceCode, 60)]);
            product_import_save_product_source_map($pdo, $companyId, 'PROSPERO_FIREBIRD', $sourceCode, $productId);

            $profileId = (int)($taxMap[$sourceTax] ?? 0);
            if ($profileId > 0 && !isset($profiles[$profileId])) $profileId = 0;
            product_import_apply_tax_profile($pdo, $companyId, $productId, $profileId, $ncm, $cest);
          }
          if (!$pdo->inTransaction()) {
            throw new RuntimeException('A transação do lote foi encerrada inesperadamente por uma operação estrutural do banco.');
          }
          $pdo->commit();
          foreach ($chunkStats as $key => $value) $stats[$key] += $value;
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          $stats['errors'] += count($rows);
          $stats['messages'][] = 'Lote iniciado em ' . ($offset + 1) . ': ' . $e->getMessage();
        }
      }
      product_import_sync_sequence($pdo, $companyId);
      $status = $stats['errors'] > 0 ? 'PARTIAL' : 'COMPLETED';
      $pdo->prepare('UPDATE product_import_runs SET status=?,inserted_count=?,updated_count=?,skipped_count=?,error_count=?,details_json=?,finished_at=NOW() WHERE company_id=? AND id=?')
        ->execute([$status, $stats['inserted'], $stats['updated'], $stats['skipped'], $stats['errors'], json_encode($stats['messages'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $companyId, $runId]);
      return $stats;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $stats['messages'][] = $e->getMessage();
      $stats['errors']++;
      $pdo->prepare('UPDATE product_import_runs SET status=\'FAILED\',inserted_count=?,updated_count=?,skipped_count=?,error_count=?,details_json=?,finished_at=NOW() WHERE company_id=? AND id=?')
        ->execute([$stats['inserted'], $stats['updated'], $stats['skipped'], $stats['errors'], json_encode($stats['messages'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $companyId, $runId]);
      throw $e;
    }
  }
}


if (!function_exists('product_import_extended_ensure_schema')) {
  function product_import_extended_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_import_tax_map_sources (
      company_id BIGINT NOT NULL,
      source_type VARCHAR(40) NOT NULL,
      source_tax_code VARCHAR(60) NOT NULL,
      target_tax_profile_id INT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (company_id, source_type, source_tax_code),
      KEY idx_product_import_tax_sources_profile (company_id, source_type, target_tax_profile_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS product_import_product_map (
      company_id BIGINT NOT NULL,
      source_type VARCHAR(40) NOT NULL,
      source_code VARCHAR(80) NOT NULL,
      product_id BIGINT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (company_id, source_type, source_code),
      KEY idx_product_import_source_product (company_id, source_type, product_id),
      KEY idx_product_import_product (company_id, product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS product_import_files (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL,
      source_type VARCHAR(40) NOT NULL,
      token CHAR(64) NOT NULL,
      original_name VARCHAR(255) NOT NULL,
      stored_path VARCHAR(500) NOT NULL,
      extension VARCHAR(12) NOT NULL,
      sheet_name VARCHAR(180) NULL,
      row_count INT NOT NULL DEFAULT 0,
      status VARCHAR(20) NOT NULL DEFAULT 'READY',
      created_by BIGINT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      expires_at DATETIME NULL,
      last_imported_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_product_import_file_token (token),
      KEY idx_product_import_files_company (company_id, created_at),
      KEY idx_product_import_files_expire (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!product_import_table_has_column($pdo, 'product_import_runs', 'source_type')) {
      try { $pdo->exec("ALTER TABLE product_import_runs ADD COLUMN source_type VARCHAR(40) NOT NULL DEFAULT 'PROSPERO_FIREBIRD' AFTER company_id"); } catch (Throwable $e) {}
    }
    if (!product_import_table_has_column($pdo, 'product_import_runs', 'source_file_name')) {
      try { $pdo->exec("ALTER TABLE product_import_runs ADD COLUMN source_file_name VARCHAR(255) NULL AFTER source_database"); } catch (Throwable $e) {}
    }

    try {
      $pdo->exec("INSERT IGNORE INTO product_import_tax_map_sources
        (company_id, source_type, source_tax_code, target_tax_profile_id, created_at, updated_at)
        SELECT company_id, 'PROSPERO_FIREBIRD', source_tax_code, target_tax_profile_id, created_at, updated_at
        FROM product_import_tax_map");
    } catch (Throwable $e) {}
  }
}

if (!function_exists('product_import_source_type')) {
  function product_import_source_type(string $sourceType): string {
    $sourceType = strtoupper(trim($sourceType));
    return preg_match('/^[A-Z0-9_]{2,40}$/', $sourceType) ? $sourceType : 'PROSPERO_FIREBIRD';
  }
}

if (!function_exists('product_import_source_label')) {
  function product_import_source_label(string $sourceType): string {
    return match (product_import_source_type($sourceType)) {
      'PROSPERO_EXCEL' => 'Planilha do Próspero ERP',
      'CPLUS4' => 'CPlus 4 / Planilha',
      'PROSPERO_FIREBIRD' => 'Próspero ERP / Firebird',
      default => ucwords(strtolower(str_replace('_', ' ', $sourceType))),
    };
  }
}

if (!function_exists('product_import_tax_mapping_for_source')) {
  function product_import_tax_mapping_for_source(PDO $pdo, int $companyId, string $sourceType): array {
    product_import_ensure_schema($pdo);
    $sourceType = product_import_source_type($sourceType);
    $st = $pdo->prepare('SELECT source_tax_code, target_tax_profile_id FROM product_import_tax_map_sources WHERE company_id=? AND source_type=? ORDER BY source_tax_code');
    $st->execute([$companyId, $sourceType]);
    $out = [];
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) $out[(string)$r['source_tax_code']] = (int)($r['target_tax_profile_id'] ?? 0);
    if ($sourceType === 'PROSPERO_FIREBIRD') {
      $legacy = product_import_tax_mapping($pdo, $companyId);
      return array_replace($legacy, $out);
    }
    if ($sourceType === 'PROSPERO_EXCEL') {
      $base = [];
      $stBase = $pdo->prepare("SELECT source_tax_code, target_tax_profile_id FROM product_import_tax_map_sources WHERE company_id=? AND source_type='PROSPERO_FIREBIRD'");
      $stBase->execute([$companyId]);
      foreach (($stBase->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) $base[(string)$r['source_tax_code']] = (int)($r['target_tax_profile_id'] ?? 0);
      return array_replace($base, $out);
    }
    return $out;
  }
}

if (!function_exists('product_import_save_tax_mapping_for_source')) {
  function product_import_save_tax_mapping_for_source(PDO $pdo, int $companyId, string $sourceType, array $map): void {
    product_import_ensure_schema($pdo);
    $sourceType = product_import_source_type($sourceType);
    $validProfiles = [];
    foreach (fiscal_tax_profiles($pdo, $companyId, false, 'PRODUCT') as $profile) $validProfiles[(int)$profile['id']] = true;
    $st = $pdo->prepare('INSERT INTO product_import_tax_map_sources (company_id, source_type, source_tax_code, target_tax_profile_id, updated_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE target_tax_profile_id=VALUES(target_tax_profile_id), updated_at=NOW()');
    foreach ($map as $source => $targetRaw) {
      $source = trim((string)$source);
      if ($source === '') continue;
      $target = (int)$targetRaw;
      $st->execute([$companyId, $sourceType, product_import_substr($source, 60), ($target > 0 && isset($validProfiles[$target])) ? $target : null]);
    }
    if ($sourceType === 'PROSPERO_FIREBIRD') product_import_save_tax_mapping($pdo, $companyId, $map);
  }
}

if (!function_exists('product_import_find_existing_for_source')) {
  function product_import_find_existing_for_source(PDO $pdo, int $companyId, string $sourceType, string $sourceCode, string $barcode, bool $hasDirectCode, bool $hasNumber): int {
    $sourceType = product_import_source_type($sourceType);
    try {
      $st = $pdo->prepare('SELECT product_id FROM product_import_product_map WHERE company_id=? AND source_type=? AND source_code=? LIMIT 1');
      $st->execute([$companyId, $sourceType, $sourceCode]);
      $id = (int)($st->fetchColumn() ?: 0);
      if ($id > 0) return $id;
    } catch (Throwable $e) {}
    if ($sourceType === 'CPLUS4') {
      // CPlus possui CODIGO comercial próprio; não correlacionar por codigo_firebird/barcode de outros ERPs.
      if (!$hasNumber) return 0;
      $st = $pdo->prepare('SELECT id FROM produtos WHERE company_id=? AND numero_cadastro=? LIMIT 1');
      $st->execute([$companyId, $sourceCode]);
      return (int)($st->fetchColumn() ?: 0);
    }
    return product_import_find_existing($pdo, $companyId, $sourceCode, $barcode, $hasDirectCode, $hasNumber);
  }
}

if (!function_exists('product_import_save_product_source_map')) {
  function product_import_save_product_source_map(PDO $pdo, int $companyId, string $sourceType, string $sourceCode, int $productId): void {
    if ($companyId <= 0 || $productId <= 0 || trim($sourceCode) === '') return;
    $sourceType = product_import_source_type($sourceType);
    $st = $pdo->prepare('INSERT INTO product_import_product_map (company_id, source_type, source_code, product_id, updated_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE product_id=VALUES(product_id), updated_at=NOW()');
    $st->execute([$companyId, $sourceType, product_import_substr(trim($sourceCode), 80), $productId]);
  }
}

if (!function_exists('product_import_storage_root')) {
  function product_import_storage_root(): string {
    $root = dirname(__DIR__) . '/storage/importacoes_produtos';
    if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) throw new RuntimeException('Não foi possível criar a pasta segura das planilhas de importação.');
    $deny = $root . '/.htaccess';
    if (!is_file($deny)) @file_put_contents($deny, "Require all denied\nDeny from all\n");
    return $root;
  }
}

if (!function_exists('product_import_excel_column_index')) {
  function product_import_excel_column_index(string $letters): int {
    $letters = strtoupper($letters);
    $value = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) $value = ($value * 26) + (ord($letters[$i]) - 64);
    return max(0, $value - 1);
  }
}

if (!function_exists('product_import_clean_utf8')) {
  /**
   * Normaliza textos recebidos de bases antigas do Próspero/Firebird.
   *
   * Algumas bases foram criadas com charset NONE/WIN1252. Nesses casos o
   * PDO Firebird pode devolver bytes Windows-1252 mesmo quando a conexão está
   * configurada como UTF8. Antes de gravar no MySQL (utf8mb4), convertemos os
   * bytes para UTF-8 e corrigimos padrões comuns de texto já corrompido.
   */
  function product_import_clean_utf8($value): string {
    $text = (string)$value;
    if ($text === '') return '';

    $isUtf8 = preg_match('//u', $text) === 1;

    // Corrige casos comuns como AÃ‡ÃƒO e REFEIÃ‡ÃƒO. A conversão
    // só é aceita se o resultado continuar UTF-8 válido e reduzir os marcadores.
    if ($isUtf8 && preg_match('/(?:Ã.|Â.|â.)/u', $text)) {
      $candidate = false;
      if (function_exists('mb_convert_encoding')) {
        $candidate = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
      } elseif (function_exists('iconv')) {
        // O resultado são os bytes originais anteriores ao mojibake.
        $candidate = @iconv('UTF-8', 'Windows-1252//IGNORE', $text);
      }
      if (is_string($candidate) && preg_match('//u', $candidate) === 1) {
        $before = preg_match_all('/(?:Ã.|Â.|â.)/u', $text, $m1);
        $after = preg_match_all('/(?:Ã.|Â.|â.)/u', $candidate, $m2);
        if ($before !== false && $after !== false && $after < $before) $text = $candidate;
      }
      $isUtf8 = preg_match('//u', $text) === 1;
    }

    if (!$isUtf8) {
      $converted = false;
      if (function_exists('mb_convert_encoding')) {
        // Windows-1252 cobre caracteres de bases legadas, inclusive Ç e Ã.
        $converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
      }
      if ((!is_string($converted) || preg_match('//u', $converted) !== 1) && function_exists('iconv')) {
        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $text);
      }
      if ((!is_string($converted) || preg_match('//u', $converted) !== 1) && function_exists('utf8_encode')) {
        $converted = @utf8_encode($text);
      }
      if (is_string($converted) && preg_match('//u', $converted) === 1) {
        $text = $converted;
      } elseif (function_exists('mb_convert_encoding')) {
        // Última proteção para que um byte inválido não cancele todo o lote.
        $text = (string)@mb_convert_encoding($text, 'UTF-8', 'UTF-8');
      }
    }

    // Remove NUL e controles sem utilidade em nomes e descrições.
    $text = str_replace("\0", '', $text);
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    return is_string($clean) ? $clean : $text;
  }
}

if (!function_exists('product_import_clean_source_row')) {
  /** Normaliza todos os campos textuais de uma linha lida do Firebird. */
  function product_import_clean_source_row(array $row): array {
    foreach ($row as $key => $value) {
      if (is_string($value)) $row[$key] = product_import_clean_utf8($value);
    }
    return $row;
  }
}

if (!function_exists('product_import_clean_source_rows')) {
  function product_import_clean_source_rows(array $rows): array {
    foreach ($rows as $index => $row) {
      if (is_array($row)) $rows[$index] = product_import_clean_source_row($row);
    }
    return $rows;
  }
}

if (!function_exists('product_import_normalize_header')) {
  function product_import_normalize_header($value): string {
    $text = trim(product_import_clean_utf8($value));
    if ($text === '') return '';
    if (function_exists('iconv')) {
      $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
      if ($converted !== false) $text = $converted;
    }
    $text = strtoupper($text);
    $text = preg_replace('/[^A-Z0-9]+/', '_', $text) ?? '';
    return trim($text, '_');
  }
}

if (!function_exists('product_import_excel_identifier')) {
  function product_import_excel_identifier($value): string {
    $text = trim((string)$value);
    if ($text === '') return '';
    if (preg_match('/^[+-]?[0-9]+(?:\.[0-9]+)?[Ee][+-]?[0-9]+$/', $text) && is_numeric($text)) {
      $text = sprintf('%.0f', (float)$text);
    } elseif (preg_match('/^([0-9]+)\.0+$/', $text, $m)) {
      $text = $m[1];
    }
    return $text;
  }
}

if (!function_exists('product_import_xlsx_sheet_map')) {
  function product_import_xlsx_sheet_map(ZipArchive $zip): array {
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relsXml === false) return [['name' => 'Planilha 1', 'target' => 'xl/worksheets/sheet1.xml']];
    $wb = @simplexml_load_string($workbookXml);
    $rels = @simplexml_load_string($relsXml);
    if (!$wb || !$rels) return [['name' => 'Planilha 1', 'target' => 'xl/worksheets/sheet1.xml']];
    $wb->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $wb->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $rels->registerXPathNamespace('pr', 'http://schemas.openxmlformats.org/package/2006/relationships');
    $relMap = [];
    foreach (($rels->xpath('//pr:Relationship') ?: []) as $rel) $relMap[(string)$rel['Id']] = (string)$rel['Target'];
    $out = [];
    foreach (($wb->xpath('//m:sheets/m:sheet') ?: []) as $sheet) {
      $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
      $rid = (string)($attrs['id'] ?? '');
      $target = $relMap[$rid] ?? '';
      if ($target === '') continue;
      if (str_starts_with($target, '/')) $target = ltrim($target, '/');
      elseif (!str_starts_with($target, 'xl/')) $target = 'xl/' . ltrim($target, '/');
      $out[] = ['name' => (string)$sheet['name'], 'target' => $target];
    }
    return $out ?: [['name' => 'Planilha 1', 'target' => 'xl/worksheets/sheet1.xml']];
  }
}

if (!function_exists('product_import_xlsx_matrix')) {
  function product_import_xlsx_matrix(string $path): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('A extensão PHP ZipArchive não está instalada. Instale php8.3-zip ou envie um arquivo CSV.');
    if (!function_exists('simplexml_load_string')) throw new RuntimeException('A extensão PHP SimpleXML não está instalada. Instale php8.3-xml.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Não foi possível abrir a planilha XLSX. Confirme se o arquivo não está corrompido.');
    try {
      $shared = [];
      $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
      if ($sharedXml !== false) {
        $sx = @simplexml_load_string($sharedXml);
        if ($sx) {
          $sx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
          foreach (($sx->xpath('//m:si') ?: []) as $item) {
            $item->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $text = '';
            foreach (($item->xpath('.//m:t') ?: []) as $part) $text .= (string)$part;
            $shared[] = $text;
          }
        }
      }
      $sheets = product_import_xlsx_sheet_map($zip);
      $selected = $sheets[0];
      foreach ($sheets as $sheet) {
        $normalized = product_import_normalize_header($sheet['name']);
        if (str_contains($normalized, 'PRODUTO')) { $selected = $sheet; break; }
      }
      $sheetXml = $zip->getFromName($selected['target']);
      if ($sheetXml === false) throw new RuntimeException('Não foi possível ler a aba de produtos da planilha.');
      $xml = @simplexml_load_string($sheetXml);
      if (!$xml) throw new RuntimeException('O XML interno da planilha está inválido.');
      $xml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
      $rows = [];
      foreach (($xml->xpath('//m:sheetData/m:row') ?: []) as $rowNode) {
        $rowNode->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $row = [];
        foreach (($rowNode->xpath('./m:c') ?: []) as $cell) {
          $cell->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
          preg_match('/^([A-Z]+)/i', (string)$cell['r'], $m);
          $col = product_import_excel_column_index($m[1] ?? 'A');
          $type = (string)$cell['t'];
          $value = '';
          if ($type === 'inlineStr') {
            foreach (($cell->xpath('./m:is//m:t') ?: []) as $part) $value .= (string)$part;
          } else {
            $values = $cell->xpath('./m:v');
            $raw = isset($values[0]) ? (string)$values[0] : '';
            if ($type === 's') $value = $shared[(int)$raw] ?? '';
            elseif ($type === 'b') $value = $raw === '1' ? '1' : '0';
            else $value = $raw;
          }
          $row[$col] = trim(product_import_clean_utf8($value));
        }
        if ($row) {
          ksort($row);
          $max = max(array_keys($row));
          $dense = [];
          for ($i = 0; $i <= $max; $i++) $dense[] = $row[$i] ?? '';
          $rows[] = $dense;
        }
        if (count($rows) > 100001) throw new RuntimeException('A planilha excede o limite de 100.000 produtos. Divida o arquivo em partes menores.');
      }
      return ['rows' => $rows, 'sheet' => (string)$selected['name'], 'sheets' => array_column($sheets, 'name')];
    } finally {
      $zip->close();
    }
  }
}

if (!function_exists('product_import_csv_matrix')) {
  function product_import_csv_matrix(string $path): array {
    $fh = fopen($path, 'rb');
    if (!$fh) throw new RuntimeException('Não foi possível abrir o arquivo CSV.');
    try {
      $first = fgets($fh);
      if ($first === false) throw new RuntimeException('O arquivo CSV está vazio.');
      $delimiters = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
      arsort($delimiters);
      $delimiter = (string)array_key_first($delimiters);
      if (($delimiters[$delimiter] ?? 0) === 0) $delimiter = ';';
      rewind($fh);
      $rows = [];
      while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
        if (isset($row[0])) $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$row[0]) ?? (string)$row[0];
        $rows[] = array_map(static fn($v) => trim(product_import_clean_utf8($v)), $row);
        if (count($rows) > 100001) throw new RuntimeException('O arquivo excede o limite de 100.000 produtos. Divida-o em partes menores.');
      }
      return ['rows' => $rows, 'sheet' => 'CSV', 'sheets' => ['CSV']];
    } finally {
      fclose($fh);
    }
  }
}

if (!function_exists('product_import_excel_matrix')) {
  function product_import_excel_matrix(string $path, string $extension): array {
    $extension = strtolower($extension);
    if (in_array($extension, ['xlsx', 'xlsm'], true)) return product_import_xlsx_matrix($path);
    if ($extension === 'csv') return product_import_csv_matrix($path);
    if ($extension === 'xls') throw new RuntimeException('O formato XLS antigo não é aceito. Abra o arquivo no Excel e salve como XLSX.');
    throw new RuntimeException('Formato não permitido. Envie XLSX, XLSM ou CSV.');
  }
}

if (!function_exists('product_import_excel_aliases')) {
  function product_import_excel_aliases(): array {
    return [
      'SOURCE_CODE' => ['CODIGO','COD_PRODUTO','CODIGO_PRODUTO','ID_PRODUTO','NUMERO'],
      'SOURCE_DESCRIPTION' => ['DESCRICAO','NOME','NOME_PRODUTO','PRODUTO'],
      'SOURCE_GROUP' => ['GRUPO','COD_GRUPO','CODIGO_GRUPO','CATEGORIA_CODIGO'],
      'GROUP_DESCRIPTION' => ['DESCRICAO_GRUPO','GRUPO_DESCRICAO','NOME_GRUPO','CATEGORIA'],
      'SOURCE_UNIT' => ['UNIDADE','UN','UNID'],
      'SOURCE_BARCODE' => ['CODIGO_BARRA','COD_BARRA','CODBARRA','EAN','EAN13','GTIN'],
      'SOURCE_NCM' => ['NCM','COD_NCM'],
      'SOURCE_CEST' => ['CEST','COD_CEST'],
      'SOURCE_TAX_CODE' => ['COD_TRIBUTACAO','COD_TRIB','TRIBUTACAO','REGRA_TRIBUTACAO'],
      'SOURCE_COST' => ['PRC_CUSTO','PRECO_CUSTO','CUSTO','VALOR_CUSTO'],
      'SOURCE_PRICE' => ['PRC_VENDA','PRECO_VENDA','PRECO','VALOR_VENDA'],
      'SOURCE_REFERENCE' => ['REFERENCIA','REFERENCIAS','REF','COD_REFERENCIA','CODIGO_REFERENCIA','REFERENCIA_PRODUTO'],
      'SOURCE_ACTIVE' => ['ATIVO','SITUACAO_ATIVO','STATUS_ATIVO'],
    ];
  }
}

if (!function_exists('product_import_parse_active')) {
  /** Retorna 1/0 para situação vinda de planilhas; valor ausente preserva comportamento anterior. */
  function product_import_parse_active($raw, bool $required = false): int {
    $v = strtoupper(trim((string)$raw));
    if ($v === '') {
      if ($required) throw new RuntimeException('A planilha CPlus 4 contém produto sem valor ATIVO. Informe 0 ou 1.');
      return 1;
    }
    if (in_array($v, ['1','S','SIM','ATIVO','TRUE'], true)) return 1;
    if (in_array($v, ['0','N','NAO','NÃO','INATIVO','FALSE'], true)) return 0;
    throw new RuntimeException('Valor de ATIVO inválido: ' . product_import_substr($v, 60) . '. Utilize 0 ou 1.');
  }
}

if (!function_exists('product_import_validate_cplus4_rows')) {
  function product_import_validate_cplus4_rows(array $normalized): void {
    if (!isset($normalized['column_map']['SOURCE_ACTIVE'])) throw new RuntimeException('A planilha CPlus 4 deve conter a coluna ATIVO (1 ou 0).');
    foreach ((array)($normalized['rows'] ?? []) as $index => $row) {
      try { product_import_parse_active($row['SOURCE_ACTIVE'] ?? '', true); }
      catch (Throwable $e) { throw new RuntimeException('Produto na posição ' . ($index+1) . ': ' . $e->getMessage(), 0, $e); }
    }
  }
}

if (!function_exists('product_import_excel_normalize_rows')) {
  function product_import_excel_normalize_rows(array $matrix): array {
    $rows = (array)($matrix['rows'] ?? []);
    if (!$rows) throw new RuntimeException('A planilha está vazia.');
    $aliases = product_import_excel_aliases();
    $headerIndex = -1;
    $columnMap = [];
    $maxHeaderSearch = min(20, count($rows));
    for ($i = 0; $i < $maxHeaderSearch; $i++) {
      $normalized = [];
      foreach ((array)$rows[$i] as $idx => $cell) $normalized[$idx] = product_import_normalize_header($cell);
      $candidate = [];
      foreach ($aliases as $target => $names) {
        foreach ($normalized as $idx => $name) {
          if ($name !== '' && in_array($name, $names, true)) { $candidate[$target] = (int)$idx; break; }
        }
      }
      if (isset($candidate['SOURCE_CODE'], $candidate['SOURCE_DESCRIPTION'])) {
        $headerIndex = $i;
        $columnMap = $candidate;
        break;
      }
    }
    if ($headerIndex < 0) throw new RuntimeException('Cabeçalho não localizado. A planilha precisa conter, no mínimo, as colunas CODIGO e DESCRICAO.');

    $normalizedRows = [];
    $blank = 0;
    $invalid = 0;
    $duplicates = 0;
    $seen = [];
    $warnings = [];
    $inferredBarcodes = 0;
    for ($i = $headerIndex + 1, $total = count($rows); $i < $total; $i++) {
      $source = (array)$rows[$i];
      $row = [];
      foreach ($aliases as $target => $_) {
        $idx = $columnMap[$target] ?? null;
        $row[$target] = $idx === null ? '' : trim((string)($source[$idx] ?? ''));
      }
      $row['SOURCE_CODE'] = product_import_excel_identifier($row['SOURCE_CODE'] ?? '');
      $row['SOURCE_BARCODE'] = product_import_excel_identifier($row['SOURCE_BARCODE'] ?? '');
      $row['SOURCE_NCM'] = product_import_excel_identifier($row['SOURCE_NCM'] ?? '');
      $row['SOURCE_CEST'] = product_import_excel_identifier($row['SOURCE_CEST'] ?? '');
      $row['SOURCE_TAX_CODE'] = product_import_excel_identifier($row['SOURCE_TAX_CODE'] ?? '');
      $row['SOURCE_REFERENCE'] = product_import_excel_identifier($row['SOURCE_REFERENCE'] ?? '');
      $code = trim((string)$row['SOURCE_CODE']);
      if ($row['SOURCE_BARCODE'] === '' && product_import_is_probable_barcode($code)) {
        $row['SOURCE_BARCODE'] = $code;
        $inferredBarcodes++;
      }
      $description = trim((string)$row['SOURCE_DESCRIPTION']);
      if ($code === '' && $description === '') { $blank++; continue; }
      if ($code === '' || $description === '') { $invalid++; continue; }
      if (isset($seen[$code])) { $duplicates++; continue; }
      $seen[$code] = true;
      if ($row['GROUP_DESCRIPTION'] === '') $row['GROUP_DESCRIPTION'] = $row['SOURCE_GROUP'];
      if ($row['SOURCE_UNIT'] === '') $row['SOURCE_UNIT'] = 'UN';
      $normalizedRows[] = $row;
    }
    if (!$normalizedRows) throw new RuntimeException('Nenhum produto válido foi encontrado após o cabeçalho.');
    if (!isset($columnMap['SOURCE_TAX_CODE'])) $warnings[] = 'A coluna COD_TRIBUTACAO não foi localizada; os produtos serão importados sem vínculo automático de tributação.';
    if (!isset($columnMap['GROUP_DESCRIPTION'])) $warnings[] = 'A coluna DESCRICAO_GRUPO não foi localizada; o conteúdo de GRUPO será usado como categoria.';
    if ($inferredBarcodes > 0) $warnings[] = $inferredBarcodes . ' código(s) numérico(s) com 8 a 20 dígitos foram reconhecidos também como código de barras porque a coluna CODIGO_BARRA estava vazia.';
    if ($duplicates > 0) $warnings[] = $duplicates . ' código(s) repetido(s) foram ignorados; foi considerada a primeira ocorrência.';
    if ($invalid > 0) $warnings[] = $invalid . ' linha(s) sem código ou descrição foram ignoradas.';
    return [
      'rows' => $normalizedRows,
      'header_row' => $headerIndex + 1,
      'column_map' => $columnMap,
      'blank_rows' => $blank,
      'invalid_rows' => $invalid,
      'duplicate_rows' => $duplicates,
      'warnings' => $warnings,
      'sheet' => (string)($matrix['sheet'] ?? 'Planilha 1'),
    ];
  }
}

if (!function_exists('product_import_excel_cleanup')) {
  function product_import_excel_cleanup(PDO $pdo, int $companyId): void {
    try {
      $st = $pdo->prepare("SELECT id, stored_path FROM product_import_files WHERE company_id=? AND expires_at IS NOT NULL AND expires_at<NOW() LIMIT 100");
      $st->execute([$companyId]);
      foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $path = (string)($row['stored_path'] ?? '');
        if ($path !== '') {
          if (is_file($path)) @unlink($path);
          $cache = $path . '.normalized.ser.gz';
          if (is_file($cache)) @unlink($cache);
          foreach ((array)glob($path . '.batch_*.json') as $stateFile) if (is_file($stateFile)) @unlink($stateFile);
        }
        $pdo->prepare('DELETE FROM product_import_files WHERE company_id=? AND id=?')->execute([$companyId, (int)$row['id']]);
      }
    } catch (Throwable $e) {}
  }
}

if (!function_exists('product_import_excel_write_cache')) {
  function product_import_excel_write_cache(string $storedPath, array $normalized): void {
    $cachePath = $storedPath . '.normalized.ser.gz';
    $serialized = serialize($normalized);
    $compressed = function_exists('gzencode') ? @gzencode($serialized, 5) : false;
    $payload = $compressed !== false ? ('GZ1' . $compressed) : ('RAW' . $serialized);
    $tmp = $cachePath . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
      @chmod($tmp, 0660);
      @rename($tmp, $cachePath);
    } else {
      @unlink($tmp);
    }
  }
}

if (!function_exists('product_import_excel_cached_normalized')) {
  function product_import_excel_cached_normalized(array $upload): array {
    $storedPath = (string)($upload['stored_path'] ?? '');
    $extension = (string)($upload['extension'] ?? '');
    if ($storedPath === '' || !is_file($storedPath)) throw new RuntimeException('O arquivo da planilha não está mais disponível. Envie-o novamente.');
    $cachePath = $storedPath . '.normalized.ser.gz';
    if (is_file($cachePath) && (int)@filemtime($cachePath) >= (int)@filemtime($storedPath)) {
      $payload = @file_get_contents($cachePath);
      $raw = false;
      if (is_string($payload) && str_starts_with($payload, 'GZ1') && function_exists('gzdecode')) $raw = @gzdecode(substr($payload, 3));
      elseif (is_string($payload) && str_starts_with($payload, 'RAW')) $raw = substr($payload, 3);
      $cached = is_string($raw) ? @unserialize($raw, ['allowed_classes' => false]) : false;
      if (is_array($cached) && isset($cached['rows']) && is_array($cached['rows'])) return $cached;
    }
    $normalized = product_import_excel_normalize_rows(product_import_excel_matrix($storedPath, $extension));
    product_import_excel_write_cache($storedPath, $normalized);
    return $normalized;
  }
}

if (!function_exists('product_import_excel_store_upload')) {
  function product_import_excel_store_upload(PDO $pdo, int $companyId, int $userId, array $file, string $sourceType = 'PROSPERO_EXCEL'): array {
    if (!in_array($sourceType, ['PROSPERO_EXCEL','CPLUS4'], true)) throw new RuntimeException('Origem da planilha inválida.');
    product_import_ensure_schema($pdo);
    product_import_excel_cleanup($pdo, $companyId);
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
      $messages = [UPLOAD_ERR_INI_SIZE => 'O arquivo excede o limite do servidor.', UPLOAD_ERR_FORM_SIZE => 'O arquivo excede o limite permitido.', UPLOAD_ERR_PARTIAL => 'O upload foi interrompido.', UPLOAD_ERR_NO_FILE => 'Selecione uma planilha para importar.'];
      throw new RuntimeException($messages[$error] ?? 'Falha no envio da planilha. Código: ' . $error . '.');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) throw new RuntimeException('A planilha enviada está vazia.');
    if ($size > 20 * 1024 * 1024) throw new RuntimeException('A planilha excede o limite de 20 MB.');
    $original = trim((string)($file['name'] ?? 'planilha.xlsx'));
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($extension, ['xlsx','xlsm','csv'], true)) throw new RuntimeException('Formato não permitido. Envie XLSX, XLSM ou CSV. Para arquivos XLS antigos, salve novamente como XLSX.');
    $token = bin2hex(random_bytes(32));
    $companyDir = product_import_storage_root() . '/empresa_' . $companyId;
    if (!is_dir($companyDir) && !@mkdir($companyDir, 0775, true) && !is_dir($companyDir)) throw new RuntimeException('Não foi possível preparar a pasta da empresa para a planilha.');
    $stored = $companyDir . '/' . $token . '.' . $extension;
    if (!is_uploaded_file((string)$file['tmp_name']) || !@move_uploaded_file((string)$file['tmp_name'], $stored)) throw new RuntimeException('Não foi possível armazenar a planilha enviada.');
    @chmod($stored, 0660);
    try {
      $normalized = product_import_excel_normalize_rows(product_import_excel_matrix($stored, $extension));
      if ($sourceType === 'CPLUS4') product_import_validate_cplus4_rows($normalized);
      product_import_excel_write_cache($stored, $normalized);
      $st = $pdo->prepare("INSERT INTO product_import_files (company_id, source_type, token, original_name, stored_path, extension, sheet_name, row_count, status, created_by, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'READY', ?, DATE_ADD(NOW(), INTERVAL 2 DAY))");
      $st->execute([$companyId, $sourceType, $token, product_import_substr($original, 255), $stored, $extension, product_import_substr((string)$normalized['sheet'], 180), count($normalized['rows']), $userId > 0 ? $userId : null]);
      return product_import_excel_get_upload($pdo, $companyId, $token);
    } catch (Throwable $e) {
      @unlink($stored);
      @unlink($stored . '.normalized.ser.gz');
      throw $e;
    }
  }
}

if (!function_exists('product_import_excel_get_upload')) {
  function product_import_excel_get_upload(PDO $pdo, int $companyId, string $token): array {
    product_import_ensure_schema($pdo);
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) throw new RuntimeException('Identificador da planilha inválido. Envie o arquivo novamente.');
    $st = $pdo->prepare('SELECT * FROM product_import_files WHERE company_id=? AND token=? LIMIT 1');
    $st->execute([$companyId, $token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('A planilha não foi localizada para esta empresa. Envie o arquivo novamente.');
    if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time()) throw new RuntimeException('A planilha expirou. Envie o arquivo novamente.');
    $root = realpath(product_import_storage_root());
    $path = realpath((string)$row['stored_path']);
    if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) throw new RuntimeException('O arquivo da planilha não está mais disponível. Envie-o novamente.');
    $row['stored_path'] = $path;
    return $row;
  }
}

if (!function_exists('product_import_excel_summary')) {
  function product_import_excel_summary(PDO $pdo, int $companyId, string $token, int $previewLimit = 40): array {
    $upload = product_import_excel_get_upload($pdo, $companyId, $token);
    $normalized = product_import_excel_cached_normalized($upload);
    $taxCodes = [];
    foreach ($normalized['rows'] as $row) {
      $code = trim((string)($row['SOURCE_TAX_CODE'] ?? ''));
      if ($code !== '') $taxCodes[$code] = true;
    }
    return [
      'count' => count($normalized['rows']),
      'rows' => array_slice($normalized['rows'], 0, max(1, min(100, $previewLimit))),
      'tax_codes' => array_keys($taxCodes),
      'upload' => $upload,
      'sheet' => $normalized['sheet'],
      'header_row' => $normalized['header_row'],
      'blank_rows' => $normalized['blank_rows'],
      'invalid_rows' => $normalized['invalid_rows'],
      'duplicate_rows' => $normalized['duplicate_rows'],
      'warnings' => $normalized['warnings'],
    ];
  }
}

if (!function_exists('product_import_execute_rows')) {
  function product_import_execute_rows(PDO $pdo, int $companyId, string $sourceType, array $rows, array $options = [], array $origin = []): array {
    product_import_ensure_schema($pdo);
    $sourceType = product_import_source_type($sourceType);
    $createNew = !array_key_exists('create_new', $options) || !empty($options['create_new']);
    $updateExisting = !array_key_exists('update_existing', $options) || !empty($options['update_existing']);
    if (!$createNew && !$updateExisting) throw new RuntimeException('Selecione ao menos uma opção: criar produtos novos ou atualizar produtos existentes.');

    $total = count($rows);
    if ($total <= 0) throw new RuntimeException('Nenhum produto válido foi encontrado para importar.');
    $rowOffset = max(0, min($total, (int)($options['offset'] ?? 0)));
    $maxRows = max(0, (int)($options['max_rows'] ?? 0));
    $maxSeconds = max(0.0, (float)($options['max_seconds'] ?? 0));
    $isBatchRequest = $maxRows > 0 || $maxSeconds > 0 || !empty($options['batch_mode']);
    $defaultChunk = $isBatchRequest ? 25 : 300;
    $chunkSize = max(10, min(1000, (int)($options['chunk_size'] ?? $defaultChunk)));
    $totalSource = max($total, (int)($options['total_source'] ?? $total));
    $startedAt = microtime(true);

    $taxMap = product_import_tax_mapping_for_source($pdo, $companyId, $sourceType);
    $profiles = [];
    foreach (fiscal_tax_profiles($pdo, $companyId, false, 'PRODUCT') as $profile) $profiles[(int)$profile['id']] = $profile;

    // Segurança fiscal: todos os códigos tributários da origem precisam estar vinculados
    // antes do primeiro lote. A validação é rápida quando a planilha já está em cache.
    $sourceTaxCodes = [];
    foreach ($rows as $rowForTaxValidation) {
      $codeForTaxValidation = trim((string)($rowForTaxValidation['SOURCE_TAX_CODE'] ?? ''));
      if ($codeForTaxValidation !== '') $sourceTaxCodes[$codeForTaxValidation] = true;
    }
    $unmappedTaxCodes = [];
    foreach (array_keys($sourceTaxCodes) as $sourceTaxCode) {
      $mappedProfileId = (int)($taxMap[$sourceTaxCode] ?? 0);
      if ($mappedProfileId <= 0 || !isset($profiles[$mappedProfileId])) $unmappedTaxCodes[] = $sourceTaxCode;
    }
    if ($unmappedTaxCodes) {
      sort($unmappedTaxCodes, SORT_NATURAL);
      throw new RuntimeException('Antes de importar, vincule os códigos de tributação da origem às regras fiscais desta empresa: ' . implode(', ', $unmappedTaxCodes) . '.');
    }

    // Todas as rotinas que podem executar DDL são preparadas antes das transações dos lotes.
    if (function_exists('fiscal_xml_ensure_schema')) fiscal_xml_ensure_schema($pdo);
    if (function_exists('fiscal_ensure_operation_cfop_schema')) fiscal_ensure_operation_cfop_schema($pdo);
    if (function_exists('company_sequence_ensure_schema')) company_sequence_ensure_schema($pdo, false);

    $hasDirectCode = product_import_table_has_column($pdo, 'produtos', 'codigo_firebird');
    $hasNumber = company_sequence_has_column($pdo, 'produtos');
    if ($hasNumber && !product_import_company_code_ready($pdo)) {
      throw new RuntimeException('Aplique primeiro a migration V11.45.5 — Código operacional do produto. O campo numero_cadastro ainda está no formato numérico antigo.');
    }
    $hasBarcode = product_import_table_has_column($pdo, 'produtos', 'codigo_barras');
    $hasCost = product_import_table_has_column($pdo, 'produtos', 'custo');
    $hasUnit = product_import_table_has_column($pdo, 'produtos', 'unidade_estoque');
    $hasReference = product_import_table_has_column($pdo, 'produtos', 'referencia');
    $sourceDatabase = product_import_substr((string)($origin['source_database'] ?? ''), 500);
    $sourceFileName = product_import_substr((string)($origin['source_file_name'] ?? ''), 255);

    $runId = max(0, (int)($options['run_id'] ?? 0));
    if ($runId > 0) {
      $check = $pdo->prepare('SELECT id,status,total_source FROM product_import_runs WHERE company_id=? AND id=? LIMIT 1');
      $check->execute([$companyId, $runId]);
      $runRow = $check->fetch(PDO::FETCH_ASSOC);
      if (!$runRow) throw new RuntimeException('A execução da importação não foi localizada. Reinicie a importação.');
      if ((int)($runRow['total_source'] ?? 0) !== $totalSource) throw new RuntimeException('A quantidade de produtos da planilha mudou durante a importação. Reinicie o processo.');
    } else {
      if (product_import_table_has_column($pdo, 'product_import_runs', 'source_type') && product_import_table_has_column($pdo, 'product_import_runs', 'source_file_name')) {
        $run = $pdo->prepare("INSERT INTO product_import_runs (company_id, source_type, status, source_database, source_file_name, source_company_code, total_source) VALUES (?, ?, 'RUNNING', ?, ?, ?, ?)");
        $run->execute([$companyId, $sourceType, $sourceDatabase, $sourceFileName !== '' ? $sourceFileName : null, (int)($origin['source_company_code'] ?? 0), $totalSource]);
      } else {
        $run = $pdo->prepare("INSERT INTO product_import_runs (company_id, status, source_database, source_company_code, total_source) VALUES (?, 'RUNNING', ?, ?, ?)");
        $run->execute([$companyId, $sourceFileName !== '' ? $sourceFileName : $sourceDatabase, (int)($origin['source_company_code'] ?? 0), $totalSource]);
      }
      $runId = (int)$pdo->lastInsertId();
    }

    $stats = [
      'total' => $totalSource,
      'inserted' => 0,
      'updated' => 0,
      'skipped' => 0,
      'errors' => 0,
      'run_id' => $runId,
      'messages' => [],
      'offset' => $rowOffset,
      'processed' => 0,
      'next_offset' => $rowOffset,
      'data_total' => $total,
      'done' => false,
      'elapsed_seconds' => 0.0,
    ];

    try {
      $cursor = $rowOffset;
      while ($cursor < $total) {
        if ($maxRows > 0 && $stats['processed'] >= $maxRows) break;
        if ($maxSeconds > 0 && $stats['processed'] > 0 && (microtime(true) - $startedAt) >= $maxSeconds) break;

        $take = min($chunkSize, $total - $cursor);
        if ($maxRows > 0) $take = min($take, $maxRows - $stats['processed']);
        if ($take <= 0) break;
        $chunk = array_slice($rows, $cursor, $take);
        $chunkStats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];

        $pdo->beginTransaction();
        try {
          foreach ($chunk as $row) {
            $sourceCode = trim((string)($row['SOURCE_CODE'] ?? ''));
            $name = trim((string)($row['SOURCE_DESCRIPTION'] ?? ''));
            if ($sourceCode === '' || $name === '') { $chunkStats['skipped']++; continue; }
            $barcode = product_import_resolve_barcode($row['SOURCE_BARCODE'] ?? '', $sourceCode);
            $category = trim((string)(($row['GROUP_DESCRIPTION'] ?? '') ?: ($row['SOURCE_GROUP'] ?? '')));
            $unit = stock_unit_clean($row['SOURCE_UNIT'] ?? 'UN') ?: 'UN';
            $ncm = product_import_digits($row['SOURCE_NCM'] ?? '', 12);
            $cest = product_import_digits($row['SOURCE_CEST'] ?? '', 12);
            $sourceTax = trim((string)($row['SOURCE_TAX_CODE'] ?? ''));
            $cost = product_import_decimal($row['SOURCE_COST'] ?? 0);
            $price = product_import_decimal($row['SOURCE_PRICE'] ?? 0);
            $reference = trim((string)($row['SOURCE_REFERENCE'] ?? ''));
            $productId = product_import_find_existing_for_source($pdo, $companyId, $sourceType, $sourceCode, $barcode, $hasDirectCode, $hasNumber);
            $data = ['nome' => product_import_substr($name, 255), 'descricao' => $name, 'categoria' => product_import_substr($category, 150), 'preco' => $price, 'ativo' => product_import_parse_active($row['SOURCE_ACTIVE'] ?? '', $sourceType === 'CPLUS4')];
            if ($hasDirectCode && $sourceType === 'PROSPERO_FIREBIRD') $data['codigo_firebird'] = product_import_substr($sourceCode, 60);
            if ($hasBarcode) $data['codigo_barras'] = $barcode !== '' ? $barcode : null;
            if ($hasCost) $data['custo'] = $cost;
            if ($hasUnit) $data['unidade_estoque'] = $unit;
            if ($hasReference) $data['referencia'] = $reference !== '' ? product_import_substr($reference, 180) : null;

            $currentCategory = null;
            if ($productId > 0) {
              try {
                $stCurrentCategory = $pdo->prepare('SELECT categoria FROM produtos WHERE company_id=? AND id=? LIMIT 1');
                $stCurrentCategory->execute([$companyId, $productId]);
                $currentCategory = $stCurrentCategory->fetchColumn();
              } catch (Throwable $e) { $currentCategory = null; }
            }

            if ($productId > 0) {
              if (!$updateExisting) { $chunkStats['skipped']++; continue; }
              if ($currentCategory !== null && trim((string)$currentCategory) !== trim((string)$data['categoria'])) {
                product_import_apply_visibility_defaults($pdo, $companyId, (string)$data['categoria'], $data, $productId);
              }
              if ($hasNumber) {
                $data['numero_cadastro'] = product_import_apply_company_code($pdo, $companyId, $productId, $sourceCode);
              }
              $sets = []; $values = [];
              foreach ($data as $column => $value) { $sets[] = '`' . $column . '`=?'; $values[] = $value; }
              $values[] = $companyId; $values[] = $productId;
              $pdo->prepare('UPDATE produtos SET ' . implode(',', $sets) . ' WHERE company_id=? AND id=?')->execute($values);
              $chunkStats['updated']++;
            } else {
              if (!$createNew) { $chunkStats['skipped']++; continue; }
              product_import_apply_visibility_defaults($pdo, $companyId, (string)$data['categoria'], $data);
              $insert = ['company_id' => $companyId] + $data;
              if ($hasNumber) {
                $insert['numero_cadastro'] = product_import_apply_company_code($pdo, $companyId, 0, $sourceCode);
              }
              $columns = array_keys($insert);
              $pdo->prepare('INSERT INTO produtos (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')')->execute(array_values($insert));
              $productId = (int)$pdo->lastInsertId();
              $chunkStats['inserted']++;
            }

            product_import_save_product_source_map($pdo, $companyId, $sourceType, $sourceCode, $productId);
            if ($sourceType === 'PROSPERO_FIREBIRD') {
              $pdo->prepare('INSERT INTO produtos_firebird_map (company_id, produto_id, codigo_firebird) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE codigo_firebird=VALUES(codigo_firebird)')->execute([$companyId, $productId, product_import_substr($sourceCode, 60)]);
            }
            $profileId = (int)($taxMap[$sourceTax] ?? 0);
            if ($profileId > 0 && !isset($profiles[$profileId])) $profileId = 0;
            product_import_apply_tax_profile($pdo, $companyId, $productId, $profileId, $ncm, $cest);
          }
          if (!$pdo->inTransaction()) throw new RuntimeException('A transação do lote foi encerrada inesperadamente por uma operação estrutural do banco.');
          $pdo->commit();
          foreach ($chunkStats as $key => $value) $stats[$key] += $value;
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          $stats['errors'] += count($chunk);
          if (count($stats['messages']) < 30) $stats['messages'][] = 'Lote iniciado em ' . ($cursor + 1) . ': ' . $e->getMessage();
        }

        $cursor += count($chunk);
        $stats['processed'] += count($chunk);
      }

      product_import_sync_sequence($pdo, $companyId);
      $stats['next_offset'] = min($total, $cursor);
      $stats['done'] = $stats['next_offset'] >= $total;
      $stats['elapsed_seconds'] = round(microtime(true) - $startedAt, 3);

      $current = $pdo->prepare('SELECT inserted_count,updated_count,skipped_count,error_count,details_json FROM product_import_runs WHERE company_id=? AND id=? LIMIT 1');
      $current->execute([$companyId, $runId]);
      $runCurrent = $current->fetch(PDO::FETCH_ASSOC) ?: [];
      $cumInserted = (int)($runCurrent['inserted_count'] ?? 0) + (int)$stats['inserted'];
      $cumUpdated = (int)($runCurrent['updated_count'] ?? 0) + (int)$stats['updated'];
      $cumSkipped = (int)($runCurrent['skipped_count'] ?? 0) + (int)$stats['skipped'];
      $cumErrors = (int)($runCurrent['error_count'] ?? 0) + (int)$stats['errors'];
      $previousMessages = json_decode((string)($runCurrent['details_json'] ?? ''), true);
      if (!is_array($previousMessages)) $previousMessages = [];
      $allMessages = array_slice(array_merge($previousMessages, $stats['messages']), -100);
      $status = $stats['done'] ? ($cumErrors > 0 ? 'PARTIAL' : 'COMPLETED') : 'RUNNING';
      $finishedAt = $stats['done'] ? date('Y-m-d H:i:s') : null;
      $pdo->prepare('UPDATE product_import_runs SET status=?, inserted_count=?, updated_count=?, skipped_count=?, error_count=?, details_json=?, finished_at=? WHERE company_id=? AND id=?')
        ->execute([$status, $cumInserted, $cumUpdated, $cumSkipped, $cumErrors, json_encode($allMessages, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $finishedAt, $companyId, $runId]);

      $stats['cumulative'] = ['inserted' => $cumInserted, 'updated' => $cumUpdated, 'skipped' => $cumSkipped, 'errors' => $cumErrors];
      return $stats;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      try {
        $pdo->prepare("UPDATE product_import_runs SET status=CASE WHEN status='COMPLETED' THEN status ELSE 'FAILED' END, details_json=?, finished_at=NOW() WHERE company_id=? AND id=?")
          ->execute([json_encode([$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $companyId, $runId]);
      } catch (Throwable $ignored) {}
      throw $e;
    }
  }
}

if (!function_exists('product_import_excel_batch_state_path')) {
  function product_import_excel_batch_state_path(array $upload, string $session): string {
    $session = strtolower(trim($session));
    if (!preg_match('/^[a-f0-9]{24,64}$/', $session)) throw new RuntimeException('Identificador da importação em lotes inválido. Atualize a página e tente novamente.');
    return (string)$upload['stored_path'] . '.batch_' . $session . '.json';
  }
}

if (!function_exists('product_import_excel_execute_batch')) {
  function product_import_excel_execute_batch(PDO $pdo, int $companyId, string $token, string $session, int $offset, array $options = []): array {
    $upload = product_import_excel_get_upload($pdo, $companyId, $token);
    $normalized = product_import_excel_cached_normalized($upload);
    $rows = (array)($normalized['rows'] ?? []);
    $sourceType = (string)($upload['source_type'] ?? 'PROSPERO_EXCEL');
    if (!in_array($sourceType, ['PROSPERO_EXCEL','CPLUS4'], true)) throw new RuntimeException('Origem de planilha inválida.');
    if ($sourceType === 'CPLUS4') product_import_validate_cplus4_rows($normalized);
    $total = count($rows);
    if ($total <= 0) throw new RuntimeException('Nenhum produto válido foi encontrado para importar.');
    $offset = max(0, min($total, $offset));
    $batchSize = max(20, min(250, (int)($options['max_rows'] ?? 150)));
    $batchSeconds = max(5.0, min(25.0, (float)($options['max_seconds'] ?? 12.0)));
    $statePath = product_import_excel_batch_state_path($upload, $session);
    $fh = @fopen($statePath, 'c+');
    if (!$fh) throw new RuntimeException('Não foi possível preparar o controle da importação em lotes.');

    try {
      if (!flock($fh, LOCK_EX)) throw new RuntimeException('Não foi possível bloquear o lote para processamento.');
      rewind($fh);
      $rawState = stream_get_contents($fh);
      $state = is_string($rawState) && trim($rawState) !== '' ? json_decode($rawState, true) : [];
      if (!is_array($state)) $state = [];
      $nextExpected = max(0, (int)($state['next_offset'] ?? 0));
      $lastOffset = isset($state['last_offset']) ? (int)$state['last_offset'] : -1;
      $lastResult = isset($state['last_result']) && is_array($state['last_result']) ? $state['last_result'] : null;

      // Se a resposta anterior se perdeu no caminho, devolve o mesmo resultado sem regravar produtos.
      if ($offset < $nextExpected && $offset === $lastOffset && $lastResult) {
        $lastResult['replayed'] = true;
        flock($fh, LOCK_UN);
        fclose($fh);
        return $lastResult;
      }
      if ($offset !== $nextExpected) throw new RuntimeException('O progresso da importação ficou fora de sequência. Atualize a página e reinicie a importação para continuar com segurança.');

      $runId = max(0, (int)($state['run_id'] ?? 0));
      $result = product_import_execute_rows($pdo, $companyId, $sourceType, $rows, [
        'create_new' => !empty($options['create_new']),
        'update_existing' => !empty($options['update_existing']),
        'offset' => $offset,
        'max_rows' => $batchSize,
        'max_seconds' => $batchSeconds,
        'chunk_size' => 25,
        'batch_mode' => true,
        'run_id' => $runId,
        'total_source' => $total,
      ], [
        'source_database' => 'PLANILHA:' . (string)$upload['sheet_name'],
        'source_file_name' => (string)$upload['original_name'],
        'source_company_code' => 0,
      ]);

      $fileStatus = !empty($result['done']) ? (((int)($result['cumulative']['errors'] ?? 0) > 0) ? 'PARTIAL' : 'IMPORTED') : 'IMPORTING';
      if (!empty($result['done'])) {
        $pdo->prepare('UPDATE product_import_files SET last_imported_at=NOW(), status=? WHERE company_id=? AND id=?')->execute([$fileStatus, $companyId, (int)$upload['id']]);
      } else {
        $pdo->prepare('UPDATE product_import_files SET status=? WHERE company_id=? AND id=?')->execute([$fileStatus, $companyId, (int)$upload['id']]);
      }

      $state = [
        'run_id' => (int)$result['run_id'],
        'next_offset' => (int)$result['next_offset'],
        'last_offset' => $offset,
        'last_result' => $result,
        'updated_at' => date('c'),
      ];
      rewind($fh);
      ftruncate($fh, 0);
      fwrite($fh, json_encode($state, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
      fflush($fh);
      @chmod($statePath, 0660);
      flock($fh, LOCK_UN);
      fclose($fh);
      return $result;
    } catch (Throwable $e) {
      @flock($fh, LOCK_UN);
      @fclose($fh);
      throw $e;
    }
  }
}

if (!function_exists('product_import_excel_execute')) {
  function product_import_excel_execute(PDO $pdo, int $companyId, string $token, array $options = []): array {
    $upload = product_import_excel_get_upload($pdo, $companyId, $token);
    $normalized = product_import_excel_cached_normalized($upload);
    $sourceType = (string)($upload['source_type'] ?? 'PROSPERO_EXCEL');
    if (!in_array($sourceType, ['PROSPERO_EXCEL','CPLUS4'], true)) throw new RuntimeException('Origem de planilha inválida.');
    if ($sourceType === 'CPLUS4') product_import_validate_cplus4_rows($normalized);
    $result = product_import_execute_rows($pdo, $companyId, $sourceType, $normalized['rows'], $options, [
      'source_database' => 'PLANILHA:' . (string)$upload['sheet_name'],
      'source_file_name' => (string)$upload['original_name'],
      'source_company_code' => 0,
    ]);
    $pdo->prepare('UPDATE product_import_files SET last_imported_at=NOW(), status=? WHERE company_id=? AND id=?')->execute([$result['errors'] > 0 ? 'PARTIAL' : 'IMPORTED', $companyId, (int)$upload['id']]);
    return $result;
  }
}

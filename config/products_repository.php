<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/pdo.php';

/**
 * Repositório de produtos (multi-tenant)
 *
 * Notas:
 *  - O projeto cria o PDO global ($pdo) em config/pdo.php.
 *  - Para evitar inconsistências e erros de sintaxe, usamos strings SQL simples
 *    e sempre filtramos por COMPANY_ID.
 */

function current_company_id(): int {
  return defined('COMPANY_ID') ? (int)COMPANY_ID : 0;
}

function products_catalog_runtime_version(): string {
  return '2026.10.23-hide-product-v1';
}

function products_send_dynamic_catalog_headers(): void {
  if (headers_sent()) return;
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
  header('Pragma: no-cache');
  header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
  header('X-Nexa-Catalog-Version: ' . products_catalog_runtime_version());
}

function products_has_column(PDO $pdo, string $column): bool {
  try {
    $st = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $st->execute(['produtos', $column]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    try {
      $st = $pdo->query("SHOW COLUMNS FROM produtos LIKE " . $pdo->quote($column));
      return $st && (bool)$st->fetch();
    } catch (Throwable $e2) {
      return false;
    }
  }
}


function products_barcode_columns(PDO $pdo): array {
  $cols = [];
  foreach (['codigo_barras','ean','gtin','barcode'] as $col) {
    if (products_has_column($pdo, $col)) $cols[] = $col;
  }
  return array_values(array_unique($cols));
}

function products_ensure_order_schema(): array {
  /** @var PDO $pdo */
  global $pdo;

  static $cols = null;
  if (is_array($cols)) return $cols;

  $cols = [
    'sort_order' => false,
    'category_sort_order' => false,
    'category_hide_order_catalog' => false,
    'category_hide_product_catalog' => false,
    'hide_order_catalog_product' => false,
  ];
  if (!$pdo instanceof PDO) return $cols;

  $cols['sort_order'] = products_has_column($pdo, 'sort_order');
  $cols['category_sort_order'] = products_has_column($pdo, 'category_sort_order');
  $cols['category_hide_order_catalog'] = products_has_column($pdo, 'category_hide_order_catalog');
  $cols['category_hide_product_catalog'] = products_has_column($pdo, 'category_hide_product_catalog');
  // Nova configuração individual é criada exclusivamente pelo Atualizador do Banco.
  $cols['hide_order_catalog_product'] = products_has_column($pdo, 'hide_order_catalog_product');

  if (!$pdo->inTransaction()) {
    if (!$cols['sort_order']) {
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER ativo'); } catch (Throwable $e) {}
      $cols['sort_order'] = products_has_column($pdo, 'sort_order');
    }
    if (!$cols['category_sort_order']) {
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN category_sort_order INT NOT NULL DEFAULT 0 AFTER sort_order'); } catch (Throwable $e) {}
      $cols['category_sort_order'] = products_has_column($pdo, 'category_sort_order');
    }
    if (!$cols['category_hide_order_catalog']) {
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN category_hide_order_catalog TINYINT(1) NOT NULL DEFAULT 0 AFTER category_sort_order'); } catch (Throwable $e) {}
      $cols['category_hide_order_catalog'] = products_has_column($pdo, 'category_hide_order_catalog');
    }
    if (!$cols['category_hide_product_catalog']) {
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN category_hide_product_catalog TINYINT(1) NOT NULL DEFAULT 0 AFTER category_hide_order_catalog'); } catch (Throwable $e) {}
      $cols['category_hide_product_catalog'] = products_has_column($pdo, 'category_hide_product_catalog');
    }
  }

  return $cols;
}

function products_select_columns_sql(): string {
  /** @var PDO $pdo */
  global $pdo;
  $cols = products_ensure_order_schema();
  $barcodeSelect = '';
  if ($pdo instanceof PDO) {
    foreach (products_barcode_columns($pdo) as $bcCol) {
      $barcodeSelect .= ', `' . str_replace('`', '``', $bcCol) . '`';
    }
  }
  $extraSelect = '';
  foreach (['numero_cadastro','codigo_firebird','codigo_cardapio','referencia'] as $extraCol) {
    if ($pdo instanceof PDO && products_has_column($pdo, $extraCol)) {
      $extraSelect .= ', `' . str_replace('`', '``', $extraCol) . '`';
    }
  }
  return 'id, nome, descricao, categoria, preco, imagem, ativo'
    . $extraSelect
    . $barcodeSelect
    . ($cols['sort_order'] ? ', COALESCE(sort_order,0) AS sort_order' : ', 0 AS sort_order')
    . ($cols['category_sort_order'] ? ', COALESCE(category_sort_order,0) AS category_sort_order' : ', 0 AS category_sort_order')
    . ($cols['category_hide_order_catalog'] ? ', COALESCE(category_hide_order_catalog,0) AS category_hide_order_catalog' : ', 0 AS category_hide_order_catalog')
    . ($cols['category_hide_product_catalog'] ? ', COALESCE(category_hide_product_catalog,0) AS category_hide_product_catalog' : ', 0 AS category_hide_product_catalog')
    . ($cols['hide_order_catalog_product'] ? ', COALESCE(hide_order_catalog_product,0) AS hide_order_catalog_product' : ', 0 AS hide_order_catalog_product');
}

function products_order_sql(string $prefix = ''): string {
  $cols = products_ensure_order_schema();
  $p = $prefix !== '' ? rtrim($prefix, '.') . '.' : '';

  $catOrder = $cols['category_sort_order']
    ? "CASE WHEN COALESCE({$p}category_sort_order,0) <= 0 THEN 999999 ELSE {$p}category_sort_order END"
    : '999999';
  $prodOrder = $cols['sort_order']
    ? "CASE WHEN COALESCE({$p}sort_order,0) <= 0 THEN 999999 ELSE {$p}sort_order END"
    : '999999';

  return "{$catOrder} ASC, {$p}categoria ASC, {$prodOrder} ASC, {$p}nome ASC, {$p}id ASC";
}


/**
 * Os campos category_hide_* representam uma configuração da CATEGORIA.
 * Por segurança, uma categoria só é considerada oculta quando TODOS os
 * produtos ativos daquela categoria estão marcados como ocultos. Isso evita
 * que um registro antigo/inconsistente faça apenas um produto desaparecer.
 */
function products_catalog_flag_column(string $catalog): string {
  return strtolower($catalog) === 'product'
    ? 'category_hide_product_catalog'
    : 'category_hide_order_catalog';
}

function products_category_hidden_db(string $category, string $catalog = 'order'): bool {
  /** @var PDO $pdo */
  global $pdo;
  if (!$pdo instanceof PDO) return false;
  $flag = products_catalog_flag_column($catalog);
  if (!products_has_column($pdo, $flag)) return false;

  $category = trim($category);
  $st = $pdo->prepare(
    "SELECT COUNT(*) AS total,
"
    . "       SUM(CASE WHEN COALESCE(`{$flag}`,0) <> 0 THEN 1 ELSE 0 END) AS hidden_count
"
    . "  FROM produtos
"
    . " WHERE company_id = ?
"
    . "   AND ativo = 1
"
    . "   AND COALESCE(TRIM(categoria),'') = ?"
  );
  $st->execute([current_company_id(), $category]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  $total = (int)($row['total'] ?? 0);
  $hidden = (int)($row['hidden_count'] ?? 0);
  return $total > 0 && $hidden >= $total;
}

function products_filter_visible_catalog_categories_db(array $items, string $catalog = 'order'): array {
  /** @var PDO $pdo */
  global $pdo;
  if (!$items || !$pdo instanceof PDO) return $items;
  $flag = products_catalog_flag_column($catalog);
  $hasCategoryFlag = products_has_column($pdo, $flag);
  if (!$hasCategoryFlag) {
    if (strtolower($catalog) === 'product' || !products_has_column($pdo, 'hide_order_catalog_product')) return $items;
    return array_values(array_filter($items, static fn(array $item): bool => empty($item['hide_order_catalog_product'])));
  }

  $categories = [];
  foreach ($items as $item) {
    $categories[trim((string)($item['categoria'] ?? ''))] = true;
  }
  $categories = array_keys($categories);
  if (!$categories) return $items;

  $hiddenCategories = [];
  foreach (array_chunk($categories, 300) as $chunk) {
    $ph = implode(',', array_fill(0, count($chunk), '?'));
    $sql = "SELECT COALESCE(TRIM(categoria),'') AS category_key,
"
      . "       COUNT(*) AS total,
"
      . "       SUM(CASE WHEN COALESCE(`{$flag}`,0) <> 0 THEN 1 ELSE 0 END) AS hidden_count
"
      . "  FROM produtos
"
      . " WHERE company_id = ?
"
      . "   AND ativo = 1
"
      . "   AND COALESCE(TRIM(categoria),'') IN ({$ph})
"
      . " GROUP BY COALESCE(TRIM(categoria),'')";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([current_company_id()], $chunk));
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
      $total = (int)($row['total'] ?? 0);
      $hidden = (int)($row['hidden_count'] ?? 0);
      if ($total > 0 && $hidden >= $total) {
        $hiddenCategories[(string)($row['category_key'] ?? '')] = true;
      }
    }
  }

  // O ocultamento individual só vale para o catálogo de PEDIDOS.
  // A coluna category_hide_* continua sendo avaliada no conjunto da categoria.
  $filterProducts = strtolower($catalog) !== 'product' && products_has_column($pdo, 'hide_order_catalog_product');
  return array_values(array_filter($items, static function(array $item) use ($hiddenCategories, $filterProducts): bool {
    $cat = trim((string)($item['categoria'] ?? ''));
    if (!empty($hiddenCategories[$cat])) return false;
    return !$filterProducts || empty($item['hide_order_catalog_product']);
  }));
}

/** Confere a disponibilidade apenas no catálogo público de PEDIDOS, não no PDV/admin. */
function products_order_catalog_available_db(int $companyId, int $productId): bool {
  /** @var PDO $pdo */
  global $pdo;
  if (!$pdo instanceof PDO || $companyId <= 0 || $productId <= 0) return false;
  $hasProductFlag = products_has_column($pdo, 'hide_order_catalog_product');
  $hasCategoryFlag = products_has_column($pdo, 'category_hide_order_catalog');
  $selectFlag = $hasProductFlag ? ', COALESCE(hide_order_catalog_product,0) AS hide_individual' : ', 0 AS hide_individual';
  $st = $pdo->prepare("SELECT id, categoria, ativo{$selectFlag} FROM produtos WHERE id=? AND company_id=? LIMIT 1");
  $st->execute([$productId, $companyId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row || (int)($row['ativo'] ?? 0) !== 1 || (int)($row['hide_individual'] ?? 0) !== 0) return false;
  if (!$hasCategoryFlag) return true;
  $stCat = $pdo->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN COALESCE(category_hide_order_catalog,0)<>0 THEN 1 ELSE 0 END) AS hidden_count
    FROM produtos WHERE company_id=? AND ativo=1 AND COALESCE(TRIM(categoria),'')=?");
  $stCat->execute([$companyId, trim((string)($row['categoria'] ?? ''))]);
  $cat = $stCat->fetch(PDO::FETCH_ASSOC) ?: [];
  return (int)($cat['total'] ?? 0) === 0 || (int)($cat['hidden_count'] ?? 0) < (int)($cat['total'] ?? 0);
}

function products_all_db(): array {
  /** @var PDO $pdo */
  global $pdo;

  $st = $pdo->prepare(
    'SELECT ' . products_select_columns_sql() . '
       FROM produtos
      WHERE ativo = 1
        AND company_id = ?
      ORDER BY ' . products_order_sql()
  );
  $st->execute([current_company_id()]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function products_find_db(int $id): ?array {
  /** @var PDO $pdo */
  global $pdo;

  products_ensure_order_schema();
  $st = $pdo->prepare('SELECT * FROM produtos WHERE id = ? AND company_id = ? LIMIT 1');
  $st->execute([$id, current_company_id()]);
  $p = $st->fetch(PDO::FETCH_ASSOC);
  return $p ?: null;
}

function products_search_db(string $term): array {
  $term = trim($term);
  if ($term === '') return products_all_db();

  /** @var PDO $pdo */
  global $pdo;
  $like = nexa_search_like($term);
  $parts = ['nome LIKE ?', 'categoria LIKE ?', 'descricao LIKE ?', 'CAST(id AS CHAR) LIKE ?'];
  $params = [current_company_id(), $like, $like, $like, $like];
  foreach (['numero_cadastro','codigo_firebird','codigo_cardapio','referencia'] as $extraCol) {
    if (products_has_column($pdo, $extraCol)) {
      $parts[] = '`' . str_replace('`', '``', $extraCol) . '` LIKE ?';
      $params[] = $like;
    }
  }
  foreach (products_barcode_columns($pdo) as $bcCol) {
    $parts[] = '`' . str_replace('`', '``', $bcCol) . '` LIKE ?';
    $params[] = $like;
  }

  $st = $pdo->prepare(
    'SELECT ' . products_select_columns_sql() . '
       FROM produtos
      WHERE ativo = 1
        AND company_id = ?
        AND (' . implode(' OR ', $parts) . ')
      ORDER BY ' . products_order_sql()
  );
  $st->execute($params);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function products_pizzas_db(): array {
  /** @var PDO $pdo */
  global $pdo;

  $cols = products_ensure_order_schema();
  $select = 'id, nome, categoria, preco, imagem'
    . ($cols['sort_order'] ? ', COALESCE(sort_order,0) AS sort_order' : ', 0 AS sort_order')
    . ($cols['category_sort_order'] ? ', COALESCE(category_sort_order,0) AS category_sort_order' : ', 0 AS category_sort_order')
    . ($cols['category_hide_order_catalog'] ? ', COALESCE(category_hide_order_catalog,0) AS category_hide_order_catalog' : ', 0 AS category_hide_order_catalog')
    . ($cols['category_hide_product_catalog'] ? ', COALESCE(category_hide_product_catalog,0) AS category_hide_product_catalog' : ', 0 AS category_hide_product_catalog')
    . ($cols['hide_order_catalog_product'] ? ', COALESCE(hide_order_catalog_product,0) AS hide_order_catalog_product' : ', 0 AS hide_order_catalog_product');

  $st = $pdo->prepare(
    "SELECT {$select}
       FROM produtos
      WHERE ativo = 1
        AND company_id = ?
        AND LOWER(COALESCE(categoria,'')) LIKE '%pizza%'
      ORDER BY " . products_order_sql()
  );
  $st->execute([current_company_id()]);
  return products_filter_visible_catalog_categories_db($st->fetchAll(PDO::FETCH_ASSOC), 'order');
}

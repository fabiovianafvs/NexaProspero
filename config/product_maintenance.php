<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Central de rotinas seguras de manutenção de produtos.
 *
 * Regras desta primeira versão:
 * - atua somente sobre produtos identificados como importados do Próspero;
 * - não altera preço, estoque, tributação, composição, produção ou cadastro;
 * - não reativa produtos inativos;
 * - normaliza somente os campos de visibilidade dos catálogos;
 * - uma categoria é mantida oculta quando todos os seus produtos estão ocultos;
 * - em categorias mistas, usa os produtos não importados como referência quando
 *   eles possuem um estado coerente; caso contrário, a referência segura é visível.
 */

if (!function_exists('product_maintenance_h')) {
  function product_maintenance_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('product_maintenance_table_exists')) {
  function product_maintenance_table_exists(PDO $pdo, string $table): bool {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
      $st->execute([$table]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      try {
        $st = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
        return $st && $st->fetchColumn() !== false;
      } catch (Throwable $e2) {
        return false;
      }
    }
  }
}

if (!function_exists('product_maintenance_column_exists')) {
  function product_maintenance_column_exists(PDO $pdo, string $table, string $column): bool {
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

if (!function_exists('product_maintenance_ensure_schema')) {
  function product_maintenance_ensure_schema(PDO $pdo): array {
    $result = ['ok' => true, 'errors' => []];
    if (!product_maintenance_table_exists($pdo, 'produtos')) {
      return ['ok' => false, 'errors' => ['Tabela de produtos não encontrada.']];
    }
    if ($pdo->inTransaction()) {
      return ['ok' => false, 'errors' => ['Não é possível preparar a manutenção durante uma transação ativa.']];
    }

    foreach ([
      'category_hide_order_catalog' => 'TINYINT(1) NOT NULL DEFAULT 0',
      'category_hide_product_catalog' => 'TINYINT(1) NOT NULL DEFAULT 0',
    ] as $column => $definition) {
      if (product_maintenance_column_exists($pdo, 'produtos', $column)) continue;
      try {
        $pdo->exec('ALTER TABLE produtos ADD COLUMN `' . $column . '` ' . $definition);
      } catch (Throwable $e) {
        $result['errors'][] = 'Não foi possível preparar o campo ' . $column . ': ' . $e->getMessage();
      }
    }

    $result['ok'] = !$result['errors']
      && product_maintenance_column_exists($pdo, 'produtos', 'category_hide_order_catalog')
      && product_maintenance_column_exists($pdo, 'produtos', 'category_hide_product_catalog');
    return $result;
  }
}

if (!function_exists('product_maintenance_imported_subquery')) {
  /** @return array{sql:string,params:array<int,mixed>} */
  function product_maintenance_imported_subquery(PDO $pdo, int $companyId): array {
    $parts = [];
    $params = [];

    if (product_maintenance_table_exists($pdo, 'product_import_product_map')) {
      $parts[] = "SELECT product_id FROM product_import_product_map WHERE company_id=? AND UPPER(COALESCE(source_type,'')) LIKE 'PROSPERO_%'";
      $params[] = $companyId;
    }
    if (product_maintenance_table_exists($pdo, 'produtos_firebird_map')) {
      $parts[] = 'SELECT produto_id AS product_id FROM produtos_firebird_map WHERE company_id=?';
      $params[] = $companyId;
    }

    if (!$parts) return ['sql' => '', 'params' => []];
    return [
      'sql' => 'SELECT DISTINCT product_id FROM (' . implode(' UNION ALL ', $parts) . ') imported_source',
      'params' => $params,
    ];
  }
}

if (!function_exists('product_maintenance_resolve_flag')) {
  function product_maintenance_resolve_flag(int $total, int $hidden, int $nonImportedTotal, int $nonImportedHidden): int {
    if ($total <= 0 || $hidden <= 0) return 0;
    if ($hidden >= $total) return 1; // categoria integralmente oculta: preserva intenção do usuário.

    // Categoria mista: se os produtos cadastrados fora da importação estão
    // coerentes, eles são a melhor referência para corrigir somente os importados.
    if ($nonImportedTotal > 0) {
      if ($nonImportedHidden <= 0) return 0;
      if ($nonImportedHidden >= $nonImportedTotal) return 1;
    }

    // Sem referência coerente, não propagamos um ocultamento parcial antigo.
    return 0;
  }
}

if (!function_exists('product_maintenance_visibility_preview')) {
  function product_maintenance_visibility_preview(PDO $pdo, int $companyId, bool $collectIds = false, int $sampleLimit = 100): array {
    $schema = product_maintenance_ensure_schema($pdo);
    if (empty($schema['ok'])) {
      throw new RuntimeException(implode(' ', (array)($schema['errors'] ?? ['Não foi possível preparar os campos de visibilidade.'])));
    }

    $imported = product_maintenance_imported_subquery($pdo, $companyId);
    if ($imported['sql'] === '') {
      return [
        'imported_count' => 0,
        'inactive_count' => 0,
        'affected_count' => 0,
        'mixed_categories' => 0,
        'categories_count' => 0,
        'samples' => [],
        'change_groups' => [],
        'message' => 'Nenhum mapeamento de produto importado do Próspero foi encontrado para esta empresa.',
      ];
    }

    $statsSql = "SELECT
        COALESCE(TRIM(p.categoria),'') AS category_key,
        COUNT(*) AS total,
        SUM(CASE WHEN COALESCE(p.category_hide_order_catalog,0) <> 0 THEN 1 ELSE 0 END) AS hide_order,
        SUM(CASE WHEN COALESCE(p.category_hide_product_catalog,0) <> 0 THEN 1 ELSE 0 END) AS hide_product,
        SUM(CASE WHEN imp.product_id IS NULL THEN 1 ELSE 0 END) AS non_import_total,
        SUM(CASE WHEN imp.product_id IS NULL AND COALESCE(p.category_hide_order_catalog,0) <> 0 THEN 1 ELSE 0 END) AS non_import_hide_order,
        SUM(CASE WHEN imp.product_id IS NULL AND COALESCE(p.category_hide_product_catalog,0) <> 0 THEN 1 ELSE 0 END) AS non_import_hide_product,
        SUM(CASE WHEN imp.product_id IS NOT NULL THEN 1 ELSE 0 END) AS imported_total
      FROM produtos p
      LEFT JOIN ({$imported['sql']}) imp ON imp.product_id=p.id
      WHERE p.company_id=?
      GROUP BY COALESCE(TRIM(p.categoria),'')
      HAVING imported_total > 0
      ORDER BY category_key";
    $stStats = $pdo->prepare($statsSql);
    $stStats->execute(array_merge($imported['params'], [$companyId]));

    $categoryRules = [];
    $mixedCategories = 0;
    foreach (($stStats->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
      $category = (string)($row['category_key'] ?? '');
      $total = (int)($row['total'] ?? 0);
      $hideOrder = (int)($row['hide_order'] ?? 0);
      $hideProduct = (int)($row['hide_product'] ?? 0);
      $nonTotal = (int)($row['non_import_total'] ?? 0);
      $nonHideOrder = (int)($row['non_import_hide_order'] ?? 0);
      $nonHideProduct = (int)($row['non_import_hide_product'] ?? 0);
      $isMixed = ($hideOrder > 0 && $hideOrder < $total) || ($hideProduct > 0 && $hideProduct < $total);
      if ($isMixed) $mixedCategories++;
      $categoryRules[$category] = [
        'order' => product_maintenance_resolve_flag($total, $hideOrder, $nonTotal, $nonHideOrder),
        'product' => product_maintenance_resolve_flag($total, $hideProduct, $nonTotal, $nonHideProduct),
        'mixed' => $isMixed,
        'total' => $total,
        'imported_total' => (int)($row['imported_total'] ?? 0),
      ];
    }

    $itemsSql = "SELECT p.id, p.nome, COALESCE(TRIM(p.categoria),'') AS category_key, p.ativo,
        COALESCE(p.category_hide_order_catalog,0) AS hide_order,
        COALESCE(p.category_hide_product_catalog,0) AS hide_product
      FROM produtos p
      INNER JOIN ({$imported['sql']}) imp ON imp.product_id=p.id
      WHERE p.company_id=?
      ORDER BY category_key, p.nome, p.id";
    $stItems = $pdo->prepare($itemsSql);
    $stItems->execute(array_merge($imported['params'], [$companyId]));

    $importedCount = 0;
    $inactiveCount = 0;
    $affectedCount = 0;
    $samples = [];
    $changeGroups = [];
    while ($row = $stItems->fetch(PDO::FETCH_ASSOC)) {
      $importedCount++;
      if (empty($row['ativo'])) $inactiveCount++;
      $category = (string)($row['category_key'] ?? '');
      $rule = $categoryRules[$category] ?? ['order' => 0, 'product' => 0, 'mixed' => false];
      $currentOrder = !empty($row['hide_order']) ? 1 : 0;
      $currentProduct = !empty($row['hide_product']) ? 1 : 0;
      $targetOrder = (int)$rule['order'];
      $targetProduct = (int)$rule['product'];
      if ($currentOrder === $targetOrder && $currentProduct === $targetProduct) continue;

      $affectedCount++;
      if (count($samples) < max(0, $sampleLimit)) {
        $samples[] = [
          'id' => (int)$row['id'],
          'nome' => (string)$row['nome'],
          'categoria' => $category !== '' ? $category : 'Sem categoria',
          'order_before' => $currentOrder,
          'order_after' => $targetOrder,
          'product_before' => $currentProduct,
          'product_after' => $targetProduct,
        ];
      }
      if ($collectIds) {
        $key = $targetOrder . ':' . $targetProduct;
        if (!isset($changeGroups[$key])) {
          $changeGroups[$key] = ['order' => $targetOrder, 'product' => $targetProduct, 'ids' => []];
        }
        $changeGroups[$key]['ids'][] = (int)$row['id'];
      }
    }

    return [
      'imported_count' => $importedCount,
      'inactive_count' => $inactiveCount,
      'affected_count' => $affectedCount,
      'mixed_categories' => $mixedCategories,
      'categories_count' => count($categoryRules),
      'samples' => $samples,
      'change_groups' => $changeGroups,
      'message' => '',
    ];
  }
}

if (!function_exists('product_maintenance_apply_visibility')) {
  function product_maintenance_apply_visibility(PDO $pdo, int $companyId): array {
    $preview = product_maintenance_visibility_preview($pdo, $companyId, true, 100);
    $affected = (int)($preview['affected_count'] ?? 0);
    if ($affected <= 0) return ['updated' => 0, 'preview' => $preview];

    $updated = 0;
    $pdo->beginTransaction();
    try {
      foreach ((array)($preview['change_groups'] ?? []) as $group) {
        $targetOrder = !empty($group['order']) ? 1 : 0;
        $targetProduct = !empty($group['product']) ? 1 : 0;
        $ids = array_values(array_unique(array_map('intval', (array)($group['ids'] ?? []))));
        foreach (array_chunk($ids, 400) as $chunk) {
          if (!$chunk) continue;
          $placeholders = implode(',', array_fill(0, count($chunk), '?'));
          $sql = "UPDATE produtos
                     SET category_hide_order_catalog=?, category_hide_product_catalog=?
                   WHERE company_id=? AND id IN ({$placeholders})";
          $st = $pdo->prepare($sql);
          $st->execute(array_merge([$targetOrder, $targetProduct, $companyId], $chunk));
          $updated += $st->rowCount();
        }
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }

    return ['updated' => $updated, 'preview' => $preview];
  }
}


if (!function_exists('product_maintenance_category_visibility_preview')) {
  function product_maintenance_category_visibility_preview(PDO $pdo, int $companyId, bool $collectCategories = false, int $sampleLimit = 100): array {
    $schema = product_maintenance_ensure_schema($pdo);
    if (empty($schema['ok'])) throw new RuntimeException(implode(' ', (array)($schema['errors'] ?? [])));

    $sql = "SELECT COALESCE(TRIM(categoria),'') AS category_key,
                   COUNT(*) AS active_total,
                   SUM(CASE WHEN COALESCE(category_hide_order_catalog,0) <> 0 THEN 1 ELSE 0 END) AS hide_order,
                   SUM(CASE WHEN COALESCE(category_hide_product_catalog,0) <> 0 THEN 1 ELSE 0 END) AS hide_product
              FROM produtos
             WHERE company_id=? AND ativo=1
             GROUP BY COALESCE(TRIM(categoria),'')
             ORDER BY category_key";
    $st = $pdo->prepare($sql);
    $st->execute([$companyId]);

    $mixed = 0;
    $affected = 0;
    $samples = [];
    $categories = [];
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
      $total = (int)($row['active_total'] ?? 0);
      $hideOrder = (int)($row['hide_order'] ?? 0);
      $hideProduct = (int)($row['hide_product'] ?? 0);
      $mixedOrder = $hideOrder > 0 && $hideOrder < $total;
      $mixedProduct = $hideProduct > 0 && $hideProduct < $total;
      if (!$mixedOrder && !$mixedProduct) continue;

      $mixed++;
      $category = (string)($row['category_key'] ?? '');
      // Em estado misto a categoria já está parcialmente visível. A referência segura
      // é mantê-la visível e uniformizar todos os registros da categoria em 0.
      $targetOrder = $mixedOrder ? 0 : ($hideOrder >= $total && $total > 0 ? 1 : 0);
      $targetProduct = $mixedProduct ? 0 : ($hideProduct >= $total && $total > 0 ? 1 : 0);

      $stCount = $pdo->prepare("SELECT COUNT(*) FROM produtos WHERE company_id=? AND COALESCE(TRIM(categoria),'')=? AND (COALESCE(category_hide_order_catalog,0)<>? OR COALESCE(category_hide_product_catalog,0)<>?)");
      $stCount->execute([$companyId, $category, $targetOrder, $targetProduct]);
      $catAffected = (int)$stCount->fetchColumn();
      $affected += $catAffected;

      if (count($samples) < max(0, $sampleLimit)) {
        $samples[] = [
          'categoria' => $category !== '' ? $category : 'Sem categoria',
          'category_key' => $category,
          'active_total' => $total,
          'hide_order' => $hideOrder,
          'hide_product' => $hideProduct,
          'target_order' => $targetOrder,
          'target_product' => $targetProduct,
          'affected' => $catAffected,
        ];
      }
      if ($collectCategories) {
        $categories[] = ['category_key' => $category, 'order' => $targetOrder, 'product' => $targetProduct];
      }
    }

    return [
      'mixed_categories' => $mixed,
      'affected_count' => $affected,
      'samples' => $samples,
      'categories' => $categories,
    ];
  }
}

if (!function_exists('product_maintenance_apply_category_visibility')) {
  function product_maintenance_apply_category_visibility(PDO $pdo, int $companyId): array {
    $preview = product_maintenance_category_visibility_preview($pdo, $companyId, true, 100);
    if ((int)($preview['mixed_categories'] ?? 0) <= 0) return ['updated' => 0, 'preview' => $preview];

    $updated = 0;
    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare("UPDATE produtos SET category_hide_order_catalog=?, category_hide_product_catalog=? WHERE company_id=? AND COALESCE(TRIM(categoria),'')=?");
      foreach ((array)($preview['categories'] ?? []) as $cat) {
        $st->execute([(int)$cat['order'], (int)$cat['product'], $companyId, (string)$cat['category_key']]);
        $updated += $st->rowCount();
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    return ['updated' => $updated, 'preview' => $preview];
  }
}


if (!function_exists('product_maintenance_hide_catalogs_preview')) {
  /**
   * Prévia da rotina que oculta todas as categorias nos dois catálogos.
   * A consulta é obrigatoriamente limitada à empresa atual.
   */
  function product_maintenance_hide_catalogs_preview(PDO $pdo, int $companyId, int $sampleLimit = 100): array {
    $schema = product_maintenance_ensure_schema($pdo);
    if (empty($schema['ok'])) {
      throw new RuntimeException(implode(' ', (array)($schema['errors'] ?? ['Não foi possível preparar os campos de visibilidade.'])));
    }

    $sql = "SELECT COALESCE(TRIM(categoria),'') AS category_key,
                   COUNT(*) AS total,
                   SUM(CASE WHEN ativo=1 THEN 1 ELSE 0 END) AS active_total,
                   SUM(CASE WHEN COALESCE(category_hide_order_catalog,0)=0 THEN 1 ELSE 0 END) AS order_visible_records,
                   SUM(CASE WHEN COALESCE(category_hide_product_catalog,0)=0 THEN 1 ELSE 0 END) AS product_visible_records,
                   SUM(CASE WHEN COALESCE(category_hide_order_catalog,0)=0 OR COALESCE(category_hide_product_catalog,0)=0 THEN 1 ELSE 0 END) AS records_to_change
              FROM produtos
             WHERE company_id=?
             GROUP BY COALESCE(TRIM(categoria),'')
             ORDER BY category_key";
    $st = $pdo->prepare($sql);
    $st->execute([$companyId]);

    $categories = 0;
    $products = 0;
    $activeProducts = 0;
    $recordsToChange = 0;
    $categoriesToChange = 0;
    $samples = [];
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
      $categories++;
      $products += (int)($row['total'] ?? 0);
      $activeProducts += (int)($row['active_total'] ?? 0);
      $change = (int)($row['records_to_change'] ?? 0);
      $recordsToChange += $change;
      if ($change > 0) $categoriesToChange++;

      if (count($samples) < max(0, $sampleLimit)) {
        $category = (string)($row['category_key'] ?? '');
        $samples[] = [
          'categoria' => $category !== '' ? $category : 'Sem categoria',
          'total' => (int)($row['total'] ?? 0),
          'active_total' => (int)($row['active_total'] ?? 0),
          'order_visible_records' => (int)($row['order_visible_records'] ?? 0),
          'product_visible_records' => (int)($row['product_visible_records'] ?? 0),
          'records_to_change' => $change,
        ];
      }
    }

    return [
      'company_id' => $companyId,
      'categories_count' => $categories,
      'categories_to_change' => $categoriesToChange,
      'products_count' => $products,
      'active_products_count' => $activeProducts,
      'records_to_change' => $recordsToChange,
      'samples' => $samples,
    ];
  }
}

if (!function_exists('product_maintenance_hide_all_catalog_categories')) {
  /**
   * Oculta todas as categorias nos catálogos de pedidos e de produtos,
   * exclusivamente para a empresa informada. Nenhum outro dado do produto é alterado.
   */
  function product_maintenance_hide_all_catalog_categories(PDO $pdo, int $companyId): array {
    $preview = product_maintenance_hide_catalogs_preview($pdo, $companyId, 100);
    if ((int)($preview['records_to_change'] ?? 0) <= 0) {
      return ['updated' => 0, 'preview' => $preview];
    }

    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare("UPDATE produtos
                              SET category_hide_order_catalog=1,
                                  category_hide_product_catalog=1
                            WHERE company_id=?
                              AND (COALESCE(category_hide_order_catalog,0)<>1
                                   OR COALESCE(category_hide_product_catalog,0)<>1)");
      $st->execute([$companyId]);
      $updated = $st->rowCount();
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }

    return ['updated' => (int)$updated, 'preview' => $preview];
  }
}

if (!function_exists('product_maintenance_diagnose_products')) {
  function product_maintenance_diagnose_products(PDO $pdo, int $companyId, string $term, int $limit = 30): array {
    $term = trim($term);
    if ($term === '') return [];
    $like = nexa_search_like($term);
    $parts = ['p.nome LIKE ?', 'CAST(p.id AS CHAR) LIKE ?'];
    $params = [$like, $like];
    foreach (['numero_cadastro','referencia','codigo_firebird','codigo_cardapio','codigo_barras','ean','gtin','barcode'] as $col) {
      if (product_maintenance_column_exists($pdo, 'produtos', $col)) {
        $parts[] = 'CAST(p.`' . $col . '` AS CHAR) LIKE ?';
        $params[] = $like;
      }
    }

    $selectExtra = [];
    foreach (['numero_cadastro','referencia','codigo_firebird','codigo_cardapio','codigo_barras'] as $col) {
      if (product_maintenance_column_exists($pdo, 'produtos', $col)) $selectExtra[] = 'p.`' . $col . '`';
    }
    $sql = "SELECT p.id, p.nome, COALESCE(TRIM(p.categoria),'') AS categoria, p.ativo,
                   COALESCE(p.category_hide_order_catalog,0) AS raw_hide_order,
                   COALESCE(p.category_hide_product_catalog,0) AS raw_hide_product"
      . ($selectExtra ? ', ' . implode(', ', $selectExtra) : '')
      . " FROM produtos p WHERE p.company_id=? AND (" . implode(' OR ', $parts) . ") ORDER BY p.nome,p.id LIMIT " . max(1, min(100, $limit));
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$companyId], $params));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stCat = $pdo->prepare("SELECT COUNT(*) AS total,
       SUM(CASE WHEN COALESCE(category_hide_order_catalog,0)<>0 THEN 1 ELSE 0 END) AS hide_order,
       SUM(CASE WHEN COALESCE(category_hide_product_catalog,0)<>0 THEN 1 ELSE 0 END) AS hide_product
      FROM produtos WHERE company_id=? AND ativo=1 AND COALESCE(TRIM(categoria),'')=?");
    foreach ($rows as &$row) {
      $stCat->execute([$companyId, (string)$row['categoria']]);
      $cat = $stCat->fetch(PDO::FETCH_ASSOC) ?: [];
      $total = (int)($cat['total'] ?? 0);
      $hideOrder = (int)($cat['hide_order'] ?? 0);
      $hideProduct = (int)($cat['hide_product'] ?? 0);
      $categoryOrderHidden = $total > 0 && $hideOrder >= $total;
      $categoryProductHidden = $total > 0 && $hideProduct >= $total;
      $row['category_active_total'] = $total;
      $row['category_hide_order_count'] = $hideOrder;
      $row['category_hide_product_count'] = $hideProduct;
      $row['category_order_hidden'] = $categoryOrderHidden ? 1 : 0;
      $row['category_product_hidden'] = $categoryProductHidden ? 1 : 0;
      $row['order_catalog_visible'] = ((int)$row['ativo'] === 1 && !$categoryOrderHidden) ? 1 : 0;
      $row['product_catalog_visible'] = ((int)$row['ativo'] === 1 && !$categoryProductHidden) ? 1 : 0;
      $row['category_mixed_order'] = ($hideOrder > 0 && $hideOrder < $total) ? 1 : 0;
      $row['category_mixed_product'] = ($hideProduct > 0 && $hideProduct < $total) ? 1 : 0;
    }
    unset($row);
    return $rows;
  }
}

if (!function_exists('product_maintenance_csrf_token')) {
  function product_maintenance_csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (empty($_SESSION['csrf_product_maintenance']) || !is_string($_SESSION['csrf_product_maintenance'])) {
      $_SESSION['csrf_product_maintenance'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['csrf_product_maintenance'];
  }
}

if (!function_exists('product_maintenance_csrf_check')) {
  function product_maintenance_csrf_check(string $token): void {
    $expected = product_maintenance_csrf_token();
    if ($token === '' || !hash_equals($expected, $token)) {
      throw new RuntimeException('A sessão da manutenção expirou. Atualize a página e tente novamente.');
    }
  }
}

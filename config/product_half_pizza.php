<?php
declare(strict_types=1);

/**
 * Regras de Pizza Meio a Meio por categoria.
 *
 * A configuração é persistida na própria tabela produtos porque o Nexa ainda
 * trata categoria como texto. Ao salvar uma categoria no cadastro de produtos,
 * o valor é propagado para todos os itens daquela categoria/empresa.
 */

if (!function_exists('product_half_pizza_normalize_category')) {
  function product_half_pizza_normalize_category(string $category): string {
    $category = trim($category);
    if ($category === '') return '';
    return function_exists('mb_strtolower')
      ? mb_strtolower($category, 'UTF-8')
      : strtolower($category);
  }
}

if (!function_exists('product_half_pizza_column_exists')) {
  function product_half_pizza_column_exists(PDO $pdo): bool {
    try {
      $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produtos' AND COLUMN_NAME = 'category_allow_half_pizza' LIMIT 1");
      $st->execute();
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      try {
        $st = $pdo->query("SHOW COLUMNS FROM produtos LIKE 'category_allow_half_pizza'");
        return $st && (bool)$st->fetch();
      } catch (Throwable $e2) {
        return false;
      }
    }
  }
}

if (!function_exists('product_half_pizza_ensure_schema')) {
  function product_half_pizza_ensure_schema(PDO $pdo, bool $allowDdl = true): bool {
    if (product_half_pizza_column_exists($pdo)) return true;
    if (!$allowDdl || $pdo->inTransaction()) return false;

    $created = false;
    try {
      $pdo->exec("ALTER TABLE produtos ADD COLUMN category_allow_half_pizza TINYINT(1) NOT NULL DEFAULT 0");
      $created = true;
    } catch (Throwable $e) {
      // Pode ter sido criada por outra requisição simultânea.
    }

    $exists = product_half_pizza_column_exists($pdo);
    if ($exists && $created) {
      // Compatibilidade: somente na criação da coluna, preserva o comportamento
      // histórico das categorias exatamente PIZZA/PIZZAS. Depois disso o usuário
      // pode desmarcar normalmente sem que o sistema volte a marcar sozinho.
      try {
        $pdo->exec("UPDATE produtos SET category_allow_half_pizza = 1 WHERE UPPER(TRIM(COALESCE(categoria,''))) IN ('PIZZA','PIZZAS')");
      } catch (Throwable $e) {}
    }
    return $exists;
  }
}

if (!function_exists('product_half_pizza_enabled_categories')) {
  function product_half_pizza_enabled_categories(PDO $pdo, int $companyId): array {
    if ($companyId <= 0 || !product_half_pizza_column_exists($pdo)) return [];
    try {
      $st = $pdo->prepare("SELECT categoria FROM produtos WHERE company_id = ? AND COALESCE(category_allow_half_pizza,0) = 1 AND TRIM(COALESCE(categoria,'')) <> '' GROUP BY categoria ORDER BY categoria");
      $st->execute([$companyId]);
      $out = [];
      foreach (($st->fetchAll(PDO::FETCH_COLUMN) ?: []) as $category) {
        $category = trim((string)$category);
        $key = product_half_pizza_normalize_category($category);
        if ($key !== '') $out[$key] = $category;
      }
      return $out;
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('product_half_pizza_category_enabled')) {
  function product_half_pizza_category_enabled(array $enabledCategories, string $category): bool {
    $key = product_half_pizza_normalize_category($category);
    return $key !== '' && array_key_exists($key, $enabledCategories);
  }
}

if (!function_exists('product_half_pizza_candidates')) {
  function product_half_pizza_candidates(PDO $pdo, int $companyId, array $categories, int $limit = 600): array {
    if ($companyId <= 0 || !product_half_pizza_column_exists($pdo)) return [];
    $unique = [];
    foreach ($categories as $category) {
      $category = trim((string)$category);
      $key = product_half_pizza_normalize_category($category);
      if ($key !== '') $unique[$key] = $category;
    }
    if (!$unique) return [];
    $values = array_values($unique);
    $ph = implode(',', array_fill(0, count($values), '?'));
    $params = array_merge([$companyId], $values);
    $limit = max(10, min(1500, $limit));
    try {
      $st = $pdo->prepare("SELECT id, nome, categoria, preco FROM produtos WHERE company_id = ? AND COALESCE(ativo,1)=1 AND categoria IN ($ph) ORDER BY categoria ASC, nome ASC LIMIT {$limit}");
      $st->execute($params);
      return array_map(static function(array $row): array {
        return [
          'id' => (int)($row['id'] ?? 0),
          'name' => (string)($row['nome'] ?? ''),
          'category' => (string)($row['categoria'] ?? ''),
          'price' => (float)($row['preco'] ?? 0),
        ];
      }, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) {
      return [];
    }
  }
}

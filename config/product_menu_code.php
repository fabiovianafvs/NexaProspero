<?php
declare(strict_types=1);

if (!function_exists('product_menu_code_column_exists')) {
  function product_menu_code_column_exists(PDO $pdo): bool {
    if (isset($GLOBALS['product_menu_code_column_exists'])) {
      return (bool)$GLOBALS['product_menu_code_column_exists'];
    }
    try {
      $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produtos' AND COLUMN_NAME = 'codigo_cardapio' LIMIT 1");
      $st->execute();
      return $GLOBALS['product_menu_code_column_exists'] = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      try {
        $st = $pdo->query("SHOW COLUMNS FROM produtos LIKE 'codigo_cardapio'");
        return $GLOBALS['product_menu_code_column_exists'] = (bool)($st && $st->fetch(PDO::FETCH_ASSOC));
      } catch (Throwable $e2) {
        return $GLOBALS['product_menu_code_column_exists'] = false;
      }
    }
  }
}

if (!function_exists('product_menu_code_ensure_schema')) {
  function product_menu_code_ensure_schema(PDO $pdo, bool $allowDdl = true): bool {
    if (product_menu_code_column_exists($pdo)) return true;
    if (!$allowDdl || $pdo->inTransaction()) return false;
    try {
      $pdo->exec("ALTER TABLE produtos ADD COLUMN codigo_cardapio VARCHAR(60) NULL AFTER codigo_barras");
    } catch (Throwable $e) {
      try { $pdo->exec("ALTER TABLE produtos ADD COLUMN codigo_cardapio VARCHAR(60) NULL"); } catch (Throwable $e2) {}
    }
    unset($GLOBALS['product_menu_code_column_exists']);
    return product_menu_code_column_exists($pdo);
  }
}

if (!function_exists('product_menu_code_normalize')) {
  function product_menu_code_normalize($value): string {
    $value = trim((string)$value);
    $value = preg_replace('/\s+/u', '', $value) ?? '';
    if (function_exists('mb_strtoupper')) $value = mb_strtoupper($value, 'UTF-8');
    else $value = strtoupper($value);
    return function_exists('mb_substr') ? mb_substr($value, 0, 60, 'UTF-8') : substr($value, 0, 60);
  }
}

if (!function_exists('product_menu_code_available')) {
  function product_menu_code_available(PDO $pdo, int $companyId, string $code, int $ignoreProductId = 0): bool {
    $code = product_menu_code_normalize($code);
    if ($code === '' || !product_menu_code_column_exists($pdo)) return true;
    $sql = 'SELECT id FROM produtos WHERE company_id = ? AND codigo_cardapio = ?';
    $params = [$companyId, $code];
    if ($ignoreProductId > 0) {
      $sql .= ' AND id <> ?';
      $params[] = $ignoreProductId;
    }
    $sql .= ' LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return !$st->fetchColumn();
  }
}

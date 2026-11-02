<?php
declare(strict_types=1);

if (!function_exists('stock_mysql_has_column')) {
  function stock_mysql_has_column(PDO $pdo, string $table, string $column): bool {
    try {
      $st = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
      $st->execute([$table, $column]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      try {
        $st = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '` LIKE ' . $pdo->quote($column));
        return $st && (bool)$st->fetch();
      } catch (Throwable $e2) {
        return false;
      }
    }
  }
}

if (!function_exists('stock_add_column_if_missing')) {
  function stock_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition, ?string $after = null): bool {
    if (stock_mysql_has_column($pdo, $table, $column)) return true;
    if ($pdo->inTransaction()) return false;
    $tableSql = '`' . str_replace('`', '``', $table) . '`';
    $columnSql = '`' . str_replace('`', '``', $column) . '`';
    try {
      $sql = 'ALTER TABLE ' . $tableSql . ' ADD COLUMN ' . $columnSql . ' ' . $definition;
      if ($after !== null && $after !== '') $sql .= ' AFTER `' . str_replace('`', '``', $after) . '`';
      $pdo->exec($sql);
    } catch (Throwable $e) {
      try {
        $pdo->exec('ALTER TABLE ' . $tableSql . ' ADD COLUMN ' . $columnSql . ' ' . $definition);
      } catch (Throwable $e2) {}
    }
    return stock_mysql_has_column($pdo, $table, $column);
  }
}

if (!function_exists('stock_ensure_schema')) {
  function stock_ensure_schema(PDO $pdo, bool $createMovements = true): array {
    $cols = [
      'unidade_estoque' => stock_mysql_has_column($pdo, 'produtos', 'unidade_estoque'),
      'estoque_atual' => stock_mysql_has_column($pdo, 'produtos', 'estoque_atual'),
      'estoque_minimo' => stock_mysql_has_column($pdo, 'produtos', 'estoque_minimo'),
      'estoque_maximo' => stock_mysql_has_column($pdo, 'produtos', 'estoque_maximo'),
      'estoque_reservado' => stock_mysql_has_column($pdo, 'produtos', 'estoque_reservado'),
    ];

    if (!$pdo->inTransaction()) {
      if (!$cols['unidade_estoque']) $cols['unidade_estoque'] = stock_add_column_if_missing($pdo, 'produtos', 'unidade_estoque', "VARCHAR(20) NULL DEFAULT 'UN'", 'preco');
      if (!$cols['estoque_atual']) $cols['estoque_atual'] = stock_add_column_if_missing($pdo, 'produtos', 'estoque_atual', 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000', 'unidade_estoque');
      if (!$cols['estoque_minimo']) $cols['estoque_minimo'] = stock_add_column_if_missing($pdo, 'produtos', 'estoque_minimo', 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000', 'estoque_atual');
      if (!$cols['estoque_maximo']) $cols['estoque_maximo'] = stock_add_column_if_missing($pdo, 'produtos', 'estoque_maximo', 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000', 'estoque_minimo');
      if (!$cols['estoque_reservado']) $cols['estoque_reservado'] = stock_add_column_if_missing($pdo, 'produtos', 'estoque_reservado', 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000', 'estoque_maximo');

      if ($createMovements) {
        try {
          $pdo->exec("CREATE TABLE IF NOT EXISTS estoque_movimentos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            produto_id BIGINT UNSIGNED NOT NULL,
            tipo VARCHAR(30) NOT NULL DEFAULT 'ajuste',
            origem_tipo VARCHAR(40) NULL,
            origem_id BIGINT UNSIGNED NULL,
            qtd DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
            saldo_anterior DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
            saldo_posterior DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
            observacao TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_estoque_movimentos_company_produto (company_id, produto_id),
            KEY idx_estoque_movimentos_origem (company_id, origem_tipo, origem_id),
            KEY idx_estoque_movimentos_created (company_id, created_at)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {}
      }
    }

    return $cols;
  }
}

if (!function_exists('stock_parse_decimal')) {
  function stock_parse_decimal($value): float {
    $value = trim((string)$value);
    if ($value === '') return 0.0;
    $value = str_replace(['R$', ' '], '', $value);
    if (str_contains($value, ',') && str_contains($value, '.')) $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);
    return is_numeric($value) ? (float)$value : 0.0;
  }
}

if (!function_exists('stock_qty_br')) {
  function stock_qty_br($value, int $maxDecimals = 4): string {
    $n = (float)$value;
    if (abs($n - round($n)) < 0.00005) return number_format($n, 0, ',', '.');
    $s = number_format($n, $maxDecimals, ',', '.');
    $s = rtrim($s, '0');
    return rtrim($s, ',');
  }
}

if (!function_exists('stock_unit_clean')) {
  function stock_unit_clean($value): string {
    $unit = strtoupper(trim((string)$value));
    $unit = preg_replace('/[^A-Z0-9ÇÃÕÁÉÍÓÚÂÊÔÜ\.\-\/ ]/u', '', $unit) ?: '';
    return substr($unit, 0, 20);
  }
}

if (!function_exists('stock_open_order_statuses')) {
  function stock_open_order_statuses(): array {
    return ['aberto', 'confirmado', 'em_producao', 'saiu_entrega'];
  }
}

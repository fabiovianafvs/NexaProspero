<?php
declare(strict_types=1);

/**
 * Numeração sequencial por empresa.
 *
 * Mantém o ID original apenas como controle interno do banco e cria números
 * visíveis independentes para cada empresa/catálogo.
 */

if (!function_exists('company_sequence_entities')) {
  function company_sequence_entities(): array {
    return [
      'produtos' => [
        'table' => 'produtos',
        'column' => 'numero_cadastro',
        'after' => 'company_id',
        // Código operacional visível do produto. Pode vir de sistemas legados
        // com zeros à esquerda ou códigos longos, portanto deve ser texto.
        'type' => 'VARCHAR(60)',
      ],
      'pedidos' => [
        'table' => 'pedidos',
        'column' => 'numero_pedido',
        'after' => 'company_id',
        'type' => 'INT UNSIGNED',
      ],
      'pessoas' => [
        'table' => 'finance_people',
        'column' => 'numero_cadastro',
        'after' => 'company_id',
        'type' => 'INT UNSIGNED',
      ],
    ];
  }
}

if (!function_exists('company_sequence_identifier_ok')) {
  function company_sequence_identifier_ok(string $name): bool {
    return (bool)preg_match('/^[a-zA-Z0-9_]+$/', $name);
  }
}

if (!function_exists('company_sequence_table_exists')) {
  function company_sequence_table_exists(PDO $pdo, string $table): bool {
    if (!company_sequence_identifier_ok($table)) return false;
    try {
      $st = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
      $st->execute([$table]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      try { $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1'); return true; } catch (Throwable $e2) { return false; }
    }
  }
}

if (!function_exists('company_sequence_column_exists')) {
  function company_sequence_column_exists(PDO $pdo, string $table, string $column): bool {
    if (!company_sequence_identifier_ok($table) || !company_sequence_identifier_ok($column)) return false;
    try {
      $st = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
      $st->execute([$table, $column]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      try {
        $st = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?');
        $st->execute([$column]);
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
      } catch (Throwable $e2) { return false; }
    }
  }
}

if (!function_exists('company_sequence_add_column_if_missing')) {
  function company_sequence_add_column_if_missing(PDO $pdo, string $table, string $column, string $after = ''): bool {
    if (!company_sequence_identifier_ok($table) || !company_sequence_identifier_ok($column)) return false;
    if (!company_sequence_table_exists($pdo, $table)) return false;
    if (company_sequence_column_exists($pdo, $table, $column)) return true;
    $entities = company_sequence_entities();
    $columnType = 'INT UNSIGNED';
    foreach ($entities as $cfg) {
      if ((string)($cfg['table'] ?? '') === $table && (string)($cfg['column'] ?? '') === $column) {
        $candidate = strtoupper(trim((string)($cfg['type'] ?? 'INT UNSIGNED')));
        if (preg_match('/^(?:INT UNSIGNED|VARCHAR\([0-9]{1,3}\))$/', $candidate)) $columnType = $candidate;
        break;
      }
    }
    $sql = 'ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $columnType . ' NULL';
    if ($after !== '' && company_sequence_identifier_ok($after) && company_sequence_column_exists($pdo, $table, $after)) {
      $sql .= ' AFTER `' . $after . '`';
    }
    try {
      $pdo->exec($sql);
      return true;
    } catch (Throwable $e) {
      return company_sequence_column_exists($pdo, $table, $column);
    }
  }
}

if (!function_exists('company_sequence_ensure_schema')) {
  function company_sequence_ensure_schema(PDO $pdo, bool $backfill = false): void {
    // V11.25: no F11 a sequência usa exclusivamente a estrutura já instalada.
    if (!empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'])) return;
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS company_sequences (
        company_id INT UNSIGNED NOT NULL,
        entity VARCHAR(40) NOT NULL,
        next_value INT UNSIGNED NOT NULL DEFAULT 1,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (company_id, entity)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}

    foreach (company_sequence_entities() as $entity => $cfg) {
      company_sequence_add_column_if_missing($pdo, (string)$cfg['table'], (string)$cfg['column'], (string)($cfg['after'] ?? ''));
      if ($backfill) company_sequence_backfill_entity($pdo, $entity);
    }
  }
}

if (!function_exists('company_sequence_initialize_company')) {
  /**
   * Inicializa os contadores visíveis de uma nova empresa.
   * O primeiro cadastro de cada entidade receberá o número 1.
   *
   * A estrutura deve ser criada antes de iniciar a transação de cadastro da
   * empresa, evitando DDL no meio da transação.
   */
  function company_sequence_initialize_company(PDO $pdo, int $companyId): void {
    if ($companyId <= 0 || !company_sequence_table_exists($pdo, 'company_sequences')) return;
    $st = $pdo->prepare('INSERT IGNORE INTO company_sequences (company_id, entity, next_value) VALUES (?, ?, 1)');
    foreach (array_keys(company_sequence_entities()) as $entity) {
      $st->execute([$companyId, (string)$entity]);
    }
  }
}

if (!function_exists('company_sequence_numeric_max')) {
  /**
   * Maior código sequencial numérico utilizável pelo contador da empresa.
   * Para produtos, ignora códigos muito longos (EAN/códigos legados), evitando
   * que a próxima sequência salte para trilhões.
   */
  function company_sequence_numeric_max(PDO $pdo, string $table, string $column, int $companyId, string $entity): int {
    if ($companyId <= 0) return 0;
    try {
      if ($entity === 'produtos') {
        $sql = "SELECT COALESCE(MAX(CASE WHEN `{$column}` REGEXP '^[0-9]{1,10}$' AND CAST(`{$column}` AS UNSIGNED) <= 4294967294 THEN CAST(`{$column}` AS UNSIGNED) ELSE 0 END),0) FROM `{$table}` WHERE company_id=?";
      } else {
        $sql = "SELECT COALESCE(MAX(`{$column}`),0) FROM `{$table}` WHERE company_id=?";
      }
      $st = $pdo->prepare($sql);
      $st->execute([$companyId]);
      return max(0, (int)$st->fetchColumn());
    } catch (Throwable $e) {
      return 0;
    }
  }
}

if (!function_exists('company_sequence_backfill_entity')) {
  function company_sequence_backfill_entity(PDO $pdo, string $entity): void {
    $entities = company_sequence_entities();
    if (!isset($entities[$entity])) return;
    $table = (string)$entities[$entity]['table'];
    $column = (string)$entities[$entity]['column'];
    if (!company_sequence_table_exists($pdo, $table) || !company_sequence_column_exists($pdo, $table, $column)) return;

    try {
      $companies = $pdo->query('SELECT DISTINCT company_id FROM `' . $table . '` WHERE company_id IS NOT NULL ORDER BY company_id ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
      return;
    }

    foreach ($companies as $companyIdRaw) {
      $companyId = (int)$companyIdRaw;
      if ($companyId <= 0) continue;
      try {
        $next = max(1, company_sequence_numeric_max($pdo, $table, $column, $companyId, $entity) + 1);

        $stRows = $pdo->prepare('SELECT id FROM `' . $table . '` WHERE company_id = ? AND (`' . $column . '` IS NULL OR `' . $column . '` = 0) ORDER BY id ASC');
        $stRows->execute([$companyId]);
        $ids = $stRows->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($ids) {
          $upd = $pdo->prepare('UPDATE `' . $table . '` SET `' . $column . '` = ? WHERE company_id = ? AND id = ?');
          foreach ($ids as $idRaw) {
            $upd->execute([$next++, $companyId, (int)$idRaw]);
          }
        }

        $pdo->prepare('INSERT INTO company_sequences (company_id, entity, next_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE next_value = GREATEST(next_value, VALUES(next_value))')
          ->execute([$companyId, $entity, $next]);
      } catch (Throwable $e) {}
    }
  }
}

if (!function_exists('company_sequence_next')) {
  function company_sequence_next(PDO $pdo, int $companyId, string $entity): int {
    $entities = company_sequence_entities();
    if ($companyId <= 0 || !isset($entities[$entity])) return 0;
    if (!$pdo->inTransaction()) company_sequence_ensure_schema($pdo, false);

    $cfg = $entities[$entity];
    $table = (string)$cfg['table'];
    $column = (string)$cfg['column'];
    $baseNext = 1;
    if (company_sequence_table_exists($pdo, $table) && company_sequence_column_exists($pdo, $table, $column)) {
      try {
        $baseNext = max(1, company_sequence_numeric_max($pdo, $table, $column, $companyId, $entity) + 1);
      } catch (Throwable $e) {}
    }

    $ownTransaction = false;
    try {
      if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $ownTransaction = true;
      }

      // INSERT IGNORE elimina a corrida entre os dois primeiros pedidos de
      // uma empresa recém-criada. Depois, o FOR UPDATE serializa a reserva.
      $pdo->prepare('INSERT IGNORE INTO company_sequences (company_id, entity, next_value) VALUES (?, ?, ?)')
        ->execute([$companyId, $entity, $baseNext]);

      $st = $pdo->prepare('SELECT next_value FROM company_sequences WHERE company_id = ? AND entity = ? FOR UPDATE');
      $st->execute([$companyId, $entity]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) throw new RuntimeException('Não foi possível inicializar a sequência da empresa.');

      $number = max($baseNext, (int)$row['next_value']);
      $pdo->prepare('UPDATE company_sequences SET next_value = ? WHERE company_id = ? AND entity = ?')
        ->execute([$number + 1, $companyId, $entity]);

      if ($ownTransaction) $pdo->commit();
      return $number;
    } catch (Throwable $e) {
      if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
      return $baseNext;
    }
  }
}

if (!function_exists('company_sequence_column_for')) {
  function company_sequence_column_for(string $entity): ?string {
    $entities = company_sequence_entities();
    return isset($entities[$entity]) ? (string)$entities[$entity]['column'] : null;
  }
}

if (!function_exists('company_sequence_has_column')) {
  function company_sequence_has_column(PDO $pdo, string $entity): bool {
    $entities = company_sequence_entities();
    if (!isset($entities[$entity])) return false;
    return company_sequence_column_exists($pdo, (string)$entities[$entity]['table'], (string)$entities[$entity]['column']);
  }
}

if (!function_exists('company_sequence_display')) {
  function company_sequence_display(array $row, string $entity, string $fallbackColumn = 'id') {
    $column = company_sequence_column_for($entity);
    if ($column && array_key_exists($column, $row)) {
      if ($entity === 'produtos') {
        $value = trim((string)$row[$column]);
        if ($value !== '' && $value !== '0') return $value;
      } elseif ((int)$row[$column] > 0) {
        return (int)$row[$column];
      }
    }
    return (int)($row[$fallbackColumn] ?? 0);
  }
}

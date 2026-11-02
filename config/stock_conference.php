<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/stock_module.php';
require_once __DIR__ . '/company_sequences.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/product_composition.php';

if (!function_exists('stock_conference_h')) {
  function stock_conference_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('stock_conference_substr')) {
  function stock_conference_substr($value, int $max): string {
    $value = (string)$value;
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
  }
}

if (!function_exists('stock_conference_table_exists')) {
  function stock_conference_table_exists(PDO $pdo, string $table): bool {
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . strtolower($table);
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
      $st->execute([$table]);
      return $cache[$key] = (bool)$st->fetchColumn();
    } catch (Throwable $e) { return $cache[$key] = false; }
  }
}

if (!function_exists('stock_conference_column_exists')) {
  function stock_conference_column_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . strtolower($table) . ':' . strtolower($column);
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
      $st->execute([$table, $column]);
      return $cache[$key] = (bool)$st->fetchColumn();
    } catch (Throwable $e) { return $cache[$key] = false; }
  }
}

if (!function_exists('stock_conference_ensure_schema')) {
  function stock_conference_ensure_schema(PDO $pdo): void {
    if ($pdo->inTransaction()) return;
    stock_ensure_schema($pdo, true);
    company_sequence_ensure_schema($pdo, false);

    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_conferences (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      company_id BIGINT UNSIGNED NOT NULL,
      name VARCHAR(180) NOT NULL,
      counted_at DATETIME NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
      reference_at DATETIME NULL,
      processed_at DATETIME NULL,
      created_by BIGINT UNSIGNED NULL,
      processed_by BIGINT UNSIGNED NULL,
      notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      KEY idx_stock_conferences_company (company_id, status, counted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_conference_items (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      company_id BIGINT UNSIGNED NOT NULL,
      conference_id BIGINT UNSIGNED NOT NULL,
      product_id BIGINT UNSIGNED NOT NULL,
      counted_qty DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
      system_qty_reference DECIMAL(15,4) NULL,
      difference_qty DECIMAL(15,4) NULL,
      current_qty_before DECIMAL(15,4) NULL,
      current_qty_after DECIMAL(15,4) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_stock_conference_product (company_id, conference_id, product_id),
      KEY idx_stock_conference_items_conference (company_id, conference_id),
      KEY idx_stock_conference_items_product (company_id, product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }
}

if (!function_exists('stock_conference_datetime')) {
  function stock_conference_datetime(string $value, ?DateTimeImmutable $fallback = null): string {
    $value = trim($value);
    try {
      if ($value !== '') return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {}
    return ($fallback ?: new DateTimeImmutable())->format('Y-m-d H:i:s');
  }
}

if (!function_exists('stock_conference_get')) {
  function stock_conference_get(PDO $pdo, int $companyId, int $conferenceId, bool $forUpdate = false): ?array {
    $sql = 'SELECT * FROM stock_conferences WHERE company_id=? AND id=? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
    $st = $pdo->prepare($sql);
    $st->execute([$companyId, $conferenceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }
}

if (!function_exists('stock_conference_list')) {
  function stock_conference_list(PDO $pdo, int $companyId): array {
    $st = $pdo->prepare("SELECT c.*,
      (SELECT COUNT(*) FROM stock_conference_items i WHERE i.company_id=c.company_id AND i.conference_id=c.id) AS item_count,
      (SELECT COALESCE(SUM(i.counted_qty),0) FROM stock_conference_items i WHERE i.company_id=c.company_id AND i.conference_id=c.id) AS counted_total
      FROM stock_conferences c WHERE c.company_id=? ORDER BY c.id DESC LIMIT 300");
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('stock_conference_save_header')) {
  function stock_conference_save_header(PDO $pdo, int $companyId, int $conferenceId, string $name, string $countedAt, string $notes = ''): int {
    $name = trim($name);
    if ($name === '') throw new RuntimeException('Informe o nome da conferência.');
    $countedAt = stock_conference_datetime($countedAt);
    if ($conferenceId > 0) {
      $current = stock_conference_get($pdo, $companyId, $conferenceId);
      if (!$current) throw new RuntimeException('Conferência não encontrada.');
      if ((string)$current['status'] !== 'DRAFT') throw new RuntimeException('A conferência processada não pode ser alterada.');
      $pdo->prepare('UPDATE stock_conferences SET name=?,counted_at=?,notes=?,updated_at=NOW() WHERE company_id=? AND id=?')
        ->execute([stock_conference_substr($name,180), $countedAt, trim($notes) ?: null, $companyId, $conferenceId]);
      return $conferenceId;
    }
    $pdo->prepare("INSERT INTO stock_conferences (company_id,name,counted_at,status,created_by,notes) VALUES (?,?,?,'DRAFT',?,?)")
      ->execute([$companyId, stock_conference_substr($name,180), $countedAt, auth_user_id() ?: null, trim($notes) ?: null]);
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('stock_conference_product_code_sql')) {
  function stock_conference_product_code_sql(PDO $pdo): array {
    $hasNumber = stock_conference_column_exists($pdo, 'produtos', 'numero_cadastro');
    $hasDirect = stock_conference_column_exists($pdo, 'produtos', 'codigo_firebird');
    $hasMap = stock_conference_table_exists($pdo, 'produtos_firebird_map') && stock_conference_column_exists($pdo, 'produtos_firebird_map', 'codigo_firebird');
    $join = $hasMap ? ' LEFT JOIN produtos_firebird_map pfm ON pfm.company_id=p.company_id AND pfm.produto_id=p.id' : '';
    $parts = [];
    if ($hasNumber) $parts[] = "NULLIF(TRIM(p.numero_cadastro),'')";
    if ($hasDirect) $parts[] = "NULLIF(TRIM(p.codigo_firebird),'')";
    if ($hasMap) $parts[] = "NULLIF(TRIM(pfm.codigo_firebird),'')";
    $parts[] = 'CAST(p.id AS CHAR)';
    return ['expr' => 'COALESCE(' . implode(',', $parts) . ')', 'join' => $join, 'has_number' => $hasNumber, 'has_direct' => $hasDirect, 'has_map' => $hasMap];
  }
}

if (!function_exists('stock_conference_barcode_meta')) {
  function stock_conference_barcode_meta(PDO $pdo): array {
    $columns = [];
    foreach (['codigo_barras','ean','gtin','barcode'] as $column) {
      if (stock_conference_column_exists($pdo, 'produtos', $column)) $columns[] = $column;
    }
    $parts = [];
    foreach ($columns as $column) $parts[] = "NULLIF(TRIM(p.`" . str_replace('`', '``', $column) . "`),'')";
    return [
      'columns' => $columns,
      'expr' => $parts ? 'COALESCE(' . implode(',', $parts) . ",'')" : "''",
    ];
  }
}

if (!function_exists('stock_conference_find_product')) {
  /** Pesquisa em prioridades distintas para evitar colisão entre número comercial e ID interno. */
  function stock_conference_find_product(PDO $pdo, int $companyId, string $code, bool $allowInternalId = true): ?array {
    $code = trim($code);
    if ($code === '') return null;
    $meta = stock_conference_product_code_sql($pdo);
    $barcode = stock_conference_barcode_meta($pdo);
    $conditions = [];
    if ($meta['has_number']) $conditions[] = ['p.numero_cadastro=?', $code];
    foreach ($barcode['columns'] as $column) $conditions[] = ['TRIM(p.`' . str_replace('`', '``', $column) . '`)=?', $code];
    if ($meta['has_direct']) $conditions[] = ['TRIM(p.codigo_firebird)=?', $code];
    if ($meta['has_map']) $conditions[] = ['TRIM(pfm.codigo_firebird)=?', $code];
    if (stock_conference_column_exists($pdo, 'produtos', 'referencia')) $conditions[] = ['TRIM(p.referencia)=?', $code];
    if ($allowInternalId && ctype_digit($code)) $conditions[] = ['p.id=?', (int)$code];
    $unitExpr = stock_conference_column_exists($pdo, 'produtos', 'unidade_estoque') ? "COALESCE(NULLIF(p.unidade_estoque,''),'UN')" : "'UN'";
    $stockExpr = stock_conference_column_exists($pdo, 'produtos', 'estoque_atual') ? 'COALESCE(p.estoque_atual,0)' : '0';
    $base = 'SELECT p.id,p.nome,' . $meta['expr'] . ' AS product_code,' . $barcode['expr'] . ' AS codigo_barras,' . $unitExpr . ' AS unidade,' . $stockExpr . ' AS estoque_atual FROM produtos p' . $meta['join'] . ' WHERE p.company_id=? AND ';
    foreach ($conditions as [$where, $value]) {
      $st = $pdo->prepare($base . $where . ' ORDER BY p.id ASC LIMIT 1');
      $st->execute([$companyId, $value]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row) return $row;
    }
    return null;
  }
}

if (!function_exists('stock_conference_search_products')) {
  function stock_conference_search_products(PDO $pdo, int $companyId, string $query, int $limit = 30): array {
    $query = trim($query);
    if ($query === '') return [];
    $limit = max(1, min(50, $limit));
    $meta = stock_conference_product_code_sql($pdo);
    $barcode = stock_conference_barcode_meta($pdo);
    $unitExpr = stock_conference_column_exists($pdo, 'produtos', 'unidade_estoque') ? "COALESCE(NULLIF(p.unidade_estoque,''),'UN')" : "'UN'";
    $hasDescription = stock_conference_column_exists($pdo, 'produtos', 'descricao');
    $like = nexa_search_like($query);
    $where = ['p.nome LIKE ?', 'CAST(p.id AS CHAR) LIKE ?'];
    $params = [$companyId, $like, $like];
    if ($hasDescription) { $where[] = "COALESCE(p.descricao,'') LIKE ?"; $params[] = $like; }
    if ($meta['has_number']) { $where[] = 'CAST(p.numero_cadastro AS CHAR) LIKE ?'; $params[] = $like; }
    if ($meta['has_direct']) { $where[] = "COALESCE(p.codigo_firebird,'') LIKE ?"; $params[] = $like; }
    if (stock_conference_column_exists($pdo, 'produtos', 'referencia')) { $where[] = "COALESCE(p.referencia,'') LIKE ?"; $params[] = $like; }
    if ($meta['has_map']) { $where[] = "COALESCE(pfm.codigo_firebird,'') LIKE ?"; $params[] = $like; }
    foreach ($barcode['columns'] as $column) { $where[] = "COALESCE(p.`" . str_replace('`', '``', $column) . "`,'') LIKE ?"; $params[] = $like; }
    $descriptionExpr = $hasDescription ? "COALESCE(p.descricao,'')" : "''";
    $sql = 'SELECT DISTINCT p.id,p.nome,' . $descriptionExpr . ' AS descricao,' . $meta['expr'] . ' AS product_code,' . $barcode['expr'] . ' AS codigo_barras,' . $unitExpr . ' AS unidade FROM produtos p' . $meta['join'] . ' WHERE p.company_id=? AND (' . implode(' OR ', $where) . ') ORDER BY p.nome ASC,p.id ASC LIMIT ' . $limit;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('stock_conference_upsert_product_qty')) {
  function stock_conference_upsert_product_qty(PDO $pdo, int $companyId, int $conferenceId, int $productId, float $quantity): void {
    static $statements = [];
    $key = spl_object_id($pdo);
    if (!isset($statements[$key])) {
      $statements[$key] = $pdo->prepare('INSERT INTO stock_conference_items (company_id,conference_id,product_id,counted_qty,updated_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE counted_qty=counted_qty+VALUES(counted_qty),updated_at=NOW(),system_qty_reference=NULL,difference_qty=NULL,current_qty_before=NULL,current_qty_after=NULL');
    }
    $statements[$key]->execute([$companyId, $conferenceId, $productId, round($quantity, 4)]);
  }
}

if (!function_exists('stock_conference_add_item')) {
  function stock_conference_add_item(PDO $pdo, int $companyId, int $conferenceId, string $productCode, $quantity): array {
    $conference = stock_conference_get($pdo, $companyId, $conferenceId);
    if (!$conference) throw new RuntimeException('Conferência não encontrada.');
    if ((string)$conference['status'] !== 'DRAFT') throw new RuntimeException('A conferência já foi processada.');
    $product = stock_conference_find_product($pdo, $companyId, $productCode);
    if (!$product) throw new RuntimeException('Produto não localizado pelo código ou código de barras informado.');
    $qty = stock_parse_decimal($quantity);
    if ($qty < 0) throw new RuntimeException('A quantidade contada não pode ser negativa.');
    stock_conference_upsert_product_qty($pdo, $companyId, $conferenceId, (int)$product['id'], $qty);
    $product['added_qty'] = $qty;
    return $product;
  }
}

if (!function_exists('stock_conference_parse_collector_line')) {
  function stock_conference_parse_collector_line(string $line, int $lineNumber): array {
    $line = rtrim($line, "\r\n");
    if ($lineNumber === 1 && strncmp($line, "\xEF\xBB\xBF", 3) === 0) $line = substr($line, 3);
    if (trim($line) === '') return ['blank' => true];
    if (strlen($line) < 22) throw new RuntimeException('A linha deve possuir 20 posições para o código, um espaço e a quantidade.');
    $code = trim(substr($line, 0, 20));
    $separator = substr($line, 20, 1);
    $quantityText = trim(substr($line, 21));
    if ($code === '') throw new RuntimeException('O código do produto está vazio nas primeiras 20 posições.');
    if (!ctype_space($separator)) throw new RuntimeException('A posição 21 deve ser um espaço entre o código e a quantidade.');
    $normalized = str_replace(' ', '', $quantityText);
    if ($normalized === '' || !preg_match('/^[+-]?\d+(?:[.,]\d+)?$/', $normalized)) throw new RuntimeException('A quantidade informada é inválida.');
    $quantity = stock_parse_decimal($normalized);
    if ($quantity < 0) throw new RuntimeException('A quantidade não pode ser negativa.');
    return ['blank' => false, 'code' => $code, 'quantity' => $quantity];
  }
}

if (!function_exists('stock_conference_import_collector_txt')) {
  function stock_conference_import_collector_txt(PDO $pdo, int $companyId, int $conferenceId, string $filePath): array {
    $conference = stock_conference_get($pdo, $companyId, $conferenceId);
    if (!$conference) throw new RuntimeException('Conferência não encontrada.');
    if ((string)$conference['status'] !== 'DRAFT') throw new RuntimeException('Não é possível importar em uma conferência já processada.');
    if (!is_file($filePath) || !is_readable($filePath)) throw new RuntimeException('O arquivo TXT enviado não pôde ser lido.');
    $size = filesize($filePath);
    if ($size !== false && $size > 5 * 1024 * 1024) throw new RuntimeException('O arquivo TXT deve possuir no máximo 5 MB.');

    $handle = fopen($filePath, 'rb');
    if (!$handle) throw new RuntimeException('Não foi possível abrir o arquivo TXT.');
    $codes = [];
    $totalLines = 0;
    $blankLines = 0;
    $validLines = 0;
    $invalidCount = 0;
    $invalidLines = [];
    try {
      while (($line = fgets($handle)) !== false) {
        $totalLines++;
        if ($totalLines > 100000) throw new RuntimeException('O arquivo ultrapassa o limite de 100.000 linhas.');
        try {
          $parsed = stock_conference_parse_collector_line($line, $totalLines);
          if (!empty($parsed['blank'])) { $blankLines++; continue; }
          $code = (string)$parsed['code'];
          $key = 'CODE:' . $code;
          if (!isset($codes[$key])) $codes[$key] = ['code' => $code, 'quantity' => 0.0];
          $codes[$key]['quantity'] = round((float)$codes[$key]['quantity'] + (float)$parsed['quantity'], 4);
          $validLines++;
        } catch (Throwable $e) {
          $invalidCount++;
          if (count($invalidLines) < 50) $invalidLines[] = ['line' => $totalLines, 'message' => $e->getMessage(), 'content' => stock_conference_substr(rtrim($line, "\r\n"), 120)];
        }
      }
    } finally {
      fclose($handle);
    }
    if (!$codes) throw new RuntimeException('Nenhuma linha válida foi encontrada no arquivo TXT.');

    $notFound = [];
    $importedProducts = [];
    $importedQuantity = 0.0;
    $pdo->beginTransaction();
    try {
      $locked = stock_conference_get($pdo, $companyId, $conferenceId, true);
      if (!$locked || (string)$locked['status'] !== 'DRAFT') throw new RuntimeException('A conferência não está mais disponível para importação.');
      foreach ($codes as $entry) {
        $code = (string)$entry['code'];
        $quantity = (float)$entry['quantity'];
        $product = stock_conference_find_product($pdo, $companyId, $code, false);
        if (!$product) { $notFound[] = $code; continue; }
        stock_conference_upsert_product_qty($pdo, $companyId, $conferenceId, (int)$product['id'], $quantity);
        $importedProducts[(int)$product['id']] = true;
        $importedQuantity += $quantity;
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }

    return [
      'total_lines' => $totalLines,
      'blank_lines' => $blankLines,
      'valid_lines' => $validLines,
      'invalid_count' => $invalidCount,
      'invalid_lines' => $invalidLines,
      'unique_codes' => count($codes),
      'imported_products' => count($importedProducts),
      'imported_quantity' => round($importedQuantity, 4),
      'not_found_count' => count($notFound),
      'not_found_codes' => array_slice($notFound, 0, 100),
    ];
  }
}

if (!function_exists('stock_conference_remove_item')) {
  function stock_conference_remove_item(PDO $pdo, int $companyId, int $conferenceId, int $itemId): void {
    $conference = stock_conference_get($pdo, $companyId, $conferenceId);
    if (!$conference || (string)$conference['status'] !== 'DRAFT') throw new RuntimeException('Somente conferências em digitação podem ser alteradas.');
    $pdo->prepare('DELETE FROM stock_conference_items WHERE company_id=? AND conference_id=? AND id=?')->execute([$companyId,$conferenceId,$itemId]);
  }
}

if (!function_exists('stock_conference_items')) {
  function stock_conference_items(PDO $pdo, int $companyId, int $conferenceId): array {
    $meta = stock_conference_product_code_sql($pdo);
    $unitExpr = stock_conference_column_exists($pdo, 'produtos', 'unidade_estoque') ? "COALESCE(NULLIF(p.unidade_estoque,''),'UN')" : "'UN'";
    $sql = 'SELECT i.*,p.nome,' . $meta['expr'] . ' AS product_code,' . $unitExpr . ' AS unidade FROM stock_conference_items i INNER JOIN produtos p ON p.company_id=i.company_id AND p.id=i.product_id' . $meta['join'] . ' WHERE i.company_id=? AND i.conference_id=? ORDER BY p.nome,p.id';
    $st = $pdo->prepare($sql);
    $st->execute([$companyId,$conferenceId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('stock_conference_stock_at')) {
  function stock_conference_stock_at(PDO $pdo, int $companyId, int $productId, string $referenceAt, ?float $fallbackCurrent = null): float {
    $referenceAt = stock_conference_datetime($referenceAt);
    if (stock_conference_table_exists($pdo, 'estoque_movimentos')) {
      try {
        $st = $pdo->prepare('SELECT saldo_posterior FROM estoque_movimentos WHERE company_id=? AND produto_id=? AND created_at<=? ORDER BY created_at DESC,id DESC LIMIT 1');
        $st->execute([$companyId,$productId,$referenceAt]);
        $value = $st->fetchColumn();
        if ($value !== false) return (float)$value;
        $st = $pdo->prepare('SELECT saldo_anterior FROM estoque_movimentos WHERE company_id=? AND produto_id=? AND created_at>? ORDER BY created_at ASC,id ASC LIMIT 1');
        $st->execute([$companyId,$productId,$referenceAt]);
        $value = $st->fetchColumn();
        if ($value !== false) return (float)$value;
      } catch (Throwable $e) {}
    }
    if ($fallbackCurrent !== null) return $fallbackCurrent;
    $st = $pdo->prepare('SELECT COALESCE(estoque_atual,0) FROM produtos WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId,$productId]);
    return (float)($st->fetchColumn() ?: 0);
  }
}

if (!function_exists('stock_conference_preview')) {
  function stock_conference_preview(PDO $pdo, int $companyId, int $conferenceId, string $referenceAt): array {
    $conference = stock_conference_get($pdo, $companyId, $conferenceId);
    if (!$conference) throw new RuntimeException('Conferência não encontrada.');
    $referenceAt = stock_conference_datetime($referenceAt);
    $items = stock_conference_items($pdo, $companyId, $conferenceId);
    foreach ($items as &$item) {
      $current = (float)($item['current_qty_before'] ?? 0);
      if ((string)$conference['status'] === 'PROCESSED' && $item['system_qty_reference'] !== null) {
        $system = (float)$item['system_qty_reference'];
        $diff = (float)$item['difference_qty'];
      } else {
        $st = $pdo->prepare('SELECT COALESCE(estoque_atual,0) FROM produtos WHERE company_id=? AND id=?');
        $st->execute([$companyId,(int)$item['product_id']]);
        $current = (float)($st->fetchColumn() ?: 0);
        $system = stock_conference_stock_at($pdo,$companyId,(int)$item['product_id'],$referenceAt,$current);
        $diff = (float)$item['counted_qty'] - $system;
      }
      $item['system_qty_reference'] = $system;
      $item['difference_qty'] = $diff;
      $item['current_qty_before'] = $current;
      $item['current_qty_after'] = $current + $diff;
    }
    unset($item);
    return ['conference'=>$conference,'reference_at'=>$referenceAt,'items'=>$items];
  }
}

if (!function_exists('stock_conference_process')) {
  function stock_conference_process(PDO $pdo, int $companyId, int $conferenceId, string $referenceAt): array {
    $referenceAt = stock_conference_datetime($referenceAt);
    $pdo->beginTransaction();
    try {
      $conference = stock_conference_get($pdo,$companyId,$conferenceId,true);
      if (!$conference) throw new RuntimeException('Conferência não encontrada.');
      if ((string)$conference['status'] !== 'DRAFT') throw new RuntimeException('Esta conferência já foi processada.');

      $stItems = $pdo->prepare('SELECT * FROM stock_conference_items WHERE company_id=? AND conference_id=? ORDER BY id FOR UPDATE');
      $stItems->execute([$companyId,$conferenceId]);
      $items = $stItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
      if (!$items) throw new RuntimeException('A conferência não possui produtos contados.');

      $stDuplicate = $pdo->prepare("SELECT COUNT(*) FROM estoque_movimentos WHERE company_id=? AND origem_tipo='CONFERENCIA_ESTOQUE' AND origem_id=?");
      $stDuplicate->execute([$companyId,$conferenceId]);
      if ((int)$stDuplicate->fetchColumn() > 0) throw new RuntimeException('Já existem movimentos de estoque para esta conferência.');

      $selProduct = $pdo->prepare('SELECT id,nome,COALESCE(estoque_atual,0) AS estoque_atual FROM produtos WHERE company_id=? AND id=? FOR UPDATE');
      $updProduct = $pdo->prepare('UPDATE produtos SET estoque_atual=? WHERE company_id=? AND id=?');
      $updItem = $pdo->prepare('UPDATE stock_conference_items SET system_qty_reference=?,difference_qty=?,current_qty_before=?,current_qty_after=?,updated_at=NOW() WHERE company_id=? AND conference_id=? AND id=?');
      $insMovement = $pdo->prepare("INSERT INTO estoque_movimentos (company_id,produto_id,tipo,origem_tipo,origem_id,qtd,saldo_anterior,saldo_posterior,observacao) VALUES (?,?,'ajuste','CONFERENCIA_ESTOQUE',?,?,?,?,?)");

      $changed = 0;
      foreach ($items as $item) {
        $selProduct->execute([$companyId,(int)$item['product_id']]);
        $product = $selProduct->fetch(PDO::FETCH_ASSOC);
        if (!$product) throw new RuntimeException('Produto da conferência não encontrado: #' . (int)$item['product_id']);
        $current = (float)$product['estoque_atual'];
        $systemAtReference = stock_conference_stock_at($pdo,$companyId,(int)$item['product_id'],$referenceAt,$current);
        $counted = (float)$item['counted_qty'];
        $difference = round($counted - $systemAtReference, 4);
        $after = round($current + $difference, 4);
        $updProduct->execute([$after,$companyId,(int)$item['product_id']]);
        $updItem->execute([$systemAtReference,$difference,$current,$after,$companyId,$conferenceId,(int)$item['id']]);
        if (abs($difference) > 0.00005) {
          $obs = 'Ajuste pela conferência de estoque #' . $conferenceId . ' (' . (string)$conference['name'] . '). Data/hora de referência: ' . date('d/m/Y H:i', strtotime($referenceAt)) . '.';
          $insMovement->execute([$companyId,(int)$item['product_id'],$conferenceId,$difference,$current,$after,$obs]);
          $changed++;
        }
      }

      $pdo->prepare("UPDATE stock_conferences SET status='PROCESSED',reference_at=?,processed_at=NOW(),processed_by=?,updated_at=NOW() WHERE company_id=? AND id=?")
        ->execute([$referenceAt,auth_user_id() ?: null,$companyId,$conferenceId]);
      if (function_exists('product_composition_enabled') && product_composition_enabled($pdo, $companyId) && product_composition_schema_ready($pdo)) {
        product_composition_recalculate_all($pdo, $companyId, 'Conferência de estoque #' . $conferenceId);
      }
      $pdo->commit();
      return ['items'=>count($items),'changed'=>$changed,'reference_at'=>$referenceAt];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('stock_conference_delete')) {
  function stock_conference_delete(PDO $pdo, int $companyId, int $conferenceId): void {
    $conference = stock_conference_get($pdo,$companyId,$conferenceId);
    if (!$conference) return;
    if ((string)$conference['status'] !== 'DRAFT') throw new RuntimeException('Conferências processadas não podem ser excluídas.');
    $pdo->beginTransaction();
    try {
      $pdo->prepare('DELETE FROM stock_conference_items WHERE company_id=? AND conference_id=?')->execute([$companyId,$conferenceId]);
      $pdo->prepare('DELETE FROM stock_conferences WHERE company_id=? AND id=?')->execute([$companyId,$conferenceId]);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/fiscal_module.php';
require_once __DIR__ . '/fiscal_pdf_reports.php';
require_once __DIR__ . '/fiscal_document_reports.php';

if (!function_exists('inventory_ensure_schema')) {
  function inventory_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_inventories (
      id BIGINT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      inventory_date DATE NOT NULL,
      stock_filter VARCHAR(20) NOT NULL DEFAULT 'positivos',
      cost_method VARCHAR(20) NOT NULL DEFAULT 'average',
      book_number INT NOT NULL DEFAULT 1,
      initial_page INT NOT NULL DEFAULT 1,
      initial_sheet INT NOT NULL DEFAULT 1,
      final_page INT NULL,
      final_sheet INT NULL,
      page_count INT NOT NULL DEFAULT 0,
      excluded_groups_json LONGTEXT NULL,
      item_count INT NOT NULL DEFAULT 0,
      total_quantity DECIMAL(18,4) NOT NULL DEFAULT 0,
      total_value DECIMAL(18,2) NOT NULL DEFAULT 0,
      warning_message TEXT NULL,
      notes TEXT NULL,
      generated_by_user_id INT NULL,
      generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      KEY idx_fiscal_inventories_company_date (company_id,inventory_date,id),
      KEY idx_fiscal_inventories_company_created (company_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_inventory_items (
      id BIGINT NOT NULL AUTO_INCREMENT,
      inventory_id BIGINT NOT NULL,
      company_id INT NOT NULL,
      product_id BIGINT NOT NULL,
      product_code VARCHAR(80) NULL,
      product_name VARCHAR(255) NOT NULL,
      product_group VARCHAR(160) NULL,
      unit VARCHAR(20) NULL,
      quantity DECIMAL(18,4) NOT NULL DEFAULT 0,
      last_cost DECIMAL(18,4) NOT NULL DEFAULT 0,
      average_cost DECIMAL(18,4) NOT NULL DEFAULT 0,
      valuation_cost DECIMAL(18,4) NOT NULL DEFAULT 0,
      total_value DECIMAL(18,2) NOT NULL DEFAULT 0,
      stock_source VARCHAR(30) NOT NULL DEFAULT 'ATUAL',
      cost_source VARCHAR(40) NOT NULL DEFAULT 'ATUAL',
      ncm VARCHAR(20) NULL,
      cest VARCHAR(20) NULL,
      cfop VARCHAR(10) NULL,
      icms_cst VARCHAR(10) NULL,
      icms_csosn VARCHAR(10) NULL,
      pis_cst VARCHAR(10) NULL,
      cofins_cst VARCHAR(10) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_inventory_item (inventory_id,product_id),
      KEY idx_fiscal_inventory_items_company (company_id,inventory_id),
      KEY idx_fiscal_inventory_items_group (inventory_id,product_group)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Atualiza instalações que já possuíam a primeira versão da tabela de inventários.
    foreach ([
      'initial_sheet' => 'INT NOT NULL DEFAULT 1 AFTER initial_page',
      'final_page' => 'INT NULL AFTER initial_sheet',
      'final_sheet' => 'INT NULL AFTER final_page',
      'page_count' => 'INT NOT NULL DEFAULT 0 AFTER final_sheet',
    ] as $column => $definition) {
      if (!fiscal_column_exists($pdo, 'fiscal_inventories', $column)) {
        try { $pdo->exec("ALTER TABLE fiscal_inventories ADD COLUMN {$column} {$definition}"); } catch (Throwable $e) {}
      }
    }

    // Mantém os campos mínimos necessários para registrar custos históricos daqui em diante.
    if (fiscal_table_exists($pdo, 'produtos')) {
      foreach ([
        'custo_medio' => 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000',
        'custo_ultima_compra' => 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000',
        'data_ultima_compra' => 'DATETIME NULL',
      ] as $column => $definition) {
        if (!fiscal_column_exists($pdo, 'produtos', $column)) {
          try { $pdo->exec("ALTER TABLE produtos ADD COLUMN {$column} {$definition}"); } catch (Throwable $e) {}
        }
      }
    }
    if (fiscal_table_exists($pdo, 'estoque_movimentos')) {
      foreach ([
        'custo_unitario' => 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000',
        'valor_movimento' => 'DECIMAL(15,2) NOT NULL DEFAULT 0.00',
        'custo_medio_anterior' => 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000',
        'custo_medio_posterior' => 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000',
      ] as $column => $definition) {
        if (!fiscal_column_exists($pdo, 'estoque_movimentos', $column)) {
          try { $pdo->exec("ALTER TABLE estoque_movimentos ADD COLUMN {$column} {$definition}"); } catch (Throwable $e) {}
        }
      }
    }
  }
}

if (!function_exists('inventory_validate_date')) {
  function inventory_validate_date(string $value, bool $monthEndRequired = true): string {
    $value = trim($value);
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) throw new RuntimeException('Informe uma data válida para o inventário.');
    if ($monthEndRequired && $value !== $dt->format('Y-m-t')) throw new RuntimeException('A data do inventário deve ser o último dia do mês.');
    return $value;
  }
}

if (!function_exists('inventory_month_end')) {
  function inventory_month_end(string $date): string {
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$dt) $dt = new DateTimeImmutable('today');
    return $dt->format('Y-m-t');
  }
}

if (!function_exists('inventory_product_groups')) {
  function inventory_product_groups(PDO $pdo, int $companyId): array {
    try {
      $st = $pdo->prepare("SELECT DISTINCT TRIM(COALESCE(categoria,'')) grupo FROM produtos WHERE company_id=? AND TRIM(COALESCE(categoria,''))<>'' ORDER BY grupo");
      $st->execute([$companyId]);
      return array_values(array_filter(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []), static function(string $v): bool { return trim($v) !== ''; }));
    } catch (Throwable $e) { return []; }
  }
}

if (!function_exists('inventory_collect_rows')) {
  /**
   * Obtém saldo e custos na data de referência. Quando não há histórico suficiente,
   * usa os valores atuais e sinaliza a origem no snapshot.
   */
  function inventory_collect_rows(PDO $pdo, int $companyId, string $inventoryDate, string $stockFilter = 'positivos', string $costMethod = 'average', array $excludedGroups = []): array {
    $inventoryDate = inventory_validate_date($inventoryDate, true);
    if (!in_array($stockFilter, ['todos','positivos','negativos'], true)) $stockFilter = 'positivos';
    if (!in_array($costMethod, ['average','last'], true)) $costMethod = 'average';
    $excludedGroups = array_values(array_unique(array_filter(array_map(static function($v): string { return trim((string)$v); }, $excludedGroups), static function(string $v): bool { return $v !== ''; })));

    $refAt = $inventoryDate . ' 23:59:59';
    $refQuoted = $pdo->quote($refAt);
    $dateQuoted = $pdo->quote($inventoryDate);
    $hasMovements = fiscal_table_exists($pdo, 'estoque_movimentos');
    $hasMovCost = $hasMovements && fiscal_column_exists($pdo, 'estoque_movimentos', 'custo_unitario');
    $hasMovAverage = $hasMovements && fiscal_column_exists($pdo, 'estoque_movimentos', 'custo_medio_posterior') && fiscal_column_exists($pdo, 'estoque_movimentos', 'custo_medio_anterior');
    $hasAverage = fiscal_column_exists($pdo, 'produtos', 'custo_medio');
    $hasLastPurchase = fiscal_column_exists($pdo, 'produtos', 'custo_ultima_compra');
    $hasLastPurchaseDate = fiscal_column_exists($pdo, 'produtos', 'data_ultima_compra');
    $hasCode = fiscal_column_exists($pdo, 'produtos', 'codigo_firebird');
    $hasStock = fiscal_column_exists($pdo, 'produtos', 'estoque_atual');
    $hasCost = fiscal_column_exists($pdo, 'produtos', 'custo');
    $hasUnit = fiscal_column_exists($pdo, 'produtos', 'unidade_estoque');
    $hasTax = fiscal_table_exists($pdo, 'fiscal_product_tax');
    $hasXmlHistory = fiscal_table_exists($pdo, 'fiscal_xml_items') && fiscal_table_exists($pdo, 'fiscal_xml_imports') && fiscal_column_exists($pdo, 'fiscal_xml_items', 'stock_unit_cost');

    $codeExpr = $hasCode ? 'p.codigo_firebird' : 'CAST(p.id AS CHAR)';
    $currentStockExpr = $hasStock ? 'COALESCE(p.estoque_atual,0)' : '0';
    $currentLastExpr = $hasLastPurchase ? 'NULLIF(p.custo_ultima_compra,0)' : 'NULL';
    if ($hasLastPurchase && $hasLastPurchaseDate) $currentLastExpr = "CASE WHEN p.data_ultima_compra IS NULL OR p.data_ultima_compra<={$refQuoted} THEN NULLIF(p.custo_ultima_compra,0) ELSE NULL END";
    $baseCostExpr = $hasCost ? 'NULLIF(p.custo,0)' : 'NULLIF(p.preco,0)';
    $currentAverageExpr = $hasAverage ? 'NULLIF(p.custo_medio,0)' : 'NULL';
    $unitExpr = $hasUnit ? "COALESCE(NULLIF(p.unidade_estoque,''),'UN')" : "'UN'";

    if ($hasMovements) {
      $stockExpr = "COALESCE(
        (SELECT em.saldo_posterior FROM estoque_movimentos em WHERE em.company_id=p.company_id AND em.produto_id=p.id AND em.created_at<={$refQuoted} ORDER BY em.created_at DESC,em.id DESC LIMIT 1),
        (SELECT em.saldo_anterior FROM estoque_movimentos em WHERE em.company_id=p.company_id AND em.produto_id=p.id AND em.created_at>{$refQuoted} ORDER BY em.created_at ASC,em.id ASC LIMIT 1),
        {$currentStockExpr})";
      $stockSourceExpr = "CASE WHEN EXISTS(SELECT 1 FROM estoque_movimentos em WHERE em.company_id=p.company_id AND em.produto_id=p.id LIMIT 1) THEN 'MOVIMENTO' ELSE 'ATUAL' END";
    } else {
      $stockExpr = $currentStockExpr;
      $stockSourceExpr = "'ATUAL'";
    }

    $lastCandidates = [];
    if ($hasMovCost) {
      $lastCandidates[] = "NULLIF((SELECT em.custo_unitario FROM estoque_movimentos em WHERE em.company_id=p.company_id AND em.produto_id=p.id AND LOWER(COALESCE(em.tipo,''))='entrada' AND em.created_at<={$refQuoted} AND COALESCE(em.custo_unitario,0)>0 ORDER BY em.created_at DESC,em.id DESC LIMIT 1),0)";
    }
    if ($hasXmlHistory) {
      $lastCandidates[] = "NULLIF((SELECT xi.stock_unit_cost FROM fiscal_xml_items xi INNER JOIN fiscal_xml_imports xx ON xx.id=xi.xml_import_id AND xx.company_id=xi.company_id WHERE xi.company_id=p.company_id AND xi.product_id=p.id AND COALESCE(xx.entry_date,xx.issue_date)<={$dateQuoted} AND COALESCE(xi.stock_unit_cost,0)>0 ORDER BY COALESCE(xx.entry_date,xx.issue_date) DESC,xi.id DESC LIMIT 1),0)";
    }
    $lastCandidates[] = $currentLastExpr;
    $lastCandidates[] = $baseCostExpr;
    $lastCandidates[] = '0';
    $lastCostExpr = 'COALESCE(' . implode(',', $lastCandidates) . ')';

    $averageCandidates = [];
    if ($hasMovAverage) {
      $averageCandidates[] = "NULLIF((SELECT em.custo_medio_posterior FROM estoque_movimentos em WHERE em.company_id=p.company_id AND em.produto_id=p.id AND em.created_at<={$refQuoted} AND COALESCE(em.custo_medio_posterior,0)>0 ORDER BY em.created_at DESC,em.id DESC LIMIT 1),0)";
      $averageCandidates[] = "NULLIF((SELECT em.custo_medio_anterior FROM estoque_movimentos em WHERE em.company_id=p.company_id AND em.produto_id=p.id AND em.created_at>{$refQuoted} AND COALESCE(em.custo_medio_anterior,0)>0 ORDER BY em.created_at ASC,em.id ASC LIMIT 1),0)";
    }
    $averageCandidates[] = $currentAverageExpr;
    $averageCandidates[] = $lastCostExpr;
    $averageCandidates[] = '0';
    $averageCostExpr = 'COALESCE(' . implode(',', $averageCandidates) . ')';

    $costSourceExpr = ($hasMovCost || $hasMovAverage || $hasXmlHistory)
      ? "CASE WHEN " . ($hasMovAverage ? "EXISTS(SELECT 1 FROM estoque_movimentos em WHERE em.company_id=p.company_id AND em.produto_id=p.id AND em.created_at<={$refQuoted} AND COALESCE(em.custo_medio_posterior,0)>0 LIMIT 1) OR " : '')
        . ($hasMovCost ? "EXISTS(SELECT 1 FROM estoque_movimentos em WHERE em.company_id=p.company_id AND em.produto_id=p.id AND LOWER(COALESCE(em.tipo,''))='entrada' AND em.created_at<={$refQuoted} AND COALESCE(em.custo_unitario,0)>0 LIMIT 1) OR " : '')
        . ($hasXmlHistory ? "EXISTS(SELECT 1 FROM fiscal_xml_items xi INNER JOIN fiscal_xml_imports xx ON xx.id=xi.xml_import_id AND xx.company_id=xi.company_id WHERE xi.company_id=p.company_id AND xi.product_id=p.id AND COALESCE(xx.entry_date,xx.issue_date)<={$dateQuoted} AND COALESCE(xi.stock_unit_cost,0)>0 LIMIT 1)" : '0=1')
        . " THEN 'HISTORICO' ELSE 'ATUAL' END"
      : "'ATUAL'";

    $taxSelect = $hasTax
      ? "pt.ncm,pt.cest,pt.cfop_entry_state AS cfop,pt.icms_cst,pt.icms_csosn,pt.pis_cst,pt.cofins_cst"
      : "'' AS ncm,'' AS cest,'' AS cfop,'' AS icms_cst,'' AS icms_csosn,'' AS pis_cst,'' AS cofins_cst";
    $join = $hasTax ? ' LEFT JOIN fiscal_product_tax pt ON pt.company_id=p.company_id AND pt.product_id=p.id' : '';

    $where = ['p.company_id=?'];
    $params = [$companyId];
    if ($excludedGroups) {
      $ph = implode(',', array_fill(0, count($excludedGroups), '?'));
      $where[] = "TRIM(COALESCE(p.categoria,'')) NOT IN ({$ph})";
      $params = array_merge($params, $excludedGroups);
    }
    if ($stockFilter === 'positivos') $where[] = "({$stockExpr})>0";
    if ($stockFilter === 'negativos') $where[] = "({$stockExpr})<0";

    $sql = "SELECT p.id,{$codeExpr} AS product_code,p.nome AS product_name,COALESCE(p.categoria,'') AS product_group,{$unitExpr} AS unit,
      {$stockExpr} AS quantity,{$lastCostExpr} AS last_cost,{$averageCostExpr} AS average_cost,{$stockSourceExpr} AS stock_source,{$costSourceExpr} AS cost_source,{$taxSelect}
      FROM produtos p{$join} WHERE " . implode(' AND ', $where) . ' ORDER BY p.nome,p.id';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $totalQty = 0.0; $totalValue = 0.0; $stockFallback = 0; $costFallback = 0;
    foreach ($rows as &$row) {
      $qty = round((float)($row['quantity'] ?? 0), 4);
      $last = round((float)($row['last_cost'] ?? 0), 4);
      $average = round((float)($row['average_cost'] ?? 0), 4);
      $valuation = $costMethod === 'last' ? $last : $average;
      if ($valuation <= 0) $valuation = $costMethod === 'last' ? $average : $last;
      $total = round($qty * $valuation, 2);
      $row['quantity'] = $qty;
      $row['last_cost'] = $last;
      $row['average_cost'] = $average;
      $row['valuation_cost'] = round($valuation, 4);
      $row['total_value'] = $total;
      $totalQty += $qty;
      $totalValue += $total;
      if ((string)($row['stock_source'] ?? '') === 'ATUAL') $stockFallback++;
      if ((string)($row['cost_source'] ?? '') === 'ATUAL') $costFallback++;
    }
    unset($row);

    $warnings = [];
    if (!$hasMovements) $warnings[] = 'A tabela de movimentações de estoque não está disponível; os saldos foram capturados do cadastro atual.';
    elseif ($stockFallback > 0) $warnings[] = $stockFallback . ' produto(s) não possuíam movimentação histórica e usaram o saldo atual.';
    if ($costFallback > 0) $warnings[] = $costFallback . ' produto(s) não possuíam custo histórico completo e usaram o custo atual disponível.';

    return [
      'rows'=>$rows,
      'total_quantity'=>round($totalQty,4),
      'total_value'=>round($totalValue,2),
      'warning_message'=>implode(' ', $warnings),
      'excluded_groups'=>$excludedGroups,
    ];
  }
}

if (!function_exists('inventory_generate')) {
  function inventory_generate(PDO $pdo, int $companyId, array $options, ?int $userId = null): int {
    inventory_ensure_schema($pdo);
    $date = inventory_validate_date((string)($options['inventory_date'] ?? ''), true);
    $stockFilter = (string)($options['stock_filter'] ?? 'positivos');
    if (!in_array($stockFilter, ['todos','positivos','negativos'], true)) $stockFilter = 'positivos';
    $costMethod = (string)($options['cost_method'] ?? 'average');
    if (!in_array($costMethod, ['average','last'], true)) $costMethod = 'average';
    $book = max(1, (int)($options['book_number'] ?? 1));
    $initialPage = max(1, (int)($options['initial_page'] ?? 1));
    $initialSheet = max(1, (int)($options['initial_sheet'] ?? $initialPage));
    $excluded = (array)($options['excluded_groups'] ?? []);
    $notes = trim((string)($options['notes'] ?? ''));
    $collected = inventory_collect_rows($pdo,$companyId,$date,$stockFilter,$costMethod,$excluded);
    $rows = (array)$collected['rows'];
    $pagination = function_exists('nexa_inventory_book_meta')
      ? nexa_inventory_book_meta([
          'initial_page'=>$initialPage,
          'initial_sheet'=>$initialSheet,
        ], $rows)
      : [
          'page_count'=>max(1, (int)ceil(max(1,count($rows))/20)),
          'final_page'=>$initialPage + max(1, (int)ceil(max(1,count($rows))/20)) - 1,
          'final_sheet'=>$initialSheet + max(1, (int)ceil(max(1,count($rows))/20)) - 1,
        ];

    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare("INSERT INTO fiscal_inventories (company_id,inventory_date,stock_filter,cost_method,book_number,initial_page,initial_sheet,final_page,final_sheet,page_count,excluded_groups_json,item_count,total_quantity,total_value,warning_message,notes,generated_by_user_id,generated_at,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
      $st->execute([
        $companyId,$date,$stockFilter,$costMethod,$book,$initialPage,$initialSheet,
        (int)($pagination['final_page'] ?? $initialPage),(int)($pagination['final_sheet'] ?? $initialSheet),(int)($pagination['page_count'] ?? 1),
        json_encode(array_values($collected['excluded_groups']),JSON_UNESCAPED_UNICODE),(int)count($rows),(float)$collected['total_quantity'],(float)$collected['total_value'],(string)$collected['warning_message'],$notes,$userId
      ]);
      $inventoryId = (int)$pdo->lastInsertId();
      $ins = $pdo->prepare("INSERT INTO fiscal_inventory_items (inventory_id,company_id,product_id,product_code,product_name,product_group,unit,quantity,last_cost,average_cost,valuation_cost,total_value,stock_source,cost_source,ncm,cest,cfop,icms_cst,icms_csosn,pis_cst,cofins_cst,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
      foreach ($rows as $row) {
        $ins->execute([$inventoryId,$companyId,(int)$row['id'],(string)$row['product_code'],(string)$row['product_name'],(string)$row['product_group'],(string)$row['unit'],(float)$row['quantity'],(float)$row['last_cost'],(float)$row['average_cost'],(float)$row['valuation_cost'],(float)$row['total_value'],(string)$row['stock_source'],(string)$row['cost_source'],(string)$row['ncm'],(string)$row['cest'],(string)$row['cfop'],(string)$row['icms_cst'],(string)$row['icms_csosn'],(string)$row['pis_cst'],(string)$row['cofins_cst']]);
      }
      $pdo->commit();
      return $inventoryId;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('inventory_list')) {
  function inventory_list(PDO $pdo, int $companyId, int $limit = 100): array {
    inventory_ensure_schema($pdo);
    $limit = max(1,min(300,$limit));
    $st = $pdo->prepare("SELECT * FROM fiscal_inventories WHERE company_id=? ORDER BY inventory_date DESC,id DESC LIMIT {$limit}");
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}
if (!function_exists('inventory_get')) {
  function inventory_get(PDO $pdo, int $companyId, int $inventoryId): ?array {
    inventory_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM fiscal_inventories WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId,$inventoryId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if ((int)($row['page_count'] ?? 0) <= 0 || (int)($row['final_page'] ?? 0) <= 0 || (int)($row['final_sheet'] ?? 0) <= 0) {
      try {
        $items = inventory_items($pdo, $companyId, $inventoryId);
        $meta = nexa_inventory_book_meta($row, $items);
        $initialSheet = max(1, (int)($row['initial_sheet'] ?? $row['initial_page'] ?? 1));
        $pdo->prepare('UPDATE fiscal_inventories SET initial_sheet=?,final_page=?,final_sheet=?,page_count=?,updated_at=NOW() WHERE company_id=? AND id=?')
          ->execute([$initialSheet,(int)$meta['final_page'],(int)$meta['final_sheet'],(int)$meta['page_count'],$companyId,$inventoryId]);
        $row['initial_sheet']=$initialSheet;
        $row['final_page']=(int)$meta['final_page'];
        $row['final_sheet']=(int)$meta['final_sheet'];
        $row['page_count']=(int)$meta['page_count'];
      } catch (Throwable $e) {}
    }
    return $row;
  }
}
if (!function_exists('inventory_items')) {
  function inventory_items(PDO $pdo, int $companyId, int $inventoryId): array {
    inventory_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM fiscal_inventory_items WHERE company_id=? AND inventory_id=? ORDER BY product_name,id');
    $st->execute([$companyId,$inventoryId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}
if (!function_exists('inventory_delete')) {
  function inventory_delete(PDO $pdo, int $companyId, int $inventoryId): void {
    inventory_ensure_schema($pdo);
    $pdo->beginTransaction();
    try {
      $pdo->prepare('DELETE FROM fiscal_inventory_items WHERE company_id=? AND inventory_id=?')->execute([$companyId,$inventoryId]);
      $pdo->prepare('DELETE FROM fiscal_inventories WHERE company_id=? AND id=?')->execute([$companyId,$inventoryId]);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}
if (!function_exists('inventory_pdf_content')) {
  function inventory_pdf_content(PDO $pdo, int $companyId, int $inventoryId): string {
    $inventory = inventory_get($pdo,$companyId,$inventoryId);
    if (!$inventory) throw new RuntimeException('Inventário não encontrado.');
    $items = inventory_items($pdo,$companyId,$inventoryId);
    $identity = function_exists('fiscal_doc_report_identity') ? fiscal_doc_report_identity($pdo,$companyId) : ['name'=>'Empresa'];
    return fiscal_pdf_inventory($inventory,$items,$identity);
  }
}

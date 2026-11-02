<?php
declare(strict_types=1);

require_once __DIR__ . '/schema_runtime.php';

/**
 * Nexa Brain v2 - Base de rentabilidade.
 *
 * Responsabilidades:
 * - criar/verificar as colunas necessárias para CMV e snapshots;
 * - congelar custo, nome e categoria no item do pedido;
 * - ratear desconto/acréscimo apenas sobre os produtos;
 * - calcular CMV, lucro bruto, margens e markup do pedido;
 * - registrar eventos no Transactional Outbox.
 */

if (!function_exists('brain_profitability_column_exists')) {
  function brain_profitability_column_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $table . '.' . $column;
    if (!empty($cache[$key])) return true;
    try {
      $st = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
      $st->execute([$table, $column]);
      $exists = (bool)$st->fetchColumn();
      if ($exists) $cache[$key] = true;
      return $exists;
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('brain_profitability_table_exists')) {
  function brain_profitability_table_exists(PDO $pdo, string $table): bool {
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $table;
    if (!empty($cache[$key])) return true;
    try {
      $st = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
      $st->execute([$table]);
      $exists = (bool)$st->fetchColumn();
      if ($exists) $cache[$key] = true;
      return $exists;
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('brain_profitability_schema_ready')) {
  function brain_profitability_schema_ready(PDO $pdo): bool {
    static $cache = [];
    $key = spl_object_id($pdo);
    if (!empty($cache[$key])) return true;
    if (nexa_schema_version_ready($pdo, 'brain_profitability', 2026072302)) return $cache[$key] = true;
    try {
      $sql = "SELECT
        ((SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE()
             AND (
               (TABLE_NAME='produtos' AND COLUMN_NAME IN ('custo','custo_medio','custo_ultima_compra','data_ultima_compra'))
               OR (TABLE_NAME='pedido_itens' AND COLUMN_NAME IN ('produto_id_snapshot','produto_nome_snapshot','categoria_snapshot','custo_unitario_snapshot','cmv_total','desconto_rateado','acrescimo_rateado','receita_liquida','lucro_bruto','custo_origem','custo_capturado_at'))
               OR (TABLE_NAME='pedidos' AND COLUMN_NAME IN ('total_cmv','receita_liquida_produtos','lucro_bruto','margem_venda_percentual','margem_custo_percentual','markup_fator','sales_channel','fulfillment_type','recognized_at','cancelled_at','profitability_calculated_at','profitability_cost_source'))
               OR (TABLE_NAME='estoque_movimentos' AND COLUMN_NAME IN ('custo_unitario','valor_movimento','custo_medio_anterior','custo_medio_posterior'))
             )) = 31)
        AND EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='brain_event_outbox')";
      $ready = (bool)$pdo->query($sql)->fetchColumn();
      if ($ready) $cache[$key] = true;
      return $ready;
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('brain_profitability_index_exists')) {
  function brain_profitability_index_exists(PDO $pdo, string $table, string $index): bool {
    try {
      $st = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? LIMIT 1');
      $st->execute([$table, $index]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('brain_profitability_add_column')) {
  function brain_profitability_add_column(PDO $pdo, string $table, string $column, string $definition, array &$errors): void {
    if (brain_profitability_column_exists($pdo, $table, $column)) return;
    if ($pdo->inTransaction()) {
      $errors[] = "Coluna {$table}.{$column} ainda não instalada e não pode ser criada dentro de uma transação.";
      return;
    }
    try {
      $pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD COLUMN `' . str_replace('`', '``', $column) . '` ' . $definition);
    } catch (Throwable $e) {
      if (!brain_profitability_column_exists($pdo, $table, $column)) $errors[] = $e->getMessage();
    }
  }
}

if (!function_exists('brain_profitability_try_index')) {
  function brain_profitability_try_index(PDO $pdo, string $table, string $index, string $sql): void {
    if ($pdo->inTransaction() || brain_profitability_index_exists($pdo, $table, $index)) return;
    try { $pdo->exec($sql); } catch (Throwable $e) {}
  }
}

if (!function_exists('brain_profitability_ensure_schema')) {
  function brain_profitability_ensure_schema(PDO $pdo, bool $allowDdl = true): array {
    $errors = [];
    if (brain_profitability_schema_ready($pdo)) return $errors;
    if (!$allowDdl || $pdo->inTransaction()) return $errors;

    if (brain_profitability_table_exists($pdo, 'produtos')) {
      foreach ([
        ['custo', 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000'],
        ['custo_medio', 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000'],
        ['custo_ultima_compra', 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000'],
        ['data_ultima_compra', 'DATETIME NULL'],
      ] as $col) brain_profitability_add_column($pdo, 'produtos', $col[0], $col[1], $errors);
    }

    if (brain_profitability_table_exists($pdo, 'pedido_itens')) {
      foreach ([
        ['produto_id_snapshot', 'BIGINT UNSIGNED NULL'],
        ['produto_nome_snapshot', 'VARCHAR(180) NULL'],
        ['categoria_snapshot', 'VARCHAR(140) NULL'],
        ['custo_unitario_snapshot', 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000'],
        ['cmv_total', 'DECIMAL(15,2) NOT NULL DEFAULT 0.00'],
        ['desconto_rateado', 'DECIMAL(15,2) NOT NULL DEFAULT 0.00'],
        ['acrescimo_rateado', 'DECIMAL(15,2) NOT NULL DEFAULT 0.00'],
        ['receita_liquida', 'DECIMAL(15,2) NOT NULL DEFAULT 0.00'],
        ['lucro_bruto', 'DECIMAL(15,2) NOT NULL DEFAULT 0.00'],
        ['custo_origem', "VARCHAR(40) NOT NULL DEFAULT 'nao_informado'"],
        ['custo_capturado_at', 'DATETIME NULL'],
      ] as $col) brain_profitability_add_column($pdo, 'pedido_itens', $col[0], $col[1], $errors);
      brain_profitability_try_index($pdo, 'pedido_itens', 'idx_pi_company_category_order', 'ALTER TABLE pedido_itens ADD KEY idx_pi_company_category_order (company_id, categoria_snapshot, pedido_id)');
      brain_profitability_try_index($pdo, 'pedido_itens', 'idx_pi_company_cost_source', 'ALTER TABLE pedido_itens ADD KEY idx_pi_company_cost_source (company_id, custo_origem)');
    }

    if (brain_profitability_table_exists($pdo, 'pedidos')) {
      foreach ([
        ['total_cmv', 'DECIMAL(15,2) NOT NULL DEFAULT 0.00'],
        ['receita_liquida_produtos', 'DECIMAL(15,2) NOT NULL DEFAULT 0.00'],
        ['lucro_bruto', 'DECIMAL(15,2) NOT NULL DEFAULT 0.00'],
        ['margem_venda_percentual', 'DECIMAL(12,4) NOT NULL DEFAULT 0.0000'],
        ['margem_custo_percentual', 'DECIMAL(12,4) NOT NULL DEFAULT 0.0000'],
        ['markup_fator', 'DECIMAL(12,4) NOT NULL DEFAULT 0.0000'],
        ['sales_channel', "VARCHAR(40) NOT NULL DEFAULT 'nao_informado'"],
        ['fulfillment_type', "VARCHAR(30) NOT NULL DEFAULT 'nao_informado'"],
        ['recognized_at', 'DATETIME NULL'],
        ['cancelled_at', 'DATETIME NULL'],
        ['profitability_calculated_at', 'DATETIME NULL'],
        ['profitability_cost_source', "VARCHAR(40) NOT NULL DEFAULT 'nao_informado'"],
      ] as $col) brain_profitability_add_column($pdo, 'pedidos', $col[0], $col[1], $errors);
      brain_profitability_try_index($pdo, 'pedidos', 'idx_pedidos_company_recognized', 'ALTER TABLE pedidos ADD KEY idx_pedidos_company_recognized (company_id, recognized_at, id)');
      brain_profitability_try_index($pdo, 'pedidos', 'idx_pedidos_company_channel_recognized', 'ALTER TABLE pedidos ADD KEY idx_pedidos_company_channel_recognized (company_id, sales_channel, recognized_at)');
      brain_profitability_try_index($pdo, 'pedidos', 'idx_pedidos_company_seller_recognized', 'ALTER TABLE pedidos ADD KEY idx_pedidos_company_seller_recognized (company_id, seller_id, recognized_at)');
    }

    if (brain_profitability_table_exists($pdo, 'estoque_movimentos')) {
      foreach ([
        ['custo_unitario', 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000'],
        ['valor_movimento', 'DECIMAL(15,2) NOT NULL DEFAULT 0.00'],
        ['custo_medio_anterior', 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000'],
        ['custo_medio_posterior', 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000'],
      ] as $col) brain_profitability_add_column($pdo, 'estoque_movimentos', $col[0], $col[1], $errors);
    }

    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS brain_event_outbox (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        company_id INT UNSIGNED NOT NULL,
        event_type VARCHAR(100) NOT NULL,
        entity_type VARCHAR(80) DEFAULT NULL,
        entity_id BIGINT UNSIGNED DEFAULT NULL,
        payload JSON DEFAULT NULL,
        status ENUM('pending','processing','published','failed') NOT NULL DEFAULT 'pending',
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        published_at DATETIME DEFAULT NULL,
        locked_by VARCHAR(180) DEFAULT NULL,
        locked_at DATETIME DEFAULT NULL,
        redis_stream_id VARCHAR(80) DEFAULT NULL,
        last_error TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_brain_outbox_claim (status, available_at, id),
        KEY idx_brain_outbox_lock (status, locked_at),
        KEY idx_brain_outbox_company (company_id, id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { $errors[] = $e->getMessage(); }

    if (!$errors) nexa_schema_version_mark($pdo, 'brain_profitability', 2026072302, 'CMV e rentabilidade instalados');
    return $errors;
  }
}

if (!function_exists('brain_profitability_channel')) {
  function brain_profitability_channel(string $source, string $fulfillment): string {
    $source = mb_strtolower(trim($source), 'UTF-8');
    $fulfillment = mb_strtolower(trim($fulfillment), 'UTF-8');
    if (str_contains($source, 'comanda')) return 'comanda';
    if (str_contains($source, 'delivery')) return 'delivery_online';
    if (str_contains($source, 'marketplace')) return 'marketplace';
    if (str_contains($source, 'api')) return 'api';
    if (str_contains($source, 'import')) return 'importacao';
    if (str_contains($source, 'pdv') || str_contains($source, 'gestao')) {
      return $fulfillment === 'balcao' ? 'pdv_balcao' : 'pdv';
    }
    return $source !== '' ? substr($source, 0, 40) : 'nao_informado';
  }
}

if (!function_exists('brain_profitability_cost_source_summary')) {
  function brain_profitability_cost_source_summary(array $sources): string {
    $sources = array_values(array_unique(array_filter(array_map(static fn($v) => trim((string)$v), $sources))));
    if (!$sources) return 'nao_informado';
    if (count($sources) === 1) return substr($sources[0], 0, 40);
    if (in_array('nao_informado', $sources, true)) return 'misto_com_custo_ausente';
    if (in_array('estimado_cadastro', $sources, true)) return 'misto_com_estimativa';
    return 'misto';
  }
}

if (!function_exists('brain_event_enqueue')) {
  function brain_event_enqueue(PDO $pdo, int $companyId, string $eventType, string $entityType, int $entityId, array $payload = []): void {
    if ($companyId <= 0 || !brain_profitability_table_exists($pdo, 'brain_event_outbox')) return;
    try {
      $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
      if ($json === false) $json = '{}';
      $pdo->prepare('INSERT INTO brain_event_outbox (company_id, event_type, entity_type, entity_id, payload, status, attempts, available_at, created_at) VALUES (?, ?, ?, ?, ?, \'pending\', 0, NOW(), NOW())')
        ->execute([$companyId, substr($eventType, 0, 100), substr($entityType, 0, 80), $entityId, $json]);
    } catch (Throwable $e) {
      error_log('[Nexa Brain Outbox] Falha ao gravar evento ' . $eventType . ': ' . $e->getMessage());
      // Por padrão a venda continua disponível. Em instalações auditadas, o modo
      // estrito faz a mesma transação falhar para garantir 100% de entrega no Outbox.
      if ((string)(getenv('NEXA_BRAIN_OUTBOX_STRICT') ?: '0') === '1') throw $e;
    }
  }
}

if (!function_exists('brain_profitability_fetch_product_cost')) {
  function brain_profitability_fetch_product_cost(array $row): array {
    $average = (float)($row['custo_medio'] ?? 0);
    $current = (float)($row['custo'] ?? 0);
    if ($average > 0) return [$average, 'custo_medio'];
    if ($current > 0) return [$current, 'custo_cadastro'];
    return [0.0, 'nao_informado'];
  }
}

if (!function_exists('brain_profitability_recalculate_order')) {
  /**
   * Recalcula o pedido. Quando $captureCurrentCost=true, todos os snapshots são renovados.
   * Quando false, snapshots existentes são preservados e somente itens novos ou cujo produto
   * foi trocado capturam o custo vigente. Para bases históricas sem snapshot, marque
   * $missingSnapshotIsHistoricalEstimate=true.
   */
  function brain_profitability_recalculate_order(PDO $pdo, int $companyId, int $orderId, bool $captureCurrentCost = false, string $eventType = 'order.profitability.updated', bool $missingSnapshotIsHistoricalEstimate = false): array {
    if ($companyId <= 0 || $orderId <= 0) return [];
    if (!brain_profitability_column_exists($pdo, 'pedidos', 'total_cmv') || !brain_profitability_column_exists($pdo, 'pedido_itens', 'custo_unitario_snapshot')) return [];

    $stOrder = $pdo->prepare('SELECT * FROM pedidos WHERE company_id = ? AND id = ? LIMIT 1');
    $stOrder->execute([$companyId, $orderId]);
    $order = $stOrder->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$order) return [];

    $selectProduct = ['p.nome AS product_name', 'p.categoria AS product_category'];
    $selectProduct[] = brain_profitability_column_exists($pdo, 'produtos', 'custo') ? 'p.custo' : '0 AS custo';
    $selectProduct[] = brain_profitability_column_exists($pdo, 'produtos', 'custo_medio') ? 'p.custo_medio' : '0 AS custo_medio';

    $stItems = $pdo->prepare('SELECT pi.*, ' . implode(', ', $selectProduct) . '
      FROM pedido_itens pi
      LEFT JOIN produtos p ON p.company_id = pi.company_id AND p.id = pi.produto_id
      WHERE pi.company_id = ? AND pi.pedido_id = ?
      ORDER BY pi.id ASC');
    $stItems->execute([$companyId, $orderId]);
    $items = $stItems->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $subtotal = round((float)($order['total_produtos'] ?? 0), 2);
    if ($subtotal <= 0 && $items) {
      $subtotal = round(array_sum(array_map(static fn($it) => (float)($it['total'] ?? 0), $items)), 2);
    }
    $discount = max(0.0, round((float)($order['discount_amount'] ?? 0), 2));
    $addition = max(0.0, round((float)($order['addition_amount'] ?? 0), 2));

    $updateItem = $pdo->prepare('UPDATE pedido_itens SET produto_id_snapshot = ?, produto_nome_snapshot = ?, categoria_snapshot = ?, custo_unitario_snapshot = ?, cmv_total = ?, desconto_rateado = ?, acrescimo_rateado = ?, receita_liquida = ?, lucro_bruto = ?, custo_origem = ?, custo_capturado_at = ? WHERE company_id = ? AND pedido_id = ? AND id = ?');

    $totalCmv = 0.0;
    $totalNetRevenue = 0.0;
    $totalGrossProfit = 0.0;
    $sources = [];
    $allocatedDiscount = 0.0;
    $allocatedAddition = 0.0;
    $lastIndex = count($items) - 1;

    foreach ($items as $index => $item) {
      $lineTotal = round((float)($item['total'] ?? 0), 2);
      $qty = round((float)($item['qtd'] ?? 0), 4);
      $currentProductId = (int)($item['produto_id'] ?? 0);
      $storedProductId = (int)($item['produto_id_snapshot'] ?? 0);
      $storedCost = (float)($item['custo_unitario_snapshot'] ?? 0);
      $storedSource = trim((string)($item['custo_origem'] ?? ''));
      $capturedAt = $item['custo_capturado_at'] ?? null;
      $productChanged = $storedProductId > 0 && $storedProductId !== $currentProductId;
      $needsCapture = $captureCurrentCost || $storedProductId <= 0 || $productChanged || $capturedAt === null || $storedSource === '' || $storedSource === 'nao_informado';

      if ($needsCapture) {
        [$unitCost, $costSource] = brain_profitability_fetch_product_cost($item);
        if (!$captureCurrentCost && $missingSnapshotIsHistoricalEstimate && $unitCost > 0) $costSource = 'estimado_cadastro';
        $capturedAt = date('Y-m-d H:i:s');
      } else {
        $unitCost = max(0.0, $storedCost);
        $costSource = $storedSource !== '' ? $storedSource : 'nao_informado';
      }

      $storedProductName = trim((string)($item['produto_nome_snapshot'] ?? ''));
      $storedCategory = trim((string)($item['categoria_snapshot'] ?? ''));
      $currentProductName = trim((string)($item['product_name'] ?? ''));
      $currentCategory = trim((string)($item['product_category'] ?? ''));
      $productName = ($needsCapture || $storedProductName === '') ? $currentProductName : $storedProductName;
      $category = ($needsCapture || $storedCategory === '') ? $currentCategory : $storedCategory;
      if ($productName === '') $productName = 'Produto #' . (int)($item['produto_id'] ?? 0);
      if ($category === '') $category = 'SEM CATEGORIA';

      if ($index === $lastIndex) {
        $lineDiscount = round($discount - $allocatedDiscount, 2);
        $lineAddition = round($addition - $allocatedAddition, 2);
      } else {
        $ratio = $subtotal > 0 ? max(0.0, $lineTotal / $subtotal) : 0.0;
        $lineDiscount = round($discount * $ratio, 2);
        $lineAddition = round($addition * $ratio, 2);
        $allocatedDiscount += $lineDiscount;
        $allocatedAddition += $lineAddition;
      }

      $cmv = round($qty * max(0.0, $unitCost), 2);
      $netRevenue = round($lineTotal - $lineDiscount + $lineAddition, 2);
      $grossProfit = round($netRevenue - $cmv, 2);

      $updateItem->execute([
        $currentProductId > 0 ? $currentProductId : null,
        mb_substr($productName, 0, 180, 'UTF-8'),
        mb_substr($category, 0, 140, 'UTF-8'),
        round(max(0.0, $unitCost), 4),
        $cmv,
        $lineDiscount,
        $lineAddition,
        $netRevenue,
        $grossProfit,
        substr($costSource, 0, 40),
        $capturedAt,
        $companyId,
        $orderId,
        (int)$item['id'],
      ]);

      $totalCmv += $cmv;
      $totalNetRevenue += $netRevenue;
      $totalGrossProfit += $grossProfit;
      $sources[] = $costSource;
    }

    $totalCmv = round($totalCmv, 2);
    $totalNetRevenue = round($totalNetRevenue, 2);
    $totalGrossProfit = round($totalGrossProfit, 2);
    $marginSale = $totalNetRevenue > 0 ? round(($totalGrossProfit / $totalNetRevenue) * 100, 4) : 0.0;
    $marginCost = $totalCmv > 0 ? round(($totalGrossProfit / $totalCmv) * 100, 4) : 0.0;
    $markup = $totalCmv > 0 ? round($totalNetRevenue / $totalCmv, 4) : 0.0;
    $fulfillment = trim((string)($order['tipo_entrega'] ?? $order['fulfillment_type'] ?? ''));
    if ($fulfillment === '') $fulfillment = 'nao_informado';
    $channel = brain_profitability_channel((string)($order['source'] ?? ''), $fulfillment);
    $status = mb_strtolower(trim((string)($order['status'] ?? '')), 'UTF-8');
    $recognizedAt = $order['recognized_at'] ?? null;
    $cancelledAt = $order['cancelled_at'] ?? null;
    if ($status === 'finalizado') {
      if (!$recognizedAt) $recognizedAt = date('Y-m-d H:i:s');
    } else {
      $recognizedAt = null;
    }
    if ($status === 'cancelado') {
      if (!$cancelledAt) $cancelledAt = date('Y-m-d H:i:s');
    } else {
      $cancelledAt = null;
    }
    $sourceSummary = brain_profitability_cost_source_summary($sources);

    $pdo->prepare('UPDATE pedidos SET total_cmv = ?, receita_liquida_produtos = ?, lucro_bruto = ?, margem_venda_percentual = ?, margem_custo_percentual = ?, markup_fator = ?, sales_channel = ?, fulfillment_type = ?, recognized_at = ?, cancelled_at = ?, profitability_calculated_at = NOW(), profitability_cost_source = ? WHERE company_id = ? AND id = ?')
      ->execute([$totalCmv, $totalNetRevenue, $totalGrossProfit, $marginSale, $marginCost, $markup, $channel, substr($fulfillment, 0, 30), $recognizedAt, $cancelledAt, $sourceSummary, $companyId, $orderId]);

    $payload = [
      'order_id' => $orderId,
      'status' => $status,
      'sales_channel' => $channel,
      'fulfillment_type' => $fulfillment,
      'revenue_net_products' => $totalNetRevenue,
      'cmv' => $totalCmv,
      'gross_profit' => $totalGrossProfit,
      'margin_sale_percent' => $marginSale,
      'margin_cost_percent' => $marginCost,
      'markup_factor' => $markup,
      'cost_source' => $sourceSummary,
      'recognized_at' => $recognizedAt,
    ];
    brain_event_enqueue($pdo, $companyId, $eventType, 'order', $orderId, $payload);
    return $payload;
  }
}

if (!function_exists('brain_profitability_sync_order_status')) {
  function brain_profitability_sync_order_status(PDO $pdo, int $companyId, int $orderId, string $newStatus, string $oldStatus = ''): array {
    $newStatus = mb_strtolower(trim($newStatus), 'UTF-8');
    $oldStatus = mb_strtolower(trim($oldStatus), 'UTF-8');
    if (brain_profitability_column_exists($pdo, 'pedidos', 'recognized_at')) {
      if ($newStatus === 'finalizado') {
        $pdo->prepare('UPDATE pedidos SET recognized_at = COALESCE(recognized_at, NOW()), cancelled_at = NULL WHERE company_id = ? AND id = ?')->execute([$companyId, $orderId]);
      } elseif ($newStatus === 'cancelado') {
        $pdo->prepare('UPDATE pedidos SET cancelled_at = COALESCE(cancelled_at, NOW()) WHERE company_id = ? AND id = ?')->execute([$companyId, $orderId]);
      } elseif ($oldStatus === 'finalizado' && $newStatus !== 'finalizado') {
        $pdo->prepare('UPDATE pedidos SET recognized_at = NULL, cancelled_at = NULL WHERE company_id = ? AND id = ?')->execute([$companyId, $orderId]);
      } elseif ($newStatus !== 'cancelado') {
        $pdo->prepare('UPDATE pedidos SET cancelled_at = NULL WHERE company_id = ? AND id = ?')->execute([$companyId, $orderId]);
      }
    }
    $event = $newStatus === 'finalizado' ? 'order.finalized' : ($newStatus === 'cancelado' ? 'order.cancelled' : 'order.status.changed');
    return brain_profitability_recalculate_order($pdo, $companyId, $orderId, false, $event, true);
  }
}

if (!function_exists('brain_profitability_initialize_average_costs')) {
  function brain_profitability_initialize_average_costs(PDO $pdo, int $companyId = 0): int {
    if (!brain_profitability_column_exists($pdo, 'produtos', 'custo') || !brain_profitability_column_exists($pdo, 'produtos', 'custo_medio')) return 0;
    $where = 'custo > 0 AND (custo_medio IS NULL OR custo_medio <= 0)';
    $params = [];
    if ($companyId > 0) { $where .= ' AND company_id = ?'; $params[] = $companyId; }
    $st = $pdo->prepare("UPDATE produtos SET custo_medio = custo WHERE {$where}");
    $st->execute($params);
    return $st->rowCount();
  }
}

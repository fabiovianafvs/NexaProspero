<?php
declare(strict_types=1);

require_once __DIR__ . '/product_composition.php';
require_once __DIR__ . '/billing_module.php';
require_once __DIR__ . '/voucher_module.php';
require_once __DIR__ . '/card_control.php';

/**
 * Nexa v2.6.8 — cancelamento operacional integrado.
 *
 * Mantém o cancelamento do pedido, a devolução do estoque e os estornos
 * financeiro/caixa idempotentes e isolados por empresa.
 */

if (!function_exists('nexa_cancel_table_exists')) {
  function nexa_cancel_table_exists(PDO $pdo, string $table): bool {
    try {
      $st = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
      $st->execute([$table]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('nexa_cancel_column_exists')) {
  function nexa_cancel_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
      $st = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');
      $st->execute([$table, $column]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('nexa_cancel_add_column')) {
  function nexa_cancel_add_column(PDO $pdo, string $table, string $column, string $definition): void {
    if (!nexa_cancel_table_exists($pdo, $table) || nexa_cancel_column_exists($pdo, $table, $column)) return;
    $pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD COLUMN `' . str_replace('`', '``', $column) . '` ' . $definition);
  }
}

if (!function_exists('nexa_order_cancellation_ensure_schema')) {
  function nexa_order_cancellation_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_cancellation_effects (
      id BIGINT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      order_id BIGINT NOT NULL,
      fiscal_document_id BIGINT NULL,
      source VARCHAR(30) NOT NULL DEFAULT 'MANUAL',
      status VARCHAR(20) NOT NULL DEFAULT 'PROCESSING',
      reason VARCHAR(255) NULL,
      order_status_before VARCHAR(30) NULL,
      stock_reversed_qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
      finance_reversed_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
      cash_reversed_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
      processed_by INT NULL,
      processed_at DATETIME NULL,
      error_message TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_order_cancellation_company_order (company_id, order_id),
      KEY idx_order_cancellation_fiscal (company_id, fiscal_document_id),
      KEY idx_order_cancellation_status (company_id, status, processed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
      'canceled_at' => 'DATETIME NULL',
      'cancel_reason' => 'VARCHAR(255) NULL',
      'cancellation_source' => 'VARCHAR(30) NULL',
      'canceled_fiscal_document_id' => 'BIGINT NULL',
    ] as $column => $definition) nexa_cancel_add_column($pdo, 'pedidos', $column, $definition);

    foreach ([
      'receivable_ids' => 'TEXT NULL',
      'canceled_at' => 'DATETIME NULL',
      'canceled_reason' => 'VARCHAR(255) NULL',
      'reversal_movement_id' => 'BIGINT NULL',
      'reversal_cash_operation_id' => 'BIGINT NULL',
    ] as $column => $definition) nexa_cancel_add_column($pdo, 'gestao_order_payments', $column, $definition);

    foreach ([
      'canceled_at' => 'DATETIME NULL',
      'canceled_reason' => 'VARCHAR(255) NULL',
    ] as $column => $definition) nexa_cancel_add_column($pdo, 'finance_receivables', $column, $definition);

    foreach ([
      'internal_cancellation_status' => "VARCHAR(20) NULL",
      'internal_cancellation_message' => 'VARCHAR(255) NULL',
      'internal_cancellation_at' => 'DATETIME NULL',
    ] as $column => $definition) nexa_cancel_add_column($pdo, 'fiscal_documents', $column, $definition);
  }
}

if (!function_exists('nexa_order_authorized_fiscal_document')) {
  function nexa_order_authorized_fiscal_document(PDO $pdo, int $companyId, int $orderId): ?array {
    if (!nexa_cancel_table_exists($pdo, 'fiscal_documents')) return null;
    $st = $pdo->prepare("SELECT id, model, series, number, access_key, status
      FROM fiscal_documents
      WHERE company_id=? AND source_type='PEDIDO' AND source_id=?
        AND UPPER(COALESCE(status,''))='AUTORIZADA'
      ORDER BY id DESC LIMIT 1");
    $st->execute([$companyId, $orderId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('nexa_cancel_stock_cost_columns')) {
  function nexa_cancel_stock_cost_columns(PDO $pdo): bool {
    return nexa_cancel_column_exists($pdo, 'estoque_movimentos', 'custo_unitario')
      && nexa_cancel_column_exists($pdo, 'estoque_movimentos', 'valor_movimento')
      && nexa_cancel_column_exists($pdo, 'estoque_movimentos', 'custo_medio_anterior')
      && nexa_cancel_column_exists($pdo, 'estoque_movimentos', 'custo_medio_posterior');
  }
}

if (!function_exists('nexa_cancel_reverse_stock')) {
  function nexa_cancel_reverse_stock(PDO $pdo, int $companyId, int $orderId, string $reason): array {
    $result = ['quantity'=>0.0, 'products'=>0, 'warning'=>''];
    if (!nexa_cancel_table_exists($pdo, 'estoque_movimentos') || !nexa_cancel_table_exists($pdo, 'produtos')) {
      $result['warning'] = 'Estrutura de estoque não disponível.';
      return $result;
    }

    // A composição possui movimentos próprios. O estorno é idempotente e precisa ser
    // executado mesmo quando o produto acabado não gerou movimento físico de PDV.
    $compositionReversal = ['components'=>0,'quantity'=>0.0];
    if (function_exists('product_composition_reverse_sale') && product_composition_schema_ready($pdo)) {
      $compositionReversal = product_composition_reverse_sale($pdo, $companyId, $orderId, $reason);
    }

    $stDone = $pdo->prepare("SELECT COUNT(*) FROM estoque_movimentos WHERE company_id=? AND origem_tipo='CANCELAMENTO_PEDIDO' AND origem_id=?");
    $stDone->execute([$companyId, $orderId]);
    if ((int)$stDone->fetchColumn() > 0) {
      $stSum = $pdo->prepare("SELECT COALESCE(SUM(qtd),0), COUNT(DISTINCT produto_id) FROM estoque_movimentos WHERE company_id=? AND origem_tipo='CANCELAMENTO_PEDIDO' AND origem_id=?");
      $stSum->execute([$companyId, $orderId]);
      $row = $stSum->fetch(PDO::FETCH_NUM) ?: [0,0];
      return ['quantity'=>(float)$row[0], 'products'=>(int)$row[1], 'warning'=>''];
    }

    $stOriginal = $pdo->prepare("SELECT produto_id, COALESCE(SUM(ABS(qtd)),0) AS qty
      FROM estoque_movimentos
      WHERE company_id=? AND origem_tipo='PDV' AND origem_id=? AND LOWER(COALESCE(tipo,''))='saida'
      GROUP BY produto_id HAVING qty>0");
    $stOriginal->execute([$companyId, $orderId]);
    $rows = $stOriginal->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
      $hasItems = false;
      if (nexa_cancel_table_exists($pdo, 'pedido_itens')) {
        // Serviços (ex.: pedido de O.S. só com serviços / NFS-e) não movimentam
        // estoque: não podem deixar o estorno "pendente" por falta de baixa.
        $serviceFilter = nexa_cancel_column_exists($pdo, 'produtos', 'is_service')
          ? ' AND NOT EXISTS (SELECT 1 FROM produtos pr WHERE pr.company_id=pedido_itens.company_id AND pr.id=pedido_itens.produto_id AND COALESCE(pr.is_service,0)=1)'
          : '';
        $stItems = $pdo->prepare('SELECT COUNT(*) FROM pedido_itens WHERE company_id=? AND pedido_id=? AND produto_id IS NOT NULL AND qtd>0' . $serviceFilter);
        $stItems->execute([$companyId, $orderId]);
        $hasItems = (int)$stItems->fetchColumn() > 0;
      }
      if ($hasItems && (int)($compositionReversal['components'] ?? 0) <= 0) $result['warning'] = 'O pedido possui itens, mas não foi localizada a baixa de estoque original do PDV; nenhuma quantidade foi devolvida automaticamente para evitar duplicidade.';
      return $result;
    }

    $withCosts = nexa_cancel_stock_cost_columns($pdo);
    $sel = $pdo->prepare('SELECT estoque_atual, COALESCE(custo_medio,0) custo_medio, COALESCE(custo,0) custo FROM produtos WHERE company_id=? AND id=? FOR UPDATE');
    $upd = $pdo->prepare('UPDATE produtos SET estoque_atual=? WHERE company_id=? AND id=?');
    $ins = $withCosts
      ? $pdo->prepare("INSERT INTO estoque_movimentos (company_id, produto_id, tipo, origem_tipo, origem_id, qtd, saldo_anterior, saldo_posterior, custo_unitario, valor_movimento, custo_medio_anterior, custo_medio_posterior, observacao) VALUES (?,?,'entrada','CANCELAMENTO_PEDIDO',?,?,?,?,?,?,?,?,?)")
      : $pdo->prepare("INSERT INTO estoque_movimentos (company_id, produto_id, tipo, origem_tipo, origem_id, qtd, saldo_anterior, saldo_posterior, observacao) VALUES (?,?,'entrada','CANCELAMENTO_PEDIDO',?,?,?,?,?)");

    foreach ($rows as $row) {
      $productId = (int)($row['produto_id'] ?? 0);
      $qty = round((float)($row['qty'] ?? 0), 4);
      if ($productId <= 0 || $qty <= 0) continue;
      $sel->execute([$companyId, $productId]);
      $product = $sel->fetch(PDO::FETCH_ASSOC) ?: null;
      if (!$product) continue;
      $before = (float)($product['estoque_atual'] ?? 0);
      $after = round($before + $qty, 4);
      $cost = (float)($product['custo_medio'] ?? 0);
      if ($cost <= 0) $cost = (float)($product['custo'] ?? 0);
      $upd->execute([$after, $companyId, $productId]);
      $obs = 'Devolução automática do estoque pelo cancelamento do pedido #' . $orderId . '. ' . $reason;
      if ($withCosts) {
        $ins->execute([$companyId, $productId, $orderId, $qty, $before, $after, $cost, round($cost * $qty, 2), $cost, $cost, $obs]);
      } else {
        $ins->execute([$companyId, $productId, $orderId, $qty, $before, $after, $obs]);
      }
      $result['quantity'] += $qty;
      $result['products']++;
    }
    $result['quantity'] = round((float)$result['quantity'], 4);
    // Também recalcula quando a venda cancelada era de uma matéria-prima vendida
    // diretamente, pois isso aumenta novamente a capacidade dos produtos compostos.
    if (function_exists('product_composition_enabled') && product_composition_enabled($pdo, $companyId) && product_composition_schema_ready($pdo)) {
      product_composition_recalculate_all($pdo, $companyId, 'Cancelamento do pedido #' . $orderId);
    }
    return $result;
  }
}

if (!function_exists('nexa_cancel_insert_finance_reversal')) {
  function nexa_cancel_insert_finance_reversal(PDO $pdo, int $companyId, int $orderId, float $amount, ?int $personId, ?int $bankId, ?int $chartId, ?int $costId, string $description, string $reason): int {
    if ($amount <= 0 || !nexa_cancel_table_exists($pdo, 'finance_movements')) return 0;
    $columns = ['company_id','movement_date','type','person_id','chart_account_id','cost_center_id','bank_account_id','origin_type','origin_id','description','document_number','amount','notes'];
    $values = ['?', 'CURDATE()', "'DEBITO'", '?','?','?','?',"'ESTORNO_PEDIDO'",'?','?','?','?','?'];
    $params = [$companyId, $personId ?: null, $chartId ?: null, $costId ?: null, $bankId ?: null, $orderId, $description, (string)$orderId, round($amount,2), $reason];
    if (nexa_cancel_column_exists($pdo, 'finance_movements', 'status')) {
      $columns[] = 'status'; $values[] = "'ATIVO'";
    }
    $sql = 'INSERT INTO finance_movements (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ')';
    $pdo->prepare($sql)->execute($params);
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('nexa_cancel_receivable_ids')) {
  function nexa_cancel_receivable_ids(array $row): array {
    $ids = [];
    $rid = (int)($row['receivable_id'] ?? 0);
    if ($rid > 0) $ids[$rid] = $rid;
    foreach (preg_split('/\D+/', (string)($row['receivable_ids'] ?? '')) ?: [] as $value) {
      $value = (int)$value;
      if ($value > 0) $ids[$value] = $value;
    }
    return array_values($ids);
  }
}

if (!function_exists('nexa_cancel_reverse_finance_and_cash')) {
  function nexa_cancel_reverse_finance_and_cash(PDO $pdo, int $companyId, int $orderId, string $reason, int $userId = 0): array {
    $result = ['finance'=>0.0, 'cash'=>0.0, 'payments'=>0, 'warning'=>''];
    if (!nexa_cancel_table_exists($pdo, 'gestao_order_payments')) return $result;

    $orderPersonId = null;
    if (nexa_cancel_table_exists($pdo, 'pedidos') && nexa_cancel_column_exists($pdo, 'pedidos', 'finance_person_id')) {
      $stPerson = $pdo->prepare('SELECT finance_person_id FROM pedidos WHERE company_id=? AND id=? LIMIT 1');
      $stPerson->execute([$companyId, $orderId]);
      $orderPersonId = (int)$stPerson->fetchColumn() ?: null;
    }

    $selectCols = ['id','cash_session_id','amount','bank_account_id','chart_account_id','cost_center_id','finance_movement_id','receivable_id','receivable_ids','reversal_movement_id','reversal_cash_operation_id'];
    $st = $pdo->prepare('SELECT ' . implode(',', $selectCols) . ' FROM gestao_order_payments WHERE company_id=? AND order_id=? FOR UPDATE');
    $st->execute([$companyId, $orderId]);
    $payments = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($payments as $payment) {
      $paymentId = (int)$payment['id'];
      $reversalMovementId = (int)($payment['reversal_movement_id'] ?? 0);
      $receivableIds = nexa_cancel_receivable_ids($payment);
      $paidAmount = 0.0;
      if ($receivableIds && nexa_cancel_table_exists($pdo, 'finance_receivables')) {
        $ph = implode(',', array_fill(0, count($receivableIds), '?'));
        $stRec = $pdo->prepare("SELECT id, COALESCE(paid_amount,0) paid_amount FROM finance_receivables WHERE company_id=? AND id IN ($ph) FOR UPDATE");
        $stRec->execute(array_merge([$companyId], $receivableIds));
        foreach (($stRec->fetchAll(PDO::FETCH_ASSOC) ?: []) as $rec) $paidAmount += max(0.0, (float)($rec['paid_amount'] ?? 0));

        $set = ["status='CANCELADO'"];
        if (nexa_cancel_column_exists($pdo, 'finance_receivables', 'open_amount')) $set[] = 'open_amount=0';
        if (nexa_cancel_column_exists($pdo, 'finance_receivables', 'canceled_at')) $set[] = 'canceled_at=COALESCE(canceled_at,NOW())';
        if (nexa_cancel_column_exists($pdo, 'finance_receivables', 'canceled_reason')) $set[] = 'canceled_reason=COALESCE(NULLIF(canceled_reason,\'\'),?)';
        if (nexa_cancel_column_exists($pdo, 'finance_receivables', 'updated_at')) $set[] = 'updated_at=NOW()';
        $params = [];
        if (str_contains(implode(',', $set), 'canceled_reason')) $params[] = $reason;
        $params = array_merge($params, [$companyId], $receivableIds);
        $pdo->prepare('UPDATE finance_receivables SET ' . implode(',', $set) . " WHERE company_id=? AND id IN ($ph)")->execute($params);
      }

      $amountToReverse = $receivableIds ? round($paidAmount,2) : round((float)($payment['amount'] ?? 0),2);
      $bankId = (int)($payment['bank_account_id'] ?? 0) ?: null;
      $chartId = (int)($payment['chart_account_id'] ?? 0) ?: null;
      $costId = (int)($payment['cost_center_id'] ?? 0) ?: null;
      $personId = $orderPersonId;
      $originalMovementId = (int)($payment['finance_movement_id'] ?? 0);
      if ((!$chartId || !$bankId || !$personId) && $originalMovementId > 0 && nexa_cancel_table_exists($pdo, 'finance_movements')) {
        $stOriginalMovement = $pdo->prepare('SELECT person_id, chart_account_id, cost_center_id, bank_account_id FROM finance_movements WHERE company_id=? AND id=? LIMIT 1');
        $stOriginalMovement->execute([$companyId, $originalMovementId]);
        $originalMovement = $stOriginalMovement->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$personId) $personId = (int)($originalMovement['person_id'] ?? 0) ?: null;
        if (!$chartId) $chartId = (int)($originalMovement['chart_account_id'] ?? 0) ?: null;
        if (!$costId) $costId = (int)($originalMovement['cost_center_id'] ?? 0) ?: null;
        if (!$bankId) $bankId = (int)($originalMovement['bank_account_id'] ?? 0) ?: null;
      }
      if ($reversalMovementId > 0 && nexa_cancel_table_exists($pdo, 'finance_movements')) {
        $stExistingReversal = $pdo->prepare('SELECT COALESCE(amount,0) FROM finance_movements WHERE company_id=? AND id=? LIMIT 1');
        $stExistingReversal->execute([$companyId, $reversalMovementId]);
        $result['finance'] += (float)$stExistingReversal->fetchColumn();
      } elseif ($amountToReverse > 0) {
        if (!$chartId) {
          $result['warning'] = trim($result['warning'] . ' Não foi possível registrar o estorno financeiro do pagamento #' . $paymentId . ' porque não há plano de contas vinculado.');
        } else {
          $reversalMovementId = nexa_cancel_insert_finance_reversal(
            $pdo, $companyId, $orderId, $amountToReverse, $personId,
            $bankId, $chartId, $costId,
            'Estorno do pedido #' . $orderId . ' por cancelamento do documento fiscal',
            $reason
          );
          $result['finance'] += $amountToReverse;
        }
      }

      $pdo->prepare('UPDATE gestao_order_payments SET canceled_at=COALESCE(canceled_at,NOW()), canceled_reason=COALESCE(NULLIF(canceled_reason,\'\'),?), reversal_movement_id=COALESCE(reversal_movement_id,?) WHERE company_id=? AND id=?')
        ->execute([$reason, $reversalMovementId ?: null, $companyId, $paymentId]);
      $result['payments']++;
    }

    if (nexa_cancel_table_exists($pdo, 'gestao_cash_operations')) {
      $stExisting = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM gestao_cash_operations WHERE company_id=? AND order_id=? AND UPPER(type)='ESTORNO'");
      $stExisting->execute([$companyId, $orderId]);
      $existing = (float)$stExisting->fetchColumn();
      if ($existing > 0) {
        $result['cash'] = $existing;
      } else {
        $stCash = $pdo->prepare("SELECT cash_session_id, bank_account_id, chart_account_id, cost_center_id, COALESCE(SUM(amount),0) amount
          FROM gestao_cash_operations
          WHERE company_id=? AND order_id=? AND UPPER(type)='VENDA'
          GROUP BY cash_session_id, bank_account_id, chart_account_id, cost_center_id
          HAVING amount>0");
        $stCash->execute([$companyId, $orderId]);
        $insCash = $pdo->prepare("INSERT INTO gestao_cash_operations (company_id,cash_session_id,user_id,type,amount,bank_account_id,chart_account_id,cost_center_id,movement_id,order_id,description,notes) VALUES (?,?,?,'ESTORNO',?,?,?,?,NULL,?,?,?)");
        $firstCashId = 0;
        foreach (($stCash->fetchAll(PDO::FETCH_ASSOC) ?: []) as $cash) {
          $amount = round((float)($cash['amount'] ?? 0),2);
          if ($amount <= 0) continue;
          $insCash->execute([
            $companyId,
            (int)($cash['cash_session_id'] ?? 0) ?: null,
            $userId ?: null,
            $amount,
            (int)($cash['bank_account_id'] ?? 0) ?: null,
            (int)($cash['chart_account_id'] ?? 0) ?: null,
            (int)($cash['cost_center_id'] ?? 0) ?: null,
            $orderId,
            'Estorno do pedido #' . $orderId . ' por cancelamento fiscal',
            $reason,
          ]);
          $cashId = (int)$pdo->lastInsertId();
          if ($firstCashId <= 0) $firstCashId = $cashId;
          $result['cash'] += $amount;
        }
        if ($firstCashId > 0) {
          $pdo->prepare('UPDATE gestao_order_payments SET reversal_cash_operation_id=COALESCE(reversal_cash_operation_id,?) WHERE company_id=? AND order_id=?')
            ->execute([$firstCashId, $companyId, $orderId]);
        }
      }
    }

    $result['finance'] = round((float)$result['finance'],2);
    $result['cash'] = round((float)$result['cash'],2);
    return $result;
  }
}

if (!function_exists('nexa_cancel_order')) {
  function nexa_cancel_order(PDO $pdo, int $companyId, int $orderId, string $reason, string $source = 'MANUAL', ?int $fiscalDocumentId = null, int $userId = 0): array {
    if ($companyId <= 0 || $orderId <= 0) throw new InvalidArgumentException('Empresa e pedido são obrigatórios para o cancelamento.');
    if (!empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'])) {
      if (!nexa_cancel_table_exists($pdo, 'order_cancellation_effects')) {
        throw new RuntimeException('Estrutura de cancelamento ainda não instalada. Execute o Atualizador do Banco antes de cancelar pelo F11.');
      }
    } else {
      nexa_order_cancellation_ensure_schema($pdo);
    }
    $source = strtoupper(trim($source)) ?: 'MANUAL';
    $reason = trim($reason);
    if ($reason === '') $reason = 'Pedido #' . $orderId . ' cancelado.';
    if (function_exists('mb_substr')) $reason = mb_substr($reason, 0, 255, 'UTF-8'); else $reason = substr($reason,0,255);

    $crossFiscalCancel = $source === 'FISCAL' && !empty($GLOBALS['NEXA_MULTI_COMPANY_FISCAL_CANCEL']);
    if ($source !== 'FISCAL') {
      // A NFS-e da O.S. costuma ter source_type=ORDEM_SERVICO. O cancelamento
      // comercial não pode ignorar um documento autorizado só por essa origem.
      if (nexa_cancel_table_exists($pdo,'os_orders') && nexa_cancel_table_exists($pdo,'fiscal_nfse_details')) {
        require_once __DIR__ . '/os_nfse_cancel_bridge.php';
        $stOs=$pdo->prepare('SELECT id FROM os_orders WHERE company_id=? AND pedido_id=? LIMIT 1');
        $stOs->execute([$companyId,$orderId]);
        if($stOs->fetchColumn() && os_nfse_related_authorized_documents($pdo,$companyId,$orderId))
          throw new RuntimeException('O pedido possui documento fiscal autorizado de O.S. Cancele primeiro a NFS-e/NF-e/NFC-e antes de liberar o refaturamento.');
      }
      $authorized = nexa_order_authorized_fiscal_document($pdo, $companyId, $orderId);
      if ($authorized) {
        $authorizedModel = strtoupper(trim((string)($authorized['model'] ?? '')));
        $label = $authorizedModel === '55' ? 'NF-e' : ($authorizedModel === '65' ? 'NFC-e' : ($authorizedModel === 'NFSE' ? 'NFS-e' : 'documento fiscal'));
        throw new RuntimeException('O pedido possui ' . $label . ' autorizada. Cancele primeiro o documento fiscal para que pedido, estoque e financeiro sejam revertidos em conjunto.');
      }
      // Se existir boleto ainda não enviado ao banco, ele será cancelado junto com o Pedido.
      // Se já houver registro/identidade bancária, o cancelamento é bloqueado para evitar
      // deixar o título ativo no Itaú enquanto o Pedido fica cancelado apenas no Nexa.
      billing_assert_order_can_cancel_locally($pdo, $companyId, $orderId);
    }

    $started = !$pdo->inTransaction();
    if ($started) $pdo->beginTransaction();
    try {
      $stOrder = $pdo->prepare('SELECT * FROM pedidos WHERE company_id=? AND id=? FOR UPDATE');
      $stOrder->execute([$companyId, $orderId]);
      $order = $stOrder->fetch(PDO::FETCH_ASSOC) ?: null;
      if (!$order) throw new RuntimeException('Pedido vinculado ao documento fiscal não foi encontrado.');
      $beforeStatus = (string)($order['status'] ?? '');

      // V11.52.7: parcelas de cartão abertas acompanham o cancelamento; recebimentos já liquidados bloqueiam o pedido até o ajuste no Controle de Cartões.
      card_control_cancel_open_order($pdo, $companyId, $orderId);

      $stEffect = $pdo->prepare('SELECT * FROM order_cancellation_effects WHERE company_id=? AND order_id=? FOR UPDATE');
      $stEffect->execute([$companyId, $orderId]);
      $effect = $stEffect->fetch(PDO::FETCH_ASSOC) ?: null;
      if ($effect && strtoupper((string)($effect['status'] ?? '')) === 'COMPLETED') {
        if (nexa_cancel_table_exists($pdo,'os_orders') && nexa_cancel_table_exists($pdo,'os_batch_invoices') && nexa_cancel_table_exists($pdo,'os_fiscal_queue')) {
          require_once __DIR__.'/os_nfse_cancel_bridge.php';
          os_nfse_release_after_order_cancel($pdo,$companyId,$orderId);
        }
        if ($started) $pdo->commit();
        return [
          'ok'=>true, 'already_processed'=>true, 'order_id'=>$orderId,
          'stock_quantity'=>(float)($effect['stock_reversed_qty'] ?? 0),
          'finance_amount'=>(float)($effect['finance_reversed_amount'] ?? 0),
          'cash_amount'=>(float)($effect['cash_reversed_amount'] ?? 0),
          'warning'=>'',
        ];
      }

      if (!$effect) {
        $pdo->prepare("INSERT INTO order_cancellation_effects (company_id,order_id,fiscal_document_id,source,status,reason,order_status_before,processed_by) VALUES (?,?,?,?,'PROCESSING',?,?,?)")
          ->execute([$companyId,$orderId,$fiscalDocumentId,$source,$reason,$beforeStatus,$userId ?: null]);
      } else {
        $pdo->prepare("UPDATE order_cancellation_effects SET fiscal_document_id=COALESCE(fiscal_document_id,?), source=?, status='PROCESSING', reason=?, processed_by=COALESCE(processed_by,?), error_message=NULL, updated_at=NOW() WHERE company_id=? AND order_id=?")
          ->execute([$fiscalDocumentId,$source,$reason,$userId ?: null,$companyId,$orderId]);
      }

      // V11.39: pedido com entrega futura possui reserva/saídas em ciclo próprio.
      // Regulariza o Controle de Entregas antes do estorno padrão do pedido, sem
      // alterar o comportamento de vendas que não utilizam o módulo.
      $deliveryControlFile = __DIR__ . '/delivery_control.php';
      if (is_file($deliveryControlFile)) {
        require_once $deliveryControlFile;
        if (function_exists('delivery_control_prepare_commercial_order_cancellation')) {
          delivery_control_prepare_commercial_order_cancellation($pdo, $companyId, $orderId, $userId ?: null);
        }
      }

      $stock = nexa_cancel_reverse_stock($pdo, $companyId, $orderId, $reason);
      $boletoCancellation = ['canceled'=>0];
      if ($source !== 'FISCAL' || $crossFiscalCancel) {
        $boletoCancellation = billing_cancel_order_unregistered_charges(
          $pdo,
          $companyId,
          $orderId,
          $reason,
          $crossFiscalCancel ? 'CANCELAMENTO_FISCAL_F11' : 'PEDIDO_CANCELADO'
        );
      }
      $financial = nexa_cancel_reverse_finance_and_cash($pdo, $companyId, $orderId, $reason, $userId);
      voucher_reverse_order_usages($pdo, $companyId, $orderId, $userId ?: null, $reason);

      $set = ["status='cancelado'"];
      $params = [];
      if (nexa_cancel_column_exists($pdo,'pedidos','canceled_at')) $set[] = 'canceled_at=COALESCE(canceled_at,NOW())';
      if (nexa_cancel_column_exists($pdo,'pedidos','cancel_reason')) { $set[]='cancel_reason=?'; $params[]=$reason; }
      if (nexa_cancel_column_exists($pdo,'pedidos','cancellation_source')) { $set[]='cancellation_source=?'; $params[]=$source; }
      if (nexa_cancel_column_exists($pdo,'pedidos','canceled_fiscal_document_id')) { $set[]='canceled_fiscal_document_id=?'; $params[]=$fiscalDocumentId; }
      $params[]=$companyId; $params[]=$orderId;
      $pdo->prepare('UPDATE pedidos SET ' . implode(',', $set) . ' WHERE company_id=? AND id=?')->execute($params);

      try {
        $brainFile = __DIR__ . '/brain_profitability.php';
        if (is_file($brainFile)) require_once $brainFile;
        if (function_exists('brain_profitability_sync_order_status')) brain_profitability_sync_order_status($pdo, $companyId, $orderId, 'cancelado', $beforeStatus);
      } catch (Throwable $e) {}

      $warnings = array_values(array_filter([(string)($stock['warning'] ?? ''), (string)($financial['warning'] ?? '')]));
      $status = $warnings ? 'PARTIAL' : 'COMPLETED';
      $message = $warnings ? implode(' ', $warnings) : 'Pedido, estoque e financeiro processados.';
      $pdo->prepare("UPDATE order_cancellation_effects SET fiscal_document_id=COALESCE(fiscal_document_id,?), source=?, status=?, reason=?, stock_reversed_qty=?, finance_reversed_amount=?, cash_reversed_amount=?, processed_at=NOW(), error_message=?, updated_at=NOW() WHERE company_id=? AND order_id=?")
        ->execute([$fiscalDocumentId,$source,$status,$reason,(float)$stock['quantity'],(float)$financial['finance'],(float)$financial['cash'],$warnings ? $message : null,$companyId,$orderId]);

      if ($fiscalDocumentId && nexa_cancel_table_exists($pdo,'fiscal_documents')) {
        $pdo->prepare("UPDATE fiscal_documents SET internal_cancellation_status=?, internal_cancellation_message=?, internal_cancellation_at=NOW(), updated_at=NOW() WHERE company_id=? AND id=?")
          ->execute([$status,$message,$companyId,$fiscalDocumentId]);
      }

      if ($status === 'COMPLETED' && nexa_cancel_table_exists($pdo,'os_orders') && nexa_cancel_table_exists($pdo,'os_batch_invoices') && nexa_cancel_table_exists($pdo,'os_fiscal_queue')) {
        require_once __DIR__.'/os_nfse_cancel_bridge.php';
        os_nfse_release_after_order_cancel($pdo,$companyId,$orderId);
      }
      if ($started) $pdo->commit();
      return [
        'ok'=>true,
        'already_processed'=>false,
        'order_id'=>$orderId,
        'status'=>$status,
        'stock_quantity'=>(float)$stock['quantity'],
        'stock_products'=>(int)$stock['products'],
        'finance_amount'=>(float)$financial['finance'],
        'cash_amount'=>(float)$financial['cash'],
        'boleto_canceled'=>(int)($boletoCancellation['canceled'] ?? 0),
        'warning'=>$warnings ? $message : '',
      ];
    } catch (Throwable $e) {
      if ($started && $pdo->inTransaction()) $pdo->rollBack();
      try {
        $pdo->prepare("INSERT INTO order_cancellation_effects (company_id,order_id,fiscal_document_id,source,status,reason,processed_by,error_message,updated_at) VALUES (?,?,?,?,'ERROR',?,?,?,NOW()) ON DUPLICATE KEY UPDATE fiscal_document_id=COALESCE(fiscal_document_id,VALUES(fiscal_document_id)), source=VALUES(source), status='ERROR', reason=VALUES(reason), processed_by=COALESCE(processed_by,VALUES(processed_by)), error_message=VALUES(error_message), updated_at=NOW()")
          ->execute([$companyId,$orderId,$fiscalDocumentId,$source,$reason,$userId ?: null,$e->getMessage()]);
        if ($fiscalDocumentId && nexa_cancel_table_exists($pdo,'fiscal_documents')) {
          $pdo->prepare("UPDATE fiscal_documents SET internal_cancellation_status='ERROR', internal_cancellation_message=?, internal_cancellation_at=NOW(), updated_at=NOW() WHERE company_id=? AND id=?")
            ->execute([$e->getMessage(),$companyId,$fiscalDocumentId]);
        }
      } catch (Throwable $ignored) {}
      throw $e;
    }
  }
}

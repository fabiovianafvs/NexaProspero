<?php
declare(strict_types=1);

require_once __DIR__ . '/product_composition.php';

if (!function_exists('nexa_stock_recalc_table_exists')) {
  function nexa_stock_recalc_table_exists(PDO $pdo, string $table): bool {
    try {
      $st=$pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
      $st->execute([$table]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('nexa_stock_recalc_ensure_schema')) {
  function nexa_stock_recalc_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_recalculation_runs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      company_id BIGINT UNSIGNED NOT NULL,
      user_id BIGINT UNSIGNED NULL,
      mode VARCHAR(20) NOT NULL DEFAULT 'PREVIEW',
      status VARCHAR(20) NOT NULL DEFAULT 'STARTED',
      canceled_orders_found INT NOT NULL DEFAULT 0,
      canceled_orders_reversed INT NOT NULL DEFAULT 0,
      products_rebuilt INT NOT NULL DEFAULT 0,
      quantity_reversed DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
      discrepancies_found INT NOT NULL DEFAULT 0,
      details_json LONGTEXT NULL,
      error_message TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      finished_at DATETIME NULL,
      PRIMARY KEY(id),
      KEY idx_stock_recalc_company_date(company_id,created_at),
      KEY idx_stock_recalc_status(company_id,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }
}

if (!function_exists('nexa_stock_recalc_missing_cancelled_orders')) {
  function nexa_stock_recalc_missing_cancelled_orders(PDO $pdo, int $companyId): array {
    if (!nexa_stock_recalc_table_exists($pdo,'pedidos') || !nexa_stock_recalc_table_exists($pdo,'estoque_movimentos')) return [];
    $sql="SELECT p.id, COALESCE(p.numero_pedido,p.id) numero_pedido, p.status,
      COALESCE(SUM(CASE WHEN LOWER(COALESCE(em.tipo,''))='saida' AND em.origem_tipo='PDV' THEN ABS(em.qtd) ELSE 0 END),0) saida_original,
      COALESCE(SUM(CASE WHEN LOWER(COALESCE(em.tipo,''))='entrada' AND em.origem_tipo='CANCELAMENTO_PEDIDO' THEN ABS(em.qtd) ELSE 0 END),0) devolvido
      FROM pedidos p
      LEFT JOIN estoque_movimentos em ON em.company_id=p.company_id AND em.origem_id=p.id
        AND em.origem_tipo IN ('PDV','CANCELAMENTO_PEDIDO')
      WHERE p.company_id=? AND LOWER(COALESCE(p.status,''))='cancelado'
      GROUP BY p.id, p.numero_pedido, p.status
      HAVING saida_original>0 AND devolvido<saida_original
      ORDER BY p.id";
    $st=$pdo->prepare($sql); $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('nexa_stock_recalc_discrepancies')) {
  function nexa_stock_recalc_discrepancies(PDO $pdo, int $companyId): array {
    if (!nexa_stock_recalc_table_exists($pdo,'produtos') || !nexa_stock_recalc_table_exists($pdo,'estoque_movimentos')) return [];
    $sql="SELECT p.id produto_id,p.nome,COALESCE(p.estoque_atual,0) estoque_atual,
      COALESCE((SELECT em.saldo_posterior FROM estoque_movimentos em WHERE em.company_id=p.company_id AND em.produto_id=p.id ORDER BY em.id DESC LIMIT 1),p.estoque_atual,0) estoque_movimentos
      FROM produtos p
      WHERE p.company_id=?
      HAVING ABS(estoque_atual-estoque_movimentos)>0.00005
      ORDER BY p.nome,p.id";
    $st=$pdo->prepare($sql); $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('nexa_stock_recalc_preview')) {
  function nexa_stock_recalc_preview(PDO $pdo, int $companyId): array {
    $missing=nexa_stock_recalc_missing_cancelled_orders($pdo,$companyId);
    $diffs=nexa_stock_recalc_discrepancies($pdo,$companyId);
    return [
      'missing_cancellations'=>$missing,
      'discrepancies'=>$diffs,
      'canceled_orders_found'=>count($missing),
      'discrepancies_found'=>count($diffs),
    ];
  }
}

if (!function_exists('nexa_stock_recalc_apply')) {
  function nexa_stock_recalc_apply(PDO $pdo, int $companyId, int $userId=0): array {
    if ($companyId<=0) throw new InvalidArgumentException('Empresa inválida.');
    nexa_stock_recalc_ensure_schema($pdo);
    $preview=nexa_stock_recalc_preview($pdo,$companyId);
    $runSt=$pdo->prepare("INSERT INTO stock_recalculation_runs(company_id,user_id,mode,status,canceled_orders_found,discrepancies_found,details_json) VALUES (?,?,'APPLY','STARTED',?,?,?)");
    $runSt->execute([$companyId,$userId?:null,$preview['canceled_orders_found'],$preview['discrepancies_found'],json_encode($preview,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $runId=(int)$pdo->lastInsertId();
    $started=!$pdo->inTransaction();
    if ($started) $pdo->beginTransaction();
    $reversedOrders=0; $reversedQty=0.0; $rebuilt=0; $warnings=[];
    try {
      $cancelFile=__DIR__.'/order_cancellation.php';
      if (is_file($cancelFile)) require_once $cancelFile;
      foreach ($preview['missing_cancellations'] as $row) {
        $orderId=(int)$row['id'];
        if ($orderId<=0) continue;
        if (function_exists('nexa_cancel_reverse_stock')) {
          $r=nexa_cancel_reverse_stock($pdo,$companyId,$orderId,'Reprocessamento manual do estoque em Configurações.');
          if ((float)($r['quantity']??0)>0) { $reversedOrders++; $reversedQty+=(float)$r['quantity']; }
          if (!empty($r['warning'])) $warnings[]='Pedido #'.($row['numero_pedido']??$orderId).': '.$r['warning'];
        } else {
          $warnings[]='Rotina de cancelamento integrada não encontrada para o pedido #'.($row['numero_pedido']??$orderId).'.';
        }
      }

      // Releitura após inserir eventuais devoluções.
      $diffs=nexa_stock_recalc_discrepancies($pdo,$companyId);
      $upd=$pdo->prepare('UPDATE produtos SET estoque_atual=? WHERE company_id=? AND id=?');
      foreach ($diffs as $row) {
        $upd->execute([(float)$row['estoque_movimentos'],$companyId,(int)$row['produto_id']]);
        $rebuilt++;
      }

      if (function_exists('product_composition_enabled') && product_composition_enabled($pdo, $companyId) && product_composition_schema_ready($pdo)) {
        product_composition_recalculate_all($pdo, $companyId, 'Reprocessamento geral de estoque');
      }

      if ($started) $pdo->commit();
      $result=[
        'run_id'=>$runId,'canceled_orders_found'=>$preview['canceled_orders_found'],
        'canceled_orders_reversed'=>$reversedOrders,'quantity_reversed'=>round($reversedQty,4),
        'products_rebuilt'=>$rebuilt,'warnings'=>$warnings,
      ];
      $pdo->prepare("UPDATE stock_recalculation_runs SET status='COMPLETED',canceled_orders_reversed=?,products_rebuilt=?,quantity_reversed=?,details_json=?,finished_at=NOW() WHERE id=? AND company_id=?")
        ->execute([$reversedOrders,$rebuilt,round($reversedQty,4),json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$runId,$companyId]);
      return $result;
    } catch (Throwable $e) {
      if ($started && $pdo->inTransaction()) $pdo->rollBack();
      try { $pdo->prepare("UPDATE stock_recalculation_runs SET status='ERROR',error_message=?,finished_at=NOW() WHERE id=? AND company_id=?")->execute([$e->getMessage(),$runId,$companyId]); } catch (Throwable $ignored) {}
      throw $e;
    }
  }
}

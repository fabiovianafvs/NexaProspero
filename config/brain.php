<?php
declare(strict_types=1);

require_once __DIR__ . '/brain_profitability.php';
require_once __DIR__ . '/brain_realtime.php';
require_once __DIR__ . '/brain_actions.php';

if (!function_exists('brain_money')) {
  function brain_money(float $value): string {
    return 'R$ ' . number_format($value, 2, ',', '.');
  }
}

if (!function_exists('brain_percent')) {
  function brain_percent(float $value, int $decimals = 1): string {
    return number_format($value, $decimals, ',', '.') . '%';
  }
}

if (!function_exists('brain_log_query_error')) {
  function brain_log_query_error(Throwable $e, string $sql = ''): void {
    error_log('[Nexa Brain] ' . $e->getMessage() . ($sql !== '' ? ' | SQL: ' . preg_replace('/\s+/', ' ', $sql) : ''));
  }
}

if (!function_exists('brain_scalar')) {
  function brain_scalar(PDO $pdo, string $sql, array $params = [], float $fallback = 0.0, ?array &$errors = null): float {
    try {
      $st = $pdo->prepare($sql);
      $st->execute($params);
      $value = $st->fetchColumn();
      return $value === false || $value === null ? $fallback : (float)$value;
    } catch (Throwable $e) {
      brain_log_query_error($e, $sql);
      if (is_array($errors)) $errors[] = $e->getMessage();
      return $fallback;
    }
  }
}

if (!function_exists('brain_row')) {
  function brain_row(PDO $pdo, string $sql, array $params = [], ?array &$errors = null): array {
    try {
      $st = $pdo->prepare($sql);
      $st->execute($params);
      return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
      brain_log_query_error($e, $sql);
      if (is_array($errors)) $errors[] = $e->getMessage();
      return [];
    }
  }
}

if (!function_exists('brain_rows')) {
  function brain_rows(PDO $pdo, string $sql, array $params = [], ?array &$errors = null): array {
    try {
      $st = $pdo->prepare($sql);
      $st->execute($params);
      return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
      brain_log_query_error($e, $sql);
      if (is_array($errors)) $errors[] = $e->getMessage();
      return [];
    }
  }
}

if (!function_exists('brain_period')) {
  function brain_period(int $days = 30): array {
    $days = max(1, min(730, $days));
    $end = (new DateTimeImmutable('tomorrow'))->format('Y-m-d 00:00:00');
    $start = (new DateTimeImmutable('today'))->modify('-' . ($days - 1) . ' days')->format('Y-m-d 00:00:00');
    return [$start, $end];
  }
}

if (!function_exists('brain_sales_filter')) {
  function brain_sales_filter(string $alias = 'pe'): string {
    return "{$alias}.status = 'finalizado' AND {$alias}.recognized_at IS NOT NULL";
  }
}

if (!function_exists('brain_snapshot')) {
  function brain_snapshot(PDO $pdo, int $companyId): array {
    $errors = [];
    if (!$pdo->inTransaction()) {
      $schemaErrors = brain_profitability_ensure_schema($pdo, true);
      if ($schemaErrors) $errors = array_merge($errors, $schemaErrors);
      try { brain_profitability_initialize_average_costs($pdo, $companyId); } catch (Throwable $e) { $errors[] = $e->getMessage(); }
    }

    $todayStart = (new DateTimeImmutable('today'))->format('Y-m-d 00:00:00');
    $tomorrowStart = (new DateTimeImmutable('tomorrow'))->format('Y-m-d 00:00:00');
    $yesterdayStart = (new DateTimeImmutable('yesterday'))->format('Y-m-d 00:00:00');
    $monthStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00');

    $today = brain_row($pdo, "SELECT
        COALESCE(SUM(total),0) AS sales_total,
        COALESCE(SUM(receita_liquida_produtos),0) AS product_revenue,
        COALESCE(SUM(total_cmv),0) AS cmv,
        COALESCE(SUM(lucro_bruto),0) AS gross_profit,
        COUNT(*) AS orders_count,
        COALESCE(SUM(discount_amount),0) AS discounts,
        COALESCE(SUM(addition_amount),0) AS additions,
        COALESCE(SUM(taxa_entrega),0) AS delivery_fees
      FROM pedidos
      WHERE company_id = ? AND " . brain_sales_filter('pedidos') . " AND recognized_at >= ? AND recognized_at < ?", [$companyId, $todayStart, $tomorrowStart], $errors);

    $salesYesterday = brain_scalar($pdo, "SELECT COALESCE(SUM(total),0) FROM pedidos WHERE company_id=? AND " . brain_sales_filter('pedidos') . " AND recognized_at>=? AND recognized_at<?", [$companyId, $yesterdayStart, $todayStart], 0.0, $errors);
    $salesMonth = brain_scalar($pdo, "SELECT COALESCE(SUM(total),0) FROM pedidos WHERE company_id=? AND " . brain_sales_filter('pedidos') . " AND recognized_at>=? AND recognized_at<?", [$companyId, $monthStart, $tomorrowStart], 0.0, $errors);
    $openOrders = brain_scalar($pdo, "SELECT COUNT(*) FROM pedidos WHERE company_id=? AND status IN ('aberto','confirmado','em_producao','saiu_entrega')", [$companyId], 0.0, $errors);
    $lowStock = brain_scalar($pdo, "SELECT COUNT(*) FROM produtos WHERE company_id=? AND ativo=1 AND estoque_minimo>0 AND (estoque_atual-estoque_reservado)<=estoque_minimo", [$companyId], 0.0, $errors);
    $overdueReceivables = brain_scalar($pdo, "SELECT COALESCE(SUM(open_amount),0) FROM finance_receivables WHERE company_id=? AND due_date<CURDATE() AND open_amount>0 AND UPPER(status) NOT IN ('PAGO','QUITADO','CANCELADO')", [$companyId], 0.0, $errors);
    $duePayables7 = brain_scalar($pdo, "SELECT COALESCE(SUM(open_amount),0) FROM finance_payables WHERE company_id=? AND due_date>=CURDATE() AND due_date<CURDATE()+INTERVAL 8 DAY AND open_amount>0 AND UPPER(status) NOT IN ('PAGO','QUITADO','CANCELADO')", [$companyId], 0.0, $errors);
    $dueReceivables7 = brain_scalar($pdo, "SELECT COALESCE(SUM(open_amount),0) FROM finance_receivables WHERE company_id=? AND due_date>=CURDATE() AND due_date<CURDATE()+INTERVAL 8 DAY AND open_amount>0 AND UPPER(status) NOT IN ('PAGO','QUITADO','CANCELADO')", [$companyId], 0.0, $errors);

    $inventory = brain_row($pdo, "SELECT
        COALESCE(SUM(GREATEST(estoque_atual-estoque_reservado,0) * COALESCE(NULLIF(custo_medio,0), NULLIF(custo,0), 0)),0) AS cost_value,
        COALESCE(SUM(GREATEST(estoque_atual-estoque_reservado,0) * preco),0) AS sale_value,
        SUM(CASE WHEN GREATEST(estoque_atual-estoque_reservado,0)>0 AND COALESCE(NULLIF(custo_medio,0), NULLIF(custo,0), 0)<=0 THEN 1 ELSE 0 END) AS products_without_cost,
        SUM(CASE WHEN estoque_maximo>0 AND (estoque_atual-estoque_reservado)>estoque_maximo THEN 1 ELSE 0 END) AS excess_products
      FROM produtos WHERE company_id=? AND ativo=1", [$companyId], $errors);

    $estimatedOrders = brain_scalar($pdo, "SELECT COUNT(*) FROM pedidos WHERE company_id=? AND status='finalizado' AND profitability_cost_source IN ('estimado_cadastro','misto_com_estimativa','misto_com_custo_ausente')", [$companyId], 0.0, $errors);
    $negativeMarginOrders = brain_scalar($pdo, "SELECT COUNT(*) FROM pedidos WHERE company_id=? AND " . brain_sales_filter('pedidos') . " AND lucro_bruto<0 AND recognized_at>=?", [$companyId, $monthStart], 0.0, $errors);
    $salesBelowCostItems = brain_scalar($pdo, "SELECT COUNT(*) FROM pedido_itens pi JOIN pedidos pe ON pe.company_id=pi.company_id AND pe.id=pi.pedido_id WHERE pi.company_id=? AND " . brain_sales_filter('pe') . " AND pi.lucro_bruto<0 AND pe.recognized_at>=?", [$companyId, $monthStart], 0.0, $errors);

    $salesToday = (float)($today['sales_total'] ?? 0);
    $productRevenueToday = (float)($today['product_revenue'] ?? 0);
    $cmvToday = (float)($today['cmv'] ?? 0);
    $grossProfitToday = (float)($today['gross_profit'] ?? 0);
    $ordersToday = (float)($today['orders_count'] ?? 0);
    $ticket = $ordersToday > 0 ? $salesToday / $ordersToday : 0.0;
    $marginSale = $productRevenueToday > 0 ? ($grossProfitToday / $productRevenueToday) * 100 : 0.0;
    $marginCost = $cmvToday > 0 ? ($grossProfitToday / $cmvToday) * 100 : 0.0;
    $markup = $cmvToday > 0 ? $productRevenueToday / $cmvToday : 0.0;
    $variation = $salesYesterday > 0 ? (($salesToday - $salesYesterday) / $salesYesterday) * 100 : ($salesToday > 0 ? 100.0 : 0.0);
    $inventoryCost = (float)($inventory['cost_value'] ?? 0);
    $inventorySale = (float)($inventory['sale_value'] ?? 0);
    $inventoryPotentialProfit = $inventorySale - $inventoryCost;
    $inventoryMarginSale = $inventorySale > 0 ? ($inventoryPotentialProfit / $inventorySale) * 100 : 0.0;
    $inventoryMarginCost = $inventoryCost > 0 ? ($inventoryPotentialProfit / $inventoryCost) * 100 : 0.0;

    $score = 100;
    if ($variation < -20) $score -= 18; elseif ($variation < 0) $score -= 8;
    if ($lowStock > 0) $score -= min(20, (int)$lowStock * 2);
    if ($overdueReceivables > 0) $score -= 18;
    if ($duePayables7 > $dueReceivables7) $score -= 12;
    if ($openOrders > 20) $score -= 8;
    if ((int)($inventory['products_without_cost'] ?? 0) > 0) $score -= 8;
    if ($negativeMarginOrders > 0) $score -= min(18, (int)$negativeMarginOrders * 3);
    if ($errors) $score -= 5;
    $score = max(0, min(100, $score));

    return [
      'salesToday' => $salesToday,
      'salesYesterday' => $salesYesterday,
      'salesMonth' => $salesMonth,
      'productRevenueToday' => $productRevenueToday,
      'cmvToday' => $cmvToday,
      'grossProfitToday' => $grossProfitToday,
      'marginSaleToday' => $marginSale,
      'marginCostToday' => $marginCost,
      'markupToday' => $markup,
      'discountsToday' => (float)($today['discounts'] ?? 0),
      'additionsToday' => (float)($today['additions'] ?? 0),
      'deliveryFeesToday' => (float)($today['delivery_fees'] ?? 0),
      'ordersToday' => $ordersToday,
      'openOrders' => $openOrders,
      'lowStock' => $lowStock,
      'overdueReceivables' => $overdueReceivables,
      'duePayables7' => $duePayables7,
      'dueReceivables7' => $dueReceivables7,
      'ticket' => $ticket,
      'variation' => $variation,
      'inventoryCost' => $inventoryCost,
      'inventorySale' => $inventorySale,
      'inventoryPotentialProfit' => $inventoryPotentialProfit,
      'inventoryMarginSale' => $inventoryMarginSale,
      'inventoryMarginCost' => $inventoryMarginCost,
      'productsWithoutCost' => (float)($inventory['products_without_cost'] ?? 0),
      'excessProducts' => (float)($inventory['excess_products'] ?? 0),
      'estimatedOrders' => $estimatedOrders,
      'negativeMarginOrders' => $negativeMarginOrders,
      'salesBelowCostItems' => $salesBelowCostItems,
      'score' => $score,
      'dataAvailable' => !$errors,
      'errors' => array_values(array_unique($errors)),
    ];
  }
}

if (!function_exists('brain_category_results')) {
  function brain_category_results(PDO $pdo, int $companyId, int $days = 30, int $limit = 12): array {
    [$start, $end] = brain_period($days);
    $filter = brain_sales_filter('pe');
    $sql = "SELECT g.*,
        ROUND(CASE WHEN totals.total_revenue>0 THEN g.revenue/totals.total_revenue*100 ELSE 0 END,2) AS representation
      FROM (
        SELECT COALESCE(NULLIF(pi.categoria_snapshot,''),'SEM CATEGORIA') AS category,
          ROUND(SUM(pi.receita_liquida),2) AS revenue,
          ROUND(SUM(pi.cmv_total),2) AS cmv,
          ROUND(SUM(pi.lucro_bruto),2) AS gross_profit,
          ROUND(CASE WHEN SUM(pi.receita_liquida)>0 THEN SUM(pi.lucro_bruto)/SUM(pi.receita_liquida)*100 ELSE 0 END,2) AS margin_sale,
          ROUND(CASE WHEN SUM(pi.cmv_total)>0 THEN SUM(pi.lucro_bruto)/SUM(pi.cmv_total)*100 ELSE 0 END,2) AS margin_cost,
          ROUND(CASE WHEN SUM(pi.cmv_total)>0 THEN SUM(pi.receita_liquida)/SUM(pi.cmv_total) ELSE 0 END,4) AS markup,
          ROUND(SUM(pi.qtd),3) AS quantity
        FROM pedido_itens pi
        JOIN pedidos pe ON pe.company_id=pi.company_id AND pe.id=pi.pedido_id
        WHERE pi.company_id=? AND {$filter} AND pe.recognized_at>=? AND pe.recognized_at<?
        GROUP BY COALESCE(NULLIF(pi.categoria_snapshot,''),'SEM CATEGORIA')
      ) g
      CROSS JOIN (
        SELECT COALESCE(SUM(pi.receita_liquida),0) AS total_revenue
        FROM pedido_itens pi
        JOIN pedidos pe ON pe.company_id=pi.company_id AND pe.id=pi.pedido_id
        WHERE pi.company_id=? AND {$filter} AND pe.recognized_at>=? AND pe.recognized_at<?
      ) totals
      ORDER BY g.revenue DESC
      LIMIT " . max(1, min(100, $limit));
    return brain_rows($pdo, $sql, [$companyId, $start, $end, $companyId, $start, $end]);
  }
}

if (!function_exists('brain_seller_results')) {
  function brain_seller_results(PDO $pdo, int $companyId, int $days = 30, int $limit = 12): array {
    [$start, $end] = brain_period($days);
    $filter = brain_sales_filter('pe');
    $sql = "SELECT g.*,
        ROUND(CASE WHEN totals.total_sales>0 THEN g.sales_total/totals.total_sales*100 ELSE 0 END,2) AS representation
      FROM (
        SELECT COALESCE(NULLIF(fp.name,''), CASE WHEN pe.seller_id IS NULL OR pe.seller_id=0 THEN 'SEM VENDEDOR' ELSE CONCAT('VENDEDOR #',pe.seller_id) END) AS seller,
          pe.seller_id,
          COUNT(*) AS orders_count,
          ROUND(SUM(pe.total),2) AS sales_total,
          ROUND(SUM(pe.receita_liquida_produtos),2) AS product_revenue,
          ROUND(SUM(pe.total_cmv),2) AS cmv,
          ROUND(SUM(pe.lucro_bruto),2) AS gross_profit,
          ROUND(CASE WHEN SUM(pe.receita_liquida_produtos)>0 THEN SUM(pe.lucro_bruto)/SUM(pe.receita_liquida_produtos)*100 ELSE 0 END,2) AS margin_sale,
          ROUND(SUM(pe.total)/NULLIF(COUNT(*),0),2) AS average_ticket,
          ROUND(SUM(pe.discount_amount),2) AS discounts
        FROM pedidos pe
        LEFT JOIN finance_people fp ON fp.company_id=pe.company_id AND fp.id=pe.seller_id
        WHERE pe.company_id=? AND {$filter} AND pe.recognized_at>=? AND pe.recognized_at<?
        GROUP BY pe.seller_id, COALESCE(NULLIF(fp.name,''), CASE WHEN pe.seller_id IS NULL OR pe.seller_id=0 THEN 'SEM VENDEDOR' ELSE CONCAT('VENDEDOR #',pe.seller_id) END)
      ) g
      CROSS JOIN (
        SELECT COALESCE(SUM(pe.total),0) AS total_sales
        FROM pedidos pe
        WHERE pe.company_id=? AND {$filter} AND pe.recognized_at>=? AND pe.recognized_at<?
      ) totals
      ORDER BY g.sales_total DESC
      LIMIT " . max(1, min(100, $limit));
    return brain_rows($pdo, $sql, [$companyId, $start, $end, $companyId, $start, $end]);
  }
}

if (!function_exists('brain_channel_results')) {
  function brain_channel_results(PDO $pdo, int $companyId, int $days = 30, int $limit = 12): array {
    [$start, $end] = brain_period($days);
    $filter = brain_sales_filter('pe');
    $sql = "SELECT g.*,
        ROUND(CASE WHEN totals.total_sales>0 THEN g.sales_total/totals.total_sales*100 ELSE 0 END,2) AS representation
      FROM (
        SELECT COALESCE(NULLIF(pe.sales_channel,''),'nao_informado') AS channel,
          COUNT(*) AS orders_count,
          ROUND(SUM(pe.total),2) AS sales_total,
          ROUND(SUM(pe.receita_liquida_produtos),2) AS product_revenue,
          ROUND(SUM(pe.total_cmv),2) AS cmv,
          ROUND(SUM(pe.lucro_bruto),2) AS gross_profit,
          ROUND(CASE WHEN SUM(pe.receita_liquida_produtos)>0 THEN SUM(pe.lucro_bruto)/SUM(pe.receita_liquida_produtos)*100 ELSE 0 END,2) AS margin_sale,
          ROUND(SUM(pe.total)/NULLIF(COUNT(*),0),2) AS average_ticket,
          ROUND(SUM(pe.taxa_entrega),2) AS delivery_fees
        FROM pedidos pe
        WHERE pe.company_id=? AND {$filter} AND pe.recognized_at>=? AND pe.recognized_at<?
        GROUP BY COALESCE(NULLIF(pe.sales_channel,''),'nao_informado')
      ) g
      CROSS JOIN (
        SELECT COALESCE(SUM(pe.total),0) AS total_sales
        FROM pedidos pe
        WHERE pe.company_id=? AND {$filter} AND pe.recognized_at>=? AND pe.recognized_at<?
      ) totals
      ORDER BY g.sales_total DESC
      LIMIT " . max(1, min(100, $limit));
    return brain_rows($pdo, $sql, [$companyId, $start, $end, $companyId, $start, $end]);
  }
}

if (!function_exists('brain_top_products')) {
  function brain_top_products(PDO $pdo, int $companyId, int $days = 30, int $limit = 8): array {
    [$start, $end] = brain_period($days);
    return brain_rows($pdo, "SELECT
        COALESCE(NULLIF(pi.produto_nome_snapshot,''),p.nome,CONCAT('Produto #',pi.produto_id)) AS nome,
        SUM(pi.qtd) AS qtd,
        ROUND(SUM(pi.receita_liquida),2) AS total,
        ROUND(SUM(pi.cmv_total),2) AS cmv,
        ROUND(SUM(pi.lucro_bruto),2) AS lucro_bruto,
        ROUND(CASE WHEN SUM(pi.receita_liquida)>0 THEN SUM(pi.lucro_bruto)/SUM(pi.receita_liquida)*100 ELSE 0 END,2) AS margem
      FROM pedido_itens pi
      JOIN pedidos pe ON pe.id=pi.pedido_id AND pe.company_id=pi.company_id
      LEFT JOIN produtos p ON p.id=pi.produto_id AND p.company_id=pi.company_id
      WHERE pi.company_id=? AND " . brain_sales_filter('pe') . " AND pe.recognized_at>=? AND pe.recognized_at<?
      GROUP BY pi.produto_id, COALESCE(NULLIF(pi.produto_nome_snapshot,''),p.nome,CONCAT('Produto #',pi.produto_id))
      ORDER BY total DESC LIMIT " . max(1, min(100, $limit)), [$companyId, $start, $end]);
  }
}

if (!function_exists('brain_stock_risks')) {
  function brain_stock_risks(PDO $pdo, int $companyId, int $limit = 10): array {
    return brain_rows($pdo, "SELECT id, nome, categoria, estoque_atual, estoque_reservado, estoque_minimo, estoque_maximo, preco,
        COALESCE(NULLIF(custo_medio,0),NULLIF(custo,0),0) AS unit_cost,
        ROUND(GREATEST(estoque_atual-estoque_reservado,0)*COALESCE(NULLIF(custo_medio,0),NULLIF(custo,0),0),2) AS stock_cost,
        ROUND(GREATEST(estoque_atual-estoque_reservado,0)*preco,2) AS stock_sale
      FROM produtos
      WHERE company_id=? AND ativo=1 AND (
        (estoque_minimo>0 AND (estoque_atual-estoque_reservado)<=estoque_minimo)
        OR (estoque_maximo>0 AND (estoque_atual-estoque_reservado)>estoque_maximo)
        OR (GREATEST(estoque_atual-estoque_reservado,0)>0 AND COALESCE(NULLIF(custo_medio,0),NULLIF(custo,0),0)<=0)
      )
      ORDER BY stock_cost DESC, nome ASC LIMIT " . max(1, min(100, $limit)), [$companyId]);
  }
}

if (!function_exists('brain_insights')) {
  function brain_insights(PDO $pdo, int $companyId, array $s): array {
    $items = [];
    if ($s['salesBelowCostItems'] > 0) $items[] = ['level'=>'danger','title'=>'Venda abaixo do custo','message'=>(int)$s['salesBelowCostItems'].' item(ns) tiveram lucro bruto negativo neste mês. Revise preço, descontos e custo cadastrado.','action'=>'Analisar pedidos','url'=>'pedidos.php'];
    if ($s['productsWithoutCost'] > 0) $items[] = ['level'=>'danger','title'=>'Produtos sem custo','message'=>(int)$s['productsWithoutCost'].' produto(s) com estoque não possuem custo. O CMV e o valor do estoque ficarão incompletos.','action'=>'Cadastrar custos','url'=>'produtos.php'];
    if ($s['lowStock'] > 0) $items[] = ['level'=>'danger','title'=>'Risco de ruptura','message'=>(int)$s['lowStock'].' produto(s) estão no estoque mínimo ou abaixo dele. Priorize os itens de maior giro e margem.','action'=>'Revisar estoque','url'=>'produtos.php'];
    if ($s['excessProducts'] > 0) $items[] = ['level'=>'warning','title'=>'Capital em excesso de estoque','message'=>(int)$s['excessProducts'].' produto(s) estão acima do estoque máximo. Considere campanha, combo ou redução de compras.','action'=>'Revisar estoque','url'=>'produtos.php'];
    if ($s['estimatedOrders'] > 0) $items[] = ['level'=>'info','title'=>'CMV histórico estimado','message'=>(int)$s['estimatedOrders'].' pedido(s) antigo(s) usam o custo atual do cadastro como estimativa. Novas vendas passam a congelar o custo real vigente.','action'=>'Ver pedidos','url'=>'pedidos.php'];
    if ($s['overdueReceivables'] > 0) $items[] = ['level'=>'warning','title'=>'Inadimplência exige atenção','message'=>'Existem '.brain_money($s['overdueReceivables']).' em contas vencidas a receber.','action'=>'Abrir contas a receber','url'=>'financeiro_contas_receber.php'];
    if ($s['duePayables7'] > $s['dueReceivables7']) $items[] = ['level'=>'warning','title'=>'Pressão no fluxo de caixa','message'=>'Nos próximos 7 dias, os pagamentos previstos superam os recebimentos em '.brain_money($s['duePayables7']-$s['dueReceivables7']).'.','action'=>'Ver financeiro','url'=>'financeiro.php'];
    if ($s['variation'] <= -10) $items[] = ['level'=>'warning','title'=>'Vendas abaixo de ontem','message'=>'O faturamento reconhecido de hoje está '.brain_percent(abs($s['variation'])).' abaixo de ontem. Avalie horário, canal, ticket e produtos líderes.','action'=>'Analisar pedidos','url'=>'pedidos.php'];
    if ($s['marginSaleToday'] > 0 && $s['marginSaleToday'] < 20) $items[] = ['level'=>'warning','title'=>'Margem do dia baixa','message'=>'A margem sobre venda está em '.brain_percent($s['marginSaleToday']).'. Reveja descontos, custos e mix de produtos.','action'=>'Ver pedidos','url'=>'pedidos.php'];
    if ($s['variation'] >= 10 && $s['grossProfitToday'] > 0) $items[] = ['level'=>'success','title'=>'Desempenho positivo','message'=>'O faturamento de hoje está '.brain_percent($s['variation']).' acima de ontem, com lucro bruto de '.brain_money($s['grossProfitToday']).'.','action'=>'Ver dashboard','url'=>'dashboard.php'];
    if (!$s['dataAvailable']) $items[] = ['level'=>'warning','title'=>'Indicadores parcialmente indisponíveis','message'=>'Uma ou mais consultas falharam. O sistema registrou o erro técnico em vez de apresentar zeros como se fossem resultados reais.','action'=>'Ver diagnóstico','url'=>'brain.php'];
    if (!$items) $items[] = ['level'=>'success','title'=>'Operação equilibrada','message'=>'Não foram detectados riscos relevantes nos indicadores principais neste momento.','action'=>'Ver dashboard','url'=>'dashboard.php'];
    return array_slice($items, 0, 8);
  }
}

if (!function_exists('brain_channel_label')) {
  function brain_channel_label(string $channel): string {
    return match ($channel) {
      'delivery_online' => 'Delivery online',
      'pdv_balcao' => 'PDV / Balcão',
      'pdv' => 'PDV',
      'comanda' => 'Comanda',
      'api' => 'API',
      'marketplace' => 'Marketplace',
      'importacao' => 'Importação',
      default => $channel !== '' ? ucfirst(str_replace('_', ' ', $channel)) : 'Não informado',
    };
  }
}

if (!function_exists('brain_business_advice')) {
  function brain_business_advice(PDO $pdo, int $companyId, array $s, ?array $categories = null): array {
    $advice = [];
    $categories = $categories ?? brain_category_results($pdo, $companyId, 30, 20);
    foreach ($categories as $category) {
      if ((float)$category['representation'] >= 20 && (float)$category['margin_sale'] > 0 && (float)$category['margin_sale'] < 20) {
        $advice[] = 'A categoria “' . $category['category'] . '” representa ' . brain_percent((float)$category['representation']) . ' das vendas, mas entrega margem de apenas ' . brain_percent((float)$category['margin_sale']) . '. Priorize revisão de preço, fornecedor, ficha técnica e descontos.';
        break;
      }
    }
    if ($s['productsWithoutCost'] > 0) $advice[] = 'Cadastre o custo dos produtos sem custo antes de tomar decisões de preço; sem isso, o lucro apresentado fica incompleto.';
    if ($s['salesBelowCostItems'] > 0) $advice[] = 'Bloqueie ou alerte vendas abaixo do custo e revise imediatamente os itens que geraram margem negativa.';
    if ($s['excessProducts'] > 0) $advice[] = 'Transforme estoque excedente em caixa com combos, venda adicional, campanhas segmentadas ou redução temporária de reposição.';
    if ($s['lowStock'] > 0) $advice[] = 'Reponha primeiro os produtos de maior giro e margem, evitando investir igualmente em itens pouco rentáveis.';
    if ($s['ticket'] > 0) $advice[] = 'Use o ticket médio atual de ' . brain_money($s['ticket']) . ' como referência e crie combos ou venda complementar para elevar esse valor sem depender apenas de mais clientes.';
    if ($s['marginSaleToday'] > 0 && $s['marginSaleToday'] < 25) $advice[] = 'A margem sobre venda do dia está em ' . brain_percent($s['marginSaleToday']) . '. Reduzir descontos indiscriminados pode aumentar o lucro mesmo sem aumentar o faturamento.';
    if (!$advice) $advice[] = 'Mantenha o acompanhamento por categoria, vendedor e canal. O próximo ganho tende a vir do aumento do ticket e da priorização dos produtos com melhor margem e giro.';
    return array_slice(array_values(array_unique($advice)), 0, 5);
  }
}

if (!function_exists('brain_dashboard_cache_key')) {
  function brain_dashboard_cache_key(int $companyId): string {
    return 'nexa:brain:company:' . $companyId . ':dashboard:v3';
  }
}

if (!function_exists('brain_dashboard_build')) {
  function brain_dashboard_build(PDO $pdo, int $companyId): array {
    $snapshot = brain_snapshot($pdo, $companyId);
    $categories = brain_category_results($pdo, $companyId, 30, 20);
    $sellers = brain_seller_results($pdo, $companyId, 30, 20);
    $channels = brain_channel_results($pdo, $companyId, 30, 20);
    $topProducts = brain_top_products($pdo, $companyId, 30, 12);
    $stockRisks = brain_stock_risks($pdo, $companyId, 20);
    $actions = brain_actions_dashboard_data($pdo, $companyId, 12);
    return [
      'companyId' => $companyId,
      'generatedAt' => date(DATE_ATOM),
      'snapshot' => $snapshot,
      'actions' => $actions,
      'insights' => brain_insights($pdo, $companyId, $snapshot),
      'topProducts' => $topProducts,
      'categories' => $categories,
      'sellers' => $sellers,
      'channels' => $channels,
      'stockRisks' => $stockRisks,
      'advice' => brain_business_advice($pdo, $companyId, $snapshot, $categories),
    ];
  }
}

if (!function_exists('brain_dashboard_get')) {
  function brain_dashboard_get(PDO $pdo, int $companyId, bool $forceRefresh = false): array {
    $key = brain_dashboard_cache_key($companyId);
    if (!$forceRefresh) {
      $cached = nexa_cache_get_json($key);
      if (is_array($cached) && (int)($cached['companyId'] ?? 0) === $companyId && !empty($cached['snapshot'])) {
        $cached['_cache'] = ['source'=>'redis','hit'=>true];
        return $cached;
      }
    }
    $data = brain_dashboard_build($pdo, $companyId);
    nexa_cache_set_json($key, $data);
    $data['_cache'] = ['source'=>'database','hit'=>false];
    return $data;
  }
}

if (!function_exists('brain_dashboard_rebuild_cache')) {
  function brain_dashboard_rebuild_cache(PDO $pdo, int $companyId): array {
    $data = brain_dashboard_build($pdo, $companyId);
    if (!nexa_cache_set_json(brain_dashboard_cache_key($companyId), $data)) {
      throw new RuntimeException('Não foi possível gravar o cache de indicadores no Redis.');
    }
    $data['_cache'] = ['source'=>'worker','hit'=>false];
    return $data;
  }
}

if (!function_exists('brain_answer')) {
  function brain_answer(PDO $pdo, int $companyId, string $question): array {
    $q = mb_strtolower(trim($question), 'UTF-8');
    $dashboard = brain_dashboard_get($pdo, $companyId);
    $s = (array)($dashboard['snapshot'] ?? []);
    if ($q === '') return ['answer'=>'Digite uma pergunta sobre vendas, lucro, CMV, margem, categorias, vendedores, canais, estoque, financeiro ou alertas que precisam de ação.','type'=>'help'];

    if (str_contains($q,'alerta') || str_contains($q,'atenção') || str_contains($q,'atencao') || str_contains($q,'prioridade') || str_contains($q,'resolver primeiro')) {
      $counts = (array)(($dashboard['actions'] ?? [])['counts'] ?? []);
      $alerts = array_slice((array)(($dashboard['actions'] ?? [])['alerts'] ?? []),0,3);
      if ((int)($counts['total'] ?? 0) <= 0) return ['answer'=>'Não existem alertas abertos na Central de Ações neste momento.','type'=>'actions'];
      $parts = [];
      foreach ($alerts as $alert) $parts[] = (string)$alert['title'];
      return ['answer'=>'Há '.(int)$counts['total'].' alerta(s) aberto(s): '.(int)($counts['critical'] ?? 0).' crítico(s), '.(int)($counts['high'] ?? 0).' de prioridade alta e '.(int)($counts['warning'] ?? 0).' de atenção. Prioridades atuais: '.implode('; ',$parts).'.','type'=>'actions'];
    }

    if (str_contains($q, 'lucro') || str_contains($q, 'cmv') || str_contains($q, 'margem') || str_contains($q, 'markup')) {
      return ['answer'=>'Hoje, a receita líquida dos produtos é '.brain_money($s['productRevenueToday']).', o CMV é '.brain_money($s['cmvToday']).' e o lucro bruto é '.brain_money($s['grossProfitToday']).'. Margem sobre venda: '.brain_percent($s['marginSaleToday']).'; margem sobre custo: '.brain_percent($s['marginCostToday']).'; markup fator: '.number_format($s['markupToday'],2,',','.').'x.','type'=>'profit'];
    }
    if (str_contains($q,'vendi hoje') || str_contains($q,'vendas hoje') || str_contains($q,'faturamento hoje')) {
      return ['answer'=>'Hoje a empresa reconheceu '.brain_money($s['salesToday']).' em '.number_format($s['ordersToday'],0,',','.').' pedido(s), com ticket médio de '.brain_money($s['ticket']).' e lucro bruto de '.brain_money($s['grossProfitToday']).'.','type'=>'sales'];
    }
    if (str_contains($q,'mês') || str_contains($q,'mes')) return ['answer'=>'O faturamento reconhecido acumulado no mês é de '.brain_money($s['salesMonth']).'. Para lucro e margem, pergunte “qual meu lucro hoje?” ou consulte os rankings de 30 dias.','type'=>'sales'];
    if (str_contains($q,'categoria')) {
      $rows = array_slice((array)($dashboard['categories'] ?? []), 0, 3);
      if (!$rows) return ['answer'=>'Ainda não há vendas finalizadas suficientes para calcular o resultado por categoria.','type'=>'category'];
      $parts = [];
      foreach ($rows as $r) $parts[] = $r['category'].' — '.brain_money((float)$r['revenue']).' ('.brain_percent((float)$r['representation']).' das vendas; margem '.brain_percent((float)$r['margin_sale']).')';
      return ['answer'=>'Categorias líderes nos últimos 30 dias: '.implode('; ', $parts).'.','type'=>'category'];
    }
    if (str_contains($q,'vendedor')) {
      $rows = array_slice((array)($dashboard['sellers'] ?? []), 0, 3);
      if (!$rows) return ['answer'=>'Ainda não há vendas finalizadas com dados suficientes para o relatório de vendedores.','type'=>'seller'];
      $parts = [];
      foreach ($rows as $r) $parts[] = $r['seller'].' — '.brain_money((float)$r['sales_total']).', lucro '.brain_money((float)$r['gross_profit']).', ticket '.brain_money((float)$r['average_ticket']);
      return ['answer'=>'Desempenho dos vendedores nos últimos 30 dias: '.implode('; ', $parts).'.','type'=>'seller'];
    }
    if (str_contains($q,'delivery') || str_contains($q,'balcão') || str_contains($q,'balcao') || str_contains($q,'canal') || str_contains($q,'comanda')) {
      $rows = array_slice((array)($dashboard['channels'] ?? []), 0, 5);
      if (!$rows) return ['answer'=>'Ainda não há vendas finalizadas suficientes para comparar os canais.','type'=>'channel'];
      $parts = [];
      foreach ($rows as $r) $parts[] = brain_channel_label((string)$r['channel']).' — '.brain_money((float)$r['sales_total']).' ('.brain_percent((float)$r['representation']).'; margem '.brain_percent((float)$r['margin_sale']).')';
      return ['answer'=>'Representatividade por canal nos últimos 30 dias: '.implode('; ', $parts).'.','type'=>'channel'];
    }
    if ((str_contains($q,'valor') && str_contains($q,'estoque')) || str_contains($q,'estoque em custo') || str_contains($q,'estoque em venda')) {
      return ['answer'=>'O estoque disponível vale '.brain_money($s['inventoryCost']).' a custo e '.brain_money($s['inventorySale']).' a preço de venda. O lucro bruto potencial é '.brain_money($s['inventoryPotentialProfit']).', equivalente a '.brain_percent($s['inventoryMarginSale']).' sobre a venda e '.brain_percent($s['inventoryMarginCost']).' sobre o custo.','type'=>'stock_value'];
    }
    if (str_contains($q,'estoque') || str_contains($q,'ruptura')) return ['answer'=>$s['lowStock'] > 0 ? 'Há '.(int)$s['lowStock'].' produto(s) no estoque mínimo ou abaixo dele e '.(int)$s['excessProducts'].' acima do estoque máximo. O estoque disponível está avaliado em '.brain_money($s['inventoryCost']).' a custo.' : 'O Brain não detectou produtos abaixo do estoque mínimo. O estoque disponível está avaliado em '.brain_money($s['inventoryCost']).' a custo e '.brain_money($s['inventorySale']).' a venda.','type'=>'stock'];
    if (str_contains($q,'aumentar') || str_contains($q,'melhorar') || str_contains($q,'dica') || str_contains($q,'o que fazer')) {
      $advice = (array)($dashboard['advice'] ?? []);
      return ['answer'=>'Ações recomendadas: '.implode(' ', array_map(static fn($v, $i) => ($i + 1).') '.$v, $advice, array_keys($advice))), 'type'=>'advice'];
    }
    if (str_contains($q,'receber') || str_contains($q,'inadimpl')) return ['answer'=>'Existem '.brain_money($s['overdueReceivables']).' vencidos a receber. Para os próximos 7 dias, estão previstos '.brain_money($s['dueReceivables7']).' em recebimentos.','type'=>'finance'];
    if (str_contains($q,'pagar') || str_contains($q,'fluxo de caixa')) return ['answer'=>'Nos próximos 7 dias há '.brain_money($s['duePayables7']).' a pagar e '.brain_money($s['dueReceivables7']).' a receber. Saldo projetado: '.brain_money($s['dueReceivables7']-$s['duePayables7']).'.','type'=>'finance'];
    if (str_contains($q,'melhor produto') || str_contains($q,'mais vendido')) {
      $top = array_slice((array)($dashboard['topProducts'] ?? []), 0, 1);
      if (!$top) return ['answer'=>'Ainda não há vendas finalizadas suficientes nos últimos 30 dias para montar o ranking.','type'=>'products'];
      $first=$top[0]; return ['answer'=>'O produto líder nos últimos 30 dias é “'.$first['nome'].'”, com '.number_format((float)$first['qtd'],3,',','.').' unidades, '.brain_money((float)$first['total']).' em receita líquida e '.brain_money((float)$first['lucro_bruto']).' de lucro bruto.','type'=>'products'];
    }
    return ['answer'=>'Posso responder sobre vendas, lucro bruto, CMV, margens, markup, categorias, vendedores, canais, estoque, fluxo de caixa e alertas que precisam de ação.','type'=>'help'];
  }
}

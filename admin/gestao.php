<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_gestao.php';
require_once __DIR__ . '/../config/cash_financial_report.php';

$companyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'sales_dashboard_read')
  : (int)COMPANY_ID;
$gestaoSalesCross = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'sales_dashboard_read');
$gestaoPdvCrossAllowed = $gestaoSalesCross
  && function_exists('multi_company_context_permission_allowed')
  && multi_company_context_permission_allowed($pdo, 'gestao_pdv');
$gestaoMobileCrossAllowed = $gestaoSalesCross
  && function_exists('multi_company_context_permission_allowed')
  && multi_company_context_permission_allowed($pdo, 'gestao_pdv_mobile');
$gestaoSummaryCrossAllowed = $gestaoSalesCross
  && function_exists('multi_company_context_permission_allowed')
  && multi_company_context_permission_allowed($pdo, 'gestao_pdv_resumido');
$gestaoPaymentsCrossAllowed = $gestaoSalesCross
  && function_exists('multi_company_context_permission_allowed')
  && multi_company_context_permission_allowed($pdo, 'gestao_pagamentos');
$gestaoCashCrossAllowed = $gestaoSalesCross
  && function_exists('multi_company_context_permission_allowed')
  && multi_company_context_permission_allowed($pdo, 'gestao_caixa');
if ($gestaoSalesCross && function_exists('multi_company_context_permission_allowed')
    && !multi_company_context_permission_allowed($pdo, 'gestao_dashboard')) {
  http_response_code(403);
  admin_topbar('Gestão', 'gestao');
  echo '<div class="msg err" style="margin:24px">Seu perfil não possui permissão para o Dashboard de Vendas na empresa selecionada pelo F11.</div>';
  admin_footer();
  exit;
}
$gestaoCompanyName = '';
try {
  $stCompany = $pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1');
  $stCompany->execute([$companyId]);
  $gestaoCompanyName = trim((string)($stCompany->fetchColumn() ?: ''));
} catch (Throwable $e) {}
$userId = (int)(auth_user_id() ?? 0);
$today = date('Y-m-d');
$monthStart = date('Y-m-01');

if (!function_exists('gestao_dash_percent')) {
  function gestao_dash_percent(float $value, float $total): float {
    return $total > 0 ? round(($value / $total) * 100, 1) : 0.0;
  }
}
if (!function_exists('gestao_dash_label')) {
  function gestao_dash_label($value): string {
    $v = trim((string)$value);
    return $v !== '' ? $v : 'Sem identificação';
  }
}

$ordersToday = 0;
$salesToday = 0.0;
$salesMonth = 0.0;
$openCash = null;
$cashSummary = null;
$operatorName = 'Operador';
$paymentRows = [];
$productRows = [];

try {
  $st = $pdo->prepare("SELECT COUNT(*) qtd, COALESCE(SUM(total),0) total FROM pedidos WHERE company_id = ? AND DATE(COALESCE(created_at, NOW())) = CURDATE() AND COALESCE(status,'') <> 'cancelado'");
  $st->execute([$companyId]);
  $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  $ordersToday = (int)($r['qtd'] ?? 0);
  $salesToday = (float)($r['total'] ?? 0);

  $st = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM pedidos WHERE company_id = ? AND DATE(COALESCE(created_at, NOW())) BETWEEN ? AND ? AND COALESCE(status,'') <> 'cancelado'");
  $st->execute([$companyId, $monthStart, $today]);
  $salesMonth = (float)$st->fetchColumn();

  if (!$gestaoSalesCross || $gestaoCashCrossAllowed) {
    $openCash = gestao_open_cash_session($pdo, $companyId, $userId);
    if ($openCash) {
      $cashSummary = gestao_cash_summary($pdo, $companyId, (int)$openCash['id']);
    }
  }
} catch (Throwable $e) {}

try {
  $u = finance_query_one($pdo, 'SELECT name, email FROM users WHERE id = ? LIMIT 1', [$userId]);
  if ($u) $operatorName = trim((string)($u['name'] ?? '')) ?: trim((string)($u['email'] ?? '')) ?: 'Operador';
} catch (Throwable $e) {}

try {
  if (finance_table_exists($pdo, 'gestao_order_payments')) {
    $expr = "COALESCE(NULLIF(TRIM(gop.payment_label),''), NULLIF(TRIM(pm.name),''), NULLIF(TRIM(p.forma_pagamento),''), 'Sem forma')";
    $st = $pdo->prepare("SELECT {$expr} AS name, COALESCE(SUM(gop.amount),0) AS total
      FROM gestao_order_payments gop
      LEFT JOIN pedidos p ON p.id = gop.order_id AND p.company_id = gop.company_id
      LEFT JOIN finance_payment_methods pm ON pm.id = gop.payment_method_id AND pm.company_id = gop.company_id
      WHERE gop.company_id = ?
        AND DATE(COALESCE(p.created_at, gop.created_at, NOW())) = CURDATE()
        AND COALESCE(p.status,'') <> 'cancelado'
      GROUP BY {$expr}
      ORDER BY total DESC");
    $st->execute([$companyId]);
    $paymentRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
  if (!$paymentRows) {
    $expr = "COALESCE(NULLIF(TRIM(forma_pagamento),''), 'Sem forma')";
    $st = $pdo->prepare("SELECT {$expr} AS name, COALESCE(SUM(total),0) AS total
      FROM pedidos
      WHERE company_id = ?
        AND DATE(COALESCE(created_at, NOW())) = CURDATE()
        AND COALESCE(status,'') <> 'cancelado'
      GROUP BY {$expr}
      ORDER BY total DESC");
    $st->execute([$companyId]);
    $paymentRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
} catch (Throwable $e) { $paymentRows = []; }

try {
  $st = $pdo->prepare("SELECT
        COALESCE(NULLIF(TRIM(pr.nome),''), CONCAT('Produto #', pi.produto_id)) AS name,
        COALESCE(SUM(pi.qtd),0) AS qty,
        COALESCE(SUM(pi.total),0) AS total
      FROM pedido_itens pi
      JOIN pedidos pe ON pe.id = pi.pedido_id AND pe.company_id = pi.company_id
      LEFT JOIN produtos pr ON pr.id = pi.produto_id AND pr.company_id = pi.company_id
      WHERE pi.company_id = ?
        AND DATE(COALESCE(pe.created_at, NOW())) = CURDATE()
        AND COALESCE(pe.status,'') <> 'cancelado'
      GROUP BY pi.produto_id, pr.nome
      ORDER BY qty DESC, total DESC
      LIMIT 10");
  $st->execute([$companyId]);
  $productRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $productRows = []; }

$paymentTotal = 0.0;
foreach ($paymentRows as $p) $paymentTotal += (float)($p['total'] ?? 0);
$productQtyTotal = 0.0;
$productValueTotal = 0.0;
foreach ($productRows as $p) {
  $productQtyTotal += (float)($p['qty'] ?? 0);
  $productValueTotal += (float)($p['total'] ?? 0);
}

$piePalette = ['#1d4ed8','#059669','#f59e0b','#dc2626','#7c3aed','#0891b2','#be123c','#65a30d'];
$pieParts = [];
$cursor = 0.0;
foreach ($paymentRows as $idx => $row) {
  $pct = gestao_dash_percent((float)($row['total'] ?? 0), $paymentTotal);
  if ($pct <= 0) continue;
  $from = $cursor;
  $to = min(100.0, $cursor + $pct);
  $pieParts[] = $piePalette[$idx % count($piePalette)] . ' ' . $from . '% ' . $to . '%';
  $cursor = $to;
}
$pieStyle = $pieParts ? 'background: conic-gradient(' . implode(',', $pieParts) . ');' : '';

$cashPayments = (array)($cashSummary['cash_sales_payments'] ?? []);
$cashTotalPayments = 0.0;
$cashDinheiro = 0.0;
foreach ($cashPayments as $p) {
  $v = (float)($p['total'] ?? 0);
  $cashTotalPayments += $v;
  if (stripos((string)($p['name'] ?? ''), 'dinheiro') !== false) $cashDinheiro += $v;
}

$cashOpenedAt = $openCash ? date('d/m/Y H:i', strtotime((string)($openCash['opened_at'] ?? 'now'))) : '';
$cashBankName = '';
if ($openCash && (int)($openCash['bank_account_id'] ?? 0) > 0) {
  try {
    $bankRow = finance_query_one($pdo, 'SELECT name FROM finance_bank_accounts WHERE company_id=? AND id=? LIMIT 1', [$companyId, (int)$openCash['bank_account_id']]);
    $cashBankName = (string)($bankRow['name'] ?? '');
  } catch (Throwable $e) {}
}



gestao_admin_start('Gestão', 'gestao');
echo nexa_cash_financial_report_css();
?>
<style>
  .gestao-dashboard-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(360px,.85fr);gap:14px;margin-top:14px;align-items:start}.gestao-dashboard-grid.inverse{grid-template-columns:minmax(360px,.85fr) minmax(0,1fr)}
  .payment-donut-wrap{display:grid;grid-template-columns:220px minmax(0,1fr);gap:18px;align-items:center}.payment-donut{width:210px;aspect-ratio:1;border-radius:999px;position:relative;box-shadow:inset 0 0 0 1px #dbe4f0}.payment-donut:after{content:'';position:absolute;inset:42px;background:#fff;border-radius:999px;border:1px solid #e2e8f0}.payment-donut-center{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;z-index:2;text-align:center;pointer-events:none}.payment-donut-center strong{display:block;color:var(--admin-primary);font-size:20px}.payment-donut-center span{display:block;color:#64748b;font-size:12px;font-weight:850}.payment-legend{display:grid;gap:8px}.payment-legend-row{display:grid;grid-template-columns:18px minmax(0,1fr) auto;gap:9px;align-items:center}.payment-dot{width:12px;height:12px;border-radius:999px}.payment-name{font-weight:850;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.payment-total{font-weight:950;color:#0f172a;white-space:nowrap}.payment-percent{color:#64748b;font-size:12px;font-weight:850}
  .product-ranking{display:grid;gap:12px}.product-row{display:grid;grid-template-columns:36px minmax(0,1fr) auto;gap:12px;align-items:center}.product-pos{width:34px;height:34px;border-radius:12px;background:#eff6ff;color:#1d4ed8;font-weight:950;display:flex;align-items:center;justify-content:center}.product-info{min-width:0}.product-title{font-weight:950;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.product-meta{color:#64748b;font-size:12px;font-weight:750;margin-top:2px}.product-bar{height:9px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin-top:7px}.product-bar span{display:block;height:100%;background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));border-radius:999px}.product-pct{text-align:right;font-weight:950;color:var(--admin-primary);white-space:nowrap}.product-pct small{display:block;color:#64748b;font-size:11px;font-weight:850;margin-top:2px}
  .cash-receipt{border:1px solid #cbd5e1;border-radius:18px;background:#fff;padding:14px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;color:#0f172a}.cash-receipt-title{text-align:center;font-family:inherit;font-weight:950;border-bottom:1px dashed #94a3b8;padding-bottom:8px;margin-bottom:9px}.cash-receipt-line{display:flex;justify-content:space-between;gap:10px;border-bottom:1px dashed #e2e8f0;padding:6px 0}.cash-receipt-line strong{white-space:nowrap}.cash-receipt-section{margin-top:10px;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}.cash-receipt-section h3{font-family:inherit;font-size:13px;text-align:center;margin:0;padding:6px;background:#f8fafc;border-bottom:1px solid #e2e8f0}.cash-receipt-total{font-weight:950;background:#f8fafc}.empty-state{border:1px dashed #cbd5e1;border-radius:18px;padding:22px;text-align:center;color:#64748b;background:#f8fbff}.section-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;margin-bottom:12px}.section-head h2{margin:0}.section-head span{color:#64748b;font-size:13px;font-weight:800}
  @media(max-width:1150px){.gestao-dashboard-grid,.gestao-dashboard-grid.inverse{grid-template-columns:1fr}.payment-donut-wrap{grid-template-columns:190px minmax(0,1fr)}.payment-donut{width:180px}.payment-donut:after{inset:36px}}
  @media(max-width:680px){.payment-donut-wrap{grid-template-columns:1fr}.payment-donut{width:min(240px,80vw);margin:auto}.payment-donut:after{inset:46px}.product-row{grid-template-columns:32px minmax(0,1fr);align-items:start}.product-pct{grid-column:2;text-align:left}.section-head{display:block}.cash-receipt{font-size:12px}.cash-receipt-line{align-items:flex-start}.gestao-dashboard-grid{gap:12px}.mg-grid-4{gap:10px}}
</style>
<?php
$gestaoHeaderActions = [];
if ($gestaoSalesCross) {
  if ($gestaoPdvCrossAllowed) $gestaoHeaderActions[] = [
    'label'=>'+ Novo pedido PDV',
    'href'=>function_exists('multi_company_context_url') ? multi_company_context_url('gestao_pdv.php') : 'gestao_pdv.php',
    'class'=>'btn btn-primary'
  ];
  if ($gestaoMobileCrossAllowed) $gestaoHeaderActions[] = [
    'label'=>'Venda Mobile',
    'href'=>function_exists('multi_company_context_url') ? multi_company_context_url('gestao_pdv_mobile.php') : 'gestao_pdv_mobile.php',
    'class'=>'btn'
  ];
  if ($gestaoSummaryCrossAllowed) $gestaoHeaderActions[] = [
    'label'=>'PDV Resumido',
    'href'=>function_exists('multi_company_context_url') ? multi_company_context_url('gestao_pdv_resumido.php') : 'gestao_pdv_resumido.php',
    'class'=>'btn'
  ];
  if ($gestaoCashCrossAllowed) $gestaoHeaderActions[] = [
    'label'=>'Caixa',
    'href'=>function_exists('multi_company_context_url') ? multi_company_context_url('gestao_caixa.php') : 'gestao_caixa.php',
    'class'=>'btn'
  ];
} else {
  $gestaoHeaderActions = [
    ['label'=>'+ Novo pedido PDV', 'href'=>'gestao_pdv.php', 'class'=>'btn btn-primary'],
    ['label'=>'Caixa', 'href'=>'gestao_caixa.php', 'class'=>'btn'],
  ];
}
gestao_page_header(
  'Gestão',
  $gestaoSalesCross
    ? 'V11.28 · Vendas Multiempresa: indicadores, pedidos, Pedido PDV, Venda Mobile, PDV Resumido, Formas de Pagamento e Caixa operacional na empresa selecionada; emissão fiscal permanece protegida.'
    : '',
  $gestaoHeaderActions
);
gestao_flash();
?>
<?php if ($gestaoSalesCross): ?>
  <div class="msg ok"><strong>V11.28 · Vendas Multiempresa.</strong> Os indicadores abaixo pertencem a <strong><?= gestao_h($gestaoCompanyName !== '' ? $gestaoCompanyName : ('Empresa #' . $companyId)) ?></strong>. Pedido PDV, Venda Mobile, PDV Resumido, Formas de Pagamento e Caixa operacional trabalham na empresa selecionada. Comandas, Pedido Detalhado e Fiscal permanecem protegidos.</div>
<?php endif; ?>
<style>
  /* Painel no padrão do Movimento Financeiro: quadros baixos, ações rápidas numa linha. */
  .gestao-kpis{gap:10px;margin-bottom:12px}
  .gestao-kpis .mg-kpi{padding:8px 14px;border-radius:14px;display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap;min-width:0}
  .gestao-kpis .mg-kpi span{font-size:12px}
  .gestao-kpis .mg-kpi strong{margin-top:0;font-size:clamp(17px,1.7vw,21px)!important}
  .gestao-stack{display:grid;gap:14px;margin-bottom:14px}
  .gestao-quick{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px}
  .gestao-quick .cash-action{padding:9px 12px;border-radius:14px;font-size:13px}
  .gestao-quick .cash-key{height:28px;width:38px}
</style>
<div class="mg-grid-4 gestao-kpis">
  <div class="mg-kpi"><span>Vendas de hoje</span><strong><?= gestao_money($salesToday) ?></strong></div>
  <div class="mg-kpi"><span>Pedidos hoje</span><strong><?= (int)$ordersToday ?></strong></div>
  <div class="mg-kpi"><span>Vendas do mês</span><strong><?= gestao_money($salesMonth) ?></strong></div>
  <?php if ($gestaoSalesCross && !$gestaoCashCrossAllowed): ?>
    <div class="mg-kpi"><span>Contexto operacional</span><strong style="font-size:18px">PDV sem fiscal</strong></div>
  <?php else: ?>
    <div class="mg-kpi <?= $openCash ? '' : 'red' ?>"><span>Caixa do usuário</span><strong><?= $openCash ? 'Aberto' : 'Fechado' ?></strong></div>
  <?php endif; ?>
</div>

<div class="gestao-stack">
  <div class="mg-card">
    <h2>Ações rápidas</h2>
    <div class="cash-menu gestao-quick">
      <?php if ($gestaoSalesCross): ?>
        <?php if ($gestaoPdvCrossAllowed): ?><a class="cash-action" href="<?= gestao_h(function_exists('multi_company_context_url') ? multi_company_context_url('gestao_pdv.php') : 'gestao_pdv.php') ?>"><span>Pedido PDV sem fiscal</span><span class="cash-key">F2</span></a><?php endif; ?>
        <?php if ($gestaoMobileCrossAllowed): ?><a class="cash-action" href="<?= gestao_h(function_exists('multi_company_context_url') ? multi_company_context_url('gestao_pdv_mobile.php') : 'gestao_pdv_mobile.php') ?>"><span>Venda Mobile sem fiscal</span><span class="cash-key">M</span></a><?php endif; ?>
        <?php if ($gestaoSummaryCrossAllowed): ?><a class="cash-action" href="<?= gestao_h(function_exists('multi_company_context_url') ? multi_company_context_url('gestao_pdv_resumido.php') : 'gestao_pdv_resumido.php') ?>"><span>PDV Resumido sem fiscal</span><span class="cash-key">R</span></a><?php endif; ?>
        <?php if ($gestaoCashCrossAllowed): ?><a class="cash-action" href="<?= gestao_h(function_exists('multi_company_context_url') ? multi_company_context_url('gestao_caixa.php') : 'gestao_caixa.php') ?>"><span>Abertura/Fechamento</span><span class="cash-key">F3</span></a><?php endif; ?>
        <?php if ($gestaoPaymentsCrossAllowed): ?><a class="cash-action" href="<?= gestao_h(function_exists('multi_company_context_url') ? multi_company_context_url('gestao_formas_pagamento.php') : 'gestao_formas_pagamento.php') ?>"><span>Formas de pagamento</span><span class="cash-key">⚙</span></a><?php endif; ?>
        <a class="cash-action" href="<?= gestao_h(function_exists('multi_company_context_url') ? multi_company_context_url('pedidos.php') : 'pedidos.php') ?>"><span>Consulta de pedidos</span><span class="cash-key">F6</span></a>
        <a class="cash-action" href="<?= gestao_h(function_exists('multi_company_context_url') ? multi_company_context_url('gestao_clientes.php') : 'gestao_clientes.php') ?>"><span>Clientes da empresa</span><span class="cash-key">F8</span></a>
      <?php else: ?>
        <a class="cash-action" href="gestao_pdv.php"><span>Pedido PDV</span><span class="cash-key">F2</span></a>
        <a class="cash-action" href="gestao_caixa.php"><span>Abertura/Fechamento</span><span class="cash-key">F3</span></a>
        <a class="cash-action" href="gestao_caixa.php?modal=suprimento"><span>Reforço de Caixa</span><span class="cash-key">F4</span></a>
        <a class="cash-action" href="gestao_caixa.php?modal=sangria"><span>Retirada / Sangria</span><span class="cash-key">F5</span></a>
        <a class="cash-action" href="gestao_pedidos.php"><span>Consulta de pedidos</span><span class="cash-key">F6</span></a>
        <a class="cash-action" href="gestao_formas_pagamento.php"><span>Formas de pagamento</span><span class="cash-key">⚙</span></a>
      <?php endif; ?>
    </div>
  </div>

  <div class="mg-card">
    <div class="section-head"><h2>Resumo do caixa</h2><span><?= $openCash ? 'Caixa atual' : 'Nenhum caixa aberto' ?></span></div>
    <?php if ($openCash): ?>
      <?= nexa_cash_financial_report_html((array)$cashSummary, (array)$openCash, $operatorName, $cashBankName) ?>
    <?php else: ?>
      <div class="empty-state"><?= $gestaoSalesCross ? 'O Caixa operacional permanece protegido no F11 nesta etapa. O Pedido PDV padrão já pode gravar na empresa selecionada, desde que a forma de pagamento não exija caixa aberto.' : 'Abra o caixa para acompanhar abertura, suprimento, sangria e resumo das vendas por forma de pagamento.' ?></div>
    <?php endif; ?>
  </div>
</div>

<div class="gestao-dashboard-grid inverse">
  <div class="mg-card">
    <div class="section-head"><h2>Formas de pagamento</h2><span>Vendas de hoje</span></div>
    <?php if ($paymentRows && $paymentTotal > 0): ?>
      <div class="payment-donut-wrap">
        <div class="payment-donut" style="<?= gestao_h($pieStyle) ?>">
          <div class="payment-donut-center"><div><strong><?= gestao_money($paymentTotal) ?></strong><span>Total informado</span></div></div>
        </div>
        <div class="payment-legend">
          <?php foreach ($paymentRows as $idx => $p): $pct = gestao_dash_percent((float)($p['total'] ?? 0), $paymentTotal); ?>
            <div class="payment-legend-row">
              <span class="payment-dot" style="background:<?= gestao_h($piePalette[$idx % count($piePalette)]) ?>"></span>
              <div><div class="payment-name"><?= gestao_h(gestao_dash_label($p['name'] ?? '')) ?></div><div class="payment-percent"><?= number_format($pct, 1, ',', '.') ?>% das vendas</div></div>
              <div class="payment-total"><?= gestao_money($p['total'] ?? 0) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="empty-state">Nenhuma venda com pagamento registrada hoje.</div>
    <?php endif; ?>
  </div>

  <div class="mg-card">
    <div class="section-head"><h2>Produtos mais vendidos</h2><span>Ordenado por quantidade</span></div>
    <?php if ($productRows): ?>
      <div class="product-ranking">
        <?php foreach ($productRows as $idx => $p):
          $qty = (float)($p['qty'] ?? 0);
          $total = (float)($p['total'] ?? 0);
          $pctQty = gestao_dash_percent($qty, $productQtyTotal);
          $pctValue = gestao_dash_percent($total, $productValueTotal);
        ?>
          <div class="product-row">
            <div class="product-pos"><?= $idx + 1 ?></div>
            <div class="product-info">
              <div class="product-title"><?= gestao_h(gestao_dash_label($p['name'] ?? '')) ?></div>
              <div class="product-meta"><?= number_format($qty, 2, ',', '.') ?> un. • <?= gestao_money($total) ?></div>
              <div class="product-bar"><span style="width:<?= max(3, min(100, $pctQty)) ?>%"></span></div>
            </div>
            <div class="product-pct"><?= number_format($pctQty, 1, ',', '.') ?>%<small><?= number_format($pctValue, 1, ',', '.') ?>% em valor</small></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state">Nenhum produto vendido hoje.</div>
    <?php endif; ?>
  </div>
</div>
<?php gestao_admin_end(); ?>

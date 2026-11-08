<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';

$cid = function_exists('finance_operational_company_id') ? finance_operational_company_id($pdo) : (int)COMPANY_ID;
$financeCrossReadOnly = function_exists('finance_multi_company_readonly') && finance_multi_company_readonly($pdo);
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

$sum = function(string $sql, array $params) use ($pdo): float {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return (float)($st->fetchColumn() ?: 0);
};

$moneyCompact = function(float $value): string {
  $abs = abs($value);
  if ($abs >= 1000000) return 'R$ ' . number_format($value / 1000000, 1, ',', '.') . ' mi';
  if ($abs >= 1000) return 'R$ ' . number_format($value / 1000, 1, ',', '.') . ' mil';
  return 'R$ ' . finance_money_br($value);
};



$receberAberto = $sum("SELECT COALESCE(SUM(open_amount),0) FROM finance_receivables WHERE company_id = ? AND status IN ('ABERTO','PARCIAL')", [$cid]);
$pagarAberto = $sum("SELECT COALESCE(SUM(open_amount),0) FROM finance_payables WHERE company_id = ? AND status IN ('ABERTO','PARCIAL')", [$cid]);
$creditosMes = $sum("SELECT COALESCE(SUM(amount),0) FROM finance_movements WHERE company_id = ? AND type = 'CREDITO' AND movement_date BETWEEN ? AND ? AND (status IS NULL OR status='' OR status='ATIVO')", [$cid, $monthStart, $monthEnd]);
$debitosMes = $sum("SELECT COALESCE(SUM(amount),0) FROM finance_movements WHERE company_id = ? AND type = 'DEBITO' AND movement_date BETWEEN ? AND ? AND (status IS NULL OR status='' OR status='ATIVO')", [$cid, $monthStart, $monthEnd]);
$saldoMes = $creditosMes - $debitosMes;

$receberVencidos = finance_query_all($pdo, "SELECT r.*, p.name AS person_name FROM finance_receivables r LEFT JOIN finance_people p ON p.company_id = r.company_id AND p.id = r.person_id WHERE r.company_id = ? AND r.status IN ('ABERTO','PARCIAL') AND r.due_date < ? ORDER BY r.due_date ASC LIMIT 8", [$cid, $today]);
$pagarVencidos = finance_query_all($pdo, "SELECT r.*, p.name AS person_name FROM finance_payables r LEFT JOIN finance_people p ON p.company_id = r.company_id AND p.id = r.person_id WHERE r.company_id = ? AND r.status IN ('ABERTO','PARCIAL') AND r.due_date < ? ORDER BY r.due_date ASC LIMIT 8", [$cid, $today]);

$monthNamesPt = [1=>'jan',2=>'fev',3=>'mar',4=>'abr',5=>'mai',6=>'jun',7=>'jul',8=>'ago',9=>'set',10=>'out',11=>'nov',12=>'dez'];
$baseMonth = new DateTimeImmutable('first day of this month');
$rangeStart = $baseMonth->modify('-11 months');
$rangeEnd = $baseMonth->modify('+1 month');
$previousRangeStart = $rangeStart->modify('-1 year');

$salesRows = finance_query_all($pdo, "
  SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS qtd, COALESCE(SUM(total),0) AS total
  FROM pedidos
  WHERE company_id = ?
    AND status <> 'cancelado'
    AND created_at >= ?
    AND created_at < ?
  GROUP BY DATE_FORMAT(created_at, '%Y-%m')
", [$cid, $previousRangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d')]);

$salesMap = [];
foreach ($salesRows as $r) {
  $salesMap[(string)$r['ym']] = ['qtd' => (int)($r['qtd'] ?? 0), 'total' => (float)($r['total'] ?? 0)];
}

$salesMonths = [];
$totalSalesCurrent = 0.0;
$totalSalesPrevious = 0.0;
$maxSalesMonth = 0.0;
for ($i = 11; $i >= 0; $i--) {
  $dt = $baseMonth->modify('-' . $i . ' months');
  $ym = $dt->format('Y-m');
  $prevYm = $dt->modify('-1 year')->format('Y-m');
  $cur = $salesMap[$ym] ?? ['qtd'=>0, 'total'=>0.0];
  $prev = $salesMap[$prevYm] ?? ['qtd'=>0, 'total'=>0.0];
  $totalSalesCurrent += (float)$cur['total'];
  $totalSalesPrevious += (float)$prev['total'];
  $maxSalesMonth = max($maxSalesMonth, (float)$cur['total'], (float)$prev['total']);
  $salesMonths[] = [
    'label' => $monthNamesPt[(int)$dt->format('n')] . '/' . $dt->format('y'),
    'current_label' => $dt->format('m/Y'),
    'previous_label' => $dt->modify('-1 year')->format('m/Y'),
    'current_total' => (float)$cur['total'],
    'previous_total' => (float)$prev['total'],
    'current_qtd' => (int)$cur['qtd'],
    'previous_qtd' => (int)$prev['qtd'],
  ];
}
if ($maxSalesMonth <= 0) $maxSalesMonth = 1.0;
$salesDelta = $totalSalesPrevious > 0 ? (($totalSalesCurrent - $totalSalesPrevious) / $totalSalesPrevious) * 100 : ($totalSalesCurrent > 0 ? 100 : 0);
$salesDeltaClass = $salesDelta > 0 ? 'pos' : ($salesDelta < 0 ? 'neg' : '');

finance_admin_start('Financeiro', 'financeiro');
finance_page_header('Financeiro', '', [
  ['href' => 'financeiro_contas_receber.php?new=1', 'label' => '+ Receber', 'class' => 'btn btn-primary'],
  ['href' => 'financeiro_contas_pagar.php?new=1', 'label' => '+ Pagar', 'class' => 'btn'],
  ['href' => 'financeiro_movimentos.php?new=1', 'label' => 'Movimento manual', 'class' => 'btn'],
]);
// V11.51.7: barra geral do Financeiro removida desta tela para ganhar altura útil.
finance_flash();
?>

<style>
  .finance-sales-chart{display:flex;align-items:flex-end;gap:8px;min-height:254px;overflow-x:auto;padding:8px 2px 2px}
  .finance-sales-month{flex:1 1 0;min-width:0;display:flex;flex-direction:column;align-items:center;gap:7px}
  .finance-sales-value{font-size:11px;font-weight:900;color:#0f172a;white-space:nowrap;text-align:center}
  .finance-sales-bars{height:158px;width:100%;display:flex;align-items:flex-end;justify-content:center;gap:4px;border-bottom:1px solid #e2e8f0;padding:0 3px}
  .finance-sales-bar{width:18px;min-height:6px;border-radius:10px 10px 5px 5px;display:block}
  .finance-sales-bar.current{background:linear-gradient(180deg,var(--admin-secondary),var(--admin-primary));box-shadow:0 10px 18px color-mix(in srgb, var(--admin-primary) 12%, transparent)}
  .finance-sales-bar.previous{background:#cbd5e1;box-shadow:0 8px 16px rgba(100,116,139,.13)}
  .finance-sales-label{font-size:11.5px;font-weight:900;color:#64748b;white-space:nowrap;text-align:center}
  .finance-sales-legend{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:10px;color:#64748b;font-size:12.5px;font-weight:800}
  .finance-sales-legend span{display:inline-flex;align-items:center;gap:6px}.finance-sales-legend i{width:14px;height:9px;border-radius:99px;display:inline-block}.finance-sales-legend .cur{background:linear-gradient(90deg,var(--admin-secondary),var(--admin-primary))}.finance-sales-legend .prev{background:#cbd5e1}
  .finance-sales-summary{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end}.finance-sales-summary .finance-badge{margin:0}
  @media(max-width:820px){
    .finance-card-head{align-items:flex-start;flex-direction:column}
    .finance-sales-summary{justify-content:flex-start;width:100%}
    .finance-sales-chart{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:3px;min-height:214px;overflow:visible;width:100%;padding:5px 0 0}
    .finance-sales-month{min-width:0;width:auto;flex:none;gap:5px}
    .finance-sales-value{display:none}
    .finance-sales-bars{height:154px;gap:2px;padding:0 1px}
    .finance-sales-bar{width:min(7px,42%)}
    .finance-sales-label{font-size:8.5px;letter-spacing:-.02em}
    .finance-dashboard-lists .finance-card-head{display:flex;align-items:stretch;gap:10px}
    .finance-dashboard-lists .finance-card-head .btn{width:100%}
  }
  @media(max-width:680px){
    .finance-dashboard-lists .finance-card{overflow:hidden}
    .finance-dashboard-lists .finance-list-table td{align-items:flex-start;white-space:normal;overflow-wrap:anywhere}
    .finance-dashboard-lists .finance-list-table td.finance-primary{padding-top:9px!important;padding-bottom:9px!important}
    .finance-dashboard-lists .finance-list-table td .btn{min-width:88px}
    .finance-sales-chart{gap:2px}
    .finance-sales-bars{height:145px}
    .finance-sales-bar{width:min(6px,44%)}
    .finance-sales-label{font-size:7.8px}
  }
</style>

<div class="finance-grid-4">
  <div class="finance-kpi"><small>Receber em aberto</small><strong class="pos">R$ <?= finance_money_br($receberAberto) ?></strong></div>
  <div class="finance-kpi"><small>Pagar em aberto</small><strong class="neg">R$ <?= finance_money_br($pagarAberto) ?></strong></div>
  <div class="finance-kpi"><small>Créditos do mês</small><strong class="pos">R$ <?= finance_money_br($creditosMes) ?></strong></div>
  <div class="finance-kpi"><small>Débitos do mês</small><strong class="neg">R$ <?= finance_money_br($debitosMes) ?></strong></div>
</div>
<div class="finance-grid" style="margin-top:14px">
  <div class="finance-kpi"><small>Resultado do mês</small><strong class="<?= $saldoMes >= 0 ? 'pos' : 'neg' ?>">R$ <?= finance_money_br($saldoMes) ?></strong></div>
  <div class="finance-kpi"><small>Período de apuração</small><strong><?= finance_date_br($monthStart) ?> a <?= finance_date_br($monthEnd) ?></strong></div>
</div>

<div class="finance-card" style="margin-top:14px">
  <div class="finance-card-head">
    <div>
      <h2 class="finance-subtitle">Vendas dos últimos 12 meses</h2>
      <div class="finance-muted">Mês atual + 11 anteriores, comparando cada mês com o mesmo mês do ano anterior.</div>
    </div>
    <div class="finance-sales-summary">
      <span class="finance-badge green">Atual: R$ <?= finance_money_br($totalSalesCurrent) ?></span>
      <span class="finance-badge gray">Ano anterior: R$ <?= finance_money_br($totalSalesPrevious) ?></span>
      <span class="finance-badge <?= $salesDeltaClass === 'neg' ? 'red' : 'green' ?>"><?= ($salesDelta >= 0 ? '+' : '') . number_format($salesDelta, 1, ',', '.') ?>%</span>
    </div>
  </div>
  <div class="finance-sales-chart" aria-label="Gráfico de vendas dos últimos 12 meses">
    <?php foreach ($salesMonths as $m):
      $hCur = max(6, (int)round(((float)$m['current_total'] / $maxSalesMonth) * 150));
      $hPrev = max(6, (int)round(((float)$m['previous_total'] / $maxSalesMonth) * 150));
    ?>
      <div class="finance-sales-month">
        <div class="finance-sales-value"><?= finance_h($moneyCompact((float)$m['current_total'])) ?></div>
        <div class="finance-sales-bars">
          <i class="finance-sales-bar previous" title="<?= finance_h($m['previous_label']) ?>: R$ <?= finance_money_br($m['previous_total']) ?> - <?= (int)$m['previous_qtd'] ?> pedido(s)" style="height:<?= $hPrev ?>px"></i>
          <i class="finance-sales-bar current" title="<?= finance_h($m['current_label']) ?>: R$ <?= finance_money_br($m['current_total']) ?> - <?= (int)$m['current_qtd'] ?> pedido(s)" style="height:<?= $hCur ?>px"></i>
        </div>
        <div class="finance-sales-label"><?= finance_h($m['label']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="finance-sales-legend"><span><i class="cur"></i>Vendas do período</span><span><i class="prev"></i>Mesmo mês do ano anterior</span></div>
</div>

<div class="finance-dashboard-lists" style="margin-top:14px">
  <div class="finance-card">
    <div class="finance-card-head"><h2 class="finance-subtitle">Recebimentos vencidos</h2><a class="btn" href="financeiro_contas_receber.php?status=ABERTO">Ver todos</a></div>
    <div class="table-wrap"><table class="finance-list-table">
      <thead><tr><th>Cliente</th><th>Vencimento</th><th>Aberto</th><th>Ação</th></tr></thead><tbody>
      <?php if (!$receberVencidos): ?><tr><td colspan="4" class="finance-empty">Nenhuma conta vencida.</td></tr><?php endif; ?>
      <?php foreach ($receberVencidos as $r): ?>
        <tr>
          <td data-label="Cliente" class="finance-primary"><strong><?= finance_h($r['person_name'] ?? '') ?></strong><br><span class="finance-muted"><?= finance_h($r['description'] ?? '') ?></span></td>
          <td data-label="Vencimento"><?= finance_date_br($r['due_date'] ?? '') ?></td>
          <td data-label="Aberto" class="finance-value pos">R$ <?= finance_money_br($r['open_amount'] ?? 0) ?></td>
          <td data-label="Ação"><a class="btn" href="financeiro_contas_receber.php?edit=<?= (int)$r['id'] ?>">Abrir</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <div class="finance-card">
    <div class="finance-card-head"><h2 class="finance-subtitle">Pagamentos vencidos</h2><a class="btn" href="financeiro_contas_pagar.php?status=ABERTO">Ver todos</a></div>
    <div class="table-wrap"><table class="finance-list-table">
      <thead><tr><th>Fornecedor</th><th>Vencimento</th><th>Aberto</th><th>Ação</th></tr></thead><tbody>
      <?php if (!$pagarVencidos): ?><tr><td colspan="4" class="finance-empty">Nenhuma conta vencida.</td></tr><?php endif; ?>
      <?php foreach ($pagarVencidos as $r): ?>
        <tr>
          <td data-label="Fornecedor" class="finance-primary"><strong><?= finance_h($r['person_name'] ?? '') ?></strong><br><span class="finance-muted"><?= finance_h($r['description'] ?? '') ?></span></td>
          <td data-label="Vencimento"><?= finance_date_br($r['due_date'] ?? '') ?></td>
          <td data-label="Aberto" class="finance-value neg">R$ <?= finance_money_br($r['open_amount'] ?? 0) ?></td>
          <td data-label="Ação"><a class="btn" href="financeiro_contas_pagar.php?edit=<?= (int)$r['id'] ?>">Abrir</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>


<?php finance_admin_end(); ?>

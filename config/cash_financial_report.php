<?php
declare(strict_types=1);
/**
 * Resumo financeiro compartilhado: Dashboard Vendas, Vendas > Caixa, impressao.
 * Somente leitura de gestao_cash_summary(), nenhum lancamento em banco.
 */
if (!function_exists('nexa_cash_financial_report_rows')) {
    function nexa_cash_financial_report_rows(array $rows): array {
        $result = [];
        foreach ($rows as $row) {
            $label = trim((string)($row['name'] ?? ''));
            if ($label === '') $label = 'Sem forma';
            $key = (function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label));
            if (!isset($result[$key])) $result[$key] = ['name'=>$label, 'total'=>0.0];
            $result[$key]['total'] += (float)($row['total'] ?? 0);
        }
        uasort($result, static function(array $a, array $b): int { return strnatcasecmp($a['name'], $b['name']); });
        return array_values($result);
    }
}
if (!function_exists('nexa_cash_financial_report_totals')) {
    function nexa_cash_financial_report_totals(array $summary): array {
        // Vendas no C/R ainda nao baixadas sao excluidas: somente formas que
        // geraram finance_movement_id e marcadas como movimenta caixa.
        $sales = nexa_cash_financial_report_rows((array)($summary['cash_sales_payments'] ?? []));
        $receipts = nexa_cash_financial_report_rows((array)($summary['cr_payments'] ?? []));
        $saleSum = $receivableSum = $cashSale = $cashReceivable = 0.0;
        foreach ($sales as $row) {
            $saleSum += $row['total'];
            if (stripos($row['name'], 'dinheiro') !== false) $cashSale += $row['total'];
        }
        foreach ($receipts as $row) {
            $receivableSum += $row['total'];
            if (stripos($row['name'], 'dinheiro') !== false) $cashReceivable += $row['total'];
        }
        return [
            'sales'=>$sales, 'receipts'=>$receipts,
            'sale_sum'=>$saleSum, 'receivable_sum'=>$receivableSum,
            'cash_sale'=>$cashSale, 'cash_receivable'=>$cashReceivable,
            'cash_received'=>$cashSale+$cashReceivable,
            'origins'=>nexa_cash_financial_report_rows((array)($summary['sales_by_origin'] ?? [])),
        ];
    }
}
if (!function_exists('nexa_cash_financial_report_css')) {
    function nexa_cash_financial_report_css(): string {
        return '<style>
.nexa-financial-receipt{border:1px solid #cbd5e1;border-radius:16px;padding:16px;background:#fff;color:#0f172a;font:600 13px/1.45 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.nexa-financial-receipt *{box-sizing:border-box}
.nexa-financial-receipt .nr-title{text-align:center;font-weight:950;font-size:17px;padding:0 0 8px;margin-bottom:8px;border-bottom:1px dashed #94a3b8;letter-spacing:.05em}
.nexa-financial-receipt .nr-item{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px dashed #e2e8f0}
.nexa-financial-receipt .nr-item span{min-width:0;overflow-wrap:anywhere}
.nexa-financial-receipt .nr-item strong{white-space:nowrap;text-align:right;font-variant-numeric:tabular-nums}
.nexa-financial-receipt .nr-box{border:1px solid #dbe4f0;border-radius:12px;overflow:hidden;margin-top:11px}
.nexa-financial-receipt .nr-box h3{font:900 13px/1.4 ui-monospace,SFMono-Regular,Consolas,monospace;text-align:center;background:#f1f5f9;color:#0f255f;margin:0;padding:7px;border-bottom:1px solid #dbe4f0}
.nexa-financial-receipt .nr-box .nr-item{padding:6px 9px}
.nexa-financial-receipt .nr-total{background:#f8fafc;font-weight:950}
.nexa-financial-receipt .nr-final{background:#eaf2ff;color:#0f255f;font-size:15px;font-weight:950;border-top:1px solid #bfdbfe;padding:10px}
.nexa-financial-receipt .nr-note{font:500 11px/1.4 Arial,sans-serif;color:#64748b;margin-top:9px}
@media print{.nexa-financial-receipt{border:0;border-radius:0;box-shadow:none;padding:0;font:600 9pt/1.2 monospace}.nexa-financial-receipt .nr-title{font-size:11pt}.nexa-financial-receipt .nr-item{padding:2px 0}.nexa-financial-receipt .nr-box{border:1px solid #555;border-radius:0;margin-top:3mm}.nexa-financial-receipt .nr-box h3{padding:3px;font-size:9pt;background:#eee!important;color:#000}.nexa-financial-receipt .nr-box .nr-item{padding:2px 3px}.nexa-financial-receipt .nr-final{padding:4px;font-size:10pt;background:#eee!important;color:#000}.nexa-financial-receipt .nr-note{font-size:7pt}}
</style>';
    }
}
if (!function_exists('nexa_cash_financial_report_html')) {
    function nexa_cash_financial_report_html(array $summary, array $session, string $operator, string $bank = ''): string {
        $numbers = nexa_cash_financial_report_totals($summary);
        $esc = static function($v): string {return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');};
        $money = static function($v): string {return function_exists('gestao_money') ? gestao_money($v) : 'R$ '.number_format((float)$v,2,',','.');};
        $date = static function($v): string {return $v ? date('d/m/Y H:i', strtotime((string)$v)) : '-';};
        $openedAt = $date($session['opened_at'] ?? '');
        $closedAt = $date($session['closed_at'] ?? '');
        $opened = strtoupper((string)($session['status'] ?? 'ABERTO')) === 'ABERTO';
        $opening = (float)($summary['opening'] ?? 0);
        $supp = (float)($summary['suprimento'] ?? 0);
        $sangria = (float)($summary['sangria'] ?? 0);
        $estorno = (float)($summary['estorno'] ?? 0);
        $crEstorno = (float)($summary['cr_reversals'] ?? 0);
        $crReceived = (float)($summary['cr_receipts'] ?? 0);
        $salesReceived = (float)($summary['sales'] ?? 0);
        $expected = (float)($summary['expected'] ?? 0);
        $saleClassificationDifference = round($salesReceived - $numbers['sale_sum'], 2);
        $crClassificationDifference = round(($crReceived-$crEstorno) - $numbers['receivable_sum'], 2);
        ob_start(); ?>
<div class="nexa-financial-receipt">
  <div class="nr-title">RESUMO FINANCEIRO</div>
  <div class="nr-item"><span>CAIXA / SITUAÇÃO</span><strong><?= $esc('#'.($session['id'] ?? '-').' / '.($opened?'ABERTO':'FECHADO')) ?></strong></div>
  <div class="nr-item"><span>DATA ABERTURA</span><strong><?= $esc($openedAt) ?></strong></div>
  <div class="nr-item"><span>OPERADOR</span><strong><?= $esc($operator) ?></strong></div>
  <?php if ($bank !== ''): ?><div class="nr-item"><span>CAIXA / BANCO</span><strong><?= $esc($bank) ?></strong></div><?php endif; ?>
  <?php if (!$opened): ?><div class="nr-item"><span>DATA FECHAMENTO</span><strong><?= $esc($closedAt) ?></strong></div><?php endif; ?>
  <div class="nr-item"><span>VALOR ABERTURA</span><strong><?= $money($opening) ?></strong></div>
  <div class="nr-item"><span>APORTES / SUPRIMENTOS</span><strong><?= $money($supp) ?></strong></div>
  <div class="nr-item"><span>SANGRIAS / RETIRADAS</span><strong>- <?= $money($sangria) ?></strong></div>
  <div class="nr-box">
    <h3>RESUMO VENDAS / PEDIDOS</h3>
    <?php foreach ($numbers['sales'] as $payment): ?>
      <div class="nr-item"><span>VENDAS · <?= $esc($payment['name']) ?></span><strong><?= $money($payment['total']) ?></strong></div>
    <?php endforeach; ?>
    <?php if (!$numbers['sales']): ?><div class="nr-item"><span>Nenhuma venda recebida</span><strong><?= $money(0) ?></strong></div><?php endif; ?>
    <?php if (abs($saleClassificationDifference) > 0.009): ?><div class="nr-item"><span>VENDAS SEM CLASSIFICAÇÃO CONCILIADA*</span><strong><?= $money($saleClassificationDifference) ?></strong></div><?php endif; ?>
    <div class="nr-item nr-total"><span>TOTAL VENDAS RECEBIDAS</span><strong><?= $money($salesReceived) ?></strong></div>
    <?php if ($numbers['origins']): ?>
      <?php foreach ($numbers['origins'] as $origin): ?><div class="nr-item"><span>Origem · <?= $esc($origin['name']) ?></span><strong><?= $money($origin['total']) ?></strong></div><?php endforeach; ?>
    <?php endif; ?>
    <div class="nr-item"><span>ESTORNOS VENDAS</span><strong>- <?= $money($estorno) ?></strong></div>
  </div>
  <div class="nr-box">
    <h3>RECEBIMENTOS CONTAS A RECEBER</h3>
    <?php foreach ($numbers['receipts'] as $payment): ?>
      <div class="nr-item"><span>RECEBIMENTO · <?= $esc($payment['name']) ?></span><strong><?= $money($payment['total']) ?></strong></div>
    <?php endforeach; ?>
    <?php if (!$numbers['receipts']): ?><div class="nr-item"><span>Nenhum título recebido no caixa</span><strong><?= $money(0) ?></strong></div><?php endif; ?>
    <?php if (abs($crClassificationDifference) > 0.009): ?><div class="nr-item"><span>RECEBIMENTOS SEM CLASSIFICAÇÃO CONCILIADA*</span><strong><?= $money($crClassificationDifference) ?></strong></div><?php endif; ?>
    <div class="nr-item nr-total"><span>TOTAL RECEBIMENTOS C/R LÍQUIDO</span><strong><?= $money($crReceived-$crEstorno) ?></strong></div>
    <?php if ($crEstorno > 0): ?><div class="nr-item"><span>ESTORNOS C/R</span><strong>- <?= $money($crEstorno) ?></strong></div><?php endif; ?>
  </div>
  <div class="nr-item"><span>TOTAL VENDAS + C/R LÍQUIDO</span><strong><?= $money($salesReceived-$estorno+$crReceived-$crEstorno) ?></strong></div>
  <div class="nr-item nr-final"><span>SALDO GERAL PREVISTO</span><strong><?= $money($expected) ?></strong></div>
  <div class="nr-item"><span>DINHEIRO RECEBIDO (ANTES DE ESTORNOS DE VENDAS)</span><strong><?= $money($numbers['cash_received']) ?></strong></div>
  <?php if (!$opened): ?>
    <div class="nr-item"><span>VALOR INFORMADO FECHAMENTO</span><strong><?= $money($session['closing_amount'] ?? 0) ?></strong></div>
    <div class="nr-item"><span>DIFERENÇA APURADA</span><strong><?= $money($session['difference_amount'] ?? 0) ?></strong></div>
  <?php endif; ?>
  <div class="nr-note">Somente valores efetivamente lançados neste caixa. Orçamentos não convertidos, O.S. não faturadas e títulos em aberto não geram entrada. Estornos de venda são apresentados separadamente porque a forma estornada pode não estar identificada. <?php if (abs($saleClassificationDifference) > 0.009 || abs($crClassificationDifference) > 0.009): ?>* Divergência entre os movimentos de caixa e o detalhamento por formas; confira vendas legadas, configurações alteradas ou movimentos de estorno. O saldo geral usa os lançamentos efetivos.<?php endif; ?></div>
</div>
<?php return (string)ob_get_clean();
    }
}

<?php
declare(strict_types=1);
// Teste isolado, sem acesso ao MySQL, sem movimentacao financeira.
require __DIR__ . '/../config/cash_financial_report.php';
function gestao_money($v): string { return 'R$ '.number_format((float)$v, 2, ',', '.'); }
$summary = [
  'opening'=>50, 'suprimento'=>10, 'sangria'=>5,
  'sales'=>100, 'estorno'=>0, 'cr_receipts'=>20, 'cr_reversals'=>0, 'expected'=>175,
  'cash_sales_payments'=>[['name'=>'Dinheiro','total'=>100]],
  'cr_payments'=>[['name'=>'Dinheiro','total'=>20]],
  'sales_by_origin'=>[['name'=>'Pedidos e vendas','total'=>100]],
];
$session=['id'=>12,'status'=>'ABERTO','opened_at'=>'2026-10-27 09:00:00'];
$totals=nexa_cash_financial_report_totals($summary);
if (abs($totals['cash_received']-120)>0.001 || abs($totals['sale_sum']-100)>0.001 || abs($totals['receivable_sum']-20)>0.001) {
    throw new RuntimeException('Separacao Vendas/C-R inconsistente.');
}
$html=nexa_cash_financial_report_html($summary,$session,'Operador Teste','Caixa 01');
foreach (['VENDAS · Dinheiro','RECEBIMENTO · Dinheiro','R$ 100,00','R$ 20,00','R$ 175,00','Operador Teste','SANGRIAS / RETIRADAS','APORTES / SUPRIMENTOS'] as $needle) {
    if (strpos($html,$needle)===false) throw new RuntimeException('Faltando no resumo: '.$needle);
}
$summary['cr_receipts']=30;
$summary['expected']=185;
$html=nexa_cash_financial_report_html($summary,$session,'Operador','');
if (strpos($html, 'RECEBIMENTOS SEM CLASSIFICAÇÃO CONCILIADA*') === false) {
    throw new RuntimeException('Divergencia C/R nao sinalizada.');
}
$summary['cr_receipts']=20; $summary['expected']=175; $summary['estorno']=30;
$summary['expected']=145;
$html=nexa_cash_financial_report_html($summary,$session,'Operador','');
if (strpos($html,'ESTORNOS VENDAS')===false || strpos($html,'R$ 145,00')===false) throw new RuntimeException('Estorno nao exibido.');
$session['status']='FECHADO'; $session['closed_at']='2026-10-27 19:00:00'; $session['closing_amount']=145; $session['difference_amount']=0;
$html=nexa_cash_financial_report_html($summary,$session,'Operador','');
if (strpos($html,'DATA FECHAMENTO')===false || strpos($html,'DIFERENÇA APURADA')===false) throw new RuntimeException('Fechamento nao exibido.');
echo "OK: resumo financeiro, separacao vendas/C-R, conciliacao e fechamento.\n";

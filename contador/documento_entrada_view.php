<?php
declare(strict_types=1);

$xml = fiscal_xml_get($pdo, $companyId, $documentId);
if (!$xml) {
  http_response_code(404);
  exit('NF-e de entrada não encontrada.');
}
$items = fiscal_report_items($pdo, $companyId, $documentId);
$xmlTotals = function_exists('fiscal_report_entry_xml_totals') ? fiscal_report_entry_xml_totals($xml) : [];
$xmlItemStMap = function_exists('fiscal_report_entry_xml_item_st_map') ? fiscal_report_entry_xml_item_st_map($xml) : [];
$e = static fn($value): string => accountant_e($value);
$money = static fn($value, int $decimals=2): string => 'R$ ' . number_format((float)$value, $decimals, ',', '.');
$qty = static fn($value): string => rtrim(rtrim(number_format((float)$value, 4, ',', '.'), '0'), ',');
$date = static function($value): string {
  $value=trim((string)$value); if ($value==='') return '—';
  $ts=strtotime($value); return $ts?date('d/m/Y',$ts):$value;
};
$docFmt = static function($value): string {
  $digits=preg_replace('/\D+/','',(string)$value) ?: '';
  if (strlen($digits)===14) return substr($digits,0,2).'.'.substr($digits,2,3).'.'.substr($digits,5,3).'/'.substr($digits,8,4).'-'.substr($digits,12,2);
  if (strlen($digits)===11) return substr($digits,0,3).'.'.substr($digits,3,3).'.'.substr($digits,6,3).'-'.substr($digits,9,2);
  return (string)$value;
};
$isReturn=false;
try {
  $st=$pdo->prepare("SELECT CASE WHEN " . accounting_xml_devolution_sql('x','op') . " THEN 1 ELSE 0 END FROM fiscal_xml_imports x LEFT JOIN fiscal_operations op ON op.company_id=x.company_id AND op.id=x.operation_id WHERE x.company_id=? AND x.id=?");
  $st->execute([$companyId,$documentId]); $isReturn=(bool)$st->fetchColumn();
} catch (Throwable $e1) {}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>NF-e de entrada <?= $e((string)($xml['number'] ?? '')) ?> - Central do Contador</title>
<style>
:root{--p:#0b1f5b;--s:#2454d6;--line:#dbe4f0;--muted:#64748b;--bg:#f4f7fb}*{box-sizing:border-box}body{margin:0;font-family:"Segoe UI",system-ui,Arial,sans-serif;background:var(--bg);color:#0f172a}.top{background:linear-gradient(135deg,var(--p),var(--s));color:#fff;padding:14px 22px}.topin{max-width:1400px;margin:auto;display:flex;justify-content:space-between;align-items:center}.top a{color:#fff;text-decoration:none;font-weight:800}.page{max-width:1400px;margin:auto;padding:22px}.head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:16px}.head h1{margin:0;color:var(--p);font-size:29px}.head p{margin:6px 0 0;color:var(--muted)}.btn{display:inline-flex;align-items:center;min-height:42px;padding:0 14px;border:1px solid var(--line);border-radius:12px;background:#fff;color:var(--p);text-decoration:none;font-weight:850}.card{background:#fff;border:1px solid var(--line);border-radius:20px;padding:18px;margin-bottom:14px;box-shadow:0 10px 30px rgba(15,23,42,.04)}.card h2{margin:0 0 14px;color:var(--p);font-size:20px}.kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:14px}.kpi{background:#fff;border:1px solid var(--line);border-radius:17px;padding:14px}.kpi span{display:block;color:var(--muted);font-size:11px;font-weight:850;text-transform:uppercase}.kpi strong{display:block;margin-top:5px;color:var(--p);font-size:18px}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.field{border:1px solid #e5eaf2;border-radius:14px;padding:11px;background:#fbfdff;min-width:0}.field span{display:block;color:var(--muted);font-size:11px;font-weight:800;margin-bottom:4px}.field strong{overflow-wrap:anywhere}.table-wrap{overflow:auto;border:1px solid var(--line);border-radius:16px}.table{width:100%;border-collapse:collapse;min-width:1150px}.table th,.table td{padding:10px 11px;border-bottom:1px solid #e5eaf2;text-align:left;vertical-align:top;font-size:13px}.table th{background:#f8fafc;color:#475569;font-size:11px;text-transform:uppercase}.right{text-align:right!important;white-space:nowrap}.key{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;overflow-wrap:anywhere}.badge{display:inline-flex;padding:6px 9px;border-radius:999px;background:#dbeafe;color:#1d4ed8;font-size:11px;font-weight:900}.badge.return{background:#fef3c7;color:#92400e}.reconcile{margin-top:12px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:14px;padding:12px;color:#1e3a8a;font-size:13px}.reconcile strong{color:#0b1f5b}.reconcile.warn{border-color:#fed7aa;background:#fff7ed;color:#9a3412}@media(max-width:900px){.kpis,.grid{grid-template-columns:1fr 1fr}.head{display:block}.head .btn{margin-top:12px}}@media(max-width:560px){.kpis,.grid{grid-template-columns:1fr}.page{padding:14px}}
</style>
</head>
<body>
<header class="top"><div class="topin"><strong>Central do Contador Próspero</strong><a href="index.php?company=<?= (int)$companyId ?>&view=documents">Voltar aos documentos</a></div></header>
<main class="page">
  <div class="head"><div><h1>NF-e de entrada nº <?= $e((string)($xml['number'] ?? '')) ?></h1><p>Série <?= $e((string)($xml['series'] ?? '')) ?> · <?= $isReturn?'Devolução de entrada':'Entrada recebida por XML' ?></p></div><a class="btn" href="index.php?company=<?= (int)$companyId ?>&view=documents">Voltar</a></div>
  <div class="kpis">
    <div class="kpi"><span>Data de emissão</span><strong><?= $e($date($xml['issue_date'] ?? '')) ?></strong></div>
    <div class="kpi"><span>Data de entrada</span><strong><?= $e($date($xml['entry_date'] ?? '')) ?></strong></div>
    <div class="kpi"><span>Situação</span><strong><?= $e((string)($xml['status'] ?? 'IMPORTADO')) ?></strong></div>
    <div class="kpi"><span>Itens</span><strong><?= count($items) ?></strong></div>
    <div class="kpi"><span>Total da NF-e</span><strong><?= $money($xml['total_invoice'] ?? 0) ?></strong></div>
  </div>

  <section class="card"><h2>Identificação do documento</h2><div class="grid">
    <div class="field"><span>Movimento</span><strong><span class="badge <?= $isReturn?'return':'' ?>"><?= $isReturn?'Devolução de entrada':'Entrada' ?></span></strong></div>
    <div class="field"><span>Modelo / série / número</span><strong><?= $e((string)($xml['model'] ?? '55')) ?> / <?= $e((string)($xml['series'] ?? '')) ?> / <?= $e((string)($xml['number'] ?? '')) ?></strong></div>
    <div class="field"><span>Operação vinculada</span><strong><?= $e((string)($xml['operation_name'] ?? 'Entrada por XML')) ?></strong></div>
    <div class="field"><span>Arquivo XML</span><strong><?= $e((string)($xml['xml_filename'] ?? '')) ?></strong></div>
    <div class="field" style="grid-column:1/-1"><span>Chave de acesso</span><strong class="key"><?= $e((string)($xml['access_key'] ?? '')) ?></strong></div>
  </div></section>

  <section class="card"><h2>Fornecedor e destinatário</h2><div class="grid">
    <div class="field"><span>Fornecedor</span><strong><?= $e((string)($xml['supplier_name'] ?? '')) ?></strong></div>
    <div class="field"><span>CPF/CNPJ</span><strong><?= $e($docFmt($xml['supplier_doc'] ?? '')) ?></strong></div>
    <div class="field"><span>Inscrição estadual</span><strong><?= $e((string)($xml['supplier_ie'] ?? '')) ?></strong></div>
    <div class="field"><span>UF / Município</span><strong><?= $e((string)($xml['supplier_uf'] ?? '')) ?> · <?= $e((string)($xml['supplier_city'] ?? '')) ?></strong></div>
    <div class="field"><span>Destinatário</span><strong><?= $e((string)($xml['buyer_name'] ?? ($company['name'] ?? ''))) ?></strong></div>
    <div class="field"><span>CPF/CNPJ destinatário</span><strong><?= $e($docFmt($xml['buyer_doc'] ?? '')) ?></strong></div>
  </div></section>

  <section class="card"><h2>Itens da NF-e</h2><div class="table-wrap"><table class="table" style="min-width:1750px"><thead><tr><th>Item</th><th>Código</th><th>Produto</th><th>NCM</th><th>CFOP XML</th><th>CFOP entrada</th><th>CST/CSOSN</th><th>Un.</th><th class="right">Quantidade</th><th class="right">Valor unit.</th><th class="right">Produtos</th><th class="right">Frete</th><th class="right">Seguro</th><th class="right">Desconto</th><th class="right">Outros</th><th class="right">IPI</th><th class="right">ICMS-ST</th><th class="right">FCP-ST</th><th class="right">Total item</th></tr></thead><tbody>
  <?php if(!$items): ?><tr><td colspan="19">Nenhum item foi encontrado para este XML.</td></tr><?php endif; ?>
  <?php foreach($items as $item):
    $tax=fiscal_xml_item_tax_payload($pdo,$companyId,$item);
    $itemNo=(int)($item['item_number']??0);
    $stPayload=$xmlItemStMap[$itemNo] ?? [];
    $fcpSt=function_exists('fiscal_report_item_fcp_st_value') ? fiscal_report_item_fcp_st_value($item) : 0.0;
    if($fcpSt<=0) $fcpSt=(float)($stPayload['fcp_st_value']??0);
    $productValue=function_exists('fiscal_report_item_product_value') ? fiscal_report_item_product_value($item) : (float)($item['total_value']??0);
    $freight=(float)($item['freight_value']??0); $insurance=(float)($item['insurance_value']??0); $discount=(float)($item['discount_value']??0); $other=(float)($item['other_value']??0); $ipi=(float)($item['ipi_value']??0); $icmsSt=(float)($item['icms_st_value']??0);
    $line=round($productValue+$freight+$insurance+$other-$discount+$ipi+$icmsSt+$fcpSt,2);
    $cst=(string)($tax['entry_icms_cst']??''); if($cst==='')$cst=(string)($tax['entry_icms_csosn']??''); if($cst==='')$cst=(string)($item['icms_cst']??$item['icms_csosn']??'');
    $stockQty=(float)($item['stock_quantity']??0); if($stockQty<=0)$stockQty=(float)($item['quantity']??0);
  ?>
    <tr><td><?= $itemNo ?></td><td><?= $e((string)($item['linked_product_code'] ?? $item['product_code'] ?? '')) ?></td><td><strong><?= $e((string)($item['linked_product_name'] ?? $item['name'] ?? '')) ?></strong></td><td><?= $e((string)($item['linked_product_ncm'] ?? $item['ncm'] ?? '')) ?></td><td><?= $e((string)($item['cfop']??'')) ?></td><td><strong><?= $e((string)($tax['entry_cfop']??'')) ?></strong></td><td><?= $e($cst) ?></td><td><?= $e((string)($item['linked_product_unit'] ?? $item['unit'] ?? 'UN')) ?></td><td class="right"><?= $qty($stockQty) ?></td><td class="right"><?= $money($item['unit_value']??0,4) ?></td><td class="right"><?= $money($productValue) ?></td><td class="right"><?= $money($freight) ?></td><td class="right"><?= $money($insurance) ?></td><td class="right"><?= $money($discount) ?></td><td class="right"><?= $money($other) ?></td><td class="right"><?= $money($ipi) ?></td><td class="right"><?= $money($icmsSt) ?></td><td class="right"><strong><?= $money($fcpSt) ?></strong></td><td class="right"><strong><?= $money($line) ?></strong></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></section>

  <?php
    $tProducts=(float)($xmlTotals['product']??($xml['total_products']??0));
    $tFreight=(float)($xmlTotals['freight']??($xml['freight_value']??0));
    $tInsurance=(float)($xmlTotals['insurance']??0);
    $tDiscount=(float)($xmlTotals['discount']??($xml['discount_value']??0));
    $tOther=(float)($xmlTotals['other']??($xml['other_value']??0));
    $tIpi=(float)($xmlTotals['ipi']??0);
    $tIcmsSt=(float)($xmlTotals['icms_st_value']??0);
    $tFcpSt=(float)($xmlTotals['fcp_st_value']??0);
    $tInvoice=(float)($xmlTotals['invoice']??($xml['total_invoice']??0));
    $knownComposition=round($tProducts+$tFreight+$tInsurance+$tOther+$tIpi+$tIcmsSt+$tFcpSt-$tDiscount,2);
    $otherFiscal=round($tInvoice-$knownComposition,2);
  ?>
  <section class="card"><h2>Totais do XML</h2><div class="grid">
    <div class="field"><span>Produtos</span><strong><?= $money($tProducts) ?></strong></div>
    <div class="field"><span>Frete</span><strong><?= $money($tFreight) ?></strong></div>
    <div class="field"><span>Seguro</span><strong><?= $money($tInsurance) ?></strong></div>
    <div class="field"><span>Desconto</span><strong><?= $money($tDiscount) ?></strong></div>
    <div class="field"><span>Outros valores</span><strong><?= $money($tOther) ?></strong></div>
    <div class="field"><span>IPI</span><strong><?= $money($tIpi) ?></strong></div>
    <div class="field"><span>ICMS-ST</span><strong><?= $money($tIcmsSt) ?></strong></div>
    <div class="field"><span>FCP-ST</span><strong><?= $money($tFcpSt) ?></strong></div>
    <?php if(abs($otherFiscal)>=0.01): ?><div class="field"><span>Outros componentes fiscais</span><strong><?= $money($otherFiscal) ?></strong></div><?php endif; ?>
    <div class="field"><span>Total da nota</span><strong><?= $money($tInvoice) ?></strong></div>
  </div>
  <div class="reconcile <?= abs($otherFiscal)>=0.01?'warn':'' ?>"><strong>Composição conferida:</strong> Produtos + frete + seguro + outros + IPI + ICMS-ST + FCP-ST − desconto<?= abs($otherFiscal)>=0.01?' + outros componentes fiscais':'' ?> = <strong><?= $money($tInvoice) ?></strong>.</div>
  </section>
</main>
</body>
</html>

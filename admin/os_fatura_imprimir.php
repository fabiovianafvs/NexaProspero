<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/os_module.php';
require_once __DIR__ . '/../config/os_batch_billing.php';
require_once __DIR__ . '/_print_brand.php';

$pdo=os_db();
$companyId=os_company_id();
os_require_enabled($pdo,$companyId);
os_batch_require_schema($pdo);

$id=max(0,(int)($_GET['id']??0));
if($id<=0)die('Fatura inválida.');

$invoice=os_batch_invoice($pdo,$companyId,$id);
if(!$invoice){http_response_code(404);die('Fatura não encontrada.');}
$orders=os_batch_invoice_orders($pdo,$companyId,$id);
$lines=os_batch_invoice_lines($pdo,$companyId,$id);

$st=$pdo->prepare('SELECT name,cnpj,cpf,endereco,numero,bairro,cidade,estado,email,whatsapp FROM companies WHERE id=? LIMIT 1');
$st->execute([$companyId]);
$company=$st->fetch(PDO::FETCH_ASSOC)?:[];
$printLogoUrl=nexa_print_logo_url($companyId);

function osbfp_date($v):string{$t=$v?strtotime((string)$v):false;return$t?date('d/m/Y',$t):'—';}
function osbfp_no($v):string{return str_pad((string)(int)$v,6,'0',STR_PAD_LEFT);}
function osbfp_doc(array $c):string{return trim((string)($c['cnpj']??''))!==''?(string)$c['cnpj']:(string)($c['cpf']??'');}
function osbfp_item_type($v):string{$v=strtoupper(trim((string)$v));return $v==='SERVICO'||$v==='SERVIÇO'?'Serviço':($v==='PRODUTO'?'Produto':ucfirst(strtolower((string)$v)));}

function osbfp_order_plates(PDO $pdo,int $companyId,array $orders):array
{
    $orderIds=array_values(array_unique(array_filter(array_map(static fn($o)=>(int)($o['os_order_id']??0),$orders),static fn($v)=>$v>0)));
    if(!$orderIds)return[];
    try{
        $hasSemantic=function_exists('os_schema_has_column')?os_schema_has_column($pdo,'os_custom_fields','semantic_role'):true;
        $ph=implode(',',array_fill(0,count($orderIds),'?'));
        $roleSql=$hasSemantic?',cf.semantic_role':' ,NULL AS semantic_role';
        $sql="SELECT ofv.order_id,ofv.value_text,cf.field_key,cf.label{$roleSql},cf.active,cf.sort_order,cf.id field_id
              FROM os_order_field_values ofv
              INNER JOIN os_custom_fields cf ON cf.company_id=ofv.company_id AND cf.id=ofv.field_id
              WHERE ofv.company_id=? AND ofv.order_id IN ($ph) AND TRIM(COALESCE(ofv.value_text,''))<>''
              ORDER BY ofv.order_id,cf.active DESC,cf.sort_order,cf.id";
        $st=$pdo->prepare($sql);$st->execute(array_merge([$companyId],$orderIds));
        $result=[];
        foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){
            $oid=(int)$r['order_id'];if(isset($result[$oid]))continue;
            $role=strtolower(trim((string)($r['semantic_role']??'')));
            $key=strtolower(trim((string)($r['field_key']??'')));
            $label=strtolower(trim((string)($r['label']??'')));
            $isPlate=$role==='vehicle_plate'||in_array($key,['placa','vehicle_plate','veiculo_placa'],true)||$label==='placa'||str_contains($label,'placa do ve');
            if(!$isPlate)continue;
            $plate=trim((string)$r['value_text']);
            if(function_exists('os_vehicle_normalize_plate'))$plate=(string)os_vehicle_normalize_plate($plate);
            if($plate!=='')$result[$oid]=$plate;
        }
        return$result;
    }catch(Throwable $e){return[];}
}

$plates=osbfp_order_plates($pdo,$companyId,$orders);
$linesByOs=[];
foreach($lines as $line){$key=(int)($line['os_number_snapshot']??0);$linesByOs[$key][]=$line;}
?><!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Fatura de O.S. #<?=osbfp_no($invoice['invoice_number'])?></title>
<style>
@page{size:A4 portrait;margin:7mm}
*{box-sizing:border-box}
body{margin:0;background:#eef3f8;color:#172033;font-family:Arial,Helvetica,sans-serif;font-size:10.5px;line-height:1.25}
.toolbar{background:#fff;border-bottom:1px solid #d8e1ec;padding:10px;display:flex;gap:8px;justify-content:center;position:sticky;top:0;z-index:5}
.toolbar a,.toolbar button{border:1px solid #b8c6d8;border-radius:8px;padding:8px 12px;background:#fff;color:#173b78;text-decoration:none;cursor:pointer;font-weight:700}
.toolbar button{background:#1557d5;border-color:#1557d5;color:#fff}
.sheet{width:min(210mm,calc(100% - 20px));background:#fff;margin:12px auto;padding:8mm;box-shadow:0 4px 18px rgba(15,23,42,.12)}
.header{display:flex;justify-content:space-between;gap:14px;border-bottom:2px solid #1557d5;padding-bottom:6px}.company-head{display:flex;align-items:flex-start;gap:8px;min-width:0}.company-logo{width:54px;height:42px;object-fit:contain;flex:0 0 54px}.company-data{min-width:0}
.header h1{margin:0;color:#0b1f5b;font-size:18px}.right{text-align:right}.right .number{font-size:18px;font-weight:800;color:#0b1f5b}.muted{color:#64748b}.status{display:inline-block;border:1px solid #cbd5e1;border-radius:999px;padding:2px 6px;font-size:9px;font-weight:800}
.notice{border:1px solid #bfd2ff;background:#f5f8ff;border-radius:6px;padding:5px 7px;color:#244a86;font-size:9px;margin-top:6px}
.section{margin-top:8px}.section-title{font-size:10px;font-weight:800;text-transform:uppercase;color:#0b1f5b;border-bottom:1px solid #aebdce;padding-bottom:3px;margin-bottom:5px;letter-spacing:.03em}
.grid{display:grid;grid-template-columns:1.4fr .8fr .8fr .5fr;gap:4px 12px}.field b{display:block;font-size:8.5px;color:#526173;margin-bottom:1px}
.os-block{margin-top:7px;border:1px solid #aebdce;border-radius:6px;overflow:hidden;background:#fff;break-inside:auto;page-break-inside:auto}
.os-main{display:grid;grid-template-columns:.7fr .75fr .8fr 1fr 2.2fr .85fr .85fr .9fr;gap:0;background:#eef4fb;border-bottom:1px solid #aebdce;break-after:avoid;page-break-after:avoid}
.os-main>div{padding:4px 5px;border-right:1px solid #d2dce8;min-width:0}.os-main>div:last-child{border-right:0}.os-main b{display:block;font-size:7.5px;text-transform:uppercase;color:#526173;margin-bottom:1px}.os-main .os-number{font-weight:900;color:#0b1f5b;font-size:11px}.os-main .desc{overflow-wrap:anywhere}.num{text-align:right!important;white-space:nowrap}
.os-items{width:100%;border-collapse:collapse;font-size:9px}.os-items th,.os-items td{padding:3px 4px;border-bottom:1px solid #dde4ed;text-align:left;vertical-align:top}.os-items tr:last-child td{border-bottom:0}.os-items th{background:#f8fafc;color:#526173;font-size:7.5px;text-transform:uppercase;white-space:nowrap}.os-items tr{break-inside:avoid;page-break-inside:avoid}.item-note{font-size:8px;color:#64748b;margin-top:1px}.empty-items{padding:5px;color:#64748b;font-style:italic}
.totals{margin-left:auto;width:min(315px,100%);margin-top:7px;font-size:9.5px}.totals div{display:flex;justify-content:space-between;padding:2px 0}.totals .grand{border-top:2px solid #1557d5;font-size:13px;font-weight:800;color:#0b1f5b;padding-top:4px;margin-top:2px}
.sign{display:grid;grid-template-columns:1fr 1fr;gap:42px;margin-top:28px}.sign div{border-top:1px solid #475569;text-align:center;padding-top:3px;font-size:9px}.footer-note{font-size:8.5px;color:#64748b;margin-top:7px;line-height:1.3}
@media print{body{background:#fff}.toolbar{display:none}.sheet{width:100%;margin:0;padding:0;box-shadow:none;border:0}.header{break-inside:avoid}.notice,.section-title{break-inside:avoid}}
@media(max-width:760px){.sheet{width:100%;margin:0;padding:12px}.header{flex-direction:column}.right{text-align:left}.grid{grid-template-columns:1fr 1fr}.os-main{grid-template-columns:1fr 1fr}.os-main>div{border-bottom:1px solid #d2dce8}.os-items{font-size:8.5px}.sign{grid-template-columns:1fr;gap:30px}}
</style>
</head>
<body>
<div class="toolbar"><button type="button" onclick="window.print()">Imprimir</button><a href="os_faturas.php?id=<?=$id?>">Voltar para a Fatura</a></div>
<main class="sheet">
<div class="header">
  <div class="company-head"><?php if($printLogoUrl!==''): ?><img class="company-logo" src="<?=os_e($printLogoUrl)?>" alt="Logo da empresa"><?php endif; ?><div class="company-data"><h1><?=os_e($company['name']??'Nexa Próspero')?></h1><div class="muted"><?=os_e(osbfp_doc($company))?></div><div class="muted"><?=os_e(trim((string)($company['endereco']??'').' '.(string)($company['numero']??'').' '.(string)($company['bairro']??'').' '.(string)($company['cidade']??'').' '.(string)($company['estado']??'')))?></div></div></div>
  <div class="right"><div class="number">Fatura de O.S. #<?=osbfp_no($invoice['invoice_number'])?></div><div>Relação de serviços para conferência</div><div class="muted">Emitida em <?=osbfp_date($invoice['issue_date'])?></div><div style="margin-top:3px"><span class="status"><?=os_e(os_batch_status_label((string)$invoice['status']))?></span></div></div>
</div>
<div class="notice"><b>Documento de conferência:</b> relação consolidada das O.S. selecionadas; não substitui documento fiscal.</div>

<div class="section">
  <div class="section-title">Cliente e período</div>
  <div class="grid">
    <div class="field"><b>Cliente</b><?=os_e($invoice['person_name_snapshot'])?></div>
    <div class="field"><b>CPF/CNPJ</b><?=os_e($invoice['person_document_snapshot'])?></div>
    <div class="field"><b>Período</b><?=osbfp_date($invoice['period_start'])?> a <?=osbfp_date($invoice['period_end'])?></div>
    <div class="field"><b>O.S.</b><?=count($orders)?></div>
  </div>
  <?php if(trim((string)$invoice['notes'])!==''):?><div style="margin-top:4px;font-size:9px"><b>Observação:</b> <?=nl2br(os_e($invoice['notes']))?></div><?php endif;?>
</div>

<div class="section">
  <div class="section-title">Ordens de Serviço e itens</div>
  <?php foreach($orders as $o):
      $osNo=(int)$o['os_number_snapshot'];
      $osLines=$linesByOs[$osNo]??[];
      $plate=$plates[(int)$o['os_order_id']]??'—';
  ?>
  <div class="os-block">
    <div class="os-main">
      <div><b>O.S.</b><span class="os-number">#<?=osbfp_no($osNo)?></span></div>
      <div><b>Data</b><?=osbfp_date($o['service_date_snapshot'])?></div>
      <div><b>Placa</b><strong><?=os_e($plate)?></strong></div>
      <div><b>Status</b><?=os_e($o['status_name_snapshot']?:'—')?></div>
      <div class="desc"><b>Descrição</b><?=os_e($o['title_snapshot']?:'—')?></div>
      <div class="num"><b>Produtos</b><?=os_money($o['total_products'])?></div>
      <div class="num"><b>Serviços</b><?=os_money($o['total_services'])?></div>
      <div class="num"><b>Total O.S.</b><strong><?=os_money($o['total_general'])?></strong></div>
    </div>
    <?php if($osLines):?>
    <table class="os-items">
      <thead><tr><th>Tipo</th><th>Descrição</th><th>Responsável</th><th class="num">Qtd.</th><th class="num">Unit.</th><th class="num">Total</th></tr></thead>
      <tbody><?php foreach($osLines as $l):?><tr><td><?=os_e(osbfp_item_type($l['item_type']))?></td><td><?=os_e($l['description_snapshot'])?><?php if(trim((string)$l['notes'])!==''):?><div class="item-note"><?=os_e($l['notes'])?></div><?php endif;?></td><td><?=os_e($l['responsible_name']?:'—')?></td><td class="num"><?=number_format((float)$l['quantity'],2,',','.')?></td><td class="num"><?=os_money($l['unit_price'])?></td><td class="num"><strong><?=os_money($l['total'])?></strong></td></tr><?php endforeach;?></tbody>
    </table>
    <?php else:?><div class="empty-items">Nenhum item detalhado encontrado para esta O.S.</div><?php endif;?>
  </div>
  <?php endforeach;?>
</div>

<div class="totals">
  <div><span>Total produtos</span><b><?=os_money($invoice['total_products'])?></b></div>
  <div><span>Total serviços</span><b><?=os_money($invoice['total_services'])?></b></div>
  <?php if((float)$invoice['discount_amount']>0):?><div><span>Descontos</span><b>- <?=os_money($invoice['discount_amount'])?></b></div><?php endif;?>
  <?php if((float)$invoice['addition_amount']>0):?><div><span>Acréscimos</span><b><?=os_money($invoice['addition_amount'])?></b></div><?php endif;?>
  <div class="grand"><span>Total da Fatura</span><span><?=os_money($invoice['total_general'])?></span></div>
</div>
<div class="sign"><div>Conferido / Aprovado pelo Cliente</div><div>Responsável pela Empresa</div></div>
<div class="footer-note">Após a conferência e aprovação, o Nexa Próspero pode gerar um único Pedido consolidado e o lançamento financeiro pelo valor total desta relação, mantendo o vínculo com cada O.S. de origem.</div>
</main>
</body>
</html>

<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/delivery_control.php';
require_once __DIR__ . '/_delivery_control_ui.php';
require_once __DIR__ . '/_print_brand.php';
require_once __DIR__ . '/../config/print_codes.php';

$companyId=(int)COMPANY_ID;
delivery_control_require_enabled($pdo,$companyId);
$id=(int)($_GET['id']??0);
if($id<=0)die('Romaneio inválido.');
$st=$pdo->prepare('SELECT * FROM delivery_control_manifests WHERE company_id=? AND id=?');$st->execute([$companyId,$id]);$m=$st->fetch(PDO::FETCH_ASSOC);if(!$m)die('Romaneio não encontrado.');
$companyName='Empresa';try{$cs=$pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1');$cs->execute([$companyId]);$companyName=trim((string)$cs->fetchColumn())?:'Empresa';}catch(Throwable $e){}
$companyLogo=nexa_print_logo_url($companyId);
$manifestCode=str_pad((string)$m['manifest_number'],6,'0',STR_PAD_LEFT);
$manifestBarcode=nexa_print_code128_svg('ROM'.$manifestCode,false);

$st=$pdo->prepare("SELECT oi.product_id,oi.product_name,oi.unit,SUM(oi.quantity) quantity
                   FROM delivery_control_manifest_orders mo
                   JOIN delivery_control_order_items oi ON oi.company_id=mo.company_id AND oi.delivery_order_id=mo.delivery_order_id
                   JOIN delivery_control_orders o ON o.company_id=mo.company_id AND o.id=mo.delivery_order_id
                   WHERE mo.company_id=? AND mo.manifest_id=? AND o.status<>'CANCELADA'
                   GROUP BY oi.product_id,oi.product_name,oi.unit ORDER BY oi.product_name");
$st->execute([$companyId,$id]);$agg=$st->fetchAll(PDO::FETCH_ASSOC)?:[];

$st=$pdo->prepare("SELECT mo.route_sequence,o.order_number,o.id order_id,o.scheduled_date,o.scheduled_time,
                          s.pedido_id,s.customer_name,s.delivery_address,s.delivery_number,s.delivery_district,s.delivery_city,s.delivery_state,
                          oi.product_name,oi.unit,oi.quantity
                   FROM delivery_control_manifest_orders mo
                   JOIN delivery_control_orders o ON o.company_id=mo.company_id AND o.id=mo.delivery_order_id
                   JOIN delivery_control_sales s ON s.company_id=o.company_id AND s.id=o.delivery_sale_id
                   JOIN delivery_control_order_items oi ON oi.company_id=o.company_id AND oi.delivery_order_id=o.id
                   WHERE mo.company_id=? AND mo.manifest_id=? AND o.status<>'CANCELADA'
                   ORDER BY mo.route_sequence,mo.id,oi.product_name");
$st->execute([$companyId,$id]);$detail=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
$byOrder=[];
foreach($detail as $r){$oid=(int)$r['order_id'];if(!isset($byOrder[$oid]))$byOrder[$oid]=['head'=>$r,'items'=>[]];$byOrder[$oid]['items'][]=$r;}

dc_page_start('Lista de Separação','delivery_manifest');
?>
<div class="dc-head no-print"><a class="dc-btn" href="delivery_manifest_view.php?id=<?=$id?>">← Voltar</a><h1>Lista de Separação</h1><div class="dc-spacer"></div><button class="dc-btn primary" onclick="window.print()">Imprimir</button></div>
<div class="sep-sheet">
  <header class="sep-print-head">
    <div class="sep-brand"><?php if($companyLogo!==''):?><img src="<?=dc_e($companyLogo)?>" alt="Logo da empresa"><?php endif;?><strong><?=dc_e($companyName)?></strong></div>
    <div class="sep-doc-title"><strong>LISTA DE SEPARAÇÃO</strong><span><?=dc_date($m['manifest_date'])?></span></div>
    <div class="sep-code"><small>ROMANEIO</small><b>#<?=$manifestCode?></b><?php if($manifestBarcode!==''):?><div class="sep-barcode"><?=$manifestBarcode?></div><?php endif;?></div>
  </header>
  <div class="sep-meta"><span><b>Transportadora:</b> <?=dc_e($m['carrier_name_snapshot']?:'Entrega própria')?></span><span><b>Veículo:</b> <?=dc_e($m['vehicle_description_snapshot']?:'—')?><?=!empty($m['vehicle_plate_snapshot'])?' · '.dc_e($m['vehicle_plate_snapshot']):''?></span><span><b>Motorista:</b> <?=dc_e($m['driver_name_snapshot']?:'—')?></span><span><b>O.E.:</b> <?=count($byOrder)?></span></div>

  <section class="sep-consolidated">
    <h2>Separação consolidada da carga</h2>
    <table class="sep-table"><thead><tr><th>Produto</th><th>Un.</th><th class="num">Qtd. total</th><th class="check">Conf.</th></tr></thead><tbody><?php if(!$agg):?><tr><td colspan="4">Nenhum item.</td></tr><?php endif;?><?php foreach($agg as $r):?><tr><td><strong><?=dc_e($r['product_name'])?></strong></td><td><?=dc_e($r['unit'])?></td><td class="num"><strong><?=dc_qty($r['quantity'])?></strong></td><td class="check">☐</td></tr><?php endforeach;?></tbody></table>
  </section>

  <section class="sep-orders">
    <h2>Separação por Ordem de Entrega</h2>
    <?php foreach($byOrder as $group):$h=$group['head'];?>
      <article class="sep-order">
        <div class="sep-order-head"><strong><?=(int)$h['route_sequence']?>ª parada · O.E. #<?=str_pad((string)$h['order_number'],6,'0',STR_PAD_LEFT)?> · Pedido #<?=(int)$h['pedido_id']?></strong><span><?=dc_e($h['customer_name'])?></span><?php if(!empty($h['scheduled_date'])):?><span>Prog.: <?=dc_date($h['scheduled_date'])?><?=!empty($h['scheduled_time'])?' '.dc_e(substr((string)$h['scheduled_time'],0,5)):''?></span><?php endif;?></div>
        <div class="sep-address"><strong><?=dc_e($h['delivery_address'])?><?=!empty($h['delivery_number'])?', '.dc_e($h['delivery_number']):''?> · <?=dc_e($h['delivery_district'])?> · <?=dc_e($h['delivery_city'])?>/<?=dc_e($h['delivery_state'])?></strong></div>
        <table class="sep-table item-table"><thead><tr><th>Produto</th><th>Un.</th><th class="num">Qtd.</th><th class="check">Conf.</th></tr></thead><tbody><?php foreach($group['items'] as $it):?><tr><td><?=dc_e($it['product_name'])?></td><td><?=dc_e($it['unit'])?></td><td class="num"><?=dc_qty($it['quantity'])?></td><td class="check">☐</td></tr><?php endforeach;?></tbody></table>
      </article>
    <?php endforeach;?>
  </section>
</div>
<style>
.sep-sheet{background:#fff;color:#111;padding:10px 12px;border:1px solid #dbe3ed;border-radius:10px;font-size:10px}.sep-print-head{display:grid;grid-template-columns:minmax(120px,1fr) minmax(220px,1.6fr) minmax(150px,1fr);align-items:center;gap:10px;border-bottom:2px solid #0f172a;padding-bottom:6px;margin-bottom:5px}.sep-brand{display:flex;align-items:center;gap:7px;min-width:0;font-size:10px}.sep-brand img{width:38mm;max-width:135px;height:16mm;object-fit:contain;object-position:left center}.sep-brand strong{line-height:1.1;overflow:hidden;text-overflow:ellipsis}.sep-doc-title{text-align:center;display:flex;flex-direction:column;gap:2px}.sep-doc-title strong{font-size:15px;letter-spacing:.04em}.sep-doc-title span{font-size:9px;font-weight:700}.sep-code{text-align:right;display:grid;justify-items:end;line-height:1}.sep-code small{font-size:8px;font-weight:900;letter-spacing:.08em}.sep-code b{font-size:22px;line-height:1.05}.sep-barcode{width:46mm;height:9mm;margin-top:2px}.sep-barcode svg{width:100%;height:100%;display:block}.sep-meta{display:flex;flex-wrap:wrap;gap:3px 16px;padding-bottom:5px;border-bottom:1px solid #94a3b8;font-size:10px}.sep-sheet h2{font-size:10px;text-transform:uppercase;margin:5px 0 2px}.sep-table{width:100%;border-collapse:collapse;font-size:10px}.sep-table th,.sep-table td{padding:1.2px 3px;border-bottom:1px solid #e2e8f0;text-align:left;line-height:1.15}.sep-table th{font-size:9px;text-transform:uppercase;color:#475569;background:#f8fafc}.sep-table .num{text-align:right;white-space:nowrap}.sep-table .check{text-align:center;width:45px}.sep-orders{margin-top:4px}.sep-order{border-top:1.5px solid #334155;padding-top:3px;margin-top:3px;break-inside:avoid}.sep-order:first-of-type{margin-top:0}.sep-order-head{display:flex;align-items:baseline;flex-wrap:wrap;gap:2px 10px;font-size:10px;line-height:1.18}.sep-order-head strong{font-size:10px}.sep-address{font-size:10px;color:#111;margin:1px 0 2px;line-height:1.18;font-weight:800}.item-table th,.item-table td{padding-top:1.2px;padding-bottom:1.2px}
@media print{@page{size:A4 portrait;margin:6mm}.sep-sheet{border:0;border-radius:0;padding:0;font-size:9pt}.sep-print-head{grid-template-columns:1fr 1.45fr 1fr;gap:5mm;padding-bottom:2.5mm;margin-bottom:2mm}.sep-brand{font-size:9pt}.sep-brand img{width:30mm;height:13mm}.sep-doc-title strong{font-size:12pt}.sep-doc-title span{font-size:9pt}.sep-code small{font-size:7.5pt}.sep-code b{font-size:17pt}.sep-barcode{width:42mm;height:8mm}.sep-meta,.sep-sheet h2,.sep-table,.sep-order-head,.sep-order-head strong,.sep-address{font-size:9pt}.sep-table th{font-size:8.2pt}.sep-table th,.sep-table td{padding:.6mm 1mm}.sep-order{padding-top:1.2mm;margin-top:1.2mm}.sep-address{margin:.3mm 0 .8mm}}
</style>
<?php dc_page_end(); ?>

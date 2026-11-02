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
$st=$pdo->prepare('SELECT * FROM delivery_control_manifests WHERE company_id=? AND id=?');
$st->execute([$companyId,$id]);$m=$st->fetch(PDO::FETCH_ASSOC);if(!$m)die('Romaneio não encontrado.');
$companyName='Empresa';try{$cs=$pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1');$cs->execute([$companyId]);$companyName=trim((string)$cs->fetchColumn())?:'Empresa';}catch(Throwable $e){}
$companyLogo=nexa_print_logo_url($companyId);
$manifestCode=str_pad((string)$m['manifest_number'],6,'0',STR_PAD_LEFT);
$manifestBarcode=nexa_print_code128_svg('ROM'.$manifestCode,false);

$st=$pdo->prepare("SELECT mo.route_sequence,o.id order_id,o.order_number,o.scheduled_date,o.scheduled_time,o.notes order_notes,
                          s.pedido_id,s.customer_name,s.customer_phone,s.delivery_address,s.delivery_number,s.delivery_complement,
                          s.delivery_district,s.delivery_city,s.delivery_state,s.delivery_reference,
                          oi.product_name,oi.unit,oi.quantity
                   FROM delivery_control_manifest_orders mo
                   JOIN delivery_control_orders o ON o.company_id=mo.company_id AND o.id=mo.delivery_order_id
                   JOIN delivery_control_sales s ON s.company_id=o.company_id AND s.id=o.delivery_sale_id
                   LEFT JOIN delivery_control_order_items oi ON oi.company_id=o.company_id AND oi.delivery_order_id=o.id
                   WHERE mo.company_id=? AND mo.manifest_id=? AND o.status<>'CANCELADA'
                   ORDER BY mo.route_sequence,mo.id,oi.product_name");
$st->execute([$companyId,$id]);$detail=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
$orders=[];
foreach($detail as $r){
    $oid=(int)$r['order_id'];
    if(!isset($orders[$oid]))$orders[$oid]=['head'=>$r,'items'=>[]];
    if(!empty($r['product_name']))$orders[$oid]['items'][]=$r;
}

dc_page_start('Romaneio #'.(int)$m['manifest_number'],'delivery_manifest');
?>
<div class="dc-head no-print"><a class="dc-btn" href="delivery_manifest_view.php?id=<?=$id?>">← Voltar</a><h1>Romaneio de Entrega</h1><div class="dc-spacer"></div><button class="dc-btn primary" onclick="window.print()">Imprimir</button></div>
<div class="manifest-sheet">
  <header class="manifest-print-head">
    <div class="manifest-brand"><?php if($companyLogo!==''):?><img src="<?=dc_e($companyLogo)?>" alt="Logo da empresa"><?php endif;?><strong><?=dc_e($companyName)?></strong></div>
    <div class="manifest-doc-title"><strong>ROMANEIO DE ENTREGA</strong><span><?=dc_date($m['manifest_date'])?></span></div>
    <div class="manifest-code"><small>ROMANEIO</small><b>#<?=$manifestCode?></b><?php if($manifestBarcode!==''):?><div class="manifest-barcode"><?=$manifestBarcode?></div><?php endif;?></div>
  </header>
  <div class="manifest-meta">
    <span><b>Transportadora:</b> <?=dc_e($m['carrier_name_snapshot']?:'Entrega própria')?></span>
    <span><b>Veículo:</b> <?=dc_e($m['vehicle_description_snapshot']?:'—')?><?=!empty($m['vehicle_plate_snapshot'])?' · '.dc_e($m['vehicle_plate_snapshot']):''?></span>
    <span><b>Motorista:</b> <?=dc_e($m['driver_name_snapshot']?:'—')?></span>
    <span><b>O.E.:</b> <?=count($orders)?></span>
  </div>
  <?php if(!empty($m['notes'])):?><div class="manifest-notes"><b>Observações:</b> <?=nl2br(dc_e($m['notes']))?></div><?php endif;?>

  <section class="manifest-orders">
  <?php if(!$orders):?><div class="manifest-empty">Nenhuma Ordem de Entrega vinculada.</div><?php endif;?>
  <?php foreach($orders as $group):$h=$group['head'];$address=array_filter([
      trim((string)($h['delivery_address']??'').(!empty($h['delivery_number'])?', '.(string)$h['delivery_number']:'')),
      (string)($h['delivery_complement']??''),
      (string)($h['delivery_district']??''),
      trim((string)($h['delivery_city']??'').(!empty($h['delivery_state'])?'/'.(string)$h['delivery_state']:''))
  ],fn($v)=>trim((string)$v)!=='');?>
    <article class="manifest-order">
      <div class="manifest-order-head">
        <strong><?=(int)$h['route_sequence']?>ª parada · O.E. #<?=str_pad((string)$h['order_number'],6,'0',STR_PAD_LEFT)?> · Pedido #<?=(int)$h['pedido_id']?></strong>
        <span><b><?=dc_e($h['customer_name'])?></b><?=!empty($h['customer_phone'])?' · '.dc_e($h['customer_phone']):''?></span>
        <?php if(!empty($h['scheduled_date'])):?><span>Programação: <?=dc_date($h['scheduled_date'])?><?=!empty($h['scheduled_time'])?' '.dc_e(substr((string)$h['scheduled_time'],0,5)):''?></span><?php endif;?>
      </div>
      <div class="manifest-address"><strong><?=dc_e(implode(' · ',$address))?></strong><?php if(!empty($h['delivery_reference'])):?> <b>Ref.:</b> <?=dc_e($h['delivery_reference'])?><?php endif;?></div>
      <?php if($group['items']):?><table class="manifest-table"><thead><tr><th>Produto</th><th>Un.</th><th class="num">Qtd.</th></tr></thead><tbody><?php foreach($group['items'] as $it):?><tr><td><?=dc_e($it['product_name'])?></td><td><?=dc_e($it['unit'])?></td><td class="num"><?=dc_qty($it['quantity'])?></td></tr><?php endforeach;?></tbody></table><?php endif;?>
      <?php if(!empty($h['order_notes'])):?><div class="manifest-order-notes"><b>Obs.:</b> <?=nl2br(dc_e($h['order_notes']))?></div><?php endif;?>
    </article>
  <?php endforeach;?>
  </section>
</div>
<style>
.manifest-sheet{background:#fff;color:#111;padding:10px 12px;border:1px solid #dbe3ed;border-radius:10px;font-size:10px}.manifest-print-head{display:grid;grid-template-columns:minmax(120px,1fr) minmax(220px,1.6fr) minmax(150px,1fr);align-items:center;gap:10px;border-bottom:2px solid #0f172a;padding-bottom:6px;margin-bottom:5px}.manifest-brand{display:flex;align-items:center;gap:7px;min-width:0;font-size:10px}.manifest-brand img{width:38mm;max-width:135px;height:16mm;object-fit:contain;object-position:left center}.manifest-brand strong{line-height:1.1;overflow:hidden;text-overflow:ellipsis}.manifest-doc-title{text-align:center;display:flex;flex-direction:column;gap:2px}.manifest-doc-title strong{font-size:15px;letter-spacing:.04em}.manifest-doc-title span{font-size:9px;font-weight:700}.manifest-code{text-align:right;display:grid;justify-items:end;line-height:1}.manifest-code small{font-size:8px;font-weight:900;letter-spacing:.08em}.manifest-code b{font-size:22px;line-height:1.05}.manifest-barcode{width:46mm;height:9mm;margin-top:2px}.manifest-barcode svg{width:100%;height:100%;display:block}.manifest-meta{display:flex;flex-wrap:wrap;gap:3px 16px;padding-bottom:4px;border-bottom:1px solid #94a3b8;font-size:10px}.manifest-notes{padding:3px 0;border-bottom:1px solid #cbd5e1;font-size:10px;line-height:1.18}.manifest-orders{margin-top:2px}.manifest-order{border-top:1.5px solid #334155;padding-top:3px;margin-top:3px;break-inside:avoid}.manifest-order:first-child{border-top:0;margin-top:0}.manifest-order-head{display:flex;align-items:baseline;flex-wrap:wrap;gap:2px 10px;line-height:1.18;font-size:10px}.manifest-order-head strong{font-size:10px}.manifest-address{font-size:10px;color:#111;line-height:1.18;margin:1px 0 2px;font-weight:800}.manifest-table{width:100%;border-collapse:collapse;font-size:10px}.manifest-table th,.manifest-table td{padding:1.2px 3px;border-bottom:1px solid #e2e8f0;text-align:left;line-height:1.15}.manifest-table th{font-size:9px;text-transform:uppercase;color:#475569;background:#f8fafc}.manifest-table .num{text-align:right;white-space:nowrap;width:70px}.manifest-order-notes{font-size:10px;line-height:1.15;margin-top:2px}.manifest-empty{padding:20px;text-align:center;color:#64748b}
@media print{@page{size:A4 portrait;margin:6mm}.manifest-sheet{border:0;border-radius:0;padding:0;font-size:9pt}.manifest-print-head{grid-template-columns:1fr 1.45fr 1fr;gap:5mm;padding-bottom:2.5mm;margin-bottom:2mm}.manifest-brand{font-size:9pt}.manifest-brand img{width:30mm;height:13mm}.manifest-doc-title strong{font-size:12pt}.manifest-doc-title span{font-size:9pt}.manifest-code small{font-size:7.5pt}.manifest-code b{font-size:17pt}.manifest-barcode{width:42mm;height:8mm}.manifest-meta,.manifest-notes,.manifest-order-head,.manifest-order-head strong,.manifest-address,.manifest-table,.manifest-order-notes{font-size:9pt}.manifest-table th{font-size:8.2pt}.manifest-table th,.manifest-table td{padding:.6mm 1mm}.manifest-order{padding-top:1.2mm;margin-top:1.2mm}.manifest-address{margin:.3mm 0 .8mm}}
</style>
<?php dc_page_end(); ?>

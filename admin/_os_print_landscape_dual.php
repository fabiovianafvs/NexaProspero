<?php
/** @var PDO $pdo */
$dualLayout=os_print_dual_layout_load($pdo,$companyId);
$items=os_print_enrich_items($pdo,$companyId,$items);
$person=[];if((int)($o['person_id']??0)>0){try{$st=$pdo->prepare('SELECT id,name,document,email,phone,whatsapp,zip_code,address,number,complement,district,city,state FROM finance_people WHERE company_id=? AND id=? LIMIT 1');$st->execute([$companyId,(int)$o['person_id']]);$person=$st->fetch(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){}}
$openingUser='';if((int)($o['created_by']??0)>0){try{$st=$pdo->prepare('SELECT name FROM users WHERE id=? AND company_id=? LIMIT 1');$st->execute([(int)$o['created_by'],$companyId]);$openingUser=trim((string)($st->fetchColumn()?:''));}catch(Throwable $e){}}
$referenceLocal=os_print_custom_value($fields,$optionLabels,['referencia_do_local','referencia_local','referencia local','local_referencia']);
$responsibleOs=os_print_custom_value($fields,$optionLabels,['responsavel_os','responsavel da os','responsavel ordem']);if($responsibleOs==='')$responsibleOs=os_print_responsible_name($items);
$arrival=os_print_custom_value($fields,$optionLabels,['hora_chegada','hora de chegada','chegada']);
$departure=os_print_custom_value($fields,$optionLabels,['hora_saida','hora de saida','saida']);
$executionDate=os_print_custom_value($fields,$optionLabels,['data_execucao','data da execucao','execucao']);if($executionDate===''&&!empty($o['closed_at']))$executionDate=date('d/m/Y',strtotime((string)$o['closed_at']));
$executedBy=os_print_responsible_name($items);
$customerName=trim((string)($o['person_name_snapshot']??''))?:trim((string)($person['name']??''));
$customerDoc=trim((string)($o['person_document_snapshot']??''))?:trim((string)($person['document']??''));
$customerPhone=trim((string)($o['contact_phone']??''));if($customerPhone==='')$customerPhone=trim((string)($person['whatsapp']??''));if($customerPhone==='')$customerPhone=trim((string)($person['phone']??''));if($customerPhone==='')$customerPhone=trim((string)($o['person_phone_snapshot']??''));
$customerEmail=trim((string)($person['email']??''));
$customerAddress=trim((string)($person['address']??''));if(trim((string)($person['number']??''))!=='')$customerAddress.=($customerAddress!==''?', ':'').trim((string)$person['number']);if(trim((string)($person['complement']??''))!=='')$customerAddress.=($customerAddress!==''?' - ':'').trim((string)$person['complement']);
$customerDistrict=trim((string)($person['district']??''));$customerZip=trim((string)($person['zip_code']??''));$customerCity=trim((string)($person['city']??''));$customerState=trim((string)($person['state']??''));
$companyDoc=osp_doc($company);$companyAddress=trim((string)($company['endereco']??''));if(trim((string)($company['numero']??''))!=='')$companyAddress.=($companyAddress!==''?', ':'').trim((string)$company['numero']);
$companyDistrict=trim((string)($company['bairro']??''));$companyCity=trim((string)($company['cidade']??''));$companyState=trim((string)($company['estado']??''));$companyPhone=trim((string)($company['whatsapp']??''));
$osNo=str_pad((string)(int)$o['os_number'],10,'0',STR_PAD_LEFT);
$openedDate='';$openedTime='';if(!empty($o['opened_at'])){$ts=strtotime((string)$o['opened_at']);if($ts){$openedDate=date('d/m/Y',$ts);$openedTime=date('H:i:s',$ts);}}
$scheduleDate='';$scheduleTime='';if(!empty($o['expected_at'])){$ts=strtotime((string)$o['expected_at']);if($ts){$scheduleDate=date('d/m/Y',$ts);$scheduleTime=date('H:i:s',$ts);}}
$paymentOptions=[];foreach($payments as $p){$label=trim((string)($p['payment_label']??''));if($label==='')continue;$suffix='';if(!empty($p['due_date']))$suffix=' - '.date('d/m/Y',strtotime((string)$p['due_date']));$paymentOptions[]=$label.$suffix;if(count($paymentOptions)>=4)break;}if(!$paymentOptions)$paymentOptions=(array)$dualLayout['options']['payment_options'];
$blockStyle=static function(array $b):string{return 'left:'.number_format((float)$b['x'],2,'.','').'mm;top:'.number_format((float)$b['y'],2,'.','').'mm;width:'.number_format((float)$b['w'],2,'.','').'mm;height:'.number_format((float)$b['h'],2,'.','').'mm;font-size:'.number_format((float)$b['font'],2,'.','').'pt;'.(empty($b['visible'])?'display:none;':'');};
$txt=(array)$dualLayout['texts'];$blankRows=max(0,(int)$dualLayout['options']['blank_item_rows']);
$printerProfile=((string)($_GET['perfil_impressao']??''))==='hp_p1005'?'hp_p1005':'padrao';
$printerSafe=($printerProfile==='hp_p1005');
// Novo formato opcional: Carta (21,59 x 27,94 cm). A4 permanece o padrão.
// No modo paisagem o lado de aproximadamente 28 cm passa a ser a largura.
$paperLetter=((string)($_GET['papel']??''))==='carta';
$paperQuery=$paperLetter?'&papel=carta':'';
$profileQuery=$printerSafe?'&perfil_impressao=hp_p1005':'';
$printHidePrices=$printContent!=='completa';$printBlankItems=$printContent==='sem_produtos';
// Respeitar o tamanho real da grade, inclusive quando o layout foi personalizado.
$gridHeight=(float)($dualLayout['blocks']['items']['h']??54);
$headerHeight=4.4;
$rowHeight=$printBlankItems ? 6.1 : 4.05;
$maxRows=max(1,(int)floor(max(0,$gridHeight-$headerHeight-.30)/$rowHeight));
$maxRows=min($maxRows,20);
$displayItems=$printBlankItems?[]:$items;
$remaining=max(0,count($displayItems)-($maxRows-1));
if(count($displayItems)<=$maxRows)$remaining=0;
if($remaining>0){$displayItems=array_slice($displayItems,0,max(0,$maxRows-1));}
else{$displayItems=array_slice($displayItems,0,$maxRows);}
if($remaining>0)$displayItems[]=['display_code'=>'','description_snapshot'=>'+ '.$remaining.' item(ns)','quantity'=>'','unit_price'=>'','total'=>''];
// Em branco, todas as linhas são reservadas à escrita; nas demais, completam a grade.
$blankRows=$printBlankItems?$maxRows:min($blankRows,max(0,$maxRows-count($displayItems)));
$renderCopy=function() use ($dualLayout,$blockStyle,$txt,$printLogoUrl,$company,$companyDoc,$companyAddress,$companyDistrict,$companyCity,$companyState,$companyPhone,$osNo,$customerName,$o,$customerAddress,$customerPhone,$customerDistrict,$customerZip,$customerDoc,$customerEmail,$customerCity,$customerState,$referenceLocal,$responsibleOs,$openedDate,$openedTime,$openingUser,$scheduleDate,$scheduleTime,$arrival,$departure,$executionDate,$displayItems,$remaining,$blankRows,$paymentOptions,$executedBy,$printHidePrices,$printBlankItems,$rowHeight){
  $b=$dualLayout['blocks'];
?>
<div class="os-dual-copy">
  <div class="osd-block osd-logo" style="<?=$blockStyle($b['logo'])?>"><?php if($printLogoUrl!==''):?><img src="<?=os_e($printLogoUrl)?>" alt="Logo da empresa"><?php endif;?></div>
  <div class="osd-block osd-heading" style="<?=$blockStyle($b['heading'])?>"><b><?=os_e($txt['document_title'])?></b><strong><?=os_e($osNo)?></strong></div>
  <div class="osd-block osd-company" style="<?=$blockStyle($b['company'])?>"><b><?=os_e((string)($company['name']??''))?></b><div>Endereço: <?=os_e($companyAddress)?></div><div>Bairro: <?=os_e($companyDistrict)?> &nbsp;&nbsp; <?=os_e(trim($companyCity.' '.$companyState))?></div><?php if($companyPhone!==''):?><div>Telefone: <?=os_e($companyPhone)?></div><?php endif;?><?php if($companyDoc!==''):?><div>CPF/CNPJ: <?=os_e($companyDoc)?></div><?php endif;?></div>
  <div class="osd-block osd-warning" style="<?=$blockStyle($b['warning'])?>"><?=os_e($txt['warning'])?></div>
  <div class="osd-block osd-customer" style="<?=$blockStyle($b['customer'])?>">
    <div class="osd-section-title"><?=os_e($txt['customer_title'])?></div>
    <div class="osd-row"><div class="osd-grow"><small>Cliente:</small><b><?=os_e($customerName)?></b></div><div class="osd-w36"><small>Contato:</small><?=os_e(trim((string)($o['contact_name']??'')))?></div></div>
    <div class="osd-row"><div class="osd-grow"><small>Endereço:</small><b><?=os_e($customerAddress)?></b></div><div class="osd-w36"><small>Telefone:</small><?=os_e($customerPhone)?></div></div>
    <div class="osd-row"><div class="osd-w44"><small>Bairro:</small><b><?=os_e($customerDistrict)?></b></div><div class="osd-w20"><small>CEP:</small><?=os_e($customerZip)?></div><div class="osd-grow"><small>CPF/CNPJ:</small><?=os_e($customerDoc)?></div></div>
    <div class="osd-row"><div class="osd-grow"><small>Email:</small><?=os_e($customerEmail)?></div><div class="osd-w34"><small>Cidade:</small><?=os_e($customerCity)?></div><div class="osd-w12"><small>UF:</small><?=os_e($customerState)?></div></div>
    <div class="osd-row osd-last"><div class="osd-grow"><small>Referência do Local:</small><?=os_e($referenceLocal)?></div><div class="osd-w48"><small>Responsável OS:</small><?=os_e($responsibleOs)?></div></div>
  </div>
  <div class="osd-block osd-os" style="<?=$blockStyle($b['os'])?>">
    <div class="osd-section-title"><?=os_e($txt['os_title'])?></div>
    <div class="osd-row"><div class="osd-w45"><small>Data/Hora da Abertura:</small><b><?=os_e(trim($openedDate.' '.$openedTime))?></b></div><div class="osd-grow"><small>Usuário Abertura:</small><?=os_e($openingUser)?></div><div class="osd-w28"><small>Status OS:</small><b><?=os_e((string)($o['status_name']??''))?></b></div></div>
    <div class="osd-row osd-last"><div class="osd-grow osd-modality"><small>Modalidade:</small><div class="osd-check"><span class="osd-option"><i class="osd-square"></i> Executado</span><span class="osd-option"><i class="osd-square"></i> Não Autorizado</span></div></div><div class="osd-w52"><small>Agendamento:</small><?=os_e($scheduleDate!==''?$scheduleDate.' às '.$scheduleTime:'')?></div></div>
  </div>
  <div class="osd-block osd-occurrence" style="<?=$blockStyle($b['occurrence'])?>"><div class="osd-section-title"><?=os_e($txt['occurrence_title'])?></div><div class="osd-occurrence-text"><?=nl2br(os_e((string)($o['reported_problem']??'')))?></div></div>
  <div class="osd-block osd-execution" style="<?=$blockStyle($b['execution'])?>"><div><small>Hora de Chegada:</small><b><?=os_e($arrival!==''?$arrival:'_____:_____')?></b></div><div><small>Hora de Saída:</small><b><?=os_e($departure!==''?$departure:'_____:_____')?></b></div><div class="osd-grow"><small>Data da Execução:</small><b><?=os_e($executionDate!==''?$executionDate:'____/____/_______')?></b></div></div>
  <div class="osd-block osd-items <?= $printBlankItems?'osd-items--handwrite':'' ?> <?= $printHidePrices&&!$printBlankItems?'osd-items--no-prices':'' ?>" style="<?=$blockStyle($b['items'])?>;--osd-row-height:<?=number_format($rowHeight,2,'.','')?>mm">
    <table><thead><tr><th style="width:13%">Código</th><th>Produto / Serviço</th><th style="width:11%">Qtde</th><?php if(!$printHidePrices||$printBlankItems):?><th style="width:18%">Vlr Unit</th><th style="width:18%">Vlr Total</th><?php endif;?></tr></thead>
    <tbody><?php foreach($displayItems as $it):?><tr><td><?=os_e((string)($it['display_code']??''))?></td><td><?=os_e((string)($it['description_snapshot']??''))?></td><td class="num"><?php if(($it['quantity']??'')!==''):?><?=os_e(number_format((float)$it['quantity'],2,',','.'))?><?php endif;?></td><?php if(!$printHidePrices||$printBlankItems):?><td class="num"><?php if(!$printHidePrices && !$printBlankItems):?><?=os_money((float)($it['unit_price']??0))?><?php endif;?></td><td class="num"><?php if(!$printHidePrices && !$printBlankItems):?><?=os_money((float)($it['total']??0))?><?php endif;?></td><?php endif;?></tr><?php endforeach;?>
    <?php for($r=0;$r<$blankRows;$r++):?><tr class="blank"><td>&nbsp;</td><td></td><td></td><?php if(!$printHidePrices||$printBlankItems):?><td></td><td></td><?php endif;?></tr><?php endfor;?></tbody></table>
  </div>
  <div class="osd-block osd-payments" style="<?=$blockStyle($b['payments'])?>"><div class="osd-pay-head"><b><?=os_e($txt['payment_title'])?></b><?php if(!$printHidePrices):?><span>Vlr Total: <strong><?=os_money((float)($o['total_general']??0))?></strong></span><?php elseif($printBlankItems):?><span>Vlr Total: <strong>______________</strong></span><?php endif;?></div><div class="osd-payment-options"><?php foreach($paymentOptions as $po):?><span>□ <?=os_e($po)?></span><?php endforeach;?></div><div class="osd-payment-note"><?=os_e($txt['payment_note'])?> <span class="osd-email-line"></span></div></div>
  <div class="osd-block osd-executed" style="<?=$blockStyle($b['executed_by'])?>"><div class="osd-sign-name"><?=os_e($executedBy)?></div><div class="osd-sign-line"></div><b class="osd-sign-label"><?=os_e($txt['executed_by'])?></b></div>
  <div class="osd-block osd-signature" style="<?=$blockStyle($b['signature'])?>"><div class="osd-sign-name"></div><div class="osd-sign-line"></div><b class="osd-sign-label"><?=os_e($txt['signature'])?></b></div>
</div>
<?php };
?><!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>O.S. #<?=os_e((string)$o['os_number'])?> - <?= $paperLetter?'Carta':'A4' ?> paisagem 2 vias</title><link rel="stylesheet" href="../assets/nexa-print-actions.css?v=1145197"><style>
@page{size:A4 landscape;margin:5mm}*{box-sizing:border-box}body{margin:0;background:#edf1f6;font-family:Arial,Helvetica,sans-serif;color:#111;padding:14px}.osd-page{position:relative;width:297mm;height:210mm;margin:0 auto;background:#fff;box-shadow:0 12px 30px rgba(15,23,42,.14);overflow:hidden}.os-dual-copy{position:absolute;top:<?=number_format((float)$dualLayout['page']['margin_y'],2,'.','')?>mm;width:<?=number_format((float)$dualLayout['page']['copy_width'],2,'.','')?>mm;height:<?=number_format((float)$dualLayout['page']['copy_height'],2,'.','')?>mm;background:#fff;overflow:hidden}.os-dual-copy:first-child{left:<?=number_format((float)$dualLayout['page']['margin_x'],2,'.','')?>mm}.os-dual-copy:last-child{left:<?=number_format((float)($dualLayout['page']['margin_x']+$dualLayout['page']['copy_width']+$dualLayout['page']['gap']),2,'.','')?>mm}.osd-block{position:absolute;border:0.25mm solid #222;background:#fff;overflow:hidden;line-height:1.05}.osd-logo{display:flex;align-items:center;justify-content:center;padding:1mm}.osd-logo img{max-width:100%;max-height:100%;object-fit:contain}.osd-heading{display:flex;align-items:center;justify-content:space-between;padding:0 1.2mm;background:#eee;white-space:nowrap}.osd-heading strong{font-size:1.02em}.osd-company{padding:.6mm 1mm}.osd-company b{font-size:1.08em}.osd-warning{display:flex;align-items:center;justify-content:center;font-weight:900;text-align:center}.osd-section-title{height:4.1mm;display:flex;align-items:center;padding:0 1mm;background:#d7d7d7;border-bottom:.25mm solid #222;font-weight:900}.osd-row{display:flex;min-height:6.25mm;border-bottom:.22mm solid #666}.osd-row>div{padding:.45mm .8mm;border-right:.22mm solid #666;overflow:hidden}.osd-row>div:last-child{border-right:0}.osd-row.osd-last{border-bottom:0}.osd-row small,.osd-execution small{display:block;font-size:.82em}.osd-row b{display:block}.osd-grow{flex:1;min-width:0}.osd-w12{width:12%}.osd-w20{width:20%}.osd-w28{width:28%}.osd-w34{width:34%}.osd-w36{width:36%}.osd-w44{width:44%}.osd-w45{width:45%}.osd-w48{width:48%}.osd-w52{width:52%}.osd-check{font-size:1.05em;text-align:center}.osd-occurrence-text{padding:1.2mm;font-weight:800;white-space:normal}.osd-execution{display:flex;align-items:stretch}.osd-execution>div{padding:.55mm .8mm;border-right:.22mm solid #666;min-width:25%}.osd-execution>div:last-child{border-right:0}.osd-execution b{display:block;margin-top:.6mm}.osd-items{padding:0}.osd-items table{width:100%;height:auto;border-collapse:collapse;table-layout:fixed;font-size:1em}.osd-items th,.osd-items td{border-right:.22mm solid #555;border-bottom:.22mm solid #555;padding:.45mm .55mm;height:var(--osd-row-height,4.05mm);overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.osd-items th:last-child,.osd-items td:last-child{border-right:0}.osd-items th{background:#ddd;font-weight:900}.osd-items .num{text-align:right}.osd-items tr.blank td{height:var(--osd-row-height,4.05mm)}.osd-payments{padding:.55mm .8mm}.osd-pay-head{display:flex;justify-content:space-between;gap:2mm}.osd-payment-options{display:grid;grid-template-columns:1fr 1fr;gap:.45mm 2mm;margin-top:.5mm;font-weight:700}.osd-payment-note{margin-top:.55mm;line-height:1.08}.osd-email-line{display:inline-block;width:35mm;border-bottom:.2mm solid #333;vertical-align:middle}.osd-executed{padding:.8mm}.osd-executed span{display:inline-block;width:70%;border-bottom:.22mm solid #333;margin-left:1mm}.osd-signature{border:0;text-align:center;padding-top:.5mm}.osd-signature div{border-top:.25mm solid #222;margin:0 auto .7mm;width:100%}.osd-signature b{font-size:1em}.nexa-print-toolbar{margin:0 auto 12px;max-width:1120px}@media print{body{background:#fff;padding:0}.nexa-print-toolbar{display:none!important}.osd-page{box-shadow:none;margin:0}}@media screen and (max-width:1050px){body{padding:8px}.osd-page{transform-origin:top left;transform:scale(.72);margin-bottom:-58mm}}@media screen and (max-width:760px){.osd-page{transform:scale(.48);margin-bottom:-108mm}.nexa-print-actions{display:grid!important;grid-template-columns:repeat(2,1fr)}}@media print{html,body{width:297mm!important;height:210mm!important;margin:0!important;padding:0!important;overflow:visible!important}.osd-page{width:297mm!important;height:210mm!important;transform:none!important;transform-origin:initial!important;margin:0!important;box-shadow:none!important;break-inside:avoid!important;page-break-inside:avoid!important}.os-dual-copy{break-inside:avoid;page-break-inside:avoid}}

/* Refino v3: cabeçalho compacto, modalidade em linha e rodapé legível. */
.osd-company{padding:.35mm .85mm;line-height:1.08;display:flex;flex-direction:column;justify-content:center;gap:.10mm}
.osd-company b{font-size:1.04em;line-height:1.08}
.osd-os .osd-section-title{height:4mm}
.osd-os .osd-row{height:7mm;min-height:0}
.osd-os .osd-row>div{padding:.35mm .8mm}
.osd-os .osd-modality{display:flex;align-items:center;gap:1.8mm;white-space:nowrap}
.osd-os .osd-modality small{display:inline;font-size:.88em;flex:0 0 auto}
.osd-os .osd-modality .osd-check{display:flex;align-items:center;gap:3mm;min-width:0;font-size:.98em;font-weight:700;white-space:nowrap}
.osd-os .osd-option{display:inline-flex;align-items:center;gap:.9mm;white-space:nowrap}
.osd-os .osd-square{display:inline-block;width:2.5mm;height:2.5mm;flex:0 0 auto;border:.22mm solid #111;border-radius:0}
.osd-payments{padding:.45mm .8mm;line-height:1.12}
.osd-pay-head{align-items:baseline;min-height:3mm}
.osd-payment-options{gap:.5mm 2mm;margin-top:.5mm;line-height:1.12}
.osd-payment-options>span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.osd-payment-note{margin-top:.6mm;line-height:1.1;font-size:.92em;overflow:hidden}
.osd-email-line{width:25mm}
.osd-executed,.osd-signature{border:0;text-align:center;display:flex;flex-direction:column;align-items:stretch;justify-content:flex-end;padding:0 .7mm .35mm;line-height:1.15;overflow:hidden}
.osd-executed .osd-sign-name,.osd-signature .osd-sign-name{height:4.2mm;display:flex;align-items:flex-end;justify-content:center;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.osd-executed .osd-sign-line,.osd-signature .osd-sign-line{width:97%;height:0;border-top:.22mm solid #222;margin:1.2mm auto .8mm;flex:0 0 auto}
.osd-executed .osd-sign-label,.osd-signature .osd-sign-label{display:block;font-size:1em;line-height:1.15;font-weight:700;white-space:normal;max-height:3.7mm;overflow:hidden}

/* Paginação A4 (v5): a folha física mede 297 x 210 mm, com @page 5 mm.
   A área imprimível é 287 x 200 mm. Mantemos a compensação por zoom
   (dimensiona o layout ANTES da paginação) que resolveu a segunda folha.
   Não usar transform:scale na versão principal. A folga é intencional. */
@media print {
  html, body {
    width: auto !important;
    height: auto !important;
    min-height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: visible !important;
    background: #fff !important;
  }
  .nexa-print-toolbar { display: none !important; }
  .osd-page {
    position: relative !important;
    width: 283mm !important;
    height: 194mm !important;
    min-height: 0 !important;
    max-height: 194mm !important;
    margin: 0 auto !important;
    padding: 0 !important;
    box-shadow: none !important;
    overflow: visible !important;
    transform: none !important;
    break-before: avoid !important;
    page-break-before: avoid !important;
    break-inside: avoid !important;
    page-break-inside: avoid !important;
    break-after: avoid !important;
    page-break-after: avoid !important;
  }
  .osd-page > .os-dual-copy {
    position: absolute !important;
    top: 0 !important;
    width: 141mm !important;
    height: 200mm !important;
    margin: 0 !important;
    padding: 0 !important;
    zoom: .97 !important;
    transform: none !important;
    transform-origin: 0 0 !important;
    break-inside: avoid !important;
    page-break-inside: avoid !important;
  }
  .osd-page > .os-dual-copy:first-child { left: 1mm !important; }
  .osd-page > .os-dual-copy:last-child { left: 146mm !important; }
}
@supports not (zoom: 1) {
  @media print {
    .osd-page > .os-dual-copy {
      transform: scale(.97) !important;
      transform-origin: 0 0 !important;
    }
  }
}
<?php if ($printerSafe): ?>
/* Perfil HP P1005 / laser: mantém o preset da O.S. e reserva uma área segura
   na folha física, com redução ANTES da paginação (zoom, não transform).
   @page 8mm + espaçamento interno => ~9-15mm de margem final.
   A Epson continua exatamente com o perfil padrão de 5mm. */
@page { size: A4 landscape; margin: 8mm; }
@media print {
  .osd-page {
    width: 275mm !important;
    height: 187mm !important;
    max-height: 187mm !important;
    min-height: 0 !important;
    margin: 1mm auto 0 !important;
    overflow: hidden !important;
    padding: 0 !important;
    transform: none !important;
    break-inside: avoid !important;
    page-break-inside: avoid !important;
  }
  .osd-page > .os-dual-copy {
    top: 0 !important;
    width: 141mm !important;
    height: 200mm !important;
    zoom: .93 !important;
    transform: none !important;
    transform-origin: 0 0 !important;
    overflow: hidden !important;
    break-inside: avoid !important;
    page-break-inside: avoid !important;
  }
  .osd-page > .os-dual-copy:first-child { left: 1mm !important; }
  .osd-page > .os-dual-copy:last-child { left: 141mm !important; }
}
@supports not (zoom: 1) {
  @media print {
    .osd-page > .os-dual-copy { transform: scale(.93) !important; }
  }
}
<?php endif; ?>

<?php if ($paperLetter): ?>
/* Papel Carta (~28 cm no lado maior): largura 279,4 x altura 215,9 mm em paisagem.
   O desenho interno das duas vias permanece o mesmo do A4; apenas se reduz
   o conjunto, ANTES da paginação, para caber na largura do papel Carta.
   Nunca usar transform: scale como mecanismo principal de impressão. */
@page { size: letter landscape; margin: 5mm; }
@media screen {
  .osd-page { width: 279.4mm; height: 215.9mm; }
  .osd-page > .os-dual-copy { top: 5mm; zoom: .92; }
  .osd-page > .os-dual-copy:first-child { left: 4mm; }
  .osd-page > .os-dual-copy:last-child { left: 141mm; }
}
@media print {
  .osd-page {
    width: 265mm !important;
    height: 191mm !important;
    min-height: 0 !important;
    max-height: 191mm !important;
    margin: 0 auto !important;
    padding: 0 !important;
    overflow: hidden !important;
    transform: none !important;
    break-inside: avoid !important;
    page-break-inside: avoid !important;
  }
  .osd-page > .os-dual-copy {
    top: 0 !important;
    width: 141mm !important;
    height: 200mm !important;
    zoom: .92 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
    transform: none !important;
    break-inside: avoid !important;
    page-break-inside: avoid !important;
  }
  .osd-page > .os-dual-copy:first-child { left: 2mm !important; }
  .osd-page > .os-dual-copy:last-child { left: 134mm !important; }
}
@supports not (zoom: 1) {
  @media print {
    .osd-page > .os-dual-copy { transform: scale(.92) !important; transform-origin: 0 0 !important; }
  }
}
<?php if ($printerSafe): ?>
/* Papel Carta + Modo HP/laser: folga adicional para impressoras restritivas. */
@page { size: letter landscape; margin: 8mm; }
@media print {
  .osd-page {
    width: 259mm !important;
    height: 185mm !important;
    max-height: 185mm !important;
    margin: 0 auto !important;
  }
  .osd-page > .os-dual-copy { zoom: .885 !important; }
  .osd-page > .os-dual-copy:first-child { left: 2mm !important; }
  .osd-page > .os-dual-copy:last-child { left: 130mm !important; }
}
@supports not (zoom: 1) {
  @media print { .osd-page > .os-dual-copy { transform: scale(.885) !important; } }
}
<?php endif; ?>

/* Carta 2 vias — centralização e faixa de corte (v2).
   O zoom reduz também as coordenadas left, não apenas a largura. Por isso,
   os offsets abaixo compensam a escala e criam uma separação real de 5 mm.
   Papel 279,4 mm: impressão normal -> margem lateral ~7,48 mm, 5 mm de vão.
   Perfil HP/laser -> margem lateral ~12,42 mm, 5 mm de vão.
   Mantido exclusivamente em papel Carta: A4 não sofre alteração. */
@media screen {
  .osd-page > .os-dual-copy:first-child { left: 8.13mm; }
  .osd-page > .os-dual-copy:last-child { left: 154.56mm; }
  .osd-page::after {
    content: "";
    position: absolute;
    z-index: 2;
    top: 8mm;
    left: 50%;
    height: 184mm;
    border-left: .18mm dashed #a8a8a8;
    pointer-events: none;
  }
}
@media print {
  .osd-page > .os-dual-copy:first-child { left: .30mm !important; }
  .osd-page > .os-dual-copy:last-child { left: 146.73mm !important; }
  .osd-page::after {
    content: "";
    position: absolute;
    z-index: 2;
    top: 3mm;
    left: 50%;
    height: 184mm;
    border-left: .18mm dashed #a8a8a8;
    pointer-events: none;
  }
}
<?php if ($printerSafe): ?>
@media print {
  .osd-page > .os-dual-copy:first-child { left: 2.50mm !important; }
  .osd-page > .os-dual-copy:last-child { left: 149.15mm !important; }
}
<?php endif; ?>
<?php endif; ?>
</style></head><body>
<div class="nexa-print-toolbar"><div class="nexa-print-toolbar-copy"><strong class="nexa-print-toolbar-title">O.S. #<?=str_pad((string)$o['os_number'],6,'0',STR_PAD_LEFT)?></strong><div class="nexa-print-toolbar-hint">Modelo <?= $paperLetter?'Carta':'A4' ?> paisagem com duas vias · <?=os_e($printContent==='sem_preco'?'Sem preços':($printBlankItems?'Sem produtos':'Completa'))?>. <?= $printerSafe?'Perfil HP/laser (área de segurança)':'Perfil padrão (5 mm)' ?></div><?php if(trim((string)($_GET['ok']??''))!==''):?><div class="nexa-print-toolbar-message"><?=os_e((string)$_GET['ok'])?></div><?php endif;?></div><div class="nexa-print-actions"><?php if ($printerSafe): ?><span class="nexa-print-profile-hint" style="font-size:11px;align-self:center;white-space:nowrap;color:#4b5563">Margem segura ~10 mm</span><?php endif; ?><button class="nexa-print-action is-primary" type="button" onclick="window.print()"><span class="nexa-print-action-label">🖨 Imprimir</span></button><a class="nexa-print-action is-whatsapp" href="documento_whatsapp.php?type=os&id=<?=$id?>&modelo=a4_landscape_dual&conteudo=<?=rawurlencode($printContent)?>&papel=<?=($paperLetter?"carta":"a4")?>&perfil_impressao=<?=($printerSafe?"hp_p1005":"padrao")?>&return_to=<?=rawurlencode('os_imprimir.php?id='.$id.'&modelo=a4_landscape_dual'.$printQuery.$paperQuery.$profileQuery)?>"><span class="nexa-print-action-label">💬 WhatsApp</span></a><a class="nexa-print-action" href="email_documento.php?type=os&id=<?=$id?>&modelo=a4_landscape_dual&conteudo=<?=rawurlencode($printContent)?>&papel=<?=($paperLetter?"carta":"a4")?>&perfil_impressao=<?=($printerSafe?"hp_p1005":"padrao")?>&return_to=<?=rawurlencode('os_imprimir.php?id='.$id.'&modelo=a4_landscape_dual'.$printQuery.$paperQuery.$profileQuery)?>"><span class="nexa-print-action-label">✉ E-mail</span></a><a class="nexa-print-action" href="os_imprimir.php?id=<?=$id?>&modelo=a4_landscape_dual<?=os_e($paperQuery)?><?=os_e($profileQuery)?><?=os_e($originQuery)?>"><span class="nexa-print-action-label" style="font-size:11px">Opções</span></a><a class="nexa-print-action" href="<?=os_e($exitHref)?>"><span class="nexa-print-action-label">↩ Sair</span></a></div></div>
<div class="osd-page"><?php $renderCopy();$renderCopy();?></div></body></html>

<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/product_labels_module.php';

$cid=(int)COMPANY_ID;
product_labels_ensure_schema($pdo,$cid);
function lei_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}

$templateId=(int)($_POST['template_id']??$_GET['template_id']??0);
$tpl=product_labels_template($pdo,$cid,$templateId);
if(!$tpl){http_response_code(404);echo 'Modelo de etiqueta não encontrado.';exit;}
$qtyRaw=is_array($_POST['qty']??null)?$_POST['qty']:[];
$qty=[];$total=0;
foreach($qtyRaw as $id=>$q){$id=(int)$id;$n=max(0,min(5000,(int)round((float)$q)));if($id>0&&$n>0){$qty[$id]=$n;$total+=$n;if($total>12000){$qty[$id]-=($total-12000);$total=12000;break;}}}
if(!$qty){echo 'Nenhum produto selecionado para impressão.';exit;}
$products=product_labels_products_by_ids($pdo,$cid,array_keys($qty));
$labels=[];
foreach($qty as $id=>$n){if(!isset($products[$id]))continue;for($i=0;$i<$n;$i++)$labels[]=$products[$id];}
if(!$labels){echo 'Os produtos selecionados não foram encontrados.';exit;}

$company=product_labels_company_name($pdo,$cid);
$layout=product_labels_sanitize_layout($tpl['layout_json']??'');
$paper=(string)($tpl['paper_type']??'ROLL');$pageW=(float)$tpl['page_width_mm'];$pageH=$paper==='A4'?(float)($tpl['page_height_mm']?:297):0.0;
$cols=max(1,(int)$tpl['columns_count']);$lw=(float)$tpl['label_width_mm'];$lh=(float)$tpl['label_height_mm'];$gx=(float)$tpl['gap_x_mm'];$gy=(float)$tpl['gap_y_mm'];$ml=(float)$tpl['margin_left_mm'];$mt=(float)$tpl['margin_top_mm'];$mr=(float)$tpl['margin_right_mm'];$mb=(float)$tpl['margin_bottom_mm'];
$codeSource=(string)$tpl['code_source'];$barcodeSource=(string)$tpl['barcode_source'];
$rollMode=strtoupper((string)($layout['_print']['media_mode']??'GAP'));
if(!in_array($rollMode,['GAP','CONTINUOUS'],true))$rollMode='GAP';
$rollPitch=product_labels_roll_pitch($tpl,$layout);

$pages=[];
$lastPageH=$pageH;
if($paper==='A4'){
  $usableH=max($lh,$pageH-$mt-$mb);
  $rows=max(1,(int)floor(($usableH+$gy)/max(.1,$lh+$gy)));
  $perPage=max(1,$rows*$cols);
  $pages=array_chunk($labels,$perPage);
  $lastPageH=$pageH;
}else{
  if($rollMode==='CONTINUOUS'){
    // Mídia realmente contínua: um único trabalho com altura exata do conteúdo.
    $rows=max(1,(int)ceil(count($labels)/$cols));
    $contentH=($rows*$lh)+(max(0,$rows-1)*$gy);
    $pageH=max($lh,$mt+$contentH+$mb);
    $lastPageH=$pageH;
    $pages=[$labels];
  }else{
    /*
     * Etiquetas com GAP/sensor: cada página técnica corresponde a UMA LINHA
     * física e sua altura é exatamente o avanço (pitch) real da mídia.
     * O pitch não é a altura imprimível: é a distância do início de uma linha
     * ao início da próxima. Ex.: etiqueta imprimível 21 mm + gap 1 mm = 22 mm.
     */
    $pages=array_chunk($labels,$cols);
    $pageH=max($lh,$rollPitch,$mt+$lh+$mb);
    $lastPageH=$pageH;
  }
}

function lei_field_style(array $v):string{
  return 'left:'.(float)$v['x'].'mm;top:'.(float)$v['y'].'mm;width:'.(float)$v['w'].'mm;height:'.(float)$v['h'].'mm;font-size:'.(float)$v['font'].'mm;font-weight:'.(!empty($v['bold'])?'900':'400').';text-align:'.lei_h($v['align']).';justify-content:'.($v['align']==='center'?'center':($v['align']==='right'?'flex-end':'flex-start')).';';
}
function lei_render_label(array $p,array $layout,string $company,string $codeSource,string $barcodeSource):string{
  $code=product_labels_source_value($p,$codeSource);$bc=product_labels_source_value($p,$barcodeSource);$price='R$ '.number_format((float)$p['preco'],2,',','.');
  $values=['company'=>$company,'name'=>(string)$p['nome'],'code'=>$code!==''?'Cód. '.$code:'','price'=>$price];$html='<div class="label">';
  foreach(['company','name','code','price'] as $k){$v=$layout[$k]??[];if(empty($v['visible']))continue;$html.='<div class="field field-'.$k.'" style="'.lei_field_style($v).'">'.lei_h($values[$k]).'</div>';}
  $v=$layout['barcode']??[];if(!empty($v['visible'])&&$bc!==''){$html.='<div class="field field-barcode" style="'.lei_field_style($v).'">'.product_labels_code128_svg($bc,!empty($v['show_text'])).'</div>';}
  return $html.'</div>';
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Etiquetas - <?=lei_h($tpl['name'])?></title>
<style>
:root{--page-w:<?=$pageW?>mm;--page-h:<?=$pageH?>mm;--label-w:<?=$lw?>mm;--label-h:<?=$lh?>mm;--gap-x:<?=$gx?>mm;--gap-y:<?=$gy?>mm;--ml:<?=$ml?>mm;--mt:<?=$mt?>mm;--mr:<?=$mr?>mm;--mb:<?=$mb?>mm}
*{box-sizing:border-box}html,body{margin:0;padding:0;background:#e9eef5;color:#111;font-family:Arial,sans-serif}.toolbar{position:sticky;top:0;z-index:20;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;background:#0b1f5b;color:#fff;padding:10px 14px;box-shadow:0 3px 16px rgba(15,23,42,.18)}.toolbar strong{font-size:14px}.toolbar span{font-size:12px;opacity:.85}.actions{display:flex;gap:8px}.btn{appearance:none;border:0;border-radius:10px;padding:9px 13px;font-weight:800;cursor:pointer;background:#fff;color:#0b1f5b;text-decoration:none}.btn.primary{background:#19a7e0;color:#fff}.print-area{padding:18px;display:grid;gap:18px;justify-content:center}.sheet{width:var(--page-w);height:var(--page-h);background:#fff;display:grid;grid-template-columns:repeat(<?=$cols?>,var(--label-w));grid-auto-rows:var(--label-h);column-gap:var(--gap-x);row-gap:var(--gap-y);padding:var(--mt) var(--mr) var(--mb) var(--ml);align-content:start;justify-content:start;overflow:hidden;box-shadow:0 12px 32px rgba(15,23,42,.18);break-after:page;page-break-after:always}.sheet:last-child{break-after:auto;page-break-after:auto}.label{width:var(--label-w);height:var(--label-h);position:relative;overflow:hidden;border:1px dashed #cbd5e1;background:#fff;break-inside:avoid;page-break-inside:avoid}.field{position:absolute;display:flex;align-items:center;overflow:hidden;line-height:1.05;white-space:normal}.field-company,.field-name{word-break:break-word}.field-price{white-space:nowrap}.field-barcode{padding:0}.field-barcode svg{width:100%;height:100%;display:block}
@page{size:<?=$pageW?>mm <?=$pageH?>mm;margin:0}
@media print{html,body{background:#fff}.toolbar{display:none!important}.print-area{padding:0;display:block}.sheet{box-shadow:none;margin:0}.label{border:0}}
</style></head><body>
<div class="toolbar"><div><strong><?=lei_h($tpl['name'])?></strong><br><span><?=count($labels)?> etiqueta(s) · <?=$cols?> coluna(s) · <?=number_format($lw,1,',','.')?> × <?=number_format($lh,1,',','.')?> mm<?php if($paper==='ROLL'&&$rollMode==='GAP'):?> · avanço <?=number_format($rollPitch,1,',','.')?> mm<?php endif;?></span></div><div class="actions"><button class="btn" type="button" onclick="window.close()">Voltar</button><button class="btn primary" type="button" onclick="window.print()">Imprimir</button></div></div>
<div class="print-area">
<?php foreach($pages as $page):?>
  <div class="sheet"><?php foreach($page as $p)echo lei_render_label($p,$layout,$company,$codeSource,$barcodeSource);?></div>
<?php endforeach;?>
</div>
<script>window.addEventListener('load',()=>{setTimeout(()=>window.print(),250);});</script>
</body></html>

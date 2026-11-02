<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/product_labels_module.php';

$cid=(int)COMPANY_ID;
product_labels_ensure_schema($pdo,$cid);
function lem_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function lem_num($v,$default=0):float{$s=str_replace(',','.',trim((string)$v));return is_numeric($s)?(float)$s:(float)$default;}

$id=(int)($_GET['id']??$_POST['id']??0);
$templates=product_labels_templates($pdo,$cid);
if($id<=0 && $templates)$id=(int)$templates[0]['id'];
$msg='';$err='';

if($_SERVER['REQUEST_METHOD']==='POST'){
  try{
    $action=(string)($_POST['action']??'save');
    if($action==='duplicate'){
      $src=product_labels_template($pdo,$cid,$id);if(!$src)throw new RuntimeException('Modelo não encontrado.');
      $name=trim((string)($_POST['name']??($src['name'].' - cópia')));if($name==='')$name=$src['name'].' - cópia';
      $st=$pdo->prepare('INSERT INTO product_label_templates (company_id,name,paper_type,page_width_mm,page_height_mm,columns_count,label_width_mm,label_height_mm,gap_x_mm,gap_y_mm,margin_left_mm,margin_top_mm,margin_right_mm,margin_bottom_mm,code_source,barcode_source,layout_json,is_default,is_active,created_at) SELECT company_id,?,paper_type,page_width_mm,page_height_mm,columns_count,label_width_mm,label_height_mm,gap_x_mm,gap_y_mm,margin_left_mm,margin_top_mm,margin_right_mm,margin_bottom_mm,code_source,barcode_source,layout_json,0,1,NOW() FROM product_label_templates WHERE company_id=? AND id=?');
      $st->execute([$name,$cid,$id]);$newId=(int)$pdo->lastInsertId();header('Location: relatorio_etiquetas_modelo.php?id='.$newId.'&ok='.rawurlencode('Modelo duplicado.'));exit;
    }
    if($action==='save'){
      $name=trim((string)($_POST['name']??''));if($name==='')throw new RuntimeException('Informe o nome do modelo.');
      $paper=in_array((string)($_POST['paper_type']??''),['ROLL','A4'],true)?(string)$_POST['paper_type']:'ROLL';
      $pageW=max(20,min(400,lem_num($_POST['page_width_mm']??100,100)));
      $pageH=$paper==='A4'?max(20,min(500,lem_num($_POST['page_height_mm']??297,297))):null;
      $cols=max(1,min(10,(int)($_POST['columns_count']??1)));
      $lw=max(5,min(250,lem_num($_POST['label_width_mm']??100,100)));
      $lh=max(5,min(250,lem_num($_POST['label_height_mm']??30,30)));
      $gx=max(0,min(50,lem_num($_POST['gap_x_mm']??0,0)));$gy=max(0,min(50,lem_num($_POST['gap_y_mm']??0,0)));
      $ml=max(0,min(80,lem_num($_POST['margin_left_mm']??0,0)));$mt=max(0,min(80,lem_num($_POST['margin_top_mm']??0,0)));$mr=max(0,min(80,lem_num($_POST['margin_right_mm']??0,0)));$mb=max(0,min(80,lem_num($_POST['margin_bottom_mm']??0,0)));
      $requiredWidth=$ml+$mr+($cols*$lw)+(max(0,$cols-1)*$gx);
      if($requiredWidth>$pageW+0.05)throw new RuntimeException('As etiquetas, margens e espaços ocupam '.number_format($requiredWidth,1,',','.').' mm, acima da largura do papel ('.number_format($pageW,1,',','.').' mm). Ajuste as medidas antes de salvar.');
      $allowedSources=['numero_cadastro','id','codigo_barras','codigo_firebird','codigo_cardapio'];
      $codeSource=in_array((string)($_POST['code_source']??''),$allowedSources,true)?(string)$_POST['code_source']:'numero_cadastro';
      $barcodeSource=in_array((string)($_POST['barcode_source']??''),$allowedSources,true)?(string)$_POST['barcode_source']:'numero_cadastro';
      $layout=product_labels_sanitize_layout((string)($_POST['layout_json']??''));
      $mediaMode=strtoupper(trim((string)($_POST['roll_media_mode']??($layout['_print']['media_mode']??'GAP'))));
      if(!in_array($mediaMode,['GAP','CONTINUOUS'],true))$mediaMode='GAP';
      $pitch=max(0,min(500,lem_num($_POST['roll_pitch_y_mm']??($layout['_print']['pitch_y_mm']??0),0)));
      $layout['_print']=['media_mode'=>$mediaMode,'pitch_y_mm'=>$pitch];
      $st=$pdo->prepare('UPDATE product_label_templates SET name=?,paper_type=?,page_width_mm=?,page_height_mm=?,columns_count=?,label_width_mm=?,label_height_mm=?,gap_x_mm=?,gap_y_mm=?,margin_left_mm=?,margin_top_mm=?,margin_right_mm=?,margin_bottom_mm=?,code_source=?,barcode_source=?,layout_json=?,updated_at=NOW() WHERE company_id=? AND id=?');
      $st->execute([$name,$paper,$pageW,$pageH,$cols,$lw,$lh,$gx,$gy,$ml,$mt,$mr,$mb,$codeSource,$barcodeSource,json_encode($layout,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$cid,$id]);
      $msg='Modelo salvo com sucesso.';
    }
  }catch(Throwable $e){$err=$e->getMessage();}
}
if(isset($_GET['ok']))$msg=(string)$_GET['ok'];
$templates=product_labels_templates($pdo,$cid);$tpl=product_labels_template($pdo,$cid,$id);
if(!$tpl && $templates){$id=(int)$templates[0]['id'];$tpl=$templates[0];}
if(!$tpl)throw new RuntimeException('Nenhum modelo de etiqueta disponível.');
$layout=product_labels_sanitize_layout($tpl['layout_json']??'');
$rollPitch=product_labels_roll_pitch($tpl,$layout);
$rollMode=strtoupper((string)($layout['_print']['media_mode']??'GAP'));
$sourceLabels=['numero_cadastro'=>'Código do produto/cadastro','id'=>'ID interno do produto','codigo_barras'=>'Código de barras cadastrado','codigo_firebird'=>'Código Próspero','codigo_cardapio'=>'Código do cardápio'];
admin_topbar('Editar modelo de etiqueta','relatorio_etiquetas_produtos');
?>
<style>
.lem-page{display:grid;gap:14px}.lem-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap}.lem-head h1{margin:0;color:var(--admin-primary)}.lem-muted{color:#64748b}.lem-grid{display:grid;grid-template-columns:minmax(360px,.78fr) minmax(520px,1.22fr);gap:14px}.lem-card{background:#fff;border:1px solid #dbe4f0;border-radius:18px;padding:16px;box-shadow:0 12px 30px rgba(15,23,42,.04)}.lem-card h2{margin:0 0 4px;color:#0b1f5b}.lem-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}.lem-span2{grid-column:1/-1}.lem-form-grid input,.lem-form-grid select{min-height:42px;border-radius:13px}.lem-section{margin-top:16px;padding-top:14px;border-top:1px solid #e5edf6}.lem-section h3{margin:0 0 8px;color:#0b1f5b}.lem-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.lem-preview-wrap{display:flex;justify-content:center;align-items:flex-start;min-height:520px;background:linear-gradient(135deg,#eef4fb,#f8fbff);border:1px dashed #b9c8db;border-radius:18px;padding:24px;overflow:auto}.lem-label{position:relative;background:#fff;border:2px solid #0b1f5b;box-shadow:0 18px 45px rgba(15,23,42,.15);transform-origin:top left;flex:0 0 auto}.lem-field{position:absolute;border:1px dashed transparent;overflow:hidden;display:flex;align-items:center;padding:1px;box-sizing:border-box;cursor:move;user-select:none;line-height:1.05}.lem-field:hover,.lem-field.selected{border-color:#2563eb;background:rgba(219,234,254,.45)}.lem-field .resize{position:absolute;width:10px;height:10px;right:-1px;bottom:-1px;background:#2563eb;border-radius:2px;cursor:nwse-resize}.lem-field.barcode svg{width:100%;height:100%;display:block}.lem-props{margin-top:12px;border:1px solid #dbe4f0;border-radius:16px;padding:12px;background:#f8fbff}.lem-props-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.lem-props-grid input,.lem-props-grid select{min-height:38px;border-radius:11px}.lem-field-buttons{display:flex;gap:7px;flex-wrap:wrap;margin-top:10px}.lem-field-buttons button.active{background:#0b1f5b;color:#fff}.lem-msg{padding:11px 13px;border-radius:14px;margin-bottom:12px}.lem-msg.ok{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}.lem-msg.err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}.lem-model-switch{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.lem-model-switch select{min-width:250px;min-height:42px;border-radius:13px}@media(max-width:1050px){.lem-grid{grid-template-columns:1fr}.lem-preview-wrap{min-height:450px}}@media(max-width:650px){.lem-form-grid,.lem-props-grid{grid-template-columns:1fr}.lem-span2{grid-column:auto}}
</style>
<div class="lem-page">
  <div class="lem-card">
    <div class="lem-head"><div><h1>Editor de modelo de etiqueta</h1><p class="lem-muted" style="margin:5px 0 0">Arraste os campos na etiqueta. Use o quadrado azul no canto para redimensionar.</p></div><div class="lem-model-switch"><label>Modelo<select id="modelSwitch"><?php foreach($templates as $t):?><option value="<?=(int)$t['id']?>" <?= (int)$t['id']===$id?'selected':''?>><?=lem_h($t['name'])?></option><?php endforeach;?></select></label><a class="btn" href="relatorio_etiquetas_produtos.php">Voltar às etiquetas</a></div></div>
  </div>
  <?php if($msg):?><div class="lem-msg ok"><?=lem_h($msg)?></div><?php endif;?><?php if($err):?><div class="lem-msg err"><?=lem_h($err)?></div><?php endif;?>
  <form method="post" id="templateForm">
    <input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="save"><input type="hidden" name="layout_json" id="layoutJson">
    <div class="lem-grid">
      <div class="lem-card">
        <h2>Configuração do modelo</h2><div class="lem-muted">Medidas em milímetros.</div>
        <div class="lem-form-grid">
          <label class="lem-span2">Nome do modelo<input name="name" value="<?=lem_h($tpl['name'])?>" required></label>
          <label>Tipo de papel<select name="paper_type" id="paperType"><option value="ROLL" <?=$tpl['paper_type']==='ROLL'?'selected':''?>>Bobina / contínuo</option><option value="A4" <?=$tpl['paper_type']==='A4'?'selected':''?>>Folha A4</option></select></label>
          <label>Quantidade de colunas<input type="number" min="1" max="10" name="columns_count" id="columnsCount" value="<?=(int)$tpl['columns_count']?>"></label>
          <label>Largura da etiqueta<input type="number" min="5" step="0.1" name="label_width_mm" id="labelW" value="<?=lem_h($tpl['label_width_mm'])?>"></label>
          <label>Altura da etiqueta<input type="number" min="5" step="0.1" name="label_height_mm" id="labelH" value="<?=lem_h($tpl['label_height_mm'])?>"></label>
          <label>Largura do papel<input type="number" min="20" step="0.1" name="page_width_mm" value="<?=lem_h($tpl['page_width_mm'])?>"></label>
          <label>Altura do papel<input type="number" min="20" step="0.1" name="page_height_mm" id="pageH" value="<?=lem_h($tpl['page_height_mm']??297)?>"></label>
        </div>
        <div class="lem-section"><h3>Margens e espaços</h3><div class="lem-form-grid">
          <label>Margem esquerda<input type="number" step="0.1" min="0" name="margin_left_mm" value="<?=lem_h($tpl['margin_left_mm'])?>"></label><label>Margem direita<input type="number" step="0.1" min="0" name="margin_right_mm" value="<?=lem_h($tpl['margin_right_mm'])?>"></label>
          <label>Margem superior<input type="number" step="0.1" min="0" name="margin_top_mm" value="<?=lem_h($tpl['margin_top_mm'])?>"></label><label>Margem inferior<input type="number" step="0.1" min="0" name="margin_bottom_mm" value="<?=lem_h($tpl['margin_bottom_mm'])?>"></label>
          <label>Espaço horizontal entre etiquetas<input type="number" step="0.1" min="0" name="gap_x_mm" value="<?=lem_h($tpl['gap_x_mm'])?>"></label><label>Gap vertical visual<input type="number" step="0.1" min="0" name="gap_y_mm" value="<?=lem_h($tpl['gap_y_mm'])?>"></label>
          <label class="lem-span2" id="rollMediaModeWrap">Tipo de mídia da bobina<select name="roll_media_mode" id="rollMediaMode"><option value="GAP" <?=$rollMode==='GAP'?'selected':''?>>Etiquetas com GAP / sensor</option><option value="CONTINUOUS" <?=$rollMode==='CONTINUOUS'?'selected':''?>>Mídia contínua</option></select></label>
          <label class="lem-span2" id="rollPitchWrap">Avanço vertical real da mídia (mm)<input type="number" step="0.1" min="5" max="500" name="roll_pitch_y_mm" id="rollPitchY" value="<?=lem_h(number_format($rollPitch,2,'.',''))?>"><small class="lem-muted" style="display:block;margin-top:5px">Meça do início de uma etiqueta ao início da próxima. Ex.: área imprimível 21 mm + gap físico 1 mm = avanço 22 mm. Esse valor evita saltos de linhas na etiquetadora.</small></label>
        </div></div>
        <div class="lem-section"><h3>Códigos</h3><div class="lem-form-grid">
          <label class="lem-span2">Campo Código usa<select name="code_source"><?php foreach($sourceLabels as $v=>$l):?><option value="<?=lem_h($v)?>" <?=$tpl['code_source']===$v?'selected':''?>><?=lem_h($l)?></option><?php endforeach;?></select></label>
          <label class="lem-span2">Código de barras usa<select name="barcode_source"><?php foreach($sourceLabels as $v=>$l):?><option value="<?=lem_h($v)?>" <?=$tpl['barcode_source']===$v?'selected':''?>><?=lem_h($l)?></option><?php endforeach;?></select></label>
        </div></div>
        <div class="lem-actions"><button class="btn btn-primary" type="submit">Salvar modelo</button><button class="btn" type="button" id="duplicateBtn">Duplicar modelo</button></div>
      </div>

      <div class="lem-card">
        <h2>Pré-visualização gráfica</h2><div class="lem-muted">Clique em um campo para editar posição, tamanho, fonte e alinhamento.</div>
        <div class="lem-field-buttons" id="fieldButtons"></div>
        <div class="lem-preview-wrap" id="previewWrap"><div class="lem-label" id="labelPreview"></div></div>
        <div class="lem-props" id="fieldProps"><strong id="propTitle">Campo</strong><div class="lem-props-grid" style="margin-top:8px">
          <label>X (mm)<input type="number" step="0.1" id="propX"></label><label>Y (mm)<input type="number" step="0.1" id="propY"></label><label>Largura<input type="number" step="0.1" id="propW"></label><label>Altura<input type="number" step="0.1" id="propH"></label>
          <label>Fonte (mm)<input type="number" step="0.1" id="propFont"></label><label>Alinhamento<select id="propAlign"><option value="left">Esquerda</option><option value="center">Centro</option><option value="right">Direita</option></select></label><label><span>Visível</span><select id="propVisible"><option value="1">Sim</option><option value="0">Não</option></select></label><label><span>Negrito</span><select id="propBold"><option value="1">Sim</option><option value="0">Não</option></select></label>
        </div><label id="barcodeTextLabel" style="display:none;margin-top:8px">Texto abaixo do código de barras<select id="propShowText"><option value="1">Mostrar</option><option value="0">Ocultar</option></select></label></div>
      </div>
    </div>
  </form>
  <form method="post" id="duplicateForm" style="display:none"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="duplicate"><input type="hidden" name="name" id="duplicateName"></form>
</div>
<script>
(function(){
 const layout=<?=json_encode($layout,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
 const labels={company:'Nome da empresa',name:'Nome do produto',code:'Código',barcode:'Código de barras',price:'Preço'};
 const samples={company:'EMPRESA EXEMPLO',name:'PRODUTO DE EXEMPLO',code:'Cód. 12345',price:'R$ 19,90'};
 const preview=document.getElementById('labelPreview'),wrap=document.getElementById('previewWrap');
 let current='name',scale=7;
 const num=v=>Number(v||0), clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
 function dims(){return {w:Math.max(5,num(document.getElementById('labelW').value)),h:Math.max(5,num(document.getElementById('labelH').value))};}
 function calcScale(){const d=dims(),maxW=Math.max(300,Math.min(720,wrap.clientWidth-50));scale=Math.min(maxW/d.w,440/d.h);scale=Math.max(2,Math.min(13,scale));return scale;}
 function barcodeSvg(){return `<svg viewBox="0 0 120 40" preserveAspectRatio="none"><g fill="#000">${Array.from({length:30},(_,i)=>`<rect x="${i*4}" y="1" width="${i%4===0?2.4:1.4}" height="29"/>`).join('')}</g><text x="60" y="39" text-anchor="middle" font-size="7" font-family="Arial">123456789</text></svg>`;}
 function render(){const d=dims();calcScale();preview.style.width=(d.w*scale)+'px';preview.style.height=(d.h*scale)+'px';preview.innerHTML='';Object.keys(labels).forEach(k=>{const v=layout[k];if(!v)return;const el=document.createElement('div');el.className='lem-field '+(k==='barcode'?'barcode ':'')+(k===current?'selected':'');el.dataset.key=k;el.style.left=(num(v.x)*scale)+'px';el.style.top=(num(v.y)*scale)+'px';el.style.width=(num(v.w)*scale)+'px';el.style.height=(num(v.h)*scale)+'px';el.style.fontSize=(num(v.font)*scale)+'px';el.style.fontWeight=v.bold?'900':'400';el.style.justifyContent=v.align==='center'?'center':(v.align==='right'?'flex-end':'flex-start');el.style.textAlign=v.align||'left';el.style.display=v.visible?'flex':'none';el.innerHTML=(k==='barcode'?barcodeSvg():samples[k])+`<span class="resize"></span>`;preview.appendChild(el);bindDrag(el,k);});updateButtons();loadProps();document.getElementById('pageH').disabled=document.getElementById('paperType').value!=='A4';const roll=document.getElementById('paperType').value==='ROLL';document.getElementById('rollMediaModeWrap').style.display=roll?'grid':'none';document.getElementById('rollPitchWrap').style.display=roll?'grid':'none';}
 function bindDrag(el,key){let mode='',sx=0,sy=0,start={};el.addEventListener('pointerdown',e=>{e.preventDefault();current=key;preview.querySelectorAll('.lem-field').forEach(x=>x.classList.toggle('selected',x===el));updateButtons();loadProps();const r=e.target.classList.contains('resize');mode=r?'resize':'move';sx=e.clientX;sy=e.clientY;start={...layout[key]};el.setPointerCapture(e.pointerId);});el.addEventListener('pointermove',e=>{if(!mode)return;const dx=(e.clientX-sx)/scale,dy=(e.clientY-sy)/scale,d=dims();if(mode==='move'){layout[key].x=clamp(num(start.x)+dx,0,Math.max(0,d.w-num(layout[key].w)));layout[key].y=clamp(num(start.y)+dy,0,Math.max(0,d.h-num(layout[key].h)));}else{layout[key].w=clamp(num(start.w)+dx,.8,Math.max(.8,d.w-num(layout[key].x)));layout[key].h=clamp(num(start.h)+dy,.8,Math.max(.8,d.h-num(layout[key].y)));}paintElement(el,key);loadProps();});el.addEventListener('pointerup',()=>{mode='';syncJson();});el.addEventListener('click',()=>{current=key;render();});}
 function paintElement(el,key){const v=layout[key];el.style.left=(num(v.x)*scale)+'px';el.style.top=(num(v.y)*scale)+'px';el.style.width=(num(v.w)*scale)+'px';el.style.height=(num(v.h)*scale)+'px';}
 function updateButtons(){const box=document.getElementById('fieldButtons');box.innerHTML='';Object.keys(labels).forEach(k=>{const b=document.createElement('button');b.type='button';b.className='btn btn-sm '+(k===current?'active':'');b.textContent=labels[k]+(layout[k].visible?'':' (oculto)');b.addEventListener('click',()=>{current=k;render();});box.appendChild(b);});}
 function loadProps(){const v=layout[current];document.getElementById('propTitle').textContent=labels[current];[['propX','x'],['propY','y'],['propW','w'],['propH','h'],['propFont','font']].forEach(([id,k])=>document.getElementById(id).value=Number(v[k]||0).toFixed(1));document.getElementById('propAlign').value=v.align||'left';document.getElementById('propVisible').value=v.visible?'1':'0';document.getElementById('propBold').value=v.bold?'1':'0';document.getElementById('barcodeTextLabel').style.display=current==='barcode'?'block':'none';if(current==='barcode')document.getElementById('propShowText').value=v.show_text?'1':'0';}
 function saveProps(){const v=layout[current],d=dims();v.x=clamp(num(document.getElementById('propX').value),0,d.w);v.y=clamp(num(document.getElementById('propY').value),0,d.h);v.w=clamp(num(document.getElementById('propW').value),.8,Math.max(.8,d.w-v.x));v.h=clamp(num(document.getElementById('propH').value),.8,Math.max(.8,d.h-v.y));v.font=clamp(num(document.getElementById('propFont').value),1,30);v.align=document.getElementById('propAlign').value;v.visible=document.getElementById('propVisible').value==='1'?1:0;v.bold=document.getElementById('propBold').value==='1'?1:0;if(current==='barcode')v.show_text=document.getElementById('propShowText').value==='1'?1:0;render();syncJson();}
 ['propX','propY','propW','propH','propFont','propAlign','propVisible','propBold','propShowText'].forEach(id=>document.getElementById(id).addEventListener('change',saveProps));
 ['labelW','labelH','paperType'].forEach(id=>document.getElementById(id).addEventListener('change',render));window.addEventListener('resize',render);
 function syncJson(){document.getElementById('layoutJson').value=JSON.stringify(layout);}
 document.getElementById('templateForm').addEventListener('submit',()=>syncJson());document.getElementById('modelSwitch').addEventListener('change',e=>location.href='relatorio_etiquetas_modelo.php?id='+encodeURIComponent(e.target.value));
 document.getElementById('duplicateBtn').addEventListener('click',()=>{const n=prompt('Nome do novo modelo:',<?=json_encode((string)$tpl['name'].' - cópia',JSON_UNESCAPED_UNICODE)?>);if(!n)return;document.getElementById('duplicateName').value=n;document.getElementById('duplicateForm').submit();});
 syncJson();render();
})();
</script>
<?php admin_footer(); ?>

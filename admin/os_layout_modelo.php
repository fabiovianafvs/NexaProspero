<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/os_module.php';
require_once __DIR__ . '/../config/os_print_layout.php';
require_once __DIR__ . '/_os_ui.php';

$pdo=os_db();$companyId=os_company_id();os_require_enabled($pdo,$companyId);os_ensure_defaults($pdo,$companyId);
function olm_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$msg=trim((string)($_GET['ok']??''));$err='';
$layout=os_print_dual_layout_load($pdo,$companyId);
if($_SERVER['REQUEST_METHOD']==='POST'){
  try{
    $action=(string)($_POST['action']??'save');
    if($action==='reset'){
      os_print_dual_layout_save($pdo,$companyId,os_print_dual_layout_default());
      header('Location: os_layout_modelo.php?ok='.rawurlencode('Layout restaurado para o modelo padrão.'));exit;
    }
    $raw=json_decode((string)($_POST['layout_json']??''),true);if(!is_array($raw))$raw=$layout;
    foreach(array_keys($layout['texts']) as $key)$raw['texts'][$key]=(string)($_POST['text_'.$key]??$layout['texts'][$key]);
    $raw['options']['blank_item_rows']=(int)($_POST['blank_item_rows']??$layout['options']['blank_item_rows']);
    $paymentText=(string)($_POST['payment_options']??'');$raw['options']['payment_options']=array_values(array_filter(array_map('trim',preg_split('/\R/u',$paymentText)?:[]),static fn($v)=>$v!==''));
    os_print_dual_layout_save($pdo,$companyId,$raw);
    header('Location: os_layout_modelo.php?ok='.rawurlencode('Layout da O.S. salvo.'));exit;
  }catch(Throwable $e){$err=$e->getMessage();}
  $layout=os_print_dual_layout_load($pdo,$companyId);
}
$labels=os_print_dual_block_labels();
os_page_start('Editor do layout A4 paisagem - 2 vias','config');
?>
<style>
.olm-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(320px,.6fr);gap:16px;align-items:start}.olm-card{background:#fff;border:1px solid #dbe4f0;border-radius:20px;padding:16px;box-shadow:0 10px 25px rgba(11,31,91,.05)}.olm-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}.olm-toolbar .spacer{flex:1}.olm-preview-wrap{overflow:auto;background:#e7ebf1;border:1px solid #cbd5e1;border-radius:16px;padding:18px;min-height:620px}.olm-page{position:relative;background:#fff;box-shadow:0 8px 30px rgba(15,23,42,.18);transform-origin:top left;user-select:none}.olm-copy{position:absolute;border:1px dashed #94a3b8;background:#fff;overflow:hidden}.olm-block{position:absolute;border:1px solid #111;background:rgba(255,255,255,.98);overflow:hidden;cursor:move;padding:1px;color:#111;line-height:1.08}.olm-block.selected{outline:2px solid #2454D6;z-index:20}.olm-block .title{background:#d9d9d9;font-weight:900;padding:1px 2px;border-bottom:1px solid #111}.olm-block .resize{position:absolute;right:0;bottom:0;width:10px;height:10px;background:#2454D6;cursor:nwse-resize}.olm-block.logo{display:flex;align-items:center;justify-content:center;font-weight:900;color:#64748b}.olm-mini-row{display:flex;border-bottom:1px solid #bbb}.olm-mini-row span{padding:1px 2px;flex:1;border-right:1px solid #bbb}.olm-mini-row span:last-child{border-right:0}.olm-mini-table{display:grid;grid-template-columns:18% 44% 10% 14% 14%}.olm-mini-table span{border-right:1px solid #bbb;border-bottom:1px solid #bbb;padding:1px}.olm-mini-table span:nth-child(5n){border-right:0}.olm-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.olm-fields .wide{grid-column:1/-1}.olm-props{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.olm-block-list{display:flex;gap:6px;flex-wrap:wrap;margin:10px 0}.olm-block-list button.active{background:#eff6ff;border-color:#2454D6;color:#0B1F5B}.olm-muted{font-size:12px;color:#64748b;line-height:1.45}.olm-side{position:sticky;top:12px}.olm-divider{border-top:1px solid #e2e8f0;margin:14px 0}.olm-texts textarea{min-height:72px}.olm-texts input,.olm-texts textarea{font-size:13px}@media(max-width:1050px){.olm-grid{grid-template-columns:1fr}.olm-side{position:static}}@media(max-width:650px){.olm-fields,.olm-props{grid-template-columns:1fr}}
</style>
<div class="os-module"><div class="os-wrap">
<div class="os-title-row"><h1>Editor do layout A4 paisagem - 2 vias</h1><span class="spacer"></span><a class="btn btn-outline" href="os_configuracoes.php?tab=geral">Voltar às configurações</a><a class="btn btn-secondary" target="_blank" href="os_monitor.php">Abrir O.S.</a></div>
<?php if($msg!==''):?><div class="alert alert-ok"><?=olm_h($msg)?></div><?php endif;?><?php if($err!==''):?><div class="alert alert-error"><?=olm_h($err)?></div><?php endif;?>
<div class="alert" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af"><b>Como funciona:</b> o editor representa <b>uma via</b>. A impressão replica automaticamente o mesmo layout duas vezes, lado a lado, em A4 paisagem. Arraste os blocos, use o canto azul para redimensionar e ajuste as medidas em milímetros.</div>
<form method="post" id="olm-form"><input type="hidden" name="action" value="save"><input type="hidden" name="layout_json" id="olm-json">
<div class="olm-grid">
  <div class="olm-card">
    <div class="olm-toolbar"><strong>Pré-visualização gráfica</strong><span class="olm-muted">A4 paisagem · duas vias idênticas</span><span class="spacer"></span><button class="btn btn-primary" type="submit">Salvar layout</button></div>
    <div class="olm-block-list" id="olm-block-buttons"></div>
    <div class="olm-preview-wrap" id="olm-preview-wrap"><div class="olm-page" id="olm-page"><div class="olm-copy" id="olm-copy-1"></div><div class="olm-copy" id="olm-copy-2"></div></div></div>
  </div>
  <div class="olm-side">
    <div class="olm-card">
      <h3 style="margin-top:0;color:#0B1F5B" id="olm-prop-title">Bloco</h3>
      <div class="olm-props">
        <label>X (mm)<input type="number" step="0.1" id="olm-x"></label><label>Y (mm)<input type="number" step="0.1" id="olm-y"></label>
        <label>Largura (mm)<input type="number" step="0.1" id="olm-w"></label><label>Altura (mm)<input type="number" step="0.1" id="olm-h"></label>
        <label>Fonte (pt)<input type="number" step="0.1" id="olm-font"></label><label>Visível<select id="olm-visible"><option value="1">Sim</option><option value="0">Não</option></select></label>
      </div>
      <div class="olm-divider"></div>
      <div class="olm-muted">O bloco <b>Logo da empresa</b> usa automaticamente a logo cadastrada nas Configurações Gerais do Nexa. O editor não grava uma imagem separada para a O.S.</div>
    </div>
    <div class="olm-card olm-texts" style="margin-top:14px">
      <h3 style="margin-top:0;color:#0B1F5B">Textos do modelo</h3>
      <div class="olm-fields">
        <label class="wide">Título do documento<input name="text_document_title" value="<?=olm_h($layout['texts']['document_title'])?>"></label>
        <label class="wide">Aviso não fiscal<input name="text_warning" value="<?=olm_h($layout['texts']['warning'])?>"></label>
        <label>Título cliente<input name="text_customer_title" value="<?=olm_h($layout['texts']['customer_title'])?>"></label>
        <label>Título dados O.S.<input name="text_os_title" value="<?=olm_h($layout['texts']['os_title'])?>"></label>
        <label class="wide">Título ocorrência<input name="text_occurrence_title" value="<?=olm_h($layout['texts']['occurrence_title'])?>"></label>
        <label>Título pagamento<input name="text_payment_title" value="<?=olm_h($layout['texts']['payment_title'])?>"></label>
        <label>Executado por<input name="text_executed_by" value="<?=olm_h($layout['texts']['executed_by'])?>"></label>
        <label class="wide">Texto da assinatura<input name="text_signature" value="<?=olm_h($layout['texts']['signature'])?>"></label>
        <label class="wide">Observação de pagamento<textarea name="text_payment_note"><?=olm_h($layout['texts']['payment_note'])?></textarea></label>
        <label>Linhas vazias na grade<input type="number" min="0" max="20" name="blank_item_rows" value="<?=(int)$layout['options']['blank_item_rows']?>"></label>
        <label class="wide">Opções padrão de pagamento <span class="olm-muted">(uma por linha, usadas quando a O.S. não possui condições lançadas)</span><textarea name="payment_options"><?=olm_h(implode("\n",(array)$layout['options']['payment_options']))?></textarea></label>
      </div>
    </div>
    <div class="olm-card" style="margin-top:14px"><div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-primary" type="submit">Salvar layout</button><button class="btn btn-danger" type="submit" name="action" value="reset" onclick="return confirm('Restaurar o layout padrão de duas vias?')">Restaurar padrão</button></div></div>
  </div>
</div></form>
</div></div>
<script>
(function(){
 const layout=<?=json_encode($layout,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
 const labels=<?=json_encode($labels,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
 const page=document.getElementById('olm-page'),c1=document.getElementById('olm-copy-1'),c2=document.getElementById('olm-copy-2'),wrap=document.getElementById('olm-preview-wrap');
 let current='customer',scale=3.05;
 const n=v=>Number(v||0),clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
 const samples={
  logo:'LOGO DA EMPRESA',heading:'REQUISIÇÃO ORÇAMENTO Nº O.S.: 000001',company:'EMPRESA EXEMPLO LTDA<br>Endereço: Rua Exemplo, 100<br>Bairro: Centro &nbsp; Cidade/MG<br>Telefone: (31) 3333-3333',warning:'NÃO É DOCUMENTO FISCAL. NÃO É VÁLIDO COMO GARANTIA DE MERCADORIA',
  customer:'<div class="title">Dados do Cliente / Execução</div><div class="olm-mini-row"><span>Cliente: CLIENTE EXEMPLO</span><span>Contato:</span></div><div class="olm-mini-row"><span>Endereço: Rua do Cliente, 50</span><span>Telefone: 31999999999</span></div><div class="olm-mini-row"><span>Bairro: Centro &nbsp; CEP: 35900-000</span><span>CPF/CNPJ: 00.000.000/0001-00</span></div><div class="olm-mini-row"><span>Email: cliente@exemplo.com</span><span>Cidade: Itabira &nbsp; UF: MG</span></div>',
  os:'<div class="title">Dados OS</div><div class="olm-mini-row"><span>Data/Hora da Abertura: 14/10/2026 09:40</span><span>Usuário Abertura: ADMIN</span><span>Status OS: ABERTO</span></div><div class="olm-mini-row"><span>Modalidade: ( ) Executado ( ) Não Autorizado</span><span>Agendamento: 15/10/2026 às 08:00</span></div>',
  occurrence:'<div class="title">Ocorrência (Solicitação do Cliente)</div><b>SOLICITAÇÃO INFORMADA PELO CLIENTE</b>',execution:'Hora de Chegada: ___:___ &nbsp; Hora de Saída: ___:___ &nbsp; Data da Execução: ___/___/____',
  items:'<div class="olm-mini-table"><span>Código</span><span>Produto</span><span>Qtde</span><span>Vlr Unit</span><span>Vlr Total</span>'+Array.from({length:8},()=>'<span>&nbsp;</span><span>&nbsp;</span><span>&nbsp;</span><span>&nbsp;</span><span>&nbsp;</span>').join('')+'</div>',
  payments:'<b>Formas de Pagamento:</b> □ 7 a 10 dias à vista &nbsp; □ 15 dias c/ acréscimo 5% &nbsp; <b>Vlr Total: R$ 0,00</b><br>□ 20 dias c/ acréscimo 7% &nbsp; □ 30 dias c/ acréscimo 10%<br><small>A escolha da forma de pagamento já autoriza a emissão da Nota Fiscal...</small>',executed_by:'Executado por: ______________________________________________',signature:'ASSINATURA DO CLIENTE POR EXTENSO/ AUTORIZAÇÃO'
 };
 function calcScale(){const available=Math.max(720,wrap.clientWidth-42);scale=Math.min(3.25,available/n(layout.page.width));scale=Math.max(2.2,scale);}
 function createBlock(key,interactive){const v=layout.blocks[key],el=document.createElement('div');el.className='olm-block '+key+(interactive&&key===current?' selected':'');el.dataset.key=key;el.style.left=(n(v.x)*scale)+'px';el.style.top=(n(v.y)*scale)+'px';el.style.width=(n(v.w)*scale)+'px';el.style.height=(n(v.h)*scale)+'px';el.style.fontSize=(n(v.font)*1.333)+'px';el.style.display=v.visible?'block':'none';el.innerHTML=samples[key]||labels[key];if(interactive){el.innerHTML+='<span class="resize"></span>';bindDrag(el,key);}return el;}
 function render(){calcScale();page.style.width=(n(layout.page.width)*scale)+'px';page.style.height=(n(layout.page.height)*scale)+'px';[c1,c2].forEach((c,i)=>{c.innerHTML='';c.style.left=((n(layout.page.margin_x)+(i*(n(layout.page.copy_width)+n(layout.page.gap))))*scale)+'px';c.style.top=(n(layout.page.margin_y)*scale)+'px';c.style.width=(n(layout.page.copy_width)*scale)+'px';c.style.height=(n(layout.page.copy_height)*scale)+'px';Object.keys(layout.blocks).forEach(k=>c.appendChild(createBlock(k,i===0)));});buttons();props();sync();}
 function bindDrag(el,key){let mode='',sx=0,sy=0,start={};el.addEventListener('pointerdown',e=>{e.preventDefault();current=key;mode=e.target.classList.contains('resize')?'resize':'move';sx=e.clientX;sy=e.clientY;start={...layout.blocks[key]};el.setPointerCapture(e.pointerId);c1.querySelectorAll('.olm-block').forEach(x=>x.classList.toggle('selected',x===el));buttons();props();});el.addEventListener('pointermove',e=>{if(!mode)return;const dx=(e.clientX-sx)/scale,dy=(e.clientY-sy)/scale,v=layout.blocks[key],cw=n(layout.page.copy_width),ch=n(layout.page.copy_height);if(mode==='move'){v.x=clamp(n(start.x)+dx,0,Math.max(0,cw-n(v.w)));v.y=clamp(n(start.y)+dy,0,Math.max(0,ch-n(v.h)));}else{v.w=clamp(n(start.w)+dx,1,Math.max(1,cw-n(v.x)));v.h=clamp(n(start.h)+dy,1,Math.max(1,ch-n(v.y)));}paint(el,key);props();sync();});el.addEventListener('pointerup',()=>{mode='';render();});el.addEventListener('click',()=>{current=key;render();});}
 function paint(el,key){const v=layout.blocks[key];el.style.left=(n(v.x)*scale)+'px';el.style.top=(n(v.y)*scale)+'px';el.style.width=(n(v.w)*scale)+'px';el.style.height=(n(v.h)*scale)+'px';}
 function buttons(){const box=document.getElementById('olm-block-buttons');box.innerHTML='';Object.keys(labels).forEach(k=>{const b=document.createElement('button');b.type='button';b.className='btn btn-sm '+(k===current?'active':'');b.textContent=labels[k]+(layout.blocks[k].visible?'':' (oculto)');b.onclick=()=>{current=k;render();};box.appendChild(b);});}
 function props(){const v=layout.blocks[current];document.getElementById('olm-prop-title').textContent=labels[current];[['olm-x','x'],['olm-y','y'],['olm-w','w'],['olm-h','h'],['olm-font','font']].forEach(([id,k])=>document.getElementById(id).value=n(v[k]).toFixed(1));document.getElementById('olm-visible').value=v.visible?'1':'0';}
 function saveProps(){const v=layout.blocks[current],cw=n(layout.page.copy_width),ch=n(layout.page.copy_height);v.x=clamp(n(document.getElementById('olm-x').value),0,cw-1);v.y=clamp(n(document.getElementById('olm-y').value),0,ch-1);v.w=clamp(n(document.getElementById('olm-w').value),1,cw-v.x);v.h=clamp(n(document.getElementById('olm-h').value),1,ch-v.y);v.font=clamp(n(document.getElementById('olm-font').value),3.5,14);v.visible=document.getElementById('olm-visible').value==='1'?1:0;render();}
 ['olm-x','olm-y','olm-w','olm-h','olm-font','olm-visible'].forEach(id=>document.getElementById(id).addEventListener('change',saveProps));
 function sync(){document.getElementById('olm-json').value=JSON.stringify(layout);}
 document.getElementById('olm-form').addEventListener('submit',sync);window.addEventListener('resize',render);render();
})();
</script>
<?php os_page_end(); ?>

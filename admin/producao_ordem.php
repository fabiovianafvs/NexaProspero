<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/producao_module.php';
require_once __DIR__ . '/_producao_ui.php';

$companyId=(int)COMPANY_ID;
if(!production_enabled($pdo,$companyId)) saas_require_company_module($pdo,$companyId,'ordem_producao','Ordem de Produção');
if(!production_ensure_schema($pdo)) throw new RuntimeException('Não foi possível preparar a estrutura da Ordem de Produção.');

$id=(int)($_POST['id']??($_GET['id']??0));$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  try{
    $action=(string)($_POST['action']??'');
    if($action==='create'){
      $finished=production_normalize_finished_lines((array)($_POST['finished_product_id']??[]),(array)($_POST['finished_qty']??[]));
      $variations=(array)($_POST['variation']??[]);
      $res=production_create_order($pdo,$companyId,(int)(auth_user_id()??0),$finished,$variations,(string)($_POST['observacao']??''),(string)($_POST['variacao_observacao']??''));
      $msg=$res['status']==='FINALIZADA'?'O.P. gerada e finalizada. Matérias-primas baixadas e produtos acabados lançados no estoque.':'O.P. gerada. Matérias-primas baixadas e ordem enviada para produção.';
      header('Location: producao_ordem.php?id='.(int)$res['id'].'&ok='.rawurlencode($msg));exit;
    }
    if($action==='finalize'&&$id>0){
      production_finalize_order($pdo,$companyId,$id);
      header('Location: producao_ordem.php?id='.$id.'&ok='.rawurlencode('Produção finalizada e produtos acabados lançados no estoque.'));exit;
    }
    if($action==='cancel'&&$id>0){
      production_cancel_process_order($pdo,$companyId,$id);
      header('Location: producao_ordem.php?id='.$id.'&ok='.rawurlencode('O.P. cancelada e matérias-primas devolvidas ao estoque.'));exit;
    }
  }catch(Throwable $e){$error=$e->getMessage();}
}

$order=$id>0?production_order_load($pdo,$companyId,$id):null;
if($id>0&&!$order){http_response_code(404);$error=$error!==''?$error:'O.P. não encontrada.';}
$settings=production_settings($pdo,$companyId);
$products=$id<=0?production_finished_products($pdo,$companyId,true):[];
$recipes=[];
if($id<=0){
  foreach($products as $p){
    $recipe=production_recipe_load($pdo,$companyId,(int)$p['id']);
    $recipes[(int)$p['id']]=[
      'id'=>(int)$p['id'],'name'=>(string)$p['nome'],'unit'=>(string)$p['unidade_estoque'],'stock'=>(float)$p['estoque_atual'],
      'recipe'=>array_map(static fn($r)=>['id'=>(int)$r['materia_prima_id'],'name'=>(string)$r['materia_prima_nome'],'unit'=>(string)$r['unidade_estoque'],'qty'=>(float)$r['quantidade'],'stock'=>(float)$r['estoque_atual']],$recipe),
    ];
  }
}

production_page_start($id>0?'Ordem de Produção':'Nova Ordem de Produção','producao_monitor');
production_page_header($id>0?'Ordem de Produção':'Nova Ordem de Produção',$id>0?'Consulte produtos fabricados, matérias-primas consumidas e movimentações da ordem.':'Informe os produtos acabados e quantidades. O Nexa calcula as matérias-primas, permite registrar variação/perda e valida o estoque antes de gerar.',[
  ['label'=>'Voltar ao Monitor','href'=>'producao_monitor.php','class'=>'btn']
]);
if($error!=='')echo '<div class="msg err">'.production_h($error).'</div>';
if(!empty($_GET['ok']))echo '<div class="msg ok">'.production_h($_GET['ok']).'</div>';

if($id>0&&$order):
  $op=str_pad((string)$order['numero_op'],6,'0',STR_PAD_LEFT);
?>
<div class="pr-card">
  <div class="pr-card-head">
    <div><h2>O.P. #<?= production_h($op) ?></h2><div class="pr-muted">Gerada em <?= production_h(date('d/m/Y H:i',strtotime((string)$order['created_at']))) ?><?= !empty($order['created_by_name'])?' por '.production_h($order['created_by_name']):'' ?>.</div></div>
    <div><?= production_status_badge((string)$order['status']) ?></div>
  </div>
  <div class="pr-grid-3">
    <div class="pr-kpi"><small>Modo da O.P.</small><strong style="font-size:17px"><?= !empty($order['order_by_sector_snapshot'])?'Por setor':'Automática' ?></strong></div>
    <div class="pr-kpi"><small>Início</small><strong style="font-size:17px"><?= !empty($order['started_at'])?production_h(date('d/m/Y H:i',strtotime((string)$order['started_at']))):'—' ?></strong></div>
    <div class="pr-kpi"><small>Finalização</small><strong style="font-size:17px"><?= !empty($order['finished_at'])?production_h(date('d/m/Y H:i',strtotime((string)$order['finished_at']))):'—' ?></strong></div>
  </div>
</div>

<div class="pr-card"><div class="pr-card-head"><div><h2>Produtos acabados</h2><div class="pr-muted">Quantidades planejadas e efetivamente lançadas no estoque.</div></div></div>
<div class="pr-table-wrap"><table class="pr-table"><thead><tr><th>Produto</th><th class="pr-num">Planejado</th><th class="pr-num">Produzido</th><th class="pr-num">Estoque anterior</th><th class="pr-num">Estoque posterior</th></tr></thead><tbody>
<?php foreach($order['items'] as $r): ?><tr><td><b><?= production_h($r['produto_nome']) ?></b></td><td class="pr-num"><?= production_h(production_qty_label($r['quantidade_planejada'],4)) ?> <?= production_h($r['unidade_estoque']) ?></td><td class="pr-num"><?= production_h(production_qty_label($r['quantidade_produzida'],4)) ?> <?= production_h($r['unidade_estoque']) ?></td><td class="pr-num"><?= $r['estoque_anterior']===null?'—':production_h(production_qty_label($r['estoque_anterior'],4)) ?></td><td class="pr-num"><?= $r['estoque_posterior']===null?'—':production_h(production_qty_label($r['estoque_posterior'],4)) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>

<div class="pr-card"><div class="pr-card-head"><div><h2>Matérias-primas</h2><div class="pr-muted">A variação é positiva quando houve consumo extra/perda e negativa quando houve economia.</div></div></div>
<div class="pr-table-wrap"><table class="pr-table"><thead><tr><th>Matéria-prima</th><th class="pr-num">Teórico</th><th class="pr-num">Variação</th><th class="pr-num">Consumido</th><th class="pr-num">Estoque anterior</th><th class="pr-num">Estoque posterior</th></tr></thead><tbody>
<?php foreach($order['materials'] as $r): $var=(float)$r['variacao']; ?><tr><td><b><?= production_h($r['materia_nome']) ?></b></td><td class="pr-num"><?= production_h(production_qty_label($r['quantidade_teorica'],6)) ?> <?= production_h($r['unidade_estoque']) ?></td><td class="pr-num <?= $var>0?'pr-bad':($var<0?'pr-ok':'') ?>"><?= $var>0?'+':'' ?><?= production_h(production_qty_label($var,6)) ?></td><td class="pr-num"><b><?= production_h(production_qty_label($r['quantidade_consumida'],6)) ?> <?= production_h($r['unidade_estoque']) ?></b></td><td class="pr-num"><?= production_h(production_qty_label($r['estoque_anterior'],4)) ?></td><td class="pr-num"><?= production_h(production_qty_label($r['estoque_posterior'],4)) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>

<?php if(!empty($order['observacao'])||!empty($order['variacao_observacao'])): ?><div class="pr-card"><h2>Observações</h2><?php if(!empty($order['observacao'])):?><p style="white-space:pre-wrap"><?= production_h($order['observacao']) ?></p><?php endif; ?><?php if(!empty($order['variacao_observacao'])):?><div class="pr-info" style="margin-bottom:0"><b>Variação/perda:</b> <?= production_h($order['variacao_observacao']) ?></div><?php endif; ?></div><?php endif; ?>

<?php if((string)$order['status']==='EM_PROCESSO'): ?>
<div class="pr-card"><div class="pr-card-head"><div><h2>Produção em processo</h2><div class="pr-muted">As matérias-primas já foram baixadas. Ao finalizar, o Nexa lançará os produtos acabados no estoque.</div></div></div><div class="pr-actions-inline">
  <form method="post" onsubmit="return confirm('Finalizar esta produção e lançar os produtos acabados no estoque?')"><input type="hidden" name="action" value="finalize"><input type="hidden" name="id" value="<?= (int)$id ?>"><button class="btn btn-primary" type="submit">Finalizar produção</button></form>
  <form method="post" onsubmit="return confirm('Cancelar esta O.P. e devolver as matérias-primas ao estoque?')"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$id ?>"><button class="btn" type="submit">Cancelar O.P.</button></form>
</div></div>
<?php endif; ?>

<?php else: ?>
<form method="post" id="productionOrderForm">
<input type="hidden" name="action" value="create">
<div class="pr-card">
  <div class="pr-card-head"><div><h2>Produtos a fabricar</h2><div class="pr-muted">Você pode lançar vários produtos na mesma ordem. Cada produto precisa ter sua Ficha Técnica cadastrada.</div></div><button class="btn" type="button" id="addFinishedRow">+ Produto</button></div>
  <?php if(!$products): ?><div class="pr-warning">Nenhum produto ativo está marcado como <b>Possui Produção</b>. Cadastre ou edite um produto antes de gerar uma O.P.</div><?php endif; ?>
  <div class="pr-table-wrap"><table class="pr-table"><thead><tr><th>Produto acabado</th><th>Quantidade</th><th>Estoque atual</th><th>Ficha técnica</th><th></th></tr></thead><tbody id="finishedRows"><tr data-finished-row><td><select name="finished_product_id[]" data-finished-product><option value="">Selecione...</option><?php foreach($products as $p): ?><option value="<?= (int)$p['id'] ?>" data-meta="Estoque: <?= production_h(production_qty_label($p['estoque_atual'],4)) ?> <?= production_h($p['unidade_estoque']) ?>"><?= production_h($p['nome']) ?></option><?php endforeach; ?></select></td><td><div class="pr-production-qty-line"><input class="pr-production-qty-input" name="finished_qty[]" data-finished-qty inputmode="decimal" placeholder="Ex.: 100"><span class="pr-production-capacity" data-finished-capacity>Pode produzir: —</span></div></td><td data-finished-stock>—</td><td data-finished-recipe>—</td><td><button class="btn btn-sm" type="button" data-remove-finished>Remover</button></td></tr></tbody></table></div>
</div>

<div class="pr-card">
  <div class="pr-card-head"><div><h2>Matérias-primas necessárias</h2><div class="pr-muted">O cálculo considera a soma de todos os produtos desta O.P. Informe a variação somente quando houver consumo real maior/menor ou perda.</div></div></div>
  <div id="materialsWarning" class="pr-warning" hidden></div>
  <div class="pr-table-wrap"><table class="pr-table"><thead><tr><th>Matéria-prima</th><th class="pr-num">Teórico</th><th>Variação (+ / −)</th><th class="pr-num">Consumo final</th><th class="pr-num">Estoque</th><th>Situação</th></tr></thead><tbody id="materialRows"><tr><td colspan="6" class="pr-empty">Inclua os produtos e quantidades para calcular.</td></tr></tbody></table></div>
</div>

<div class="pr-card">
  <div class="pr-card-head"><div><h2>Informações da O.P.</h2><div class="pr-muted">Modo atual: <b><?= !empty($settings['order_by_sector'])?'Por setor':'Automática' ?></b>.</div></div></div>
  <div class="pr-grid">
    <div class="pr-row"><label>Observação da O.P.</label><textarea name="observacao" placeholder="Opcional"></textarea></div>
    <div class="pr-row"><label>Motivo da variação / perda</label><textarea name="variacao_observacao" placeholder="Ex.: perda no preparo, sobra aproveitada, consumo real acima da ficha..."></textarea><div class="pr-help">Use este campo quando informar variações nas matérias-primas.</div></div>
  </div>
  <div class="pr-info" style="margin-top:12px"><?php if(!empty($settings['order_by_sector'])): ?><b>Ordem por setor:</b> ao gerar, as matérias-primas serão baixadas agora e a O.P. ficará Em processo. Os acabados entrarão no estoque ao finalizar.<?php else: ?><b>Ordem automática:</b> ao gerar, as matérias-primas serão baixadas e os produtos acabados entrarão no estoque na mesma operação.<?php endif; ?></div>
  <div style="display:flex;justify-content:flex-end;margin-top:14px"><button class="btn btn-primary" type="submit" id="generateOrder" disabled>Gerar O.P.</button></div>
</div>
</form>

<?php require_once __DIR__ . '/_buscar_f8.php'; nexa_buscar_f8_assets(); ?>
<style>
.pr-production-qty-line{display:flex;align-items:center;gap:9px;min-width:290px}.pr-production-qty-input{width:125px!important;min-width:125px;flex:0 0 125px}.pr-production-capacity{font-size:11px;font-weight:900;color:#047857;white-space:nowrap}.pr-production-capacity.is-over{color:#b91c1c}@media(max-width:720px){.pr-production-qty-line{min-width:250px;align-items:flex-start;flex-direction:column}.pr-production-qty-input{width:145px!important;flex-basis:auto}}
</style>
<template id="finishedRowTemplate"><tr data-finished-row><td><select name="finished_product_id[]" data-finished-product><option value="">Selecione...</option><?php foreach($products as $p): ?><option value="<?= (int)$p['id'] ?>" data-meta="Estoque: <?= production_h(production_qty_label($p['estoque_atual'],4)) ?> <?= production_h($p['unidade_estoque']) ?>"><?= production_h($p['nome']) ?></option><?php endforeach; ?></select></td><td><div class="pr-production-qty-line"><input class="pr-production-qty-input" name="finished_qty[]" data-finished-qty inputmode="decimal" placeholder="Ex.: 100"><span class="pr-production-capacity" data-finished-capacity>Pode produzir: —</span></div></td><td data-finished-stock>—</td><td data-finished-recipe>—</td><td><button class="btn btn-sm" type="button" data-remove-finished>Remover</button></td></tr></template>
<script>
(function(){
 const recipes=<?= json_encode($recipes,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
 const body=document.getElementById('finishedRows'),matBody=document.getElementById('materialRows'),add=document.getElementById('addFinishedRow'),tpl=document.getElementById('finishedRowTemplate'),btn=document.getElementById('generateOrder'),warn=document.getElementById('materialsWarning');
 const variations={};
 function num(v){v=String(v??'').trim().replace(/\s/g,'');if(!v)return 0;if(v.includes(',')&&v.includes('.'))v=v.replace(/\./g,'');v=v.replace(',','.');const n=Number(v);return Number.isFinite(n)?n:0;}
 function fmt(v,d=6){const n=Number(v||0);return n.toLocaleString('pt-BR',{maximumFractionDigits:d});}
 function collect(){
   const agg={},errors=[];let hasProduct=false;
   body.querySelectorAll('[data-finished-row]').forEach(row=>{
     const sel=row.querySelector('[data-finished-product]'),qtyEl=row.querySelector('[data-finished-qty]'),stockEl=row.querySelector('[data-finished-stock]'),recipeEl=row.querySelector('[data-finished-recipe]'),capacityEl=row.querySelector('[data-finished-capacity]');
     const pid=sel.value,qty=num(qtyEl.value),p=recipes[pid];
     stockEl.textContent=p?fmt(p.stock,4)+' '+p.unit:'—';
     recipeEl.textContent=p?(p.recipe.length?p.recipe.length+' matéria(s)-prima(s)':'Sem ficha'):'—';
     let capacity=null;
     if(p&&p.recipe.length){p.recipe.forEach(m=>{const use=Number(m.qty||0),available=Math.max(0,Number(m.stock||0));if(use>0){const possible=available/use;capacity=capacity===null?possible:Math.min(capacity,possible);}});}
     if(capacityEl){capacityEl.textContent=p&&p.recipe.length&&capacity!==null?'Pode produzir: '+fmt(Math.max(0,capacity),4)+' '+p.unit:'Pode produzir: —';capacityEl.classList.toggle('is-over',capacity!==null&&qty>capacity+0.000005);}
     if(!pid&&!qty)return;
     hasProduct=true;
     if(!p){errors.push('Selecione um produto válido.');return;}
     if(qty<=0){errors.push('Informe uma quantidade maior que zero para '+p.name+'.');return;}
     if(!p.recipe.length){errors.push(p.name+' não possui ficha técnica.');return;}
     p.recipe.forEach(m=>{if(!agg[m.id])agg[m.id]={id:m.id,name:m.name,unit:m.unit,stock:Number(m.stock||0),theoretical:0};agg[m.id].theoretical+=Number(m.qty||0)*qty;});
   });
   return {agg,errors,hasProduct};
 }
 function render(){
   const c=collect(),keys=Object.keys(c.agg);matBody.innerHTML='';let enough=true;
   if(!keys.length){matBody.innerHTML='<tr><td colspan="6" class="pr-empty">Inclua os produtos e quantidades para calcular.</td></tr>';enough=false;}
   keys.sort((a,b)=>String(c.agg[a].name).localeCompare(String(c.agg[b].name),'pt-BR')).forEach(k=>{
     const m=c.agg[k],variation=Number(variations[k]||0),actual=Math.max(0,m.theoretical+variation),ok=(m.stock+0.000005)>=actual;
     if(!ok)enough=false;
     const tr=document.createElement('tr');
     tr.innerHTML='<td><b></b></td><td class="pr-num"></td><td><input inputmode="decimal" name="variation['+m.id+']" data-variation="'+m.id+'" placeholder="0"></td><td class="pr-num"><b></b></td><td class="pr-num"></td><td></td>';
     tr.children[0].querySelector('b').textContent=m.name;
     tr.children[1].textContent=fmt(m.theoretical,6)+' '+m.unit;
     const inp=tr.querySelector('[data-variation]');inp.value=variation?String(variation).replace('.',','):'';
     tr.children[3].querySelector('b').textContent=fmt(actual,6)+' '+m.unit;
     tr.children[4].textContent=fmt(m.stock,4)+' '+m.unit;
     tr.children[5].innerHTML=ok?'<span class="pr-ok">Estoque disponível</span>':'<span class="pr-bad">Estoque insuficiente</span>';
     inp.addEventListener('change',function(){variations[k]=num(this.value);render();});
     matBody.appendChild(tr);
   });
   const msgs=[...new Set(c.errors)];
   warn.hidden=!msgs.length;warn.textContent=msgs.join(' ');
   btn.disabled=!c.hasProduct||!!msgs.length||!keys.length||!enough;
 }
 function bind(row){
   const sel=row.querySelector('[data-finished-product]'),qty=row.querySelector('[data-finished-qty]'),rem=row.querySelector('[data-remove-finished]');
   sel.addEventListener('change',render);
   if(window.NexaBuscarF8)window.NexaBuscarF8.enhanceSelect(sel,{title:'Buscar produto acabado',placeholder:'Selecione o produto acabado',registerUrl:'produto_form.php',registerLabel:'+ Cadastrar produto'});
   qty.addEventListener('input',render);rem.addEventListener('click',()=>{if(body.querySelectorAll('[data-finished-row]').length<=1){sel.value='';sel.dispatchEvent(new Event('change',{bubbles:true}));qty.value='';render();}else{row.remove();render();}});
 }
 body.querySelectorAll('[data-finished-row]').forEach(bind);add.addEventListener('click',()=>{const f=tpl.content.cloneNode(true),r=f.querySelector('[data-finished-row]');body.appendChild(f);bind(r);render();});
 document.getElementById('productionOrderForm').addEventListener('submit',function(e){render();if(btn.disabled){e.preventDefault();return;}if(!confirm('Gerar esta Ordem de Produção e movimentar o estoque das matérias-primas?'))e.preventDefault();});
 render();
})();
</script>
<?php endif; ?>
<?php production_page_end(); ?>

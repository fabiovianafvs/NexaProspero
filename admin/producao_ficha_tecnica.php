<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/producao_module.php';
require_once __DIR__ . '/_producao_ui.php';

$companyId=(int)COMPANY_ID;
if(!production_enabled($pdo,$companyId)) saas_require_company_module($pdo,$companyId,'ordem_producao','Ficha Técnica de Produção');
if(!production_ensure_schema($pdo)) throw new RuntimeException('Não foi possível preparar a estrutura da Ordem de Produção.');

$error='';$selectedId=(int)($_POST['produto_id']??($_GET['produto_id']??0));
if($_SERVER['REQUEST_METHOD']==='POST'){
  try{
    $action=(string)($_POST['action']??'');
    if($action==='save_recipe'){
      production_recipe_save($pdo,$companyId,$selectedId,(array)($_POST['material_id']??[]),(array)($_POST['material_qty']??[]));
      header('Location: producao_ficha_tecnica.php?produto_id='.$selectedId.'&ok='.rawurlencode('Ficha técnica salva com sucesso.'));exit;
    }
  }catch(Throwable $e){$error=$e->getMessage();}
}
$products=production_finished_products($pdo,$companyId,false);
$materials=production_raw_materials($pdo,$companyId,false);
$selected=null;foreach($products as $p){if((int)$p['id']===$selectedId){$selected=$p;break;}}
$recipe=$selected?production_recipe_load($pdo,$companyId,$selectedId):[];
$display=$recipe?:[['materia_prima_id'=>0,'quantidade'=>'']];

production_page_start('Ficha Técnica de Produção','producao_ficha');
production_page_header('Ficha Técnica','Defina as matérias-primas e quantidades necessárias para fabricar uma unidade de cada produto marcado como “Possui Produção”.');
if($error!=='')echo '<div class="msg err">'.production_h($error).'</div>';
if(!empty($_GET['ok']))echo '<div class="msg ok">'.production_h($_GET['ok']).'</div>';

echo '<div class="pr-card"><div class="pr-card-head"><div><h2>Produto acabado</h2><div class="pr-muted">Selecione o produto para consultar ou editar a ficha técnica.</div></div></div>';
echo '<form method="get"><div class="pr-grid"><div class="pr-row"><label>Produto</label><select name="produto_id" onchange="this.form.submit()"><option value="">Selecione...</option>';
foreach($products as $p)echo '<option value="'.(int)$p['id'].'" data-meta="Estoque: '.production_h(production_qty_label($p['estoque_atual'],4)).' '.production_h($p['unidade_estoque']).'"'.((int)$p['id']===$selectedId?' selected':'').'>'.production_h($p['nome']).' — estoque '.production_h(production_qty_label($p['estoque_atual'],4)).' '.production_h($p['unidade_estoque']).'</option>';
echo '</select></div></div></form></div>';

if(!$products){
  echo '<div class="pr-card"><div class="pr-empty">Nenhum produto está marcado como <b>Possui Produção</b>. Abra o cadastro do produto e habilite essa configuração.</div></div>';
}elseif($selected){
  echo '<form method="post" id="recipeForm"><input type="hidden" name="action" value="save_recipe"><input type="hidden" name="produto_id" value="'.(int)$selectedId.'">';
  echo '<div class="pr-card"><div class="pr-card-head"><div><h2>'.production_h($selected['nome']).'</h2><div class="pr-muted">Quantidade informada abaixo = consumo para produzir 1 '.production_h($selected['unidade_estoque']).' do produto acabado.</div></div><button class="btn btn-primary" type="submit">Salvar ficha técnica</button></div>';
  if(!$materials)echo '<div class="pr-warning">Nenhuma matéria-prima cadastrada. Marque os insumos como <b>Matéria-prima</b> no cadastro de produtos.</div>';
  echo '<div class="pr-table-wrap"><table class="pr-table"><thead><tr><th>Matéria-prima</th><th>Quantidade para 1 '.production_h($selected['unidade_estoque']).'</th><th>Estoque atual</th><th></th></tr></thead><tbody id="recipeRows">';
  foreach($display as $r){$mid=(int)($r['materia_prima_id']??0);echo '<tr data-recipe-row><td><select name="material_id[]" data-material><option value="">Selecione...</option>';foreach($materials as $m){echo '<option value="'.(int)$m['id'].'" data-stock="'.production_h((string)$m['estoque_atual']).'" data-unit="'.production_h($m['unidade_estoque']).'" data-meta="Estoque: '.production_h(production_qty_label($m['estoque_atual'],4)).' '.production_h($m['unidade_estoque']).'"'.((int)$m['id']===$mid?' selected':'').'>'.production_h($m['nome']).'</option>';}echo '</select></td><td><input name="material_qty[]" inputmode="decimal" value="'.production_h(production_qty_label($r['quantidade']??'',6)).'" placeholder="Ex.: 0,500"></td><td data-stock-label>—</td><td><button type="button" class="btn btn-sm" data-remove>Remover</button></td></tr>';}
  echo '</tbody></table></div><div style="margin-top:10px"><button class="btn" type="button" id="addRecipeRow">+ Matéria-prima</button></div></div></form>';
}
?>
<?php require_once __DIR__ . '/_buscar_f8.php'; nexa_buscar_f8_assets(); ?>
<template id="recipeRowTemplate"><tr data-recipe-row><td><select name="material_id[]" data-material><option value="">Selecione...</option><?php foreach($materials as $m): ?><option value="<?= (int)$m['id'] ?>" data-stock="<?= production_h((string)$m['estoque_atual']) ?>" data-unit="<?= production_h($m['unidade_estoque']) ?>" data-meta="Estoque: <?= production_h(production_qty_label($m['estoque_atual'],4)) ?> <?= production_h($m['unidade_estoque']) ?>"><?= production_h($m['nome']) ?></option><?php endforeach; ?></select></td><td><input name="material_qty[]" inputmode="decimal" placeholder="Ex.: 0,500"></td><td data-stock-label>—</td><td><button type="button" class="btn btn-sm" data-remove>Remover</button></td></tr></template>
<script>
(function(){
 const rows=document.getElementById('recipeRows'),add=document.getElementById('addRecipeRow'),tpl=document.getElementById('recipeRowTemplate');
 const registerUrl='produto_form.php';
 function fmt(v){const n=Number(v||0);return Number.isFinite(n)?n.toLocaleString('pt-BR',{maximumFractionDigits:4}):'0';}
 const finishedSelect=document.querySelector('select[name="produto_id"]');
 if(finishedSelect&&window.NexaBuscarF8) window.NexaBuscarF8.enhanceSelect(finishedSelect,{title:'Buscar produto acabado',placeholder:'Selecione o produto acabado',registerUrl:registerUrl,registerLabel:'+ Cadastrar produto'});
 function bind(row){
   const sel=row.querySelector('[data-material]'),stock=row.querySelector('[data-stock-label]'),rem=row.querySelector('[data-remove]');
   function upd(){const o=sel&&sel.options[sel.selectedIndex];stock.textContent=o&&o.value?fmt(o.dataset.stock)+' '+(o.dataset.unit||'UN'):'—';}
   if(sel){sel.addEventListener('change',upd);if(window.NexaBuscarF8)window.NexaBuscarF8.enhanceSelect(sel,{title:'Buscar matéria-prima',placeholder:'Selecione a matéria-prima',registerUrl:registerUrl,registerLabel:'+ Cadastrar produto'});upd();}
   if(rem)rem.addEventListener('click',()=>{const all=rows.querySelectorAll('[data-recipe-row]');if(all.length<=1){row.querySelectorAll('input').forEach(i=>i.value='');if(sel){sel.value='';sel.dispatchEvent(new Event('change',{bubbles:true}));}upd();}else row.remove();});
 }
 if(rows)rows.querySelectorAll('[data-recipe-row]').forEach(bind);if(add&&rows&&tpl)add.addEventListener('click',()=>{const f=tpl.content.cloneNode(true),r=f.querySelector('[data-recipe-row]');rows.appendChild(f);if(r)bind(r);});
})();
</script>
<?php production_page_end(); ?>

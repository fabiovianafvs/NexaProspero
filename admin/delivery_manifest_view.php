<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/delivery_control.php';
require_once __DIR__ . '/_delivery_control_ui.php';

$companyId=(int)COMPANY_ID;
delivery_control_require_enabled($pdo,$companyId);
$userId=dc_user_id();
$id=(int)($_GET['id']??$_POST['id']??0);
if($id<=0)die('Romaneio inválido.');
$msg='';$err='';
$loadManifest=function()use($pdo,$companyId,$id){$st=$pdo->prepare('SELECT * FROM delivery_control_manifests WHERE company_id=? AND id=? LIMIT 1');$st->execute([$companyId,$id]);return $st->fetch(PDO::FETCH_ASSOC)?:null;};

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        dc_csrf_check((string)($_POST['csrf']??''));
        $action=(string)($_POST['action']??'');
        $pdo->beginTransaction();
        $st=$pdo->prepare('SELECT * FROM delivery_control_manifests WHERE company_id=? AND id=? FOR UPDATE');
        $st->execute([$companyId,$id]);$m=$st->fetch(PDO::FETCH_ASSOC);
        if(!$m)throw new RuntimeException('Romaneio não encontrado.');
        if($action==='add'){
            $oid=(int)($_POST['order_id']??0);
            if($oid<=0)throw new RuntimeException('Selecione uma O.E.');
            $st=$pdo->prepare("SELECT o.id,o.status FROM delivery_control_orders o WHERE o.company_id=? AND o.id=? FOR UPDATE");
            $st->execute([$companyId,$oid]);$o=$st->fetch(PDO::FETCH_ASSOC);
            if(!$o||!in_array((string)$o['status'],['GERADA','SEPARADA'],true))throw new RuntimeException('O.E. indisponível para romaneio.');
            $st=$pdo->prepare("SELECT COUNT(*) FROM delivery_control_manifest_orders mo JOIN delivery_control_manifests mx ON mx.company_id=mo.company_id AND mx.id=mo.manifest_id WHERE mo.company_id=? AND mo.delivery_order_id=? AND mx.status<>'CANCELADO'");
            $st->execute([$companyId,$oid]);
            if((int)$st->fetchColumn()>0)throw new RuntimeException('Esta O.E. já está vinculada a outro romaneio.');
            $st=$pdo->prepare('SELECT COALESCE(MAX(route_sequence),0)+1 FROM delivery_control_manifest_orders WHERE company_id=? AND manifest_id=?');
            $st->execute([$companyId,$id]);$seq=(int)$st->fetchColumn();
            $pdo->prepare('INSERT INTO delivery_control_manifest_orders(company_id,manifest_id,delivery_order_id,route_sequence) VALUES(?,?,?,?)')->execute([$companyId,$id,$oid,$seq]);
            $pdo->prepare("UPDATE delivery_control_orders SET status='EM_ROMANEIO',updated_at=NOW() WHERE company_id=? AND id=?")->execute([$companyId,$oid]);
            $msg='O.E. incluída no romaneio.';
        }elseif($action==='remove'){
            $linkId=(int)($_POST['link_id']??0);
            $st=$pdo->prepare('SELECT delivery_order_id FROM delivery_control_manifest_orders WHERE company_id=? AND manifest_id=? AND id=? FOR UPDATE');
            $st->execute([$companyId,$id,$linkId]);$oid=(int)$st->fetchColumn();
            if($oid<=0)throw new RuntimeException('O.E. não encontrada no romaneio.');
            $pdo->prepare('DELETE FROM delivery_control_manifest_orders WHERE company_id=? AND manifest_id=? AND id=?')->execute([$companyId,$id,$linkId]);
            $pdo->prepare("UPDATE delivery_control_orders SET status='GERADA',updated_at=NOW() WHERE company_id=? AND id=? AND status='EM_ROMANEIO'")->execute([$companyId,$oid]);
            $msg='O.E. removida do romaneio.';
        }elseif(in_array($action,['up','down'],true)){
            $linkId=(int)($_POST['link_id']??0);
            $st=$pdo->prepare('SELECT id,route_sequence FROM delivery_control_manifest_orders WHERE company_id=? AND manifest_id=? AND id=? FOR UPDATE');
            $st->execute([$companyId,$id,$linkId]);$cur=$st->fetch(PDO::FETCH_ASSOC);
            if(!$cur)throw new RuntimeException('Item de rota não encontrado.');
            $op=$action==='up'?'<':'>';$ord=$action==='up'?'DESC':'ASC';
            $st=$pdo->prepare("SELECT id,route_sequence FROM delivery_control_manifest_orders WHERE company_id=? AND manifest_id=? AND route_sequence {$op} ? ORDER BY route_sequence {$ord},id {$ord} LIMIT 1 FOR UPDATE");
            $st->execute([$companyId,$id,(int)$cur['route_sequence']]);$other=$st->fetch(PDO::FETCH_ASSOC);
            if($other){
                $pdo->prepare('UPDATE delivery_control_manifest_orders SET route_sequence=? WHERE company_id=? AND id=?')->execute([(int)$other['route_sequence'],$companyId,$linkId]);
                $pdo->prepare('UPDATE delivery_control_manifest_orders SET route_sequence=? WHERE company_id=? AND id=?')->execute([(int)$cur['route_sequence'],$companyId,(int)$other['id']]);
            }
            $msg='Sequência da rota atualizada.';
        }elseif($action==='separate'){
            $pdo->prepare("UPDATE delivery_control_manifests SET status='FECHADO',updated_at=NOW() WHERE company_id=? AND id=?")->execute([$companyId,$id]);
            $pdo->prepare("UPDATE delivery_control_orders o JOIN delivery_control_manifest_orders mo ON mo.company_id=o.company_id AND mo.delivery_order_id=o.id SET o.status='SEPARADA',o.updated_at=NOW() WHERE mo.company_id=? AND mo.manifest_id=? AND o.status='EM_ROMANEIO'")->execute([$companyId,$id]);
            $msg='Romaneio fechado para separação.';
        }elseif($action==='route'){
            $pdo->prepare("UPDATE delivery_control_manifests SET status='EM_ROTA',updated_at=NOW() WHERE company_id=? AND id=?")->execute([$companyId,$id]);
            $pdo->prepare("UPDATE delivery_control_orders o JOIN delivery_control_manifest_orders mo ON mo.company_id=o.company_id AND mo.delivery_order_id=o.id SET o.status='SAIU_PARA_ENTREGA',o.updated_at=NOW() WHERE mo.company_id=? AND mo.manifest_id=? AND o.status IN ('EM_ROMANEIO','SEPARADA')")->execute([$companyId,$id]);
            $msg='Romaneio marcado como em rota.';
        }
        delivery_control_event($pdo,$companyId,'MANIFEST',$id,strtoupper('ROMANEIO_'.$action),$userId);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$err=$e->getMessage();}
}

$m=$loadManifest();
if(!$m){http_response_code(404);die('Romaneio não encontrado.');}
$st=$pdo->prepare("SELECT mo.id link_id,mo.route_sequence,o.*,s.customer_name,s.pedido_id,COALESCE(SUM(oi.quantity),0) total_qty
                   FROM delivery_control_manifest_orders mo
                   JOIN delivery_control_orders o ON o.company_id=mo.company_id AND o.id=mo.delivery_order_id
                   JOIN delivery_control_sales s ON s.company_id=o.company_id AND s.id=o.delivery_sale_id
                   LEFT JOIN delivery_control_order_items oi ON oi.company_id=o.company_id AND oi.delivery_order_id=o.id
                   WHERE mo.company_id=? AND mo.manifest_id=? GROUP BY mo.id ORDER BY mo.route_sequence,mo.id");
$st->execute([$companyId,$id]);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];

dc_page_start('Romaneio #'.(int)$m['manifest_number'],'delivery_manifest');
?>
<div class="dc-head">
  <a class="dc-btn" href="delivery_manifest.php">← Voltar</a>
  <h1>Romaneio #<?=str_pad((string)$m['manifest_number'],6,'0',STR_PAD_LEFT)?></h1>
  <span class="dc-badge <?=dc_status_class((string)$m['status'])?>"><?=dc_e(dc_status_label((string)$m['status']))?></span>
  <div class="dc-spacer"></div>
  <a target="_blank" class="dc-btn" href="delivery_manifest_print.php?id=<?=$id?>">Imprimir Romaneio</a>
  <a target="_blank" class="dc-btn primary" href="delivery_separation_print.php?id=<?=$id?>">Lista de Separação</a>
</div>
<?php if($msg):?><div class="dc-alert"><?=dc_e($msg)?></div><?php endif;?><?php if($err):?><div class="dc-alert error"><?=dc_e($err)?></div><?php endif;?>
<div class="dc-card"><div class="dc-card-head">Dados do Romaneio</div><div class="dc-card-body"><div class="dc-grid"><div><span class="dc-muted">Data</span><div><strong><?=dc_date($m['manifest_date'])?></strong></div></div><div><span class="dc-muted">Transportadora</span><div><strong><?=dc_e($m['carrier_name_snapshot']?:'Entrega própria')?></strong></div></div><div><span class="dc-muted">Veículo</span><div><strong><?=dc_e($m['vehicle_description_snapshot']?:'—')?><?=!empty($m['vehicle_plate_snapshot'])?' · '.dc_e($m['vehicle_plate_snapshot']):''?></strong></div></div><div><span class="dc-muted">Motorista</span><div><strong><?=dc_e($m['driver_name_snapshot']?:'—')?></strong></div></div></div></div></div>

<?php if((string)$m['status']==='ABERTO'):?>
<form method="post" class="dc-card" id="addOrderForm">
  <input type="hidden" name="csrf" value="<?=dc_e(dc_csrf_token())?>">
  <input type="hidden" name="id" value="<?=$id?>">
  <input type="hidden" name="action" value="add">
  <input type="hidden" name="order_id" id="selectedOrderId" value="">
  <div class="dc-card-head">Adicionar Ordem de Entrega</div>
  <div class="dc-card-body">
    <div class="dc-order-add-row">
      <div class="dc-field">
        <label>Ordem de Entrega</label>
        <input type="text" id="orderLookupText" autocomplete="off" placeholder="Digite nº da O.E., pedido, cliente ou endereço e pressione Enter">
      </div>
      <button type="button" class="dc-btn" id="openOrderLookup"><strong>Buscar (F8)</strong></button>
      <button type="submit" class="dc-btn primary" id="addSelectedOrder" disabled>Adicionar ao Romaneio</button>
    </div>
    <div class="dc-muted" id="selectedOrderHint" style="font-size:12px;margin-top:6px">Digite e pressione Enter para pesquisar, ou use F8 para abrir a consulta vazia.</div>
  </div>
</form>
<?php endif;?>

<div class="dc-card"><div class="dc-card-head">Rota / Ordens de Entrega</div><div class="dc-card-body"><div class="dc-table-wrap"><table class="dc-table"><thead><tr><th>Rota</th><th>O.E.</th><th>Cliente</th><th>Endereço</th><th>Status</th><th class="num">Qtd.</th><th></th></tr></thead><tbody><?php if(!$rows):?><tr><td colspan="7" class="dc-muted">Nenhuma O.E. no romaneio.</td></tr><?php endif;?><?php foreach($rows as $r):?><tr><td class="dc-route"><?=(int)$r['route_sequence']?></td><td><strong>#<?=str_pad((string)$r['order_number'],6,'0',STR_PAD_LEFT)?></strong><div class="dc-muted">Pedido #<?=(int)$r['pedido_id']?></div></td><td><?=dc_e($r['customer_name'])?></td><td class="dc-address"><?=dc_e($r['delivery_address'])?>, <?=dc_e($r['delivery_number'])?><br><span class="dc-muted"><?=dc_e($r['delivery_district'])?> · <?=dc_e($r['delivery_city'])?>/<?=dc_e($r['delivery_state'])?></span></td><td><span class="dc-badge <?=dc_status_class((string)$r['status'])?>"><?=dc_e(dc_status_label((string)$r['status']))?></span></td><td class="num"><?=dc_qty($r['total_qty'])?></td><td><?php if((string)$m['status']==='ABERTO'):?><div class="dc-actions"><form method="post"><input type="hidden" name="csrf" value="<?=dc_e(dc_csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="link_id" value="<?=(int)$r['link_id']?>"><button name="action" value="up" class="dc-btn sm" title="Subir rota">↑</button><button name="action" value="down" class="dc-btn sm" title="Descer rota">↓</button><button name="action" value="remove" class="dc-btn sm danger" onclick="return confirm('Remover esta O.E. do romaneio?')">Remover</button></form></div><?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php if($rows):?><div class="dc-actions" style="margin-top:14px"><?php if((string)$m['status']==='ABERTO'):?><form method="post"><input type="hidden" name="csrf" value="<?=dc_e(dc_csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><button name="action" value="separate" class="dc-btn primary" onclick="return confirm('Fechar o romaneio para separação?')">Fechar para Separação</button></form><?php endif;?><?php if(in_array((string)$m['status'],['ABERTO','FECHADO'],true)):?><form method="post"><input type="hidden" name="csrf" value="<?=dc_e(dc_csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><button name="action" value="route" class="dc-btn" onclick="return confirm('Marcar todas as O.E. deste romaneio como saiu para entrega?')">Saiu para Entrega</button></form><?php endif;?></div><?php endif;?></div></div>

<?php if((string)$m['status']==='ABERTO'):?>
<div class="dc-lookup-modal" id="orderLookupModal" hidden>
  <div class="dc-lookup-backdrop" data-close-lookup></div>
  <section class="dc-lookup-dialog" role="dialog" aria-modal="true" aria-labelledby="orderLookupTitle">
    <div class="dc-lookup-head"><div><strong id="orderLookupTitle">Pesquisar Ordem de Entrega</strong><div class="dc-muted">Enter pesquisa • ↑/↓ navega • Enter seleciona • Esc fecha</div></div><button type="button" class="dc-btn sm" data-close-lookup>Fechar</button></div>
    <div class="dc-lookup-search"><input type="text" id="orderLookupSearch" autocomplete="off" placeholder="Nº O.E., pedido, cliente, endereço, bairro ou cidade"><button type="button" class="dc-btn primary" id="orderLookupSearchButton">Pesquisar</button></div>
    <div class="dc-lookup-result"><table class="dc-table"><thead><tr><th>O.E.</th><th>Programação</th><th>Pedido</th><th>Cliente</th><th>Endereço</th><th class="num">Qtd.</th></tr></thead><tbody id="orderLookupRows"><tr><td colspan="6" class="dc-muted">Digite uma pesquisa ou pressione Pesquisar para listar as O.E. disponíveis.</td></tr></tbody></table></div>
    <div class="dc-lookup-foot"><span class="dc-muted" id="orderLookupCount"></span><button type="button" class="dc-btn primary" id="orderLookupSelect" disabled>Selecionar</button></div>
  </section>
</div>
<style>
.dc-order-add-row{display:grid;grid-template-columns:minmax(300px,1fr) auto auto;gap:10px;align-items:end}.dc-order-add-row .dc-btn{min-height:39px;white-space:nowrap}
.dc-lookup-modal[hidden]{display:none}.dc-lookup-modal{position:fixed;inset:0;z-index:9999}.dc-lookup-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.5)}.dc-lookup-dialog{position:relative;z-index:1;width:min(1120px,calc(100vw - 36px));max-height:calc(100vh - 42px);margin:21px auto;background:#fff;border-radius:14px;box-shadow:0 24px 70px rgba(15,23,42,.28);display:flex;flex-direction:column;overflow:hidden}.dc-lookup-head{display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid #e2e8f0}.dc-lookup-head>div:first-child{flex:1}.dc-lookup-head strong{font-size:17px}.dc-lookup-head .dc-muted{font-size:11px;margin-top:2px}.dc-lookup-search{display:grid;grid-template-columns:1fr auto;gap:8px;padding:12px 16px;border-bottom:1px solid #e2e8f0}.dc-lookup-search input{width:100%;border:1px solid #aebdce;border-radius:8px;padding:10px 11px;box-sizing:border-box}.dc-lookup-result{padding:0 16px;overflow:auto;flex:1;min-height:250px}.dc-lookup-result .dc-table{min-width:900px}.dc-lookup-result tbody tr{cursor:pointer}.dc-lookup-result tbody tr:hover td,.dc-lookup-result tbody tr.is-selected td{background:#e7f0fb}.dc-lookup-foot{display:flex;align-items:center;gap:10px;padding:10px 16px;border-top:1px solid #e2e8f0}.dc-lookup-foot .dc-muted{flex:1;font-size:12px}
@media(max-width:760px){.dc-order-add-row{grid-template-columns:1fr}.dc-order-add-row .dc-btn{width:100%}.dc-lookup-dialog{width:calc(100vw - 14px);margin:7px auto;max-height:calc(100vh - 14px)}.dc-lookup-search{grid-template-columns:1fr}}
</style>
<script>
(function(){
 const modal=document.getElementById('orderLookupModal');
 const external=document.getElementById('orderLookupText');
 const hidden=document.getElementById('selectedOrderId');
 const openBtn=document.getElementById('openOrderLookup');
 const addBtn=document.getElementById('addSelectedOrder');
 const hint=document.getElementById('selectedOrderHint');
 const search=document.getElementById('orderLookupSearch');
 const searchBtn=document.getElementById('orderLookupSearchButton');
 const body=document.getElementById('orderLookupRows');
 const count=document.getElementById('orderLookupCount');
 const selectBtn=document.getElementById('orderLookupSelect');
 if(!modal||!external||!hidden)return;
 let rows=[],active=-1,requestSeq=0,lastLoadedTerm=null;
 const pad=n=>String(n).padStart(6,'0');
 const fmtDate=s=>{if(!s)return '—';const p=String(s).slice(0,10).split('-');return p.length===3?p[2]+'/'+p[1]+'/'+p[0]:'—';};
 const fmtQty=n=>Number(n||0).toLocaleString('pt-BR',{minimumFractionDigits:0,maximumFractionDigits:4});
 function clearSelected(){hidden.value='';addBtn.disabled=true;hint.textContent='Digite e pressione Enter para pesquisar, ou use F8 para abrir a consulta vazia.';}
 function setActive(i){if(!rows.length){active=-1;selectBtn.disabled=true;return;}active=Math.max(0,Math.min(rows.length-1,i));Array.from(body.querySelectorAll('tr[data-index]')).forEach((tr,idx)=>tr.classList.toggle('is-selected',idx===active));selectBtn.disabled=false;const tr=body.querySelector('tr[data-index="'+active+'"]');if(tr)tr.scrollIntoView({block:'nearest'});}
 function render(){body.textContent='';if(!rows.length){const tr=document.createElement('tr'),td=document.createElement('td');td.colSpan=6;td.className='dc-muted';td.textContent='Nenhuma O.E. disponível encontrada.';tr.appendChild(td);body.appendChild(tr);count.textContent='0 registro(s)';active=-1;selectBtn.disabled=true;return;}rows.forEach((r,i)=>{const tr=document.createElement('tr');tr.dataset.index=String(i);const vals=['#'+pad(r.order_number),fmtDate(r.scheduled_date)+(r.scheduled_time?' '+String(r.scheduled_time).slice(0,5):''),'#'+r.pedido_id,r.customer_name,[r.address,r.district,[r.city,r.state].filter(Boolean).join('/')].filter(Boolean).join(' · '),fmtQty(r.total_qty)];vals.forEach((v,idx)=>{const td=document.createElement('td');td.textContent=v||'—';if(idx===5)td.className='num';tr.appendChild(td);});tr.addEventListener('click',()=>setActive(i));tr.addEventListener('dblclick',()=>{setActive(i);choose();});body.appendChild(tr);});count.textContent=rows.length+' registro(s)';setActive(0);}
 async function load(term){term=String(term||'').trim();lastLoadedTerm=term;const seq=++requestSeq;body.innerHTML='<tr><td colspan="6" class="dc-muted">Pesquisando...</td></tr>';count.textContent='';selectBtn.disabled=true;try{const r=await fetch('delivery_order_lookup.php?q='+encodeURIComponent(term||''),{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});const d=await r.json();if(seq!==requestSeq)return;if(!r.ok||!d.ok)throw new Error(d.message||'Falha na pesquisa');rows=Array.isArray(d.rows)?d.rows:[];render();}catch(e){if(seq!==requestSeq)return;rows=[];body.innerHTML='<tr><td colspan="6" style="color:#991b1b">'+String(e.message||e)+'</td></tr>';count.textContent='';active=-1;selectBtn.disabled=true;}}
 function open(term,autoSearch){modal.hidden=false;document.body.style.overflow='hidden';search.value=term||'';rows=[];active=-1;body.innerHTML='<tr><td colspan="6" class="dc-muted">Digite uma pesquisa ou pressione Pesquisar para listar as O.E. disponíveis.</td></tr>';count.textContent='';selectBtn.disabled=true;setTimeout(()=>{search.focus();search.select();if(autoSearch)load(search.value);},20);}
 function close(){modal.hidden=true;document.body.style.overflow='';external.focus();}
 function choose(){if(active<0||!rows[active])return;const r=rows[active];hidden.value=String(r.id);external.value='#'+pad(r.order_number)+' · '+r.customer_name+' · Pedido #'+r.pedido_id;addBtn.disabled=false;hint.textContent='O.E. selecionada. Pressione Enter em “Adicionar ao Romaneio” para incluir.';modal.hidden=true;document.body.style.overflow='';setTimeout(()=>addBtn.focus(),20);}
 external.addEventListener('input',clearSelected);
 external.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();open(external.value.trim(),true);}});
 openBtn.addEventListener('click',()=>open('',false));
 searchBtn.addEventListener('click',()=>load(search.value.trim()));
 search.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();const term=search.value.trim();if(rows.length&&active>=0&&lastLoadedTerm===term){choose();}else load(term);}else if(e.key==='ArrowDown'){e.preventDefault();setActive(active<0?0:active+1);}else if(e.key==='ArrowUp'){e.preventDefault();setActive(active<0?rows.length-1:active-1);}});
 body.addEventListener('keydown',()=>{});
 selectBtn.addEventListener('click',choose);
 modal.querySelectorAll('[data-close-lookup]').forEach(el=>el.addEventListener('click',close));
 document.addEventListener('keydown',e=>{if(e.key==='F8'){e.preventDefault();if(modal.hidden)open('',false);else{search.value='';search.focus();}}else if(e.key==='Escape'&&!modal.hidden){e.preventDefault();close();}else if(!modal.hidden&&e.key==='Enter'&&document.activeElement===selectBtn){e.preventDefault();choose();}});
 document.getElementById('addOrderForm').addEventListener('submit',e=>{if(!hidden.value){e.preventDefault();open(external.value.trim(),external.value.trim()!=='');}});
 setTimeout(()=>external.focus(),30);
})();
</script>
<?php endif;?>
<?php dc_page_end(); ?>

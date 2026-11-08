<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/contracts_module.php';
$pdo=contracts_db();$cid=contracts_company_id();$uid=contracts_user_id();contracts_require_schema($pdo);
function cg_date($v):?string{$v=trim((string)$v);return preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)?$v:null;}
$today=date('Y-m-d');$periodStart=cg_date($_GET['period_start']??$_POST['period_start']??'')?:date('Y-m-01');$periodEnd=cg_date($_GET['period_end']??$_POST['period_end']??'')?:date('Y-m-t');if($periodEnd<$periodStart){[$periodStart,$periodEnd]=[$periodEnd,$periodStart];}
$personId=max(0,(int)($_GET['person_id']??$_POST['person_id']??0));$personName=trim((string)($_GET['person_name']??$_POST['person_name']??''));$ok='';$err='';
// V7: geracao de contratos em blocos pequenos evita timeout ao registrar muitos
// boletos via API bancaria. Um erro nao deve reenviar competencias ja gravadas.
if (empty($_SESSION['nexa_contract_bulk_v7_csrf'])) $_SESSION['nexa_contract_bulk_v7_csrf']=bin2hex(random_bytes(24));
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST' && ($_POST['action']??'')==='generate_ajax_v7') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, private');
  try {
    $csrf=(string)($_POST['csrf_v7']??'');
    if(!hash_equals((string)$_SESSION['nexa_contract_bulk_v7_csrf'],$csrf)) throw new RuntimeException('Sessao de seguranca expirada. Atualize a tela.');
    $keys=(array)($_POST['selected']??[]);
    if(count($keys)<1 || count($keys)>5) throw new RuntimeException('Envie de 1 a 5 competencias por etapa.');
    foreach($keys as $key) if(!is_string($key)||!preg_match('/^\d+\|\d{4}-\d{2}-\d{2}$/D',$key)) throw new RuntimeException('Competencia invalida.');
    $result=contracts_generate_selected($pdo,$cid,$uid,$periodStart,$periodEnd,$keys,$personId);
    echo json_encode(['ok'=>true,'generated'=>count($result['generated']??[]),'billing_prepared'=>(int)($result['billing_prepared']??0),'billing_registered'=>(int)($result['billing_registered']??0),'errors'=>array_values(array_merge((array)($result['errors']??[]),(array)($result['billing_errors']??[])))],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
  }catch(Throwable $e){http_response_code(400);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);}
  exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='generate'){
  $keys=array_values(array_filter((array)($_POST['selected']??[]),fn($v)=>is_string($v)&&preg_match('/^\d+\|\d{4}-\d{2}-\d{2}$/',$v)));
  if(!$keys)$err='Selecione pelo menos uma competência para gerar.';else{try{$res=contracts_generate_selected($pdo,$cid,$uid,$periodStart,$periodEnd,$keys,$personId);$n=count($res['generated']);$ok=$n.' pedido'.($n===1?'':'s').' gerado'.($n===1?'':'s').' com sucesso.';$registered=(int)($res['billing_registered']??0);$prepared=(int)($res['billing_prepared']??0);if($registered>0)$ok.=' '.$registered.' boleto'.($registered===1?'':'s').' registrado'.($registered===1?'':'s').' automaticamente no banco.';elseif($prepared>0)$ok.=' '.$prepared.' cobrança'.($prepared===1?' preparada':'s preparadas').' para registro automático.';$allErrors=array_merge((array)($res['errors']??[]),(array)($res['billing_errors']??[]));if($allErrors)$err=implode("\n",$allErrors);}catch(Throwable $e){$err=$e->getMessage();}}
}
try{$rows=contracts_generation_candidates($pdo,$cid,$periodStart,$periodEnd,$personId);}catch(Throwable $e){$rows=[];$err=$err?:$e->getMessage();}
$pending=array_values(array_filter($rows,fn($r)=>!$r['generation']||((int)($r['generation']['order_id']??0)<=0&&strtoupper((string)($r['generation']['status']??''))!=='GERADO')));$pendingTotal=array_sum(array_column($pending,'amount'));$generatedCount=count($rows)-count($pending);
?>
<?php admin_topbar('Contratos - Gerar','contratos_gerar'); ?>
<style>
.cg{max-width:1450px;margin:24px auto;padding:0 18px}.cg-card{background:#fff;border:1px solid #dbe4ee;border-radius:16px;padding:20px;margin-bottom:16px}.cg h1{color:#0b1f5b;margin:0 0 5px}.cg-sub{color:#64748b}.cg-filter-grid{display:grid;grid-template-columns:170px 170px minmax(360px,1fr) auto auto;gap:10px;align-items:end}.cg-f{min-width:0}.cg-f label{display:block;font-size:12px;font-weight:800;color:#475569;margin-bottom:5px}.cg input{width:100%;height:42px;box-sizing:border-box;border:1px solid #9aa9bc;border-radius:10px;padding:9px 11px;background:#fff}.cg-inline{display:flex;gap:8px;align-items:center;min-width:0}.cg-inline input{min-width:0;flex:1}.cg-inline .cg-btn{height:42px;padding:9px 12px;white-space:nowrap}.cg-filter-grid>.cg-btn{height:42px;padding:9px 14px;white-space:nowrap}.cg-btn{border:1px solid #cbd5e1;background:#fff;color:#0f172a;border-radius:10px;padding:10px 14px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.cg-btn.primary{background:#1557d5;border-color:#1557d5;color:#fff}.cg-btn.success{background:#0f9f6e;border-color:#0f9f6e;color:#fff}.cg-alert{border-radius:10px;padding:12px 14px;margin-bottom:14px;white-space:pre-line}.cg-alert.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}.cg-alert.err{background:#fff1f2;color:#9f1239;border:1px solid #fecdd3}.cg-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}.cg-stat{background:#fff;border:1px solid #dbe4ee;border-radius:14px;padding:14px}.cg-stat small{display:block;color:#64748b;font-weight:800}.cg-stat strong{display:block;color:#0b1f5b;font-size:21px;margin-top:5px}.cg-table-wrap{overflow:auto;border:1px solid #dbe4ee;border-radius:12px}.cg table{width:100%;border-collapse:collapse;min-width:980px}.cg th,.cg td{padding:11px 10px;border-bottom:1px solid #e7edf4;text-align:left}.cg th{background:#f7f9fc;color:#475569;font-size:12px;text-transform:uppercase;letter-spacing:.03em}.cg td.num,.cg th.num{text-align:right}.cg-status{display:inline-block;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:900}.cg-status.pending{background:#fff7ed;color:#9a3412}.cg-status.done{background:#ecfdf5;color:#047857}.cg-toolbar{display:flex;gap:10px;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap}.cg-muted{color:#64748b}.cg-check{width:18px!important;height:18px}.cg-link{color:#1557d5;font-weight:800;text-decoration:none}.cg-modal-bg{position:fixed;inset:0;z-index:10050;background:rgba(15,23,42,.48);display:none;align-items:center;justify-content:center;padding:18px}.cg-modal-bg.open{display:flex}.cg-modal{width:min(760px,calc(100vw - 36px));max-height:calc(100vh - 50px);overflow:hidden;background:#fff;border-radius:16px;box-shadow:0 25px 70px rgba(15,23,42,.28);border:1px solid #dbe4ee}.cg-modal-head{display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid #e5eaf1}.cg-modal-head strong{font-size:18px;color:#0b1f5b}.cg-modal-body{padding:16px}.cg-modal-search{display:flex;gap:8px;align-items:center}.cg-modal-search input{flex:1;min-width:0;height:42px}.cg-hint{font-size:12px;color:#64748b;margin:9px 0}.cg-results{border:1px solid #dbe4ee;border-radius:10px;max-height:350px;overflow:auto;background:#fff}.cg-result{padding:10px 12px;border-bottom:1px solid #edf1f5;cursor:pointer}.cg-result:last-child{border-bottom:0}.cg-result:hover,.cg-result.sel{background:#eef4ff}.cg-result strong{display:block;color:#0f172a}.cg-result small{display:block;margin-top:3px;color:#64748b}@media(max-width:1050px){.cg-filter-grid{grid-template-columns:160px 160px minmax(300px,1fr)}.cg-filter-grid>.cg-btn{justify-self:start}.cg-stats{grid-template-columns:repeat(2,1fr)}}@media(max-width:760px){.cg-filter-grid{grid-template-columns:1fr 1fr}.cg-f.person{grid-column:1/-1}}@media(max-width:560px){.cg-filter-grid{grid-template-columns:1fr}.cg-f.person{grid-column:auto}.cg-inline{align-items:stretch;flex-direction:column}.cg-inline .cg-btn,.cg-filter-grid>.cg-btn{width:100%}.cg-stats{grid-template-columns:1fr}.cg-modal-search{align-items:stretch;flex-direction:column}.cg-modal-search .cg-btn{width:100%}}
</style>
<div class="cg">
  <div class="cg-card"><h1>Gerar Contratos</h1><div class="cg-sub">Selecione o período, revise as competências elegíveis e gere os pedidos individualmente ou em lote. Somente contratos com Status Cobrança = Manual aparecem nesta tela.</div></div>
  <?php if($ok!==''):?><div class="cg-alert ok"><?=contracts_e($ok)?><div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px"><a class="cg-btn success" href="fiscal_emissao_lote.php?tipo=NFSE&amp;origem=CONTRATOS&amp;data_ini=<?=contracts_e($today)?>&amp;data_fim=<?=contracts_e($today)?>">Ir para NFS-e em lote</a><a class="cg-btn" href="pedidos.php">Ir para Pedidos / Boletos</a></div></div><?php endif;?>
  <?php if($err!==''):?><div class="cg-alert err"><?=contracts_e($err)?></div><?php endif;?>
  <form method="get" class="cg-card"><div class="cg-filter-grid">
    <div class="cg-f"><label>Período inicial</label><input type="date" name="period_start" value="<?=contracts_e($periodStart)?>"></div>
    <div class="cg-f"><label>Período final</label><input type="date" name="period_end" value="<?=contracts_e($periodEnd)?>"></div>
    <div class="cg-f person"><label>Cliente</label><div class="cg-inline"><input type="hidden" id="person_id" name="person_id" value="<?=$personId?>"><input id="person_name" name="person_name" value="<?=contracts_e($personName)?>" placeholder="Todos os clientes" readonly><button class="cg-btn" type="button" id="personLookup" title="Buscar cliente (F8)">Buscar (F8)</button></div></div>
    <button class="cg-btn primary" type="submit">Filtrar</button>
    <a class="cg-btn" href="contratos_gerar.php">Limpar</a>
  </div></form>
  <div class="cg-stats"><div class="cg-stat"><small>Competências encontradas</small><strong><?=count($rows)?></strong></div><div class="cg-stat"><small>Pendentes</small><strong><?=count($pending)?></strong></div><div class="cg-stat"><small>Já geradas</small><strong><?=$generatedCount?></strong></div><div class="cg-stat"><small>Total pendente</small><strong><?=contracts_money($pendingTotal)?></strong></div></div>
  <form method="post" class="cg-card" id="generationForm"><input type="hidden" name="action" value="generate"><input type="hidden" name="csrf_v7" value="<?=contracts_e((string)$_SESSION['nexa_contract_bulk_v7_csrf'])?>"><input type="hidden" name="period_start" value="<?=contracts_e($periodStart)?>"><input type="hidden" name="period_end" value="<?=contracts_e($periodEnd)?>"><input type="hidden" name="person_id" value="<?=$personId?>"><input type="hidden" name="person_name" value="<?=contracts_e($personName)?>">
    <div class="cg-toolbar"><div><button type="button" class="cg-btn" id="selectAll">Marcar todos pendentes</button> <button type="button" class="cg-btn" id="clearAll">Desmarcar</button></div><button class="cg-btn success" type="submit" onclick="return confirm('Gerar os pedidos selecionados?')">Gerar pedidos selecionados</button></div>
    <div id="contract-v7-progress" role="status" aria-live="polite" style="display:none;margin:12px 0;padding:14px;border:1px solid #bfdbfe;border-radius:10px;background:#eff6ff">
      <strong id="contract-v7-status"></strong><progress id="contract-v7-bar" value="0" max="1" style="display:block;width:100%;height:14px;margin:8px 0"></progress>
      <div id="contract-v7-events" style="white-space:pre-wrap;max-height:190px;overflow:auto;font-size:12px"></div>
      <a id="contract-v7-refresh" class="cg-btn" href="#" style="display:none;margin-top:12px">Atualizar lista de competencias</a>
      <a id="contract-v7-boletos" class="cg-btn primary" href="financeiro_boletos.php" style="display:none;margin-top:12px">Ir para Boletos</a>
    </div>
    <div class="cg-table-wrap"><table><thead><tr><th style="width:42px"></th><th>Contrato</th><th>Cliente</th><th>Competência / Vencimento</th><th>Itens</th><th class="num">Valor</th><th>Status</th><th>Pedido</th></tr></thead><tbody>
    <?php if(!$rows):?><tr><td colspan="8" class="cg-muted">Nenhuma competência elegível encontrada para o período informado.</td></tr><?php endif;?>
    <?php foreach($rows as $r):$g=$r['generation'];$done=$g&&((int)($g['order_id']??0)>0||strtoupper((string)($g['status']??''))==='GERADO');?><tr>
      <td><?php if(!$done):?><input class="cg-check rowcheck" type="checkbox" name="selected[]" value="<?=contracts_e($r['key'])?>"><?php endif;?></td>
      <td><a class="cg-link" href="contrato_editar.php?id=<?=(int)$r['contract']['id']?>">#<?=contracts_e($r['contract']['contract_number'])?></a></td><td><?=contracts_e($r['contract']['billing_person_name']?:$r['contract']['contracting_person_name'])?></td><td><?=date('d/m/Y',strtotime($r['due_date']))?></td><td><?=$r['item_count']?> item(ns)</td><td class="num"><?=contracts_money($r['amount'])?></td><td><span class="cg-status <?=$done?'done':'pending'?>"><?=$done?'Gerado':'Pendente'?></span></td><td><?php if($done&&((int)$g['order_id']>0)):?><a class="cg-link" href="pedido.php?id=<?=(int)$g['order_id']?>">#<?=(int)$g['order_id']?></a><?php else:?>—<?php endif;?></td>
    </tr><?php endforeach;?></tbody></table></div>
  </form>
</div>
<div id="personModal" class="cg-modal-bg" aria-hidden="true"><div class="cg-modal" role="dialog" aria-modal="true" aria-labelledby="personModalTitle"><div class="cg-modal-head"><strong id="personModalTitle">Buscar cliente</strong><span style="flex:1"></span><button type="button" class="cg-btn" id="personClose">Fechar</button></div><div class="cg-modal-body"><div class="cg-modal-search"><input id="personSearch" autocomplete="off" placeholder="Digite nome, documento ou telefone"><button type="button" class="cg-btn primary" id="personSearchBtn">Pesquisar</button><a class="cg-btn" href="financeiro_pessoas.php?new=1" target="_blank" rel="noopener">Cadastrar</a></div><div class="cg-hint">Pesquisa vazia ao abrir. Enter pesquisa; ↑/↓ percorrem os registros; Enter seleciona. Se digitar novamente, Enter faz uma nova pesquisa.</div><div id="personResults" class="cg-results"><div class="cg-result cg-muted">Digite a pesquisa e pressione Enter.</div></div></div></div></div>
<script>
(()=>{
  const modal=document.getElementById('personModal'),q=document.getElementById('personSearch'),res=document.getElementById('personResults'),searchBtn=document.getElementById('personSearchBtn'),personId=document.getElementById('person_id'),personName=document.getElementById('person_name');
  let rows=[],idx=-1,last='__dirty__';
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  function open(){modal?.classList.add('open');modal?.setAttribute('aria-hidden','false');if(q){q.value='';q.dataset.last='__dirty__';}if(res)res.innerHTML='<div class="cg-result cg-muted">Digite a pesquisa e pressione Enter.</div>';rows=[];idx=-1;last='__dirty__';setTimeout(()=>q?.focus(),30)}
  function close(){modal?.classList.remove('open');modal?.setAttribute('aria-hidden','true')}
  function mark(){res?.querySelectorAll('[data-i]').forEach((el,i)=>el.classList.toggle('sel',i===idx));res?.querySelector('[data-i="'+idx+'"]')?.scrollIntoView({block:'nearest'})}
  function choose(i){const r=rows[i];if(!r)return;if(personId)personId.value=String(r.id??'');if(personName)personName.value=String(r.name??'');close();setTimeout(()=>personName?.focus(),20)}
  function draw(data){rows=Array.isArray(data)?data:[];idx=rows.length?0:-1;if(!res)return;res.innerHTML=rows.map((r,i)=>`<div class="cg-result ${i===0?'sel':''}" data-i="${i}"><strong>${esc(r.name||'')}</strong><small>${esc([(window.nexaMaskDoc||String)(r.document||''),r.phone||r.whatsapp].filter(Boolean).join(' · '))}</small></div>`).join('')||'<div class="cg-result cg-muted">Nenhum cliente encontrado.</div>';res.querySelectorAll('[data-i]').forEach(el=>el.addEventListener('click',()=>choose(Number(el.dataset.i))))}
  async function search(){if(!q||!res)return;searchBtn.disabled=true;res.innerHTML='<div class="cg-result cg-muted">Pesquisando...</div>';try{const resp=await fetch('contratos_ajax.php?action=people&role=customer&q='+encodeURIComponent(q.value.trim()),{headers:{'Accept':'application/json'}});const j=await resp.json();if(!resp.ok||j.ok===false)throw new Error(j.message||'Falha ao pesquisar clientes.');draw(j.rows||[]);last=q.value;q.dataset.last=q.value}catch(err){rows=[];idx=-1;res.innerHTML='<div class="cg-result cg-muted">'+esc(err?.message||'Não foi possível pesquisar clientes.')+'</div>'}finally{searchBtn.disabled=false;q.focus()}}
  document.getElementById('personLookup')?.addEventListener('click',open);document.getElementById('personClose')?.addEventListener('click',close);searchBtn?.addEventListener('click',search);
  q?.addEventListener('input',()=>{last='__dirty__';q.dataset.last='__dirty__';rows=[];idx=-1});
  q?.addEventListener('keydown',e=>{if(e.key==='ArrowDown'&&rows.length){e.preventDefault();idx=Math.min(rows.length-1,idx+1);mark();return}if(e.key==='ArrowUp'&&rows.length){e.preventDefault();idx=Math.max(0,idx-1);mark();return}if(e.key==='Enter'){e.preventDefault();if(rows.length&&idx>=0&&last===q.value)choose(idx);else search()}});
  modal?.addEventListener('mousedown',e=>{if(e.target===modal)close()});
  document.addEventListener('keydown',e=>{if(e.key==='F8'){e.preventDefault();if(modal?.classList.contains('open'))q?.focus();else open();return}if(e.key==='Escape'&&modal?.classList.contains('open')){e.preventDefault();close()}});
  document.getElementById('selectAll')?.addEventListener('click',()=>document.querySelectorAll('.rowcheck').forEach(c=>c.checked=true));document.getElementById('clearAll')?.addEventListener('click',()=>document.querySelectorAll('.rowcheck').forEach(c=>c.checked=false));
})();
</script>
<script>
// V7 - A geracao de muitos contratos passa por lotes de cinco, preservando
// o comportamento normal (POST) quando JavaScript esta indisponivel.
(()=>{
 const form=document.getElementById('generationForm');if(!form)return;
 const status=document.getElementById('contract-v7-status'),bar=document.getElementById('contract-v7-bar');
 const box=document.getElementById('contract-v7-progress'),logbox=document.getElementById('contract-v7-events');
 const refresh=document.getElementById('contract-v7-refresh'),goBoletos=document.getElementById('contract-v7-boletos');
 let working=false;
 refresh.href=window.location.href;
 window.addEventListener('beforeunload',e=>{if(working){e.preventDefault();e.returnValue='';}});
 const report=str=>{const row=document.createElement('div');row.textContent=str;logbox.appendChild(row);logbox.scrollTop=logbox.scrollHeight;};
 form.addEventListener('submit',async e=>{
  if(working){e.preventDefault();return;}
  const selected=Array.from(form.querySelectorAll('input.rowcheck:checked')).map(el=>el.value);
  if(!selected.length){e.preventDefault();alert('Selecione pelo menos uma competencia.');return;}
  e.preventDefault();working=true;box.style.display='block';logbox.textContent='';bar.max=selected.length;bar.value=0;refresh.style.display='none';goBoletos.style.display='none';
  form.querySelectorAll('button[type=submit]').forEach(b=>b.disabled=true);
  let generated=0,prepared=0,registered=0,errors=0,processed=0;
  const base=new FormData(form),csrf=base.get('csrf_v7');
  try{
   for(let i=0;i<selected.length;i+=5){
    const subset=selected.slice(i,i+5);
    status.textContent='Processando competencias '+(i+1)+' a '+Math.min(i+5,selected.length)+' de '+selected.length+'. Mantenha esta aba aberta.';
    const data=new FormData();
    data.append('action','generate_ajax_v7');data.append('csrf_v7',csrf);
    for(const field of ['period_start','period_end','person_id','person_name'])data.append(field,String(base.get(field)||''));
    subset.forEach(key=>data.append('selected[]',key));
    const response=await fetch('contratos_gerar.php',{method:'POST',body:data,credentials:'same-origin',cache:'no-store'});
    let result;try{result=await response.json();}catch(err){throw new Error('Resposta inesperada do servidor no grupo iniciado em '+(i+1)+'. Confira pedidos/boletos antes de reenviar.');}
    if(!response.ok || !result.ok)throw new Error(result.message||'Erro ao gerar grupo.');
    generated+=Number(result.generated||0);prepared+=Number(result.billing_prepared||0);registered+=Number(result.billing_registered||0);
    (result.errors||[]).forEach(message=>{errors++;report('ATENCAO: '+message);});
    processed+=subset.length;bar.value=processed;
    report('Grupo '+(Math.floor(i/5)+1)+': '+result.generated+' pedido(s), '+result.billing_registered+' boleto(s) registrado(s), '+result.billing_prepared+' boleto(s) preparado(s).');
   }
   status.textContent='Concluido: '+generated+' pedido(s) gerado(s), '+registered+' boleto(s) registrado(s) no banco, '+prepared+' preparado(s), '+errors+' ocorrencia(s) para conferir.';
  }catch(err){status.textContent='Processamento interrompido apos '+processed+' competencia(s): '+err.message+' Nao gere novamente sem consultar os pedidos e a fila de boletos.';}
  finally{working=false;refresh.style.display='inline-flex';goBoletos.style.display='inline-flex';form.querySelectorAll('button[type=submit]').forEach(b=>b.disabled=false);}
 });
})();
</script>
<?php admin_footer(); ?>

<?php
/**
 * Nexa Próspero V11.36.4 - modal reutilizável "Ler Orçamento".
 * Espera $orcamentoAjaxUrl preenchida pela tela chamadora.
 */
$orcamentoAjaxUrl = isset($orcamentoAjaxUrl) ? (string)$orcamentoAjaxUrl : '';
?>
<style>
.orc-reader-modal{position:fixed;inset:0;background:rgba(15,23,42,.58);z-index:390;display:none;align-items:center;justify-content:center;padding:18px}.orc-reader-modal.open{display:flex}.orc-reader-dialog{width:min(980px,100%);max-height:88vh;background:#fff;border-radius:24px;box-shadow:0 28px 80px rgba(2,6,23,.3);display:flex;flex-direction:column;overflow:hidden}.orc-reader-head{padding:18px 20px 13px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.orc-reader-head h2{margin:0;color:#0b1f5b}.orc-reader-head p{margin:4px 0 0;color:#64748b;font-size:13px}.orc-reader-close{border:0;background:#eef2f7;width:38px;height:38px;border-radius:12px;font-size:24px;line-height:1;cursor:pointer}.orc-reader-search{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:9px;padding:14px 20px;border-bottom:1px solid #eef2f7}.orc-reader-search input{min-height:44px;border:1px solid #cbd5e1;border-radius:13px;padding:0 13px;font-size:15px}.orc-reader-results{padding:12px 14px 18px;overflow:auto;display:grid;gap:7px}.orc-reader-row{width:100%;border:1px solid #dbe4f0;background:#fff;border-radius:15px;padding:11px 12px;display:grid;grid-template-columns:105px minmax(180px,1fr) 86px 112px 34px;gap:10px;align-items:center;text-align:left;cursor:pointer;color:#0f172a}.orc-reader-row:hover,.orc-reader-row:focus{border-color:#60a5fa;background:#f8fbff;outline:none}.orc-reader-number strong{display:block;color:#0b1f5b;font-size:15px}.orc-reader-number small,.orc-reader-customer small,.orc-reader-meta small{display:block;color:#64748b;font-size:11px;margin-top:2px}.orc-reader-customer strong{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.orc-reader-total{font-weight:900;text-align:right;color:#0f172a}.orc-reader-arrow{font-size:23px;color:#2563eb;text-align:center}.orc-reader-empty{padding:28px;text-align:center;color:#64748b}.orc-reader-status{font-size:11px;font-weight:800;color:#b45309}.orc-reader-loading{opacity:.65;pointer-events:none}@media(max-width:720px){.orc-reader-modal{align-items:flex-end;padding:0}.orc-reader-dialog{width:100%;max-height:92dvh;border-radius:24px 24px 0 0}.orc-reader-row{grid-template-columns:82px minmax(0,1fr) 90px}.orc-reader-meta{display:none}.orc-reader-arrow{display:none}.orc-reader-total{grid-column:3}.orc-reader-search{grid-template-columns:1fr}.orc-reader-search .btn{width:100%}}
</style>
<div class="orc-reader-modal" id="orcReaderModal" aria-hidden="true">
  <div class="orc-reader-dialog" role="dialog" aria-modal="true" aria-labelledby="orcReaderTitle">
    <div class="orc-reader-head">
      <div><h2 id="orcReaderTitle">Ler Orçamento</h2><p>Selecione um orçamento da empresa atual para carregar cliente, itens e condições de pagamento nesta tela.</p></div>
      <button type="button" class="orc-reader-close" id="orcReaderClose" aria-label="Fechar">×</button>
    </div>
    <div class="orc-reader-search">
      <input type="search" id="orcReaderQuery" placeholder="Número do orçamento, cliente, documento, telefone ou vendedor" autocomplete="off">
      <button type="button" class="btn btn-primary" id="orcReaderSearchBtn">Buscar (F8)</button>
    </div>
    <div class="orc-reader-results" id="orcReaderResults"><div class="orc-reader-empty">Digite a pesquisa e pressione Enter ou clique em Buscar.</div></div>
  </div>
</div>
<script>
(function(){
  const ajaxUrl = <?= json_encode($orcamentoAjaxUrl, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  const modal=document.getElementById('orcReaderModal'),query=document.getElementById('orcReaderQuery'),results=document.getElementById('orcReaderResults'),searchBtn=document.getElementById('orcReaderSearchBtn');
  if(!modal || !ajaxUrl) return;
  const esc=v=>String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
  const money=v=>'R$ '+Number(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
  const date=v=>{if(!v)return '';const p=String(v).slice(0,10).split('-');return p.length===3?p.reverse().join('/'):String(v)};
  async function getJson(url){const r=await fetch(url,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});const j=await r.json().catch(()=>({}));if(!r.ok||j.ok===false)throw new Error(j.error||j.message||'Não foi possível consultar os orçamentos.');return j}
  function close(){modal.classList.remove('open');modal.setAttribute('aria-hidden','true')}
  function open(){modal.classList.add('open');modal.setAttribute('aria-hidden','false');if(query)query.value='';results.innerHTML='<div class="orc-reader-empty">Digite a pesquisa e pressione Enter ou clique em Buscar.</div>';setTimeout(()=>query?.focus(),60)}
  async function list(q=''){
    q=String(q||'').trim();
    if(!q){ results.innerHTML='<div class="orc-reader-empty">Digite a pesquisa e pressione Enter ou clique em Buscar.</div>'; query?.focus(); return; }
    results.classList.add('orc-reader-loading'); results.innerHTML='<div class="orc-reader-empty">Consultando...</div>';
    try{
      const sep=ajaxUrl.includes('?')?'&':'?';const d=await getJson(ajaxUrl+sep+'action=list&q='+encodeURIComponent(q));const rows=d.rows||[];
      if(!rows.length){results.innerHTML='<div class="orc-reader-empty">Nenhum orçamento aberto/aprovado encontrado.</div>';return}
      results.innerHTML=rows.map(r=>`<button type="button" class="orc-reader-row" data-orc-reader-id="${Number(r.id||0)}"><div class="orc-reader-number"><strong>#${esc(r.quote_number||r.id)}</strong><small>${esc(date(r.quote_date))}</small><span class="orc-reader-status">${esc(r.status||'')}</span></div><div class="orc-reader-customer"><strong>${esc(r.person_name_snapshot||'Consumidor / cliente não informado')}</strong><small>${esc(r.person_document_snapshot||r.person_phone_snapshot||'')}</small></div><div class="orc-reader-meta"><strong>${Number(r.item_count||0)}</strong><small>item(ns)</small></div><div class="orc-reader-total">${money(r.total_general)}</div><div class="orc-reader-arrow">›</div></button>`).join('');
      results.querySelector('.orc-reader-row')?.focus();
    }catch(e){results.innerHTML='<div class="orc-reader-empty">'+esc(e.message||'Falha ao consultar orçamentos.')+'</div>'}finally{results.classList.remove('orc-reader-loading')}
  }
  async function load(id){
    results.classList.add('orc-reader-loading');
    try{
      const sep=ajaxUrl.includes('?')?'&':'?';const d=await getJson(ajaxUrl+sep+'action=load&id='+encodeURIComponent(id));const data=d.data||d;
      const warnings=Array.isArray(data.warnings)?data.warnings:[];
      if(warnings.length && !confirm('Atenção ao carregar este orçamento:\n\n• '+warnings.join('\n• ')+'\n\nDeseja continuar?')) return;
      if(typeof window.NexaOrcamentoApply!=='function') throw new Error('A tela atual não preparou a importação do orçamento.');
      const applied=await window.NexaOrcamentoApply(data);
      if(applied!==false) close();
    }catch(e){alert(e.message||'Falha ao carregar o orçamento.')}finally{results.classList.remove('orc-reader-loading')}
  }
  window.openOrcamentoReader=open;
  document.getElementById('orcReaderClose')?.addEventListener('click',close);
  modal.addEventListener('click',e=>{if(e.target===modal)close()});
  searchBtn?.addEventListener('click',()=>list(query?.value||''));
  query?.addEventListener('input',()=>{results.innerHTML='<div class="orc-reader-empty">Pressione Enter ou clique em Buscar para pesquisar.</div>'});
  query?.addEventListener('keydown',e=>{
    if(e.key==='Enter'){e.preventDefault();list(query.value)}
    else if(e.key==='ArrowDown'){const first=results.querySelector('.orc-reader-row');if(first){e.preventDefault();first.focus()}}
    else if(e.key==='Escape'){e.preventDefault();close()}
  });
  document.addEventListener('keydown',e=>{
    if(!modal.classList.contains('open')) return;
    if(e.key==='F8'){e.preventDefault();list(query?.value||'');return}
    const row=e.target?.closest?.('.orc-reader-row');
    if(row && (e.key==='ArrowDown'||e.key==='ArrowUp')){
      const rows=Array.from(results.querySelectorAll('.orc-reader-row')),i=rows.indexOf(row);if(i>=0){e.preventDefault();rows[(i+(e.key==='ArrowDown'?1:-1)+rows.length)%rows.length]?.focus()}return;
    }
    if(row && e.key==='Enter'){e.preventDefault();load(row.dataset.orcReaderId);return}
    if(row && e.key.length===1 && !e.ctrlKey && !e.altKey && !e.metaKey){e.preventDefault();query.value=e.key;query.focus();results.innerHTML='<div class="orc-reader-empty">Pressione Enter ou clique em Buscar para pesquisar.</div>';}
  });
  results.addEventListener('click',e=>{const row=e.target.closest('[data-orc-reader-id]');if(row)load(row.dataset.orcReaderId)});
})();
</script>

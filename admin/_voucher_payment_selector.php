<?php
/**
 * Seletor de vales reutilizável nas telas de venda.
 * Espera que cada option da forma de pagamento possua data-payment-type="VALE".
 */
$voucherPaymentScope = isset($voucherPaymentScope) ? strtolower(trim((string)$voucherPaymentScope)) : 'pdv';
$voucherPaymentEndpoint = 'financeiro_vales_ajax.php?scope=' . rawurlencode($voucherPaymentScope);
if (function_exists('multi_company_context_url')) $voucherPaymentEndpoint = multi_company_context_url($voucherPaymentEndpoint);
?>
<style>
.voucher-pay-modal{position:fixed;inset:0;z-index:520;display:none;align-items:flex-start;justify-content:center;padding:24px;background:rgba(15,23,42,.58);backdrop-filter:blur(3px);overflow:auto}.voucher-pay-modal.open{display:flex}.voucher-pay-dialog{width:min(900px,100%);background:#fff;border:1px solid #dbe4f0;border-radius:24px;box-shadow:0 26px 80px rgba(2,6,23,.34);padding:18px}.voucher-pay-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;margin-bottom:14px}.voucher-pay-head h2{margin:0;color:#0b1f5b;font-size:22px}.voucher-pay-head p{margin:5px 0 0;color:#64748b}.voucher-pay-close{width:42px;height:42px;border:1px solid #dbe4f0;border-radius:14px;background:#fff;color:#0f172a;font-size:22px;font-weight:900;cursor:pointer}.voucher-pay-info{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:12px}.voucher-pay-kpi{border:1px solid #dbe4f0;background:#f8fbff;border-radius:16px;padding:11px}.voucher-pay-kpi span{display:block;color:#64748b;font-size:12px;font-weight:800}.voucher-pay-kpi strong{display:block;color:#0b1f5b;font-size:18px;margin-top:3px}.voucher-pay-list{display:grid;gap:8px;max-height:46vh;overflow:auto;padding-right:3px}.voucher-pay-row{display:grid;grid-template-columns:34px minmax(0,1fr) 140px 150px;gap:10px;align-items:center;border:1px solid #dbe4f0;border-radius:16px;padding:11px;background:#fff}.voucher-pay-row.active{border-color:#86efac;background:#f0fdf4}.voucher-pay-row input[type=checkbox]{width:20px;height:20px}.voucher-pay-main strong{display:block;color:#0b1f5b}.voucher-pay-main small{display:block;color:#64748b;margin-top:3px}.voucher-pay-balance span,.voucher-pay-use label{display:block;color:#64748b;font-size:11px;font-weight:850;text-transform:uppercase}.voucher-pay-balance strong{display:block;color:#047857;font-size:17px;margin-top:2px}.voucher-pay-use input{width:100%;box-sizing:border-box;margin-top:4px}.voucher-pay-empty{padding:22px;border:1px dashed #cbd5e1;border-radius:16px;text-align:center;color:#64748b}.voucher-pay-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}.voucher-pay-line-tools{grid-column:1/-1;display:flex;align-items:center;justify-content:space-between;gap:8px;border:1px solid #bbf7d0;background:#f0fdf4;border-radius:13px;padding:8px 10px}.voucher-pay-line-tools span{font-size:12px;color:#166534;font-weight:800}.voucher-pay-line-tools button{border:0;background:#166534;color:#fff;border-radius:10px;padding:7px 10px;font-weight:850;cursor:pointer}.voucher-pay-line-tools.invalid{border-color:#fecaca;background:#fef2f2}.voucher-pay-line-tools.invalid span{color:#b91c1c}.voucher-pay-line-tools.invalid button{background:#b91c1c}@media(max-width:700px){.voucher-pay-modal{align-items:flex-end;padding:0}.voucher-pay-dialog{border-radius:26px 26px 0 0;max-height:92dvh;overflow:auto}.voucher-pay-info{grid-template-columns:1fr}.voucher-pay-row{grid-template-columns:30px minmax(0,1fr)}.voucher-pay-balance,.voucher-pay-use{grid-column:2}.voucher-pay-actions{display:grid;grid-template-columns:1fr 1fr}.voucher-pay-actions .btn{width:100%}}
</style>
<div class="voucher-pay-modal" id="voucherPayModal" aria-hidden="true">
  <div class="voucher-pay-dialog" role="dialog" aria-modal="true" aria-labelledby="voucherPayTitle">
    <div class="voucher-pay-head">
      <div><h2 id="voucherPayTitle">Selecionar vales</h2><p>Marque um ou mais vales e informe quanto deseja usar de cada um.</p></div>
      <button type="button" class="voucher-pay-close" id="voucherPayClose" aria-label="Fechar">×</button>
    </div>
    <div class="voucher-pay-info">
      <div class="voucher-pay-kpi"><span>Valor desta forma</span><strong id="voucherPayTarget">R$ 0,00</strong></div>
      <div class="voucher-pay-kpi"><span>Selecionado em vales</span><strong id="voucherPaySelected">R$ 0,00</strong></div>
      <div class="voucher-pay-kpi"><span>Saldo disponível</span><strong id="voucherPayAvailable">R$ 0,00</strong></div>
    </div>
    <div class="voucher-pay-list" id="voucherPayList"><div class="voucher-pay-empty">Carregando vales...</div></div>
    <div class="voucher-pay-actions">
      <button type="button" class="btn" id="voucherPayCancel">Cancelar</button>
      <button type="button" class="btn btn-primary" id="voucherPayConfirm">Usar vales selecionados</button>
    </div>
  </div>
</div>
<script>
(function(){
  const endpoint = <?= json_encode($voucherPaymentEndpoint, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  const modal = document.getElementById('voucherPayModal');
  const list = document.getElementById('voucherPayList');
  const targetEl = document.getElementById('voucherPayTarget');
  const selectedEl = document.getElementById('voucherPaySelected');
  const availableEl = document.getElementById('voucherPayAvailable');
  let activeLine = null;
  let activeTarget = 0;
  let loadingToken = 0;

  const money = value => {
    if(typeof value === 'number') return Number.isFinite(value) ? value : 0;
    let s = String(value ?? '').trim().replace(/\s+/g,'').replace(/R\$/gi,'');
    if(!s) return 0;
    if(s.includes(',')) s = s.replace(/\./g,'').replace(',','.');
    else if((s.match(/\./g)||[]).length > 1) s = s.replace(/\./g,'');
    const n = Number(s.replace(/[^0-9.-]/g,''));
    return Number.isFinite(n) ? n : 0;
  };
  const fmt = value => Number(value||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
  const fmtInput = value => Number(value||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
  const lineOf = el => el?.closest?.('.pdv-payment-line,.cmd-payment-line,.pdh-pay-row') || null;
  const selectedOption = select => select?.options?.[select.selectedIndex] || null;
  const isVoucherSelect = select => {
    const option = selectedOption(select);
    const type = String(option?.dataset?.paymentType || '').trim().toUpperCase();
    const code = String(option?.dataset?.paymentCode || '').trim().toUpperCase();
    const flag = String(option?.dataset?.isVoucher || '').trim();
    return type === 'VALE' || code === 'VALE' || flag === '1';
  };
  const customerId = () => Number(document.querySelector('input[name="customer_id"]')?.value || 0);

  function lineKey(line){ return String(line?.querySelector('.pay-line-key')?.value || ''); }
  function clearAllocations(line, invalid=false){
    if(!line) return;
    line.querySelectorAll('.voucher-pay-hidden').forEach(el=>el.remove());
    const tools=line.querySelector('.voucher-pay-line-tools');
    if(tools){
      tools.classList.toggle('invalid',!!invalid);
      const span=tools.querySelector('span');
      if(span) span.textContent=invalid?'A seleção de vales precisa ser atualizada.':'Nenhum vale selecionado.';
    }
  }
  function ensureTools(line){
    if(!line) return null;
    let tools=line.querySelector('.voucher-pay-line-tools');
    if(!tools){
      tools=document.createElement('div');
      tools.className='voucher-pay-line-tools invalid';
      tools.innerHTML='<span>Selecione os vales que serão utilizados.</span><button type="button" class="voucher-pay-open">Selecionar vales</button>';
      line.appendChild(tools);
    }
    return tools;
  }
  function removeTools(line){ if(line) line.querySelector('.voucher-pay-line-tools')?.remove(); }
  function currentSaved(line){
    const out={};
    if(!line) return out;
    line.querySelectorAll('input.voucher-pay-hidden[data-voucher-id]').forEach(el=>{
      const id=String(el.dataset.voucherId||'');
      const amountEl=line.querySelector('input.voucher-pay-hidden[data-voucher-amount="'+CSS.escape(id)+'"]');
      if(id) out[id]=money(amountEl?.value||0);
    });
    return out;
  }
  function updateSelected(){
    let total=0;
    list?.querySelectorAll('.voucher-pay-row').forEach(row=>{
      const cb=row.querySelector('.voucher-pay-check');
      const input=row.querySelector('.voucher-pay-amount');
      let v=cb?.checked?Math.max(0,money(input?.value||0)):0;
      const max=Number(row.dataset.balance||0);
      if(v>max) v=max;
      total+=v;
      row.classList.toggle('active',!!cb?.checked && v>0);
    });
    if(selectedEl) selectedEl.textContent=fmt(total);
    return Math.round(total*100)/100;
  }
  function close(){
    modal?.classList.remove('open');
    modal?.setAttribute('aria-hidden','true');
    activeLine=null; activeTarget=0;
  }
  async function openForLine(line){
    if(!line) return;
    if(modal?.classList.contains('open') && activeLine===line) return;
    const select=line.querySelector('.pay-method');
    if(!isVoucherSelect(select)) return;
    const person=customerId();
    if(person<=0){
      alert('Para usar Vale, selecione primeiro um cliente cadastrado na venda.');
      select.value='';
      clearAllocations(line,false); removeTools(line);
      select.dispatchEvent(new Event('change',{bubbles:true}));
      return;
    }
    activeLine=line;
    activeTarget=Math.max(0,Math.round(money(line.querySelector('.pay-amount')?.value||0)*100)/100);
    const tools=ensureTools(line);
    if(activeTarget<=0){
      tools?.classList.add('invalid');
      const span=tools?.querySelector('span');
      if(span) span.textContent='Informe primeiro o valor que será pago com Vale e depois selecione os vales.';
      line.querySelector('.pay-amount')?.focus();
      return;
    }
    modal?.classList.add('open'); modal?.setAttribute('aria-hidden','false');
    if(targetEl) targetEl.textContent=fmt(activeTarget);
    if(selectedEl) selectedEl.textContent=fmt(0);
    if(availableEl) availableEl.textContent='...';
    if(list) list.innerHTML='<div class="voucher-pay-empty">Carregando vales...</div>';
    const token=++loadingToken;
    try{
      const url=new URL(endpoint,window.location.href); url.searchParams.set('person_id',String(person));
      const response=await fetch(url.toString(),{credentials:'same-origin',headers:{'Accept':'application/json'}});
      const data=await response.json().catch(()=>({}));
      if(token!==loadingToken) return;
      if(!response.ok||!data.ok) throw new Error(data.error||'Não foi possível consultar os vales.');
      const rows=Array.isArray(data.rows)?data.rows:[];
      const available=rows.reduce((sum,row)=>sum+Number(row.balance_amount||0),0);
      if(availableEl) availableEl.textContent=fmt(available);
      if(!rows.length){ if(list) list.innerHTML='<div class="voucher-pay-empty">Este cliente não possui vales com saldo disponível.</div>'; return; }
      const saved=currentSaved(line);
      let remaining=activeTarget;
      const html=rows.map(row=>{
        const id=String(Number(row.id||0)); const balance=Math.max(0,Number(row.balance_amount||0));
        let use=Object.prototype.hasOwnProperty.call(saved,id)?Math.min(balance,Math.max(0,Number(saved[id]||0))):0;
        if(!Object.keys(saved).length && remaining>0){ use=Math.min(balance,remaining); remaining=Math.max(0,Math.round((remaining-use)*100)/100); }
        const checked=use>0;
        const date=String(row.issue_date||'').split('-'); const dateBr=date.length===3?date.reverse().join('/'):(row.issue_date||'');
        const safeCode=String(row.code||('Vale #'+id)).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
        return `<div class="voucher-pay-row${checked?' active':''}" data-id="${id}" data-balance="${balance}"><input type="checkbox" class="voucher-pay-check" ${checked?'checked':''}><div class="voucher-pay-main"><strong>${safeCode}</strong><small>Emitido em ${dateBr||'-'} · Valor original ${fmt(Number(row.original_amount||0))}</small></div><div class="voucher-pay-balance"><span>Saldo</span><strong>${fmt(balance)}</strong></div><div class="voucher-pay-use"><label>Usar</label><input class="voucher-pay-amount" inputmode="decimal" value="${fmtInput(use)}" ${checked?'':'disabled'}></div></div>`;
      }).join('');
      if(list) list.innerHTML=html;
      updateSelected();
      list?.querySelector('.voucher-pay-amount:not([disabled])')?.focus();
    }catch(err){ if(list) list.innerHTML='<div class="voucher-pay-empty">'+String(err?.message||'Falha ao consultar vales.')+'</div>'; if(availableEl) availableEl.textContent=fmt(0); }
  }
  function apply(){
    if(!activeLine) return;
    const key=lineKey(activeLine); if(!key){ alert('Não foi possível identificar a linha de pagamento.'); return; }
    const picked=[]; let sum=0;
    list?.querySelectorAll('.voucher-pay-row').forEach(row=>{
      const cb=row.querySelector('.voucher-pay-check'); if(!cb?.checked) return;
      const id=Number(row.dataset.id||0), max=Number(row.dataset.balance||0); let amount=Math.round(money(row.querySelector('.voucher-pay-amount')?.value||0)*100)/100;
      if(amount<=0||id<=0) return;
      if(amount>max+0.009){ amount=-1; }
      picked.push({id,amount,max}); if(amount>0) sum+=amount;
    });
    if(picked.some(x=>x.amount<0)){ alert('O valor utilizado não pode ultrapassar o saldo do vale.'); return; }
    sum=Math.round(sum*100)/100;
    if(sum<=0){ alert('Selecione pelo menos um vale e informe um valor a utilizar.'); return; }
    if(sum>activeTarget+0.009){ alert('O total selecionado em vales não pode ser maior que o valor desta forma de pagamento.'); return; }
    clearAllocations(activeLine,false);
    picked.filter(x=>x.amount>0).forEach(item=>{
      const idInput=document.createElement('input'); idInput.type='hidden'; idInput.name=`voucher_id[${key}][]`; idInput.value=String(item.id); idInput.className='voucher-pay-hidden'; idInput.dataset.voucherId=String(item.id);
      const amountInput=document.createElement('input'); amountInput.type='hidden'; amountInput.name=`voucher_amount[${key}][]`; amountInput.value=Number(item.amount).toFixed(2); amountInput.className='voucher-pay-hidden'; amountInput.dataset.voucherAmount=String(item.id);
      activeLine.append(idInput,amountInput);
    });
    const amountField=activeLine.querySelector('.pay-amount');
    if(amountField){ amountField.dataset.voucherApplying='1'; amountField.value=fmtInput(sum); amountField.dataset.autoSync='0'; amountField.dispatchEvent(new Event('input',{bubbles:true})); amountField.dispatchEvent(new Event('change',{bubbles:true})); delete amountField.dataset.voucherApplying; }
    const tools=ensureTools(activeLine); tools?.classList.remove('invalid');
    const codes=picked.filter(x=>x.amount>0).map(x=>{const row=list?.querySelector(`.voucher-pay-row[data-id="${x.id}"]`);return row?.querySelector('.voucher-pay-main strong')?.textContent||('#'+x.id);});
    const span=tools?.querySelector('span'); if(span) span.textContent=`${codes.join(', ')} · ${fmt(sum)} selecionados`;
    close();
  }

  document.addEventListener('change',e=>{
    const select=e.target?.closest?.('select.pay-method');
    if(select){
      const line=lineOf(select); if(!line) return;
      if(isVoucherSelect(select)){ clearAllocations(line,false); ensureTools(line); setTimeout(()=>openForLine(line),0); }
      else { clearAllocations(line,false); removeTools(line); }
      return;
    }
    if(e.target?.classList?.contains('pay-amount')){
      const line=lineOf(e.target), method=line?.querySelector('.pay-method');
      if(line && isVoucherSelect(method) && !line.querySelector('.voucher-pay-hidden') && money(e.target.value)>0 && !modal?.classList.contains('open')){
        setTimeout(()=>openForLine(line),0);
      }
      return;
    }
    if(e.target?.classList?.contains('voucher-pay-check')){
      const row=e.target.closest('.voucher-pay-row'), input=row?.querySelector('.voucher-pay-amount');
      if(input){ input.disabled=!e.target.checked; if(e.target.checked && money(input.value)<=0) input.value=fmtInput(Math.min(Number(row.dataset.balance||0),Math.max(0,activeTarget-updateSelected()))); }
      updateSelected();
    }
  },true);
  document.addEventListener('input',e=>{
    if(e.target?.classList?.contains('voucher-pay-amount')){ updateSelected(); return; }
    if(!e.target?.classList?.contains('pay-amount') || e.target.dataset.voucherApplying==='1') return;
    const line=lineOf(e.target), select=line?.querySelector('.pay-method');
    if(line && isVoucherSelect(select) && line.querySelector('.voucher-pay-hidden')) clearAllocations(line,true);
  },true);
  document.addEventListener('click',e=>{
    const openBtn=e.target?.closest?.('.voucher-pay-open'); if(openBtn){ e.preventDefault(); openForLine(lineOf(openBtn)); return; }
    if(e.target===modal){ close(); return; }
  });
  document.getElementById('voucherPayClose')?.addEventListener('click',close);
  document.getElementById('voucherPayCancel')?.addEventListener('click',close);
  document.getElementById('voucherPayConfirm')?.addEventListener('click',apply);
  document.addEventListener('keydown',e=>{ if(e.key==='Escape' && modal?.classList.contains('open')){ e.preventDefault(); close(); } });

  document.addEventListener('submit',e=>{
    const form=e.target; if(!(form instanceof HTMLFormElement)) return;
    for(const select of form.querySelectorAll('select.pay-method')){
      if(!isVoucherSelect(select)) continue;
      const line=lineOf(select), amount=Math.round(money(line?.querySelector('.pay-amount')?.value||0)*100)/100;
      const allocations=Array.from(line?.querySelectorAll('input.voucher-pay-hidden[data-voucher-amount]')||[]).reduce((s,el)=>s+money(el.value),0);
      if(customerId()<=0 || amount<=0 || allocations<=0 || Math.abs(amount-allocations)>0.01){
        e.preventDefault(); e.stopImmediatePropagation();
        if(customerId()<=0){ alert('Pagamento com Vale exige um cliente cadastrado.'); }
        else { alert('Selecione os vales que serão utilizados antes de finalizar a venda.'); openForLine(line); }
        return;
      }
    }
  },true);
})();
</script>

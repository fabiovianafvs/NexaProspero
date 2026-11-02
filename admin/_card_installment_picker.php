<?php
declare(strict_types=1);
/**
 * V11.52.7 - Seletor compartilhado de parcelamento do Controle de Cartões.
 * Requer: $pdo, $companyId e $payments já carregados pela tela de venda.
 */
require_once __DIR__ . '/../config/card_control.php';
$__cardPickerMap = card_control_payment_map($pdo, (int)$companyId, is_array($payments ?? null) ? $payments : []);
if ($__cardPickerMap):
$__cardPickerJson = json_encode($__cardPickerMap, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '{}';
?>
<style>
  .nexa-card-plan-modal{position:fixed;inset:0;z-index:7250;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(7,24,77,.55);backdrop-filter:blur(5px)}
  .nexa-card-plan-modal.open{display:flex}.nexa-card-plan-dialog{width:min(610px,100%);max-height:92vh;overflow:auto;background:#fff;border-radius:22px;border:1px solid #d9e5f2;box-shadow:0 28px 90px rgba(7,24,77,.28);padding:18px}
  .nexa-card-plan-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:12px}.nexa-card-plan-head h3{margin:0;color:#07184d;font-size:20px}.nexa-card-plan-head p{margin:4px 0 0;color:#64748b;font-size:13px}.nexa-card-plan-close{border:1px solid #dbe4f0;background:#fff;border-radius:12px;min-width:42px;height:42px;font-size:20px;cursor:pointer;color:#334155}
  .nexa-card-plan-summary{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 13px;margin-bottom:10px;border-radius:14px;background:#f5f9ff;border:1px solid #d7e5f4}.nexa-card-plan-summary span{font-size:12px;color:#64748b;font-weight:800}.nexa-card-plan-summary strong{font-size:21px;color:#0b348f}
  .nexa-card-plan-options{display:grid;gap:8px}.nexa-card-plan-option{width:100%;text-align:left;border:1px solid #dbe5f0;background:#fff;border-radius:14px;padding:12px 14px;cursor:pointer;display:grid;grid-template-columns:minmax(130px,.8fr) minmax(150px,1.2fr) auto;gap:10px;align-items:center;color:#172554}.nexa-card-plan-option:hover,.nexa-card-plan-option:focus{border-color:#3b82f6;background:#f8fbff;outline:none}.nexa-card-plan-option .main{font-weight:950;font-size:15px}.nexa-card-plan-option .meta{font-size:12px;color:#64748b;line-height:1.35}.nexa-card-plan-option .net{font-size:12px;text-align:right;color:#0f766e;font-weight:850}
  .nexa-card-plan-badge{display:inline-flex;align-items:center;gap:5px;margin-top:5px;padding:4px 8px;border-radius:999px;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;font-size:11px;font-weight:900;cursor:pointer;max-width:100%}
  .nexa-card-plan-badge::before{content:'💳';font-size:11px}
  @media(max-width:560px){.nexa-card-plan-dialog{padding:14px;border-radius:18px}.nexa-card-plan-option{grid-template-columns:1fr}.nexa-card-plan-option .net{text-align:left}.nexa-card-plan-head h3{font-size:18px}}
</style>
<div class="nexa-card-plan-modal" id="nexaCardPlanModal" aria-hidden="true">
  <div class="nexa-card-plan-dialog" role="dialog" aria-modal="true" aria-labelledby="nexaCardPlanTitle">
    <div class="nexa-card-plan-head">
      <div><h3 id="nexaCardPlanTitle">Parcelamento do cartão</h3><p id="nexaCardPlanSubtitle">Selecione como esta venda será recebida pela operadora.</p></div>
      <button type="button" class="nexa-card-plan-close" id="nexaCardPlanClose" aria-label="Fechar">×</button>
    </div>
    <div class="nexa-card-plan-summary"><span>Valor nesta forma de pagamento</span><strong id="nexaCardPlanAmount">R$ 0,00</strong></div>
    <div class="nexa-card-plan-options" id="nexaCardPlanOptions"></div>
  </div>
</div>
<script>
(()=>{
  const cardMap = <?= $__cardPickerJson ?>;
  const modal = document.getElementById('nexaCardPlanModal');
  const optionsEl = document.getElementById('nexaCardPlanOptions');
  const amountEl = document.getElementById('nexaCardPlanAmount');
  const subtitleEl = document.getElementById('nexaCardPlanSubtitle');
  let activeLine = null;
  let lastFocus = null;
  const money = value => Number(value||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
  const parseMoney = value => {
    let s=String(value??'').trim().replace(/R\$|\s/g,'');
    if(s.includes(',') && s.includes('.')) s=s.replace(/\./g,'').replace(',','.');
    else if(s.includes(',')) s=s.replace(',','.');
    const n=Number(s); return Number.isFinite(n)?n:0;
  };
  const lineOf = el => el?.closest?.('.pdv-payment-line,.cmd-payment-line,.pdh-pay-row') || null;
  const lineKey = line => String(line?.querySelector('.pay-line-key')?.value || '0').replace(/[^a-zA-Z0-9_-]/g,'') || '0';
  const methodId = line => String(line?.querySelector('.pay-method')?.value || '');
  const lineAmount = line => parseMoney(line?.querySelector('.pay-amount')?.value || '0');
  function hiddenInput(line){
    let input=line?.querySelector('.card-installment-count');
    if(!input && line){
      input=document.createElement('input'); input.type='hidden'; input.className='card-installment-count';
      input.name=`card_installment_count[${lineKey(line)}]`; input.value=''; line.appendChild(input);
    } else if(input){ input.name=`card_installment_count[${lineKey(line)}]`; }
    return input;
  }
  function badge(line){
    let b=line?.querySelector('.nexa-card-plan-badge');
    if(!b && line){ b=document.createElement('button'); b.type='button'; b.className='nexa-card-plan-badge'; b.addEventListener('click',()=>openPicker(line,true)); const target=line.querySelector('.pay-value') || line.querySelector('label') || line; target.appendChild(b); }
    return b;
  }
  function clearLine(line){ const h=line?.querySelector('.card-installment-count'); if(h) h.value=''; const b=line?.querySelector('.nexa-card-plan-badge'); if(b) b.remove(); }
  function planByCount(cfg,count){ return (cfg?.plans||[]).find(p=>Number(p.installments)===Number(count)) || null; }
  function renderBadge(line,plan){
    if(!line||!plan)return; const b=badge(line), amount=lineAmount(line), count=Math.max(1,Number(plan.installments||1));
    b.textContent=`${count}x cartão · ${money(amount/count)}`; b.title='Clique para alterar o parcelamento do cartão';
  }
  function selectPlan(line,plan){
    const h=hiddenInput(line); if(!h||!plan)return; h.value=String(plan.installments); renderBadge(line,plan); closePicker();
    line.dispatchEvent(new CustomEvent('nexa:card-plan-selected',{bubbles:true,detail:{installments:Number(plan.installments)}}));
  }
  function openPicker(line,force=false){
    if(!line)return false; const cfg=cardMap[methodId(line)]; if(!cfg){ clearLine(line); return false; }
    const plans=Array.isArray(cfg.plans)?cfg.plans:[]; if(!plans.length)return false;
    const h=hiddenInput(line); const current=planByCount(cfg,h?.value||0);
    if(plans.length===1 && !force){ selectPlan(line,plans[0]); return true; }
    activeLine=line; lastFocus=document.activeElement; const amount=lineAmount(line);
    amountEl.textContent=money(amount); subtitleEl.textContent=(cfg.name||'Cartão')+(cfg.acquirer?` · ${cfg.acquirer}`:''); optionsEl.innerHTML='';
    plans.forEach(plan=>{
      const n=Math.max(1,Number(plan.installments||1)); const fee=Math.max(0,Number(plan.feePercent||0)); const feeValue=amount*fee/100; const net=amount-feeValue;
      const btn=document.createElement('button'); btn.type='button'; btn.className='nexa-card-plan-option';
      const installmentValue=amount/n;
      btn.innerHTML=`<span class="main">${n} x ${money(installmentValue)}</span><span class="meta">1º recebimento: ${Number(plan.firstDays||0)} dia(s)<br>Intervalo: ${Number(plan.intervalDays||0)} dia(s) · Taxa: ${fee.toLocaleString('pt-BR',{minimumFractionDigits:0,maximumFractionDigits:4})}%</span><span class="net">Líquido previsto<br>${money(net)}</span>`;
      if(current && Number(current.installments)===n) btn.setAttribute('aria-current','true');
      btn.addEventListener('click',()=>selectPlan(line,plan)); optionsEl.appendChild(btn);
    });
    modal.classList.add('open'); modal.setAttribute('aria-hidden','false'); setTimeout(()=>optionsEl.querySelector('button')?.focus(),20); return true;
  }
  function closePicker(){ modal.classList.remove('open'); modal.setAttribute('aria-hidden','true'); activeLine=null; if(lastFocus && document.contains(lastFocus)) setTimeout(()=>lastFocus.focus(),0); }
  function processMethod(select){
    const line=lineOf(select); if(!line)return; const cfg=cardMap[String(select.value||'')];
    if(!cfg){ clearLine(line); return; }
    const h=hiddenInput(line), selected=planByCount(cfg,h?.value||0);
    if(selected){ renderBadge(line,selected); return; }
    openPicker(line,false);
  }
  document.addEventListener('change',e=>{ if(e.target?.classList?.contains('pay-method')) setTimeout(()=>processMethod(e.target),0); },true);
  document.addEventListener('input',e=>{ if(e.target?.classList?.contains('pay-amount')){ const line=lineOf(e.target); if(!line)return; const cfg=cardMap[methodId(line)], h=line?.querySelector('.card-installment-count'), p=planByCount(cfg,h?.value||0); if(p)renderBadge(line,p); } },true);
  document.addEventListener('submit',e=>{
    const form=e.target; if(!(form instanceof HTMLFormElement))return; const selects=[...form.querySelectorAll('.pay-method')];
    for(const sel of selects){ const line=lineOf(sel), cfg=cardMap[String(sel.value||'')]; if(!line||!cfg)continue; const h=hiddenInput(line), p=planByCount(cfg,h?.value||0); if(!p){ e.preventDefault(); e.stopImmediatePropagation(); openPicker(line,false); return false; } }
  },true);
  document.getElementById('nexaCardPlanClose')?.addEventListener('click',closePicker);
  modal?.addEventListener('click',e=>{if(e.target===modal)closePicker()});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&modal?.classList.contains('open'))closePicker()});
  window.NexaCardInstallments={open:openPicker,refresh:()=>document.querySelectorAll('.pay-method').forEach(processMethod)};
  setTimeout(()=>document.querySelectorAll('.pay-method').forEach(sel=>{if(sel.value)processMethod(sel)}),50);
})();
</script>
<?php endif; ?>

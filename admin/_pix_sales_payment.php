<?php
declare(strict_types=1);

/**
 * Widget compartilhado de Pix imediato para vendas.
 *
 * Antes de incluir este arquivo, defina $pixSalesWidget com:
 * form_id, line_selector, method_selector, amount_selector, payments,
 * self_url, mc_csrf, mc_ctx e label (opcional).
 */
$cfg = is_array($pixSalesWidget ?? null) ? $pixSalesWidget : [];
$formId = trim((string)($cfg['form_id'] ?? ''));
$lineSelector = trim((string)($cfg['line_selector'] ?? ''));
$methodSelector = trim((string)($cfg['method_selector'] ?? ''));
$amountSelector = trim((string)($cfg['amount_selector'] ?? ''));
$selfUrl = trim((string)($cfg['self_url'] ?? ''));
$mcCsrf = (string)($cfg['mc_csrf'] ?? '');
$mcCtx = (string)($cfg['mc_ctx'] ?? '');
$label = trim((string)($cfg['label'] ?? 'Pagamento Pix')) ?: 'Pagamento Pix';
$paymentRows = is_array($cfg['payments'] ?? null) ? $cfg['payments'] : [];
$pixMethods = [];
foreach ($paymentRows as $p) {
  $id = (int)($p['id'] ?? 0);
  if ($id <= 0 || empty($p['pix_auto_qr_enabled'])) continue;
  $pixMethods[(string)$id] = [
    'id'=>$id,
    'name'=>(string)($p['name'] ?? 'Pix'),
    'pixAutoQr'=>1,
    'provider'=>(string)($p['pix_provider'] ?? ''),
    'expiration'=>(int)($p['pix_expiration_seconds'] ?? 300),
  ];
}
if ($formId === '' || $lineSelector === '' || $methodSelector === '' || $amountSelector === '' || $selfUrl === '' || !$pixMethods) return;
$widgetId = 'nexaSalesPix_' . substr(sha1($formId . '|' . $selfUrl), 0, 10);
?>
<style>
#<?= htmlspecialchars($widgetId, ENT_QUOTES, 'UTF-8') ?>{position:fixed;inset:0;z-index:1200;background:rgba(7,24,77,.54);backdrop-filter:blur(5px);display:none;align-items:center;justify-content:center;padding:18px;box-sizing:border-box}
#<?= htmlspecialchars($widgetId, ENT_QUOTES, 'UTF-8') ?>.open{display:flex}
#<?= htmlspecialchars($widgetId, ENT_QUOTES, 'UTF-8') ?> .nexa-pix-dialog{width:min(540px,100%);max-height:94vh;overflow:auto;background:#fff;border-radius:24px;padding:18px;box-shadow:0 30px 90px rgba(7,24,77,.34);box-sizing:border-box}
#<?= htmlspecialchars($widgetId, ENT_QUOTES, 'UTF-8') ?> .nexa-pix-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:12px}
#<?= htmlspecialchars($widgetId, ENT_QUOTES, 'UTF-8') ?> .nexa-pix-head h2{margin:0;color:#07184d;font-size:24px}.nexa-pix-muted{color:#64748b;font-size:12px;margin:4px 0 0}
#<?= htmlspecialchars($widgetId, ENT_QUOTES, 'UTF-8') ?> .nexa-pix-close{border:0;background:#eef4fb;color:#173a70;border-radius:12px;width:40px;height:40px;font-size:22px;cursor:pointer}
#<?= htmlspecialchars($widgetId, ENT_QUOTES, 'UTF-8') ?> .nexa-pix-body{display:grid;gap:12px}.nexa-pix-amount{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 15px;border:1px solid #cfe0f4;border-radius:14px;background:linear-gradient(135deg,#f6fbff,#eaf4ff)}.nexa-pix-amount span{font-size:13px;font-weight:850;color:#55708e}.nexa-pix-amount strong{font-size:25px;color:#075e0e}
#<?= htmlspecialchars($widgetId, ENT_QUOTES, 'UTF-8') ?> .nexa-pix-qr{width:min(280px,72vw);height:min(280px,72vw);margin:0 auto;display:flex;align-items:center;justify-content:center;border:1px solid #d7e2f0;border-radius:18px;background:#fff;padding:10px;box-sizing:border-box;box-shadow:inset 0 0 0 6px #f7fbff}.nexa-pix-qr img{width:100%;height:100%;object-fit:contain}.nexa-pix-placeholder{color:#6b7f99;font-weight:850;text-align:center;padding:24px}
#<?= htmlspecialchars($widgetId, ENT_QUOTES, 'UTF-8') ?> .nexa-pix-status{display:flex;align-items:center;gap:11px;padding:12px 14px;border-radius:14px;border:1px solid #bfdbfe;background:#eff6ff;color:#174f8b}.nexa-pix-status strong,.nexa-pix-status small{display:block}.nexa-pix-status small{margin-top:2px;font-size:12px;color:#55708e}.nexa-pix-status.paid{border-color:#86efac;background:#f0fdf4;color:#08752d}.nexa-pix-status.error{border-color:#fecaca;background:#fef2f2;color:#b91c1c}.nexa-pix-status.expired{border-color:#fed7aa;background:#fff7ed;color:#b45309}.nexa-pix-dot{width:13px;height:13px;border-radius:50%;background:#1684e8;flex:0 0 auto}.nexa-pix-status.paid .nexa-pix-dot{background:#16a34a}.nexa-pix-status.error .nexa-pix-dot,.nexa-pix-status.expired .nexa-pix-dot{background:currentColor}
#<?= htmlspecialchars($widgetId, ENT_QUOTES, 'UTF-8') ?> .nexa-pix-copy{display:grid;gap:6px;font-size:12px;font-weight:900;color:#174f8b}.nexa-pix-copy textarea{width:100%;resize:none;border:1px solid #c5d5e8;border-radius:12px;padding:10px 12px;background:#fbfdff;color:#334155;font:700 12px/1.35 ui-monospace,SFMono-Regular,Menlo,monospace;box-sizing:border-box}.nexa-pix-meta{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;color:#64748b;font-size:11px}.nexa-pix-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;margin-top:14px}.nexa-pix-actions button{min-height:42px}
</style>
<div id="<?= htmlspecialchars($widgetId, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true">
  <div class="nexa-pix-dialog" role="dialog" aria-modal="true" aria-labelledby="<?= htmlspecialchars($widgetId, ENT_QUOTES, 'UTF-8') ?>Title">
    <div class="nexa-pix-head"><div><h2 id="<?= htmlspecialchars($widgetId, ENT_QUOTES, 'UTF-8') ?>Title"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></h2><p class="nexa-pix-muted">Leia o QR Code no aplicativo do banco. A venda só será finalizada após a confirmação do pagamento.</p></div><button type="button" class="nexa-pix-close" data-pix-close aria-label="Fechar">×</button></div>
    <div class="nexa-pix-body">
      <div class="nexa-pix-amount"><span>Valor desta cobrança</span><strong data-pix-amount>R$ 0,00</strong></div>
      <div class="nexa-pix-qr"><img data-pix-qr alt="QR Code Pix" hidden><div class="nexa-pix-placeholder" data-pix-placeholder>Gerando QR Code...</div></div>
      <div class="nexa-pix-status" data-pix-status><span class="nexa-pix-dot"></span><div><strong data-pix-status-title>Aguardando pagamento</strong><small data-pix-status-detail>Consultando confirmação no banco...</small></div></div>
      <label class="nexa-pix-copy">Pix Copia e Cola<textarea data-pix-copy rows="3" readonly placeholder="O código Pix aparecerá aqui."></textarea></label>
      <div class="nexa-pix-meta"><span data-pix-txid></span><span data-pix-expires></span></div>
    </div>
    <div class="nexa-pix-actions"><button type="button" class="btn" data-pix-close>Fechar</button><button type="button" class="btn" data-pix-copy-btn>Copiar código</button><button type="button" class="btn btn-primary" data-pix-verify>Verificar agora</button></div>
  </div>
</div>
<script>
(function(){
  const form=document.getElementById(<?= json_encode($formId) ?>);
  if(!form) return;
  const modal=document.getElementById(<?= json_encode($widgetId) ?>);
  const methods=<?= json_encode($pixMethods, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?> || {};
  const lineSelector=<?= json_encode($lineSelector) ?>, methodSelector=<?= json_encode($methodSelector) ?>, amountSelector=<?= json_encode($amountSelector) ?>;
  const apiUrl=<?= json_encode($selfUrl, JSON_UNESCAPED_SLASHES) ?>, mcCsrf=<?= json_encode($mcCsrf) ?>, mcCtx=<?= json_encode($mcCtx) ?>;
  let activeLine=null, activeCharge=0, pollTimer=null, busy=false, completing=false;
  const q=(s)=>modal?.querySelector(s), money=(v)=>Number(v||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
  function parseMoney(v){let s=String(v||'0').trim().replace(/[^0-9,.-]/g,'');if(s.includes(',')){s=s.replace(/\./g,'').replace(',','.')}const n=Number(s);return Number.isFinite(n)?Math.round(n*100)/100:0}
  function lines(){return Array.from(form.querySelectorAll(lineSelector))}
  function methodInfo(line){const id=String(line?.querySelector(methodSelector)?.value||'');return {id,method:methods[id]||null}}
  function ensureHidden(line){let h=line?.querySelector('.nexa-sales-pix-charge-id');if(!h&&line){h=document.createElement('input');h.type='hidden';h.name='payment_pix_charge_id[]';h.className='nexa-sales-pix-charge-id';h.value='';line.appendChild(h)}return h}
  function ensureAll(){lines().forEach(ensureHidden)}
  function reset(line){const h=ensureHidden(line);if(h){h.value='';delete h.dataset.amount;delete h.dataset.method;delete h.dataset.paid}}
  function pendingLine(){ensureAll();for(const line of lines()){const {id,method}=methodInfo(line);if(!method)continue;const amount=parseMoney(line.querySelector(amountSelector)?.value||0);if(amount<=0)continue;const h=ensureHidden(line);if(h?.value){const sameMethod=String(h.dataset.method||'')===id;const sameAmount=Math.abs(Number(h.dataset.amount||0)-amount)<=.009;if(sameMethod&&sameAmount&&h.dataset.paid==='1')continue;if(!sameMethod||!sameAmount)reset(line)}return line}return null}
  function status(kind,title,detail){const box=q('[data-pix-status]');if(!box)return;box.classList.remove('paid','error','expired');if(kind)box.classList.add(kind);q('[data-pix-status-title]').textContent=title||'Aguardando pagamento';q('[data-pix-status-detail]').textContent=detail||''}
  function render(charge){q('[data-pix-amount]').textContent=money(charge?.amount||0);q('[data-pix-copy]').value=String(charge?.copy_paste||'');q('[data-pix-txid]').textContent=charge?.txid?'TXID: '+charge.txid:'';let exp='';if(charge?.expires_at){const d=new Date(String(charge.expires_at).replace(' ','T'));if(!Number.isNaN(d.getTime()))exp='Expira às '+d.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit',second:'2-digit'})}q('[data-pix-expires]').textContent=exp;const img=q('[data-pix-qr]'),ph=q('[data-pix-placeholder]');if(charge?.qr_image){img.src=String(charge.qr_image);img.hidden=false;ph.hidden=true}else{img.hidden=true;ph.hidden=false;ph.textContent=charge?.copy_paste?'Use o Pix Copia e Cola abaixo.':'Aguardando QR Code do banco...'}if(charge?.paid)status('paid','Pagamento confirmado','Confirmação recebida do banco. Finalizando a venda...');else if(charge?.expired)status('expired','QR Code expirado','Feche e finalize novamente para gerar uma nova cobrança.');else if(String(charge?.status||'').toUpperCase()==='ERRO')status('error','Falha na cobrança',charge?.message||'Não foi possível confirmar esta cobrança Pix.');else status('', 'Aguardando pagamento', charge?.provider_status?'Status no banco: '+charge.provider_status:'A confirmação será verificada automaticamente.')}
  async function post(action,payload={}){const fd=new FormData();fd.append('action',action);fd.append('mc_csrf',mcCsrf);fd.append('mc_ctx',mcCtx);Object.entries(payload).forEach(([k,v])=>fd.append(k,String(v??'')));const r=await fetch(apiUrl,{method:'POST',body:fd,credentials:'same-origin',cache:'no-store'});const j=await r.json().catch(()=>({}));if(!r.ok||!j.ok)throw new Error(j.error||'Não foi possível comunicar com o serviço Pix.');return j}
  function stop(){if(pollTimer){clearInterval(pollTimer);pollTimer=null}}
  function close(){stop();modal?.classList.remove('open');modal?.setAttribute('aria-hidden','true');activeLine=null;activeCharge=0;busy=false;completing=false}
  async function poll(showError=false){if(!activeCharge||busy)return;busy=true;try{const d=await post('pix_sales_status',{charge_id:activeCharge}),c=d.charge||{};render(c);if(c.paid){complete(c);return}if(c.expired||String(c.status||'').toUpperCase()==='ERRO')stop()}catch(e){if(showError)status('error','Não foi possível consultar agora',String(e?.message||e));else status('','Aguardando pagamento','Falha temporária na consulta. O sistema tentará novamente.')}finally{busy=false}}
  function complete(charge){if(completing||!activeLine||!charge?.paid)return;completing=true;const h=ensureHidden(activeLine),{id}=methodInfo(activeLine),amount=parseMoney(activeLine.querySelector(amountSelector)?.value||0);h.value=String(charge.id||'');h.dataset.amount=amount.toFixed(2);h.dataset.method=id;h.dataset.paid='1';stop();render(charge);setTimeout(()=>{close();if(form.requestSubmit)form.requestSubmit();else form.submit()},650)}
  async function start(line){if(!line||busy)return;busy=true;completing=false;activeLine=line;stop();const {id}=methodInfo(line),amount=parseMoney(line.querySelector(amountSelector)?.value||0),h=ensureHidden(line);if(!id||amount<=0){busy=false;return}const existing=Number(h.value||0),matches=existing>0&&String(h.dataset.method||'')===id&&Math.abs(Number(h.dataset.amount||0)-amount)<=.009;if(!matches)reset(line);activeCharge=matches?existing:0;modal?.classList.add('open');modal?.setAttribute('aria-hidden','false');q('[data-pix-amount]').textContent=money(amount);q('[data-pix-copy]').value='';q('[data-pix-qr]').hidden=true;q('[data-pix-placeholder]').hidden=false;q('[data-pix-placeholder]').textContent=matches?'Recuperando QR Code...':'Gerando QR Code...';status('',matches?'Consultando cobrança':'Gerando cobrança','Conectando com o banco...');try{let c;if(matches){const d=await post('pix_sales_status',{charge_id:existing});c=d.charge||{};render(c);if(c.paid){complete(c);return}if(c.expired||String(c.status||'').toUpperCase()==='ERRO'){reset(line);activeCharge=0;return}}else{const d=await post('pix_sales_create',{payment_method_id:id,amount:amount.toFixed(2).replace('.',',')});c=d.charge||{};activeCharge=Number(c.id||0);if(!activeCharge)throw new Error('O banco não retornou o identificador da cobrança Pix.');h.value=String(activeCharge);h.dataset.amount=amount.toFixed(2);h.dataset.method=id;h.dataset.paid=c.paid?'1':'0';render(c);if(c.paid){complete(c);return}}pollTimer=setInterval(()=>poll(false),2500);setTimeout(()=>poll(false),450)}catch(e){status('error',matches?'Não foi possível consultar o Pix':'Não foi possível gerar o Pix',String(e?.message||e));stop()}finally{busy=false}}
  function ensurePaidBeforeContinue(){
    ensureAll();
    const line=pendingLine();
    if(!line) return false;
    start(line);
    return true;
  }
  // Expõe um preflight para telas que possuem uma etapa posterior (ex.: escolha fiscal).
  // Assim a própria tela pode resolver o Pix depois das validações comerciais e ANTES
  // de abrir outro modal. O listener de submit abaixo permanece como fallback genérico.
  form.nexaSalesPixEnsurePaid = ensurePaidBeforeContinue;
  form.addEventListener('input',e=>{const line=e.target.closest?.(lineSelector);if(!line)return;if(e.target.matches(amountSelector)){const h=ensureHidden(line);if(h?.value){const amount=parseMoney(e.target.value||0);if(Math.abs(Number(h.dataset.amount||0)-amount)>.009)reset(line)}}});
  form.addEventListener('change',e=>{const line=e.target.closest?.(lineSelector);if(!line)return;if(e.target.matches(methodSelector))reset(line)});
  form.addEventListener('submit',e=>{if(e.defaultPrevented)return;if(ensurePaidBeforeContinue()){e.preventDefault()}});
  modal?.querySelectorAll('[data-pix-close]').forEach(b=>b.addEventListener('click',close));q('[data-pix-verify]')?.addEventListener('click',()=>poll(true));q('[data-pix-copy-btn]')?.addEventListener('click',async()=>{const code=q('[data-pix-copy]')?.value||'';if(!code){status('error','Código ainda indisponível','Aguarde o banco retornar o Pix Copia e Cola.');return}try{if(navigator.clipboard?.writeText)await navigator.clipboard.writeText(code);else{const t=q('[data-pix-copy]');t.focus();t.select();document.execCommand('copy')}status('','Código copiado','Cole o código no aplicativo do banco para concluir o pagamento.')}catch(e){status('error','Não foi possível copiar','Selecione e copie o código manualmente.')}});
  modal?.addEventListener('click',e=>{if(e.target===modal)close()});
  ensureAll();
})();
</script>
<?php unset($pixSalesWidget); ?>

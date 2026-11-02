<?php
declare(strict_types=1);

if (!function_exists('nexa_buscar_f8_assets')) {
  /**
   * Modal de pesquisa reutilizável para substituir selects longos sem alterar o POST legado.
   * O select original permanece no DOM e recebe o valor escolhido; o helper apenas troca a UX.
   */
  function nexa_buscar_f8_assets(): void {
    static $rendered = false;
    if ($rendered) return;
    $rendered = true;
    ?>
    <style>
      .nexa-f8-wrap{position:relative;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:7px;align-items:center;width:100%}
      .nexa-f8-source{position:absolute!important;left:0!important;top:0!important;width:1px!important;height:1px!important;min-height:1px!important;padding:0!important;margin:0!important;border:0!important;opacity:0!important;pointer-events:none!important;overflow:hidden!important}
      .nexa-f8-display{width:100%;min-height:39px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;padding:8px 10px;color:#0f172a;cursor:pointer;text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .nexa-f8-display.is-empty{color:#64748b}
      .nexa-f8-button{white-space:nowrap;min-height:39px!important;padding-left:12px!important;padding-right:12px!important}
      .nexa-f8-hotkey{display:inline-flex;margin-left:5px;padding:2px 6px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#475569;font-size:10px;font-weight:900;line-height:1}
      .nexa-f8-backdrop[hidden]{display:none!important}.nexa-f8-backdrop{position:fixed;inset:0;z-index:12050;background:rgba(15,23,42,.52);display:flex;align-items:center;justify-content:center;padding:16px}
      .nexa-f8-modal{width:min(820px,96vw);max-height:90vh;background:#fff;border:1px solid #dbe4f0;border-radius:20px;box-shadow:0 28px 85px rgba(15,23,42,.28);display:flex;flex-direction:column;overflow:hidden}
      .nexa-f8-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:15px 17px;border-bottom:1px solid #e5edf6;background:#f8fbff}.nexa-f8-head h2{margin:0;color:#0b1f5b;font-size:20px}.nexa-f8-head p{margin:4px 0 0;color:#64748b;font-size:12px;line-height:1.45}.nexa-f8-close{border:1px solid #dbe4f0;background:#fff;border-radius:10px;width:38px;height:38px;font-size:22px;line-height:1;cursor:pointer;color:#334155}
      .nexa-f8-body{padding:14px 17px;display:grid;gap:10px;min-height:0}.nexa-f8-search-line{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:8px;align-items:center}.nexa-f8-query{width:100%;min-height:42px;border:1px solid #cbd5e1;border-radius:12px;padding:9px 11px;font-size:14px}.nexa-f8-register[hidden]{display:none!important}.nexa-f8-counter{min-height:16px;color:#64748b;font-size:11px}.nexa-f8-results{border:1px solid #dbe4f0;border-radius:14px;overflow:auto;min-height:220px;max-height:55vh;background:#fff;outline:none}.nexa-f8-result{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;width:100%;border:0;border-bottom:1px solid #eef2f7;background:#fff;padding:10px 12px;text-align:left;cursor:pointer;color:#0f172a}.nexa-f8-result:last-child{border-bottom:0}.nexa-f8-result:hover,.nexa-f8-result.is-active{background:#eef5ff}.nexa-f8-result strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px}.nexa-f8-result small{display:block;margin-top:3px;color:#64748b;font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.nexa-f8-result-code{font-size:11px;font-weight:900;color:#475569;white-space:nowrap}.nexa-f8-empty{padding:28px 16px;text-align:center;color:#64748b}
      @media(max-width:680px){.nexa-f8-backdrop{padding:7px}.nexa-f8-modal{width:100%;max-height:96vh}.nexa-f8-search-line{grid-template-columns:1fr 1fr}.nexa-f8-query{grid-column:1/-1}.nexa-f8-search-line .btn{width:100%;justify-content:center}.nexa-f8-wrap{grid-template-columns:minmax(0,1fr) auto}.nexa-f8-button{padding-left:9px!important;padding-right:9px!important}}
    </style>
    <div class="nexa-f8-backdrop" id="nexaF8Backdrop" hidden aria-hidden="true">
      <div class="nexa-f8-modal" role="dialog" aria-modal="true" aria-labelledby="nexaF8Title">
        <div class="nexa-f8-head">
          <div><h2 id="nexaF8Title">Pesquisar</h2><p>Digite a pesquisa e pressione Enter. Use ↑/↓ para navegar e Enter para selecionar.</p></div>
          <button type="button" class="nexa-f8-close" id="nexaF8Close" aria-label="Fechar">×</button>
        </div>
        <div class="nexa-f8-body">
          <div class="nexa-f8-search-line">
            <input class="nexa-f8-query" id="nexaF8Query" autocomplete="off" placeholder="Digite para pesquisar...">
            <button type="button" class="btn btn-primary" id="nexaF8Search">Pesquisar</button>
            <a class="btn nexa-f8-register" id="nexaF8Register" href="#" target="_blank" rel="noopener" hidden>+ Cadastrar</a>
          </div>
          <div class="nexa-f8-counter" id="nexaF8Counter"></div>
          <div class="nexa-f8-results" id="nexaF8Results" tabindex="0"><div class="nexa-f8-empty">Digite um termo e pressione Enter para pesquisar.</div></div>
        </div>
      </div>
    </div>
    <script>
    (function(){
      if(window.NexaBuscarF8) return;
      const backdrop=document.getElementById('nexaF8Backdrop'), query=document.getElementById('nexaF8Query'), searchBtn=document.getElementById('nexaF8Search'), register=document.getElementById('nexaF8Register'), closeBtn=document.getElementById('nexaF8Close'), results=document.getElementById('nexaF8Results'), counter=document.getElementById('nexaF8Counter'), title=document.getElementById('nexaF8Title');
      let current=null, filtered=[], active=-1, lastQuery='';
      const instances=[];
      function norm(v){return String(v??'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();}
      function contextualUrl(url){try{const u=new URL(url,window.location.href),ctx=new URL(window.location.href).searchParams.get('mc_ctx');if(ctx&&!u.searchParams.has('mc_ctx')&&u.origin===window.location.origin)u.searchParams.set('mc_ctx',ctx);return u.toString();}catch(e){return url;}}
      function visible(el){return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));}
      function selectedText(select){const o=select.options[select.selectedIndex];return o&&o.value?String(o.textContent||'').trim():'';}
      function sync(instance){const text=selectedText(instance.select);instance.display.value=text;instance.display.classList.toggle('is-empty',!text);instance.display.placeholder=instance.options.placeholder||'Selecione...';}
      function optionsFor(instance){return Array.from(instance.select.options).filter(o=>o.value!==''&&!o.disabled).map(o=>({
        value:o.value,label:String(o.textContent||'').trim(),code:o.dataset.code||o.value,meta:o.dataset.meta||'',barcode:o.dataset.barcode||'',option:o,
        hay:norm([o.value,o.textContent,o.dataset.code,o.dataset.meta,o.dataset.barcode,o.dataset.document].filter(Boolean).join(' '))
      }));}
      function setActive(index){const buttons=results.querySelectorAll('[data-nexa-f8-index]');if(!buttons.length){active=-1;return;}active=Math.max(0,Math.min(index,buttons.length-1));buttons.forEach((b,i)=>b.classList.toggle('is-active',i===active));buttons[active].scrollIntoView({block:'nearest'});}
      function render(){
        if(!current)return;const q=norm(query.value);lastQuery=q;const all=optionsFor(current);filtered=q?all.filter(item=>item.hay.includes(q)):[];active=filtered.length?0:-1;
        counter.textContent=q?(filtered.length+' registro(s) encontrado(s)'):' ';results.innerHTML='';
        if(!q){results.innerHTML='<div class="nexa-f8-empty">Digite um termo e pressione Enter para pesquisar.</div>';return;}
        if(!filtered.length){results.innerHTML='<div class="nexa-f8-empty">Nenhum registro encontrado.</div>';return;}
        filtered.forEach((item,index)=>{const b=document.createElement('button');b.type='button';b.className='nexa-f8-result'+(index===0?' is-active':'');b.dataset.nexaF8Index=String(index);const left=document.createElement('span'),strong=document.createElement('strong'),small=document.createElement('small'),code=document.createElement('span');strong.textContent=item.label;left.appendChild(strong);const details=[item.meta,item.barcode?('Código de barras: '+item.barcode):''].filter(Boolean).join(' · ');if(details){small.textContent=details;left.appendChild(small);}code.className='nexa-f8-result-code';code.textContent=item.code?('#'+item.code):'';b.appendChild(left);b.appendChild(code);b.addEventListener('mouseenter',()=>setActive(index));b.addEventListener('click',()=>choose(index));results.appendChild(b);});
      }
      function choose(index){const item=filtered[index];if(!current||!item)return;current.select.value=item.value;current.select.dispatchEvent(new Event('change',{bubbles:true}));sync(current);close();if(current.options.onSelect)try{current.options.onSelect(item,current);}catch(e){} }
      function open(instance){
        if(!instance||!visible(instance.wrap))return;current=instance;title.textContent=instance.options.title||'Pesquisar';query.value='';lastQuery='';filtered=[];active=-1;results.innerHTML='<div class="nexa-f8-empty">Digite um termo e pressione Enter para pesquisar.</div>';counter.textContent=' ';
        const url=instance.options.registerUrl||'';if(url){register.hidden=false;register.href=contextualUrl(url);register.textContent=instance.options.registerLabel||'+ Cadastrar';}else{register.hidden=true;register.removeAttribute('href');}
        backdrop.hidden=false;backdrop.setAttribute('aria-hidden','false');requestAnimationFrame(()=>{query.focus();query.select();});
      }
      function close(){backdrop.hidden=true;backdrop.setAttribute('aria-hidden','true');if(current&&current.display)current.display.focus();current=null;filtered=[];active=-1;}
      function enhanceSelect(select,options={}){
        if(!select)return null;if(select.dataset.nexaF8Enhanced==='1')return instances.find(i=>i.select===select)||null;
        const nearbyRegister=select.parentElement?select.parentElement.querySelector('.hint a[href]'):null;
        options=Object.assign({},options);
        if(nearbyRegister)options.registerUrl=nearbyRegister.href;
        if(!options.registerLabel&&nearbyRegister)options.registerLabel='+ Cadastrar';
        if(nearbyRegister&&nearbyRegister.parentElement&&options.keepInlineRegister!==true)nearbyRegister.parentElement.hidden=true;
        select.dataset.nexaF8Enhanced='1';select.classList.add('nexa-f8-source');
        const wrap=document.createElement('div');wrap.className='nexa-f8-wrap';const display=document.createElement('input');display.type='text';display.className='nexa-f8-display';display.readOnly=true;display.autocomplete='off';display.setAttribute('aria-label',options.title||'Seleção');const btn=document.createElement('button');btn.type='button';btn.className='btn nexa-f8-button';btn.innerHTML='Buscar <span class="nexa-f8-hotkey">F8</span>';btn.title=(options.title||'Pesquisar')+' (F8)';select.insertAdjacentElement('afterend',wrap);wrap.appendChild(display);wrap.appendChild(btn);const instance={select,wrap,display,button:btn,options};instances.push(instance);sync(instance);
        display.addEventListener('click',()=>open(instance));btn.addEventListener('click',()=>open(instance));[display,btn].forEach(el=>el.addEventListener('keydown',e=>{if(e.key==='F8'){e.preventDefault();open(instance);}}));select.addEventListener('change',()=>sync(instance));select.addEventListener('invalid',e=>{e.preventDefault();open(instance);});
        return instance;
      }
      query.addEventListener('input',()=>{});searchBtn.addEventListener('click',render);closeBtn.addEventListener('click',close);
      query.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();const q=norm(query.value);if(q!==lastQuery||!filtered.length){render();}else if(active>=0)choose(active);}else if(e.key==='ArrowDown'){e.preventDefault();if(!filtered.length)render();if(filtered.length)setActive(active<0?0:active+1);}else if(e.key==='ArrowUp'){e.preventDefault();if(filtered.length)setActive(active<=0?filtered.length-1:active-1);}else if(e.key==='Escape'){e.preventDefault();close();}});
      results.addEventListener('keydown',e=>{if(e.key==='ArrowDown'){e.preventDefault();setActive(active+1);}else if(e.key==='ArrowUp'){e.preventDefault();setActive(active<=0?filtered.length-1:active-1);}else if(e.key==='Enter'&&active>=0){e.preventDefault();choose(active);}else if(e.key==='Escape'){e.preventDefault();close();}});
      document.addEventListener('keydown',e=>{if(e.key!=='F8'||!backdrop.hidden)return;const wrap=e.target&&e.target.closest?e.target.closest('.nexa-f8-wrap'):null;if(wrap){const inst=instances.find(i=>i.wrap===wrap);if(inst){e.preventDefault();open(inst);return;}}const candidates=instances.filter(i=>visible(i.wrap));if(candidates.length===1){e.preventDefault();open(candidates[0]);}});
      window.NexaBuscarF8={enhanceSelect,open,close,refresh:function(select){const i=instances.find(x=>x.select===select);if(i)sync(i);},instances};
    })();
    </script>
    <?php
  }
}

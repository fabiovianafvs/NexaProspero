// Admin UI helpers (v30.8)
(function(){
  'use strict';

  // ---------- Money mask (BRL-style: 39,90) ----------
  function formatMoneyFromDigits(digits){
    digits = (digits || '').replace(/\D+/g,'');
    if(digits === '') return '';
    // ensure at least 3 digits for cents
    if(digits.length === 1) digits = '0' + digits;
    if(digits.length === 2) digits = '0' + digits;
    const cents = digits.slice(-2);
    let ints = digits.slice(0, -2);
    // trim leading zeros but keep one
    ints = ints.replace(/^0+(?=\d)/,'');
    // add thousands separators (.)
    ints = ints.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return ints + ',' + cents;
  }

  function attachMoneyMask(input){
    // format initial value if any
    if(input.value && input.value.trim() !== '') input.value = formatMoneyFromDigits(input.value);
    const onInput = ()=>{
      const start = input.selectionStart || 0;
      const before = input.value;
      const formatted = formatMoneyFromDigits(before);
      input.value = formatted;
      // cursor: keep at end (simpler / robust)
      input.setSelectionRange(input.value.length, input.value.length);
    };
    input.addEventListener('input', onInput);
    input.addEventListener('blur', ()=>{
      if(input.value.trim() === '') return;
      input.value = formatMoneyFromDigits(input.value);
    });
  }

  document.querySelectorAll('input[data-mask="money"]').forEach(attachMoneyMask);

  // ---------- Image preview ----------
  document.querySelectorAll('input[type="file"][data-preview]').forEach(inp=>{
    const sel = inp.getAttribute('data-preview');
    const img = sel ? document.querySelector(sel) : null;
    if(!img) return;
    inp.addEventListener('change', ()=>{
      const f = inp.files && inp.files[0];
      if(!f) return;
      const url = URL.createObjectURL(f);
      img.src = url;
      img.onload = ()=> URL.revokeObjectURL(url);
    });
  });

  // ---------- Required validation (visual) ----------
  document.querySelectorAll('form[data-validate]').forEach(form=>{
    form.addEventListener('submit', (ev)=>{
      let ok = true;
      form.querySelectorAll('[data-required], [required]').forEach(el=>{
        const v = (el.value || '').trim();
        const isEmpty = v === '';
        el.classList.toggle('invalid', isEmpty);
        if(isEmpty) ok = false;
      });
      if(!ok){
        ev.preventDefault();
        const first = form.querySelector('.invalid');
        if(first) first.focus();
      }
    });
    form.querySelectorAll('[data-required], [required]').forEach(el=>{
      el.addEventListener('input', ()=> el.classList.remove('invalid'));
      el.addEventListener('change', ()=> el.classList.remove('invalid'));
    });
  });

  // ---------- Category chips ----------
  document.querySelectorAll('[data-chips]').forEach(box=>{
    const inputSel = box.getAttribute('data-chips');
    const input = inputSel ? document.querySelector(inputSel) : null;
    if(!input) return;
    box.querySelectorAll('[data-chip]').forEach(chip=>{
      chip.addEventListener('click', ()=>{
        input.value = chip.getAttribute('data-chip') || chip.textContent.trim();
        input.dispatchEvent(new Event('input', {bubbles:true}));
        input.focus();
      });
    });
  });

})();



// ---------- Responsive admin menu ----------
(function(){
  const toggle = document.querySelector('[data-admin-menu-toggle]');
  const closers = document.querySelectorAll('[data-admin-menu-close]');
  const sidebar = document.querySelector('[data-admin-sidebar]');
  const nav = document.querySelector('.navlinks') || sidebar;
  if(!toggle || !nav) return;

  const isSideMenu = document.body.classList.contains('admin-side-menu-enabled');
  const collapsedKey = 'nexa.admin.sidebar.collapsed';

  function isMobile(){
    return window.innerWidth <= 920;
  }

  function applySidebarCollapsed(){
    if(!isSideMenu) return;
    const collapsed = !isMobile() && window.localStorage && localStorage.getItem(collapsedKey) === '1';
    document.body.classList.toggle('admin-sidebar-collapsed', collapsed);
    toggle.setAttribute('aria-label', collapsed ? 'Mostrar menu lateral' : 'Esconder menu lateral');
    if(!document.body.classList.contains('admin-menu-open')){
      toggle.setAttribute('aria-expanded', 'false');
      toggle.textContent = '☰';
    }
  }

  function setOpen(open){
    document.body.classList.toggle('admin-menu-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.textContent = open ? '×' : '☰';
  }

  function closeTopDropdowns(except){
    document.querySelectorAll('.nav-dropdown.is-open').forEach(dd=>{
      if(except && dd === except) return;
      dd.classList.remove('is-open');
      const a = dd.querySelector(':scope > a');
      if(a) a.setAttribute('aria-expanded', 'false');
    });
  }

  function closeSideGroups(except){
    document.querySelectorAll('.admin-side-group.is-open').forEach(group=>{
      if(except && group === except) return;
      if(group.classList.contains('active')) return;
      group.classList.remove('is-open');
      const a = group.querySelector(':scope > .admin-side-main');
      if(a) a.setAttribute('aria-expanded', 'false');
    });
  }

  applySidebarCollapsed();

  toggle.addEventListener('click', ()=>{
    if(isSideMenu && !isMobile()){
      const nextCollapsed = !document.body.classList.contains('admin-sidebar-collapsed');
      if(window.localStorage) localStorage.setItem(collapsedKey, nextCollapsed ? '1' : '0');
      document.body.classList.toggle('admin-sidebar-collapsed', nextCollapsed);
      toggle.setAttribute('aria-label', nextCollapsed ? 'Mostrar menu lateral' : 'Esconder menu lateral');
      toggle.setAttribute('aria-expanded', 'false');
      toggle.textContent = '☰';
      return;
    }
    setOpen(!document.body.classList.contains('admin-menu-open'));
  });

  closers.forEach(el=>el.addEventListener('click', ()=>setOpen(false)));

  // Menu superior: o item com submenu abre no clique e fecha no segundo clique.
  document.querySelectorAll('.nav-dropdown > a').forEach(anchor=>{
    anchor.addEventListener('click', (ev)=>{
      const dropdown = anchor.closest('.nav-dropdown');
      if(!dropdown || !dropdown.querySelector('.nav-submenu')) return;
      ev.preventDefault();
      ev.stopImmediatePropagation();
      const willOpen = !dropdown.classList.contains('is-open');
      closeTopDropdowns(dropdown);
      dropdown.classList.toggle('is-open', willOpen);
      anchor.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });
  });

  // Menu lateral: o item principal abre/fecha os filhos sem navegar.
  document.querySelectorAll('.admin-side-group > .admin-side-main').forEach(anchor=>{
    anchor.addEventListener('click', (ev)=>{
      const group = anchor.closest('.admin-side-group');
      if(!group || !group.querySelector('.admin-side-children')) return;
      ev.preventDefault();
      ev.stopImmediatePropagation();
      const willOpen = !group.classList.contains('is-open');
      closeSideGroups(group);
      group.classList.toggle('is-open', willOpen);
      anchor.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });
  });

  // Ao clicar em um link real no celular, recolhe o menu. Em desktop, mantém o lateral aberto.
  nav.querySelectorAll('a').forEach(a=>{
    a.addEventListener('click', ()=>{
      if(a.matches('.admin-side-main, .nav-dropdown > a')) return;
      if(!isSideMenu || isMobile()) setOpen(false);
    });
  });

  document.addEventListener('click', (ev)=>{
    if(ev.target.closest('.nav-dropdown')) return;
    closeTopDropdowns(null);
  });

  document.addEventListener('keydown', (ev)=>{
    if(ev.key === 'Escape'){
      setOpen(false);
      closeTopDropdowns(null);
      document.querySelectorAll('.admin-side-group.is-open').forEach(group=>{
        if(group.classList.contains('active')) return;
        group.classList.remove('is-open');
        const a = group.querySelector(':scope > .admin-side-main');
        if(a) a.setAttribute('aria-expanded', 'false');
      });
    }
  });

  window.addEventListener('resize', ()=>{
    if(!isMobile()) setOpen(false);
    applySidebarCollapsed();
  });
})();

// ---------- Global processing feedback ----------
(function(){
  'use strict';
  const adminScriptSrc = (document.currentScript && document.currentScript.src) || ((document.querySelector('script[src*="admin.js"]') || {}).src || '');

  function adminAssetUrl(fileName){
    if(adminScriptSrc){
      try { return new URL(fileName, adminScriptSrc).href; } catch(e) {}
    }
    return '/assets/' + fileName;
  }

  let overlay = null;
  let showTimer = null;
  let stepTimer = null;
  let currentSteps = ['Processando solicitação...'];
  let stepIndex = 0;
  // Andamento real vindo do servidor (config/progress.php + /api/progresso.php).
  let progressToken = '';
  let progressTokenAt = 0;
  let progressTimer = null;
  let progressStartedAt = 0;
  let progressHasReal = false;
  let explicitShowAt = 0;

  function ensureOverlay(){
    if(overlay) return overlay;
    const style = document.createElement('style');
    style.textContent = `
      .admin-processing-overlay{position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;background:rgba(248,251,255,.76);backdrop-filter:blur(3px)}
      .admin-processing-overlay.is-visible{display:flex}
      .admin-processing-box{width:min(330px,calc(100vw - 40px));border:1px solid #dbe4f0;border-radius:24px;background:#fff;box-shadow:0 22px 66px rgba(15,23,42,.20);padding:22px 18px 20px;text-align:center;color:#0f172a}
      .admin-processing-logo{display:block;width:auto;max-width:190px;max-height:82px;object-fit:contain;margin:0 auto 13px}
      .admin-processing-spinner{width:42px;height:42px;margin:0 auto;border-radius:999px;border:4px solid #e5edf6;border-top-color:var(--admin-secondary,#2454D6);animation:adminSpin .78s linear infinite}
      .admin-processing-text{display:block;margin-top:11px;color:#64748b;font-size:10.5px;line-height:1.35;font-weight:750;letter-spacing:.01em;min-height:28px}
      .admin-is-processing{cursor:progress!important}
      .admin-is-processing button[type="submit"],.admin-is-processing input[type="submit"]{pointer-events:none;opacity:.72}
      @keyframes adminSpin{to{transform:rotate(360deg)}}
    `;
    document.head.appendChild(style);
    overlay = document.createElement('div');
    overlay.className = 'admin-processing-overlay';
    overlay.setAttribute('role','status');
    overlay.setAttribute('aria-live','polite');
    overlay.innerHTML = '<div class="admin-processing-box"><img class="admin-processing-logo" alt="Nexa Próspero"><div class="admin-processing-spinner"></div><span class="admin-processing-text">Processando solicitação...</span></div>';
    const logo = overlay.querySelector('.admin-processing-logo');
    if(logo){
      const candidates = [
        adminAssetUrl('logo-nexa-v6-1.png'),
        '/assets/logo-nexa-v6-1.png',
        '../assets/logo-nexa-v6-1.png',
        '/nexa/assets/logo-nexa-v6-1.png',
        adminAssetUrl('logo-nexa-prospero.jpg'),
        '/assets/logo-nexa-prospero.jpg'
      ].filter(function(value, index, arr){ return value && arr.indexOf(value) === index; });
      let logoIndex = 0;
      logo.addEventListener('error', function(){
        logoIndex += 1;
        if(logoIndex < candidates.length){
          logo.src = candidates[logoIndex];
        }else{
          logo.style.display = 'none';
        }
      });
      logo.src = candidates[0];
    }
    document.body.appendChild(overlay);
    return overlay;
  }

  function setProcessingText(text){
    const el = ensureOverlay().querySelector('.admin-processing-text');
    if(el) el.textContent = text || 'Processando solicitação...';
  }

  function uniqueSteps(steps){
    const out = [];
    steps.forEach(function(step){
      const text = String(step || '').trim();
      if(text && out[out.length - 1] !== text) out.push(text);
    });
    return out.length ? out : ['Processando solicitação...'];
  }

  function normalizeSteps(input){
    if(Array.isArray(input)) return uniqueSteps(input.map(String));
    if(typeof input === 'string' && input.trim()) return [input.trim()];
    return ['Preparando solicitação...', 'Validando informações...', 'Gravando alterações...', 'Atualizando a tela...'];
  }

  function newProgressToken(){
    const bytes = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    return Array.from(bytes, function(b){ return b.toString(16).padStart(2, '0'); }).join('');
  }

  // Formulários de operações demoradas recebem um código para o servidor
  // informar a etapa real (gravando, transmitindo para a SEFAZ, autorizada...).
  function attachProgressToken(form){
    const action = String((form.querySelector('[name="action"]') || {}).value || '').toLowerCase();
    if(!/finalize_pdv|emitir_nf|authorize|transmit|finalizar/.test(action) && !form.hasAttribute('data-progress')) return;
    let input = form.querySelector('input[name="nexa_progress"]');
    if(!input){
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'nexa_progress';
      form.appendChild(input);
    }
    progressToken = newProgressToken();
    progressTokenAt = Date.now();
    input.value = progressToken;
  }

  function renderRealProgress(steps){
    if(!Array.isArray(steps) || !steps.length) return;
    if(!progressHasReal){
      progressHasReal = true;
      window.clearInterval(stepTimer); // deixa de usar as mensagens de exemplo
    }
    // Mostra só a etapa atual, trocando a mensagem a cada novo andamento.
    const last = steps[steps.length - 1] || {};
    setProcessingText(String(last.text || ''));
  }

  function startProgressPolling(token){
    window.clearInterval(progressTimer);
    progressStartedAt = Date.now();
    progressHasReal = false;
    let busy = false;
    progressTimer = window.setInterval(function(){
      if(busy || !token) return;
      busy = true;
      fetch('/api/progresso.php?t=' + encodeURIComponent(token), {cache: 'no-store', credentials: 'omit'})
        .then(function(r){ return r.ok ? r.json() : null; })
        .then(function(data){ if(data && data.steps) renderRealProgress(data.steps); })
        .catch(function(){})
        .finally(function(){ busy = false; });
    }, 800);
    // Segurança: nunca consulta por mais de 5 minutos.
    const myTimer = progressTimer;
    window.setTimeout(function(){ window.clearInterval(myTimer); if(progressTimer === myTimer) progressTimer = null; }, 300000);
  }

  function showProcessing(messageOrSteps, fromGeneric){
    if(!fromGeneric) explicitShowAt = Date.now();
    currentSteps = normalizeSteps(messageOrSteps);
    stepIndex = 0;
    setProcessingText(currentSteps[0]);
    window.clearTimeout(showTimer);
    window.clearInterval(stepTimer);
    showTimer = window.setTimeout(function(){
      ensureOverlay().classList.add('is-visible');
      document.body.classList.add('admin-is-processing');
      if(progressToken && Date.now() - progressTokenAt < 3000){
        startProgressPolling(progressToken);
      }
      if(currentSteps.length > 1 && !progressHasReal){
        stepTimer = window.setInterval(function(){
          if(stepIndex < currentSteps.length - 1){
            stepIndex += 1;
            setProcessingText(currentSteps[stepIndex]);
          }else{
            window.clearInterval(stepTimer);
          }
        }, 1250);
      }
    }, 80);
  }

  function hideProcessing(){
    window.clearTimeout(showTimer);
    window.clearInterval(stepTimer);
    window.clearInterval(progressTimer);
    progressTimer = null;
    progressToken = '';
    progressHasReal = false;
    if(overlay) overlay.classList.remove('is-visible');
    document.body.classList.remove('admin-is-processing');
  }

  function stepsForForm(form){
    const action = String((form.querySelector('[name="action"]') || {}).value || '').toLowerCase();
    const text = String(form.textContent || '').toLowerCase();
    if(action.includes('emitir_nfce')) return ['Conferindo dados fiscais...', 'Montando XML da NFC-e...', 'Assinando o documento...', 'Transmitindo para a SEFAZ...', 'Aguardando retorno da autorização...'];
    if(action.includes('emitir_nfe')) return ['Conferindo dados fiscais...', 'Montando XML da NF-e...', 'Assinando o documento...', 'Transmitindo para a SEFAZ...', 'Aguardando retorno da autorização...'];
    if(action.includes('authorize')) return ['Localizando documento fiscal...', 'Transmitindo lote para a SEFAZ...', 'Aguardando autorização...', 'Registrando retorno fiscal...'];
    if(action.includes('consult')) return ['Enviando consulta para a SEFAZ...', 'Verificando situação atual...', 'Atualizando retorno do documento...'];
    if(action.includes('recover')) return ['Consultando chave de acesso...', 'Recuperando protocolo autorizado...', 'Atualizando XML e situação fiscal...'];
    if(action.includes('create_preview')) return ['Recalculando totais fiscais...', 'Montando XML preliminar...', 'Gravando pré-documento fiscal...'];
    if(action.includes('finalize_pdv')) return ['Conferindo itens e pagamento...', 'Gravando pedido no sistema...', 'Atualizando estoque e financeiro...', 'Finalizando venda...'];
    if(text.includes('salvar')) return ['Validando campos informados...', 'Gravando alterações no banco...', 'Atualizando informações da tela...'];
    return ['Preparando solicitação...', 'Validando informações...', 'Gravando alterações...', 'Atualizando a tela...'];
  }

  function stepsForLink(a){
    const href = String(a.getAttribute('href') || '').toLowerCase();
    const text = String(a.textContent || '').toLowerCase();
    if(href.includes('download') || text.includes('baixar')) return ['Localizando arquivo solicitado...', 'Gerando arquivo para download...'];
    if(text.includes('imprimir') || href.includes('imprimir')) return ['Localizando documento autorizado...', 'Carregando layout de impressão...', 'Preparando visualização na tela...'];
    if(text.includes('consultar')) return ['Preparando consulta...', 'Verificando dados na origem...', 'Atualizando resultado na tela...'];
    if(text.includes('relatório') || text.includes('sped') || text.includes('sintegra') || text.includes('inventário')) return ['Aplicando filtros do relatório...', 'Calculando totais e agrupamentos...', 'Montando visualização do relatório...'];
    if(text.includes('pedido') || href.includes('pedido')) return ['Abrindo módulo de vendas...', 'Carregando dados do pedido...', 'Preparando tela para operação...'];
    return ['Abrindo módulo selecionado...', 'Validando permissões de acesso...', 'Carregando informações da tela...'];
  }

  window.adminShowProcessing = showProcessing;
  window.adminHideProcessing = hideProcessing;
  window.addEventListener('pageshow', hideProcessing);
  document.addEventListener('visibilitychange', function(){ if(document.visibilityState === 'visible') hideProcessing(); });

  document.addEventListener('submit', function(ev){
    const form = ev.target;
    if(!(form instanceof HTMLFormElement)) return;
    if(form.matches('[data-no-processing], [data-ajax], [target="_blank"]')) return;
    attachProgressToken(form);
    window.setTimeout(function(){
      if(ev.defaultPrevented) return;
      // A própria tela (PDV) já mostrou mensagens específicas: não sobrescrever
      // com as genéricas (era isso que deixava só "Finalizando venda...").
      if(Date.now() - explicitShowAt < 400) return;
      showProcessing(stepsForForm(form), true);
    }, 0);
  }, true);

  document.addEventListener('click', function(ev){
    const clicked = ev.target.closest('a,button,input[type="button"],input[type="submit"]');
    if(!clicked) return;
    if(clicked.matches('[data-no-processing], [data-close-modal], .finance-modal-close, [data-admin-menu-toggle], [data-admin-menu-close], [data-pdv-fiscal], .pdv-step-nav, .pdv-next, .pdv-prev, .pdv-product, .pdv-cat')) return;
    const tag = clicked.tagName.toLowerCase();
    if(tag === 'a'){
      const a = clicked;
      const href = (a.getAttribute('href') || '').trim();
      if(!href || href === '#' || href.startsWith('#') || href.toLowerCase().startsWith('javascript:')) return;
      if(a.target && a.target !== '_self') return;
      if(a.hasAttribute('download')) return;
      // Arquivos CSV/JSON são entregues por Content-Disposition; não navegam
      // nesta aba. A sobreposição de processamento ficaria presa indefinidamente.
      // O servidor continua validando permissão/empresa antes do download.
      if(/(?:[?&]action=)(?:export_model|export_tributacoes)(?:&|$)/i.test(href)) return;
      window.setTimeout(function(){ if(!ev.defaultPrevented) showProcessing(stepsForLink(a)); }, 0);
      return;
    }
    if(clicked.type && String(clicked.type).toLowerCase() === 'submit') return;
  }, true);
})();

// ---------- Global menu quick search ----------
(function(){
  'use strict';

  const box = document.querySelector('[data-admin-menu-search]');
  if(!box) return;

  const input = box.querySelector('[data-admin-menu-search-input]');
  const results = box.querySelector('[data-admin-menu-search-results]');
  const empty = box.querySelector('[data-admin-menu-search-empty]');
  const allItems = Array.from(box.querySelectorAll('[data-admin-menu-search-item]'));
  if(!input || !results || !allItems.length) return;

  let visibleItems = [];
  let activeIndex = -1;

  function normalize(value){
    return String(value || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLocaleLowerCase('pt-BR')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function scoreItem(item, query){
    // A pesquisa é propositalmente restrita ao NOME do menu.
    // O grupo continua aparecendo apenas como contexto visual do resultado,
    // mas não faz outros menus do mesmo grupo entrarem na pesquisa.
    const label = normalize(item.dataset.menuLabel);
    if(label === query) return 0;
    if(label.startsWith(query)) return 10;
    if(label.includes(query)) return 20;

    const words = query.split(' ').filter(Boolean);
    if(words.length > 1 && words.every(word => label.includes(word))) return 30;
    return Number.POSITIVE_INFINITY;
  }

  function setActive(index){
    if(!visibleItems.length){
      activeIndex = -1;
      return;
    }
    if(index < 0) index = visibleItems.length - 1;
    if(index >= visibleItems.length) index = 0;
    activeIndex = index;
    visibleItems.forEach((item, idx)=>{
      const active = idx === activeIndex;
      item.classList.toggle('is-active', active);
      item.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    const active = visibleItems[activeIndex];
    if(active) active.scrollIntoView({block:'nearest'});
  }

  function closeResults(){
    results.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    allItems.forEach(item=>{
      item.hidden = true;
      item.classList.remove('is-active');
      item.setAttribute('aria-selected', 'false');
    });
    visibleItems = [];
    activeIndex = -1;
  }

  function performSearch(){
    const query = normalize(input.value);
    allItems.forEach(item=>{
      item.hidden = true;
      item.classList.remove('is-active');
      item.setAttribute('aria-selected', 'false');
    });

    if(query === ''){
      closeResults();
      if(empty) empty.hidden = true;
      return;
    }

    visibleItems = allItems
      .map(item=>({
        item,
        score: scoreItem(item, query),
        order: Number(item.dataset.menuOrder || 0)
      }))
      .filter(row=>Number.isFinite(row.score))
      .sort((a,b)=>a.score - b.score || a.order - b.order)
      .map(row=>row.item);

    visibleItems.forEach(item=>{ item.hidden = false; });
    if(empty) empty.hidden = visibleItems.length > 0;
    results.hidden = false;
    input.setAttribute('aria-expanded', 'true');
    // Ao digitar, apenas lista os menus encontrados.
    // A seleção visual só começa quando o usuário usa ↑/↓ ou passa o mouse.
    activeIndex = -1;
    visibleItems.forEach(item=>{
      item.classList.remove('is-active');
      item.setAttribute('aria-selected', 'false');
    });
  }

  input.addEventListener('input', performSearch);
  input.addEventListener('focus', ()=>{
    if(input.value.trim() !== '') performSearch();
  });

  input.addEventListener('keydown', (ev)=>{
    if(ev.key === 'ArrowDown'){
      ev.preventDefault();
      if(results.hidden) performSearch();
      setActive(activeIndex + 1);
      return;
    }
    if(ev.key === 'ArrowUp'){
      ev.preventDefault();
      if(results.hidden) performSearch();
      setActive(activeIndex - 1);
      return;
    }
    if(ev.key === 'Enter'){
      if(visibleItems.length){
        ev.preventDefault();
        const item = visibleItems[activeIndex >= 0 ? activeIndex : 0];
        if(item) item.click();
      }
      return;
    }
    if(ev.key === 'Escape'){
      ev.preventDefault();
      closeResults();
      input.blur();
    }
  });

  allItems.forEach(item=>{
    item.addEventListener('mouseenter', ()=>{
      const idx = visibleItems.indexOf(item);
      if(idx >= 0) setActive(idx);
    });
  });

  document.addEventListener('click', (ev)=>{
    if(!box.contains(ev.target)) closeResults();
  });

  // F8 é o atalho global para chegar rapidamente à pesquisa de menus.
  document.addEventListener('keydown', (ev)=>{
    if(ev.key !== 'F8') return;
    ev.preventDefault();
    input.focus();
    input.select();
    if(input.value.trim() !== '') performSearch();
  });
})();
// Nexa Próspero — Ícones de navegação, aperfeiçoamento não intrusivo.
// Apenas o menu lateral já existente é alterado no navegador;
// não modifica funções PHP, menus, permissões ou regras de módulos.
(function(){
  'use strict';
  const side = document.querySelector('[data-admin-sidebar]');
  if(!side || !document.body.classList.contains('admin-side-menu-enabled')) return;
  const fullScreen = !!document.querySelector('.pdv-checkout-mode, .cmd-checkout-mode');
  if(fullScreen) return;
  const icons = {
    home:'<path d="m3.5 10.5 8.5-7 8.5 7"/><path d="M5.5 10V20h13V10"/><path d="M9.5 20v-6h5v6"/>',
    brain:'<path d="M12 20V6.8M12 9a3.4 3.4 0 0 0-6.5-1.2A3.3 3.3 0 0 0 5 14a3.8 3.8 0 0 0 7 1.5M12 9a3.4 3.4 0 0 1 6.5-1.2A3.3 3.3 0 0 1 19 14a3.8 3.8 0 0 1-7 1.5"/>',
    box:'<path d="m12 3.7 7.7 4.1v8.4L12 20.3l-7.7-4.1V7.8L12 3.7Z"/><path d="m4.3 7.8 7.7 4.1 7.7-4.1M12 11.9v8.4"/>',
    shopping:'<path d="M4.5 5.5H7l2 10.2h9l2-7.2H7.7"/><circle cx="10" cy="19.7" r="1"/><circle cx="17.4" cy="19.7" r="1"/>',
    users:'<circle cx="8.6" cy="8.7" r="3.1"/><path d="M3.7 20a5 5 0 0 1 9.8 0"/><path d="M16.2 6.4a3 3 0 0 1 0 5.9M15.7 16a5 5 0 0 1 4.6 4"/>',
    cart:'<path d="M3.5 4h2.8L8 15.8h11l1.5-8.4H7"/><circle cx="9.2" cy="20" r="1"/><circle cx="17.9" cy="20" r="1"/>',
    wallet:'<rect x="3.7" y="6" width="16.6" height="13.2" rx="2"/><path d="M4.2 9h13.2m-1.8 3.5h4.7v4h-4.7z"/>',
    receipt:'<path d="M5.8 3.7h12.4v16.6l-2.2-1.4-2.2 1.4-2.2-1.4-2.2 1.4-2.2-1.4-1.4 1.4z"/><path d="M8.8 9h6.4M8.8 12h6.4M8.8 15h4"/>',
    tools:'<path d="M14.4 5.5a4.7 4.7 0 0 0 4.3 5.1L10 19.2a2.1 2.1 0 0 1-3-3l8.6-8.6a4.7 4.7 0 0 0-1.2-2.1Z"/>',
    building:'<path d="M4.5 20V6.5h7V20M11.5 11.5 18.7 8V20M3 20h18M7.4 9.4h1.2M7.4 12.7h1.2M15 14.5h1.3"/>',
    message:'<path d="M5 18.5V6.5A2.5 2.5 0 0 1 7.5 4h9A2.5 2.5 0 0 1 19 6.5v8a2 2 0 0 1-2 2H9.2Z"/><path d="M8.5 9h7m-7 3h5"/>',
    api:'<path d="m8 7-5 5 5 5M16 7l5 5-5 5M14.5 4.5l-5 15"/>',
    truck:'<path d="M2.8 7h11v10h-11zM13.8 10h3.5l3.7 4v3h-7.2"/><circle cx="7.5" cy="18.5" r="1.3"/><circle cx="17.6" cy="18.5" r="1.3"/>',
    grid:'<rect x="4" y="4" width="6.2" height="6.2" rx="1"/><rect x="13.8" y="4" width="6.2" height="6.2" rx="1"/><rect x="4" y="13.8" width="6.2" height="6.2" rx="1"/><rect x="13.8" y="13.8" width="6.2" height="6.2" rx="1"/>',
    chart:'<path d="M3.6 20h17M6.8 17v-5.5h3V17m2-7v7h3v-7m2-4v11h3V6"/>',
    clip:'<rect x="5.6" y="4.4" width="12.8" height="16" rx="2"/><path d="M9 4.4V3h6v1.4M9 10h6M9 13h6"/>',
    file:'<path d="M6.5 3.6h8.2l3.6 3.8V20H6.5zM14.7 3.6v3.8h3.6M9 12h6m-6 3h6"/>',
    download:'<path d="M12 3.7v11.5m-4-4 4 4 4-4M4.5 19.2h15"/>',
    upload:'<path d="M12 15.7V4.2m-4 4 4-4 4 4M4.5 17.5v2h15v-2"/>',
    gear:'<circle cx="12" cy="12" r="3"/><path d="M12 3.5v2m0 13v2m8.5-8.5h-2m-13 0h-2m14.6 6.1-1.4-1.4M6.7 6.7 5.3 5.3m12.8 0-1.4 1.4M6.7 17.3l-1.4 1.4"/>',
    logout:'<path d="M10 4.5H6.7a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2H10M15 8l4 4-4 4m4-4H9.5"/>',
    dot:'<circle cx="12" cy="12" r="5"/>'
  };
  const mainByHref = [
    [/dashboard\.php/, 'home'],[/brain\.php/,'brain'],[/pedido/,'clip'],[/produto/,'box'],
    [/financeiro_pessoas|clientes/,'users'],[/exportacao/,'upload'],[/api\.php/,'api'],
    [/whatsapp/,'message'],[/orcamento/,'clip'],[/crm_atendimento/,'message'],
    [/delivery_|entrega|romaneio/,'truck'],[/contrato/,'file'],[/compras/,'shopping'],
    [/os_monitor|os_mobile|ordem_servico/,'tools'],[/producao/,'building'],[/gestao/,'cart'],
    [/financeiro/,'wallet'],[/fiscal_radar|fiscal_entrada|importar_xml/,'download'],
    [/fiscal/,'receipt'],[/relatorio/,'chart']
  ];
  function classify(anchor,label){
    const href=(anchor.getAttribute('href')||'').toLowerCase();
    const text=label.toLowerCase();
    if (anchor.classList.contains('admin-side-logout')) return 'logout';
    if (text==='tabelas') return 'grid';
    if (text.includes('relatório')||text.includes('relatorio'))return 'chart';
    if (text.includes('código qr')||text.includes('qr codes'))return 'grid';
    if (text.includes('impressão')||text.includes('impressao'))return 'file';
    if (text.includes('configuração')||text.includes('configuracao'))return 'gear';
    if (text.includes('pagamento'))return 'wallet';
    for(const entry of mainByHref)if(entry[0].test(href))return entry[1];
    if(text.includes('caixa')||text.includes('conta'))return 'wallet';
    if(text.includes('compras'))return 'shopping';
    if(text.includes('fiscal'))return 'receipt';
    if(text.includes('cliente')||text.includes('pessoa'))return 'users';
    return 'dot';
  }
  const anchors=side.querySelectorAll('.admin-side-main,.admin-side-link,.admin-side-children a');
  anchors.forEach(anchor=>{
    if(anchor.querySelector('.nexa-menu-glyph'))return;
    const label=anchor.textContent.trim();
    if(!label)return;
    const key=classify(anchor,label);
    const glyph=document.createElement('span');
    glyph.className='nexa-menu-glyph';
    glyph.setAttribute('aria-hidden','true');
    glyph.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.45" stroke-linecap="round" stroke-linejoin="round">'+(icons[key]||icons.dot)+'</svg>';
    const text=document.createElement('span');
    text.className='nexa-menu-caption';
    text.textContent=label;
    anchor.textContent='';
    anchor.appendChild(glyph);
    anchor.appendChild(text);
    anchor.setAttribute('title',label);
    if(!anchor.hasAttribute('aria-label'))anchor.setAttribute('aria-label',label);
  });
  document.body.classList.add('nexa-nav-icons');
  side.querySelectorAll('.admin-side-group > .admin-side-main').forEach(anchor=>{
    anchor.addEventListener('click',ev=>{
      if(window.innerWidth<=920 || !document.body.classList.contains('admin-sidebar-collapsed'))return;
      ev.preventDefault();
      ev.stopImmediatePropagation();
      document.body.classList.remove('admin-sidebar-collapsed');
      try{localStorage.setItem('nexa.admin.sidebar.collapsed','0');}catch(e){}
      side.querySelectorAll('.admin-side-group').forEach(group=>group.classList.remove('is-open'));
      const group=anchor.closest('.admin-side-group');
      if(group)group.classList.add('is-open');
      anchor.setAttribute('aria-expanded','true');
      const toggle=document.querySelector('[data-admin-menu-toggle]');
      if(toggle)toggle.setAttribute('aria-label','Esconder menu lateral');
    },true);
  });
})();

/* Nexa Próspero · Novo grid da Consulta de Pedidos (piloto por empresa).
 * Conserva formulários/URLs/CSRF da tela original. Nenhum endpoint fiscal novo.
 */
(() => {
  'use strict';
  const table = document.getElementById('ordersGridV1');
  const cfg = window.nexaOrdersGridConfig || {};
  if (!table) return;
  const tbody = table.tBodies[0];
  const wrap = table.closest('.pedidos-grid-v1-wrap');
  if (wrap) {
    wrap.style.setProperty('max-height', 'none', 'important');
    wrap.style.setProperty('height', 'auto', 'important');
    wrap.style.setProperty('overflow-y', 'hidden', 'important');
  }
  const headers = Array.from(table.querySelectorAll('thead th[data-col]'));
  const columns = headers.map(x => x.dataset.col);
  const movable = columns.filter(x => x !== 'acoes');
  const labels = Object.fromEntries(headers.map(x => [x.dataset.col, x.textContent.trim()]));
  const defaults = {numero:95,cliente:255,total:120,entrega:138,pagamento:150,status:135,data:150,prevista:175,fiscal:210};
  const old = cfg.preferences && typeof cfg.preferences === 'object' ? cfg.preferences : {};
  const storageKey = 'nexa.pedidos.grid.v1.' + String(cfg.company || 0);
  let fallback = {};
  try { fallback = JSON.parse(localStorage.getItem(storageKey) || '{}') || {}; } catch (e) {}
  const from = Object.keys(old).length ? old : fallback;
  let visible = Array.isArray(from.visible) ? from.visible.filter(x => movable.includes(x)) : [...movable];
  if (!visible.length) visible = [...movable];
  let order = Array.isArray(from.order) ? from.order.filter(x => movable.includes(x)) : [...movable];
  order = [...new Set(order)].concat(movable.filter(x => !order.includes(x)));
  let widths = from.widths && typeof from.widths === 'object' ? {...from.widths} : {};
  let saveTimer;
  let activeSort = null;
  let activeSortDir = 1;
  const filters = {};
  const prefState = document.getElementById('ordersGridPrefState');

  function svg(name) {
    const paths = {
      edit:'<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L9 17l-4 1 1-4Z"/>',
      print:'<path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6zM18 12h.01"/>',
      fiscal:'<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M9 7h6M9 11h6M9 15h3"/>',
      mail:'<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m3 6 9 7 9-7"/>',
      whatsapp:'<path d="M21 11.5a8.5 8.5 0 0 1-12.6 7.4L3 21l2.1-5.4A8.5 8.5 0 1 1 21 11.5Z"/><path d="M8.5 8.8c.6 3.2 2.6 5.2 5.8 6l1.6-1.4"/>',
      status:'<path d="M3 12h18M12 3l-4 4 4 4M12 21l4-4-4-4"/>',
      more:'<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
      filter:'<path d="M4 7h16M7 12h10M10 17h4"/>'
    };
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + (paths[name] || paths.more) + '</svg>';
  }
  function iconBtn(kind, tooltip) {
    const el = document.createElement('button');
    el.type = 'button'; el.className = 'orders-grid-icon'; el.title = tooltip; el.setAttribute('aria-label', tooltip);
    el.innerHTML = svg(kind);
    return el;
  }
  function pack(name) { const host = document.createElement('div'); host.dataset.pack = name; return host; }
  const menu = document.createElement('div');
  menu.className = 'orders-grid-popover'; menu.hidden = true; menu.setAttribute('role','menu');
  document.body.appendChild(menu);
  let currentPack = null;
  function closeMenu() {
    if (currentPack) { while (menu.firstChild) currentPack.appendChild(menu.firstChild); currentPack = null; }
    menu.hidden = true;
  }
  function positionMenu(anchor) {
    const rect = anchor.getBoundingClientRect();
    const w = Math.max(194, menu.offsetWidth || 194);
    let left = Math.max(8, Math.min(rect.right - w, window.innerWidth - w - 8));
    menu.style.left = left + 'px';
    const height = Math.min(menu.scrollHeight || 200, window.innerHeight * 0.7);
    menu.style.top = (rect.bottom + height + 8 < window.innerHeight ? rect.bottom + 5 : Math.max(6, rect.top - height - 5)) + 'px';
  }
  function showMenu(anchor, content) {
    if (currentPack === content && !menu.hidden) { closeMenu(); return; }
    closeMenu(); currentPack = content;
    while (content.firstChild) menu.appendChild(content.firstChild);
    menu.hidden = false; positionMenu(anchor);
  }
  const modal = document.createElement('div');
  modal.className='orders-grid-modal'; modal.hidden=true;
  modal.innerHTML='<div class="orders-grid-modal-card" role="dialog" aria-modal="true" aria-labelledby="ordersGridModalTitle"><h3 id="ordersGridModalTitle">Alterar status do pedido</h3><p>Selecione a situação e confirme. As validações fiscais e de cancelamento permanecem ativas.</p><div id="ordersGridStatusHost"></div></div>';
  document.body.appendChild(modal);
  const modalHost = modal.querySelector('#ordersGridStatusHost');
  let modalOrigin = null;
  function closeStatus() {
    if (!modal.hidden && modalOrigin) { const form = modalHost.querySelector('form'); if (form) modalOrigin.appendChild(form); }
    modal.hidden=true; modalOrigin=null;
  }
  function openStatus(form, title) {
    closeMenu(); closeStatus();
    modalOrigin=form.parentNode;
    modalHost.appendChild(form);
    const close = document.createElement('button'); close.type='button'; close.className='orders-grid-modal-close'; close.textContent='Voltar';
    close.onclick = closeStatus;
    form.appendChild(close);
    // Botão de voltar é removido ao fechar, antes da próxima abertura.
    const previousClose = form.querySelectorAll('.orders-grid-modal-close');
    if (previousClose.length > 1) previousClose[0].remove();
    modal.querySelector('#ordersGridModalTitle').textContent=title;
    modal.hidden=false;
    const select=form.querySelector('select[name="status"]'); if(select) select.focus();
  }
  function moveAll(from, to) { if (!from) return; while(from.firstChild) to.appendChild(from.firstChild); }
  function originalLabel(el) { return ((el.innerText || el.textContent || '').trim()); }

  // Mantém os controles PHP existentes, mudando somente sua apresentação.
  const rows = Array.from(tbody.querySelectorAll('tr.pedidos-order-row'));
  rows.forEach(row => {
    const actionRow = row.nextElementSibling;
    if (!actionRow || !actionRow.classList.contains('pedidos-action-row')) return;
    const oldButtons = actionRow.querySelector('.pedidos-action-buttons');
    const newActions = row.querySelector('.orders-grid-icons');
    if (!oldButtons || !newActions) return;
    const groups = {print:pack('print'), fiscal:pack('fiscal'), more:pack('more')};
    const statusForm = actionRow.querySelector('.pedidos-status-form');
    const unused = document.createElement('div'); unused.hidden = true; actionRow.appendChild(unused);
    const fiscalSummary = actionRow.querySelector('.pedidos-fiscal-summary-values');
    const fiscalCell = row.querySelector('.orders-grid-fiscal-cell');
    if (fiscalSummary && fiscalCell) fiscalCell.appendChild(fiscalSummary);
    const candidates = Array.from(oldButtons.children);
    let edit = null, wa = null, email = null;
    for (const node of candidates) {
      const href = node.getAttribute('href') || '';
      const act = node.getAttribute('action') || '';
      const label = originalLabel(node).toLowerCase();
      if (node.classList.contains('pedidos-fiscal-menu')) {
        const dropdown = node.querySelector('.pedidos-fiscal-menu-list');
        if (dropdown) moveAll(dropdown, groups.fiscal);
        node.remove();
      } else if (node.classList.contains('pedidos-os-fiscal-actions')) {
        groups.fiscal.appendChild(node);
      } else if (href.includes('pedido_editar.php')) {
        edit = node;
      } else if (node.classList.contains('pedidos-whatsapp-form') || act.includes('pedido_whatsapp.php')) {
        wa = node;
      } else if (href.includes('email_documento.php')) {
        email = node;
      } else if (/fiscal|nfc-e|nf-e|nfs-e|nfe|nfce|nfse/.test(label + ' ' + href + ' ' + act)) {
        groups.fiscal.appendChild(node);
      } else if (/pedido_print|imprimir|produção|cozinha|visualizar/.test(label + ' ' + href)) {
        groups.print.appendChild(node);
      } else {
        groups.more.appendChild(node);
      }
    }
    if (edit) { edit.className='orders-grid-icon'; edit.title='Editar pedido'; edit.setAttribute('aria-label',edit.title); edit.innerHTML=svg('edit'); newActions.appendChild(edit); }
    if (groups.print.children.length) { const b=iconBtn('print','Imprimir: pedido, produção e cozinha'); b.onclick=()=>window.nexaOrdersDialogs.openActions('print',groups.print,'Pedido #' + String(row.dataset.orderId),b); newActions.appendChild(b); }
    if (groups.fiscal.children.length) { const b=iconBtn('fiscal','Documento fiscal: emitir, consultar ou imprimir'); b.onclick=()=>window.nexaOrdersDialogs.openActions('fiscal',groups.fiscal,'Pedido #' + String(row.dataset.orderId),b); newActions.appendChild(b); }
    if (wa) {
      const btn=wa.querySelector('button');
      if (btn) { btn.className='orders-grid-icon'; btn.title='Enviar pelo WhatsApp'; btn.setAttribute('aria-label',btn.title); btn.innerHTML=svg('whatsapp'); }
      newActions.appendChild(wa);
    }
    if (email) { email.className='orders-grid-icon'; email.title='Enviar por e-mail'; email.setAttribute('aria-label',email.title); email.innerHTML=svg('mail'); newActions.appendChild(email); }
    if (statusForm) {
      unused.appendChild(statusForm);
      const b=iconBtn('status','Trocar status');
      b.onclick=()=>window.nexaOrdersDialogs.openStatus(statusForm,'Trocar status · Pedido #' + String(row.dataset.orderId),b);
      newActions.appendChild(b);
    }
    if (groups.more.children.length) { const b=iconBtn('more','Outras ações do pedido'); b.onclick=()=>showMenu(b,groups.more); newActions.appendChild(b); }
    unused.append(groups.print,groups.fiscal,groups.more);
  });
  // Fechamento da modal pelo fundo e Esc.
  modal.addEventListener('click', e=>{if(e.target===modal)closeStatus();});
  document.addEventListener('keydown', e=>{ if(e.key==='Escape'){closeMenu();closeStatus();closeFilter();}});
  document.addEventListener('pointerdown', e=>{ if(!menu.hidden && !menu.contains(e.target) && !e.target.closest('.orders-grid-icon')) closeMenu();});
  wrap.addEventListener('scroll',closeMenu,{passive:true});
  window.addEventListener('resize',closeMenu);

  const filterPop = document.createElement('div');filterPop.className='orders-grid-popover';filterPop.hidden=true;
  filterPop.innerHTML='<label class="orders-grid-filter-label">Filtrar coluna</label><input type="search" placeholder="Digite para filtrar nesta coluna" autocomplete="off"><button class="btn" type="button">Limpar filtro</button>';
  document.body.appendChild(filterPop);
  let filterCol=null;
  const filterInput=filterPop.querySelector('input');
  function closeFilter(){filterPop.hidden=true;filterCol=null;}
  function showFilter(anchor,col){ closeMenu(); filterCol=col; filterPop.hidden=false; filterPop.querySelector('label').textContent='Filtrar · '+labels[col];filterInput.value=filters[col]||''; const r=anchor.getBoundingClientRect(); filterPop.style.left=Math.max(8,Math.min(r.left,window.innerWidth-258))+'px';filterPop.style.top=Math.max(8,Math.min(r.bottom+6,window.innerHeight-140))+'px';filterInput.focus();}
  filterInput.addEventListener('input',()=>{if(filterCol){filters[filterCol]=filterInput.value.toLocaleLowerCase('pt-BR');applyFilter();updateFilterButtons();}});
  filterPop.querySelector('button').onclick=()=>{if(filterCol){filters[filterCol]='';filterInput.value='';applyFilter();updateFilterButtons();filterInput.focus();}};
  document.addEventListener('pointerdown',e=>{if(!filterPop.hidden && !filterPop.contains(e.target) && !e.target.closest('.orders-grid-filter-btn'))closeFilter();});
  wrap.addEventListener('scroll',closeFilter,{passive:true});

  function groupsInTable() {return rows.map(row=>({row, action:row.nextElementSibling && row.nextElementSibling.classList.contains('pedidos-action-row') ? row.nextElementSibling : null}));}
  const rowPairs = groupsInTable();
  function cell(row,col){return Array.from(row.cells).find(el=>el.dataset.col===col);}
  function applyFilter(){
    let shown=0;
    for(const pair of rowPairs){
      const ok=Object.entries(filters).every(([col,text])=> !text || (cell(pair.row,col)?.textContent || '').toLocaleLowerCase('pt-BR').includes(text));
      pair.row.hidden=!ok;if(pair.action)pair.action.hidden=!ok;if(ok)shown++;
    }
    const state=document.getElementById('ordersGridFilteredCount');if(state)state.textContent=shown===rows.length ? (shown+' visíveis') : (shown+' de '+rows.length+' visíveis');
  }
  function sortValue(row,col){const raw=(cell(row,col)?.textContent||'').trim();if(col==='total')return Number(raw.replace(/[^0-9,\-]/g,'').replace(',','.'))||0;if(col==='numero')return Number(raw.replace(/\D/g,''))||0;return raw.toLocaleLowerCase('pt-BR');}
  function sortBy(col){
    activeSortDir=activeSort===col ? -activeSortDir : 1;activeSort=col;
    const items=rowPairs.slice().sort((a,b)=>{const x=sortValue(a.row,col),y=sortValue(b.row,col);return (typeof x==='number' ? x-y : x.localeCompare(y,'pt-BR',{numeric:true})) * activeSortDir;});
    items.forEach(pair=>{tbody.appendChild(pair.row);if(pair.action)tbody.appendChild(pair.action);});
  }
  function updateFilterButtons(){headers.forEach(th=>{const btn=th.querySelector('.orders-grid-filter-btn'); if(btn)btn.classList.toggle('active',!!filters[th.dataset.col]);});}

  // Configuração de colunas com ações obrigatoriamente na última posição.
  function applyOrder(){
    const final=[...order,'acoes'];
    table.querySelectorAll('thead tr,tbody tr.pedidos-order-row').forEach(tr=>{
      const map={}; Array.from(tr.children).forEach(td=>{if(td.dataset.col)map[td.dataset.col]=td;});
      final.forEach(col=>{if(map[col])tr.appendChild(map[col]);});
    });
  }
  function width(col){const n=Number(widths[col]);return Number.isFinite(n)&&n>=70&&n<=650 ? Math.round(n) : (defaults[col]||140);}
  function applyWidths(){let sum=250;for(const col of movable){const px=width(col); table.querySelectorAll('[data-col="'+col+'"]:not(.orders-grid-hidden)').forEach(el=>{el.style.width=px+'px';el.style.minWidth=px+'px';el.style.maxWidth=px+'px';});if(visible.includes(col))sum+=px;}table.style.width=Math.max(wrap.clientWidth,sum)+'px';}
  function applyVisibility(){for(const col of movable){table.querySelectorAll('[data-col="'+col+'"]').forEach(el=>el.classList.toggle('orders-grid-hidden',!visible.includes(col)));}applyWidths();}
  function serialize(){return {visible:[...visible],order:[...order],widths:{...widths}};}
  function save(){
    const json=JSON.stringify(serialize());try{localStorage.setItem(storageKey,json);}catch(e){}
    if (cfg.cross || !cfg.csrf) {if(prefState)prefState.textContent='Preferências locais';return;}
    if(prefState)prefState.textContent='Salvando preferências…';
    const data=new URLSearchParams({action:'save_orders_grid_preferences',csrf_token:String(cfg.csrf),preferences:json});
    fetch('pedidos.php',{method:'POST',credentials:'same-origin',body:data,headers:{'Accept':'application/json'}}).then(r=>r.json().then(j=>({ok:r.ok,json:j}))).then(r=>{if(prefState)prefState.textContent=r.ok&&r.json.ok?'Preferências salvas':'Preferências apenas neste navegador';}).catch(()=>{if(prefState)prefState.textContent='Preferências apenas neste navegador';});
  }
  function scheduleSave(){clearTimeout(saveTimer);saveTimer=setTimeout(save,350);}
  const picker=document.getElementById('ordersGridColumns');
  if(picker){ movable.forEach(col=>{const label=document.createElement('label'); const inp=document.createElement('input');inp.type='checkbox';inp.checked=visible.includes(col);inp.addEventListener('change',()=>{if(!inp.checked && visible.length===1){inp.checked=true;return;}visible=inp.checked?[...visible,col]:visible.filter(x=>x!==col);applyVisibility();scheduleSave();});label.append(inp,document.createTextNode(labels[col]));picker.appendChild(label);});}
  for (const th of headers) {
    const col=th.dataset.col;if(col==='acoes')continue;
    const h=document.createElement('div');h.className='orders-grid-head';
    const drag=document.createElement('span');drag.className='orders-grid-drag';drag.textContent='⠿';drag.draggable=true;drag.title='Arraste para posicionar coluna';
    drag.addEventListener('dragstart',e=>{e.dataTransfer.setData('text/plain',col);e.dataTransfer.effectAllowed='move';});
    const title=document.createElement('button');title.type='button';title.className='orders-grid-head-label';title.textContent=labels[col];title.title='Ordenar por '+labels[col];title.addEventListener('click',()=>sortBy(col));
    const filter=document.createElement('button');filter.type='button';filter.className='orders-grid-filter-btn';filter.textContent='⌕';filter.title='Filtrar coluna '+labels[col];filter.onclick=()=>showFilter(filter,col);
    h.append(drag,title,filter);th.replaceChildren(h);
    th.addEventListener('dragover',e=>{e.preventDefault();e.dataTransfer.dropEffect='move';});
    th.addEventListener('drop',e=>{e.preventDefault();const src=e.dataTransfer.getData('text/plain');if(!movable.includes(src)||src===col)return;order=order.filter(x=>x!==src);order.splice(order.indexOf(col),0,src);applyOrder();scheduleSave();});
    const resize=document.createElement('span');resize.className='orders-grid-resizer';resize.title='Arraste para redimensionar';th.appendChild(resize);
    resize.addEventListener('pointerdown',ev=>{ev.preventDefault();ev.stopPropagation();const start=ev.clientX,oldWidth=width(col); resize.setPointerCapture(ev.pointerId);const moving=e=>{widths[col]=Math.max(70,Math.min(650,Math.round(oldWidth+e.clientX-start)));applyWidths();};const stop=()=>{resize.removeEventListener('pointermove',moving);resize.removeEventListener('pointerup',stop);resize.removeEventListener('pointercancel',stop);scheduleSave();};resize.addEventListener('pointermove',moving);resize.addEventListener('pointerup',stop);resize.addEventListener('pointercancel',stop);});
  }
  applyOrder();applyVisibility();
  document.documentElement.classList.add('orders-grid-ready');
})();

/* Nexa Próspero | Cards mobile v2
 * Todos os pedidos recebem ações compactas individualmente.
 * Primeiro reaproveita as ações já preparadas pelo grid; quando algum pedido
 * não possui par correspondente, reutiliza os controles PHP do próprio card.
 * Não altera URLs, formulários, CSRF ou permissões fiscais.
 */
(() => {
  'use strict';
  const list = document.getElementById('ordersMobileCardsV2');
  if (!list) return; // Opt-in inativo: consulta legada intocada.
  const table = document.getElementById('ordersGridV1');
  const tbody = table?.tBodies[0] || null;
  const media = window.matchMedia('(max-width: 900px)');
  const root = document.querySelector('.pedidos-grid-compact-card') || list;
  const cards = new Map();
  const fallbackDone = new WeakSet();

  const svg = kind => {
    const icons = {
      edit:'<path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L9 17l-4 1 1-4Z"/>',
      print:'<path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/>',
      fiscal:'<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M9 7h6M9 11h6M9 15h3"/>',
      mail:'<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m3 6 9 7 9-7"/>',
      whatsapp:'<path d="M21 11.5a8.5 8.5 0 0 1-12.6 7.4L3 21l2.1-5.4A8.5 8.5 0 1 1 21 11.5Z"/><path d="M8.5 8.8c.6 3.2 2.6 5.2 5.8 6l1.6-1.4"/>',
      status:'<path d="M3 12h18M12 3l-4 4 4 4M12 21l4-4-4-4"/>',
      more:'<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>'
    };
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + (icons[kind] || icons.more) + '</svg>';
  };
  function decorate(el,kind,label) {
    if (!el) return;
    el.classList.remove('btn','btn-sm','btn-primary');
    el.classList.add('orders-grid-icon');
    el.title=label; el.setAttribute('aria-label',label);
    el.innerHTML=svg(kind);
  }
  function iconButton(kind,label) {
    const b=document.createElement('button'); b.type='button';
    decorate(b,kind,label); return b;
  }
  const popover=document.createElement('div');
  popover.className='orders-grid-popover orders-mobile-popover';
  popover.hidden=true; popover.setAttribute('role','menu');
  document.body.appendChild(popover);
  let activePack=null, activeButton=null;
  function closeMenu() {
    if (activePack) while(popover.firstChild) activePack.appendChild(popover.firstChild);
    if (activeButton) activeButton.setAttribute('aria-expanded','false');
    activePack=null; activeButton=null; popover.hidden=true;
  }
  function openMenu(button,pack) {
    if (activePack===pack && !popover.hidden) {closeMenu();return;}
    closeMenu();
    activePack=pack; activeButton=button;
    while(pack.firstChild) popover.appendChild(pack.firstChild);
    button.setAttribute('aria-expanded','true');
    popover.hidden=false;
    const rect=button.getBoundingClientRect();
    const width=Math.max(194,popover.offsetWidth||194);
    popover.style.left=Math.max(8,Math.min(rect.right-width,innerWidth-width-8))+'px';
    const height=Math.min(popover.scrollHeight||180,innerHeight*.7);
    popover.style.top=(rect.bottom+height+6<innerHeight ? rect.bottom+5 : Math.max(6,rect.top-height-5))+'px';
  }
  const modal=document.createElement('div');
  modal.className='orders-grid-modal orders-mobile-status-modal'; modal.hidden=true;
  modal.innerHTML='<div class="orders-grid-modal-card" role="dialog" aria-modal="true" aria-labelledby="ordersMobileStatusTitle"><h3 id="ordersMobileStatusTitle">Trocar status</h3><p>Selecione a situação e confirme. As validações fiscais e de cancelamento continuam ativas.</p><div id="ordersMobileStatusHost"></div></div>';
  document.body.appendChild(modal);
  const modalHost=modal.querySelector('#ordersMobileStatusHost');
  let modalForm=null, modalParent=null, modalCloseBtn=null;
  function closeStatus() {
    if(modalForm && modalParent) {
      if(modalCloseBtn) modalCloseBtn.remove();
      modalParent.appendChild(modalForm);
    }
    modalForm=null; modalParent=null; modalCloseBtn=null; modal.hidden=true;
  }
  function openStatus(form,card) {
    closeMenu(); closeStatus();
    modalForm=form; modalParent=form.parentElement;
    modalHost.appendChild(form);
    modalCloseBtn=document.createElement('button'); modalCloseBtn.type='button';
    modalCloseBtn.className='orders-grid-modal-close';modalCloseBtn.textContent='Voltar';
    modalCloseBtn.addEventListener('click',closeStatus);
    form.appendChild(modalCloseBtn);
    const number=card.querySelector('.pedidos-mobile-head .idlink')?.textContent?.trim()||'';
    modal.querySelector('#ordersMobileStatusTitle').textContent='Trocar status · Pedido '+number;
    modal.hidden=false;
    form.querySelector('select[name="status"]')?.focus();
  }
  modal.addEventListener('click',e=>{if(e.target===modal)closeStatus();});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeMenu();closeStatus();}});
  document.addEventListener('pointerdown',e=>{
    if(!popover.hidden && !popover.contains(e.target) && !e.target.closest('.orders-mobile-menu-trigger')) closeMenu();
  });
  window.addEventListener('resize',closeMenu);

  function classify(node) {
    const href=node.getAttribute('href')||'';
    const action=node.getAttribute('action')||'';
    const label=(node.innerText||node.textContent||'').toLowerCase();
    if(href.includes('pedido_editar.php')) return 'edit';
    if(action.includes('pedido_whatsapp.php')) return 'whatsapp';
    if(href.includes('email_documento.php')) return 'mail';
    if(node.classList.contains('pedidos-fiscal-menu') || node.classList.contains('pedidos-os-fiscal-actions') ||
       /fiscal_|os_fiscal|os_faturar|nfse_/.test(href+' '+action) || /fiscal|nf-e|nfc-e|nfs-e/.test(label)) return 'fiscal';
    if(/pedido_print|produ[cç][aã]o|cozinha|imprimir|visualizar/.test(href+' '+label)) return 'print';
    return 'more';
  }
  function installFallback(card) {
    if(fallbackDone.has(card)) return;
    const host=card.querySelector('.pedidos-mobile-icon-host');
    const old=card.querySelector('.pedidos-mobile-buttons');
    if(!host || !old) return;
    fallbackDone.add(card);
    const groups={print:document.createElement('div'),fiscal:document.createElement('div'),more:document.createElement('div')};
    const originals=Array.from(old.children);
    for(const node of originals) {
      const kind=classify(node);
      if(kind==='edit' && node.matches('a')) {decorate(node,'edit','Editar pedido');host.appendChild(node);}
      else if(kind==='mail' && node.matches('a')) {decorate(node,'mail','Enviar pedido por e-mail');host.appendChild(node);}
      else if(kind==='whatsapp' && node.matches('form')) {
        decorate(node.querySelector('button'),'whatsapp','Enviar pelo WhatsApp');host.appendChild(node);
      } else groups[kind].appendChild(node);
    }
    for(const kind of ['print','fiscal','more']) {
      const group=groups[kind];
      if(!group.children.length) continue;
      group.className='orders-mobile-action-pack';
      old.appendChild(group);
      const label={print:'Imprimir: pedido, produção e cozinha',fiscal:'Documento fiscal: emitir, consultar ou imprimir',more:'Outras ações do pedido'}[kind];
      const btn=iconButton(kind,label);
      btn.classList.add('orders-mobile-menu-trigger');btn.setAttribute('aria-haspopup','menu');btn.setAttribute('aria-expanded','false');
      btn.addEventListener('click',()=>{
        if(kind==='print' || kind==='fiscal') window.nexaOrdersDialogs.openActions(kind,group,'Pedido '+(card.querySelector('.pedidos-mobile-head .idlink')?.textContent?.trim() || '#'+card.dataset.mobileOrderId),btn);
        else openMenu(btn,group);
      });
      host.appendChild(btn);
    }
    const status=card.querySelector('.pedidos-mobile-actions > .pedidos-status-form');
    if(status) {
      const b=iconButton('status','Trocar status');
      b.addEventListener('click',()=>window.nexaOrdersDialogs.openStatus(status,'Trocar status · Pedido '+(card.querySelector('.pedidos-mobile-head .idlink')?.textContent?.trim() || '#'+card.dataset.mobileOrderId),b));
      host.appendChild(b);
    }
    card.classList.add('orders-mobile-card-fallback');
  }
  function prepareCard(card) {
    if(!card.dataset.mobileOrderId) return;
    const anchor=card.querySelector('.pedidos-mobile-head .idlink');
    if(anchor && !card.dataset.mobileLinkAttached) {
      card.dataset.mobileLinkAttached='1';
      card.setAttribute('aria-label','Abrir pedido '+anchor.textContent.trim());
      card.addEventListener('click',event=>{
        if(event.target.closest('a,button,input,select,textarea,label,form,[role="button"]'))return;
        window.location.assign(anchor.href);
      });
      card.addEventListener('keydown',event=>{
        if(event.target!==card || !['Enter',' '].includes(event.key))return;
        event.preventDefault();window.location.assign(anchor.href);
      });
    }
  }
  function reconcile() {
    // Busca TODOS os cards, não apenas os pares identificados no grid.
    root.querySelectorAll('.pedidos-mobile-card-v2[data-mobile-order-id]').forEach(card=>{
      cards.set(card.dataset.mobileOrderId,card);
      prepareCard(card);
    });
    const rows=tbody ? Array.from(tbody.querySelectorAll('tr.pedidos-order-row[data-order-id]')) : [];
    rows.forEach((row,index)=>{
      const card=cards.get(row.dataset.orderId);
      if(!card)return;
      card.hidden=row.hidden;
      const host=card.querySelector('.pedidos-mobile-icon-host');
      const actions=row.querySelector('.orders-grid-icons') || host?.querySelector('.orders-grid-icons');
      if(!card.classList.contains('orders-mobile-card-fallback') && host && actions && actions.children.length) {
        if(media.matches) {
          if(actions.parentNode!==host) host.appendChild(actions);
        } else {
          const cell=row.querySelector('.orders-grid-actions-col');
          if(cell && actions.parentNode!==cell)cell.appendChild(actions);
        }
        card.classList.add('orders-mobile-card-enhanced');
      } else if(!card.classList.contains('orders-mobile-card-enhanced')) {
        installFallback(card);
      }
    });
    // Mesmo se faltarem pares na tabela, cada card recebe ações compactas.
    cards.forEach(card=>{
      if(!card.classList.contains('orders-mobile-card-enhanced') && !card.classList.contains('orders-mobile-card-fallback'))installFallback(card);
    });
    if(tbody) {
      const ordered=rows.map(row=>cards.get(row.dataset.orderId)).filter(Boolean);
      ordered.forEach((card,index)=>{
        if(list.children[index]!==card) list.insertBefore(card,list.children[index]||null);
      });
    }
  }
  let queued=false;
  function schedule() {
    if(queued)return;
    queued=true;
    requestAnimationFrame(()=>{queued=false;reconcile();});
  }
  reconcile();
  if(tbody) new MutationObserver(schedule).observe(tbody,{childList:true,subtree:true,attributes:true,attributeFilter:['hidden']});
  new MutationObserver(schedule).observe(list,{childList:true});
  if(typeof media.addEventListener==='function')media.addEventListener('change',schedule);
  else if(typeof media.addListener==='function')media.addListener(schedule);
  window.addEventListener('pageshow',schedule);
})();

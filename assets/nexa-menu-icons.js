// Nexa Próspero - Ícones dos menus. Arquivo autônomo v4 (expansão temporária por mouse).
// Não altera permissões, links nem comportamento do PDV Checkout.
(function(){
  'use strict';
  function init(){
    if(document.body.dataset.nexaMenuIconsV3==='ready') return;
    const side=document.querySelector('[data-admin-sidebar]');
    const horizontal=document.querySelectorAll('.navlinks a, .admin-compact-nav a');
    if(!side && !horizontal.length) return;
    // Checkout e telas de trabalho integral continuam com seu layout próprio.
    if(document.querySelector('.pdv-checkout-mode, .cmd-checkout-mode')) return;
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

    function putIcon(anchor){
      if(!anchor || anchor.querySelector('.nexa-menu-glyph')) return;
      const label=(anchor.textContent||'').replace(/\s+/g,' ').trim();
      if(!label) return;
      const key=classify(anchor,label);
      const glyph=document.createElement('span');
      glyph.className='nexa-menu-glyph';
      glyph.setAttribute('aria-hidden','true');
      glyph.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.45" stroke-linecap="round" stroke-linejoin="round">'+(icons[key]||icons.dot)+'</svg>';
      const caption=document.createElement('span');
      caption.className='nexa-menu-caption';
      caption.textContent=label;
      anchor.textContent='';
      anchor.appendChild(glyph);
      anchor.appendChild(caption);
      anchor.setAttribute('title',label);
      if(!anchor.hasAttribute('aria-label'))anchor.setAttribute('aria-label',label);
    }
    if(side){
      side.querySelectorAll('.admin-side-main,.admin-side-link,.admin-side-children a').forEach(putIcon);
      document.body.classList.add('nexa-nav-icons');

      // Expansão temporária: não altera o estado persistido do botão ☰.
      // O admin.js existente continua responsável por fixar/desfixar o menu.
      const hoverClass='nexa-sidebar-hover-expanded';
      let exitTimer=null;
      const cancelClose=()=>{
        if(exitTimer!==null){clearTimeout(exitTimer);exitTimer=null;}
      };
      const compactDesktop=()=>window.innerWidth>920
        && document.body.classList.contains('admin-side-menu-enabled')
        && document.body.classList.contains('admin-sidebar-collapsed')
        && !document.querySelector('.pdv-checkout-mode, .cmd-checkout-mode');
      const mouseEnabled=()=>window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
      const temporaryOpen=()=>{
        cancelClose();
        if(compactDesktop()) document.body.classList.add(hoverClass);
      };
      const temporaryClose=(delay)=>{
        cancelClose();
        if(!delay){document.body.classList.remove(hoverClass);return;}
        exitTimer=window.setTimeout(()=>{
          document.body.classList.remove(hoverClass);
          exitTimer=null;
        },130);
      };
      side.addEventListener('pointerenter',ev=>{
        if(ev.pointerType==='mouse' && mouseEnabled()) temporaryOpen();
      });
      side.addEventListener('pointerleave',ev=>{
        if(ev.pointerType==='mouse') temporaryClose(true);
      });
      // Suporte à navegação por teclado no menu recolhido.
      side.addEventListener('focusin',temporaryOpen);
      side.addEventListener('focusout',ev=>{
        if(!side.contains(ev.relatedTarget))temporaryClose(true);
      });
      window.addEventListener('resize',()=>{
        if(window.innerWidth<=920)temporaryClose(false);
      });
      document.addEventListener('visibilitychange',()=>{
        if(document.hidden)temporaryClose(false);
      });

      // Submenus: o clique abre o grupo sem fixar a barra lateral.
      // O handler original do admin.js realiza a abertura/fechamento do grupo.
      side.querySelectorAll('.admin-side-group > .admin-side-main').forEach(anchor=>{
        if(anchor.dataset.nexaIconParentBound==='1')return;
        anchor.dataset.nexaIconParentBound='1';
        anchor.addEventListener('click',()=>{
          if(compactDesktop())temporaryOpen();
        },true);
      });
    }
    // Empresas que optam pelo menu superior também recebem os ícones.
    horizontal.forEach(putIcon);
    document.body.classList.add('nexa-menu-icons-v3');
    document.body.dataset.nexaMenuIconsV3='ready';
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});
  else init();
})();

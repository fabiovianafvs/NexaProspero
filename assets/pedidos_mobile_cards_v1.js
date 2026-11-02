/* Nexa Próspero | Consulta de Pedidos - cards móveis opt-in.
 * Transfere os mesmos botões e formulários JÁ montados pelo grid desktop,
 * sem clonar ações fiscais nem alterar endpoints, CSRF ou permissões.
 */
(() => {
  'use strict';
  const list = document.getElementById('ordersMobileCardsV2');
  const table = document.getElementById('ordersGridV1');
  if (!list || !table) return;  // opção desabilitada: sem efeito
  const media = window.matchMedia('(max-width: 900px)');
  const tbody = table.tBodies[0];
  if (!tbody) return;
  const cards = new Map(
    Array.from(list.querySelectorAll('.pedidos-mobile-card-v2[data-mobile-order-id]'))
      .map(card => [card.dataset.mobileOrderId, card])
  );
  const actionPairs = [];
  table.querySelectorAll('tbody tr.pedidos-order-row[data-order-id]').forEach(row => {
    const card = cards.get(row.dataset.orderId);
    const desktop = row.querySelector('.orders-grid-icons');
    const mobile = card?.querySelector('.pedidos-mobile-icon-host');
    // Sem ações de grid inicializadas, preserva fallback legado.
    if (card && desktop && mobile && desktop.children.length) {
      actionPairs.push({row, card, desktop, mobile});
      const source = card.querySelector('.pedidos-mobile-head .idlink');
      const href = source?.getAttribute('href');
      if (href) {
        card.setAttribute('aria-label', 'Abrir pedido ' + source.textContent.trim());
        card.addEventListener('click', event => {
          if (event.target.closest('a,button,input,select,textarea,label,form,[role="button"]')) return;
          window.location.assign(href);
        });
        card.addEventListener('keydown', event => {
          if (event.target !== card || (event.key !== 'Enter' && event.key !== ' ')) return;
          event.preventDefault(); window.location.assign(href);
        });
      }
    }
  });
  if (!actionPairs.length) return;
  const syncPlacement = () => {
    const isMobile = media.matches;
    actionPairs.forEach(({row,desktop,mobile}) => {
      if (isMobile) {
        if (desktop.parentElement !== mobile) mobile.appendChild(desktop);
      } else {
        const cell = row.querySelector('.orders-grid-actions-col');
        if (cell && desktop.parentElement !== cell) cell.appendChild(desktop);
      }
    });
    list.classList.toggle('orders-mobile-ready', isMobile && actionPairs.length === cards.size);
  };
  const syncRows = () => {
    tbody.querySelectorAll('tr.pedidos-order-row[data-order-id]').forEach(row => {
      const card = cards.get(row.dataset.orderId);
      if (card) { card.hidden = row.hidden; list.appendChild(card); }
    });
  };
  let scheduled = false;
  const scheduleRows = () => {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => { scheduled = false; syncRows(); });
  };
  syncPlacement();
  syncRows();
  const observer = new MutationObserver(scheduleRows);
  observer.observe(tbody, {childList:true, subtree:true, attributes:true, attributeFilter:['hidden']});
  if (typeof media.addEventListener === 'function') media.addEventListener('change', syncPlacement);
  else if (typeof media.addListener === 'function') media.addListener(syncPlacement);
  window.addEventListener('pageshow', () => {syncPlacement(); syncRows();});
})();

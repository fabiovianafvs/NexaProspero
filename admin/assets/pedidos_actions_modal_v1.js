/* Nexa Próspero · Consulta de Pedidos: modais de ações.
 * Isolado à opção "Novo grid". Reutiliza botões, links, formulários, permissões e CSRF
 * já renderizados pelo PHP; nenhuma nova operação fiscal é criada aqui.
 */
(() => {
  'use strict';
  if (!document.getElementById('ordersGridV1')) return;
  const modal = document.createElement('div');
  modal.className = 'orders-action-dialog';
  modal.hidden = true;
  modal.innerHTML = `
    <div class="orders-action-dialog-card" role="dialog" aria-modal="true" aria-labelledby="ordersActionTitle" aria-describedby="ordersActionDescription">
      <h3 id="ordersActionTitle"></h3>
      <p id="ordersActionDescription"></p>
      <div class="orders-action-dialog-body" id="ordersActionBody"></div>
      <div class="orders-action-dialog-footer" id="ordersActionFooter"></div>
    </div>`;
  document.body.appendChild(modal);
  const heading = modal.querySelector('#ordersActionTitle');
  const description = modal.querySelector('#ordersActionDescription');
  const body = modal.querySelector('#ordersActionBody');
  const footer = modal.querySelector('#ordersActionFooter');
  let activeNode = null;
  let placeholder = null;
  let returnFocus = null;
  let submitting = false;

  function giveFocus() {
    const target = modal.querySelector('select, input:not([type="hidden"]), button:not([disabled]), a[href]');
    if (target) target.focus();
  }
  function close(options={}) {
    if (submitting && !options.force) return;
    modal.hidden = true;
    document.body.classList.remove('orders-action-dialog-open');
    if (activeNode && placeholder && placeholder.parentNode) {
      // Formulários de status são reutilizados. Não acumular botões Voltar.
      if (activeNode.matches('form.pedidos-status-form')) {
        if (!submitting) { activeNode.reset(); activeNode.querySelector('select[name="status"]')?.dispatchEvent(new Event('change', {bubbles:true})); }
        activeNode.querySelectorAll('.orders-action-back').forEach(button=>button.remove());
        activeNode.classList.remove('orders-action-dialog-status-form');
      }
      placeholder.replaceWith(activeNode);
    }
    activeNode = null; placeholder = null;
    body.replaceChildren(); footer.replaceChildren();
    if (returnFocus && returnFocus.isConnected && !options.skipFocus) returnFocus.focus();
    returnFocus = null;
  }
  function mount(node, title, desc, trigger) {
    close({force:true,skipFocus:true});
    submitting = false;
    if (!node || !node.parentNode) return false;
    placeholder = document.createComment('nexa-orders-action-return');
    node.parentNode.insertBefore(placeholder,node);
    activeNode = node;
    returnFocus = trigger || document.activeElement;
    heading.textContent = title;
    description.textContent = desc;
    body.appendChild(node);
    modal.hidden = false;
    document.body.classList.add('orders-action-dialog-open');
    return true;
  }
  function backButton() {
    const button = document.createElement('button');
    button.type='button';
    button.className='orders-action-back';
    button.textContent='Voltar';
    button.addEventListener('click',()=>close());
    return button;
  }
  function openActions(kind, pack, label, trigger) {
    if (modal.hidden === false && activeNode === pack) { close(); return; }
    const title = (kind === 'print' ? 'Impressões' : 'Documento fiscal')+' · '+label;
    const detail = kind === 'print'
      ? 'Escolha a impressão desejada. O documento será aberto no navegador.'
      : 'Selecione uma operação fiscal. As regras de emissão e autorização continuam sendo verificadas pelo Nexa.';
    if (!mount(pack,title,detail,trigger)) return;
    modal.dataset.kind = kind;
    pack.classList.add('orders-action-dialog-options');
    footer.appendChild(backButton());
    giveFocus();
  }
  function openStatus(form, title, trigger) {
    if (!mount(form,title,'Selecione o novo status e confirme. As validações fiscais e de cancelamento continuam ativas.',trigger)) return;
    modal.dataset.kind='status';
    // Rodapé no próprio formulário, para manter os botões alinhados.
    form.classList.add('orders-action-dialog-status-form');
    const back=backButton();
    back.classList.add('orders-grid-modal-close');
    form.appendChild(back);
    // Ao fechar, o formulário volta ao local original sem acumular botões.
    const select = form.querySelector('select[name="status"]');
    if (select) select.focus();
  }
  function showProcessing(message) {
    // Mesmo overlay institucional (logo Nexa + animação) usado nas demais telas.
    // O carregamento indica uma requisição iniciada, não confirma êxito na SEFAZ.
    if (typeof window.adminShowProcessing === 'function') window.adminShowProcessing(message);
  }
  function messageForForm(form) {
    if (modal.dataset.kind === 'status') return 'Atualizando situação do pedido... Aguarde a confirmação do sistema.';
    const action = String(form.querySelector('[name="action"]')?.value || '').toLowerCase();
    const type = String(form.querySelector('[name="doc_type"]')?.value || '').toUpperCase();
    if (/emitir|enqueue|authorize|reprocess/.test(action)) return 'Processando '+(type ? type.replace('NFCE','NFC-e').replace('NFSE','NFS-e').replace('NFE','NF-e') : 'documento fiscal')+'... Aguarde o retorno oficial.';
    if (/consult/.test(action)) return 'Consultando situação fiscal... Aguarde a resposta.';
    return 'Processando operação fiscal... Aguarde a confirmação do sistema.';
  }
  // Os handlers de validação do formulário (incluindo motivo e confirmação do
  // cancelamento) são executados no próprio form, ANTES deste listener no documento.
  document.addEventListener('submit', ev => {
    if (modal.hidden || !modal.contains(ev.target) || ev.defaultPrevented) return;
    const form=ev.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.matches('[data-ajax], [target="_blank"]')) return;
    submitting = true;
    // Não remova/mova o formulário durante o submit: mantém campos e CSRF intactos.
    modal.hidden = true;
    document.body.classList.remove('orders-action-dialog-open');
    const message=messageForForm(form);
    // admin.js também observa submit; reaplica o texto específico após sua rotina.
    showProcessing(message);
    window.setTimeout(()=>showProcessing(message),10);
  });
  // Links preservam target, permissões, parâmetros e a ação original.
  body.addEventListener('click', ev=>{
    const link = ev.target.closest('a[href]');
    if (!link || !body.contains(link)) return;
    if (link.getAttribute('href')?.startsWith('#')) return;
    const newTab=link.target && link.target !== '_self';
    modal.hidden=true;
    document.body.classList.remove('orders-action-dialog-open');
    showProcessing(newTab ? 'Preparando a impressão em outra aba...' : 'Abrindo documento solicitado...');
    if (newTab) {
      // Uma nova aba não devolve sinal de conclusão; não prender a tela artificialmente.
      window.setTimeout(()=>{
        if(typeof window.adminHideProcessing==='function') window.adminHideProcessing();
        close({force:true});
      },2000);
    }
  });
  modal.addEventListener('click', ev=>{if(ev.target===modal)close();});
  document.addEventListener('keydown',ev=>{
    if (modal.hidden) return;
    if (ev.key==='Escape') {ev.preventDefault();close();return;}
    if(ev.key !== 'Tab') return;
    const nodes=Array.from(modal.querySelectorAll('a[href], button:not([disabled]), input:not([type="hidden"]), select, textarea, [tabindex="0"]'))
      .filter(n=>n.getClientRects().length && getComputedStyle(n).visibility!=='hidden');
    if(!nodes.length) return;
    if(ev.shiftKey && document.activeElement===nodes[0]) {ev.preventDefault();nodes[nodes.length-1].focus();}
    else if(!ev.shiftKey && document.activeElement===nodes[nodes.length-1]) {ev.preventDefault();nodes[0].focus();}
  });
  window.addEventListener('pageshow',()=>{
    if (activeNode) {submitting=false;close({force:true});}
  });
  window.nexaOrdersDialogs = {openActions,openStatus,close};
})();

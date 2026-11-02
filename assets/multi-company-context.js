(() => {
  'use strict';

  const cfg = window.NEXA_MULTI_COMPANY_CONTEXT || {};
  if (!cfg.enabled) return;

  const token = typeof cfg.token === 'string' ? cfg.token : '';
  const modal = document.querySelector('[data-mc-switch-modal]');
  const openButton = document.querySelector('[data-mc-switch-open]');
  const statusBox = document.querySelector('[data-mc-switch-status]');
  let busy = false;

  function isEditableTarget(target) {
    if (!(target instanceof Element)) return false;
    return !!target.closest('input, textarea, select, [contenteditable="true"]');
  }

  function openModal() {
    if (!cfg.canSwitch || !modal) return;
    modal.hidden = false;
    document.body.classList.add('admin-company-modal-open');
    if (openButton) openButton.setAttribute('aria-expanded', 'true');
    const current = modal.querySelector('.admin-company-choice.is-current');
    const first = current || modal.querySelector('[data-mc-company-choice]');
    if (first instanceof HTMLElement) setTimeout(() => first.focus(), 0);
  }

  function closeModal() {
    if (!modal) return;
    modal.hidden = true;
    document.body.classList.remove('admin-company-modal-open');
    if (openButton) {
      openButton.setAttribute('aria-expanded', 'false');
      openButton.focus({preventScroll: true});
    }
  }

  function setStatus(message, isError = false) {
    if (!statusBox) return;
    statusBox.textContent = message || '';
    statusBox.style.color = isError ? '#b91c1c' : '#b45309';
  }

  function contextUrlWithToken(nextToken) {
    const url = new URL(window.location.href);
    if (nextToken) url.searchParams.set('mc_ctx', nextToken);
    else url.searchParams.delete('mc_ctx');
    return url.toString();
  }

  async function chooseCompany(button) {
    if (busy || !(button instanceof HTMLElement)) return;
    const companyId = Number(button.dataset.companyId || 0);
    if (!Number.isInteger(companyId) || companyId <= 0) return;

    if (companyId === Number(cfg.contextCompanyId || 0)) {
      closeModal();
      return;
    }

    busy = true;
    modal?.querySelectorAll('[data-mc-company-choice]').forEach((el) => { el.disabled = true; });
    setStatus('Validando acesso e preparando o contexto desta aba...');

    try {
      const body = new URLSearchParams();
      body.set('csrf', String(cfg.csrf || ''));
      body.set('target_company_id', String(companyId));
      if (token) body.set('mc_ctx', token);

      const response = await fetch(String(cfg.endpoint || '/admin/multiempresa_context_switch.php'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'Accept': 'application/json'
        },
        body: body.toString()
      });

      let data = null;
      try { data = await response.json(); } catch (_) { data = null; }
      if (!response.ok || !data || !data.ok) {
        throw new Error((data && data.reason) ? String(data.reason) : 'Não foi possível trocar o contexto.');
      }

      window.location.assign(contextUrlWithToken(String(data.token || '')));
    } catch (error) {
      setStatus('Não foi possível trocar a empresa: ' + (error?.message || 'erro inesperado'), true);
      busy = false;
      modal?.querySelectorAll('[data-mc-company-choice]').forEach((el) => { el.disabled = false; });
    }
  }

  function internalAdminUrl(raw) {
    if (!raw || raw.startsWith('#') || raw.toLowerCase().startsWith('javascript:')) return null;
    try {
      const url = new URL(raw, window.location.href);
      if (url.origin !== window.location.origin) return null;
      if (!url.pathname.startsWith('/admin/')) return null;
      if (/\/admin\/logout\.php$/i.test(url.pathname)) return null;
      if (/\/admin\/multiempresa_context_switch\.php$/i.test(url.pathname)) return null;
      return url;
    } catch (_) {
      return null;
    }
  }

  function decorateLink(anchor) {
    if (!token || !(anchor instanceof HTMLAnchorElement)) return;
    const url = internalAdminUrl(anchor.getAttribute('href') || '');
    if (!url) return;
    url.searchParams.set('mc_ctx', token);
    anchor.href = url.toString();
  }

  function decorateForm(form) {
    if (!token || !(form instanceof HTMLFormElement)) return;
    const actionRaw = form.getAttribute('action') || window.location.href;
    const url = internalAdminUrl(actionRaw);
    if (!url) return;
    let input = form.querySelector('input[name="mc_ctx"]');
    if (!(input instanceof HTMLInputElement)) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'mc_ctx';
      form.appendChild(input);
    }
    input.value = token;
  }

  if (token) {
    document.querySelectorAll('a[href]').forEach(decorateLink);
    document.querySelectorAll('form').forEach(decorateForm);
  }

  openButton?.addEventListener('click', openModal);
  modal?.addEventListener('click', (event) => {
    const close = event.target instanceof Element ? event.target.closest('[data-mc-switch-close]') : null;
    if (close) { closeModal(); return; }
    const choice = event.target instanceof Element ? event.target.closest('[data-mc-company-choice]') : null;
    if (choice instanceof HTMLElement) chooseCompany(choice);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'F11' && cfg.canSwitch) {
      event.preventDefault();
      event.stopPropagation();
      openModal();
      return;
    }
    if (event.key === 'Escape' && modal && !modal.hidden) {
      event.preventDefault();
      closeModal();
    }
  }, true);

  // Preserva o contexto nos links/formulários criados dinamicamente pelas telas.
  document.addEventListener('click', (event) => {
    if (!token || !(event.target instanceof Element)) return;
    const anchor = event.target.closest('a[href]');
    if (anchor instanceof HTMLAnchorElement) decorateLink(anchor);
  }, true);

  document.addEventListener('submit', (event) => {
    if (!token) return;
    if (event.target instanceof HTMLFormElement) decorateForm(event.target);
  }, true);
})();

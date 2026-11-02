(() => {
  'use strict';

  const root = document.getElementById('planBuilder');
  if (!root) return;

  const money = (value) => Number(value || 0).toLocaleString('pt-BR', {
    style: 'currency', currency: 'BRL'
  });
  const escapeHtml = (value) => {
    const div = document.createElement('div'); div.textContent = value; return div.innerHTML;
  };
  const sellerBrand = String(window.NEXA_BUILDER?.seller || 'Nexa Próspero').trim() || 'Nexa Próspero';
  const productBrand = String(window.NEXA_BUILDER?.product || 'Nexa Próspero').trim() || 'Nexa Próspero';

  const deliveryBox = root.querySelector('.delivery-options');
  const lineInputs = [...root.querySelectorAll('input[name="linha"]')];
  const deliveryInputs = [...root.querySelectorAll('input[name="delivery"]')];
  const moduleChecks = [...root.querySelectorAll('.addon-card .addon-select')];
  const quantityInputs = [...root.querySelectorAll('.addon-card input[data-quantity-for]')];
  const gestao = moduleChecks.find((input) => (input.dataset.moduleCode || input.value || input.name) === 'gestao') || null;
  const summaryTitle = document.getElementById('summaryTitle');
  const summaryItems = document.getElementById('summaryItems');
  const monthlyTotal = document.getElementById('monthlyTotal');
  const setupTotal = document.getElementById('setupTotal');
  const waQuote = document.getElementById('waQuote');
  const submitButton = root.querySelector('.plan-recap button[type="submit"]');
  const hiddenLine = document.getElementById('nexaLine');
  const hiddenDelivery = document.getElementById('nexaDelivery');
  const hiddenDeliveryPlanId = document.getElementById('nexaDeliveryPlanId');

  const mobileProgress = document.getElementById('mobileWizardProgress');
  const mobileAction = document.getElementById('mobilePlanAction');
  const mobileNext = document.getElementById('mobileNext');
  const mobileBack = document.getElementById('mobileBack');
  const mobileCurrentValue = document.getElementById('mobileCurrentValue');
  const mobileValueLabel = document.getElementById('mobileValueLabel');
  const mobileStepLabel = document.getElementById('mobileStepLabel');
  const mobileProgressBar = document.getElementById('mobileProgressBar');
  const mobileQuery = window.matchMedia('(max-width: 760px)');
  let mobileStep = 1;
  let previousLine = root.querySelector('input[name="linha"]:checked')?.value || '';
  let totals = { monthly: 0, setup: 0, items: [] };

  const selectedText = (input, selector) => {
    const holder = input.closest('.addon-card') || input.closest('label');
    return holder?.querySelector(selector)?.textContent?.trim() || input.value;
  };
  const currentLine = () => root.querySelector('input[name="linha"]:checked')?.value || '';
  const selectedDelivery = () => root.querySelector('input[name="delivery"]:checked');
  const quantityInputFor = (moduleInput) => moduleInput.closest('.addon-card')?.querySelector('input[data-quantity-for]') || null;

  function clampQuantity(input) {
    if (!input) return 1;
    const min = Math.max(1, Number.parseInt(input.min || '1', 10) || 1);
    const max = Math.max(min, Number.parseInt(input.max || '99', 10) || 99);
    let value = Number.parseInt(input.value || String(min), 10);
    if (!Number.isFinite(value)) value = min;
    value = Math.max(min, Math.min(max, value));
    input.value = String(value);
    return value;
  }

  function syncAddonState(input) {
    input.closest('.addon-card')?.classList.toggle('is-selected', input.checked);
  }

  function calculate() {
    const line = currentLine();
    const items = [];
    let monthly = 0, setup = 0;

    if (deliveryBox) deliveryBox.hidden = line !== 'food';
    if (hiddenLine) hiddenLine.value = line;
    const deliverySelection = selectedDelivery();
    if (hiddenDelivery) hiddenDelivery.value = deliverySelection?.value || '';
    if (hiddenDeliveryPlanId) hiddenDeliveryPlanId.value = deliverySelection?.dataset.planId || '0';

    if (line === 'food') {
      if (previousLine === 'gestao' && gestao?.dataset.autoSelected === '1') {
        gestao.checked = false;
        delete gestao.dataset.autoSelected;
        syncAddonState(gestao);
      }
      const delivery = selectedDelivery();
      if (delivery) {
        monthly += Number(delivery.dataset.monthly || 0);
        setup += Number(delivery.dataset.setup || 0);
        items.push(selectedText(delivery, '.delivery-card-title strong'));
      }
    } else if (line === 'gestao' && gestao) {
      if (!gestao.checked) {
        gestao.checked = true;
        gestao.dataset.autoSelected = '1';
      }
      syncAddonState(gestao);
    }

    moduleChecks.forEach((input) => {
      syncAddonState(input);
      if (!input.checked) return;
      const quantity = input.dataset.allowQuantity === '1' ? clampQuantity(quantityInputFor(input)) : 1;
      monthly += Number(input.dataset.monthly || 0) * quantity;
      setup += Number(input.dataset.setup || 0) * quantity;
      const itemName = selectedText(input, '.addon-copy strong');
      items.push(quantity > 1 ? `${quantity} × ${itemName}` : itemName);
    });

    totals = { monthly, setup, items };
    if (summaryTitle) {
      summaryTitle.textContent = line === 'food' ? `${productBrand} Food` : line === 'gestao' ? `${productBrand} Gestão` : productBrand;
    }
    if (summaryItems) summaryItems.innerHTML = items.length
      ? items.map((item) => `<div><span>✓</span><span>${escapeHtml(item)}</span></div>`).join('')
      : '<div><span>•</span><span>Faça suas escolhas para montar o plano</span></div>';
    if (monthlyTotal) monthlyTotal.innerHTML = `${money(monthly)}<small>/mês</small>`;
    if (setupTotal) setupTotal.textContent = money(setup);

    const messageParts = [`Olá! Montei uma solução ${productBrand}:`, ...items.map(i => `- ${i}`), `Mensalidade estimada: ${money(monthly)}`, `Implantação e serviços iniciais: ${money(setup)}`];
    if (sellerBrand && sellerBrand !== productBrand) messageParts.push(`Revenda responsável: ${sellerBrand}`);
    messageParts.push('Gostaria de falar com um consultor.');
    const message = messageParts.join('\n');
    if (waQuote && window.NEXA_BUILDER?.wa) {
      waQuote.href = `https://wa.me/${window.NEXA_BUILDER.wa}?text=${encodeURIComponent(message)}`;
    }
    if (submitButton) submitButton.disabled = !line || (line === 'food' && !selectedDelivery());
    previousLine = line;
    updateMobileUI();
  }

  function mobileSteps() {
    return currentLine() === 'food' ? [1, 2, 3, 4] : [1, 3, 4];
  }
  function stepValid(step) {
    if (step === 1) return !!currentLine();
    if (step === 2) return currentLine() !== 'food' || !!selectedDelivery();
    if (step === 3) return !!currentLine();
    return true;
  }
  function updateMobileUI() {
    if (!mobileQuery.matches) {
      document.body.classList.remove('mobile-wizard-enabled');
      root.removeAttribute('data-active-wizard-step');
      if (mobileProgress) mobileProgress.hidden = true;
      if (mobileAction) mobileAction.hidden = true;
      return;
    }
    if (mobileProgress) mobileProgress.hidden = false;
    if (mobileAction) mobileAction.hidden = false;
    document.body.classList.add('mobile-wizard-enabled');
    const steps = mobileSteps();
    if (!steps.includes(mobileStep)) mobileStep = steps[0];
    root.dataset.activeWizardStep = String(mobileStep);
    const position = steps.indexOf(mobileStep) + 1;
    const labels = {1: 'Como quer começar', 2: 'Volume do Delivery', 3: 'Módulos adicionais', 4: 'Resumo do plano'};
    if (mobileStepLabel) mobileStepLabel.textContent = mobileStep === 4 ? 'Resumo final' : `Passo ${Math.min(position, 3)} de 3 · ${labels[mobileStep]}`;
    if (mobileProgressBar) mobileProgressBar.style.width = `${(position / steps.length) * 100}%`;
    if (mobileBack) mobileBack.hidden = position === 1;
    if (mobileValueLabel) mobileValueLabel.textContent = mobileStep === 4 ? 'Mensalidade final' : 'Valor atual';
    if (mobileCurrentValue) mobileCurrentValue.textContent = `${money(totals.monthly)}/mês`;
    if (mobileNext) {
      mobileNext.disabled = !stepValid(mobileStep);
      mobileNext.textContent = mobileStep === 4 ? 'Continuar para cadastro →' : mobileStep === 3 ? 'Ver resumo →' : 'Avançar →';
    }
  }
  function moveMobile(direction) {
    const steps = mobileSteps();
    const index = steps.indexOf(mobileStep);
    if (direction > 0 && !stepValid(mobileStep)) return;
    if (direction > 0 && mobileStep === 4) {
      root.requestSubmit();
      return;
    }
    const nextIndex = Math.max(0, Math.min(steps.length - 1, index + direction));
    mobileStep = steps[nextIndex];
    updateMobileUI();
    window.scrollTo({top: 0, behavior: 'smooth'});
  }

  root.addEventListener('change', (event) => {
    if (event.target.matches('input[data-quantity-for]')) {
      const card = event.target.closest('.addon-card');
      const checkbox = card?.querySelector('.addon-select');
      clampQuantity(event.target);
      if (checkbox) checkbox.checked = true;
    }
    calculate();
  });

  root.addEventListener('input', (event) => {
    if (!event.target.matches('input[data-quantity-for]')) return;
    const card = event.target.closest('.addon-card');
    const checkbox = card?.querySelector('.addon-select');
    if (checkbox) checkbox.checked = true;
    calculate();
  });

  root.addEventListener('click', (event) => {
    const quantityButton = event.target.closest('.quantity-minus, .quantity-plus');
    if (quantityButton) {
      event.preventDefault();
      const card = quantityButton.closest('.addon-card');
      const quantityInput = card?.querySelector('input[data-quantity-for]');
      const checkbox = card?.querySelector('.addon-select');
      if (quantityInput) {
        const current = clampQuantity(quantityInput);
        quantityInput.value = String(current + (quantityButton.classList.contains('quantity-plus') ? 1 : -1));
        clampQuantity(quantityInput);
      }
      if (checkbox) checkbox.checked = true;
      calculate();
      return;
    }
    if (event.target.closest('.delivery-card, .entry-card, .addon-body')) {
      window.requestAnimationFrame(calculate);
    }
  });

  lineInputs.forEach((input) => input.addEventListener('change', calculate));
  deliveryInputs.forEach((input) => input.addEventListener('change', calculate));
  quantityInputs.forEach(clampQuantity);
  mobileNext?.addEventListener('click', () => moveMobile(1));
  mobileBack?.addEventListener('click', () => moveMobile(-1));
  mobileQuery.addEventListener?.('change', updateMobileUI);
  calculate();
})();

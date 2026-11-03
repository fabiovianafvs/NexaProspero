(() => {
  'use strict';

  const form = document.getElementById('company-signup-form');
  if (!form) return;

  const onlyDigits = (value) => String(value || '').replace(/\D/g, '');
  const cnpj = document.getElementById('cnpj');
  const cpf = document.getElementById('cpf');
  const phone = document.getElementById('telefone');
  const searchButton = document.getElementById('consultar-cnpj');
  const cnpjStatus = document.getElementById('cnpj-status');
  const cpfStatus = document.getElementById('cpf-status');
  const fantasy = document.getElementById('nome_fantasia');
  const subdomainPreview = document.getElementById('subdomain-preview');
  const subdomainStatus = document.getElementById('subdomain-status');
  const rootDomain = form.dataset.rootDomain || 'nexaprospero.com.br';
  const subdomainEndpoint = form.dataset.subdomainEndpoint || '';
  const cnpjEndpoint = form.dataset.cnpjEndpoint || '/api/cnpj.php';

  const fields = {
    razaoSocial: document.getElementById('razao_social'),
    nomeFantasia: fantasy,
    cidade: document.getElementById('cidade'),
    uf: document.getElementById('uf'),
    segmento: document.getElementById('segmento'),
    cep: document.getElementById('cep'),
    endereco: document.getElementById('endereco'),
    numero: document.getElementById('numero'),
    complemento: document.getElementById('complemento'),
    bairro: document.getElementById('bairro'),
  };

  let lastConsulted = '';
  let lookupTimer = null;
  let cnpjController = null;
  let subdomainTimer = null;
  let subdomainController = null;
  let subdomainAvailable = null;

  const selectedPersonType = () => {
    const selected = form.querySelector('input[name="person_type"]:checked');
    return selected ? selected.value : 'pj';
  };

  const formatCnpj = (value) => {
    let v = onlyDigits(value).slice(0, 14);
    return v.replace(/^(\d{2})(\d)/, '$1.$2')
      .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
      .replace(/\.(\d{3})(\d)/, '.$1/$2')
      .replace(/(\d{4})(\d)/, '$1-$2');
  };

  const formatCpf = (value) => {
    let v = onlyDigits(value).slice(0, 11);
    return v.replace(/^(\d{3})(\d)/, '$1.$2')
      .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
      .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
  };

  const validCpf = (value) => {
    const digits = onlyDigits(value);
    if (digits.length !== 11 || /^(\d)\1{10}$/.test(digits)) return false;
    for (let position = 9; position <= 10; position += 1) {
      let sum = 0;
      for (let i = 0; i < position; i += 1) sum += Number(digits[i]) * ((position + 1) - i);
      const digit = ((sum * 10) % 11) % 10;
      if (digit !== Number(digits[position])) return false;
    }
    return true;
  };

  const validCnpj = (value) => {
    const digits = onlyDigits(value);
    if (digits.length !== 14 || /^(\d)\1{13}$/.test(digits)) return false;
    const calculate = (base, weights) => {
      const sum = weights.reduce((total, weight, index) => total + Number(base[index]) * weight, 0);
      const remainder = sum % 11;
      return remainder < 2 ? 0 : 11 - remainder;
    };
    const first = calculate(digits.slice(0, 12), [5,4,3,2,9,8,7,6,5,4,3,2]);
    const second = calculate(digits.slice(0, 12) + first, [6,5,4,3,2,9,8,7,6,5,4,3,2]);
    return first === Number(digits[12]) && second === Number(digits[13]);
  };

  const normalizeSubdomain = (value) => String(value || '')
    .trim()
    .toLocaleLowerCase('pt-BR')
    .replace(/[&+]/g, ' e ')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/ç/g, 'c')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 63)
    .replace(/-$/g, '');

  const setCnpjStatus = (message, type = '') => {
    if (!cnpjStatus) return;
    cnpjStatus.textContent = message;
    cnpjStatus.className = `cnpj-status${type ? ` is-${type}` : ''}`;
  };

  const setCpfStatus = (message, type = '') => {
    if (!cpfStatus) return;
    cpfStatus.textContent = message;
    cpfStatus.className = `cnpj-status${type ? ` is-${type}` : ''}`;
  };

  const setSubdomainStatus = (message, type = '') => {
    if (!subdomainStatus) return;
    subdomainStatus.textContent = message;
    subdomainStatus.className = `subdomain-status${type ? ` is-${type}` : ''}`;
  };

  const setLoading = (loading) => {
    if (searchButton) {
      searchButton.disabled = loading;
      searchButton.textContent = loading ? 'Consultando…' : 'Consultar';
    }
    if (cnpj) cnpj.setAttribute('aria-busy', loading ? 'true' : 'false');
  };

  const refreshPersonType = () => {
    const type = selectedPersonType();
    form.querySelectorAll('[data-person-section]').forEach((section) => {
      const active = section.dataset.personSection === type;
      section.classList.toggle('hidden-by-type', !active);
      section.querySelectorAll('input,select,textarea,button').forEach((control) => {
        control.disabled = !active;
      });
    });

    if (cnpj) cnpj.required = type === 'pj';
    if (fields.razaoSocial) fields.razaoSocial.required = type === 'pj';
    if (cpf) cpf.required = type === 'pf';

    if (type === 'pj') {
      setCpfStatus('O CPF será usado somente para identificar o responsável pelo cadastro.');
      if (cnpj) cnpj.focus();
    } else {
      setCnpjStatus('Digite o CNPJ para preencher automaticamente os dados da empresa.');
      if (cpf) cpf.focus();
    }
  };

  const suggestSegment = (activity) => {
    if (!fields.segmento || fields.segmento.value || !activity) return;
    const text = activity.toLocaleLowerCase('pt-BR');
    const mappings = [
      [['restaurante'], 'Restaurante'],
      [['lanchonete', 'casa de chá', 'sucos'], 'Lanchonete'],
      [['pizzaria'], 'Pizzaria'],
      [['hamburguer'], 'Hamburgueria'],
      [['padaria', 'panificação', 'confeitaria'], 'Padaria'],
      [['vestuário', 'calçados', 'roupas'], 'Loja de roupas ou calçados'],
      [['material de construção'], 'Material de construção'],
      [['peças e acessórios', 'autopeças'], 'Autopeças'],
      [['comércio varejista'], 'Comércio varejista'],
    ];
    const found = mappings.find(([keywords]) => keywords.some((keyword) => text.includes(keyword)));
    if (found) fields.segmento.value = found[1];
  };

  const fillCompany = (company) => {
    if (fields.razaoSocial && company.razao_social) fields.razaoSocial.value = company.razao_social;
    if (fields.nomeFantasia) {
      fields.nomeFantasia.value = company.nome_fantasia || company.razao_social || '';
      fields.nomeFantasia.dispatchEvent(new Event('input', { bubbles: true }));
    }
    if (fields.cidade && company.cidade) fields.cidade.value = company.cidade;
    if (fields.uf && company.uf) fields.uf.value = company.uf;
    if (fields.cep && company.cep) fields.cep.value = String(company.cep).replace(/\D/g, '').replace(/^(\d{5})(\d{3})$/, '$1-$2');
    if (fields.endereco && company.endereco) fields.endereco.value = company.endereco;
    if (fields.numero && company.numero) fields.numero.value = company.numero;
    if (fields.complemento) fields.complemento.value = company.complemento || fields.complemento.value || 'Sem complemento';
    if (fields.bairro && company.bairro) fields.bairro.value = company.bairro;
    suggestSegment(company.atividade || '');

    const situation = company.situacao ? ` Situação cadastral: ${company.situacao}.` : '';
    setCnpjStatus(`Dados encontrados e preenchidos automaticamente.${situation}`, 'success');
  };

  const consultCnpj = async () => {
    if (!cnpj || selectedPersonType() !== 'pj') return;
    const digits = onlyDigits(cnpj.value);
    if (digits.length !== 14 || !validCnpj(digits)) {
      setCnpjStatus('Informe um CNPJ válido com 14 números.', 'warning');
      return;
    }
    if (digits === lastConsulted) return;

    if (cnpjController) cnpjController.abort();
    cnpjController = new AbortController();
    setLoading(true);
    setCnpjStatus('Consultando os dados cadastrais…', 'loading');

    try {
      const response = await fetch(`${cnpjEndpoint}?cnpj=${encodeURIComponent(digits)}`, {
        headers: { Accept: 'application/json' },
        signal: cnpjController.signal,
        cache: 'no-store',
      });
      const data = await response.json();
      if (!response.ok || !data || !data.ok) throw new Error((data && data.message) || 'Não foi possível consultar o CNPJ.');
      const normalized = data.data || data.company || {};
      const company = {
        razao_social: normalized.razao_social || '',
        nome_fantasia: normalized.nome_fantasia || '',
        cidade: normalized.cidade || normalized.municipio || '',
        uf: normalized.estado || normalized.uf || '',
        atividade: normalized.atividade || normalized.cnae_fiscal_descricao || '',
        situacao: normalized.situacao_cadastral || normalized.situacao || '',
        cep: normalized.cep || '',
        endereco: normalized.endereco || normalized.logradouro || '',
        numero: normalized.numero || '',
        complemento: normalized.complemento || '',
        bairro: normalized.bairro || '',
      };
      lastConsulted = digits;
      fillCompany(company);
    } catch (error) {
      if (error.name !== 'AbortError') setCnpjStatus(error.message || 'Consulta indisponível. Preencha os dados manualmente.', 'error');
    } finally {
      setLoading(false);
    }
  };

  const updateSubdomainPreview = () => {
    const subdomain = normalizeSubdomain(fantasy ? fantasy.value : '');
    if (subdomainPreview) subdomainPreview.textContent = `${subdomain || 'seu-nome'}.${rootDomain}`;
    return subdomain;
  };

  const checkSubdomain = async () => {
    const name = fantasy ? fantasy.value.trim() : '';
    const generated = updateSubdomainPreview();
    subdomainAvailable = null;

    if (!name) {
      setSubdomainStatus('Digite o nome fantasia para validar a disponibilidade.');
      return;
    }
    if (generated.length < 3) {
      subdomainAvailable = false;
      setSubdomainStatus('O nome precisa gerar um endereço com pelo menos 3 caracteres.', 'error');
      return;
    }
    if (!subdomainEndpoint) {
      setSubdomainStatus('O endereço será validado ao concluir o cadastro.', 'warning');
      return;
    }

    if (subdomainController) subdomainController.abort();
    subdomainController = new AbortController();
    setSubdomainStatus('Validando disponibilidade do endereço…', 'loading');

    try {
      const response = await fetch(`${subdomainEndpoint}?name=${encodeURIComponent(name)}`, {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
        signal: subdomainController.signal,
      });
      const payload = await response.json();
      if (payload.host && subdomainPreview) subdomainPreview.textContent = payload.host;
      subdomainAvailable = Boolean(payload.available);
      setSubdomainStatus(payload.message || (subdomainAvailable ? 'Endereço disponível.' : 'Endereço indisponível.'), subdomainAvailable ? 'success' : 'error');
    } catch (error) {
      if (error.name !== 'AbortError') {
        subdomainAvailable = null;
        setSubdomainStatus('Não foi possível validar agora. O sistema verificará novamente ao concluir.', 'warning');
      }
    }
  };

  form.querySelectorAll('input[name="person_type"]').forEach((input) => input.addEventListener('change', refreshPersonType));

  if (cnpj) {
    cnpj.addEventListener('input', () => {
      cnpj.value = formatCnpj(cnpj.value);
      const digits = onlyDigits(cnpj.value);
      if (digits !== lastConsulted) lastConsulted = '';
      clearTimeout(lookupTimer);
      if (digits.length === 14 && validCnpj(digits)) lookupTimer = window.setTimeout(consultCnpj, 450);
      else setCnpjStatus(digits.length === 14 ? 'O CNPJ informado não é válido.' : 'Digite o CNPJ para preencher automaticamente os dados da empresa.', digits.length === 14 ? 'error' : '');
    });
    cnpj.addEventListener('blur', () => {
      if (onlyDigits(cnpj.value).length === 14) consultCnpj();
    });
  }

  if (cpf) {
    cpf.addEventListener('input', () => {
      cpf.value = formatCpf(cpf.value);
      const digits = onlyDigits(cpf.value);
      if (digits.length === 11) setCpfStatus(validCpf(digits) ? 'CPF válido.' : 'O CPF informado não é válido.', validCpf(digits) ? 'success' : 'error');
      else setCpfStatus('Digite os 11 números do CPF.');
    });
  }

  if (searchButton) searchButton.addEventListener('click', consultCnpj);

  if (fantasy) {
    fantasy.addEventListener('input', () => {
      updateSubdomainPreview();
      subdomainAvailable = null;
      clearTimeout(subdomainTimer);
      subdomainTimer = window.setTimeout(checkSubdomain, 450);
    });
    fantasy.addEventListener('blur', checkSubdomain);
  }

  const cep = document.getElementById('cep');
  if (cep) {
    cep.addEventListener('input', () => {
      let v = onlyDigits(cep.value).slice(0, 8);
      if (v.length > 5) v = v.replace(/^(\d{5})(\d+)/, '$1-$2');
      cep.value = v;
    });
  }

  if (phone) {
    phone.addEventListener('input', () => {
      let v = onlyDigits(phone.value).slice(0, 11);
      v = v.replace(/^(\d{2})(\d)/, '($1) $2');
      v = v.length > 13 ? v.replace(/(\d{5})(\d{4})$/, '$1-$2') : v.replace(/(\d{4})(\d{4})$/, '$1-$2');
      phone.value = v;
    });
  }

  // Somente um POST real por tentativa: a tela acompanha a solicitação sem
  // afirmar que etapas específicas do servidor já foram concluídas.
  const signupProgress = document.getElementById('nexa-signup-progress');
  const signupProgressMessage = document.getElementById('nexa-signup-progress-message');
  const signupSubmitButtons = Array.from(form.querySelectorAll('button[type="submit"],input[type="submit"]'));
  let signupSubmitting = false;
  let signupProgressTimer = null;
  const signupFill = document.getElementById('nexa-signup-progress-fill');
  const signupElapsed = document.getElementById('nexa-signup-elapsed');
  const signupSteps = Array.from(document.querySelectorAll('#nexa-signup-steps li'));
  let signupStartedAt = 0;
  const signupMessages = [
    [0, 'Recebemos o seu cadastro. Estamos montando tudo para você.'],
    [12, 'Tudo certo até aqui. Continue nesta página.'],
    [30, 'Quase lá! Estamos finalizando o seu ambiente.'],
    [60, 'Está levando um pouco mais que o normal, mas o cadastro continua em andamento. Não é preciso reenviar.'],
    [150, 'Ainda processando. Se passar de 3 minutos, fale com o suporte pelo WhatsApp antes de tentar de novo.']
  ];
  const formatElapsed = (seconds) => seconds < 60 ? seconds + 's' : Math.floor(seconds / 60) + 'min ' + String(seconds % 60).padStart(2, '0') + 's';
  const renderSignupProgress = () => {
    const t = Math.floor((Date.now() - signupStartedAt) / 1000);
    if (signupElapsed) signupElapsed.textContent = formatElapsed(t);
    // A barra se aproxima de 95% sem chegar ao fim: só a resposta do servidor conclui.
    if (signupFill) signupFill.style.width = (3 + 92 * (1 - Math.exp(-t / 20))).toFixed(1) + '%';
    let current = 0;
    signupSteps.forEach((step, index) => { if (t >= Number(step.dataset.at || 0)) current = index; });
    signupSteps.forEach((step, index) => {
      step.classList.toggle('is-done', index < current);
      step.classList.toggle('is-active', index === current);
    });
    let text = signupMessages[0][1];
    signupMessages.forEach(([at, message]) => { if (t >= at) text = message; });
    if (signupProgressMessage && signupProgressMessage.textContent !== text) {
      signupProgressMessage.textContent = text;
      signupProgressMessage.classList.toggle('is-slow', t >= 60);
    }
  };
  const clearSignupProgress = () => {
    signupSubmitting = false;
    if (signupProgressTimer !== null) window.clearInterval(signupProgressTimer);
    signupProgressTimer = null;
    signupSubmitButtons.forEach((button) => { button.disabled = false; });
    if (signupProgress) { signupProgress.classList.remove('is-active'); signupProgress.hidden = true; }
  };
  window.addEventListener('pageshow', clearSignupProgress);

  form.addEventListener('submit', (event) => {
    const type = selectedPersonType();
    if (type === 'pf' && (!cpf || !validCpf(cpf.value))) {
      event.preventDefault();
      setCpfStatus('Informe um CPF válido antes de continuar.', 'error');
      if (cpf) cpf.focus();
      return;
    }
    if (type === 'pj' && (!cnpj || !validCnpj(cnpj.value))) {
      event.preventDefault();
      setCnpjStatus('Informe um CNPJ válido antes de continuar.', 'error');
      if (cnpj) cnpj.focus();
      return;
    }
    if (subdomainAvailable === false) {
      event.preventDefault();
      setSubdomainStatus('Este endereço já está em uso. Altere o nome fantasia antes de continuar.', 'error');
      if (fantasy) fantasy.focus();
      return;
    }
    if (event.defaultPrevented || !form.checkValidity()) return;
    if (signupSubmitting) { event.preventDefault(); return; }
    signupSubmitting = true;
    signupSubmitButtons.forEach((button) => { button.disabled = true; });
    if (signupProgress) { signupProgress.hidden = false; signupProgress.classList.add('is-active'); }
    signupStartedAt = Date.now();
    renderSignupProgress();
    signupProgressTimer = window.setInterval(renderSignupProgress, 1000);
  });

  refreshPersonType();
  updateSubdomainPreview();
})();

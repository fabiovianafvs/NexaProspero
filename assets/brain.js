(() => {
  const form = document.getElementById('brainForm');
  const input = document.getElementById('brainQuestion');
  const messages = document.getElementById('brainMessages');
  const status = document.getElementById('brainRealtimeStatus');
  const realtime = window.NEXA_BRAIN_REALTIME || {};

  const setRealtimeStatus = (state, text) => {
    if (!status) return;
    status.dataset.state = state;
    status.textContent = text;
  };

  if (form && input && messages) {
    const submit = form.querySelector('button[type="submit"]');
    const cache = new Map();
    const CACHE_TTL_MS = 60_000;
    let busy = false;
    window.NEXA_BRAIN_BUSY = false;

    const add = (text, who) => {
      const el = document.createElement('div');
      el.className = `brain-message brain-${who}`;
      el.textContent = text;
      messages.appendChild(el);
      messages.scrollTop = messages.scrollHeight;
      return el;
    };

    const normalize = (value) => (value || '').trim().replace(/\s+/g, ' ').toLocaleLowerCase('pt-BR');

    const setBusy = (value) => {
      busy = value;
      window.NEXA_BRAIN_BUSY = value;
      input.disabled = value;
      if (submit) submit.disabled = value;
      document.querySelectorAll('[data-q]').forEach((button) => { button.disabled = value; });
    };

    async function ask(question) {
      const q = (question || '').trim();
      if (!q || busy) return;

      add(q, 'user');
      input.value = '';
      const loading = add('Analisando os indicadores em cache...', 'bot');
      const key = normalize(q);
      const cached = cache.get(key);
      if (cached && Date.now() - cached.createdAt < CACHE_TTL_MS) {
        loading.textContent = cached.answer;
        return;
      }

      setBusy(true);
      const controller = new AbortController();
      const timeout = window.setTimeout(() => controller.abort(), 20_000);

      try {
        const response = await fetch('brain_api.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ question: q }),
          signal: controller.signal,
        });

        let data = null;
        try { data = await response.json(); } catch (_) {}
        if (!response.ok) throw new Error(data?.message || `Falha HTTP ${response.status}`);

        const answer = data?.ok
          ? String(data.answer || 'Não encontrei uma resposta para esta pergunta.')
          : String(data?.message || 'Não foi possível analisar agora.');
        loading.textContent = answer;
        if (data?.ok) cache.set(key, { answer, createdAt: Date.now() });
      } catch (error) {
        loading.textContent = error?.name === 'AbortError'
          ? 'A análise demorou além do esperado. Tente novamente.'
          : 'Falha de comunicação. Tente novamente.';
      } finally {
        window.clearTimeout(timeout);
        setBusy(false);
        input.focus();
      }
    }

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      ask(input.value);
    });

    document.querySelectorAll('[data-q]').forEach((button) => {
      button.addEventListener('click', () => ask(button.dataset.q));
    });
  }

  if (!realtime.enabled || !realtime.url || !realtime.token) {
    setRealtimeStatus('disabled', 'Tempo real: não configurado');
    return;
  }

  let socket = null;
  let reconnectTimer = null;
  let reconnectAttempt = 0;
  let reloadTimer = null;
  let closedByPage = false;
  let updatePending = false;
  let lastInteractionAt = Date.now();
  let lastMessageSignature = '';
  let lastMessageAt = 0;

  const PAGE_STARTED_AT = Date.now();
  const STARTUP_GRACE_MS = 8_000;
  const AUTO_RELOAD_DELAY_MS = 12_000;
  const AUTO_RELOAD_MIN_INTERVAL_MS = 60_000;
  const USER_IDLE_MS = 12_000;
  const LAST_RELOAD_KEY = 'nexaBrainLastAutoReloadAt';

  const mobileQuery = window.matchMedia
    ? window.matchMedia('(max-width: 900px), (pointer: coarse)')
    : { matches: false };

  const isMobileExperience = () => Boolean(
    mobileQuery.matches
    || Number(navigator.maxTouchPoints || 0) > 0
    || window.innerWidth <= 900
  );

  const markInteraction = () => { lastInteractionAt = Date.now(); };
  ['pointerdown', 'touchstart', 'keydown', 'scroll'].forEach((eventName) => {
    window.addEventListener(eventName, markInteraction, { passive: true, capture: true });
  });

  const buildUrl = () => {
    const separator = String(realtime.url).includes('?') ? '&' : '?';
    return `${realtime.url}${separator}token=${encodeURIComponent(realtime.token)}`;
  };

  const getLastAutoReloadAt = () => {
    try { return Number(window.sessionStorage.getItem(LAST_RELOAD_KEY) || 0); } catch (_) { return 0; }
  };

  const saveLastAutoReloadAt = () => {
    try { window.sessionStorage.setItem(LAST_RELOAD_KEY, String(Date.now())); } catch (_) {}
  };

  const hasActiveEditing = () => {
    const active = document.activeElement;
    if (!active) return false;
    const tag = String(active.tagName || '').toLowerCase();
    return ['input', 'textarea', 'select'].includes(tag)
      || Boolean(active.isContentEditable)
      || Boolean(window.NEXA_BRAIN_BUSY);
  };

  const refreshNow = () => {
    if (closedByPage) return;
    saveLastAutoReloadAt();
    window.location.reload();
  };

  const showUpdateAvailable = () => {
    updatePending = true;
    if (!status) return;
    status.setAttribute('role', 'button');
    status.setAttribute('tabindex', '0');
    status.setAttribute('aria-live', 'polite');
    status.title = 'Clique ou toque para atualizar os indicadores';
    setRealtimeStatus(
      'updated',
      isMobileExperience()
        ? 'Novos dados disponíveis — toque para atualizar'
        : 'Novos dados disponíveis — clique para atualizar'
    );
  };

  const canAutoReload = () => {
    if (isMobileExperience()) return false;
    if (document.hidden || hasActiveEditing()) return false;
    if (Date.now() - lastInteractionAt < USER_IDLE_MS) return false;
    return true;
  };

  const scheduleReload = () => {
    showUpdateAvailable();

    // No celular e em dispositivos de toque nunca recarregamos a página
    // automaticamente. Isso preserva navegação, rolagem e preenchimento.
    if (isMobileExperience() || reloadTimer) return;

    const elapsedSinceLastReload = Date.now() - getLastAutoReloadAt();
    const waitForThrottle = Math.max(0, AUTO_RELOAD_MIN_INTERVAL_MS - elapsedSinceLastReload);
    const delay = Math.max(AUTO_RELOAD_DELAY_MS, waitForThrottle);

    reloadTimer = window.setTimeout(function tryReload() {
      reloadTimer = null;
      if (!updatePending || closedByPage) return;
      if (!canAutoReload()) {
        reloadTimer = window.setTimeout(tryReload, 5_000);
        return;
      }
      refreshNow();
    }, delay);
  };

  const messageSignature = (message) => [
    message.eventType || '',
    message.entityType || '',
    Number(message.entityId || 0),
    message.cacheGeneratedAt || '',
    message.occurredAt || '',
  ].join('|');

  if (status) {
    status.addEventListener('click', () => {
      if (updatePending) refreshNow();
    });
    status.addEventListener('keydown', (event) => {
      if (!updatePending || !['Enter', ' '].includes(event.key)) return;
      event.preventDefault();
      refreshNow();
    });
  }

  const connect = () => {
    if (closedByPage) return;
    window.clearTimeout(reconnectTimer);
    setRealtimeStatus('connecting', 'Tempo real: conectando');
    try {
      socket = new WebSocket(buildUrl());
    } catch (_) {
      setRealtimeStatus('error', 'Tempo real: configuração inválida');
      return;
    }

    socket.addEventListener('open', () => {
      reconnectAttempt = 0;
      if (!updatePending) setRealtimeStatus('connected', 'Tempo real: conectado');
    });

    socket.addEventListener('message', (event) => {
      let message = null;
      try { message = JSON.parse(event.data); } catch (_) { return; }
      if (!message || Number(message.companyId || 0) !== Number(realtime.companyId || 0)) return;
      if (message.type !== 'brain.updated' || message.dashboardChanged === false) return;

      // Evita processar mensagens idênticas retransmitidas em sequência.
      const signature = messageSignature(message);
      const now = Date.now();
      if (signature === lastMessageSignature && now - lastMessageAt < 5_000) return;
      lastMessageSignature = signature;
      lastMessageAt = now;

      // Impede que um evento recebido logo na conexão gere ciclo de reload.
      if (now - PAGE_STARTED_AT < STARTUP_GRACE_MS) {
        showUpdateAvailable();
        return;
      }
      scheduleReload();
    });

    socket.addEventListener('close', () => {
      if (closedByPage) return;
      setRealtimeStatus('disconnected', 'Tempo real: reconectando');
      reconnectAttempt += 1;
      const delay = Math.min(30_000, 1_000 * (2 ** Math.min(reconnectAttempt, 5)));
      reconnectTimer = window.setTimeout(connect, delay);
    });

    socket.addEventListener('error', () => {
      setRealtimeStatus('error', 'Tempo real: indisponível');
      try { socket.close(); } catch (_) {}
    });
  };

  window.addEventListener('beforeunload', () => {
    closedByPage = true;
    window.clearTimeout(reconnectTimer);
    window.clearTimeout(reloadTimer);
    try { socket?.close(); } catch (_) {}
  });

  connect();
})();

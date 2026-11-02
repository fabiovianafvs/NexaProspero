(() => {
  'use strict';

  const config = window.NEXA_REALTIME_CONFIG || {};
  const features = config.features || {};
  if (!config.enabled || !config.url || !config.token || !Number(config.companyId || 0)) return;

  const companyId = Number(config.companyId || 0);
  let socket = null;
  let reconnectTimer = 0;
  let reconnectAttempt = 0;
  let closedByPage = false;
  let audioCtx = null;
  let audioReady = false;
  const soundPreferenceKey = `nexa:rt:sound-enabled:${companyId}`;
  let soundPreferenceEnabled = false;
  try { soundPreferenceEnabled = localStorage.getItem(soundPreferenceKey) === '1'; } catch (_) {}
  const originalTitle = document.title;
  let titleTimer = 0;
  let statusHideTimer = 0;

  const money = (value) => {
    const number = Number(value || 0);
    try { return number.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
    catch (_) { return `R$ ${number.toFixed(2).replace('.', ',')}`; }
  };

  const buildUrl = () => {
    const separator = String(config.url).includes('?') ? '&' : '?';
    return `${config.url}${separator}token=${encodeURIComponent(config.token)}`;
  };

  const injectStyles = () => {
    if (document.getElementById('nexaRealtimeStyles')) return;
    const style = document.createElement('style');
    style.id = 'nexaRealtimeStyles';
    style.textContent = `
      .nexa-rt-stack{position:fixed;top:82px;right:16px;z-index:400;display:flex;flex-direction:column;gap:10px;width:min(410px,calc(100vw - 32px));pointer-events:none}
      .nexa-rt-toast{pointer-events:auto;background:#fff;border:1px solid #bfdbfe;border-left:5px solid var(--admin-secondary,#2454D6);border-radius:16px;box-shadow:0 20px 55px rgba(11,31,91,.22);padding:13px 14px 14px;display:grid;gap:7px;animation:nexaRtIn .18s ease-out;color:#0f172a}
      .nexa-rt-toast-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
      .nexa-rt-toast strong{color:#0b1f5b;font-size:15px;line-height:1.3}.nexa-rt-toast small{color:#64748b}.nexa-rt-row{display:flex;justify-content:space-between;gap:12px;align-items:center}.nexa-rt-toast a{color:var(--admin-secondary,#2454D6);font-weight:900;text-decoration:none;white-space:nowrap}
      .nexa-rt-close{border:0;background:transparent;color:#64748b;font:800 22px/1 system-ui,-apple-system,Segoe UI,sans-serif;cursor:pointer;padding:0 2px;margin:-2px -2px 0 0;border-radius:8px;min-width:26px;min-height:26px;display:grid;place-items:center}
      .nexa-rt-close:hover,.nexa-rt-close:focus-visible{background:#f1f5f9;color:#0f172a;outline:none}
      .nexa-rt-sound-hint{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 10px;border:1px solid #fde68a;background:#fffbeb;border-radius:10px;color:#92400e;font-size:12px;font-weight:700}
      .nexa-rt-sound-hint button{border:1px solid #f59e0b;background:#fff;color:#92400e;border-radius:9px;padding:6px 9px;font-weight:900;cursor:pointer;white-space:nowrap}
      .nexa-rt-enable{position:fixed;right:16px;bottom:16px;z-index:395;border:0;border-radius:999px;background:linear-gradient(135deg,var(--admin-primary,#0B1F5B),var(--admin-secondary,#2454D6));color:#fff;font-weight:900;padding:11px 15px;box-shadow:0 14px 35px rgba(11,31,91,.24);cursor:pointer}
      .nexa-rt-enable.ok{background:#166534}
      .nexa-rt-state{position:fixed;left:12px;bottom:12px;z-index:394;border-radius:999px;padding:7px 10px;font:700 11px/1.2 system-ui,-apple-system,Segoe UI,sans-serif;box-shadow:0 8px 24px rgba(15,23,42,.12);background:#fff;border:1px solid #cbd5e1;color:#475569;transition:opacity .2s ease}
      .nexa-rt-state.ok{border-color:#bbf7d0;background:#f0fdf4;color:#166534}.nexa-rt-state.bad{border-color:#fecaca;background:#fff1f2;color:#b91c1c}
      @keyframes nexaRtIn{from{opacity:0;transform:translateY(-8px) scale(.98)}to{opacity:1;transform:none}}
      @media(max-width:640px){.nexa-rt-stack{top:72px;right:8px;width:calc(100vw - 16px)}.nexa-rt-enable{right:10px;bottom:10px;max-width:calc(100vw - 20px)}.nexa-rt-row{align-items:flex-start}.nexa-rt-sound-hint{align-items:flex-start}}
    `;
    document.head.appendChild(style);
  };

  const needsNotificationPermission = () => ('Notification' in window) && Notification.permission === 'default';

  const setSoundPreference = (enabled) => {
    soundPreferenceEnabled = !!enabled;
    try {
      if (soundPreferenceEnabled) localStorage.setItem(soundPreferenceKey, '1');
      else localStorage.removeItem(soundPreferenceKey);
    } catch (_) {}
  };

  const updatePermissionButton = () => {
    if (!features.deliveryNewOrder) return;
    let btn = document.getElementById('nexaRealtimeEnable');
    // O usuário só precisa autorizar o som uma única vez neste navegador.
    // O AudioContext em si não sobrevive à troca de página, por isso o estado
    // de autorização é persistido no localStorage e reativado silenciosamente
    // no primeiro clique/tecla da nova tela.
    const needsAudio = !soundPreferenceEnabled;
    const needsNotification = needsNotificationPermission();

    if (!needsAudio && !needsNotification) {
      if (btn) btn.remove();
      return;
    }

    if (!btn) {
      btn = document.createElement('button');
      btn.type = 'button';
      btn.id = 'nexaRealtimeEnable';
      btn.className = 'nexa-rt-enable';
      btn.addEventListener('click', async () => {
        const soundOk = await unlockAudio();
        if (soundOk) {
          setSoundPreference(true);
          playBeep(); // bip de teste: confirma que o navegador liberou o áudio.
        }
        if (needsNotificationPermission()) {
          try { await Notification.requestPermission(); } catch (_) {}
        }
        updatePermissionButton();
      });
      document.body.appendChild(btn);
    }

    if (needsAudio && needsNotification) btn.textContent = '🔊 Ativar som e notificações';
    else if (needsAudio) btn.textContent = '🔊 Ativar som';
    else btn.textContent = '🔔 Ativar notificações';
  };

  const unlockAudio = async () => {
    try {
      const AudioContextClass = window.AudioContext || window.webkitAudioContext;
      if (!AudioContextClass) return false;
      if (!audioCtx || audioCtx.state === 'closed') audioCtx = new AudioContextClass();
      if (audioCtx.state === 'suspended') await audioCtx.resume();

      // Em alguns navegadores o contexto só fica efetivamente liberado após gerar
      // um pequeno nó de áudio dentro do gesto do usuário.
      if (audioCtx.state === 'running') {
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        gain.gain.value = 0.00001;
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.01);
      }

      audioReady = audioCtx.state === 'running';
      updatePermissionButton();
      return audioReady;
    } catch (_) {
      audioReady = false;
      updatePermissionButton();
      return false;
    }
  };

  const playBeep = () => {
    if (!audioCtx || !audioReady || audioCtx.state !== 'running') return false;
    try {
      const now = audioCtx.currentTime;
      // Dois bips curtos, um pouco mais fortes e em frequências distintas para
      // serem perceptíveis mesmo em caixas de som de notebook.
      [[0, 880], [0.24, 1175]].forEach(([offset, frequency]) => {
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(frequency, now + offset);
        gain.gain.setValueAtTime(0.0001, now + offset);
        gain.gain.exponentialRampToValueAtTime(0.32, now + offset + 0.018);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + offset + 0.18);
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.start(now + offset);
        osc.stop(now + offset + 0.20);
      });
      return true;
    } catch (_) {
      return false;
    }
  };

  const stack = () => {
    let el = document.getElementById('nexaRealtimeStack');
    if (!el) {
      el = document.createElement('div');
      el.id = 'nexaRealtimeStack';
      el.className = 'nexa-rt-stack';
      document.body.appendChild(el);
    }
    return el;
  };

  const orderNumber = (message) => Number(message?.payload?.order_number || message?.entityId || 0);
  const orderId = (message) => Number(message?.payload?.order_id || message?.entityId || 0);
  const orderUrl = (message) => `/admin/pedidos.php?q=${encodeURIComponent(String(orderNumber(message) || orderId(message)))}`;

  const eventIdentity = (message) => {
    const id = orderId(message);
    if (id) return `order:${id}`;
    const streamId = String(message?.streamId || '').trim();
    if (streamId) return `stream:${streamId}`;
    const occurredAt = String(message?.occurredAt || '').trim();
    if (occurredAt) return `time:${occurredAt}`;
    return '';
  };

  const claimEvent = async (message) => {
    const eventType = String(message.eventType || '');
    const identity = eventIdentity(message);
    if (!identity || !eventType) return true;
    const safeIdentity = identity.replace(/[^a-zA-Z0-9:_-]/g, '_');
    const storageKey = `nexa:rt:handled:${companyId}:${eventType}:${safeIdentity}`;
    const lockName = `nexa-rt-${companyId}-${eventType}-${safeIdentity}`;

    const tryClaim = () => {
      try {
        const previous = Number(localStorage.getItem(storageKey) || 0);
        // O mesmo evento não volta a aparecer após reconnect/reload por 24h.
        if (previous && (Date.now() - previous) < 86400000) return false;
        localStorage.setItem(storageKey, String(Date.now()));
      } catch (_) {}
      return true;
    };

    if (navigator.locks?.request) {
      try {
        return await navigator.locks.request(lockName, { ifAvailable: true }, (lock) => {
          if (!lock) return false;
          return tryClaim();
        });
      } catch (_) {}
    }
    return tryClaim();
  };

  const activateSoundFromToast = async (button) => {
    const ok = await unlockAudio();
    if (ok) {
      setSoundPreference(true);
      playBeep();
      const hint = button?.closest('.nexa-rt-sound-hint');
      if (hint) hint.remove();
      updatePermissionButton();
    } else if (button) {
      button.textContent = 'Tentar novamente';
    }
  };

  const showToast = (message, soundPlayed) => {
    const payload = message.payload || {};
    const number = orderNumber(message);
    const identity = eventIdentity(message) || `${Date.now()}`;
    const domId = `nexaRtToast_${identity.replace(/[^a-zA-Z0-9_-]/g, '_')}`;

    // Proteção adicional contra o mesmo card ser inserido mais de uma vez no DOM.
    const existing = document.getElementById(domId);
    if (existing) return;

    const box = document.createElement('div');
    box.id = domId;
    box.className = 'nexa-rt-toast';
    box.setAttribute('role', 'status');

    const head = document.createElement('div');
    head.className = 'nexa-rt-toast-head';

    const title = document.createElement('strong');
    title.textContent = `🔔 Novo pedido do delivery #${number || orderId(message)}`;

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'nexa-rt-close';
    close.setAttribute('aria-label', 'Fechar notificação');
    close.title = 'Fechar';
    close.textContent = '×';
    close.addEventListener('click', () => box.remove());

    head.appendChild(title);
    head.appendChild(close);
    box.appendChild(head);

    const info = document.createElement('div');
    info.textContent = `${payload.customer || 'Cliente'} • ${money(payload.total)}`;
    box.appendChild(info);

    const row = document.createElement('div');
    row.className = 'nexa-rt-row';
    const meta = document.createElement('small');
    const delivery = String(payload.delivery_type || 'Pedido pelo catálogo').replaceAll('_', ' ');
    meta.textContent = delivery;
    const link = document.createElement('a');
    link.href = orderUrl(message);
    link.textContent = 'Abrir pedido';
    row.appendChild(meta);
    row.appendChild(link);
    box.appendChild(row);

    if (!soundPlayed && !soundPreferenceEnabled) {
      const hint = document.createElement('div');
      hint.className = 'nexa-rt-sound-hint';
      const text = document.createElement('span');
      text.textContent = 'Som bloqueado pelo navegador nesta aba.';
      const activate = document.createElement('button');
      activate.type = 'button';
      activate.textContent = '🔊 Ativar som';
      activate.addEventListener('click', () => activateSoundFromToast(activate));
      hint.appendChild(text);
      hint.appendChild(activate);
      box.appendChild(hint);
    }

    stack().prepend(box);

    // Mantém no máximo 4 avisos simultâneos. O mesmo pedido não é reapresentado
    // por reconnect/reload por causa do claimEvent/localStorage acima.
    const cards = Array.from(stack().querySelectorAll('.nexa-rt-toast'));
    cards.slice(4).forEach(card => card.remove());

    window.setTimeout(() => { if (box.isConnected) box.remove(); }, 30000);
  };

  const showSystemNotification = (message) => {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    const payload = message.payload || {};
    const number = orderNumber(message) || orderId(message);
    try {
      const notification = new Notification('Novo pedido do delivery', {
        body: `Pedido #${number} • ${payload.customer || 'Cliente'} • ${money(payload.total)}`,
        tag: `nexa-delivery-${companyId}-${orderId(message) || String(message.streamId || 'event')}`,
        renotify: false,
      });
      notification.onclick = () => {
        try { window.focus(); } catch (_) {}
        window.location.href = orderUrl(message);
        try { notification.close(); } catch (_) {}
      };
      window.setTimeout(() => { try { notification.close(); } catch (_) {} }, 15000);
    } catch (_) {}
  };

  const pulseTitle = (message) => {
    window.clearTimeout(titleTimer);
    document.title = `🔔 Pedido #${orderNumber(message) || orderId(message)} - ${originalTitle}`;
    titleTimer = window.setTimeout(() => { document.title = originalTitle; }, 12000);
  };

  const alertDeliveryOrder = async (message) => {
    if (!(await claimEvent(message))) return;

    // Se o usuário já autorizou o som anteriormente, tenta restaurar o
    // AudioContext sem voltar a pedir autorização. Em páginas onde houve
    // qualquer clique/tecla, gestureUnlock já terá deixado o contexto pronto.
    if (soundPreferenceEnabled && (!audioReady || audioCtx?.state !== 'running')) {
      await unlockAudio();
    }

    const soundPlayed = soundPreferenceEnabled ? playBeep() : false;
    showToast(message, soundPlayed);
    showSystemNotification(message);
    pulseTitle(message);
    updatePermissionButton();
  };

  const dispatchEvent = (message) => {
    try { window.dispatchEvent(new CustomEvent('nexa:realtime', { detail: message })); } catch (_) {}
    if (features.deliveryNewOrder && message.eventType === 'delivery.order.created') {
      alertDeliveryOrder(message);
    }
  };

  const setConnectionState = (state) => {
    let el = document.getElementById('nexaRealtimeState');
    if (!el) {
      el = document.createElement('div');
      el.id = 'nexaRealtimeState';
      el.className = 'nexa-rt-state';
      document.body.appendChild(el);
    }
    window.clearTimeout(statusHideTimer);
    el.className = 'nexa-rt-state';
    if (state === 'connected') {
      el.classList.add('ok');
      el.textContent = '● Tempo real conectado';
      statusHideTimer = window.setTimeout(() => { el.style.opacity = '0'; }, 3500);
    } else if (state === 'disconnected') {
      el.classList.add('bad');
      el.style.opacity = '1';
      el.textContent = '● Tempo real desconectado — reconectando';
    } else {
      el.style.opacity = '1';
      el.textContent = '● Conectando ao tempo real…';
    }
  };

  const connect = () => {
    if (closedByPage) return;
    window.clearTimeout(reconnectTimer);
    try {
      socket = new WebSocket(buildUrl());
      window.NEXA_REALTIME_SOCKET = socket;
    } catch (_) {
      reconnectAttempt += 1;
      reconnectTimer = window.setTimeout(connect, Math.min(30000, 1000 * (2 ** Math.min(reconnectAttempt, 5))));
      return;
    }

    socket.addEventListener('open', () => { reconnectAttempt = 0; setConnectionState('connected'); });
    socket.addEventListener('message', (event) => {
      let message = null;
      try { message = JSON.parse(event.data); } catch (_) { return; }
      if (!message || Number(message.companyId || 0) !== companyId) return;
      if (message.type === 'welcome') return;
      dispatchEvent(message);
    });
    socket.addEventListener('close', () => {
      if (closedByPage) return;
      setConnectionState('disconnected');
      reconnectAttempt += 1;
      reconnectTimer = window.setTimeout(connect, Math.min(30000, 1000 * (2 ** Math.min(reconnectAttempt, 5))));
    });
    socket.addEventListener('error', () => { try { socket.close(); } catch (_) {} });
  };

  const gestureUnlock = async () => {
    if (!soundPreferenceEnabled || audioReady) return;
    await unlockAudio();
  };

  injectStyles();
  updatePermissionButton();

  // Tenta liberar o som em qualquer gesto do usuário. Não usa "once" para que,
  // se a primeira tentativa for recusada pelo navegador, a próxima interação tente de novo.
  document.addEventListener('pointerdown', gestureUnlock, { capture: true, passive: true });
  document.addEventListener('keydown', gestureUnlock, { capture: true });
  document.addEventListener('touchstart', gestureUnlock, { capture: true, passive: true });

  // Sincroniza a preferência entre abas do mesmo navegador.
  window.addEventListener('storage', (event) => {
    if (event.key !== soundPreferenceKey) return;
    soundPreferenceEnabled = event.newValue === '1';
    updatePermissionButton();
  });

  // Se o som já foi autorizado em uma visita anterior, tenta restaurá-lo logo
  // ao carregar. Caso o navegador bloqueie autoplay, a primeira interação
  // normal do operador nesta tela o reativa sem exibir novamente o aviso.
  if (soundPreferenceEnabled) {
    unlockAudio().catch(() => {});
  }

  window.addEventListener('beforeunload', () => {
    closedByPage = true;
    window.clearTimeout(reconnectTimer);
    try { socket?.close(); } catch (_) {}
  });

  setConnectionState('connecting');
  connect();
})();

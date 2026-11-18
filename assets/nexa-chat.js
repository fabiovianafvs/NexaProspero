// Balão do Chat interno em todas as telas do painel (carregado pelo admin_footer).
// Mostra as mensagens não lidas e abre a conversa numa janelinha (chat.php?embed=1)
// sem sair da tela atual. Atualiza pelo nexa-realtime (evento chat.message), sem
// consultas periódicas.
(() => {
  'use strict';
  const cfg = window.NEXA_CHAT || {};
  if (!cfg.me || document.getElementById('nexaChatBtn')) return;
  const ME = Number(cfg.me);
  let unread = Number(cfg.unread || 0);
  let panel = null, frame = null, opened = false;

  const style = document.createElement('style');
  style.textContent = `
    .nexa-chat-btn{position:fixed;right:18px;bottom:18px;z-index:9990;width:54px;height:54px;border-radius:50%;border:0;cursor:pointer;
      background:linear-gradient(135deg,var(--admin-primary,#0B1F5B),var(--admin-secondary,#2454D6));color:#fff;font-size:24px;box-shadow:0 10px 28px rgba(11,31,91,.32);display:grid;place-items:center}
    .nexa-chat-btn:hover{transform:translateY(-1px)}
    .nexa-chat-badge{position:absolute;top:-3px;right:-3px;min-width:21px;height:21px;border-radius:999px;background:#dc2626;color:#fff;font:800 11px/21px system-ui,sans-serif;padding:0 6px;border:2px solid #fff}
    .nexa-chat-badge[hidden]{display:none}
    .nexa-chat-panel{position:fixed;right:18px;bottom:82px;z-index:9991;width:390px;height:min(600px,calc(100vh - 110px));background:#fff;border-radius:16px;overflow:hidden;
      box-shadow:0 24px 60px rgba(11,31,91,.35);border:1px solid #d1d7db;display:none;flex-direction:column}
    .nexa-chat-panel.on{display:flex}
    .nexa-chat-top{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:linear-gradient(135deg,var(--admin-primary,#0B1F5B),var(--admin-secondary,#2454D6));color:#fff;font:800 14px system-ui,sans-serif}
    .nexa-chat-top button{border:0;background:transparent;color:#fff;font-size:20px;cursor:pointer;line-height:1}
    .nexa-chat-panel iframe{flex:1;border:0;width:100%}
    body.nexa-chat-on .nexa-rt-enable{bottom:84px}
    @media(max-width:640px){.nexa-chat-panel{right:0;bottom:0;width:100vw;height:100dvh;border-radius:0}.nexa-chat-btn{right:12px;bottom:12px}}
    @media print{.nexa-chat-btn,.nexa-chat-panel{display:none!important}}
  `;
  document.head.appendChild(style);
  document.body.classList.add('nexa-chat-on');

  const btn = document.createElement('button');
  btn.type = 'button'; btn.id = 'nexaChatBtn'; btn.className = 'nexa-chat-btn';
  btn.title = 'Chat interno'; btn.setAttribute('aria-label', 'Chat interno');
  btn.innerHTML = '💬<span class="nexa-chat-badge" hidden></span>';
  document.body.appendChild(btn);
  const badge = btn.querySelector('.nexa-chat-badge');
  const paint = () => { badge.hidden = unread <= 0; badge.textContent = unread > 99 ? '99+' : String(unread); btn.title = unread ? `Chat interno: ${unread} mensagem(ns) não lida(s)` : 'Chat interno'; };
  paint();

  function toggle(force) {
    opened = typeof force === 'boolean' ? force : !opened;
    if (opened && !panel) {
      panel = document.createElement('div');
      panel.className = 'nexa-chat-panel';
      panel.innerHTML = '<div class="nexa-chat-top"><span>💬 Chat interno</span><button type="button" title="Fechar" aria-label="Fechar">×</button></div>';
      frame = document.createElement('iframe');
      frame.title = 'Chat interno';
      frame.src = '/admin/chat.php?embed=1';
      panel.appendChild(frame);
      panel.querySelector('button').addEventListener('click', () => toggle(false));
      document.body.appendChild(panel);
    }
    if (panel) panel.classList.toggle('on', opened);
  }
  btn.addEventListener('click', () => toggle());
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && opened) toggle(false); });

  // A janelinha informa o total de não lidas.
  window.addEventListener('message', (e) => {
    if (e.origin !== location.origin || !e.data) return;
    if (e.data.nexaChat === 'close') { toggle(false); btn.focus(); return; }
    if (e.data.nexaChat !== 'unread') return;
    unread = Number(e.data.unread || 0); paint();
  });

  async function refreshUnread() {
    try {
      const r = await fetch('/admin/chat_api.php?action=unread', { credentials: 'same-origin' });
      const d = await r.json();
      if (d && d.ok) { unread = Number(d.unread || 0); paint(); }
    } catch (_) {}
  }

  // Tempo real: só ids (o conteúdo nunca passa pelo aviso).
  const rt = cfg.rt || {};
  function connect(attempt) {
    if (!rt.enabled || !rt.url || !rt.token) return;
    let ws;
    try { ws = new WebSocket(rt.url + (rt.url.includes('?') ? '&' : '?') + 'token=' + encodeURIComponent(rt.token)); }
    catch (_) { setTimeout(() => connect(attempt + 1), Math.min(60000, 2000 * 2 ** Math.min(attempt, 5))); return; }
    ws.addEventListener('open', () => { attempt = 0; });
    ws.addEventListener('message', (ev) => {
      let m; try { m = JSON.parse(ev.data); } catch (_) { return; }
      if (!m || m.eventType !== 'chat.message' || m.replay) return;
      const p = m.payload || {};
      if (!Array.isArray(p.members) || !p.members.map(Number).includes(ME)) return;
      if (Number(p.by) === ME && p.what !== 'read') return;
      if (opened) return; // a janelinha aberta se atualiza sozinha e informa o total
      refreshUnread();
      if (p.what === 'message' || !p.what) { btn.animate([{ transform: 'scale(1)' }, { transform: 'scale(1.15)' }, { transform: 'scale(1)' }], { duration: 450 }); }
    });
    ws.addEventListener('close', () => setTimeout(() => connect(attempt + 1), Math.min(60000, 2000 * 2 ** Math.min(attempt + 1, 5))));
    ws.addEventListener('error', () => { try { ws.close(); } catch (_) {} });
  }
  connect(0);
  window.addEventListener('focus', () => { if (!opened) refreshUnread(); });
})();

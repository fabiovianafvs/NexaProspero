<?php
declare(strict_types=1);

// WhatsApp > Painel Ao Vivo: quem está atendendo cada conversa agora. Uma coluna com as
// conversas na fila (Pendentes) e uma por atendente (ou por setor), com o ponto verde de
// quem está com o Nexa aberto. Atualiza pelo serviço nexa-realtime (sem consultas periódicas).
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/whatsapp_atendimento.php';
if (is_file(__DIR__ . '/../config/brain_realtime.php')) require_once __DIR__ . '/../config/brain_realtime.php';

$cid = (int)COMPANY_ID;
$uid = (int)(auth_user_id() ?? 0);
$allowed = access_is_company_master() || access_has_permission($pdo, $cid, 'whatsapp_ao_vivo') || access_has_permission($pdo, $cid, 'whatsapp_chatbot');
$ready = whatsapp_bot_ready($pdo);

// Dados (só leitura) para a tela.
if (isset($_GET['api'])) {
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store');
  if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
  if ($uid <= 0) { http_response_code(401); echo '{"ok":false,"error":"Sessão expirada. Entre novamente."}'; exit; }
  if (!$allowed) { http_response_code(403); echo '{"ok":false,"error":"Sem permissão para o Painel Ao Vivo."}'; exit; }
  if (!$ready) { http_response_code(409); echo '{"ok":false,"error":"Aplique \"WhatsApp - Atendimento\" no Atualizador do Banco."}'; exit; }
  try {
    echo json_encode(['ok' => true] + whatsapp_att_live($pdo, $cid), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Não foi possível carregar: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

require __DIR__ . '/_chrome.php';
if (!$allowed) {
  if (function_exists('access_render_denied')) access_render_denied('Seu usuário não possui permissão para ver o Painel Ao Vivo do WhatsApp.');
  http_response_code(403); exit('Acesso restrito.');
}
$rt = ['enabled' => false];
if (function_exists('nexa_realtime_browser_config')) {
  try { $rt = nexa_realtime_browser_config($cid, $uid); } catch (Throwable $e) {}
}

admin_topbar('Painel Ao Vivo', 'whatsapp_ao_vivo');
?>
<style>
.wlv{--red:#dc2626;--ok:#16a34a;--off:#ef4444;--line:#e2e8f0;--mut:#64748b;--txt:#0f172a;margin-top:10px;background:#f1f5f9;border:1px solid #dbe3ee;border-radius:18px;padding:12px;color:var(--txt);display:flex;flex-direction:column;gap:12px;height:calc(100vh - 150px);height:calc(100dvh - 150px);min-height:420px}
.wlv *{box-sizing:border-box}
.wlv:fullscreen{height:100vh;border-radius:0;border:0;padding:16px}
.wlv-head{display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:#fff;border:1px solid var(--line);border-radius:14px;padding:10px 14px}
.wlv-title{display:flex;align-items:center;gap:8px;font-size:20px;font-weight:900;color:#0b1f5b;margin:0}
.wlv-total{min-width:28px;height:28px;border-radius:999px;background:#0b1f5b;color:#fff;font-size:14px;font-weight:900;display:inline-flex;align-items:center;justify-content:center;padding:0 9px;font-variant-numeric:tabular-nums}
.wlv-live{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:800;color:var(--mut)}
.wlv-live i{width:9px;height:9px;border-radius:50%;background:#94a3b8;display:inline-block}
.wlv-live.on i{background:var(--ok);box-shadow:0 0 0 0 rgba(22,163,74,.55);animation:wlvPulse 1.8s infinite}
@keyframes wlvPulse{70%{box-shadow:0 0 0 8px rgba(22,163,74,0)}100%{box-shadow:0 0 0 0 rgba(22,163,74,0)}}
.wlv-mode{display:inline-flex;gap:4px;background:#f1f5f9;border-radius:10px;padding:3px;margin-left:auto}
.wlv-mode label{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;font-size:13px;font-weight:800;color:#334155;cursor:pointer;margin:0!important;min-height:0!important}
.wlv-mode input{margin:0;accent-color:#0b1f5b}
.wlv-mode label.on{background:#fff;box-shadow:0 1px 3px rgba(15,23,42,.12);color:#0b1f5b}
.wlv-ico{width:38px;height:38px;border-radius:10px;border:1px solid var(--line);background:#fff;cursor:pointer;font-size:17px;display:inline-flex;align-items:center;justify-content:center;color:#0b1f5b}
.wlv-ico:hover{background:#f8fafc}.wlv-ico:focus-visible{outline:2px solid #2454d6;outline-offset:2px}
.wlv-ico.spin span{display:inline-block;animation:wlvSpin .7s linear}
@keyframes wlvSpin{to{transform:rotate(360deg)}}
.wlv-board{flex:1;min-height:0;display:flex;gap:12px;overflow-x:auto;overflow-y:hidden;padding-bottom:4px;align-items:stretch}
.wlv-col{flex:0 0 300px;display:flex;flex-direction:column;min-height:0;background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden}
.wlv-col.pend{border-color:#fecaca}
.wlv-ch{display:flex;align-items:center;gap:10px;padding:10px 12px;border-bottom:1px solid var(--line)}
.wlv-col.pend .wlv-ch{background:var(--red);color:#fff;border-bottom-color:var(--red)}
.wlv-av{position:relative;flex:0 0 36px;width:36px;height:36px;border-radius:50%;background:#e0e7ff;color:#3730a3;font-weight:900;font-size:14px;display:flex;align-items:center;justify-content:center;text-transform:uppercase}
.wlv-col.pend .wlv-av{background:rgba(255,255,255,.2);color:#fff;font-size:17px}
.wlv-dot{position:absolute;right:-1px;bottom:-1px;width:12px;height:12px;border-radius:50%;border:2px solid #fff;background:#cbd5e1}
.wlv-dot.on{background:var(--ok)}.wlv-dot.off{background:var(--off)}
.wlv-cn{flex:1;min-width:0}
.wlv-cn b{display:block;font-size:14px;font-weight:900;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wlv-cn small{display:block;font-size:11.5px;color:var(--mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wlv-col.pend .wlv-cn small{color:#fee2e2}
.wlv-n{min-width:26px;height:26px;border-radius:999px;background:#eef2ff;color:#1e3a8a;font-weight:900;font-size:13px;display:inline-flex;align-items:center;justify-content:center;padding:0 8px;font-variant-numeric:tabular-nums}
.wlv-col.pend .wlv-n{background:#fff;color:var(--red)}
.wlv-list{flex:1;min-height:0;overflow-y:auto;padding:6px}
.wlv-it{display:grid;grid-template-columns:32px minmax(0,1fr) auto;gap:8px;align-items:center;padding:8px;border-radius:10px;text-decoration:none;color:inherit;border:1px solid transparent}
.wlv-it:hover{background:#f8fafc;border-color:var(--line)}.wlv-it:focus-visible{outline:2px solid #2454d6;outline-offset:1px}
.wlv-it+.wlv-it{margin-top:2px}
.wlv-ia{width:32px;height:32px;border-radius:50%;background:#dcfce7;color:#166534;font-weight:900;font-size:13px;display:flex;align-items:center;justify-content:center;text-transform:uppercase}
.wlv-it.q .wlv-ia{background:#fee2e2;color:#991b1b}
.wlv-im{min-width:0}
.wlv-im b{display:block;font-size:13px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wlv-im span{display:block;font-size:11.5px;color:var(--mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wlv-im .tag{display:inline-block;font-size:10.5px;font-weight:800;border-radius:999px;padding:1px 7px;margin-right:4px;background:#f1f5f9;color:#334155}
.wlv-im .tag.q{background:#fee2e2;color:#991b1b}
.wlv-ir{display:flex;flex-direction:column;align-items:flex-end;gap:3px}
.wlv-t{font-size:11.5px;color:var(--mut);font-weight:700;white-space:nowrap;font-variant-numeric:tabular-nums}
.wlv-t.late{color:#b45309}.wlv-t.very{color:var(--red)}
.wlv-u{min-width:18px;height:18px;border-radius:999px;background:#16a34a;color:#fff;font-size:11px;font-weight:900;display:inline-flex;align-items:center;justify-content:center;padding:0 5px}
.wlv-empty{padding:18px 10px;text-align:center;color:var(--mut);font-size:12.5px}
.wlv-msg{padding:12px 14px;border-radius:12px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-weight:700}
.wlv-note{font-size:11.5px;color:var(--mut)}
@media(max-width:700px){.wlv{height:auto;min-height:0}.wlv-board{flex-direction:column;overflow:visible}.wlv-col{flex:0 0 auto;max-height:420px}.wlv-mode{margin-left:0}}
@media(prefers-reduced-motion:reduce){.wlv-live.on i,.wlv-ico.spin span{animation:none}}
</style>

<?php if (!$ready): ?>
<div class="wlv-msg" style="margin-top:12px">Aplique "WhatsApp - Atendimento" no Atualizador do Banco para usar o Painel Ao Vivo.</div>
<?php else: ?>
<div class="wlv" id="wlv">
  <div class="wlv-head">
    <h1 class="wlv-title">Ao vivo <span class="wlv-total" id="wlvTotal" title="Conversas na fila + em atendimento">0</span></h1>
    <span class="wlv-live" id="wlvLive" title="Atualiza sozinho a cada mudança nas conversas"><i></i><span>Conectando…</span></span>
    <div class="wlv-mode" role="radiogroup" aria-label="Agrupar por">
      <label class="on"><input type="radio" name="wlvMode" value="users" checked> Usuários</label>
      <label><input type="radio" name="wlvMode" value="deps"> Departamentos</label>
    </div>
    <button class="wlv-ico" type="button" id="wlvRefresh" title="Atualizar agora" aria-label="Atualizar agora"><span>⟳</span></button>
    <button class="wlv-ico" type="button" id="wlvFull" title="Tela cheia" aria-label="Tela cheia"><span>⛶</span></button>
  </div>
  <div class="wlv-board" id="wlvBoard"><div class="wlv-empty">Carregando…</div></div>
  <div class="wlv-note" id="wlvNote">Clique numa conversa para abrir no Atendimento. O ponto verde mostra quem está com o Nexa aberto agora; o vermelho, quem não está.</div>
</div>
<script>
(() => {
  const RT = <?= json_encode(['enabled' => !empty($rt['enabled']), 'url' => (string)($rt['url'] ?? ''), 'token' => (string)($rt['token'] ?? ''), 'companyId' => $cid], JSON_UNESCAPED_SLASHES) ?>;
  const $ = (id) => document.getElementById(id);
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const ini = (n) => { const p = String(n || '?').trim().split(/\s+/).filter(Boolean); return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase(); };
  let data = null, mode = 'users', online = null, loading = false, again = false, timer = 0;
  try { if (localStorage.getItem('nexa_wlv_mode') === 'deps') mode = 'deps'; } catch (_) {}

  // Diferença de relógio entre o servidor e o computador (o tempo de espera usa a hora do servidor).
  let skew = 0;
  const ts = (s) => s ? new Date(String(s).replace(' ', 'T')).getTime() : 0;
  function ago(s) {
    const t = ts(s); if (!t) return { txt: '', cls: '' };
    const min = Math.max(0, Math.floor((Date.now() - skew - t) / 60000));
    const txt = min < 1 ? 'agora' : min < 60 ? min + ' min' : Math.floor(min / 60) + 'h' + String(min % 60).padStart(2, '0');
    return { txt, cls: min >= 30 ? 'very' : min >= 10 ? 'late' : '' };
  }

  function item(c, opts = {}) {
    const queue = c.status === 'PENDENTE';
    const since = queue ? c.queued_at : (c.accepted_at || c.last_at);
    const a = ago(since);
    const tags = [];
    if (opts.showQueue && queue) tags.push('<span class="tag q">Na fila</span>');
    if (opts.showUser && !queue) tags.push('<span class="tag">' + esc(c.user || 'Pelo celular') + '</span>');
    if (opts.showDep && c.department) tags.push('<span class="tag">' + esc(c.department) + '</span>');
    const sub = tags.join('') + esc(c.preview || c.phone || '');
    return '<a class="wlv-it' + (queue ? ' q' : '') + '" href="whatsapp_atendimento.php?id=' + c.id + '" target="_blank" rel="noopener" title="' + esc(c.name + (c.phone ? ' · ' + c.phone : '') + (queue ? ' · na fila' : ' · em atendimento')) + '">'
      + '<span class="wlv-ia">' + esc(ini(c.name)) + '</span>'
      + '<span class="wlv-im"><b>' + esc(c.name) + '</b><span>' + sub + '</span></span>'
      + '<span class="wlv-ir"><span class="wlv-t ' + a.cls + '" data-since="' + esc(since || '') + '" title="' + (queue ? 'Esperando na fila há' : 'Em atendimento há') + '">' + esc(a.txt) + '</span>'
      + (c.unread > 0 ? '<span class="wlv-u" title="Mensagens não lidas">' + c.unread + '</span>' : '') + '</span></a>';
  }

  function col(opts) {
    const dot = opts.userId ? '<span class="wlv-dot ' + (online === null ? '' : (online.has(opts.userId) ? 'on' : 'off')) + '" data-user="' + opts.userId + '" title="' + (online === null ? 'Situação on-line indisponível' : (online.has(opts.userId) ? 'On-line (com o Nexa aberto)' : 'Off-line')) + '"></span>' : '';
    return '<section class="wlv-col' + (opts.pend ? ' pend' : '') + '">'
      + '<div class="wlv-ch"><span class="wlv-av">' + (opts.icon || esc(ini(opts.title))) + dot + '</span>'
      + '<span class="wlv-cn"><b>' + esc(opts.title) + '</b>' + (opts.sub ? '<small>' + esc(opts.sub) + '</small>' : '') + '</span>'
      + '<span class="wlv-n">' + opts.items.length + '</span></div>'
      + '<div class="wlv-list">' + (opts.items.length ? opts.items.map((c) => item(c, opts)).join('') : '<div class="wlv-empty">' + esc(opts.empty || 'Nenhuma conversa.') + '</div>') + '</div></section>';
  }

  function render() {
    if (!data) return;
    const all = [...data.pending, ...data.phone, ...data.attendants.flatMap((a) => a.items)];
    $('wlvTotal').textContent = all.length;
    const cols = [];
    if (mode === 'users') {
      cols.push(col({ pend: true, icon: '⏳', title: 'Pendentes', sub: 'Aguardando um atendente aceitar', items: data.pending, showDep: true, empty: 'Ninguém esperando. 🎉' }));
      data.attendants.forEach((a) => cols.push(col({ userId: a.id, title: a.name, sub: a.departments.length ? a.departments.join(', ') : '', items: a.items, showDep: true, empty: 'Sem conversas agora.' })));
      if (data.phone.length) cols.push(col({ icon: '📱', title: 'Pelo celular', sub: 'Respondidas no celular da empresa', items: data.phone, showDep: true }));
    } else {
      const groups = new Map();
      data.departments.forEach((d) => groups.set(d.id, { title: d.name, items: [] }));
      all.forEach((c) => {
        const k = c.department_id || 0;
        if (!groups.has(k)) groups.set(k, { title: k ? (c.department || 'Setor #' + k) : 'Sem setor', items: [] });
        groups.get(k).items.push(c);
      });
      if (groups.has(0) && !groups.get(0).items.length) groups.delete(0);
      groups.forEach((g) => {
        // Na fila primeiro (por ordem de chegada); depois os em atendimento.
        g.items.sort((x, y) => (x.status === 'PENDENTE' ? 0 : 1) - (y.status === 'PENDENTE' ? 0 : 1) || ts(x.status === 'PENDENTE' ? x.queued_at : x.accepted_at) - ts(y.status === 'PENDENTE' ? y.queued_at : y.accepted_at));
        const q = g.items.filter((c) => c.status === 'PENDENTE').length;
        cols.push(col({ title: g.title, sub: q + ' na fila · ' + (g.items.length - q) + ' em atendimento', items: g.items, showQueue: true, showUser: true, empty: 'Sem conversas agora.' }));
      });
      if (!cols.length) cols.push('<div class="wlv-empty">Nenhum setor cadastrado no chatbot.</div>');
    }
    $('wlvBoard').innerHTML = cols.join('');
  }

  function paintDots() {
    document.querySelectorAll('.wlv-dot[data-user]').forEach((d) => {
      const on = online !== null && online.has(Number(d.dataset.user));
      d.className = 'wlv-dot ' + (online === null ? '' : (on ? 'on' : 'off'));
      d.title = online === null ? 'Situação on-line indisponível' : (on ? 'On-line (com o Nexa aberto)' : 'Off-line');
    });
  }

  async function load() {
    if (loading) { again = true; return; }
    loading = true;
    $('wlvRefresh').classList.add('spin');
    try {
      const r = await fetch('whatsapp_ao_vivo.php?api=1', { credentials: 'same-origin', cache: 'no-store' });
      const d = await r.json();
      if (!d.ok) throw new Error(d.error || 'Falha ao carregar.');
      skew = Date.now() - ts(d.now);
      data = d; render();
    } catch (e) {
      $('wlvBoard').innerHTML = '<div class="wlv-msg">' + esc(e.message || e) + '</div>';
    } finally {
      loading = false;
      setTimeout(() => $('wlvRefresh').classList.remove('spin'), 700);
      if (again) { again = false; load(); }
    }
  }
  const schedule = () => { clearTimeout(timer); timer = setTimeout(load, 350); };

  // Tempo de espera: só recalcula o texto na tela (não consulta o servidor).
  setInterval(() => { document.querySelectorAll('.wlv-t[data-since]').forEach((el) => { const a = ago(el.dataset.since); el.textContent = a.txt; el.className = 'wlv-t ' + a.cls; }); }, 30000);

  document.querySelectorAll('input[name=wlvMode]').forEach((r) => {
    if (r.value === mode) { r.checked = true; }
    r.closest('label').classList.toggle('on', r.value === mode);
    r.addEventListener('change', () => {
      mode = r.value;
      try { localStorage.setItem('nexa_wlv_mode', mode); } catch (_) {}
      document.querySelectorAll('.wlv-mode label').forEach((l) => l.classList.toggle('on', l.querySelector('input').checked));
      render();
    });
  });
  $('wlvRefresh').addEventListener('click', load);
  $('wlvFull').addEventListener('click', () => {
    const box = $('wlv');
    if (document.fullscreenElement) document.exitFullscreen?.(); else box.requestFullscreen?.().catch(() => {});
  });
  document.addEventListener('fullscreenchange', () => { $('wlvFull').title = document.fullscreenElement ? 'Sair da tela cheia' : 'Tela cheia'; });

  // Tempo real: muda a conversa -> recarrega; presença (on-line) vem do próprio serviço.
  function live(on, text) { const el = $('wlvLive'); el.classList.toggle('on', on); el.querySelector('span').textContent = text; }
  function connectRt(attempt = 0) {
    if (!RT.enabled || !RT.url || !RT.token) { live(false, 'Tempo real desligado — use ⟳ para atualizar'); return; }
    let ws;
    try { ws = new WebSocket(RT.url + (RT.url.includes('?') ? '&' : '?') + 'token=' + encodeURIComponent(RT.token)); }
    catch (_) { setTimeout(() => connectRt(attempt + 1), Math.min(30000, 1000 * 2 ** Math.min(attempt, 5))); return; }
    ws.addEventListener('open', () => { attempt = 0; live(true, 'Ao vivo'); load(); });
    ws.addEventListener('message', (ev) => {
      let m; try { m = JSON.parse(ev.data); } catch (_) { return; }
      if (!m) return;
      if (m.type === 'presence.list') { online = new Set((m.users || []).map(Number)); paintDots(); return; }
      if (m.type === 'presence') { if (online === null) online = new Set(); if (m.online) online.add(Number(m.userId)); else online.delete(Number(m.userId)); paintDots(); return; }
      if (m.eventType === 'whatsapp.chat.updated' && !m.replay && !(m.payload && m.payload.ticks)) schedule();
    });
    ws.addEventListener('close', () => { live(false, 'Reconectando…'); setTimeout(() => connectRt(attempt + 1), Math.min(30000, 1000 * 2 ** Math.min(attempt + 1, 5))); });
    ws.addEventListener('error', () => { try { ws.close(); } catch (_) {} });
  }
  window.addEventListener('focus', schedule);
  load();
  connectRt();
})();
</script>
<?php endif; ?>
<?php admin_footer(); ?>

<?php
declare(strict_types=1);

// Chat interno: conversas entre os usuários da empresa (diretas e em grupo), com
// arquivos e resposta citando outra mensagem. Tela cheia (menu) ou janela do balão
// (?embed=1, aberta pelo assets/nexa-chat.js em qualquer tela). Tempo real pelo
// nexa-realtime (evento chat.message), sem consultas periódicas.
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/chat_interno.php';
if (is_file(__DIR__ . '/../config/brain_realtime.php')) require_once __DIR__ . '/../config/brain_realtime.php';

$cid = (int)COMPANY_ID;
$uid = (int)(auth_user_id() ?? 0);
$embed = !empty($_GET['embed']);
if (!chat_user_allowed($pdo, $cid)) {
  if (function_exists('access_render_denied')) access_render_denied('O Chat interno está desligado no seu perfil de acesso.');
  http_response_code(403); exit('O Chat interno está desligado no seu perfil de acesso.');
}
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['nexa_chat_csrf'])) $_SESSION['nexa_chat_csrf'] = bin2hex(random_bytes(20));
$csrf = (string)($_SESSION['nexa_chat_csrf'] ?? '');
$ready = chat_ready($pdo);
$rt = ['enabled' => false];
if (function_exists('nexa_realtime_browser_config')) { try { $rt = nexa_realtime_browser_config($cid, $uid); } catch (Throwable $e) {} }
$GLOBALS['NEXA_CHAT_PAGE'] = true; // o balão não aparece na própria tela do chat

if ($embed) {
  echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Chat interno</title><link rel="stylesheet" href="/assets/admin.css"><style>html,body{margin:0;height:100%;background:#fff;zoom:1!important}</style></head><body>';
} else {
  require __DIR__ . '/_chrome.php';
  admin_topbar('Chat interno', 'chat_interno');
}
?>
<style>
.ci{--g:#2454d6;--bg:#f0f2f5;--line:#e9edef;--mut:#667781;display:grid;grid-template-columns:clamp(260px,28vw,360px) 1fr;height:calc(100vh / var(--nexa-zoom,1) - 150px);height:calc(100dvh / var(--nexa-zoom,1) - 150px);min-height:420px;border:1px solid #d1d7db;border-radius:16px;overflow:hidden;background:#fff;color:#111b21}
.ci *{box-sizing:border-box}
.ci.embed{height:100dvh;border:0;border-radius:0;grid-template-columns:1fr;min-height:0}
.ci.embed.open .ci-side,.ci:not(.embed) .ci-back{display:none}.ci.embed:not(.open) .ci-main{display:none}
.ci-side{display:flex;flex-direction:column;border-right:1px solid var(--line);min-width:0;min-height:0}
.ci-head{padding:10px 12px;background:var(--bg);display:flex;gap:8px;align-items:center;min-height:56px}
.ci-head input{flex:1;border:0;border-radius:10px;padding:9px 12px;background:#fff;font:inherit;min-width:0}
.ci-btn{border:1px solid #d1d7db;background:#fff;border-radius:10px;padding:7px 11px;font-weight:700;cursor:pointer;color:#0b1f5b;font-size:13px;white-space:nowrap}
.ci-btn.g{background:var(--g);border-color:var(--g);color:#fff}.ci-btn.r{color:#b91c1c}
.ci-list{flex:1;overflow-y:auto;min-height:0}
.ci-item{display:flex;gap:10px;padding:10px 12px;border-bottom:1px solid var(--line);cursor:pointer;align-items:center}.ci-item:hover{background:#f5f6f6}.ci-item.on{background:#eef2ff}
.ci-av{width:40px;height:40px;border-radius:50%;background:#dbe4ff;color:#2454d6;display:flex;align-items:center;justify-content:center;font-weight:800;flex:0 0 40px}
.ci-meta{flex:1;min-width:0}.ci-row{display:flex;justify-content:space-between;gap:6px;align-items:baseline}
.ci-name{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ci-time{font-size:11px;color:var(--mut);white-space:nowrap}
.ci-prev{font-size:13px;color:var(--mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ci-unread{background:var(--g);color:#fff;border-radius:999px;font-size:11px;font-weight:800;padding:2px 7px}
.ci-empty{padding:28px 16px;color:var(--mut);text-align:center;font-size:14px}
.ci-main{display:flex;flex-direction:column;min-width:0;min-height:0;background:#eef1f6}
.ci-title{flex:1;min-width:0}.ci-title b{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ci-title small{color:var(--mut);display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ci-msgs{flex:1;overflow-y:auto;padding:14px 5%;display:flex;flex-direction:column;gap:4px;min-height:0}
.ci-b{max-width:72%;padding:6px 9px 4px;border-radius:8px;box-shadow:0 1px .5px rgba(11,20,26,.13);font-size:14px;line-height:1.4;white-space:pre-wrap;word-wrap:break-word;position:relative;background:#fff;align-self:flex-start}
.ci-b.me{align-self:flex-end;background:#dbe8ff}
.ci-b small{display:block;text-align:right;font-size:11px;color:var(--mut);margin-top:2px}
.ci-who{font-size:12px;font-weight:800;color:#2454d6;margin-bottom:2px}
.ci-sys{align-self:center;background:#fff8c5;color:#54656f;font-size:12px;border-radius:8px;padding:4px 10px;margin:4px 0;text-align:center;white-space:pre-wrap}
.ci-day{align-self:center;background:#fff;color:#54656f;font-size:12px;border-radius:8px;padding:3px 10px;margin:8px 0}
.ci-b img{max-width:260px;max-height:300px;border-radius:6px;display:block}
.ci-doc{display:flex;gap:8px;align-items:center;background:rgba(0,0,0,.05);border-radius:6px;padding:8px;text-decoration:none;color:inherit}
.ci-q{border-left:4px solid #2454d6;background:rgba(0,0,0,.05);border-radius:6px;padding:4px 8px;margin:2px 0 4px;font-size:12.5px;white-space:normal;max-height:62px;overflow:hidden}.ci-q b{display:block;color:#2454d6;font-size:12px}
.ci-del{color:var(--mut);font-style:italic}
.ci-tk{margin-left:4px;font-weight:700;letter-spacing:-3px;color:#8696a0}.ci-tk.read{color:#2454d6}
.ci-mb{position:absolute;top:2px;right:2px;border:0;border-radius:50%;width:22px;height:22px;background:inherit;color:#54656f;cursor:pointer;opacity:0;font-size:14px}.ci-b:hover .ci-mb,.ci-mb:focus-visible{opacity:1}
.ci-menu{position:fixed;z-index:10000;background:#fff;border-radius:10px;box-shadow:0 10px 30px rgba(11,20,26,.25);padding:6px 0;min-width:150px;display:none}.ci-menu.on{display:block}
.ci-menu button{display:block;width:100%;text-align:left;border:0;background:#fff;padding:8px 14px;font:inherit;font-size:14px;cursor:pointer}.ci-menu button:hover{background:#f0f2f5}.ci-menu button.r{color:#b91c1c}
.ci-bar{background:#fff;border-top:1px solid var(--line);padding:7px 12px;display:none;gap:8px;align-items:center}.ci-bar.on{display:flex}.ci-bar .ci-q{flex:1;margin:0}
.ci-compose{background:var(--bg);padding:8px 10px;display:flex;gap:6px;align-items:flex-end}
.ci-compose textarea{flex:1;border:0;border-radius:10px;padding:10px 12px;font:inherit;resize:none;max-height:120px;min-height:42px}
.ci-ico{border:0;background:transparent;font-size:21px;cursor:pointer;padding:6px;color:#54656f}
.ci-send{border:0;background:var(--g);color:#fff;border-radius:50%;width:42px;height:42px;font-size:17px;cursor:pointer;flex:0 0 42px}
.ci-welcome{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--mut);text-align:center;padding:24px}
.ci-modal{position:fixed;inset:0;background:rgba(11,20,26,.45);display:none;align-items:center;justify-content:center;z-index:9999;padding:14px}.ci-modal.on{display:flex}
.ci-card{background:#fff;border-radius:16px;padding:16px;width:100%;max-width:420px;display:grid;gap:10px;max-height:92vh;overflow:auto}
.ci-card h3{margin:0;color:#0b1f5b}.ci-card input[type=text],.ci-card input[type=search]{width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:8px 10px;font:inherit}
.ci-people{max-height:280px;overflow:auto;border:1px solid #e2e8f0;border-radius:10px}.ci-people label,.ci-people button{display:flex;gap:8px;align-items:center;width:100%;padding:8px 10px;border:0;border-bottom:1px solid #f1f5f9;background:#fff;font:inherit;font-size:14px;cursor:pointer;text-align:left}.ci-people label:hover,.ci-people button:hover{background:#eef2ff}
.ci-tabs{display:flex;gap:6px}.ci-tabs button{flex:1}.ci-tabs button.on{background:#eef2ff;border-color:#2454d6}
.ci-drop{outline:3px dashed #2454d6;outline-offset:-8px}
@media(max-width:820px){.ci:not(.embed){grid-template-columns:1fr;height:calc(100dvh - 120px)}.ci:not(.embed).open .ci-side{display:none}.ci:not(.embed):not(.open) .ci-main{display:none}.ci:not(.embed) .ci-back{display:inline-block}.ci-b{max-width:88%}}
</style>
<?php if (!$ready): ?>
<div class="msg err" style="margin:12px">O chat interno ainda não foi instalado. Peça ao administrador da plataforma para aplicar "Chat interno" no Atualizador do Banco.</div>
<?php else: ?>
<div class="ci<?= $embed ? ' embed' : '' ?>" id="ci">
  <aside class="ci-side">
    <div class="ci-head"><input id="ciSearch" placeholder="Pesquisar conversa" autocomplete="off"><button class="ci-btn g" type="button" id="ciNew">+ Nova</button><?php if ($embed): ?><a class="ci-btn" href="chat.php" target="_top" title="Abrir em tela cheia">⤢</a><?php endif; ?></div>
    <div class="ci-list" id="ciList"><div class="ci-empty">Carregando…</div></div>
  </aside>
  <section class="ci-main">
    <div class="ci-welcome" id="ciWelcome"><div style="font-size:48px">💬</div><h3 style="color:#41525d;margin:8px 0">Chat interno</h3><div>Converse com a equipe da empresa. Clique em <b>+ Nova</b> para falar com alguém ou criar um grupo.</div></div>
    <div id="ciChat" style="display:none;flex:1;flex-direction:column;min-height:0">
      <div class="ci-head"><button class="ci-btn ci-back" type="button" id="ciBack">←</button><div class="ci-av" id="ciAv"></div><div class="ci-title"><b id="ciName"></b><small id="ciSub"></small></div><button class="ci-btn" type="button" id="ciGroupEdit" style="display:none">Grupo</button></div>
      <div class="ci-msgs" id="ciMsgs"></div>
      <div class="ci-bar" id="ciReplyBar"><div class="ci-q" id="ciReplyQ"></div><button class="ci-btn" type="button" id="ciReplyX">✕</button></div>
      <div class="ci-bar" id="ciFileBar"><span id="ciFileName" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span><button class="ci-btn" type="button" id="ciFileX">✕</button></div>
      <form class="ci-compose" id="ciForm">
        <input type="file" id="ciFile" hidden>
        <button class="ci-ico" type="button" id="ciAttach" title="Anexar arquivo (ou cole um print com Ctrl+V)">📎</button>
        <textarea id="ciText" rows="1" placeholder="Digite uma mensagem" title="Enter envia; Shift+Enter quebra a linha"></textarea>
        <button class="ci-send" type="submit" title="Enviar">➤</button>
      </form>
    </div>
  </section>
</div>
<div class="ci-menu" id="ciMenu"></div>
<div class="ci-modal" id="ciNewModal"><div class="ci-card">
  <h3 id="ciNewTitle">Nova conversa</h3>
  <div class="ci-tabs" id="ciNewTabs"><button class="ci-btn on" type="button" data-t="pessoa">Com uma pessoa</button><button class="ci-btn" type="button" data-t="grupo">Novo grupo</button></div>
  <div id="ciGroupName" style="display:none"><label style="font-size:12px;font-weight:700">Nome do grupo</label><input type="text" id="ciGName" maxlength="80" placeholder="Ex.: Financeiro, Vendas"></div>
  <input type="search" id="ciPSearch" placeholder="Buscar pessoa" autocomplete="off">
  <div class="ci-people" id="ciPeople"></div>
  <div style="display:flex;gap:8px;justify-content:space-between"><button class="ci-btn r" type="button" id="ciLeave" style="display:none">Sair do grupo</button><span></span><div style="display:flex;gap:8px"><button class="ci-btn" type="button" data-close>Cancelar</button><button class="ci-btn g" type="button" id="ciGroupGo" style="display:none">Salvar grupo</button></div></div>
</div></div>
<script>
(() => {
  const API = 'chat_api.php', CSRF = <?= json_encode($csrf) ?>, ME = <?= (int)$uid ?>, EMBED = <?= $embed ? 'true' : 'false' ?>;
  const RT = <?= json_encode(['enabled' => !empty($rt['enabled']), 'url' => (string)($rt['url'] ?? ''), 'token' => (string)($rt['token'] ?? '')], JSON_UNESCAPED_SLASHES) ?>;
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (s) => { const urls = []; const t = String(s ?? '').replace(/https?:\/\/[^\s<>"]+/g, (u) => { urls.push(u); return '\u0000' + (urls.length - 1) + '\u0000'; }); return esc(t).replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/\u0000(\d+)\u0000/g, (_, i) => { const u = esc(urls[+i]); return '<a href="' + u + '" target="_blank" rel="noopener">' + u + '</a>'; }); };
  const ini = (n) => String(n || '?').trim().split(/\s+/).slice(0, 2).map((p) => p[0] || '').join('').toUpperCase();
  const dt = (s) => new Date(String(s).replace(' ', 'T'));
  const tm = (s) => { if (!s) return ''; const d = dt(s), t = new Date(); return d.toDateString() === t.toDateString() ? d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }); };
  const hm = (s) => dt(s).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
  let items = [], conv = 0, info = null, lastId = 0, lastDay = '', reply = null, pending = null, busy = false, q = '';
  const cache = new Map();

  async function api(action, params = {}, post = false, file = null) {
    let res;
    if (post) {
      const fd = new FormData(); fd.append('action', action);
      Object.entries(params).forEach(([k, v]) => fd.append(k, v));
      if (file) fd.append('file', file);
      res = await fetch(API, { method: 'POST', body: fd, headers: { 'X-CSRF-Token': CSRF }, credentials: 'same-origin' });
    } else res = await fetch(API + '?' + new URLSearchParams({ action, ...params }), { credentials: 'same-origin' });
    let d; try { d = await res.json(); } catch (_) { d = { ok: false, error: 'Resposta inválida do servidor (' + res.status + ').' }; }
    if (!d.ok) throw new Error(d.error || 'Falha.');
    return d;
  }
  const fail = (e) => alert(e.message || e);
  const tellParent = (n) => { if (EMBED) try { parent.postMessage({ nexaChat: 'unread', unread: n }, location.origin); } catch (_) {} };

  async function loadList() {
    try {
      const d = await api('list'); items = d.items || []; renderList(); tellParent(d.unread || 0);
      if (!EMBED) document.title = (d.unread ? '(' + d.unread + ') ' : '') + 'Chat interno';
    } catch (e) { $('ciList').innerHTML = '<div class="ci-empty">' + esc(e.message) + '</div>'; }
  }
  function renderList() {
    const f = q.trim().toLowerCase();
    const list = items.filter((c) => !f || c.name.toLowerCase().includes(f));
    $('ciList').innerHTML = list.map((c) => `<div class="ci-item${c.id === conv ? ' on' : ''}" data-id="${c.id}"><div class="ci-av">${c.kind === 'GRUPO' ? '👥' : esc(ini(c.name))}</div><div class="ci-meta"><div class="ci-row"><span class="ci-name">${esc(c.name)}</span><span class="ci-time">${esc(tm(c.last_at))}</span></div><div class="ci-row"><span class="ci-prev">${esc(c.preview || (c.kind === 'GRUPO' ? 'Grupo criado' : 'Nova conversa'))}</span>${c.unread ? '<span class="ci-unread">' + c.unread + '</span>' : ''}</div></div></div>`).join('')
      || '<div class="ci-empty">' + (f ? 'Nenhuma conversa encontrada.' : 'Nenhuma conversa ainda. Clique em <b>+ Nova</b>.') + '</div>';
  }
  let lt = 0; const scheduleList = () => { clearTimeout(lt); lt = setTimeout(loadList, 250); };

  function bubble(m) {
    cache.set(m.id, m);
    let h = ''; const d = dt(m.at).toLocaleDateString('pt-BR');
    if (d !== lastDay) { lastDay = d; h += '<div class="ci-day">' + esc(d) + '</div>'; }
    if (m.system) return h + '<div class="ci-sys">' + esc(m.body) + ' · ' + esc(hm(m.at)) + '</div>';
    const mine = m.user_id === ME;
    const who = !mine && info && info.kind === 'GRUPO' ? '<div class="ci-who">' + esc(m.user || 'Usuário') + '</div>' : '';
    if (m.del) return h + `<div class="ci-b${mine ? ' me' : ''}" data-mid="${m.id}">${who}<span class="ci-del">🚫 ${mine ? 'Você apagou esta mensagem' : 'Mensagem apagada'}</span><small>${esc(hm(m.at))}</small></div>`;
    let media = '';
    if (m.media) {
      const src = 'chat_arquivo.php?id=' + m.id;
      if (/^image\//.test(m.mime)) media = '<a href="' + src + '" target="_blank" data-no-processing><img src="' + src + '" alt="imagem" loading="lazy"></a>';
      else { const pdf = /pdf/.test(m.mime); media = '<a class="ci-doc" href="' + src + (pdf ? '' : '&download=1') + '"' + (pdf ? ' target="_blank" rel="noopener"' : ' download') + ' data-no-processing>📄 <span>' + esc(m.name || 'Arquivo') + (m.size ? ' · ' + Math.max(1, Math.round(m.size / 1024)) + ' KB' : '') + '</span></a>'; }
    }
    const quote = m.reply ? '<div class="ci-q"><b>' + esc(m.reply.who || 'Mensagem') + '</b>' + esc(m.reply.text) + '</div>' : '';
    const tick = mine ? '<span class="ci-tk' + (m.read ? ' read' : '') + '" title="' + (m.read ? 'Vista' : 'Enviada') + '">' + (m.read ? '✓✓' : '✓') + '</span>' : '';
    return h + `<div class="ci-b${mine ? ' me' : ''}" data-mid="${m.id}"><button type="button" class="ci-mb" data-mid="${m.id}" aria-label="Opções">⌄</button>${who}${quote}${media}${m.body ? '<div>' + fmt(m.body) + '</div>' : ''}<small>${esc(hm(m.at))}${tick}</small></div>`;
  }
  function renderHead() {
    $('ciAv').textContent = info.kind === 'GRUPO' ? '👥' : ini(info.name);
    $('ciName').textContent = info.name;
    $('ciSub').textContent = info.kind === 'GRUPO' ? info.members.map((u) => u.id === ME ? 'Você' : u.name).join(', ') : 'Conversa direta';
    $('ciGroupEdit').style.display = info.kind === 'GRUPO' ? '' : 'none';
  }
  async function markRead() {
    if (!conv || !lastId || document.hidden) return;
    const c = items.find((x) => x.id === conv);
    if (c && !c.unread) return;
    try { await api('read', { id: conv, last_id: lastId }, true); scheduleList(); } catch (_) {}
  }
  async function openConv(id) {
    if (id !== conv) { setReply(null); clearFile(); }
    conv = id; lastId = 0; lastDay = '';
    $('ci').classList.add('open'); $('ciWelcome').style.display = 'none'; $('ciChat').style.display = 'flex';
    $('ciMsgs').innerHTML = '<div class="ci-sys">Carregando…</div>';
    renderList();
    try {
      const d = await api('messages', { id });
      if (conv !== id) return;
      info = d.info; renderHead();
      $('ciMsgs').innerHTML = d.messages.map(bubble).join('') || '<div class="ci-sys">Nenhuma mensagem ainda. Diga olá! 👋</div>';
      if (d.messages.length) lastId = d.messages[d.messages.length - 1].id;
      $('ciMsgs').scrollTop = $('ciMsgs').scrollHeight;
      markRead(); $('ciText').focus();
    } catch (e) { $('ciMsgs').innerHTML = '<div class="ci-sys">' + esc(e.message) + '</div>'; }
  }
  async function refresh() {
    if (!conv || busy) return; busy = true;
    try {
      const d = await api('messages', { id: conv, after: lastId });
      info = d.info; renderHead();
      if (d.messages.length) {
        const box = $('ciMsgs'); const bottom = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
        if (!lastId) box.innerHTML = '';
        box.insertAdjacentHTML('beforeend', d.messages.map(bubble).join(''));
        lastId = d.messages[d.messages.length - 1].id;
        if (bottom || d.messages.some((m) => m.user_id === ME)) box.scrollTop = box.scrollHeight;
        markRead();
      }
    } catch (_) {} finally { busy = false; }
  }

  // Envio
  const text = $('ciText');
  const resize = () => { text.style.height = 'auto'; text.style.height = Math.min(120, text.scrollHeight) + 'px'; };
  text.addEventListener('input', resize);
  text.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('ciForm').requestSubmit(); } });
  $('ciForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const v = text.value.trim(); if (!conv || (!v && !pending)) return;
    const f = pending, r = reply;
    text.value = ''; resize(); clearFile(); setReply(null);
    try { await api('send', r ? { id: conv, text: v, reply_to: r.id } : { id: conv, text: v }, true, f); await refresh(); scheduleList(); }
    catch (err) { text.value = v; resize(); if (f) setFile(f); fail(err); }
  });
  function setFile(f) {
    if (!f) return; if (f.size > 16 * 1024 * 1024) { alert('O arquivo deve ter no máximo 16 MB.'); return; }
    pending = f; $('ciFileName').textContent = '📎 ' + f.name; $('ciFileBar').classList.add('on'); text.focus();
  }
  function clearFile() { pending = null; $('ciFile').value = ''; $('ciFileBar').classList.remove('on'); }
  $('ciAttach').addEventListener('click', () => $('ciFile').click());
  $('ciFile').addEventListener('change', (e) => setFile(e.target.files[0] || null));
  $('ciFileX').addEventListener('click', clearFile);
  document.addEventListener('paste', (e) => {
    if (!conv || !e.clipboardData) return;
    const it = Array.from(e.clipboardData.items || []).find((i) => i.kind === 'file'); const f = it ? it.getAsFile() : null;
    if (f) { e.preventDefault(); setFile(f.name && !/^image\.\w+$/i.test(f.name) ? f : new File([f], 'print-' + Date.now() + '.png', { type: f.type })); }
  });
  const main = document.querySelector('.ci-main');
  main.addEventListener('dragover', (e) => { if (conv && e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files')) { e.preventDefault(); main.classList.add('ci-drop'); } });
  main.addEventListener('dragleave', (e) => { if (e.target === main) main.classList.remove('ci-drop'); });
  main.addEventListener('drop', (e) => { main.classList.remove('ci-drop'); const f = e.dataTransfer && e.dataTransfer.files[0]; if (f) { e.preventDefault(); setFile(f); } });

  // Menu da mensagem: responder / apagar (só as próprias).
  const menu = $('ciMenu'); let target = null;
  const closeMenu = () => { menu.classList.remove('on'); target = null; };
  function setReply(m) {
    reply = m || null; $('ciReplyBar').classList.toggle('on', !!m);
    if (m) { $('ciReplyQ').innerHTML = '<b>Respondendo a ' + esc(m.user_id === ME ? 'você' : m.user) + '</b>' + esc((m.body || m.name || '').slice(0, 160)); text.focus(); }
  }
  $('ciReplyX').addEventListener('click', () => setReply(null));
  $('ciMsgs').addEventListener('click', (e) => {
    const b = e.target.closest('.ci-mb'); if (!b) return;
    e.stopPropagation(); const m = cache.get(Number(b.dataset.mid)); if (!m) return;
    target = m;
    menu.innerHTML = '<button type="button" data-a="reply">↩️ Responder</button>' + (m.user_id === ME ? '<button type="button" class="r" data-a="delete">🗑️ Apagar</button>' : '');
    menu.classList.add('on');
    menu.style.left = '0px'; menu.style.top = '0px';
    const o = menu.getBoundingClientRect(), sc = menu.offsetWidth > 0 ? o.width / menu.offsetWidth : 1, r = b.getBoundingClientRect();
    const left = Math.max(6, Math.min(r.right - o.width, innerWidth - o.width - 6)), top = r.bottom + o.height + 6 < innerHeight ? r.bottom + 4 : Math.max(6, r.top - o.height - 4);
    menu.style.left = ((left - o.left) / sc) + 'px'; menu.style.top = ((top - o.top) / sc) + 'px';
  });
  document.addEventListener('mousedown', (e) => { if (menu.classList.contains('on') && !menu.contains(e.target) && !e.target.closest('.ci-mb')) closeMenu(); });
  menu.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-a]'); if (!b || !target) return; const m = target; closeMenu();
    if (b.dataset.a === 'reply') setReply(m);
    else if (confirm('Apagar esta mensagem para todos?')) { try { await api('delete', { id: conv, message_id: m.id }, true); openConv(conv); scheduleList(); } catch (err) { fail(err); } }
  });

  // Nova conversa / grupo
  let users = null, mode = 'pessoa', editing = 0; const picked = new Set();
  const modal = $('ciNewModal');
  async function loadUsers() { if (!users) users = (await api('users')).items || []; return users; }
  function renderPeople() {
    const f = $('ciPSearch').value.trim().toLowerCase();
    const list = (users || []).filter((u) => !f || u.name.toLowerCase().includes(f));
    $('ciPeople').innerHTML = list.map((u) => mode === 'pessoa'
      ? '<button type="button" data-u="' + u.id + '"><span class="ci-av" style="width:32px;height:32px;flex-basis:32px">' + esc(ini(u.name)) + '</span>' + esc(u.name) + '</button>'
      : '<label><input type="checkbox" value="' + u.id + '"' + (picked.has(u.id) ? ' checked' : '') + '> ' + esc(u.name) + '</label>').join('') || '<div class="ci-empty">Nenhuma pessoa encontrada.</div>';
  }
  function setMode(m) {
    mode = m;
    $('ciNewTabs').querySelectorAll('button').forEach((b) => b.classList.toggle('on', b.dataset.t === m));
    $('ciGroupName').style.display = m === 'grupo' ? '' : 'none';
    $('ciGroupGo').style.display = m === 'grupo' ? '' : 'none';
    renderPeople();
  }
  async function openNew(groupInfo = null) {
    try { await loadUsers(); } catch (e) { fail(e); return; }
    picked.clear(); $('ciPSearch').value = ''; editing = groupInfo ? groupInfo.id : 0;
    $('ciNewTitle').textContent = groupInfo ? 'Grupo: ' + groupInfo.name : 'Nova conversa';
    $('ciNewTabs').style.display = groupInfo ? 'none' : '';
    $('ciLeave').style.display = groupInfo ? '' : 'none';
    $('ciGName').value = groupInfo ? groupInfo.name : '';
    if (groupInfo) groupInfo.members.forEach((u) => { if (u.id !== ME) picked.add(u.id); });
    const canEdit = !groupInfo || groupInfo.admin;
    $('ciGName').disabled = !canEdit;
    setMode(groupInfo ? 'grupo' : 'pessoa');
    $('ciGroupGo').style.display = canEdit ? '' : 'none';
    if (!canEdit) $('ciPeople').querySelectorAll('input').forEach((i) => { i.disabled = true; });
    modal.classList.add('on');
  }
  $('ciNew').addEventListener('click', () => openNew());
  $('ciGroupEdit').addEventListener('click', () => { if (info) openNew(info); });
  $('ciNewTabs').addEventListener('click', (e) => { const b = e.target.closest('[data-t]'); if (b) setMode(b.dataset.t); });
  $('ciPSearch').addEventListener('input', renderPeople);
  $('ciPeople').addEventListener('change', (e) => { const i = e.target.closest('input'); if (i) { if (i.checked) picked.add(Number(i.value)); else picked.delete(Number(i.value)); } });
  $('ciPeople').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-u]'); if (!b) return;
    try { const d = await api('open_direct', { user_id: b.dataset.u }, true); modal.classList.remove('on'); await loadList(); openConv(d.id); } catch (err) { fail(err); }
  });
  $('ciGroupGo').addEventListener('click', async () => {
    try { const d = await api('group_save', { id: editing, name: $('ciGName').value, members: Array.from(picked).join(',') }, true); modal.classList.remove('on'); await loadList(); openConv(d.id); } catch (err) { fail(err); }
  });
  $('ciLeave').addEventListener('click', async () => {
    if (!editing || !confirm('Sair deste grupo? Você deixa de receber as mensagens.')) return;
    try { await api('group_leave', { id: editing }, true); modal.classList.remove('on'); conv = 0; $('ci').classList.remove('open'); $('ciChat').style.display = 'none'; $('ciWelcome').style.display = ''; loadList(); } catch (err) { fail(err); }
  });
  modal.addEventListener('click', (e) => { if (e.target === modal || e.target.hasAttribute('data-close')) modal.classList.remove('on'); });

  $('ciList').addEventListener('click', (e) => { const it = e.target.closest('.ci-item'); if (it) openConv(Number(it.dataset.id)); });
  $('ciBack').addEventListener('click', () => { conv = 0; $('ci').classList.remove('open'); renderList(); });
  $('ciSearch').addEventListener('input', (e) => { q = e.target.value; renderList(); });

  // Tempo real: o servidor avisa (só ids); a lista e a conversa são buscadas pela API.
  function connect(attempt = 0) {
    if (!RT.enabled) return;
    let ws;
    try { ws = new WebSocket(RT.url + (RT.url.includes('?') ? '&' : '?') + 'token=' + encodeURIComponent(RT.token)); } catch (_) { setTimeout(() => connect(attempt + 1), Math.min(30000, 1000 * 2 ** Math.min(attempt, 5))); return; }
    ws.addEventListener('open', () => { attempt = 0; loadList(); if (conv) refresh(); });
    ws.addEventListener('message', (ev) => {
      let m; try { m = JSON.parse(ev.data); } catch (_) { return; }
      if (!m || m.eventType !== 'chat.message' || m.replay) return;
      const p = m.payload || {};
      if (!Array.isArray(p.members) || !p.members.map(Number).includes(ME)) return;
      scheduleList();
      if (Number(m.entityId) === conv) { if (p.what === 'deleted' || p.what === 'read') openConv(conv); else refresh(); }
    });
    ws.addEventListener('close', () => setTimeout(() => connect(attempt + 1), Math.min(30000, 1000 * 2 ** Math.min(attempt + 1, 5))));
    ws.addEventListener('error', () => { try { ws.close(); } catch (_) {} });
  }
  // Na janelinha do balão, Esc fecha (quando nenhuma janela do chat estiver aberta).
  if (EMBED) document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (modal.classList.contains('on')) { modal.classList.remove('on'); return; }
    if (menu.classList.contains('on')) { closeMenu(); return; }
    try { parent.postMessage({ nexaChat: 'close' }, location.origin); } catch (_) {}
  });
  window.addEventListener('focus', () => { loadList(); if (conv) { refresh(); markRead(); } });
  document.addEventListener('visibilitychange', () => { if (!document.hidden && conv) markRead(); });
  const start = Number(new URLSearchParams(location.search).get('c') || 0);
  loadList().then(() => { if (start) openConv(start); });
  connect();
})();
</script>
<?php endif; ?>
<?php if (!$embed): ?>
<script>
// A tela ocupa toda a altura que sobra na janela, em qualquer resolução e zoom
// (o painel usa zoom de 80% em notebooks 1366x768): mede a escala real e ajusta.
(function () {
  const box = document.getElementById('ci'); if (!box) return;
  const fit = () => {
    if (document.fullscreenElement) { box.style.height = ''; return; }
    const want = window.innerHeight - 12;
    let css = parseFloat(box.style.height) || box.offsetHeight;
    for (let i = 0; i < 3; i++) {
      const r = box.getBoundingClientRect();
      const scale = r.height > 0 && css > 0 ? r.height / css : 1;
      const diff = want - (r.top + r.height);
      if (Math.abs(diff) < 1.5) break;
      css = Math.max(420, css + diff / scale);
      box.style.height = Math.floor(css) + 'px';
    }
  };
  fit();
  window.addEventListener('resize', fit);
  document.addEventListener('fullscreenchange', () => setTimeout(fit, 50));
})();
</script>
<?php endif; ?>
<?php if ($embed) { echo '</body></html>'; } else { admin_footer(); } ?>

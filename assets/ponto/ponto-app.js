/* Nexa Ponto — funções comuns do Bater Ponto (celular/computador) e do terminal.
 * O relógio mostra a hora do SERVIDOR (a que vale no registro): a página recebe a hora do
 * servidor e só conta os segundos aqui; não consulta o servidor de tempos em tempos. */
(function () {
  'use strict';
  var P = window.NexaPonto = window.NexaPonto || {};
  P.offset = 0;

  P.sync = function (serverMs) {
    if (serverMs) P.offset = Number(serverMs) - Date.now();
  };
  P.now = function () { return new Date(Date.now() + P.offset); };

  P.startClock = function (elTime, elDate, tz) {
    var ft, fd;
    try {
      ft = new Intl.DateTimeFormat('pt-BR', { timeZone: tz, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
      fd = new Intl.DateTimeFormat('pt-BR', { timeZone: tz, weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
    } catch (e) {
      ft = new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
      fd = new Intl.DateTimeFormat('pt-BR', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
    }
    function tick() {
      var d = P.now();
      if (elTime) elTime.textContent = ft.format(d);
      if (elDate) { var ds = fd.format(d); elDate.textContent = ds.charAt(0).toUpperCase() + ds.slice(1); }
      setTimeout(tick, 1005 - (d.getTime() % 1000));
    }
    tick();
  };

  P.uuid = function () {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    var b = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(b);
    b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
    var h = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  };

  // Localização: pedida uma vez ao abrir (e de novo antes de registrar, se ainda não veio).
  P.geo = { lat: null, lng: null, acc: null, status: 'pendente', msg: 'Obtendo localização…' };
  P.locate = function (cb, force) {
    var g = P.geo;
    if (!force && g.status === 'ok' && g.at && Date.now() - g.at < 120000) { if (cb) cb(g); return; }
    if (!navigator.geolocation) { g.status = 'indisponivel'; g.msg = 'Este aparelho não informa a localização.'; if (cb) cb(g); return; }
    g.status = 'pendente'; g.msg = 'Obtendo localização…';
    if (cb) cb(g);
    navigator.geolocation.getCurrentPosition(function (pos) {
      g.lat = pos.coords.latitude; g.lng = pos.coords.longitude; g.acc = Math.round(pos.coords.accuracy || 0);
      g.status = 'ok'; g.at = Date.now(); g.msg = 'Localização obtida (precisão de ' + g.acc + ' m)';
      if (cb) cb(g);
    }, function (err) {
      g.status = 'indisponivel'; g.lat = g.lng = g.acc = null;
      g.msg = err && err.code === 1 ? 'Localização bloqueada no navegador. Permita o acesso à localização para este site.' : 'Não foi possível obter a localização.';
      if (cb) cb(g);
    }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
  };

  P.post = function (url, data) {
    var fd = new FormData();
    Object.keys(data || {}).forEach(function (k) { if (data[k] !== null && data[k] !== undefined) fd.append(k, data[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.now_ms) P.sync(j.now_ms); return j; })
      .catch(function () { return { ok: false, erro: 'Sem conexão com o servidor. O ponto NÃO foi registrado: confira a internet e tente de novo.' }; });
  };

  P.esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  };

  P.beep = function (ok) {
    try {
      var A = window.AudioContext || window.webkitAudioContext; if (!A) return;
      var c = P._ac || (P._ac = new A()), o = c.createOscillator(), g = c.createGain();
      o.frequency.value = ok ? 880 : 220; g.gain.value = 0.08; o.connect(g); g.connect(c.destination);
      o.start(); o.stop(c.currentTime + (ok ? 0.15 : 0.35));
    } catch (e) { /* sem som */ }
  };
})();

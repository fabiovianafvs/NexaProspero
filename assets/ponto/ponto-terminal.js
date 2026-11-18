/* Nexa Ponto — terminal (tablet/computador da empresa).
 * Fluxo: identificar (matrícula/CPF + PIN, ou cartão) → conferir nome e tipo → registrar → comprovante (QR). */
(function () {
  'use strict';
  var P = window.NexaPonto, T = window.TM || {};
  var $ = function (id) { return document.getElementById(id); };
  P.sync(T.now);
  P.startClock($('paTime'), $('paDate'), T.tz);
  if ($('tmDev')) $('tmDev').textContent = T.dev || '';
  P.locate(null);

  var login = $('tmLogin'), pin = $('tmPin'), form = $('tmForm'), err = $('tmErr');
  var state = { ticket: null, busy: false, uuid: null, timer: null, field: login };

  function showErr(msg) { err.textContent = msg; err.hidden = !msg; if (msg) P.beep(false); }
  function resetIdent() {
    clearTimeout(state.timer); clearInterval(state.count);
    state.ticket = null; state.busy = false;
    if (login) { login.value = ''; pin.value = ''; state.field = login; }
    $('tmConf').hidden = true; $('tmIdent').hidden = false; $('paDone').hidden = true;
    if (login) setTimeout(function () { login.focus(); }, 30);
  }
  function idleTimeout(sec) { clearTimeout(state.timer); state.timer = setTimeout(resetIdent, sec * 1000); }

  function identificar(data) {
    if (state.busy) return;
    state.busy = true; showErr('');
    data.action = 'identificar'; data.ft = T.ft;
    P.post('/ponto.php', data).then(function (r) {
      state.busy = false;
      if (!r.ok) { showErr(r.erro || 'Não identificado.'); if (pin) { pin.value = ''; } idleTimeout(30); return; }
      state.ticket = r.ticket; state.uuid = P.uuid();
      $('tmNome').textContent = r.nome;
      $('tmMat').textContent = 'Matrícula ' + r.matricula;
      var sit = $('tmSit'); sit.textContent = r.situacao_label;
      sit.className = 'pa-badge ' + ({ TRABALHANDO: 'g', INTERVALO: 'y', ENCERROU: 'n' }[r.situacao] || '');
      var ph = $('tmPh');
      ph.innerHTML = r.foto ? '<img class="pa-ph" alt="" src="' + P.esc(r.foto) + '">' : P.esc((r.nome || '?').charAt(0).toUpperCase());
      $('tmHoje').innerHTML = r.hoje.length ? r.hoje.map(function (m) { return '<div class="pa-mark"><b>' + P.esc(m.hora) + '</b><small>' + P.esc(m.tipo) + '</small></div>'; }).join('') : '<span class="pa-empty">Nenhum registro hoje.</span>';
      var main = $('tmMain');
      main.dataset.int = r.proximo;
      main.innerHTML = 'Registrar ' + P.esc(T.labels[r.proximo].toLowerCase()) + '<small>toque para confirmar</small>';
      $('tmAlts').innerHTML = Object.keys(T.labels).filter(function (k) { return k !== r.proximo; })
        .map(function (k) { return '<button type="button" class="pa-alt" data-int="' + k + '">' + P.esc(T.labels[k]) + '</button>'; }).join('');
      $('tmIdent').hidden = true; $('tmConf').hidden = false;
      main.disabled = false; main.focus();
      idleTimeout(30);
    });
  }

  function registrar(intencao) {
    if (state.busy || !state.ticket) return;
    if (window.NexaFace && NexaFace.ativo()) {
      state.busy = true; clearTimeout(state.timer);
      NexaFace.capturar({ sub: $('tmNome').textContent + ': centralize o rosto e toque no botão.' }).then(function (f) {
        state.busy = false;
        if (f) enviar(intencao, f); else idleTimeout(30);
      });
      return;
    }
    enviar(intencao, null);
  }

  function enviar(intencao, f) {
    state.busy = true; clearTimeout(state.timer);
    document.querySelectorAll('#tmConf [data-int]').forEach(function (b) { b.disabled = true; });
    var g = P.geo;
    P.post('/ponto.php', { action: 'registrar', ft: T.ft, ticket: state.ticket, intencao: intencao, uuid: state.uuid, lat: g.lat, lng: g.lng, acc: g.acc, foto: f && f.foto, descritor: f && f.descritor }).then(function (r) {
      state.busy = false;
      document.querySelectorAll('#tmConf [data-int]').forEach(function (b) { b.disabled = false; });
      if (!r.ok) { P.beep(false); alert(r.erro || 'Não foi possível registrar.'); if (/expirada/i.test(r.erro || '')) resetIdent(); else idleTimeout(30); return; }
      P.beep(true);
      $('dNome').textContent = r.nome; $('dHora').textContent = r.hora; $('dTipo').textContent = r.intencao;
      $('dData').textContent = r.data; $('dNsr').textContent = r.nsr;
      $('dFacT').hidden = $('dFac').hidden = !r.facial; $('dFac').textContent = r.facial || '';
      $('dQr').innerHTML = r.qr || '<a href="' + P.esc(r.comprovante) + '" target="_blank" rel="noopener">Abrir comprovante</a>';
      $('paDone').hidden = false;
      var n = 15; $('dCount').textContent = n;
      state.count = setInterval(function () { n--; $('dCount').textContent = n; if (n <= 0) resetIdent(); }, 1000);
    });
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('#tmConf [data-int]');
    if (b) registrar(b.dataset.int);
  });
  $('tmCancel').addEventListener('click', resetIdent);
  $('dOk').addEventListener('click', resetIdent);

  if (form) {
    login.addEventListener('focus', function () { state.field = login; });
    pin.addEventListener('focus', function () { state.field = pin; });
    $('tmPad').addEventListener('click', function (e) {
      var b = e.target.closest('button[data-k]'); if (!b) return;
      var f = state.field || login;
      if (b.dataset.k === 'C') { login.value = ''; pin.value = ''; state.field = login; login.focus(); showErr(''); return; }
      if (f.value.length < Number(f.maxLength || 20)) f.value += b.dataset.k;
      f.focus();
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (state.field === login || !pin.value) { if (login.value) { state.field = pin; pin.focus(); } return; }
      identificar({ login: login.value, pin: pin.value });
    });
    login.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); if (login.value && !isCard()) { state.field = pin; pin.focus(); } } });
  }

  // Leitor de cartão (tipo teclado): digita muito rápido e termina com Enter.
  var buf = [], times = [];
  function isCard() { return buf.length >= 4 && times[times.length - 1] - times[0] < 400; }
  document.addEventListener('keydown', function (e) {
    if (!$('tmIdent') || $('tmIdent').hidden) return;
    var now = Date.now();
    if (times.length && now - times[times.length - 1] > 120) { buf = []; times = []; }
    if (e.key === 'Enter') {
      if (T.rfid && isCard()) {
        e.preventDefault(); e.stopPropagation();
        var code = buf.join('');
        if (login) { login.value = ''; pin.value = ''; }
        buf = []; times = [];
        identificar({ rfid: code });
        return;
      }
      buf = []; times = [];
      return;
    }
    if (e.key && e.key.length === 1 && /[0-9A-Za-z]/.test(e.key)) { buf.push(e.key); times.push(now); }
  }, true);

  resetIdent();
})();

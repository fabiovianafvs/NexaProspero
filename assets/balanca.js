(function(window){
  'use strict';

  const states = new WeakMap();

  function stateFor(cfg){
    if (!cfg || typeof cfg !== 'object') cfg = {};
    if (!states.has(cfg)) {
      states.set(cfg, {port:null, reader:null, writer:null, decoder:new TextDecoder(), buffer:'', lastWeight:null, lastStatus:'Aguardando balança', reading:false, waiters:[]});
    }
    return states.get(cfg);
  }

  function isEnabled(cfg){ return !!(cfg && cfg.enabled && cfg.connection !== 'manual'); }
  function isSupported(){ return !!(navigator.serial && window.TextDecoder); }
  function moneyWeight(v){ return Number(v || 0).toLocaleString('pt-BR', {minimumFractionDigits:3, maximumFractionDigits:3}) + ' kg'; }
  function emitStatus(st, msg){
    st.lastStatus = msg;
    window.dispatchEvent(new CustomEvent('nexa-scale-status', {detail:{message:msg, weight:st.lastWeight}}));
  }
  function makeAppendable(node){
    // Compatibilidade: algumas telas antigas chamavam buildControls(...).forEach(...),
    // enquanto outras usam appendChild direto.
    try { node.forEach = function(cb){ cb(node); }; } catch(e) {}
    return node;
  }
  function resolveWaiters(st, sample){
    st.waiters = st.waiters.filter(w => {
      if (sample && sample.ok) { w.resolve(sample.weight); return false; }
      return true;
    });
  }
  function statusFromPayload(payload){
    const p = String(payload || '').toUpperCase();
    if (/^I+$/.test(p)) return {ok:false, reason:'Peso instável'};
    if (/^N+$/.test(p)) return {ok:false, reason:'Peso negativo'};
    if (/^S+$/.test(p)) return {ok:false, reason:'Peso acima da capacidade'};
    return null;
  }
  function parseDecimalText(text){
    const m = String(text || '').match(/[-+]?\d+(?:[\.,]\d+)?/);
    if (!m) return null;
    const n = Number(m[0].replace(',', '.'));
    return Number.isFinite(n) ? n : null;
  }
  function parseUranoUran12(payload){
    const clean = String(payload || '').replace(/[\x00-\x1F]+/g, ' ').replace(/\s+/g, ' ').trim();
    const m = clean.match(/PESO\s*:\s*([-+]?\d+(?:[\.,]\d+)?)\s*(kg|g)?/i);
    if (!m) return null;
    let n = Number(String(m[1]).replace(',', '.'));
    if (!Number.isFinite(n)) return null;
    if (String(m[2] || '').toLowerCase() === 'g') n = n / 1000;
    return {ok:true, weight:Math.max(0, n), raw:payload};
  }
  function parsePayload(payload, cfg){
    payload = String(payload || '').trim();
    if (!payload) return null;
    if (String(cfg.model || '') === 'urano_udc_coe' || String(cfg.protocol || '').indexOf('urano') === 0) {
      const urano12 = parseUranoUran12(payload);
      if (urano12) return urano12;
    }
    const status = statusFromPayload(payload);
    if (status) return status;
    const places = Math.max(0, Math.min(4, Number(cfg.decimalPlaces ?? 3)));
    const hasDecimalSeparator = /[-+]?\d+[\.,]\d+/.test(payload);
    if (hasDecimalSeparator) {
      const explicit = parseDecimalText(payload);
      if (explicit !== null) return {ok:true, weight:Math.max(0, explicit), raw:payload};
    }
    const digits = payload.replace(/[^0-9-]/g, '');
    if (!digits || /^-+$/.test(digits)) return null;
    const compact = digits.replace('-', '');
    const n = Number(digits);
    if (!Number.isFinite(n)) return null;
    if (compact.length >= 4 && compact.length <= 6) {
      return {ok:true, weight:Math.max(0, n / Math.pow(10, places)), raw:payload};
    }
    return {ok:true, weight:Math.max(0, n), raw:payload};
  }
  function parseChunks(st, cfg, text){
    st.buffer += String(text || '');
    const samples = [];

    // Pacotes Toledo: STX ... ETX ou STX ... CR.
    let safety = 0;
    while (safety++ < 20) {
      const start = st.buffer.indexOf('\x02');
      if (start < 0) break;
      let end = st.buffer.indexOf('\x03', start + 1);
      let cr = st.buffer.indexOf('\r', start + 1);
      if (end < 0 || (cr >= 0 && cr < end)) end = cr;
      if (end < 0) break;
      const payload = st.buffer.slice(start + 1, end);
      st.buffer = st.buffer.slice(end + 1);
      const parsed = parsePayload(payload, cfg);
      if (parsed) samples.push(parsed);
    }

    // Urano UDC CO-E / Uran12 pode chegar sem CR/LF. Aguarda a unidade para
    // considerar o campo PESO completo e não interpretar um pacote parcial.
    if (String(cfg.model || '') === 'urano_udc_coe' || String(cfg.protocol || '').indexOf('urano') === 0) {
      const um = st.buffer.match(/PESO\s*:\s*[-+]?\d+(?:[\.,]\d+)?\s*(?:kg|g)/i);
      if (um) {
        const parsed = parseUranoUran12(um[0]);
        if (parsed) samples.push(parsed);
        st.buffer = st.buffer.slice((um.index || 0) + um[0].length);
      }
    }

    // Protocolos contínuos com CR/LF ou texto simples.
    const parts = st.buffer.split(/[\r\n]+/);
    st.buffer = parts.pop() || '';
    parts.forEach(line => {
      const parsed = parsePayload(line, cfg);
      if (parsed) samples.push(parsed);
    });

    // Evita buffer infinito quando chega texto sem quebra.
    if (st.buffer.length > 120) {
      const parsed = parsePayload(st.buffer, cfg);
      if (parsed) samples.push(parsed);
      st.buffer = '';
    }

    samples.forEach(sample => {
      if (!sample.ok) {
        emitStatus(st, sample.reason || 'Peso não disponível');
        return;
      }
      const min = Number(cfg.minWeight ?? 0);
      if (Number(sample.weight) < min) {
        emitStatus(st, 'Peso abaixo do mínimo configurado');
        return;
      }
      st.lastWeight = Number(sample.weight);
      emitStatus(st, 'Peso lido: ' + moneyWeight(st.lastWeight));
      resolveWaiters(st, sample);
    });
  }
  async function requestIfNeeded(st, cfg){
    if (!st.port || String(cfg.requestMode || 'enq') !== 'enq') return;
    try {
      const writer = st.writer || st.port.writable.getWriter();
      st.writer = writer;
      // ENQ (05h). A Urano UDC CO-E aceita solicitação de peso por 04h ou 05h;
      // usamos 05h para manter compatibilidade com o fluxo já existente.
      await writer.write(new Uint8Array([5]));
    } catch (e) {
      emitStatus(st, 'Não foi possível solicitar peso da balança.');
    }
  }
  async function readLoop(st, cfg){
    if (!st.port || st.reading || !st.port.readable) return;
    st.reading = true;
    try {
      st.reader = st.port.readable.getReader();
      while (true) {
        const {value, done} = await st.reader.read();
        if (done) break;
        if (value) parseChunks(st, cfg, st.decoder.decode(value, {stream:true}));
      }
    } catch (e) {
      emitStatus(st, 'Leitura da balança interrompida.');
    } finally {
      try { st.reader && st.reader.releaseLock(); } catch(e) {}
      st.reader = null;
      st.reading = false;
    }
  }
  async function connect(cfg){
    const st = stateFor(cfg);
    if (!isEnabled(cfg)) throw new Error('Integração com balança desabilitada nas configurações.');
    if (!isSupported()) throw new Error('Este navegador não liberou Web Serial. Use Chrome/Edge em HTTPS ou informe a quantidade manualmente.');
    if (st.port) return st;
    try {
      const granted = await navigator.serial.getPorts();
      if (granted && granted.length) st.port = granted[0];
    } catch(e) {}
    if (!st.port) {
      emitStatus(st, 'Solicitando porta da balança...');
      st.port = await navigator.serial.requestPort();
    }
    try {
      await st.port.open({
        baudRate: Number(cfg.baudRate || 2400),
        dataBits: Number(cfg.dataBits || 8),
        parity: String(cfg.parity || 'none'),
        stopBits: Number(cfg.stopBits || 1),
        flowControl: 'none'
      });
    } catch (e) {
      st.port = null;
      throw e;
    }
    emitStatus(st, 'Balança conectada.');
    readLoop(st, cfg);
    return st;
  }
  async function readWeight(cfg, options){
    options = options || {};
    const st = await connect(cfg);
    if (options.fresh) st.lastWeight = null;
    await requestIfNeeded(st, cfg);
    if (!options.fresh && Number(st.lastWeight || 0) >= Number(cfg.minWeight || 0)) return st.lastWeight;
    const timeout = Math.max(500, Math.min(10000, Number(cfg.timeoutMs || 2500)));
    return await new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        st.waiters = st.waiters.filter(w => w.resolve !== resolve);
        reject(new Error('Não foi possível ler o peso no tempo esperado. Verifique porta, protocolo e peso estável.'));
      }, timeout);
      st.waiters.push({resolve:(w)=>{ clearTimeout(timer); resolve(w); }, reject});
      requestIfNeeded(st, cfg);
    });
  }
  function applyToInput(input, weight){
    if (!input) return;
    input.value = Number(weight || 0).toLocaleString('pt-BR', {minimumFractionDigits:3, maximumFractionDigits:3});
    input.dispatchEvent(new Event('input', {bubbles:true}));
    input.dispatchEvent(new Event('change', {bubbles:true}));
  }
  function buildControls(cfg, targetInput, options){
    options = options || {};
    const wrap = document.createElement('div');
    wrap.className = options.className || 'scale-control-box';
    const label = options.label || 'Balança';
    wrap.innerHTML = '<strong>'+label+'</strong><div class="scale-status" data-scale-status>Balança: aguardando</div><div class="scale-actions"><button type="button" class="btn" data-no-processing data-scale-connect>Conectar balança</button><button type="button" class="btn btn-primary" data-no-processing data-scale-read>Ler peso</button></div>';
    const status = wrap.querySelector('[data-scale-status]');
    const setStatus = (msg) => { status.textContent = msg; };
    if (!isEnabled(cfg)) {
      setStatus('Balança desabilitada nas configurações. Informe a quantidade manualmente.');
      wrap.querySelector('[data-scale-connect]').disabled = true;
      wrap.querySelector('[data-scale-read]').disabled = true;
      return makeAppendable(wrap);
    }
    if (!isSupported()) {
      setStatus('Web Serial indisponível neste navegador. Use Chrome/Edge em HTTPS ou informe manualmente.');
      wrap.querySelector('[data-scale-connect]').disabled = true;
      wrap.querySelector('[data-scale-read]').disabled = true;
      return makeAppendable(wrap);
    }
    wrap.querySelector('[data-scale-connect]').addEventListener('click', async () => {
      try { await connect(cfg); setStatus('Balança conectada.'); } catch(e) { setStatus(e.message || String(e)); }
    });
    wrap.querySelector('[data-scale-read]').addEventListener('click', async () => {
      try { setStatus('Lendo peso...'); const w = await readWeight(cfg, {fresh:true}); if (cfg.autoFillQuantity !== false) { applyToInput(targetInput, w); setStatus('Peso aplicado: ' + moneyWeight(w)); } else { setStatus('Peso lido: ' + moneyWeight(w)); } }
      catch(e) { setStatus(e.message || String(e)); }
    });
    window.addEventListener('nexa-scale-status', e => { if(e.detail && e.detail.message) setStatus(e.detail.message); });
    return makeAppendable(wrap);
  }

  window.NexaScale = {isEnabled, isSupported, connect, readWeight, applyToInput, buildControls, moneyWeight};
})(window);

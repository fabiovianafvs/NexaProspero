'use strict';

const express = require('express');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const QRCode = require('qrcode');
const { Client, LocalAuth, MessageMedia } = require('whatsapp-web.js');

const app = express();
const port = Number(process.env.PORT || process.env.WHATSAPP_PORT || 3333);
const host = process.env.HOST || process.env.WHATSAPP_HOST || '127.0.0.1';
const authPath = process.env.WHATSAPP_AUTH_PATH || path.join(__dirname, '.wwebjs_auth');
const tokensFile = process.env.WHATSAPP_TOKENS_FILE || path.join(__dirname, 'tokens.json');
const globalToken = String(process.env.WHATSAPP_API_TOKEN || '').trim();
const maxDocumentBytes = 8 * 1024 * 1024;
const whatsappWebJsVersion = (() => {
  try { return require('whatsapp-web.js/package.json').version || ''; } catch (_) { return ''; }
})();
const mediaWidCompatPatched = (() => {
  try {
    const baseFile = require.resolve('whatsapp-web.js/src/structures/Base');
    return fs.readFileSync(baseFile, 'utf8').includes('static _normalizeId(id)');
  } catch (_) { return false; }
})();
const mediaPrivateIdFixPatched = (() => {
  try {
    const utilsFile = require.resolve('whatsapp-web.js/src/util/Injected/Utils');
    return fs.readFileSync(utilsFile, 'utf8').includes('NEXA-R8: remove MediaData private id');
  } catch (_) { return false; }
})();

app.disable('x-powered-by');
app.use(express.json({ limit: '20mb' }));

/** @type {Map<string, {client: Client, qr: string, ready: boolean, authenticated: boolean, initializing: boolean, lastError: string, updatedAt: string}>} */
const sessions = new Map();
const recoveryLocks = new Map();
let companyTokens = loadTokens();

function nowIso() {
  return new Date().toISOString();
}

function safeCompanyId(value) {
  const id = String(value ?? '').replace(/[^0-9A-Za-z_-]+/g, '');
  if (!id || id.length > 64) throw new Error('company_id inválido.');
  return id;
}

function digits(value) {
  return String(value ?? '').replace(/\D+/g, '');
}

function normalizePhone(value) {
  let number = digits(value);
  if ((number.length === 10 || number.length === 11) && !number.startsWith('55')) {
    number = `55${number}`;
  }
  return number;
}

function loadTokens() {
  try {
    if (!fs.existsSync(tokensFile)) return {};
    const parsed = JSON.parse(fs.readFileSync(tokensFile, 'utf8'));
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
  } catch (error) {
    console.error('[tokens] Falha ao carregar tokens.json:', error.message);
    return {};
  }
}

function saveTokens() {
  const temp = `${tokensFile}.tmp`;
  fs.writeFileSync(temp, JSON.stringify(companyTokens, null, 2), { mode: 0o600 });
  fs.renameSync(temp, tokensFile);
  try { fs.chmodSync(tokensFile, 0o600); } catch (_) {}
}

function constantEquals(a, b) {
  const left = Buffer.from(String(a || ''));
  const right = Buffer.from(String(b || ''));
  if (!left.length || left.length !== right.length) return false;
  return crypto.timingSafeEqual(left, right);
}

function requestIsLoopback(req) {
  const ip = String(req.ip || req.socket?.remoteAddress || '');
  return ip === '127.0.0.1' || ip === '::1' || ip.endsWith('::ffff:127.0.0.1');
}

function tokenFromRequest(req) {
  return String(req.get('X-API-Token') || req.get('Authorization') || '')
    .replace(/^Bearer\s+/i, '')
    .trim();
}

function authorize(req, companyId, options = {}) {
  const provided = tokenFromRequest(req);
  const companyToken = String(companyTokens[companyId] || '').trim();

  if (globalToken && constantEquals(provided, globalToken)) return true;
  if (companyToken && constantEquals(provided, companyToken)) return true;

  if (options.allowAnyRegisteredToken && provided) {
    for (const token of Object.values(companyTokens)) {
      if (token && constantEquals(provided, token)) return true;
    }
  }

  // Instalações antigas podem funcionar somente em 127.0.0.1 e sem token global.
  // Mantemos essa compatibilidade apenas quando não existe token cadastrado.
  if (!globalToken && !companyToken && requestIsLoopback(req)) return true;
  return false;
}

function isHardBrowserError(value) {
  const text = String(value || '').toLowerCase();
  return text.includes('detached frame')
    || text.includes('execution context was destroyed')
    || text.includes('cannot find context with specified id')
    || text.includes('target closed')
    || text.includes('session closed')
    || text.includes('most likely the page has been closed');
}

function isMediaWidCompatibilityError(value) {
  const text = String(value || '');
  return /Data passed to getter must include an id property|it's how we memoize|\bt:\s*t\b|\br:\s*r\b/i.test(text);
}

function isRecoverableWWebJsError(value) {
  const text = String(value || '');
  return /getChat|WWebJS|detached frame|execution context|target closed|cannot read properties of undefined|cannot find context/i.test(text);
}


function setStateError(state, error) {
  if (!state) return;
  state.lastError = String(error?.message || error || 'Falha no WhatsApp Web.');
  state.updatedAt = nowIso();
}

function clearStateError(state) {
  if (!state) return;
  state.lastError = '';
  state.updatedAt = nowIso();
}

function authorizeAdmin(req) {
  if (requestIsLoopback(req)) return true;
  const provided = tokenFromRequest(req);
  return Boolean(globalToken && constantEquals(provided, globalToken));
}

function storedCompanyIds() {
  const ids = new Set();
  try {
    for (const name of fs.readdirSync(authPath, { withFileTypes: true })) {
      if (!name.isDirectory()) continue;
      const match = /^session-company-(.+)$/.exec(name.name);
      if (!match) continue;
      try { ids.add(safeCompanyId(match[1])); } catch (_) {}
    }
  } catch (_) {}
  return ids;
}

function statePayload(companyId, state) {
  const lastError = state?.lastError || '';
  const degraded = Boolean(state?.ready) && isHardBrowserError(lastError);
  const connected = Boolean(state?.ready) && !degraded;
  return {
    ok: true,
    company_id: companyId,
    connected,
    ready: connected,
    authenticated: Boolean(state?.authenticated),
    initializing: Boolean(state?.initializing),
    status: degraded ? 'degraded' : (connected ? 'connected' : (state?.qr ? 'qr' : (state?.initializing ? 'initializing' : 'disconnected'))),
    qr: state?.qr || '',
    lastError,
    recovery_mode: 'on_demand',
    updatedAt: state?.updatedAt || nowIso(),
  };
}

function buildClient(companyId) {
  const state = {
    client: null,
    qr: '',
    ready: false,
    authenticated: false,
    initializing: true,
    lastError: '',
    updatedAt: nowIso(),
  };

  const client = new Client({
    authStrategy: new LocalAuth({
      clientId: `company-${companyId}`,
      dataPath: authPath,
    }),
    // NEXA-R9: com a VPS carregada (vários Chromes abrindo juntos) o padrão de
    // 30s estourava e a sessão ficava parada até alguém mexer.
    authTimeoutMs: 120000,
    puppeteer: {
      headless: true,
      timeout: 120000,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-accelerated-2d-canvas',
        '--no-first-run',
        '--no-zygote',
        '--disable-gpu',
        // Economia de memória/CPU: com muitas empresas, cada Chrome conta.
        '--disable-extensions',
        '--disable-background-networking',
        '--disable-component-update',
        '--disable-default-apps',
        '--disable-sync',
        '--mute-audio',
        '--no-default-browser-check',
        '--disable-breakpad',
        '--disable-crash-reporter',
        '--disable-site-isolation-trials',
        '--disable-features=site-per-process,IsolateOrigins,Translate,BackForwardCache,AcceptCHFrame,MediaRouter,OptimizationHints',
        '--renderer-process-limit=2',
        '--disk-cache-size=33554432',
        '--media-cache-size=1',
      ],
    },
  });
  state.client = client;
  sessions.set(companyId, state);

  client.on('qr', async (qr) => {
    try {
      state.qr = await QRCode.toDataURL(qr, { margin: 1, width: 320 });
      state.ready = false;
      state.initializing = false;
      state.lastError = '';
      state.updatedAt = nowIso();
    } catch (error) {
      state.lastError = error.message;
      state.updatedAt = nowIso();
    }
  });

  client.on('authenticated', () => {
    state.authenticated = true;
    state.qr = '';
    state.lastError = '';
    state.updatedAt = nowIso();
  });

  client.on('ready', () => {
    state.ready = true;
    state.authenticated = true;
    state.initializing = false;
    state.qr = '';
    state.lastError = '';
    state.updatedAt = nowIso();
    console.log(`[whatsapp] Empresa ${companyId} conectada.`);
  });

  client.on('auth_failure', (message) => {
    state.ready = false;
    state.authenticated = false;
    state.initializing = false;
    state.lastError = String(message || 'Falha de autenticação.');
    state.updatedAt = nowIso();
    console.error(`[whatsapp] Falha de autenticação da empresa ${companyId}:`, state.lastError);
  });

  client.on('disconnected', (reason) => {
    state.ready = false;
    state.authenticated = false;
    state.initializing = false;
    state.lastError = String(reason || 'Sessão desconectada.');
    state.updatedAt = nowIso();
    console.warn(`[whatsapp] Empresa ${companyId} desconectada:`, state.lastError);
  });

  Promise.resolve(client.initialize()).catch((error) => {
    state.ready = false;
    state.initializing = false;
    state.lastError = error.message;
    state.updatedAt = nowIso();
    console.error(`[whatsapp] Falha ao inicializar empresa ${companyId}:`, error);
  });

  return state;
}

function getState(companyId) {
  return sessions.get(companyId) || buildClient(companyId);
}

async function waitUntilReady(state, timeoutMs = 30000) {
  const started = Date.now();
  while (!state.ready && Date.now() - started < timeoutMs) {
    if (state.lastError && !state.initializing && !state.qr) break;
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  if (!state.ready) {
    if (state.qr) throw new Error('WhatsApp ainda não conectado. Leia o QR Code no painel.');
    throw new Error(state.lastError || 'WhatsApp ainda não está pronto para envio.');
  }
}

async function resolveChatId(client, phone, options = {}) {
  const normalized = normalizePhone(phone);
  if (normalized.length < 10 || normalized.length > 15) throw new Error('Número de WhatsApp inválido.');

  const pnId = `${normalized}@c.us`;
  let lidId = '';

  // R8.2: para envio de mídia, força uma única resolução PN -> LID antes de abrir o chat.
  // O WhatsApp Web atual pode exigir que o mapeamento LID esteja populado mesmo quando
  // o destino é informado como @c.us. Essa chamada usa a API oficial da própria versão
  // 1.34.7 (enforceLidAndPnRetrieval) e não cria polling nem consulta em segundo plano.
  if (options.preflightLid === true && typeof client.getContactLidAndPhone === 'function') {
    try {
      const pairs = await client.getContactLidAndPhone([pnId]);
      const pair = Array.isArray(pairs) ? pairs[0] : null;
      lidId = String(pair?.lid || '').trim();
      const resolvedPn = String(pair?.pn || '').trim();
      if (resolvedPn && /@c\.us$/i.test(resolvedPn)) {
        console.log(`[whatsapp] LID preflight: ${resolvedPn}${lidId ? ` -> ${lidId}` : ''}`);
      } else if (lidId) {
        console.log(`[whatsapp] LID preflight: ${pnId} -> ${lidId}`);
      }
    } catch (error) {
      console.warn(`[whatsapp] LID preflight não concluiu para ${pnId}: ${error?.message || error}`);
    }
  }

  try {
    const numberId = await client.getNumberId(normalized);
    const serialized = numberId?._serialized || numberId?.$1 || '';
    if (serialized) return { chatId: serialized, lidId, pnId };
  } catch (_) {}
  return { chatId: pnId, lidId, pnId };
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function hasWWebJsInjection(state) {
  try {
    const page = state?.client?.pupPage;
    if (!page || page.isClosed()) return false;
    return await page.evaluate(() => (
      typeof window.WWebJS !== 'undefined'
      && typeof window.WWebJS.getChat === 'function'
      && typeof window.WWebJS.sendMessage === 'function'
    ));
  } catch (error) {
    setStateError(state, error);
    return false;
  }
}

async function reinjectWWebJs(state) {
  const client = state?.client;
  if (!client) throw new Error('Cliente WhatsApp não inicializado.');

  try {
    if (typeof client.inject !== 'function') {
      throw new Error('A versão atual do whatsapp-web.js não expõe a rotina de reinjeção.');
    }
    await client.inject();
  } catch (error) {
    setStateError(state, error);
    throw error;
  }

  const started = Date.now();
  while (Date.now() - started < 15000) {
    if (await hasWWebJsInjection(state)) {
      clearStateError(state);
      return;
    }
    await sleep(250);
  }
  throw new Error('WhatsApp Web carregou, mas a integração interna WWebJS não ficou disponível.');
}

async function ensureWWebJs(state) {
  if (await hasWWebJsInjection(state)) return;
  if (isHardBrowserError(state?.lastError)) {
    throw new Error(state.lastError);
  }
  console.warn('[whatsapp] WWebJS indisponível. Tentando reinjeção sem desconectar a sessão...');
  await reinjectWWebJs(state);
}

async function rebuildClientOnDemand(companyId, currentState) {
  const existing = recoveryLocks.get(companyId);
  if (existing) return existing;

  const promise = (async () => {
    console.warn(`[whatsapp] Recuperação sob demanda da empresa ${companyId}: recriando Chromium sem logout.`);
    if (currentState) {
      currentState.ready = false;
      currentState.initializing = true;
      currentState.lastError = 'Recuperando sessão do navegador...';
      currentState.updatedAt = nowIso();
    }

    try {
      if (currentState?.client) await currentState.client.destroy();
    } catch (error) {
      console.warn(`[whatsapp] Falha ao encerrar Chromium antigo da empresa ${companyId}:`, error?.message || error);
    }

    if (sessions.get(companyId) === currentState) sessions.delete(companyId);
    await sleep(700);

    const newState = buildClient(companyId);
    await waitUntilReady(newState, 35000);
    clearStateError(newState);
    console.log(`[whatsapp] Recuperação sob demanda concluída para empresa ${companyId}.`);
    return newState;
  })();

  recoveryLocks.set(companyId, promise);
  try {
    return await promise;
  } finally {
    if (recoveryLocks.get(companyId) === promise) recoveryLocks.delete(companyId);
  }
}

async function sendAttempt(state, to, content, options = {}) {
  await ensureWWebJs(state);

  const internalOptions = { ...options };
  const preflightLid = internalOptions.nexaLidPreflight === true;
  delete internalOptions.nexaLidPreflight;

  const resolved = await resolveChatId(state.client, to, { preflightLid });
  try {
    return await state.client.sendMessage(resolved.chatId, content, internalOptions);
  } catch (error) {
    const message = String(error?.message || error || '');
    // Se o WhatsApp Web ainda exigir o identificador LID explícito, fazemos um único
    // fallback apenas quando a primeira tentativa falhou antes do envio por ausência de LID.
    if (preflightLid && /No LID for user|Lid is missing in chat table/i.test(message)
        && resolved.lidId && resolved.lidId !== resolved.chatId) {
      console.warn(`[whatsapp] Falha LID em ${resolved.chatId}; repetindo uma única vez com ${resolved.lidId}.`);
      return await state.client.sendMessage(resolved.lidId, content, internalOptions);
    }
    throw error;
  }
}

async function sendWithOnDemandRecovery(companyId, to, content, options = {}) {
  let state = getState(companyId);
  await waitUntilReady(state, 35000);

  try {
    const sent = await sendAttempt(state, to, content, options);
    clearStateError(state);
    return sent;
  } catch (error) {
    const message = String(error?.message || error || '');
    setStateError(state, error);
    if (isMediaWidCompatibilityError(message)) {
      throw new Error(mediaWidCompatPatched
        ? `Falha de compatibilidade de mídia do WhatsApp Web mesmo com patch aplicado: ${message}`
        : `Compatibilidade de mídia do whatsapp-web.js não aplicada. Execute o instalador R7. Detalhe: ${message}`);
    }
    if (!isRecoverableWWebJsError(message)) throw error;

    if (!isHardBrowserError(message)) {
      try {
        console.warn(`[whatsapp] Falha recuperável da empresa ${companyId}: ${message}. Tentando reinjeção única.`);
        await reinjectWWebJs(state);
        await sleep(500);
        const sent = await sendAttempt(state, to, content, options);
        clearStateError(state);
        return sent;
      } catch (reinjectError) {
        setStateError(state, reinjectError);
        if (!isHardBrowserError(reinjectError?.message || reinjectError)) throw reinjectError;
      }
    }

    // Erros de frame/contexto indicam que a página Chromium ficou inválida.
    // Não fazemos polling nem logout. Recriamos o cliente apenas quando um envio exige isso,
    // preservando o LocalAuth existente, e repetimos a operação uma única vez.
    state = await rebuildClientOnDemand(companyId, state);
    const sent = await sendAttempt(state, to, content, options);
    clearStateError(state);
    return sent;
  }
}

app.get('/admin/sessions', (req, res) => {
  if (!authorizeAdmin(req)) return res.status(401).json({ ok: false, error: 'Acesso administrativo não autorizado.' });

  const ids = storedCompanyIds();
  for (const id of sessions.keys()) ids.add(id);
  const items = [];
  for (const companyId of ids) {
    const state = sessions.get(companyId);
    if (state) {
      const payload = statePayload(companyId, state);
      items.push({
        company_id: companyId,
        status: payload.status,
        connected: payload.connected,
        ready: payload.ready,
        authenticated: payload.authenticated,
        initializing: payload.initializing,
        stored: true,
        last_error: payload.lastError,
        updated_at: payload.updatedAt,
      });
    } else {
      items.push({
        company_id: companyId,
        status: 'stored',
        connected: false,
        ready: false,
        authenticated: true,
        initializing: false,
        stored: true,
        last_error: '',
        updated_at: '',
      });
    }
  }
  return res.json({ ok: true, items });
});

app.post('/admin/session/start', async (req, res) => {
  if (!authorizeAdmin(req)) return res.status(401).json({ ok: false, error: 'Acesso administrativo não autorizado.' });
  try {
    const companyId = safeCompanyId(req.body?.company_id);
    const state = getState(companyId);
    return res.json(statePayload(companyId, state));
  } catch (error) {
    return res.status(400).json({ ok: false, error: error.message });
  }
});

// NEXA-R9: reabre uma sessão salva que falhou ao iniciar (timeout, Chrome
// travado). Não faz logout e não apaga o LocalAuth. Não mexe em sessão
// conectada, iniciando ou aguardando QR Code.
app.post('/admin/session/restart', async (req, res) => {
  if (!authorizeAdmin(req)) return res.status(401).json({ ok: false, error: 'Acesso administrativo não autorizado.' });
  try {
    const companyId = safeCompanyId(req.body?.company_id);
    if (!storedCompanyIds().has(companyId)) return res.status(404).json({ ok: false, error: 'Sessão não salva neste servidor.' });
    const current = sessions.get(companyId);
    if (current && (current.ready || current.initializing || current.qr || recoveryLocks.has(companyId))) {
      return res.json({ ...statePayload(companyId, current), restarted: false });
    }
    if (current?.client) {
      try { await current.client.destroy(); } catch (_) {}
    }
    if (sessions.get(companyId) === current) sessions.delete(companyId);
    const state = buildClient(companyId);
    console.log(`[whatsapp] Empresa ${companyId}: reabrindo sessão salva após falha.`);
    return res.json({ ...statePayload(companyId, state), restarted: true });
  } catch (error) {
    return res.status(400).json({ ok: false, error: error.message });
  }
});

// NEXA-R9: fecha todos os Chromes com calma antes de reiniciar o serviço, para
// o WhatsApp gravar a sessão e ela voltar sem pedir QR Code.
app.post('/admin/shutdown-sessions', async (req, res) => {
  if (!authorizeAdmin(req)) return res.status(401).json({ ok: false, error: 'Acesso administrativo não autorizado.' });
  const total = await destroyAllSessions(30000);
  return res.json({ ok: true, closed: total });
});

app.get('/health', (_req, res) => {
  res.json({
    ok: true,
    service: 'nexa-whatsapp',
    service_version: 'r8.2',
    document_send: true,
    recovery_wwebjs: true,
    recovery_browser_on_demand: true,
    background_polling: false,
    whatsapp_webjs_version: whatsappWebJsVersion,
    media_wid_compat: mediaWidCompatPatched,
    media_private_id_fix: mediaPrivateIdFixPatched,
    media_lid_preflight: true,
    time: nowIso(),
  });
});

app.post('/tokens/upsert', (req, res) => {
  try {
    const companyId = safeCompanyId(req.body?.company_id);
    if (!authorize(req, companyId, { allowAnyRegisteredToken: true })) {
      return res.status(401).json({ ok: false, error: 'Token inválido para registrar a empresa.' });
    }
    const token = String(req.body?.token || '').trim();
    if (token.length < 32 || token.length > 256) {
      return res.status(400).json({ ok: false, error: 'Token da empresa inválido.' });
    }
    companyTokens[companyId] = token;
    saveTokens();
    return res.json({ ok: true, company_id: companyId, message: 'Token registrado.' });
  } catch (error) {
    return res.status(400).json({ ok: false, error: error.message });
  }
});

app.get('/status', (req, res) => {
  try {
    const companyId = safeCompanyId(req.query?.company_id);
    if (!authorize(req, companyId)) return res.status(401).json({ ok: false, error: 'Token inválido.' });
    const state = getState(companyId);
    return res.json(statePayload(companyId, state));
  } catch (error) {
    return res.status(400).json({ ok: false, error: error.message });
  }
});

app.get('/qr', (req, res) => {
  try {
    const companyId = safeCompanyId(req.query?.company_id);
    if (!authorize(req, companyId)) return res.status(401).json({ ok: false, error: 'Token inválido.' });
    const state = getState(companyId);
    return res.json(statePayload(companyId, state));
  } catch (error) {
    return res.status(400).json({ ok: false, error: error.message });
  }
});

app.post('/logout', async (req, res) => {
  try {
    const companyId = safeCompanyId(req.body?.company_id);
    if (!authorize(req, companyId)) return res.status(401).json({ ok: false, error: 'Token inválido.' });
    const state = sessions.get(companyId);
    if (state?.client) {
      try { await state.client.logout(); } catch (_) {}
      try { await state.client.destroy(); } catch (_) {}
    }
    sessions.delete(companyId);
    const sessionPath = path.join(authPath, `session-company-${companyId}`);
    try { fs.rmSync(sessionPath, { recursive: true, force: true }); } catch (_) {}
    return res.json({ ok: true, company_id: companyId, message: 'WhatsApp desconectado.' });
  } catch (error) {
    return res.status(400).json({ ok: false, error: error.message });
  }
});

app.post('/send', async (req, res) => {
  try {
    const companyId = safeCompanyId(req.body?.company_id);
    if (!authorize(req, companyId)) return res.status(401).json({ ok: false, error: 'Token inválido.' });
    const to = normalizePhone(req.body?.to);
    const message = String(req.body?.message || '').trim();
    if (!to || !message) return res.status(400).json({ ok: false, error: 'Destino e mensagem são obrigatórios.' });

    const sent = await sendWithOnDemandRecovery(companyId, to, message);
    return res.json({ ok: true, company_id: companyId, to, message_id: sent?.id?._serialized || sent?.id?.$1 || '' });
  } catch (error) {
    return res.status(500).json({ ok: false, error: error.message });
  }
});

app.post('/send-document', async (req, res) => {
  try {
    const companyId = safeCompanyId(req.body?.company_id);
    if (!authorize(req, companyId)) return res.status(401).json({ ok: false, error: 'Token inválido.' });

    const to = normalizePhone(req.body?.to);
    const caption = String(req.body?.caption || '').trim();
    const filename = path.basename(String(req.body?.filename || 'documento.pdf')).replace(/[^0-9A-Za-zÀ-ÿ._-]+/g, '-');
    const mimetype = String(req.body?.mimetype || 'application/pdf').trim();
    const fileBase64 = String(req.body?.file_base64 || '').replace(/^data:[^;]+;base64,/, '').replace(/\s+/g, '');

    if (!to) return res.status(400).json({ ok: false, error: 'Destino inválido.' });
    if (!fileBase64) return res.status(400).json({ ok: false, error: 'Arquivo Base64 não informado.' });
    if (!/^[A-Za-z0-9+/]*={0,2}$/.test(fileBase64)) return res.status(400).json({ ok: false, error: 'Arquivo Base64 inválido.' });

    const size = Buffer.byteLength(fileBase64, 'base64');
    if (size <= 0 || size > maxDocumentBytes) {
      return res.status(413).json({ ok: false, error: 'O documento deve possuir no máximo 8 MB.' });
    }

    const media = new MessageMedia(mimetype, fileBase64, filename || 'documento.pdf', size);
    const sent = await sendWithOnDemandRecovery(companyId, to, media, {
      caption,
      sendMediaAsDocument: true,
      sendSeen: false,
      nexaLidPreflight: true,
    });

    return res.json({
      ok: true,
      company_id: companyId,
      to,
      filename,
      size,
      message_id: sent?.id?._serialized || sent?.id?.$1 || '',
    });
  } catch (error) {
    return res.status(500).json({ ok: false, error: error.message });
  }
});

app.use((error, _req, res, _next) => {
  if (error?.type === 'entity.too.large') {
    return res.status(413).json({ ok: false, error: 'Arquivo muito grande para o serviço WhatsApp.' });
  }
  console.error('[http]', error);
  return res.status(500).json({ ok: false, error: error?.message || 'Erro interno.' });
});

const server = app.listen(port, host, () => {
  console.log(`[whatsapp] Serviço Nexa ouvindo em http://${host}:${port}`);
  console.log(`[whatsapp] Envio de documentos habilitado. Limite: ${maxDocumentBytes} bytes.`);
});

// NEXA-R9: fecha os Chromes em paralelo (antes era um por vez, com limite de
// 8s no total: com várias empresas, parte deles era morta no meio da gravação).
async function destroyAllSessions(timeoutMs) {
  const states = [...sessions.values()];
  sessions.clear();
  const all = Promise.allSettled(states.map((state) => (state?.client ? state.client.destroy() : null)));
  await Promise.race([all, new Promise((resolve) => setTimeout(resolve, timeoutMs))]);
  return states.length;
}

async function shutdown(signal) {
  console.log(`[whatsapp] Encerrando por ${signal}...`);
  setTimeout(() => process.exit(0), 25000).unref();
  await destroyAllSessions(20000);
  server.close(() => process.exit(0));
}

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));

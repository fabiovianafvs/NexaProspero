// Nexa Próspero - motor de WhatsApp leve (Baileys).
//
// Substitui o serviço antigo (whatsapp-web.js), que abria um Google Chrome por
// empresa (400-700 MB cada). Aqui todas as empresas ficam num único processo
// Node conversando direto com o WhatsApp (sem navegador): algumas dezenas de MB
// por empresa conectada e processador parado quando não há envio.
//
// Mantém as MESMAS rotas e respostas do serviço antigo, para o sistema não
// precisar mudar: /status, /qr, /send, /send-document, /logout, /tokens/upsert,
// /tokens/remove, /admin/sessions, /admin/session/(start|restart|stop|new-qr),
// /admin/shutdown-sessions e /health.
//
// Novidades:
//  - reconexão automática por evento (sem verificação periódica);
//  - fila de envio por empresa com intervalo mínimo (protege o número);
//  - confere se o número tem WhatsApp e corrige o 9º dígito (Brasil);
//  - mensagens RECEBIDAS podem ser entregues ao sistema por webhook assinado
//    (base do chatbot), ligado empresa por empresa em /inbound/config.
//
// Configuração por variáveis de ambiente (arquivo /etc/nexa-wa/engine.env):
//   HOST, PORT, DATA_DIR, API_TOKEN, SEND_MIN_INTERVAL_MS, BROWSER_NAME,
//   INBOUND_WEBHOOK_URL, INBOUND_WEBHOOK_HOST, INBOUND_WEBHOOK_SECRET, LOG_LEVEL

import http from 'node:http';
import https from 'node:https';
import fs from 'node:fs';
import fsp from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { URL, fileURLToPath } from 'node:url';
import QRCode from 'qrcode';
import pino from 'pino';

const SERVICE_VERSION = 'engine-1.6';
const HOST = process.env.HOST || '127.0.0.1';
const PORT = Number(process.env.PORT || 3333);
const DATA_DIR = process.env.DATA_DIR || '/var/lib/nexa-wa';
const AUTH_DIR = path.join(DATA_DIR, 'auth');
const TOKENS_FILE = path.join(DATA_DIR, 'tokens.json');
const MEDIA_DIR = path.join(DATA_DIR, 'media');
const SETTINGS_FILE = path.join(DATA_DIR, 'settings.json');
const GLOBAL_TOKEN = String(process.env.API_TOKEN || '').trim();
const SEND_MIN_INTERVAL_MS = Math.max(300, Number(process.env.SEND_MIN_INTERVAL_MS || 1500));
const STARTUP_STAGGER_MS = Math.max(200, Number(process.env.STARTUP_STAGGER_MS || 1500));
const BROWSER_NAME = String(process.env.BROWSER_NAME || 'Nexa Próspero');
const WEBHOOK_URL = String(process.env.INBOUND_WEBHOOK_URL || '').trim();
const WEBHOOK_HOST = String(process.env.INBOUND_WEBHOOK_HOST || '').trim();
const WEBHOOK_SECRET = String(process.env.INBOUND_WEBHOOK_SECRET || '').trim();
const MAX_BODY_BYTES = 20 * 1024 * 1024;
const MAX_DOCUMENT_BYTES = 16 * 1024 * 1024;
const MAX_MEDIA_BYTES = 16 * 1024 * 1024;
const WAIT_READY_MS = 25000;

// A biblioteca de criptografia do WhatsApp (libsignal) escreve no console o
// conteúdo das sessões ("Closing session: SessionEntry {...}"), inclusive chaves.
// Isso enche o log e não deve ser gravado: essas linhas são descartadas.
const SIGNAL_NOISE = /^(Closing session|Opening session|Removing old closed session|Closing open session|Session error|Decrypted message with closed session|Migrating session to)/;
for (const method of ['log', 'info', 'debug']) {
  const original = console[method].bind(console);
  console[method] = (...args) => {
    if (typeof args[0] === 'string' && SIGNAL_NOISE.test(args[0])) return;
    original(...args);
  };
}
const log = (...args) => console.log(new Date().toISOString(), ...args);
const warn = (...args) => console.warn(new Date().toISOString(), ...args);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const nowIso = () => new Date().toISOString();
const baileysLogger = pino({ level: process.env.LOG_LEVEL || 'warn' });

// ---------------------------------------------------------------------------
// Baileys (ESM a partir da v7; o nome do pacote mudou de @whiskeysockets/baileys
// para baileys). Aceita os dois.
// ---------------------------------------------------------------------------
let B = null;
let BAILEYS_PKG = '';
for (const name of ['baileys', '@whiskeysockets/baileys']) {
  try {
    B = await import(name);
    BAILEYS_PKG = name;
    break;
  } catch (_) { /* tenta o próximo */ }
}
if (!B) {
  console.error('Biblioteca Baileys não instalada. Rode o instalador do motor de WhatsApp.');
  process.exit(1);
}
const pick = (name) => B[name] ?? B.default?.[name];
const makeWASocket = pick('makeWASocket') || (typeof B.default === 'function' ? B.default : B.default?.default);
const DisconnectReason = pick('DisconnectReason') || {};
const Browsers = pick('Browsers');
const BufferJSON = pick('BufferJSON');
const initAuthCreds = pick('initAuthCreds');
const makeCacheableSignalKeyStore = pick('makeCacheableSignalKeyStore');
const fetchLatestBaileysVersion = pick('fetchLatestBaileysVersion');
const proto = pick('proto');
const downloadMediaMessage = pick('downloadMediaMessage');
const BAILEYS_VERSION = (() => {
  try {
    const pkgFile = path.join(path.dirname(fileURLToPath(import.meta.url)), 'node_modules', BAILEYS_PKG, 'package.json');
    return JSON.parse(fs.readFileSync(pkgFile, 'utf8')).version || '';
  } catch (_) { return ''; }
})();
if (typeof makeWASocket !== 'function' || !BufferJSON || typeof initAuthCreds !== 'function') {
  console.error('Versão da Baileys incompatível com o motor Nexa.');
  process.exit(1);
}

const CODE = {
  loggedOut: DisconnectReason.loggedOut ?? 401,
  forbidden: DisconnectReason.forbidden ?? 403,
  timedOut: DisconnectReason.timedOut ?? 408,
  multideviceMismatch: DisconnectReason.multideviceMismatch ?? 411,
  connectionReplaced: DisconnectReason.connectionReplaced ?? 440,
  badSession: DisconnectReason.badSession ?? 500,
  restartRequired: DisconnectReason.restartRequired ?? 515,
};

// ---------------------------------------------------------------------------
// Gravação segura em disco: escreve num arquivo temporário e renomeia (uma
// queda de energia no meio da gravação não corrompe a sessão). Gravações no
// mesmo arquivo ficam em fila, na ordem em que foram pedidas.
// ---------------------------------------------------------------------------
const writeChains = new Map();
function writeFileAtomic(file, content) {
  const prev = writeChains.get(file) || Promise.resolve();
  const next = prev.catch(() => {}).then(async () => {
    const tmp = `${file}.${process.pid}.${crypto.randomBytes(4).toString('hex')}.tmp`;
    await fsp.writeFile(tmp, content, { mode: 0o600 });
    await fsp.rename(tmp, file);
  });
  writeChains.set(file, next);
  next.finally(() => { if (writeChains.get(file) === next) writeChains.delete(file); }).catch(() => {});
  return next;
}
function removeFileQueued(file) {
  const prev = writeChains.get(file) || Promise.resolve();
  const next = prev.catch(() => {}).then(() => fsp.rm(file, { force: true }));
  writeChains.set(file, next);
  next.finally(() => { if (writeChains.get(file) === next) writeChains.delete(file); }).catch(() => {});
  return next;
}
async function flushWrites() {
  await Promise.allSettled([...writeChains.values()]);
}

function readJsonSync(file, fallback) {
  try {
    const parsed = JSON.parse(fs.readFileSync(file, 'utf8'));
    return parsed && typeof parsed === 'object' ? parsed : fallback;
  } catch (_) { return fallback; }
}

// Sessão da empresa em arquivos (equivalente ao useMultiFileAuthState da
// Baileys, porém com gravação atômica).
async function fileAuthState(dir) {
  await fsp.mkdir(dir, { recursive: true, mode: 0o700 });
  const fileFor = (name) => path.join(dir, `${String(name).replace(/\//g, '__').replace(/:/g, '-')}.json`);
  const read = async (name) => {
    try {
      return JSON.parse(await fsp.readFile(fileFor(name), 'utf8'), BufferJSON.reviver);
    } catch (_) { return null; }
  };
  const write = (name, data) => writeFileAtomic(fileFor(name), JSON.stringify(data, BufferJSON.replacer));
  const creds = (await read('creds')) || initAuthCreds();
  const AppStateKey = proto?.Message?.AppStateSyncKeyData;
  return {
    state: {
      creds,
      keys: {
        get: async (type, ids) => {
          const out = {};
          await Promise.all(ids.map(async (id) => {
            let value = await read(`${type}-${id}`);
            if (type === 'app-state-sync-key' && value && AppStateKey) {
              value = typeof AppStateKey.fromObject === 'function' ? AppStateKey.fromObject(value) : AppStateKey.create(value);
            }
            out[id] = value;
          }));
          return out;
        },
        set: async (data) => {
          const jobs = [];
          for (const category of Object.keys(data)) {
            for (const id of Object.keys(data[category] || {})) {
              const value = data[category][id];
              const name = `${category}-${id}`;
              jobs.push(value ? write(name, value) : removeFileQueued(fileFor(name)));
            }
          }
          await Promise.all(jobs);
        },
      },
    },
    saveCreds: () => write('creds', creds),
  };
}

// ---------------------------------------------------------------------------
// Tokens (mesmo formato do serviço antigo) e configurações do motor.
// ---------------------------------------------------------------------------
fs.mkdirSync(AUTH_DIR, { recursive: true, mode: 0o700 });
let companyTokens = readJsonSync(TOKENS_FILE, {});
let settings = readJsonSync(SETTINGS_FILE, {});
if (!settings.inbound || typeof settings.inbound !== 'object') settings.inbound = {};
if (!settings.stopped || typeof settings.stopped !== 'object') settings.stopped = {};
const saveTokens = () => writeFileAtomic(TOKENS_FILE, JSON.stringify(companyTokens, null, 2));
const saveSettings = () => writeFileAtomic(SETTINGS_FILE, JSON.stringify(settings, null, 2));

function constantEquals(a, b) {
  const left = Buffer.from(String(a || ''));
  const right = Buffer.from(String(b || ''));
  if (!left.length || left.length !== right.length) return false;
  return crypto.timingSafeEqual(left, right);
}
function tokenFrom(req) {
  return String(req.headers['x-api-token'] || req.headers.authorization || '').replace(/^Bearer\s+/i, '').trim();
}
function isGlobal(req) {
  return Boolean(GLOBAL_TOKEN) && constantEquals(tokenFrom(req), GLOBAL_TOKEN);
}
function isCompany(req, sid) {
  if (isGlobal(req)) return true;
  const own = String(companyTokens[sid] || '');
  return Boolean(own) && constantEquals(tokenFrom(req), own);
}
function companiesForToken(req) {
  const provided = tokenFrom(req);
  if (!provided) return [];
  return Object.keys(companyTokens).filter((sid) => constantEquals(provided, companyTokens[sid]));
}

function safeId(value) {
  const id = String(value ?? '').replace(/[^0-9A-Za-z_-]+/g, '');
  if (!id || id.length > 64) throw httpError(400, 'company_id inválido.');
  return id;
}
function digits(value) {
  return String(value ?? '').replace(/\D+/g, '');
}
function normalizePhone(value) {
  let n = digits(value);
  if ((n.length === 10 || n.length === 11) && !n.startsWith('55')) n = `55${n}`;
  return n;
}
function httpError(status, message) {
  const error = new Error(message);
  error.status = status;
  return error;
}

// ---------------------------------------------------------------------------
// Sessões
// ---------------------------------------------------------------------------
/** @type {Map<string, any>} */
const sessions = new Map();
let waVersion = null;
let waVersionAt = 0;
let shuttingDown = false;

const authDirFor = (sid) => path.join(AUTH_DIR, `company-${sid}`);
function hasStoredCreds(sid) {
  const creds = readJsonSync(path.join(authDirFor(sid), 'creds.json'), null);
  // Login por QR Code não marca creds.registered (só o login por código de
  // pareamento marca): a sessão salva é reconhecida pelo creds.me (aparelho já
  // vinculado). Antes só "registered" contava e, a cada reinício do motor, as
  // empresas não eram reabertas e pareciam desconectadas.
  return Boolean(creds && (creds.registered || creds.me?.id));
}
function storedIds() {
  const ids = [];
  try {
    for (const entry of fs.readdirSync(AUTH_DIR, { withFileTypes: true })) {
      const m = entry.isDirectory() ? /^company-(.+)$/.exec(entry.name) : null;
      if (m && hasStoredCreds(m[1])) ids.push(m[1]);
    }
  } catch (_) {}
  return ids;
}

function blankState(sid) {
  return {
    sid,
    sock: null,
    status: 'idle',
    qr: '',
    lastError: '',
    updatedAt: nowIso(),
    registered: hasStoredCreds(sid),
    phone: '',
    retries: 0,
    reconnectTimer: null,
    generation: 0,
    sendChain: Promise.resolve(),
    lastSendAt: 0,
    recent: new Map(),
    numberCache: new Map(),
    waiters: new Set(),
  };
}
function getState(sid) {
  let state = sessions.get(sid);
  if (!state) {
    state = blankState(sid);
    sessions.set(sid, state);
  }
  return state;
}
function setStatus(state, status, error = undefined) {
  state.status = status;
  if (error !== undefined) state.lastError = String(error || '');
  state.updatedAt = nowIso();
  for (const wake of state.waiters) wake();
}

async function currentWaVersion() {
  if (typeof fetchLatestBaileysVersion !== 'function') return undefined;
  if (waVersion && Date.now() - waVersionAt < 12 * 3600 * 1000) return waVersion;
  try {
    const res = await Promise.race([fetchLatestBaileysVersion(), sleep(8000).then(() => null)]);
    if (res?.version) {
      waVersion = res.version;
      waVersionAt = Date.now();
    }
  } catch (_) {}
  return waVersion || undefined;
}

function closeSocket(state) {
  const sock = state.sock;
  state.sock = null;
  state.generation += 1;
  if (state.reconnectTimer) {
    clearTimeout(state.reconnectTimer);
    state.reconnectTimer = null;
  }
  if (sock) {
    try { sock.ev.removeAllListeners(); } catch (_) {}
    try { sock.end(undefined); } catch (_) {}
  }
}

function scheduleReconnect(state, reason) {
  if (shuttingDown || state.reconnectTimer) return;
  state.retries += 1;
  const delay = Math.min(300000, 2000 * 2 ** Math.min(state.retries - 1, 8)) + Math.floor(Math.random() * 1000);
  warn(`[empresa ${state.sid}] Conexão caiu (${reason}). Nova tentativa em ${Math.round(delay / 1000)}s.`);
  state.reconnectTimer = setTimeout(() => {
    state.reconnectTimer = null;
    startSession(state.sid).catch((error) => warn(`[empresa ${state.sid}] Falha ao reconectar:`, error?.message || error));
  }, delay);
  state.reconnectTimer.unref?.();
}

async function startSession(sid) {
  const state = getState(sid);
  if (shuttingDown) return state;
  if (state.sock && ['connecting', 'qr', 'connected'].includes(state.status)) return state;
  closeSocket(state);
  const generation = state.generation;
  setStatus(state, 'connecting');

  const { state: auth, saveCreds } = await fileAuthState(authDirFor(sid));
  if (generation !== state.generation) return state;
  state.registered = Boolean(auth.creds?.registered || auth.creds?.me?.id);
  const version = await currentWaVersion();
  if (generation !== state.generation) return state;

  const ignore = (jid) => {
    const j = String(jid || '');
    return j.endsWith('@g.us') || j.endsWith('@broadcast') || j.endsWith('@newsletter') || j === 'status@broadcast';
  };
  const browser = Browsers?.ubuntu ? Browsers.ubuntu(BROWSER_NAME) : [BROWSER_NAME, 'Chrome', '22.0'];
  const options = {
    auth: {
      creds: auth.creds,
      keys: typeof makeCacheableSignalKeyStore === 'function' ? makeCacheableSignalKeyStore(auth.keys, baileysLogger) : auth.keys,
    },
    logger: baileysLogger,
    browser,
    printQRInTerminal: false,
    markOnlineOnConnect: false,
    syncFullHistory: false,
    // Não bloquear a sincronização inicial: sem ela o Baileys 7 não recebe os
    // mapeamentos de LID e o celular pode remover o aparelho logo após a leitura
    // do QR Code ("device_removed"). Só o histórico COMPLETO fica de fora; as
    // mensagens antigas não são guardadas nem respondidas pelo robô.
    shouldSyncHistoryMessage: (msg) => {
      const full = proto?.HistorySync?.HistorySyncType?.FULL;
      return full === undefined ? true : msg?.syncType !== full;
    },
    shouldIgnoreJid: ignore,
    generateHighQualityLinkPreview: false,
    connectTimeoutMs: 60000,
    defaultQueryTimeoutMs: 60000,
    keepAliveIntervalMs: 30000,
    getMessage: async (key) => state.recent.get(String(key?.id || '')) || undefined,
  };
  if (version) options.version = version;

  const sock = makeWASocket(options);
  state.sock = sock;

  sock.ev.on('creds.update', () => {
    saveCreds().catch((error) => warn(`[empresa ${sid}] Falha ao gravar sessão:`, error?.message || error));
  });

  sock.ev.on('connection.update', async (update) => {
    if (generation !== state.generation) return;
    const { connection, lastDisconnect, qr } = update;
    if (qr) {
      try {
        state.qr = await QRCode.toDataURL(qr, { margin: 1, width: 320 });
        setStatus(state, 'qr', '');
      } catch (error) {
        setStatus(state, 'qr', error.message);
      }
    }
    if (connection === 'open') {
      state.qr = '';
      state.retries = 0;
      state.registered = true;
      state.phone = digits(String(sock.user?.id || '').split(':')[0].split('@')[0]);
      setStatus(state, 'connected', '');
      log(`[empresa ${sid}] Conectada${state.phone ? ` (${state.phone})` : ''}.`);
      return;
    }
    if (connection !== 'close') return;

    const code = Number(lastDisconnect?.error?.output?.statusCode || lastDisconnect?.error?.statusCode || 0);
    const reason = String(lastDisconnect?.error?.message || `código ${code}`);
    state.sock = null;
    try { sock.ev.removeAllListeners(); } catch (_) {}
    state.qr = '';
    if (shuttingDown) return;

    if (code === CODE.loggedOut) {
      // Desconectado pelo celular (aparelho removido): apaga a sessão.
      state.registered = false;
      setStatus(state, 'disconnected', 'WhatsApp desconectado pelo celular. Leia o QR Code novamente.');
      await flushWrites();
      await fsp.rm(authDirFor(sid), { recursive: true, force: true }).catch(() => {});
      warn(`[empresa ${sid}] Sessão encerrada pelo celular.`);
      return;
    }
    if (code === CODE.forbidden) {
      setStatus(state, 'disconnected', 'O WhatsApp recusou a conexão deste número (possível bloqueio).');
      warn(`[empresa ${sid}] Conexão recusada pelo WhatsApp (403).`);
      return;
    }
    if (code === CODE.connectionReplaced) {
      setStatus(state, 'disconnected', 'Esta sessão foi aberta em outro servidor/serviço. Use Reconectar no painel.');
      warn(`[empresa ${sid}] Sessão substituída por outra conexão (440).`);
      return;
    }
    if (!state.registered && !hasStoredCreds(sid)) {
      // Estava aguardando a leitura do QR Code e ninguém leu: para de gerar QR.
      if (code === CODE.restartRequired) {
        startSession(sid).catch(() => {});
        return;
      }
      setStatus(state, 'disconnected', 'QR Code expirou. Abra o WhatsApp no painel para gerar outro.');
      return;
    }
    if (code === CODE.restartRequired) {
      // Normal logo após ler o QR Code: reabre na hora.
      startSession(sid).catch(() => {});
      return;
    }
    setStatus(state, 'connecting', reason);
    scheduleReconnect(state, reason);
  });

  sock.ev.on('messages.upsert', ({ messages, type }) => {
    if (type !== 'notify' || !settings.inbound[sid] || !WEBHOOK_URL) return;
    for (const msg of messages || []) {
      handleInboundOrdered(state, msg);
    }
  });

  // Situação das mensagens enviadas (entregue/lida) -> webhook "status" (checks na tela de Atendimento).
  // 2 = enviada ao servidor, 3 = entregue no celular, 4 = lida, 5 = áudio ouvido.
  sock.ev.on('messages.update', (updates) => {
    if (!settings.inbound[sid] || !WEBHOOK_URL) return;
    for (const u of updates || []) {
      const key = u?.key || {};
      const st = Number(u?.update?.status ?? -1);
      if (!key.fromMe || !key.id || st < 2) continue;
      const remote = String(key.remoteJid || '');
      if (remote.endsWith('@g.us') || remote.endsWith('@broadcast') || remote.endsWith('@newsletter')) continue;
      deliverWebhook({ event: 'status', company_id: sid, message_id: String(key.id), status: st, reply_jid: remote });
    }
  });

  return state;
}

async function waitConnected(state, timeoutMs) {
  if (state.status === 'connected' && state.sock) return;
  if (!state.sock && state.status !== 'connecting') {
    if (!state.registered && !hasStoredCreds(state.sid)) throw httpError(409, 'WhatsApp ainda não conectado. Leia o QR Code no painel.');
    await startSession(state.sid);
  }
  const until = Date.now() + timeoutMs;
  while (!(state.status === 'connected' && state.sock) && Date.now() < until) {
    if (state.status === 'qr') throw httpError(409, 'WhatsApp ainda não conectado. Leia o QR Code no painel.');
    if (state.status === 'disconnected') break;
    await new Promise((resolve) => {
      const timer = setTimeout(done, Math.max(50, until - Date.now()));
      function done() { clearTimeout(timer); state.waiters.delete(done); resolve(); }
      state.waiters.add(done);
    });
  }
  if (!(state.status === 'connected' && state.sock)) {
    throw httpError(409, state.lastError || 'WhatsApp ainda não está pronto para envio.');
  }
}

// Números do Brasil: o WhatsApp de contas antigas pode estar sem o 9º dígito.
function phoneCandidates(n) {
  const list = [n];
  if (n.startsWith('55') && n.length === 13 && n[4] === '9') list.push(n.slice(0, 4) + n.slice(5));
  if (n.startsWith('55') && n.length === 12 && /[6-9]/.test(n[4])) list.push(`${n.slice(0, 4)}9${n.slice(4)}`);
  return list;
}

async function resolveJid(state, rawTo, rawJid) {
  const jid = String(rawJid || '').trim();
  const phone = normalizePhone(rawTo);
  // Prefere SEMPRE o número de telefone: enviar direto para o endereço interno
  // (@lid) derrubava a sessão ("device_removed"). Sem telefone, tenta converter
  // o @lid para o número; só em último caso usa o @lid.
  if (phone.length < 10 && /^[0-9]{5,20}(:[0-9]+)?@s\.whatsapp\.net$/.test(jid)) return jid;
  if (phone.length < 10 && /^[0-9]{5,20}(:[0-9]+)?@lid$/.test(jid)) {
    try {
      const pn = await state.sock?.signalRepository?.lidMapping?.getPNForLID?.(jid);
      if (pn && String(pn).includes('@s.whatsapp.net')) return String(pn).replace(/:\d+@/, '@');
    } catch (_) {}
    return jid;
  }
  const n = phone;
  if (n.length < 10 || n.length > 15) throw httpError(400, 'Número de WhatsApp inválido.');
  const cached = state.numberCache.get(n);
  if (cached && Date.now() - cached.at < 7 * 86400000) return cached.jid;

  const candidates = phoneCandidates(n);
  let found = '';
  let checked = false;
  try {
    const result = await Promise.race([
      state.sock.onWhatsApp(...candidates.map((c) => `${c}@s.whatsapp.net`)),
      sleep(15000).then(() => null),
    ]);
    if (Array.isArray(result) && result.length) {
      // Só conclui "sem WhatsApp" com resposta clara; resposta vazia = envia assim mesmo.
      checked = true;
      const hit = result.find((r) => r && r.exists && r.jid);
      if (hit) found = String(hit.jid);
    }
  } catch (error) {
    warn(`[empresa ${state.sid}] Consulta do número ${n} falhou: ${error?.message || error}`);
  }
  if (!found && checked) throw httpError(422, 'Este número não possui WhatsApp.');
  if (!found) return `${n}@s.whatsapp.net`;
  if (state.numberCache.size > 3000) state.numberCache.delete(state.numberCache.keys().next().value);
  state.numberCache.set(n, { jid: found, at: Date.now() });
  return found;
}

function rememberSent(state, sent) {
  const id = String(sent?.key?.id || '');
  if (!id || !sent?.message) return;
  state.recent.set(id, sent.message);
  while (state.recent.size > 300) state.recent.delete(state.recent.keys().next().value);
}

// Fila por empresa: um envio por vez, com intervalo mínimo entre eles.
function enqueueSend(state, job) {
  const run = state.sendChain.catch(() => {}).then(async () => {
    const wait = state.lastSendAt + SEND_MIN_INTERVAL_MS - Date.now();
    if (wait > 0) await sleep(wait);
    try {
      return await job();
    } finally {
      state.lastSendAt = Date.now();
    }
  });
  state.sendChain = run;
  return run;
}

async function sendContent(sid, to, jidHint, content) {
  const state = getState(sid);
  await waitConnected(state, WAIT_READY_MS);
  return enqueueSend(state, async () => {
    if (!state.sock || state.status !== 'connected') throw httpError(409, 'WhatsApp desconectou antes do envio. Tente novamente.');
    const jid = await resolveJid(state, to, jidHint);
    const sent = await state.sock.sendMessage(jid, content);
    rememberSent(state, sent);
    return { jid, id: String(sent?.key?.id || '') };
  });
}

// ---------------------------------------------------------------------------
// Mensagens recebidas -> webhook do sistema (chatbot)
// ---------------------------------------------------------------------------
function unwrapMessage(message) {
  let m = message || {};
  for (let i = 0; i < 5; i += 1) {
    const inner = m.ephemeralMessage?.message || m.viewOnceMessage?.message || m.viewOnceMessageV2?.message
      || m.documentWithCaptionMessage?.message || m.editedMessage?.message;
    if (!inner) break;
    m = inner;
  }
  return m;
}

// Mídia recebida: o motor baixa e guarda por pouco tempo em DATA_DIR/media; o
// sistema busca o arquivo por GET /media (pelo túnel) assim que recebe o aviso.
const MEDIA_TYPES = { imageMessage: 'image', audioMessage: 'audio', videoMessage: 'video', documentMessage: 'document', stickerMessage: 'sticker' };

function mediaFile(sid, id) {
  return path.join(MEDIA_DIR, safeId(sid), String(id).replace(/[^0-9A-Za-z_-]/g, '').slice(0, 80));
}

async function storeMedia(state, msg, m, type) {
  const node = m[Object.keys(MEDIA_TYPES).find((k) => MEDIA_TYPES[k] === type)] || {};
  const mimetype = String(node.mimetype || '').split(';')[0].trim() || 'application/octet-stream';
  const declared = Number(node.fileLength?.low ?? node.fileLength ?? 0) || 0;
  const filename = String(node.fileName || '').slice(0, 150);
  const id = String(msg?.key?.id || '');
  if (!id || typeof downloadMediaMessage !== 'function') return { mimetype, filename, size: declared, error: 'indisponivel' };
  if (declared > MAX_MEDIA_BYTES) return { mimetype, filename, size: declared, error: 'grande' };
  try {
    const buffer = await downloadMediaMessage(msg, 'buffer', {}, { logger: baileysLogger, reuploadRequest: state.sock?.updateMediaMessage });
    if (!buffer?.length) return { mimetype, filename, size: declared, error: 'vazio' };
    if (buffer.length > MAX_MEDIA_BYTES) return { mimetype, filename, size: buffer.length, error: 'grande' };
    const file = mediaFile(state.sid, id);
    await fsp.mkdir(path.dirname(file), { recursive: true, mode: 0o700 });
    await fsp.writeFile(file, buffer, { mode: 0o600 });
    await fsp.writeFile(file + '.json', JSON.stringify({ mimetype, filename }), { mode: 0o600 });
    return { id, mimetype, filename, size: buffer.length };
  } catch (error) {
    warn(`[empresa ${state.sid}] Não foi possível baixar a mídia ${id}:`, error?.message || error);
    return { mimetype, filename, size: declared, error: 'falha' };
  }
}

// Apaga mídias com mais de 1 dia (o sistema já copiou para a pasta dele).
async function cleanupMedia() {
  const limit = Date.now() - 24 * 3600 * 1000;
  let dirs = [];
  try { dirs = await fsp.readdir(MEDIA_DIR); } catch (_) { return; }
  for (const dir of dirs) {
    const full = path.join(MEDIA_DIR, dir);
    let files = [];
    try { files = await fsp.readdir(full); } catch (_) { continue; }
    for (const f of files) {
      try { const st = await fsp.stat(path.join(full, f)); if (st.mtimeMs < limit) await fsp.unlink(path.join(full, f)); } catch (_) {}
    }
  }
}

// Mantém a ordem das mensagens de cada conversa (a mídia demora para baixar).
const inboundChains = new Map();
function handleInboundOrdered(state, msg) {
  const k = `${state.sid}|${msg?.key?.remoteJid || ''}`;
  const next = (inboundChains.get(k) || Promise.resolve())
    .then(() => handleInbound(state, msg))
    .catch((error) => warn(`[empresa ${state.sid}] Falha ao tratar mensagem recebida:`, error?.message || error));
  inboundChains.set(k, next);
  next.finally(() => { if (inboundChains.get(k) === next) inboundChains.delete(k); });
}

async function handleInbound(state, msg) {
  const key = msg?.key || {};
  const remote = String(key.remoteJid || '');
  if (!remote || remote.endsWith('@g.us') || remote.endsWith('@broadcast') || remote.endsWith('@newsletter')) return;
  const id = String(key.id || '');
  // Mensagem própria: só interessa a digitada por uma pessoa no celular/WhatsApp
  // Web da empresa (as enviadas pela API ficam em state.recent).
  if (key.fromMe && (!id || state.recent.has(id))) return;
  if (!msg.message || msg.message.protocolMessage || msg.message.reactionMessage) return;
  const m = unwrapMessage(msg.message);
  let type = 'text';
  let text = m.conversation || m.extendedTextMessage?.text || '';
  if (!text && m.buttonsResponseMessage) { type = 'button'; text = m.buttonsResponseMessage.selectedButtonId || m.buttonsResponseMessage.selectedDisplayText || ''; }
  if (!text && m.listResponseMessage) { type = 'list'; text = m.listResponseMessage.singleSelectReply?.selectedRowId || m.listResponseMessage.title || ''; }
  if (!text && m.templateButtonReplyMessage) { type = 'button'; text = m.templateButtonReplyMessage.selectedId || ''; }
  if (m.imageMessage) { type = 'image'; text = text || m.imageMessage.caption || ''; }
  else if (m.documentMessage) { type = 'document'; text = text || m.documentMessage.caption || ''; }
  else if (m.audioMessage) type = 'audio';
  else if (m.videoMessage) { type = 'video'; text = text || m.videoMessage.caption || ''; }
  else if (m.stickerMessage) type = 'sticker';
  else if (m.locationMessage) { type = 'location'; text = `https://maps.google.com/?q=${m.locationMessage.degreesLatitude},${m.locationMessage.degreesLongitude}`; }
  else if (m.contactMessage) { type = 'contact'; text = String(m.contactMessage.displayName || '') + (m.contactMessage.vcard ? '\n' + String(m.contactMessage.vcard).slice(0, 1500) : ''); }
  else if (m.contactsArrayMessage) {
    // Vários contatos de uma vez: os vCards vão um embaixo do outro (o sistema separa).
    type = 'contact';
    text = (m.contactsArrayMessage.contacts || []).slice(0, 10)
      .map((c) => String(c?.vcard || '').slice(0, 1500) || `BEGIN:VCARD\nFN:${String(c?.displayName || '')}\nEND:VCARD`).join('\n');
  }
  if (!text && type === 'text') return; // reação, enquete etc.

  // Contatos novos podem vir como LID; o número real vem em remoteJidAlt/senderPn.
  const alt = String(key.remoteJidAlt || key.senderPn || msg.senderPn || '');
  const pnJid = remote.endsWith('@s.whatsapp.net') ? remote : (alt.endsWith('@s.whatsapp.net') ? alt : '');
  const payload = {
    event: key.fromMe ? 'human_reply' : 'message',
    company_id: state.sid,
    message_id: id,
    from: pnJid ? digits(pnJid.split('@')[0].split(':')[0]) : '',
    reply_jid: remote,
    push_name: key.fromMe ? '' : String(msg.pushName || ''),
    type,
    text: String(text).slice(0, 4000),
    timestamp: Number(msg.messageTimestamp?.low ?? msg.messageTimestamp ?? 0) || Math.floor(Date.now() / 1000),
  };
  if (['image', 'audio', 'video', 'document', 'sticker'].includes(type)) payload.media = await storeMedia(state, msg, m, type);
  deliverWebhook(payload);
}

function postJson(urlString, body) {
  return new Promise((resolve, reject) => {
    const url = new URL(urlString);
    const ts = String(Math.floor(Date.now() / 1000));
    const signature = WEBHOOK_SECRET ? crypto.createHmac('sha256', WEBHOOK_SECRET).update(`${ts}.${body}`).digest('hex') : '';
    const headers = {
      'Content-Type': 'application/json',
      'Content-Length': Buffer.byteLength(body),
      'X-Nexa-Timestamp': ts,
      'X-Nexa-Signature': signature,
      'User-Agent': 'nexa-wa-engine',
    };
    if (WEBHOOK_HOST) headers.Host = WEBHOOK_HOST;
    const lib = url.protocol === 'https:' ? https : http;
    const req = lib.request(url, { method: 'POST', headers, timeout: 15000 }, (res) => {
      res.resume();
      res.on('end', () => (res.statusCode >= 200 && res.statusCode < 300 ? resolve() : reject(new Error(`HTTP ${res.statusCode}`))));
    });
    req.on('timeout', () => req.destroy(new Error('tempo esgotado')));
    req.on('error', reject);
    req.end(body);
  });
}

function deliverWebhook(payload) {
  const body = JSON.stringify(payload);
  const delays = [0, 2000, 10000, 30000];
  (async () => {
    for (let i = 0; i < delays.length; i += 1) {
      if (delays[i]) await sleep(delays[i]);
      try {
        await postJson(WEBHOOK_URL, body);
        return;
      } catch (error) {
        if (i === delays.length - 1) warn(`[empresa ${payload.company_id}] Webhook de mensagem recebida falhou: ${error?.message || error}`);
      }
    }
  })();
}

// ---------------------------------------------------------------------------
// Respostas no formato do serviço antigo
// ---------------------------------------------------------------------------
function legacyStatus(state) {
  if (state.status === 'connected') return 'connected';
  if (state.status === 'qr') return 'qr';
  if (state.status === 'connecting') return 'initializing';
  return 'disconnected';
}
function statePayload(sid, state) {
  const status = legacyStatus(state);
  return {
    ok: true,
    company_id: sid,
    connected: status === 'connected',
    ready: status === 'connected',
    authenticated: Boolean(state.registered),
    initializing: status === 'initializing',
    status,
    qr: status === 'qr' ? state.qr : '',
    lastError: state.lastError,
    recovery_mode: 'auto_reconnect',
    updatedAt: state.updatedAt,
    engine: 'baileys',
    phone: state.phone,
    inbound_enabled: Boolean(settings.inbound[sid]),
  };
}
function sessionItem(sid) {
  const state = sessions.get(sid);
  const stored = hasStoredCreds(sid);
  if (!state) {
    return { company_id: sid, status: stored ? 'stored' : 'disconnected', connected: false, ready: false, authenticated: stored, initializing: false, stored, last_error: '', updated_at: '', engine: 'baileys' };
  }
  const p = statePayload(sid, state);
  return { company_id: sid, status: p.status, connected: p.connected, ready: p.ready, authenticated: p.authenticated, initializing: p.initializing, stored, last_error: p.lastError, updated_at: p.updatedAt, engine: 'baileys', phone: p.phone };
}

async function logoutSession(sid) {
  const state = sessions.get(sid);
  if (state?.sock) {
    const sock = state.sock;
    state.generation += 1;
    state.sock = null;
    try { await Promise.race([sock.logout(), sleep(8000)]); } catch (_) {}
    try { sock.ev.removeAllListeners(); } catch (_) {}
    try { sock.end(undefined); } catch (_) {}
  }
  if (state) closeSocket(state);
  sessions.delete(sid);
  await flushWrites();
  await fsp.rm(authDirFor(sid), { recursive: true, force: true }).catch(() => {});
}

// ---------------------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------------------
function readBody(req) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    req.on('data', (chunk) => {
      size += chunk.length;
      if (size > MAX_BODY_BYTES) {
        reject(httpError(413, 'Arquivo muito grande para o serviço WhatsApp.'));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on('end', () => {
      if (!chunks.length) return resolve({});
      try {
        const parsed = JSON.parse(Buffer.concat(chunks).toString('utf8'));
        resolve(parsed && typeof parsed === 'object' ? parsed : {});
      } catch (_) {
        reject(httpError(400, 'JSON inválido.'));
      }
    });
    req.on('error', reject);
  });
}

function sendJson(res, status, data) {
  if (res.headersSent) return;
  const body = JSON.stringify(data);
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Content-Length': Buffer.byteLength(body) });
  res.end(body);
}

const denied = (res) => sendJson(res, 401, { ok: false, error: 'Token inválido.' });

const routes = {
  'GET /health': async (_req, res) => {
    const list = [...sessions.values()];
    sendJson(res, 200, {
      ok: true,
      service: 'nexa-whatsapp',
      service_version: SERVICE_VERSION,
      engine: 'baileys',
      baileys_version: BAILEYS_VERSION,
      document_send: true,
      background_polling: false,
      sessions_total: list.length,
      sessions_connected: list.filter((s) => s.status === 'connected').length,
      memory_mb: Math.round(process.memoryUsage().rss / 1048576),
      uptime_s: Math.round(process.uptime()),
      inbound_webhook: Boolean(WEBHOOK_URL),
      time: nowIso(),
    });
  },

  'GET /status': async (req, res, _body, query) => {
    const sid = safeId(query.get('company_id'));
    if (!isCompany(req, sid)) return denied(res);
    const state = getState(sid);
    if (!state.sock && state.status !== 'connecting') {
      if (settings.stopped[sid]) {
        delete settings.stopped[sid];
        await saveSettings();
      }
      await startSession(sid);
    }
    sendJson(res, 200, statePayload(sid, state));
  },

  'GET /qr': async (req, res, body, query) => routes['GET /status'](req, res, body, query),

  'POST /send': async (req, res, body) => {
    const sid = safeId(body.company_id);
    if (!isCompany(req, sid)) return denied(res);
    const to = normalizePhone(body.to);
    const message = String(body.message || '').trim();
    if ((!to && !body.jid) || !message) return sendJson(res, 400, { ok: false, error: 'Destino e mensagem são obrigatórios.' });
    const sent = await sendContent(sid, to, body.jid, { text: message });
    sendJson(res, 200, { ok: true, company_id: sid, to, jid: sent.jid, message_id: sent.id });
  },

  'POST /send-document': async (req, res, body) => {
    const sid = safeId(body.company_id);
    if (!isCompany(req, sid)) return denied(res);
    const to = normalizePhone(body.to);
    const caption = String(body.caption || '').trim();
    const filename = path.basename(String(body.filename || 'documento.pdf')).replace(/[^0-9A-Za-zÀ-ÿ._-]+/g, '-') || 'documento.pdf';
    const mimetype = String(body.mimetype || 'application/pdf').trim();
    const b64 = String(body.file_base64 || '').replace(/^data:[^;]+;base64,/, '').replace(/\s+/g, '');
    if (!to && !body.jid) return sendJson(res, 400, { ok: false, error: 'Destino inválido.' });
    if (!b64) return sendJson(res, 400, { ok: false, error: 'Arquivo Base64 não informado.' });
    if (!/^[A-Za-z0-9+/]*={0,2}$/.test(b64)) return sendJson(res, 400, { ok: false, error: 'Arquivo Base64 inválido.' });
    const buffer = Buffer.from(b64, 'base64');
    if (!buffer.length || buffer.length > MAX_DOCUMENT_BYTES) return sendJson(res, 413, { ok: false, error: 'O arquivo deve possuir no máximo 16 MB.' });
    // kind: document (padrão), image, video ou audio.
    const kind = String(body.kind || 'document');
    let content;
    if (kind === 'image') content = { image: buffer, mimetype };
    else if (kind === 'video') content = { video: buffer, mimetype };
    else if (kind === 'audio') content = { audio: buffer, mimetype, ptt: Boolean(body.ptt) };
    else content = { document: buffer, mimetype, fileName: filename };
    if (caption && kind !== 'audio') content.caption = caption;
    const sent = await sendContent(sid, to, body.jid, content);
    sendJson(res, 200, { ok: true, company_id: sid, to, filename, size: buffer.length, jid: sent.jid, message_id: sent.id });
  },

  // Envia um contato (cartão de visita vCard) para o cliente.
  'POST /send-contact': async (req, res, body) => {
    const sid = safeId(body.company_id);
    if (!isCompany(req, sid)) return denied(res);
    const to = normalizePhone(body.to);
    const name = String(body.name || '').replace(/[\r\n;]+/g, ' ').trim().slice(0, 120);
    const phone = normalizePhone(body.phone);
    if ((!to && !body.jid) || !name || phone.length < 10) return sendJson(res, 400, { ok: false, error: 'Informe o destino, o nome e o telefone do contato.' });
    const org = String(body.company || '').replace(/[\r\n;]+/g, ' ').trim().slice(0, 120);
    const vcard = ['BEGIN:VCARD', 'VERSION:3.0', `FN:${name}`, org ? `ORG:${org};` : '', `TEL;type=CELL;type=VOICE;waid=${phone}:+${phone}`, 'END:VCARD'].filter(Boolean).join('\n');
    const sent = await sendContent(sid, to, body.jid, { contacts: { displayName: name, contacts: [{ vcard }] } });
    sendJson(res, 200, { ok: true, company_id: sid, to, jid: sent.jid, message_id: sent.id });
  },

  'GET /media': async (req, res, _body, query) => {
    const sid = safeId(query.get('company_id'));
    if (!isCompany(req, sid)) return denied(res);
    const file = mediaFile(sid, String(query.get('id') || ''));
    let meta = {};
    let data;
    try {
      data = await fsp.readFile(file);
      meta = JSON.parse(await fsp.readFile(file + '.json', 'utf8'));
    } catch (_) {
      return sendJson(res, 404, { ok: false, error: 'Mídia não encontrada.' });
    }
    res.writeHead(200, { 'Content-Type': String(meta.mimetype || 'application/octet-stream'), 'Content-Length': data.length, 'Cache-Control': 'no-store' });
    res.end(data);
  },

  'POST /logout': async (req, res, body) => {
    const sid = safeId(body.company_id);
    if (!isCompany(req, sid)) return denied(res);
    await logoutSession(sid);
    sendJson(res, 200, { ok: true, company_id: sid, message: 'WhatsApp desconectado.' });
  },

  'POST /tokens/upsert': async (req, res, body) => {
    const sid = safeId(body.company_id);
    const token = String(body.token || '').trim();
    if (token.length < 32 || token.length > 256) return sendJson(res, 400, { ok: false, error: 'Token da empresa inválido.' });
    const current = String(companyTokens[sid] || '');
    // Trocar um token existente: só com o token global ou o próprio token atual.
    // Primeiro cadastro: também com qualquer token já registrado (compatível com o antigo).
    const allowed = isCompany(req, sid) || (!current && companiesForToken(req).length > 0);
    if (!allowed) return sendJson(res, 401, { ok: false, error: 'Token inválido para registrar a empresa.' });
    companyTokens[sid] = token;
    await saveTokens();
    sendJson(res, 200, { ok: true, company_id: sid, message: 'Token registrado.' });
  },

  'POST /tokens/remove': async (req, res, body) => {
    const sid = safeId(body.company_id);
    if (!isCompany(req, sid)) return denied(res);
    delete companyTokens[sid];
    await saveTokens();
    sendJson(res, 200, { ok: true, company_id: sid, message: 'Token removido.' });
  },

  'POST /inbound/config': async (req, res, body) => {
    const sid = safeId(body.company_id);
    if (!isCompany(req, sid)) return denied(res);
    if (body.enabled) settings.inbound[sid] = true;
    else delete settings.inbound[sid];
    await saveSettings();
    sendJson(res, 200, { ok: true, company_id: sid, inbound_enabled: Boolean(settings.inbound[sid]), webhook_configured: Boolean(WEBHOOK_URL) });
  },

  'GET /admin/sessions': async (req, res) => {
    let ids;
    if (isGlobal(req)) {
      ids = new Set([...storedIds(), ...sessions.keys()]);
    } else {
      const own = companiesForToken(req);
      if (!own.length) return sendJson(res, 401, { ok: false, error: 'Acesso administrativo não autorizado.' });
      ids = new Set(own);
    }
    sendJson(res, 200, { ok: true, engine: 'baileys', items: [...ids].map(sessionItem) });
  },

  'POST /admin/session/start': async (req, res, body) => {
    const sid = safeId(body.company_id);
    if (!isCompany(req, sid)) return denied(res);
    delete settings.stopped[sid];
    await saveSettings();
    const state = await startSession(sid);
    sendJson(res, 200, statePayload(sid, state));
  },

  'POST /admin/session/restart': async (req, res, body) => {
    const sid = safeId(body.company_id);
    if (!isCompany(req, sid)) return denied(res);
    const state = getState(sid);
    if (state.status === 'connected' && state.sock) return sendJson(res, 200, { ...statePayload(sid, state), restarted: false, message: 'Sessão já está conectada.' });
    closeSocket(state);
    state.retries = 0;
    delete settings.stopped[sid];
    await saveSettings();
    await startSession(sid);
    sendJson(res, 200, { ...statePayload(sid, state), restarted: true, message: 'Sessão reaberta.' });
  },

  'POST /admin/session/stop': async (req, res, body) => {
    const sid = safeId(body.company_id);
    if (!isCompany(req, sid)) return denied(res);
    const state = getState(sid);
    closeSocket(state);
    setStatus(state, 'idle', 'Sessão parada pelo suporte.');
    settings.stopped[sid] = true;
    await saveSettings();
    sendJson(res, 200, { ...statePayload(sid, state), message: 'Sessão parada (o login foi mantido).' });
  },

  'POST /admin/session/new-qr': async (req, res, body) => {
    const sid = safeId(body.company_id);
    if (!isCompany(req, sid)) return denied(res);
    await logoutSession(sid);
    delete settings.stopped[sid];
    await saveSettings();
    const state = await startSession(sid);
    sendJson(res, 200, { ...statePayload(sid, state), message: 'Novo QR Code sendo gerado.' });
  },

  'POST /admin/shutdown-sessions': async (req, res) => {
    if (!isGlobal(req)) return sendJson(res, 401, { ok: false, error: 'Acesso administrativo não autorizado.' });
    const total = sessions.size;
    for (const state of sessions.values()) closeSocket(state);
    await flushWrites();
    sendJson(res, 200, { ok: true, closed: total });
  },
};

const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url || '/', 'http://localhost');
    const handler = routes[`${req.method} ${url.pathname}`];
    if (!handler) return sendJson(res, 404, { ok: false, error: 'Rota não encontrada.' });
    const body = req.method === 'POST' ? await readBody(req) : {};
    await handler(req, res, body, url.searchParams);
  } catch (error) {
    const status = Number(error?.status) || 500;
    if (status >= 500) warn('[http]', req.method, req.url?.split('?')[0], error?.message || error);
    sendJson(res, status, { ok: false, error: String(error?.message || 'Erro interno.') });
  }
});
server.requestTimeout = 120000;
server.headersTimeout = 30000;

// ---------------------------------------------------------------------------
// Início e encerramento
// ---------------------------------------------------------------------------
process.on('unhandledRejection', (error) => warn('[aviso] Promessa rejeitada sem tratamento:', error?.message || error));
process.on('uncaughtException', (error) => {
  console.error(new Date().toISOString(), '[erro] Falha inesperada; o systemd reinicia o serviço.', error);
  process.exit(1);
});

async function shutdown(signal) {
  if (shuttingDown) return;
  shuttingDown = true;
  log(`Encerrando (${signal})...`);
  setTimeout(() => process.exit(0), 15000).unref();
  for (const state of sessions.values()) closeSocket(state);
  await flushWrites();
  server.close(() => process.exit(0));
}
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));

server.listen(PORT, HOST, async () => {
  log(`Motor WhatsApp Nexa (${SERVICE_VERSION}, ${BAILEYS_PKG} ${BAILEYS_VERSION}) ouvindo em http://${HOST}:${PORT}`);
  if (!GLOBAL_TOKEN) warn('ATENÇÃO: API_TOKEN não definido; rotas administrativas ficam bloqueadas.');
  cleanupMedia().catch(() => {});
  setInterval(() => cleanupMedia().catch(() => {}), 3600 * 1000).unref();
  const ids = storedIds().filter((sid) => !settings.stopped[sid]);
  log(`Reabrindo ${ids.length} sessão(ões) salvas, uma a cada ${STARTUP_STAGGER_MS} ms.`);
  for (const sid of ids) {
    if (shuttingDown) break;
    startSession(sid).catch((error) => warn(`[empresa ${sid}] Falha ao abrir:`, error?.message || error));
    await sleep(STARTUP_STAGGER_MS);
  }
});

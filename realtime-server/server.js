'use strict';

const http = require('http');
const net = require('net');
const crypto = require('crypto');
const { URL } = require('url');

const SERVICE_NAME = 'nexa-realtime';
const SERVICE_VERSION = '2.0.0';
const recentWindowMs = Math.max(60_000, Number(process.env.NEXA_REALTIME_RECENT_WINDOW_MS || 10 * 60 * 1000));
const recentMaxPerCompany = Math.max(10, Number(process.env.NEXA_REALTIME_RECENT_MAX_PER_COMPANY || 100));

const config = {
  host: process.env.NEXA_REALTIME_WS_HOST || process.env.NEXA_BRAIN_WS_HOST || '0.0.0.0',
  port: Number(process.env.NEXA_REALTIME_WS_PORT || process.env.NEXA_BRAIN_WS_PORT || 8081),
  path: process.env.NEXA_REALTIME_WS_PATH || process.env.NEXA_BRAIN_WS_PATH || '/brain-ws',
  secret: process.env.NEXA_REALTIME_WS_SECRET || process.env.NEXA_BRAIN_WS_SECRET || '',
  allowedOrigins: (process.env.NEXA_REALTIME_ALLOWED_ORIGINS || process.env.NEXA_BRAIN_ALLOWED_ORIGINS || '').split(',').map(v => v.trim()).filter(Boolean),
  redisHost: process.env.REDIS_HOST || '127.0.0.1',
  redisPort: Number(process.env.REDIS_PORT || 6379),
  redisPassword: process.env.REDIS_PASSWORD || '',
  redisDatabase: Number(process.env.REDIS_DATABASE || 0),
  redisStream: process.env.NEXA_REALTIME_REDIS_STREAM || process.env.NEXA_BRAIN_REDIS_STREAM || 'nexa:brain:events',
};

if (!config.secret) {
  console.error('NEXA_REALTIME_WS_SECRET/NEXA_BRAIN_WS_SECRET não foi configurado.');
  process.exit(1);
}

const rooms = new Map();
let redisReady = false;
// Ao reiniciar o gateway, retoma uma pequena janela recente do Stream.
// Isso evita perder um pedido criado poucos minutos antes do Admin conectar,
// sem polling no MySQL e sem reprocessar o Stream inteiro.
let lastStreamId = `${Date.now() - recentWindowMs}-0`;
let totalBroadcasts = 0;
const recentEvents = new Map();

const json = (res, status, body) => {
  const payload = Buffer.from(JSON.stringify(body));
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': payload.length,
    'Cache-Control': 'no-store',
  });
  res.end(payload);
};

const server = http.createServer((req, res) => {
  if (req.url === '/health' || req.url === '/healthz') {
    let connections = 0;
    for (const clients of rooms.values()) connections += clients.size;
    return json(res, redisReady ? 200 : 503, {
      ok: redisReady,
      service: SERVICE_NAME,
      version: SERVICE_VERSION,
      pid: process.pid,
      uptimeSeconds: Math.round(process.uptime()),
      redis: redisReady ? 'connected' : 'disconnected',
      rooms: rooms.size,
      connections,
      broadcasts: totalBroadcasts,
      stream: config.redisStream,
      lastStreamId,
      now: new Date().toISOString(),
    });
  }
  return json(res, 404, { ok: false, message: 'Not found' });
});

function base64urlDecode(value) {
  const normalized = value.replace(/-/g, '+').replace(/_/g, '/');
  const padding = normalized.length % 4 ? '='.repeat(4 - (normalized.length % 4)) : '';
  return Buffer.from(normalized + padding, 'base64');
}

function validateToken(token) {
  if (!token || !token.includes('.')) throw new Error('Token ausente.');
  const [body, signature] = token.split('.', 2);
  const expected = crypto.createHmac('sha256', config.secret).update(body).digest();
  const received = base64urlDecode(signature);
  if (received.length !== expected.length || !crypto.timingSafeEqual(received, expected)) throw new Error('Assinatura inválida.');
  const payload = JSON.parse(base64urlDecode(body).toString('utf8'));
  if (!payload.company_id || Number(payload.company_id) <= 0) throw new Error('Empresa inválida.');
  if (!payload.exp || Number(payload.exp) < Math.floor(Date.now() / 1000)) throw new Error('Token expirado.');
  return { companyId: Number(payload.company_id), userId: Number(payload.user_id || 0), exp: Number(payload.exp) };
}

function normalizeOrigin(value) {
  try {
    const parsed = new URL(String(value || ''));
    if (!['http:', 'https:'].includes(parsed.protocol)) return null;
    if (parsed.username || parsed.password || parsed.pathname !== '/' || parsed.search || parsed.hash) return null;
    return {
      protocol: parsed.protocol.toLowerCase(),
      hostname: parsed.hostname.toLowerCase().replace(/\.$/, ''),
      port: parsed.port,
      origin: parsed.origin.toLowerCase(),
    };
  } catch (_) {
    return null;
  }
}

function originMatchesPattern(origin, pattern) {
  const normalizedOrigin = normalizeOrigin(origin);
  if (!normalizedOrigin) return false;

  const value = String(pattern || '').trim();
  if (!value.includes('*')) {
    const normalizedPattern = normalizeOrigin(value);
    return !!normalizedPattern && normalizedOrigin.origin === normalizedPattern.origin;
  }

  const wildcard = value.match(/^(https?):\/\/\*\.([a-z0-9.-]+)(?::(\d+))?$/i);
  if (!wildcard) return false;

  const protocol = `${wildcard[1].toLowerCase()}:`;
  const rootDomain = wildcard[2].toLowerCase().replace(/\.$/, '');
  const expectedPort = wildcard[3] || '';
  const host = normalizedOrigin.hostname;

  return normalizedOrigin.protocol === protocol
    && normalizedOrigin.port === expectedPort
    && host !== rootDomain
    && host.endsWith(`.${rootDomain}`);
}

function originAllowed(origin) {
  if (!config.allowedOrigins.length) return true;
  return config.allowedOrigins.some(pattern => originMatchesPattern(origin, pattern));
}

function websocketAccept(key) {
  return crypto.createHash('sha1').update(key + '258EAFA5-E914-47DA-95CA-C5AB0DC85B11').digest('base64');
}

function frame(opcode, payload = Buffer.alloc(0)) {
  payload = Buffer.isBuffer(payload) ? payload : Buffer.from(String(payload));
  const length = payload.length;
  let header;
  if (length < 126) {
    header = Buffer.alloc(2);
    header[1] = length;
  } else if (length <= 0xffff) {
    header = Buffer.alloc(4);
    header[1] = 126;
    header.writeUInt16BE(length, 2);
  } else {
    header = Buffer.alloc(10);
    header[1] = 127;
    header.writeBigUInt64BE(BigInt(length), 2);
  }
  header[0] = 0x80 | (opcode & 0x0f);
  return Buffer.concat([header, payload]);
}

function sendJson(client, payload) {
  if (!client.socket.destroyed && client.socket.writable) {
    client.socket.write(frame(0x1, Buffer.from(JSON.stringify(payload))));
  }
}

function closeClient(client) {
  const clients = rooms.get(client.companyId);
  if (clients) {
    clients.delete(client);
    if (!clients.size) rooms.delete(client.companyId);
  }
  try { client.socket.destroy(); } catch (_) {}
}

function processClientFrames(client, chunk) {
  client.buffer = Buffer.concat([client.buffer, chunk]);
  while (client.buffer.length >= 2) {
    const first = client.buffer[0];
    const second = client.buffer[1];
    const opcode = first & 0x0f;
    const masked = (second & 0x80) !== 0;
    let length = second & 0x7f;
    let offset = 2;
    if (length === 126) {
      if (client.buffer.length < 4) return;
      length = client.buffer.readUInt16BE(2);
      offset = 4;
    } else if (length === 127) {
      if (client.buffer.length < 10) return;
      const big = client.buffer.readBigUInt64BE(2);
      if (big > BigInt(Number.MAX_SAFE_INTEGER)) return closeClient(client);
      length = Number(big);
      offset = 10;
    }
    const maskLength = masked ? 4 : 0;
    if (client.buffer.length < offset + maskLength + length) return;
    let payload = client.buffer.subarray(offset + maskLength, offset + maskLength + length);
    if (masked) {
      const mask = client.buffer.subarray(offset, offset + 4);
      const unmasked = Buffer.alloc(payload.length);
      for (let i = 0; i < payload.length; i++) unmasked[i] = payload[i] ^ mask[i % 4];
      payload = unmasked;
    }
    client.buffer = client.buffer.subarray(offset + maskLength + length);
    if (opcode === 0x8) return closeClient(client);
    if (opcode === 0x9) client.socket.write(frame(0xA, payload));
    if (opcode === 0xA) client.alive = true;
  }
}

server.on('upgrade', (req, socket) => {
  try {
    const parsed = new URL(req.url, 'http://localhost');
    if (parsed.pathname !== config.path) throw new Error('Rota WebSocket inválida.');
    if (!originAllowed(req.headers.origin)) throw new Error('Origin não permitida.');
    const auth = validateToken(parsed.searchParams.get('token') || '');
    const key = req.headers['sec-websocket-key'];
    if (!key || String(req.headers.upgrade || '').toLowerCase() !== 'websocket') throw new Error('Handshake inválido.');

    socket.write([
      'HTTP/1.1 101 Switching Protocols',
      'Upgrade: websocket',
      'Connection: Upgrade',
      `Sec-WebSocket-Accept: ${websocketAccept(key)}`,
      '\r\n',
    ].join('\r\n'));

    const client = { socket, companyId: auth.companyId, userId: auth.userId, alive: true, buffer: Buffer.alloc(0) };
    if (!rooms.has(client.companyId)) rooms.set(client.companyId, new Set());
    rooms.get(client.companyId).add(client);
    socket.setNoDelay(true);
    socket.on('data', chunk => processClientFrames(client, chunk));
    socket.on('close', () => closeClient(client));
    socket.on('error', () => closeClient(client));
    sendJson(client, { type: 'welcome', companyId: client.companyId, connectedAt: new Date().toISOString(), service: SERVICE_NAME, version: SERVICE_VERSION });
    // Entrega eventos recentes ainda válidos caso o Admin tenha sido aberto após o pedido.
    // Continua 100% orientado a eventos/Redis; não consulta o MySQL.
    replayRecent(client);
  } catch (error) {
    socket.write('HTTP/1.1 401 Unauthorized\r\nConnection: close\r\nContent-Type: text/plain\r\n\r\nUnauthorized');
    socket.destroy();
  }
});

setInterval(() => {
  for (const clients of rooms.values()) {
    for (const client of clients) {
      if (!client.alive) { closeClient(client); continue; }
      client.alive = false;
      try { client.socket.write(frame(0x9, Buffer.from('ping'))); } catch (_) { closeClient(client); }
    }
  }
}, 30000).unref();

class RespConnection {
  constructor() {
    this.socket = null;
    this.buffer = Buffer.alloc(0);
    this.pending = [];
    this.closed = false;
  }

  async connect() {
    await new Promise((resolve, reject) => {
      const socket = net.createConnection({ host: config.redisHost, port: config.redisPort });
      const fail = error => reject(error);
      socket.once('error', fail);
      socket.once('connect', () => {
        socket.removeListener('error', fail);
        this.socket = socket;
        socket.setNoDelay(true);
        socket.on('data', chunk => this.onData(chunk));
        socket.on('error', error => this.onClose(error));
        socket.on('close', () => this.onClose(new Error('Redis encerrou a conexão.')));
        resolve();
      });
    });
    if (config.redisPassword) await this.command(['AUTH', config.redisPassword]);
    if (config.redisDatabase > 0) await this.command(['SELECT', String(config.redisDatabase)]);
  }

  encode(args) {
    const parts = [`*${args.length}\r\n`];
    for (const arg of args) {
      const value = Buffer.from(String(arg));
      parts.push(`$${value.length}\r\n`, value, '\r\n');
    }
    return Buffer.concat(parts.map(part => Buffer.isBuffer(part) ? part : Buffer.from(part)));
  }

  command(args) {
    if (!this.socket || this.closed) return Promise.reject(new Error('Redis desconectado.'));
    return new Promise((resolve, reject) => {
      this.pending.push({ resolve, reject });
      this.socket.write(this.encode(args));
    });
  }

  parse(offset = 0) {
    if (offset >= this.buffer.length) return null;
    const prefix = String.fromCharCode(this.buffer[offset]);
    const lineEnd = this.buffer.indexOf('\r\n', offset + 1);
    if (lineEnd < 0) return null;
    const line = this.buffer.subarray(offset + 1, lineEnd).toString();
    if (prefix === '+' || prefix === '-' || prefix === ':') {
      return { value: prefix === ':' ? Number(line) : line, error: prefix === '-', bytes: lineEnd + 2 - offset };
    }
    if (prefix === '$') {
      const length = Number(line);
      if (length < 0) return { value: null, bytes: lineEnd + 2 - offset };
      const start = lineEnd + 2;
      if (this.buffer.length < start + length + 2) return null;
      return { value: this.buffer.subarray(start, start + length).toString(), bytes: start + length + 2 - offset };
    }
    if (prefix === '*') {
      const count = Number(line);
      if (count < 0) return { value: null, bytes: lineEnd + 2 - offset };
      const values = [];
      let cursor = lineEnd + 2;
      for (let i = 0; i < count; i++) {
        const parsed = this.parse(cursor);
        if (!parsed) return null;
        values.push(parsed.value);
        cursor += parsed.bytes;
      }
      return { value: values, bytes: cursor - offset };
    }
    throw new Error('Resposta RESP inválida.');
  }

  onData(chunk) {
    this.buffer = Buffer.concat([this.buffer, chunk]);
    while (this.pending.length) {
      let parsed;
      try { parsed = this.parse(0); } catch (error) { return this.onClose(error); }
      if (!parsed) return;
      this.buffer = this.buffer.subarray(parsed.bytes);
      const pending = this.pending.shift();
      if (parsed.error) pending.reject(new Error(parsed.value)); else pending.resolve(parsed.value);
    }
  }

  onClose(error) {
    if (this.closed) return;
    this.closed = true;
    redisReady = false;
    while (this.pending.length) this.pending.shift().reject(error);
    try { this.socket?.destroy(); } catch (_) {}
  }
}

function fieldsToObject(fields) {
  const out = {};
  if (!Array.isArray(fields)) return out;
  for (let i = 0; i + 1 < fields.length; i += 2) out[String(fields[i])] = fields[i + 1];
  return out;
}

function eventTimestamp(message) {
  const value = Date.parse(message.occurredAt || '');
  if (Number.isFinite(value)) return value;
  const streamMs = Number(String(message.streamId || '').split('-', 1)[0]);
  return Number.isFinite(streamMs) && streamMs > 0 ? streamMs : Date.now();
}

function pruneRecent(companyId) {
  const list = recentEvents.get(companyId) || [];
  const minTs = Date.now() - recentWindowMs;
  const kept = list.filter(item => eventTimestamp(item) >= minTs).slice(-recentMaxPerCompany);
  if (kept.length) recentEvents.set(companyId, kept); else recentEvents.delete(companyId);
  return kept;
}

function rememberEvent(message) {
  const companyId = Number(message.companyId || 0);
  if (!companyId) return;
  const list = pruneRecent(companyId);
  list.push(message);
  recentEvents.set(companyId, list.slice(-recentMaxPerCompany));
}

function replayRecent(client) {
  const list = pruneRecent(client.companyId);
  for (const item of list) sendJson(client, { ...item, replay: true });
}

function broadcastEvent(fields, streamId = '') {
  const companyId = Number(fields.company_id || 0);
  if (!companyId) return;
  let payload = {};
  try { payload = JSON.parse(fields.payload || '{}'); } catch (_) {}
  const message = {
    type: 'brain.updated',
    companyId,
    eventType: fields.event_type || '',
    entityType: fields.entity_type || '',
    entityId: Number(fields.entity_id || 0),
    dashboardChanged: fields.dashboard_changed === '1',
    occurredAt: fields.occurred_at || null,
    cacheGeneratedAt: fields.cache_generated_at || null,
    streamId: String(streamId || ''),
    payload,
  };
  rememberEvent(message);
  const clients = rooms.get(companyId);
  if (!clients) return;
  for (const client of clients) sendJson(client, message);
  totalBroadcasts += clients.size;
}

async function consumeRedisStream() {
  for (;;) {
    let redis;
    try {
      redis = new RespConnection();
      await redis.connect();
      redisReady = true;
      console.log(`[${new Date().toISOString()}] Redis conectado. Stream=${config.redisStream}`);
      for (;;) {
        const reply = await redis.command(['XREAD', 'BLOCK', '5000', 'COUNT', '100', 'STREAMS', config.redisStream, lastStreamId]);
        if (!reply) continue;
        for (const stream of reply) {
          const entries = Array.isArray(stream) ? stream[1] : [];
          for (const entry of entries || []) {
            lastStreamId = String(entry[0]);
            broadcastEvent(fieldsToObject(entry[1]), lastStreamId);
          }
        }
      }
    } catch (error) {
      redisReady = false;
      console.error(`[${new Date().toISOString()}] Redis/Stream: ${error.message}`);
      try { redis?.onClose(error); } catch (_) {}
      await new Promise(resolve => setTimeout(resolve, 2000));
    }
  }
}

server.listen(config.port, config.host, () => {
  console.log(`Nexa Realtime ${SERVICE_VERSION} em http://${config.host}:${config.port}${config.path}`);
});

consumeRedisStream().catch(error => {
  console.error(error);
  process.exit(1);
});

function shutdown() {
  console.log('Encerrando Nexa Realtime WebSocket...');
  for (const clients of rooms.values()) for (const client of clients) closeClient(client);
  server.close(() => process.exit(0));
  setTimeout(() => process.exit(1), 5000).unref();
}
process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);

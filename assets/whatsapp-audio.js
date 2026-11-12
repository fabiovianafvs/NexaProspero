/*
 * Nexa Próspero - gravação de áudio do Atendimento WhatsApp.
 *
 * O WhatsApp só toca mensagem de voz em Ogg/Opus. O Chrome/Edge gravam Opus
 * dentro de WebM; este arquivo troca só a "embalagem" (WebM -> Ogg), sem
 * recompactar o som. O Firefox já grava em Ogg. O Safari grava MP4/AAC, que
 * vai como arquivo de áudio comum.
 *
 * window.NexaAudio.webmToOgg(ArrayBuffer) -> Uint8Array (Ogg/Opus)
 * window.NexaAudio.Recorder: start(), stop() -> Promise<File>, cancel()
 */
(function (root) {
  'use strict';

  // ---------- WebM (EBML) ----------
  const ID_SEGMENT = 0x18538067, ID_TRACKS = 0x1654AE6B, ID_TRACK_ENTRY = 0xAE, ID_CODEC_PRIVATE = 0x63A2,
    ID_CODEC_ID = 0x86, ID_CLUSTER = 0x1F43B675, ID_SIMPLE_BLOCK = 0xA3, ID_BLOCK_GROUP = 0xA0, ID_BLOCK = 0xA1;
  const MASTERS = new Set([ID_SEGMENT, ID_TRACKS, ID_TRACK_ENTRY, ID_CLUSTER, ID_BLOCK_GROUP]);

  function readId(b, p) {
    const first = b[p];
    let len = 1;
    if (first >= 0x80) len = 1; else if (first >= 0x40) len = 2; else if (first >= 0x20) len = 3; else if (first >= 0x10) len = 4; else return null;
    if (p + len > b.length) return null;
    let v = 0;
    for (let i = 0; i < len; i++) v = v * 256 + b[p + i];
    return { v, len };
  }

  function readSize(b, p) {
    const first = b[p];
    if (first === undefined || first === 0) return null;
    let len = 1, mask = 0x80;
    while (!(first & mask)) { len++; mask >>= 1; }
    if (p + len > b.length) return null;
    let v = first & (mask - 1), allOnes = v === mask - 1;
    for (let i = 1; i < len; i++) { v = v * 256 + b[p + i]; if (b[p + i] !== 0xFF) allOnes = false; }
    return { v: allOnes ? -1 : v, len };
  }

  function parseWebm(buf) {
    const b = new Uint8Array(buf);
    let p = 0, head = null, codec = '';
    const packets = [];
    // Pula o cabeçalho EBML.
    const h = readId(b, 0);
    if (!h || h.v !== 0x1A45DFA3) throw new Error('Arquivo WebM inválido.');
    const hs = readSize(b, h.len);
    p = h.len + hs.len + hs.v;
    while (p < b.length) {
      const id = readId(b, p); if (!id) break;
      const sz = readSize(b, p + id.len); if (!sz) break;
      const data = p + id.len + sz.len;
      if (MASTERS.has(id.v)) { p = data; continue; } // entra no elemento (tamanho pode ser "desconhecido")
      if (sz.v < 0 || data + sz.v > b.length) break; // gravação cortada no fim
      if (id.v === ID_CODEC_PRIVATE) head = b.slice(data, data + sz.v);
      else if (id.v === ID_CODEC_ID) codec = String.fromCharCode.apply(null, b.subarray(data, data + sz.v));
      else if (id.v === ID_SIMPLE_BLOCK || id.v === ID_BLOCK) {
        const tn = readSize(b, data);
        const flags = b[data + tn.len + 2];
        if (flags & 0x06) throw new Error('WebM com lacing não suportado.');
        const start = data + tn.len + 3;
        if (start < data + sz.v) packets.push(b.slice(start, data + sz.v));
      }
      p = data + sz.v;
    }
    if (codec && codec !== 'A_OPUS') throw new Error('O áudio gravado não está em Opus.');
    if (!packets.length) throw new Error('Nenhum áudio gravado.');
    return { head, packets };
  }

  // ---------- Opus ----------
  function opusSamples(pkt) {
    if (!pkt.length) return 0;
    const toc = pkt[0], cfg = toc >> 3;
    let fs;
    if (cfg < 12) fs = [480, 960, 1920, 2880][cfg % 4];
    else if (cfg < 16) fs = [480, 960][cfg % 2];
    else fs = [120, 240, 480, 960][cfg % 4];
    const c = toc & 3;
    const frames = c === 0 ? 1 : (c < 3 ? 2 : (pkt.length > 1 ? pkt[1] & 0x3F : 0));
    return fs * frames;
  }

  function opusHead(channels) {
    const h = new Uint8Array(19);
    h.set([0x4F, 0x70, 0x75, 0x73, 0x48, 0x65, 0x61, 0x64]); // OpusHead
    h[8] = 1; h[9] = channels; h[10] = 0x38; h[11] = 0x01; // pre-skip 312
    h[12] = 0x80; h[13] = 0xBB; h[14] = 0; h[15] = 0; // 48000 Hz
    return h;
  }

  // ---------- Ogg ----------
  const CRC = (() => {
    const t = new Uint32Array(256);
    for (let i = 0; i < 256; i++) {
      let r = i << 24;
      for (let j = 0; j < 8; j++) r = (r & 0x80000000) ? ((r << 1) ^ 0x04C11DB7) : (r << 1);
      t[i] = r >>> 0;
    }
    return t;
  })();
  function crc32(b) {
    let c = 0;
    for (let i = 0; i < b.length; i++) c = ((c << 8) ^ CRC[((c >>> 24) ^ b[i]) & 0xFF]) >>> 0;
    return c >>> 0;
  }

  function oggPage(packets, granule, serial, seq, flags) {
    const seg = [];
    packets.forEach((pk) => { let n = pk.length; while (n >= 255) { seg.push(255); n -= 255; } seg.push(n); });
    const bodyLen = packets.reduce((a, pk) => a + pk.length, 0);
    const page = new Uint8Array(27 + seg.length + bodyLen);
    const dv = new DataView(page.buffer);
    page.set([0x4F, 0x67, 0x67, 0x53]); // OggS
    page[4] = 0; page[5] = flags;
    dv.setUint32(6, granule % 0x100000000, true);
    dv.setUint32(10, Math.floor(granule / 0x100000000), true);
    dv.setUint32(14, serial, true);
    dv.setUint32(18, seq, true);
    page[26] = seg.length;
    page.set(seg, 27);
    let o = 27 + seg.length;
    packets.forEach((pk) => { page.set(pk, o); o += pk.length; });
    dv.setUint32(22, crc32(page), true);
    return page;
  }

  function webmToOgg(buf) {
    const { head: rawHead, packets } = parseWebm(buf);
    const isHead = rawHead && rawHead.length >= 19 && String.fromCharCode.apply(null, rawHead.subarray(0, 8)) === 'OpusHead';
    const head = isHead ? rawHead : opusHead(1);
    const serial = (Math.random() * 0xFFFFFFFF) >>> 0;
    const vendor = 'Nexa Prospero';
    const tags = new Uint8Array(8 + 4 + vendor.length + 4);
    tags.set([0x4F, 0x70, 0x75, 0x73, 0x54, 0x61, 0x67, 0x73]); // OpusTags
    new DataView(tags.buffer).setUint32(8, vendor.length, true);
    for (let i = 0; i < vendor.length; i++) tags[12 + i] = vendor.charCodeAt(i);
    const pages = [oggPage([head], 0, serial, 0, 0x02), oggPage([tags], 0, serial, 1, 0)];
    let seq = 2, granule = 0, cur = [], segs = 0;
    for (let i = 0; i < packets.length; i++) {
      const pk = packets[i], need = Math.floor(pk.length / 255) + 1;
      if (cur.length && (segs + need > 255 || cur.length >= 50)) {
        pages.push(oggPage(cur, granule, serial, seq++, 0));
        cur = []; segs = 0;
      }
      cur.push(pk); segs += need; granule += opusSamples(pk);
    }
    pages.push(oggPage(cur, granule, serial, seq, 0x04));
    const total = pages.reduce((a, pg) => a + pg.length, 0);
    const out = new Uint8Array(total);
    let o = 0;
    pages.forEach((pg) => { out.set(pg, o); o += pg.length; });
    return out;
  }

  // ---------- Gravador ----------
  function pickType() {
    if (typeof MediaRecorder === 'undefined') return null;
    const types = ['audio/ogg;codecs=opus', 'audio/webm;codecs=opus', 'audio/mp4'];
    for (const t of types) { try { if (MediaRecorder.isTypeSupported(t)) return t; } catch (_) {} }
    return '';
  }

  class Recorder {
    constructor() { this.rec = null; this.stream = null; this.chunks = []; this.type = ''; this.startedAt = 0; }
    static supported() { return !!(root.navigator && navigator.mediaDevices && navigator.mediaDevices.getUserMedia && pickType() !== null); }
    async start() {
      this.type = pickType() || '';
      this.stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } });
      this.chunks = [];
      const opts = this.type ? { mimeType: this.type, audioBitsPerSecond: 32000 } : {};
      this.rec = new MediaRecorder(this.stream, opts);
      this.rec.ondataavailable = (e) => { if (e.data && e.data.size) this.chunks.push(e.data); };
      this.rec.start(250);
      this.startedAt = Date.now();
    }
    seconds() { return this.startedAt ? Math.floor((Date.now() - this.startedAt) / 1000) : 0; }
    release() {
      if (this.stream) this.stream.getTracks().forEach((t) => t.stop());
      this.stream = null; this.startedAt = 0;
    }
    cancel() {
      try { if (this.rec && this.rec.state !== 'inactive') { this.rec.ondataavailable = null; this.rec.stop(); } } catch (_) {}
      this.chunks = []; this.release();
    }
    stop() {
      return new Promise((resolve, reject) => {
        if (!this.rec || this.rec.state === 'inactive') { this.release(); reject(new Error('Nenhuma gravação em andamento.')); return; }
        this.rec.onstop = async () => {
          this.release();
          try {
            const type = (this.rec.mimeType || this.type || '').toLowerCase();
            const blob = new Blob(this.chunks, { type });
            const stamp = new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '');
            if (type.indexOf('ogg') >= 0) resolve(new File([blob], 'audio-' + stamp + '.ogg', { type: 'audio/ogg' }));
            else if (type.indexOf('webm') >= 0) resolve(new File([webmToOgg(await blob.arrayBuffer())], 'audio-' + stamp + '.ogg', { type: 'audio/ogg' }));
            else resolve(new File([blob], 'audio-' + stamp + '.m4a', { type: 'audio/mp4' }));
          } catch (e) { reject(e); }
        };
        this.rec.stop();
      });
    }
  }

  root.NexaAudio = { webmToOgg, opusSamples, Recorder };
})(typeof window !== 'undefined' ? window : globalThis);

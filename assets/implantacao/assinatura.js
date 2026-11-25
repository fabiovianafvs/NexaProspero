/* Nexa — quadro de assinatura simples (dedo ou mouse) para a Trilha de Implantação.
   Uso: const pad = NexaAssinatura(document.querySelector('canvas'));
        pad.clear(); pad.isEmpty(); pad.toDataURL() (PNG com fundo branco). */
(function (w) {
  w.NexaAssinatura = function (canvas) {
    const ctx = canvas.getContext('2d');
    let drawing = false, last = null, strokes = 0;
    function fit() {
      const r = canvas.getBoundingClientRect(), dpr = Math.max(1, w.devicePixelRatio || 1);
      const data = strokes ? canvas.toDataURL() : null;
      canvas.width = Math.round(r.width * dpr); canvas.height = Math.round(r.height * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, r.width, r.height);
      ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0b1f5b';
      if (data) { const img = new Image(); img.onload = () => ctx.drawImage(img, 0, 0, r.width, r.height); img.src = data; }
    }
    function pos(e) { const r = canvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
    canvas.addEventListener('pointerdown', e => { e.preventDefault(); canvas.setPointerCapture(e.pointerId); drawing = true; last = pos(e); ctx.beginPath(); ctx.arc(last.x, last.y, 1.1, 0, Math.PI * 2); ctx.fillStyle = '#0b1f5b'; ctx.fill(); strokes++; });
    canvas.addEventListener('pointermove', e => { if (!drawing) return; e.preventDefault(); const p = pos(e); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke(); last = p; });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(t => canvas.addEventListener(t, () => { drawing = false; }));
    w.addEventListener('resize', () => { if (!drawing) fit(); });
    fit();
    return {
      clear() { strokes = 0; fit(); },
      isEmpty() { return strokes === 0; },
      toDataURL() { return canvas.toDataURL('image/png'); }
    };
  };
})(window);

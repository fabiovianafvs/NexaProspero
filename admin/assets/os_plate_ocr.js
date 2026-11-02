/* Nexa Prospero | OS - Placa Inteligente v2 | 2026-10-19
 * Leitura AUTOMATICA: fotografa -> localiza e le a placa -> consulta interna.
 * Sem servico de consulta pago; OCR no navegador por Tesseract.js 6.0.1.
 * Recorte manual e digitacao apenas como alternativas quando o OCR falha.
 */
(function () {
  'use strict';
  var CDN = [
    'https://cdn.jsdelivr.net/npm/tesseract.js@6.0.1/dist/tesseract.min.js',
    'https://unpkg.com/tesseract.js@6.0.1/dist/tesseract.min.js'
  ];
  var ocrPromise = null;
  var initialized = false;

  function addStyles() {
    if (document.getElementById('os-plate-ocr-style')) return;
    var style = document.createElement('style');
    style.id = 'os-plate-ocr-style';
    style.textContent = [
      '.os-plate-camera-btn{white-space:nowrap;min-width:0;display:inline-flex;align-items:center;justify-content:center;gap:6px}',
      '.os-plate-camera-icon{font-size:17px;line-height:1}',
      '.os-plate-file-input{display:none!important}',
      '.os-plate-ocr-row{display:flex;flex-wrap:wrap;gap:7px}',
      '.os-module .os-plate-ocr-row>input.os-plate-input{flex:1 1 100%;min-width:0}',
      '.os-module .os-plate-ocr-row>.btn{flex:1 1 auto;min-width:0}',
      '.os-plate-ocr-backdrop{position:fixed;inset:0;z-index:2200;display:none;align-items:center;justify-content:center;padding:12px;background:rgba(8,21,42,.72)}',
      '.os-plate-ocr-backdrop.open{display:flex}',
      '.os-plate-ocr-card{width:min(660px,100%);max-height:94dvh;overflow:auto;background:#fff;border-radius:16px;box-shadow:0 28px 70px rgba(0,0,0,.25);color:#243447}',
      '.os-plate-ocr-head{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e1e8f0;padding:12px 16px;gap:8px}',
      '.os-plate-ocr-head b{font-size:16px;color:#163e71}',
      '.os-plate-ocr-body{padding:12px 16px}',
      '.os-plate-ocr-hint{font-size:12px;margin:0 0 10px;color:#52677b;line-height:1.5}',
      '.os-plate-ocr-canvas{touch-action:none;box-sizing:border-box;width:100%;height:auto;display:block;border:1px solid #cad6e3;border-radius:8px;background:#eff4f8;cursor:default}',
      '.os-plate-ocr-canvas.selecting{cursor:crosshair}',
      '.os-plate-ocr-status{font-size:13px;min-height:20px;margin:10px 0 0;color:#334e6f;line-height:1.45}',
      '.os-plate-ocr-status.error{color:#ab2e25}',
      '.os-plate-ocr-actions{display:flex;flex-wrap:wrap;gap:9px;justify-content:flex-end;padding:10px 16px 15px}',
      '.os-plate-ocr-actions button{min-height:39px}',
      '.os-plate-ocr-actions button:disabled{opacity:.6;cursor:wait}',
      'body.os-plate-ocr-open{overflow:hidden}',
      '.os-module .os-plate-camera-btn{min-height:38px}',
      '.mobile-plate-row .os-plate-camera-btn{width:100%;min-height:48px}',
      '@media(max-width:420px){.os-module .os-plate-ocr-row>.btn{flex:1 1 100%}}',
      '@media(max-width:450px){.os-plate-ocr-actions button{flex:1 1 100%}}'
    ].join('\n');
    document.head.appendChild(style);
  }
  function loadScript(url) {
    return new Promise(function (resolve, reject) {
      var tag = document.createElement('script');
      tag.async = true;
      tag.src = url;
      tag.onload = resolve;
      tag.onerror = function () { tag.remove(); reject(new Error('Falha ao carregar OCR.')); };
      document.head.appendChild(tag);
    });
  }
  function loadOCR() {
    if (window.Tesseract && typeof window.Tesseract.createWorker === 'function') return Promise.resolve();
    if (!ocrPromise) ocrPromise = loadScript(CDN[0]).catch(function () { return loadScript(CDN[1]); })
      .then(function () {
        if (!window.Tesseract || typeof window.Tesseract.createWorker !== 'function') throw new Error('OCR indisponível.');
      }).catch(function (error) { ocrPromise = null; throw error; });
    return ocrPromise;
  }
  function asLetter(c) { return ({'0':'O','1':'I','2':'Z','4':'A','5':'S','6':'G','8':'B'})[c] || c; }
  function asDigit(c) { return ({'O':'0','Q':'0','D':'0','I':'1','L':'1','Z':'2','S':'5','G':'6','B':'8','T':'7'})[c] || c; }
  function extractPlate(text) {
    // Processar LINHAS e tokens, evitando associar caracteres de placas a palavras distantes.
    var lines = String(text || '').toUpperCase().split(/[\r\n]+/);
    var options = [];
    for (var i = 0; i < lines.length; i++) {
      var words = lines[i].match(/[A-Z0-9]{1,15}/g) || [];
      for (var k = 0; k < words.length; k++) {
        options.push(words[k]);
        if (k + 1 < words.length && words[k].length + words[k + 1].length <= 10) options.push(words[k] + words[k + 1]);
        if (k + 2 < words.length && words[k].length + words[k + 1].length + words[k + 2].length === 7) options.push(words[k] + words[k + 1] + words[k + 2]);
      }
      var line = lines[i].replace(/[^A-Z0-9]/g, '');
      if (line.length >= 7 && line.length <= 14) options.push(line);
    }
    for (var j = 0; j < options.length; j++) {
      var input = options[j];
      for (var p = 0; p <= input.length - 7; p++) {
        var a = input.substr(p, 7).split('');
        for (var n = 0; n < 3; n++) a[n] = asLetter(a[n]);
        a[3] = asDigit(a[3]);
        a[5] = asDigit(a[5]);
        a[6] = asDigit(a[6]);
        var result = a.join('');
        if (/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/.test(result)) return result;
      }
    }
    return '';
  }
  function clamp(v, low, high) { return Math.max(low, Math.min(high, v)); }

  function init() {
    if (initialized) return;
    var field = document.querySelector('[data-semantic="vehicle_plate"]');
    var lookup = document.querySelector('[data-plate-lookup]') || document.getElementById('mobilePlateLookup');
    if (!field || !lookup || !lookup.parentNode) return;
    initialized = true;
    addStyles();
    var msg = document.getElementById('vehicleLookupMessage') || document.getElementById('mobileVehicleMessage');
    var camera = document.createElement('button');
    camera.type = 'button';
    camera.className = 'btn btn-outline btn-sm nowrap os-plate-camera-btn';
    camera.setAttribute('aria-label', 'Fotografar placa para reconhecimento automático');
    camera.innerHTML = '<span class="os-plate-camera-icon" aria-hidden="true">📷</span> Fotografar placa';
    var file = document.createElement('input');
    file.type = 'file';
    file.accept = 'image/*';
    file.setAttribute('capture', 'environment');
    file.className = 'os-plate-file-input';
    file.setAttribute('aria-label', 'Fotografia da placa');
    lookup.parentNode.classList.add('os-plate-ocr-row');
    lookup.insertAdjacentElement('afterend', camera);
    camera.insertAdjacentElement('afterend', file);

    var overlay = document.createElement('div');
    overlay.className = 'os-plate-ocr-backdrop';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Leitura automática da placa');
    overlay.innerHTML = '<div class="os-plate-ocr-card">' +
      '<div class="os-plate-ocr-head"><b>Leitura automática da placa</b><button class="btn btn-secondary btn-sm" type="button" data-os-plate-close>Fechar</button></div>' +
      '<div class="os-plate-ocr-body"><p class="os-plate-ocr-hint">A identificação começa automaticamente após a foto, sem precisar marcar ou ampliar a placa. Se não reconhecer, você poderá ajustar o recorte ou digitar a placa.</p>' +
      '<canvas class="os-plate-ocr-canvas" aria-label="Fotografia da placa"></canvas>' +
      '<p class="os-plate-ocr-status" aria-live="polite">Aguardando fotografia...</p></div>' +
      '<div class="os-plate-ocr-actions"><button type="button" class="btn btn-secondary" data-os-plate-new>Outra foto</button><button type="button" class="btn btn-secondary" data-os-plate-area hidden>Ajustar recorte</button><button type="button" class="btn btn-primary" data-os-plate-read hidden>Ler área marcada</button></div></div>';
    document.body.appendChild(overlay);
    var canvas = overlay.querySelector('canvas');
    var ctx = canvas.getContext('2d', { willReadFrequently: true });
    var status = overlay.querySelector('.os-plate-ocr-status');
    var newButton = overlay.querySelector('[data-os-plate-new]');
    var areaButton = overlay.querySelector('[data-os-plate-area]');
    var readButton = overlay.querySelector('[data-os-plate-read]');
    var closeButton = overlay.querySelector('[data-os-plate-close]');
    var photoCanvas = null; // Fonte de pixels ORIGINAL: nenhuma marcação é incorporada ao OCR.
    var imageURL = null, image = null, selection = null, dragStart = null, selecting = false;
    var busy = false, previousFocus = null;

    function say(text, error) {
      status.textContent = text;
      status.classList.toggle('error', !!error);
    }
    function notice(text) { if (msg) msg.textContent = text; }
    function redraw() {
      if (!photoCanvas) return;
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(photoCanvas, 0, 0);
      if (selection && selection.w > 4 && selection.h > 4) {
        ctx.fillStyle = 'rgba(3,24,44,.30)';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        // Cropping com coordenadas da MESMA imagem redimensionada; corrige seleção deslocada.
        ctx.drawImage(photoCanvas, selection.x, selection.y, selection.w, selection.h, selection.x, selection.y, selection.w, selection.h);
        ctx.lineWidth = Math.max(2, canvas.width / 400);
        ctx.strokeStyle = '#0ce0ff';
        ctx.strokeRect(selection.x, selection.y, selection.w, selection.h);
      }
    }
    function clearPhoto() {
      if (imageURL) URL.revokeObjectURL(imageURL);
      imageURL = null; image = null; photoCanvas = null; selection = null;
      dragStart = null; selecting = false; file.value = '';
    }
    function hide() {
      if (busy) return;
      overlay.classList.remove('open');
      document.body.classList.remove('os-plate-ocr-open');
      clearPhoto();
      if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
    }
    function setBusy(value) {
      busy = value;
      closeButton.disabled = value;
      newButton.disabled = value;
      areaButton.disabled = value;
      readButton.disabled = value;
    }
    function setFallback() {
      areaButton.hidden = false;
      readButton.hidden = false;
      areaButton.textContent = selecting ? 'Desmarcar recorte' : 'Ajustar recorte';
    }
    function showPhoto(blob) {
      if (!blob || (blob.type && !/^image\//i.test(blob.type))) { notice('Escolha uma fotografia válida.'); return; }
      if (blob.size > 15 * 1024 * 1024) { notice('Fotografia acima de 15 MB. Tente outra.'); return; }
      clearPhoto();
      previousFocus = document.activeElement;
      imageURL = URL.createObjectURL(blob);
      var pic = new Image();
      pic.onload = function () {
        image = pic;
        var scale = Math.min(1, 1800 / Math.max(pic.naturalWidth, pic.naturalHeight));
        canvas.width = Math.max(1, Math.round(pic.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(pic.naturalHeight * scale));
        photoCanvas = document.createElement('canvas');
        photoCanvas.width = canvas.width; photoCanvas.height = canvas.height;
        photoCanvas.getContext('2d', { willReadFrequently: true }).drawImage(pic, 0, 0, canvas.width, canvas.height);
        selection = null; selecting = false;
        areaButton.hidden = true; readButton.hidden = true;
        canvas.classList.remove('selecting');
        overlay.classList.add('open');
        document.body.classList.add('os-plate-ocr-open');
        redraw();
        say('Identificando automaticamente a placa na fotografia...', false);
        // Inicia a leitura assim que a imagem é carregada: SEM clique adicional ou seleção.
        recognize(false);
      };
      pic.onerror = function () { clearPhoto(); notice('Não foi possível abrir a imagem. Tente outra.'); };
      pic.src = imageURL;
    }
    function getPoint(ev) {
      var rect = canvas.getBoundingClientRect();
      var borderLeft = canvas.clientLeft || 0, borderTop = canvas.clientTop || 0;
      var imageW = Math.max(1, canvas.clientWidth);
      var imageH = Math.max(1, canvas.clientHeight);
      return { x: clamp((ev.clientX - rect.left - borderLeft) * canvas.width / imageW, 0, canvas.width),
               y: clamp((ev.clientY - rect.top - borderTop) * canvas.height / imageH, 0, canvas.height) };
    }
    canvas.addEventListener('pointerdown', function (ev) {
      if (busy || !photoCanvas || !selecting) return;
      ev.preventDefault();
      dragStart = getPoint(ev); selection = null;
      if (canvas.setPointerCapture) canvas.setPointerCapture(ev.pointerId);
    });
    canvas.addEventListener('pointermove', function (ev) {
      if (!dragStart || busy || !selecting) return;
      var p = getPoint(ev);
      selection = { x: Math.min(dragStart.x, p.x), y: Math.min(dragStart.y, p.y),
                    w: Math.abs(p.x - dragStart.x), h: Math.abs(p.y - dragStart.y) };
      redraw();
    });
    function finishDrag() {
      dragStart = null;
      if (selection && (selection.w < 12 || selection.h < 8)) selection = null;
      redraw();
    }
    canvas.addEventListener('pointerup', finishDrag);
    canvas.addEventListener('pointercancel', finishDrag);
    areaButton.addEventListener('click', function () {
      if (busy) return;
      if (selecting) { selecting = false; selection = null; say('Recorte desmarcado. Arraste para marcar novamente, se necessário.', false); }
      else { selecting = true; say('Arraste sobre a placa na fotografia e toque em Ler área marcada.', false); }
      canvas.classList.toggle('selecting', selecting);
      areaButton.textContent = selecting ? 'Desmarcar recorte' : 'Ajustar recorte';
      redraw();
    });
    closeButton.addEventListener('click', hide);
    overlay.addEventListener('click', function (ev) { if (ev.target === overlay) hide(); });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && overlay.classList.contains('open')) { ev.preventDefault(); hide(); }
    });
    camera.addEventListener('click', function () { if (!busy) file.click(); });
    newButton.addEventListener('click', function () { if (!busy) file.click(); });
    file.addEventListener('change', function () { if (file.files && file.files[0] && !busy) showPhoto(file.files[0]); });

    function makeCrop(region, withContrast, lineMode) {
      var r = region || { x: 0, y: 0, w: photoCanvas.width, h: photoCanvas.height };
      // Garante crop válido mesmo com arrasto no sentido inverso e pixels nas bordas.
      var x = clamp(Math.round(r.x), 0, photoCanvas.width - 1);
      var y = clamp(Math.round(r.y), 0, photoCanvas.height - 1);
      var rw = clamp(Math.round(r.w), 1, photoCanvas.width - x);
      var rh = clamp(Math.round(r.h), 1, photoCanvas.height - y);
      var maxW = lineMode ? 1650 : 1600;
      var zoom = Math.min(3, maxW / rw);
      // Imagem inteira não precisa ser ampliada artificialmente.
      if (!region) zoom = Math.min(1, maxW / rw, 1200 / rh);
      var outputW = Math.max(1, Math.round(rw * zoom));
      var outputH = Math.max(1, Math.round(rh * zoom));
      if (outputH > 1200) {
        outputW = Math.max(1, Math.round(outputW * 1200 / outputH));
        outputH = 1200;
      }
      var margin = lineMode ? Math.round(outputH * 0.09) + 5 : 0;
      var result = document.createElement('canvas');
      result.width = outputW + margin * 2; result.height = outputH + margin * 2;
      var c = result.getContext('2d', { willReadFrequently: true });
      if (margin) { c.fillStyle = '#ffffff'; c.fillRect(0, 0, result.width, result.height); }
      c.drawImage(photoCanvas, x, y, rw, rh, margin, margin, outputW, outputH);
      if (withContrast) {
        var pixels = c.getImageData(margin, margin, outputW, outputH), data = pixels.data;
        for (var k = 0; k < data.length; k += 4) {
          var luminance = .299 * data[k] + .587 * data[k + 1] + .114 * data[k + 2];
          var contrasted = clamp((luminance - 128) * 1.65 + 128, 0, 255);
          data[k] = data[k + 1] = data[k + 2] = contrasted;
        }
        c.putImageData(pixels, margin, margin);
      }
      return result;
    }

    function autoRegions() {
      // Detector leve de regiões com concentração de bordas verticais, típico
      // dos sete caracteres da placa. 100% JavaScript local, sem OpenCV/API.
      var w = photoCanvas.width, h = photoCanvas.height;
      var factor = Math.min(1, 680 / Math.max(w, h));
      var sw = Math.max(1, Math.round(w * factor)), sh = Math.max(1, Math.round(h * factor));
      var sample = document.createElement('canvas'); sample.width = sw; sample.height = sh;
      var sctx = sample.getContext('2d', { willReadFrequently: true });
      sctx.drawImage(photoCanvas, 0, 0, sw, sh);
      var pixel = sctx.getImageData(0, 0, sw, sh).data;
      var gray = new Uint8Array(sw * sh);
      for (var i = 0; i < gray.length; i++) {
        var j = i * 4;
        gray[i] = Math.round(.299 * pixel[j] + .587 * pixel[j + 1] + .114 * pixel[j + 2]);
      }
      var stride = sw + 1;
      var integral = new Float64Array((sw + 1) * (sh + 1));
      for (var yy = 1; yy <= sh; yy++) {
        var sum = 0;
        for (var xx = 1; xx <= sw; xx++) {
          var index = (yy - 1) * sw + (xx - 1);
          // Diferença lateral: bordas dos traços verticais (A, B, 0, 1 ...).
          var diff = xx > 1 ? Math.abs(gray[index] - gray[index - 1]) : 0;
          sum += diff > 25 ? 1 : 0;
          integral[yy * stride + xx] = integral[(yy - 1) * stride + xx] + sum;
        }
      }
      function count(x, y, ww, hh) {
        x = Math.max(0, Math.floor(x)); y = Math.max(0, Math.floor(y));
        ww = Math.min(sw - x, Math.floor(ww)); hh = Math.min(sh - y, Math.floor(hh));
        if (ww < 1 || hh < 1) return 0;
        var a = y * stride + x, b = a + ww, c = a + hh * stride;
        return integral[c + ww] - integral[b] - integral[c] + integral[a];
      }
      var scores = [];
      // Várias escalas: placa pequena ou aproximada, na parte alta ou baixa.
      [0.12, .18, .26, .36].forEach(function (part) {
        var rh = Math.max(26, Math.round(sh * part));
        var rw = Math.min(sw, Math.round(rh * 3.9));
        if (rw < 65 || rh >= sh || rw >= sw * .99) return;
        var sy = Math.max(8, Math.floor(rh / 3));
        var sx = Math.max(10, Math.floor(rw / 4));
        for (var y = 0; y <= sh - rh; y += sy) {
          for (var x = 0; x <= sw - rw; x += sx) {
            var total = count(x, y, rw, rh);
            var mid = count(x + rw * .07, y + rh * .19, rw * .86, rh * .65);
            var edgeRatio = mid / (rw * rh * .86 * .65);
            if (edgeRatio < .016) continue;
            var outer = (total - mid) / (rw * rh * (1 - .86 * .65));
            var center = 1 - .12 * Math.abs((x + rw / 2) / sw - .5);
            var score = (edgeRatio - .15 * outer) * center;
            scores.push({ x: x, y: y, w: rw, h: rh, score: score });
          }
        }
      });
      scores.sort(function (a, b) { return b.score - a.score; });
      var top = [];
      for (var n = 0; n < scores.length && top.length < 4; n++) {
        var t = scores[n], cx = t.x + t.w / 2, cy = t.y + t.h / 2;
        var duplicate = top.some(function (chosen) {
          return Math.abs(cx - (chosen.x + chosen.w / 2)) < Math.min(t.w, chosen.w) * .42 &&
                 Math.abs(cy - (chosen.y + chosen.h / 2)) < Math.min(t.h, chosen.h) * .56;
        });
        if (duplicate) continue;
        top.push(t);
      }
      return top.map(function (r) {
        // 12% de folga em volta evita cortar caracteres próximos da moldura.
        var padX = r.w * .1, padY = r.h * .12;
        var x = Math.max(0, (r.x - padX) / factor), y = Math.max(0, (r.y - padY) / factor);
        var right = Math.min(w, (r.x + r.w + padX) / factor);
        var bottom = Math.min(h, (r.y + r.h + padY) / factor);
        return { x: x, y: y, w: right - x, h: bottom - y };
      });
    }

    async function recognize(manual) {
      if (busy || !photoCanvas) return;
      if (manual && (!selection || selection.w < 12 || selection.h < 8)) {
        say('Marque a placa com o dedo ou mouse antes de tocar em Ler área marcada.', true);
        return;
      }
      setBusy(true);
      var worker = null, found = '';
      try {
        say('Preparando leitura automática gratuita...', false);
        await loadOCR();
        worker = await window.Tesseract.createWorker('eng', 1, {
          logger: function (ev) {
            if (ev && ev.status === 'loading language traineddata') say('Carregando caracteres para leitura...', false);
          }
        });
        await worker.setParameters({
          tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
          preserve_interword_spaces: '0',
          tessedit_pageseg_mode: '11'
        });
        async function attempt(region, segMode, enhance, label) {
          say(label, false);
          await worker.setParameters({ tessedit_pageseg_mode: segMode });
          var result = await worker.recognize(makeCrop(region, enhance, segMode === '7'));
          return extractPlate(result && result.data && result.data.text);
        }
        if (manual) {
          found = await attempt(selection, '7', false, 'Lendo a área selecionada...');
          if (!found) found = await attempt(selection, '7', true, 'Refinando contraste da área selecionada...');
        } else {
          // Primeiro, texto esparso na foto INTEIRA; depois busca automática
          // de regiões com traços de placa, sem pedir ao usuário para desenhar.
          found = await attempt(null, '11', false, 'Procurando a placa na fotografia...');
          if (!found && photoCanvas.width / photoCanvas.height > 1.55 && photoCanvas.width / photoCanvas.height < 5.5) {
            found = await attempt(null, '7', false, 'Reconhecendo os caracteres da placa...');
          }
          if (!found) {
            var regions = autoRegions();
            for (var n = 0; n < regions.length && !found; n++) {
              found = await attempt(regions[n], '7', n > 1, 'Localizando placa automaticamente (' + (n + 1) + '/' + regions.length + ')...');
            }
          }
          if (!found) found = await attempt(null, '11', true, 'Tentando leitura com contraste aprimorado...');
        }
        if (!found) throw new Error('Não identifiquei uma placa nesta foto. Você pode fotografar mais perto, ajustar o recorte ou digitar a placa manualmente.');
        field.value = found;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        notice('Placa reconhecida: ' + found + '. Consultando cadastro interno. Confira os caracteres antes de salvar.');
        field.dispatchEvent(new Event('nexa-plate-recognized', { bubbles: true }));
        say('Placa ' + found + ' reconhecida e consultada no Nexa.', false);
      } catch (err) {
        say((err && err.message) || 'Não foi possível reconhecer a placa.', true);
        notice('Placa não reconhecida. Você pode digitar a placa normalmente ou tentar outra foto.');
        setFallback();
      } finally {
        if (worker) { try { await worker.terminate(); } catch (_ignore) {} }
        setBusy(false);
        if (found) { hide(); field.focus(); }
      }
    }
    readButton.addEventListener('click', function () { recognize(true); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

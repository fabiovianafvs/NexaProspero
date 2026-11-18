/* Nexa Ponto — câmera e reconhecimento do rosto (V10).
 * Usado pelo Bater Ponto, pelo terminal e pelo cadastro do rosto.
 * O "modelo" do rosto (128 números) é calculado aqui no aparelho com a face-api (MIT, guardada em
 * /assets/vendor/face-api); o servidor só compara números e guarda a foto. Nada vai para terceiros. */
(function () {
  'use strict';
  var F = window.NexaFace = window.NexaFace || {};
  var cfg = { modo: 'DESLIGADO', bloqueia: false, base: '/assets/vendor/face-api' };
  var loading = null;

  F.init = function (c) { for (var k in c) cfg[k] = c[k]; if (cfg.modo === 'RECONHECER') F.load(); return F; };
  F.ativo = function () { return cfg.modo === 'FOTO' || cfg.modo === 'RECONHECER'; };

  function script(src) {
    return new Promise(function (ok, fail) {
      var s = document.createElement('script'); s.src = src; s.async = true;
      s.onload = ok; s.onerror = function () { fail(new Error('Não foi possível carregar o reconhecimento facial.')); };
      document.head.appendChild(s);
    });
  }

  /** Carrega a biblioteca e os modelos (uma vez; ~8 MB, ficam no cache do navegador). */
  F.load = function () {
    if (loading) return loading;
    loading = (window.faceapi ? Promise.resolve() : script(cfg.base + '/face-api.js')).then(function () {
      var M = cfg.base + '/model', n = window.faceapi.nets;
      return Promise.all([n.tinyFaceDetector.loadFromUri(M), n.faceLandmark68TinyNet.loadFromUri(M), n.faceRecognitionNet.loadFromUri(M)]);
    });
    loading.catch(function () { loading = null; });
    return loading;
  };

  /** Modelo do rosto de uma imagem/canvas/vídeo: "n1,n2,...,n128" ou null (nenhum ou mais de um rosto). */
  F.descritor = function (el) {
    return F.load().then(function () {
      var fa = window.faceapi;
      return fa.detectAllFaces(el, new fa.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.5 })).withFaceLandmarks(true).withFaceDescriptors();
    }).then(function (dets) {
      if (!dets || dets.length !== 1) return null;
      return Array.prototype.map.call(dets[0].descriptor, function (v) { return Math.round(v * 1e6) / 1e6; }).join(',');
    });
  };

  var css = '.nf-ov{position:fixed;inset:0;z-index:9999;background:rgba(7,27,85,.92);display:flex;align-items:center;justify-content:center;padding:14px}'
    + '.nf-box{background:#fff;border-radius:20px;max-width:440px;width:100%;padding:16px;text-align:center;font-family:inherit}'
    + '.nf-box h3{margin:0 0 4px;color:#0b1f5b;font-size:18px}.nf-sub{margin:0 0 10px;color:#475569;font-size:13px}'
    + '.nf-cam{position:relative;border-radius:16px;overflow:hidden;background:#0f172a;aspect-ratio:4/3}'
    + '.nf-cam video{width:100%;height:100%;object-fit:cover;transform:scaleX(-1);display:block}'
    + '.nf-cam:after{content:"";position:absolute;left:50%;top:48%;width:46%;height:70%;transform:translate(-50%,-50%);border:3px dashed rgba(255,255,255,.75);border-radius:50%}'
    + '.nf-st{min-height:20px;margin:10px 0;font-weight:700;color:#334155;font-size:14px}.nf-st.err{color:#b91c1c}'
    + '.nf-row{display:flex;gap:8px;justify-content:center;flex-wrap:wrap}'
    + '.nf-btn{min-height:46px;padding:0 18px;border-radius:14px;border:1px solid #cbd5e1;background:#fff;font-weight:800;font-size:15px;cursor:pointer;color:#0b1f5b}'
    + '.nf-btn.pri{background:#2453d4;border-color:#2453d4;color:#fff}.nf-btn:disabled{opacity:.55}';

  function ui(titulo, sub, botao) {
    if (!document.getElementById('nfCss')) { var st = document.createElement('style'); st.id = 'nfCss'; st.textContent = css; document.head.appendChild(st); }
    var ov = document.createElement('div'); ov.className = 'nf-ov';
    ov.innerHTML = '<div class="nf-box" role="dialog" aria-modal="true"><h3></h3><p class="nf-sub"></p><div class="nf-cam"><video autoplay playsinline muted></video></div>'
      + '<div class="nf-st" aria-live="polite"></div><div class="nf-row"><button type="button" class="nf-btn" data-a="x">Cancelar</button><button type="button" class="nf-btn pri" data-a="ok"></button></div></div>';
    ov.querySelector('h3').textContent = titulo; ov.querySelector('.nf-sub').textContent = sub; ov.querySelector('[data-a=ok]').textContent = botao;
    document.body.appendChild(ov);
    return ov;
  }

  /**
   * Abre a câmera e tira a foto. Resolve com {foto: dataURL JPEG|null, descritor: string|null, erro: string|null}
   * ou null se a pessoa cancelar. opts: titulo, sub, botao, exigeRosto (cadastro), reconhecer (bool).
   */
  F.capturar = function (opts) {
    opts = opts || {};
    var reconhecer = opts.reconhecer !== undefined ? opts.reconhecer : cfg.modo === 'RECONHECER';
    var exige = opts.exigeRosto !== undefined ? opts.exigeRosto : cfg.bloqueia;
    if (reconhecer) F.load();
    return new Promise(function (resolve) {
      var ov = ui(opts.titulo || 'Olhe para a câmera', opts.sub || 'Centralize o rosto no contorno e toque no botão.', opts.botao || 'Registrar com foto');
      var video = ov.querySelector('video'), st = ov.querySelector('.nf-st'), ok = ov.querySelector('[data-a=ok]'), stream = null, done = false;
      function msg(t, err) { st.textContent = t || ''; st.className = 'nf-st' + (err ? ' err' : ''); }
      function fim(r) { if (done) return; done = true; if (stream) stream.getTracks().forEach(function (t) { t.stop(); }); ov.remove(); resolve(r); }
      ov.querySelector('[data-a=x]').addEventListener('click', function () { fim(null); });
      ok.disabled = true; msg('Abrindo a câmera…');
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { semCamera('Este aparelho não permite usar a câmera aqui.'); return; }
      navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } }, audio: false }).then(function (s) {
        stream = s; video.srcObject = s;
        return video.play();
      }).then(function () {
        ok.disabled = false; msg(reconhecer ? 'Preparando o reconhecimento…' : '');
        if (reconhecer) F.load().then(function () { if (!done) msg(''); }, function (e) { if (!done) msg(e.message, true); });
      }).catch(function (e) {
        semCamera(e && e.name === 'NotAllowedError' ? 'A câmera foi bloqueada. Permita o uso da câmera para este site.' : 'Não foi possível abrir a câmera.');
      });
      function semCamera(t) {
        msg(t, true);
        if (exige) { ok.hidden = true; return; }
        ok.disabled = false; ok.textContent = 'Continuar sem foto';
        ok.onclick = function () { fim({ foto: null, descritor: null, erro: t }); };
      }
      ok.addEventListener('click', function () {
        if (!stream || ok.onclick) return;
        var w = video.videoWidth || 640, h = video.videoHeight || 480, sc = Math.min(1, 480 / w);
        var cv = document.createElement('canvas'); cv.width = Math.round(w * sc); cv.height = Math.round(h * sc);
        cv.getContext('2d').drawImage(video, 0, 0, cv.width, cv.height);
        var foto = cv.toDataURL('image/jpeg', 0.82);
        if (!reconhecer) { fim({ foto: foto, descritor: null, erro: null }); return; }
        ok.disabled = true; msg('Conferindo o rosto…');
        F.descritor(cv).then(function (d) {
          if (!d && exige) { ok.disabled = false; msg('Rosto não encontrado. Fique de frente, com boa luz, e tente de novo.', true); return; }
          fim({ foto: foto, descritor: d, erro: d ? null : 'sem rosto' });
        }, function (e) {
          if (exige) { ok.disabled = false; msg(e.message || 'Falha no reconhecimento.', true); return; }
          fim({ foto: foto, descritor: null, erro: 'falha' });
        });
      });
    });
  };
})();

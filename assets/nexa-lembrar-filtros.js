/*
 * Nexa Próspero - Lembra a última pesquisa de uma tela de consulta.
 *
 * Uso: no <form method="get"> de filtros, adicione
 *   data-lembrar-filtros="nome-da-tela"
 *   data-lembrar-extra="pg,por_pagina"          (opcional: outros parâmetros a lembrar)
 *   data-lembrar-ignorar="edit,new,settle"      (opcional: modos em que não restaura)
 *   data-lembrar-somente-retorno="1"             (opcional: restaura só ao voltar da própria tela)
 * e inclua este script logo DEPOIS do formulário (sem defer).
 *
 * Como funciona:
 *  - Ao pesquisar, guarda os filtros da URL na sessionStorage da aba.
 *  - Ao voltar para a tela "limpa" (ex.: depois de editar e salvar), restaura os
 *    filtros guardados, mantendo mensagens como ?ok=... e o contexto mc_ctx.
 *  - O botão Limpar deve apontar para "tela.php?limpar_filtros=1".
 * Cada aba do navegador tem a sua própria memória; fechar a aba apaga.
 */
(function () {
  'use strict';
  try {
    var form = document.querySelector('form[data-lembrar-filtros]');
    if (!form || !window.sessionStorage || !window.URLSearchParams) return;

    var page = form.getAttribute('data-lembrar-filtros') || location.pathname;
    var list = function (attr) {
      return (form.getAttribute(attr) || '').split(',').map(function (v) { return v.trim(); }).filter(Boolean);
    };
    var ignore = list('data-lembrar-ignorar');
    var keys = {};
    list('data-lembrar-extra').forEach(function (k) { keys[k] = true; });
    Array.prototype.forEach.call(form.querySelectorAll('[name]'), function (el) {
      if (el.name && el.name !== 'mc_ctx' && el.name !== 'csrf') keys[el.name] = true;
    });

    var params = new URLSearchParams(location.search);
    var storeKey = 'nexa_filtros:' + page + ':' + (params.get('mc_ctx') || '');
    var flagKey = 'nexa_filtros_restaurado:' + page;

    // Limpar: esquece a pesquisa e tira o parâmetro da barra de endereço.
    if (params.has('limpar_filtros')) {
      sessionStorage.removeItem(storeKey);
      params.delete('limpar_filtros');
      var rest = params.toString();
      history.replaceState(null, '', location.pathname + (rest ? '?' + rest : ''));
      return;
    }

    // Telas de edição/inclusão na mesma página: não mexe.
    for (var i = 0; i < ignore.length; i++) if (params.has(ignore[i])) return;

    var hasFilter = Object.keys(keys).some(function (k) { return params.has(k); });
    if (hasFilter) {
      var keep = new URLSearchParams();
      Object.keys(keys).forEach(function (k) {
        params.getAll(k).forEach(function (v) { keep.append(k, v); });
      });
      sessionStorage.setItem(storeKey, keep.toString());

      if (sessionStorage.getItem(flagKey)) {
        sessionStorage.removeItem(flagKey);
        showNotice();
      }
      return;
    }

    var saved = sessionStorage.getItem(storeKey);
    if (!saved) return;
    // data-lembrar-somente-retorno: só restaura ao voltar de uma ação da própria
    // tela (salvar, baixar, editar). Entrando pelo menu, abre limpa e sem consultar.
    if (form.getAttribute('data-lembrar-somente-retorno') === '1') {
      var ref = '';
      try { ref = document.referrer ? new URL(document.referrer).pathname : ''; } catch (e2) { ref = ''; }
      if (ref !== location.pathname) return;
    }
    var merged = new URLSearchParams(saved);
    params.forEach(function (v, k) { if (!keys[k]) merged.append(k, v); });
    sessionStorage.setItem(flagKey, '1');
    location.replace(location.pathname + '?' + merged.toString());
  } catch (e) { /* sem memória de filtros: a tela funciona normalmente */ }

  function showNotice() {
    var clear = new URLSearchParams();
    var ctx = new URLSearchParams(location.search).get('mc_ctx');
    if (ctx) clear.set('mc_ctx', ctx);
    clear.set('limpar_filtros', '1');
    var box = document.createElement('div');
    box.setAttribute('role', 'status');
    box.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:9998;max-width:calc(100vw - 32px);display:flex;gap:12px;align-items:center;'
      + 'background:#0b1f5b;color:#fff;border-radius:14px;padding:11px 14px;font:600 13px/1.4 Arial,Helvetica,sans-serif;box-shadow:0 14px 34px rgba(11,31,91,.28);transition:opacity .4s';
    box.innerHTML = '<span>&#8634; Sua pesquisa anterior foi mantida.</span>';
    var a = document.createElement('a');
    a.href = location.pathname + '?' + clear.toString();
    a.textContent = 'Limpar';
    a.style.cssText = 'color:#7dd3fc;font-weight:800;text-decoration:underline;white-space:nowrap';
    box.appendChild(a);
    document.body.appendChild(box);
    setTimeout(function () { box.style.opacity = '0'; setTimeout(function () { box.remove(); }, 450); }, 6000);
  }
})();

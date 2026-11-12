/*
 * Nexa Próspero - campo de etiquetas (contatos).
 * <input data-tag-input data-suggest='["VIP","Revenda"]'> vira "chips" coloridos;
 * o valor do input continua sendo o texto separado por vírgula (o que vai no POST).
 * NexaTags.sync(input) redesenha depois de mudar input.value por código.
 * NexaTags.html(texto) devolve as etiquetas prontas (mesmas cores do PHP).
 */
(function () {
  'use strict';
  const PAL = [['#dcfce7', '#166534'], ['#dbeafe', '#1e40af'], ['#fef3c7', '#92400e'], ['#fce7f3', '#9d174d'], ['#ede9fe', '#5b21b6'], ['#ffedd5', '#9a3412'], ['#ccfbf1', '#115e59'], ['#fee2e2', '#991b1b']];
  const color = (t) => { let h = 0; for (const ch of String(t).trim().toLowerCase()) h = (h * 31 + ch.codePointAt(0)) % 4294967296; return PAL[h % PAL.length]; };
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const list = (v) => {
    const out = [], seen = new Set();
    String(v || '').split(/[,;]+/).forEach((t) => {
      t = t.replace(/\s+/g, ' ').trim().slice(0, 30);
      const k = t.toLowerCase();
      if (t && !seen.has(k) && out.length < 8) { seen.add(k); out.push(t); }
    });
    return out;
  };
  const chip = (t) => { const [bg, fg] = color(t); return '<span class="nexa-tag" style="background:' + bg + ';color:' + fg + '">' + esc(t) + '</span>'; };

  if (!document.getElementById('nexa-tag-css')) {
    const st = document.createElement('style');
    st.id = 'nexa-tag-css';
    st.textContent = '.nexa-tag{display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:800;line-height:1.6;white-space:nowrap;margin:1px 2px 1px 0}'
      + '.nexa-tags-box{position:relative;display:flex;flex-wrap:wrap;gap:4px;align-items:center;min-height:38px;box-sizing:border-box;width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:4px 8px;background:#fff;cursor:text}'
      + '.nexa-tags-box:focus-within{border-color:#2454d6;box-shadow:0 0 0 3px rgba(36,84,214,.12)}'
      + '.nexa-tags-box .nexa-tag{font-size:12px;padding:2px 4px 2px 9px;margin:0}.nexa-tags-box .nexa-tag button{border:0;background:transparent;color:inherit;cursor:pointer;font-weight:800;padding:0 4px;font-size:13px;line-height:1}'
      + '.nexa-tags-box input.nexa-tags-type{flex:1;min-width:120px;border:0!important;outline:0;box-shadow:none!important;padding:4px 2px!important;font:inherit;background:transparent;min-height:0!important;width:auto!important}'
      + '.nexa-tags-sug{position:absolute;left:0;right:0;top:100%;margin-top:4px;z-index:20;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 10px 24px rgba(0,0,0,.12);max-height:200px;overflow:auto;display:none}'
      + '.nexa-tags-sug.on{display:block}.nexa-tags-sug button{display:block;width:100%;text-align:left;border:0;background:#fff;padding:6px 10px;cursor:pointer;font:inherit}.nexa-tags-sug button.sel,.nexa-tags-sug button:hover{background:#eff6ff}';
    document.head.appendChild(st);
  }

  function attach(input) {
    if (!input || input.__nexaTags) return input && input.__nexaTags;
    let suggest = [];
    try { suggest = JSON.parse(input.getAttribute('data-suggest') || '[]') || []; } catch (_) {}
    const box = document.createElement('div');
    box.className = 'nexa-tags-box';
    const typer = document.createElement('input');
    typer.className = 'nexa-tags-type';
    typer.placeholder = input.getAttribute('placeholder') || 'Digite e tecle Enter';
    typer.title = 'Enter inclui; seta para baixo mostra as etiquetas já usadas';
    typer.autocomplete = 'off';
    const sug = document.createElement('div');
    sug.className = 'nexa-tags-sug';
    input.type = 'hidden';
    input.parentNode.insertBefore(box, input.nextSibling);
    let sel = 0, items = [];
    const tags = () => list(input.value);
    const render = () => {
      box.querySelectorAll('.nexa-tag').forEach((n) => n.remove());
      tags().forEach((t, i) => {
        const [bg, fg] = color(t);
        const s = document.createElement('span');
        s.className = 'nexa-tag'; s.style.background = bg; s.style.color = fg;
        s.textContent = t;
        const x = document.createElement('button'); x.type = 'button'; x.textContent = '×'; x.title = 'Remover';
        x.addEventListener('click', (e) => { e.stopPropagation(); const l = tags(); l.splice(i, 1); input.value = l.join(', '); render(); typer.focus(); });
        s.appendChild(x);
        box.insertBefore(s, typer);
      });
      typer.placeholder = tags().length ? '' : (input.getAttribute('placeholder') || 'Digite e tecle Enter');
    };
    const add = (t) => {
      t = String(t || '').replace(/[,;]/g, ' ').replace(/\s+/g, ' ').trim();
      if (!t) return;
      input.value = list(input.value + ',' + t).join(', ');
      typer.value = ''; render(); sug.classList.remove('on');
    };
    // force: mostra todas as sugestões (seta para baixo); senão só enquanto digita.
    const showSug = (force) => {
      if (force !== true && !typer.value.trim()) { sug.classList.remove('on'); return; }
      const q = typer.value.trim().toLowerCase(), have = new Set(tags().map((t) => t.toLowerCase()));
      items = suggest.filter((t) => !have.has(String(t).toLowerCase()) && (!q || String(t).toLowerCase().includes(q))).slice(0, 12);
      if (q && !items.some((t) => String(t).toLowerCase() === q)) items.push(typer.value.trim());
      sel = Math.min(sel, Math.max(0, items.length - 1));
      sug.innerHTML = items.map((t, i) => '<button type="button" data-i="' + i + '" class="' + (i === sel ? 'sel' : '') + '">' + chip(t) + (q && i === items.length - 1 && !suggest.some((x) => String(x).toLowerCase() === q) ? ' <small style="color:#64748b">nova etiqueta</small>' : '') + '</button>').join('');
      sug.classList.toggle('on', items.length > 0 && document.activeElement === typer);
    };
    box.appendChild(typer);
    box.appendChild(sug);
    box.addEventListener('click', () => typer.focus());
    typer.addEventListener('input', () => { sel = 0; showSug(); });
    typer.addEventListener('blur', () => setTimeout(() => { sug.classList.remove('on'); if (typer.value.trim()) add(typer.value); }, 150));
    typer.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!sug.classList.contains('on')) { sel = 0; showSug(true); return; }
        if (items.length) { sel = (sel + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length; showSug(true); }
        return;
      }
      if (e.key === 'Enter' || e.key === ',' || e.key === ';' || e.key === 'Tab') {
        if (e.key === 'Tab' && !typer.value.trim()) return;
        e.preventDefault();
        if (sug.classList.contains('on') && items[sel] !== undefined && (e.key !== ',' || !typer.value.trim())) add(items[sel]); else add(typer.value);
        return;
      }
      if (e.key === 'Backspace' && !typer.value) { const l = tags(); l.pop(); input.value = l.join(', '); render(); }
      if (e.key === 'Escape') sug.classList.remove('on');
    });
    sug.addEventListener('mousedown', (e) => { const b = e.target.closest('button'); if (b) { e.preventDefault(); add(items[+b.dataset.i]); } });
    render();
    const api = { sync: render, setSuggest: (s) => { suggest = s || []; } };
    input.__nexaTags = api;
    return api;
  }

  window.NexaTags = {
    attach,
    sync: (input) => { const a = attach(input); if (a) a.sync(); },
    list,
    color,
    html: (v) => list(v).map(chip).join(' '),
  };
  const init = () => document.querySelectorAll('input[data-tag-input]').forEach(attach);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

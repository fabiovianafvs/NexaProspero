/* Nexa Próspero · Grid de títulos (Contas a Receber / Contas a Pagar).
 * Ordena ao clicar no título da coluna, ajusta largura pela borda do cabeçalho,
 * move colunas pelo marcador ⠿ e escolhe colunas visíveis. Seleção (esquerda) e
 * ações (direita) ficam fixas. Preferências salvas por usuário (grid_preferencias.php)
 * e, de reserva, no navegador. Não altera formulários, links nem a seleção de baixa.
 */
(() => {
  'use strict';
  document.querySelectorAll('table[data-ntg]').forEach(init);

  function init(table) {
    const grid = table.dataset.ntg;
    const tbody = table.tBodies[0];
    const wrap = table.closest('.ntg-wrap');
    const parse = (v, d) => { try { const x = JSON.parse(v || ''); return x && typeof x === 'object' ? x : d; } catch (e) { return d; } };
    const defaults = parse(table.dataset.defaults, {});
    const headers = Array.from(table.tHead.rows[0].cells);
    const movable = headers.filter(th => th.dataset.col && !th.classList.contains('ntg-sel') && !th.classList.contains('ntg-actions')).map(th => th.dataset.col);
    const labels = Object.fromEntries(headers.filter(th => th.dataset.col).map(th => [th.dataset.col, th.textContent.trim()]));
    const types = Object.fromEntries(headers.filter(th => th.dataset.col).map(th => [th.dataset.col, th.dataset.type || 'text']));
    const storageKey = 'nexa.grid.' + grid + '.' + (table.dataset.scope || '0');
    let local = {};
    try { local = JSON.parse(localStorage.getItem(storageKey) || '{}') || {}; } catch (e) {}
    const server = parse(table.dataset.prefs, {});
    const from = Object.keys(server).length ? server : local;
    let visible = Array.isArray(from.visible) ? from.visible.filter(c => movable.includes(c)) : [...movable];
    if (!visible.length) visible = [...movable];
    let order = Array.isArray(from.order) ? from.order.filter(c => movable.includes(c)) : [];
    order = [...new Set(order)].concat(movable.filter(c => !order.includes(c)));
    let widths = from.widths && typeof from.widths === 'object' ? { ...from.widths } : {};
    const prefState = document.querySelector('[data-ntg-state="' + grid + '"]');
    const picker = document.querySelector('[data-ntg-columns="' + grid + '"]');
    let sortCol = null, sortDir = 1, saveTimer = null;

    function width(col) { const n = Number(widths[col]); return Number.isFinite(n) && n >= 40 && n <= 650 ? Math.round(n) : (Number(defaults[col]) || 120); }
    function setW(el, px) { el.style.setProperty('width', px + 'px', 'important'); el.style.setProperty('min-width', px + 'px', 'important'); el.style.setProperty('max-width', px + 'px', 'important'); }

    // Largura da coluna de ações = maior quantidade de ícones de uma linha.
    let maxIcons = 1;
    tbody.querySelectorAll('.ntg-icons').forEach(box => { maxIcons = Math.max(maxIcons, box.querySelectorAll('.ntg-icon').length); });
    const actionsWidth = Math.max(52, maxIcons * 34 + 10);
    table.querySelectorAll('.ntg-actions').forEach(el => setW(el, actionsWidth));

    function applyOrder() {
      table.querySelectorAll('tr').forEach(tr => {
        const map = {};
        Array.from(tr.children).forEach(td => { if (td.dataset.col) map[td.dataset.col] = td; });
        if (!Object.keys(map).length) return;
        const actions = tr.querySelector('.ntg-actions');
        order.forEach(col => { if (map[col]) tr.insertBefore(map[col], actions || null); });
      });
    }
    function applyWidths() {
      let sum = 34 + actionsWidth;
      for (const col of movable) {
        const px = width(col);
        if (visible.includes(col)) sum += px;
        table.querySelectorAll('[data-col="' + col + '"]').forEach(el => setW(el, px));
      }
      table.style.setProperty('width', sum + 'px', 'important');
      table.style.setProperty('min-width', '0', 'important');
    }
    function applyVisibility() {
      for (const col of movable) table.querySelectorAll('[data-col="' + col + '"]').forEach(el => el.classList.toggle('ntg-hidden', !visible.includes(col)));
      applyWidths();
    }

    function save() {
      const prefs = { visible: [...visible], order: [...order], widths: { ...widths } };
      const json = JSON.stringify(prefs);
      try { localStorage.setItem(storageKey, json); } catch (e) {}
      const url = table.dataset.saveUrl, csrf = table.dataset.csrf;
      if (!url || !csrf) { if (prefState) prefState.textContent = 'Preferências neste navegador'; return; }
      if (prefState) prefState.textContent = 'Salvando…';
      fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' }, body: new URLSearchParams({ grid, csrf, preferences: json }) })
        .then(r => r.json().then(j => r.ok && j.ok))
        .then(ok => { if (prefState) prefState.textContent = ok ? 'Preferências salvas' : 'Preferências neste navegador'; })
        .catch(() => { if (prefState) prefState.textContent = 'Preferências neste navegador'; });
    }
    function scheduleSave() { clearTimeout(saveTimer); saveTimer = setTimeout(save, 400); }

    // Ordenação pelo valor de data-sort (datas ISO e valores numéricos) ou pelo texto.
    function cellOf(tr, col) { return Array.from(tr.children).find(td => td.dataset.col === col); }
    function value(tr, col) {
      const td = cellOf(tr, col); if (!td) return '';
      const raw = td.dataset.sort !== undefined ? td.dataset.sort : td.textContent.trim();
      if (types[col] === 'num') { const n = Number(String(raw).replace(/[^0-9,.\-]/g, '').replace(/\.(?=.*[.,])/g, '').replace(',', '.')); return Number.isFinite(n) ? n : 0; }
      return String(raw).toLocaleLowerCase('pt-BR');
    }
    function sortBy(col) {
      sortDir = sortCol === col ? -sortDir : 1; sortCol = col;
      const rows = Array.from(tbody.rows).filter(tr => !tr.classList.contains('ntg-empty-row'));
      rows.sort((a, b) => { const x = value(a, col), y = value(b, col); if (x === y) return 0; if (x === '') return 1; if (y === '') return -1; return (typeof x === 'number' ? x - y : String(x).localeCompare(String(y), 'pt-BR', { numeric: true })) * sortDir; });
      rows.forEach(tr => tbody.appendChild(tr));
      headers.forEach(th => { const s = th.querySelector('.ntg-sort'); if (s) s.textContent = th.dataset.col === col ? (sortDir === 1 ? '▲' : '▼') : ''; });
    }

    if (picker) {
      movable.forEach(col => {
        const label = document.createElement('label');
        const inp = document.createElement('input'); inp.type = 'checkbox'; inp.checked = visible.includes(col);
        inp.addEventListener('change', () => {
          if (!inp.checked && visible.length === 1) { inp.checked = true; return; }
          visible = inp.checked ? [...visible, col] : visible.filter(c => c !== col);
          applyVisibility(); scheduleSave();
        });
        label.append(inp, document.createTextNode(labels[col]));
        picker.appendChild(label);
      });
      const reset = document.querySelector('[data-ntg-reset="' + grid + '"]');
      if (reset) reset.addEventListener('click', () => {
        visible = [...movable]; order = [...movable]; widths = {};
        picker.querySelectorAll('input').forEach(i => { i.checked = true; });
        applyOrder(); applyVisibility(); scheduleSave();
      });
    }

    for (const th of headers) {
      const col = th.dataset.col;
      if (!col || !movable.includes(col)) continue;
      const head = document.createElement('div'); head.className = 'ntg-head';
      const drag = document.createElement('span'); drag.className = 'ntg-drag'; drag.textContent = '⠿'; drag.draggable = true; drag.title = 'Arraste para mudar a coluna de lugar';
      drag.addEventListener('dragstart', e => { e.dataTransfer.setData('text/plain', col); e.dataTransfer.effectAllowed = 'move'; });
      const title = document.createElement('button'); title.type = 'button'; title.className = 'ntg-head-label'; title.textContent = labels[col]; title.title = 'Ordenar por ' + labels[col];
      title.addEventListener('click', () => sortBy(col));
      const arrow = document.createElement('span'); arrow.className = 'ntg-sort';
      head.append(drag, title, arrow); th.replaceChildren(head);
      th.addEventListener('dragover', e => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; });
      th.addEventListener('drop', e => {
        e.preventDefault();
        const src = e.dataTransfer.getData('text/plain');
        if (!movable.includes(src) || src === col) return;
        order = order.filter(c => c !== src); order.splice(order.indexOf(col), 0, src);
        applyOrder(); scheduleSave();
      });
      const resizer = document.createElement('span'); resizer.className = 'ntg-resizer'; resizer.title = 'Arraste para ajustar a largura';
      th.appendChild(resizer);
      resizer.addEventListener('pointerdown', ev => {
        ev.preventDefault(); ev.stopPropagation();
        const start = ev.clientX, old = width(col);
        resizer.setPointerCapture(ev.pointerId);
        const move = e => { widths[col] = Math.max(40, Math.min(650, Math.round(old + e.clientX - start))); applyWidths(); };
        const stop = () => { resizer.removeEventListener('pointermove', move); resizer.removeEventListener('pointerup', stop); resizer.removeEventListener('pointercancel', stop); scheduleSave(); };
        resizer.addEventListener('pointermove', move); resizer.addEventListener('pointerup', stop); resizer.addEventListener('pointercancel', stop);
      });
    }

    applyOrder(); applyVisibility();
    if (wrap) wrap.classList.add('is-ready');
    document.documentElement.classList.add('ntg-ready');
  }
})();

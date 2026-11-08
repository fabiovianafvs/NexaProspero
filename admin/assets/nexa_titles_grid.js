/* Nexa Próspero · Grid de títulos (Contas a Receber / Contas a Pagar).
 * Ordena ao clicar no título da coluna, ajusta largura pela borda do cabeçalho,
 * move colunas pelo marcador ⠿ e escolhe colunas visíveis. Seleção (esquerda) e
 * ações (direita) ficam fixas. Preferências salvas por usuário (grid_preferencias.php)
 * e, de reserva, no navegador. Não altera formulários, links nem a seleção de baixa.
 */
(() => {
  'use strict';
  const ICONS = {
    edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L9 17l-4 1 1-4Z"/>',
    view: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
    cancel: '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/>',
    trash: '<path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 11v5M14 11v5"/>',
    print: '<path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/>',
    pdf: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
    download: '<path d="M12 3v12M7 10l5 5 5-5M4 21h16"/>',
    mail: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m3 6 9 7 9-7"/>',
    whatsapp: '<path d="M21 11.5a8.5 8.5 0 0 1-12.6 7.4L3 21l2.1-5.4A8.5 8.5 0 1 1 21 11.5Z"/><path d="M8.5 8.8c.6 3.2 2.6 5.2 5.8 6l1.6-1.4"/>',
    check: '<path d="M20 6 9 17l-5-5"/>',
    copy: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5v.5"/>',
    more: '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>'
  };
  const ICON_RULES = [
    [/^(editar|alterar|editar\s.*)$/i, 'edit'],
    [/excluir|remover|apagar|deletar/i, 'trash', true],
    [/^cancelar|inutilizar/i, 'cancel', true],
    [/imprimir|danfe|danfce|espelho/i, 'print'],
    [/\bpdf\b/i, 'pdf'],
    [/\bxml\b|baixar|download/i, 'download'],
    [/e-?mail/i, 'mail'],
    [/whats/i, 'whatsapp'],
    [/^(visualizar|ver|abrir|detalhes|consultar|ver detalhes|abrir pedido|abrir nota)$/i, 'view'],
    [/^(aprovar|confirmar|processar|concluir|finalizar)$/i, 'check'],
    [/^(duplicar|copiar|clonar)$/i, 'copy']
  ];
  const svg = name => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + (ICONS[name] || ICONS.view) + '</svg>';
  const slugify = t => t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 30);

  // Converte uma tabela comum (data-ntg-auto="chave") para o grid: nomeia as colunas
  // pelo cabeçalho, fixa Ações à direita e troca os botões conhecidos por ícones.
  function prepAuto(table) {
    const key = table.dataset.ntgAuto;
    const tbody = table.tBodies[0];
    if (!key || !table.tHead || table.tHead.rows.length !== 1 || !tbody) return false;
    let ths = Array.from(table.tHead.rows[0].cells);
    if (ths.length < 2 || ths.some(th => th.colSpan > 1)) return false;
    table.querySelectorAll('colgroup').forEach(c => c.remove());
    // Linha de ações separada logo abaixo do registro: os botões viram a coluna Ações.
    const isActionRow = tr => tr.cells.length === 1 && tr.cells[0].colSpan > 1 && tr.previousElementSibling && tr.previousElementSibling.cells.length === ths.length && !!tr.querySelector('a,button,form,[class*="action-buttons"]');
    const actionRows = Array.from(tbody.rows).filter(isActionRow);
    if (actionRows.length) {
      const th = document.createElement('th'); th.textContent = 'Ações'; table.tHead.rows[0].appendChild(th);
      const actionSet = new Set(actionRows);
      Array.from(tbody.rows).forEach(tr => {
        if (tr.cells.length !== ths.length || actionSet.has(tr)) return;
        const td = document.createElement('td'); tr.appendChild(td);
        const next = tr.nextElementSibling;
        if (next && actionSet.has(next)) {
          const box = next.querySelector('[class*="action-buttons"],.fiscal-inline-actions') || next.cells[0];
          while (box.firstChild) td.appendChild(box.firstChild);
          next.remove();
        }
      });
      ths = Array.from(table.tHead.rows[0].cells);
    }
    const n = ths.length;
    const rows = Array.from(tbody.rows);
    const dataRows = rows.filter(tr => tr.cells.length === n);
    const spanRows = rows.filter(tr => tr.cells.length !== n);
    const lastText = ths[n - 1].textContent.trim();
    const lastHasControls = dataRows.length > 0 && dataRows.every(tr => !tr.cells[n - 1].textContent.trim() || tr.cells[n - 1].querySelector('a,button,form,select'));
    const actionsIdx = (/^(a[çc][õo]es|a[çc][ãa]o|abrir|op[çc][õo]es)$/i.test(lastText) || (lastText === '' && lastHasControls)) ? n - 1 : -1;
    const selIdx = ths[0].querySelector('input[type=checkbox]') || (ths[0].textContent.trim() === '' && dataRows.length && dataRows.every(tr => tr.cells[0].querySelector('input[type=checkbox]') || !tr.cells[0].textContent.trim())) ? 0 : -1;
    const defaults = {};
    const used = {};
    ths.forEach((th, i) => {
      if (i === actionsIdx) { th.classList.add('ntg-actions'); return; }
      if (i === selIdx) { th.classList.add('ntg-sel'); return; }
      let col = slugify(th.textContent.trim()) || ('c' + i);
      while (used[col]) col += '_' + i;
      used[col] = true;
      th.dataset.col = col;
      if (th.matches('.num,.right,.cp-num,.ntg-num,.text-right,.money')) { th.dataset.type = 'num'; th.classList.add('ntg-num'); }
      defaults[col] = Math.max(70, Math.min(360, Math.round(th.getBoundingClientRect().width)));
    });
    dataRows.forEach(tr => {
      Array.from(tr.cells).forEach((td, i) => {
        if (i === actionsIdx) { td.classList.add('ntg-actions'); iconize(td); return; }
        if (i === selIdx) { td.classList.add('ntg-sel'); return; }
        td.dataset.col = ths[i].dataset.col;
        if (ths[i].dataset.type === 'num') td.classList.add('ntg-num');
      });
    });
    // Colunas numéricas sem marcação: detecta pelo conteúdo.
    ths.forEach((th, i) => {
      if (!th.dataset.col || th.dataset.type) return;
      const vals = dataRows.map(tr => tr.cells[i].textContent.trim()).filter(Boolean);
      if (vals.length && vals.filter(v => /^[-+]?\s*(R\$\s*)?-?[\d.]+(,\d+)?\s*%?$/.test(v)).length >= vals.length * 0.8) {
        th.dataset.type = 'num'; th.classList.add('ntg-num');
        dataRows.forEach(tr => tr.cells[i].classList.add('ntg-num'));
      }
    });
    spanRows.forEach(tr => tr.classList.add('ntg-empty-row'));
    if (spanRows.length && dataRows.length) table.dataset.noSort = '1';
    table.dataset.ntg = key;
    table.dataset.defaults = JSON.stringify(defaults);
    table.classList.add('ntg-table');
    let wrap = table.closest('.ntg-wrap');
    if (!wrap) { wrap = document.createElement('div'); wrap.className = 'ntg-wrap'; table.parentNode.insertBefore(wrap, table); wrap.appendChild(table); }
    const bar = document.createElement('div');
    bar.className = 'ntg-toolbar ntg-auto-bar';
    bar.innerHTML = '<span class="ntg-pref-state" data-ntg-state="' + key + '" role="status" aria-live="polite"></span><details class="ntg-picker"><summary class="btn btn-sm">☷ Colunas</summary><div class="ntg-picker-panel"><strong>Colunas visíveis</strong><div data-ntg-columns="' + key + '"></div><button type="button" class="btn ntg-reset" data-ntg-reset="' + key + '">Restaurar padrão</button><small>Clique no título para ordenar · arraste ⠿ para mudar de lugar · ajuste a largura pela borda do cabeçalho.</small></div></details>';
    wrap.parentNode.insertBefore(bar, wrap);
    return true;
  }
  function iconize(td) {
    const box = document.createElement('div'); box.className = 'ntg-icons';
    while (td.firstChild) box.appendChild(td.firstChild);
    td.appendChild(box);
    // Textos de ajuda longos viram um ícone (i) com o texto ao passar o mouse.
    Array.from(box.querySelectorAll('span,div,small,p')).forEach(el => {
      if (el.closest('a,button,form,select,details') || el.querySelector('a,button,form,select')) return;
      const text = (el.textContent || '').replace(/\s+/g, ' ').trim();
      if (text.length <= 28 || el.classList.contains('ntg-icon')) return;
      const info = document.createElement('span'); info.className = 'ntg-icon ntg-static'; info.title = text; info.setAttribute('aria-label', text); info.innerHTML = svg('info');
      el.replaceWith(info);
    });
    const controls = [];
    box.querySelectorAll('a,button').forEach(el => {
      if (el.closest('select') || el.classList.contains('ntg-icon')) return;
      const text = (el.textContent || '').replace(/\s+/g, ' ').trim();
      const rule = ICON_RULES.find(r => r[0].test(text));
      const unit = el.closest('form') && el.closest('form').parentNode && box.contains(el.closest('form')) ? el.closest('form') : el;
      if (!rule || el.querySelector('img,svg')) { el.classList.add('ntg-mini'); controls.push({ unit, icon: false }); return; }
      el.title = el.title || text; el.setAttribute('aria-label', text);
      el.className = 'ntg-icon' + (rule[2] ? ' ntg-danger' : '') + (rule[1] === 'whatsapp' ? ' ntg-wa' : '');
      el.innerHTML = svg(rule[1]);
      controls.push({ unit, icon: true });
    });
    // Muitas ações: ficam até 4 visíveis (ícones primeiro) e o resto no menu ⋯.
    const units = [...new Set(controls.map(c => c.unit))];
    if (units.length > 4) {
      const iconUnits = controls.filter(c => c.icon).map(c => c.unit);
      const keep = new Set([...new Set(iconUnits)].slice(0, 3));
      const pack = document.createElement('div'); pack.hidden = true; pack.className = 'ntg-more-pack';
      units.forEach(u => { if (!keep.has(u)) pack.appendChild(u); });
      const more = document.createElement('button'); more.type = 'button'; more.className = 'ntg-icon'; more.title = 'Mais ações'; more.setAttribute('aria-label', 'Mais ações'); more.innerHTML = svg('more');
      more.addEventListener('click', e => { e.stopPropagation(); toggleMenu(more, pack); });
      box.appendChild(more); box.appendChild(pack);
    }
  }
  // Menu flutuante do ⋯ (os botões originais são movidos para ele e devolvidos ao fechar).
  const menu = document.createElement('div'); menu.className = 'ntg-menu'; menu.hidden = true;
  let menuPack = null;
  function closeMenu() { if (menuPack) { while (menu.firstChild) menuPack.appendChild(menu.firstChild); menuPack = null; } menu.hidden = true; }
  function toggleMenu(anchor, pack) {
    if (menuPack === pack) { closeMenu(); return; }
    closeMenu(); menuPack = pack;
    if (!menu.isConnected) document.body.appendChild(menu);
    while (pack.firstChild) menu.appendChild(pack.firstChild);
    menu.hidden = false;
    const r = anchor.getBoundingClientRect(), w = Math.max(200, menu.offsetWidth);
    menu.style.left = Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8)) + 'px';
    const h = Math.min(menu.scrollHeight, window.innerHeight * 0.7);
    menu.style.top = (r.bottom + h + 8 < window.innerHeight ? r.bottom + 4 : Math.max(6, r.top - h - 4)) + 'px';
  }
  document.addEventListener('pointerdown', e => { if (!menu.hidden && !menu.contains(e.target) && !e.target.closest('.ntg-icon')) closeMenu(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMenu(); });
  window.addEventListener('resize', closeMenu);
  document.addEventListener('scroll', closeMenu, true);

  // No celular as telas mantêm o layout próprio (cartões); o grid é para telas largas.
  const narrow = window.matchMedia && window.matchMedia('(max-width: 760px)').matches;
  document.querySelectorAll('table[data-ntg-auto]').forEach(t => { if (narrow || !prepAuto(t)) { t.removeAttribute('data-ntg-auto'); t.classList.add('ntg-auto-skip'); } });
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
    // Colunas novas (que não existiam quando a preferência foi salva) entram visíveis,
    // na posição natural, logo depois da coluna que as antecede no padrão.
    const savedOrder = Array.isArray(from.order) ? [...new Set(from.order.filter(c => movable.includes(c)))] : [];
    const isNew = c => savedOrder.length > 0 && !savedOrder.includes(c);
    let visible = Array.isArray(from.visible) ? from.visible.filter(c => movable.includes(c)) : [...movable];
    movable.forEach(c => { if (isNew(c) && !visible.includes(c)) visible.push(c); });
    if (!visible.length) visible = [...movable];
    let order = savedOrder.length ? [...savedOrder] : [...movable];
    movable.forEach((c, i) => {
      if (order.includes(c)) return;
      const prev = movable.slice(0, i).reverse().find(x => order.includes(x));
      order.splice(prev ? order.indexOf(prev) + 1 : 0, 0, c);
    });
    let widths = from.widths && typeof from.widths === 'object' ? { ...from.widths } : {};
    const prefState = document.querySelector('[data-ntg-state="' + grid + '"]');
    const picker = document.querySelector('[data-ntg-columns="' + grid + '"]');
    let sortCol = null, sortDir = 1, saveTimer = null;

    function width(col) { const n = Number(widths[col]); return Number.isFinite(n) && n >= 40 && n <= 650 ? Math.round(n) : (Number(defaults[col]) || 120); }
    function setW(el, px) { el.style.setProperty('width', px + 'px', 'important'); el.style.setProperty('min-width', px + 'px', 'important'); el.style.setProperty('max-width', px + 'px', 'important'); }

    // Largura da coluna de ações = maior quantidade de ícones de uma linha.
    let maxIcons = 1;
    tbody.querySelectorAll('.ntg-icons').forEach(box => { maxIcons = Math.max(maxIcons, box.querySelectorAll('.ntg-icon').length); });
    if (table.dataset.minIcons) maxIcons = Math.max(maxIcons, Number(table.dataset.minIcons) || 1);
    let measured = 0;
    tbody.querySelectorAll('.ntg-actions .ntg-icons').forEach(box => { measured = Math.max(measured, box.scrollWidth); });
    const actionsWidth = Math.min(420, Math.max(52, maxIcons * 34 + 10, measured ? measured + 14 : 0));
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
      let sum = (table.tHead.querySelector('.ntg-sel') ? 34 : 0) + (table.tHead.querySelector('.ntg-actions') ? actionsWidth : 0);
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
      if (types[col] === 'num') {
        let t = String(raw).replace(/[^0-9,.\-]/g, '');
        if (t.includes(',')) t = t.replace(/\./g, '').replace(',', '.');
        else if (/^-?\d{1,3}(\.\d{3})+$/.test(t)) t = t.replace(/\./g, '');
        const n = Number(t); return Number.isFinite(n) ? n : 0;
      }
      const d = /^(\d{2})\/(\d{2})\/(\d{4})(?:\s+(\d{2}):(\d{2}))?/.exec(String(raw).trim());
      if (d) return d[3] + d[2] + d[1] + (d[4] || '00') + (d[5] || '00');
      return String(raw).toLocaleLowerCase('pt-BR');
    }
    function sortBy(col) {
      if (table.dataset.noSort === '1') return;
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

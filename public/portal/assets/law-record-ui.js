/* Shared Law record compositions; visual primitives and focus use Fokus Styles. */
(() => {
  let sequence = 0;
  const node = (tag, cls = '', text = '') => {
    const el = document.createElement(tag); el.className = cls;
    if (tag === 'select') { el.classList.add('fs-form-select'); el.dataset.fs = 'select'; }
    if (text !== '') el.textContent = text; return el;
  };
  const button = (text, action, primary = false) => {
    const el = node('button', `fs-btn fs-btn-${primary ? 'primary' : 'secondary'}`, text); el.type = 'button';
    if (action) el.addEventListener('click', action); return el;
  };
  const input = (value = '', type = 'text', max = 180) => {
    const el = node('input', 'fs-form-control'); el.type = type; el.value = value ?? ''; el.maxLength = max; return el;
  };
  const select = (items, value = '') => {
    const el = node('select', 'fs-form-select');
    items.forEach(([id, label]) => el.add(new Option(label, id, false, String(id) === String(value ?? '')))); return el;
  };
  const field = (text, control, name = '', help = '') => {
    const box = node('div', 'law-record-field'); const id = `law-record-field-${++sequence}`;
    control.id = id; if (name) control.name = name;
    const label = node('label', 'fs-form-label', text); label.htmlFor = id; box.append(label, control);
    if (help) { const hint = node('p', 'law-record-help', help); hint.id = `${id}-help`; control.setAttribute('aria-describedby', hint.id); box.append(hint); }
    return box;
  };
  const section = (title) => {
    const el = node('section', 'fs-card fs-card-sm'); const head = node('header', 'fs-card-header fs-u-p-3');
    head.append(node('h3', 'fs-card-title', title)); el.body = node('div', 'fs-card-body fs-stack fs-stack-gap-3 fs-u-p-3');
    el.append(head, el.body); return el;
  };
  const message = (text = '') => {
    const el = node('p', 'fs-alert fs-alert-danger', text); el.setAttribute('role', 'alert'); el.hidden = !text; return el;
  };
  function dialog(title, opener, size = 'fs-modal-xl') {
    const focus = opener instanceof HTMLElement ? opener : document.getElementById('content-region');
    const trigger = node('button'); trigger.type = 'button'; trigger.hidden = true;
    const overlay = node('div', 'fs-modal fs-modal-scrollable'); overlay.id = `law-record-dialog-${++sequence}`; overlay.setAttribute('aria-hidden', 'true');
    const frame = node('div', `fs-modal-dialog ${size}`); const content = node('div', 'fs-modal-content law-record-modal-content');
    const head = node('header', 'fs-modal-header law-record-dialog-header'); const heading = node('h2', 'fs-modal-title', title); heading.id = `${overlay.id}-title`;
    overlay.setAttribute('aria-labelledby', heading.id);
    const close = node('button', 'fs-btn-close'); close.type = 'button'; close.setAttribute('aria-label', 'Fechar'); close.setAttribute('data-fs-dismiss', 'modal'); head.append(heading, close);
    const body = node('div', 'fs-modal-body law-record-modal-body fs-stack fs-stack-gap-3'); const footer = node('footer', 'fs-modal-footer law-record-dialog-footer');
    content.append(head, body, footer); frame.append(content); overlay.append(frame);
    trigger.setAttribute('data-fs-target', `#${overlay.id}`); document.body.append(trigger, overlay);
    const api = window.FokusStyles?.Modal;
    if (!api) { trigger.remove(); overlay.remove(); throw new Error('O componente de diálogo não está disponível.'); }
    const instance = api.getOrCreateInstance(trigger);
    trigger.addEventListener('fs:hidden', () => { instance.dispose(); trigger.remove(); overlay.remove(); if (focus?.isConnected) focus.focus(); }, { once: true });
    instance.show(); return { body, footer, close: () => instance.hide(), element: overlay };
  }
  function bindForm(form, submit, error, action) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault(); if (submit.disabled) return;
      error.hidden = true; form.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
      submit.disabled = true; form.setAttribute('aria-busy', 'true');
      try { await action(); }
      catch (failure) {
        error.textContent = failure.message || 'Não foi possível concluir a operação.'; error.hidden = false;
        for (const name of Object.keys(failure.errors || {})) {
          const control = [...form.elements].find((el) => el.name === name); if (control) control.setAttribute('aria-invalid', 'true');
        }
        error.tabIndex = -1; error.focus();
      } finally { submit.disabled = false; form.removeAttribute('aria-busy'); }
    });
  }
  // The approved Contacts composition is also used by Processes. Keep the
  // palette, geometry and typography together instead of styling each module.
  const palette = ['#9670c2', '#52a89a', '#d49a4c', '#638fc5'];
  function chartSeries(items, limit = 4) {
    const ordered = items.filter((item) => Number(item.total) > 0).map((item) => ({ ...item, total: Number(item.total) })).sort((a, b) => b.total - a.total);
    if (ordered.length <= limit) return ordered;
    return [...ordered.slice(0, limit - 1), { label: 'Outras classes', total: ordered.slice(limit - 1).reduce((sum, item) => sum + item.total, 0) }];
  }
  function compositionChart(items, total, noun, compact = false) {
    const box = node('div', compact ? 'law-dashboard-contact-distribution' : 'law-record-overview-chart');
    const ring = node('div', compact ? 'law-dashboard-contact-ring' : 'law-record-composition-ring');
    const series = chartSeries(items); let offset = 0;
    const stops = series.map((item, index) => { const start = offset; offset += item.total / Math.max(1, total) * 100; return `${palette[index]} ${start}% ${offset}%`; });
    if (stops.length) ring.style.setProperty('--law-chart-gradient', `conic-gradient(${stops.join(', ')})`);
    ring.dataset.empty = String(total === 0); ring.setAttribute('role', 'img');
    ring.setAttribute('aria-label', `Distribuição de ${noun}: ${series.map((item) => `${item.label}: ${item.total}`).join('; ') || 'nenhum registro'}`);
    const center = node('span', compact ? 'law-dashboard-ring-center' : 'law-record-ring-center');
    center.append(node('strong', '', Number(total).toLocaleString('pt-BR')), node(compact ? 'small' : 'span', '', compact ? noun : noun.toLocaleUpperCase('pt-BR'))); ring.append(center);
    const legend = node('div', compact ? 'law-dashboard-contact-breakdown' : 'law-record-chart-legend law-record-chart-legend-list');
    series.forEach((item, index) => {
      if (compact) {
        const row = node('div', 'law-dashboard-breakdown-row'); const head = node('div', 'law-dashboard-breakdown-head');
        head.append(node('span', 'law-dashboard-breakdown-label', item.label), node('strong', '', item.total.toLocaleString('pt-BR')));
        const track = node('span', 'law-dashboard-breakdown-track'); const fill = node('span', 'law-dashboard-breakdown-fill');
        fill.style.width = `${item.total / Math.max(1, total) * 100}%`; fill.style.backgroundColor = palette[index]; track.append(fill); row.append(head, track); legend.append(row);
      } else {
        const row = node('div', 'law-record-chart-legend-row'); const dot = node('span', 'law-record-chart-dot'); dot.style.backgroundColor = palette[index];
        row.append(dot, node('span', '', item.label), node('strong', '', item.total.toLocaleString('pt-BR'))); legend.append(row);
      }
    });
    if (!series.length) legend.append(node('span', compact ? 'law-dashboard-recent-empty' : 'law-record-chart-legend-row', 'Nenhum registro nesta consulta.'));
    box.append(ring, legend); return box;
  }
  function metricCards(specs) {
    return specs.map(([label, value, note, tone], index) => {
      const card = node('article', `law-record-metric-card fs-card law-record-metric-${tone}`); const body = node('div', 'fs-card-body law-record-metric-body');
      body.append(node('span', 'law-record-metric-index', String(index + 1).padStart(2, '0')), node('span', 'law-record-metric-label', label), node('strong', '', Number(value).toLocaleString('pt-BR')), node('small', '', note)); card.append(body); return card;
    });
  }
  function detailSection(title, marker = 'INF', subtitle = '') {
    const card = node('section', 'law-record-detail-card'); const head = node('header', 'law-record-detail-section-header'); const copy = node('div', 'law-record-detail-section-heading law-record-section-heading-copy');
    copy.append(node('h3', '', title)); if (subtitle) copy.append(node('p', '', subtitle));
    head.append(node('span', 'law-record-detail-section-mark', marker), copy); card.body = node('div', 'law-record-section-body'); card.append(head, card.body); return card;
  }
  function renderPagination(container, pagination, onPage, noun = 'registros', onError = null) {
    const current = Number(pagination.page || 1), size = Number(pagination.per_page || 15), total = Number(pagination.total || 0), last = Math.max(1, Math.ceil(total / size));
    const summary = node('span', 'fs-u-fs-sm fs-u-color-secondary', total ? `Mostrando ${(current - 1) * size + 1} a ${Math.min(current * size, total)} de ${total.toLocaleString('pt-BR')} ${noun}` : 'Nenhum registro encontrado'); summary.setAttribute('role', 'status');
    const nav = node('nav'); nav.setAttribute('aria-label', `Paginação de ${noun}`); const list = node('ul', 'fs-pagination fs-pagination-compact');
    const add = (label, target, aria, disabled, selected = false) => {
      const li = node('li', `fs-page-item${selected ? ' is-active' : ''}`); const control = button(label, async () => {
        if (disabled || selected || container.getAttribute('aria-busy') === 'true') return;
        const controls = [...list.querySelectorAll('button')]; const states = controls.map((item) => item.disabled); controls.forEach((item) => { item.disabled = true; }); container.setAttribute('aria-busy', 'true');
        try { await onPage(target); } catch (error) { if (onError) onError(error); else { summary.textContent = error.message || 'Não foi possível carregar a página.'; summary.setAttribute('role', 'alert'); } }
        finally { container.removeAttribute('aria-busy'); controls.forEach((item, index) => { item.disabled = states[index]; }); }
      }); control.className = 'fs-page-link'; control.disabled = disabled; control.setAttribute('aria-label', aria);
      if (selected) { li.setAttribute('aria-current', 'page'); control.setAttribute('aria-current', 'page'); } li.append(control); list.append(li);
    };
    add('‹', current - 1, 'Página anterior', current <= 1);
    const pages = last <= 7 ? Array.from({ length: last }, (_, index) => index + 1) : [...new Set([1, current - 1, current, current + 1, last])].filter((page) => page >= 1 && page <= last).sort((a, b) => a - b);
    pages.forEach((page, index) => { if (index && page - pages[index - 1] > 1) { const gap = node('li', 'fs-page-item'); gap.setAttribute('aria-hidden', 'true'); gap.append(node('span', 'fs-page-link', '…')); list.append(gap); } add(String(page), page, `Página ${page} de ${last}`, false, page === current); });
    add('›', current + 1, 'Próxima página', current >= last); nav.append(list); container.replaceChildren(summary, nav);
  }
  window.FokusLawRecordUI = { node, button, input, select, field, section, message, dialog, bindForm, compositionChart, metricCards, detailSection, renderPagination };
})();

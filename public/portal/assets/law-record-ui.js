/* Shared Law record compositions; visual primitives and focus use Fokus Styles. */
(() => {
  let sequence = 0;
  const node = (tag, cls = '', text = '') => {
    const el = document.createElement(tag); el.className = cls;
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
    const box = node('div', 'fs-stack fs-stack-gap-1'); const id = `law-record-field-${++sequence}`;
    control.id = id; if (name) control.name = name;
    const label = node('label', 'fs-form-label', text); label.htmlFor = id; box.append(label, control);
    if (help) { const hint = node('p', 'fs-u-fs-sm fs-u-color-secondary', help); hint.id = `${id}-help`; control.setAttribute('aria-describedby', hint.id); box.append(hint); }
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
  function dialog(title, opener, size = 'fs-modal-lg') {
    const focus = opener instanceof HTMLElement ? opener : document.getElementById('content-region');
    const trigger = node('button'); trigger.type = 'button'; trigger.hidden = true;
    const overlay = node('div', 'fs-modal fs-modal-scrollable'); overlay.id = `law-record-dialog-${++sequence}`; overlay.setAttribute('aria-hidden', 'true');
    const frame = node('div', `fs-modal-dialog ${size}`); const content = node('div', 'fs-modal-content');
    const head = node('header', 'fs-modal-header'); const heading = node('h2', 'fs-modal-title', title); heading.id = `${overlay.id}-title`;
    overlay.setAttribute('aria-labelledby', heading.id);
    const close = node('button', 'fs-btn-close'); close.type = 'button'; close.setAttribute('aria-label', 'Fechar'); close.setAttribute('data-fs-dismiss', 'modal'); head.append(heading, close);
    const body = node('div', 'fs-modal-body fs-stack fs-stack-gap-3'); const footer = node('footer', 'fs-modal-footer');
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
  window.FokusLawRecordUI = { node, button, input, select, field, section, message, dialog, bindForm };
})();

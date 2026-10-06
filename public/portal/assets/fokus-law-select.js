/* Law adapter for the installed Fokus Styles Select component. */
(() => {
  const Select = window.FokusStyles?.Select;
  if (!Select) return;

  const labelsBySelect = new WeakMap();
  const accessibilityBySelect = new WeakMap();
  const toggleIds = new WeakMap();
  const invalidListeners = new WeakSet();
  let sequence = 0;

  const copyThemeToMenu = (select, menu) => {
    const darkMenu = select.dataset.fsSelectTheme === 'dark';
    menu.classList.toggle('law-select-menu-dark', darkMenu);
    if (darkMenu) return;
    const computed = getComputedStyle(select);
    [
      '--fs-color-primary', '--fs-color-on-primary', '--fs-color-text',
      '--fs-color-muted', '--fs-color-border', '--fs-color-border-default',
      '--fs-color-surface', '--fs-color-surface-raised', '--fs-color-subtle',
      '--fs-control-border-color', '--fs-control-bg', '--fs-control-radius',
      '--fs-focus-color', '--fs-font-sans',
    ].forEach((token) => {
      const value = computed.getPropertyValue(token).trim();
      if (value) menu.style.setProperty(token, value);
    });
  };

  const refresh = (select) => {
    if (!(select instanceof HTMLSelectElement) || !select.isConnected) return null;

    const current = Select.getInstance(select);
    if (select.multiple || select.size > 1) {
      current?.dispose();
      select.removeAttribute('data-fs');
      select.classList.remove('fs-form-select');
      select.classList.add('law-native-multiselect');
      return null;
    }

    select.classList.add('fs-form-select');
    select.dataset.fs = 'select';
    const labels = labelsBySelect.get(select) || Array.from(select.labels || []);
    labelsBySelect.set(select, labels);
    const accessibility = accessibilityBySelect.get(select) || {
      ariaLabel: select.getAttribute('aria-label'),
      labelledBy: select.getAttribute('aria-labelledby'),
      describedBy: select.getAttribute('aria-describedby'),
    };
    accessibilityBySelect.set(select, accessibility);
    current?.dispose();

    const instance = new Select(select);
    const toggle = instance.toggleEl;
    const toggleId = toggleIds.get(select) || `law-select-control-${++sequence}`;
    toggleIds.set(select, toggleId);
    toggle.id = toggleId;

    labels.forEach((label) => {
      if (select.id && label.htmlFor === select.id) label.htmlFor = toggleId;
    });

    const labelText = labels.map((label) => label.textContent.trim()).filter(Boolean).join(' ');
    if (accessibility.ariaLabel) toggle.setAttribute('aria-label', accessibility.ariaLabel);
    else if (accessibility.labelledBy) toggle.setAttribute('aria-labelledby', accessibility.labelledBy);
    else if (labelText) toggle.setAttribute('aria-label', labelText.replace(/\s+/g, ' '));
    if (select.required) toggle.setAttribute('aria-required', 'true');
    if (accessibility.describedBy) toggle.setAttribute('aria-describedby', accessibility.describedBy);
    select.removeAttribute('aria-label');
    select.removeAttribute('aria-labelledby');
    select.removeAttribute('aria-describedby');
    toggle.disabled = select.disabled;

    copyThemeToMenu(select, instance.menuEl);
    if (!invalidListeners.has(select)) {
      select.addEventListener('invalid', () => Select.getInstance(select)?.toggleEl.focus());
      invalidListeners.add(select);
    }
    return instance;
  };

  window.FokusLawSelect = { refresh };

  const collectSelects = (node, target) => {
    if (!(node instanceof Element)) return;
    if (node.matches('select[data-fs="select"]')) target.add(node);
    node.querySelectorAll('select[data-fs="select"]').forEach((select) => target.add(select));
  };

  const observer = new MutationObserver((records) => {
    const changed = new Set();
    records.forEach((record) => {
      if (record.type === 'attributes') {
        const select = record.target instanceof HTMLSelectElement ? record.target : record.target.closest?.('select');
        if (select instanceof HTMLSelectElement) changed.add(select);
      }
      if (record.type !== 'childList') return;
      if (record.target instanceof HTMLSelectElement) changed.add(record.target);
      record.addedNodes.forEach((node) => collectSelects(node, changed));
    });
    changed.forEach((select) => refresh(select));
  });

  observer.observe(document.documentElement, {
    subtree: true,
    childList: true,
    attributes: true,
    attributeFilter: ['disabled', 'multiple', 'size'],
  });
  document.querySelectorAll('select[data-fs="select"]').forEach((select) => refresh(select));
})();

(() => {
  const CLASSIFICATIONS = {
    client: 'Cliente', lawyer: 'Advogado(a)', law_firm: 'Escritório de advocacia', public_body: 'Órgão público', court_unit: 'Unidade judiciária',
    police: 'Policial', prosecutor_office: 'Ministério Público', public_defender: 'Defensoria Pública', expert: 'Perito(a)', witness: 'Testemunha', representative: 'Representante', other: 'Outro',
  };
  const DOCUMENTS = { cpf: 'CPF', cnpj: 'CNPJ', oab: 'OAB', rg: 'RG', other: 'Outro' };
  const $ = (tag, cls = '', text = '') => { const node = document.createElement(tag); if (cls) node.className = cls; if (text !== '') node.textContent = text; return node; };
  const field = (labelText, control) => { const label = $('label', 'law-contact-field'); label.append($('span', '', labelText), control); return label; };
  const select = (items, value = '') => { const control = $('select', 'fs-form-control'); items.forEach(([v, label]) => { const option = new Option(label, v); option.selected = v === value; control.append(option); }); return control; };
  const input = (value = '', placeholder = '', maxLength = 255) => { const control = $('input', 'fs-form-control'); control.value = value || ''; control.placeholder = placeholder; control.maxLength = maxLength; return control; };
  const button = (text, cls = 'fs-btn fs-btn-secondary', fn) => { const control = $('button', cls, text); control.type = 'button'; if (fn) control.addEventListener('click', (event) => fn(event)); return control; };
  const section = (title, copy = '') => {
    const box = $('section', 'fs-card fs-card-sm law-contacts-form-section');
    const header = $('div', 'fs-card-header'); header.append($('h3', 'fs-card-title', title));
    if (copy) header.append($('p', 'fs-card-subtitle law-contact-help', copy));
    const body = $('div', 'fs-card-body'); box.append(header, body); box.content = body; return box;
  };
  let modalSequence = 0;
  function createModal(root, title, size = 'fs-modal-xl', opener = null) {
    const focusTarget = opener instanceof HTMLElement ? opener : root instanceof HTMLElement ? root : document.getElementById('content-region');
    const oldTabindex = focusTarget.getAttribute('tabindex'); focusTarget.setAttribute('tabindex', '-1');
    const id = `law-contact-modal-${++modalSequence}`;
    const trigger = $('button'); trigger.type = 'button'; trigger.tabIndex = -1; trigger.setAttribute('aria-hidden', 'true');
    trigger.setAttribute('data-fs-target', `#${id}`); trigger.style.position = 'fixed'; trigger.style.left = '-10000px'; trigger.style.width = '1px'; trigger.style.height = '1px'; trigger.style.opacity = '0';
    const overlay = $('div', 'fs-modal fs-modal-scrollable'); overlay.id = id; overlay.setAttribute('aria-hidden', 'true');
    const frame = $('div', `fs-modal-dialog ${size}`);
    const content = $('div', 'fs-modal-content law-contact-modal-content');
    const header = $('header', 'fs-modal-header law-contact-dialog-header');
    const heading = $('h2', 'fs-modal-title', title); header.append(heading);
    const close = $('button', 'fs-btn-close'); close.type = 'button'; close.setAttribute('aria-label', 'Fechar'); close.setAttribute('data-fs-dismiss', 'modal'); header.append(close);
    const body = $('div', 'fs-modal-body law-contact-modal-body');
    const footer = $('footer', 'fs-modal-footer law-contact-dialog-footer');
    content.append(header, body, footer); frame.append(content); overlay.append(frame); document.body.append(trigger, overlay);
    const api = window.FokusStyles?.Modal;
    if (!api) { overlay.remove(); trigger.remove(); throw new Error('O componente modal do Fokus Styles não está disponível.'); }
    const instance = api.getOrCreateInstance(trigger);
    trigger.addEventListener('fs:hidden', () => {
      instance.dispose(); overlay.remove(); trigger.remove(); focusTarget.focus();
      if (oldTabindex === null) focusTarget.removeAttribute('tabindex'); else focusTarget.setAttribute('tabindex', oldTabindex);
    }, { once: true });
    instance.show();
    return { overlay, body, footer, close: () => instance.hide(), onHidden: (callback) => trigger.addEventListener('fs:hidden', callback, { once: true }) };
  }
  function confirmAction(root, title, description, actionLabel, opener = null) {
    return new Promise((resolve) => {
      const modal = createModal(root, title, 'fs-modal-sm', opener);
      let accepted = false;
      modal.body.append($('p', '', description));
      const cancel = button('Cancelar', 'fs-btn fs-btn-secondary', () => modal.close());
      const confirm = button(actionLabel, 'fs-btn fs-btn-danger', () => { accepted = true; modal.close(); });
      modal.footer.append(cancel, confirm);
      modal.onHidden(() => resolve(accepted));
      confirm.focus();
    });
  }

  function render(root, context) {
    const permissions = new Set(context.law_permissions || []);
    if (context.company?.role === 'admin') ['law.contacts.view', 'law.contacts.create', 'law.contacts.update', 'law.contacts.delete', 'law.contacts.merge', 'law.contacts.sensitive.view', 'law.contacts.shared.view', 'law.contacts.share.manage'].forEach((p) => permissions.add(p));
    const can = (permission) => permissions.has(permission);
    window.lawContactsCanSensitive = can('law.contacts.sensitive.view');
    window.lawContactsCanMerge = can('law.contacts.merge');
    let page = 1;
    let currentItems = [];
    let searchTimer;
    root.replaceChildren();
    const heading = $('div', 'law-page-heading');
    heading.append($('p', 'law-page-eyebrow', 'GESTÃO DE CONTATOS'), $('h2', '', 'Contatos'), $('p', 'law-page-lede', 'Organize pessoas, empresas, instituições e órgãos em uma base compartilhada pelos setores autorizados.'));
    const headingActions = $('div', 'law-contact-heading-actions');
    if (can('law.contacts.share.manage')) headingActions.append(button('Compartilhamento entre empresas', 'fs-btn fs-btn-outline-primary', (event) => openSharing(root, event.currentTarget)));
    if (can('law.contacts.create')) headingActions.append(button('Novo contato', 'fs-btn fs-btn-primary', (event) => openEditor(root, null, refresh, event.currentTarget)));
    heading.append(headingActions);
    root.append(heading);

    const metrics = $('section', 'law-contact-metrics'); metrics.setAttribute('aria-live', 'polite'); metrics.append($('p', 'law-contact-loading', 'Carregando contatos…')); root.append(metrics);
    const usage = $('div', 'law-subscription-usage'); usage.textContent = 'Carregando capacidade…'; root.append(usage);

    const filters = $('form', 'law-contact-filters');
    const search = input('', 'Buscar por nome, organização, documento autorizado ou classificação', 180); search.type = 'search'; search.setAttribute('aria-label', 'Buscar contatos');
    const nature = select([['', 'Todas as naturezas'], ['pf', 'Pessoa física'], ['pj', 'Pessoa jurídica']]); nature.setAttribute('aria-label', 'Filtrar por natureza');
    const status = select([['ativo', 'Ativos'], ['inativo', 'Inativos'], ['todos', 'Todos']]); status.setAttribute('aria-label', 'Filtrar por situação');
    const classification = select([['', 'Todas as classificações'], ...Object.entries(CLASSIFICATIONS)]); classification.setAttribute('aria-label', 'Filtrar por classificação');
    const tagFilter = input('', 'Filtrar por tag', 64); tagFilter.setAttribute('list', 'law-contact-tags'); tagFilter.setAttribute('aria-label', 'Filtrar por tag');
    const datalist = $('datalist'); datalist.id = 'law-contact-tags'; tagFilter.setAttribute('list', datalist.id);
    filters.append(field('Pesquisar', search), field('Natureza', nature), field('Classificação', classification), field('Tag', tagFilter), field('Situação', status), datalist);
    filters.addEventListener('submit', (event) => { event.preventDefault(); page = 1; refresh(); });
    [nature, status, classification, tagFilter].forEach((control) => control.addEventListener('change', () => { page = 1; refresh(); }));
    search.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => { page = 1; refresh(); }, 250); });
    root.append(filters);

    const state = $('p', 'law-contact-feedback'); state.setAttribute('role', 'status'); root.append(state);
    const tableWrap = $('div', 'fs-table-responsive law-contact-table-wrap');
    const table = $('table', 'fs-table law-contact-table');
    const thead = $('thead'); const headerRow = $('tr');
    ['Contato', 'Natureza', 'Classificações', 'Tags', 'Setores', 'Situação', 'Ações'].forEach((label) => headerRow.append($('th', '', label)));
    thead.append(headerRow); table.append(thead); const tbody = $('tbody'); table.append(tbody); tableWrap.append(table); root.append(tableWrap);
    const footer = $('div', 'law-contact-pagination'); const pageLabel = $('span');
    const previous = button('Anterior', 'fs-btn fs-btn-secondary', () => { if (page > 1) { page--; refresh(); } });
    const next = button('Próxima', 'fs-btn fs-btn-secondary', () => { page++; refresh(); });
    footer.append(previous, pageLabel, next); root.append(footer);

    async function refresh() {
      state.textContent = '';
      try {
        const params = new URLSearchParams({ page: String(page), per_page: '25', status: status.value });
        if (search.value.trim()) params.set('q', search.value.trim());
        if (nature.value) params.set('nature', nature.value);
        if (classification.value) params.set('classification', classification.value);
        if (tagFilter.value.trim()) params.set('tag', tagFilter.value.trim());
        const result = await window.FokusApi.request(`/law/contacts?${params.toString()}`);
        currentItems = result.contacts || [];
        metrics.replaceChildren();
        const summary = result.summary || {};
        [['Contatos PF', summary.pf], ['Contatos PJ', summary.pj], ['Departamentos', summary.departments], ['Cadastros no limite', summary.registrations_counted]].forEach(([label, value]) => {
          const card = $('article', 'law-contact-metric-card fs-card fs-card-sm'); const body = $('div', 'fs-card-body law-contact-metric-body'); body.append($('span', '', label), $('strong', '', Number(value || 0).toLocaleString('pt-BR'))); card.append(body); metrics.append(card);
        });
        const recent = $('section', 'law-contact-recent-card fs-card fs-card-sm'); const header = $('div', 'fs-card-header'); header.append($('h3', 'fs-card-title', 'Acessados recentemente'));
        const recentBody = $('div', 'fs-card-body law-contact-recent');
        if (summary.recent?.length) summary.recent.slice(0, 5).forEach((item) => { const link = button(item.display_name, 'law-contact-recent-link', (event) => openDetails(root, item.id, false, refresh, event.currentTarget)); recentBody.append(link); });
        else recentBody.append($('p', '', 'Seus contatos criados ou consultados aparecerão aqui.'));
        recent.append(header, recentBody);
        metrics.append(recent);
        const meter = summary.usage;
        if (meter?.available) {
          usage.replaceChildren($('span', '', `${meter.label}: ${meter.used.toLocaleString('pt-BR')} de ${meter.limit.toLocaleString('pt-BR')} (${meter.percentage}%)`));
          usage.dataset.state = meter.over_threshold ? 'warning' : 'normal';
        } else { usage.textContent = 'A capacidade contratada para contatos não está configurada nesta assinatura.'; }
        datalist.replaceChildren(...(result.tags || []).map((name) => { const option = $('option'); option.value = name; return option; }));
        tbody.replaceChildren();
        if (!currentItems.length) {
          const tr = $('tr'); const td = $('td', 'law-contact-empty', 'Nenhum contato encontrado com estes filtros.'); td.colSpan = 7; tr.append(td); tbody.append(tr);
        } else currentItems.forEach((contact) => {
          const tr = $('tr');
          const titleCell = $('td'); const open = button(contact.display_name, 'law-contact-name', (event) => openDetails(root, contact.id, contact.is_shared, refresh, event.currentTarget));
          titleCell.append(open); if (contact.is_shared) titleCell.append($('span', 'law-contact-shared-badge', `Compartilhado por ${contact.source_company_name || 'outra empresa'}`));
          tr.append(titleCell, $('td', '', contact.legal_nature === 'pj' ? 'Pessoa jurídica' : 'Pessoa física'));
          tr.append($('td', '', (contact.classification_labels || []).join(', ') || '—'));
          tr.append($('td', '', (contact.tags || []).join(', ') || '—'));
          tr.append($('td', '', `${contact.departments?.length || 0} departamento(s)`));
          tr.append($('td', '', contact.status === 'ativo' ? 'Ativo' : 'Inativo'));
          const actions = $('td', 'law-contact-actions');
          actions.append(button('Detalhes', 'fs-btn fs-btn-secondary', (event) => openDetails(root, contact.id, contact.is_shared, refresh, event.currentTarget)));
          if (!contact.is_shared && can('law.contacts.update')) actions.append(button('Editar', 'fs-btn fs-btn-outline-primary', (event) => openEditor(root, contact, refresh, event.currentTarget)));
          if (!contact.is_shared && can('law.contacts.delete') && contact.status === 'ativo') actions.append(button('Inativar', 'fs-btn fs-btn-danger', async (event) => {
            if (!await confirmAction(root, 'Inativar contato', 'O cadastro deixará de aparecer entre os contatos ativos. Os dados históricos serão preservados.', 'Inativar contato', event.currentTarget)) return;
            try { await window.FokusApi.request(`/law/contacts/${encodeURIComponent(contact.id)}`, { method: 'DELETE' }); await refresh(); }
            catch (error) { state.dataset.state = 'error'; state.textContent = error.message || 'Não foi possível inativar o contato.'; }
          }));
          tr.append(actions); tbody.append(tr);
        });
        const pagination = result.pagination || { page, per_page: 25, total: currentItems.length };
        pageLabel.textContent = `Página ${pagination.page} · ${pagination.total.toLocaleString('pt-BR')} contato(s)`;
        previous.disabled = page <= 1; next.disabled = pagination.page * pagination.per_page >= pagination.total;
      } catch (error) {
        metrics.replaceChildren(); usage.dataset.state = 'error'; usage.textContent = error.message || 'Não foi possível carregar os contatos.';
        state.dataset.state = 'error'; state.textContent = 'Não foi possível carregar a lista. Atualize ou ajuste os filtros.';
      }
    }
    refresh();
    return { refresh };
  }

  function openEditor(root, contact, onSaved, opener = null) {
    const modal = createModal(root, contact ? 'Editar contato' : 'Novo contato', 'fs-modal-xl', opener);
    const form = $('form', 'law-contact-editor');
    form.id = `law-contact-form-${++modalSequence}`;
    const status = $('p', 'law-contact-feedback'); status.setAttribute('role', 'status');
    const basic = section('Dados principais');
    const nature = select([['pf', 'Pessoa física'], ['pj', 'Pessoa jurídica']], contact?.legal_nature || 'pf'); nature.name = 'legal_nature';
    const name = input(contact?.display_name || '', 'Nome completo ou nome fantasia', 180); name.required = true; name.name = 'display_name';
    const legalName = input(contact?.legal_name || '', 'Razão social (opcional)', 180); legalName.name = 'legal_name';
    basic.content.append(field('Natureza *', nature), field('Nome *', name)); const legalNameField = field('Razão social / nome complementar', legalName); basic.content.append(legalNameField);
    const classes = section('Classificações', 'Selecione todos os perfis profissionais aplicáveis.');
    const classGrid = $('div', 'law-contact-check-grid');
    Object.entries(CLASSIFICATIONS).forEach(([code, label]) => {
      const wrap = $('label', 'law-contact-check'); const check = $('input'); check.type = 'checkbox'; check.value = code; check.checked = (contact?.classifications || []).includes(code); wrap.append(check, $('span', '', label)); classGrid.append(wrap);
    }); classes.content.append(classGrid);
    let documentRows;
    let documentSection;
    if (window.lawContactsCanSensitive) {
      documentSection = section('Documentos', 'Até 4 documentos. CPF/CNPJ são opcionais e validados quando informados.');
      documentRows = $('div', 'law-contact-repeat-list');
      (contact?.documents || []).forEach((doc) => documentRows.append(documentRow(doc)));
      documentSection.content.append(documentRows, button('Adicionar documento', 'fs-btn fs-btn-secondary', () => { if (documentRows.children.length < 4) documentRows.append(documentRow()); }));
    }
    const channelSection = section('Telefones e e-mails', 'Até 4 telefones e 2 e-mails; marque como pessoal quando não for canal institucional.');
    const channelRows = $('div', 'law-contact-repeat-list'); (contact?.channels || []).filter((item) => window.lawContactsCanSensitive || !item.personal).forEach((item) => channelRows.append(channelRow(item)));
    channelSection.content.append(channelRows, button('Adicionar telefone ou e-mail', 'fs-btn fs-btn-secondary', () => { if (channelRows.children.length < 6) channelRows.append(channelRow()); }));
    const addressSection = section('Endereços', 'Até 2 endereços completos.');
    const addressRows = $('div', 'law-contact-repeat-list'); (contact?.addresses || []).forEach((item) => addressRows.append(addressRow(item)));
    addressSection.content.append(addressRows, button('Adicionar endereço', 'fs-btn fs-btn-secondary', () => { if (addressRows.children.length < 2) addressRows.append(addressRow()); }));
    const departmentSection = section('Departamentos da empresa', 'Cada departamento é uma unidade adicional no consumo contratado e aceita até 4 telefones e 2 e-mails.');
    const departmentRows = $('div', 'law-contact-repeat-list'); (contact?.departments || []).forEach((item) => departmentRows.append(departmentRow(item)));
    departmentSection.content.append(departmentRows, button('Adicionar departamento', 'fs-btn fs-btn-secondary', () => departmentRows.append(departmentRow())));
    const tagField = input((contact?.tags || []).join(', '), 'Ex.: testemunha, urgente, comarca', 400); tagField.name = 'tags';
    const tags = section('Tags', 'Separe por vírgula; até 6 por contato. As tags são reutilizadas pela empresa.'); tags.content.append(field('Tags', tagField));
    const notes = document.createElement('textarea'); notes.className = 'fs-form-control'; notes.maxLength = 4000; notes.value = contact?.notes || ''; notes.name = 'notes';
    const notesSection = section('Notas privadas', 'Visíveis somente a perfis com acesso a dados sensíveis.'); notesSection.content.append(field('Notas', notes));
    const message = $('p', 'law-contact-feedback'); message.setAttribute('role', 'status');
    if (contact) {
      const statusSelect = select([['ativo', 'Ativo'], ['inativo', 'Inativo']], contact.status); statusSelect.name = 'status'; basic.content.append(field('Situação', statusSelect));
      const excludedWrap = $('label', 'law-contact-check'); const excluded = $('input'); excluded.type = 'checkbox'; excluded.checked = Boolean(contact.sharing_excluded); excluded.name = 'sharing_excluded'; excludedWrap.append(excluded, $('span', '', 'Excluir dos compartilhamentos configurados')); basic.content.append(excludedWrap);
    }
    const cancel = button('Cancelar', 'fs-btn fs-btn-secondary', () => modal.close());
    const save = $('button', 'fs-btn fs-btn-primary', contact ? 'Salvar alterações' : 'Cadastrar contato'); save.type = 'submit'; save.setAttribute('form', form.id);
    modal.footer.append(cancel, save);
    form.append(basic, classes);
    if (documentSection) form.append(documentSection);
    form.append(channelSection, addressSection);
    const departmentWrap = $('div', 'law-contact-department-wrap'); departmentWrap.append(departmentSection);
    form.append(departmentWrap, tags);
    // Notes remain editable only for a profile that can read protected personal data.
    if (window.lawContactsCanSensitive) form.append(notesSection);
    form.append(message);
    const updateNature = () => { departmentWrap.hidden = nature.value !== 'pj'; legalNameField.hidden = nature.value !== 'pj'; };
    nature.addEventListener('change', updateNature); updateNature();
    form.addEventListener('submit', async (event) => {
      event.preventDefault(); save.disabled = true; message.textContent = '';
      const body = {
        legal_nature: nature.value, display_name: name.value.trim(), legal_name: legalName.value.trim() || null,
        classifications: [...classGrid.querySelectorAll('input:checked')].map((check) => check.value),
        channels: [...channelRows.children].map((row) => ({ type: row.querySelector('[data-channel-type]').value, value: row.querySelector('[data-channel-value]').value.trim(), label: row.querySelector('[data-channel-label]').value.trim() || null, personal: row.querySelector('[data-channel-personal]')?.checked || false })).filter((item) => item.value),
        addresses: [...addressRows.children].map((row) => ({ type: row.querySelector('[data-address-type]').value, postal_code: row.querySelector('[data-postal]').value.trim() || null, street: row.querySelector('[data-street]').value.trim(), number: row.querySelector('[data-number]').value.trim() || null, complement: row.querySelector('[data-complement]').value.trim() || null, district: row.querySelector('[data-district]').value.trim() || null, city: row.querySelector('[data-city]').value.trim(), state: row.querySelector('[data-state]').value.trim().toUpperCase() || null, country: row.querySelector('[data-country]').value.trim() || 'Brasil', primary: row.querySelector('[data-primary]').checked })).filter((address) => address.street && address.city),
        departments: nature.value === 'pj' ? [...departmentRows.children].map((row) => ({ name: row.querySelector('[data-department-name]').value.trim(), channels: [...row.querySelectorAll('.law-contact-department-channel')].map((item) => ({ type: item.querySelector('[data-channel-type]').value, value: item.querySelector('[data-channel-value]').value.trim(), label: item.querySelector('[data-channel-label]').value.trim() || null })).filter((item) => item.value) })).filter((item) => item.name) : [],
        tags: tagField.value.split(',').map((value) => value.trim()).filter(Boolean),
      };
      if (window.lawContactsCanSensitive) {
        body.documents = [...documentRows.children].map((row) => ({ type: row.querySelector('[data-doc-type]').value, number: row.querySelector('[data-doc-number]').value.trim(), state: row.querySelector('[data-doc-state]').value.trim() || null, label: row.querySelector('[data-doc-label]').value.trim() || null })).filter((doc) => doc.number);
        body.notes = notes.value.trim() || null;
      }
      if (contact) { body.status = form.elements.namedItem('status').value; body.sharing_excluded = form.elements.namedItem('sharing_excluded').checked; }
      try {
        await window.FokusApi.request(contact ? `/law/contacts/${encodeURIComponent(contact.id)}` : '/law/contacts', { method: contact ? 'PATCH' : 'POST', body });
        modal.close(); onSaved();
      } catch (error) { message.dataset.state = 'error'; message.textContent = error.message || 'Não foi possível salvar o contato.'; }
      finally { save.disabled = false; }
    });
    modal.body.append(form); name.focus();
  }

  function documentRow(doc = {}) {
    const row = $('div', 'law-contact-repeat-row law-contact-document-row');
    const type = select(Object.entries(DOCUMENTS), doc.type || 'cpf'); type.dataset.docType = '1';
    const number = input(doc.number || '', 'Número do documento', 120); number.dataset.docNumber = '1';
    const state = input(doc.state || '', 'UF', 2); state.dataset.docState = '1';
    const label = input(doc.label || '', 'Identificação', 80); label.dataset.docLabel = '1';
    row.append(field('Tipo', type), field('Número', number), field('UF', state), field('Identificação', label), button('Remover', 'law-contact-remove', () => row.remove())); return row;
  }

  function channelRow(item = {}) {
    const row = $('div', 'law-contact-repeat-row law-contact-channel-row');
    const type = select([['phone', 'Telefone'], ['email', 'E-mail']], item.type || 'phone'); type.dataset.channelType = '1';
    const value = input(item.value || '', 'Telefone ou e-mail', 255); value.dataset.channelValue = '1';
    const label = input(item.label || '', 'Rótulo (ex.: celular)', 80); label.dataset.channelLabel = '1';
    let personalWrap;
    if (window.lawContactsCanSensitive) { personalWrap = $('label', 'law-contact-check'); const personal = $('input'); personal.type = 'checkbox'; personal.checked = Boolean(item.personal); personal.dataset.channelPersonal = '1'; personalWrap.append(personal, $('span', '', 'Pessoal')); }
    row.append(field('Canal', type), field('Contato', value), field('Rótulo', label)); if (personalWrap) row.append(personalWrap); row.append(button('Remover', 'law-contact-remove', () => row.remove())); return row;
  }

  function addressRow(address = {}) {
    const row = $('fieldset', 'law-contact-address-row'); row.append($('legend', '', 'Endereço'));
    const addressTypes = [['business', 'Comercial/institucional'], ['correspondence', 'Correspondência'], ['other', 'Outro']];
    if (window.lawContactsCanSensitive) addressTypes.splice(1, 0, ['residential', 'Residencial']);
    const type = select(addressTypes, address.type || 'business'); type.dataset.addressType = '1';
    const postal = input(address.postal_code, 'CEP', 16); postal.dataset.postal = '1';
    const street = input(address.street, 'Logradouro', 180); street.required = true; street.dataset.street = '1';
    const number = input(address.number, 'Número', 32); number.dataset.number = '1';
    const complement = input(address.complement, 'Complemento', 120); complement.dataset.complement = '1';
    const district = input(address.district, 'Bairro', 120); district.dataset.district = '1';
    const city = input(address.city, 'Município', 120); city.required = true; city.dataset.city = '1';
    const state = input(address.state, 'UF', 2); state.dataset.state = '1';
    const country = input(address.country || 'Brasil', 'País', 80); country.dataset.country = '1';
    const primaryWrap = $('label', 'law-contact-check'); const primary = $('input'); primary.type = 'checkbox'; primary.checked = Boolean(address.primary); primary.dataset.primary = '1'; primaryWrap.append(primary, $('span', '', 'Principal'));
    row.append(field('Tipo', type), field('CEP', postal), field('Logradouro *', street), field('Número', number), field('Complemento', complement), field('Bairro', district), field('Município *', city), field('UF', state), field('País', country), primaryWrap, button('Remover', 'law-contact-remove', () => row.remove())); return row;
  }

  function departmentRow(department = {}) {
    const row = $('fieldset', 'law-contact-department-row'); row.append($('legend', '', 'Departamento'));
    const name = input(department.name || '', 'Ex.: Contabilidade', 120); name.dataset.departmentName = '1'; name.required = true;
    const channels = $('div', 'law-contact-repeat-list');
    (department.channels || []).forEach((channel) => channels.append(departmentChannelRow(channel)));
    const add = button('Adicionar telefone ou e-mail', 'fs-btn fs-btn-secondary', () => { if (channels.children.length < 6) channels.append(departmentChannelRow()); });
    row.append(field('Nome do departamento *', name), channels, add, button('Remover departamento', 'law-contact-remove', () => row.remove())); return row;
  }

  function departmentChannelRow(channel = {}) {
    const row = $('div', 'law-contact-repeat-row law-contact-department-channel');
    const type = select([['phone', 'Telefone'], ['email', 'E-mail']], channel.type || 'phone'); type.dataset.channelType = '1';
    const value = input(channel.value || '', 'Canal institucional do departamento'); value.dataset.channelValue = '1';
    const label = input(channel.label || '', 'Rótulo', 80); label.dataset.channelLabel = '1';
    row.append(field('Tipo', type), field('Contato', value), field('Rótulo', label), button('Remover', 'law-contact-remove', () => row.remove())); return row;
  }

  async function openDetails(root, id, isShared, onChanged, opener = null) {
    const result = await window.FokusApi.request(`/law/contacts/${encodeURIComponent(id)}${isShared ? '' : '?from_search=1'}`);
    const contact = result.contact;
    const modal = createModal(root, contact.display_name, 'fs-modal-xl', opener);
    const body = $('div', 'law-contact-detail-body');
    if (contact.is_shared) body.append($('p', 'law-contact-source', `Contato compartilhado por ${contact.source_company_name}. Dados somente para consulta.`));
    const addCard = (title, rows) => {
      const card = $('section', 'fs-card fs-card-sm law-contact-detail-card'); const header = $('div', 'fs-card-header'); header.append($('h3', 'fs-card-title', title));
      const cardBody = $('div', 'fs-card-body'); const dl = $('dl'); rows.forEach(([key, value]) => { const dt = $('dt', '', key); const dd = $('dd', '', value || '—'); dl.append(dt, dd); });
      cardBody.append(dl); card.append(header, cardBody); body.append(card);
    };
    addCard('Identificação', [['Natureza', contact.legal_nature === 'pj' ? 'Pessoa jurídica' : 'Pessoa física'], ['Razão social / nome complementar', contact.legal_name], ['Classificações', (contact.classification_labels || []).join(', ')], ['Tags', (contact.tags || []).join(', ')], ['Situação', contact.status]]);
    if (contact.documents?.length) addCard('Documentos', contact.documents.map((doc) => [doc.label || DOCUMENTS[doc.type] || doc.type, `${doc.number}${doc.state ? ` · ${doc.state}` : ''}`]));
    if (contact.channels?.length) addCard('Telefones e e-mails', contact.channels.map((item) => [item.label || (item.type === 'email' ? 'E-mail' : 'Telefone'), item.value]));
    if (contact.addresses?.length) addCard('Endereços', contact.addresses.map((item) => [item.type === 'residential' ? 'Residencial' : item.type === 'business' ? 'Comercial/institucional' : 'Endereço', [item.street, item.number, item.complement, item.district, item.city, item.state, item.postal_code].filter(Boolean).join(', ')]));
    if (contact.departments?.length) contact.departments.forEach((department) => addCard(`Departamento: ${department.name}`, department.channels.map((item) => [item.label || (item.type === 'email' ? 'E-mail' : 'Telefone'), item.value])));
    if (contact.notes) addCard('Notas privadas', [['Observações', contact.notes]]);
    if (contact.is_shared) body.append($('p', 'law-contact-share-notice', 'As alterações só podem ser feitas pela empresa responsável pelo cadastro.'));
    modal.body.append(body);
    if (!isShared && window.lawContactsCanMerge) {
      const merge = button('Mesclar com outro contato', 'fs-btn fs-btn-outline-primary', () => openMerge(modal, root, contact, onChanged)); modal.footer.append(merge);
    }
  }

  async function openMerge(parent, root, contact, onChanged) {
    const result = await window.FokusApi.request('/law/contacts?status=ativo&per_page=100');
    const choices = [['', 'Selecione o contato que será mantido'], ...(result.contacts || []).filter((item) => item.id !== contact.id && !item.is_shared && item.legal_nature === contact.legal_nature).map((item) => [item.id, item.display_name])];
    parent.close();
    const modal = createModal(root, 'Mesclar contatos', 'fs-modal-lg');
    const form = $('form', 'law-contact-editor'); form.id = `law-contact-form-${++modalSequence}`;
    const target = select(choices); target.required = true; const reason = document.createElement('textarea'); reason.className = 'fs-form-control'; reason.minLength = 5; reason.maxLength = 500; reason.required = true; reason.placeholder = 'Explique por que estes cadastros representam o mesmo contato.';
    const message = $('p', 'law-contact-feedback'); message.setAttribute('role', 'status');
    modal.footer.append(button('Cancelar', 'fs-btn fs-btn-secondary', () => modal.close())); const submit = $('button', 'fs-btn fs-btn-primary', 'Mesclar cadastros'); submit.type = 'submit'; submit.setAttribute('form', form.id); modal.footer.append(submit);
    form.append(field('Contato que será mantido', target), field('Motivo da mesclagem', reason), message);
    form.addEventListener('submit', async (event) => { event.preventDefault(); submit.disabled = true; try { await window.FokusApi.request(`/law/contacts/${encodeURIComponent(contact.id)}/merge`, { method: 'POST', body: { target_contact_id: target.value, reason: reason.value.trim() } }); modal.close(); onChanged(); } catch (error) { message.dataset.state = 'error'; message.textContent = error.message || 'Não foi possível mesclar os contatos.'; } finally { submit.disabled = false; } });
    modal.body.append(form);
  }

  async function openSharing(root, opener = null) {
    const result = await window.FokusApi.request('/law/contact-sharing');
    const modal = createModal(root, 'Compartilhamento entre empresas', 'fs-modal-xl', opener); const form = $('form', 'law-contact-editor'); form.id = `law-contact-form-${++modalSequence}`;
    form.append($('p', 'law-contact-help', 'Escolha empresas destinatárias, classificações profissionais e campos permitidos. A origem mantém a propriedade dos contatos.'));
    const policies = new Map((result.policies || []).map((policy) => [policy.recipient_company_id, policy]));
    const items = $('div', 'law-contact-sharing-list');
    (result.companies || []).forEach((company) => {
      const policy = policies.get(company.id); const card = $('fieldset', 'fs-card fs-card-sm law-contact-share-card'); const legend = $('legend', '', company.name); card.append(legend);
      const enabledWrap = $('label', 'law-contact-check'); const enabled = $('input'); enabled.type = 'checkbox'; enabled.checked = Boolean(policy?.is_active); enabled.dataset.shareCompany = company.id; enabledWrap.append(enabled, $('span', '', 'Permitir acesso')); card.append(enabledWrap);
      const classes = $('div', 'law-contact-check-grid');
      Object.entries(CLASSIFICATIONS).forEach(([code, label]) => { const wrap = $('label', 'law-contact-check'); const check = $('input'); check.type = 'checkbox'; check.value = code; check.checked = (policy?.classification_codes || []).includes(code); check.dataset.shareClass = '1'; wrap.append(check, $('span', '', label)); classes.append(wrap); });
      card.append($('p', 'law-contact-help', 'Classificações visíveis'), classes);
      const fields = $('div', 'law-contact-check-grid');
      (result.share_fields && Object.entries(result.share_fields) || []).forEach(([code, label]) => { const wrap = $('label', 'law-contact-check'); const check = $('input'); check.type = 'checkbox'; check.value = code; check.checked = (policy?.shared_fields || ['professional_channels']).includes(code); check.dataset.shareField = '1'; wrap.append(check, $('span', '', label)); fields.append(wrap); });
      card.append($('p', 'law-contact-help', 'Campos autorizados'), fields); items.append(card);
    });
    const message = $('p', 'law-contact-feedback'); message.setAttribute('role', 'status');
    modal.footer.append(button('Cancelar', 'fs-btn fs-btn-secondary', () => modal.close())); const save = $('button', 'fs-btn fs-btn-primary', 'Salvar regras'); save.type = 'submit'; save.setAttribute('form', form.id); modal.footer.append(save);
    form.append(items, message);
    form.addEventListener('submit', async (event) => {
      event.preventDefault(); save.disabled = true;
      const policiesToSave = [...items.querySelectorAll('[data-share-company]:checked')].map((enabled) => {
        const card = enabled.closest('fieldset'); return {
          recipient_company_id: enabled.dataset.shareCompany,
          classification_codes: [...card.querySelectorAll('[data-share-class]:checked')].map((check) => check.value),
          shared_fields: [...card.querySelectorAll('[data-share-field]:checked')].map((check) => check.value),
        };
      });
      try { await window.FokusApi.request('/law/contact-sharing', { method: 'PUT', body: { policies: policiesToSave } }); modal.close(); }
      catch (error) { message.dataset.state = 'error'; message.textContent = error.message || 'Não foi possível salvar as regras de compartilhamento.'; }
      finally { save.disabled = false; }
    });
    modal.body.append(form);
  }

  window.FokusLawContacts = { render, openContact: (root, id) => openDetails(root, id, false, () => {}) };
})();

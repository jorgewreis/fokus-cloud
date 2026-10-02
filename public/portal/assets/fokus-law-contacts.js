(() => {
  const DOCUMENTS = { cpf: 'CPF', cnpj: 'CNPJ', state_registration: 'Inscrição estadual', oab: 'OAB', rg: 'RG', registration: 'Matrícula', cadastro: 'Cadastro', voter_title: 'Título de eleitor', passport: 'Passaporte', other: 'Outro' };
  const DOCUMENT_TYPES_BY_NATURE = { pf: ['cpf', 'oab', 'rg', 'registration', 'cadastro', 'voter_title', 'passport', 'other'], pj: ['cnpj', 'state_registration', 'other'] };
  const DOCUMENT_TYPES_WITHOUT_STATE = ['cpf', 'cnpj', 'registration', 'cadastro', 'passport', 'voter_title'];
  const DOCUMENT_TYPES_WITHOUT_LABEL = [...DOCUMENT_TYPES_WITHOUT_STATE, 'state_registration'];
  const STATES = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'].map((uf) => [uf, uf]);
  const CONTACT_ICONS = '/backoffice/assets/icons/';
  const $ = (tag, cls = '', text = '') => { const node = document.createElement(tag); if (cls) node.className = cls; if (text !== '') node.textContent = text; return node; };
  const formatContactDocument = (document) => {
    const value = String(document.number || 'Dado protegido');
    if (value === 'Dado protegido' || value.includes('•') || value.includes('*')) return value;
    const digits = value.replace(/\D/g, '');
    if (document.type === 'cpf') return window.FokusDocuments?.formatCpf(value) || value;
    if (document.type === 'cnpj') return window.FokusDocuments?.formatCnpj(value) || value;
    if (document.type === 'oab' && digits.length > 3) return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return value;
  };
  const formatContactPhone = (value) => {
    const original = String(value || 'Dado protegido');
    if (original === 'Dado protegido' || original.includes('•') || original.includes('*')) return original;
    const digits = original.replace(/\D/g, '');
    if (digits.length === 10) return `(${digits.slice(0, 2)}) ${digits.slice(2, 6)}-${digits.slice(6)}`;
    if (digits.length === 11) return `(${digits.slice(0, 2)}) ${digits.slice(2, 7)}-${digits.slice(7)}`;
    return original;
  };
  const field = (labelText, control) => { const label = $('label', 'law-contact-field'); label.append($('span', '', labelText), control); return label; };
  const setWidth = (control, width) => { control.classList.add(`fs-width-${width}`); return control; };
  const select = (items, value = '') => { const control = $('select', 'fs-form-control'); items.forEach(([v, label]) => { const option = new Option(label, v); option.selected = v === value; control.append(option); }); return control; };
  const input = (value = '', placeholder = '', maxLength = 255) => { const control = $('input', 'fs-form-control'); control.value = value || ''; control.placeholder = placeholder; control.maxLength = maxLength; return control; };
  const button = (text, cls = 'fs-btn fs-btn-secondary', fn) => { const control = $('button', cls, text); control.type = 'button'; if (fn) control.addEventListener('click', (event) => fn(event)); return control; };
  const CONTACT_PAGE_SIZE = 15;
  function renderPagination(container, pagination, onPage, label = 'contatos') {
    const current = Number(pagination.page || 1);
    const perPage = Number(pagination.per_page || CONTACT_PAGE_SIZE);
    const total = Number(pagination.total || 0);
    const last = Math.max(1, Math.ceil(total / perPage));
    const summary = $('span', 'fs-u-fs-sm fs-u-color-secondary', total ? `Mostrando ${(current - 1) * perPage + 1} a ${Math.min(current * perPage, total)} de ${total.toLocaleString('pt-BR')} ${label}` : 'Nenhum registro encontrado');
    summary.setAttribute('role', 'status');
    const nav = $('nav'); nav.setAttribute('aria-label', `Paginação de ${label}`);
    const list = $('ul', 'fs-pagination fs-pagination-compact');
    const addPage = (text, target, ariaLabel, disabled, active = false) => {
      const item = $('li', `fs-page-item${active ? ' is-active' : ''}`);
      const control = button(text, 'fs-page-link', async () => {
        if (disabled || active || container.getAttribute('aria-busy') === 'true') return;
        container.setAttribute('aria-busy', 'true');
        const buttons = [...list.querySelectorAll('button')];
        const disabledStates = buttons.map((button) => button.disabled);
        buttons.forEach((button) => { button.disabled = true; });
        try { await onPage(target); }
        catch (error) { window.alert(error.message || 'Não foi possível carregar a página.'); }
        finally { container.removeAttribute('aria-busy'); buttons.forEach((button, index) => { button.disabled = disabledStates[index]; }); }
      });
      control.setAttribute('aria-label', ariaLabel); control.disabled = disabled;
      if (active) { item.setAttribute('aria-current', 'page'); control.setAttribute('aria-current', 'page'); }
      item.append(control); list.append(item);
    };
    addPage('‹', current - 1, 'Página anterior', current <= 1);
    const pages = last <= 7
      ? Array.from({ length: last }, (_, index) => index + 1)
      : [...new Set([1, current - 1, current, current + 1, last])].filter((page) => page >= 1 && page <= last).sort((a, b) => a - b);
    pages.forEach((page, index) => {
      if (index > 0 && page - pages[index - 1] > 1) {
        const gap = $('li', 'fs-page-item'); gap.setAttribute('aria-hidden', 'true');
        gap.append($('span', 'fs-page-link', '…')); list.append(gap);
      }
      addPage(String(page), page, `Página ${page} de ${last}`, false, page === current);
    });
    addPage('›', current + 1, 'Próxima página', current >= last);
    nav.append(list); container.replaceChildren(summary, nav);
  }
  const iconButton = (label, icon, fn) => { const control = button('', 'fs-btn fs-btn-icon fs-btn-icon-plain fs-table-action', fn); control.setAttribute('aria-label', label); control.title = label; const image = $('img'); image.src = `${CONTACT_ICONS}${icon}`; image.alt = ''; control.append(image); return control; };
  const section = (title) => {
    const box = $('section', 'fs-card fs-card-sm law-contacts-form-section');
    const header = $('div', 'fs-card-header fs-u-p-3'); header.append($('h3', 'fs-card-title', title));
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

  async function openContactContextSettings(root, opener, onSaved) {
    const feedback = $('p', 'law-contact-feedback'); feedback.setAttribute('role', 'status');
    try {
      const initial = await window.FokusApi.request('/law/contacts/context');
      const modal = createModal(root, 'Contexto da base de Contatos', 'fs-modal-md', opener);
      const options = initial.available_contexts || [];
      const choice = setWidth(select(options.map((item) => [item.context_code, item.label]), initial.context.context_code), 800);
      const preview = $('div', 'law-contact-context-preview fs-alert fs-alert-info');
      const message = $('p', 'law-contact-feedback'); message.setAttribute('role', 'status');
      const renderPreview = () => {
        const item = options.find((option) => option.context_code === choice.value);
        preview.replaceChildren($('strong', '', `Prévia: ${item?.label || 'Contexto selecionado'}`), $('p', '', `A configuração ajustará os rótulos para “${item?.organization_label || 'Organização'}” e “${item?.unit_label || 'Unidade'}”, além das sugestões de cadastro. ${Number(initial.preview?.contacts || 0).toLocaleString('pt-BR')} registros e seus vínculos serão preservados.`));
      };
      choice.addEventListener('change', renderPreview); renderPreview();
      modal.body.append(field('Segmento e contexto', choice), preview, message);
      modal.footer.append(button('Cancelar', 'fs-btn fs-btn-secondary', () => modal.close()));
      const save = button('Aplicar contexto', 'fs-btn fs-btn-primary', async () => {
        save.disabled = true;
        try { await window.FokusApi.request('/law/contacts/context', { method: 'PUT', body: { context_code: choice.value } }); modal.close(); await onSaved?.(); }
        catch (error) { message.dataset.state = 'error'; message.textContent = error.message || 'Não foi possível atualizar o contexto.'; }
        finally { save.disabled = false; }
      });
      modal.footer.append(save);
    } catch (error) { feedback.dataset.state = 'error'; feedback.textContent = error.message || 'Não foi possível carregar os contextos disponíveis.'; root.append(feedback); }
  }

  function render(root, context) {
    const permissions = new Set(context.law_permissions || []);
    if (context.company?.role === 'admin') ['law.contacts.view', 'law.contacts.create', 'law.contacts.update', 'law.contacts.delete', 'law.contacts.merge', 'law.contacts.sensitive.view', 'law.contacts.shared.view', 'law.contacts.share.manage'].forEach((p) => permissions.add(p));
    const can = (permission) => permissions.has(permission);
    window.lawContactsCanSensitive = can('law.contacts.sensitive.view');
    window.lawContactsCanMerge = can('law.contacts.merge');
    let page = 1;
    let currentItems = [];
    let currentContext = null;
    let hierarchyOptions = [];
    let relationshipOptions = [];
    let designationOptions = [];
    let competencyOptions = [];
    let searchTimer;
    root.replaceChildren();
    const heading = $('div', 'law-page-heading law-contact-page-heading');
    heading.append($('p', 'law-page-eyebrow', 'GESTÃO DE CONTATOS'), $('h2', '', 'Contatos'), $('p', 'law-page-lede', 'Organize pessoas, organizações e unidades em uma base compartilhada pelos setores autorizados.'));
    const headingActions = $('div', 'law-contact-heading-actions');
    if (can('law.contacts.create')) {
      headingActions.append(button('Novo contato', 'fs-btn fs-btn-primary', (event) => openEditor(root, null, refresh, event.currentTarget, relationshipOptions, designationOptions, competencyOptions)));
      headingActions.append(button('Cadastrar unidade', 'fs-btn fs-btn-secondary', (event) => openEditor(root, null, refresh, event.currentTarget, relationshipOptions, designationOptions, competencyOptions, 'unit')));
    }
    heading.append(headingActions);
    root.append(heading);

    const overview = $('section', 'law-contact-overview-banner fs-card');
    overview.setAttribute('aria-label', 'Resumo da base de contatos');
    root.append(overview);
    const metrics = $('section', 'law-contact-metrics'); metrics.setAttribute('aria-live', 'polite'); metrics.append($('p', 'law-contact-loading', 'Carregando contatos…')); root.append(metrics);
    const recentCard = $('section', 'law-contact-recent-card fs-card');
    const recentHeader = $('div', 'fs-card-header fs-u-p-3 law-contact-recent-header');
    recentHeader.append($('h3', 'fs-card-title', 'Acessados recentemente'));
    const recentBody = $('div', 'fs-card-body law-contact-recent');
    recentCard.append(recentHeader, recentBody); root.append(recentCard);

    const filters = $('form', 'law-contact-filters');
    const search = input('', 'Buscar por nome, organização ou documento autorizado', 180); search.type = 'search'; search.setAttribute('aria-label', 'Buscar contatos');
    const nature = select([['', 'Todos'], ['pf', 'Pessoa física'], ['pj', 'Pessoa jurídica']]); nature.setAttribute('aria-label', 'Filtrar por pessoa física ou jurídica');
    const recordKind = select([['', 'Todos os registros'], ['contact', 'Contatos (PF/PJ)'], ['unit', 'Unidades']]); recordKind.setAttribute('aria-label', 'Filtrar por tipo de registro');
    const profession = select([['', 'Todas as profissões']]); profession.setAttribute('aria-label', 'Filtrar por profissão');
    const tagFilter = input('', 'Filtrar por tag', 64); tagFilter.setAttribute('list', 'law-contact-tags'); tagFilter.setAttribute('aria-label', 'Filtrar por tag');
    const datalist = $('datalist'); datalist.id = 'law-contact-tags'; tagFilter.setAttribute('list', datalist.id);
    filters.append(field('Pesquisar', search), field('Tipo de registro', recordKind), field('Pessoa física ou jurídica', nature), field('Profissão / vínculo', profession), field('Tag', tagFilter), datalist);
    filters.addEventListener('submit', (event) => { event.preventDefault(); page = 1; refresh(); });
    [recordKind, nature, profession, tagFilter].forEach((control) => control.addEventListener('change', () => { page = 1; refresh(); }));
    search.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => { page = 1; refresh(); }, 250); });
    root.append(filters);

    const state = $('p', 'law-contact-feedback'); state.setAttribute('role', 'status'); root.append(state);
    const tableWrap = $('div', 'fs-table-responsive law-contact-table-wrap');
    const table = $('table', 'fs-table law-contact-table');
    const thead = $('thead'); const headerRow = $('tr');
    ['Nome', 'Cadastro', 'Profissão / vínculo', 'Tags', 'Ações'].forEach((label) => headerRow.append($('th', '', label)));
    thead.append(headerRow); table.append(thead); const tbody = $('tbody'); table.append(tbody); tableWrap.append(table); root.append(tableWrap);
    const footer = $('div', 'law-contact-pagination'); root.append(footer);

    async function refresh() {
      state.textContent = '';
      try {
        const params = new URLSearchParams({ page: String(page), per_page: String(CONTACT_PAGE_SIZE) });
        if (search.value.trim()) params.set('q', search.value.trim());
        if (nature.value) params.set('nature', nature.value);
        if (recordKind.value) params.set('record_kind', recordKind.value);
        if (profession.value) params.set('profession', profession.value);
        if (tagFilter.value.trim()) params.set('tag', tagFilter.value.trim());
        const result = await window.FokusApi.request(`/law/contacts?${params.toString()}`);
        currentContext = result.context || currentContext;
        hierarchyOptions = result.hierarchy_options || [];
        window.lawContactContext = currentContext;
        window.lawContactHierarchyOptions = hierarchyOptions;
        if (currentContext) heading.children[2].textContent = `${currentContext.label} · Base compartilhada entre setores autorizados.`;
        window.lawContactProfessions = result.professions || [];
        const selectedProfession = profession.value;
        const filterProfessions = result.filter_professions || [];
        if (selectedProfession && !filterProfessions.some((item) => item.normalized_name === selectedProfession)) {
          filterProfessions.push({ name: selectedProfession, normalized_name: selectedProfession });
        }
        profession.replaceChildren(new Option('Todas as profissões', ''), ...filterProfessions.map((item) => new Option(item.name, item.normalized_name)));
        profession.value = selectedProfession;
        relationshipOptions = result.relationship_options || [];
        designationOptions = result.designation_options || [];
        competencyOptions = result.competency_options || [];
        currentItems = result.contacts || [];
        metrics.replaceChildren();
        const summary = result.summary || {};
        const meter = summary.usage;
        const total = Number(summary.contacts_total || 0);
        const pf = Number(summary.pf || 0);
        const pj = Number(summary.pj || 0);
        const units = Number(summary.units || summary.departments || 0);
        const natureTotal = Math.max(1, pf + pj + units);
        overview.replaceChildren();
        const bannerCopy = $('div', 'law-contact-overview-copy');
        bannerCopy.append($('span', 'law-contact-overview-kicker', 'PAINEL DE RELACIONAMENTO'));
        bannerCopy.append($('h3', '', 'Sua rede de contatos, em uma visão.'));
        bannerCopy.append($('p', '', `Pessoas, organizações e ${currentContext?.unit_label?.toLocaleLowerCase('pt-BR') || 'unidades'} com seus vínculos institucionais sempre à mão.`));
        const quota = $('div', 'law-contact-capacity');
        if (meter?.available) {
          const quotaHead = $('div', 'law-contact-capacity-head');
          quotaHead.append($('span', '', meter.label), $('strong', '', `${Number(meter.used).toLocaleString('pt-BR')} / ${Number(meter.limit).toLocaleString('pt-BR')}`));
          const track = $('div', 'law-contact-capacity-track'); track.setAttribute('role', 'progressbar'); track.setAttribute('aria-label', meter.label); track.setAttribute('aria-valuemin', '0'); track.setAttribute('aria-valuemax', String(meter.limit)); track.setAttribute('aria-valuenow', String(meter.used));
          const fill = $('span', 'law-contact-capacity-fill'); fill.style.width = `${Math.min(100, Number(meter.percentage || 0))}%`; track.append(fill);
          quota.append(quotaHead, track, $('small', '', `${Number(meter.percentage || 0)}% da capacidade utilizada`));
          quota.dataset.state = meter.over_threshold ? 'warning' : 'normal';
        } else quota.append($('small', '', 'Capacidade contratada não configurada.'));
        bannerCopy.append(quota);

        const chartArea = $('div', 'law-contact-overview-chart');
        const ring = $('div', 'law-contact-composition-ring');
        ring.style.setProperty('--contact-pf-share', `${(pf / natureTotal) * 100}%`);
        ring.dataset.empty = String(total === 0);
        ring.setAttribute('role', 'img'); ring.setAttribute('aria-label', `Composição da base: ${pf} pessoas, ${pj} organizações e ${units} unidades`);
        const center = $('div', 'law-contact-ring-center'); center.append($('strong', '', total.toLocaleString('pt-BR')), $('span', '', 'CONTATOS')); ring.append(center);
        const legend = $('div', 'law-contact-chart-legend');
        [['pf', 'Pessoa física', pf], ['pj', 'Pessoa jurídica', pj], ['dept', currentContext?.unit_label || 'Unidades', units]].forEach(([tone, label, value]) => {
          const row = $('div', `law-contact-chart-legend-row law-contact-chart-${tone}`); row.append($('span', 'law-contact-chart-dot'), $('span', '', label), $('strong', '', Number(value || 0).toLocaleString('pt-BR'))); legend.append(row);
        });
        chartArea.append(ring, legend); overview.append(bannerCopy, chartArea);

        const active = Number(summary.contacts_active || 0);
        const metricSpecs = [
          ['Contatos na base', total, `${active.toLocaleString('pt-BR')} registros disponíveis`, 'violet'],
          ['Pessoas / contatos', pf, `${Math.round((pf / natureTotal) * 100)}% da base de contatos`, 'blue'],
          ['Organizações', pj, `${Math.round((pj / natureTotal) * 100)}% da base de contatos`, 'teal'],
          [currentContext?.unit_label || 'Unidades', units, `${Number(summary.registrations_counted || total).toLocaleString('pt-BR')} registros contabilizados`, 'amber'],
        ];
        metricSpecs.forEach(([label, value, note, tone], index) => {
          const card = $('article', `law-contact-metric-card fs-card law-contact-metric-${tone}`);
          const body = $('div', 'fs-card-body law-contact-metric-body');
          body.append($('span', 'law-contact-metric-index', String(index + 1).padStart(2, '0')), $('span', 'law-contact-metric-label', label), $('strong', '', Number(value).toLocaleString('pt-BR')), $('small', '', note));
          card.append(body); metrics.append(card);
        });
        recentBody.replaceChildren();
        if (summary.recent?.length) summary.recent.slice(0, 5).forEach((item) => {
          const link = button('', 'law-contact-recent-item', (event) => openDetails(root, item.id, false, refresh, event.currentTarget));
          const detail = $('span', 'law-contact-recent-meta');
          const activityLabels = { created: 'Cadastrado', viewed: 'Consultado', search_opened: 'Consultado' };
          const timestamp = item.at ? new Date(item.at) : null;
          const time = $('time', '', timestamp && !Number.isNaN(timestamp.valueOf()) ? timestamp.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : 'Agora');
          if (timestamp && !Number.isNaN(timestamp.valueOf())) time.dateTime = timestamp.toISOString();
          detail.append($('span', '', activityLabels[item.activity] || 'Consultado'), time);
          link.append($('strong', '', item.display_name), detail); recentBody.append(link);
        });
        else recentBody.append($('p', 'law-contact-recent-empty', 'Os contatos criados ou consultados aparecerão aqui.'));
        datalist.replaceChildren(...(result.tags || []).map((name) => { const option = $('option'); option.value = name; return option; }));
        tbody.replaceChildren();
        if (!currentItems.length) {
          const tr = $('tr'); const td = $('td', 'law-contact-empty', 'Nenhum contato encontrado com estes filtros.'); td.colSpan = 5; tr.append(td); tbody.append(tr);
        } else currentItems.forEach((contact) => {
          const tr = $('tr');
          const titleCell = $('td'); const open = button(contact.display_name, 'law-contact-name', (event) => openDetails(root, contact.id, contact.is_shared, refresh, event.currentTarget));
          titleCell.append(open); if (contact.is_shared) titleCell.append($('span', 'law-contact-shared-badge', `Compartilhado por ${contact.source_company_name || 'outra empresa'}`));
          tr.append(titleCell, $('td', '', contact.record_kind === 'unit' ? (currentContext?.unit_label || 'Unidade') : contact.legal_nature === 'pj' ? 'Pessoa jurídica' : 'Pessoa física'));
          tr.append($('td', '', contact.legal_nature === 'pj' || contact.record_kind === 'unit' ? '—' : ((contact.professions || []).join(', ') || '—')));
          tr.append($('td', '', (contact.tags || []).join(', ') || '—'));
          const actions = $('td', 'law-contact-actions');
          const actionList = $('div', 'law-contact-action-list');
          actionList.append(iconButton('Ver detalhes', 'Folder-File--Streamline-Ultimate.png', (event) => openDetails(root, contact.id, contact.is_shared, refresh, event.currentTarget)));
          if (!contact.is_shared && can('law.contacts.update')) actionList.append(iconButton('Editar contato', 'Common-File-Edit--Streamline-Ultimate.png', (event) => openEditor(root, contact, refresh, event.currentTarget, relationshipOptions, designationOptions, competencyOptions)));
          if (!contact.is_shared && can('law.contacts.delete')) actionList.append(iconButton('Excluir contato', 'Common-File-Remove--Streamline-Ultimate.png', async (event) => {
            if (!await confirmAction(root, 'Excluir contato', 'O cadastro será removido da base ativa e deixará de consumir capacidade. A trilha de auditoria será preservada.', 'Excluir contato', event.currentTarget)) return;
            try { await window.FokusApi.request(`/law/contacts/${encodeURIComponent(contact.id)}`, { method: 'DELETE' }); await refresh(); }
            catch (error) { state.dataset.state = 'error'; state.textContent = error.message || 'Não foi possível excluir o contato.'; }
          }));
          actions.append(actionList);
          tr.append(actions); tbody.append(tr);
        });
        const pagination = result.pagination || { page, per_page: CONTACT_PAGE_SIZE, total: currentItems.length };
        renderPagination(footer, pagination, async (target) => { page = target; await refresh(); });
      } catch (error) {
        metrics.replaceChildren(); overview.replaceChildren($('p', 'law-contact-overview-error', error.message || 'Não foi possível carregar o resumo da base.'));
        recentBody.replaceChildren($('p', 'law-contact-recent-empty', 'A atividade recente ficará disponível quando a lista carregar.'));
        state.dataset.state = 'error'; state.textContent = 'Não foi possível carregar a lista. Atualize ou ajuste os filtros.';
      }
    }
    refresh();
    return { refresh };
  }

  function openEditor(root, contact, onSaved, opener = null, relationshipOptions = [], designationOptions = [], competencyOptions = [], initialRecordKind = null) {
    const isUnitRecord = contact?.record_kind === 'unit' || initialRecordKind === 'unit';
    const modal = createModal(root, contact ? 'Editar contato' : isUnitRecord ? `Cadastrar ${window.lawContactContext?.unit_label || 'Unidade'}` : 'Novo contato', 'fs-modal-xl', opener);
    const form = $('form', 'law-contact-editor');
    form.id = `law-contact-form-${++modalSequence}`;
    const status = $('p', 'law-contact-feedback'); status.setAttribute('role', 'status');
    const basic = section('Dados principais');
    const contextData = window.lawContactContext || { context_code: 'judiciario', organization_label: 'Organização', unit_label: 'Unidade' };
    const recordKind = setWidth(select([['contact', 'Contato'], ['unit', contextData.unit_label]], isUnitRecord ? 'unit' : 'contact'), 800); recordKind.name = 'record_kind'; recordKind.disabled = true;
    const nature = setWidth(select([['pf', 'Pessoa física'], ['pj', 'Pessoa jurídica']], contact?.legal_nature || 'pf'), 800); nature.name = 'legal_nature';
    const name = setWidth(input(contact?.display_name || '', 'Nome completo ou nome fantasia', 180), 800); name.required = true; name.name = 'display_name';
    const acronym = input(contact?.acronym || '', 'Ex.: TJBA', 32); acronym.name = 'acronym';
    const particles = new Set(['da','das','de','do','dos','e']);
    name.addEventListener('blur', () => { name.value = name.value.trim().toLocaleLowerCase('pt-BR').replace(/(^|[\s-])([^\s-]+)/gu, (part) => { const word = part.trimStart(); const prefix = part.slice(0, part.length - word.length); return prefix + (particles.has(word) ? word : word.charAt(0).toLocaleUpperCase('pt-BR') + word.slice(1)); }); });
    const legalName = input(contact?.legal_name || '', 'Informe a razão social ou um complemento do nome', 180); legalName.name = 'legal_name';
    const nameField = field('Nome *', name); const acronymField = field('Sigla', acronym);
    const nameRow = $('div', 'law-contact-name-fields'); nameRow.append(nameField, acronymField);
    const natureField = field('Natureza do contato *', nature);
    const recordKindField = field('Cadastro', recordKind);
    const parentPlaceholder = contextData.context_code === 'escritorio' ? 'Selecione o escritório ou a filial' : 'Selecione o órgão ou a unidade';
    const hierarchyParent = setWidth(select([['', parentPlaceholder], ...(window.lawContactHierarchyOptions || []).filter((item) => item.id !== contact?.id).map((item) => [item.id, `${item.display_name}${item.record_kind === 'unit' ? ` · ${contextData.unit_label}` : ''}`])], contact?.parent_contact_id || ''), 800);
    hierarchyParent.name = 'parent_contact_id';
    const parentField = field('Vinculado a', hierarchyParent);
    const preserveHiddenParent = Boolean(contact?.parent_contact_id && !contact?.parent);
    if (preserveHiddenParent) parentField.hidden = true;
    if (isUnitRecord) basic.content.append(recordKindField, parentField, nameRow);
    else basic.content.append(natureField, parentField, nameRow);
    const legalNameField = field('Razão social ou complemento do nome', legalName); basic.content.append(legalNameField);
    const professionSection = section('Profissão / vínculo');
    const professionRows = $('div', 'law-contact-profession-list');
    (contact?.professions || []).forEach((value) => professionRows.append(professionChip(value)));
    const professionSelect = select([['', 'Selecione uma profissão'], ...(window.lawContactProfessions || []).map((value) => [value, value])]);
    const professionNew = setWidth(input('', 'Ex.: Policial Civil, Guarda Municipal, Agente penitenciário', 100), 800);
    const professionSuggestions = $('datalist'); professionSuggestions.id = `${form.id}-profession-suggestions`;
    const professionExamples = contextData.context_code === 'escritorio' ? ['Advogado(a)', 'Estagiário(a) de Direito', 'Correspondente jurídico'] : contextData.context_code === 'orgao_publico' ? ['Servidor(a) público(a)', 'Agente público(a)', 'Conselheiro(a)', 'Policial militar', 'Policial civil', 'Policial federal'] : ['Magistrado(a)', 'Servidor(a) público(a)', 'Oficial de justiça', 'Defensor(a) público(a)', 'Policial militar', 'Policial civil', 'Policial federal'];
    professionExamples.forEach((value) => { const option = $('option'); option.value = value; professionSuggestions.append(option); });
    professionNew.setAttribute('list', professionSuggestions.id);
    const addProfession = (value) => { const clean = value.trim(); if (clean && ![...professionRows.querySelectorAll('[data-profession]')].some((item) => item.dataset.profession.toLocaleLowerCase() === clean.toLocaleLowerCase())) professionRows.append(professionChip(clean)); professionSelect.value = ''; professionNew.value = ''; };
    professionSelect.addEventListener('change', () => { if (professionSelect.value) addProfession(professionSelect.value); });
    professionSection.content.append(professionRows, field('Profissões cadastradas', professionSelect), field('Nova profissão / especificação', professionNew), professionSuggestions, button('Adicionar profissão', 'fs-btn fs-btn-secondary', () => addProfession(professionNew.value)));
    const institutionalSection = section('Dados institucionais');
    const institutionalData = Array.isArray(contact?.institutional_data) ? contact.institutional_data : (contact?.institutional_data ? [contact.institutional_data] : []);
    const courtData = institutionalData.find((item) => item.type === 'court_unit') || {};
    const publicData = institutionalData.find((item) => item.type === 'public_body') || {};
    const courtTypeWrap = $('label', 'law-contact-check'); const courtType = $('input'); courtType.type = 'checkbox'; courtType.checked = Boolean(institutionalData.find((item) => item.type === 'court_unit')); courtTypeWrap.append(courtType, $('span', '', 'Unidade judiciária'));
    const publicTypeWrap = $('label', 'law-contact-check'); const publicType = $('input'); publicType.type = 'checkbox'; publicType.checked = Boolean(institutionalData.find((item) => item.type === 'public_body')); publicTypeWrap.append(publicType, $('span', '', 'Órgão público'));
    const institutionTypes = $('div', 'law-contact-institution-types'); institutionTypes.append(courtTypeWrap, publicTypeWrap);
    const primaryInstitutional = setWidth(select([['', 'Selecione o tipo institucional principal']], institutionalData.find((item) => item.primary)?.type || institutionalData[0]?.type || ''), 800);
    const updateInstitutionalPrimary = () => {
      const enabled = [['court_unit', 'Unidade judiciária'], ['public_body', 'Órgão público']].filter(([code]) => code === 'court_unit' ? courtType.checked : publicType.checked);
      const previous = primaryInstitutional.value;
      primaryInstitutional.replaceChildren(new Option('Selecione o tipo institucional principal', ''), ...enabled.map(([code, label]) => new Option(label, code)));
      primaryInstitutional.value = enabled.some(([code]) => code === previous) ? previous : (enabled[0]?.[0] || '');
    };
    const cnj = input(courtData.cnj_code || '', 'Código CNJ (20 dígitos)', 25);
    const competencies = input((courtData.competencies || []).join(', '), 'Ex.: Criminal, Família, Fazenda Pública', 500);
    const competencyList = $('datalist'); competencyList.id = `${form.id}-competencies`; competencyOptions.forEach((value) => { const option = $('option'); option.value = value; competencyList.append(option); }); competencies.setAttribute('list', competencyList.id);
    const sphere = select([['', 'Selecione a esfera'], ['Federal', 'Federal'], ['Estadual', 'Estadual'], ['Distrital', 'Distrital'], ['Municipal', 'Municipal']], publicData.administrative_sphere || '');
    const officialCode = input(publicData.official_code || '', 'Código oficial', 80);
    const issuingSystem = input(publicData.issuing_system || '', 'Sistema emissor', 80);
    institutionalSection.content.append(institutionTypes, field('Tipo institucional principal', primaryInstitutional), $('small', 'law-contact-help', 'Identificadores institucionais são opcionais; a ausência gera apenas um aviso de completude.'));
    institutionalSection.content.append(field('Código CNJ da unidade judiciária', cnj), field('Competências (separadas por vírgula)', competencies), competencyList, field('Esfera administrativa', sphere), field('Identificador oficial', officialCode), field('Sistema emissor', issuingSystem));
    const updateInstitutional = () => { institutionalSection.hidden = recordKind.value === 'unit' ? false : nature.value !== 'pj'; cnj.parentElement.hidden = !courtType.checked; competencies.parentElement.hidden = !courtType.checked; sphere.parentElement.hidden = !publicType.checked; officialCode.parentElement.hidden = !publicType.checked; issuingSystem.parentElement.hidden = !publicType.checked; updateInstitutionalPrimary(); };
    courtType.addEventListener('change', updateInstitutional); publicType.addEventListener('change', updateInstitutional); updateInstitutional();
    const relationshipSection = section('Vínculos empresariais');
    const linkedContactIds = new Set((contact?.linked_contacts || []).map((item) => item.id));
    const linkMetadata = new Map((contact?.linked_contacts || []).map((item) => [item.id, { roles: item.roles || [], designations: item.designations || [] }]));
    const relationshipPicker = setWidth(select([['', 'Selecione para vincular']]), 900);
    const relationshipPickerField = field('Adicionar empresa', relationshipPicker);
    const relationshipChips = $('div', 'law-contact-profession-list');
    const relationshipStats = $('div', 'law-contact-relationship-stats');
    const relationshipStat = (label, value) => { const stat = $('div', 'law-contact-relationship-stat'); stat.append($('strong', '', Number(value).toLocaleString('pt-BR')), $('span', '', label)); return stat; };
    const renderRelationshipStats = () => {
      const roles = [...linkMetadata.values()].reduce((total, value) => total + value.roles.length, 0);
      const designations = [...linkMetadata.values()].reduce((total, value) => total + value.designations.length, 0);
      relationshipStats.replaceChildren(
        relationshipStat('Pessoas vinculadas', linkedContactIds.size),
        relationshipStat('Papéis atribuídos', roles),
        relationshipStat('Designações registradas', designations),
      );
    };
    const renderRelationshipChips = () => {
      relationshipChips.replaceChildren();
      [...linkedContactIds].forEach((id) => {
        const item = relationshipOptions.find((option) => option.id === id) || (contact?.linked_contacts || []).find((option) => option.id === id);
        if (!item) return;
        const row = $('div', 'law-contact-relationship-editor');
        const header = $('div', 'law-contact-relationship-header');
        const identity = $('div', 'law-contact-relationship-identity');
        identity.append($('strong', 'law-contact-relationship-name', item.display_name));
        if (item.acronym) identity.append($('span', 'law-contact-relationship-acronym', item.acronym));
        header.append(identity);
        const removeLink = button('Remover vínculo', 'fs-btn fs-btn-danger', () => { linkedContactIds.delete(id); linkMetadata.delete(id); renderRelationshipChips(); renderRelationshipStats(); });
        header.append(removeLink); row.append(header);
        const metadata = linkMetadata.get(id) || { roles: [], designations: [] };
        const roleGroup = $('div', 'law-contact-relationship-group');
        roleGroup.append($('h4', 'law-contact-relationship-title', 'Papéis'));
        const roleRows = $('div', 'law-contact-relationship-entries');
        const roleSets = {
          escritorio: [['client', 'Cliente (cadastro)'], ['collaborator', 'Colaborador'], ['employee', 'Funcionário'], ['legal_representative', 'Representante legal'], ['partner', 'Sócio'], ['administrator', 'Administrador/diretor'], ['attorney_in_fact', 'Procurador'], ['public_servant', 'Servidor público'], ['interested_party', 'Interessado (cadastro)'], ['service_user', 'Usuário do serviço (cadastro)'], ['other', 'Outro']],
          orgao_publico: [['public_servant', 'Servidor público'], ['service_user', 'Usuário do serviço (cadastro)'], ['interested_party', 'Interessado (cadastro)'], ['collaborator', 'Colaborador'], ['employee', 'Funcionário'], ['legal_representative', 'Representante legal'], ['client', 'Cliente (cadastro)'], ['partner', 'Sócio'], ['administrator', 'Administrador/diretor'], ['attorney_in_fact', 'Procurador'], ['other', 'Outro']],
          judiciario: [['public_servant', 'Servidor público'], ['interested_party', 'Interessado (cadastro)'], ['client', 'Cliente (cadastro)'], ['service_user', 'Usuário do serviço (cadastro)'], ['collaborator', 'Colaborador'], ['employee', 'Funcionário'], ['legal_representative', 'Representante legal'], ['partner', 'Sócio'], ['administrator', 'Administrador/diretor'], ['attorney_in_fact', 'Procurador'], ['other', 'Outro']],
        };
        const roleOptions = [['', 'Selecione um papel'], ...(roleSets[contextData.context_code] || roleSets.judiciario)];
        const addRoleRow = (role = {}) => { const entry = $('div', 'law-contact-relationship-entry law-contact-role-entry'); const selectRole = setWidth(select(roleOptions, role.code || ''), 400); const detail = setWidth(input(role.detail || '', 'Complemento para Outro', 160), 500); entry.append(field('Papel', selectRole), field('Complemento (Outro)', detail), button('Remover papel', 'fs-btn fs-btn-danger law-contact-chip-remove', () => entry.remove())); entry.getMetadata = () => ({ code: selectRole.value, detail: selectRole.value === 'other' ? detail.value.trim() || null : null }); roleRows.append(entry); };
        metadata.roles.forEach(addRoleRow);
        const addRole = button('Adicionar papel', 'fs-btn fs-btn-secondary', () => addRoleRow());
        roleGroup.append(roleRows, addRole);
        const designationGroup = $('div', 'law-contact-relationship-group');
        designationGroup.append($('h4', 'law-contact-relationship-title', 'Designações'));
        const designationRows = $('div', 'law-contact-relationship-entries');
        const designationList = $('datalist'); designationList.id = `${form.id}-designations-${id}`; designationOptions.forEach((value) => { const option = $('option'); option.value = value; designationList.append(option); });
        const addDesignationRow = (designation = {}) => { const entry = $('div', 'law-contact-relationship-entry law-contact-designation-entry'); const title = setWidth(input(designation.name || '', 'Ex.: DPC, IPC, CB/PM, SD/PM, TEN/PM', 120), 500); title.setAttribute('list', designationList.id); entry.append(field('Cargo/posto/graduação/função', title), button('Remover designação', 'fs-btn fs-btn-danger law-contact-chip-remove', () => entry.remove())); entry.getMetadata = () => title.value.trim() ? { name: title.value.trim() } : null; designationRows.append(entry); };
        metadata.designations.forEach(addDesignationRow);
        const addDesignation = button('Adicionar designação', 'fs-btn fs-btn-secondary', () => addDesignationRow());
        designationGroup.append(designationRows, addDesignation);
        row.append(roleGroup, designationGroup, designationList);
        row.dataset.linkId = id;
        row.getMetadata = () => ({ contact_id: id, roles: [...roleRows.children].map((entry) => entry.getMetadata()).filter((role) => role.code), designations: [...designationRows.children].map((entry) => entry.getMetadata()).filter(Boolean) });
        relationshipChips.append(row);
      });
    };
    relationshipPicker.addEventListener('change', () => { if (relationshipPicker.value) linkedContactIds.add(relationshipPicker.value); relationshipPicker.value = ''; renderRelationshipChips(); renderRelationshipStats(); });
    relationshipSection.content.append(relationshipPickerField, relationshipChips, relationshipStats); renderRelationshipChips(); renderRelationshipStats();
    let documentRows;
    let documentSection;
    if (window.lawContactsCanSensitive) {
      documentSection = section('Documentos');
      documentRows = $('div', 'law-contact-repeat-list');
      (contact?.documents || []).filter((doc) => DOCUMENT_TYPES_BY_NATURE[nature.value].includes(doc.type)).forEach((doc) => documentRows.append(documentRow(doc, nature.value)));
      documentSection.content.append(documentRows, button('Adicionar documento', 'fs-btn fs-btn-secondary', () => { if (documentRows.children.length < 4) documentRows.append(documentRow({}, nature.value)); }));
    }
    const channelSection = section('Telefones e e-mails');
    const channelRows = $('div', 'law-contact-repeat-list'); (contact?.channels || []).filter((item) => window.lawContactsCanSensitive || !item.personal).forEach((item) => channelRows.append(channelRow(item)));
    enforceSinglePrimary(channelRows, (row) => row.querySelector('[data-channel-type]').value === 'email' ? 'email' : 'phone', true);
    channelSection.content.append(channelRows, button('Adicionar telefone ou e-mail', 'fs-btn fs-btn-secondary', () => {
      if (channelRows.children.length >= 6) return;
      const row = channelRow(); channelRows.append(row);
      const group = row.querySelector('[data-channel-type]').value === 'email' ? 'email' : 'phone';
      if (![...channelRows.children].some((other) => other !== row && (other.querySelector('[data-channel-type]').value === 'email' ? 'email' : 'phone') === group && other.querySelector('[data-primary]').value === '1')) row.querySelector('[data-primary]').value = '1';
    }));
    const addressSection = section('Endereços');
    const addressRows = $('div', 'law-contact-repeat-list'); (contact?.addresses || []).forEach((item) => addressRows.append(addressRow(item)));
    enforceSinglePrimary(addressRows, () => 'address', true);
    addressSection.content.append(addressRows, button('Adicionar endereço', 'fs-btn fs-btn-secondary', () => {
      if (addressRows.children.length >= 2) return;
      const row = addressRow(); addressRows.append(row);
      if (![...addressRows.children].some((other) => other !== row && other.querySelector('[data-primary]').value === '1')) row.querySelector('[data-primary]').value = '1';
    }));
    const tagField = input((contact?.tags || []).join(', '), 'Ex.: testemunha, urgente, comarca', 400); tagField.name = 'tags';
    const tags = section('Tags'); tags.content.append(field('Tags', tagField));
    const notes = document.createElement('textarea'); notes.className = 'fs-form-control'; notes.maxLength = 4000; notes.value = contact?.notes || ''; notes.name = 'notes';
    const notesSection = section('Notas privadas'); notesSection.content.append(field('Notas', notes));
    const message = $('p', 'law-contact-feedback'); message.setAttribute('role', 'status');
    if (contact) {
      const excludedWrap = $('label', 'law-contact-check'); const excluded = $('input'); excluded.type = 'checkbox'; excluded.checked = Boolean(contact.sharing_excluded); excluded.name = 'sharing_excluded'; excludedWrap.append(excluded, $('span', '', 'Excluir dos compartilhamentos configurados')); basic.content.append(excludedWrap);
    }
    const cancel = button('Cancelar', 'fs-btn fs-btn-secondary', () => modal.close());
    const save = $('button', 'fs-btn fs-btn-primary', contact ? 'Salvar alterações' : 'Cadastrar contato'); save.type = 'submit'; save.setAttribute('form', form.id);
    modal.footer.append(cancel, save);
    form.append(basic, professionSection, institutionalSection, relationshipSection);
    if (documentSection) form.append(documentSection);
    form.append(channelSection, addressSection);
    form.append(tags);
    // Notes remain editable only for a profile that can read protected personal data.
    if (window.lawContactsCanSensitive) form.append(notesSection);
    form.append(message);
    const updateNature = () => {
      const isUnit = recordKind.value === 'unit';
      natureField.hidden = isUnit; parentField.hidden = preserveHiddenParent || (isUnit ? false : nature.value === 'pf');
      nature.disabled = isUnit;
      legalNameField.hidden = isUnit || nature.value !== 'pj';
      name.classList.toggle('fs-width-800', nature.value !== 'pj'); name.classList.toggle('fs-width-900', nature.value === 'pj');
      legalName.classList.toggle('fs-width-900', nature.value === 'pj');
      acronymField.hidden = isUnit || nature.value !== 'pj';
      professionSection.hidden = isUnit || nature.value === 'pj';
      relationshipSection.hidden = isUnit;
      if (documentSection) documentSection.hidden = isUnit;
      if (window.lawContactsCanSensitive) notesSection.hidden = false;
      updateInstitutional();
      if (documentRows) [...documentRows.children].forEach((row) => {
        const type = row.querySelector('[data-doc-type]');
        if (!DOCUMENT_TYPES_BY_NATURE[nature.value].includes(type.value)) { row.remove(); return; }
        const selected = type.value;
        type.replaceChildren(...DOCUMENT_TYPES_BY_NATURE[nature.value].map((code) => new Option(DOCUMENTS[code], code)));
        type.value = selected; type.dispatchEvent(new Event('change'));
      });
      const candidates = relationshipOptions.filter((item) => item.id !== contact?.id && item.legal_nature !== nature.value);
      relationshipPicker.replaceChildren(new Option('Selecione para vincular', ''), ...candidates.map((item) => new Option(`${item.display_name}${item.acronym ? ` (${item.acronym})` : ''}`, item.id)));
      relationshipSection.querySelector('.fs-card-title').textContent = nature.value === 'pj' ? 'Vínculos institucionais' : 'Vínculos empresariais';
      relationshipPickerField.hidden = nature.value === 'pj'; relationshipChips.hidden = nature.value === 'pj'; relationshipStats.hidden = nature.value !== 'pj';
      relationshipSection.hidden = nature.value === 'pj' ? false : candidates.length === 0;
    };
    nature.addEventListener('change', updateNature); hierarchyParent.addEventListener('change', updateNature); updateNature();
    form.addEventListener('submit', async (event) => {
      event.preventDefault(); message.textContent = '';
      const invalidDocument = [...(documentRows?.children || [])].find((row) => {
        const type = row.querySelector('[data-doc-type]').value;
        const number = row.querySelector('[data-doc-number]');
        if (!['cpf', 'cnpj'].includes(type) || !number.value.trim()) return false;
        const valid = window.FokusDocuments?.valid(number.value, type) || false;
        if (valid) number.removeAttribute('aria-invalid'); else number.setAttribute('aria-invalid', 'true');
        return !valid;
      });
      if (invalidDocument) { message.dataset.state = 'error'; message.textContent = `Informe um ${invalidDocument.querySelector('[data-doc-type]').value.toUpperCase()} válido, com os dígitos verificadores corretos.`; invalidDocument.querySelector('[data-doc-number]').focus(); return; }
      save.disabled = true;
      const body = {
        record_kind: recordKind.value, legal_nature: recordKind.value === 'unit' ? null : nature.value,
        parent_contact_id: recordKind.value === 'unit' || nature.value === 'pj' ? (hierarchyParent.value || (preserveHiddenParent ? contact.parent_contact_id : null)) : null,
        display_name: name.value.trim(), acronym: acronym.value.trim() || null, legal_name: legalName.value.trim() || null,
        professions: nature.value === 'pf' && recordKind.value !== 'unit' ? [...professionRows.querySelectorAll('[data-profession]')].map((item) => item.dataset.profession) : [],
        linked_contact_ids: [...linkedContactIds],
        linked_relationships: [...relationshipChips.children].map((row) => row.getMetadata()),
        channels: [...channelRows.children].map((row) => ({ type: row.querySelector('[data-channel-type]').value, value: row.querySelector('[data-channel-value]').value.trim(), label: row.querySelector('[data-channel-label]').value.trim() || null, personal: row.querySelector('[data-personal]').checked, primary: row.querySelector('[data-primary]').value === '1' })).filter((item) => item.value && (window.lawContactsCanSensitive || !item.personal)),
        addresses: [...addressRows.children].map((row) => ({ type: row.querySelector('[data-address-type]').value, postal_code: row.querySelector('[data-postal]').value.trim() || null, street: row.querySelector('[data-street]').value.trim(), number: row.querySelector('[data-number]').value.trim() || null, complement: row.querySelector('[data-complement]').value.trim() || null, district: row.querySelector('[data-district]').value.trim() || null, city: row.querySelector('[data-city]').value.trim(), state: row.querySelector('[data-state]').value.trim().toUpperCase(), country: row.querySelector('[data-country]').value.trim() || 'Brasil', primary: row.querySelector('[data-primary]').value === '1' })),
        tags: tagField.value.split(',').map((value) => value.trim()).filter(Boolean),
      };
      body.institutional_data = [];
      if ((nature.value === 'pj' || recordKind.value === 'unit') && courtType.checked) body.institutional_data.push({ type: 'court_unit', primary: primaryInstitutional.value === 'court_unit', cnj_code: cnj.value.trim() || null, competencies: competencies.value.split(',').map((value) => value.trim()).filter(Boolean) });
      if ((nature.value === 'pj' || recordKind.value === 'unit') && publicType.checked) body.institutional_data.push({ type: 'public_body', primary: primaryInstitutional.value === 'public_body', administrative_sphere: sphere.value || null, official_code: officialCode.value.trim() || null, issuing_system: issuingSystem.value.trim() || null });
      if (window.lawContactsCanSensitive) {
        body.documents = [...documentRows.children].map((row) => { const type = row.querySelector('[data-doc-type]').value; const noUf = DOCUMENT_TYPES_WITHOUT_STATE.includes(type); const noLabel = DOCUMENT_TYPES_WITHOUT_LABEL.includes(type); return { type, number: row.querySelector('[data-doc-number]').value.trim(), state: noUf ? null : (row.querySelector('[data-doc-state]').value || null), label: noLabel ? null : (row.querySelector('[data-doc-label]').value.trim() || null) }; }).filter((doc) => doc.number);
        body.notes = notes.value.trim() || null;
      }
      if (contact) body.sharing_excluded = form.elements.namedItem('sharing_excluded').checked;
      try {
        await window.FokusApi.request(contact ? `/law/contacts/${encodeURIComponent(contact.id)}` : '/law/contacts', { method: contact ? 'PATCH' : 'POST', body });
        modal.close(); onSaved();
      } catch (error) { message.dataset.state = 'error'; message.textContent = error.message || 'Não foi possível salvar o contato.'; }
      finally { save.disabled = false; }
    });
    modal.body.append(form); name.focus();
  }

  function documentRow(doc = {}, nature = 'pf') {
    const row = $('div', 'law-contact-repeat-row law-contact-document-row');
    const types = [...DOCUMENT_TYPES_BY_NATURE[nature]];
    const highlighted = window.lawContactContext?.context_code === 'escritorio' ? 'oab' : 'registration';
    if (types.includes(highlighted)) types.splice(types.indexOf(highlighted), 1), types.unshift(highlighted);
    const type = setWidth(select(types.map((code) => [code, DOCUMENTS[code]]), doc.type || (nature === 'pj' ? 'cnpj' : 'cpf')), 300); type.dataset.docType = '1';
    const number = setWidth(input(doc.number || '', 'Número do documento', 120), 500); number.dataset.docNumber = '1';
    const state = setWidth(select([['','UF'], ...STATES], doc.state || 'BA'), 300); state.dataset.docState = '1';
    const label = setWidth(input(doc.label || '', 'Identificação', 80), 400); label.dataset.docLabel = '1';
    const stateField = field('UF de emissão', state); const labelField = field('Identificação', label);
    const numberMask = window.FokusDocuments?.bind(number, () => type.value);
    const update = () => { const noUf = DOCUMENT_TYPES_WITHOUT_STATE.includes(type.value); const noLabel = DOCUMENT_TYPES_WITHOUT_LABEL.includes(type.value); stateField.hidden = noUf; state.required = type.value === 'state_registration'; labelField.hidden = noLabel; number.placeholder = type.value === 'cpf' ? '000.000.000-00' : type.value === 'cnpj' ? '00.000.000/0000-00' : 'Número do documento'; number.inputMode = type.value === 'cpf' ? 'numeric' : type.value === 'cnpj' ? 'text' : 'text'; number.maxLength = type.value === 'cpf' ? 14 : type.value === 'cnpj' ? 18 : 120; numberMask?.apply(); };
    type.addEventListener('change', update); update();
    row.append(field('Tipo', type), field('Número', number), stateField, labelField, button('Remover', 'fs-btn fs-btn-danger law-contact-remove', () => row.remove())); return row;
  }

  function professionChip(value) {
    const chip = $('span', 'law-contact-profession-chip', value); chip.dataset.profession = value;
    chip.append(button('×', 'fs-btn fs-btn-danger law-contact-remove', () => chip.remove())); return chip;
  }

  function channelRow(item = {}) {
    const row = $('div', 'law-contact-repeat-row law-contact-channel-row');
    const type = setWidth(select([['phone', 'Telefone fixo'], ['extension', 'Ramal'], ['mobile', 'Celular'], ['whatsapp', 'WhatsApp'], ['email', 'E-mail']], item.type || 'phone'), 300); type.dataset.channelType = '1';
    const value = setWidth(input(item.value || '', 'Telefone ou e-mail', 255), 500); value.dataset.channelValue = '1';
    const labelInput = setWidth(input(item.label || '', 'Ex.: Recepção, gabinete, pessoal', 80), 400); labelInput.dataset.channelLabel = '1';
    const personal = $('input'); personal.type = 'checkbox'; personal.checked = Boolean(item.personal); personal.dataset.personal = '1';
    const personalField = $('label', 'law-contact-check'); personalField.append(personal, $('span', '', 'Canal pessoal / sensível'));
    personalField.hidden = !window.lawContactsCanSensitive;
    const primary = setWidth(select([['0','Não'],['1','Sim']], item.primary ? '1' : '0'), 300); primary.dataset.primary = '1';
    const sensitiveNote = $('small', 'law-contact-help', 'O valor de um canal pessoal fica oculto sem a permissão de dados sensíveis e não é compartilhado.');
    sensitiveNote.hidden = !window.lawContactsCanSensitive;
    const privacy = $('div', 'law-contact-channel-privacy'); privacy.hidden = !window.lawContactsCanSensitive; privacy.append(personalField, sensitiveNote);
    row.append(field('Canal', type), field('Contato', value), field('Rótulo', labelInput), privacy, field('Principal', primary), button('Remover', 'fs-btn fs-btn-danger law-contact-remove', () => row.remove())); return row;
  }

  function enforceSinglePrimary(rows, groupForRow, chooseFirst) {
    const entries = [...rows.children];
    const seen = new Set();
    entries.forEach((row) => {
      const primary = row.querySelector('[data-primary]');
      const group = groupForRow(row);
      if (primary.value === '1') {
        if (seen.has(group)) primary.value = '0';
        else seen.add(group);
      }
    });
    if (chooseFirst) entries.forEach((row) => {
      const primary = row.querySelector('[data-primary]');
      const group = groupForRow(row);
      if (!seen.has(group)) { primary.value = '1'; seen.add(group); }
    });
    rows.addEventListener('change', (event) => {
      if (!event.target.matches('[data-primary], [data-channel-type]')) return;
      const row = event.target.closest('.law-contact-repeat-row, .law-contact-address-row');
      const primary = row.querySelector('[data-primary]');
      if (event.target.matches('[data-channel-type]') && primary.value !== '1') return;
      if (event.target.matches('[data-primary]') && event.target.value !== '1') return;
      const group = groupForRow(row);
      [...rows.children].forEach((other) => {
        if (other !== row && groupForRow(other) === group) other.querySelector('[data-primary]').value = '0';
      });
    });
  }

  function addressRow(address = {}) {
    const row = $('fieldset', 'law-contact-address-row'); row.append($('legend', '', 'Endereço'));
    const addressTypes = [['business', window.lawContactContext?.segment_code === 'advocacia' ? 'Comercial' : 'Institucional/comercial'], ['correspondence', 'Correspondência'], ['other', 'Outro']];
    if (window.lawContactsCanSensitive) addressTypes.splice(1, 0, ['residential', 'Residencial']);
    const type = setWidth(select(addressTypes, address.type || 'business'), 400); type.dataset.addressType = '1';
    const postal = setWidth(input(address.postal_code, '00000-000', 9), 400); postal.dataset.postal = '1'; postal.inputMode = 'numeric';
    const street = setWidth(input(address.street, 'Logradouro', 180), 800); street.required = true; street.dataset.street = '1';
    const number = setWidth(input(address.number, 'Número', 32), 300); number.dataset.number = '1';
    const complement = input(address.complement, 'Complemento', 120); complement.dataset.complement = '1';
    const district = input(address.district, 'Bairro', 120); district.dataset.district = '1';
    const city = input(address.city, 'Município', 120); city.required = true; city.dataset.city = '1';
    const state = setWidth(select([['','Selecione a UF'], ...STATES], address.state || 'BA'), 300); state.required = true; state.dataset.state = '1';
    const country = setWidth(input(address.country || 'Brasil', 'País', 80), 500); country.dataset.country = '1';
    const primary = setWidth(select([['0','Não'],['1','Sim']], address.primary ? '1' : '0'), 300); primary.dataset.primary = '1';
    const lookupStatus = $('small', 'law-contact-help law-contact-cep-status'); lookupStatus.setAttribute('aria-live', 'polite');
    const postalField = field('CEP', postal);
    const setLocked = (locked) => { district.disabled = locked; city.disabled = locked; state.disabled = locked; country.disabled = locked; };
    let lookupTimer; let lookupController; let lookupSequence = 0;
    postal.addEventListener('input', () => {
      const digits = postal.value.replace(/\D/g, '').slice(0, 8);
      postal.value = digits.replace(/^(\d{5})(\d)/, '$1-$2');
      clearTimeout(lookupTimer); lookupController?.abort(); lookupController = null;
      const sequence = ++lookupSequence;
      setLocked(false); lookupStatus.textContent = '';
      if (digits.length !== 8) return;
      lookupStatus.textContent = 'Consultando CEP…';
      lookupTimer = setTimeout(async () => {
        lookupController = new AbortController();
        try {
          const data = await window.FokusApi.request(`/law/addresses/cep/${digits}`, { signal: lookupController.signal });
          if (sequence !== lookupSequence) return;
          if (data.erro) { lookupStatus.textContent = 'CEP não encontrado. Preencha o endereço manualmente.'; return; }
          if (data.logradouro) street.value = data.logradouro;
          if (data.complemento) complement.value = data.complemento;
          district.value = data.bairro || '';
          city.value = data.localidade || '';
          if (STATES.some(([uf]) => uf === data.uf)) state.value = data.uf;
          country.value = 'Brasil';
          const completeLocality = Boolean(data.bairro && data.localidade && data.uf && STATES.some(([uf]) => uf === data.uf));
          setLocked(completeLocality);
          lookupStatus.textContent = 'Endereço encontrado.';
        } catch (error) {
          if (error.name !== 'AbortError' && sequence === lookupSequence) lookupStatus.textContent = 'Não foi possível consultar o CEP. Preencha o endereço manualmente.';
        }
      }, 350);
    });
    row.append(field('Tipo', type), postalField, field('Logradouro *', street), field('Número', number), field('Complemento', complement), field('Bairro', district), field('Município *', city), field('UF *', state), field('País', country), field('Principal', primary), button('Remover', 'fs-btn fs-btn-danger law-contact-remove', () => row.remove()), lookupStatus); return row;
  }

  function departmentRow(department = {}) {
    const row = $('fieldset', 'law-contact-department-row'); row.append($('legend', '', 'Departamento'));
    const name = input(department.name || '', 'Ex.: Contabilidade', 120); name.dataset.departmentName = '1'; name.required = true;
    const channels = $('div', 'law-contact-repeat-list');
    (department.channels || []).forEach((channel) => channels.append(departmentChannelRow(channel)));
    const add = button('Adicionar telefone ou e-mail', 'fs-btn fs-btn-secondary', () => { if (channels.children.length < 6) channels.append(departmentChannelRow()); });
    row.append(field('Nome do departamento *', name), channels, add, button('Remover departamento', 'fs-btn fs-btn-danger law-contact-remove', () => row.remove())); return row;
  }

  function departmentChannelRow(channel = {}) {
    const row = $('div', 'law-contact-repeat-row law-contact-department-channel');
    const type = setWidth(select([['phone', 'Telefone'], ['email', 'E-mail']], channel.type || 'phone'), 300); type.dataset.channelType = '1';
    const value = setWidth(input(channel.value || '', 'Canal institucional do departamento'), 500); value.dataset.channelValue = '1';
    const label = setWidth(input(channel.label || '', 'Rótulo', 80), 400); label.dataset.channelLabel = '1';
    row.append(field('Tipo', type), field('Contato', value), field('Rótulo', label), button('Remover', 'fs-btn fs-btn-danger law-contact-remove', () => row.remove())); return row;
  }

  async function openDetails(root, id, isShared, onChanged, opener = null) {
    const result = await window.FokusApi.request(`/law/contacts/${encodeURIComponent(id)}${isShared ? '' : '?from_search=1'}`);
    const contact = result.contact;
    const modal = createModal(root, 'Ficha do contato', 'fs-modal-xl', opener);
    const body = $('div', 'law-contact-detail-body');
    const labels = { pf: 'Pessoa física', pj: 'Pessoa jurídica', residential: 'Residencial', business: window.lawContactContext?.segment_code === 'advocacia' ? 'Comercial' : 'Institucional / comercial' };
    const nameParts = String(contact.display_name || '?').trim().split(/\s+/).filter(Boolean);
    const removeDiacritics = (value) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    const firstName = nameParts[0] || '?';
    const lastName = nameParts[nameParts.length - 1] || firstName;
    const initials = `${removeDiacritics([...firstName][0] || '?')}${nameParts.length > 1 ? removeDiacritics([...lastName][0] || '') : ''}`.toLocaleUpperCase('pt-BR');
    const summary = $('section', 'law-contact-detail-summary');
    const avatar = $('span', 'law-contact-detail-avatar', initials);
    const summaryCopy = $('div', 'law-contact-detail-summary-copy');
    const recordLabel = contact.record_kind === 'unit' ? (window.lawContactContext?.unit_label || 'Unidade') : (labels[contact.legal_nature] || 'Contato');
    summaryCopy.append($('span', 'law-contact-detail-eyebrow', recordLabel.toLocaleUpperCase('pt-BR')));
    const titleRow = $('div', 'law-contact-detail-name-row');
    titleRow.append($('h3', 'law-contact-detail-name', contact.display_name));
    if (contact.acronym && contact.legal_nature !== 'pj') titleRow.append($('span', 'law-contact-detail-acronym', contact.acronym));
    summaryCopy.append(titleRow);
    const institutions = Array.isArray(contact.institutional_data) ? contact.institutional_data : [];
    if (contact.legal_nature === 'pj' || contact.record_kind === 'unit') {
      const institutionDetails = [];
      institutions.forEach((institution) => {
        if (institution.type === 'court_unit' && institution.cnj_code) institutionDetails.push(institution.cnj_code);
        if (institution.official_code) institutionDetails.push(institution.official_code);
        if (institution.administrative_sphere) institutionDetails.push(institution.administrative_sphere);
        if (institution.issuing_system) institutionDetails.push(institution.issuing_system);
      });
      const legalDetails = institutionDetails.length ? institutionDetails.join(' | ') : contact.legal_name;
      if (legalDetails) summaryCopy.append($('p', 'law-contact-detail-legal-name', legalDetails));
    } else if (contact.legal_name) summaryCopy.append($('p', 'law-contact-detail-legal-name', contact.legal_name));
    const summaryBadges = $('div', 'law-contact-detail-summary-badges');
    if (contact.legal_nature === 'pj' && contact.acronym) summaryBadges.append($('span', 'law-contact-detail-acronym', contact.acronym));
    const statusText = labels[contact.status] || contact.status || 'Situação não informada';
    const statusBadge = $('span', `law-contact-detail-badge law-contact-detail-status-${contact.status}`);
    statusBadge.setAttribute('role', 'img');
    statusBadge.setAttribute('aria-label', statusText);
    statusBadge.title = statusText;
    summaryBadges.append(statusBadge);
    summary.append(avatar, summaryCopy, summaryBadges);
    const chips = $('div', 'law-contact-detail-chip-groups');
    const addChips = (values, tone) => {
      if (!values?.length) return;
      const group = $('div', `law-contact-detail-chip-group law-contact-detail-chip-${tone}`);
      const list = $('div', 'law-contact-detail-chips');
      values.forEach((value) => list.append($('span', 'law-contact-detail-chip', value)));
      group.append(list); chips.append(group);
    };
    const roleLabels = { employee: 'Funcionário/colaborador', public_servant: 'Servidor público', legal_representative: 'Representante legal', partner: 'Sócio', administrator: 'Administrador/diretor', attorney_in_fact: 'Procurador', client: 'Cliente (cadastro)', service_user: 'Usuário do serviço (cadastro)', interested_party: 'Interessado (cadastro)', collaborator: 'Colaborador', other: 'Outro' };
    const relationshipTags = [];
    if (contact.legal_nature === 'pj' || contact.record_kind === 'unit') {
      const linkedCount = (contact.linked_contacts || []).length;
      const linkLabel = `${linkedCount} ${linkedCount === 1 ? 'vínculo ativo' : 'vínculos ativos'}`;
      const competencies = [...new Set(institutions.filter((item) => item.type === 'court_unit').flatMap((item) => item.competencies || []).filter(Boolean))];
      addChips([...(linkedCount ? [linkLabel] : []), ...competencies], 'institutional');
    }
    if (contact.legal_nature === 'pf') (contact.linked_contacts || []).forEach((linked) => {
      (linked.roles || []).filter((item) => item.current).forEach((item) => relationshipTags.push(item.code === 'other' && item.detail ? item.detail : roleLabels[item.code] || item.code));
      (linked.designations || []).filter((item) => item.current && item.name).forEach((item) => relationshipTags.push(item.name));
      if (linked.display_name) relationshipTags.push(`${linked.display_name}${linked.acronym ? ` · ${linked.acronym}` : ''}`);
    });
    if (contact.legal_nature === 'pf') addChips(contact.professions || [], 'professions');
    addChips(relationshipTags, 'relationships');
    addChips(contact.tags || [], 'tags');
    summaryCopy.append(chips);
    body.append(summary);
    if (contact.is_shared) body.append($('aside', 'law-contact-source', `Compartilhado por ${contact.source_company_name}. Este contato está disponível somente para consulta.`));

    const addSection = (title, marker, count, className = '') => {
      const card = $('section', `law-contact-detail-card ${className}`.trim());
      const header = $('header', 'law-contact-detail-section-header');
      header.append($('span', 'law-contact-detail-section-mark', marker));
      const heading = $('div', 'law-contact-detail-section-heading');
      heading.append($('h3', '', title), $('span', '', count));
      header.append(heading); card.append(header);
      const items = $('div', 'law-contact-detail-items'); card.append(items); body.append(card);
      return items;
    };
    if (contact.parent || contact.children?.length) {
      const children = contact.children || [];
      const hierarchyItems = addSection('Hierarquia', 'ORG', `${children.length} filho(s)`, 'law-contact-detail-hierarchy');
      if (contact.parent) { const row = $('article', 'law-contact-detail-linked-contact'); row.append($('strong', '', 'Vinculada a'), $('span', '', contact.parent.display_name)); hierarchyItems.append(row); }
      children.forEach((child) => {
        const row = $('article', 'law-contact-detail-linked-contact');
        row.append($('strong', '', child.display_name));
        hierarchyItems.append(row);
      });
    }
    (contact.institutional_data || []).forEach((institution) => {
      const items = addSection(`${institution.primary ? 'Tipo institucional principal · ' : ''}${institution.type === 'court_unit' ? 'Dados da unidade judiciária' : 'Dados do órgão público'}`, 'INS', '', 'law-contact-detail-institution');
      const values = institution.type === 'court_unit'
        ? [['Código CNJ', institution.cnj_code], ['Competências', (institution.competencies || []).join(', ')]]
        : [['Esfera administrativa', institution.administrative_sphere], ['Código oficial', institution.official_code], ['Sistema emissor', institution.issuing_system]];
      values.filter(([, value]) => value).forEach(([label, value]) => { const item = $('article', 'law-contact-detail-linked-contact'); item.append($('strong', '', label), $('span', '', value)); items.append(item); });
    });
    if (contact.linked_contacts?.length) {
      const items = addSection(contact.legal_nature === 'pj' ? 'Pessoas vinculadas' : 'Empresas vinculadas', 'VÍN', contact.linked_contacts.length, 'law-contact-detail-links');
      if (contact.legal_nature === 'pj') items.remove();
      else contact.linked_contacts.forEach((linked) => {
          const row = $('article', 'law-contact-detail-linked-contact law-contact-detail-linked-company');
          const identity = $('div', 'law-contact-detail-link-identity');
          identity.append($('strong', 'law-contact-detail-link-name', linked.display_name));
          if (linked.acronym) identity.append($('span', 'law-contact-detail-link-acronym', linked.acronym));
          row.append(identity);
          const activeRoles = (linked.roles || []).filter((item) => item.current).map((item) => `${roleLabels[item.code] || item.code}${item.code === 'other' && item.detail ? `: ${item.detail}` : ''}`);
          const activeDesignations = (linked.designations || []).filter((item) => item.current).map((item) => item.name);
          const metadataTags = $('div', 'law-contact-detail-linked-tags');
          [...activeRoles, ...activeDesignations].forEach((value) => metadataTags.append($('span', 'law-contact-detail-linked-tag', value)));
          if (metadataTags.children.length) row.append(metadataTags);
          items.append(row);
      });
    }
    if (contact.documents?.length) {
      const items = addSection('Documentos', 'ID', contact.documents.length, 'law-contact-detail-documents');
      contact.documents.forEach((doc) => {
        const item = $('article', 'law-contact-detail-document');
        const type = DOCUMENTS[doc.type] || doc.type || 'Documento';
        item.append($('span', 'law-contact-detail-document-type', Array.from(type).slice(0, 4).join('').toLocaleUpperCase('pt-BR')));
        const detail = $('div', 'law-contact-detail-document-copy');
        detail.append($('strong', '', formatContactDocument(doc)));
        detail.append($('span', '', [doc.label, doc.state].filter(Boolean).join(' · ') || 'Documento cadastrado'));
        item.append(detail); items.append(item);
      });
    }
    if (contact.channels?.length) {
      const items = addSection('Telefones e e-mails', 'TEL', contact.channels.length, 'law-contact-detail-channels');
      contact.channels.forEach((channel) => {
        const isEmail = channel.type === 'email';
        const item = $('article', `law-contact-detail-channel law-contact-detail-channel-${isEmail ? 'email' : 'phone'}`);
        const mark = $('span', 'law-contact-detail-channel-mark', isEmail ? '@' : '☎');
        const detail = $('div', 'law-contact-detail-channel-copy');
        const meta = $('div', 'law-contact-detail-channel-meta');
        const title = $('div', 'law-contact-detail-channel-title');
        const channelKinds = { phone: 'TELEFONE FIXO', extension: 'RAMAL', mobile: 'CELULAR', whatsapp: 'WHATSAPP', email: 'E-MAIL' };
        title.append($('span', 'law-contact-detail-channel-kind', channelKinds[channel.type] || 'TELEFONE'));
        const badges = $('div', 'law-contact-detail-channel-badges');
        if (channel.primary) badges.append($('span', 'law-contact-detail-primary', 'Principal'));
        if (channel.personal) badges.append($('span', 'law-contact-detail-private', channel.value === 'Dado protegido' ? 'Acesso restrito' : 'Pessoal'));
        if (badges.children.length) title.append(badges);
        meta.append(title);
        const value = isEmail ? (channel.value || 'Dado protegido') : formatContactPhone(channel.value);
        detail.append(meta, $('strong', '', value));
        item.append(mark, detail); items.append(item);
      });
    }
    if (contact.addresses?.length) {
      const items = addSection('Endereços', 'END', contact.addresses.length, 'law-contact-detail-addresses');
      contact.addresses.forEach((address) => {
        const item = $('article', 'law-contact-detail-address');
        const meta = $('div', 'law-contact-detail-address-meta');
        meta.append($('span', 'law-contact-detail-address-type', labels[address.type] || 'Endereço'));
        if (address.primary) meta.append($('span', 'law-contact-detail-primary', 'Principal'));
        const street = [address.street, address.number].filter(Boolean).join(', ');
        const extra = [address.complement, address.district].filter(Boolean).join(' · ');
        const locality = [address.city, address.state].filter(Boolean).join(' / ');
        item.append(meta, $('strong', '', street || 'Endereço não informado'));
        if (extra) item.append($('span', 'law-contact-detail-address-extra', extra));
        if (locality) item.append($('span', 'law-contact-detail-address-locality', locality));
        const postal = [address.postal_code ? `CEP ${address.postal_code}` : '', address.country].filter(Boolean).join(' · ');
        if (postal) item.append($('span', 'law-contact-detail-address-postal', postal));
        items.append(item);
      });
    }
    if (contact.departments?.length) {
      const items = addSection('Departamentos', 'SET', contact.departments.length, 'law-contact-detail-departments');
      contact.departments.forEach((department) => {
        const item = $('article', 'law-contact-detail-department');
        const header = $('div', 'law-contact-detail-department-header');
        header.append($('h4', '', department.name), $('span', `law-contact-detail-department-status law-contact-detail-status-${department.status}`, labels[department.status] || department.status));
        item.append(header);
        const channels = $('div', 'law-contact-detail-department-channels');
        (department.channels || []).forEach((channel) => {
          const row = $('div', 'law-contact-detail-department-channel');
          row.append($('span', '', channel.label || (channel.type === 'email' ? 'E-mail' : 'Telefone')), $('strong', '', channel.value || 'Dado protegido'));
          channels.append(row);
        });
        if (channels.children.length) item.append(channels);
        else item.append($('p', 'law-contact-detail-empty', 'Nenhum telefone ou e-mail neste departamento.'));
        items.append(item);
      });
    }
    if (contact.notes) {
      const notes = $('section', 'law-contact-detail-notes');
      notes.append($('span', 'law-contact-detail-eyebrow', 'NOTA PRIVADA'), $('h3', '', 'Observações'), $('p', '', contact.notes));
      body.append(notes);
    }
    if (contact.is_shared) body.append($('aside', 'law-contact-share-notice', 'As alterações só podem ser feitas pela empresa responsável pelo cadastro.'));
    modal.body.append(body);
    if (!isShared && window.lawContactsCanMerge && contact.has_possible_duplicates) {
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

  async function renderSharingPage(root, context) {
    document.title = 'Compartilhamentos | Fokus Law';
    root.replaceChildren();
    const heading = $('div', 'law-page-heading law-contact-page-heading');
    heading.append($('p', 'law-page-eyebrow', 'GESTÃO DE CONTATOS'), $('h2', '', 'Compartilhamentos'), $('p', 'law-page-lede', 'Escolha as empresas e defina, em uma única política, quais contatos e informações ficam disponíveis para esse grupo.'));
    root.append(heading);
    const feedback = $('p', 'law-contact-feedback'); feedback.setAttribute('role', 'status'); feedback.textContent = 'Carregando políticas…'; root.append(feedback);
    try {
      const result = await window.FokusApi.request('/law/contact-sharing');
      if (!root.isConnected) return;
      feedback.remove();
      const companies = result.companies || [];
      const policies = result.policies || [];
      const companiesById = new Map(companies.map((company) => [company.id, company]));
      const policiesById = new Map(policies.map((policy) => [policy.recipient_company_id, policy]));
      policies.forEach((policy) => { if (!companiesById.has(policy.recipient_company_id)) companiesById.set(policy.recipient_company_id, { id: policy.recipient_company_id, name: policy.recipient_company_name || 'Empresa' }); });
      let selectedIds = new Set();
      let editingId = null;
      let rules = { legal_natures: ['pj'], profession_names: [], shared_fields: ['professional_channels'] };

      const formCard = $('section', 'fs-card law-contact-sharing-form-card');
      const formHeader = $('div', 'fs-card-header fs-u-p-3');
      const formTitle = $('h3', 'fs-card-title', 'Criar política de compartilhamento');
      formHeader.append(formTitle);
      const form = $('form', 'law-contact-sharing-form');
      const status = $('p', 'law-contact-feedback'); status.setAttribute('role', 'status');
      const companySelect = select([['', 'Selecione uma empresa com Contatos ativo'], ...companies.map((company) => [company.id, company.name])]);
      companies.forEach((company, index) => { if (policiesById.get(company.id)?.is_active) companySelect.options[index + 1].disabled = true; });
      companySelect.setAttribute('aria-label', 'Empresa para incluir na política');
      const addCompany = button('Adicionar empresa', 'fs-btn fs-btn-outline-primary');
      const selectedWrap = $('div', 'law-contact-sharing-recipients');
      const ruleGroups = $('div', 'law-contact-sharing-rule-groups');
      const actions = $('div', 'law-contact-sharing-actions');
      const cancelEdit = button('Cancelar edição', 'fs-btn fs-btn-secondary', () => renderSharingPage(root, context)); cancelEdit.hidden = true;
      const submit = $('button', 'fs-btn fs-btn-primary', 'Criar política para 0 empresas'); submit.type = 'submit';
      actions.append(cancelEdit, submit);

      const choice = $('div', 'law-contact-sharing-company-choice');
      choice.append(field('Empresa participante', companySelect), addCompany);
      const selectedSection = $('section', 'law-contact-sharing-selected');
      selectedSection.append($('h4', '', 'Empresas selecionadas'), selectedWrap);
      const renderSelected = () => {
        selectedWrap.replaceChildren();
        selectedIds.forEach((id) => {
          const company = companiesById.get(id);
          if (!company) return;
          const chip = $('span', 'law-contact-sharing-chip', company.name);
          if (!editingId) {
            const remove = button('×', 'law-contact-sharing-chip-remove', () => { selectedIds.delete(id); renderSelected(); });
            remove.setAttribute('aria-label', `Remover ${company.name}`); chip.append(remove);
          }
          selectedWrap.append(chip);
        });
        if (!selectedIds.size) selectedWrap.append($('p', 'law-contact-sharing-empty', 'Adicione uma ou mais empresas para aplicar as regras.'));
        submit.textContent = editingId ? 'Salvar alterações' : `Criar política para ${selectedIds.size} empresa${selectedIds.size === 1 ? '' : 's'}`;
        submit.disabled = !editingId && selectedIds.size === 0;
        addCompany.disabled = !companySelect.value || selectedIds.has(companySelect.value);
      };
      addCompany.addEventListener('click', () => {
        if (!companySelect.value) return;
        selectedIds.add(companySelect.value); companySelect.value = ''; renderSelected();
      });
      companySelect.addEventListener('change', renderSelected);

      const renderRules = () => {
        ruleGroups.replaceChildren();
        const natureGroup = $('fieldset', 'law-contact-sharing-rule-group');
        natureGroup.append($('legend', '', 'Cadastros incluídos'));
        const natureChecks = $('div', 'law-contact-check-grid');
        [['pj', 'Pessoas jurídicas'], ['pf', 'Pessoas físicas']].forEach(([value, label]) => {
          const wrap = $('label', 'law-contact-check'); const check = $('input'); check.type = 'checkbox'; check.value = value; check.checked = rules.legal_natures.includes(value);
          check.addEventListener('change', () => { rules.legal_natures = check.checked ? [...new Set([...rules.legal_natures, value])] : rules.legal_natures.filter((item) => item !== value); renderRules(); });
          wrap.append(check, $('span', '', label)); natureChecks.append(wrap);
        });
        natureGroup.append(natureChecks); ruleGroups.append(natureGroup);

        if (rules.legal_natures.includes('pf')) {
          const professionGroup = $('fieldset', 'law-contact-sharing-rule-group'); professionGroup.append($('legend', '', 'Profissões disponibilizadas'));
          const professionChecks = $('div', 'law-contact-check-grid');
          (result.professions || []).forEach((profession) => {
            const wrap = $('label', 'law-contact-check'); const check = $('input'); check.type = 'checkbox'; check.value = profession.value; check.checked = rules.profession_names.includes(profession.value);
            check.addEventListener('change', () => { rules.profession_names = check.checked ? [...new Set([...rules.profession_names, profession.value])] : rules.profession_names.filter((item) => item !== profession.value); });
            wrap.append(check, $('span', '', profession.label)); professionChecks.append(wrap);
          });
          if (!(result.professions || []).length) professionChecks.append($('p', 'law-contact-sharing-empty', 'Cadastre profissões e vincule-as a contatos antes de compartilhar pessoas físicas.'));
          professionGroup.append(professionChecks); ruleGroups.append(professionGroup);
        }

        const fieldsGroup = $('fieldset', 'law-contact-sharing-rule-group'); fieldsGroup.append($('legend', '', 'Informações autorizadas'));
        const fieldChecks = $('div', 'law-contact-check-grid');
        Object.entries(result.share_fields || {}).forEach(([value, label]) => {
          const wrap = $('label', 'law-contact-check'); const check = $('input'); check.type = 'checkbox'; check.value = value; check.checked = rules.shared_fields.includes(value);
          check.addEventListener('change', () => { rules.shared_fields = check.checked ? [...new Set([...rules.shared_fields, value])] : rules.shared_fields.filter((item) => item !== value); });
          wrap.append(check, $('span', '', label)); fieldChecks.append(wrap);
        });
        fieldsGroup.append(fieldChecks); ruleGroups.append(fieldsGroup);
      };

      const submitForm = async (event) => {
        event.preventDefault();
        if (!selectedIds.size) { status.dataset.state = 'error'; status.textContent = 'Adicione ao menos uma empresa à política.'; return; }
        if (!rules.legal_natures.length || (rules.legal_natures.includes('pf') && !rules.profession_names.length)) { status.dataset.state = 'error'; status.textContent = 'Escolha ao menos uma natureza e, para pessoas físicas, uma profissão.'; return; }
        submit.disabled = true; status.textContent = '';
        const payload = [...selectedIds].map((recipient_company_id) => ({ recipient_company_id, legal_natures: rules.legal_natures, profession_names: rules.legal_natures.includes('pf') ? rules.profession_names : [], shared_fields: rules.shared_fields }));
        try { await window.FokusApi.request('/law/contact-sharing', { method: 'PUT', body: { policies: payload, replace_all: false } }); await renderSharingPage(root, context); }
        catch (error) { status.dataset.state = 'error'; status.textContent = error.message || 'Não foi possível salvar a política.'; submit.disabled = false; }
      };
      form.addEventListener('submit', submitForm);
      form.append(choice, selectedSection, ruleGroups, status, actions);
      formCard.append(formHeader, form); root.append(formCard);
      renderSelected(); renderRules();

      const statistics = result.statistics || {};
      const sharingDashboard = $('section', 'fs-card law-contact-sharing-dashboard');
      const dashboardHeader = $('div', 'fs-card-header fs-u-p-3 law-contact-sharing-dashboard-header');
      dashboardHeader.append($('h3', 'fs-card-title', 'O que sua rede compartilha'));
      const metricGrid = $('div', 'law-contact-sharing-metrics');
      [
        ['Acordos ativos', statistics.active_agreements, 'Com confirmação dos dois lados', 'active'],
        ['Contatos disponibilizados', statistics.contacts_shared, `${Number(statistics.fields_shared || 0)} autorizações de campos`, 'outgoing'],
        ['Contatos recebidos', statistics.contacts_received, `${Number(statistics.fields_received || 0)} autorizações de campos`, 'incoming'],
      ].forEach(([label, value, note, tone]) => {
        const metric = $('article', `law-contact-sharing-metric law-contact-sharing-metric-${tone}`);
        metric.append($('span', '', label), $('strong', '', Number(value || 0).toLocaleString('pt-BR')), $('small', '', note)); metricGrid.append(metric);
      });
      const chart = $('div', 'law-contact-sharing-chart');
      const chartHeader = $('div', 'law-contact-sharing-chart-header');
      chartHeader.append($('h4', '', 'Contatos por empresa'), $('div', 'law-contact-sharing-chart-legend'));
      chartHeader.lastElementChild.append($('span', '', 'Disponibilizados'), $('span', '', 'Recebidos'));
      chart.append(chartHeader);
      const activeCompanies = (statistics.companies || []).filter((company) => company.active)
        .sort((a, b) => (Number(b.contacts_shared || 0) + Number(b.contacts_received || 0)) - (Number(a.contacts_shared || 0) + Number(a.contacts_received || 0))).slice(0, 5);
      if (!activeCompanies.length) chart.append($('p', 'law-contact-sharing-chart-empty', 'O gráfico aparecerá quando houver um acordo confirmado pelas duas empresas.'));
      else {
        const maxCount = Math.max(1, ...activeCompanies.flatMap((company) => [Number(company.contacts_shared || 0), Number(company.contacts_received || 0)]));
        activeCompanies.forEach((company) => {
          const row = $('div', 'law-contact-sharing-chart-row');
          const companyLabel = $('strong', '', company.company_name); row.append(companyLabel);
          const bars = $('div', 'law-contact-sharing-bars');
          [['Disponibilizados', company.contacts_shared, 'outgoing'], ['Recebidos', company.contacts_received, 'incoming']].forEach(([label, value, tone]) => {
            const line = $('div', 'law-contact-sharing-bar-line'); const number = Number(value || 0);
            line.append($('span', '', number.toLocaleString('pt-BR')));
            const track = $('span', 'law-contact-sharing-bar-track'); track.setAttribute('role', 'img'); track.setAttribute('aria-label', `${company.company_name}: ${number.toLocaleString('pt-BR')} contatos ${label.toLowerCase()}`);
            const fill = $('span', `law-contact-sharing-bar-fill is-${tone}`); fill.style.width = `${number ? Math.max(2, (number / maxCount) * 100) : 0}%`; track.append(fill); line.append(track); bars.append(line);
          });
          row.append(bars); chart.append(row);
        });
        if ((statistics.companies || []).filter((company) => company.active).length > 5) chart.append($('p', 'law-contact-sharing-chart-note', 'Exibindo os cinco acordos com maior movimentação. A tabela abaixo contém todos os acordos.'));
      }
      sharingDashboard.append(dashboardHeader, metricGrid, chart); root.append(sharingDashboard);

      const tableCard = $('section', 'fs-card law-contact-sharing-table-card');
      const tableHeader = $('div', 'fs-card-header fs-u-p-3'); tableHeader.append($('h3', 'fs-card-title', 'Políticas da empresa'));
      const tableWrap = $('div', 'fs-table-responsive'); const table = $('table', 'fs-table law-contact-sharing-table'); const head = $('thead'); const headRow = $('tr');
      ['Empresa', 'Sua política', 'Acordo', 'Regras e dados por direção', 'Ações'].forEach((label) => headRow.append($('th', '', label))); head.append(headRow); table.append(head);
      const body = $('tbody');
      const rows = new Map();
      policies.forEach((policy) => {
        const company = companiesById.get(policy.recipient_company_id);
        if (policy.is_active || company?.incoming_agreement) rows.set(policy.recipient_company_id, { policy, company, incoming: Boolean(policy.reciprocal_active) });
      });
      companies.filter((company) => company.incoming_agreement && !rows.has(company.id)).forEach((company) => rows.set(company.id, { policy: null, company, incoming: true }));
      const sharingRows = [...rows.values()].sort((a, b) => (a.company?.name || a.policy?.recipient_company_name || '').localeCompare(b.company?.name || b.policy?.recipient_company_name || '', 'pt-BR'));
      const paging = $('div', 'law-contact-quality-paging');
      const drawSharingPage = (page = 1) => {
        body.replaceChildren();
        sharingRows.slice((page - 1) * CONTACT_PAGE_SIZE, page * CONTACT_PAGE_SIZE).forEach(({ policy, company, incoming }) => {
          const recipientId = policy?.recipient_company_id || company.id; const companyName = company?.name || policy?.recipient_company_name || 'Empresa';
          const active = Boolean(policy?.is_active); const row = $('tr');
          row.append($('td', '', companyName)); row.append($('td', '', active ? 'Configurada' : 'A configurar'));
          const state = active && incoming ? 'Ativo' : active ? 'Aguardando confirmação' : 'Pendente';
          const badge = $('span', `law-contact-sharing-status ${active && incoming ? 'is-active' : 'is-pending'}`, state); const stateCell = $('td'); stateCell.append(badge); row.append(stateCell);
          const rulesCell = $('td', 'law-contact-sharing-rule-directions');
          const appendRuleDirection = (label, directionPolicy) => {
            if (!directionPolicy) return;
            const direction = $('div', 'law-contact-sharing-rule-direction');
            direction.append($('strong', '', label));
            const details = [...(directionPolicy.legal_natures || []).map((nature) => nature === 'pf' ? 'Pessoa física' : 'Pessoa jurídica'), ...(directionPolicy.profession_names || []).map((name) => (result.professions || []).find((item) => item.value === name)?.label || name), ...(directionPolicy.shared_fields || []).map((name) => result.share_fields?.[name] || name)];
            direction.append($('span', '', details.join(' · ') || 'Sem dados autorizados')); rulesCell.append(direction);
          };
          appendRuleDirection('Disponibiliza', policy?.is_active ? policy : null);
          appendRuleDirection('Recebe', company?.incoming_policy || null);
          if (!rulesCell.children.length) rulesCell.textContent = 'Defina os dois lados do acordo';
          row.append(rulesCell);
          const actionsCell = $('td', 'law-contact-actions'); const actionList = $('div', 'law-contact-action-list');
          actionList.append(iconButton('Editar política', 'Common-File-Edit--Streamline-Ultimate.png', () => {
            editingId = recipientId; selectedIds = new Set([recipientId]); rules = { legal_natures: policy?.legal_natures?.length ? [...policy.legal_natures] : ['pj'], profession_names: [...(policy?.profession_names || [])], shared_fields: [...(policy?.shared_fields || ['professional_channels'])] };
            formTitle.textContent = `Editar política · ${companyName}`; cancelEdit.hidden = false; companySelect.disabled = true; renderSelected(); renderRules(); formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }));
          if (active) actionList.append(iconButton('Remover política', 'Common-File-Remove--Streamline-Ultimate.png', async (event) => {
            if (!await confirmAction(root, 'Remover política', `A empresa “${companyName}” deixará de receber os contatos abrangidos por este acordo.`, 'Remover política', event.currentTarget)) return;
            try { await window.FokusApi.request(`/law/contact-sharing/${encodeURIComponent(recipientId)}`, { method: 'DELETE' }); await renderSharingPage(root, context); }
            catch (error) { window.alert(error.message || 'Não foi possível remover a política.'); }
          }));
          actionsCell.append(actionList); row.append(actionsCell); body.append(row);
        });
        if (!body.children.length) { const row = $('tr'); const cell = $('td', 'law-contact-empty', 'Ainda não há políticas ativas ou pendentes.'); cell.colSpan = 5; row.append(cell); body.append(row); }
        renderPagination(paging, { page, per_page: CONTACT_PAGE_SIZE, total: sharingRows.length }, drawSharingPage, 'políticas');
      };
      table.append(body); tableWrap.append(table); tableCard.append(tableHeader, tableWrap, paging); root.append(tableCard); drawSharingPage();
    } catch (error) { feedback.dataset.state = 'error'; feedback.textContent = error.message || 'Não foi possível carregar as políticas de compartilhamento.'; }
  }

  async function renderQualityPage(root, context) {
    document.title = 'Revisão e qualidade | Fokus Law';
    root.replaceChildren();
    const heading = $('div', 'law-page-heading law-contact-page-heading law-contact-quality-heading');
    heading.append($('p', 'law-page-eyebrow', 'GESTÃO DE CONTATOS'), $('h2', '', 'Revisão e qualidade'), $('p', 'law-page-lede', 'Priorize os dados essenciais para localizar e relacionar seus contatos.'));
    if (context.company?.role === 'admin') {
      const actions = $('div', 'law-contact-heading-actions');
      actions.append(button('Contexto da base', 'fs-btn fs-btn-secondary', (event) => openContactContextSettings(root, event.currentTarget, () => renderQualityPage(root, context))));
      heading.append(actions);
    }
    root.append(heading);
    const feedback = $('p', 'law-contact-feedback'); feedback.setAttribute('role', 'status'); feedback.textContent = 'Analisando a qualidade dos cadastros…'; root.append(feedback);
    try {
      const result = await window.FokusApi.request(`/law/contacts/quality/review?type=action_required&per_page=${CONTACT_PAGE_SIZE}`);
      if (!root.isConnected) return;
      feedback.remove();
      const canEdit = context.company?.role === 'admin' || (context.law_permissions || []).includes('law.contacts.update');
      const labels = { without_phone: 'Sem telefone', lawyer_without_oab: 'Advogado sem número de OAB', police_without_company: 'Policial sem empresa vinculada' };
      const summary = result.summary || {};
      const sectionNode = $('section', 'law-contact-quality fs-card');
      const header = $('div', 'fs-card-header fs-u-p-3'); header.append($('h3', 'fs-card-title', 'Pontos de atenção'));
      const grid = $('div', 'fs-card-body law-contact-quality-grid');
      Object.entries(labels).filter(([type]) => Number(summary[type] || 0) > 0).forEach(([type, label]) => {
        const card = $('article', 'law-contact-quality-metric'); card.append($('span', 'law-contact-quality-label', label), $('strong', '', Number(summary[type]).toLocaleString('pt-BR')), $('small', '', 'cadastros para revisar')); grid.append(card);
      });
      if (!grid.children.length) {
        const clear = $('div', 'law-contact-quality-clear'); clear.append($('strong', '', 'Tudo em dia'), $('span', '', 'Nenhum cadastro precisa de atenção nos critérios prioritários.')); grid.append(clear);
      }
      sectionNode.append(header, grid);
      const tableCard = $('section', 'law-contact-quality-table-card fs-card');
      const tableHeader = $('div', 'fs-card-header fs-u-p-3'); tableHeader.append($('h3', 'fs-card-title', 'Cadastros para complementar'));
      const wrap = $('div', 'law-contact-quality-table-wrap'); const table = $('table', 'law-contact-quality-table');
      const thead = $('thead'); const headerRow = $('tr'); ['Contato', 'Tipo de cadastro', 'Informação a revisar', ''].forEach((text) => headerRow.append($('th', '', text))); thead.append(headerRow);
      const tbody = $('tbody'); const paging = $('div', 'law-contact-quality-paging');
      const drawTable = (pageResult) => {
        tbody.replaceChildren();
        (pageResult.contacts || []).forEach((item) => {
          const row = $('tr'); row.append($('th', '', item.display_name), $('td', '', item.legal_nature === 'pj' ? 'Pessoa jurídica' : 'Pessoa física'));
          const issues = $('td', 'law-contact-quality-issues'); (item.issues || []).forEach((issue) => issues.append($('span', 'law-contact-quality-tag', labels[issue] || issue))); row.append(issues);
          const action = $('td', 'law-contact-quality-action'); action.append(button(canEdit ? 'Completar cadastro' : 'Ver cadastro', 'fs-btn fs-btn-secondary', async (event) => {
            if (!canEdit) { openDetails(root, item.id, false, () => renderQualityPage(root, context), event.currentTarget); return; }
            const trigger = event.currentTarget; trigger.disabled = true;
            try {
              const [detail, list] = await Promise.all([window.FokusApi.request(`/law/contacts/${encodeURIComponent(item.id)}?from_search=1`), window.FokusApi.request('/law/contacts?page=1&per_page=15')]);
              openEditor(root, detail.contact, () => renderQualityPage(root, context), trigger, list.relationship_options || [], list.designation_options || [], list.competency_options || []);
            } catch (error) { window.alert(error.message || 'Não foi possível abrir o cadastro para edição.'); }
            finally { trigger.disabled = false; }
          })); row.append(action); tbody.append(row);
        });
        if (!tbody.children.length) { const row = $('tr'); const cell = $('td', 'law-contact-empty', 'Não há cadastros pendentes.'); cell.colSpan = 4; row.append(cell); tbody.append(row); }
        renderPagination(paging, pageResult.pagination || {}, async (page) => drawTable(await window.FokusApi.request(`/law/contacts/quality/review?type=action_required&page=${page}&per_page=${CONTACT_PAGE_SIZE}`)), 'cadastros');
      };
      table.append(thead, tbody); wrap.append(table); tableCard.append(tableHeader, wrap, paging);
      const duplicates = button('Analisar possíveis duplicidades', 'fs-btn fs-btn-outline-primary', async (event) => {
        const trigger = event.currentTarget; trigger.disabled = true; trigger.textContent = 'Analisando…';
        try {
          const result = await window.FokusApi.request(`/law/contacts/quality/duplicates?per_page=${CONTACT_PAGE_SIZE}`); const modal = createModal(root, 'Possíveis duplicidades', 'fs-modal-lg');
          const intro = $('p', 'law-contact-help'); const rows = $('div'); const paging = $('div', 'law-contact-quality-paging'); modal.body.append(intro, rows, paging);
          const drawPage = (pageResult) => {
            intro.textContent = `${Number(pageResult.pagination?.total || 0).toLocaleString('pt-BR')} par(es) para revisão. Os valores coincidentes ficam ocultos; esta análise não altera cadastros.`;
            rows.replaceChildren(); (pageResult.pairs || []).forEach((pair) => { const row = $('div', 'law-contact-duplicate-pair'); row.append($('strong', '', `${pair.contact.display_name} · ${pair.candidate.display_name}`), $('span', '', pair.reason), button('Revisar primeiro contato', 'fs-btn fs-btn-secondary', () => { modal.close(); openDetails(root, pair.contact.id, false, () => renderQualityPage(root, context)); })); rows.append(row); });
            if (!pageResult.pairs?.length) rows.append($('p', '', 'Nenhum candidato encontrado.'));
            renderPagination(paging, pageResult.pagination || {}, async (page) => drawPage(await window.FokusApi.request(`/law/contacts/quality/duplicates?page=${page}&per_page=${CONTACT_PAGE_SIZE}`)), 'pares');
          };
          drawPage(result); modal.footer.append(button('Fechar', 'fs-btn fs-btn-secondary', () => modal.close()));
        } catch (error) { window.alert(error.message || 'Não foi possível analisar duplicidades.'); }
        finally { trigger.disabled = false; trigger.textContent = 'Analisar possíveis duplicidades'; }
      });
      grid.append(duplicates); root.append(sectionNode, tableCard); drawTable(result);
    } catch (error) { feedback.dataset.state = 'error'; feedback.textContent = error.message || 'Não foi possível analisar a qualidade dos cadastros.'; }
  }

  window.FokusLawContacts = {
    render,
    renderSharingPage,
    renderQualityPage,
    openContact: (root, id, opener = null) => openDetails(root, id, false, () => {}, opener),
    openSearchedContact: (root, id, isShared, opener = null) => openDetails(root, id, isShared, () => render(root, context), opener),
  };
})();

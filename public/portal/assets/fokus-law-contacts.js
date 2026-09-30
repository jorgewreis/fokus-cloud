(() => {
  const CLASSIFICATIONS = {
    client: 'Cliente', lawyer: 'Advogado(a)', law_firm: 'Escritório de advocacia', public_body: 'Órgão público', court_unit: 'Unidade judiciária',
    police: 'Policial', prosecutor_office: 'Ministério Público', public_defender: 'Defensoria Pública', expert: 'Perito(a)', witness: 'Testemunha', representative: 'Representante', other: 'Outro',
  };
  const DOCUMENTS = { cpf: 'CPF', cnpj: 'CNPJ', state_registration: 'Inscrição estadual', oab: 'OAB', rg: 'RG', registration: 'Matrícula', cadastro: 'Cadastro', voter_title: 'Título de eleitor', passport: 'Passaporte', other: 'Outro' };
  const DOCUMENT_TYPES_BY_NATURE = { pf: ['cpf', 'oab', 'rg', 'registration', 'cadastro', 'voter_title', 'passport', 'other'], pj: ['cnpj', 'state_registration', 'registration', 'cadastro', 'other'] };
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
  const field = (labelText, control) => { const label = $('label', 'law-contact-field'); label.append($('span', '', labelText), control); return label; };
  const select = (items, value = '') => { const control = $('select', 'fs-form-control'); items.forEach(([v, label]) => { const option = new Option(label, v); option.selected = v === value; control.append(option); }); return control; };
  const input = (value = '', placeholder = '', maxLength = 255) => { const control = $('input', 'fs-form-control'); control.value = value || ''; control.placeholder = placeholder; control.maxLength = maxLength; return control; };
  const button = (text, cls = 'fs-btn fs-btn-secondary', fn) => { const control = $('button', cls, text); control.type = 'button'; if (fn) control.addEventListener('click', (event) => fn(event)); return control; };
  const iconButton = (label, icon, fn) => { const control = button('', 'fs-btn fs-btn-icon fs-btn-icon-plain fs-table-action', fn); control.setAttribute('aria-label', label); control.title = label; const image = $('img'); image.src = `${CONTACT_ICONS}${icon}`; image.alt = ''; control.append(image); return control; };
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
    let relationshipOptions = [];
    let designationOptions = [];
    let competencyOptions = [];
    let searchTimer;
    root.replaceChildren();
    const heading = $('div', 'law-page-heading law-contact-page-heading');
    heading.append($('p', 'law-page-eyebrow', 'GESTÃO DE CONTATOS'), $('h2', '', 'Contatos'), $('p', 'law-page-lede', 'Organize pessoas, empresas, instituições e órgãos em uma base compartilhada pelos setores autorizados.'));
    const headingActions = $('div', 'law-contact-heading-actions');
    if (can('law.contacts.share.manage')) headingActions.append(button('Compartilhamento entre empresas', 'fs-btn fs-btn-outline-primary', (event) => openSharing(root, event.currentTarget)));
    if (can('law.contacts.create')) headingActions.append(button('Novo contato', 'fs-btn fs-btn-primary', (event) => openEditor(root, null, refresh, event.currentTarget, relationshipOptions)));
    heading.append(headingActions);
    root.append(heading);

    const overview = $('section', 'law-contact-overview-banner fs-card');
    overview.setAttribute('aria-label', 'Resumo da base de contatos');
    root.append(overview);
    const metrics = $('section', 'law-contact-metrics'); metrics.setAttribute('aria-live', 'polite'); metrics.append($('p', 'law-contact-loading', 'Carregando contatos…')); root.append(metrics);
    const recentCard = $('section', 'law-contact-recent-card fs-card');
    const recentHeader = $('div', 'fs-card-header law-contact-recent-header');
    const recentTitle = $('div'); recentTitle.append($('span', 'law-contact-section-kicker', 'SEU FLUXO'), $('h3', 'fs-card-title', 'Acessados recentemente'));
    recentHeader.append(recentTitle);
    recentHeader.append($('span', 'law-contact-recent-caption', 'Até cinco contatos consultados por você'));
    const recentBody = $('div', 'fs-card-body law-contact-recent');
    recentCard.append(recentHeader, recentBody); root.append(recentCard);

    const filters = $('form', 'law-contact-filters');
    const search = input('', 'Buscar por nome, organização ou documento autorizado', 180); search.type = 'search'; search.setAttribute('aria-label', 'Buscar contatos');
    const nature = select([['', 'Todas as naturezas'], ['pf', 'Pessoa física'], ['pj', 'Pessoa jurídica']]); nature.setAttribute('aria-label', 'Filtrar por natureza');
    const status = select([['ativo', 'Ativos'], ['inativo', 'Inativos'], ['todos', 'Todos']]); status.setAttribute('aria-label', 'Filtrar por situação');
    const profession = select([['', 'Todas as profissões']]); profession.setAttribute('aria-label', 'Filtrar por profissão');
    const tagFilter = input('', 'Filtrar por tag', 64); tagFilter.setAttribute('list', 'law-contact-tags'); tagFilter.setAttribute('aria-label', 'Filtrar por tag');
    const datalist = $('datalist'); datalist.id = 'law-contact-tags'; tagFilter.setAttribute('list', datalist.id);
    filters.append(field('Pesquisar', search), field('Natureza', nature), field('Profissão', profession), field('Tag', tagFilter), field('Situação', status), datalist);
    filters.addEventListener('submit', (event) => { event.preventDefault(); page = 1; refresh(); });
    [nature, status, profession, tagFilter].forEach((control) => control.addEventListener('change', () => { page = 1; refresh(); }));
    search.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => { page = 1; refresh(); }, 250); });
    root.append(filters);

    const state = $('p', 'law-contact-feedback'); state.setAttribute('role', 'status'); root.append(state);
    const tableWrap = $('div', 'fs-table-responsive law-contact-table-wrap');
    const table = $('table', 'fs-table law-contact-table');
    const thead = $('thead'); const headerRow = $('tr');
    ['Contato', 'Natureza', 'Profissão / vínculo', 'Tags', 'Setores', 'Situação', 'Ações'].forEach((label) => headerRow.append($('th', '', label)));
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
        if (profession.value) params.set('profession', profession.value);
        if (tagFilter.value.trim()) params.set('tag', tagFilter.value.trim());
        const result = await window.FokusApi.request(`/law/contacts?${params.toString()}`);
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
        const classified = Math.max(1, pf + pj);
        overview.replaceChildren();
        const bannerCopy = $('div', 'law-contact-overview-copy');
        bannerCopy.append($('span', 'law-contact-overview-kicker', 'PAINEL DE RELACIONAMENTO'));
        bannerCopy.append($('h3', '', 'Sua rede jurídica, em uma visão.'));
        bannerCopy.append($('p', '', 'Pessoas, instituições e equipes com os vínculos importantes sempre à mão.'));
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
        ring.style.setProperty('--contact-pf-share', `${(pf / classified) * 100}%`);
        ring.dataset.empty = String(total === 0);
        ring.setAttribute('role', 'img'); ring.setAttribute('aria-label', `Composição dos contatos: ${pf} pessoas físicas e ${pj} pessoas jurídicas`);
        const center = $('div', 'law-contact-ring-center'); center.append($('strong', '', total.toLocaleString('pt-BR')), $('span', '', 'CONTATOS')); ring.append(center);
        const legend = $('div', 'law-contact-chart-legend');
        [['pf', 'Pessoa física', pf], ['pj', 'Pessoa jurídica', pj], ['dept', 'Departamentos', summary.departments]].forEach(([tone, label, value]) => {
          const row = $('div', `law-contact-chart-legend-row law-contact-chart-${tone}`); row.append($('span', 'law-contact-chart-dot'), $('span', '', label), $('strong', '', Number(value || 0).toLocaleString('pt-BR'))); legend.append(row);
        });
        chartArea.append(ring, legend); overview.append(bannerCopy, chartArea);

        const active = Number(summary.contacts_active || 0);
        const inactive = Number(summary.contacts_inactive || 0);
        const metricSpecs = [
          ['Contatos na base', total, `${active.toLocaleString('pt-BR')} ativos · ${inactive.toLocaleString('pt-BR')} inativos`, 'violet'],
          ['Pessoas físicas', pf, `${Math.round((pf / classified) * 100)}% da base classificada`, 'blue'],
          ['Pessoas jurídicas', pj, `${Math.round((pj / classified) * 100)}% da base classificada`, 'teal'],
          ['Departamentos', Number(summary.departments || 0), `${Number(summary.registrations_counted || total).toLocaleString('pt-BR')} cadastros contabilizados`, 'amber'],
        ];
        metricSpecs.forEach(([label, value, note, tone], index) => {
          const card = $('article', `law-contact-metric-card fs-card law-contact-metric-${tone}`);
          const body = $('div', 'fs-card-body law-contact-metric-body');
          body.append($('span', 'law-contact-metric-index', String(index + 1).padStart(2, '0')), $('span', 'law-contact-metric-label', label), $('strong', '', Number(value).toLocaleString('pt-BR')), $('small', '', note));
          card.append(body); metrics.append(card);
        });
        const quality = summary.quality || {};
        const qualitySection = $('section', 'law-contact-quality fs-card');
        const qualityHeader = $('div', 'fs-card-header'); qualityHeader.append($('h3', 'fs-card-title', 'Qualidade dos cadastros'), $('p', 'fs-card-subtitle law-contact-help', 'Pendências em contatos ativos próprios; os dados pessoais não são exibidos.'));
        const qualityBody = $('div', 'fs-card-body law-contact-quality-grid');
        [['Sem telefone', quality.without_phone, 'without_phone'], ['Sem e-mail', quality.without_email, 'without_email'], ['Dados institucionais incompletos', quality.institutional_incomplete, 'institutional_incomplete']].forEach(([label, value, type]) => {
          const card = $('div', 'law-contact-quality-metric'); card.append($('span', '', label), $('strong', '', Number(value || 0).toLocaleString('pt-BR')));
          card.append(button('Revisar cadastros', 'fs-btn fs-btn-secondary', async (event) => {
            const trigger = event.currentTarget; trigger.disabled = true;
            try {
              const result = await window.FokusApi.request(`/law/contacts/quality/review?type=${encodeURIComponent(type)}`);
              const modal = createModal(root, label, 'fs-modal-lg', trigger);
              const reviewRows = $('div'); const paging = $('div', 'law-contact-quality-paging'); modal.body.append(reviewRows, paging);
              const renderReviewPage = (pageResult) => {
                const currentPage = Number(pageResult.pagination?.page || 1); const perPage = Number(pageResult.pagination?.per_page || 25); const count = Number(pageResult.pagination?.total || 0); const pages = Math.max(1, Math.ceil(count / perPage));
                reviewRows.replaceChildren(); (pageResult.contacts || []).forEach((item) => { const row = $('div', 'law-contact-duplicate-pair'); row.append($('strong', '', item.display_name), $('span', '', item.legal_nature === 'pj' ? 'Pessoa jurídica' : 'Pessoa física'), button('Abrir ficha', 'fs-btn fs-btn-secondary', (openEvent) => { modal.close(); openDetails(root, item.id, false, refresh, openEvent.currentTarget); })); reviewRows.append(row); });
                if (!pageResult.contacts?.length) reviewRows.append($('p', '', 'Nenhum cadastro pendente nesta categoria.'));
                paging.replaceChildren(button('Anterior', 'fs-btn fs-btn-secondary', async () => renderReviewPage(await window.FokusApi.request(`/law/contacts/quality/review?type=${encodeURIComponent(type)}&page=${currentPage - 1}`))), $('span', '', `Página ${currentPage}/${pages}`), button('Próxima', 'fs-btn fs-btn-secondary', async () => renderReviewPage(await window.FokusApi.request(`/law/contacts/quality/review?type=${encodeURIComponent(type)}&page=${currentPage + 1}`))));
                paging.firstElementChild.disabled = currentPage <= 1; paging.lastElementChild.disabled = currentPage >= pages;
              };
              renderReviewPage(result);
              modal.footer.append(button('Fechar', 'fs-btn fs-btn-secondary', () => modal.close()));
            } catch (error) { window.alert(error.message || 'Não foi possível carregar os cadastros para revisão.'); }
            finally { trigger.disabled = false; }
          })); qualityBody.append(card);
        });
        const duplicateButton = button('Analisar possíveis duplicidades', 'fs-btn fs-btn-outline-primary', async () => {
          duplicateButton.disabled = true; duplicateButton.textContent = 'Analisando…';
          try {
            const result = await window.FokusApi.request('/law/contacts/quality/duplicates');
            const modal = createModal(root, 'Possíveis duplicidades', 'fs-modal-lg');
            const intro = $('p', 'law-contact-help'); const rows = $('div'); const paging = $('div', 'law-contact-quality-paging'); modal.body.append(intro, rows, paging);
            const renderPage = (pageResult) => {
              const currentPage = Number(pageResult.pagination?.page || 1); const perPage = Number(pageResult.pagination?.per_page || 25); const totalPairs = Number(pageResult.pagination?.total || 0); const pages = Math.max(1, Math.ceil(totalPairs / perPage));
              intro.textContent = `${totalPairs.toLocaleString('pt-BR')} par(es) para revisão. Página ${currentPage} de ${pages}. Os valores coincidentes ficam ocultos; esta análise não altera cadastros.`;
              rows.replaceChildren(); (pageResult.pairs || []).forEach((pair) => { const row = $('div', 'law-contact-duplicate-pair'); row.append($('strong', '', `${pair.contact.display_name} · ${pair.candidate.display_name}`), $('span', '', pair.reason), button('Revisar primeiro contato', 'fs-btn fs-btn-secondary', () => { modal.close(); openDetails(root, pair.contact.id, false, refresh); })); rows.append(row); });
              if (!pageResult.pairs?.length) rows.append($('p', '', 'Nenhum candidato encontrado.'));
              paging.replaceChildren(button('Anterior', 'fs-btn fs-btn-secondary', async () => renderPage(await window.FokusApi.request(`/law/contacts/quality/duplicates?page=${currentPage - 1}`))), $('span', '', `Página ${currentPage}/${pages}`), button('Próxima', 'fs-btn fs-btn-secondary', async () => renderPage(await window.FokusApi.request(`/law/contacts/quality/duplicates?page=${currentPage + 1}`))));
              paging.firstElementChild.disabled = currentPage <= 1; paging.lastElementChild.disabled = currentPage >= pages;
            };
            renderPage(result); modal.footer.append(button('Fechar', 'fs-btn fs-btn-secondary', () => modal.close()));
          } catch (error) { window.alert(error.message || 'Não foi possível analisar duplicidades.'); }
          finally { duplicateButton.disabled = false; duplicateButton.textContent = 'Analisar possíveis duplicidades'; }
        });
        qualityBody.append(duplicateButton); qualitySection.append(qualityHeader, qualityBody); overview.append(qualitySection);
        recentBody.replaceChildren();
        if (summary.recent?.length) summary.recent.slice(0, 5).forEach((item) => {
          const link = button('', 'law-contact-recent-item', (event) => openDetails(root, item.id, false, refresh, event.currentTarget));
          const detail = $('span', 'law-contact-recent-meta');
          const activityLabels = { created: 'Cadastrado', viewed: 'Consultado', search_opened: 'Aberto pela busca' };
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
          const tr = $('tr'); const td = $('td', 'law-contact-empty', 'Nenhum contato encontrado com estes filtros.'); td.colSpan = 7; tr.append(td); tbody.append(tr);
        } else currentItems.forEach((contact) => {
          const tr = $('tr');
          const titleCell = $('td'); const open = button(contact.display_name, 'law-contact-name', (event) => openDetails(root, contact.id, contact.is_shared, refresh, event.currentTarget));
          titleCell.append(open); if (contact.is_shared) titleCell.append($('span', 'law-contact-shared-badge', `Compartilhado por ${contact.source_company_name || 'outra empresa'}`));
          tr.append(titleCell, $('td', '', contact.legal_nature === 'pj' ? 'Pessoa jurídica' : 'Pessoa física'));
          tr.append($('td', '', contact.legal_nature === 'pj' ? '—' : ((contact.professions || contact.classification_labels || []).join(', ') || '—')));
          tr.append($('td', '', (contact.tags || []).join(', ') || '—'));
          tr.append($('td', '', `${contact.departments?.length || 0} departamento(s)`));
          tr.append($('td', '', contact.status === 'ativo' ? 'Ativo' : 'Inativo'));
          const actions = $('td', 'law-contact-actions');
          const actionList = $('div', 'law-contact-action-list');
          actionList.append(iconButton('Ver detalhes', 'Folder-File--Streamline-Ultimate.png', (event) => openDetails(root, contact.id, contact.is_shared, refresh, event.currentTarget)));
          if (!contact.is_shared && can('law.contacts.update')) actionList.append(iconButton('Editar contato', 'Common-File-Edit--Streamline-Ultimate.png', (event) => openEditor(root, contact, refresh, event.currentTarget, relationshipOptions)));
          if (!contact.is_shared && can('law.contacts.delete') && contact.status === 'ativo') actionList.append(iconButton('Inativar contato', 'Common-File-Subtract--Streamline-Ultimate.png', async (event) => {
            if (!await confirmAction(root, 'Inativar contato', 'O cadastro deixará de aparecer entre os contatos ativos. Os dados históricos serão preservados.', 'Inativar contato', event.currentTarget)) return;
            try { await window.FokusApi.request(`/law/contacts/${encodeURIComponent(contact.id)}`, { method: 'DELETE' }); await refresh(); }
            catch (error) { state.dataset.state = 'error'; state.textContent = error.message || 'Não foi possível inativar o contato.'; }
          }));
          actions.append(actionList);
          tr.append(actions); tbody.append(tr);
        });
        const pagination = result.pagination || { page, per_page: 25, total: currentItems.length };
        pageLabel.textContent = `Página ${pagination.page} · ${pagination.total.toLocaleString('pt-BR')} contato(s)`;
        previous.disabled = page <= 1; next.disabled = pagination.page * pagination.per_page >= pagination.total;
      } catch (error) {
        metrics.replaceChildren(); overview.replaceChildren($('p', 'law-contact-overview-error', error.message || 'Não foi possível carregar o resumo da base.'));
        recentBody.replaceChildren($('p', 'law-contact-recent-empty', 'A atividade recente ficará disponível quando a lista carregar.'));
        state.dataset.state = 'error'; state.textContent = 'Não foi possível carregar a lista. Atualize ou ajuste os filtros.';
      }
    }
    refresh();
    return { refresh };
  }

  function openEditor(root, contact, onSaved, opener = null, relationshipOptions = []) {
    const modal = createModal(root, contact ? 'Editar contato' : 'Novo contato', 'fs-modal-xl', opener);
    const form = $('form', 'law-contact-editor');
    form.id = `law-contact-form-${++modalSequence}`;
    const status = $('p', 'law-contact-feedback'); status.setAttribute('role', 'status');
    const basic = section('Dados principais');
    const nature = select([['pf', 'Pessoa física'], ['pj', 'Pessoa jurídica']], contact?.legal_nature || 'pf'); nature.name = 'legal_nature';
    const name = input(contact?.display_name || '', 'Nome completo ou nome fantasia', 180); name.required = true; name.name = 'display_name';
    const acronym = input(contact?.acronym || '', 'Ex.: TJBA', 32); acronym.name = 'acronym';
    const particles = new Set(['da','das','de','do','dos','e']);
    name.addEventListener('blur', () => { name.value = name.value.trim().toLocaleLowerCase('pt-BR').replace(/(^|[\s-])([^\s-]+)/gu, (part) => { const word = part.trimStart(); const prefix = part.slice(0, part.length - word.length); return prefix + (particles.has(word) ? word : word.charAt(0).toLocaleUpperCase('pt-BR') + word.slice(1)); }); });
    const legalName = input(contact?.legal_name || '', 'Razão social (opcional)', 180); legalName.name = 'legal_name';
    const nameField = field('Nome *', name); const acronymField = field('Sigla', acronym);
    const nameRow = $('div', 'law-contact-name-fields'); nameRow.append(nameField, acronymField);
    basic.content.append(field('Natureza *', nature), nameRow); const legalNameField = field('Razão social / nome complementar', legalName); basic.content.append(legalNameField);
    const professionSection = section('Profissão / vínculo', 'Selecione uma opção cadastrada ou inclua uma nova especificação.');
    const professionRows = $('div', 'law-contact-profession-list');
    (contact?.professions || []).forEach((value) => professionRows.append(professionChip(value)));
    const professionSelect = select([['', 'Selecione uma profissão'], ...(window.lawContactProfessions || []).map((value) => [value, value])]);
    const professionNew = input('', 'Ex.: Policial Civil, Guarda Municipal, Agente penitenciário', 100);
    const addProfession = (value) => { const clean = value.trim(); if (clean && ![...professionRows.querySelectorAll('[data-profession]')].some((item) => item.dataset.profession.toLocaleLowerCase() === clean.toLocaleLowerCase())) professionRows.append(professionChip(clean)); professionSelect.value = ''; professionNew.value = ''; };
    professionSelect.addEventListener('change', () => { if (professionSelect.value) addProfession(professionSelect.value); });
    professionSection.content.append(professionRows, field('Profissões cadastradas', professionSelect), field('Nova profissão / especificação', professionNew), button('Adicionar profissão', 'fs-btn fs-btn-secondary', () => addProfession(professionNew.value)));
    const classificationSection = section('Classificações', 'Selecione todas as classificações aplicáveis ao contato.');
    const classificationInputs = new Map();
    Object.entries(CLASSIFICATIONS).forEach(([code, label]) => { const wrap = $('label', 'law-contact-check'); const check = $('input'); check.type = 'checkbox'; check.checked = (contact?.classifications || []).includes(code); wrap.append(check, $('span', '', label)); classificationInputs.set(code, check); classificationSection.content.append(wrap); });
    const institutionalSection = section('Dados institucionais', 'Campos opcionais por classificação; cadastros incompletos podem ser salvos e revisados depois.');
    const institutionalData = Array.isArray(contact?.institutional_data) ? contact.institutional_data : (contact?.institutional_data ? [contact.institutional_data] : []);
    const courtData = institutionalData.find((item) => item.type === 'court_unit') || {};
    const publicData = institutionalData.find((item) => item.type === 'public_body') || {};
    const cnj = input(courtData.cnj_code || '', 'Código CNJ (20 dígitos)', 25);
    const competencies = input((courtData.competencies || []).join(', '), 'Ex.: Criminal, Família, Fazenda Pública', 500);
    const competencyList = $('datalist'); competencyList.id = `${form.id}-competencies`; competencyOptions.forEach((value) => { const option = $('option'); option.value = value; competencyList.append(option); }); competencies.setAttribute('list', competencyList.id);
    const sphere = select([['', 'Selecione a esfera'], ['Federal', 'Federal'], ['Estadual', 'Estadual'], ['Distrital', 'Distrital'], ['Municipal', 'Municipal']], publicData.administrative_sphere || '');
    const officialCode = input(publicData.official_code || '', 'Código oficial', 80);
    const issuingSystem = input(publicData.issuing_system || '', 'Sistema emissor', 80);
    institutionalSection.content.append(field('Código CNJ da unidade judiciária', cnj), field('Competências (separadas por vírgula)', competencies), competencyList, field('Esfera administrativa', sphere), field('Identificador oficial', officialCode), field('Sistema emissor', issuingSystem));
    const updateInstitutional = () => { institutionalSection.hidden = !classificationInputs.get('court_unit').checked && !classificationInputs.get('public_body').checked; cnj.parentElement.hidden = !classificationInputs.get('court_unit').checked; competencies.parentElement.hidden = !classificationInputs.get('court_unit').checked; sphere.parentElement.hidden = !classificationInputs.get('public_body').checked; officialCode.parentElement.hidden = !classificationInputs.get('public_body').checked; issuingSystem.parentElement.hidden = !classificationInputs.get('public_body').checked; };
    classificationInputs.get('court_unit').addEventListener('change', updateInstitutional); classificationInputs.get('public_body').addEventListener('change', updateInstitutional); updateInstitutional();
    const relationshipSection = section('Vínculos empresariais', 'Associe este contato a pessoas físicas ou empresas já cadastradas.');
    const linkedContactIds = new Set((contact?.linked_contacts || []).map((item) => item.id));
    const linkMetadata = new Map((contact?.linked_contacts || []).map((item) => [item.id, { roles: item.roles || [], designations: item.designations || [] }]));
    const relationshipPicker = select([['', 'Selecione para vincular']]);
    const relationshipChips = $('div', 'law-contact-profession-list');
    const renderRelationshipChips = () => {
      relationshipChips.replaceChildren();
      [...linkedContactIds].forEach((id) => {
        const item = relationshipOptions.find((option) => option.id === id) || (contact?.linked_contacts || []).find((option) => option.id === id);
        if (!item) return;
        const row = $('div', 'law-contact-relationship-editor');
        row.append($('strong', '', `${item.display_name}${item.acronym ? ` (${item.acronym})` : ''}`));
        const metadata = linkMetadata.get(id) || { roles: [], designations: [] };
        const roleRows = $('div', 'law-contact-relationship-entries');
        const roleOptions = [['', 'Selecione um papel'], ['employee', 'Funcionário/colaborador'], ['public_servant', 'Servidor público'], ['legal_representative', 'Representante legal'], ['partner', 'Sócio'], ['administrator', 'Administrador/diretor'], ['attorney_in_fact', 'Procurador'], ['other', 'Outro']];
        const addRoleRow = (role = {}) => { const entry = $('div', 'law-contact-relationship-entry'); const selectRole = select(roleOptions, role.code || ''); const detail = input(role.detail || '', 'Complemento para Outro', 160); const starts = input(role.starts_on || '', '', 10); starts.type = 'date'; const ends = input(role.ends_on || '', '', 10); ends.type = 'date'; entry.append(field('Papel', selectRole), field('Complemento (Outro)', detail), field('Início', starts), field('Término', ends), button('Remover papel', 'law-contact-chip-remove', () => entry.remove())); entry.getMetadata = () => ({ code: selectRole.value, detail: selectRole.value === 'other' ? detail.value.trim() || null : null, starts_on: starts.value || null, ends_on: ends.value || null }); roleRows.append(entry); };
        metadata.roles.forEach(addRoleRow);
        const designationRows = $('div', 'law-contact-relationship-entries');
        const designationList = $('datalist'); designationList.id = `${form.id}-designations-${id}`; designationOptions.forEach((value) => { const option = $('option'); option.value = value; designationList.append(option); });
        const addDesignationRow = (designation = {}) => { const entry = $('div', 'law-contact-relationship-entry'); const title = input(designation.name || '', 'Ex.: DPC, IPC, CB/PM, SD/PM, TEN/PM', 120); title.setAttribute('list', designationList.id); const starts = input(designation.starts_on || '', '', 10); starts.type = 'date'; const ends = input(designation.ends_on || '', '', 10); ends.type = 'date'; entry.append(field('Cargo/posto/graduação/função', title), field('Início', starts), field('Término', ends), button('Remover designação', 'law-contact-chip-remove', () => entry.remove())); entry.getMetadata = () => title.value.trim() ? { name: title.value.trim(), starts_on: starts.value || null, ends_on: ends.value || null } : null; designationRows.append(entry); };
        metadata.designations.forEach(addDesignationRow);
        row.append(roleRows, button('Adicionar papel', 'fs-btn fs-btn-secondary', () => addRoleRow()), designationRows, button('Adicionar designação', 'fs-btn fs-btn-secondary', () => addDesignationRow()), designationList);
        row.append(button('Remover vínculo', 'law-contact-chip-remove', () => { linkedContactIds.delete(id); linkMetadata.delete(id); renderRelationshipChips(); }));
        row.dataset.linkId = id;
        row.getMetadata = () => ({ contact_id: id, roles: [...roleRows.children].map((entry) => entry.getMetadata()).filter((role) => role.code), designations: [...designationRows.children].map((entry) => entry.getMetadata()).filter(Boolean) });
        relationshipChips.append(row);
      });
    };
    relationshipPicker.addEventListener('change', () => { if (relationshipPicker.value) linkedContactIds.add(relationshipPicker.value); relationshipPicker.value = ''; renderRelationshipChips(); });
    relationshipSection.content.append(field('Adicionar pessoa ou empresa', relationshipPicker), relationshipChips); renderRelationshipChips();
    let documentRows;
    let documentSection;
    if (window.lawContactsCanSensitive) {
      documentSection = section('Documentos', 'Até 4 documentos. CPF ou CNPJ são validados quando informados; inscrição estadual exige UF.');
      documentRows = $('div', 'law-contact-repeat-list');
      (contact?.documents || []).filter((doc) => DOCUMENT_TYPES_BY_NATURE[nature.value].includes(doc.type)).forEach((doc) => documentRows.append(documentRow(doc, nature.value)));
      documentSection.content.append(documentRows, button('Adicionar documento', 'fs-btn fs-btn-secondary', () => { if (documentRows.children.length < 4) documentRows.append(documentRow({}, nature.value)); }));
    }
    const channelSection = section('Telefones e e-mails', 'Até 4 telefones e 2 e-mails.');
    const channelRows = $('div', 'law-contact-repeat-list'); (contact?.channels || []).filter((item) => window.lawContactsCanSensitive || !item.personal).forEach((item) => channelRows.append(channelRow(item)));
    enforceSinglePrimary(channelRows, (row) => row.querySelector('[data-channel-type]').value === 'email' ? 'email' : 'phone', true);
    channelSection.content.append(channelRows, button('Adicionar telefone ou e-mail', 'fs-btn fs-btn-secondary', () => {
      if (channelRows.children.length >= 6) return;
      const row = channelRow(); channelRows.append(row);
      const group = row.querySelector('[data-channel-type]').value === 'email' ? 'email' : 'phone';
      if (![...channelRows.children].some((other) => other !== row && (other.querySelector('[data-channel-type]').value === 'email' ? 'email' : 'phone') === group && other.querySelector('[data-primary]').value === '1')) row.querySelector('[data-primary]').value = '1';
    }));
    const addressSection = section('Endereços', 'Até 2 endereços completos.');
    const addressRows = $('div', 'law-contact-repeat-list'); (contact?.addresses || []).forEach((item) => addressRows.append(addressRow(item)));
    enforceSinglePrimary(addressRows, () => 'address', true);
    addressSection.content.append(addressRows, button('Adicionar endereço', 'fs-btn fs-btn-secondary', () => {
      if (addressRows.children.length >= 2) return;
      const row = addressRow(); addressRows.append(row);
      if (![...addressRows.children].some((other) => other !== row && other.querySelector('[data-primary]').value === '1')) row.querySelector('[data-primary]').value = '1';
    }));
    const departmentSection = section('Departamentos da empresa', 'Cada departamento é uma unidade adicional no consumo contratado e aceita até 4 telefones e 2 e-mails.');
    const departmentRows = $('div', 'law-contact-repeat-list'); (contact?.departments || []).forEach((item) => departmentRows.append(departmentRow(item)));
    departmentSection.content.append(departmentRows, button('Adicionar departamento', 'fs-btn fs-btn-secondary', () => departmentRows.append(departmentRow())));
    const tagField = input((contact?.tags || []).join(', '), 'Ex.: testemunha, urgente, comarca', 400); tagField.name = 'tags';
    const tags = section('Tags', 'Separe por vírgula; até 6 por contato. As tags são reutilizadas pela empresa.'); tags.content.append(field('Tags', tagField));
    const notes = document.createElement('textarea'); notes.className = 'fs-form-control'; notes.maxLength = 4000; notes.value = contact?.notes || ''; notes.name = 'notes';
    const notesSection = section('Notas privadas', 'Visíveis somente a perfis com acesso a dados sensíveis.'); notesSection.content.append(field('Notas', notes));
    const message = $('p', 'law-contact-feedback'); message.setAttribute('role', 'status');
    const duplicateSuggestions = $('div', 'law-contact-duplicate-suggestions');
    const checkDuplicates = button('Verificar possíveis duplicidades', 'fs-btn fs-btn-outline-primary', async () => {
      checkDuplicates.disabled = true; duplicateSuggestions.replaceChildren($('p', 'law-contact-help', 'Buscando cadastros semelhantes…'));
      try {
        const result = await window.FokusApi.request('/law/contacts/quality/suggestions', { method: 'POST', body: { display_name: name.value.trim(), legal_name: legalName.value.trim() || null, legal_nature: nature.value, channels: [...channelRows.children].map((row) => ({ type: row.querySelector('[data-channel-type]').value, value: row.querySelector('[data-channel-value]').value.trim(), personal: false })).filter((channel) => channel.value), institutional_code: officialCode.value.trim() || null } });
        duplicateSuggestions.replaceChildren();
        if (!result.candidates?.length) duplicateSuggestions.append($('p', 'law-contact-help', 'Nenhum candidato encontrado.'));
        else { duplicateSuggestions.append($('p', 'law-contact-help', 'Confira os candidatos antes de salvar. A correspondência é explicada sem expor os valores coincidentes.')); result.candidates.forEach((candidate) => { const row = $('div', 'law-contact-duplicate-pair'); row.append($('strong', '', candidate.display_name), $('span', '', candidate.reason), button('Ver cadastro', 'fs-btn fs-btn-secondary', (event) => openDetails(root, candidate.id, false, onSaved, event.currentTarget))); duplicateSuggestions.append(row); }); }
      } catch (error) { duplicateSuggestions.replaceChildren($('p', 'law-contact-feedback', error.message || 'Não foi possível verificar duplicidades.')); }
      finally { checkDuplicates.disabled = false; }
    });
    if (contact) checkDuplicates.hidden = true;
    if (contact) {
      const statusSelect = select([['ativo', 'Ativo'], ['inativo', 'Inativo']], contact.status); statusSelect.name = 'status'; basic.content.append(field('Situação', statusSelect));
      const excludedWrap = $('label', 'law-contact-check'); const excluded = $('input'); excluded.type = 'checkbox'; excluded.checked = Boolean(contact.sharing_excluded); excluded.name = 'sharing_excluded'; excludedWrap.append(excluded, $('span', '', 'Excluir dos compartilhamentos configurados')); basic.content.append(excludedWrap);
    }
    const cancel = button('Cancelar', 'fs-btn fs-btn-secondary', () => modal.close());
    const save = $('button', 'fs-btn fs-btn-primary', contact ? 'Salvar alterações' : 'Cadastrar contato'); save.type = 'submit'; save.setAttribute('form', form.id);
    modal.footer.append(cancel, save);
    form.append(basic, classificationSection, professionSection, institutionalSection, relationshipSection);
    if (documentSection) form.append(documentSection);
    form.append(channelSection, addressSection);
    const departmentWrap = $('div', 'law-contact-department-wrap'); departmentWrap.append(departmentSection);
    form.append(departmentWrap, tags);
    // Notes remain editable only for a profile that can read protected personal data.
    if (window.lawContactsCanSensitive) form.append(notesSection);
    form.append(checkDuplicates, duplicateSuggestions, message);
    const updateNature = () => {
      departmentWrap.hidden = nature.value !== 'pj'; legalNameField.hidden = nature.value !== 'pj';
      acronymField.hidden = nature.value !== 'pj';
      professionSection.hidden = nature.value === 'pj';
      if (documentRows) [...documentRows.children].forEach((row) => {
        const type = row.querySelector('[data-doc-type]');
        if (!DOCUMENT_TYPES_BY_NATURE[nature.value].includes(type.value)) { row.remove(); return; }
        const selected = type.value;
        type.replaceChildren(...DOCUMENT_TYPES_BY_NATURE[nature.value].map((code) => new Option(DOCUMENTS[code], code)));
        type.value = selected; type.dispatchEvent(new Event('change'));
      });
      const candidates = relationshipOptions.filter((item) => item.id !== contact?.id && item.legal_nature !== nature.value);
      relationshipPicker.replaceChildren(new Option('Selecione para vincular', ''), ...candidates.map((item) => new Option(`${item.display_name}${item.acronym ? ` (${item.acronym})` : ''}`, item.id)));
      relationshipSection.hidden = candidates.length === 0;
    };
    nature.addEventListener('change', updateNature); updateNature();
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
        legal_nature: nature.value, display_name: name.value.trim(), acronym: acronym.value.trim() || null, legal_name: legalName.value.trim() || null,
        professions: nature.value === 'pf' ? [...professionRows.querySelectorAll('[data-profession]')].map((item) => item.dataset.profession) : [],
        classifications: [...classificationInputs].filter(([, check]) => check.checked).map(([code]) => code),
        linked_contact_ids: [...linkedContactIds],
        linked_relationships: [...relationshipChips.children].map((row) => row.getMetadata()),
        channels: [...channelRows.children].map((row) => ({ type: row.querySelector('[data-channel-type]').value, value: row.querySelector('[data-channel-value]').value.trim(), label: row.querySelector('[data-channel-label]').value.trim() || null, personal: false, primary: row.querySelector('[data-primary]').value === '1' })).filter((item) => item.value),
        addresses: [...addressRows.children].map((row) => ({ type: row.querySelector('[data-address-type]').value, postal_code: row.querySelector('[data-postal]').value.trim() || null, street: row.querySelector('[data-street]').value.trim(), number: row.querySelector('[data-number]').value.trim() || null, complement: row.querySelector('[data-complement]').value.trim() || null, district: row.querySelector('[data-district]').value.trim() || null, city: row.querySelector('[data-city]').value.trim(), state: row.querySelector('[data-state]').value.trim().toUpperCase(), country: row.querySelector('[data-country]').value.trim() || 'Brasil', primary: row.querySelector('[data-primary]').value === '1' })),
        departments: nature.value === 'pj' ? [...departmentRows.children].map((row) => ({ name: row.querySelector('[data-department-name]').value.trim(), channels: [...row.querySelectorAll('.law-contact-department-channel')].map((item) => ({ type: item.querySelector('[data-channel-type]').value, value: item.querySelector('[data-channel-value]').value.trim(), label: item.querySelector('[data-channel-label]').value.trim() || null })).filter((item) => item.value) })).filter((item) => item.name) : [],
        tags: tagField.value.split(',').map((value) => value.trim()).filter(Boolean),
      };
      body.institutional_data = [];
      if (classificationInputs.get('court_unit').checked) body.institutional_data.push({ type: 'court_unit', cnj_code: cnj.value.trim() || null, competencies: competencies.value.split(',').map((value) => value.trim()).filter(Boolean) });
      if (classificationInputs.get('public_body').checked) body.institutional_data.push({ type: 'public_body', administrative_sphere: sphere.value || null, official_code: officialCode.value.trim() || null, issuing_system: issuingSystem.value.trim() || null });
      if (window.lawContactsCanSensitive) {
        body.documents = [...documentRows.children].map((row) => { const type = row.querySelector('[data-doc-type]').value; const noUf = ['cpf','cnpj'].includes(type); const noLabel = noUf || type === 'state_registration'; return { type, number: row.querySelector('[data-doc-number]').value.trim(), state: noUf ? null : (row.querySelector('[data-doc-state]').value || null), label: noLabel ? null : (row.querySelector('[data-doc-label]').value.trim() || null) }; }).filter((doc) => doc.number);
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

  function documentRow(doc = {}, nature = 'pf') {
    const row = $('div', 'law-contact-repeat-row law-contact-document-row');
    const type = select(DOCUMENT_TYPES_BY_NATURE[nature].map((code) => [code, DOCUMENTS[code]]), doc.type || (nature === 'pj' ? 'cnpj' : 'cpf')); type.dataset.docType = '1';
    const number = input(doc.number || '', 'Número do documento', 120); number.dataset.docNumber = '1';
    const state = select([['','UF'], ...STATES], doc.state || 'BA'); state.dataset.docState = '1';
    const label = input(doc.label || '', 'Identificação', 80); label.dataset.docLabel = '1';
    const stateField = field('UF de emissão', state); const labelField = field('Identificação', label);
    const numberMask = window.FokusDocuments?.bind(number, () => type.value);
    const update = () => { const noUf = ['cpf','cnpj'].includes(type.value); stateField.hidden = noUf; state.required = type.value === 'state_registration'; labelField.hidden = noUf || type.value === 'state_registration'; number.placeholder = type.value === 'cpf' ? '000.000.000-00' : type.value === 'cnpj' ? '00.000.000/0000-00' : 'Número do documento'; number.inputMode = type.value === 'cpf' ? 'numeric' : type.value === 'cnpj' ? 'text' : 'text'; number.maxLength = type.value === 'cpf' ? 14 : type.value === 'cnpj' ? 18 : 120; numberMask?.apply(); };
    type.addEventListener('change', update); update();
    row.append(field('Tipo', type), field('Número', number), stateField, labelField, button('Remover', 'law-contact-remove', () => row.remove())); return row;
  }

  function professionChip(value) {
    const chip = $('span', 'law-contact-profession-chip', value); chip.dataset.profession = value;
    chip.append(button('×', 'law-contact-remove', () => chip.remove())); return chip;
  }

  function channelRow(item = {}) {
    const row = $('div', 'law-contact-repeat-row law-contact-channel-row');
    const type = select([['phone', 'Telefone fixo'], ['extension', 'Ramal'], ['mobile', 'Celular'], ['whatsapp', 'WhatsApp'], ['email', 'E-mail']], item.type || 'phone'); type.dataset.channelType = '1';
    const value = input(item.value || '', 'Telefone ou e-mail', 255); value.dataset.channelValue = '1';
    const labels = ['Principal','Pessoal','Profissional','Recado','Comercial','Emergência'];
    if (item.label && !labels.includes(item.label)) labels.push(item.label);
    const labelSelect = select(labels.map((value) => [value, value]), item.label || 'Principal'); labelSelect.dataset.channelLabel = '1';
    const primary = select([['0','Não'],['1','Sim']], item.primary ? '1' : '0'); primary.dataset.primary = '1';
    row.append(field('Canal', type), field('Contato', value), field('Rótulo', labelSelect), field('Principal', primary), button('Remover', 'law-contact-remove', () => row.remove())); return row;
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
    const addressTypes = [['business', 'Comercial/institucional'], ['correspondence', 'Correspondência'], ['other', 'Outro']];
    if (window.lawContactsCanSensitive) addressTypes.splice(1, 0, ['residential', 'Residencial']);
    const type = select(addressTypes, address.type || 'business'); type.dataset.addressType = '1';
    const postal = input(address.postal_code, '00000-000', 9); postal.dataset.postal = '1'; postal.inputMode = 'numeric';
    const street = input(address.street, 'Logradouro', 180); street.required = true; street.dataset.street = '1';
    const number = input(address.number, 'Número', 32); number.dataset.number = '1';
    const complement = input(address.complement, 'Complemento', 120); complement.dataset.complement = '1';
    const district = input(address.district, 'Bairro', 120); district.dataset.district = '1';
    const city = input(address.city, 'Município', 120); city.required = true; city.dataset.city = '1';
    const state = select([['','Selecione a UF'], ...STATES], address.state || 'BA'); state.required = true; state.dataset.state = '1';
    const country = input(address.country || 'Brasil', 'País', 80); country.dataset.country = '1';
    const primary = select([['0','Não'],['1','Sim']], address.primary ? '1' : '0'); primary.dataset.primary = '1';
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
    row.append(field('Tipo', type), postalField, field('Logradouro *', street), field('Número', number), field('Complemento', complement), field('Bairro', district), field('Município *', city), field('UF *', state), field('País', country), field('Principal', primary), button('Remover', 'law-contact-remove', () => row.remove()), lookupStatus); return row;
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
    const modal = createModal(root, 'Ficha do contato', 'fs-modal-xl', opener);
    const body = $('div', 'law-contact-detail-body');
    const labels = { pf: 'Pessoa física', pj: 'Pessoa jurídica', ativo: 'Ativo', inativo: 'Inativo', residential: 'Residencial', business: 'Comercial / institucional' };
    const nameParts = String(contact.display_name || '?').trim().split(/\s+/).filter(Boolean);
    const removeDiacritics = (value) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    const firstName = nameParts[0] || '?';
    const lastName = nameParts[nameParts.length - 1] || firstName;
    const initials = `${removeDiacritics([...firstName][0] || '?')}${nameParts.length > 1 ? removeDiacritics([...lastName][0] || '') : ''}`.toLocaleUpperCase('pt-BR');
    const summary = $('section', 'law-contact-detail-summary');
    const avatar = $('span', 'law-contact-detail-avatar', initials);
    const summaryCopy = $('div', 'law-contact-detail-summary-copy');
    summaryCopy.append($('span', 'law-contact-detail-eyebrow', 'VISÃO GERAL'));
    const titleRow = $('div', 'law-contact-detail-name-row');
    titleRow.append($('h3', 'law-contact-detail-name', contact.display_name));
    if (contact.acronym) titleRow.append($('span', 'law-contact-detail-acronym', contact.acronym));
    summaryCopy.append(titleRow);
    summaryCopy.append($('p', 'law-contact-detail-legal-name', contact.legal_name || labels[contact.legal_nature] || 'Contato'));
    const summaryBadges = $('div', 'law-contact-detail-summary-badges');
    summaryBadges.append($('span', `law-contact-detail-badge law-contact-detail-nature-${contact.legal_nature}`, labels[contact.legal_nature] || 'Contato'));
    summaryBadges.append($('span', `law-contact-detail-badge law-contact-detail-status-${contact.status}`, labels[contact.status] || contact.status || 'Situação não informada'));
    summary.append(avatar, summaryCopy, summaryBadges);
    const chips = $('div', 'law-contact-detail-chip-groups');
    const addChips = (title, values, tone) => {
      if (!values?.length) return;
      const group = $('div', `law-contact-detail-chip-group law-contact-detail-chip-${tone}`);
      group.append($('span', 'law-contact-detail-chip-label', title));
      const list = $('div', 'law-contact-detail-chips');
      values.forEach((value) => list.append($('span', 'law-contact-detail-chip', value)));
      group.append(list); chips.append(group);
    };
    if (contact.legal_nature !== 'pj') addChips('Profissões / vínculos', contact.professions || contact.classification_labels || [], 'classifications');
    addChips('Tags', contact.tags || [], 'tags');
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
    (contact.institutional_data || []).forEach((institution) => {
      const items = addSection(institution.type === 'court_unit' ? 'Dados da unidade judiciária' : 'Dados do órgão público', 'INS', '');
      const values = institution.type === 'court_unit'
        ? [['Código CNJ', institution.cnj_code], ['Competências', (institution.competencies || []).join(', ')]]
        : [['Esfera administrativa', institution.administrative_sphere], ['Código oficial', institution.official_code], ['Sistema emissor', institution.issuing_system]];
      values.filter(([, value]) => value).forEach(([label, value]) => { const item = $('article', 'law-contact-detail-linked-contact'); item.append($('strong', '', label), $('span', '', value)); items.append(item); });
    });
    if (contact.linked_contacts?.length) {
      const items = addSection(contact.legal_nature === 'pj' ? 'Pessoas vinculadas' : 'Empresas vinculadas', 'VÍN', contact.linked_contacts.length, 'law-contact-detail-links');
      if (contact.legal_nature === 'pj') items.remove();
      else contact.linked_contacts.forEach((linked) => {
          const row = $('article', 'law-contact-detail-linked-contact');
          row.append($('strong', '', linked.display_name), $('span', '', `${linked.acronym ? `${linked.acronym} · ` : ''}${labels[linked.legal_nature] || 'Contato'}`));
          const roleLabels = { employee: 'Funcionário/colaborador', public_servant: 'Servidor público', legal_representative: 'Representante legal', partner: 'Sócio', administrator: 'Administrador/diretor', attorney_in_fact: 'Procurador', other: 'Outro' };
          const activeRoles = (linked.roles || []).filter((item) => item.current).map((item) => `${roleLabels[item.code] || item.code}${item.code === 'other' && item.detail ? `: ${item.detail}` : ''}`);
          const activeDesignations = (linked.designations || []).filter((item) => item.current).map((item) => item.name);
          if (activeRoles.length) row.append($('span', '', `Papéis vigentes: ${activeRoles.join(', ')}`));
          if (activeDesignations.length) row.append($('span', '', `Cargo/posto/função: ${activeDesignations.join(', ')}`));
          items.append(row);
      });
    }
    if (contact.documents?.length) {
      const items = addSection('Documentos', 'ID', contact.documents.length, 'law-contact-detail-documents');
      contact.documents.forEach((doc) => {
        const item = $('article', 'law-contact-detail-document');
        const type = DOCUMENTS[doc.type] || doc.type || 'Documento';
        item.append($('span', 'law-contact-detail-document-type', Array.from(type).slice(0, 3).join('').toLocaleUpperCase('pt-BR')));
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
        meta.append(title);
        const badges = $('div', 'law-contact-detail-channel-badges');
        if (channel.primary) badges.append($('span', 'law-contact-detail-primary', 'Principal'));
        if (channel.personal) badges.append($('span', 'law-contact-detail-private', channel.value === 'Dado protegido' ? 'Acesso restrito' : 'Pessoal'));
        if (badges.children.length) meta.append(badges);
        detail.append(meta, $('strong', '', channel.value || 'Dado protegido'));
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

  async function openSharing(root, opener = null) {
    const result = await window.FokusApi.request('/law/contact-sharing');
    const modal = createModal(root, 'Compartilhamento entre empresas', 'fs-modal-xl', opener); const form = $('form', 'law-contact-editor'); form.id = `law-contact-form-${++modalSequence}`;
    form.append($('p', 'law-contact-help', 'Por padrão, nenhuma empresa acessa os contatos de outra. O compartilhamento só fica ativo quando as duas empresas configuram um acordo recíproco; cada uma define o escopo que disponibiliza.'));
    const policies = new Map((result.policies || []).map((policy) => [policy.recipient_company_id, policy]));
    const items = $('div', 'law-contact-sharing-list');
    (result.companies || []).forEach((company) => {
      const policy = policies.get(company.id);
      const card = $('fieldset', 'fs-card fs-card-sm law-contact-share-card');
      const legend = $('legend', '', company.name); card.append(legend);
      const agreementStatus = company.incoming_agreement && policy?.is_active
        ? 'Acordo bilateral ativo.'
        : policy?.is_active
          ? 'Aguardando a outra empresa configurar o acordo.'
          : company.incoming_agreement
            ? 'A outra empresa propôs compartilhar. Configure seu escopo para aceitar.'
            : 'Sem acordo de compartilhamento.';
      card.append($('p', 'law-contact-help law-contact-share-status', agreementStatus));
      const enabledWrap = $('label', 'law-contact-check');
      const enabled = $('input'); enabled.type = 'checkbox'; enabled.checked = Boolean(policy?.is_active); enabled.dataset.shareCompany = company.id;
      enabledWrap.append(enabled, $('span', '', 'Configurar meu lado do acordo bilateral')); card.append(enabledWrap);

      const natures = $('div', 'law-contact-check-grid');
      [['pj', 'Pessoas jurídicas'], ['pf', 'Pessoas físicas']].forEach(([value, label]) => {
        const wrap = $('label', 'law-contact-check'); const check = $('input');
        check.type = 'checkbox'; check.value = value; check.checked = (policy?.legal_natures || []).includes(value); check.dataset.shareNature = '1';
        wrap.append(check, $('span', '', label)); natures.append(wrap);
      });
      card.append($('p', 'law-contact-help', 'Naturezas que minha empresa disponibiliza'), natures);

      const professionGroup = $('div', 'law-contact-share-professions');
      professionGroup.append($('p', 'law-contact-help', 'Profissões de pessoas físicas que minha empresa disponibiliza'));
      const professions = $('div', 'law-contact-check-grid');
      (result.professions || []).forEach((profession) => {
        const wrap = $('label', 'law-contact-check'); const check = $('input');
        check.type = 'checkbox'; check.value = profession.value; check.checked = (policy?.profession_names || []).includes(profession.value); check.dataset.shareProfession = '1';
        wrap.append(check, $('span', '', profession.label)); professions.append(wrap);
      });
      professionGroup.append(professions);
      const pfNature = natures.querySelector('[data-share-nature][value="pf"]');
      professionGroup.hidden = !pfNature.checked;
      pfNature.addEventListener('change', () => { professionGroup.hidden = !pfNature.checked; });
      card.append(professionGroup);

      const fields = $('div', 'law-contact-check-grid');
      (result.share_fields && Object.entries(result.share_fields) || []).forEach(([code, label]) => { const wrap = $('label', 'law-contact-check'); const check = $('input'); check.type = 'checkbox'; check.value = code; check.checked = (policy?.shared_fields || []).includes(code); check.dataset.shareField = '1'; wrap.append(check, $('span', '', label)); fields.append(wrap); });
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
          legal_natures: [...card.querySelectorAll('[data-share-nature]:checked')].map((check) => check.value),
          profession_names: card.querySelector('[data-share-nature][value="pf"]:checked')
            ? [...card.querySelectorAll('[data-share-profession]:checked')].map((check) => check.value)
            : [],
          shared_fields: [...card.querySelectorAll('[data-share-field]:checked')].map((check) => check.value),
        };
      });
      const incompleteScope = policiesToSave.some((policy) => policy.legal_natures.length === 0 || (policy.legal_natures.includes('pf') && policy.profession_names.length === 0));
      if (incompleteScope) {
        message.dataset.state = 'error'; message.textContent = 'Escolha ao menos uma natureza e, para pessoas físicas, uma profissão vinculada a um contato.'; save.disabled = false; return;
      }
      try { await window.FokusApi.request('/law/contact-sharing', { method: 'PUT', body: { policies: policiesToSave } }); modal.close(); }
      catch (error) { message.dataset.state = 'error'; message.textContent = error.message || 'Não foi possível salvar as regras de compartilhamento.'; }
      finally { save.disabled = false; }
    });
    modal.body.append(form);
  }

  window.FokusLawContacts = {
    render,
    openContact: (root, id, opener = null) => openDetails(root, id, false, () => {}, opener),
    openSearchedContact: (root, id, isShared, opener = null) => openDetails(root, id, isShared, () => render(root, context), opener),
  };
})();

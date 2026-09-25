(() => {
  const ICON_ROOT = '/backoffice/assets/icons/';
  const ICONS = {
    overview: 'Layout-Dashboard--Streamline-Ultimate.png',
    settings: 'Cog--Streamline-Ultimate.png',
    processes: 'Folder-File--Streamline-Ultimate.png',
    contacts: 'Customer-Relationship-Management-List-Document-Share--Streamline-Ultimate.png',
    filings: 'Common-File-Quill--Streamline-Ultimate.png',
    hearings: 'Alarm-Bell--Streamline-Ultimate-Regular.png',
    tasks: 'Single-Man-Actions-Time--Streamline-Ultimate.png',
    reports: 'Layout-Dashboard-1--Streamline-Ultimate.png',
    profile: 'Settings-User--Streamline-Ultimate.png',
    company: 'Small-Office-Tall-Building--Streamline-Ultimate.png',
    users: 'Programming-User-Chat--Streamline-Ultimate.png',
  };
  const MODULES = {
    processos: { label: 'Processos', icon: 'processes' },
    contatos: { label: 'Contatos', icon: 'contacts' },
    expedicoes: { label: 'Expedientes', icon: 'filings' },
    audiencias: { label: 'Audiências', icon: 'hearings' },
    tarefas: { label: 'Tarefas e prazos', icon: 'tasks' },
    relatorios: { label: 'Relatórios', icon: 'reports' },
  };
  const shell = document.querySelector('#law-shell');
  const loading = document.querySelector('#shell-loading');
  const railItems = document.querySelector('#rail-items');
  const pageItems = document.querySelector('#page-items');
  const sectionTitle = document.querySelector('#section-title');
  const contentRegion = document.querySelector('#content-region');
  const sidebar = document.querySelector('#law-sidebar');
  const mobileButton = document.querySelector('#mobile-menu-button');
  const mobileScrim = document.querySelector('#mobile-scrim');
  const preferenceKey = (userId, key) => `fokus-law:${userId}:${key}`;
  let context = null;
  const initialPage = shell.dataset.initialPage || 'overview';
  let settingsView = ['company', 'subscription'].includes(initialPage) ? initialPage : 'settings';
  let activeGroup = ['company', 'subscription'].includes(initialPage) ? 'settings' : 'overview';

  const element = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };

  function icon(name, className = '') {
    const image = document.createElement('img');
    image.src = ICON_ROOT + (ICONS[name] || ICONS.overview);
    image.alt = '';
    if (className) image.className = className;
    return image;
  }

  function getModuleDescriptor(module) {
    const family = String(module.family || module.code || '').toLowerCase();
    const known = MODULES[family] || Object.entries(MODULES).find(([key]) => family.startsWith(`${key}-`))?.[1];
    return known || { label: module.name, icon: 'overview' };
  }

  function closeMobileNav(returnFocus = false) {
    const wasOpen = sidebar.classList.contains('is-open');
    sidebar.classList.remove('is-open');
    mobileScrim.hidden = true;
    mobileButton.setAttribute('aria-expanded', 'false');
    mobileButton.setAttribute('aria-label', 'Abrir menu');
    if (returnFocus && wasOpen) mobileButton.focus();
  }

  function addRailButton(group, label, iconName) {
    const button = element('button', 'law-rail-button');
    button.type = 'button';
    button.dataset.group = group;
    button.setAttribute('aria-label', label);
    button.title = label;
    button.append(icon(iconName));
    const tooltip = element('span', 'law-rail-tooltip', label);
    tooltip.setAttribute('aria-hidden', 'true');
    button.append(tooltip);
    button.addEventListener('click', () => {
      activeGroup = group;
      if (group === 'settings') settingsView = 'settings';
      renderNavigation();
      closeMobileNav();
      contentRegion.focus({ preventScroll: true });
    });
    railItems.append(button);
  }

  function activeModule() {
    return context.modules.find((item) => item.id === activeGroup.slice('module:'.length));
  }

  function renderRail() {
    railItems.replaceChildren();
    addRailButton('overview', 'Visão geral', 'overview');
    context.modules.forEach((module) => {
      const descriptor = getModuleDescriptor(module);
      addRailButton(`module:${module.id}`, descriptor.label, descriptor.icon);
    });
    if (context.permissions.manage_settings) addRailButton('settings', 'Configurações', 'settings');
  }

  function appendNavLink(container, label, href, iconName, selected = false, disabled = false) {
    const link = element('a', 'law-nav-item');
    if (!disabled) link.href = href;
    if (selected) link.setAttribute('aria-current', 'page');
    link.append(icon(iconName));
    link.append(element('span', '', label));
    if (disabled) {
      link.classList.add('disabled');
      link.setAttribute('aria-disabled', 'true');
      link.addEventListener('click', (event) => event.preventDefault());
    }
    container.append(link);
  }

  function appendNavButton(container, label, iconName, selected, onClick, disabled = false) {
    const button = element('button', 'law-nav-item');
    button.type = 'button';
    button.disabled = disabled;
    if (disabled) {
      button.classList.add('disabled');
      button.setAttribute('aria-disabled', 'true');
    }
    if (selected) button.setAttribute('aria-current', 'page');
    button.append(icon(iconName));
    button.append(element('span', '', label));
    if (!disabled) button.addEventListener('click', onClick);
    container.append(button);
  }

  function renderNavigation() {
    let headingIcon = 'overview';
    railItems.querySelectorAll('[data-group]').forEach((button) => {
      if (button.dataset.group === activeGroup) button.setAttribute('aria-current', 'page');
      else button.removeAttribute('aria-current');
    });
    pageItems.replaceChildren();

    if (activeGroup === 'overview') {
      sectionTitle.textContent = 'Visão geral';
      document.querySelector('#section-icon').src = ICON_ROOT + ICONS.overview;
      appendNavButton(pageItems, 'Dashboard', 'overview', initialPage !== 'profile', () => window.location.assign('/portal/fokus-law'));
      appendNavLink(pageItems, 'Meu perfil', '/portal/fokus-law/perfil', 'profile', initialPage === 'profile');
      appendNavButton(pageItems, 'Preferências do Fokus Law', 'settings', settingsView === 'preferences', () => { settingsView = 'preferences'; renderContent('preferences'); });
      renderContent(initialPage === 'profile' ? 'profile' : settingsView === 'preferences' ? 'preferences' : 'overview');
      return;
    }

    if (activeGroup === 'settings') {
      if (!context.permissions.manage_settings) {
        activeGroup = 'overview';
        renderNavigation();
        return;
      }
      sectionTitle.textContent = 'Configurações';
      headingIcon = 'settings';
      document.querySelector('#section-icon').src = ICON_ROOT + ICONS[headingIcon];
      const list = element('ul');
      const entries = [
        ['Empresa', '/portal/fokus-law/empresa', 'company'],
        ['Assinatura', '/portal/fokus-law/assinatura', 'settings'],
      ];
      if (context.permissions.manage_company_users) entries.push(['Usuários, perfis e permissões', '/portal/usuarios', 'users']);
      entries.forEach(([label, href, iconName]) => {
        const item = element('li');
        appendNavLink(item, label, href, iconName);
        list.append(item);
      });
      const unitsItem = element('li');
      appendNavButton(unitsItem, 'Setores da empresa', 'company', settingsView === 'units', () => { settingsView = 'units'; renderNavigation(); });
      list.append(unitsItem);
      pageItems.append(list);
      renderContent(settingsView === 'units' ? 'units' : settingsView === 'company' ? 'company' : settingsView === 'subscription' ? 'subscription' : 'settings');
      return;
    }

    const module = activeModule();
    if (!module) {
      activeGroup = 'overview';
      renderNavigation();
      return;
    }
    const descriptor = getModuleDescriptor(module);
    sectionTitle.textContent = descriptor.label;
    headingIcon = descriptor.icon;
    appendNavButton(pageItems, `Visão geral de ${descriptor.label}`, descriptor.icon, true, () => renderContent('module'), true);
    pageItems.append(element('p', 'law-nav-description', 'As páginas funcionais deste módulo serão adicionadas aqui.'));
    renderContent('module');
    document.querySelector('#section-icon').src = ICON_ROOT + ICONS[headingIcon];
  }

  function renderOverview() {
    const hasModules = context.modules.length > 0;
    const heading = element('div');
    heading.append(element('p', 'law-page-eyebrow', 'ESPAÇO DE TRABALHO'));
    heading.append(element('h2', '', 'Visão geral'));
    heading.append(element('p', 'law-page-lede', 'Acompanhe o contexto da sua empresa e acesse os módulos disponíveis no Fokus Law.'));
    contentRegion.append(heading);

    const welcome = element('section', 'law-welcome-card');
    const copy = element('div', 'law-welcome-copy');
    copy.append(element('h3', '', hasModules ? 'Seu Fokus Law está pronto para receber seu trabalho.' : 'Seu espaço Fokus Law está pronto.'));
    copy.append(element('p', '', hasModules
      ? 'Os módulos habilitados para sua empresa aparecem na navegação lateral. Nesta etapa, suas áreas funcionais ainda estão sendo preparadas.'
      : 'Quando módulos forem contratados e habilitados para sua empresa, eles aparecerão na navegação lateral.'));
    welcome.append(copy);
    const iconBox = element('div', 'law-welcome-icon');
    iconBox.append(icon('overview'));
    welcome.append(iconBox);
    contentRegion.append(welcome);

    const grid = element('div', 'law-info-grid');
    const cards = [
      ['Empresa ativa', context.company.display_name || context.company.legal_name || context.company.name],
      ['Assinatura', context.subscription?.label || 'Nenhuma assinatura ativa'],
      ['Módulos habilitados', String(context.modules.length)],
    ];
    cards.forEach(([label, value]) => {
      const card = element('article', 'law-info-card');
      card.append(element('span', '', label));
      card.append(element('strong', '', value));
      grid.append(card);
    });
    contentRegion.append(grid);
  }

  function renderSettings() {
    const heading = element('div');
    heading.append(element('p', 'law-page-eyebrow', 'ADMINISTRAÇÃO DA EMPRESA'));
    heading.append(element('h2', '', 'Configurações'));
    heading.append(element('p', 'law-page-lede', 'Gerencie a empresa, a assinatura, os usuários, os perfis, as permissões e os setores do Fokus Law.'));
    contentRegion.append(heading);

    const summaryHeading = element('div', 'law-settings-summary-heading');
    summaryHeading.append(element('h3', '', 'Resumo das configurações'));
    summaryHeading.append(element('p', '', 'Informações da empresa ativa, para consulta.'));
    contentRegion.append(summaryHeading);
    const summaries = element('div', 'law-settings-summary-grid');
    summaries.setAttribute('aria-live', 'polite');
    summaries.append(element('p', 'law-settings-summary-loading', 'Carregando resumos…'));
    contentRegion.append(summaries);
    loadSettingsSummaries(summaries);

    const list = element('div', 'law-settings-list');
    const managementHeading = element('div', 'law-settings-summary-heading');
    managementHeading.append(element('h3', '', 'Acessar configurações'));
    managementHeading.append(element('p', '', 'Abra uma área para consultar ou administrar seus dados.'));
    contentRegion.append(managementHeading);
    const links = [
      ['Empresa', 'Consulte e configure a empresa ativa.', '/portal/fokus-law/empresa', 'company'],
      ['Assinatura', 'Consulte e gerencie a assinatura do Fokus Law.', '/portal/fokus-law/assinatura', 'settings'],
    ];
    if (context.permissions.manage_company_users) links.push(['Usuários, perfis e permissões', 'Gerencie os vínculos e os acessos das pessoas da empresa.', '/portal/usuarios', 'users']);
    links.forEach(([title, description, href, iconName]) => {
      const link = element('a', 'law-settings-link');
      link.href = href;
      link.append(icon(iconName));
      const text = element('span');
      text.append(element('strong', '', title));
      text.append(element('small', '', description));
      link.append(text);
      link.append(element('span', '', '›'));
      list.append(link);
    });
    const units = element('button', 'law-settings-link law-settings-action');
    units.type = 'button';
    units.append(icon('company'));
    const unitText = element('span');
    unitText.append(element('strong', '', 'Setores da empresa'));
    unitText.append(element('small', '', 'Cadastre os setores e escolha quais ficam ativos no Fokus Law.'));
    units.append(unitText, element('span', '', '›'));
    units.addEventListener('click', () => { settingsView = 'units'; renderNavigation(); });
    list.append(units);
    contentRegion.append(list);
  }

  async function loadSettingsSummaries(grid) {
    const [companyResult, usersResult, unitsResult] = await Promise.allSettled([
      FokusApi.request('/law/company-profile'),
      FokusApi.request('/portal/users'),
      FokusApi.request('/law/units'),
    ]);
    if (!grid.isConnected) return;
    grid.replaceChildren();

    const makeSummary = (title, iconName, className, lines) => {
      const card = element('article', `law-settings-summary-card ${className}`);
      const header = element('div', 'law-settings-summary-card-heading');
      const iconBox = element('span', 'law-settings-summary-icon');
      iconBox.append(icon(iconName));
      header.append(iconBox, element('h4', '', title));
      card.append(header);
      lines.forEach((line, index) => card.append(element(index === 0 ? 'strong' : 'p', index === 0 ? 'law-settings-summary-primary' : 'law-settings-summary-secondary', line)));
      return card;
    };

    const company = companyResult.status === 'fulfilled' ? companyResult.value.company : null;
    grid.append(makeSummary('Dados da empresa', 'company', 'law-summary-company', company
      ? [company.display_name || company.legal_name, company.legal_name, `${formatDocument(company.document_type, company.document_number)} · ${companyStatus(company.status)}`]
      : ['Dados indisponíveis', 'Não foi possível carregar os dados da empresa.']));

    grid.append(makeSummary('Assinatura', 'settings', 'law-summary-subscription', context.subscription
      ? [context.subscription.label || context.subscription.plan_name || 'Fokus Law', `${context.subscription.product_name || 'Produto'} · Ativa`, `${context.modules.length} módulo(s) habilitado(s)`]
      : ['Sem assinatura ativa', 'Nenhuma assinatura do Fokus Law está ativa.']));

    const users = usersResult.status === 'fulfilled' && Array.isArray(usersResult.value) ? usersResult.value : null;
    grid.append(makeSummary('Usuários', 'users', 'law-summary-users', users
      ? [`${users.length} usuário(s) vinculado(s)`, `${users.filter((user) => user.status === 'ativo').length} ativo(s)`, `${users.filter((user) => user.role === 'admin').length} administrador(es)`]
      : ['Resumo indisponível', 'Não foi possível carregar os vínculos de usuários.']));

    const units = unitsResult.status === 'fulfilled' ? unitsResult.value.units : null;
    grid.append(makeSummary('Setores da empresa', 'company', 'law-summary-units', Array.isArray(units)
      ? [`${units.length} setor(es) cadastrado(s)`, `${units.filter((unit) => unit.status === 'ativo').length} ativo(s)`, context.active_unit?.name ? `Setor selecionado: ${context.active_unit.name}` : 'Nenhum setor selecionado']
      : ['Resumo indisponível', 'Não foi possível carregar os setores.']));
  }

  function formatDocument(type, number) {
    const digits = String(number || '').replace(/\D/g, '');
    if (type === 'cpf' && digits.length === 11) return digits.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
    if (type === 'cnpj' && digits.length === 14) return digits.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
    return digits || 'Documento não informado';
  }

  function companyStatus(status) {
    return ({ ativa: 'Ativa', pendente: 'Pendente', suspensa: 'Suspensa', encerrando: 'Encerrando', encerrada: 'Encerrada' })[status] || status || 'Não informada';
  }

  async function renderCompany(successMessage = '') {
    contentRegion.replaceChildren();
    const heading = element('div', 'law-company-heading');
    heading.append(element('p', 'law-page-eyebrow', 'ADMINISTRAÇÃO DA EMPRESA'));
    heading.append(element('h2', '', 'Dados da empresa'));
    heading.append(element('p', 'law-page-lede', 'Consulte e mantenha os dados institucionais da empresa ativa no Fokus Law.'));
    contentRegion.append(heading);

    const feedback = element('p', 'law-company-feedback');
    feedback.setAttribute('role', 'status');
    feedback.textContent = successMessage;
    const sections = element('div', 'law-company-sections');
    sections.setAttribute('aria-busy', 'true');
    sections.append(element('p', 'law-profile-loading', 'Carregando dados da empresa…'));
    contentRegion.append(feedback, sections);

    let profile;
    try {
      profile = await FokusApi.request('/law/company-profile');
    } catch (error) {
      sections.replaceChildren();
      sections.setAttribute('aria-busy', 'false');
      const message = element('p', 'law-profile-loading', error.message || 'Não foi possível carregar os dados da empresa.');
      message.dataset.state = 'error';
      sections.append(message);
      return;
    }

    sections.replaceChildren();

    const labels = {
      legal_name: 'Razão social / nome legal', display_name: 'Nome de exibição institucional', document: 'CPF/CNPJ', status: 'Situação',
      contact_email: 'E-mail institucional', contact_phone: 'Telefone institucional', website: 'Site',
      address_postal_code: 'CEP', address_street: 'Logradouro', address_number: 'Número', address_complement: 'Complemento',
      address_district: 'Bairro', address_city: 'Cidade', address_state: 'UF',
    };
    const groups = [
      ['Identificação', ['legal_name', 'display_name', 'document', 'status']],
      ['Contato institucional', ['contact_email', 'contact_phone', 'website']],
      ['Endereço', ['address_postal_code', 'address_street', 'address_number', 'address_complement', 'address_district', 'address_city', 'address_state']],
    ];
    const cardNodes = [];
    groups.forEach(([title, fields]) => {
      const card = element('section', 'law-company-card');
      card.append(element('h3', '', title));
      const details = element('dl', 'law-company-details');
      fields.forEach((field) => {
        const row = element('div', 'law-company-detail');
        row.append(element('dt', '', labels[field]));
        const value = field === 'document' ? formatDocument(profile.company.document_type, profile.company.document_number)
        : field === 'status' ? companyStatus(profile.company.status) : profile.company[field];
        row.append(element('dd', '', value || 'Não informado'));
        details.append(row);
      });
      card.append(details);
      sections.append(card);
      cardNodes.push(card);
    });

    const actions = element('div', 'law-company-actions');
    const edit = element('button', 'fs-btn fs-btn-primary', 'Editar dados');
    edit.type = 'button';
    edit.hidden = Boolean(context.support_mode);
    actions.append(edit);
    sections.append(actions);

    const historyCard = element('section', 'law-company-card law-company-history');
    historyCard.append(element('h3', '', 'Histórico de alterações'));
    const history = element('ol', 'law-company-history-list');
    if (!profile.audit.length) history.append(element('li', '', 'Nenhuma alteração registrada.'));
    profile.audit.forEach((entry) => {
      const item = element('li');
      const fields = Object.keys(entry.after || {}).map((field) => labels[field] || field).join(', ');
      item.append(element('strong', '', `${entry.actor_name} · ${new Date(entry.created_at.replace(' ', 'T')).toLocaleString('pt-BR')}`));
      item.append(element('span', '', `Alterou: ${fields || 'dados da empresa'}`));
      history.append(item);
    });
    historyCard.append(history);
    sections.append(historyCard);
    sections.setAttribute('aria-busy', 'false');

    edit.addEventListener('click', () => {
      const form = element('form', 'law-company-form');
      const fields = [
        ['legal_name', 'Razão social / nome legal', 'text', 'fs-width-700'],
        ['display_name', 'Nome de exibição institucional', 'text', 'fs-width-700'],
        ['contact_email', 'E-mail institucional', 'email', 'fs-width-700'],
        ['contact_phone', 'Telefone institucional', 'tel', 'fs-width-400'],
        ['website', 'Site', 'url', 'fs-width-700'],
        ['address_postal_code', 'CEP', 'text', 'fs-width-300'],
        ['address_street', 'Logradouro', 'text', 'fs-width-700'],
        ['address_number', 'Número', 'text', 'fs-width-300'],
        ['address_complement', 'Complemento', 'text', 'fs-width-400'],
        ['address_district', 'Bairro', 'text', 'fs-width-400'],
        ['address_city', 'Cidade', 'text', 'fs-width-500'],
        ['address_state', 'UF', 'text', 'fs-width-200'],
      ];
      const fieldset = element('fieldset', 'law-company-form-fields');
      fieldset.append(element('legend', '', 'Dados institucionais'));
      fields.forEach(([name, labelText, type, width]) => {
        const label = element('label', 'law-company-field');
        label.append(element('span', '', labelText));
        const input = element('input', `fs-form-control ${width}`);
        input.name = name;
        input.type = type;
        input.maxLength = name === 'address_complement' ? 100 : 255;
        input.value = profile.company[name] || '';
        input.autocomplete = ({ contact_email: 'email', contact_phone: 'tel', address_postal_code: 'postal-code', address_street: 'street-address', address_city: 'address-level2', address_state: 'address-level1' })[name] || 'off';
        if (name === 'legal_name') input.required = true;
        if (name === 'address_postal_code') { input.inputMode = 'numeric'; input.placeholder = '00000-000'; }
        if (name === 'address_state') { input.maxLength = 2; input.placeholder = 'BA'; }
        label.append(input);
        label.append(element('small', 'law-company-field-error'));
        fieldset.append(label);
      });
      const readonly = element('p', 'law-company-readonly', `${labels.document}: ${formatDocument(profile.company.document_type, profile.company.document_number)} · Situação: ${companyStatus(profile.company.status)}`);
      const formFeedback = element('p', 'law-company-feedback');
      formFeedback.setAttribute('role', 'alert');
      const footer = element('div', 'law-company-form-footer');
      const cancel = element('button', 'fs-btn fs-btn-outline-primary', 'Cancelar');
      cancel.type = 'button';
      const save = element('button', 'fs-btn fs-btn-primary', 'Salvar alterações');
      save.type = 'submit';
      footer.append(cancel, save);
      form.append(fieldset, readonly, formFeedback, footer);
      sections.replaceChildren(form, historyCard);
      cancel.addEventListener('click', () => renderCompany());
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        save.disabled = true;
        formFeedback.textContent = 'Salvando…';
        const body = Object.fromEntries(new FormData(form).entries());
        body.version = profile.company.version;
        if (body.address_state) body.address_state = body.address_state.toUpperCase();
        try {
          const result = await FokusApi.request('/law/company-profile', { method: 'PATCH', body });
          context.company.legal_name = result.company.legal_name;
          context.company.display_name = result.company.display_name || result.company.legal_name;
          await renderCompany(result.message || 'Dados da empresa atualizados.');
        } catch (error) {
          save.disabled = false;
          formFeedback.textContent = error.status === 409 ? `${error.message} Cancele a edição para recarregar os dados atuais.` : error.message || 'Não foi possível salvar os dados.';
          Object.entries(error.errors || {}).forEach(([field, messages]) => {
            const input = form.elements.namedItem(field);
            if (!input || !messages?.[0]) return;
            input.setAttribute('aria-invalid', 'true');
            const errorMessage = input.parentElement.querySelector('.law-company-field-error');
            errorMessage.textContent = messages[0];
            errorMessage.id = `law-company-error-${field}`;
            input.setAttribute('aria-describedby', errorMessage.id);
          });
        }
      });
    });
  }

  function renderPreferences() {
    const heading = element('div');
    heading.append(element('p', 'law-page-eyebrow', 'CONFIGURAÇÕES DA EXPERIÊNCIA'));
    heading.append(element('h2', '', 'Preferências do Fokus Law'));
    heading.append(element('p', 'law-page-lede', 'Estas preferências são salvas neste navegador. Alertas e busca serão conectados às fontes dos módulos quando estiverem disponíveis.'));
    contentRegion.append(heading);

    const card = element('section', 'law-preference-card');
    card.append(element('p', '', 'Ajuste a navegação e o movimento da interface para este dispositivo.'));
    const preferences = [
      ['remember-group', 'Lembrar o último grupo aberto', 'Manter o grupo selecionado ao retornar ao Fokus Law.'],
      ['reduced-motion', 'Reduzir animações', 'Usar transições reduzidas nesta interface.'],
    ];
    preferences.forEach(([key, label, description]) => {
      const row = element('div', 'law-preference-row');
      const text = element('div');
      const inputId = `pref-${key}`;
      const labelElement = element('label');
      labelElement.htmlFor = inputId;
      labelElement.append(element('strong', '', label));
      labelElement.append(element('br'));
      labelElement.append(element('small', '', description));
      text.append(labelElement);
      const input = document.createElement('input');
      input.id = inputId;
      input.type = 'checkbox';
      input.checked = localStorage.getItem(preferenceKey(context.user.id, key)) === 'true';
      input.addEventListener('change', () => {
        localStorage.setItem(preferenceKey(context.user.id, key), String(input.checked));
        if (key === 'reduced-motion') document.documentElement.classList.toggle('law-pref-reduced-motion', input.checked);
      });
      row.append(text, input);
      card.append(row);
    });
    contentRegion.append(card);
  }

  async function refreshUnits() {
    const result = await FokusApi.request('/law/units');
    context.units = result.units;
    context.active_unit_id = result.active_unit_id;
    context.active_unit = result.active_unit;
    renderUnitOptions();
    return result;
  }

  async function renderUnits() {
    const heading = element('div');
    heading.append(element('p', 'law-page-eyebrow', 'ORGANIZAÇÃO DA EMPRESA'));
    heading.append(element('h2', '', 'Setores do sistema'));
    heading.append(element('p', 'law-page-lede', 'Crie setores para organizar o espaço de trabalho. Cada pessoa escolhe seu setor ativo no seletor da navegação.'));
    contentRegion.append(heading);

    const card = element('section', 'law-units-card');
    const form = element('form', 'law-unit-form');
    const label = element('label', '', 'Nome do setor');
    const input = element('input', 'fs-form-control');
    input.name = 'name';
    input.required = true;
    input.maxLength = 100;
    input.minLength = 2;
    input.placeholder = 'Ex.: Cartório Cível';
    label.append(input);
    const submit = element('button', 'fs-btn fs-btn-primary', 'Adicionar setor');
    submit.type = 'submit';
    form.append(label, submit);
    const status = element('p', 'law-units-status');
    status.setAttribute('role', 'status');
    const list = element('div', 'law-unit-list');
    card.append(form, status, list);
    contentRegion.append(card);

    const drawList = (units) => {
      list.replaceChildren();
      if (!units.length) {
        list.append(element('p', 'law-units-empty', 'Nenhum setor cadastrado.'));
        return;
      }
      units.forEach((unit) => {
        const row = element('div', 'law-unit-row');
        const meta = element('div', 'law-unit-meta');
        meta.append(element('strong', '', unit.name));
        meta.append(element('span', '', unit.status === 'ativo' ? 'Ativo' : 'Inativo'));
        const toggle = element('button', 'fs-btn fs-btn-outline-primary', unit.status === 'ativo' ? 'Desativar' : 'Reativar');
        toggle.type = 'button';
        toggle.addEventListener('click', async () => {
          toggle.disabled = true;
          try {
            await FokusApi.request(`/law/units/${encodeURIComponent(unit.id)}`, { method: 'PATCH', body: { status: unit.status === 'ativo' ? 'inativo' : 'ativo' } });
            const result = await refreshUnits();
            drawList(result.units);
            status.textContent = 'Setor atualizado.';
          } catch (error) {
            toggle.disabled = false;
            status.textContent = error.message || 'Não foi possível atualizar o setor.';
          }
        });
        row.append(meta, toggle);
        list.append(row);
      });
    };

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      submit.disabled = true;
      status.textContent = '';
      try {
        await FokusApi.request('/law/units', { method: 'POST', body: { name: input.value.trim() } });
        input.value = '';
        const result = await refreshUnits();
        drawList(result.units);
        status.textContent = 'Setor criado.';
        input.focus();
      } catch (error) {
        status.textContent = error.message || 'Não foi possível criar o setor.';
      } finally {
        submit.disabled = false;
      }
    });

    try {
      const result = await refreshUnits();
      drawList(result.units);
    } catch (error) {
      status.textContent = error.message || 'Não foi possível carregar os setores.';
    }
  }

  function renderModulePlaceholder(module) {
    if (String(module.family || module.module_code || module.code || '').toLowerCase().startsWith('contatos')) {
      renderContacts();
      return;
    }
    const descriptor = getModuleDescriptor(module);
    const heading = element('div');
    heading.append(element('p', 'law-page-eyebrow', 'MÓDULO HABILITADO'));
    heading.append(element('h2', '', descriptor.label));
    heading.append(element('p', 'law-page-lede', 'Este espaço segue a navegação oficial do Fokus Law e receberá as páginas funcionais do módulo em uma etapa própria.'));
    contentRegion.append(heading);
    const state = element('section', 'law-module-state');
    state.append(element('strong', '', `${descriptor.label} está habilitado para sua empresa.`));
    state.append(element('p', '', 'A interface funcional deste módulo ainda não faz parte desta entrega.'));
    contentRegion.append(state);
  }

  async function renderContacts() {
    document.title = 'Contatos | Fokus Law';
    const heading = element('div'); heading.append(element('p', 'law-page-eyebrow', 'GESTÃO DE CONTATOS'), element('h2', '', 'Contatos'));
    heading.append(element('p', 'law-page-lede', 'Cadastre os contatos da empresa e consulte a capacidade contratada para todos os setores.'));
    contentRegion.append(heading);
    const usage = element('div', 'law-subscription-usage'); usage.textContent = 'Carregando capacidade…'; contentRegion.append(usage);
    const form = element('form', 'law-contact-form');
    const nameField = lawSubscriptionField('Nome de exibição'); const name = element('input', 'fs-form-control'); name.required = true; name.maxLength = 180; nameField.append(name);
    const typeField = lawSubscriptionField('Tipo de contato'); const type = element('select', 'fs-form-control'); [['person','Pessoa'],['organization','Organização'],['lawyer','Advogado(a)'],['law_firm','Escritório'],['public_body','Órgão público'],['court_unit','Unidade judiciária'],['unknown','Outro']].forEach(([value,label])=>type.append(new Option(label,value))); typeField.append(type);
    const sectorField = lawSubscriptionField('Setor'); const sector = element('select', 'fs-form-control'); sector.append(new Option('Empresa toda', '')); (context.units || []).filter((unit)=>unit.status==='ativo').forEach((unit)=>sector.append(new Option(unit.name, unit.id))); if (context.active_unit_id) sector.value = context.active_unit_id; sectorField.append(sector);
    const submit = element('button','fs-btn fs-btn-primary','Cadastrar contato'); submit.type='submit'; const formStatus=element('p','law-subscription-feedback'); formStatus.setAttribute('role','status');
    form.append(nameField,typeField,sectorField,submit,formStatus); contentRegion.append(form);
    const list = element('div','law-contact-list'); contentRegion.append(list);
    const refresh = async () => {
      const result = await FokusApi.request('/law/contacts');
      if (!contentRegion.isConnected || contentRegion.dataset.view !== 'module') return;
      if (context.permissions.manage_settings) loadLawNotifications(document.querySelector('#notifications-button'), document.querySelector('#notifications-panel'));
      list.replaceChildren();
      const metric = result.usage;
      if (metric?.available) { usage.textContent=`${metric.label}: ${metric.used.toLocaleString('pt-BR')} de ${metric.limit.toLocaleString('pt-BR')} (${metric.percentage}%)`; usage.dataset.state=metric.over_threshold?'warning':'normal'; if(metric.over_threshold) usage.append(element('strong','',' Acima de 70%: avalie um upgrade em Configurações > Assinatura.')); }
      else usage.textContent = 'A capacidade contratada para contatos não está configurada nesta assinatura.';
      if (!result.contacts.length) { list.append(element('p','law-module-state','Nenhum contato cadastrado neste setor.')); return; }
      result.contacts.forEach((contact)=>{ const card=element('article','law-contact-card'); const info=element('div'); info.append(element('strong','',contact.display_name),element('span','',`${contact.legal_name || contact.contact_type} · ${contact.unit_name || 'Empresa toda'} · ${contact.status}`)); const archive=element('button','fs-btn fs-btn-secondary','Remover'); archive.type='button'; archive.addEventListener('click',async()=>{ archive.disabled=true; try { await FokusApi.request(`/law/contacts/${encodeURIComponent(contact.id)}`,{method:'DELETE'}); await refresh(); } catch(error){ formStatus.dataset.state='error'; formStatus.textContent=error.message; archive.disabled=false; } }); card.append(info,archive); list.append(card); });
    };
    form.addEventListener('submit',async(event)=>{ event.preventDefault(); submit.disabled=true; formStatus.textContent=''; try { await FokusApi.request('/law/contacts',{method:'POST',body:{display_name:name.value.trim(),contact_type:type.value,law_unit_id:sector.value||null}}); name.value=''; formStatus.textContent='Contato cadastrado.'; await refresh(); name.focus(); } catch(error){ formStatus.dataset.state='error'; formStatus.textContent=error.message||'Não foi possível cadastrar.'; } finally{ submit.disabled=false; } });
    try { await refresh(); } catch(error){ usage.dataset.state='error'; usage.textContent=error.message||'Não foi possível carregar os contatos.'; }
  }

  async function renderSubscription() {
    document.title = 'Assinatura | Fokus Law';
    const heading = element('div');
    heading.append(element('p', 'law-page-eyebrow', 'PLANO E RECURSOS'));
    heading.append(element('h2', '', 'Assinatura'));
    heading.append(element('p', 'law-page-lede', 'Consulte o plano, os módulos e os limites contratados. Compare opções e solicite alterações para a empresa ativa.'));
    contentRegion.append(heading);
    const feedback = element('p', 'law-subscription-feedback', 'Carregando assinatura e catálogo…');
    feedback.setAttribute('role', 'status');
    contentRegion.append(feedback);
    try {
      const data = await FokusApi.request('/law/subscription');
      if (!contentRegion.isConnected || contentRegion.dataset.view !== 'subscription') return;
      if (context.permissions.manage_settings) loadLawNotifications(document.querySelector('#notifications-button'), document.querySelector('#notifications-panel'));
      feedback.remove();
      drawSubscription(data);
    } catch (error) {
      feedback.dataset.state = 'error';
      feedback.textContent = error.message || 'Não foi possível carregar os dados da assinatura.';
    }
  }

  function drawSubscription(data) {
    const current = data.subscription;
    const catalog = data.catalog || {};
    const plans = catalog.plans || [];
    const modules = catalog.modules || [];
    if (!current) {
      const empty = element('section', 'law-module-state');
      empty.append(element('strong', '', 'Nenhuma assinatura aberta do Fokus Law.'));
      empty.append(element('p', '', 'A contratação inicial pode ser feita pelo portal de assinaturas.'));
      const link = element('a', 'fs-btn fs-btn-primary', 'Ver opções de assinatura'); link.href = '/produtos/fokus-law'; empty.append(link);
      contentRegion.append(empty); return;
    }

    const currentItems = new Map((current.items || []).map((item) => [item.conditions?.module_code || item.module_code || item.family, item]));
    const pendingChange = data.pending_change;
    const summary = element('section', 'law-subscription-overview');
    summary.append(element('p', 'law-page-eyebrow', 'ASSINATURA ATUAL'));
    summary.append(element('h3', '', current.plan_name || 'Composição personalizada'));
    const price = element('strong', 'law-subscription-price', formatLawMoney(current.amount));
    price.append(element('span', '', current.billing_cycle === 'annual' ? ' / ano' : ' / mês')); summary.append(price);
    const facts = element('div', 'law-subscription-facts');
    [['Situação', current.status], ['Ciclo', current.billing_cycle === 'annual' ? 'Anual' : 'Mensal'], ['Período até', formatLawDate(current.current_period_ends_at)]].forEach(([label, value]) => {
      const fact = element('div', 'law-subscription-fact'); fact.append(element('span', '', label), element('strong', '', value || '—')); facts.append(fact);
    });
    summary.append(facts); contentRegion.append(summary);

    const form = element('form', 'law-subscription-builder');
    form.append(element('h3', '', 'Planos, módulos e capacidades'));
    form.append(element('p', 'law-page-lede', 'As funcionalidades são informativas. Você pode trocar o plano ou compor os módulos e limites da assinatura.'));
    const controls = element('div', 'law-subscription-controls');
    const planWrap = lawSubscriptionField('Plano publicado');
    const planSelect = element('select', 'fs-form-control'); planSelect.name = 'target_plan_id'; planSelect.append(new Option('Composição personalizada', ''));
    plans.forEach((plan) => planSelect.append(new Option(`${plan.name} · ${formatLawMoney(plan.monthly_amount)}/mês`, plan.id)));
    planSelect.value = plans.find((plan) => plan.code === current.plan_code)?.id || '';
    planWrap.append(planSelect); controls.append(planWrap);
    const cycleWrap = lawSubscriptionField('Forma de cobrança');
    const cycleSelect = element('select', 'fs-form-control'); cycleSelect.append(new Option('Mensal', 'monthly'), new Option('Anual', 'annual')); cycleSelect.value = current.billing_cycle || 'monthly'; cycleWrap.append(cycleSelect); controls.append(cycleWrap);
    form.append(controls);

    const moduleGrid = element('div', 'law-subscription-module-grid');
    const moduleControls = new Map();
    modules.forEach((module) => {
      const card = element('article', 'law-subscription-module-card');
      const label = element('label', 'law-subscription-module-title');
      const checkbox = element('input'); checkbox.type = 'checkbox'; checkbox.value = module.code; checkbox.checked = currentItems.has(module.code);
      label.append(checkbox, element('strong', '', module.name)); card.append(label);
      if (module.description) card.append(element('p', 'law-subscription-module-description', module.description));
      const capabilities = module.capability_items || (module.capabilities || []).map((name) => ({ name }));
      if (capabilities.length) { const list = element('ul', 'law-subscription-features'); capabilities.forEach((feature) => list.append(element('li', '', feature.name))); card.append(list); }
      const personalizationBox = element('div', 'law-subscription-personalizations');
      (module.personalizations || []).filter((p) => p.active).forEach((p) => {
        const selected = currentItems.get(module.code)?.conditions?.personalizations?.find((entry) => entry.type_code === p.type_code);
        const field = lawSubscriptionField(p.name || p.label || p.type_code);
        const select = element('select', 'fs-form-control'); select.dataset.typeCode = p.type_code;
        (p.tiers || []).filter((tier) => tier.active).forEach((tier) => select.append(new Option(`${Number(tier.value).toLocaleString('pt-BR')} · ${formatLawMoney(tier.additional_monthly_amount)}/mês`, tier.value)));
        if (selected?.value) select.value = String(selected.value);
        if (!select.options.length) return;
        field.append(select);
        if (p.type_code === 'contatos_cadastrados' && data.usage?.contatos_cadastrados?.available) {
          const usage = data.usage.contatos_cadastrados;
          const note = element('p', 'law-subscription-usage');
          const update = () => { const limit = Number(select.value); const percent = limit ? Math.round(usage.used / limit * 1000) / 10 : 100; note.replaceChildren(document.createTextNode(`Uso atual: ${usage.used.toLocaleString('pt-BR')} de ${limit.toLocaleString('pt-BR')} (${percent}%).`)); note.dataset.state = percent > 70 ? 'warning' : 'normal'; if (percent > 70) note.append(element('strong', '', ' Considere aumentar esta capacidade.')); };
          select.addEventListener('change', update); update(); field.append(note);
        }
        personalizationBox.append(field);
      });
      card.append(personalizationBox); moduleGrid.append(card); moduleControls.set(module.code, checkbox);
    });
    form.append(moduleGrid);
    planSelect.addEventListener('change', () => { const plan = plans.find((item) => item.id === planSelect.value); moduleControls.forEach((checkbox, code) => { checkbox.checked = Boolean(plan?.module_codes?.includes(code)); }); });
    moduleControls.forEach((checkbox) => checkbox.addEventListener('change', () => { if (checkbox.checked) planSelect.value = ''; }));

    const actions = element('div', 'law-subscription-actions');
    const quoteButton = element('button', 'fs-btn fs-btn-primary', 'Calcular alteração'); quoteButton.type = 'submit';
    const applyButton = element('button', 'fs-btn fs-btn-primary', 'Solicitar alteração'); applyButton.type = 'button'; applyButton.hidden = true;
    const quote = element('div', 'law-subscription-quote'); actions.append(quoteButton, quote, applyButton); form.append(actions);
    const feedback = element('p', 'law-subscription-feedback'); feedback.setAttribute('role', 'status'); form.append(feedback);
    let payload;
    form.addEventListener('submit', async (event) => {
      event.preventDefault(); quoteButton.disabled = true; applyButton.hidden = true; feedback.textContent = '';
      const items = [];
      moduleControls.forEach((checkbox, code) => { if (checkbox.checked) { const card = checkbox.closest('.law-subscription-module-card'); items.push({ module_code: code, quantity: 1, personalizations: [...card.querySelectorAll('[data-type-code]')].map((input) => ({ type_code: input.dataset.typeCode, tier_value: Number(input.value) })) }); } });
      payload = { billing_cycle: cycleSelect.value, version: current.version, reason: 'Alteração solicitada pelo administrador na página Assinatura.', items };
      if (planSelect.value) payload.target_plan_id = planSelect.value;
      try {
        const result = await FokusApi.request('/law/subscription/quote', { method: 'POST', body: payload });
        quote.replaceChildren(element('strong', '', `${result.action === 'upgrade' ? 'Aumento' : 'Redução'} para ${formatLawMoney(result.target.amount)} por ${cycleSelect.value === 'annual' ? 'ano' : 'mês'}.`));
        quote.append(element('span', '', result.action === 'upgrade' ? `Cobrança proporcional agora: ${formatLawMoney(result.charge_now)}.` : `Vigência em ${formatLawDate(result.effective_at)}.`));
        if (pendingChange) applyButton.textContent = 'Atualizar alteração pendente';
        applyButton.hidden = false; applyButton.focus();
      } catch (error) { feedback.dataset.state = 'error'; feedback.textContent = error.message || 'Não foi possível calcular a alteração.'; }
      finally { quoteButton.disabled = false; }
    });
    applyButton.addEventListener('click', async () => { applyButton.disabled = true; try { const result = await FokusApi.request('/law/subscription/change', { method: pendingChange ? 'PATCH' : 'POST', body: payload }); if (result.checkout_url) window.location.assign(result.checkout_url); else window.location.reload(); } catch (error) { feedback.dataset.state = 'error'; feedback.textContent = error.message || 'Não foi possível solicitar a alteração.'; applyButton.disabled = false; } });
    contentRegion.append(form);

    if (pendingChange) {
      const pending = element('section', 'law-subscription-pending'); pending.append(element('h3', '', 'Alteração pendente'));
      pending.append(element('p', '', pendingChange.status === 'agendada' ? `Programada para ${formatLawDate(pendingChange.effective_at)}.` : 'Aguardando confirmação do pagamento.'));
      pending.append(element('p', '', 'Edite as seleções acima e use “Calcular alteração” para substituir esta solicitação.'));
      const cancel = element('button', 'fs-btn fs-btn-secondary', 'Cancelar alteração'); cancel.type = 'button'; cancel.addEventListener('click', async () => { cancel.disabled = true; try { await FokusApi.request('/law/subscription/change', { method: 'DELETE' }); window.location.reload(); } catch (error) { feedback.dataset.state = 'error'; feedback.textContent = error.message; cancel.disabled = false; } }); pending.append(cancel);
      contentRegion.append(pending);
    }
    const history = element('section', 'law-subscription-history'); history.append(element('h3', '', 'Histórico e pagamentos'));
    const entries = [...(data.history || []).map((item) => ({ label: `Alteração: ${item.type} · ${item.status}`, date: item.created_at })), ...(data.payments || []).map((item) => ({ label: `Pagamento: ${formatLawMoney(item.amount)} · ${item.status}`, date: item.created_at }))].sort((a, b) => new Date(b.date) - new Date(a.date)).slice(0, 10);
    if (!entries.length) history.append(element('p', '', 'Ainda não há alterações ou pagamentos registrados.'));
    else { const list = element('ul', 'law-subscription-history-list'); entries.forEach((entry) => { const row = element('li'); row.append(element('span', '', entry.label), element('time', '', formatLawDate(entry.date))); list.append(row); }); history.append(list); }
    contentRegion.append(history);
  }

  function lawSubscriptionField(text) { const field = element('label', 'law-subscription-field'); field.append(element('span', '', text)); return field; }
  function formatLawMoney(value) { return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value || 0)); }
  function formatLawDate(value) { if (!value) return '—'; const date = new Date(value); return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium' }).format(date); }

  function renderContent(view) {
    contentRegion.replaceChildren();
    contentRegion.dataset.view = view;
    if (view === 'overview') renderOverview();
    else if (view === 'settings') renderSettings();
    else if (view === 'profile') renderProfile();
    else if (view === 'preferences') renderPreferences();
    else if (view === 'units') renderUnits();
    else if (view === 'company') renderCompany();
    else if (view === 'subscription') renderSubscription();
    else {
      const module = activeModule();
      if (module) renderModulePlaceholder(module);
      else renderOverview();
    }
  }

  function renderProfile() {
    document.title = 'Meu perfil | Fokus Law';
    const template = document.querySelector('#law-profile-template');
    if (!template) {
      announceError('Não foi possível carregar o perfil. Atualize a página para tentar novamente.');
      return;
    }
    contentRegion.append(template.content.cloneNode(true));
    if (typeof window.initializeLawProfile === 'function') window.initializeLawProfile();
  }

  function renderCompanyOptions() {
    const activeCompany = context.companies.find((company) => company.id === context.active_company_id);
    const switchButton = document.querySelector('#company-switch');
    const options = document.querySelector('#company-options');
    document.querySelector('#company-name').textContent = context.company.name;
    document.querySelector('#company-name-static').textContent = context.company.name;
    if (context.companies.length < 2) {
      switchButton.hidden = true;
      document.querySelector('#company-name-static').hidden = false;
      return;
    }
    document.querySelector('#company-name-static').hidden = true;
    switchButton.hidden = false;
    options.replaceChildren();
    context.companies.forEach((company) => {
      const option = element('button', 'law-company-option', company.name);
      option.type = 'button';
      option.setAttribute('role', 'option');
      option.setAttribute('aria-selected', String(company.id === activeCompany?.id));
      option.addEventListener('click', async () => {
        switchButton.disabled = true;
        try {
          await FokusApi.request('/auth/select-company', { method: 'POST', body: { company_id: company.id } });
          window.location.reload();
        } catch (error) {
          switchButton.disabled = false;
          options.hidden = true;
          switchButton.setAttribute('aria-expanded', 'false');
          announceError(error.message);
        }
      });
      options.append(option);
    });
  }

  function renderUnitOptions() {
    const wrapper = document.querySelector('#unit-context');
    const select = document.querySelector('#law-unit-select');
    const units = (context.units || []).filter((unit) => unit.status === 'ativo');
    wrapper.hidden = units.length === 0;
    select.replaceChildren();
    if (!units.length) return;
    select.disabled = units.length === 1;
    if (!context.active_unit_id) {
      const placeholder = element('option', '', 'Selecione um setor');
      placeholder.value = '';
      placeholder.selected = true;
      placeholder.disabled = true;
      select.append(placeholder);
    }
    units.forEach((unit) => {
      const option = element('option', '', unit.name);
      option.value = unit.id;
      option.selected = unit.id === context.active_unit_id;
      select.append(option);
    });
  }

  function announceError(message) {
    contentRegion.replaceChildren();
    const alert = element('div', 'fs-alert fs-alert-danger', message);
    alert.setAttribute('role', 'alert');
    contentRegion.append(alert);
  }

  function openPopover(button, panel) {
    const willOpen = panel.hidden;
    document.querySelectorAll('.law-popover').forEach((item) => { item.hidden = true; });
    document.querySelectorAll('[aria-controls="notifications-panel"]').forEach((item) => item.setAttribute('aria-expanded', 'false'));
    panel.hidden = !willOpen;
    button.setAttribute('aria-expanded', String(willOpen));
  }

  function initializeInteractions() {
    const unitSelect = document.querySelector('#law-unit-select');
    unitSelect.addEventListener('change', async () => {
      unitSelect.disabled = true;
      try {
        await FokusApi.request('/law/active-unit', { method: 'POST', body: { unit_id: unitSelect.value } });
        window.location.reload();
      } catch (error) {
        unitSelect.disabled = false;
        announceError(error.message || 'Não foi possível trocar o setor ativo.');
      }
    });

    const companySwitch = document.querySelector('#company-switch');
    const companyOptions = document.querySelector('#company-options');
    companySwitch.addEventListener('click', () => {
      const open = companySwitch.getAttribute('aria-expanded') === 'true';
      companySwitch.setAttribute('aria-expanded', String(!open));
      companyOptions.hidden = open;
    });

    const notificationButton = document.querySelector('#notifications-button');
    const notificationPanel = document.querySelector('#notifications-panel');
    notificationButton.addEventListener('click', () => openPopover(notificationButton, notificationPanel));
    if (context.permissions.manage_settings) loadLawNotifications(notificationButton, notificationPanel);

    const search = document.querySelector('#global-search');
    const searchState = document.querySelector('#search-state');
    const showSearchState = () => { searchState.hidden = false; };
    search.addEventListener('focus', showSearchState);
    search.addEventListener('input', showSearchState);
    document.addEventListener('click', (event) => {
      if (!event.target.closest('.law-search-wrap')) searchState.hidden = true;
      if (!event.target.closest('.law-popover-anchor')) {
        notificationPanel.hidden = true;
        notificationButton.setAttribute('aria-expanded', 'false');
      }
      if (!event.target.closest('.law-company-context')) {
        companyOptions.hidden = true;
        companySwitch.setAttribute('aria-expanded', 'false');
      }
    });
    document.addEventListener('keydown', (event) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        search.focus();
      }
      if (event.key === 'Escape') {
        searchState.hidden = true;
        notificationPanel.hidden = true;
        notificationButton.setAttribute('aria-expanded', 'false');
        companyOptions.hidden = true;
        companySwitch.setAttribute('aria-expanded', 'false');
        closeMobileNav(true);
      }
    });

    document.querySelector('#logout-button').addEventListener('click', async (event) => {
      const button = event.currentTarget;
      button.disabled = true;
      try { await FokusApi.request('/auth/logout', { method: 'POST' }); }
      finally { window.location.assign('/?acesso=cliente'); }
    });

    mobileButton.addEventListener('click', () => {
      const open = !sidebar.classList.contains('is-open');
      sidebar.classList.toggle('is-open', open);
      mobileScrim.hidden = !open;
      mobileButton.setAttribute('aria-expanded', String(open));
      mobileButton.setAttribute('aria-label', open ? 'Fechar menu' : 'Abrir menu');
      if (open) sidebar.querySelector('.law-rail-button')?.focus();
    });
    mobileScrim.addEventListener('click', closeMobileNav);
    document.querySelectorAll('.law-profile-link').forEach((link) => link.addEventListener('click', closeMobileNav));
  }

  async function loadLawNotifications(button, panel) {
    try {
      const result = await FokusApi.request('/law/notifications');
      const count = Number(result.unread_count || 0);
      button.setAttribute('aria-label', count ? `Notificações, ${count} não lidas` : 'Notificações');
      let badge = button.querySelector('.law-notification-count');
      if (count) { if (!badge) { badge = element('span','law-notification-count'); button.append(badge); } badge.textContent = count > 99 ? '99+' : String(count); }
      else badge?.remove();
      const items = result.notifications || [];
      panel.replaceChildren(element('h2','','Notificações'));
      if (!items.length) { panel.append(element('p','','Nenhuma notificação por enquanto.'),element('span','','Os avisos dos módulos aparecerão aqui.')); return; }
      items.forEach((item)=>{ const row=element('button','law-notification-item'); row.type='button'; if(!item.read_at) row.dataset.unread='true'; row.append(element('strong','',item.title),element('span','',item.message),element('time','',formatLawDate(item.created_at))); row.addEventListener('click',async()=>{ try { await FokusApi.request(`/law/notifications/${encodeURIComponent(item.id)}/read`,{method:'PATCH'}); } catch {} const href=String(item.payload?.href||'/portal/fokus-law/assinatura'); if(href.startsWith('/')) window.location.assign(href); }); panel.append(row); });
    } catch (error) {
      if (error.status === 403) return;
    }
  }

  function setContext(value) {
    context = value;
    if (initialPage !== 'profile') document.title = initialPage === 'company' ? 'Empresa | Fokus Law' : 'Fokus Law | Fokus Cloud';
    document.querySelector('#user-name').textContent = context.user.name;
    document.querySelector('#user-email').textContent = context.user.email;
    document.querySelector('#user-avatar').textContent = context.user.name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    document.querySelector('#subscription-label').textContent = context.subscription?.label || 'Fokus Law';
    renderCompanyOptions();
    renderUnitOptions();
    const remember = localStorage.getItem(preferenceKey(context.user.id, 'remember-group')) === 'true';
    if (!['profile', 'company', 'subscription'].includes(initialPage) && remember) {
      const lastGroup = localStorage.getItem(preferenceKey(context.user.id, 'last-group'));
      if ((lastGroup === 'settings' && context.permissions.manage_settings) || context.modules.some((item) => `module:${item.id}` === lastGroup)) activeGroup = lastGroup;
    }
    if (localStorage.getItem(preferenceKey(context.user.id, 'reduced-motion')) === 'true') document.documentElement.classList.add('law-pref-reduced-motion');
    renderRail();
    renderNavigation();
    initializeInteractions();
    if (context.support_mode) {
      const supportNotice = document.querySelector('#support-notice');
      document.querySelector('#support-context').textContent = `${context.support_mode.company} · Assinatura ${context.support_mode.subscription_status} · ${context.support_mode.reason}`;
      supportNotice.hidden = false;
      document.querySelector('#support-exit').addEventListener('click', async (event) => {
        event.currentTarget.disabled = true;
        try {
          const result = await FokusApi.request('/backoffice/support/exit', { method: 'POST' });
          window.location.assign(result.redirect_to || '/backoffice/');
        } catch (error) {
          event.currentTarget.disabled = false;
          announceError(error.message || 'Não foi possível encerrar o acesso de suporte.');
        }
      });
    }
    railItems.addEventListener('click', () => {
      if (localStorage.getItem(preferenceKey(context.user.id, 'remember-group')) === 'true') {
        localStorage.setItem(preferenceKey(context.user.id, 'last-group'), activeGroup);
      }
    });
  }

FokusApi.request('/law/shell-context').then((value) => {
    setContext(value);
    loading.hidden = true;
    shell.hidden = false;
  }).catch((error) => {
    if (error.status === 401) window.location.assign('/?acesso=cliente');
    else if (error.status === 409) window.location.assign('/portal/empresas');
    else if (error.status === 403 && /e-mail/i.test(error.message)) window.location.assign('/verificar-email');
    else if (error.status === 403) window.location.assign('/portal/empresas');
    else {
      loading.replaceChildren(element('p', '', 'Não foi possível carregar o Fokus Law. Atualize a página ou entre novamente no portal.'));
      const link = element('a', 'fs-btn fs-btn-outline-primary', 'Voltar ao portal');
      link.href = '/portal';
      loading.append(link);
    }
  });
})();

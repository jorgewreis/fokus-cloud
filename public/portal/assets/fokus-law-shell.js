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
    contactsCreate: 'Single-Neutral-Actions-Add--Streamline-Ultimate-Regular.png',
    contactsShare: 'Common-File-Text-Share--Streamline-Ultimate-Regular.png',
    contactsQuality: 'Like-Ribbon-1--Streamline-Ultimate-Regular.png',
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
  let contactsView = ['contacts-sharing', 'contacts-quality'].includes(initialPage) ? initialPage : 'module';
  let settingsView = ['company', 'subscription', 'users', 'transfer'].includes(initialPage) ? initialPage : 'settings';
  let activeGroup = ['company', 'subscription', 'users', 'transfer'].includes(initialPage) ? 'settings' : 'overview';

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
    return visibleModules().find((item) => item.id === activeGroup.slice('module:'.length));
  }

  function canLawPermission(permission) {
    return context.company?.role === 'admin' || (context.law_permissions || []).includes(permission);
  }

  function visibleModules() {
    return (context.modules || []).filter((module) => {
      const family = String(module.family || module.module_code || module.code || '').toLowerCase();
      return !family.startsWith('contatos') || canLawPermission('law.contacts.view');
    });
  }

  function renderRail() {
    railItems.replaceChildren();
    addRailButton('overview', 'Visão geral', 'overview');
    visibleModules().forEach((module) => {
      const descriptor = getModuleDescriptor(module);
      addRailButton(`module:${module.id}`, descriptor.label, descriptor.icon);
    });
    if (context.permissions.manage_settings || context.permissions.manage_company_users || context.permissions.transfer_admin) addRailButton('settings', 'Configurações', 'settings');
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
      if (!context.permissions.manage_settings && !context.permissions.manage_company_users && !context.permissions.transfer_admin) {
        activeGroup = 'overview';
        renderNavigation();
        return;
      }
      sectionTitle.textContent = 'Configurações';
      headingIcon = 'settings';
      document.querySelector('#section-icon').src = ICON_ROOT + ICONS[headingIcon];
      const list = element('ul');
      const entries = context.permissions.manage_settings ? [
        ['Empresa', '/portal/fokus-law/empresa', 'company'],
        ['Assinatura', '/portal/fokus-law/assinatura', 'settings'],
      ] : [];
      if (context.permissions.manage_company_users) entries.push(['Usuários, perfis e permissões', '/portal/usuarios', 'users']);
      if (context.permissions.transfer_admin) entries.push(['Transferir administração', '/portal/transferir-administracao', 'users']);
      entries.forEach(([label, href, iconName]) => {
        const item = element('li');
        appendNavLink(item, label, href, iconName, (initialPage === 'users' && label === 'Usuários, perfis e permissões') || (initialPage === 'transfer' && label === 'Transferir administração'));
        list.append(item);
      });
      if (context.permissions.manage_settings) {
        const unitsItem = element('li');
        appendNavButton(unitsItem, 'Setores da empresa', 'company', settingsView === 'units', () => { settingsView = 'units'; renderNavigation(); });
        list.append(unitsItem);
      }
      pageItems.append(list);
      renderContent(settingsView === 'units' ? 'units' : settingsView === 'company' ? 'company' : settingsView === 'subscription' ? 'subscription' : settingsView === 'users' ? 'users' : settingsView === 'transfer' ? 'transfer' : 'settings');
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
    const isContactsModule = String(module.family || module.module_code || module.code || '').toLowerCase().startsWith('contatos');
    if (isContactsModule) {
      appendNavButton(pageItems, 'Cadastro e consulta', 'contactsCreate', contactsView === 'module', () => { contactsView = 'module'; renderNavigation(); });
      if (canLawPermission('law.contacts.share.manage')) appendNavLink(pageItems, 'Compartilhamentos', '/portal/fokus-law/contatos/compartilhamentos', 'contactsShare', contactsView === 'contacts-sharing');
      if (canLawPermission('law.contacts.view')) appendNavLink(pageItems, 'Revisão e qualidade', '/portal/fokus-law/contatos/revisao-e-qualidade', 'contactsQuality', contactsView === 'contacts-quality');
    } else {
      appendNavButton(pageItems, `Visão geral de ${descriptor.label}`, descriptor.icon, true, () => renderContent('module'), true);
      pageItems.append(element('p', 'law-nav-description', 'As páginas funcionais deste módulo serão adicionadas aqui.'));
    }
    renderContent(contactsView);
    document.querySelector('#section-icon').src = ICON_ROOT + ICONS[headingIcon];
  }

  function renderOverview() {
    const heading = element('div');
    heading.append(element('p', 'law-page-eyebrow', 'ESPAÇO DE TRABALHO'));
    heading.append(element('h2', '', 'Visão geral'));
    heading.append(element('p', 'law-page-lede', 'Acompanhe seu trabalho e retome suas atividades em um só lugar.'));
    contentRegion.append(heading);

    const welcome = element('section', 'law-dashboard-hero');
    const welcomeCopy = element('div', 'law-dashboard-hero-copy');
    welcomeCopy.append(element('span', 'law-dashboard-hero-kicker', 'FOKUS LAW · WORKSPACE'));
    welcomeCopy.append(element('h3', '', 'Seu trabalho, em foco.'));
    welcomeCopy.append(element('p', '', 'Acesse suas ferramentas e continue de onde parou.'));
    const visual = element('div', 'law-dashboard-hero-art');
    visual.setAttribute('aria-hidden', 'true');
    visual.append(element('span', 'law-dashboard-hero-orbit law-dashboard-hero-orbit-one'), element('span', 'law-dashboard-hero-orbit law-dashboard-hero-orbit-two'));
    visual.append(icon('overview', 'law-dashboard-hero-icon'));
    welcome.append(welcomeCopy, visual);
    contentRegion.append(welcome);

    const grid = element('div', 'law-dashboard-widgets');
    contentRegion.append(grid);
    const contactsEnabled = visibleModules().some((module) => String(module.family || module.module_code || module.code || '').toLowerCase().startsWith('contatos'));
    if (contactsEnabled && canLawPermission('law.contacts.view')) {
      const contactModule = visibleModules().find((module) => String(module.family || module.module_code || module.code || '').toLowerCase().startsWith('contatos'));
      const card = element('article', 'fs-card law-dashboard-module-widget law-dashboard-contacts');
      const header = element('header', 'law-dashboard-widget-header');
      const identity = element('div', 'law-dashboard-widget-identity');
      const mark = element('span', 'law-dashboard-widget-mark'); mark.append(icon('contacts'));
      const title = element('div'); title.append(element('span', 'law-dashboard-widget-kicker', 'BASE JURÍDICA'), element('h3', '', 'Gestão de Contatos'));
      identity.append(mark, title);
      const open = element('button', 'law-dashboard-widget-open', 'Abrir módulo'); open.type = 'button'; open.append(element('span', '', '↗'));
      open.addEventListener('click', () => { if (contactModule) { activeGroup = `module:${contactModule.id}`; renderNavigation(); closeMobileNav(); contentRegion.focus({ preventScroll: true }); } });
      header.append(identity, open);
      const body = element('div', 'law-dashboard-widget-body');
      body.append(element('p', 'law-contact-loading', 'Carregando seus indicadores…'));
      card.append(header, body);
      grid.append(card);
      FokusApi.request('/law/contacts/dashboard').then(({ summary }) => {
        if (!card.isConnected) return;
        body.replaceChildren();
        const total = Number(summary.contacts_total || 0);
        const pf = Number(summary.pf || 0);
        const pj = Number(summary.pj || 0);
        const distribution = element('div', 'law-dashboard-contact-distribution');
        const ring = element('div', 'law-dashboard-contact-ring');
        const classified = Math.max(1, pf + pj);
        ring.style.setProperty('--contact-pf-share', `${(pf / classified) * 100}%`);
        ring.dataset.empty = String(total === 0);
        ring.setAttribute('role', 'img');
        ring.setAttribute('aria-label', `Distribuição de contatos: ${pf} pessoas físicas e ${pj} pessoas jurídicas`);
        const ringCenter = element('span', 'law-dashboard-ring-center'); ringCenter.append(element('strong', '', total.toLocaleString('pt-BR')), element('small', '', 'contatos'));
        ring.append(ringCenter);
        const breakdown = element('div', 'law-dashboard-contact-breakdown');
        [['PF', 'Pessoas físicas', pf, 'pf'], ['PJ', 'Pessoas jurídicas', pj, 'pj']].forEach(([short, label, value, tone]) => {
          const row = element('div', `law-dashboard-breakdown-row law-dashboard-breakdown-${tone}`);
          const rowHead = element('div', 'law-dashboard-breakdown-head');
          rowHead.append(element('span', 'law-dashboard-breakdown-label', label), element('strong', '', Number(value).toLocaleString('pt-BR')));
          const track = element('span', 'law-dashboard-breakdown-track');
          const fill = element('span', 'law-dashboard-breakdown-fill');
          fill.style.width = `${Math.min(100, (Number(value) / classified) * 100)}%`;
          fill.dataset.zero = String(Number(value) === 0);
          track.append(fill); row.append(rowHead, track); breakdown.append(row);
        });
        distribution.append(ring, breakdown);

        const recentSection = element('div', 'law-dashboard-widget-recent');
        const recentTitle = element('span', 'law-dashboard-widget-kicker', 'ACESSADOS RECENTEMENTE');
        const recent = element('div', 'law-dashboard-recent-list');
        (summary.recent || []).slice(0, 5).forEach((contact) => {
          const link = element('button', 'law-dashboard-recent-link', contact.display_name); link.type = 'button';
          link.addEventListener('click', (event) => window.FokusLawContacts?.openContact(contentRegion, contact.id, event.currentTarget));
          recent.append(link);
        });
        if (!(summary.recent || []).length) recent.append(element('span', 'law-dashboard-recent-empty', 'Os contatos que você acessar aparecerão aqui.'));
        recentSection.append(recentTitle, recent);
        body.append(distribution, recentSection);
      }).catch(() => { if (card.isConnected) body.replaceChildren(element('p', 'law-dashboard-widget-error', 'Não foi possível carregar este resumo agora.')); });
    }
  }

  function renderSettings() {
    const heading = element('div');
    heading.append(element('p', 'law-page-eyebrow', 'ADMINISTRAÇÃO DA EMPRESA'));
    heading.append(element('h2', '', 'Configurações'));
    if (!context.permissions.manage_settings) {
      heading.append(element('p', 'law-page-lede', 'Administre os perfis e os acessos da equipe nos setores em que você tem permissão.'));
      contentRegion.append(heading);
      const link = element('a', 'law-settings-link'); link.href = '/portal/usuarios'; link.append(icon('users'));
      const text = element('span'); text.append(element('strong', '', 'Usuários, perfis e permissões'), element('small', '', 'Gerencie os vínculos da equipe no setor ativo.'));
      link.append(text); contentRegion.append(link); return;
    }
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
    if (type === 'cpf') return window.FokusDocuments?.formatCpf(number) || 'Documento não informado';
    if (type === 'cnpj') return window.FokusDocuments?.formatCnpj(number) || 'Documento não informado';
    return String(number || '').replace(/\D/g, '') || 'Documento não informado';
  }

  function companyStatus(status) {
    return ({ ativa: 'Ativa', pendente: 'Pendente', suspensa: 'Suspensa', encerrando: 'Encerrando', encerrada: 'Encerrada' })[status] || status || 'Não informada';
  }

  function maskUserCpf(value) {
    const digits = String(value || '').replace(/\D/g, '');
    return digits.length === 11 ? `${digits.slice(0, 3)}.***.***-${digits.slice(-2)}` : 'CPF não informado';
  }

  function isValidUserCpf(value) {
    const cpf = String(value || '').replace(/\D/g, '');
    if (cpf.length !== 11 || /^(\d)\1+$/.test(cpf)) return false;
    const checkDigit = (length) => {
      const total = cpf.slice(0, length).split('').reduce((sum, digit, index) => sum + Number(digit) * (length + 1 - index), 0);
      const digit = (total * 10) % 11;
      return digit === 10 ? 0 : digit;
    };
    return checkDigit(9) === Number(cpf[9]) && checkDigit(10) === Number(cpf[10]);
  }
  window.FokusLawUserCpfValid = isValidUserCpf;

  function userRoleLabel(role) {
    return ({ admin: 'Administrador', gestor: 'Gestor', usuario: 'Usuário' })[role] || role || 'Não informado';
  }

  function userStatusLabel(status) {
    return ({ ativo: 'Ativo', pendente: 'Convite pendente', suspenso: 'Suspenso', removido: 'Removido' })[status] || status || 'Não informado';
  }

  async function renderUsers(successMessage = '') {
    if (window.FokusLawAccessUsers) return window.FokusLawAccessUsers.render(context, contentRegion, successMessage);
    document.title = 'Usuários, perfis e permissões | Fokus Law';
    contentRegion.replaceChildren();
    const heading = element('header', 'law-users-heading');
    heading.append(element('p', 'law-page-eyebrow', 'ADMINISTRAÇÃO DA EMPRESA'));
    heading.append(element('h2', '', 'Usuários, perfis e permissões'));
    heading.append(element('p', 'law-page-lede', 'Convide pessoas para a empresa e administre seus perfis e acessos.'));
    contentRegion.append(heading);

    const feedback = element('p', 'law-users-feedback');
    feedback.id = 'law-users-feedback';
    feedback.setAttribute('role', 'status');
    feedback.setAttribute('aria-live', 'polite');
    if (successMessage) feedback.dataset.state = 'success';
    feedback.textContent = successMessage;

    const layout = element('div', 'law-users-layout');
    const inviteCard = element('section', 'law-profile-card law-users-invite');
    inviteCard.setAttribute('aria-labelledby', 'law-users-invite-title');
    const inviteHeader = element('div', 'law-profile-card-heading');
    const inviteCopy = element('div');
    inviteCopy.append(element('p', 'law-profile-eyebrow', 'NOVO ACESSO'), element('h3', '', 'Convidar pessoa'));
    inviteCopy.querySelector('h3').id = 'law-users-invite-title';
    inviteHeader.append(inviteCopy);
    inviteCard.append(inviteHeader);
    const form = element('form', 'law-profile-form law-users-form');
    form.id = 'law-users-invite-form';
    form.noValidate = true;
    const fields = [
      { name: 'name', label: 'Nome completo', autocomplete: 'name', type: 'text' },
      { name: 'cpf', label: 'CPF', autocomplete: 'off', type: 'text', inputmode: 'numeric', placeholder: '000.000.000-00' },
      { name: 'email', label: 'E-mail', autocomplete: 'email', type: 'email' },
    ];
    fields.forEach((field) => {
      const id = `law-users-${field.name}`;
      const label = element('label', 'law-profile-field law-users-field');
      label.htmlFor = id;
      label.append(element('span', '', field.label));
      const input = element('input', 'fs-form-control');
      input.id = id;
      input.name = field.name;
      input.type = field.type;
      input.autocomplete = field.autocomplete;
      input.required = true;
      if (field.inputmode) input.inputMode = field.inputmode;
      if (field.placeholder) input.placeholder = field.placeholder;
      if (field.name === 'cpf') { input.maxLength = 14; window.FokusDocuments?.bind(input, 'cpf'); }
      label.append(input);
      form.append(label);
    });
    const roleLabel = element('label', 'law-profile-field law-users-field');
    roleLabel.htmlFor = 'law-users-role';
    roleLabel.append(element('span', '', 'Perfil'));
    const roleSelect = element('select', 'fs-form-select');
    roleSelect.id = 'law-users-role';
    roleSelect.name = 'role';
    roleSelect.append(new Option('Usuário', 'usuario'), new Option('Gestor', 'gestor'));
    roleLabel.append(roleSelect);
    form.append(roleLabel);
    const formFooter = element('div', 'law-profile-form-footer law-users-form-footer');
    const submit = element('button', 'fs-btn fs-btn-primary', 'Enviar convite');
    submit.type = 'submit';
    formFooter.append(submit);
    form.append(formFooter);
    inviteCard.append(form);

    const profiles = element('section', 'law-profile-card law-users-profiles');
    profiles.setAttribute('aria-labelledby', 'law-users-profiles-title');
    const profilesHeading = element('div', 'law-profile-card-heading');
    const profilesCopy = element('div');
    profilesCopy.append(element('p', 'law-profile-eyebrow', 'ACESSO À EMPRESA'), element('h3', '', 'Perfis disponíveis'));
    profilesCopy.querySelector('h3').id = 'law-users-profiles-title';
    profilesHeading.append(profilesCopy);
    const profileList = element('dl', 'law-users-profile-list');
    [['Gestor', 'Acessa as operações delegadas nos módulos disponíveis. Não administra usuários, assinatura ou administração da empresa.'], ['Usuário', 'Acessa as operações permitidas ao seu perfil, sem administrar usuários, configurações sensíveis ou permissões.']].forEach(([title, description]) => {
      const item = element('div', 'law-users-profile-item');
      item.append(element('dt', '', title), element('dd', '', description));
      profileList.append(item);
    });
    profiles.append(profilesHeading, profileList);
    layout.append(inviteCard, profiles);

    const listCard = element('section', 'law-profile-card law-users-list-card');
    listCard.setAttribute('aria-labelledby', 'law-users-list-title');
    const listHeader = element('div', 'law-profile-card-heading law-users-list-heading');
    const listCopy = element('div');
    listCopy.append(element('p', 'law-profile-eyebrow', 'VÍNCULOS DA EMPRESA'), element('h3', '', 'Pessoas com acesso'));
    listCopy.querySelector('h3').id = 'law-users-list-title';
    listHeader.append(listCopy);
    const transfer = element('a', 'fs-btn fs-btn-outline-primary law-users-transfer', 'Transferir administração');
    transfer.href = '/portal/transferir-administracao';
    listHeader.append(transfer);
    const listStatus = element('p', 'law-profile-loading');
    listStatus.setAttribute('role', 'status');
    listStatus.setAttribute('aria-live', 'polite');
    listStatus.textContent = 'Carregando usuários…';
    const userList = element('div', 'law-users-list');
    userList.setAttribute('aria-busy', 'true');
    listCard.append(listHeader, listStatus, userList);

    const removeTrigger = element('button');
    removeTrigger.type = 'button';
    removeTrigger.hidden = true;
    removeTrigger.setAttribute('data-fs', 'modal');
    removeTrigger.setAttribute('data-fs-target', '#law-users-remove-modal');
    const removeModal = element('div', 'fs-modal');
    removeModal.id = 'law-users-remove-modal';
    removeModal.setAttribute('aria-hidden', 'true');
    const modalDialog = element('div', 'fs-modal-dialog fs-modal-sm');
    const modalContent = element('div', 'fs-modal-content');
    const modalHeader = element('div', 'fs-modal-header');
    const modalTitle = element('h2', 'fs-modal-title', 'Remover acesso');
    modalHeader.append(modalTitle);
    const modalClose = element('button', 'fs-btn-close');
    modalClose.type = 'button';
    modalClose.setAttribute('data-fs-dismiss', 'modal');
    modalClose.setAttribute('aria-label', 'Fechar');
    modalHeader.append(modalClose);
    const modalBody = element('div', 'fs-modal-body');
    const modalDescription = element('p');
    modalBody.append(modalDescription);
    const modalError = element('p', 'law-users-modal-error');
    modalError.setAttribute('role', 'alert');
    modalBody.append(modalError);
    const modalFooter = element('div', 'fs-modal-footer');
    const modalCancel = element('button', 'fs-btn fs-btn-outline-secondary', 'Cancelar');
    modalCancel.type = 'button';
    modalCancel.setAttribute('data-fs-dismiss', 'modal');
    const modalConfirm = element('button', 'fs-btn fs-btn-danger', 'Remover acesso');
    modalConfirm.type = 'button';
    modalFooter.append(modalCancel, modalConfirm);
    modalContent.append(modalHeader, modalBody, modalFooter);
    modalDialog.append(modalContent);
    removeModal.append(modalDialog);

    contentRegion.append(feedback, layout, listCard, removeTrigger, removeModal);
    const modal = window.FokusStyles?.Modal?.getOrCreateInstance(removeTrigger);
    let pendingRemoval = null;

    async function refreshUsers(message = '') {
      userList.setAttribute('aria-busy', 'true');
      userList.replaceChildren();
      listStatus.hidden = false;
      listStatus.dataset.state = '';
      listStatus.textContent = 'Carregando usuários…';
      try {
        const users = await FokusApi.request('/portal/users');
        userList.replaceChildren();
        if (!Array.isArray(users) || users.length === 0) {
          listStatus.textContent = 'Nenhuma pessoa está vinculada a esta empresa.';
          userList.setAttribute('aria-busy', 'false');
          if (message) { feedback.textContent = message; feedback.dataset.state = 'success'; }
          return;
        }
        listStatus.hidden = true;
        users.forEach((user) => {
          const card = element('article', 'law-users-row');
          const details = element('div', 'law-users-row-details');
          const identity = element('div', 'law-users-identity');
          identity.append(element('h4', '', user.name || 'Usuário'), element('p', '', user.email || 'E-mail não informado'));
          const metadata = element('dl', 'law-users-metadata');
          [[ 'CPF', maskUserCpf(user.cpf) ], [ 'Perfil', userRoleLabel(user.role) ], [ 'Situação', userStatusLabel(user.status) ]].forEach(([label, value]) => {
            const field = element('div', 'law-users-meta-item');
            field.append(element('dt', '', label), element('dd', '', value));
            metadata.append(field);
          });
          details.append(identity, metadata);
          card.append(details);
          if (user.role !== 'admin') {
            const actions = element('div', 'law-users-row-actions');
            [['gestor', 'Gestor'], ['usuario', 'Usuário']].forEach(([role, label]) => {
              const button = element('button', role === user.role ? 'fs-btn fs-btn-secondary' : 'fs-btn fs-btn-outline-secondary', role === user.role ? `${label} · atual` : `Definir perfil: ${label}`);
              button.type = 'button';
              button.disabled = role === user.role || user.status === 'removido';
              button.addEventListener('click', () => updateUser(user, { role }));
              actions.append(button);
            });
            if (user.status === 'removido') {
              const restore = element('button', 'fs-btn fs-btn-outline-primary', 'Restaurar acesso');
              restore.type = 'button';
              restore.addEventListener('click', async () => {
                await runUserAction(restore, async () => FokusApi.request(`/portal/users/${encodeURIComponent(user.id)}/restore`, { method: 'POST', body: { version: Number(user.version) } }), 'Acesso restaurado.');
              });
              actions.append(restore);
            } else if (user.status !== 'suspenso') {
              const suspend = element('button', 'fs-btn fs-btn-outline-secondary', 'Suspender acesso');
              suspend.type = 'button';
              suspend.addEventListener('click', () => updateUser(user, { status: 'suspenso' }));
              actions.append(suspend);
            }
            if (user.status !== 'removido') {
              const remove = element('button', 'fs-btn fs-btn-outline-primary law-users-remove-button', 'Remover');
              remove.type = 'button';
              remove.addEventListener('click', () => {
                pendingRemoval = { user, trigger: remove };
                remove.addEventListener('fs:hidden', () => {
                  if (pendingRemoval?.trigger === remove) pendingRemoval = null;
                }, { once: true });
                modalDescription.textContent = `O vínculo de ${user.name || 'esta pessoa'} com a empresa será removido.`;
                modalError.textContent = '';
                if (modal) modal.triggerEl = remove;
                modal?.show();
              });
              actions.append(remove);
            }
            card.append(actions);
          }
          userList.append(card);
        });
        userList.setAttribute('aria-busy', 'false');
        if (message) { feedback.textContent = message; feedback.dataset.state = 'success'; }
      } catch (error) {
        userList.setAttribute('aria-busy', 'false');
        listStatus.hidden = false;
        listStatus.dataset.state = 'error';
        listStatus.textContent = error.message || 'Não foi possível carregar os usuários.';
        if (error.status === 401 || error.status === 403) window.location.assign(error.status === 401 ? '/acesso' : '/portal/empresas');
      }
    }

    async function runUserAction(button, request, successMessage) {
      button.disabled = true;
      feedback.textContent = 'Salvando alteração…';
      feedback.dataset.state = '';
      try {
        const result = await request();
        await refreshUsers(result?.message || successMessage);
      } catch (error) {
        feedback.textContent = error.message || 'Não foi possível salvar a alteração.';
        feedback.dataset.state = 'error';
        button.disabled = false;
      }
    }

    async function updateUser(user, changes) {
      const button = document.activeElement;
      await runUserAction(button, () => FokusApi.request(`/portal/users/${encodeURIComponent(user.id)}`, {
        method: 'PATCH', body: { ...changes, version: Number(user.version) },
      }), changes.role ? 'Perfil atualizado.' : 'Acesso suspenso.');
    }

    modalConfirm.addEventListener('click', async () => {
      if (!pendingRemoval) return;
      modalConfirm.disabled = true;
      try {
        const { user } = pendingRemoval;
        const result = await FokusApi.request(`/portal/users/${encodeURIComponent(user.id)}`, {
          method: 'PATCH', body: { status: 'removido', version: Number(user.version) },
        });
        pendingRemoval = null;
        modal?.hide();
        await refreshUsers(result?.message || 'Acesso removido.');
      } catch (error) {
        modalError.textContent = error.message || 'Não foi possível remover o acesso.';
      } finally {
        modalConfirm.disabled = false;
      }
    });
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      feedback.textContent = '';
      feedback.dataset.state = '';
      if (!form.reportValidity()) return;
      const data = Object.fromEntries(new FormData(form));
      const cpf = window.FokusDocuments?.normalize(data.cpf, 'cpf') || String(data.cpf || '').replace(/\D/g, '');
      if (!(window.FokusDocuments?.cpf(cpf) ?? isValidUserCpf(cpf))) {
        const cpfInput = form.elements.namedItem('cpf');
        cpfInput.setAttribute('aria-invalid', 'true');
        cpfInput.setAttribute('aria-describedby', feedback.id);
        feedback.textContent = 'Informe um CPF válido com 11 dígitos.';
        feedback.dataset.state = 'error';
        cpfInput.focus();
        return;
      }
      form.elements.namedItem('cpf').removeAttribute('aria-invalid');
      form.elements.namedItem('cpf').removeAttribute('aria-describedby');
      const submitButton = form.querySelector('[type="submit"]');
      submitButton.disabled = true;
      submitButton.textContent = 'Enviando convite…';
      try {
        const result = await FokusApi.request('/portal/users', { method: 'POST', body: { ...data, cpf } });
        form.reset();
        await refreshUsers(result?.message || 'Convite enviado.');
        form.querySelector('[name="name"]').focus({ preventScroll: true });
      } catch (error) {
        feedback.textContent = error.message || 'Não foi possível enviar o convite.';
        feedback.dataset.state = 'error';
      } finally {
        submitButton.disabled = false;
        submitButton.textContent = 'Enviar convite';
      }
    });
    await refreshUsers();
  }

  async function renderTransfer() {
    if (window.FokusLawAccessUsers) return window.FokusLawAccessUsers.renderTransfer(context, contentRegion);
  }

  async function renderCompany(successMessage = '') {
    contentRegion.replaceChildren();
    const heading = element('div', 'law-company-heading');
    const headingCopy = element('div');
    headingCopy.append(element('p', 'law-page-eyebrow', 'ADMINISTRAÇÃO DA EMPRESA'));
    headingCopy.append(element('h2', '', 'Dados da empresa'));
    headingCopy.append(element('p', 'law-page-lede', 'Identidade, contato e endereço da empresa ativa.'));
    heading.append(headingCopy);
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
      { title: 'Identificação', eyebrow: '01 / EMPRESA', description: 'Dados oficiais e nome usado nas comunicações.', iconName: 'company', variant: 'identity', fields: ['legal_name', 'display_name', 'document', 'status'] },
      { title: 'Contato institucional', eyebrow: '02 / CONTATO', description: 'Canais públicos da organização.', iconName: 'users', variant: 'contact', fields: ['contact_email', 'contact_phone', 'website'] },
      { title: 'Endereço', eyebrow: '03 / LOCALIZAÇÃO', description: 'Referência física da empresa.', iconName: 'company', variant: 'address', fields: ['address_postal_code', 'address_street', 'address_number', 'address_complement', 'address_district', 'address_city', 'address_state'] },
    ];
    groups.forEach((group) => {
      const card = element('section', `law-company-card law-company-card-${group.variant}`);
      const cardHeading = element('div', 'law-company-card-heading');
      const cardCopy = element('div');
      cardCopy.append(element('p', 'law-card-eyebrow', group.eyebrow), element('h3', '', group.title), element('p', 'law-card-description', group.description));
      const symbol = element('span', 'law-company-card-symbol');
      symbol.append(icon(group.iconName));
      cardHeading.append(cardCopy, symbol);
      card.append(cardHeading);
      const details = element('dl', 'law-company-details');
      group.fields.forEach((field) => {
        const row = element('div', `law-company-detail law-company-detail-${field}`);
        row.append(element('dt', '', labels[field]));
        const value = field === 'document' ? formatDocument(profile.company.document_type, profile.company.document_number)
        : field === 'status' ? companyStatus(profile.company.status) : profile.company[field];
        const display = element('dd', '', value || 'Não informado');
        if (!value) display.classList.add('is-empty');
        if (field === 'status') { display.classList.add('law-company-status'); display.dataset.state = profile.company.status || 'unknown'; }
        row.append(display);
        details.append(row);
      });
      card.append(details);
      sections.append(card);
    });

    const edit = element('button', 'fs-btn fs-btn-primary law-company-edit', 'Editar dados');
    edit.type = 'button';
    edit.hidden = Boolean(context.support_mode);
    heading.append(edit);

    const historyCard = element('section', 'law-company-card law-company-history');
    const historyHeading = element('div', 'law-company-card-heading');
    const historyCopy = element('div');
    historyCopy.append(element('p', 'law-card-eyebrow', 'REGISTRO DA EMPRESA'), element('h3', '', 'Histórico de alterações'), element('p', 'law-card-description', 'Veja quem atualizou a ficha e quais dados foram alterados.'));
    historyHeading.append(historyCopy);
    historyCard.append(historyHeading);
    const history = element('ol', 'law-company-history-list');
    if (!profile.audit.length) history.append(element('li', 'law-company-history-empty', 'Nenhuma alteração registrada.'));
    profile.audit.forEach((entry) => {
      const item = element('li', 'law-company-history-item');
      const changedFields = Object.keys(entry.after || {}).map((field) => labels[field] || field);
      const date = new Date(String(entry.created_at).replace(' ', 'T'));
      const dateLabel = Number.isNaN(date.getTime()) ? entry.created_at : new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short' }).format(date);
      const fullDate = Number.isNaN(date.getTime()) ? entry.created_at : new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
      item.append(element('span', 'law-company-history-date', dateLabel));
      const event = element('div', 'law-company-history-event');
      event.append(element('strong', '', 'Dados da empresa atualizados'));
      event.append(element('span', 'law-company-history-meta', `${entry.actor_name || 'Administrador'} · ${fullDate}`));
      if (changedFields.length) {
        const chips = element('div', 'law-company-history-fields');
        changedFields.forEach((field) => chips.append(element('span', '', field)));
        event.append(chips);
      }
      item.append(event);
      history.append(item);
    });
    historyCard.append(history);
    sections.append(historyCard);
    sections.setAttribute('aria-busy', 'false');

    edit.addEventListener('click', () => {
      edit.hidden = true;
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
      if (contactsView === 'contacts-sharing') window.FokusLawContacts?.renderSharingPage(contentRegion, context);
      else if (contactsView === 'contacts-quality') window.FokusLawContacts?.renderQualityPage(contentRegion, context);
      else renderContacts();
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

  function renderContacts() {
    document.title = 'Contatos | Fokus Law';
    window.FokusLawContacts?.render(contentRegion, context);
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

    const currentItems = new Map((current.items || []).map((item) => [item.module_code || item.conditions?.catalog_module_code || item.conditions?.module_code || item.family, item]));
    const activePlan = plans.find((plan) => plan.code === current.plan_code);
    const activeSegment = activePlan?.segment || current.segment || (current.items || []).flatMap((item) => item.conditions?.segments || [])[0] || null;
    const activeContexts = new Set((current.items || []).map((item) => item.conditions?.context_code).filter(Boolean));
    if (!activeContexts.size && activePlan) {
      activePlan.module_codes?.forEach((code) => {
        const contextCode = modules.find((module) => module.code === code)?.context_code;
        if (contextCode) activeContexts.add(contextCode);
      });
    }
    const availableModules = modules.filter((module) =>
      (!activeSegment || !module.segments?.length || module.segments.includes(activeSegment))
      && (!activeContexts.size || !module.context_code || activeContexts.has(module.context_code)));
    const availableCodes = new Set(availableModules.map((module) => module.code));
    const availablePlans = plans.filter((plan) =>
      (!activeSegment || plan.segment === activeSegment)
      && (plan.module_codes || []).every((code) => availableCodes.has(code)));
    const pendingChange = data.pending_change;
    const summary = element('section', 'law-subscription-overview');
    const summaryTop = element('div', 'law-subscription-overview-top');
    summaryTop.append(element('p', 'law-page-eyebrow', 'ASSINATURA ATUAL'));
    summaryTop.append(element('span', 'law-subscription-status', current.status || 'Ativa'));
    summary.append(summaryTop);
    summary.append(element('h3', '', current.plan_name || 'Composição personalizada'));
    summary.append(element('p', 'law-subscription-current-caption', 'Plano e recursos em uso pela empresa.'));
    summary.append(element('span', 'law-subscription-price-label', 'VALOR ATUAL'));
    const price = element('strong', 'law-subscription-price', formatLawMoney(current.amount));
    price.append(element('span', '', current.billing_cycle === 'annual' ? ' / ano' : ' / mês')); summary.append(price);
    const facts = element('div', 'law-subscription-facts');
    [['Situação', current.status], ['Ciclo', current.billing_cycle === 'annual' ? 'Anual' : 'Mensal'], ['Período até', formatLawDate(current.current_period_ends_at)]].forEach(([label, value]) => {
      const fact = element('div', 'law-subscription-fact'); fact.append(element('span', '', label), element('strong', '', value || '—')); facts.append(fact);
    });
    summary.append(facts); contentRegion.append(summary);

    const vouchers = data.voucher_benefits || [];
    if (vouchers.length) {
      const activeVoucher = vouchers.find((voucher) => !voucher.benefit_ends_at || new Date(voucher.benefit_ends_at) >= new Date()) || vouchers[0];
      const benefitCard = element('section', 'law-voucher-card');
      const benefitCopy = element('div');
      benefitCopy.append(element('p', 'law-card-eyebrow', 'BENEFÍCIO DA ASSINATURA'));
      benefitCopy.append(element('h3', '', lawVoucherLabel(activeVoucher)));
      const voucherName = activeVoucher.name && activeVoucher.name !== activeVoucher.code ? `${activeVoucher.name} · ` : '';
      benefitCopy.append(element('p', '', `${voucherName}Cupom ${activeVoucher.code}`));
      const benefitMeta = element('div', 'law-voucher-meta');
      const expired = activeVoucher.benefit_ends_at && new Date(activeVoucher.benefit_ends_at) < new Date();
      benefitMeta.append(element('strong', '', expired ? 'Benefício encerrado' : activeVoucher.benefit_ends_at ? `Válido até ${formatLawDate(activeVoucher.benefit_ends_at)}` : 'Benefício aplicado'));
      if (activeVoucher.discount_amount > 0) benefitMeta.append(element('span', '', `${formatLawMoney(activeVoucher.discount_amount)} de economia aplicada`));
      benefitCard.append(benefitCopy, benefitMeta);
      contentRegion.append(benefitCard);
    }

    const form = element('form', 'law-subscription-builder');
    form.append(element('p', 'law-card-eyebrow', 'GERENCIAR ASSINATURA'));
    form.append(element('h3', '', 'Planos, módulos e capacidades'));
    form.append(element('p', 'law-page-lede', 'Escolha o plano, o ciclo de cobrança e a capacidade dos módulos disponíveis para sua empresa.'));
    const controls = element('div', 'law-subscription-controls');
    const planWrap = lawSubscriptionField('Plano publicado');
    const planSelect = element('select', 'fs-form-control'); planSelect.name = 'target_plan_id'; planSelect.append(new Option('Composição personalizada', ''));
    availablePlans.forEach((plan) => planSelect.append(new Option(`${plan.name} · ${formatLawMoney(plan.monthly_amount)}/mês`, plan.id)));
    planSelect.value = availablePlans.find((plan) => plan.code === current.plan_code)?.id || '';
    planWrap.append(planSelect); controls.append(planWrap);
    const cycleWrap = lawSubscriptionField('Forma de cobrança');
    const cycleSelect = element('select', 'fs-form-control'); cycleSelect.append(new Option('Mensal', 'monthly'), new Option('Anual', 'annual')); cycleSelect.value = current.billing_cycle || 'monthly'; cycleWrap.append(cycleSelect); controls.append(cycleWrap);
    form.append(controls);

    const scope = activeContexts.has('judiciario') ? 'Judiciário' : activeContexts.has('orgao_publico') ? 'Órgão público' : activeSegment === 'advocacia' ? 'Advocacia' : activeSegment === 'setor_publico' ? 'Setor público' : 'Sua empresa';
    form.append(element('p', 'law-subscription-scope', `Módulos disponíveis para ${scope}`));

    const moduleGrid = element('div', 'law-subscription-module-grid');
    const moduleControls = new Map();
    availableModules.forEach((module) => {
      const card = element('article', 'law-subscription-module-card');
      card.dataset.selected = currentItems.has(module.code) ? 'true' : 'false';
      const cardTop = element('div', 'law-subscription-module-top');
      const moduleSymbol = element('span', 'law-subscription-module-symbol');
      moduleSymbol.append(icon(MODULES[module.module_code]?.icon || 'overview'));
      const selectionState = element('span', 'law-subscription-module-state', currentItems.has(module.code) ? 'Contratado' : 'Disponível');
      cardTop.append(moduleSymbol, selectionState); card.append(cardTop);
      const label = element('label', 'law-subscription-module-title');
      const checkbox = element('input'); checkbox.type = 'checkbox'; checkbox.value = module.code; checkbox.checked = currentItems.has(module.code);
      label.append(checkbox, element('strong', '', module.name)); card.append(label);
      if (module.description) card.append(element('p', 'law-subscription-module-description', module.description));
      const capabilities = module.capability_items || (module.capabilities || []).map((name) => ({ name }));
      if (capabilities.length) { const list = element('ul', 'law-subscription-features'); capabilities.forEach((feature) => list.append(element('li', '', feature.name))); card.append(list); }
      const personalizationBox = element('div', 'law-subscription-personalizations');
      (module.personalizations || []).filter((p) => p.active).forEach((p) => {
        const selected = currentItems.get(module.code)?.conditions?.personalizations?.find((entry) => entry.type_code === p.type_code);
        const field = lawSubscriptionField(p.name || p.type_label || p.label || p.type_code);
        const select = element('select', 'fs-form-control'); select.dataset.typeCode = p.type_code;
        (p.tiers || []).filter((tier) => tier.active).forEach((tier) => select.append(new Option(`${Number(tier.value).toLocaleString('pt-BR')} · ${formatLawMoney(tier.additional_monthly_amount)}/mês`, tier.value)));
        if (selected?.value) select.value = String(selected.value);
        if (!select.options.length) return;
        field.append(select);
        if (p.type_code === 'contatos_cadastrados' && currentItems.has(module.code) && data.usage?.contatos_cadastrados?.available) {
          const usage = data.usage.contatos_cadastrados;
          const note = element('div', 'law-subscription-usage');
          const update = () => {
            const currentLimit = Number(usage.limit || 0);
            const percent = currentLimit ? Math.round(usage.used / currentLimit * 1000) / 10 : 0;
            note.dataset.state = percent > 70 ? 'warning' : 'normal';
            const caption = element('p', 'law-subscription-usage-caption');
            caption.append(element('span', '', 'Uso da capacidade atual'), element('strong', '', `${usage.used.toLocaleString('pt-BR')} / ${currentLimit.toLocaleString('pt-BR')}`));
            const track = element('span', 'law-subscription-usage-track');
            const fill = element('span', 'law-subscription-usage-fill'); fill.style.width = `${Math.min(percent, 100)}%`; track.append(fill);
            note.replaceChildren(caption, track);
            if (percent > 70) note.append(element('p', 'law-subscription-usage-warning', 'Uso acima de 70%. Considere ampliar esta capacidade.'));
            if (Number(select.value) !== currentLimit) note.append(element('p', 'law-subscription-usage-preview', `Nova capacidade selecionada: ${Number(select.value).toLocaleString('pt-BR')}.`));
          };
          select.addEventListener('change', update); update(); field.append(note);
        }
        personalizationBox.append(field);
      });
      card.append(personalizationBox); moduleGrid.append(card); moduleControls.set(module.code, checkbox);
      checkbox.addEventListener('change', () => { card.dataset.selected = checkbox.checked ? 'true' : 'false'; selectionState.textContent = checkbox.checked ? 'Selecionado' : 'Disponível'; planSelect.value = ''; });
    });
    form.append(moduleGrid);
    planSelect.addEventListener('change', () => { const plan = availablePlans.find((item) => item.id === planSelect.value); if (!plan) return; moduleControls.forEach((checkbox, code) => { checkbox.checked = Boolean(plan.module_codes?.includes(code)); const card = checkbox.closest('.law-subscription-module-card'); card.dataset.selected = checkbox.checked ? 'true' : 'false'; card.querySelector('.law-subscription-module-state').textContent = checkbox.checked ? 'Selecionado' : 'Disponível'; }); });

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
      if (pendingChange.status === 'aguardando_pagamento') {
        const checkout = (data.payments || []).find((payment) => payment.status === 'aguardando_pagamento' && payment.checkout_url)?.checkout_url;
        if (checkout) { const resume = element('a', 'fs-btn fs-btn-primary', 'Continuar pagamento'); resume.href = checkout; pending.append(resume); }
      }
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
  function lawVoucherLabel(voucher) {
    const value = Number(voucher.discount_value || 0);
    if (voucher.discount_type === 'trial_free') return 'Assinatura com período gratuito';
    if (voucher.discount_type === 'percentage') return `${value.toLocaleString('pt-BR')}% de desconto`;
    if (voucher.discount_type === 'commercial_credit') return `Crédito de ${formatLawMoney(value)}`;
    return `Desconto de ${formatLawMoney(value)}`;
  }
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
    else if (view === 'users') renderUsers();
    else if (view === 'transfer') renderTransfer();
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
    const contactsModule = visibleModules().find((module) => String(module.family || module.module_code || module.code || '').toLowerCase().startsWith('contatos'));
    const canSearchContacts = Boolean(contactsModule && canLawPermission('law.contacts.view'));
    let searchTimer;
    let searchRequest = 0;
    search.disabled = !canSearchContacts;
    search.placeholder = canSearchContacts ? 'Pesquisar contatos' : 'Busca de contatos indisponível';
    search.setAttribute('role', 'combobox');
    search.setAttribute('aria-autocomplete', 'list');

    const hideSearchState = () => {
      window.clearTimeout(searchTimer);
      searchRequest += 1;
      searchState.hidden = true;
      search.setAttribute('aria-expanded', 'false');
    };
    const showSearchMessage = (message, state = '') => {
      const note = element('p', `law-search-message${state ? ` is-${state}` : ''}`, message);
      searchState.replaceChildren(note);
      searchState.hidden = false;
      search.setAttribute('aria-expanded', 'true');
    };
    const showSearchResults = (contacts) => {
      searchState.replaceChildren();
      if (!contacts.length) {
        showSearchMessage('Nenhum contato encontrado.');
        return;
      }

      const heading = element('p', 'law-search-results-heading', 'CONTATOS');
      searchState.append(heading);
      contacts.forEach((contact, index) => {
        const result = element('button', 'law-search-result');
        result.type = 'button';
        result.setAttribute('role', 'option');
        result.setAttribute('aria-selected', 'false');
        result.dataset.contactSearchResult = String(index);
        const name = element('strong', '', contact.display_name || 'Contato sem nome');
        const professions = (contact.professions || []).slice(0, 2).join(', ');
        const detail = [contact.legal_nature === 'pj' ? 'Pessoa jurídica' : 'Pessoa física', professions, contact.status === 'inativo' ? 'Inativo' : 'Ativo']
          .filter(Boolean).join(' · ');
        result.append(name, element('span', '', detail));
        result.addEventListener('click', async () => {
          hideSearchState();
          activeGroup = `module:${contactsModule.id}`;
          renderNavigation();
          closeMobileNav();
          contentRegion.focus({ preventScroll: true });
          try {
            await window.FokusLawContacts?.openSearchedContact(contentRegion, contact.id, Boolean(contact.is_shared), search);
          } catch (error) {
            showSearchMessage(error.message || 'Não foi possível abrir este contato.', 'error');
            search.focus();
          }
        });
        result.addEventListener('keydown', (event) => {
          const options = [...searchState.querySelectorAll('[data-contact-search-result]')];
          const currentIndex = options.indexOf(result);
          if (event.key === 'ArrowDown') { event.preventDefault(); options[(currentIndex + 1) % options.length]?.focus(); }
          if (event.key === 'ArrowUp') { event.preventDefault(); if (currentIndex === 0) search.focus(); else options[currentIndex - 1]?.focus(); }
          if (event.key === 'Escape') { hideSearchState(); search.focus(); }
        });
        searchState.append(result);
      });
      searchState.hidden = false;
      search.setAttribute('aria-expanded', 'true');
    };

    search.addEventListener('focus', () => {
      if (!canSearchContacts) return;
      if (search.value.trim().length >= 2) {
        search.dispatchEvent(new Event('input'));
        return;
      }
      showSearchMessage('Digite ao menos 2 caracteres para buscar nos contatos.');
    });
    search.addEventListener('input', () => {
      window.clearTimeout(searchTimer);
      const query = search.value.trim();
      if (!canSearchContacts) return;
      if (query.length < 2) {
        searchRequest += 1;
        showSearchMessage('Digite ao menos 2 caracteres para buscar nos contatos.');
        return;
      }
      showSearchMessage('Buscando contatos…');
      const requestId = ++searchRequest;
      searchTimer = window.setTimeout(async () => {
        const params = new URLSearchParams({ q: query, page: '1', per_page: '10' });
        try {
          const result = await FokusApi.request(`/law/contacts?${params.toString()}`);
          if (requestId !== searchRequest || search.value.trim() !== query) return;
          showSearchResults(result.contacts || []);
        } catch (error) {
          if (requestId !== searchRequest) return;
          showSearchMessage(error.message || 'Não foi possível pesquisar contatos.', 'error');
        }
      }, 220);
    });
    search.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowDown') {
        const firstResult = searchState.querySelector('[data-contact-search-result]');
        if (firstResult && !searchState.hidden) { event.preventDefault(); firstResult.focus(); }
      }
      if (event.key === 'Enter' && !searchState.hidden) {
        const firstResult = searchState.querySelector('[data-contact-search-result]');
        if (firstResult) { event.preventDefault(); firstResult.click(); }
      }
      if (event.key === 'Escape') hideSearchState();
    });
    document.addEventListener('click', (event) => {
      if (!event.target.closest('.law-search-wrap')) hideSearchState();
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
        hideSearchState();
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
    if (!['profile', 'users', 'transfer'].includes(initialPage)) document.title = initialPage === 'company' ? 'Empresa | Fokus Law' : 'Fokus Law | Fokus Cloud';
    document.querySelector('#user-name').textContent = context.user.name;
    document.querySelector('#user-email').textContent = context.user.email;
    document.querySelector('#user-avatar').textContent = context.user.name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    document.querySelector('#subscription-label').textContent = context.subscription?.label || 'Fokus Law';
    renderCompanyOptions();
    renderUnitOptions();
    const remember = localStorage.getItem(preferenceKey(context.user.id, 'remember-group')) === 'true';
    if (!['profile', 'company', 'subscription', 'users', 'transfer', 'contacts-sharing', 'contacts-quality'].includes(initialPage) && remember) {
      const lastGroup = localStorage.getItem(preferenceKey(context.user.id, 'last-group'));
      if ((lastGroup === 'settings' && context.permissions.manage_settings) || visibleModules().some((item) => `module:${item.id}` === lastGroup)) activeGroup = lastGroup;
    }
    if (['contacts-sharing', 'contacts-quality'].includes(initialPage)) {
      const contactsModule = visibleModules().find((item) => String(item.family || item.module_code || item.code || '').toLowerCase().startsWith('contatos'));
      if (contactsModule) activeGroup = `module:${contactsModule.id}`;
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

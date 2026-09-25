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
  let activeGroup = 'overview';

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
    addRailButton('settings', 'Configurações', 'settings');
  }

  function appendNavLink(container, label, href, iconName, selected = false, disabled = false) {
    const link = element('a', 'law-nav-item');
    link.href = href;
    if (selected) link.setAttribute('aria-current', 'page');
    link.append(icon(iconName));
    link.append(element('span', '', label));
    if (disabled) {
      link.setAttribute('aria-disabled', 'true');
      link.addEventListener('click', (event) => event.preventDefault());
    }
    container.append(link);
  }

  function appendNavButton(container, label, iconName, selected, onClick) {
    const button = element('button', 'law-nav-item');
    button.type = 'button';
    if (selected) button.setAttribute('aria-current', 'page');
    button.append(icon(iconName));
    button.append(element('span', '', label));
    button.addEventListener('click', onClick);
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
      appendNavButton(pageItems, 'Resumo da empresa', 'overview', true, () => renderContent('overview'));
      renderContent('overview');
      return;
    }

    if (activeGroup === 'settings') {
      sectionTitle.textContent = 'Configurações';
      headingIcon = 'settings';
      document.querySelector('#section-icon').src = ICON_ROOT + ICONS[headingIcon];
      const list = element('ul');
      const entries = [
        ['Perfil', '/portal/perfil', 'profile'],
        ['Empresas', '/portal/empresas', 'company'],
      ];
      if (context.permissions.manage_company_users) entries.push(['Usuários e acessos', '/portal/usuarios', 'users']);
      entries.forEach(([label, href, iconName]) => {
        const item = element('li');
        appendNavLink(item, label, href, iconName);
        list.append(item);
      });
      const preferencesItem = element('li');
      appendNavButton(preferencesItem, 'Preferências do Fokus Law', 'settings', true, () => renderContent('preferences'));
      list.append(preferencesItem);
      pageItems.append(list);
      renderContent('settings');
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
    appendNavButton(pageItems, `Visão geral de ${descriptor.label}`, descriptor.icon, true, () => renderContent('module'));
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
      ['Empresa ativa', context.company.name],
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
    heading.append(element('p', 'law-page-eyebrow', 'PREFERÊNCIAS DO PRODUTO'));
    heading.append(element('h2', '', 'Configurações'));
    heading.append(element('p', 'law-page-lede', 'Gerencie sua conta e as preferências da sua experiência no Fokus Law.'));
    contentRegion.append(heading);

    const list = element('div', 'law-settings-list');
    const links = [
      ['Meu perfil', 'Atualize seus dados de acesso no portal Fokus Cloud.', '/portal/perfil', 'profile'],
      ['Empresas', 'Consulte e escolha a empresa ativa da sua sessão.', '/portal/empresas', 'company'],
    ];
    if (context.permissions.manage_company_users) links.push(['Usuários e acessos', 'Gerencie os vínculos de usuários da empresa.', '/portal/usuarios', 'users']);
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
    contentRegion.append(list);
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

  function renderModulePlaceholder(module) {
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

  function renderContent(view) {
    contentRegion.replaceChildren();
    contentRegion.dataset.view = view;
    if (view === 'overview') renderOverview();
    else if (view === 'settings') renderSettings();
    else if (view === 'preferences') renderPreferences();
    else {
      const module = activeModule();
      if (module) renderModulePlaceholder(module);
      else renderOverview();
    }
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

  function setContext(value) {
    context = value;
    document.querySelector('#user-name').textContent = context.user.name;
    document.querySelector('#user-email').textContent = context.user.email;
    document.querySelector('#user-avatar').textContent = context.user.name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    document.querySelector('#subscription-label').textContent = context.subscription?.label || 'Fokus Law';
    renderCompanyOptions();
    const remember = localStorage.getItem(preferenceKey(context.user.id, 'remember-group')) === 'true';
    if (remember) {
      const lastGroup = localStorage.getItem(preferenceKey(context.user.id, 'last-group'));
      if (lastGroup === 'settings' || context.modules.some((item) => `module:${item.id}` === lastGroup)) activeGroup = lastGroup;
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

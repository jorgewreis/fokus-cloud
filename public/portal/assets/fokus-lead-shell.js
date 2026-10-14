(() => {
  const shell = document.querySelector('#lead-shell');
  const loading = document.querySelector('#lead-loading');
  const page = shell.dataset.initialPage === 'profile' ? 'profile' : 'dashboard';
  const sidebar = document.querySelector('#lead-sidebar');
  const menuButton = document.querySelector('#lead-menu-button');
  const scrim = document.querySelector('#lead-scrim');
  const optionLists = [document.querySelector('#lead-company-options'), document.querySelector('#lead-top-company-options')];
  const switchButtons = [document.querySelector('#lead-company-button'), document.querySelector('#lead-top-company-button')];
  const initials = (name) => String(name || '').trim().split(/\s+/).slice(0, 2).map((part) => part[0] || '').join('').toUpperCase() || 'FL';
  let context;

  const closeMenu = (restore = false) => {
    sidebar.classList.remove('is-open');
    scrim.hidden = true;
    menuButton.setAttribute('aria-expanded', 'false');
    menuButton.setAttribute('aria-label', 'Abrir menu');
    if (restore) menuButton.focus();
  };

  function renderCompanies() {
    optionLists.forEach((list) => {
      list.replaceChildren();
      context.companies.forEach((company) => {
        const button = document.createElement('button');
        button.type = 'button'; button.className = 'lead-company-option'; button.setAttribute('role', 'option');
        button.textContent = company.name;
        button.setAttribute('aria-selected', String(company.id === context.active_company_id));
        button.addEventListener('click', async () => {
          if (company.id === context.active_company_id) { optionLists.forEach((item) => { item.hidden = true; }); switchButtons.forEach((item) => item.setAttribute('aria-expanded', 'false')); return; }
          button.disabled = true;
          try {
            await FokusApi.request('/auth/select-company', { method: 'POST', body: { company_id: company.id } });
            window.location.assign(page === 'profile' ? '/portal/fokus-lead/perfil' : '/portal/fokus-lead');
          } catch (error) { button.disabled = false; button.title = error.message || 'Não foi possível trocar de empresa.'; }
        });
        list.append(button);
      });
    });
    const canSwitch = context.companies.length > 1;
    switchButtons.forEach((button) => { button.hidden = !canSwitch; });
    if (!canSwitch) optionLists.forEach((list) => { list.hidden = true; });
  }

  function setContext(value) {
    context = value;
    const company = value.company.name || value.company.legal_name;
    document.querySelector('#lead-company-name').textContent = company;
    document.querySelector('#lead-top-company-name').textContent = company;
    document.querySelector('#lead-top-company').textContent = company;
    document.querySelector('#lead-subscription').textContent = value.subscription.label || 'Fokus Lead';
    document.querySelector('#lead-user-name').textContent = value.user.name;
    document.querySelector('#lead-user-email').textContent = value.user.email;
    document.querySelector('#lead-avatar').textContent = initials(value.user.name);
    document.querySelector('#lead-welcome-user').textContent = value.user.name.split(/\s+/)[0];
    document.querySelector('#lead-dashboard-company').textContent = company;
    document.querySelector('#lead-dashboard-subscription').textContent = value.subscription.label || 'Fokus Lead';
    document.querySelector('#lead-dashboard-role').textContent = value.company.role;
    document.querySelector('#lead-dashboard').hidden = page !== 'dashboard';
    document.querySelector('#lead-profile').hidden = page !== 'profile';
    document.querySelector('#lead-dashboard-link').removeAttribute('aria-current');
    document.querySelector('#lead-profile-link').removeAttribute('aria-current');
    document.querySelector(`#lead-${page}-link`).setAttribute('aria-current', 'page');
    document.title = page === 'profile' ? 'Meu perfil | Fokus Lead' : 'Fokus Lead | Fokus Cloud';
    renderCompanies();
    if (page === 'profile' && window.initializeLawProfile) window.initializeLawProfile();
  }

  switchButtons.forEach((button) => button.addEventListener('click', () => {
    const ownList = button.id === 'lead-top-company-button' ? optionLists[1] : optionLists[0];
    const open = ownList.hidden;
    optionLists.forEach((list) => { list.hidden = true; });
    ownList.hidden = !open;
    switchButtons.forEach((item) => item.setAttribute('aria-expanded', String(open)));
    if (open) ownList.querySelector('[role="option"]')?.focus();
  }));
  document.addEventListener('click', (event) => {
    if (!event.target.closest('#lead-company-button, #lead-top-company-button, #lead-company-options, #lead-top-company-options')) {
      optionLists.forEach((list) => { list.hidden = true; }); switchButtons.forEach((item) => item.setAttribute('aria-expanded', 'false'));
    }
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      const wasOpen = sidebar.classList.contains('is-open');
      optionLists.forEach((list) => { list.hidden = true; }); switchButtons.forEach((item) => item.setAttribute('aria-expanded', 'false'));
      closeMenu(wasOpen);
    }
  });
  menuButton.addEventListener('click', () => {
    const open = !sidebar.classList.contains('is-open');
    sidebar.classList.toggle('is-open', open); scrim.hidden = !open;
    menuButton.setAttribute('aria-expanded', String(open)); menuButton.setAttribute('aria-label', open ? 'Fechar menu' : 'Abrir menu');
    if (open) sidebar.querySelector('nav a')?.focus();
  });
  scrim.addEventListener('click', () => closeMenu());
  sidebar.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => closeMenu()));
  document.querySelector('#lead-logout').addEventListener('click', async (event) => {
    event.currentTarget.disabled = true;
    try { await FokusApi.request('/auth/logout', { method: 'POST' }); }
    finally { window.location.assign('/?acesso=cliente'); }
  });

  FokusApi.request('/lead/shell-context').then((value) => {
    setContext(value); loading.hidden = true; shell.hidden = false;
  }).catch((error) => {
    if (error.status === 401) window.location.assign('/?acesso=cliente');
    else if (error.status === 409) window.location.assign('/portal/empresas');
    else if (error.status === 403 && /e-mail/i.test(error.message)) window.location.assign('/verificar-email');
    else if (error.status === 403) window.location.assign('/portal/empresas');
    else loading.textContent = error.message || 'Não foi possível carregar o Fokus Lead. Atualize a página ou entre novamente.';
  });
})();

(function () {
  const path = window.location.pathname;
  const links = [
    ['/portal', 'Visão geral'],
    ['/portal/assinaturas', 'Assinaturas'],
    ['/portal/usuarios', 'Usuários'],
    ['/portal/empresas', 'Empresas'],
    ['/portal/fokus-law/perfil', 'Meu perfil']
  ];
  function mount() {
    document.documentElement.dataset.role = 'user';
    document.documentElement.dataset.theme = 'light';
    document.body.classList.add('portal-page');
    const main = document.querySelector('main');
    if (!main || main.dataset.portalMounted) return;
    main.dataset.portalMounted = 'true';
    const shell = document.createElement('div'); shell.className = 'portal-shell';
    const aside = document.createElement('aside'); aside.className = 'portal-sidebar';
    aside.innerHTML = `<a class="portal-brand" href="/portal">Fokus<span>Cloud</span></a><div class="portal-context" id="portal-context">Empresa ativa<strong>Carregando...</strong></div><nav class="portal-nav" aria-label="Navegação principal">${links.map(([href, label]) => `<a href="${href}" class="${path === href ? 'active' : ''}">${label}</a>`).join('')}</nav><button type="button" id="portal-logout" class="portal-logout">Sair</button>`;
    const content = document.createElement('div'); content.className = 'portal-main';
    const inner = document.createElement('div'); inner.className = 'portal-content';
    const header = document.createElement('header'); header.className = 'portal-header';
    header.innerHTML = `<div><p class="portal-eyebrow">Portal do cliente</p><h1>${document.title.split('|')[0].trim()}</h1></div><p id="portal-user-name">Carregando conta...</p>`;
    const supportNotice = document.createElement('section'); supportNotice.className = 'fs-alert fs-alert-warning'; supportNotice.hidden = true; supportNotice.setAttribute('role', 'status'); supportNotice.innerHTML = '<div><strong>Modo de suporte ativo</strong><p data-support-context></p><button type="button" class="fs-btn fs-btn-outline-primary" data-support-exit>Encerrar acesso de suporte</button></div>';
    inner.append(header, supportNotice, main); content.append(inner); shell.append(aside, content); document.body.append(shell);
    let supportModeActive = false;
    const exitSupport = async () => {
      try { const result = await FokusApi.request('/backoffice/support/exit', { method: 'POST' }); location.assign(result.redirect_to || '/backoffice/'); }
      catch (error) { window.FokusToast?.show(error.message || 'Não foi possível encerrar o acesso de suporte.', 'danger'); }
    };
    const showSupportMode = () => {
      if (supportModeActive) return;
      supportModeActive = true;
      document.querySelector('#portal-user-name').textContent = 'Acesso de suporte';
      document.querySelector('#portal-context strong').textContent = 'Dados do cliente indisponíveis';
      supportNotice.hidden = false;
      supportNotice.querySelector('[data-support-context]').textContent = 'Os dados do cliente não ficam disponíveis durante o acesso de suporte.';
      supportNotice.querySelector('[data-support-exit]').addEventListener('click', exitSupport);
      document.dispatchEvent(new CustomEvent('fokus:support-mode'));
    };
    document.querySelector('#portal-logout').onclick = async () => {
      if (supportModeActive) {
        await exitSupport();
        return;
      }
      try { await FokusApi.request('/auth/logout', { method: 'POST' }); }
      catch (error) {
        if (error.status === 403 && error.payload?.code === 'support_customer_api_denied') {
          await exitSupport();
          return;
        }
      }
      location.assign('/');
    };
    if (window.FokusApi) window.FokusApi.request('/auth/me').then((data) => {
      const company = data.companies?.find((item) => item.id === data.active_company_id);
      document.querySelector('#portal-user-name').textContent = data.user?.name || '';
      document.querySelector('#portal-context strong').textContent = company?.name || 'Nenhuma empresa selecionada';
    }).catch(async (error) => {
      if (error.status === 401 && await window.FokusApi.supportSessionActive()) showSupportMode();
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();

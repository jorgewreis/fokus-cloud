(() => {
  const form = document.querySelector('[data-law-login-form]');

  const showToast = (message) => {
    let container = document.querySelector('[data-law-toast-container]');
    if (!container) {
      container = document.createElement('div');
      container.className = 'fs-toast-container';
      container.dataset.lawToastContainer = '';
      container.setAttribute('aria-live', 'polite');
      document.body.append(container);
    }

    let toast = container.querySelector('[data-law-toast]');
    if (!toast) {
      toast = document.createElement('div');
      toast.className = 'fs-toast fs-toast-danger is-progressing';
      toast.dataset.lawToast = '';
      toast.dataset.toastProgress = 'true';
      toast.dataset.autohide = 'true';
      toast.setAttribute('role', 'alert');
      toast.innerHTML = '<div><strong class="fs-toast-title">Não foi possível continuar</strong><span class="fs-toast-message"></span></div><button type="button" class="icon-button icon-button-close fs-toast-close" aria-label="Fechar">×</button><span class="fs-toast-progress"></span>';
      toast.querySelector('.fs-toast-close').addEventListener('click', () => { toast.hidden = true; });
      container.append(toast);
    }
    toast.querySelector('.fs-toast-message').textContent = message;
    toast.hidden = false;
    clearTimeout(showToast.timer);
    showToast.timer = setTimeout(() => { toast.hidden = true; }, 4200);
  };

  if (form) {
    const email = form.elements.email;
    const system = form.elements.system;
    const profile = form.elements.profile;
    const password = form.elements.password;
    const submit = form.querySelector('button[type="submit"]');
    const status = form.querySelector('[data-law-login-status]');
    const profileField = profile.closest('div');
    let lookupTimer;
    let systems = [];
    let userName = '';
    submit.textContent = 'Entrar';

    const reset = (message = 'A identificação começa ao informar seu e-mail.') => {
      systems = [];
      userName = '';
      system.disabled = true;
      system.innerHTML = '<option value="">Aguardando identificação</option>';
      profile.disabled = true;
      profileField.hidden = true;
      profile.innerHTML = '<option value="">Selecione o sistema primeiro</option>';
      password.disabled = true;
      password.value = '';
      submit.disabled = true;
      status.textContent = message;
    };

    const renderProfiles = () => {
      const selected = systems.find((item) => item.value === system.value);
      const availableProfiles = selected?.profiles || [];
      profile.innerHTML = '<option value="">Escolha seu perfil</option>' + availableProfiles.map((item) => `<option value="${item.value}">${item.label}</option>`).join('');
      profileField.hidden = availableProfiles.length < 2;
      profile.disabled = availableProfiles.length < 2;
      password.disabled = availableProfiles.length === 0;
      password.value = '';
      submit.disabled = availableProfiles.length === 0;
      if (availableProfiles.length === 1) {
        profile.value = availableProfiles[0].value;
        status.textContent = `${userName ? `${userName} — ` : ''}Perfil confirmado. Digite sua senha.`;
      } else {
        status.textContent = availableProfiles.length ? `${userName ? `${userName} — ` : ''}Sistema localizado. Escolha o perfil para liberar a senha.` : `${userName ? `${userName} — ` : ''}Nenhum perfil disponível para este sistema.`;
      }
    };

    const renderSystems = (payload) => {
      const items = payload.systems || [];
      userName = payload.user?.name || '';
      systems = items;
      system.innerHTML = systems.map((item) => `<option value="${item.value}">${item.label}</option>`).join('');
      system.disabled = systems.length === 0;
      if (systems.length) {
        system.value = systems[0].value;
        renderProfiles();
      } else {
        reset('Não encontramos um sistema Fokus Law ativo vinculado a este e-mail. Confirme o vínculo com uma empresa e a assinatura ativa do Fokus Law.');
      }
    };

    reset();

    email.addEventListener('input', () => {
      clearTimeout(lookupTimer);
      reset('Informe um e-mail válido para consultar seu cadastro.');
      const value = email.value.trim();
      if (!value) return;
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return;
      status.textContent = 'Consultando os sistemas vinculados ao e-mail…';
      lookupTimer = setTimeout(() => {
        window.FokusApi.request('/auth/law-context', { method: 'POST', body: { email: value } })
          .then(renderSystems)
          .catch((error) => {
            const message = error.status === 404
              ? 'Não encontramos um sistema Fokus Law ativo vinculado a este e-mail. Confirme o vínculo com uma empresa e a assinatura ativa do Fokus Law.'
              : (error.message || 'Não foi possível consultar os sistemas vinculados a este e-mail.');
            reset(message);
            showToast(message);
          });
      }, 420);
    });

    system.addEventListener('change', renderProfiles);
    profile.addEventListener('change', () => {
      password.disabled = !profile.value;
      submit.disabled = !profile.value;
      status.textContent = profile.value ? `${userName ? `${userName} — ` : ''}Perfil confirmado. Digite sua senha.` : 'Escolha seu perfil para continuar.';
      if (!profile.value) password.value = '';
    });
    form.addEventListener('submit', (event) => { event.preventDefault(); status.textContent = 'O acesso contextual será ativado junto à publicação do ambiente Fokus Law.'; });
  }

  const livePlans = document.querySelector('[data-law-live-plans]'), grid = document.querySelector('[data-law-live-plan-grid]');
  if (livePlans && grid && window.FokusApi) window.FokusApi.request('/catalog/law').then((catalog) => { const plans = catalog.plans || []; if (!plans.length) return; grid.innerHTML = plans.map((plan) => `<article class="law-live-plan"><strong>${plan.name}</strong><span>A partir de R$ ${Number(plan.monthly_amount).toLocaleString('pt-BR', { minimumFractionDigits: 2 })} / mês</span></article>`).join(''); livePlans.hidden = false; }).catch(() => {});
})();

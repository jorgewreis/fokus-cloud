(() => {
  const form = document.querySelector('[data-law-login-form]');

  const showToast = (message) => window.FokusToast?.show(message, 'danger');

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
      if (!systems.length) {
        const message = payload.message || 'Não existe nenhuma assinatura ativa do Fokus Law vinculada a este usuário.';
        reset(`${userName ? `${userName} — ` : ''}${message}`);
        showToast(message);
        return;
      }
      system.innerHTML = systems.map((item) => `<option value="${item.value}">${item.label}</option>`).join('');
      system.disabled = systems.length === 0;
      if (systems.length) {
        system.value = systems[0].value;
        renderProfiles();
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
              ? 'Usuário não encontrado.'
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

    const support = document.createElement('section');
    support.hidden = true;
    support.className = 'law-support-access';
    support.setAttribute('aria-labelledby', 'law-support-title');
    support.innerHTML = '<h2 id="law-support-title">Acesso de suporte</h2><p>Escolha uma assinatura e um usuário real da empresa. O acesso será registrado em auditoria.</p><label for="law-support-subscription">Assinatura</label><select id="law-support-subscription" required></select><label for="law-support-user">Usuário e perfil</label><select id="law-support-user" required></select><label for="law-support-reason">Motivo do acesso</label><textarea id="law-support-reason" minlength="10" maxlength="1000" required></textarea><button class="law-submit" type="button" id="law-support-start">Acessar em modo de suporte</button><p role="status" aria-live="polite" id="law-support-status"></p>';
    form.before(support);
    const subscriptionSelect = support.querySelector('#law-support-subscription');
    const userSelect = support.querySelector('#law-support-user');
    const supportStatus = support.querySelector('#law-support-status');
    let supportSubscriptions = [];
    const renderSupportUsers = () => {
      const selected = supportSubscriptions.find((item) => item.id === subscriptionSelect.value);
      userSelect.replaceChildren(new Option('Selecione o usuário', ''));
      (selected?.users || []).forEach((user) => userSelect.add(new Option(user.label, user.membership_id)));
      userSelect.disabled = !selected?.users?.length;
      if (selected && !selected.users.length) supportStatus.textContent = 'Esta assinatura não possui usuários ativos disponíveis para teste.';
    };
    subscriptionSelect.addEventListener('change', renderSupportUsers);
    support.querySelector('#law-support-start').addEventListener('click', async () => {
      const reason = support.querySelector('#law-support-reason').value.trim();
      if (!subscriptionSelect.value || !userSelect.value || reason.length < 10) {
        supportStatus.textContent = 'Selecione assinatura e usuário e informe um motivo com pelo menos 10 caracteres.';
        return;
      }
      supportStatus.textContent = 'Iniciando acesso de suporte…';
      try {
        const result = await window.FokusApi.request('/backoffice/support/access', { method: 'POST', body: { subscription_id: subscriptionSelect.value, membership_id: userSelect.value, reason } });
        location.assign(result.redirect_to || '/portal');
      } catch (error) {
        supportStatus.textContent = error.message || 'Não foi possível iniciar o acesso de suporte.';
        showToast(supportStatus.textContent);
      }
    });
    window.FokusApi.request('/backoffice/auth/me').then(async ({ admin }) => {
      if (admin?.role !== 'superadministrador') return;
      form.hidden = true;
      support.hidden = false;
      supportStatus.textContent = 'Carregando assinaturas Fokus Law…';
      const payload = await window.FokusApi.request('/backoffice/support/law-context');
      supportSubscriptions = payload.subscriptions || [];
      subscriptionSelect.replaceChildren(new Option('Selecione a assinatura', ''));
      supportSubscriptions.forEach((item) => subscriptionSelect.add(new Option(item.label, item.id)));
      subscriptionSelect.disabled = !supportSubscriptions.length;
      supportStatus.textContent = supportSubscriptions.length ? 'Sessão interna autenticada com MFA. O acesso de suporte será auditado.' : 'Nenhuma assinatura Fokus Law foi encontrada.';
    }).catch(() => {});
  }

  const livePlans = document.querySelector('[data-law-live-plans]'), grid = document.querySelector('[data-law-live-plan-grid]');
  if (livePlans && grid && window.FokusApi) window.FokusApi.request('/catalog/law').then((catalog) => { const plans = catalog.plans || []; if (!plans.length) return; grid.innerHTML = plans.map((plan) => `<article class="law-live-plan"><strong>${plan.name}</strong><span>A partir de R$ ${Number(plan.monthly_amount).toLocaleString('pt-BR', { minimumFractionDigits: 2 })} / mês</span></article>`).join(''); livePlans.hidden = false; }).catch(() => {});
})();

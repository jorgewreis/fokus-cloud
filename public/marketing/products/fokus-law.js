(() => {
  const form = document.querySelector('[data-law-login-form]');

  const showToast = (message) => window.FokusToast?.show(message, 'danger');

  if (form) {
    const email = form.elements.email;
    const system = form.elements.system;
    const systemLabel = form.querySelector('#law-system-label');
    const instructions = form.closest('.law-login-card').querySelector('#law-login-instructions');
    const profile = form.elements.profile;
    const password = form.elements.password;
    const submit = form.querySelector('button[type="submit"]');
    const status = form.querySelector('[data-law-login-status]');
    const accessChoice = form.querySelector('[data-law-access-choice]');
    const accessType = form.querySelector('#law-access-type');
    const accessStatus = form.querySelector('[data-law-access-status]');
    const subscriptionLogin = form.querySelector('[data-law-subscription-login]');
    const profileField = profile.closest('div');
    const hero = form.closest('.law-hero');
    let lookupTimer;
    let systems = [];
    submit.textContent = 'Entrar';

    const reset = (message = 'Informe seu e-mail para consultar os acessos.') => {
      systems = [];
      systemLabel.textContent = 'Empresa vinculada';
      instructions.textContent = 'Informe seu e-mail para localizar a empresa vinculada, o sistema e o perfil disponíveis.';
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
      const isPerson = selected?.document_type === 'cpf';
      systemLabel.textContent = isPerson ? 'Pessoa vinculada' : 'Empresa vinculada';
      instructions.textContent = isPerson
        ? 'Informe seu e-mail para localizar a pessoa vinculada, o sistema e o perfil disponíveis.'
        : 'Informe seu e-mail para localizar a empresa vinculada, o sistema e o perfil disponíveis.';
      const availableProfiles = selected?.profiles || [];
      profile.innerHTML = '<option value="">Escolha seu perfil</option>' + availableProfiles.map((item) => `<option value="${item.value}">${item.label}</option>`).join('');
      profileField.hidden = availableProfiles.length < 2;
      profile.disabled = availableProfiles.length < 2;
      password.disabled = availableProfiles.length === 0;
      password.value = '';
      submit.disabled = availableProfiles.length === 0;
      if (availableProfiles.length === 1) {
        profile.value = availableProfiles[0].value;
        status.textContent = 'Informe sua senha.';
      } else {
        status.textContent = availableProfiles.length ? 'Selecione um perfil.' : 'Nenhum perfil disponível.';
      }
    };

    const renderSystems = (payload) => {
      const items = payload.systems || [];
      systems = items;
      if (!systems.length) {
        const message = 'Nenhuma assinatura ativa encontrada.';
        reset(message);
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
      support.hidden = true;
      hero?.classList.remove('law-support-mode');
      accessChoice.hidden = true;
      accessType.value = '';
      accessStatus.textContent = '';
      subscriptionLogin.hidden = false;
      reset('E-mail inválido.');
      const value = email.value.trim();
      if (!value) return;
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return;
      status.textContent = 'Consultando vínculos…';
      lookupTimer = setTimeout(async () => {
        if (!supportAdminReady) {
          status.textContent = 'Verificando permissões de suporte…';
          await supportAdminCheck;
        }
        if (email.value.trim().toLowerCase() !== value.toLowerCase()) return;
        if (supportAdmin && value.toLowerCase() === supportAdmin.email.toLowerCase()) {
          accessChoice.hidden = false;
          subscriptionLogin.hidden = true;
          accessStatus.textContent = 'Selecione o tipo de acesso.';
          return;
        }
        lookupSubscriptionContext(value);
      }, 420);
    });

    const lookupSubscriptionContext = (value) => window.FokusApi.request('/auth/law-context', { method: 'POST', body: { email: value } })
      .then((payload) => {
        if (email.value.trim().toLowerCase() === value.toLowerCase()) renderSystems(payload);
      })
      .catch((error) => {
        if (email.value.trim().toLowerCase() !== value.toLowerCase()) return;
        const message = error.status === 404 ? 'E-mail não localizado.' : 'Falha ao consultar vínculos. Tente novamente.';
        reset(message);
        showToast(message);
      });

    accessType.addEventListener('change', () => {
      const value = email.value.trim();
      support.hidden = true;
      hero?.classList.remove('law-support-mode');
      if (accessType.value === 'subscription') {
        subscriptionLogin.hidden = false;
        accessStatus.textContent = '';
        reset('Consultando assinaturas…');
        lookupSubscriptionContext(value).then(() => {
          if (accessType.value === 'subscription') accessStatus.textContent = '';
        });
      } else if (accessType.value === 'support') {
        subscriptionLogin.hidden = true;
        support.hidden = false;
        hero?.classList.add('law-support-mode');
        support.querySelector('[data-support-email]').textContent = `${supportAdmin.name} — ${supportAdmin.email}`;
        supportStatus.textContent = 'Carregando assinaturas…';
        accessStatus.textContent = 'Acesso de suporte. Sessões registradas em auditoria.';
        loadSupportSubscriptions();
      } else {
        subscriptionLogin.hidden = true;
        accessStatus.textContent = 'Selecione o tipo de acesso.';
      }
    });

    system.addEventListener('change', renderProfiles);
    profile.addEventListener('change', () => {
      password.disabled = !profile.value;
      submit.disabled = !profile.value;
      status.textContent = profile.value ? 'Informe sua senha.' : 'Selecione um perfil.';
      if (!profile.value) password.value = '';
    });
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!form.reportValidity()) return;
      submit.disabled = true;
      status.textContent = 'Validando credenciais…';
      try {
        const result = await window.FokusApi.request('/auth/law-login', {
          method: 'POST',
          body: {
            email: email.value.trim(),
            password: password.value,
            company_id: system.value,
            profile: profile.value,
          },
        });
        location.assign(result.redirect_to || '/portal/fokus-law');
      } catch (error) {
        status.textContent = 'Falha na autenticação.';
        showToast(status.textContent);
        submit.disabled = false;
      }
    });

    const support = document.createElement('section');
    support.hidden = true;
    support.className = 'law-support-access';
    support.setAttribute('aria-labelledby', 'law-support-title');
    support.innerHTML = '<h2 id="law-support-title">Acesso de suporte</h2><p data-support-email></p><p>Escolha uma assinatura e um usuário real da empresa. O acesso será registrado em auditoria.</p><label for="law-support-subscription">Assinatura</label><select class="fs-form-select" data-fs="select" id="law-support-subscription" required></select><label for="law-support-user">Usuário e perfil</label><select class="fs-form-select" data-fs="select" id="law-support-user" required></select><label for="law-support-reason">Motivo do acesso</label><textarea class="fs-form-control" id="law-support-reason" minlength="10" maxlength="1000" required></textarea><button class="fs-btn fs-btn-primary law-submit" type="button" id="law-support-start">Acessar em modo de suporte</button><p role="status" aria-live="polite" id="law-support-status"></p>';
    form.after(support);
    const subscriptionSelect = support.querySelector('#law-support-subscription');
    const userSelect = support.querySelector('#law-support-user');
    const supportStatus = support.querySelector('#law-support-status');
    let supportSubscriptions = [];
    let supportAdmin = null;
    let supportAdminReady = false;
    let supportSubscriptionsLoaded = false;
    let supportSubscriptionsLoading = false;
    const loadSupportSubscriptions = async () => {
      if (supportSubscriptionsLoaded || supportSubscriptionsLoading) return;
      supportSubscriptionsLoading = true;
      try {
        const payload = await window.FokusApi.request('/backoffice/support/law-context');
        supportSubscriptions = payload.subscriptions || [];
        subscriptionSelect.replaceChildren(new Option('Selecione a assinatura', ''));
        supportSubscriptions.forEach((item) => subscriptionSelect.add(new Option(item.label, item.id)));
        subscriptionSelect.disabled = !supportSubscriptions.length;
        supportStatus.textContent = supportSubscriptions.length ? 'MFA validado. Acesso de suporte auditado.' : 'Nenhuma assinatura disponível.';
        supportSubscriptionsLoaded = true;
      } catch (error) {
        supportStatus.textContent = 'Falha ao carregar assinaturas.';
        showToast(supportStatus.textContent);
      } finally {
        supportSubscriptionsLoading = false;
      }
    };
    const renderSupportUsers = () => {
      const selected = supportSubscriptions.find((item) => item.id === subscriptionSelect.value);
      userSelect.replaceChildren(new Option('Selecione o usuário', ''));
      (selected?.users || []).forEach((user) => userSelect.add(new Option(user.label, user.membership_id)));
      userSelect.disabled = !selected?.users?.length;
      if (selected && !selected.users.length) supportStatus.textContent = 'Nenhum usuário ativo nesta assinatura.';
    };
    subscriptionSelect.addEventListener('change', renderSupportUsers);
    support.querySelector('#law-support-start').addEventListener('click', async () => {
      const reason = support.querySelector('#law-support-reason').value.trim();
      if (!subscriptionSelect.value || !userSelect.value || reason.length < 10) {
        supportStatus.textContent = 'Selecione a assinatura e o usuário. Motivo: mínimo de 10 caracteres.';
        return;
      }
      supportStatus.textContent = 'Iniciando acesso…';
      try {
        await window.FokusApi.request('/backoffice/support/access', { method: 'POST', body: { subscription_id: subscriptionSelect.value, membership_id: userSelect.value, reason } });
        location.assign('/portal/fokus-law');
      } catch (error) {
        supportStatus.textContent = 'Falha ao iniciar o acesso.';
        showToast(supportStatus.textContent);
      }
    });
    const supportAdminCheck = window.FokusApi.request('/backoffice/auth/me').then(({ admin }) => {
      if (admin?.role === 'superadministrador' && admin?.email) supportAdmin = admin;
    }).catch(() => {}).finally(() => { supportAdminReady = true; });
  }

  const interestForm = document.querySelector('[data-law-interest-form]');
  if (interestForm && window.FokusApi) {
    const interestStatus = interestForm.querySelector('[data-law-interest-status]');
    const interestButton = interestForm.querySelector('button[type="submit"]');
    const modulesField = interestForm.querySelector('[data-module-priorities]');
    const modulePriorityFields = interestForm.querySelector('[data-module-priority-fields]');
    const moduleInputs = [...interestForm.querySelectorAll('input[name="interest_modules"]')];
    const renderModulePriorities = () => {
      const selected = moduleInputs.filter((input) => input.checked).map((input) => input.value);
      modulesField.hidden = selected.length === 0;
      modulePriorityFields.innerHTML = selected.map((module, index) => {
        const id = 'law-interest-priority-' + index;
        return '<label for="' + id + '">' + module + '<textarea class="fs-form-control" id="' + id + '" name="module_priority" rows="3" maxlength="500" placeholder="Quais funcionalidades desse módulo são mais importantes para você?"></textarea></label>';
      }).join('');
    };
    moduleInputs.forEach((input) => input.addEventListener('change', renderModulePriorities));
    interestForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!interestForm.reportValidity()) return;
      const data = new FormData(interestForm);
      interestStatus.textContent = 'Enviando suas informações…';
      interestButton.disabled = true;
      const moduleInterests = data.getAll('interest_modules').map((value) => String(value));
      const priorities = [...modulePriorityFields.querySelectorAll('textarea')].map((field, index) => {
        const module = moduleInterests[index];
        const detail = field.value.trim();
        return detail ? module + ': ' + detail : '';
      }).filter(Boolean);
      const desiredModules = String(data.get('desired_modules') || '').trim();
      const desiredFeatures = String(data.get('desired_features') || '').trim();
      const feedback = [
        priorities.length ? 'Funcionalidades prioritárias por módulo: ' + priorities.join(' | ') : '',
        desiredModules ? 'Módulos que gostaria que existissem: ' + desiredModules : '',
        desiredFeatures ? 'Funcionalidades desejadas nesses módulos: ' + desiredFeatures : ''
      ].filter(Boolean).join('\n');
      if (feedback.length > 4000) {
        interestStatus.textContent = 'Reduza os detalhes por módulo para até 4.000 caracteres no total.';
        interestButton.disabled = false;
        return;
      }
      const payload = {
        name: String(data.get('name') || '').trim(),
        email: String(data.get('email') || '').trim(),
        products: ['law'],
        profiles: [String(data.get('profile') || '')],
        organization: String(data.get('organization') || '').trim() || undefined,
        modules: [...moduleInterests, ...(desiredModules ? ['Sugestão de módulo'] : [])],
        main_difficulties: String(data.get('main_difficulties') || '').trim(),
        current_process: feedback || undefined,
        privacy_accepted: Boolean(data.get('privacy_accepted')),
        privacy_version: '1.0',
        source_url: location.href,
        website: String(data.get('website') || '')
      };
      try {
        await window.FokusApi.request('/product-interests', { method: 'POST', body: payload });
        interestStatus.textContent = 'Recebemos suas informações. Elas foram registradas e encaminhadas à equipe Fokus Law.';
        interestForm.reset();
        renderModulePriorities();
      } catch (error) {
        interestStatus.textContent = error.message || 'Não foi possível enviar e encaminhar suas informações agora. Tente novamente em instantes.';
      } finally {
        interestButton.disabled = false;
      }
    });
  }

  const livePlans = document.querySelector('[data-law-live-plans]'), grid = document.querySelector('[data-law-live-plan-grid]');
  if (livePlans && grid && window.FokusApi) window.FokusApi.request('/catalog/fokus-law').then((catalog) => { const plans = catalog.plans || []; if (!plans.length) return; grid.innerHTML = plans.map((plan) => `<article class="law-live-plan"><strong>${plan.name}</strong><span>A partir de R$ ${Number(plan.monthly_amount).toLocaleString('pt-BR', { minimumFractionDigits: 2 })} / mês</span></article>`).join(''); livePlans.hidden = false; }).catch(() => {});
})();

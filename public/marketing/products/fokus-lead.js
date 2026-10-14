(() => {
  const form = document.querySelector('[data-lead-login-form]');
  if (!form) return;

  const email = form.elements.email;
  const system = form.elements.system;
  const systemLabel = form.querySelector('#lead-system-label');
  const instructions = form.closest('.lead-login-card').querySelector('#lead-login-instructions');
  const profile = form.elements.profile;
  const profileField = profile.closest('div');
  const password = form.elements.password;
  const submit = form.querySelector('button[type="submit"]');
  const status = form.querySelector('[data-lead-login-status]');
  let lookupTimer;
  let systems = [];

  const reset = (message = 'Informe seu e-mail para consultar os acessos.') => {
    systems = [];
    systemLabel.textContent = 'Empresa vinculada';
    instructions.textContent = 'Informe seu e-mail para localizar a empresa vinculada e os acessos disponíveis.';
    system.replaceChildren(new Option('Aguardando identificação', ''));
    system.disabled = true;
    profile.replaceChildren(new Option('Selecione a empresa primeiro', ''));
    profile.disabled = true;
    profileField.hidden = true;
    password.disabled = true;
    password.value = '';
    submit.disabled = true;
    status.textContent = message;
  };

  const renderProfiles = () => {
    const selected = systems.find((item) => item.value === system.value);
    const availableProfiles = selected?.profiles || [];
    const isIndividual = selected?.document_type === 'cpf';
    systemLabel.textContent = isIndividual ? 'Corretor vinculado' : 'Empresa vinculada';
    instructions.textContent = isIndividual
      ? 'Informe seu e-mail para localizar o corretor vinculado e os acessos disponíveis.'
      : 'Informe seu e-mail para localizar a empresa vinculada e os acessos disponíveis.';
    profile.replaceChildren(new Option('Escolha seu perfil', ''));
    availableProfiles.forEach((item) => profile.add(new Option(item.label, item.value)));
    profileField.hidden = availableProfiles.length < 2;
    profile.disabled = availableProfiles.length < 2;
    password.disabled = availableProfiles.length === 0;
    password.value = '';
    submit.disabled = availableProfiles.length === 0 || availableProfiles.length > 1;
    if (availableProfiles.length === 1) {
      profile.value = availableProfiles[0].value;
      status.textContent = 'Informe sua senha.';
    } else {
      status.textContent = availableProfiles.length
        ? 'Selecione um perfil.'
        : 'Nenhum perfil disponível.';
    }
  };

  const renderSystems = (payload) => {
    systems = payload.systems || [];
    if (!systems.length) {
      reset('Nenhuma assinatura ativa encontrada.');
      return;
    }
    system.replaceChildren(new Option('Selecione o vínculo', ''));
    systems.forEach((item) => system.add(new Option(item.label, item.value)));
    system.disabled = false;
    if (systems.length === 1) {
      system.value = systems[0].value;
      renderProfiles();
    } else {
      status.textContent = 'Selecione um vínculo.';
    }
  };

  reset();
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!form.reportValidity() || !system.value || !profile.value) return;
    submit.disabled = true;
    status.textContent = 'Validando credenciais…';
    try {
      const result = await window.FokusApi.request('/auth/lead-login', {
        method: 'POST',
        body: { email: email.value.trim(), password: password.value, company_id: system.value, profile: profile.value },
      });
      location.assign(result.redirect_to || '/portal/fokus-lead');
    } catch (error) {
      status.textContent = 'Falha na autenticação.';
      submit.disabled = false;
    }
  });
  email.addEventListener('input', () => {
    clearTimeout(lookupTimer);
    reset('E-mail inválido.');
    const value = email.value.trim();
    if (!value || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return;
    status.textContent = 'Consultando vínculos…';
    lookupTimer = setTimeout(async () => {
      if (email.value.trim().toLowerCase() !== value.toLowerCase()) return;
      try {
        const payload = await window.FokusApi.request('/auth/lead-context', { method: 'POST', body: { email: value } });
        if (email.value.trim().toLowerCase() === value.toLowerCase()) renderSystems(payload);
      } catch (error) {
        if (email.value.trim().toLowerCase() !== value.toLowerCase()) return;
        reset(error.status === 404 ? 'E-mail não localizado.' : 'Falha ao consultar vínculos. Tente novamente.');
      }
    }, 420);
  });

  system.addEventListener('change', renderProfiles);
  profile.addEventListener('change', () => {
    password.disabled = !profile.value;
    submit.disabled = !profile.value;
    status.textContent = profile.value ? 'Informe sua senha.' : 'Selecione um perfil.';
    if (!profile.value) password.value = '';
  });
})();

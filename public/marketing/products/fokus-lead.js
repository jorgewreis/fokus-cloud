(() => {
  const form = document.querySelector('[data-lead-login-form]');
  if (!form) return;

  const email = form.elements.email;
  const system = form.elements.system;
  const profile = form.elements.profile;
  const profileField = profile.closest('div');
  const selectionFields = form.querySelector('.lead-field-grid');
  const password = form.elements.password;
  const submit = form.querySelector('button[type="submit"]');
  const status = form.querySelector('[data-lead-login-status]');
  let systems = [];

  const resetSelection = () => {
    systems = [];
    selectionFields.hidden = true;
    system.replaceChildren(new Option('Aguardando confirmação da senha', ''));
    system.disabled = true;
    profile.replaceChildren(new Option('Selecione a empresa primeiro', ''));
    profile.disabled = true;
    profileField.hidden = true;
  };

  const enableCredentials = (message = 'Informe sua senha.') => {
    resetSelection();
    password.disabled = false;
    submit.disabled = false;
    status.textContent = message;
  };

  const renderProfiles = () => {
    const selected = systems.find((item) => item.value === system.value);
    const profiles = selected?.profiles || [];
    profile.replaceChildren(new Option('Escolha seu perfil', ''));
    profiles.forEach((item) => profile.add(new Option(item.label, item.value)));
    profileField.hidden = profiles.length < 2;
    profile.disabled = profiles.length < 2;
    if (profiles.length === 1) profile.value = profiles[0].value;
    submit.disabled = !profile.value;
    status.textContent = profile.value ? 'Confirme sua senha para entrar.' : 'Selecione seu perfil.';
  };

  const renderSystems = (payload) => {
    systems = payload.systems || [];
    if (!systems.length) {
      enableCredentials('Não foi possível iniciar o acesso. Revise suas credenciais.');
      return;
    }
    selectionFields.hidden = false;
    system.replaceChildren(new Option('Selecione o vínculo', ''));
    systems.forEach((item) => system.add(new Option(item.label, item.value)));
    system.disabled = false;
    password.disabled = false;
    if (systems.length === 1) {
      system.value = systems[0].value;
      renderProfiles();
    } else {
      submit.disabled = true;
      status.textContent = 'Selecione o vínculo e o perfil para continuar.';
    }
  };

  resetSelection();
  password.disabled = true;
  submit.disabled = true;
  status.textContent = 'Informe seu e-mail e senha.';

  email.addEventListener('input', () => {
    password.value = '';
    if (!email.validity.valid) {
      resetSelection();
      password.disabled = true;
      submit.disabled = true;
      status.textContent = 'Informe um e-mail válido.';
      return;
    }
    enableCredentials();
  });

  system.addEventListener('change', renderProfiles);
  profile.addEventListener('change', () => {
    submit.disabled = !profile.value;
    status.textContent = profile.value ? 'Confirme sua senha para entrar.' : 'Selecione seu perfil.';
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!form.reportValidity() || (selectionFields.hidden === false && (!system.value || !profile.value))) return;
    submit.disabled = true;
    status.textContent = 'Validando credenciais…';
    try {
      const result = await window.FokusApi.request('/auth/lead-login', {
        method: 'POST',
        body: {
          email: email.value.trim(),
          password: password.value,
          ...(system.value && profile.value ? { company_id: system.value, profile: profile.value } : {}),
        },
      });
      if (result.selection_required) {
        renderSystems(result);
        return;
      }
      location.assign(result.redirect_to || '/portal/fokus-lead');
    } catch (error) {
      status.textContent = 'Falha na autenticação.';
      submit.disabled = false;
    }
  });
})();

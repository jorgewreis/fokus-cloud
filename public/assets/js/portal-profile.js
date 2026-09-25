window.initializeLawProfile = () => {
  const loading = document.querySelector('#profile-loading');
  const sections = document.querySelector('#profile-sections');
  const supportNotice = document.querySelector('#profile-support-notice');
  const personalForm = document.querySelector('#personal-form');
  const emailForm = document.querySelector('#email-form');
  const securityForm = document.querySelector('#security-form');

  function feedback(id, message, state = '') {
    const target = document.getElementById(id);
    target.textContent = message;
    if (state) target.dataset.state = state;
    else delete target.dataset.state;
  }

  function formatPhone(value) {
    const digits = String(value || '').replace(/\D/g, '').slice(0, 11);
    if (!digits) return '';
    if (digits.length < 3) return `(${digits}`;
    const ddd = digits.slice(0, 2);
    const number = digits.slice(2);
    if (digits.length <= 10) {
      return number.length > 4 ? `(${ddd}) ${number.slice(0, 4)}-${number.slice(4)}` : `(${ddd}) ${number}`;
    }
    return number.length > 5 ? `(${ddd}) ${number.slice(0, 5)}-${number.slice(5)}` : `(${ddd}) ${number}`;
  }

  function formatCpf(value) {
    const digits = String(value || '').replace(/\D/g, '').slice(0, 11);
    return digits
      .replace(/^(\d{3})(\d)/, '$1.$2')
      .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
      .replace(/\.(\d{3})(\d)/, '.$1-$2');
  }

  function setSupportReadOnly() {
    if (supportNotice) supportNotice.hidden = false;
    [personalForm, emailForm, securityForm].forEach((form) => {
      form.querySelectorAll('input, button').forEach((control) => { control.disabled = true; });
    });
  }

  async function loadProfile() {
    try {
      const result = await FokusApi.request('/auth/me');
      const user = result.user;
      personalForm.elements.name.value = user.name || '';
      personalForm.elements.phone.value = formatPhone(user.phone || '');
      document.querySelector('#profile-cpf').value = formatCpf(user.cpf || '');
      document.querySelector('#current-email').textContent = user.email || '';
      document.querySelector('#email-verified').textContent = user.email_verified ? 'Confirmado' : 'Não confirmado';
      if (result.support_mode?.active) setSupportReadOnly();
      sections.hidden = false;
      loading.hidden = true;
    } catch (error) {
      if (error.status === 401) {
        location.assign('/?acesso=cliente');
        return;
      }
      loading.textContent = error.message || 'Não foi possível carregar o perfil. Atualize a página para tentar novamente.';
      loading.dataset.state = 'error';
    }
  }

  personalForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!personalForm.reportValidity()) return;
    const button = personalForm.querySelector('button[type="submit"]');
    button.disabled = true;
    feedback('personal-status', 'Salvando…');
    try {
      const result = await FokusApi.request('/auth/profile', {
        method: 'PATCH',
        body: { name: personalForm.elements.name.value.trim(), phone: personalForm.elements.phone.value.trim() },
      });
      personalForm.elements.name.value = result.user.name || '';
      personalForm.elements.phone.value = formatPhone(result.user.phone || '');
      feedback('personal-status', 'Dados pessoais atualizados.', 'success');
    } catch (error) {
      feedback('personal-status', error.message, 'error');
    } finally {
      button.disabled = false;
    }
  });

  personalForm.elements.phone.addEventListener('input', (event) => {
    const input = event.currentTarget;
    const caretAtEnd = input.selectionStart === input.value.length;
    input.value = formatPhone(input.value);
    if (caretAtEnd) input.setSelectionRange(input.value.length, input.value.length);
  });

  emailForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!emailForm.reportValidity()) return;
    const button = emailForm.querySelector('button[type="submit"]');
    button.disabled = true;
    feedback('email-status', 'Enviando solicitação…');
    try {
      const result = await FokusApi.request('/auth/profile', {
        method: 'PATCH',
        body: { email: emailForm.elements.email.value.trim(), current_password: emailForm.elements.current_password.value },
      });
      feedback('email-status', result.message, 'success');
      emailForm.reset();
    } catch (error) {
      feedback('email-status', error.message, 'error');
    } finally {
      button.disabled = false;
    }
  });

  securityForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!securityForm.reportValidity()) return;
    const button = securityForm.querySelector('button[type="submit"]');
    button.disabled = true;
    feedback('security-status', 'Atualizando senha…');
    try {
      const result = await FokusApi.request('/auth/profile', {
        method: 'PATCH',
        body: {
          current_password: securityForm.elements.current_password.value,
          password: securityForm.elements.password.value,
          password_confirmation: securityForm.elements.password_confirmation.value,
        },
      });
      securityForm.reset();
      feedback('security-status', result.message, 'success');
    } catch (error) {
      feedback('security-status', error.message, 'error');
    } finally {
      button.disabled = false;
    }
  });

  loadProfile();
};

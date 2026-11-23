(() => {
  const form = document.querySelector('[data-custom-proposal-form]');
  if (!form || !window.FokusApi) return;

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const status = form.querySelector('[data-custom-proposal-status]');
    const button = form.querySelector('button[type="submit"]');
    const need = String(form.elements.main_difficulties.value || '').trim();
    status.textContent = 'Enviando solicitação…';
    button.disabled = true;
    try {
      await window.FokusApi.request('/product-interests', {
        method: 'POST',
        body: {
          name: form.elements.name.value,
          email: form.elements.email.value,
          organization: form.elements.organization.value,
          products: ['law'],
          profiles: [form.elements.profile.value],
          main_difficulties: `Solicitação de proposta do Fokus Law sob medida.\n\n${need}`,
          source_url: location.href,
          privacy_accepted: form.elements.privacy_accepted.checked,
          privacy_version: '2.0',
          website: form.elements.website.value,
        },
      });
      status.textContent = 'Recebemos a solicitação de proposta. A resposta vai para o e-mail informado.';
      form.reset();
    } catch (error) {
      status.textContent = error.message || 'Não foi possível enviar a solicitação.';
    } finally {
      button.disabled = false;
    }
  });
})();

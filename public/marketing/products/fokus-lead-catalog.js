(() => {
  const section = document.querySelector('[data-lead-catalog]');
  if (!section || !window.FokusApi) return;

  const plansNode = section.querySelector('[data-lead-plan-grid]');
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
  }[character]));
  const money = (value) => new Intl.NumberFormat('pt-BR', {
    style: 'currency', currency: 'BRL',
  }).format(Number(value || 0));

  window.FokusApi.request('/catalog/fokus-lead').then((catalog) => {
    const plans = catalog.plans || [];

    plansNode.innerHTML = plans.length ? plans.map((plan) => `<article class="lead-offer-row${plan.featured ? ' is-featured' : ''}">
        <h3>${escapeHtml(plan.name)}</h3>
        <span>${money(plan.monthly_amount)} <small>/ mês</small></span>
      </article>`).join('') : '<p class="lead-catalog-state">Não há planos publicados no catálogo.</p>';
  }).catch(() => {
    plansNode.innerHTML = '<p class="lead-catalog-state" role="status">Não foi possível carregar o catálogo agora.</p>';
  });
})();

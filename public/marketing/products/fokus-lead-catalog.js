(() => {
  const section = document.querySelector('[data-lead-catalog]');
  if (!section || !window.FokusApi) return;

  const plansNode = section.querySelector('[data-lead-plan-grid]');
  const modulesNode = section.querySelector('[data-lead-module-grid]');
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
  }[character]));
  const money = (value) => new Intl.NumberFormat('pt-BR', {
    style: 'currency', currency: 'BRL',
  }).format(Number(value || 0));

  window.FokusApi.request('/catalog/lead').then((catalog) => {
    const modules = catalog.modules || [];
    const moduleNames = new Map(modules.map((module) => [module.code, module.name]));
    const plans = catalog.plans || [];

    plansNode.innerHTML = plans.length ? plans.map((plan) => {
      const included = (plan.module_codes || []).map((code) => moduleNames.get(code)).filter(Boolean);
      return `<article class="lead-plan-card${plan.featured ? ' is-featured' : ''}">
        <p class="lead-plan-context">${escapeHtml(plan.segment === 'one' ? 'CORRETOR AUTÔNOMO' : plan.segment === 'team' ? 'EQUIPES E IMOBILIÁRIAS' : 'FOKUS LEAD')}</p>
        <h3>${escapeHtml(plan.name)}</h3>
        <p class="lead-plan-price">${money(plan.monthly_amount)} <span>/ mês</span></p>
        <p class="lead-plan-annual">${money(plan.annual_amount)} por ano</p>
        <ul>${included.map((name) => `<li>${escapeHtml(name)}</li>`).join('')}</ul>
      </article>`;
    }).join('') : '<p class="lead-catalog-state">Não há planos publicados no catálogo.</p>';

    modulesNode.innerHTML = modules.length ? modules.map((module) => `<article class="lead-module-catalog-card">
      <h4>${escapeHtml(module.name)}</h4>
      <p>${escapeHtml(module.description || '')}</p>
      <strong>${money(module.monthly_amount)} <span>/ mês por módulo</span></strong>
    </article>`).join('') : '<p class="lead-catalog-state">Não há módulos publicados no catálogo.</p>';
  }).catch(() => {
    plansNode.innerHTML = '<p class="lead-catalog-state" role="status">Não foi possível carregar o catálogo agora.</p>';
    modulesNode.replaceChildren();
  });
})();

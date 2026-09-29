(() => {
  const show = (node, catalog) => {
    const plans = catalog.plans || [];
    const modules = (catalog.modules || []).filter((module) => module.available_standalone && /contato/i.test(`${module.name} ${module.code}`));
    const prices = [...plans.map((plan) => Number(plan.monthly_amount)), ...modules.map((module) => Number(module.monthly_amount))].filter((amount) => Number.isFinite(amount) && amount >= 0);
    if (!prices.length) return;
    const amount = Math.min(...prices);
    node.innerHTML = `<span>PLANOS FOKUS LAW</span><strong>A partir de ${new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(amount)} / mês</strong><a href="/produtos/fokus-law/planos">Ver planos e configurar <span aria-hidden="true">↗</span></a>`;
    node.hidden = false;
  };
  document.querySelectorAll('[data-law-price-summary]').forEach((node) => window.FokusApi?.request('/catalog/fokus-law').then((catalog) => show(node, catalog)).catch(() => {}));
})();

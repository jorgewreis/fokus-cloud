(() => {
  const offersNode = document.querySelector('#lead-offers-grid');
  if (!offersNode || !window.FokusApi) return;
  const $ = (selector) => document.querySelector(selector);
  const money = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value || 0));
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]));
  const state = { catalog: null, cycle: 'monthly', plan: '', selected: new Set(), request: 0, quote: null };
  const moduleByCode = (code) => state.catalog?.modules?.find((item) => item.code === code);
  const currentPlan = () => state.catalog?.plans?.find((item) => item.code === state.plan);
  const planCodes = () => currentPlan()?.module_codes || [];
  const dependencies = (codes) => {
    const result = new Set();
    const visit = (code) => { if (result.has(code)) return; result.add(code); (moduleByCode(code)?.dependencies || []).forEach((item) => visit(item.code)); };
    codes.forEach(visit); return result;
  };
  const conflicts = (codes) => {
    const all = [...codes];
    for (let i = 0; i < all.length; i += 1) for (let j = i + 1; j < all.length; j += 1) {
      if ((moduleByCode(all[i])?.incompatibilities || []).some((item) => item.code === all[j]) || (moduleByCode(all[j])?.incompatibilities || []).some((item) => item.code === all[i])) return true;
    }
    return false;
  };
  const selectedCodes = () => [...dependencies([...planCodes(), ...state.selected])];
  const selectionItems = () => selectedCodes().map((module_code) => ({ module_code, quantity: 1 }));
  const saveIntent = () => {
    const intent = { selection_mode: state.plan ? 'plan' : 'custom', plan_code: state.plan || null, cycle: state.cycle, items: selectionItems() };
    localStorage.setItem('fokus-lead-offer-v1', JSON.stringify(intent));
  };
  const quote = async () => {
    const request = ++state.request;
    const codes = selectedCodes();
    const buy = $('#lead-checkout-link');
    if (!codes.length) { state.quote = null; $('#lead-total').textContent = '—'; buy.setAttribute('aria-disabled', 'true'); $('#lead-quote-status').textContent = 'Selecione pelo menos um módulo para consultar a cotação.'; return; }
    const items = selectionItems();
    $('#lead-quote-status').textContent = 'Atualizando a cotação pelo servidor…'; buy.setAttribute('aria-disabled', 'true');
    try {
      const result = await FokusApi.request('/catalog/fokus-lead/quote', { method: 'POST', body: { product_code: 'fokus-lead', selection_mode: state.plan ? 'plan' : 'custom', ...(state.plan ? { plan_code: state.plan } : {}), cycle: state.cycle, items } });
      if (request !== state.request) return;
      state.quote = result; $('#lead-total').textContent = money(result.amount); $('#lead-cycle-label').textContent = state.cycle === 'annual' ? 'Total anual' : 'Total mensal';
      $('#lead-summary-items').innerHTML = `<p>${state.plan ? 'Plano-base' : 'Módulos selecionados'}: ${money(result.breakdown.plan_base || result.breakdown.extra_modules)}</p>${state.plan ? `<p>Módulos extras: ${money(result.breakdown.extra_modules)}</p>` : ''}`;
      $('#lead-quote-status').textContent = `Cotação baseada na versão ${esc(result.publication_versions.product_catalog_release_version || result.publication_versions.product_catalog_version)} do catálogo.`;
      buy.setAttribute('aria-disabled', 'false'); saveIntent();
    } catch (error) { if (request !== state.request) return; state.quote = null; $('#lead-total').textContent = '—'; $('#lead-quote-status').textContent = error.message || 'Não foi possível calcular esta composição.'; }
  };
  const renderModules = () => {
    const required = new Set(planCodes()); const selected = dependencies([...planCodes(), ...state.selected]);
    const available = (state.catalog.modules || []).filter((item) => !state.plan || required.has(item.code) || item.available_standalone);
    $('#lead-plan-modules').innerHTML = available.length ? available.map((item) => {
      const included = required.has(item.code); const auto = selected.has(item.code) && !included && !state.selected.has(item.code);
      return `<label class="lead-module-option"><input type="checkbox" value="${esc(item.code)}" ${selected.has(item.code) ? 'checked' : ''} ${included || auto ? 'disabled' : ''}><span><strong>${esc(item.name)}${included ? ' · incluído' : auto ? ' · dependência necessária' : ''}</strong><small>${esc(item.description || '')}${!included ? ` · ${money(item.monthly_amount)}/mês por módulo` : ''}</small></span></label>`;
    }).join('') : '<p>Não há módulos liberados para esta configuração.</p>';
    $('#lead-plan-modules').querySelectorAll('input:not(:disabled)').forEach((input) => input.addEventListener('change', () => {
      const next = new Set(state.selected); if (input.checked) next.add(input.value); else next.delete(input.value);
      const all = dependencies([...planCodes(), ...next]);
      if (conflicts(all) || [...all].some((code) => !moduleByCode(code))) { input.checked = false; $('#lead-quote-status').textContent = 'Esta combinação possui módulos incompatíveis ou uma dependência indisponível.'; return; }
      state.selected = next; renderModules(); quote();
    }));
  };
  const renderOffers = () => {
    const plans = state.catalog.plans || [];
    const cards = plans.map((plan) => `<article class="lead-price-card${plan.featured ? ' is-featured' : ''}"><p>${esc(plan.segment || 'FOKUS LEAD')}</p><h3>${esc(plan.name)}</h3><span>${esc(plan.description || 'Uma combinação de recursos para sua rotina imobiliária.')}</span><strong>${money(state.cycle === 'annual' ? plan.annual_amount : plan.monthly_amount)}<small> / ${state.cycle === 'annual' ? 'ano' : 'mês'}</small></strong><button type="button" data-plan="${esc(plan.code)}">Configurar este plano <b aria-hidden="true">↗</b></button></article>`);
    cards.unshift('<article class="lead-price-card lead-custom-card"><p>FLEXIBILIDADE</p><h3>Personalizada</h3><span>Escolha módulos independentes, conforme a disponibilidade do catálogo.</span><strong>Você escolhe</strong><button type="button" data-custom>Montar composição <b aria-hidden="true">↗</b></button></article>');
    offersNode.innerHTML = cards.join('');
  };
  offersNode.addEventListener('click', (event) => {
    const plan = event.target.closest('[data-plan]'); if (plan) { state.plan = plan.dataset.plan; state.selected.clear(); $('#lead-plan-select').value = state.plan; renderModules(); quote(); $('#lead-configure').scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' }); }
    if (event.target.closest('[data-custom]')) { state.plan = ''; state.selected.clear(); $('#lead-plan-select').value = ''; renderModules(); quote(); $('#lead-configure').scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' }); }
  });
  $('#lead-plan-select').addEventListener('change', (event) => { state.plan = event.target.value; state.selected.clear(); renderModules(); quote(); });
  document.querySelectorAll('[data-cycle]').forEach((button) => button.addEventListener('click', () => { state.cycle = button.dataset.cycle; document.querySelectorAll('[data-cycle]').forEach((item) => item.setAttribute('aria-pressed', String(item === button))); renderOffers(); quote(); }));
  $('#lead-checkout-link').addEventListener('click', (event) => { if (!state.quote) event.preventDefault(); });
  FokusApi.request('/catalog/fokus-lead').then((catalog) => {
    state.catalog = catalog; $('#lead-plan-select').innerHTML = '<option value="">Assinatura personalizada · escolha os módulos</option>' + (catalog.plans || []).map((plan) => `<option value="${esc(plan.code)}">${esc(plan.name)}</option>`).join('');
    renderOffers(); renderModules(); $('#lead-summary-title').textContent = 'Assinatura personalizada';
  }).catch((error) => { offersNode.innerHTML = `<p role="status">${esc(error.message || 'Não foi possível carregar as ofertas. Tente novamente em instantes.')}</p>`; $('#lead-plan-modules').textContent = 'O catálogo está temporariamente indisponível.'; });
})();

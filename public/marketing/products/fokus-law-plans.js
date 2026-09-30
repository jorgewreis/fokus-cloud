(() => {
  const offersNode = document.querySelector('#lp-offers');
  if (!offersNode) return;
  const $ = (selector) => document.querySelector(selector);
  const money = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value || 0));
  const state = { catalog: null, cycle: 'monthly', plan: '', selected: new Set(), quoteTimer: 0, quote: null };
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
  const annualFromMonthly = (monthly) => Math.round(Number(monthly) * 1000) / 100;
  const moduleByCode = (code) => state.catalog?.modules?.find((module) => module.code === code);
  const currentPlan = () => state.catalog?.plans?.find((plan) => plan.code === state.plan);
  const planCodes = () => currentPlan()?.module_codes || [];
  const segmentNames = { advocacia: 'Advocacia', setor_publico: 'Setor público' };

  const renderOffers = () => {
    const plans = state.catalog?.plans || [];
    const standalones = (state.catalog?.modules || []).filter((module) => module.available_standalone && module.module_code === 'contatos');
    $('#lp-offers-heading').textContent = standalones.length ? 'Escolha um plano ou comece por Contatos.' : 'Escolha um plano para sua operação.';
    const cards = plans.map((plan) => `<article class="lp-offer"><p class="law-eyebrow law-eyebrow-purple">${esc(segmentNames[plan.segment] || plan.segment || 'Fokus Law')}</p><h3>${esc(plan.name)}</h3><p>${esc(plan.description || 'Composição de módulos para sua operação.')}</p><strong>${money(state.cycle === 'annual' ? plan.annual_amount : plan.monthly_amount)} <small>/ ${state.cycle === 'annual' ? 'ano' : 'mês'}</small></strong><button type="button" data-choose-plan="${esc(plan.code)}">Configurar plano</button></article>`);
    standalones.forEach((module) => cards.push(`<article class="lp-offer lp-standalone"><p class="law-eyebrow law-eyebrow-sage">GESTÃO DE CONTATOS AVULSA</p><h3>${esc(segmentNames[module.segments?.[0]] || module.segments?.[0] || 'Fokus Law')}</h3><p>${esc(module.name)}. Organize pessoas e organizações e personalize a capacidade.</p><strong>${money(Number(module.monthly_amount || 0) * (state.cycle === 'annual' ? 10 : 1))} <small>/ ${state.cycle === 'annual' ? 'ano' : 'mês'}</small></strong><button type="button" data-choose-standalone="${esc(module.code)}">Configurar Contatos</button></article>`));
    offersNode.innerHTML = cards.length ? cards.join('') : '<p class="lp-state">Não há planos ou módulos avulsos disponíveis no catálogo publicado.</p>';
  };

  const renderBuilder = () => {
    const planSelect = $('#lp-plan');
    const plans = state.catalog.plans || [];
    const hasStandaloneContacts = (state.catalog.modules || []).some((module) => module.module_code === 'contatos' && module.available_standalone);
    planSelect.innerHTML = `<option value="">${hasStandaloneContacts ? 'Somente módulos avulsos' : 'Selecione um plano-base'}</option>` + plans.map((plan) => `<option value="${esc(plan.code)}">${esc(plan.name)}</option>`).join('');
    planSelect.value = state.plan;
    const required = new Set(planCodes());
    const avail = (state.catalog.modules || []).filter((module) => required.has(module.code) || (module.available_standalone && (state.plan || module.module_code === 'contatos')));
    $('#lp-modules').innerHTML = avail.length ? avail.map((module) => {
      const isRequired = required.has(module.code);
      const checked = isRequired || state.selected.has(module.code);
      return `<label class="lp-module-choice"><input type="checkbox" value="${esc(module.code)}" ${checked ? 'checked' : ''} ${isRequired ? 'disabled' : ''}><span><strong>${esc(module.name)}${isRequired ? ' · incluído' : ''}</strong><small>${esc(module.description || `A partir de ${money(module.monthly_amount)}/mês`)}</small></span></label>`;
    }).join('') : '<p class="lp-standalone-unavailable">A publicação atual não libera módulos para contratação avulsa. Os módulos de Contatos aparecem nos planos acima; a opção independente ficará disponível quando for habilitada e publicada no catálogo.</p>';
    $('#lp-modules').querySelectorAll('input[type=checkbox]:not(:disabled)').forEach((input) => input.addEventListener('change', () => { input.checked ? state.selected.add(input.value) : state.selected.delete(input.value); refreshCapacities(); quote(); }));
    refreshCapacities();
  };

  const selectedCodes = () => [...new Set([...planCodes(), ...state.selected])];
  const refreshCapacities = () => {
    const node = $('#lp-capacities');
    const modules = selectedCodes().map(moduleByCode).filter(Boolean);
    node.innerHTML = modules.flatMap((module) => (module.personalizations || []).filter((personalization) => personalization.active && personalization.tiers?.some((tier) => tier.active)).map((personalization) => {
      const tiers = personalization.tiers.filter((tier) => tier.active).sort((a, b) => Number(a.value) - Number(b.value));
      const planDefault = currentPlan()?.personalization_defaults?.find((item) => item.personalization_id === personalization.id);
      const baseTier = planDefault ? tiers.find((tier) => tier.id === planDefault.tier_id) : (personalization.required ? tiers[0] : null);
      const type = personalization.type_code;
      return `<div class="lp-personalization"><label>${esc(module.name)} · ${esc(personalization.name || personalization.type_label || type)}<select data-module="${esc(module.code)}" data-type="${esc(type)}" data-max-tier="${Number(tiers.at(-1)?.value || 0)}">${!personalization.required && !baseTier ? '<option value="">Sem limite adicional</option>' : ''}${tiers.map((tier) => `<option value="${Number(tier.value)}" ${(baseTier?.id === tier.id) ? 'selected' : ''}>${Number(tier.value).toLocaleString('pt-BR')} · ${money(tier.additional_monthly_amount)}/mês</option>`).join('')}<option value="over_limit">Acima de ${Number(tiers.at(-1)?.value || 0).toLocaleString('pt-BR')} · solicitar proposta</option></select></label></div>`;
    })).join('');
    node.querySelectorAll('select').forEach((select) => select.addEventListener('change', quote));
  };

  const quote = () => {
    clearTimeout(state.quoteTimer);
    state.quoteTimer = setTimeout(async () => {
      const codes = selectedCodes();
      const title = $('#lp-summary-title');
      const status = $('#lp-summary-status');
      const buy = $('#lp-buy');
      if (!codes.length) { title.textContent = 'Selecione um plano ou módulo'; $('#lp-total').textContent = '—'; buy.setAttribute('aria-disabled', 'true'); state.quote = null; return; }
      const overLimit = [...document.querySelectorAll('.lp-personalization select')].find((select) => select.value === 'over_limit');
      if (overLimit) {
        const module = moduleByCode(overLimit.dataset.module);
        const segments = module?.segments || [];
        proposal.hidden = false;
        form.elements['modules[]'].value = module.code;
        form.elements.catalog_max_capacity.value = overLimit.dataset.maxTier;
        form.elements.desired_capacity.min = String(Number(overLimit.dataset.maxTier) + 1);
        form.elements.desired_capacity.value = '';
        const segmentField = form.elements['profiles[]'];
        segmentField.innerHTML = '<option value="">Selecione</option>' + segments.map((code) => `<option value="${esc(segmentNames[code] || code)}">${esc(segmentNames[code] || code)}</option>`).join('');
        $('#lp-proposal').scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
        title.textContent = 'Capacidade acima das faixas publicadas'; $('#lp-total').textContent = 'Sob consulta'; $('#lp-summary-items').textContent = 'A maior faixa publicada não atende ao limite desejado.'; status.textContent = 'Envie o pedido de proposta para a equipe comercial.'; buy.setAttribute('aria-disabled', 'true'); state.quote = null; return;
      }
      proposal.hidden = true;
      title.textContent = currentPlan()?.name || 'Somente módulos avulsos';
      status.textContent = 'Atualizando cotação pelo servidor…'; buy.setAttribute('aria-disabled', 'true');
      const items = codes.map((code) => ({ module_code: code, quantity: 1, personalizations: [...document.querySelectorAll(`[data-module="${CSS.escape(code)}"]`)].filter((select) => select.value).map((select) => ({ type_code: select.dataset.type, tier_value: Number(select.value) })) }));
      try {
        const result = await window.FokusApi.request('/catalog/fokus-law/quote', { method: 'POST', body: { product_code: 'fokus-law', selection_mode: state.plan ? 'plan' : 'modules', ...(state.plan ? { plan_code: state.plan } : {}), cycle: state.cycle, items } });
        state.quote = result;
        $('#lp-total').textContent = money(result.amount);
        $('#lp-cycle-label').textContent = state.cycle === 'annual' ? 'Total anual' : 'Total mensal';
        $('#lp-summary-items').innerHTML = `<p>Plano-base: ${money(result.breakdown.plan_base)}</p><p>Módulos adicionais: ${money(result.breakdown.extra_modules)}</p><p>Ajuste de capacidade: ${money(result.breakdown.capacity_adjustments)}</p>`;
        status.textContent = `Cotação baseada na versão ${result.publication_versions.product_catalog_release_version || result.publication_versions.product_catalog_version} do catálogo.`;
        buy.setAttribute('aria-disabled', 'false');
        localStorage.setItem('fokus-law-offer-v1', JSON.stringify({ selection_mode: state.plan ? 'plan' : 'modules', plan_code: state.plan || null, cycle: state.cycle, items, quote_version: result.publication_versions.product_catalog_release_version || result.publication_versions.product_catalog_version }));
      } catch (error) { state.quote = null; status.textContent = error.message || 'Não foi possível calcular esta composição.'; $('#lp-total').textContent = '—'; }
    }, 180);
  };

  offersNode.addEventListener('click', (event) => {
    const planButton = event.target.closest('[data-choose-plan]');
    const soloButton = event.target.closest('[data-choose-standalone]');
    if (planButton) { state.plan = planButton.dataset.choosePlan; state.selected.clear(); renderBuilder(); $('#composicao').scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' }); quote(); }
    if (soloButton) { state.plan = ''; state.selected = new Set([soloButton.dataset.chooseStandalone]); renderBuilder(); $('#composicao').scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' }); quote(); }
  });
  $('#lp-plan').addEventListener('change', () => { state.plan = $('#lp-plan').value; state.selected.clear(); renderBuilder(); quote(); });
  document.querySelectorAll('[data-cycle]').forEach((button) => button.addEventListener('click', () => { state.cycle = button.dataset.cycle; document.querySelectorAll('[data-cycle]').forEach((item) => item.setAttribute('aria-pressed', String(item === button))); renderOffers(); quote(); }));
  $('#lp-buy').href = '/contratar/fokus-law';
  $('#lp-buy').addEventListener('click', (event) => { if (!state.quote) { event.preventDefault(); return; } });

  const proposal = $('#lp-proposal');
  const form = $('#lp-interest-form');
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const status = $('#lp-interest-status'); status.textContent = 'Enviando solicitação…';
    const button = form.querySelector('button'); button.disabled = true;
    const data = Object.fromEntries(new FormData(form));
    data.products = ['law']; data.profiles = [form.elements['profiles[]'].value]; data.modules = [form.elements['modules[]'].value]; data.privacy_accepted = form.elements.privacy_accepted.checked; data.catalog_max_capacity = Number(form.elements.catalog_max_capacity.value); data.desired_capacity = Number(form.elements.desired_capacity.value); data.source_url = location.href;
    try { await window.FokusApi.request('/product-interests', { method: 'POST', body: data }); status.textContent = 'Pedido registrado. A equipe comercial poderá analisar a capacidade solicitada.'; form.reset(); }
    catch (error) { status.textContent = error.message || 'Não foi possível enviar o pedido.'; }
    finally { button.disabled = false; }
  });

  if ('IntersectionObserver' in window && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
    const observer = new IntersectionObserver((entries) => entries.forEach((entry) => { if (entry.isIntersecting) { entry.target.classList.add('is-visible'); observer.unobserve(entry.target); } }), { threshold: .12 });
    document.querySelectorAll('[data-reveal]').forEach((node) => { node.classList.add('lp-reveal'); observer.observe(node); });
  }
  window.FokusApi.request('/catalog/fokus-law').then((catalog) => {
    state.catalog = catalog;
    renderOffers(); renderBuilder(); quote();
    const profiles = form.elements['profiles[]'];
    profiles.innerHTML = '<option value="">Selecione uma faixa acima de catálogo primeiro</option>';
  }).catch((error) => { const catalogUnavailable = [404, 422, 503].includes(error.status); offersNode.innerHTML = `<p class="lp-state">${catalogUnavailable ? 'Não há ofertas disponíveis no momento. Consulte novamente em breve.' : 'Não foi possível carregar as ofertas agora. Tente novamente em instantes.'}</p>`; $('#lp-modules').innerHTML = '<p class="lp-state">A composição será exibida assim que as ofertas estiverem disponíveis.</p>'; $('#lp-summary-status').textContent = 'A cotação usa os valores do catálogo publicado.'; });
})();

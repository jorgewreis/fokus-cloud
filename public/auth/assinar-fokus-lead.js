(() => {
  const content = document.querySelector('#lead-checkout-content');
  const message = document.querySelector('#lead-checkout-status');
  const key = 'fokus-lead-offer-v1';
  const returnTo = '/assinaturas/fokus-lead';
  const money = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value || 0));
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]));
  const quotePayload = (selection) => ({ product_code: 'fokus-lead', selection_mode: selection.selection_mode, ...(selection.plan_code ? { plan_code: selection.plan_code } : {}), cycle: selection.cycle, items: selection.items });
  const setLoginForm = () => {
    content.innerHTML = `<h2>Entre para continuar.</h2><p>Você precisa de uma conta com e-mail confirmado e empresa ativa para contratar o Fokus Lead.</p><form id="lead-offer-login"><label>CPF ou CNPJ<input class="fs-form-control" name="document" autocomplete="username" maxlength="18" required></label><label>Senha<input class="fs-form-control" name="password" type="password" autocomplete="current-password" required></label><button type="submit">Entrar e continuar</button><p id="lead-login-status" role="status" aria-live="polite"></p></form><p><a class="lead-checkout-action" href="/cadastro?return_to=%2Fassinaturas%2Ffokus-lead">Criar conta e empresa</a></p>`;
    const form = document.querySelector('#lead-offer-login'); const doc = form.elements.namedItem('document');
    const mask = window.FokusDocuments.bind(doc, () => window.FokusDocuments.typeOf(doc.value));
    form.addEventListener('submit', async (event) => {
      event.preventDefault(); const status = document.querySelector('#lead-login-status'); const button = form.querySelector('button'); const type = window.FokusDocuments.typeOf(doc.value); mask.apply();
      if (!window.FokusDocuments.valid(doc.value, type)) { status.textContent = 'Informe um CPF ou CNPJ válido.'; doc.focus(); return; }
      button.disabled = true; status.textContent = 'Validando acesso…';
      try {
        const result = await FokusApi.request('/auth/login', { method: 'POST', body: { document: window.FokusDocuments.normalize(doc.value, type), password: form.elements.password.value } });
        if (!result.user?.email_verified) { location.assign(`/verificar-email?return_to=${encodeURIComponent(returnTo)}`); return; }
        if (result.active_company_id) { location.reload(); return; }
        if (!result.companies?.length) { status.innerHTML = `Esta conta ainda não tem uma empresa. <a href="/portal/empresas">Gerenciar empresas</a>`; button.disabled = false; return; }
        status.innerHTML = `Selecione a empresa para prosseguir:<label>Empresa<select class="fs-form-select" id="lead-offer-company">${result.companies.map((company) => `<option value="${esc(company.id)}">${esc(company.name)}</option>`).join('')}</select></label><button id="lead-company-confirm" type="button">Continuar</button>`;
        document.querySelector('#lead-company-confirm').addEventListener('click', async () => { try { await FokusApi.request('/auth/select-company', { method: 'POST', body: { company_id: document.querySelector('#lead-offer-company').value } }); location.reload(); } catch (error) { status.textContent = error.message || 'Não foi possível selecionar a empresa.'; } });
      } catch (error) { status.textContent = error.message || 'Não foi possível entrar.'; button.disabled = false; }
    });
  };
  (async () => {
    let selection; try { selection = JSON.parse(localStorage.getItem(key) || 'null'); } catch {}
    if (!selection?.items?.length) { location.replace('/produtos/fokus-lead/planos'); return; }
    let user; try { user = await FokusApi.request('/auth/me'); } catch { user = null; }
    if (!user) { setLoginForm(); return; }
    if (!user.user?.email_verified) { location.replace(`/verificar-email?return_to=${encodeURIComponent(returnTo)}`); return; }
    if (!user.active_company_id) { content.innerHTML = `<h2>Selecione uma empresa.</h2><p>Vincule ou selecione a empresa responsável pela assinatura.</p><a class="lead-checkout-action" href="/portal/empresas">Gerenciar empresas</a>`; return; }
    try {
      const [catalog, quote, subscriptions] = await Promise.all([FokusApi.request('/catalog/fokus-lead'), FokusApi.request('/catalog/fokus-lead/quote', { method: 'POST', body: quotePayload(selection) }), FokusApi.request('/subscriptions')]);
      const names = new Map((catalog.modules || []).map((module) => [module.code, module.name]));
      const existing = subscriptions.find((subscription) => ['lead', 'fokus-lead'].includes(subscription.product_code) && subscription.status !== 'encerrada');
      content.innerHTML = `<h2>${esc(catalog.plans?.find((plan) => plan.code === selection.plan_code)?.name || 'Assinatura personalizada')}</h2><ul>${selection.items.map((item) => `<li>${esc(names.get(item.module_code) || item.module_code)}</li>`).join('')}</ul><p>Ciclo de cobrança: <strong>${selection.cycle === 'annual' ? 'Anual' : 'Mensal'}</strong></p><p>${selection.plan_code ? 'Plano-base' : 'Módulos selecionados'}: ${money(quote.breakdown.plan_base || quote.breakdown.extra_modules)}</p>${selection.plan_code ? `<p>Módulos extras: ${money(quote.breakdown.extra_modules)}</p>` : ''}<h2>Total ${selection.cycle === 'annual' ? 'anual' : 'mensal'}: ${money(quote.amount)}</h2><p>Catálogo versão ${esc(quote.publication_versions.product_catalog_release_version || quote.publication_versions.product_catalog_version)}.</p>${existing ? `<p>Esta empresa já possui uma assinatura Fokus Lead em andamento. Abra o portal para consultar o acesso e a assinatura.</p><a class="lead-checkout-action" href="/portal/fokus-lead">Abrir Fokus Lead</a>` : '<button id="lead-start-checkout" type="button">Iniciar pagamento seguro</button>'}`;
      if (existing) return;
      document.querySelector('#lead-start-checkout').addEventListener('click', async (event) => {
        const button = event.currentTarget; button.disabled = true; message.textContent = 'Validando a cotação e iniciando o pagamento…';
        try { const result = await FokusApi.request('/subscriptions/checkout', { method: 'POST', body: { ...quotePayload(selection), voucher_code: null }, headers: { 'Idempotency-Key': crypto.randomUUID() } }); if (result.checkout_url) location.assign(result.checkout_url); else throw new Error('O checkout não retornou o endereço seguro de pagamento.'); }
        catch (error) { message.textContent = error.message || 'Não foi possível iniciar o pagamento.'; button.disabled = false; }
      });
    } catch (error) { content.innerHTML = `<p>${esc(error.message || 'A oferta selecionada não está mais disponível.')}</p><a class="lead-checkout-action" href="/produtos/fokus-lead/planos">Consultar ofertas atuais</a>`; }
  })();
})();

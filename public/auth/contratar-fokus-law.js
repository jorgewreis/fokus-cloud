(() => {
  const content = document.querySelector('#checkout-content');
  const message = document.querySelector('#checkout-message');
  const key = 'fokus-law-offer-v1';
  const money = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value || 0));
  const quotePayload = (selection) => ({ product_code: 'law', selection_mode: selection.selection_mode, ...(selection.plan_code ? { plan_code: selection.plan_code } : {}), cycle: selection.cycle, items: selection.items });
  const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]));
  (async () => {
    let selection;
    try { selection = JSON.parse(localStorage.getItem(key) || 'null'); } catch {}
    if (!selection?.items?.length) { location.replace('/produtos/fokus-law/planos'); return; }
    let user;
    try { user = await FokusApi.request('/auth/me'); } catch { user = null; }
    if (!user) {
      content.innerHTML = '<p>Entre na sua conta ou crie uma empresa para continuar. A composição não contém preços e será recotada pelo servidor.</p><form id="offer-login" class="lp-login"><label>CPF ou CNPJ<input name="document" autocomplete="username" inputmode="numeric" required /></label><label>Senha<input name="password" type="password" autocomplete="current-password" required /></label><button class="fs-btn fs-btn-primary" type="submit">Entrar</button><p id="offer-login-status" role="status" aria-live="polite"></p></form><p><a class="fs-btn fs-btn-outline-primary" href="/cadastro?return_to=%2Fcontratar%2Ffokus-law">Criar empresa</a></p>';
      const login = document.querySelector('#offer-login');
      login.addEventListener('submit', async (event) => {
        event.preventDefault(); const button = login.querySelector('button'); const status = document.querySelector('#offer-login-status'); button.disabled = true; status.textContent = 'Validando acesso…';
        try {
          const response = await FokusApi.request('/auth/login', { method: 'POST', body: Object.fromEntries(new FormData(login)) });
          if (!response.user?.email_verified) { location.assign('/verificar-email?return_to=%2Fcontratar%2Ffokus-law'); return; }
          if (response.active_company_id) { location.reload(); return; }
          if (!response.companies?.length) { status.innerHTML = 'Esta conta ainda não tem uma empresa ativa. <a href="/portal/empresas">Gerenciar empresas</a>'; button.disabled = false; return; }
          const choices = response.companies.map((company) => `<option value="${escape(company.id)}">${escape(company.name)}</option>`).join('');
          status.innerHTML = `Selecione a empresa para prosseguir:<label>Empresa<select id="offer-company">${choices}</select></label><button class="fs-btn fs-btn-secondary" id="offer-company-confirm" type="button">Continuar</button>`;
          document.querySelector('#offer-company-confirm').addEventListener('click', async () => {
            try { await FokusApi.request('/auth/select-company', { method: 'POST', body: { company_id: document.querySelector('#offer-company').value } }); location.reload(); }
            catch (error) { status.textContent = error.message || 'Não foi possível selecionar a empresa.'; }
          });
        } catch (error) { status.textContent = error.message || 'Não foi possível entrar.'; button.disabled = false; }
      });
      return;
    }
    if (!user.user?.email_verified) { location.replace('/verificar-email?return_to=%2Fcontratar%2Ffokus-law'); return; }
    if (!user.active_company_id) { content.innerHTML = '<p>Selecione ou crie uma empresa para iniciar a assinatura.</p><a class="fs-btn fs-btn-primary" href="/portal/empresas">Gerenciar empresas</a>'; return; }
    try {
      const [catalog, publicQuote] = await Promise.all([FokusApi.request('/catalog/law'), FokusApi.request('/catalog/law/quote', { method: 'POST', body: quotePayload(selection) })]);
      let existing = null;
      let canManageLaw = true;
      try { existing = await FokusApi.request('/law/subscription'); }
      catch (error) { if (error.status === 403) canManageLaw = false; else throw error; }
      let quote = publicQuote;
      let changePayload = null;
      if (existing?.subscription && canManageLaw) {
        const plan = selection.plan_code ? catalog.plans?.find((item) => item.code === selection.plan_code) : null;
        changePayload = { billing_cycle: selection.cycle, version: Number(existing.subscription.version), reason: 'Alteração solicitada pela página pública de planos.', items: selection.items, ...(plan ? { target_plan_id: plan.id } : {}) };
        quote = await FokusApi.request('/law/subscription/quote', { method: 'POST', body: changePayload });
      }
      const names = new Map((catalog.modules || []).map((module) => [module.code, module.name]));
      content.innerHTML = `<h2>${escape(selection.plan_code || 'Somente módulos')}</h2><ul>${selection.items.map((item) => `<li>${escape(names.get(item.module_code) || item.module_code)}${item.personalizations?.length ? ` · ${item.personalizations.map((p) => `${escape(p.type_code)}: ${Number(p.tier_value).toLocaleString('pt-BR')}`).join(', ')}` : ''}</li>`).join('')}</ul><p>Ciclo: <strong>${selection.cycle === 'annual' ? 'Anual' : 'Mensal'}</strong></p><p>Plano-base: ${money(quote.breakdown?.plan_base ?? 0)}</p><p>Módulos extras: ${money(quote.breakdown?.extra_modules ?? 0)}</p><p>Ajuste de capacidade: ${money(quote.breakdown?.capacity_adjustments ?? 0)}</p><h2>Total ${selection.cycle === 'annual' ? 'anual' : 'mensal'}: ${money(existing?.subscription ? quote.target?.amount : quote.amount)}</h2><p>${existing?.subscription ? 'A empresa já possui uma assinatura Fokus Law. Esta solicitação altera a assinatura atual, sem criar outra.' : `Catálogo versão ${quote.publication_versions.product_catalog_version}. O valor será validado novamente ao iniciar o pagamento.`}</p>${existing?.subscription && quote.charge_now !== undefined ? `<p>${quote.action === 'upgrade' ? `Cobrança proporcional agora: ${money(quote.charge_now)}.` : `Esta alteração entra em vigor em ${escape(quote.effective_at || 'data informada na assinatura')}.`}</p>` : ''}${!canManageLaw ? '<p>Somente a pessoa administradora da empresa pode alterar a assinatura Fokus Law.</p><a href="/portal/fokus-law/assinatura">Abrir gestão de assinatura</a>' : `<button id="start-checkout" class="fs-btn fs-btn-success" type="button">${existing?.subscription ? 'Solicitar alteração' : 'Iniciar pagamento'}</button>`}<p><a href="/produtos/fokus-law/planos">Voltar e alterar a composição</a></p>`;
      if (!canManageLaw) return;
      document.querySelector('#start-checkout').addEventListener('click', async (event) => {
        const button = event.currentTarget; button.disabled = true; message.textContent = 'Validando e iniciando checkout…';
        try {
          const result = existing?.subscription
            ? await FokusApi.request('/law/subscription/change', { method: 'POST', body: changePayload })
            : await FokusApi.request('/subscriptions/checkout', { method: 'POST', body: { ...quotePayload(selection), voucher_code: null } });
          if (result.checkout_url) location.assign(result.checkout_url);
          else if (existing?.subscription) { message.textContent = result.message || 'A alteração foi registrada.'; localStorage.removeItem(key); button.disabled = true; }
        } catch (error) {
          message.textContent = error.message || 'Não foi possível iniciar o checkout.';
          if (error.status === 409) message.innerHTML = `${escape(error.message)} <a href="/portal/fokus-law/assinatura">Acesse a assinatura atual para alterar a composição.</a>`;
          button.disabled = false;
        }
      });
    } catch (error) { content.innerHTML = `<p>${escape(error.message || 'A oferta selecionada não está mais disponível.')}</p><a class="fs-btn fs-btn-primary" href="/produtos/fokus-law/planos">Consultar ofertas atuais</a>`; }
  })();
})();

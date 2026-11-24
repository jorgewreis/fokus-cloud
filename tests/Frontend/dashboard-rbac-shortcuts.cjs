'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const dashboard = fs.readFileSync(path.join(root, 'public/portal/dashboard.html'), 'utf8');
const inlineScript = [...dashboard.matchAll(/<script>([\s\S]*?)<\/script>/g)].at(-1)?.[1];

assert.ok(inlineScript, 'O script do resumo do portal não foi encontrado.');

async function render(role, support = false) {
  const elements = {
    role: { textContent: '' }, actions: { textContent: '' }, subscriptions: { textContent: '', parentElement: { hidden: false } }, renewal: { textContent: '', parentElement: { hidden: false } }, message: { textContent: '' },
    users: { hidden: false }, subscriptionsLink: { hidden: false }, shortcuts: { hidden: false },
  };
  const requests = [];
  const sandbox = {
    role: elements.role, actions: elements.actions, subscriptions: elements.subscriptions, renewal: elements.renewal, message: elements.message,
    document: { querySelector: (selector) => ({ 'main .review-actions a[href="/portal/usuarios"]': elements.users, 'main .review-actions a[href="/portal/assinaturas"]': elements.subscriptionsLink, 'main .portal-section': elements.shortcuts }[selector] ?? null) },
    FokusApi: { async request(url) {
      requests.push(url);
      if (url === '/auth/me') {
        if (support) { const error = new Error('Unauthenticated.'); error.status = 401; error.payload = { message: 'Unauthenticated.' }; throw error; }
        return { user: { email_verified: true }, active_company_id: 'company-1', companies: [{ id: 'company-1', name: 'Empresa sintética', role }] };
      }
      if (url === '/subscriptions') return [];
      throw new Error(`Rota inesperada: ${url}`);
    }, async supportSessionActive() { requests.push('supportSessionActive'); return support; } },
    setTimeout, Date,
  };
  Object.defineProperty(sandbox, 'location', { get: () => '', set: () => assert.fail('Não deveria redirecionar.') });
  vm.runInNewContext(inlineScript.replace('(async()=>', 'globalThis.__dashboardRun=(async()=>'), sandbox);
  await sandbox.__dashboardRun;
  return { elements, requests };
}

(async () => {
  const admin = await render('admin');
  assert.equal(admin.elements.role.textContent, 'Administrador');
  assert.equal(admin.elements.actions.textContent, 'Gestão completa');
  assert.equal(admin.elements.users.hidden, false);
  assert.equal(admin.elements.subscriptionsLink.hidden, false);
  assert.deepEqual(admin.requests, ['/auth/me', '/subscriptions']);

  for (const role of ['gestor', 'usuario']) {
    const result = await render(role);
    assert.equal(result.elements.role.textContent, role === 'gestor' ? 'Gestor' : 'Usuário');
    assert.deepEqual(result.requests, ['/auth/me']);
    assert.equal(result.elements.subscriptions.parentElement.hidden, true);
    assert.equal(result.elements.renewal.parentElement.hidden, true);
    assert.equal(result.elements.actions.textContent, 'Perfil');
    assert.equal(result.elements.users.hidden, true);
    assert.equal(result.elements.subscriptionsLink.hidden, true);
    assert.equal(result.elements.message.textContent, 'Empresa ativa: Empresa sintética');
  }

  const support = await render(null, true);
  assert.deepEqual(support.requests, ['/auth/me', 'supportSessionActive']);
  assert.equal(support.elements.role.textContent, 'Suporte');
  assert.equal(support.elements.actions.textContent, 'Encerrar o acesso');
  assert.equal(support.elements.message.textContent, 'Sessão de suporte ativa. Os dados do cliente não ficam disponíveis.');
  assert.doesNotMatch(support.elements.message.textContent, /Empresa sintética/);
  assert.equal(support.elements.subscriptions.parentElement.hidden, true);
  assert.equal(support.elements.renewal.parentElement.hidden, true);
  assert.equal(support.elements.shortcuts.hidden, true);

  const subscriptionsPage = fs.readFileSync(path.join(root, 'public/portal/subscriptions.html'), 'utf8');
  const subscriptionsScript = [...subscriptionsPage.matchAll(/<script>([\s\S]*?)<\/script>/g)].at(-1)[1];
  const denialMessage = { textContent: '' };
  let redirected = false;
  const denied = new Error('Forbidden'); denied.status = 403;
  const subscriptionsSandbox = {
    document: { querySelector: (selector) => selector === '#message' ? denialMessage : {} },
    FokusApi: { request: async () => { throw denied; } },
    location: { assign: () => { redirected = true; } },
  };
  vm.runInNewContext(subscriptionsScript.replace('(async () =>', 'globalThis.__run=(async () =>'), subscriptionsSandbox);
  await subscriptionsSandbox.__run;
  assert.match(denialMessage.textContent, /Somente administradores/);
  assert.equal(redirected, false);
  const supportRequests = [];
  subscriptionsSandbox.FokusApi.request = async (url) => { supportRequests.push(url); const error = new Error('Forbidden'); error.status = 403; error.payload = { code: 'support_customer_api_denied' }; throw error; };
  const supportSubscriptionsSandbox = { ...subscriptionsSandbox };
  vm.runInNewContext(subscriptionsScript.replace('(async () =>', 'globalThis.__run=(async () =>'), supportSubscriptionsSandbox);
  await supportSubscriptionsSandbox.__run;
  assert.deepEqual(supportRequests, ['/subscriptions']);
  assert.match(denialMessage.textContent, /sessão de suporte/);
  assert.equal(redirected, false);

  const leadScript = fs.readFileSync(path.join(root, 'public/auth/assinar-fokus-lead.js'), 'utf8');
  for (const role of ['gestor', 'usuario']) {
    const content = { innerHTML: '' }, calls = [];
    const leadSandbox = {
      document: { querySelector: (selector) => selector === '#lead-checkout-content' ? content : { textContent: '' } },
      localStorage: { getItem: () => JSON.stringify({ items: [{ module_code: 'synthetic' }] }) },
      FokusApi: { request: async (url) => { calls.push(url); return { user: { email_verified: true }, active_company_id: 'company-1', companies: [{ id: 'company-1', role }] }; } },
    };
    vm.runInNewContext(leadScript.replace('(async () => {', 'globalThis.__run=(async () => {'), leadSandbox);
    await leadSandbox.__run;
    assert.deepEqual(calls, ['/auth/me']);
    assert.match(content.innerHTML, /Somente um administrador/);
  }
  const supportLeadContent = { innerHTML: '' }, supportLeadCalls = [];
  const supportLead = {
    document: { querySelector: (selector) => selector === '#lead-checkout-content' ? supportLeadContent : { textContent: '' } },
    localStorage: { getItem: () => JSON.stringify({ items: [{ module_code: 'synthetic' }] }) },
    FokusApi: {
      request: async (url) => { supportLeadCalls.push(url); const error = new Error('Unauthenticated.'); error.status = 401; throw error; },
      supportSessionActive: async () => { supportLeadCalls.push('supportSessionActive'); return true; },
    },
  };
  vm.runInNewContext(leadScript.replace('(async () => {', 'globalThis.__run=(async () => {'), supportLead);
  await supportLead.__run;
  assert.deepEqual(supportLeadCalls, ['/auth/me', 'supportSessionActive']);
  assert.match(supportLeadContent.innerHTML, /sessão de suporte/);
  assert.doesNotMatch(supportLeadContent.innerHTML, /Somente um administrador/);

  const leadContent = { innerHTML: '' }, leadCalls = [];
  const adminLead = {
    document: { querySelector: (selector) => selector === '#lead-checkout-content' ? leadContent : { textContent: '' } },
    localStorage: { getItem: () => JSON.stringify({ items: [{ module_code: 'synthetic' }], cycle: 'monthly' }) },
    FokusApi: { request: async (url) => {
      leadCalls.push(url);
      if (url === '/auth/me') return { user: { email_verified: true }, active_company_id: 'company-1', companies: [{ id: 'company-1', role: 'admin' }] };
      if (url === '/catalog/fokus-lead') return { modules: [], plans: [] };
      if (url === '/catalog/fokus-lead/quote') return { amount: 10, breakdown: { extra_modules: 10 }, publication_versions: { product_catalog_version: 1 } };
      if (url === '/subscriptions') return [{ product_code: 'lead', status: 'ativa' }];
      throw new Error(`Rota inesperada: ${url}`);
    } },
  };
  vm.runInNewContext(leadScript.replace('(async () => {', 'globalThis.__run=(async () => {'), adminLead);
  await adminLead.__run;
  assert.deepEqual(leadCalls, ['/auth/me', '/catalog/fokus-lead', '/catalog/fokus-lead/quote', '/subscriptions']);
  assert.match(leadContent.innerHTML, /já possui uma assinatura Fokus Lead/);
  console.log('dashboard, subscriptions and Lead: access states and requests verified');
})().catch((error) => { console.error(error); process.exitCode = 1; });

'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const dashboard = fs.readFileSync(path.join(root, 'public/portal/dashboard.html'), 'utf8');
const inlineScript = [...dashboard.matchAll(/<script>([\s\S]*?)<\/script>/g)].at(-1)?.[1];

assert.ok(inlineScript, 'O script do resumo do portal não foi encontrado.');

async function render(role) {
  const elements = {
    role: { textContent: '' }, actions: { textContent: '' }, subscriptions: { textContent: '' }, renewal: { textContent: '' }, message: { textContent: '' },
    users: { hidden: false }, subscriptionsLink: { hidden: false },
  };
  const requests = [];
  const sandbox = {
    role: elements.role, actions: elements.actions, subscriptions: elements.subscriptions, renewal: elements.renewal, message: elements.message,
    document: { querySelector: (selector) => ({ 'a[href="/portal/usuarios"]': elements.users, 'a[href="/portal/assinaturas"]': elements.subscriptionsLink }[selector] ?? null) },
    FokusApi: { async request(url) {
      requests.push(url);
      if (url === '/auth/me') return { user: { email_verified: true }, active_company_id: 'company-1', companies: [{ id: 'company-1', name: 'Empresa sintética', role }] };
      if (url === '/subscriptions') return [];
      throw new Error(`Rota inesperada: ${url}`);
    } },
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

  for (const role of ['gestor', 'user']) {
    const result = await render(role);
    assert.equal(result.elements.role.textContent, 'Usuário');
    assert.equal(result.elements.actions.textContent, 'Perfil');
    assert.equal(result.elements.users.hidden, true);
    assert.equal(result.elements.subscriptionsLink.hidden, true);
    assert.equal(result.elements.message.textContent, 'Empresa ativa: Empresa sintética');
  }

  console.log('dashboard-rbac-shortcuts: admin=visible; non-admin=hidden; requests=expected');
})().catch((error) => { console.error(error); process.exitCode = 1; });

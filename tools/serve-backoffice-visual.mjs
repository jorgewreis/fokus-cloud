import { createReadStream, existsSync } from 'node:fs';
import { stat } from 'node:fs/promises';
import { createServer } from 'node:http';
import { extname, resolve } from 'node:path';

const publicRoot = resolve(import.meta.dirname, '../public');
const port = Number(process.env.BACKOFFICE_VISUAL_PORT || 4177);
const types = { '.css': 'text/css', '.html': 'text/html', '.js': 'application/javascript', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
const admin = { id: 'PAD_VISUAL', name: 'Administração Fokus', role: 'superadministrador', permissions: ['platform.security.manage', 'platform.catalog.publish', 'platform.users.view', 'platform.subscriptions.manage'] };
const products = [{ id: 'PRD_LAW', name: 'Fokus Law', code: 'law', status: 'ativo', plans: [{ id: 'PLN_1', name: 'Essencial' }] }];
const modules = [{ id: 'MOD_1', product_id: 'PRD_LAW', product_name: 'Fokus Law', name: 'Gestão de processos', code: 'processos', module_code: 'processos', status: 'ativo', publication_state: 'publicado', monthly_price: 29.9, segments: ['advocacia'], capabilities: ['Controle de prazos'], capability_codes: ['prazos'], dependencies: [], incompatibilities: [], linked_plans: [], personalizations: [] }];
const plans = [{ id: 'PLN_1', product_id: 'PRD_LAW', product_code: 'law', product_name: 'Fokus Law', product_status: 'ativo', product_publication_version: 3, code: 'law-essencial', name: 'Essencial', full_name: 'Fokus Law - Essencial', segment: 'advocacia', status: 'ativo', publication_state: 'publicado', featured: true, configured_monthly_amount: null, monthly_amount: 24.9, annual_amount: 249, modules_count: 1, modules: [{ ...modules[0], monthly_amount: 29.9 }], personalization_defaults: [], subscription_count: 2, company_count: 2, voucher_count: 1 }];
const subscriptionRows = Array.from({ length: 17 }, (_, index) => ({
    id: `SUB_VISUAL_${String(index + 1).padStart(2, '0')}`,
    company_name: index === 0 ? 'Empresa de Demonstração' : `Empresa de Teste ${String(index + 1).padStart(2, '0')}`,
    product_id: index % 2 ? 'PRD_LEAD' : 'PRD_LAW',
    product_code: index % 2 ? 'lead' : 'law',
    product_name: index % 2 ? 'Fokus Lead' : 'Fokus Law',
    plan_name: index % 2 ? 'Essencial' : 'Advocacia',
    status: ['ativa', 'aguardando_pagamento', 'inadimplente', 'suspensa', 'cancelamento_agendado', 'encerrada'][index % 6],
    billing_cycle: index % 2 ? 'annual' : 'monthly',
    amount: index % 2 ? 249 : 64.7,
    monthly_amount: index % 2 ? 24.9 : 64.7,
    current_period_starts_at: '2026-09-01T00:00:00.000Z',
    current_period_ends_at: '2026-10-01T00:00:00.000Z',
    cancel_at: index % 6 === 4 ? '2026-10-01T00:00:00.000Z' : null,
}));
const subscriptionChanges = [{
    id: 'CHG_VISUAL_01',
    subscription_id: 'SUB_VISUAL_01',
    type: 'upgrade',
    status: 'aplicada',
    effective_at: '2026-08-01T00:00:00.000Z',
    proration_amount: 12.5,
    reason: 'Atualização do contrato solicitada pela empresa.',
    before_snapshot: { status: 'ativa', plan_name: 'Inicial', billing_cycle: 'monthly', amount: 39.9 },
    after_snapshot: { status: 'ativa', plan_name: 'Advocacia', billing_cycle: 'monthly', amount: 64.7 },
    created_at: '2026-08-01T12:00:00.000Z',
}];

const json = (response, payload, status = 200) => {
    response.writeHead(status, { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' });
    response.end(JSON.stringify(payload));
};

createServer(async (request, response) => {
    const url = new URL(request.url, `http://${request.headers.host}`);
    if (url.pathname === '/api/csrf-token') return json(response, { token: 'visual-test-token' });
    if (url.pathname === '/api/auth/law-context') return json(response, { user: { name: 'Pessoa Teste' }, systems: [{ value: 'CMP_VISUAL', label: 'Empresa de Demonstração — Fokus Law · Advocacia', profiles: [{ value: 'usuario', label: 'Usuário comum' }] }] });
    if (url.pathname === '/api/backoffice/auth/me') return json(response, { admin });
    if (url.pathname === '/api/backoffice/dashboard') return json(response, { user: admin, alerts: [] });
    if (url.pathname === '/api/backoffice/companies') return json(response, { data: [], meta: { total: 0, current_page: 1, per_page: 15, last_page: 1 }, summary: {} });
    if (url.pathname === '/api/backoffice/directory/companies') return json(response, { data: [{ id: 'CMP_VISUAL', label: 'Fokus Law - Empresa de Demonstração' }] });
    if (url.pathname === '/api/backoffice/directory/users' && request.method === 'POST') return json(response, { message: 'Convite enviado.' }, 201);
    if (url.pathname === '/api/backoffice/directory/users') return json(response, { data: [
        { id: 'PAD_VISUAL', name: 'Administração Fokus', email: 'admin@fokuscloud.test', type: 'plataforma', role: 'superadministrador', status: 'ativo', company_count: 0 },
        { id: 'USR_VISUAL', name: 'Ana Empresa', email: 'ana@example.test', type: 'empresa', status: 'ativa', profile: 'Administrador', company_names: ['Empresa de Demonstração'], company_count: 1 },
    ], meta: { total: 2, current_page: Number(url.searchParams.get('page') || 1), per_page: 15, last_page: 1 } });
    if (url.pathname === '/api/backoffice/directory/users/empresa/USR_VISUAL') return json(response, { id: 'USR_VISUAL', name: 'Ana Empresa', email: 'ana@example.test', type: 'empresa', status: 'ativa', cpf: '12345678901', memberships: [{ company_id: 'CMP_VISUAL', company_name: 'Empresa de Demonstração', role: 'admin', status: 'ativo', subscriptions: [{ product_name: 'Fokus Law', plan_name: 'Essencial', status: 'ativa', billing_cycle: 'monthly' }] }] });
    if (url.pathname === '/api/backoffice/directory/users/plataforma/PAD_VISUAL') return json(response, { id: 'PAD_VISUAL', name: 'Administração Fokus', email: 'admin@fokuscloud.test', type: 'plataforma', role: 'superadministrador', status: 'ativo' });
    if (url.pathname === '/api/backoffice/catalog/products') return json(response, { products });
    if (url.pathname === '/api/backoffice/catalog') return json(response, { products: [{ ...products[0], modules }], options: { segments: { law: [{ code: 'advocacia', label: 'Advocacia' }] }, module_codes: [{ code: 'processos', label: 'Processos' }], personalization_types: [{ code: 'usuarios', label: 'Usuários' }] } });
    if (url.pathname === '/api/backoffice/subscriptions' && request.method === 'GET') {
        const query = (url.searchParams.get('q') || '').toLowerCase();
        const status = url.searchParams.get('status') || '';
        const productId = url.searchParams.get('product_id') || '';
        const page = Math.max(1, Number(url.searchParams.get('page') || 1));
        const perPage = Math.max(1, Number(url.searchParams.get('per_page') || 15));
        const filtered = subscriptionRows.filter((subscription) => (!query || `${subscription.company_name} ${subscription.product_name}`.toLowerCase().includes(query)) && (!status || subscription.status === status) && (!productId || subscription.product_id === productId));
        const start = (page - 1) * perPage;
        return json(response, { data: filtered.slice(start, start + perPage), meta: { total: filtered.length, current_page: page, per_page: perPage, last_page: Math.max(1, Math.ceil(filtered.length / perPage)) } });
    }
    if (url.pathname.startsWith('/api/backoffice/subscriptions/') && request.method === 'GET') {
        const id = decodeURIComponent(url.pathname.split('/').pop());
        const subscription = subscriptionRows.find((item) => item.id === id);
        if (!subscription) return json(response, { message: 'Assinatura não encontrada.' }, 404);
        return json(response, {
            ...subscription,
            company_id: 'CMP_VISUAL',
            version: 1,
            plan_id: 'PLN_1',
            plan_code: 'law-advocacia',
            commercial_snapshot: { plan_id: 'PLN_1', plan_name: subscription.plan_name, billing_cycle: subscription.billing_cycle, monthly_amount: subscription.monthly_amount, amount: subscription.amount, status: subscription.status },
            items: [{ id: 'ITM_VISUAL', name: 'Gestão de processos', quantity: 2, unit_price: 29.9, conditions: { plan_code: 'law-advocacia', usage_limit: 10 } }],
            payments: [{ id: 'PAG_VISUAL', provider: 'mercado_pago', status: 'aprovado', amount: subscription.amount, currency: 'BRL', paid_at: '2026-09-02T13:15:00.000Z', billing_period_starts_at: '2026-09-01T00:00:00.000Z', billing_period_ends_at: '2026-10-01T00:00:00.000Z', created_at: '2026-09-01T12:00:00.000Z' }],
            history: subscriptionChanges.filter((change) => change.subscription_id === id),
        });
    }
    if (url.pathname.startsWith('/api/backoffice/subscriptions/') && request.method === 'PATCH') {
        let body = {};
        for await (const chunk of request) body = JSON.parse(chunk.toString() || '{}');
        const id = decodeURIComponent(url.pathname.split('/').pop());
        const subscription = subscriptionRows.find((item) => item.id === id);
        if (!subscription) return json(response, { message: 'Assinatura não encontrada.' }, 404);
        subscriptionChanges.unshift({ id: `CHG_VISUAL_${subscriptionChanges.length + 1}`, subscription_id: id, type: body.action, status: body.action === 'downgrade' ? 'agendada' : 'aplicada', effective_at: '2026-10-01T00:00:00.000Z', proration_amount: 0, reason: body.reason, before_snapshot: { status: subscription.status, plan_name: subscription.plan_name }, after_snapshot: { status: subscription.status, plan_name: subscription.plan_name }, created_at: new Date().toISOString() });
        if (body.action === 'suspensao') subscription.status = 'suspensa';
        if (body.action === 'reativacao') subscription.status = 'ativa';
        if (body.action === 'cancelamento') subscription.status = 'cancelamento_agendado';
        if (body.action === 'cancelamento_imediato') subscription.status = 'encerrada';
        return json(response, { message: 'Alteração comercial registrada.', status: 'aplicada', effective_at: new Date().toISOString() });
    }
    if (url.pathname === '/api/backoffice/plans') return json(response, plans);
    if (url.pathname.startsWith('/api/backoffice/')) return json(response, {});

    const relative = url.pathname === '/backoffice/' || /^\/backoffice\/(?!assets\/|pages\/)/.test(url.pathname)
        ? 'backoffice/index.html'
        : url.pathname.replace(/^\//, '');
    const file = resolve(publicRoot, relative);
    if (!file.startsWith(publicRoot) || !existsSync(file) || !(await stat(file)).isFile()) {
        response.writeHead(404); response.end(); return;
    }
    response.writeHead(200, { 'content-type': types[extname(file)] || 'application/octet-stream', 'cache-control': 'no-store' });
    createReadStream(file).pipe(response);
}).listen(port, '127.0.0.1', () => console.log(`Backoffice visual server on ${port}`));

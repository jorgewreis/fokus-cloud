import { test, expect } from '@playwright/test';

const grantBillingPermissions = async (page) => {
    await page.route('**/api/backoffice/auth/me', async (route) => {
        const response = await route.fetch();
        const payload = await response.json();
        const permissions = [...new Set([...(payload.admin?.permissions || []), 'platform.payments.view', 'platform.reconciliation.view', 'platform.reconciliation.manage', 'platform.refunds.request', 'platform.refunds.manage'])];
        await route.fulfill({ response, json: { ...payload, admin: { ...payload.admin, permissions } } });
    });
};

const payments = Array.from({ length: 17 }, (_, index) => ({
    id: `PAG_VISUAL_${String(index + 1).padStart(2, '0')}`,
    company_name: index === 0 ? 'Empresa Alpha Financeira' : `Empresa Financeira ${String(index + 1).padStart(2, '0')}`,
    subscription_id: `ASS_VISUAL_${index + 1}`,
    provider_subscription_id: `preapproval-${index + 1}`,
    provider: 'mercado_pago', provider_payment_id: `mp-payment-${index + 1}`,
    status: 'aprovado', amount: 64.7, currency: 'BRL', paid_at: '2026-09-20T10:00:00.000Z',
    billing_period_starts_at: '2026-09-20T00:00:00.000Z', billing_period_ends_at: '2026-10-20T00:00:00.000Z',
    created_at: '2026-09-20T10:00:00.000Z',
}));
const alert = {
    id: 'RCA_VISUAL_01', company_id: 'COM_VISUAL_01', company_name: 'Empresa Alpha Financeira',
    payment_id: payments[0].id, subscription_id: payments[0].subscription_id, type: 'payment_status',
    internal_status: 'recusado', mercado_pago_status: 'approved', impact: 'alto', status: 'aberta',
    opened_at: '2026-09-20T10:00:00.000Z', reviewed_at: null, correction_reason: null,
};
const refund = {
    id: 'RFD_VISUAL_01', company_id: 'COM_VISUAL_01', company_name: 'Empresa Alpha Financeira',
    payment_id: payments[0].id, provider_payment_id: payments[0].provider_payment_id,
    subscription_id: payments[0].subscription_id, amount: 20, allowed_case: 'erro_tecnico',
    requested_by_platform_admin_id: 'PAD_REQUESTER', approved_by_platform_admin_id: null,
    reason: 'Falha técnica confirmada.', status: 'solicitado', requested_at: '2026-09-20T11:00:00.000Z',
    approved_at: null, executed_at: null, refused_at: null, provider_refund_id: null,
};

test('console financeiro pagina, filtra e conclui ações pelos drawers', async ({ page }) => {
    let reconciliationStatus = 'aberta';
    let refundStatus = 'solicitado';
    const listRequests = [];

    await page.route('**/api/backoffice/payments?**', async (route) => {
        const url = new URL(route.request().url());
        listRequests.push({ path: 'payments', q: url.searchParams.get('q'), date_from: url.searchParams.get('date_from') });
        const q = (url.searchParams.get('q') || '').toLocaleLowerCase('pt-BR');
        const status = url.searchParams.get('status') || '';
        const filtered = payments.filter((item) => (!q || `${item.company_name} ${item.id} ${item.provider_payment_id}`.toLocaleLowerCase('pt-BR').includes(q)) && (!status || item.status === status));
        const pageNumber = Number(url.searchParams.get('page') || 1);
        const perPage = Number(url.searchParams.get('per_page') || 15);
        const start = (pageNumber - 1) * perPage;
        await route.fulfill({ contentType: 'application/json', json: { data: filtered.slice(start, start + perPage), meta: { total: filtered.length, current_page: pageNumber, per_page: perPage, last_page: Math.max(1, Math.ceil(filtered.length / perPage)) } } });
    });
    await page.route('**/api/backoffice/payments/PAG_VISUAL_01', (route) => route.fulfill({ contentType: 'application/json', json: payments[0] }));
    await page.route('**/api/backoffice/reconciliation?**', (route) => route.fulfill({ contentType: 'application/json', json: { data: [{ ...alert, status: reconciliationStatus }], meta: { total: 1, current_page: 1, per_page: 15, last_page: 1 } } }));
    await page.route('**/api/backoffice/reconciliation/RCA_VISUAL_01', async (route) => {
        if (route.request().method() === 'PATCH') {
            const body = route.request().postDataJSON();
            expect(body.action).toBe('revisar');
            expect(body.reason).toBe('Status remoto confirmado.');
            reconciliationStatus = 'em_revisao';
            return route.fulfill({ contentType: 'application/json', json: { ...alert, status: reconciliationStatus } });
        }
        return route.fulfill({ contentType: 'application/json', json: { ...alert, status: reconciliationStatus } });
    });
    await page.route('**/api/backoffice/refunds?**', (route) => route.fulfill({ contentType: 'application/json', json: { data: [{ ...refund, status: refundStatus }], meta: { total: 1, current_page: 1, per_page: 15, last_page: 1 } } }));
    await page.route('**/api/backoffice/refunds/RFD_VISUAL_01', async (route) => {
        if (route.request().method() === 'PATCH') {
            const body = route.request().postDataJSON();
            expect(body.action).toBe('aprovar');
            expect(body.reason).toBe('Documentação conferida.');
            refundStatus = 'aprovado';
            return route.fulfill({ contentType: 'application/json', json: { ...refund, status: refundStatus, approved_at: '2026-09-23T10:00:00.000Z' } });
        }
        return route.fulfill({ contentType: 'application/json', json: { ...refund, status: refundStatus } });
    });

    await page.setViewportSize({ width: 1440, height: 900 });
    await grantBillingPermissions(page);
    await page.goto('/backoffice/pagamentos');
    await expect(page.locator('#page-content')).toHaveAttribute('data-backoffice-page', 'pagamentos');
    await expect(page.locator('#billing-payments-list tr')).toHaveCount(15);
    await expect(page.locator('#billing-payments-page-summary')).toContainText('página 1 de 2');
    await page.getByRole('button', { name: 'Próxima página' }).click();
    await expect(page.locator('#billing-payments-list tr')).toHaveCount(2);
    await page.getByRole('button', { name: 'Página anterior' }).click();
    await expect(page.locator('#billing-payments-list tr')).toHaveCount(15);

    await page.locator('#billing-payments-query').fill('Empresa Alpha');
    await page.locator('#billing-payments-status').selectOption('aprovado');
    await page.locator('#billing-payments-from').fill('2026-09-01');
    await page.locator('#billing-payments-to').fill('2026-09-30');
    await page.getByRole('button', { name: 'Filtrar' }).first().click();
    await expect(page.locator('#billing-payments-list tr')).toHaveCount(1);
    expect(listRequests.at(-1)).toEqual({ path: 'payments', q: 'Empresa Alpha', date_from: '2026-09-01' });
    const paymentDetail = page.getByRole('button', { name: 'Detalhes' });
    await paymentDetail.click();
    await expect(page.locator('#billing-drawer')).toBeVisible();
    await expect(page.locator('#billing-detail-sections')).toContainText('mp-payment-1');
    await expect(page.locator('#billing-refund-request-toggle')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('#billing-drawer')).toBeHidden();
    await expect(paymentDetail).toBeFocused();

    await page.locator('#billing-reconciliation-tab').click();
    await expect(page.locator('#billing-reconciliation-list tr')).toHaveCount(1);
    await page.locator('#billing-reconciliation-list [data-billing-detail]').click();
    await expect(page.locator('#billing-detail-sections')).toContainText('Empresa Alpha Financeira');
    await page.getByRole('button', { name: 'Marcar em revisão' }).click();
    await page.locator('#billing-action-reason').fill('Status remoto confirmado.');
    await page.getByRole('button', { name: 'Confirmar', exact: true }).click();
    await expect(page.locator('#billing-message')).toContainText('concluída');
    await expect(page.locator('#billing-detail-sections')).toContainText('Em revisão');
    await page.locator('#billing-drawer-close').click();

    await page.locator('#billing-refunds-tab').click();
    await expect(page.locator('#billing-refunds-list tr')).toHaveCount(1);
    await page.locator('#billing-refunds-list [data-billing-detail]').click();
    await expect(page.locator('#billing-detail-sections')).toContainText('Falha técnica confirmada.');
    await page.getByRole('button', { name: 'Aprovar reembolso' }).click();
    await page.locator('#billing-action-reason').fill('Documentação conferida.');
    await page.getByRole('button', { name: 'Confirmar', exact: true }).click();
    await expect(page.locator('#billing-detail-sections')).toContainText('Aprovado');
});

test('abas financeiras adaptam a tabela e o drawer ao celular', async ({ page }) => {
    await page.route('**/api/backoffice/payments?**', (route) => route.fulfill({ contentType: 'application/json', json: { data: [payments[0]], meta: { total: 1, current_page: 1, per_page: 15, last_page: 1 } } }));
    await page.route('**/api/backoffice/payments/PAG_VISUAL_01', (route) => route.fulfill({ contentType: 'application/json', json: payments[0] }));
    await grantBillingPermissions(page);
    for (const viewport of [{ width: 768, height: 1024 }, { width: 375, height: 812 }, { width: 320, height: 700 }]) {
        await page.setViewportSize(viewport);
        await page.goto('/backoffice/pagamentos');
        await expect(page.locator('#billing-payments-list tr')).toHaveCount(1);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
        await page.locator('#billing-payments-list [data-billing-detail]').click();
        await expect(page.locator('#billing-drawer')).toBeVisible();
        expect(await page.locator('#billing-drawer').evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true);
        await page.locator('#billing-drawer-close').click();
    }
});

test('solicitação de reembolso abre a aba de acompanhamento após confirmação', async ({ page }) => {
    let created = false;
    await page.route('**/api/backoffice/payments?**', (route) => route.fulfill({ contentType: 'application/json', json: { data: [payments[0]], meta: { total: 1, current_page: 1, per_page: 15, last_page: 1 } } }));
    await page.route('**/api/backoffice/payments/PAG_VISUAL_01', (route) => route.fulfill({ contentType: 'application/json', json: payments[0] }));
    await page.route('**/api/backoffice/refunds*', async (route) => {
        if (route.request().method() === 'POST') {
            const body = route.request().postDataJSON();
            expect(body).toEqual({ payment_id: 'PAG_VISUAL_01', amount: 20, allowed_case: 'erro_tecnico', reason: 'Erro técnico confirmado.' });
            created = true;
            return route.fulfill({ status: 201, contentType: 'application/json', json: { ...refund, status: 'solicitado' } });
        }
        const data = created ? [{ ...refund, status: 'solicitado' }] : [];
        return route.fulfill({ contentType: 'application/json', json: { data, meta: { total: data.length, current_page: 1, per_page: 15, last_page: 1 } } });
    });

    await grantBillingPermissions(page);
    await page.goto('/backoffice/pagamentos');
    await page.locator('#billing-payments-list [data-billing-detail]').click();
    await page.getByRole('button', { name: 'Solicitar reembolso' }).click();
    await page.locator('#billing-refund-amount').fill('20');
    await page.locator('#billing-refund-case').selectOption('erro_tecnico');
    await page.locator('#billing-refund-reason').fill('Erro técnico confirmado.');
    await page.getByRole('button', { name: 'Enviar solicitação' }).click();
    await expect(page.locator('#billing-refunds-tab')).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('#billing-refunds-list tr')).toHaveCount(1);
    await expect(page.locator('#billing-message')).toContainText('registrada para aprovação');
});

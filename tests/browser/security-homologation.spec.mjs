import { expect, test } from '@playwright/test';

async function csrfToken(page) {
    const response = await page.goto('/api/csrf-token');
    expect(response?.ok()).toBe(true);
    return response.json();
}

async function postJson(page, path, body, token) {
    return page.evaluate(async ({ path, body, token }) => {
        const headers = { Accept: 'application/json', 'Content-Type': 'application/json' };
        if (token !== undefined) headers['X-CSRF-TOKEN'] = token;
        const response = await fetch(path, {
            method: 'POST',
            credentials: 'same-origin',
            headers,
            body: JSON.stringify(body),
        });
        return { status: response.status, body: await response.json().catch(() => null) };
    }, { path, body, token });
}

test('CSRF bloqueia mutações sem token e sessão emite cookies seguros', async ({ page, context }) => {
    const { token } = await csrfToken(page);
    expect(token).toBeTruthy();

    const cookies = await context.cookies();
    const session = cookies.find((cookie) => cookie.name === '__Host-fokuscloud-session');
    const xsrf = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN');
    expect(session).toBeDefined();
    expect(session.secure).toBe(true);
    expect(session.httpOnly).toBe(true);
    expect(session.sameSite).toBe('Lax');
    expect(session.path).toBe('/');
    expect(session.domain).toBe('localhost');
    expect(xsrf).toBeDefined();
    expect(xsrf.secure).toBe(true);
    expect(xsrf.httpOnly).toBe(false);
    expect(xsrf.sameSite).toBe('Lax');

    const payload = { cpf: '12345678901' };
    expect((await postJson(page, '/api/auth/request-password-reset', payload)).status).toBe(419);
    expect((await postJson(page, '/api/auth/request-password-reset', payload, 'invalid-token')).status).toBe(419);
    expect((await postJson(page, '/api/auth/request-password-reset', payload, token)).status).toBe(200);
});

test('limites de autenticação e webhook retornam 429 sem chamar o gateway', async ({ page }) => {
    const { token } = await csrfToken(page);
    const loginPayload = { cpf: '52998224725', password: 'senha-sintetica-invalida' };
    const loginStatuses = [];
    for (let attempt = 0; attempt < 6; attempt += 1) {
        loginStatuses.push((await postJson(page, '/api/auth/login', loginPayload, token)).status);
    }
    expect(loginStatuses.slice(0, 5)).toEqual([422, 422, 422, 422, 422]);
    expect(loginStatuses[5]).toBe(429);

    const webhookStatuses = [];
    for (let attempt = 0; attempt < 3; attempt += 1) {
        const response = await page.request.post(`/api/webhooks/mercado-pago?data.id=security-canary-${attempt}`, {
            headers: { Accept: 'application/json', 'x-request-id': `security-canary-${attempt}` },
            data: { type: 'security_homologation' },
        });
        webhookStatuses.push(response.status());
    }
    expect(webhookStatuses).toEqual([401, 401, 429]);
});

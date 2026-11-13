const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { chromium } = require(process.env.FOKUS_PLAYWRIGHT || 'playwright');

const root = path.resolve(__dirname, '../..');
const pub = path.join(root, 'public');
const normal = [
  { id: 'COM-exemplo', name: 'Empresa Alfa', role: 'admin' },
  { id: 'COM-segunda', name: 'Empresa Beta', role: 'gestor' },
];
const injected = [{ id: 'COM-" onfocus="void 0', name: 'Alfa <img onerror="void 0">', role: 'gestor <svg onload="void 0">' }];
const base = fs.readFileSync(path.join(root, 'tests/fixtures/portal/companies-before.html'), 'utf8')
  .replace('<link rel="stylesheet" href="/assets/css/reset.css"><link rel="stylesheet" href="/assets/css/style.css">', '');
const fixed = fs.readFileSync(path.join(pub, 'portal/companies.html'), 'utf8');

async function run() {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.FOKUS_CHROME,
    args: ['--disable-background-networking', '--disable-component-update', '--host-resolver-rules=MAP * ~NOTFOUND'] });
  const reports = [];
  try {
    async function open(html, companies, rejectSelection = false, rejectLoad = false, realShell = false) {
      const context = await browser.newContext({ viewport: { width: 390, height: 800 } });
      const calls = [];
      const inlineScript = html.match(/<script>([\s\S]*?)<\/script>/i);
      assert.ok(inlineScript, 'Script da página não encontrado');
      const scriptHash = crypto.createHash('sha256').update(inlineScript[1]).digest('base64');
      await context.exposeBinding('__recordRequest', (_, value) => { calls.push(value); });
      await context.addInitScript(({ companies, rejectSelection, rejectLoad }) => {
        window.FokusApi = { request: async (url, options) => {
          if (url === '/auth/me') {
            if (rejectLoad) throw new Error('Falha de sessão fictícia');
            return { companies, user: { name: 'Usuário <img onerror="void 0">' }, active_company_id: companies[0]?.id,
              support_mode: { active: true, company: 'Suporte <svg onload="void 0">', subscription_status: 'ativa', reason: 'Motivo <img onerror="void 0">' } };
          }
          if (url === '/auth/select-company') {
            await window.__recordRequest({ url, options });
            if (rejectSelection) throw new Error('Seleção recusada');
            return {};
          }
          throw new Error('Requisição fictícia não prevista');
        } };
      }, { companies, rejectSelection, rejectLoad });
      await context.route('**/*', async (route) => {
        const url = new URL(route.request().url());
        if (url.hostname !== 'fixture.invalid') return route.abort();
        if (url.pathname === '/portal/empresas') return route.fulfill({ status: 200, contentType: 'text/html', body: html,
          headers: { 'Content-Security-Policy': `script-src 'self' 'sha256-${scriptHash}'; img-src 'none'` } });
        if (url.pathname === '/portal') return route.fulfill({ status: 200, contentType: 'text/html', body: '<title>Portal fictício</title>' });
        if (url.pathname.endsWith('.js') && !(realShell && url.pathname === '/assets/js/portal-shell.js')) return route.fulfill({ status: 200, contentType: 'text/javascript', body: '' });
        const target = path.resolve(pub, decodeURIComponent(url.pathname).replace(/^\/+/, ''));
        if (!target.startsWith(pub + path.sep) || !fs.existsSync(target) || !fs.statSync(target).isFile()) return route.fulfill({ status: 404, body: '' });
        return route.fulfill({ status: 200, contentType: url.pathname.endsWith('.css') ? 'text/css' : url.pathname.endsWith('.js') ? 'text/javascript' : 'application/octet-stream', body: fs.readFileSync(target) });
      });
      const page = await context.newPage();
      await page.goto('https://fixture.invalid/portal/empresas');
      if (companies.length && !rejectLoad) await page.locator('#companies button').first().waitFor();
      return { context, page, calls };
    }

    const first = await open(base, normal);
    const second = await open(fixed, normal);
    try {
      const metrics = page => page.locator('#companies button').first().evaluate(el => {
        const css = getComputedStyle(el), r = el.getBoundingClientRect();
        return { text: el.textContent, width: r.width, height: r.height, color: css.color, background: css.backgroundColor, border: css.border, font: css.font };
      });
      assert.deepEqual(await metrics(first.page), await metrics(second.page));
      assert.equal(await second.page.locator('#companies button').count(), 2);
      assert.equal(await second.page.locator('#companies button').nth(1).textContent(), 'Empresa Beta — gestor');
      await first.page.evaluate(() => document.fonts.ready);
      await second.page.evaluate(() => document.fonts.ready);
      assert.deepEqual(await first.page.screenshot({ animations: 'disabled', caret: 'hide' }), await second.page.screenshot({ animations: 'disabled', caret: 'hide' }));
      reports.push('texto normal e captura visual iguais');
    } finally { await first.context.close(); await second.context.close(); }

    const safe = await open(fixed, injected, true);
    try {
      const button = safe.page.locator('#companies button');
      assert.equal(await button.textContent(), `${injected[0].name} — ${injected[0].role}`);
      assert.equal(await button.getAttribute('data-id'), injected[0].id);
      assert.equal(await safe.page.locator('#companies img, #companies svg').count(), 0);
      assert.equal(await button.getAttribute('onfocus'), null);
      await safe.page.keyboard.press('Tab');
      assert.equal(await button.evaluate(el => document.activeElement === el), true);
      await safe.page.keyboard.press('Enter');
      await safe.page.getByText('Seleção recusada').waitFor();
      assert.equal(safe.calls.length, 1);
      assert.deepEqual(safe.calls[0], { url: '/auth/select-company', options: { method: 'POST', body: { company_id: injected[0].id } } });
      reports.push('markup literal, id intacto, teclado e rejeição simulada');
    } finally { await safe.context.close(); }

    const success = await open(fixed, normal);
    try {
      await Promise.all([success.page.waitForURL('https://fixture.invalid/portal'), success.page.locator('#companies button').nth(1).click()]);
      assert.deepEqual(success.calls[0].options.body, { company_id: normal[1].id });
      reports.push('seleção e redirecionamento simulados');
    } finally { await success.context.close(); }

    for (const [companies, rejectLoad, message] of [
      [[], false, 'Nenhuma empresa ativa está disponível para esta conta.'],
      [normal, true, 'Falha de sessão fictícia'],
    ]) {
      const failure = await open(fixed, companies, false, rejectLoad);
      try {
        await failure.page.getByText(message).waitFor();
        assert.equal(await failure.page.locator('#companies button').count(), 0);
        assert.equal(await failure.page.locator('#login-again').isVisible(), true);
      } finally { await failure.context.close(); }
    }
    reports.push('lista vazia e erro de carregamento simulados');

    const shell = await open(fixed, injected, false, false, true);
    try {
      await shell.page.locator('#portal-context strong').waitFor();
      await shell.page.getByText(injected[0].name, { exact: true }).waitFor();
      assert.equal(await shell.page.locator('#portal-context strong').textContent(), injected[0].name);
      assert.equal(await shell.page.locator('#portal-user-name').textContent(), 'Usuário <img onerror="void 0">');
      assert.match(await shell.page.locator('[data-support-context]').textContent(), /Suporte <svg onload="void 0">/);
      assert.equal(await shell.page.locator('#portal-context img, #portal-context svg, #portal-user-name img, [data-support-context] img, [data-support-context] svg').count(), 0);
      reports.push('shell real mantém dados de /auth/me como texto');
    } finally { await shell.context.close(); }
    console.log(JSON.stringify({ result: 'passed', checks: reports }));
  } finally { await browser.close(); }
}
run().catch(error => { console.error(error); process.exitCode = 1; });

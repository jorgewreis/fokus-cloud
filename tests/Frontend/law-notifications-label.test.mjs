import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { JSDOM } = process.env.REI54_JSDOM_MODULE
  ? await import(pathToFileURL(process.env.REI54_JSDOM_MODULE).href) : require('jsdom');
const root = new URL('../../', import.meta.url);
const html = await readFile(new URL('resources/views/portal/fokus-law.blade.php', root), 'utf8');
const shell = await readFile(new URL('public/portal/assets/fokus-law-shell.js', root), 'utf8');
const bootstrap = shell.lastIndexOf("FokusApi.request('/auth/me')");
assert.notEqual(bootstrap, -1, 'shell bootstrap must be identifiable');

for (const [name, response] of [
  ['vazia', { unread_count: 0, notifications: [] }],
  ['com notificações', { unread_count: 1, notifications: [{ id: 'example', title: 'Aviso', message: 'Mensagem', created_at: null }] }],
]) {
  test(`painel de notificações ${name} mantém vínculo com seu título após carregar`, async () => {
    const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'http://localhost/' });
    const { window } = dom;
    try {
      let calls = 0;
      window.FokusApi = { request: async url => {
        calls++;
        assert.equal(url, '/law/notifications');
        return response;
      } };
      // Expose the real loader while avoiding the unrelated application bootstrap.
      window.eval(`${shell.slice(0, bootstrap)} window.testLoadLawNotifications = loadLawNotifications; })();`);
      const panel = window.document.querySelector('#notifications-panel');
      const initialTitle = panel.querySelector('#notifications-title');
      await window.testLoadLawNotifications(window.document.querySelector('#notifications-button'), panel);
      assert.equal(calls, 1);
      const titleId = panel.getAttribute('aria-labelledby');
      assert.ok(titleId);
      const title = window.document.getElementById(titleId);
      assert.ok(title && panel.contains(title));
      assert.notEqual(title, initialTitle, 'successful load replaces the initial title');
      assert.equal(title.textContent, 'Notificações');
      assert.equal(panel.querySelectorAll(`#${titleId}`).length, 1);
      assert.equal(panel.querySelectorAll('.law-notification-item').length, response.notifications.length);
    } finally { window.close(); }
  });
}

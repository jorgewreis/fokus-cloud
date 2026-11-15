import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { JSDOM } = process.env.REI54_JSDOM_MODULE ? await import(pathToFileURL(process.env.REI54_JSDOM_MODULE).href) : require('jsdom');
const html = await readFile(new URL('../../public/styles/examples/law-notifications.html', import.meta.url), 'utf8');

function open() {
  const dom = new JSDOM(html, { runScripts:'outside-only', url:'http://localhost/' });
  dom.window.fetch = () => { throw new Error('Example must not request data'); };
  dom.window.XMLHttpRequest = class { constructor() { throw new Error('Example must not request data'); } };
  dom.window.eval(dom.window.document.querySelector('script:not([src])').textContent);
  return dom;
}

test('painel fictício alterna lista, nome, contagem e vínculo do título', () => {
  const dom = open();
  try {
    const d = dom.window.document, trigger = d.getElementById('notifications-button'), panel = d.getElementById('notifications-panel');
    assert.equal(panel.hidden, true);
    assert.equal(trigger.getAttribute('aria-label'), 'Notificações, 2 não lidas');
    trigger.click();
    assert.equal(panel.hidden, false);
    assert.equal(trigger.getAttribute('aria-expanded'), 'true');
    assert.equal(d.getElementById(panel.getAttribute('aria-labelledby')).textContent, 'Notificações');
    assert.equal(panel.querySelectorAll('.example-item[data-unread="true"]').length, 2);
    d.getElementById('show-empty').click();
    assert.equal(trigger.getAttribute('aria-label'), 'Notificações');
    assert.equal(d.getElementById('notifications-count').hidden, true);
    assert.equal(panel.hidden, true);
    trigger.click();
    assert.equal(panel.hidden, false);
    assert.equal(trigger.getAttribute('aria-expanded'), 'true');
    assert.match(panel.textContent, /Nenhuma notificação por enquanto/);
    d.getElementById('show-items').click();
    assert.equal(panel.hidden, true);
    trigger.click();
    assert.equal(panel.hidden, false);
    assert.equal(panel.querySelectorAll('.example-item').length, 2);
  } finally { dom.window.close(); }
});

test('ativação local e Escape fecham o painel e devolvem foco sem requisição', () => {
  const dom = open();
  try {
    const d = dom.window.document, trigger = d.getElementById('notifications-button'), panel = d.getElementById('notifications-panel');
    trigger.click(); panel.querySelector('.example-item').click();
    assert.equal(panel.hidden, true);
    assert.equal(d.activeElement, trigger);
    assert.match(d.getElementById('notification-status').textContent, /Nenhuma informação foi enviada/);
    trigger.click();
    d.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key:'Escape', bubbles:true }));
    assert.equal(panel.hidden, true);
    assert.equal(trigger.getAttribute('aria-expanded'), 'false');
    assert.equal(d.activeElement, trigger);
  } finally { dom.window.close(); }
});

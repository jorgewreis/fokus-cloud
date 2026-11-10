const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('@playwright/test');
(async () => {
  const source = fs.readFileSync(path.join(__dirname, '../../public/portal/assets/fokus-law-processes.js'), 'utf8');
  const editor = source.slice(source.indexOf('    async function edit(event)'), source.indexOf('    async function detail('));
  const ui = fs.readFileSync(path.join(__dirname, '../../public/portal/assets/law-record-ui.js'), 'utf8');
  const binder = ui.slice(ui.indexOf('  function bindForm('), ui.indexOf('// The approved Contacts'));
  const browser = await chromium.launch({ executablePath: process.env.LAW_CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', headless: true, chromiumSandbox: true });
  try {
    const page = await browser.newPage(); await page.route('**/*', route => route.abort());
    await page.setContent('<main id="root"></main>');
    const results = await page.evaluate(async ({ editor, binder }) => {
      const outcomes = [];
      for (const mode of ['save', 'cancel', 'superseded', 'repeat']) {
        let callback, payload, calls = 0, reloads = 0, resolve;
        const pending = new Promise(done => { resolve = done; });
        let viewToken = 1, currentId = 'case-1';
        const root = document.getElementById('root'); root.replaceChildren();
        const $ = (tag, cls = '', text = '') => { const n = document.createElement(tag); n.className = cls; n.textContent = text; return n; };
        const element = $('section'); const modal = { element, body: $('div'), footer: $('footer'), close() { element.remove(); } }; element.append(modal.body, modal.footer); root.append(element);
        const controls = {};
        const select = (options, value) => { const node = $('select'); options.forEach(([key,label]) => node.append(new Option(label,key))); if(value !== undefined) node.value = value; return node; };
        const input = (value = '', type = 'text') => { const node = $('input'); node.type = type; node.value = value || ''; return node; };
        const section = () => { const node = $('section'); node.body = $('div'); node.append(node.body); return node; };
        const field = (label, node, name) => { controls[name] = node; node.name = name; const wrapper = $('label', '', label); wrapper.append(node); return wrapper; };
        const button = (label, fn) => { const node = $('button', '', label); node.type = 'button'; if(fn) node.addEventListener('click', fn); return node; };
        const c = { id: currentId, version: 3, law_unit_id: 'unit-1', operational_status: 'active', operational_priority: 'normal', confidentiality_level: 'public', internal_confidentiality_level: 'secret', source_confidentiality_level: 'public', subjects: [], official_fields: [] };
        const detailData = { case: c, can_manage_access: true };
        const getRefs = async () => ({ statuses: [{code:'active',label:'Active'}], members: [], procedural_priorities: [], confidentiality_levels: [{code:'public',label:'Public'},{code:'cnj_5',label:'Restricted'}] });
        const active = () => true, dialog = () => modal, text = value => value, keyValues = () => $('div'), message = () => $('p'), confidentialityAlert = '', needsConfidentialityAlert = () => false;
        const metadataEditor = () => ({ classSelect: select([]), classOther: $('div'), subjectsSelect: select([]), customHost: $('div'), values: () => ({ subjects: [], klass: null }) });
        const apiPath = id => '/' + id;
        const request = async (_url, options) => { calls++; payload = options.body; await pending; };
        const detail = async () => { reloads++; };
        const actualBind = eval('(' + binder.trim() + ')');
        const bindForm = (form, save, error, fn) => {
          let lastOperation;
          actualBind(form, save, error, () => { lastOperation = fn(); return lastOperation; });
          callback = () => { form.dispatchEvent(new Event('submit', { cancelable: true })); return lastOperation; };
        };
        const edit = eval('(' + editor.trim() + ')'); await edit();
        if(controls.internal_confidentiality_level.value !== 'secret') throw new Error('Legacy internal level lost in real select');
        const operation = callback();
        if(mode === 'repeat') callback();
        if(mode === 'cancel') modal.close();
        if(mode === 'superseded') viewToken++;
        resolve(); await operation;
        if(payload.internal_confidentiality_level !== 'secret' || 'source_confidentiality_level' in payload || 'confidentiality_level' in payload) throw new Error('Incorrect confidentiality payload');
        if(calls !== 1 || reloads !== (['save','repeat'].includes(mode) ? 1 : 0)) throw new Error('Late/repeated request changed navigation');
        outcomes.push({mode, calls, reloads});
      }
      return outcomes;
    }, { editor, binder });
    assert.equal(results.length, 4); console.log(JSON.stringify({ browser: await browser.version(), results }));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });

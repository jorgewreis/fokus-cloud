import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile, mkdir } from 'node:fs/promises';
import { extname, resolve } from 'node:path';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = resolve(import.meta.dirname, '../../public');
const server = createServer(async (req, res) => {
 try {
  const path = decodeURIComponent(new URL(req.url, 'http://localhost').pathname);
  const file = resolve(root, '.' + path + (path.endsWith('/') ? 'index.html' : ''));
  if (!file.startsWith(root + '/')) throw new Error('Invalid path');
  const data = await readFile(file);
  res.setHeader('Content-Type', ({ '.js':'application/javascript', '.html':'text/html', '.css':'text/css', '.json':'application/json' })[extname(file)] || 'application/octet-stream'); res.end(data);
 } catch { res.statusCode = 404; res.end('Not found'); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const base = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({ ...(process.env.CHROMIUM_PATH ? {executablePath:process.env.CHROMIUM_PATH} : {}), headless:true, args:['--no-sandbox'] });
try {
 const page = await browser.newPage({ viewport:{width:1280,height:900} });
 const errors = []; page.on('pageerror', error => errors.push(error.message));
 await page.addInitScript(() => { window.copiedText = ''; Object.defineProperty(navigator, 'clipboard', {value:{writeText:async value => {window.copiedText=value;}}}); });
 await page.goto(base + '/styles/next/');
 await page.waitForFunction(() => document.querySelectorAll('#catalog-list a').length === 56);
 await page.locator('#catalog-search').fill('zzzz-no-results');
 assert.equal(await page.locator('#catalog-list a').count(), 0);
 await page.locator('#catalog-reset').click(); assert.equal(await page.locator('#catalog-list a').count(), 56);
 await page.locator('#catalog-group').selectOption({label:'Formulários'});
 assert.equal(await page.locator('#catalog-list a').count(),13);
 await page.locator('#catalog-reset').click();
 let previewCount = 0;
 const links = await page.locator('#catalog-list a').evaluateAll(nodes => nodes.map(a => a.hash));
 for (const hash of links) {
  await page.evaluate(hash => {location.hash=hash;}, hash);
  await page.waitForFunction(hash => document.querySelector('#catalog-list a[aria-current]')?.hash === hash, hash);
  assert.equal(await page.locator('#catalog-detail h2').count(),1);
  for (const frame of await page.locator('#catalog-detail iframe').all()) {
   previewCount++;
   assert.equal(await frame.getAttribute('sandbox'),'allow-scripts');
   const child = await (await frame.elementHandle()).contentFrame();
   await child.waitForLoadState('load');
   assert.equal(await child.evaluate(() => typeof FokusStyles),'object');
  }
 }
 assert.equal(previewCount,137);
 await page.evaluate(hash => {location.hash=hash;}, links[0]);
 await page.waitForFunction(hash => document.querySelector('#catalog-list a[aria-current]')?.hash === hash, links[0]);
 const first = page.locator('#catalog-detail details').first(); await first.locator('summary').click();
 const original = await first.locator('code').textContent(); await first.locator('button').click();
 assert.equal(await page.evaluate(() => window.copiedText),original);
 await page.evaluate(hash => {location.hash=hash;}, links[1]); await page.waitForTimeout(50); await page.goBack();
 await page.waitForFunction(hash => location.hash === hash && document.querySelector('#catalog-list a[aria-current]')?.hash === hash, links[0]);
 await page.setViewportSize({width:390,height:844});
 assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
 await mkdir(resolve(import.meta.dirname, 'test-results'),{recursive:true});
 await page.screenshot({path:process.env.STYLES_SCREENSHOT || resolve(import.meta.dirname, 'test-results/styles-next-mobile.png'),fullPage:true});
 await page.goto(base + '/styles/next/#file-upload-advanced');
 const upload = page.locator('#catalog-detail iframe').first().contentFrame();
 await upload.locator('input[type=file]').setInputFiles({name:'pixel.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII=','base64')});
 await upload.locator('.fs-file-upload-list img').waitFor();
 const thumbnail = await upload.locator('.fs-file-upload-list img').elementHandle();
 await thumbnail.waitForElementState('visible');
 assert.ok(await thumbnail.evaluate(img=>img.src.startsWith('blob:')));
 await page.waitForTimeout(100);
 assert.ok(await thumbnail.evaluate(img=>img.complete && img.naturalWidth > 0));
 const siblingUpload = page.locator('#catalog-detail iframe').nth(1).contentFrame();
 await siblingUpload.locator('input[type=file]').setInputFiles({name:'sibling.txt',mimeType:'text/plain',buffer:Buffer.from('local fixture')});
 await page.locator('#catalog-detail').getByRole('button',{name:/^Reiniciar exemplo:/}).first().click();
 await upload.locator('input[type=file]').waitFor();
 assert.equal(await upload.locator('input[type=file]').evaluate(input=>input.files.length),0);
 assert.equal(await siblingUpload.locator('input[type=file]').evaluate(input=>input.files.length),1);
 await page.goto(base + '/styles/next/#invalid'); assert.match(await page.locator('#catalog-detail').textContent(), /não encontrado/);
 await page.route('**/styles/next/catalog.json', route => route.fulfill({status:503,body:''}));
 await page.reload(); await page.locator('#catalog-retry').waitFor({state:'visible'});
 await page.unroute('**/styles/next/catalog.json'); await page.locator('#catalog-retry').click();
 await page.waitForFunction(() => document.querySelectorAll('#catalog-list a').length === 56);
 assert.deepEqual(errors,[]);
 console.log('PASS: 56 contracts, filters, faithful copy, history, sandbox, mobile width, unknown ID and fetch retry.');
} finally { await browser.close(); server.close(); }

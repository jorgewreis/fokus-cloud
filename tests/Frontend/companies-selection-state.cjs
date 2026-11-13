'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.FOKUS_PLAYWRIGHT || 'playwright');
const root = path.resolve(__dirname, '../..');
const html = fs.readFileSync(path.join(root, 'public/portal/companies.html'), 'utf8');
const companies = [{id:'COM-alfa',name:'Empresa Alfa',role:'admin'},{id:'COM-beta',name:'Empresa Beta',role:'gestor'}];
async function run() {
 const browser = await chromium.launch({headless:true,executablePath:process.env.FOKUS_CHROME,args:['--disable-background-networking','--disable-component-update','--host-resolver-rules=MAP * ~NOTFOUND']});
 let external = 0;
 async function open(mode) {
  const context = await browser.newContext({serviceWorkers:'block'});
  const observed=[];await context.exposeBinding('__recordFixturePost',(_,id)=>{observed.push(id)});
  await context.addInitScript(({companies,mode})=>{
   window.fixtureCalls=[]; window.fixtureNavigation=[];
   window.FokusApi={request:(url,options)=>{
    if(url==='/auth/me') return Promise.resolve({companies});
    if(url==='/auth/select-company'){window.fixtureCalls.push({url,options});window.__recordFixturePost(options.body.company_id);return new Promise((resolve,reject)=>{window.fixtureResolve=resolve;window.fixtureReject=reject})}
    return Promise.reject(new Error('Rota não prevista'));
   }};
   window.fixtureNavigate=(url)=>{window.fixtureNavigation.push(url);if(mode==='throws')throw new Error('Falha de navegação fictícia')};
  },{companies,mode});
  const served = mode==='real' ? html : html.replace("location.assign('/portal')", "window.fixtureNavigate('/portal')");
  if(mode!=='real')assert.notEqual(served,html,'chamada de navegação não encontrada');
  await context.route('**/*',route=>{
   const u=new URL(route.request().url());
   if(u.hostname!=='fixture.invalid'){external++;return route.abort()}
   if(u.pathname==='/portal/empresas')return route.fulfill({status:200,contentType:'text/html; charset=utf-8',body:served});
   if(['/assets/css/portal.css','/assets/css/shared/fokus.css'].includes(u.pathname))return route.fulfill({status:200,contentType:'text/css',body:fs.readFileSync(path.join(root,'public',u.pathname.slice(1)))});
   if(u.pathname==='/portal')return route.fulfill({status:200,contentType:'text/html; charset=utf-8',body:'<title>Portal fictício</title>'});
   return route.fulfill({status:200,contentType:u.pathname.endsWith('.css')?'text/css':'text/javascript',body:''});
  });
  const page=await context.newPage();await page.goto('https://fixture.invalid/portal/empresas');await page.locator('#companies button').first().waitFor();return {context,page,observed};
 }
 try {
  for(const mode of ['interrupted','throws']) {
   const {context,page}=await open(mode);
   try {
    const a=page.locator('#companies button').nth(0),b=page.locator('#companies button').nth(1),link=page.locator('#continue-portal');assert.equal(await link.isHidden(),true);
    await a.focus();assert.equal(await a.evaluate(el=>document.activeElement===el),true);await page.keyboard.press('Enter');
    assert.equal(await page.locator('#companies').getAttribute('aria-busy'),'true');assert.equal(await a.isDisabled(),true);assert.equal(await b.isDisabled(),true);
    await page.evaluate(()=>{const buttons=document.querySelectorAll('#companies button');buttons[0].dispatchEvent(new MouseEvent('click',{bubbles:true}));buttons[1].dispatchEvent(new MouseEvent('click',{bubbles:true}))});
    assert.equal(await page.evaluate(()=>window.fixtureCalls.length),1);
    assert.deepEqual(await page.evaluate(()=>window.fixtureCalls[0]),{url:'/auth/select-company',options:{method:'POST',body:{company_id:'COM-alfa'}}});
    await page.evaluate(()=>window.fixtureReject(new Error('Erro fictício')));await page.getByText('Erro fictício').waitFor();
    assert.equal(await page.locator('#companies').getAttribute('aria-busy'),'false');assert.equal(await a.isEnabled(),true);assert.equal(await b.isEnabled(),true);assert.equal(await a.evaluate(el=>document.activeElement===el),true);
    await b.focus();await page.keyboard.press('Space');assert.equal(await page.locator('#feedback').textContent(),'Selecionando empresa...');assert.equal(await page.evaluate(()=>window.fixtureCalls.length),2);
    assert.equal(await page.evaluate(()=>window.fixtureCalls[1].options.body.company_id),'COM-beta');await page.evaluate(()=>window.fixtureResolve({}));
    await link.waitFor({state:'visible'});assert.equal(await link.getAttribute('href'),'/portal');assert.equal(await page.locator('#companies').getAttribute('aria-busy'),'false');assert.equal(await a.isDisabled(),true);assert.equal(await b.isDisabled(),true);assert.equal(await link.evaluate(el=>document.activeElement===el),true);
    assert.equal(await page.locator('#feedback').textContent(),'Empresa selecionada. Continue para o portal.');assert.deepEqual(await page.evaluate(()=>window.fixtureNavigation),['/portal']);
    await page.evaluate(()=>document.querySelector('#companies button').dispatchEvent(new MouseEvent('click',{bubbles:true})));assert.equal(await page.evaluate(()=>window.fixtureCalls.length),2);
    await Promise.all([page.waitForURL('https://fixture.invalid/portal'),page.keyboard.press('Enter')]);assert.equal(await page.evaluate(()=>document.title),'Portal fictício');
    console.log(`${mode}: pending=1; retry=1; success=1; busy=ok; focus=ok; recovery_link=ok; extra_post=0`);
   } finally {await context.close()}
  }
  for(const outcome of ['reject','resolve']) {
   const {context,page}=await open('interrupted');
   try {
    await page.locator('#companies button').first().click();
    await page.evaluate(()=>{const button=document.createElement('button');button.id='other-focus';button.textContent='Outro controle';document.body.append(button);button.focus()});
    if(outcome==='reject')await page.evaluate(()=>window.fixtureReject(null));else await page.evaluate(()=>window.fixtureResolve({}));
    if(outcome==='reject')await page.getByText('Não foi possível selecionar a empresa.').waitFor();else await page.locator('#continue-portal').waitFor({state:'visible'});
    assert.equal(await page.evaluate(()=>document.activeElement.id),'other-focus');
    console.log('focus_moved_'+outcome+'=preserved');
   }finally{await context.close()}
  }
  {
   const {context,page,observed}=await open('real');
   try {
    const button=page.locator('#companies button').first(),link=page.locator('#continue-portal');
    await button.click();await page.waitForFunction(()=>window.fixtureCalls.length===1);
    await page.evaluate(()=>{window.fixtureBeforeUnload=(e)=>{e.preventDefault();e.returnValue='';};window.addEventListener('beforeunload',window.fixtureBeforeUnload)});
    const dialogHandled=page.waitForEvent('dialog').then(async dialog=>{assert.equal(dialog.type(),'beforeunload');await dialog.dismiss()});
    await page.evaluate(()=>window.fixtureResolve({}));await link.waitFor({state:'visible'});
    await page.waitForFunction(()=>document.activeElement?.id==='continue-portal');
    await dialogHandled;assert.equal(page.url(),'https://fixture.invalid/portal/empresas');
    assert.equal(await button.isDisabled(),true);assert.equal(await page.locator('#companies').getAttribute('aria-busy'),'false');
    assert.deepEqual(observed,['COM-alfa']);await page.evaluate(()=>window.removeEventListener('beforeunload',window.fixtureBeforeUnload));
    await Promise.all([page.waitForURL('https://fixture.invalid/portal'),page.keyboard.press('Enter')]);assert.deepEqual(observed,['COM-alfa']);
    console.log('real_navigation_cancelled=ok; recovery_link=ok; extra_post=0');
   }finally{await context.close()}
  }
  assert.equal(external,0);console.log('external_requests=0');
 } finally {await browser.close()}
}
run().catch(error=>{console.error(error);process.exitCode=1});

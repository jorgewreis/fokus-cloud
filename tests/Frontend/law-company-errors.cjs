'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {chromium}=require(process.env.FOKUS_PLAYWRIGHT||'playwright');
const root=path.resolve(__dirname,'../..');
const source=fs.readFileSync(path.join(root,'public/portal/assets/fokus-law-shell.js'),'utf8');
const css=fs.readFileSync(path.join(root,'public/portal/assets/fokus-law-shell.css'),'utf8');
const start=source.indexOf("  async function renderCompany(successMessage = '') {");
const end=source.indexOf('  function renderPreferences() {',start);
assert(start>=0&&end>start,'Bloco renderCompany não encontrado');
const block=source.slice(start,end);
async function run(){
 const browser=await chromium.launch({headless:true,executablePath:process.env.FOKUS_CHROME,args:['--disable-background-networking','--disable-component-update','--host-resolver-rules=MAP * ~NOTFOUND']});let blocked=0;
 try{
  async function scenario({existing=false,foreignInvalid=false,hidden=false,collision=false,finish=false}={}){
   const ctx=await browser.newContext({serviceWorkers:'block'});await ctx.route('**/*',r=>{blocked++;return r.abort()});
   try{
    const page=await ctx.newPage();await page.setContent('<!doctype html><html><body><main id="content-region"></main></body></html>');await page.addStyleTag({content:css});
    await page.addScriptTag({content:`const contentRegion=document.querySelector('#content-region');const context={company:{},support_mode:false};function element(tag,cls,text){const e=document.createElement(tag);if(cls)e.className=cls;if(text!==undefined)e.textContent=text;return e}function icon(){return document.createElement('span')}function formatDocument(){return 'documento ficticio'}function companyStatus(s){return s}let calls=0;window.FokusApi={request:(url,options)=>{if(url==='/law/company-profile'&&!options)return Promise.resolve({company:{legal_name:'Empresa Ficticia',contact_email:'contato@example.test',document_type:'cnpj',document_number:'00000000000000',status:'ativa',version:1},audit:[]});if(url==='/law/company-profile'&&options?.method==='PATCH'){calls++;if(calls===3)return new Promise(resolve=>{window.fixtureResolve=resolve});let e=new Error('Validacao ficticia');e.status=422;e.errors=calls===1?{legal_name:['Erro nome 1'],contact_email:['Erro email 1']}:{contact_email:['Erro email 2'],campo_inexistente:['Ignorar']};return Promise.reject(e)}return Promise.reject(new Error('rota nao prevista'))}};${block}window.fixtureRun=renderCompany;window.fixtureCount=()=>calls;`});
    await page.evaluate(()=>window.fixtureRun());await page.getByRole('button',{name:'Editar dados'}).click();
    if(existing)await page.evaluate(({foreignInvalid,collision})=>{const input=document.querySelector('[name=legal_name]'),hint=document.createElement('small'),old=input.parentElement.querySelector('.law-company-field-error');hint.id='external-help';hint.textContent='Ajuda externa';input.parentElement.append(hint);old.id=collision?'law-company-error-legal_name':'external-error';old.textContent='Texto preexistente';input.setAttribute('aria-describedby','external-help '+old.id);if(foreignInvalid)input.setAttribute('aria-invalid','true')},{foreignInvalid,collision});
    if(hidden)await page.locator('[name=legal_name]').locator('..').locator('.law-company-field-error').evaluate(el=>{el.hidden=true});
    const save=page.getByRole('button',{name:'Salvar alterações'}),legal=page.locator('[name=legal_name]'),email=page.locator('[name=contact_email]');
    await save.click();await page.getByText('Erro email 1',{exact:true}).waitFor();const own=page.locator(collision?'#law-company-error-legal_name-1':'#law-company-error-legal_name');assert.equal(await own.isVisible(),true);assert.equal(await legal.getAttribute('aria-invalid'),'true');
    if(existing){const oldId=collision?'law-company-error-legal_name':'external-error';assert.equal(await page.locator('#'+oldId).textContent(),'Texto preexistente');assert.match(await legal.getAttribute('aria-describedby'),/external-help/)}
    if(!existing&&!hidden){const layout=await legal.locator('..').evaluate(el=>({children:el.children.length,display:getComputedStyle(el).display,gap:getComputedStyle(el).gap}));assert.deepEqual(layout,{children:3,display:'grid',gap:'6px'})}
    await save.click();await page.getByText('Erro email 2',{exact:true}).waitFor();assert.equal(await page.evaluate(()=>window.fixtureCount()),2);assert.equal(await legal.getAttribute('aria-invalid'),foreignInvalid?'true':null);assert.equal(await own.count(),0);assert.equal(await email.getAttribute('aria-invalid'),'true');
    if(existing){const oldId=collision?'law-company-error-legal_name':'external-error';assert.equal(await legal.getAttribute('aria-describedby'),'external-help '+oldId);assert.equal(await page.locator('#'+oldId).textContent(),'Texto preexistente')}else assert.equal(await legal.getAttribute('aria-describedby'),null);
    if(finish){await save.click();await page.waitForFunction(()=>window.fixtureCount()===3);assert.equal(await save.isDisabled(),true);assert.equal(await page.getByText('Erro email 2',{exact:true}).count(),0);assert.equal(await email.getAttribute('aria-invalid'),null);await page.evaluate(()=>window.fixtureResolve({company:{legal_name:'Empresa Ficticia',display_name:'Empresa Ficticia'},message:'Dados salvos'}));await page.getByText('Dados salvos',{exact:true}).waitFor();assert.equal(await page.locator('.law-company-form').count(),0)}
   }finally{await ctx.close()}
  }
  await scenario({finish:true});await scenario({existing:true});await scenario({existing:true,foreignInvalid:true});await scenario({existing:true,collision:true});await scenario({hidden:true});assert.equal(blocked,0);
  console.log('422_successive=ok; retry_success=ok; prior_descriptions=ok; foreign_invalid=ok; id_collision=ok; hidden=ok; css_grid=ok; external_requests=0');
 }finally{await browser.close()}
}
run().catch(e=>{console.error(e);process.exitCode=1});

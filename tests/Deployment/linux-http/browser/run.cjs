// Real browser + authenticated HTTP. No fulfilled routes, API mocks or injected application code.
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const root = path.resolve(__dirname, '../../../..');
const requireCandidate = createRequire(path.join(root, 'package.json'));
const { chromium } = requireCandidate('playwright');
const [base, fixturePath, outputArg] = process.argv.slice(2);
if (!base || !/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw Error('Explicit loopback HTTP origin required');
const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));
const output = outputArg || path.join(process.env.RELEASE_REPORT_DIR, 'browser');
const sourceIdentity = JSON.parse(fs.readFileSync(path.join(process.env.RELEASE_REPORT_DIR,'source-before.json'),'utf8')).source_sha256;
if (!/^[a-f0-9]{64}$/.test(sourceIdentity)) throw Error('Browser run must use final coordinator source identity');
fs.mkdirSync(output, {recursive:true});
const tests = [], responses = [], documents = [], consoleErrors = [], blocked = [];
let assertionCount = 0;
function assert(value, message) { if (!value) throw Error(message); assertionCount++; }
async function scenario(name, fn) {
  const before = assertionCount, started = new Date().toISOString();
  try { await fn(); tests.push({name,status:'passed',assertions:assertionCount-before,started,ended:new Date().toISOString()}); console.log('PASS',name); }
  catch(error) { tests.push({name,status:'failed',assertions:assertionCount-before,started,ended:new Date().toISOString(),error:error.message}); console.log('FAIL',name,error.message); }
}
async function api(page, route, method='GET', body) {
  return page.evaluate(async ({route,method,body}) => {
    const headers = {Accept:'application/json'};
    if (method !== 'GET') {
      const csrf = await fetch('/api/csrf-token',{credentials:'same-origin'});
      headers['X-CSRF-TOKEN'] = (await csrf.json()).token;
    }
    if (body !== undefined) headers['Content-Type']='application/json';
    const response = await fetch('/api'+route,{method,headers,credentials:'same-origin',body:body===undefined?undefined:JSON.stringify(body)});
    const text = await response.text();let data;try{data=JSON.parse(text);}catch{data={non_json:true};}
    return {status:response.status,data};
  },{route,method,body});
}
async function login(page, user) {
  await page.goto(base+'/portal/fokus-law',{waitUntil:'domcontentloaded'});
  const result = await api(page,'/auth/law-login','POST',{email:user.email,password:user.password||fixture.password,company_id:fixture.company,profile:user.profile||'admin'});
  assert(result.status===200,'Real law-login should return 200, got '+result.status);
  await page.goto(base+'/portal/fokus-law',{waitUntil:'domcontentloaded'});
  const context=await api(page,'/law/shell-context');
  assert(context.status===200,'Authenticated shell context HTTP200');
  assert(context.data.active_company_id===fixture.company,'Session selects synthetic company');
  assert(context.data.active_unit_id===fixture.unit,'Session resolves synthetic active unit');
  return context.data;
}
async function go(page, module) {
  const response=await page.goto(base+'/portal/fokus-law'+(module==='contatos'?'':'/'+module),{waitUntil:'domcontentloaded'});
  if(response.status()!==200)throw Error('Document '+module+' returned '+response.status()+': '+(await page.locator('body').innerText()).slice(0,250));
  await page.locator('#law-workspace').waitFor({state:'attached',timeout:3000});
  if(module==='contatos'){
    const group=page.locator('.law-module-group').filter({has:page.getByRole('button',{name:'Contatos',exact:true})});
    const toggle=group.getByRole('button',{name:'Contatos',exact:true});await toggle.waitFor();
    if(await toggle.getAttribute('aria-expanded')!=='true')await toggle.click();
    await group.getByRole('button',{name:'Cadastro e consulta',exact:true}).click();
  }
}
const modal = page => page.locator('.fs-modal[aria-hidden="false"]:visible, [role="dialog"]:visible, dialog[open]').last();
const responseTo = (page,suffix,method) => page.waitForResponse(response => response.url().includes('/api'+suffix) && (!method || response.request().method()===method),{timeout:12000});
(async()=>{
  const browser=await chromium.launch({headless:true,chromiumSandbox:true});
  try {
    const context=await browser.newContext({viewport:{width:1440,height:1080},acceptDownloads:true});
    await context.route('**/*',route=>{
      const url=new URL(route.request().url());
      if (url.origin===base || ['data:','blob:'].includes(url.protocol)) return route.continue();
      blocked.push({url:url.origin+url.pathname,type:route.request().resourceType()});return route.abort('blockedbyclient');
    });
    const page=await context.newPage();page.setDefaultTimeout(12000);
    page.on('pageerror',error=>consoleErrors.push(error.message));
    page.on('response',response=>{
      const u=new URL(response.url());
      if(response.request().isNavigationRequest())documents.push({time:new Date().toISOString(),path:u.pathname,status:response.status()});
      if(u.pathname.startsWith('/api/'))responses.push({time:new Date().toISOString(),method:response.request().method(),path:u.pathname,status:response.status()});
    });
    let shell,taskId,contactName='QA Browser Contact '+Date.now();
    await scenario('real-login-session-and-csrf',async()=>{
      shell=await login(page,fixture);
      const rejected=await page.evaluate(async()=>{const r=await fetch('/api/law/contacts',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({display_name:'No csrf synthetic',legal_nature:'pf'})});return r.status;});
      assert(rejected===419,'Missing CSRF must return419; got '+rejected);
      assert(shell.features.tasks_enabled===true,'Enabled only in synthetic HTTP environment');
    });
    await scenario('contacts-cancel-create-repeat-save',async()=>{
      await go(page,'contatos');await page.getByRole('button',{name:'Novo contato',exact:true}).click();
      await modal(page).locator('[name="display_name"]').fill('Cancelled browser contact');
      const before=responses.filter(r=>r.path==='/api/law/contacts'&&r.method==='POST').length;
      await modal(page).getByRole('button',{name:'Cancelar',exact:true}).click();
      assert(await page.locator('[name="display_name"]:visible').count()===0,'Cancel closes editor');
      assert(responses.filter(r=>r.path==='/api/law/contacts'&&r.method==='POST').length===before,'Cancel sends no write');
      await page.getByRole('button',{name:'Novo contato',exact:true}).click();
      await modal(page).locator('[name="display_name"]').fill(contactName);
      const response=responseTo(page,'/law/contacts','POST');
      await modal(page).getByRole('button',{name:'Cadastrar contato',exact:true}).evaluate(el=>{el.click();el.click();});
      assert((await response).status()===201,'Real create contact returns201');
      await page.waitForTimeout(300);
      assert(responses.filter(r=>r.path==='/api/law/contacts'&&r.method==='POST').length===before+1,'Repeated click submits once');
      const records=await api(page,'/law/contacts?q='+encodeURIComponent(contactName));
      assert(records.status===200,'Saved contact is readable through real API');
      const rows=records.data.data||records.data.contacts||[];
      assert(JSON.stringify(records.data).toLowerCase().includes(contactName.toLowerCase()),'Saved contact persisted');
    });
    await scenario('contacts-import-rejects-alphabetic-cpf-without-persistence',async()=>{
      await go(page,'contatos');await page.getByRole('button',{name:'Importar contatos',exact:true}).click();
      const invalidName='QA Invalid CPF Browser '+Date.now();
      const pending=responseTo(page,'/law/contacts/import/preview','POST');
      await modal(page).getByLabel('Arquivo de contatos').setInputFiles({name:'invalid-cpf.csv',mimeType:'text/csv',buffer:Buffer.from('display_name,legal_nature,legal_name,cpf,cnpj,email,phone\n'+invalidName+',pf,,AAAAAAAAA00,,,\n')});
      const response=await pending;assert(response.status()===200,'Invalid CPF preview returns validation rows');
      const preview=await response.json();assert(preview.rows?.length===1&&preview.rows[0].status==='invalid','Alphabetic CPF AAAAAAAAA00 is invalid, never ready');
      assert(await modal(page).locator('.law-contact-import-preview input[type="checkbox"]').isDisabled(),'Invalid CPF row cannot be selected');
      assert(await modal(page).getByRole('button',{name:'Confirmar importação',exact:true}).isDisabled(),'Invalid-only preview cannot be committed through UI');
      const forced=await api(page,'/law/contacts/import/commit','POST',{batch_id:preview.batch_id,rows:[0],confirmed:true});
      assert(forced.status===409,'Server rejects selection of a non-ready invalid row with409');
      const listed=await api(page,'/law/contacts?q='+encodeURIComponent(invalidName));
      assert(listed.status===200&&!JSON.stringify(listed.data).includes(invalidName),'Invalid alphabetic CPF did not persist');
      await modal(page).getByRole('button',{name:'Cancelar',exact:true}).click();
    });
    await scenario('contacts-real-export-import-preview-confirm',async()=>{
      await go(page,'contatos');
      for(const format of ['CSV','PDF']) {
        const download=page.waitForEvent('download');const response=responseTo(page,'/law/contacts/export','GET');
        await page.getByRole('button',{name:'Exportar '+format,exact:true}).click();
        assert((await response).status()===200,'Export '+format+' real HTTP200');
        const file=await download;assert(file.suggestedFilename().toLowerCase().endsWith('.'+format.toLowerCase()),'Export download filename '+format);
        // Generated synthetic export stays local, not delivered as a user attachment.
        await file.saveAs(path.join(output,'synthetic-export.'+format.toLowerCase()));
      }
      await page.getByRole('button',{name:'Importar contatos',exact:true}).click();
      const preview=responseTo(page,'/law/contacts/import/preview','POST');
      await modal(page).getByLabel('Arquivo de contatos').setInputFiles({name:'synthetic.csv',mimeType:'text/csv',buffer:Buffer.from('display_name,legal_nature,legal_name,cpf,cnpj,email,phone\nQA Import Browser '+Date.now()+',pf,,,,,\n')});
      assert((await preview).status()===200,'Real import preview HTTP200');
      await modal(page).getByLabel('Revisei as linhas selecionadas e confirmo a importação.').check();
      const commit=responseTo(page,'/law/contacts/import/commit','POST');
      await modal(page).getByRole('button',{name:'Confirmar importação',exact:true}).click();
      assert((await commit).status()===201,'Real import commit HTTP201');
    });
    await scenario('portfolio-real-preview-confirm-preserves-existing',async()=>{
      await go(page,'processos');await page.getByRole('button',{name:'Importar carteira',exact:true}).click();
      const sequence=String(Date.now()%10000000).padStart(7,'0'),rest='20268050103';
      const digits=String(98-Number(BigInt(sequence+rest+'00')%97n)).padStart(2,'0');
      const numbers=[sequence+digits+rest];assert(numbers[0].length===20,'Fresh synthetic CNJ has20 digits and modulo97 check digits');
      await modal(page).locator('textarea').fill(numbers.join('\n'));
      let response=responseTo(page,'/law/cases/portfolio/import','POST');
      await modal(page).getByRole('button',{name:'Conferir carteira',exact:true}).click();
      const preview=await response;assert(preview.status()===200,'Portfolio preview HTTP200');
      const data=await preview.json();assert(data.rows?.length>0,'Portfolio preview has actual validation rows');
      response=responseTo(page,'/law/cases/portfolio/import','POST');
      await modal(page).getByRole('button',{name:'Confirmar importação',exact:true}).click();
      const confirmed=await response;assert([200,201].includes(confirmed.status()),'Portfolio confirm accepted');
      const result=await confirmed.json();assert(result.created_count>=1,'Valid synthetic process persisted');
      const createdId=result.created_ids[0],before=await api(page,'/law/cases/'+createdId);
      const repeated=await api(page,'/law/cases/portfolio/import','POST',{numbers,law_unit_id:fixture.unit,internal_confidentiality_level:'public',confirmed:true});
      assert(repeated.status===200&&repeated.data.created_count===0&&repeated.data.summary.existing===1,'Repeating portfolio import recognizes existing case without inserting');
      const after=await api(page,'/law/cases/'+createdId);assert(JSON.stringify(before.data.case)===JSON.stringify(after.data.case),'Existing case fields remain unchanged by repeated import');
      await modal(page).getByRole('button',{name:'Concluir',exact:true}).click();
    });
    await scenario('tasks-create-cancel-repeated-submit-edit-back-forward',async()=>{
      await go(page,'tarefas');await page.getByRole('button',{name:'Nova tarefa',exact:true}).click();
      await modal(page).getByLabel('Título',{exact:true}).fill('Cancelled task');await modal(page).getByRole('button',{name:'Fechar',exact:true}).click();
      assert(await page.locator('dialog[open]').count()===0,'Task cancel closes dialog');
      await page.getByRole('button',{name:'Nova tarefa',exact:true}).click();
      const title='QA Browser Task '+Date.now();await modal(page).getByLabel('Título',{exact:true}).fill(title);await modal(page).getByLabel('Descrição',{exact:true}).fill('Synthetic authenticated browser task');
      const save=modal(page).getByRole('button',{name:'Salvar',exact:true});await save.waitFor();
      await page.waitForFunction(()=>[...document.querySelectorAll('dialog[open] button')].some(x=>x.textContent==='Salvar'&&!x.disabled));
      const response=responseTo(page,'/law/tasks','POST');const before=responses.filter(r=>r.path==='/api/law/tasks'&&r.method==='POST').length;
      await save.evaluate(el=>{el.click();el.click();});const created=await response;assert(created.status()===201,'Task real create HTTP201');
      const createdData=await created.json();taskId=createdData.task?.id||createdData.id;
      await page.waitForTimeout(250);assert(responses.filter(r=>r.path==='/api/law/tasks'&&r.method==='POST').length===before+1,'Task repeated submit writes once');
      await page.locator('tr').filter({hasText:title}).getByRole('button',{name:'Abrir',exact:true}).click();await page.getByRole('button',{name:'Editar',exact:true}).click();
      await modal(page).getByLabel('Título',{exact:true}).fill(title+' updated');
      const patched=responseTo(page,'/law/tasks/'+taskId,'PATCH');await modal(page).getByRole('button',{name:'Salvar',exact:true}).click();assert((await patched).status()===200,'Task real PATCH success');
      await page.getByRole('button',{name:'Voltar',exact:true}).click();await page.goBack();await page.getByRole('button',{name:'Editar',exact:true}).waitFor();assert(page.url().includes('law_task='),'Back restores detail URL');
      await page.goForward();await page.getByRole('button',{name:'Nova tarefa',exact:true}).waitFor();assert(!page.url().includes('law_task='),'Forward restores list URL');
      const detail=await api(page,'/law/tasks/'+taskId);assert(detail.data.events.filter(x=>x.event_type==='created').length===1,'Persisted task has one created event');
    });
    await scenario('publication-triage-real-ui-cas',async()=>{
      const id=fixture.publication_case||fixture.cases?.public||fixture.public_case;
      assert(Boolean(id),'Fixture public case present');
      const initial=await api(page,'/law/cases/'+id);assert(initial.status===200,'Public case HTTP200');
      const review=initial.data.workflow?.publications?.[0];assert(Boolean(review),'Publication projected in actual response');
      await go(page,'processos');
      // Navigate via genuine shell UI record URL, then use its rendered triage form.
      const number=initial.data.case?.case_number_formatted;
      assert(Boolean(number),'Actual case response contains formatted CNJ');
      await page.getByRole('button',{name:'Consultar processo '+number,exact:true}).click();
      await page.getByRole('button',{name:'Registrar triagem',exact:true}).first().click();
      await modal(page).locator('select').selectOption('reviewed');await modal(page).locator('textarea').fill('Synthetic reviewed in real browser');
      const response=responseTo(page,'/law/cases/'+id+'/publications/','PATCH');await modal(page).getByRole('button',{name:'Salvar triagem',exact:true}).click();assert((await response).status()===200,'Triage real PATCH200');
      const stale=await api(page,'/law/cases/'+id+'/publications/'+encodeURIComponent(review.key),'PATCH',{status:'pending',version:review.version,next_action:'stale synthetic write'});
      assert(stale.status===409,'Old version rejected409 after real UI update');
    });
    await scenario('confidentiality-authorized-vs-no-grant',async()=>{
      const restrictedId=fixture.restricted_case||fixture.cases?.restricted;
      assert(Boolean(restrictedId),'Restricted fixture case present');
      const authorized=await api(page,'/law/cases/'+restrictedId);assert(authorized.status===200,'Authorized synthetic user sees restricted case');
      const outsider=fixture.restricted_user;assert(Boolean(outsider?.email),'No-grant user fixture present');
      const restrictedContext=await browser.newContext({viewport:{width:1280,height:900}});
      await restrictedContext.route('**/*',route=>new URL(route.request().url()).origin===base?route.continue():route.abort());
      const other=await restrictedContext.newPage();await login(other,outsider);
      const forbidden=await api(other,'/law/cases/'+restrictedId);assert([403,404].includes(forbidden.status),'No-grant user cannot read restricted case');
      const listed=await api(other,'/law/cases');assert(listed.status===200,'No-grant user can list allowed cases');assert(!JSON.stringify(listed.data).includes(restrictedId),'Restricted case absent from list');
      await restrictedContext.close();
    });
    assert(consoleErrors.length===0,'No uncaught browser errors: '+consoleErrors.join('; '));
    await context.close();
  } catch(error) {tests.push({name:'browser-top-level',status:'failed',error:error.message,assertions:0});console.error(error.message);}
  finally {
    await browser.close();
    const result={candidate_source_sha256:sourceIdentity,browser:'Lockfile Playwright Chromium on Linux, chromiumSandbox:true',versions:{chrome:browser.version(),node:process.version,playwright:requireCandidate('playwright/package.json').version},origin:base,scope:'Real HTTP, actual API responses, session authentication and middleware; external requests blocked; screenshots not included',limits:'Loopback HTTP with SESSION_SECURE_COOKIE=false tests local sessions and CSRF, not HTTPS transport or production Secure cookie behavior. No TLS warning was bypassed.',scenario_count:tests.length,assertions:assertionCount,tests,responses,documents,consoleErrors,blockedExternal:blocked,publication_authorized:false};
    fs.writeFileSync(path.join(output,'browser-result.json'),JSON.stringify(result,null,2));
    console.log(JSON.stringify({passed:tests.filter(t=>t.status==='passed').length,failed:tests.filter(t=>t.status==='failed').length,assertions:assertionCount}));
    process.exitCode=tests.some(t=>t.status==='failed')?1:0;
  }
})();

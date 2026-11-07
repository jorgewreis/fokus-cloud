// Real browser/server negative check for the default disabled task feature.
const fs=require('node:fs'),path=require('node:path'),{createRequire}=require('node:module');
const root=path.resolve(__dirname,'../../../..'),req=createRequire(path.join(root,'package.json'));
const {chromium}=req('playwright');const [origin,fixturePath,output]=process.argv.slice(2);
if(!/^http:\/\/127\.0\.0\.1:\d+$/.test(origin))throw Error('Loopback origin required');
const fixture=JSON.parse(fs.readFileSync(fixturePath,'utf8'));let assertions=0;
function check(ok,label){if(!ok)throw Error(label);assertions++;console.log('PASS '+label);}
(async()=>{const browser=await chromium.launch({headless:true,chromiumSandbox:true});
const result={status:'failed',assertions:0,publication_authorized:false};try{
 const context=await browser.newContext();await context.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.abort());
 const page=await context.newPage();await page.goto(origin+'/portal/fokus-law',{waitUntil:'domcontentloaded'});
 const data=await page.evaluate(async f=>{
  const token=(await (await fetch('/api/csrf-token')).json()).token;
  const login=await fetch('/api/auth/law-login',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify({email:f.email,password:f.password,company_id:f.company,profile:'admin'})});
  const shell=await fetch('/api/law/shell-context',{headers:{Accept:'application/json'}});
  const tasks=await fetch('/api/law/tasks',{headers:{Accept:'application/json'}});
  return {login:login.status,shell:shell.status,context:await shell.json(),tasks:tasks.status};
 },fixture);
 check(data.login===200,'real login accepted with task feature false');check(data.shell===200,'real shell context available');
 check(data.context.features.tasks_enabled===false,'shell reports task feature disabled');check(data.tasks===404,'task API returns404 while disabled');
 result.status='passed';await context.close();
}catch(error){result.error=error.message;console.error(error.message);process.exitCode=1;}finally{result.assertions=assertions;fs.writeFileSync(path.join(output,'feature-disabled-result.json'),JSON.stringify(result,null,2));await browser.close();}})();

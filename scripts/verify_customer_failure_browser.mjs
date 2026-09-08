import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import {chromium} from 'playwright';

// Isolated browser + synthetic API. No real session, database, OTA or notification is used.
const root=path.resolve('public'), out=path.resolve('output/customer-failures-20260908/evidence');
fs.mkdirSync(out,{recursive:true});
const hotel={id:7,tenant_id:71,name:'验收用虚拟门店',status:1};
const user={id:9001,tenant_id:71,username:'fixture-user',realname:'合成验收账号',role_id:8,hotel_id:7,default_hotel_id:7,
  context:{tenantId:71,hotelId:7,userId:9001,tokenStatus:'valid',permissionStatus:'allowed',fetchPermissionStatus:'allowed'},
  is_super_admin:false,permissions:{all:true,can_view_online_data:true,can_fetch_online_data:true},capabilities:['all'],
  permitted_hotels:[hotel],modules:{online_data:true,collection_health:true,ai:false,operation:false},
  protected_access:[{allowed:false,reason:'module_not_entitled',module:'ai_decision',paths:[{path:'api/ota-standard/revenue-metrics',methods:[]},{path:'api/agent/ota-diagnosis',methods:[]},{path:'api/revenue-ai/overview',methods:[]}]},
    {allowed:false,reason:'module_not_entitled',module:'operation_decision',paths:[{path:'api/operation',methods:[]}]}]};
const requests=[], errors=[];
let statusMode='success';
const server=http.createServer((req,res)=>{
  const url=new URL(req.url,'http://localhost');
  if(url.pathname.startsWith('/api/')) {
    requests.push({method:req.method,path:url.pathname,query:Object.fromEntries(url.searchParams)});
    if(url.pathname==='/api/online-data/auto-fetch-status' && statusMode==='failure') {
      res.writeHead(503,{'content-type':'application/json'});
      res.end(JSON.stringify({code:503,message:'合成状态服务暂不可用',data:{}}));return;
    }
    let data={};
    if(url.pathname==='/api/auth/login') data={token:'synthetic-test-session-only',user,context:{tenant_id:71,hotelId:7,tokenStatus:'valid',permissionStatus:'allowed'}};
    else if(url.pathname==='/api/auth/info') data=user;
    else if(['/api/hotels','/api/hotels/list','/api/hotels/all'].includes(url.pathname)) data=[hotel];
    else if(url.pathname.includes('config-list')||url.pathname==='/api/platform-data-sources') data=[];
    else if(url.pathname==='/api/online-data/auto-fetch-status') data={detail_loaded:false,running_task:null,last_result:{status:'success',success:true,message:'合成旧任务已完成'}};
    else if(url.pathname==='/api/compass') data={layout:{},metrics:{},weather:[],todos:[],alerts:[],holidays:[]};
    res.writeHead(200,{'content-type':'application/json'});res.end(JSON.stringify({code:200,data,message:'synthetic fixture'}));return;
  }
  const file=path.resolve(root,`.${decodeURIComponent(url.pathname==='/'?'/index.html':url.pathname)}`);
  if(!file.startsWith(root+path.sep)||!fs.existsSync(file)||!fs.statSync(file).isFile()){res.writeHead(404);res.end();return;}
  const mime={'.html':'text/html','.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml','.woff2':'font/woff2'}[path.extname(file)]||'application/octet-stream';
  res.writeHead(200,{'content-type':mime});fs.createReadStream(file).pipe(res);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const origin=`http://127.0.0.1:${server.address().port}`;
const browser=await chromium.launch({headless:true});
const context=await browser.newContext({viewport:{width:1440,height:1050}});
await context.addInitScript(()=>{
  let runtime;
  Object.defineProperty(window,'Vue',{configurable:true,get:()=>runtime,set:value=>{
    runtime=value;
    const create=value.createApp;
    value.createApp=(...args)=>{const app=create(...args),mount=app.mount.bind(app);
      app.mount=(...mountArgs)=>{const proxy=mount(...mountArgs);window.__fixtureApp=proxy;return proxy;};return app;};
  }});
});
await context.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.abort());
const page=await context.newPage();
page.on('pageerror',error=>errors.push(error.message));
page.on('console',message=>{if(message.type()==='error' && message.text().includes('[SUXIOS] Vue runtime error')) errors.push(message.text());});
try {
  await page.goto(origin,{waitUntil:'domcontentloaded'});
  await page.locator('#login-username').fill('fixture-user');
  await page.locator('#login-password').fill('synthetic-fixture-input');
  await page.getByTestId('login-submit').click();
  await page.waitForFunction(()=>window.__fixtureApp?.isLoggedIn===true && document.documentElement.dataset.suxiAuthenticatedInteractiveReady==='1');
  await page.waitForFunction(()=>window.__fixtureApp?.authContext?.permissionStatus==='allowed');
  const smoke = await page.evaluate(async()=>{
    const app=window.__fixtureApp;
    await app.openOnlinePlatformAutoTab();
    return {loggedIn:app.isLoggedIn,blockedPage:window.SUXI_SYSTEM_STATIC.isPageModuleAvailable('operation-optimizer',app.user)};
  });
  assert.equal(smoke.loggedIn,true);assert.equal(smoke.blockedPage,false);
  await page.waitForFunction(()=>document.documentElement.dataset.suxiRenderPhase==='full',{},{timeout:30000});
  await page.getByText('配置：自动采集',{exact:true}).click();
  await page.getByTestId('cookie-api-temporary-fetch').waitFor();
  await page.waitForLoadState('networkidle');
  statusMode='failure';
  await page.evaluate(()=>window.__fixtureApp.loadAutoFetchStatus({detail:false,force:true}));
  await page.getByTestId('auto-fetch-status-unavailable').waitFor();
  assert.match(await page.getByTestId('auto-fetch-status-unavailable').getAttribute('class'),/text-amber-700/);
  await page.screenshot({path:path.join(out,'browser-status-unavailable.png'),fullPage:false});
  const beforeRetry=requests.filter(r=>r.path==='/api/online-data/auto-fetch-status').length;
  statusMode='success';
  await page.getByRole('button',{name:'重新读取采集状态',exact:true}).click();
  await page.getByTestId('auto-fetch-status-unavailable').waitFor({state:'hidden'});
  assert.ok(requests.filter(r=>r.path==='/api/online-data/auto-fetch-status').length>beforeRetry);
  const deniedCalls=requests.filter(r=>['/api/ota-standard/revenue-metrics','/api/agent/ota-diagnosis','/api/revenue-ai/overview'].includes(r.path)||r.path.startsWith('/api/operation/'));
  assert.equal(deniedCalls.length,0);
  assert.equal(errors.length,0,errors.join('\n'));
  const result={status:'PASS',evidence_tier:'isolated Chromium, real built frontend, synthetic API only',checks:['login and full render','unopened module navigation blocked','protected network requests suppressed','HTTP 503 displays unknown collection state','manual retry requests fresh status and recovers the page'],page_errors:errors,denied_api_requests:deniedCalls.length,request_count:requests.length};
  fs.writeFileSync(path.join(out,'browser-verification.json'),JSON.stringify(result,null,2));console.log(JSON.stringify(result));
} catch(error) {
  await page.screenshot({path:path.join(out,'browser-failure.png')}).catch(()=>{});
  console.log(JSON.stringify({page_errors:errors,visible_text:(await page.locator('body').innerText()).slice(0,1800),request_paths:requests.map(r=>r.path)}));
  throw error;
} finally {await browser.close();await new Promise(resolve=>server.close(resolve));}

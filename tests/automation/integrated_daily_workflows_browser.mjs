import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import http from 'node:http';
import { spawn } from 'node:child_process';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { createHash } from 'node:crypto';
import { chromium } from 'playwright-core';

// Complete target application; no injected page proxy or replaced component handlers.
// Own fixture adapter invokes target services using independent SQLite. Other APIs fail.
const args = process.argv.slice(2);
const option = name => { const index=args.indexOf(name); return index<0?null:args[index+1]; };
const ownerRoot=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const root=path.resolve(option('--root') || process.cwd());
const output=path.resolve(option('--output') || path.join(ownerRoot,'output/long-goal/integrated'));
assert.ok(output.startsWith(ownerRoot+path.sep), 'Evidence must be written under the verifier owner tree');
assert.ok(fs.existsSync(path.join(root,'public/index.html')), 'Explicit target root must contain the built app');
fs.mkdirSync(output,{recursive:true});
const state=fs.mkdtempSync(path.join(os.tmpdir(),'l10-integrated-synthetic-'));
const phpBinary=option('--php') || 'C:/xampp/php/php.exe';
const phpFixture=path.join(ownerRoot,'tests/fixtures/integrated_workflows_api.php');
const measuredPaths=['public/app-main.min.js','public/app-render.min.js','resources/frontend/app-template.html','app/service/PreciseQueryRouterService.php','app/service/PreciseQueryPeriodService.php','public/components/system/operating-intelligence-components.js','app/service/OperationTaskWorkflowService.php','public/components/operations/task-workflow-panel.js'];
measuredPaths.push('public/index.html', 'public/app-startup-helpers.min.js', 'public/home-static.js', 'public/style.min.css', 'public/style-startup.min.css', 'public/compass-authority-polish.css');
const measure=()=>Object.fromEntries(measuredPaths.map(name=>[name,createHash('sha256').update(fs.readFileSync(path.join(root,name))).digest('hex')]));
const manifest={root,output,state,synthetic:true,mode:'complete_app_with_explicit_synthetic_http_and_target_services',
  assets:measure()};
fs.writeFileSync(path.join(output,'candidate.json'),JSON.stringify(manifest,null,2));
const apiCalls=[], checks=[], pageErrors=[], unsupported=[], blockedExternal=[], browserEvents=[];
const startedAt=Date.now();
const control={mode:'normal',lostPost:false,lostGet:false,lostTask:false,factsGate:null};
const deferred=()=>{let resolve;const promise=new Promise(done=>{resolve=done;});return {promise,resolve};};
const bounded=async(promise,label)=>{
  let timer;
  try{return await Promise.race([promise,new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error(`Timed out waiting for ${label}`)),20000);})]);}
  finally{clearTimeout(timer);}
};
function php(request) {
  return new Promise((resolve,reject)=>{
    const process=spawn(phpBinary,[phpFixture,root,state],{cwd:root,windowsHide:true,env:{...globalThis.process.env,
      SUXIOS_CACHE_PATH:path.join(state,'bootstrap-cache'),SUXIOS_LOCAL_LOCK_PATH:path.join(state,'bootstrap-locks')},stdio:['pipe','pipe','pipe']});
    let output='',error='';process.stdout.on('data',data=>output+=data);process.stderr.on('data',data=>error+=data);
    process.on('error',reject);process.on('exit',code=>{try{if(code)throw new Error(error||output);resolve(JSON.parse(output));}catch(e){reject(new Error(`synthetic PHP adapter: ${e.message}`));}});
    process.stdin.end(JSON.stringify(request));
  });
}
const initialized=await php({path:'/__fixture/init'});
assert.equal(initialized.code,200,initialized.message);
for(const name of ['query_class','workflow_class']) assert.ok(path.resolve(initialized.data[name]).startsWith(root+path.sep));
const {recoveryTask}=await import(pathToFileURL(path.join(root,'tests/automation/helpers/ota_recovery_fixture.mjs')));
const collectionTasks=['success','partial','failed','unknown','recovered_success'].map((kind,index)=>{
  const task=recoveryTask(kind,41+index);
  const remap=value=>Array.isArray(value)?value.map(remap):value&&typeof value==='object'?Object.fromEntries(Object.entries(value).map(([key,value])=>[key,
    ['system_hotel_id','hotel_id'].includes(key)?80:key==='tenant_id'?10:['business_date','data_date','target_date'].includes(key)?'2026-09-07':key==='platform_hotel_id'?'SYNTHETIC-MT-80':remap(value)])):value;
  return remap(task);
});
const hotels=[{id:80,tenant_id:10,name:'synthetic 酒店 A',hotel_name:'synthetic 酒店 A',status:1},{id:81,tenant_id:10,name:'synthetic 酒店 B',hotel_name:'synthetic 酒店 B',status:1}];
const actor={id:3,username:'synthetic_l10',realname:'synthetic 店长',role_id:1,role_name:'超级管理员',is_super_admin:true,is_hotel_manager:true,tenant_id:10,hotel_id:80,default_hotel_id:80,hotel:hotels[0],permitted_hotels:hotels,
  permissions:{can_manage_own_hotels:true,can_view_online_data:true,can_fetch_online_data:true,can_view_report:true,can_fill_daily_report:true,'operation.view':true,'operation.execute':true},capabilities:['all'],
  context:{tenantId:10,hotelId:80,permissionStatus:'allowed',tokenStatus:'valid',fetchPermissionStatus:'allowed',permitted_hotel_ids:[80,81]}};
const reply=(res,code,data,message='synthetic fixture')=>{res.writeHead(code,{ 'Content-Type':'application/json; charset=utf-8' });res.end(JSON.stringify({code,message,data}));};
const actualBackend=pathname=>pathname==='/api/dashboard/revenue-facts'||pathname.startsWith('/api/agent/precise-queries')||/^\/api\/operation\/(task-workflows|execution-flow|my-tasks|execution-tasks\/\d+\/workflow)$/.test(pathname);
const server=http.createServer(async(req,res)=>{
  try {
    const url=new URL(req.url,'http://127.0.0.1');
    if(url.pathname.startsWith('/api/')) {
      let raw='';for await(const chunk of req)raw+=chunk;const body=raw?JSON.parse(raw):null;
      // A request keeps its fault mode even when another scenario starts while PHP is running.
      const mode=control.mode;
      const factGate=url.pathname==='/api/dashboard/revenue-facts'?control.factsGate?.[url.searchParams.get('hotel_id')]:null;
      const call={method:req.method,path:url.pathname,query:Object.fromEntries(url.searchParams),fixture_mode:mode,received_ms:Date.now()-startedAt};apiCalls.push(call);
      res.once('finish',()=>{call.http_status=res.statusCode;call.response_ms=Date.now()-startedAt;});
      if(url.pathname==='/api/auth/info')return reply(res,200,actor);
      if(['/api/hotels','/api/hotels/all'].includes(url.pathname))return reply(res,200,hotels);
      if(url.pathname==='/api/system-config')return reply(res,200,{system_name:'SUXIOS synthetic',currency_symbol:'¥'});
      if(url.pathname==='/api/online-data/local-collector/status')return reply(res,200,{status:'synthetic',summary:{},devices:[],accounts:[],tasks:collectionTasks,boundary:{synthetic:true,external_write_count:0}});
      const recovery=/^\/api\/online-data\/local-collector\/tasks\/(\d+)\/recover$/.exec(url.pathname);
      if(recovery){
        const task=collectionTasks.find(item=>item.id===Number(recovery[1]));
        if(!task||JSON.stringify(body.scope)!==JSON.stringify(task.recovery_item.scope))return reply(res,409,null,'synthetic original collection scope mismatch');
        call.scope=body.scope;call.action=body.action;call.task_id=task.id;
        if(mode==='recovery-failure')return reply(res,503,null,'synthetic 回执暂不可读，请重试');
        task.recovery_item.last_check={status:'reconciled',synthetic:true};
        if(body.action==='backfill')task.recovery_item.recovery_task_id=500+task.id;
        return reply(res,200,{recovery:task.recovery_item,message:body.action==='backfill'?'synthetic 已登记原日期补采；未执行外部采集':'synthetic 已核对原编号与回执'});
      }
      if(actualBackend(url.pathname)){
        if(mode==='facts-failure'&&url.pathname==='/api/dashboard/revenue-facts')return reply(res,503,null,'synthetic 事实读取失败');
        if(mode==='denied'&&url.pathname.startsWith('/api/agent/precise-queries'))return reply(res,403,null,'synthetic 当前账号无查询权限');
        if(mode==='login'&&url.pathname.startsWith('/api/agent/precise-queries'))return reply(res,401,null,'synthetic 登录不可用，请在原设备登录');
        if(mode==='saving'&&req.method==='POST'&&url.pathname==='/api/agent/precise-queries'){call.client_request_key=body.client_request_key;return reply(res,429,null,'synthetic 原请求仍在保存，请沿用编号重试');}
        const result=await php({path:req.url,method:req.method,body});
        call.code=result.code;call.id=result.data?.id??result.data?.task_id;call.version=result.data?.version;
        call.digest=result.data?.content_digest;call.scope=result.data?.parsed_scope??result.data?.scope??result.data?.hotel;
        if(body?.client_request_key){call.client_request_key=body.client_request_key;call.parent_question_id=body.parent_question_id;call.initial_payload=body;}
        if(body?.request_id)call.request_id=body.request_id;
        if(mode==='lost-post'&&!control.lostPost&&req.method==='POST'&&url.pathname==='/api/agent/precise-queries'){control.lostPost=true;return reply(res,504,null,'synthetic 保存后响应丢失');}
        if(mode==='lost-get'&&!control.lostGet&&req.method==='GET'&&url.pathname.startsWith('/api/agent/precise-queries/')){control.lostGet=true;return reply(res,504,null,'synthetic 精确回读超时');}
        if(mode==='lost-task'&&!control.lostTask&&req.method==='POST'&&url.pathname.endsWith('/workflow')){control.lostTask=true;return reply(res,504,null,'synthetic 任务已提交但响应丢失');}
        if(factGate){factGate.ready.resolve(call);await factGate.release.promise;}
        reply(res,result.code,result.data,result.message);
        factGate?.sent.resolve(call);
        return;
      }
      unsupported.push({method:req.method,path:url.pathname});
      return reply(res,501,null,`synthetic 未接入 ${url.pathname}，此接口不计验收通过`);
    }
    const file=path.resolve(root,'public','.'+(url.pathname==='/'?'/index.html':decodeURIComponent(url.pathname)));
    if(!file.startsWith(path.join(root,'public')+path.sep)||!fs.existsSync(file)||!fs.statSync(file).isFile()){res.writeHead(404);res.end();return;}
    const extension=path.extname(file);const types={'.html':'text/html; charset=utf-8','.js':'text/javascript','.css':'text/css','.svg':'image/svg+xml','.png':'image/png','.avif':'image/avif','.woff2':'font/woff2'};
    res.writeHead(200,{'Content-Type':types[extension]||'application/octet-stream'});fs.createReadStream(file).pipe(res);
  } catch(error){if(!res.headersSent)reply(res,500,null,`synthetic adapter failed: ${error.message}`);else res.destroy();}
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const base=`http://127.0.0.1:${server.address().port}`;
const browser=await chromium.launch({headless:true});
const context=await browser.newContext({viewport:{width:1440,height:1000}});const page=await context.newPage();
page.setDefaultTimeout(20000);page.on('pageerror',error=>pageErrors.push(error.message));
page.on('requestfailed',request=>{const url=new URL(request.url());if(url.origin===base&&url.pathname.startsWith('/api/'))browserEvents.push({kind:'requestfailed',path:url.pathname,query:Object.fromEntries(url.searchParams),error:request.failure()?.errorText,at_ms:Date.now()-startedAt});});
page.on('console',message=>{if(message.type()==='error'&&/加载基础经营事实失败:|加载首页罗盘失败:/.test(message.text()))browserEvents.push({kind:'console',text:message.text(),at_ms:Date.now()-startedAt});});
await page.route('**/*',route=>{const url=new URL(route.request().url());if(url.origin!==base){blockedExternal.push(url.origin+url.pathname);return route.abort();}return route.continue();});
// Fresh isolated browser only. This fixed synthetic marker is not a real credential.
await page.addInitScript(()=>sessionStorage.setItem('token','synthetic-l10-no-real-account'));
const pageIdentity=()=>page.evaluate(()=>({hotel_id:document.querySelector('[aria-label="首页门店"]')?.value,business_date:document.querySelector('[aria-label="经营事实业务日期"]')?.value,render_phase:document.documentElement.dataset.suxiRenderPhase}));
const writeEvidence=()=>fs.writeFileSync(path.join(output,'result.json'),JSON.stringify({...manifest,checks,apiCalls,unsupported,blockedExternal,pageErrors,browserEvents,complete_app:true,status:checks.length===5&&checks.every(c=>c.status==='passed')&&pageErrors.length===0&&!manifest.asset_drift?.length?'passed':'partial'},null,2));
const screenshot=async name=>{browserEvents.push({kind:'screenshot',name,viewport:page.viewportSize(),scope:await pageIdentity(),at_ms:Date.now()-startedAt});return page.screenshot({path:path.join(output,name+'.png'),fullPage:true});};
async function check(name,run){try{await run();checks.push({name,status:'passed'});console.log('PASS '+name);}catch(error){checks.push({name,status:'failed',error:error.message,scope:await pageIdentity()});console.log('FAIL '+name+': '+error.message);await screenshot(name+'-failed');fs.writeFileSync(path.join(output,name+'-dom.txt'),await page.locator('body').innerText());}finally{control.mode='normal';for(const gate of Object.values(control.factsGate||{}))gate.release.resolve();control.factsGate=null;await closeQuery().catch(()=>{});writeEvidence();}}
const home=async()=>{
  if(await page.getByTestId('home-daily-workflows').isVisible())return;
  const menu=page.getByTestId('app-nav').getByRole('button',{name:'今日经营看板',exact:true});
  if(await menu.count())await menu.click();
  else await page.goto(base);
  await page.getByTestId('home-daily-workflows').waitFor();
};
const desktop={width:1440,height:1000},mobile={width:390,height:844};
const waitForFacts=async(hotelId)=>{
  await page.waitForFunction(id=>{
    const refresh=document.querySelector('.home-facts-refresh');
    return document.querySelector('[aria-label="首页门店"]')?.value===id&&refresh&&!refresh.disabled;
  },String(hotelId));
  const text=await page.getByTestId('home-executive-answer').innerText();
  assert.match(text,new RegExp(`synthetic 酒店 ${String(hotelId)==='80'?'A':'B'}.*2026-09-07`));
  assert.match(text,/缺少|暂无|未取得|待补/);
  assert.doesNotMatch(text,/读取失败|Page request was cancelled/);
};
const prepareHome=async(viewport=desktop)=>{
  control.mode='normal';await closeQuery();await page.setViewportSize(viewport);await home();
  const hotel=page.getByLabel('首页门店',{exact:true}),date=page.getByLabel('经营事实业务日期',{exact:true});
  if(await hotel.inputValue()!=='80')await hotel.selectOption('80');
  if(await date.inputValue()!=='2026-09-07'){await date.fill('2026-09-07');await date.dispatchEvent('change');}
  await waitForFacts('80');
};
const isFacts=(hotelId)=>message=>{const url=new URL(message.url());return url.origin===base&&url.pathname==='/api/dashboard/revenue-facts'&&url.searchParams.get('hotel_id')===String(hotelId);};
const openQuery=async()=>{const launcher=page.getByTestId('system-guide-floating-launcher');if(await launcher.count()&&await launcher.getAttribute('aria-expanded')==='true')return;await page.getByRole('button',{name:/中文查数 \/ 追溯/}).click();await page.getByTestId('system-guide-input').waitFor();};
const closeQuery=async()=>{const launcher=page.getByTestId('system-guide-floating-launcher');if(await launcher.count()&&await launcher.getAttribute('aria-expanded')==='true')await launcher.click();};
const ask=async question=>{await openQuery();await page.getByTestId('system-guide-input').fill(question);await page.getByTestId('system-guide-submit').click();await page.waitForFunction(()=>{const input=document.querySelector('[data-testid="system-guide-input"]');return input&&!input.disabled;});};
try {
  await page.goto(base,{waitUntil:'domcontentloaded'});
  await page.getByTestId('home-daily-workflows').waitFor({timeout:60000});
  await page.addStyleTag({content:'body:after{content:"synthetic · L10 integrated";position:fixed;right:5px;top:0;z-index:2147483647;font-size:11px;color:#8b6423;background:#fff5d9;pointer-events:none}'});
  await check('01-trustworthy-status',async()=>{
    await prepareHome(desktop);
    // Hold both real service responses: release old A only after B is actually requested.
    const gates=Object.fromEntries(['80','81'].map(id=>[id,{ready:deferred(),release:deferred(),sent:deferred()}]));
    control.mode='gated-facts';control.factsGate=gates;
    const oldRequest=page.waitForRequest(isFacts('80'));
    await page.getByRole('button',{name:/可信经营状态/}).click();
    const old=await oldRequest;await bounded(gates['80'].ready.promise,'hotel A service response');
    await page.getByLabel('首页门店',{exact:true}).selectOption('81');
    await bounded(gates['81'].ready.promise,'hotel B service response');
    gates['80'].release.resolve();await bounded(gates['80'].sent.promise,'old A response release');
    const oldResponse=await old.response();if(oldResponse)await oldResponse.finished();
    assert.match(await page.getByTestId('home-workspace-scope').innerText(),/synthetic 酒店 B/);
    assert.doesNotMatch(await page.getByTestId('home-executive-answer').innerText(),/synthetic 酒店 A/);
    const currentResponse=page.waitForResponse(isFacts('81'));
    gates['81'].release.resolve();await (await currentResponse).finished();await waitForFacts('81');
    control.factsGate=null;control.mode='facts-failure';
    browserEvents.push({kind:'facts-failure-start',scope:await pageIdentity(),at_ms:Date.now()-startedAt});
    const failureResponse=page.waitForResponse(isFacts('81'));
    await page.getByRole('button',{name:/可信经营状态/}).click();
    assert.equal((await failureResponse).status(),503);
    await page.getByTestId('home-executive-answer').getByText(/synthetic 事实读取失败/).first().waitFor();
    await prepareHome(desktop);await screenshot('01-home-desktop');
    await page.setViewportSize(mobile);await screenshot('01-home-mobile');
    assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
    await page.setViewportSize({width:320,height:844});
    await page.getByTestId('home-daily-workflows').scrollIntoViewIfNeeded();
    const narrowControls=await page.getByTestId('home-daily-workflows').locator('button').evaluateAll(buttons=>buttons.map(button=>{
      const rect=button.getBoundingClientRect();return {width:rect.width,height:rect.height,left:rect.left,right:rect.right};
    }));
    assert.equal(narrowControls.length,5);
    for(const rect of narrowControls)assert.ok(rect.width>=24&&rect.height>=44&&rect.left>=0&&rect.right<=321);
    assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
    await screenshot('01-home-narrow-320');
  });
  await check('02-query-save-readback',async()=>{
    await prepareHome(mobile);
    await ask('携程最近七天收入');
    await page.getByTestId('system-guide-result').getByText(/订单金额|口径/).first().waitFor();
    await ask('订单金额');await page.getByTestId('precise-query-fact-card').waitFor();
    const periodText=await page.getByTestId('precise-query-fact-card').innerText();
    const saved=apiCalls.filter(call=>call.method==='POST'&&call.path==='/api/agent/precise-queries').at(-1);assert.ok(saved.parent_question_id>0);
    const exact=await php({path:`/api/agent/precise-queries/${saved.id}`});assert.equal(exact.data.content_digest,saved.digest);
    assert.equal(exact.data.parsed_scope.hotel_id,80);assert.equal(exact.data.parsed_scope.tenant_id,10);
    fs.writeFileSync(path.join(output,'query-exact-readback.json'),JSON.stringify(exact,null,2));
    await page.setViewportSize(mobile);await screenshot('02-query-mobile');await closeQuery();await page.setViewportSize(desktop);await openQuery();await screenshot('02-query-desktop');
    control.mode='lost-post';await ask('携程昨天间夜');await page.getByTestId('system-guide-error').waitFor();
    const uncertain=apiCalls.filter(call=>call.path==='/api/agent/precise-queries'&&call.method==='POST').at(-1);
    control.mode='normal';await page.getByTestId('system-guide-submit').click();await page.waitForFunction(()=>!document.querySelector('[data-testid="system-guide-input"]').disabled);
    const repeated=apiCalls.filter(call=>call.path==='/api/agent/precise-queries'&&call.method==='POST').at(-1);assert.equal(repeated.id,uncertain.id);assert.deepEqual(repeated.initial_payload,uncertain.initial_payload);
    control.mode='lost-get';await ask('携程昨天订单量');await page.getByTestId('system-guide-error').waitFor();
    const posts=apiCalls.filter(call=>call.path==='/api/agent/precise-queries'&&call.method==='POST').length;
    control.mode='normal';await page.reload();await page.getByTestId('home-daily-workflows').waitFor();await openQuery();
    await page.getByTestId('system-guide-input').waitFor();await page.waitForFunction(()=>document.querySelector('[data-testid="system-guide-input"]').value==='携程昨天订单量');
    await page.getByTestId('system-guide-submit').click();await page.waitForFunction(()=>!document.querySelector('[data-testid="system-guide-input"]').disabled);
    assert.equal(apiCalls.filter(call=>call.path==='/api/agent/precise-queries'&&call.method==='POST').length,posts);
    control.mode='saving';await ask('携程昨天曝光人数');const pending=apiCalls.findLast(call=>call.path==='/api/agent/precise-queries'&&call.method==='POST');control.mode='normal';
    await page.getByTestId('system-guide-submit').click();await page.waitForFunction(()=>!document.querySelector('[data-testid="system-guide-input"]').disabled);
    assert.equal(apiCalls.findLast(call=>call.path==='/api/agent/precise-queries'&&call.method==='POST').client_request_key,pending.client_request_key);
    control.mode='denied';await ask('权限反例');await page.getByTestId('system-guide-error').getByText(/无查询权限/).waitFor();control.mode='normal';await closeQuery();
    // The real request layer invalidates permission context on 403. Recover via its normal auth bootstrap.
    await Promise.all([page.waitForResponse(response=>new URL(response.url()).pathname==='/api/auth/info'),page.reload()]);
    await page.getByTestId('home-daily-workflows').waitFor();
    assert.match(periodText,/5\/7/);assert.match(periodText,/非全期间金额/);
  });
  await check('03-collection-gap-recovery',async()=>{
    await prepareHome(desktop);await page.getByRole('button',{name:/采集缺口定位/}).click();await page.getByTestId('online-data-health-panel').waitFor();
    await page.getByRole('button',{name:'配置：平台账号',exact:true}).click();
    await page.getByTestId('local-collector-collection-receipts').waitFor();
    const row=page.getByTestId('local-collector-receipt-44');await row.waitFor();
    control.mode='recovery-failure';await row.getByTestId('local-collector-recovery-reconcile').click();await row.getByText(/回执暂不可读/).waitFor();
    control.mode='normal';await row.getByTestId('local-collector-recovery-reconcile').click();await row.getByText(/已核对原编号/).waitFor();
    const failed=page.getByTestId('local-collector-receipt-43');await failed.getByTestId('local-collector-recovery-backfill').click();
    await failed.getByText(/已登记原日期补采/).waitFor();
    const recovery=apiCalls.findLast(call=>call.action==='backfill');assert.equal(recovery.scope.system_hotel_id,80);assert.equal(recovery.scope.business_date,'2026-09-07');assert.equal(recovery.task_id,43);
    await failed.scrollIntoViewIfNeeded();await screenshot('03-collection-desktop');await page.setViewportSize({width:390,height:844});await failed.getByTestId('local-collector-recovery-backfill').scrollIntoViewIfNeeded();await screenshot('03-collection-mobile');assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
  });
  await check('04-original-task-verification',async()=>{
    await prepareHome(desktop);await page.getByRole('button',{name:/任务执行核实/}).click();const panel=page.getByTestId('operation-task-workflow');await panel.waitFor();
    await panel.getByRole('button',{name:/^#1 /}).click();await panel.getByRole('button',{name:'设置任务条件',exact:true}).click();
    await panel.getByLabel('工作流类型',{exact:true}).selectOption('price_check');await panel.getByLabel('负责人用户编号',{exact:true}).fill('3');
    await panel.getByLabel('逐项完成条件（每行一条）',{exact:true}).fill('核对同房型展示\n记录发现与处理');
    for(const [label,value] of [['前窗起日','2026-09-08'],['前窗止日','2026-09-08'],['后窗起日','2026-09-09'],['后窗止日','2026-09-09']])await panel.getByLabel(label,{exact:true}).fill(value);
    await panel.getByRole('button',{name:'保存任务条件',exact:true}).click();await panel.getByText('已保存并回读版本 1。',{exact:true}).waitFor();
    await panel.getByRole('button',{name:'开始或恢复',exact:true}).click();await panel.getByText('已保存并回读版本 2。',{exact:true}).waitFor();
    await panel.getByRole('button',{name:'登记执行材料',exact:true}).click();await panel.getByLabel('实际执行日期（区别于业务日）',{exact:true}).fill('2026-09-08');
    await panel.getByLabel('材料引用',{exact:true}).fill('synthetic:l10-execution');await panel.getByLabel('执行记录',{exact:true}).fill('synthetic 核对记录，不代表真实经营动作');
    await panel.getByLabel('核对同房型展示',{exact:true}).check();await panel.getByLabel('记录发现与处理',{exact:true}).check();
    control.mode='lost-task';await panel.getByRole('button',{name:'保存执行材料',exact:true}).click();await panel.getByText(/synthetic 任务已提交但响应丢失/).waitFor();await panel.getByRole('button',{name:'回读确认保存',exact:true}).waitFor();
    control.mode='normal';await panel.getByRole('button',{name:'回读确认保存',exact:true}).click();await panel.getByText(/没有重复提交/).waitFor();
    const exact=await php({path:'/api/operation/execution-tasks/1/workflow?hotel_id=80'});assert.equal(exact.data.version,3);assert.equal(exact.data.execution_records.length,1);
    await panel.getByRole('button',{name:'完成填报',exact:true}).click();await panel.getByLabel('核对同房型展示',{exact:true}).check();await panel.getByLabel('记录发现与处理',{exact:true}).check();
    await panel.getByRole('button',{name:'确认完成填报',exact:true}).click();await panel.getByText('已保存并回读版本 4。',{exact:true}).waitFor();
    await page.setViewportSize({width:390,height:844});await panel.getByText(/synthetic 核对记录/).scrollIntoViewIfNeeded();await screenshot('04-task-mobile');assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await page.setViewportSize({width:1440,height:1000});await screenshot('04-task-desktop');
  });
  await check('05-effect-review-exact-history',async()=>{
    await prepareHome(desktop);
    const previous=await php({path:'/api/operation/execution-tasks/1/workflow?hotel_id=80'});
    assert.equal(previous.data.version,4,'Effect review requires original task version 4 saved by scenario 04');
    await page.getByRole('button',{name:/效果复盘/}).first().click();const panel=page.getByTestId('operation-task-workflow');await panel.waitFor();
    await panel.getByRole('button',{name:/^#1 /}).click();await panel.getByRole('button',{name:'人工核实',exact:true}).click();
    await panel.getByLabel('核实说明',{exact:true}).fill('synthetic 核实，未执行外部动作');await panel.getByLabel('我已人工核对同对象执行与全部完成条件',{exact:true}).check();
    await panel.getByRole('button',{name:'保存人工核实',exact:true}).click();await panel.getByText('已保存并回读版本 5。',{exact:true}).waitFor();
    await panel.getByRole('button',{name:'复盘观察',exact:true}).click();await panel.getByLabel('复盘结论与其他影响因素',{exact:true}).fill('synthetic 后续事实缺失，经营效果未成立');
    await panel.getByRole('button',{name:'保存复盘观察',exact:true}).click();await panel.getByText('已保存并回读版本 6。',{exact:true}).waitFor();
    await panel.getByTestId('workflow-review-result').waitFor();await panel.getByTestId('workflow-review-result').scrollIntoViewIfNeeded();await screenshot('05-review-desktop');
    const reviewed=await php({path:'/api/operation/execution-tasks/1/workflow?hotel_id=80'});assert.equal(reviewed.data.version,6);assert.equal(reviewed.data.review.effect_status,'unestablished');
    fs.writeFileSync(path.join(output,'workflow-exact-readback.json'),JSON.stringify(reviewed,null,2));
    await panel.getByRole('button',{name:'历史 v1',exact:true}).click();await panel.getByText(/版本 1（历史）/).waitFor();
    assert.equal(await panel.getByRole('button',{name:'重开任务',exact:true}).count(),0);
    await panel.getByRole('button',{name:'读取最新版本',exact:true}).click();await panel.getByTestId('workflow-review-result').waitFor();
    await page.setViewportSize({width:390,height:844});await panel.getByTestId('workflow-review-result').scrollIntoViewIfNeeded();await screenshot('05-review-mobile');assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
    const denied=await php({path:'/api/operation/execution-tasks/4/workflow?hotel_id=80'});assert.equal(denied.code,404);
  });
} finally {
  manifest.final_assets=measure();manifest.asset_drift=measuredPaths.filter(name=>manifest.assets[name]!==manifest.final_assets[name]);
  writeEvidence();await screenshot('final-state').catch(()=>{});await browser.close();await new Promise(resolve=>server.close(resolve));
  fs.writeFileSync(path.join(output,'api-and-scope.json'),JSON.stringify({apiCalls,unsupported,pageErrors,blockedExternal,browserEvents},null,2));
  console.log(JSON.stringify({checks:checks.map(c=>({name:c.name,status:c.status})),pageErrors:pageErrors.length,unsupported:[...new Set(unsupported.map(x=>x.path))]}));
  if(checks.length!==5||checks.some(c=>c.status!=='passed')||pageErrors.length||manifest.asset_drift.length)process.exitCode=1;
}

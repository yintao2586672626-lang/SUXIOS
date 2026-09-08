import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import { chromium } from 'playwright-core';
import { compile } from '@vue/compiler-dom';

// No application server, credentials, external API or database. A fresh test-only browser
// mounts the real homepage toolbar and real precise-query Vue component.
const root = process.cwd();
const output = path.join(root, 'output/long-goal');
fs.mkdirSync(output, { recursive: true });
const read = name => fs.readFileSync(path.join(root, name), 'utf8');
const source = read('public/app-main.js');
const navigation = source.slice(source.indexOf('const homeDailyWorkflowError ='), source.indexOf('const operatingLearningList ='));
const home = read('resources/frontend/templates/fragments/23a-page-compass-summary.html').split('<div v-if="currentPage === \'compass\'" class="home-priority-layout">')[0];
const template = `<main data-current-page="compass"><aside class="fixture-note" role="note"><b>synthetic · L10 隔离页面验收</b><p>真实 Vue 组件与入口，模拟酒店、接口与存储；不代表真实账号、OTA 数据或现场效果。</p></aside>
${home}<label>查询平台 <select v-model="operatingQuestionForm.platform" aria-label="查询平台"><option>ctrip</option><option>meituan</option></select></label>
<p data-testid="destination">{{ destination }}</p><operating-question-consultant :ctx="assistantContext" /></main>`;
const render = compile(template, { mode: 'function' }).code;
const html = `<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>synthetic L10 五流程</title><link rel="stylesheet" href="/style.css"><style>body{margin:0;background:#f5f7f4;font-family:'Microsoft YaHei',sans-serif}main{max-width:1200px;margin:auto;padding:20px;box-sizing:border-box}.fixture-note{padding:14px;background:#fff6dc;border:1px solid #c6ac72;border-radius:12px;margin-bottom:18px}.fixture-note p{margin:8px 0 0}select,input{min-height:44px;max-width:100%}</style></head><body><div id="fixture"></div>
<script src="/vue.global.prod.js"></script><script src="/components/system/hotel-data-analyst-components.js"></script><script src="/components/system/operating-intelligence-components.js"></script><script>
const component=window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL.create(Vue);
const app=Vue.createApp({render:new Function('Vue',${JSON.stringify(render)})(Vue),setup(){
const {ref,computed,nextTick}=Vue;
const currentPage=ref('compass'),filterReportHotel=ref('7'),homeRevenueFactBusinessDate=ref('2026-09-07');
const compassHotelOptions=ref([{id:'7',name:'synthetic 酒店 A'},{id:'8',name:'synthetic 酒店 B'}]);
const homeBusinessTimeModel=computed(()=>({hotelName:compassHotelOptions.value.find(h=>h.id===filterReportHotel.value)?.name,selectedBusinessDate:homeRevenueFactBusinessDate.value,maxBusinessDate:'2026-09-08'}));
const compassLoading=ref(false),homeRevenueFactLayerLoading=ref(false),destination=ref('选择流程查看入口行为；下游业务页面在集成候选中终验。');
const operatingQuestionForm=ref({hotel_id:'7',platform:'ctrip',date_start:'2026-09-07',date_end:'2026-09-07'});
const authContext=ref({tenantId:1,permissionStatus:'allowed'}),session=ref(1),user=ref({id:901,tenant_id:1,hotel_id:7});
const captureAuthSession=()=>session.value,isAuthSessionCurrent=value=>value===session.value;
const reportHotelOptionExists=id=>compassHotelOptions.value.some(h=>h.id===id),visibleMenuItems=ref(['compass','online-data','ops-track']);
const findMenuItemByPath=(items,target)=>items.includes(target);
const coreOperationsHotelId=ref(''),coreOperationsTargetDate=ref(''),localCollectorBackfillDate=ref(''),operationFilters=ref({hotel_id:'7',date:''});
const operationExecutionStages=ref([{key:'review',label:'待复盘'}]),operationExecutionStageFilter=ref('');
const openOnlineDataEntryTab=async()=>destination.value='synthetic 路由：采集缺口 / 酒店 '+coreOperationsHotelId.value+' / '+coreOperationsTargetDate.value;
const openHomeOperatingScheduleAll=async()=>destination.value='synthetic 路由：执行与复盘 / 酒店 '+operationFilters.value.hotel_id+' / '+operationFilters.value.date;
const refreshCompassDashboard=async()=>destination.value='synthetic 路由：读取可信状态 / 酒店 '+filterReportHotel.value+' / '+homeRevenueFactBusinessDate.value;
${navigation}
const managerCapabilityRequest=async(url,options)=>{const res=await fetch('/api'+url,{...options,headers:{'Content-Type':'application/json'}});const body=await res.json();if(!res.ok)throw new Error(body.message||'读取失败');return body;};
const assistantContext=computed(()=>({currentPage:currentPage.value,pageTitle:'经营工作台',filterReportHotel:filterReportHotel.value,homeRevenueFactBusinessDate:homeRevenueFactBusinessDate.value,operatingQuestionForm:operatingQuestionForm.value,user:user.value,authContext:authContext.value,assistantSessionEpoch:()=>session.value,managerCapabilityRequest,visibleMenuItems:[{path:'compass'},{path:'online-data'},{path:'ops-track'},{path:'agent-center'}]}));
window.fixture={session,authContext,user};
return {currentPage,filterReportHotel,homeRevenueFactBusinessDate,compassHotelOptions,homeBusinessTimeModel,compassLoading,homeRevenueFactLayerLoading,refreshCompassDashboard,operatingQuestionForm,openHomeDailyWorkflow,homeDailyWorkflowError,assistantContext,destination};
}});app.component('OperatingQuestionConsultant',component.operatingQuestionConsultant);app.mount('#fixture');</script></body></html>`;
const renderedHtml = html
  .replace('<link rel="stylesheet" href="/style.css">', '<link rel="stylesheet" href="/tailwind.min.css"><link rel="stylesheet" href="/font-awesome.min.css"><link rel="stylesheet" href="/style.css">')
  .replace('<style>', '<link rel="stylesheet" href="/compass-authority-polish.css"><style>body:after{content:"synthetic · L10";position:fixed;right:8px;top:3px;z-index:9999;font-size:10px;color:#846924;pointer-events:none}')
  .replace('id="fixture"', 'id="app"').replace("app.mount('#fixture')", "app.mount('#app')");
fs.writeFileSync(path.join(output, 'synthetic-daily-workflows.html'), renderedHtml);
const calls = [], records = new Map(), keys = new Map();
let scenario = 'ready', nextId = 700, lostGet = false, lostPost = false;
const makeRecord = (id, payload) => ({
  id, question: payload.query, route_type: 'operating_query', content_digest: `synthetic-${id}`,
  persistence_status: 'readback_verified', status: 'blocked_by_missing_facts',
  parsed_scope: { ...payload.current_scope, business_date: payload.current_scope.date_start },
  answer_summary: `synthetic 酒店 ${payload.current_scope.hotel_id}：所选日期缺少已核验曝光数据，数值未取得。`,
});
const json = (res, status, body) => { res.writeHead(status, { 'content-type': 'application/json' }); res.end(JSON.stringify(body)); };
const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://127.0.0.1');
  if (url.pathname.startsWith('/api/')) {
    let body = ''; for await (const chunk of req) body += chunk;
    const payload = body ? JSON.parse(body) : null;
    if (url.pathname === '/api/agent/precise-queries' && req.method === 'POST') {
      if (scenario === 'denied' || scenario === 'login') {
        calls.push({ method: 'POST', path: url.pathname, scenario });
        return json(res, scenario === 'denied' ? 403 : 401, { code: scenario === 'denied' ? 403 : 401, message: scenario === 'denied' ? 'synthetic 当前账号没有该酒店权限' : 'synthetic 登录状态不可用，请在原设备登录' });
      }
      const id = keys.get(payload.client_request_key) || ++nextId;
      if (!keys.has(payload.client_request_key)) { keys.set(payload.client_request_key, id); records.set(id, makeRecord(id, payload)); }
      calls.push({ method: 'POST', path: url.pathname, id, client_request_key: payload.client_request_key, scope: payload.current_scope, scenario });
      if (scenario === 'lost-post' && !lostPost) { lostPost = true; return json(res, 504, { code: 504, message: 'synthetic 保存已提交，但网关未取得响应' }); }
      const delay = scenario === 'slow' ? (payload.current_scope.hotel_id === 7 ? 550 : 1200) : 0;
      setTimeout(() => json(res, 200, { code: 200, data: records.get(id) }), delay); return;
    }
    const match = /^\/api\/agent\/precise-queries\/(\d+)$/.exec(url.pathname);
    if (match) {
      const id = Number(match[1]); calls.push({ method: 'GET', path: url.pathname, id, scenario });
      if (scenario === 'lost-get' && !lostGet) { lostGet = true; return json(res, 504, { code: 504, message: 'synthetic 按编号回读超时' }); }
      if (!records.has(id)) return json(res, 404, { code: 404, message: 'synthetic 编号不存在' });
      return json(res, 200, { code: 200, data: records.get(id) });
    }
    if (url.pathname === '/api/agent/system-guidance/context') return json(res, 503, { code: 503, message: 'synthetic 个人学习接口未接入此隔离样例' });
    calls.push({ method: req.method, path: url.pathname, unsupported: true });
    return json(res, 501, { code: 501, message: 'synthetic 未提供该接口，不能视为成功' });
  }
  if (url.pathname === '/') { res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' }); res.end(renderedHtml); return; }
  const file = path.resolve(root, 'public', `.${decodeURIComponent(url.pathname)}`);
  if (!file.startsWith(path.join(root, 'public') + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.writeHead(404); res.end(); return; }
  res.writeHead(200, { 'content-type': file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
  fs.createReadStream(file).pipe(res);
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const base = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({ headless: true });
const browserContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
const page = await browserContext.newPage();
const errors = []; page.on('pageerror', error => errors.push(error.message));
const check = async (name, task) => { await task(); console.log(`PASS ${name}`); };
const open = async () => { if (!(await page.locator('[data-testid="system-guide-floating-entry"]').getAttribute('open') !== null)) await page.getByRole('button', { name: /中文查数/ }).click(); };
const ask = async query => { await open(); await page.getByTestId('system-guide-input').fill(query); await page.getByTestId('system-guide-submit').click(); };
const waitIdle = async () => page.getByTestId('system-guide-input').waitFor({ state: 'visible' }).then(() => page.waitForFunction(() => !document.querySelector('[data-testid="system-guide-input"]').disabled));
try {
  await page.goto(base);
  await check('actual home five-entry layout at 390px', async () => {
    assert.equal(await page.getByTestId('home-daily-workflows').getByRole('button').count(), 5);
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await page.screenshot({ path: path.join(output, '01-home-mobile-synthetic.png'), fullPage: true });
    for (const title of ['可信经营状态', '采集缺口定位', '任务执行核实', '效果复盘']) await page.getByRole('button', { name: new RegExp(title) }).click();
    assert.match(await page.getByTestId('destination').innerText(), /酒店 7/);
  });
  await check('actual query scope and explicit missing data', async () => {
    await ask('查询曝光人数'); await waitIdle();
    await page.getByText('synthetic 酒店 7：所选日期缺少已核验曝光数据，数值未取得。', { exact: true }).first().waitFor();
    assert.match(await page.getByTestId('precise-query-scope').innerText(), /酒店 7.*ctrip.*2026-09-07/);
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await page.screenshot({ path: path.join(output, '02-query-mobile-missing-synthetic.png'), fullPage: true });
  });
  await check('hotel A late result cannot replace B or clear B loading', async () => {
    scenario = 'slow'; await ask('A慢请求');
    await page.getByLabel('首页门店').selectOption('8'); await ask('B慢请求');
    await page.waitForTimeout(750);
    assert.equal(await page.getByTestId('system-guide-input').isDisabled(), true);
    assert.equal(await page.getByText('A慢请求', { exact: true }).count(), 0);
    await waitIdle(); assert.match(await page.getByTestId('precise-query-scope').innerText(), /酒店 8/);
  });
  await check('known saved ID GET failure survives refresh without another POST', async () => {
    scenario = 'lost-get'; const before = calls.filter(x => x.method === 'POST').length;
    await ask('保存后回读断网'); await waitIdle();
    await page.getByTestId('system-guide-error').waitFor();
    await page.screenshot({ path: path.join(output, '03-query-recovery-synthetic.png'), fullPage: true });
    const savedId = calls.filter(x => x.method === 'POST').at(-1).id;
    await page.reload(); await page.getByLabel('首页门店').selectOption('8'); await open();
    await page.waitForFunction(() => document.querySelector('[data-testid="system-guide-input"]').value === '保存后回读断网');
    scenario = 'ready'; await page.getByTestId('system-guide-submit').click(); await waitIdle();
    assert.equal(calls.filter(x => x.method === 'POST').length, before + 1);
    assert.equal(calls.at(-1).id, savedId);
  });
  await check('unknown save result retry reuses client key and saved object', async () => {
    scenario = 'lost-post'; await ask('保存响应丢失'); await waitIdle();
    const first = calls.filter(x => x.method === 'POST').at(-1);
    await page.getByTestId('system-guide-submit').click(); await waitIdle();
    const second = calls.filter(x => x.method === 'POST').at(-1);
    assert.equal(first.client_request_key, second.client_request_key); assert.equal(first.id, second.id);
  });
  await check('permission failure and login block remain visible and create no records', async () => {
    for (const mode of ['denied', 'login']) {
      scenario = mode; const count = records.size; await ask(mode === 'denied' ? '受限权限' : '登录不可用'); await waitIdle();
      assert.equal(records.size, count); assert.match(await page.getByTestId('system-guide-error').innerText(), mode === 'denied' ? /权限/ : /原设备登录/);
    }
    await page.screenshot({ path: path.join(output, '04-login-block-synthetic.png'), fullPage: true });
  });
  await check('date/platform switches clear previous turns before another request', async () => {
    await page.getByLabel('经营事实业务日期').fill('2026-09-06'); await page.getByLabel('经营事实业务日期').dispatchEvent('change');
    await page.getByLabel('查询平台').selectOption('meituan');
    assert.match(await page.getByTestId('precise-query-scope').innerText(), /meituan.*2026-09-06/);
    assert.equal(await page.getByTestId('system-guide-error').count(), 0);
  });
  await check('desktop layout', async () => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.getByTestId('system-guide-floating-launcher').click();
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await page.screenshot({ path: path.join(output, '05-home-desktop-synthetic.png'), fullPage: true });
  });
  assert.deepEqual(errors, []); assert.equal(calls.filter(x => x.unsupported).length, 0);
  fs.writeFileSync(path.join(output, 'fixture-api-evidence.json'), JSON.stringify({ evidence: 'synthetic actual Vue; transport Map; no real database/account', calls, record_count: records.size, errors }, null, 2));
} finally {
  await browser.close(); await new Promise(resolve => server.close(resolve));
}

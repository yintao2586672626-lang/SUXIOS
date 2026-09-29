import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import vm from 'node:vm';
import { chromium } from '@playwright/test';

// Run from the repository root. Dedicated synthetic session and intercepted APIs only.
const root = process.cwd();
const publicRoot = path.resolve(root, 'public');
const out = path.join(root, 'output/review-five/browser');
fs.mkdirSync(out, { recursive: true });
const visualSource = fs.readFileSync('scripts/verify_taste_visual_smoke.mjs', 'utf8');
const mockStart = visualSource.indexOf('function buildMockApiData(');
const mockEnd = visualSource.indexOf('\nasync function login(', mockStart);
assert.ok(mockStart >= 0 && mockEnd > mockStart);
const baseData = vm.runInNewContext(visualSource.slice(mockStart, mockEnd) + '\nbuildMockApiData;', { URL });
const results = [], errors = [], requests = [], writes = [];
let historyMode = 'ok', releaseHistory, taskAssigned = true;
const item = { id: 41, hotel_id: 1, action: '合成任务：复核渠道可售房型', status: 'approved',
  action_management: { action_card: { action: { title: '合成任务：复核渠道可售房型' } } },
  execution: { task_id: 91, status: 'pending' },
  approval: { status: 'approved' }, recommendation: { platform: 'ctrip', target_date: '2026-09-01' },
  execution_task: { id: 91, status: 'approved', hotel_id: 1 },
  assignment: { status: 'scheduled', assignee_id: 999001, due_at: '2026-09-09 18:00:00', review_at: '2026-09-10 10:00:00' },
  next_action: { label: '核对后记录执行证据' } };
const report = { id: 510, hotel_id: 1, report_date: '2026-09-01', status: 'draft',
  summary: '合成日报，仅验证导航。', recommended_actions: [{ title: item.action, action: item.action, execution_intent_id: 41 }] };
const answer = { id: 71, hotel_id: 1, platform: 'ctrip', date_start: '2026-09-01', date_end: '2026-09-01',
  content_digest: '7'.repeat(64), question_text: '合成问答：已保存证据是什么？', answer_summary: '合成证据。',
  answer: { decision_frame: { requested_object: 'demand' } } };
const row = { id: 61, system_hotel_id: 1, hotel_id: 'external-synthetic-9001', hotel_name: '合成验证门店',
  source: 'meituan', data_type: 'advertising', data_date: '2026-09-01', create_time: '2026-09-02 12:00:00',
  dimension: '广告计划', cost: 12, exposure: 20, click: 2 };
const server = http.createServer((req, res) => {
  try {
    const pathname = decodeURIComponent(new URL(req.url, 'http://local').pathname);
    const file = path.resolve(publicRoot, '.' + (pathname === '/' ? '/index.html' : pathname));
    if (!file.startsWith(publicRoot + path.sep) || !fs.statSync(file).isFile()) { res.writeHead(404).end(); return; }
    const type = ({ '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' })[path.extname(file)] || 'application/octet-stream';
    res.writeHead(200, { 'Content-Type': type, 'Cache-Control': 'no-store' });
    fs.createReadStream(file).pipe(res);
  } catch { res.writeHead(404).end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const base = 'http://127.0.0.1:' + server.address().port;
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage({ viewport: { width: 390, height: 844 }, acceptDownloads: true });
page.setDefaultTimeout(15000);
page.on('pageerror', e => errors.push(e.message));
await page.route('**/*', async route => {
  const url = new URL(route.request().url());
  if (url.origin !== base) return route.abort();
  if (!url.pathname.startsWith('/api/')) return route.continue();
  const api = url.pathname.slice(4);
  const method = route.request().method();
  requests.push({ method, path: api, query: url.search });
  if (!['GET', 'HEAD'].includes(method)) {
    writes.push({ method, path: api });
    return route.fulfill({ status: 405, json: { code: 405, message: 'Synthetic review permits no business writes' } });
  }
  let data = baseData(url.href);
  if (api === '/ai-daily-reports/latest') data = { report };
  if (api === '/ai-daily-reports/510') data = report;
  if (/\/execution-intents\/41$/.test(api)) data = item;
  if (['/operation/execution-flow', '/operation/my-tasks'].includes(api)) data = {
    data_status: 'ok', list: api === '/operation/my-tasks' && !taskAssigned ? [] : [item], summary: {}, stages: [], data_gaps: [],
    capabilities: { hotel_id: 1 }, scope: { hotel_id: 1 },
  };
  if (api === '/agent/operating-questions/71') data = answer;
  if (api === '/online-data/daily-data-list') {
    if (historyMode === 'loading') await new Promise(resolve => { releaseHistory = resolve; });
    if (historyMode === 'failure') return route.fulfill({ json: { code: 503, message: '合成查询失败，请重试' } });
    data = { list: historyMode === 'empty' ? [] : [row], pagination: { page: Number(url.searchParams.get('page') || 1), page_size: 30, total: historyMode === 'empty' ? 0 : 1 } };
  }
  await route.fulfill({ json: { code: 200, message: 'synthetic fixture only', data } });
});
await page.addInitScript(() => sessionStorage.setItem('token', 'synthetic-five-feature-session'));
const go = async key => {
  await page.evaluate(async key => { window.__reviewVm.currentPage = key; await window.__reviewVm.$nextTick(); }, key);
  await page.waitForFunction(key => document.querySelector('[data-testid="app-main"]')?.getAttribute('data-current-page') === key
    && !document.querySelector('[data-testid="deferred-page-loading"]'), key);
};
const screenshot = async name => page.screenshot({ path: path.join(out, name + '.png'), fullPage: false });
try {
  await page.goto(base, { waitUntil: 'domcontentloaded' });
  await page.getByTestId('app-main').waitFor({ state: 'visible' });
  await page.waitForFunction(() => {
    const root = document.querySelector('#app');
    const proxy = (root?._vnode?.component || root?.__vue_app__?._container?._vnode?.component)?.proxy;
    if (proxy?.authContext?.permissionStatus !== 'allowed') return false;
    Object.defineProperty(window, '__reviewVm', { configurable: true, get() {
      const root = document.querySelector('#app');
      return (root?._vnode?.component || root?.__vue_app__?._container?._vnode?.component)?.proxy;
    } });
    return true;
  });
  await go('revenue-research-center');
  await page.waitForFunction(() => window.__reviewVm.revenueResearchProducts.length === 8);
  const key = await page.evaluate(async () => {
    const v = window.__reviewVm, key = v.revenueResearchProducts[0].key;
    v.revenueResearchHotelId = '1';
    await v.$nextTick();
    v.revenueResearchRuns = { [key]: { result: {
      status: 'pending_data', hotel_scope: { hotel_id: 1 },
      local_sources: Array.from({ length: 7 }, (_, n) => ({ label: '合成来源' + (n + 1), count: 1 })),
      gaps: [{ label: '现场数据缺口', reason: '本次仅验证页面' }],
      result: { summary: '合成完整摘要首段\n' + '完整内容。'.repeat(70) + '\n合成完整摘要末段', risk_signals: ['仍待真实账号核验'] },
    } } };
    return key;
  });
  await page.getByTestId('revenue-research-full-result-' + key).locator('summary').click();
  assert.match(await page.getByTestId('revenue-research-full-summary-' + key).innerText(), /合成完整摘要末段/);
  assert.match(await page.getByTestId('revenue-research-full-result-' + key).innerText(), /合成来源7/);
  await screenshot('research-mobile');
  results.push({ flow: 'eight cold entries and complete mobile research disclosure', status: 'passed' });

  await go('ai-daily-report');
  await page.waitForFunction(() => window.__reviewVm.revenueAiStaticReady && !window.__reviewVm.operationLoading.aiDailyReport);
  await page.evaluate(report => { const v = window.__reviewVm; v.aiDailyReportForm.hotel_id = '1'; v.aiDailyReportForm.report_date = report.report_date; v.aiDailyReport = report; }, report);
  await page.getByRole('button', { name: '查看对应任务', exact: true }).click();
  await page.waitForFunction(() => window.__reviewVm.currentPage === 'ops-track' && window.__reviewVm.aiDailyReportTaskReturn?.reportId === 510);
  await page.getByTestId('operation-mobile-task-list').waitFor({ state: 'visible' });
  assert.match(await page.getByTestId('operation-mobile-task-list').innerText(), /合成任务/);
  assert.equal(await page.getByTestId('operation-advanced-tools').getAttribute('open'), null);
  await screenshot('tasks-mobile');
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.getByTestId('operation-mobile-task-list').waitFor({ state: 'hidden' });
  await screenshot('tasks-desktop');
  await page.setViewportSize({ width: 390, height: 844 });
  await page.getByTestId('operation-mobile-task-list').waitFor({ state: 'visible' });
  results.push({ flow: 'existing daily report task opens exact intent and mobile task card', status: 'passed' });
  await page.getByRole('button', { name: '返回日报并刷新进展', exact: true }).click();
  await page.waitForFunction(() => window.__reviewVm.currentPage === 'ai-daily-report'
    && !window.__reviewVm.operationLoading.aiDailyReport && window.__reviewVm.aiDailyReport?.id === 510);
  assert.ok(requests.some(r => r.path === '/ai-daily-reports/510'));
  results.push({ flow: 'return reads exact originating daily report without task creation', status: 'passed' });

  await page.evaluate(async answer => { await window.__reviewVm.openOperatingQuestionEvidence(answer); }, answer);
  await page.waitForFunction(() => window.__reviewVm.currentPage === 'agent-center' && window.__reviewVm.operatingQuestionState.result?.id === 71);
  assert.deepEqual(await page.evaluate(() => {
    const v = window.__reviewVm; return [v.operatingQuestionForm.hotel_id, v.operatingQuestionForm.platform, v.operatingQuestionState.result.content_digest];
  }), ['1', 'ctrip', answer.content_digest]);
  results.push({ flow: 'saved answer exact identity readback and workspace navigation', status: 'passed' });

  await go('meituan-ebooking');
  await page.evaluate(async () => {
    const v = window.__reviewVm;
    v.onlineDataTab = 'meituan-download'; v.downloadCenterTab = 'ads';
    v.onlineDataFilter = { hotel_id: '1', source: 'meituan', data_type: 'advertising', start_date: '2026-09-01', end_date: '2026-09-03' };
    v.onlineDataPage = 1;
    await v.$nextTick(); await v.loadOnlineDataList({ force: true });
  });
  await page.getByTestId('meituan-history-result-scope').waitFor({ state: 'visible' });
  await page.getByTestId('meituan-history-result-scope').locator('summary').click();
  await page.getByTestId('meituan-history-result-scope').getByRole('button', { name: '查看详情' }).click();
  const dialog = page.getByRole('dialog', { name: '保存记录详情' });
  await dialog.waitFor({ state: 'visible' });
  const close = dialog.getByRole('button', { name: '关闭详情，返回原查询' });
  const bounds = await close.boundingBox();
  assert.ok(bounds.height >= 44 && bounds.x >= 0 && bounds.x + bounds.width <= 390);
  await screenshot('history-dialog-mobile');
  await close.click();
  const identity = await page.getByTestId('meituan-history-result-scope').innerText();
  await page.evaluate(() => { window.__reviewVm.onlineDataFilter.start_date = '2026-09-02'; });
  assert.match(await page.getByTestId('meituan-history-result-scope').innerText(), /筛选条件已修改/);
  for (const [id, suffix] of [['meituan-download-current-page-csv', 'page-1'], ['meituan-download-filtered-csv', 'filtered']]) {
    const pending = page.waitForEvent('download');
    await page.getByTestId(id).click();
    const download = await pending;
    assert.ok(download.suggestedFilename().includes('1-2026-09-01-2026-09-03-' + suffix));
    await download.saveAs(path.join(out, download.suggestedFilename()));
    assert.match(fs.readFileSync(path.join(out, download.suggestedFilename()), 'utf8'), /meituan,1,2026-09-01,2026-09-03/);
  }
  assert.match(identity, /酒店 #1/);
  results.push({ flow: 'native detail return and both CSV scopes survive changed query draft', status: 'passed' });
  historyMode = 'loading';
  await page.evaluate(() => { void window.__reviewVm.loadOnlineDataList({ force: true }); });
  await page.getByRole('status').filter({ hasText: '正在读取已保存数据' }).waitFor({ state: 'visible' });
  assert.equal(typeof releaseHistory, 'function');
  historyMode = 'ok'; releaseHistory();
  await page.waitForFunction(() => !window.__reviewVm.onlineDataListLoading);
  historyMode = 'failure';
  await page.evaluate(async () => { await window.__reviewVm.loadOnlineDataList({ force: true }); });
  await page.getByRole('alert').filter({ hasText: '合成查询失败' }).waitFor({ state: 'visible' });
  assert.equal(await page.getByText('暂无美团广告数据', { exact: true }).isVisible(), false);
  await screenshot('history-failure-mobile');
  await page.evaluate(() => { window.__reviewVm.downloadCenterTab = 'traffic'; });
  await page.getByRole('heading', { name: /美团流量分析/ }).waitFor({ state: 'visible' });
  assert.equal(await page.getByRole('alert').filter({ hasText: '合成查询失败' }).isVisible(), false);
  await page.evaluate(() => { window.__reviewVm.downloadCenterTab = 'ads'; });
  historyMode = 'empty';
  await page.evaluate(async () => { await window.__reviewVm.loadOnlineDataList({ force: true }); });
  await page.getByText('暂无美团广告数据', { exact: true }).waitFor({ state: 'visible' });
  results.push({ flow: 'history failure is distinct from a successful empty result', status: 'passed' });
  taskAssigned = false;
  await go('ops-track');
  const taskScope = page.getByLabel('任务范围', { exact: true });
  await taskScope.selectOption('mine');
  await page.waitForFunction(() => !window.__reviewVm.operationLoading.actions && window.__reviewVm.operationExecutionItems.length === 0);
  assert.doesNotMatch(await page.getByTestId('operation-mobile-task-list').innerText(), /合成任务/);
  await taskScope.selectOption('all');
  await page.getByTestId('operation-mobile-task-list').waitFor({ state: 'visible' });
  assert.match(await page.getByTestId('operation-mobile-task-list').innerText(), /合成任务/);
  results.push({ flow: 'personal scope excludes unassigned fixture and all scope reads it back', status: 'passed' });
  await page.getByRole('button', { name: '经营助手', exact: true }).click();
  await page.getByTestId('system-guide-floating-panel').waitFor({ state: 'visible' });
  await page.getByTestId('system-guide-input').waitFor({ state: 'visible' });
  await screenshot('assistant-loaded-mobile');
  results.push({ flow: 'full assistant lazy-loads its evidence controller and renders the input', status: 'passed' });
  assert.equal(writes.length, 0, 'navigation must not write or duplicate tasks');
  assert.deepEqual(errors, [], 'no uncaught browser exceptions');
  console.log(JSON.stringify({ status: 'passed', flows: results.length, evidence: 'local synthetic browser only' }));
} catch (error) {
  await screenshot('failure').catch(() => {});
  console.error(JSON.stringify(await page.evaluate(() => ({ page: window.__reviewVm?.currentPage,
    researchKeys: Object.keys(window.__reviewVm?.revenueResearchRuns || {}),
    report: window.__reviewVm?.aiDailyReport?.id, operationError: window.__reviewVm?.operationError }))));
  console.error(error.stack || error.message);
  process.exitCode = 1;
} finally {
  fs.writeFileSync(path.join(out, 'receipt.json'), JSON.stringify({ time: new Date().toISOString(), evidence: 'local synthetic browser only', results, pageErrors: errors, businessWrites: writes, requests }, null, 2) + '\n');
  await browser.close();
  await new Promise(resolve => server.close(resolve));
}

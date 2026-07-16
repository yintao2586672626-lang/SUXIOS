import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const page = readFileSync('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html', 'utf8');
const appMain = readFileSync('public/app-main.js', 'utf8');
const ctripStatic = readFileSync('public/ctrip-static.js', 'utf8');

const sliceBetween = (source, startText, endText) => {
  const start = source.indexOf(startText);
  assert.ok(start >= 0, `missing start marker: ${startText}`);
  const end = source.indexOf(endText, start);
  assert.ok(end > start, `missing end marker: ${endText}`);
  return source.slice(start, end);
};

const loadCtripStaticApi = () => {
  const context = { window: {}, console };
  vm.runInNewContext(ctripStatic, context, { filename: 'public/ctrip-static.js' });
  return context.window.SUXI_CTRIP_STATIC;
};

test('Ctrip business tabs use customer-facing names and do not expose raw diagnostics', () => {
  const flowPanel = sliceBetween(page, "onlineDataTab === 'ctrip-flow-overview'", '<!-- 流量数据获取 -->');
  const profileOpenTag = page.match(/<div[^>]*v-if="onlineDataTab === 'ctrip-fetch-settings'"[^>]*>/)?.[0] || '';

  assert.match(page, />\s*流量概览\s*<\/button>/);
  assert.match(page, />\s*入库记录\s*<\/button>/);
  assert.doesNotMatch(flowPanel, /目标页面：https:\/\/ebooking\.ctrip\.com/);
  assert.doesNotMatch(flowPanel, /showRawData|ctripFlowOverviewResult\.captured_counts|ctripFlowOverviewInterfaceRows\.length/);
  assert.doesNotMatch(flowPanel, /接口命中明细|复制排障数据|JSON\.stringify\(ctripFlowOverviewResult/);
  assert.match(profileOpenTag, /ctrip-fetch-settings/);
  assert.doesNotMatch(profileOpenTag, /is_super_admin/);
});

test('Ctrip result state never presents an exception as a green success', async () => {
  const flowPanel = sliceBetween(page, "onlineDataTab === 'ctrip-flow-overview'", '<!-- 流量数据获取 -->');
  assert.match(flowPanel, /otaFetchResultView\(ctripFlowOverviewResult\)\.panelClass/);
  assert.match(flowPanel, /otaFetchResultView\(ctripFlowOverviewResult\)\.title/);
  assert.match(flowPanel, /otaFetchResultView\(ctripFlowOverviewResult\)\.detail/);
  assert.doesNotMatch(flowPanel, /获取成功[\s\S]*已保存 \{\{ ctripFlowOverviewResult\.saved_count/);

  const api = loadCtripStaticApi();
  let visibleResult = null;
  const outcome = await api.runCtripOverviewFetchFlow({
    getSystemHotelId: () => 7,
    getActiveCtripConfig: () => ({ id: 11, has_cookies: true, credential_status: 'ready' }),
    getForm: () => ({ requestUrls: 'https://ebooking.ctrip.com/api/example', dataDate: '2026-07-14' }),
    setResult: value => { visibleResult = value; },
    requestFetch: async () => {
      const error = new Error('授权已失效');
      error.data = { data: { stderr: 'platform rejected request' } };
      throw error;
    },
  });

  assert.equal(outcome.status, 'exception');
  assert.equal(visibleResult.error, '授权已失效');
  assert.equal(visibleResult.ui_flow_status, 'exception');
});

test('Ctrip overview uses a visible business date and refuses an empty date', async () => {
  const api = loadCtripStaticApi();
  assert.match(api.createCtripOverviewForm().dataDate, /^\d{4}-\d{2}-\d{2}$/);

  let requestCount = 0;
  const outcome = await api.runCtripOverviewFetchFlow({
    getSystemHotelId: () => 7,
    getActiveCtripConfig: () => ({ id: 11, has_cookies: true, credential_status: 'ready' }),
    getForm: () => ({ requestUrls: 'https://ebooking.ctrip.com/api/example', dataDate: '' }),
    requestFetch: async () => { requestCount += 1; return { code: 200 }; },
  });

  assert.equal(outcome.status, 'missing_data_date');
  assert.equal(requestCount, 0);
});

test('Ctrip tables distinguish real zero from a missing value', () => {
  assert.match(appMain, /const hasDisplayValue = \(value\) =>/);
  assert.match(appMain, /const toDisplayNumber = \(value\) =>/);
  assert.match(appMain, /const formatOptionalNumber = \(value, missingText = '未返回'\)/);
  assert.match(appMain, /const formatOptionalPercent = \(value, missingText = '未返回'\)/);
  assert.match(appMain, /const empty = \(\) => \(\{[\s\S]*listExposure: null,[\s\S]*submitRate: null,/);
  assert.match(appMain, /const aNumber = toDisplayNumber\(aValue\);[\s\S]*return aMissing \? 1 : -1/);

  assert.match(page, /formatOptionalNumber\(hotel\.bookOrderNum\)/);
  assert.match(page, /formatOptionalPercent\(hotel\.convertionRate\)/);
  assert.match(page, /formatOptionalNumber\(row\.self\.listExposure\)/);
  assert.match(page, /formatOptionalPercent\(row\.avg\.submitRate\)/);
  assert.doesNotMatch(page, /hotel\.bookOrderNum \|\| '-'/);
  assert.doesNotMatch(page, /hotel\.convertionRate \? hotel\.convertionRate \+ '%' : '-'/);
});

test('Ctrip overview tasks stay Ctrip-only and history controls remain usable for scoped accounts', () => {
  const priorityStrip = sliceBetween(
    page,
    'data-testid="ctrip-data-health-priority-strip"',
    'data-testid="ctrip-store-overview-business-board"',
  );
  const historyFilters = sliceBetween(page, 'onlineHistoryFilter.platform', '<div class="overflow-x-auto table-container">');

  assert.match(appMain, /const ctripDataHealthCookieAlertRows = computed\(\(\) => dataHealthCookieAlertRows\.value\.filter\(dataHealthRowIsCtrip\)\)/);
  assert.match(appMain, /const ctripDataHealthTodayWorkOrders = computed\(\(\) => buildDataHealthTodayWorkOrders\(\{/);
  assert.match(appMain, /highRiskActionRows: \[\]/);
  assert.match(priorityStrip, /ctripDataHealthCookieAlertSummary/);
  assert.match(priorityStrip, /ctripDataHealthQualityTaskRows/);
  assert.match(priorityStrip, /ctripDataHealthTodayWorkOrders/);
  assert.doesNotMatch(priorityStrip, /dataHealthHighRiskSummary/);

  assert.match(historyFilters, /查询已存数据/);
  assert.match(historyFilters, /重置筛选/);
  assert.match(historyFilters, /刷新最新抓取结果/);
  assert.doesNotMatch(historyFilters, /user\?\.is_super_admin/);
  assert.match(page, /ctripSearchOpportunitySaving \? '生成中\.\.\.' : '下载图片'/);
});

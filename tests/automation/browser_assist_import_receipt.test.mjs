import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { compile } from '@vue/compiler-dom';
import { renderToString } from 'vue/server-renderer';

const source = readFileSync('public/app-main.js', 'utf8');
const extract = (start, end) => {
  const from = source.indexOf(start), to = source.indexOf(end, from);
  assert.ok(from >= 0 && to > from, start);
  return source.slice(from, to);
};
const receipt = status => ({
  status, source_contract: 'ota_browser_assist_collection_contract.v1', collection_mode: 'browser_assist_dom',
  package_count: 2, row_count: 2, normalized_count: 2, saved_count: status === 'failed' ? 0 : (status === 'success' ? 2 : 1), warnings: [],
  packages: ['ctrip', 'meituan'].map((platform, index) => ({
    platform, data_type: 'traffic', row_count: 1,
    status: status === 'success' || (index === 0 && status !== 'failed') ? 'success' : 'failed',
    message: index === 1 && status !== 'success' ? '合成失败：回读数据不一致' : '',
    normalized_count: 1, saved_count: status === 'success' || (index === 0 && status !== 'failed') ? 1 : 0,
    readback_verified: status === 'success' || (index === 0 && status !== 'failed'), sync_task_id: 101 + index,
    request_scope: { scope_type: 'parsed_import_request', system_hotel_id: 80, platform, data_type: 'traffic', business_dates: [`2026-08-${23 + index}`] },
  })),
});
const harness = (data, httpStatus = 200) => {
  const notices = [], posts = [], refreshes = [];
  const envelope = { code: httpStatus, message: '合成导入结果', data };
  const context = {
    ...Vue, Set, FormData, console: { error() {} }, API_BASE: '/api',
    currentPage: Vue.ref('online-data'), token: Vue.ref('synthetic-only'), user: Vue.ref({}),
    captureAuthSession: () => ({ epoch: 1 }), isAuthSessionCurrent: () => true,
    readRequestCooldown: { check: () => null, record() {} },
    isTerminalAuthFailureResponse: () => false, currentPageReadPolicy: () => ({}),
    withBusinessRequestContext: (url, options) => ({ url, options }),
    appSystemStatic: { protectedRequestDenial: () => null },
    fetch: async (url, options) => {
      posts.push({ url, body: JSON.parse(options.body) });
      return { ok: httpStatus >= 200 && httpStatus < 300, status: httpStatus, json: async () => envelope };
    },
    showToast: (...args) => notices.push(args),
    schedulePlatformDataSourcePanelLoad: () => refreshes.push('sources'),
    schedulePlatformCollectionStatusRefresh: () => refreshes.push('collection'),
    scheduleDataHealthPanelRefresh: () => refreshes.push('health'),
    scheduleOnlineDataRefresh: () => refreshes.push('rows'),
  };
  const state = vm.runInNewContext(`(() => {
    ${extract('const executeApiRequest =', 'const apiRequest = request;')}
    ${extract('const browserAssistImporting =', 'const platformAccountCenterPlatform =')}
    ${extract('const browserAssistImportForm =', 'const downloadCenterTab =')}
    ${extract('const readBrowserAssistCaptureFile =', 'const platformSourceStatusClass =')}
    return { submit: importBrowserAssistCaptureFromText, form: browserAssistImportForm,
      result: browserAssistImportResult, busy: browserAssistImporting,
      countText: typeof browserAssistImportCountText === 'function' ? browserAssistImportCountText : null,
      statusText: typeof browserAssistImportStatusText === 'function' ? browserAssistImportStatusText : null,
      warningSummaryText: browserAssistImportWarningSummaryText };
  })()`, context);
  state.form.value = { system_hotel_id: '80', capture_json: '{"synthetic":"receipt"}' };
  return { ...state, envelope, posts, notices, refreshes };
};
const renderPanel = async view => {
  const template = readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');
  const start = template.indexOf('<div class="bg-white border border-emerald-200 rounded-lg p-4" data-testid="browser-assist-import-panel">');
  const end = template.indexOf('<div class="bg-white border border-gray-200 rounded-lg p-4">', start);
  assert.ok(start >= 0 && end > start);
  const render = new Function('Vue', compile(template.slice(start, end), { mode: 'function', prefixIdentifiers: true }).code)(Vue);
  const result = view.result.value;
  const context = {
    browserAssistImportForm: view.form.value, platformDataSourceHotelOptions: [],
    browserAssistImporting: view.busy.value, browserAssistImportFileName: '', browserAssistImportResult: result,
    browserAssistImportPackages: result?.packages || [], browserAssistImportWarnings: result?.warnings || [],
    browserAssistImportWarningSummaryText: view.warningSummaryText.value,
    browserAssistImportCountText: view.countText, browserAssistImportStatusText: view.statusText,
    platformSyncLogScope: null,
    copyBrowserAssistCollectorScript() {}, readBrowserAssistCaptureFile() {}, importBrowserAssistCaptureFromText() {}, clearBrowserAssistImportForm() {},
  };
  return renderToString(Vue.createSSRApp({ data: () => context, render }));
};

for (const [status, code] of [['partial_success', 422], ['failed', 500]]) {
  test(`actual HTTP ${code} keeps ${status} package receipts, draft, readback and request scope visible`, async () => {
    const view = harness(receipt(status), code);
    await view.submit();
    assert.equal(view.result.value?.status, status);
    assert.equal(view.form.value.capture_json, '{"synthetic":"receipt"}');
    assert.equal(view.busy.value, false);
    assert.equal(view.posts.length, 1, 'no automatic replay after a partial write');
    assert.deepEqual(view.refreshes, ['sources', 'collection', 'health', 'rows']);
    const rendered = await renderPanel(view);
    assert.match(rendered, status === 'partial_success' ? /部分完成/ : /导入失败/);
    for (const text of ['合成失败：回读数据不一致', '102', '2026-08-23', '2026-08-24', '请求范围', '未验证', '核对后重新导入']) assert.ok(rendered.includes(text), text);
    assert.ok(!view.notices.some(([, level]) => level === 'success'));
  });
}

test('complete receipt clears only its submitted draft and retains the verified package readback', async () => {
  const view = harness(receipt('success'));
  await view.submit();
  assert.equal(view.form.value.capture_json, '');
  assert.equal(view.result.value?.saved_count, 2);
  assert.equal(view.notices[0][1], 'success');
  const rendered = await renderPanel(view);
  assert.match(rendered, /已完成/);
  assert.match(rendered, /已回读/);
});

test('unknown package outcomes keep null counts, previously returned totals and unattempted packages separate', async () => {
  const data = receipt('partial_success');
  data.saved_count_complete = false;
  data.unconfirmed_package_count = 1;
  Object.assign(data.packages[1], { status: 'unknown', saved_count: null, readback_verified: null, sync_task_id: null, message: '该分包未取得完整保存回执' });
  data.packages.push({ platform: 'meituan', data_type: 'competitor', row_count: 1, status: 'not_attempted', saved_count: 0, readback_verified: false, sync_task_id: 0, message: '前一分包结果未确认，尚未尝试' });
  const view = harness(data, 422);
  await view.submit();
  assert.equal(view.result.value?.saved_count, 1);
  assert.equal(view.result.value?.packages[1].saved_count, null);
  const rendered = await renderPanel(view);
  for (const text of ['未返回', '结果未确认', '尚未尝试', '总数未确认', '1 个分包']) assert.ok(rendered.includes(text), text);
  assert.equal(view.form.value.capture_json, '{"synthetic":"receipt"}');
});

test('partial receipt shows every normalization warning and escapes warning text', async () => {
  const data = receipt('partial_success');
  data.warnings = [
    { platform: 'ctrip', code: 'data_date_missing', message: '携程流量缺少业务日期，未导入' },
    { platform: 'meituan', code: 'metrics_missing', message: '美团流量缺少可用指标，未导入' },
    { platform: 'meituan', code: 'identity_missing' },
    { platform: 'meituan', code: 'message_untrusted', message: '<script>alert("warning")</script>' },
  ];
  const view = harness(data, 422);
  await view.submit();
  const rendered = await renderPanel(view);
  assert.match(rendered, /提醒 4 条/);
  for (const warning of ['携程流量缺少业务日期，未导入', '美团流量缺少可用指标，未导入', 'identity_missing']) assert.ok(rendered.includes(warning), warning);
  assert.ok(rendered.includes('&lt;script&gt;') && rendered.includes('&lt;/script&gt;'));
  assert.ok(!rendered.includes('<script>alert("warning")</script>'));
  assert.match(source, /^\s+browserAssistImportWarningSummaryText,\s*$/m, 'the computed warning summary is exposed to the Vue template');
});

test('a structured zero-package failure preserves the draft and shows invalid-date diagnostics without claiming package recovery', async () => {
  const data = {
    status: 'failed', source_contract: 'ota_browser_assist_collection_contract.v1', collection_mode: 'browser_assist_dom',
    package_count: 0, row_count: 0, normalized_count: 0, saved_count: 0,
    saved_count_complete: true, unconfirmed_package_count: 0,
    warnings: [
      { platform: 'ctrip', module: 'ctrip_stats', code: 'source_timestamp_invalid', message: 'Ctrip realtime metrics skipped because the source timestamp is invalid; normalizer-generated time was not used as a business date.' },
      { platform: 'meituan', module: 'meituan_stats', code: 'data_date_invalid', message: 'Meituan realtime metrics skipped because the explicit business date is invalid; snapshot time was not used as a fallback.' },
    ],
    packages: [],
  };
  const view = harness(data, 422);
  await view.submit();
  assert.equal(view.result.value?.status, 'failed');
  assert.equal(view.result.value?.receipt_status, 'failed');
  assert.equal(view.form.value.capture_json, '{"synthetic":"receipt"}');
  assert.equal(view.posts.length, 1, 'a failed receipt never replays the upload');
  assert.deepEqual(view.refreshes, [], 'a receipt with no importable packages does not refresh saved-data views');
  assert.ok(!view.notices.some(([, level]) => level === 'success'));
  assert.match(view.notices[0][0], /核对采集提醒和日期来源/);
  const rendered = await renderPanel(view);
  for (const text of ['导入失败', '提醒 2 条', 'source timestamp is invalid', 'normalizer-generated time was not used as a business date', 'explicit business date is invalid', 'snapshot time was not used as a fallback', '原稿已保留']) assert.ok(rendered.includes(text), text);
  assert.ok(!rendered.includes('分包任务编号'));
  assert.ok(!rendered.includes('任务 #'));
});

test('an empty failed receipt requires the complete zero-save contract and a warning array', async () => {
  const valid = {
    status: 'failed', source_contract: 'ota_browser_assist_collection_contract.v1', collection_mode: 'browser_assist_dom',
    package_count: 0, row_count: 0, normalized_count: 0, saved_count: 0,
    saved_count_complete: true, unconfirmed_package_count: 0, warnings: [], packages: [],
  };
  for (const [label, change] of [
    ['missing warnings', data => { delete data.warnings; }],
    ['incomplete saved count', data => { data.saved_count_complete = false; }],
    ['nonzero saved count', data => { data.saved_count = 1; }],
    ['partial status', data => { data.status = 'partial_success'; }],
  ]) {
    const data = structuredClone(valid);
    change(data);
    const view = harness(data, 422);
    await view.submit();
    assert.equal(view.result.value, null, label);
    assert.equal(view.form.value.capture_json, '{"synthetic":"receipt"}', label);
    assert.equal(view.refreshes.length, 0, label);
  }
});

for (const [label, data] of [['null', null], ['empty', {}], ['missing contract', { status: 'success', saved_count: 1 }], ['missing count', { ...receipt('success'), saved_count: null }], ['boolean count', { ...receipt('success'), saved_count: true }]]) {
  test(`incomplete success envelope preserves the draft and unknown counts: ${label}`, async () => {
    const view = harness(data);
    await view.submit();
    assert.equal(view.form.value.capture_json, '{"synthetic":"receipt"}');
    assert.ok(!view.notices.some(([, level]) => level === 'success'));
    assert.ok(!view.notices.some(([message]) => message.includes('0 条入库')));
  });
}

test('an explicit zero remains zero without making missing counts zero', async () => {
  const data = receipt('failed');
  data.packages[1].row_count = null;
  const view = harness(data, 500);
  await view.submit();
  assert.equal(view.countText(0), '0');
  assert.equal(view.countText('0'), '0');
  for (const value of [null, undefined, '', ' ', -1, true, [], NaN, Infinity]) assert.equal(view.countText(value), '未返回');
  assert.match(await renderPanel(view), /未返回/);
});

for (const [label, fields] of [['hotel', { system_hotel_id: 81 }], ['platform', { platform: 'ctrip' }]]) {
  test(`a mismatched package ${label} scope is not accepted as this request receipt`, async () => {
    const data = receipt('partial_success');
    Object.assign(data.packages[1].request_scope, fields);
    const view = harness(data, 422);
    await view.submit();
    assert.equal(view.result.value, null);
    assert.equal(view.form.value.capture_json, '{"synthetic":"receipt"}');
    assert.equal(view.refreshes.length, 0);
  });
}

test('actual HTTP 500 cannot clear a browser-assist draft even if its body claims complete success', async () => {
  const view = harness(receipt('success'), 500);
  view.envelope.code = 200;
  await view.submit();
  assert.equal(view.form.value.capture_json, '{"synthetic":"receipt"}');
  assert.equal(view.result.value?.receipt_status, 'unknown');
  assert.ok(!view.notices.some(([, level]) => level === 'success'));
});

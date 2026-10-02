import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { ref, computed, watch } from 'vue';
import * as Vue from 'vue';
import { compile } from '@vue/compiler-dom';
import { renderToString } from 'vue/server-renderer';

const source = readFileSync('public/app-main.js', 'utf8');
const extract = (start, end) => {
  const from = source.indexOf(start), to = source.indexOf(end, from);
  assert.ok(from >= 0 && to > from, start);
  return source.slice(from, to);
};
const success = (sourceId = 10, fields = {}) => ({ code: 200, data: {
  status: 'success', saved_count: 1, selected_data_source_id: sourceId,
  effective_import_source_id: sourceId + 200, import_provenance_status: 'user_provided_unverified',
  analysis_eligible_count: 0, readback_verified: true, ...fields,
} });
const harness = () => {
  const posts = [], notices = [], refreshes = [];
  let session = 1;
  const context = { ref, computed, watch,
    captureAuthSession: () => session, isAuthSessionCurrent: value => value === session,
    showToast: (...args) => notices.push(args),
    schedulePlatformDataSourcePanelLoad: () => refreshes.push('sources'),
    scheduleOnlineDataRefresh: () => refreshes.push('rows'),
    request: (url, options) => new Promise((resolve, reject) => posts.push({ url, body: JSON.parse(options.body), resolve, reject })),
  };
  const state = vm.runInNewContext(`(() => {
    ${extract('const platformDataImporting =', 'const browserAssistImporting =')}
    ${extract('const platformImportForm =', 'const browserAssistImportForm =')}
    ${extract('const importPlatformDataRowsFromText =', 'const readBrowserAssistCaptureFile =')}
    return { form: platformImportForm, result: platformImportResult, summary: platformImportResultSummaryText,
      busy: platformDataImporting, submit: importPlatformDataRowsFromText };
  })()`, context);
  state.form.value = { data_source_id: '10', rows_json: '[{"synthetic":"old","data_date":"2026-09-14"}]' };
  return { ...state, posts, notices, refreshes, changeSession: () => { session += 1; } };
};

for (const outcome of ['success', 'error']) {
  test(`old manual import ${outcome} does not replace a new source draft or receipt`, async () => {
    const view = harness();
    const old = view.submit();
    assert.equal(view.posts[0].body.data_source_id, 10);
    view.form.value.data_source_id = '11';
    view.form.value.rows_json = '[{"synthetic":"new"}]';
    if (outcome === 'success') view.posts[0].resolve(success());
    else view.posts[0].reject(new Error('synthetic old failure'));
    await old;
    assert.equal(view.form.value.rows_json, '[{"synthetic":"new"}]');
    assert.equal(view.result.value, null);
    assert.equal(view.notices.length, 0);
    assert.equal(view.refreshes.length, 0);
    assert.equal(view.busy.value, false);
    const current = view.submit();
    assert.equal(view.posts[1].body.data_source_id, 11);
    view.posts[1].resolve(success(11));
    await current;
    assert.equal(view.form.value.rows_json, '');
    assert.equal(view.result.value.selected_data_source_id, 11);
    assert.equal(view.result.value.effective_import_source_id, 211);
    assert.match(view.summary.value, /用户提供 · 未验证/);
    assert.equal(view.notices[0][1], 'warning', 'database readback never upgrades manual provenance');
  });
}

test('source A to B to A invalidates pending output and a new draft clears old receipt', async () => {
  const view = harness();
  const old = view.submit();
  view.form.value.data_source_id = '11';
  view.form.value.data_source_id = '10';
  view.posts[0].resolve(success());
  await old;
  assert.equal(view.result.value, null);
  assert.ok(view.form.value.rows_json);
  const current = view.submit();
  view.posts[1].resolve(success());
  await current;
  assert.equal(view.result.value.saved_count, 1);
  view.form.value.rows_json = '[{"synthetic":"next"}]';
  assert.equal(view.result.value, null);
});

test('duplicate clicks send one write and current failure remains retryable', async () => {
  const view = harness();
  const pending = view.submit();
  const duplicate = view.submit();
  assert.equal(view.posts.length, 1);
  view.posts[0].reject(new Error('synthetic failure'));
  await Promise.all([pending, duplicate]);
  assert.equal(view.busy.value, false);
  assert.ok(view.form.value.rows_json);
  const retry = view.submit();
  view.posts[1].resolve(success());
  await retry;
  assert.equal(view.result.value.saved_count, 1);
});

test('an old session finally cannot unlock a new session write', async () => {
  const view = harness();
  const old = view.submit();
  view.changeSession();
  view.busy.value = false;
  view.form.value = { data_source_id: '11', rows_json: '[{"synthetic":"new-session"}]' };
  const current = view.submit();
  view.posts[0].resolve(success());
  await old;
  assert.equal(view.busy.value, true);
  assert.equal(view.result.value, null);
  view.posts[1].resolve(success(11));
  await current;
  assert.equal(view.busy.value, false);
  assert.equal(view.result.value.selected_data_source_id, 11);
});

test('a different returned selected source is not accepted as this source receipt', async () => {
  const view = harness();
  const pending = view.submit();
  view.posts[0].resolve(success(11));
  await pending;
  assert.equal(view.result.value, null);
  assert.ok(view.form.value.rows_json);
  assert.equal(view.refreshes.length, 0);
  assert.equal(view.notices[0][1], 'warning');
});

for (const [label, fields] of [['missing count', { saved_count: null }], ['bad count', { saved_count: true }], ['no exact readback', { readback_verified: false }]]) {
  test(`incomplete manual receipt retains the draft: ${label}`, async () => {
    const view = harness();
    const pending = view.submit();
    view.posts[0].resolve(success(10, fields));
    await pending;
    assert.ok(view.form.value.rows_json);
    if (label !== 'no exact readback') assert.equal(view.result.value.saved_count, null);
    assert.equal(view.notices[0][1], 'warning');
  });
}

test('the real manual receipt template keeps unknown persistence distinct from saved manual provenance', async () => {
  const template = readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');
  const from = template.indexOf('<div v-if="platformImportResult" data-testid="manual-import-truth-result"');
  const to = template.indexOf('</div>', from) + '</div>'.length;
  assert.ok(from >= 0 && to > from);
  const render = new Function('Vue', compile(template.slice(from, to), { mode: 'function', prefixIdentifiers: true }).code)(Vue);
  for (const confirmed of [false, true]) {
    const view = harness();
    const pending = view.submit();
    view.posts[0].resolve(success(10, { saved_count: 0, readback_verified: confirmed }));
    await pending;
    const html = await renderToString(Vue.createSSRApp({ render, data: () => ({
      platformImportResult: view.result.value, platformImportResultSummaryText: view.summary.value,
    }) }));
    assert.match(html, confirmed ? /已入库，但不等于已验证 OTA 事实/ : /保存结果未确认，原稿已保留/);
    assert.match(html, /入库 0 条/);
    assert.match(html, /用户提供 · 未验证/);
    if (!confirmed) assert.doesNotMatch(html, /已入库，但不等于/);
  }
});

for (const [status, code] of [['partial_success', 422], ['failed', 500]]) {
  test(`manual ${status} error receipts preserve saved counts and source readback without clearing the draft`, async () => {
    const view = harness();
    const pending = view.submit();
    const error = new Error('synthetic incomplete import');
    error.data = success(10, { status, saved_count: status === 'partial_success' ? 1 : 0, readback_verified: false, task_id: 101 });
    error.data.code = code;
    view.posts[0].reject(error);
    await pending;
    assert.equal(view.result.value?.saved_count, status === 'partial_success' ? 1 : 0);
    assert.equal(view.result.value?.selected_data_source_id, 10);
    assert.equal(view.result.value?.effective_import_source_id, 210);
    assert.equal(view.result.value?.import_status, status);
    assert.equal(view.result.value?.task_id, 101);
    assert.equal(view.result.value?.receipt_status, 'unknown');
    assert.ok(view.form.value.rows_json);
    assert.equal(view.busy.value, false);
    assert.deepEqual(view.refreshes, ['sources', 'rows']);
    assert.equal(view.posts.length, 1);
  });
}

test('an HTTP error cannot confirm a manual write even when its body falsely claims success', async () => {
  const view = harness();
  const pending = view.submit();
  const error = new Error('synthetic HTTP failure');
  error.status = 500;
  error.data = success();
  view.posts[0].reject(error);
  await pending;
  assert.ok(view.form.value.rows_json);
  assert.equal(view.result.value?.receipt_status, 'unknown');
  assert.equal(view.result.value?.import_status, 'unknown');
});

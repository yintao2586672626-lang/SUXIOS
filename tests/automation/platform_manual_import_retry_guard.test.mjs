import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { ref, computed, watch } from 'vue';
import * as Vue from 'vue';
import { compile } from '@vue/compiler-dom';
import { renderToString } from 'vue/server-renderer';

const source = readFileSync('public/app-main.js', 'utf8');
const template = readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');
const extract = (start, end) => {
  const from = source.indexOf(start), to = source.indexOf(end, from);
  assert.ok(from >= 0 && to > from, start);
  return source.slice(from, to);
};
const success = sourceId => ({ code: 200, data: {
  status: 'success', saved_count: 1, selected_data_source_id: sourceId,
  effective_import_source_id: sourceId + 200, import_provenance_status: 'user_provided_unverified',
  analysis_eligible_count: 0, readback_verified: true, task_id: 101,
} });

function harness() {
  const posts = [], notices = [], refreshes = [];
  const context = {
    ref, computed, watch,
    captureAuthSession: () => 1,
    isAuthSessionCurrent: value => value === 1,
    showToast: (...args) => notices.push(args),
    schedulePlatformDataSourcePanelLoad: () => refreshes.push('sources'),
    scheduleOnlineDataRefresh: () => refreshes.push('rows'),
    request: (url, options) => new Promise((resolve, reject) => posts.push({ url, body: JSON.parse(options.body), resolve, reject })),
  };
  const state = vm.runInNewContext(`(() => {
    ${extract('const platformDataImporting =', 'const browserAssistImporting =')}
    ${extract('const platformImportForm =', 'const browserAssistImportForm =')}
    ${extract('const importPlatformDataRowsFromText =', 'const readBrowserAssistCaptureFile =')}
    return {
      form: platformImportForm,
      result: platformImportResult,
      summary: platformImportResultSummaryText,
      busy: platformDataImporting,
      recovery: platformImportRecovery,
      recoveryText: platformImportRecoveryText,
      needsReview: platformImportRequiresHistoryReview,
      acknowledge: unlockManualImport,
      submit: importPlatformDataRowsFromText,
    };
  })()`, context);
  state.form.value = { data_source_id: '10', rows_json: '[{"synthetic":"manual OTA row","data_date":"2026-09-14"}]' };
  return { ...state, posts, notices, refreshes };
}

test('a lost manual import response keeps its source draft locked until history review is acknowledged', async () => {
  const view = harness();
  const pending = view.submit();
  assert.equal(view.posts.length, 1);
  assert.equal(view.posts[0].body.data_source_id, 10);
  view.posts[0].reject(new Error('synthetic socket reset after dispatch'));
  await pending;

  assert.equal(view.result.value?.receipt_status, 'unknown');
  assert.equal(view.result.value?.recovery_reason, 'outcome_unknown');
  assert.equal(view.result.value?.requested_data_source_id, 10);
  assert.equal(view.result.value?.selected_data_source_id, null);
  assert.equal(view.result.value?.saved_count, null);
  assert.match(view.summary.value, /服务器是否保存无法确认/);
  assert.equal(view.recovery.value, null);
  assert.equal(view.needsReview.value, true);
  assert.ok(view.form.value.rows_json, 'an uncertain write keeps the exact submitted draft');
  assert.equal(view.busy.value, false);
  assert.match(view.notices[0][0], /服务器是否保存无法确认/);

  await view.submit();
  assert.equal(view.posts.length, 1, 'an uncertain write cannot be blindly replayed');
  view.acknowledge();
  assert.equal(view.needsReview.value, false);
  assert.equal(view.result.value, null);
  assert.equal(view.posts.length, 1, 'history review acknowledgement does not auto-submit');

  const explicitRetry = view.submit();
  assert.equal(view.posts.length, 2);
  view.posts[1].resolve(success(10));
  await explicitRetry;
  assert.equal(view.result.value?.receipt_status, 'confirmed');
  assert.equal(view.form.value.rows_json, '', 'a confirmed readback clears only the reviewed draft');
});

test('a successful HTTP status without an import receipt is unknown and cannot be replayed blindly', async () => {
  const view = harness();
  const pending = view.submit();
  view.posts[0].resolve({ code: 200, data: {} });
  await pending;
  assert.equal(view.result.value?.receipt_status, 'unknown');
  assert.equal(view.result.value?.requested_data_source_id, 10);
  assert.equal(view.result.value?.selected_data_source_id, null);
  assert.equal(view.result.value?.saved_count, null);
  assert.equal(view.needsReview.value, true);
  assert.ok(view.form.value.rows_json);
  await view.submit();
  assert.equal(view.posts.length, 1);
});

test('an explicit HTTP client rejection without a partial receipt remains correctable', async () => {
  const view = harness();
  const rejected = view.submit();
  const error = new Error('文件格式错误。');
  error.status = 422;
  error.data = { code: 422, message: error.message };
  view.posts[0].reject(error);
  await rejected;
  assert.equal(view.needsReview.value, false);
  assert.equal(view.result.value, null);

  view.form.value.rows_json = '[{"synthetic":"corrected row"}]';
  const retry = view.submit();
  assert.equal(view.posts.length, 2);
  view.posts[1].resolve(success(10));
  await retry;
  assert.equal(view.result.value?.receipt_status, 'confirmed');
});

test('the manual import panel disables source and row edits while in flight or awaiting history review', async () => {
  const marker = template.indexOf('data-testid="import-review"');
  const from = template.lastIndexOf('<p', marker);
  const panel = template.slice(from, template.indexOf('</p>', from));
  assert.match(panel, /platformImportRecoveryText/);
  assert.match(template, /核对后解锁重试/);
  assert.match(template, /:disabled="platformImportBusy \|\| platformImportLocked"/);
  assert.match(template, /platformImportLocked \? '请先核对历史'/);
  const fragment = template.slice(from, template.indexOf('</p>', from) + '</p>'.length);
  const render = new Function('Vue', compile(fragment, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
  const html = await renderToString(Vue.createSSRApp({ render, data: () => ({
    platformImportRecovery: { reason: 'source_mismatch', requested_data_source_id: 10, returned_data_source_id: 11, task_id: null, saved_count: null, readback_count: null },
    platformImportRecoveryText: '来源回执不匹配：请求 #10，回执 #11，任务 #未返回；先核对任务历史。',
    platformDataImporting: false,
  }) }));
  assert.match(html, /来源回执不匹配/);
  assert.match(html, /请求 #10/);
  assert.match(html, /回执 #11/);
});

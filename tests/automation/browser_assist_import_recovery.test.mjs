import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { ref, computed, watch } from 'vue';

const source = readFileSync('public/app-main.js', 'utf8');
const extract = (start, end) => {
  const from = source.indexOf(start);
  const to = source.indexOf(end, from);
  assert.ok(from >= 0 && to > from, start);
  return source.slice(from, to);
};
const deferred = () => {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
};
const success = { code: 200, data: {
  status: 'success', source_contract: 'ota_browser_assist_collection_contract.v1', collection_mode: 'browser_assist_dom',
  saved_count: 1, packages: [{ platform: 'ctrip', data_type: 'traffic', saved_count: 1, status: 'success', readback_verified: true }], warnings: [],
} };
const harness = () => {
  const reads = [], posts = [], notices = [], refreshes = [];
  let session = 1;
  const context = {
    ref, computed, watch, console,
    captureAuthSession: () => session,
    isAuthSessionCurrent: value => value === session,
    showToast: (...args) => notices.push(args),
    request: (url, options) => {
      const pending = deferred();
      posts.push({ url, body: JSON.parse(options.body), ...pending });
      return pending.promise;
    },
    FileReader: class {
      constructor() { reads.push(this); }
      readAsText(file) { this.file = file; }
    },
    schedulePlatformDataSourcePanelLoad: () => refreshes.push('sources'),
    schedulePlatformCollectionStatusRefresh: () => refreshes.push('collection'),
    scheduleDataHealthPanelRefresh: () => refreshes.push('health'),
    scheduleOnlineDataRefresh: () => refreshes.push('rows'),
  };
  const formStart = source.indexOf('const browserAssistImportForm =');
  const formEnd = source.indexOf('const browserAssistImportWarnings =', formStart);
  const state = vm.runInNewContext(`(() => {
    ${extract('const browserAssistImporting =', 'const platformAccountCenterPlatform =')}
    ${source.slice(formStart, formEnd)}
    ${extract('const readBrowserAssistCaptureFile =', 'const platformSourceStatusClass =')}
    return { form: browserAssistImportForm, result: browserAssistImportResult,
      busy: browserAssistImporting, name: browserAssistImportFileName,
      submit: importBrowserAssistCaptureFromText, read: readBrowserAssistCaptureFile,
      clear: clearBrowserAssistImportForm };
  })()`, context);
  state.form.value = { system_hotel_id: '80', capture_json: '{"synthetic":"old"}' };
  return { ...state, reads, posts, notices, refreshes, changeSession: () => { session += 1; } };
};
const chooseFile = (view, name) => view.read({ target: { files: [{ name, size: 10 }], value: name } });
const finishFile = (reader, text) => { reader.result = text; reader.onload(); };

for (const outcome of ['success', 'failure']) {
  test(`an old import ${outcome} preserves a newer capture and its current hotel`, async () => {
    const view = harness();
    const pending = view.submit();
    assert.equal(view.posts[0].body.system_hotel_id, 80);
    assert.equal(view.posts[0].body.capture.synthetic, 'old');
    view.form.value.system_hotel_id = '81';
    view.form.value.capture_json = '{"synthetic":"new"}';
    view.name.value = 'new.json';
    if (outcome === 'success') view.posts[0].resolve(success);
    else view.posts[0].reject(new Error('synthetic old failure'));
    await pending;
    assert.equal(view.form.value.capture_json, '{"synthetic":"new"}');
    assert.equal(view.name.value, 'new.json');
    assert.equal(view.result.value, null);
    assert.equal(view.notices.length, 0);
    assert.equal(view.refreshes.length, 0);
    assert.equal(view.busy.value, false);
    const retry = view.submit();
    assert.equal(view.posts[1].body.system_hotel_id, 81);
    view.posts[1].resolve(success);
    await retry;
    assert.equal(view.form.value.capture_json, '');
    assert.equal(view.result.value.saved_count, 1);
    assert.equal(view.notices.length, 1);
  });
}

test('switching away and back invalidates old results, and a new draft clears a completed result', async () => {
  const view = harness();
  const pending = view.submit();
  view.form.value.system_hotel_id = '81';
  view.form.value.system_hotel_id = '80';
  view.posts[0].resolve(success);
  await pending;
  assert.equal(view.result.value, null);
  assert.equal(view.form.value.capture_json, '{"synthetic":"old"}');
  const retry = view.submit();
  view.posts[1].resolve(success);
  await retry;
  assert.equal(view.result.value.saved_count, 1);
  view.form.value.capture_json = '{"synthetic":"next"}';
  assert.equal(view.result.value, null);
});

test('repeated submit while the original write is pending sends only one request', async () => {
  const view = harness();
  const pending = view.submit();
  const duplicate = view.submit();
  assert.equal(view.posts.length, 1);
  view.posts[0].resolve(success);
  await Promise.all([pending, duplicate]);
  assert.equal(view.busy.value, false);
});

test('old session completion cannot clear a new session request busy state or draft', async () => {
  const view = harness();
  const old = view.submit();
  view.changeSession();
  view.busy.value = false;
  view.form.value = { system_hotel_id: '81', capture_json: '{"synthetic":"new-session"}' };
  const current = view.submit();
  view.posts[0].resolve(success);
  await old;
  assert.equal(view.busy.value, true);
  assert.equal(view.form.value.capture_json, '{"synthetic":"new-session"}');
  assert.equal(view.result.value, null);
  view.posts[1].resolve(success);
  await current;
  assert.equal(view.busy.value, false);
  assert.equal(view.result.value.saved_count, 1);
});

test('latest selected file wins when readers complete out of order', () => {
  const view = harness();
  chooseFile(view, 'old.json');
  chooseFile(view, 'new.json');
  finishFile(view.reads[1], '{"synthetic":"new-file"}');
  finishFile(view.reads[0], '{"synthetic":"old-file"}');
  assert.equal(view.name.value, 'new.json');
  assert.equal(view.form.value.capture_json, '{"synthetic":"new-file"}');
});

test('selecting a new file invalidates an old import before the new file has finished reading', async () => {
  const view = harness();
  const pending = view.submit();
  chooseFile(view, 'new.json');
  view.posts[0].resolve(success);
  await pending;
  finishFile(view.reads[0], '{"synthetic":"new-file"}');
  assert.equal(view.form.value.capture_json, '{"synthetic":"new-file"}');
  assert.equal(view.name.value, 'new.json');
  assert.equal(view.result.value, null);
  assert.equal(view.notices.length, 0);
});

test('current import and file failures preserve the draft and remain retryable', async () => {
  const view = harness();
  const pending = view.submit();
  view.posts[0].reject(new Error('synthetic save failure'));
  await pending;
  assert.equal(view.busy.value, false);
  assert.equal(view.form.value.capture_json, '{"synthetic":"old"}');
  assert.equal(view.notices.length, 1);
  assert.equal(view.notices[0][1], 'error');
  chooseFile(view, 'failed.json');
  view.reads[0].onerror();
  assert.equal(view.form.value.capture_json, '{"synthetic":"old"}');
  assert.equal(view.notices.length, 2);
  chooseFile(view, 'retry.json');
  finishFile(view.reads[1], '{"synthetic":"retry-file"}');
  const retry = view.submit();
  assert.equal(view.posts[1].body.capture.synthetic, 'retry-file');
  view.posts[1].resolve(success);
  await retry;
  assert.equal(view.result.value.saved_count, 1);
});

test('submit cannot send the previous capture while a selected file is still reading', async () => {
  const view = harness();
  chooseFile(view, 'new.json');
  await view.submit();
  assert.equal(view.posts.length, 0);
  assert.equal(view.notices[0][1], 'warning');
  finishFile(view.reads[0], '{"synthetic":"new-file"}');
  const pending = view.submit();
  assert.equal(view.posts[0].body.capture.synthetic, 'new-file');
  view.posts[0].resolve(success);
  await pending;
  assert.equal(view.result.value.saved_count, 1);
});

for (const action of ['clear', 'edit', 'hotel', 'session']) {
  test(`pending file read cannot overwrite after ${action}`, () => {
    const view = harness();
    chooseFile(view, 'pending.json');
    if (action === 'clear') view.clear();
    if (action === 'edit') view.form.value.capture_json = '{"synthetic":"typed"}';
    if (action === 'hotel') view.form.value.system_hotel_id = '81';
    if (action === 'session') view.changeSession();
    const expected = view.form.value.capture_json;
    finishFile(view.reads[0], '{"synthetic":"obsolete-file"}');
    view.reads[0].onerror();
    assert.equal(view.form.value.capture_json, expected);
    assert.equal(view.name.value, '');
    assert.equal(view.notices.length, 0);
  });
}

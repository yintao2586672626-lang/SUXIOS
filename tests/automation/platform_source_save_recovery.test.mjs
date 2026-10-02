import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import { ref, watch } from 'vue';

const source = readFileSync('public/app-main.js', 'utf8');
const extract = (start, end) => {
  const from = source.indexOf(start), to = source.indexOf(end, from);
  assert.ok(from >= 0 && to > from, start);
  return source.slice(from, to);
};
const draft = (id = '10', name = 'Synthetic source A') => ({
  id, name, system_hotel_id: '200', platform: 'custom', data_type: 'business',
  ingestion_method: 'manual', enabled: true, config_json: '{}', secret_json: '',
});
const harness = () => {
  const posts = [], notices = [], refreshes = [];
  let session = 1;
  const context = { ref, watch, captureAuthSession: () => session,
    isAuthSessionCurrent: value => value === session,
    parsePlatformJsonField: value => JSON.parse(value || '{}'),
    showToast: (...args) => notices.push(args),
    schedulePlatformDataSourcePanelLoad: options => refreshes.push(options),
    request: (url, options) => new Promise((resolve, reject) => posts.push({ url, body: JSON.parse(options.body), resolve, reject })),
  };
  const state = vm.runInNewContext(`(() => {
    const platformDataSourceError = ref('');
    ${extract('const platformDataSourceSaving =', 'const platformDataSourceSyncingId =')}
    ${extract('const defaultPlatformDataSourceForm =', 'const platformDataSourceConfigPlaceholder =')}
    ${extract('const resetPlatformDataSourceForm =', 'const loadPlatformDataSources =')}
    ${extract('const savePlatformDataSource =', 'const editPlatformDataSource =')}
    return { form: platformDataSourceForm, busy: platformDataSourceSaving,
      error: platformDataSourceError, submit: savePlatformDataSource, reset: resetPlatformDataSourceForm };
  })()`, context);
  state.form.value = draft();
  return { ...state, posts, notices, refreshes, changeSession: () => { session += 1; } };
};

for (const mode of ['replace', 'edit', 'away-and-back']) {
  for (const outcome of ['success', 'failure']) {
    test(`${mode}: an older source save ${outcome} preserves the later draft`, async () => {
      const view = harness();
      const pending = view.submit();
      if (mode === 'replace') view.form.value = draft('11', 'Synthetic B unsaved');
      if (mode === 'edit') view.form.value.name = 'Synthetic A revised';
      if (mode === 'away-and-back') {
        view.form.value.name = 'Temporary edit';
        view.form.value.name = 'Synthetic source A';
      }
      const expected = JSON.stringify(view.form.value);
      view.error.value = 'New draft validation';
      if (outcome === 'success') view.posts[0].resolve({ code: 200 });
      else view.posts[0].reject(new Error('Old request failure'));
      await pending;
      assert.equal(JSON.stringify(view.form.value), expected);
      assert.equal(view.error.value, 'New draft validation');
      assert.equal(view.notices.length, outcome === 'success' ? 1 : 0);
      assert.equal(view.refreshes.length, outcome === 'success' ? 1 : 0);
      if (outcome === 'success') assert.match(view.notices[0][0], /Synthetic source A.*已保存.*编辑内容已保留/);
      assert.equal(view.busy.value, false);
    });
  }
}

test('duplicate save makes one write, preserves failure input, and allows a retry', async () => {
  const view = harness();
  const pending = view.submit();
  const duplicate = view.submit();
  assert.equal(view.posts.length, 1);
  view.posts[0].resolve({ code: 422, message: 'Synthetic validation failure' });
  await Promise.all([pending, duplicate]);
  assert.equal(view.error.value, 'Synthetic validation failure');
  assert.equal(view.form.value.id, '10');
  assert.equal(view.busy.value, false);
  const retry = view.submit();
  assert.equal(view.posts[1].body.id, '10');
  assert.equal(view.posts[1].body.system_hotel_id, 200);
  assert.equal('secret' in view.posts[1].body, false);
  view.posts[1].resolve({ code: 200 });
  await retry;
  assert.equal(view.form.value.name, '');
  assert.equal(view.notices.length, 1);
  assert.equal(view.refreshes.length, 1);
});

for (const mode of ['edit-current', 'new-form', 'mismatched-receipt', 'array-id', 'array-hotel']) {
  test(`${mode}: a confirmed create ID is retained only for its original edited draft`, async () => {
    const view = harness();
    view.form.value = draft('', 'Synthetic new source');
    const pending = view.submit();
    if (mode === 'new-form') view.form.value = draft('', 'Another new source');
    else view.form.value.name = 'Synthetic revised source';
    const saved = { id: 51, system_hotel_id: mode === 'mismatched-receipt' ? 201 : 200,
      platform: 'custom', data_type: 'business' };
    if (mode === 'array-id') saved.id = [51];
    if (mode === 'array-hotel') saved.system_hotel_id = [200];
    view.posts[0].resolve({ code: 200, data: saved });
    await pending;
    assert.equal(view.form.value.id, mode === 'edit-current' ? 51 : '');
    assert.equal(view.form.value.name, mode === 'new-form' ? 'Another new source' : 'Synthetic revised source');
    assert.equal(view.refreshes.length, 1);
    if (mode === 'edit-current') {
      const update = view.submit();
      assert.equal(view.posts[1].body.id, 51, 're-saving the revised draft updates the newly created source');
      view.posts[1].resolve({ code: 200, data: saved });
      await update;
    }
  });
}

for (const outcome of ['success', 'failure']) {
  test(`old-session ${outcome} cannot clear or unlock the current save`, async () => {
    const view = harness();
    const old = view.submit();
    view.changeSession();
    view.busy.value = false;
    view.form.value = draft('11', 'New session source');
    const current = view.submit();
    if (outcome === 'success') view.posts[0].resolve({ code: 200 });
    else view.posts[0].reject(new Error('Old session failure'));
    await old;
    assert.equal(view.busy.value, true);
    assert.equal(view.form.value.id, '11');
    assert.equal(view.error.value, '');
    assert.equal(view.notices.length, 0);
    assert.equal(view.refreshes.length, 0);
    view.posts[1].resolve({ code: 200 });
    await current;
    assert.equal(view.busy.value, false);
    assert.equal(view.form.value.name, '');
    assert.equal(view.refreshes.length, 1);
  });
}

test('a current transport failure retains the draft and supports correcting malformed JSON', async () => {
  const view = harness();
  const pending = view.submit();
  view.posts[0].reject(new Error('Synthetic network failure'));
  await pending;
  assert.match(view.error.value, /network failure/);
  assert.equal(view.form.value.name, 'Synthetic source A');
  view.form.value.config_json = '{invalid';
  await view.submit();
  assert.equal(view.posts.length, 1);
  assert.equal(view.busy.value, false);
  view.form.value.config_json = '{"synthetic":true}';
  const retry = view.submit();
  assert.equal(view.posts[1].body.config.synthetic, true);
  view.posts[1].resolve({ code: 200 });
  await retry;
  assert.equal(view.form.value.name, '');
});

const listHarness = () => {
  const posts = [], notices = [], cache = new Map(), pending = new Map();
  let session = 1;
  const state = { platformDataSources: ref([]), platformDataSourceLoading: ref(false),
    platformDataSourceLoadFailed: ref(false), platformDataSourceSnapshotReady: ref(false),
    platformDataSourceLoadError: ref('') };
  const load = vm.runInNewContext(`(() => {
    ${extract('const loadPlatformDataSources =', 'const loadPlatformSyncTasks =')}
    return loadPlatformDataSources;
  })()`, { ...state, captureAuthSession: () => ({ epoch: session }),
    isAuthSessionCurrent: value => value.epoch === session,
    currentPage: ref('online-data'), normalizeRequestCacheOptions: value => value,
    currentPageReadPolicy: () => ({}), isPageLoadPolicyCurrent: () => true,
    platformDataSourcesRequestPromises: pending, platformDataSourcesResultCache: cache,
    readRequestCache: (map, key) => map.has(key), writeRequestCache: (map, key) => map.set(key, true),
    showToast: (...args) => notices.push(args),
    request: () => new Promise((resolve, reject) => posts.push({ resolve, reject })),
  });
  return { ...state, posts, notices, cache, pending, load, changeSession: () => { session += 1; } };
};

for (const outcome of ['success', 'failure']) {
  for (const order of ['old-first', 'new-first']) {
    test(`source list ${outcome}, ${order}: old reads cannot overwrite the refresh after saving`, async () => {
      const view = listHarness();
      const old = view.load({ force: true });
      const current = view.load({ force: true });
      const finishOld = () => outcome === 'success'
        ? view.posts[0].resolve({ code: 200, data: [{ id: 10, name: 'Old A' }] })
        : view.posts[0].reject(new Error('Old failure'));
      if (order === 'old-first') {
        finishOld();
        await old;
        assert.equal(view.platformDataSourceLoading.value, true);
        assert.deepEqual([...view.platformDataSources.value], []);
      }
      view.posts[1].resolve({ code: 200, data: [{ id: 10, name: 'Saved B' }] });
      await current;
      if (order === 'new-first') { finishOld(); await old; }
      assert.equal(view.platformDataSources.value[0].name, 'Saved B');
      assert.equal(view.platformDataSourceLoadFailed.value, false);
      assert.equal(view.platformDataSourceLoadError.value, '');
      assert.equal(view.platformDataSourceLoading.value, false);
      assert.equal(view.pending.size, 0);
      assert.equal(view.cache.size, 1);
      assert.equal(view.notices.length, 0);
    });
  }
}

test('source refresh still deduplicates reads and preserves a verified snapshot on current failure', async () => {
  const view = listHarness();
  const first = view.load({ cacheMs: 1000 });
  const joined = view.load({ cacheMs: 1000 });
  assert.equal(view.posts.length, 1);
  view.posts[0].resolve({ code: 200, data: [{ id: 10, name: 'Verified snapshot' }] });
  await Promise.all([first, joined]);
  await view.load({ cacheMs: 1000 });
  assert.equal(view.posts.length, 1);
  const refresh = view.load({ force: true });
  view.posts[1].reject(new Error('Current failure'));
  await refresh;
  assert.equal(view.platformDataSources.value[0].name, 'Verified snapshot');
  assert.equal(view.platformDataSourceSnapshotReady.value, true);
  assert.equal(view.platformDataSourceLoadFailed.value, true);
  assert.match(view.platformDataSourceLoadError.value, /Current failure/);
  assert.equal(view.cache.size, 0);
});

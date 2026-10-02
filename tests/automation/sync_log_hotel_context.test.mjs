import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/app-main.js', 'utf8');
const template = readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');

const sliceBetween = (start, end) => {
  const from = source.indexOf(start);
  const to = source.indexOf(end, from + start.length);
  assert.notEqual(from, -1, `missing start marker: ${start}`);
  assert.notEqual(to, -1, `missing end marker: ${end}`);
  return source.slice(from, to);
};

const scopeHelpers = sliceBetween(
  'const platformSyncLogIdentityText = (log = {}) =>',
  'const loadPlatformSyncHistoryPage = async (kind, options = {}) => {',
);

const pagerFactory = sliceBetween(
  'const createPlatformSyncHistoryPagerComponent = ',
  'const suxiRootComponent = ',
);

const syncLogLoaders = pagerFactory + scopeHelpers + sliceBetween(
  'const loadPlatformSyncHistoryPage = async (kind, options = {}) => {',
  'const loadPlatformCollectionResources = async (options = {}) => {',
);

const openHotelSyncLogs = sliceBetween(
  'const openHotelSyncLogs = async (hotel = {}, platform = \'\') => {',
  'const openHotelNextAction = async (hotel = {}) => {',
);

const ref = (value) => ({ value });

const createLoaderContext = (request) => {
  const taskCache = new Map();
  const logCache = new Map();
  const context = vm.createContext({
    URLSearchParams,
    platformSyncLogScope: ref(null),
    platformSyncTasks: ref([]),
    platformSyncLogs: ref([]),
    platformSyncTasksLoadFailed: ref(false),
    platformSyncLogsLoadFailed: ref(false),
    platformSyncTasksHasMore: ref(false),
    platformSyncLogsHasMore: ref(false),
    platformSyncTasksMoreLoading: ref(false),
    platformSyncLogsMoreLoading: ref(false),
    platformSyncTasksRefreshing: ref(false),
    platformSyncLogsRefreshing: ref(false),
    platformSyncTasksMoreError: ref(''),
    platformSyncLogsMoreError: ref(''),
    platformSyncHistoryPageSize: 20,
    platformSyncTasksResultCache: taskCache,
    platformSyncLogsResultCache: logCache,
    platformSyncTasksRequestPromises: new Map(),
    platformSyncLogsRequestPromises: new Map(),
    captureAuthSession: () => ({ epoch: 1 }),
    normalizeRequestCacheOptions: (options) => options,
    currentPageReadPolicy: () => ({}),
    isAuthSessionCurrent: () => true,
    isPageLoadPolicyCurrent: () => true,
    readRequestCache: (cache, key) => cache.has(key),
    writeRequestCache: (cache, key) => cache.set(key, true),
    request,
    currentPage: ref('online-data'),
    console: { error() {} },
  });
  vm.runInContext(`${syncLogLoaders}\nglobalThis.loadTasks = loadPlatformSyncTasks; globalThis.loadLogs = loadPlatformSyncLogs; globalThis.formatIdentity = platformSyncLogIdentityText; globalThis.setScope = setPlatformSyncLogScope; globalThis.createPager = createPlatformSyncHistoryPagerComponent;`, context);
  return context;
};

test('hotel sync-log entry fixes the selected hotel and platform before refreshing the panel', async () => {
  const refreshes = [];
  const context = vm.createContext({
    platformSyncLogScope: ref(null),
    platformSyncTasks: ref([]),
    platformSyncLogs: ref([]),
    platformSyncTasksLoadFailed: ref(false),
    platformSyncLogsLoadFailed: ref(false),
    platformSyncTasksHasMore: ref(false),
    platformSyncLogsHasMore: ref(false),
    platformSyncTasksMoreLoading: ref(false),
    platformSyncLogsMoreLoading: ref(false),
    platformSyncTasksRefreshing: ref(false),
    platformSyncLogsRefreshing: ref(false),
    platformSyncTasksMoreError: ref(''),
    platformSyncLogsMoreError: ref(''),
    prepareHotelPlatformAccountContext: async () => 101,
    currentPage: ref('dashboard'),
    nextTick: async () => {},
    openPlatformSourcesTab() {},
    schedulePlatformSyncLogPanelRefresh: (options) => refreshes.push(options),
    showToast() {},
  });
  vm.runInContext(`${scopeHelpers}${openHotelSyncLogs}\nglobalThis.openHotelSyncLogs = openHotelSyncLogs;`, context);

  await context.openHotelSyncLogs({ id: 101, name: 'Hotel A' }, 'ctrip');

  assert.deepEqual(JSON.parse(JSON.stringify(context.platformSyncLogScope.value)), {
    system_hotel_id: 101,
    platform: 'ctrip',
    hotel_name: 'Hotel A',
  });
  assert.equal(refreshes.length, 1);
  assert.equal(refreshes[0].force, true);
});

test('task and log cache keys and URLs follow the current hotel scope', async () => {
  const urls = [];
  const context = createLoaderContext(async (url) => {
    urls.push(url);
    return { code: 200, data: [] };
  });
  context.platformSyncLogScope.value = { system_hotel_id: 101, platform: 'ctrip' };
  await context.loadTasks({ cacheMs: 5000 });
  await context.loadLogs({ cacheMs: 5000 });
  context.platformSyncLogScope.value = { system_hotel_id: 102, platform: 'meituan' };
  await context.loadTasks({ cacheMs: 5000 });
  await context.loadLogs({ cacheMs: 5000 });

  assert.equal(urls.length, 4, 'changing hotel scope must not reuse another scope cache entry');
  assert.match(urls[0], /system_hotel_id=101/);
  assert.match(urls[0], /platform=ctrip/);
  assert.match(urls[1], /system_hotel_id=101/);
  assert.match(urls[2], /system_hotel_id=102/);
  assert.match(urls[2], /platform=meituan/);
  assert.match(urls[3], /system_hotel_id=102/);
});

test('generic sync panel remains limited by the existing API authorization with no hotel filter', async () => {
  const urls = [];
  const context = createLoaderContext(async (url) => {
    urls.push(url);
    return { code: 200, data: [] };
  });

  await context.loadTasks({ force: true });
  await context.loadLogs({ force: true });

  assert.deepEqual(urls, [
    '/online-data/sync-tasks?limit=21',
    '/online-data/sync-logs?limit=21',
  ]);
});

test('a previous hotel response cannot replace the current scoped task list', async () => {
  let resolveRequest;
  const context = createLoaderContext(() => new Promise((resolve) => { resolveRequest = resolve; }));
  context.platformSyncLogScope.value = { system_hotel_id: 101, platform: 'ctrip' };

  const pending = context.loadTasks({ force: true });
  context.platformSyncLogScope.value = { system_hotel_id: 102, platform: 'meituan' };
  resolveRequest({ code: 200, data: [{ id: 1, system_hotel_id: 101, platform: 'ctrip' }] });
  await pending;

  assert.deepEqual(JSON.parse(JSON.stringify(context.platformSyncTasks.value)), []);
  assert.equal(context.platformSyncTasksLoadFailed.value, false);
});

test('sync-log failures stay visible and a successful refresh recovers the panel', async () => {
  const responses = [
    { code: 503, data: null },
    { code: 200, data: [{ id: 8, system_hotel_id: 101, platform: 'ctrip' }] },
  ];
  const context = createLoaderContext(async () => responses.shift());
  context.platformSyncLogScope.value = { system_hotel_id: 101, platform: 'ctrip' };

  await context.loadTasks({ force: true });
  assert.equal(context.platformSyncTasksLoadFailed.value, true);
  assert.deepEqual(JSON.parse(JSON.stringify(context.platformSyncTasks.value)), []);
  await context.loadTasks({ force: true });
  assert.equal(context.platformSyncTasksLoadFailed.value, false);
  assert.deepEqual(JSON.parse(JSON.stringify(context.platformSyncTasks.value)), [
    { id: 8, system_hotel_id: 101, platform: 'ctrip' },
  ]);

  assert.match(template, /v-if="platformSyncLogScope"/);
  assert.match(template, /\{\{ platformSyncLogScopeText \}\}/);
  assert.match(template, /同步日志加载失败；请重试。未读取不代表没有历史记录。/);
  assert.match(template, /platformSyncLogIdentityText\(log\)/);
  assert.match(template, /酒店 #\{\{ task\.system_hotel_id \|\| '未关联' \}\}/);
  assert.equal(context.formatIdentity({ system_hotel_id: 101, platform: 'ctrip' }), '酒店 #101 · ctrip');
  assert.equal(context.formatIdentity({ system_hotel_id: 0, platform: '' }), '酒店 #未关联 · 平台未关联');
});

test('failed refresh keeps prior rows without claiming the selected history is fully read', async () => {
  const context = createLoaderContext(async () => ({ code: 503, message: 'synthetic refresh failure' }));
  context.platformSyncLogScope.value = { system_hotel_id: 101, platform: 'ctrip' };

  assert.match(template, /<Pager\s*\/>/);
  for (const [kind, loader, rowsRef, failedRef] of [
    ['task', context.loadTasks, context.platformSyncTasks, context.platformSyncTasksLoadFailed],
    ['log', context.loadLogs, context.platformSyncLogs, context.platformSyncLogsLoadFailed],
  ]) {
    const existingRows = [{ id: kind === 'task' ? 8 : 18, system_hotel_id: 101, platform: 'ctrip' }];
    rowsRef.value = existingRows;
    await loader({ force: true });

    assert.equal(failedRef.value, true);
    assert.deepEqual(JSON.parse(JSON.stringify(rowsRef.value)), existingRows);
  }
});

test('shared history pager renders both scoped states and invokes the corresponding older-page actions', () => {
  const context = createLoaderContext(async () => ({ code: 200, data: [] }));
  let pagerStates;
  const requested = [];
  const component = context.createPager(
    (type, props, children) => ({ type, props, children }),
    () => ({ tasks: () => pagerStates.tasks, logs: () => pagerStates.logs }),
  );
  const render = (states) => { pagerStates = states; return component.setup()(); };

  const active = render({
    logs: { hasMore: true, loading: false, refreshing: false, itemLabel: '日志', load: (options) => requested.push(options) },
    tasks: { hasMore: true, loading: false, refreshing: false, itemLabel: '任务', load: (options) => requested.push(options) },
  });
  assert.equal(active.type, 'div');
  assert.deepEqual(JSON.parse(JSON.stringify(active.children.map((node) => node.children))), ['读取较早日志', '读取较早任务']);
  active.children.forEach((button) => button.props.onClick());
  assert.deepEqual(JSON.parse(JSON.stringify(requested)), [{ append: true }, { append: true }]);

  const recovering = render({
    logs: { hasMore: true, error: '较早日志加载失败：synthetic', itemLabel: '日志' },
    tasks: { hasMore: true, loading: true, refreshing: true, itemLabel: '任务' },
  });
  assert.deepEqual(JSON.parse(JSON.stringify(recovering.children.map((node) => node.children))), ['读取失败，点击重试', '正在读取较早任务...']);
  assert.equal(recovering.children[0].props.title, '较早日志加载失败：synthetic');
  assert.equal(recovering.children[1].props.disabled, true);

  const exhausted = render({
    logs: { hasRows: true, itemLabel: '日志' },
    tasks: { hasRows: true, loadFailed: true, itemLabel: '任务' },
  });
  assert.equal(exhausted.children.length, 1);
  assert.equal(exhausted.children[0].children, '当前范围日志已读完。');
  assert.equal(render({ logs: {}, tasks: {} }), null);
});

test('older task and log pages carry keyset cursors and keep the selected hotel/platform', async () => {
  const urls = [];
  const rows = [
    Array.from({ length: 21 }, (_, index) => ({ id: 200 - index, system_hotel_id: 101, platform: 'ctrip' })),
    Array.from({ length: 21 }, (_, index) => ({ id: 180 - index, system_hotel_id: 101, platform: 'ctrip' })),
    Array.from({ length: 21 }, (_, index) => ({ id: 500 - index, system_hotel_id: 101, platform: 'ctrip' })),
    Array.from({ length: 21 }, (_, index) => ({ id: 480 - index, system_hotel_id: 101, platform: 'ctrip' })),
  ];
  const context = createLoaderContext(async (url) => {
    urls.push(url);
    return { code: 200, data: rows.shift() };
  });
  context.platformSyncLogScope.value = { system_hotel_id: 101, platform: 'ctrip' };

  await context.loadTasks({ force: true });
  await context.loadTasks({ append: true });
  await context.loadLogs({ force: true });
  await context.loadLogs({ append: true });

  assert.match(urls[1], /before_id=181/);
  assert.match(urls[1], /system_hotel_id=101/);
  assert.match(urls[1], /platform=ctrip/);
  assert.match(urls[3], /before_id=481/);
  assert.match(urls[3], /system_hotel_id=101/);
  assert.match(urls[3], /platform=ctrip/);
  assert.equal(context.platformSyncTasks.value.length, 40);
  assert.equal(context.platformSyncLogs.value.length, 40);
});

test('sync history page boundary hides older-page action when exactly one page exists', async () => {
  const urls = [];
  const rows = Array.from({ length: 20 }, (_, index) => ({ id: 320 - index, system_hotel_id: 101, platform: 'ctrip' }));
  const context = createLoaderContext(async (url) => {
    urls.push(url);
    return { code: 200, data: rows };
  });
  context.platformSyncLogScope.value = { system_hotel_id: 101, platform: 'ctrip' };

  await context.loadTasks({ force: true });

  assert.equal(context.platformSyncTasks.value.length, 20);
  assert.equal(context.platformSyncTasksHasMore.value, false);
  assert.match(urls[0], /limit=21/);
});

test('sync history lookahead preserves the sentinel as the next visible row without skipping it', async () => {
  const urls = [];
  const firstPage = Array.from({ length: 21 }, (_, index) => ({ id: 400 - index, system_hotel_id: 102, platform: 'meituan' }));
  const finalPage = [{ id: 380, system_hotel_id: 102, platform: 'meituan' }];
  const responses = [firstPage, finalPage];
  const context = createLoaderContext(async (url) => {
    urls.push(url);
    return { code: 200, data: responses.shift() };
  });
  context.platformSyncLogScope.value = { system_hotel_id: 102, platform: 'meituan' };

  await context.loadLogs({ force: true });
  assert.equal(context.platformSyncLogs.value.length, 20);
  assert.equal(context.platformSyncLogs.value[context.platformSyncLogs.value.length - 1].id, 381);
  assert.equal(context.platformSyncLogsHasMore.value, true);

  await context.loadLogs({ append: true });
  assert.equal(context.platformSyncLogs.value.length, 21);
  assert.equal(context.platformSyncLogs.value[context.platformSyncLogs.value.length - 1].id, 380);
  assert.equal(context.platformSyncLogsHasMore.value, false);
  assert.match(urls[0], /limit=21/);
  assert.match(urls[1], /limit=21/);
  assert.match(urls[1], /before_id=381/);
  assert.match(urls[1], /system_hotel_id=102/);
  assert.match(urls[1], /platform=meituan/);
});

test('failed older task page preserves visible history and retries from the same cursor', async () => {
  const urls = [];
  const firstPage = Array.from({ length: 21 }, (_, index) => ({ id: 120 - index, system_hotel_id: 101, platform: 'meituan' }));
  const olderPage = Array.from({ length: 21 }, (_, index) => ({ id: 100 - index, system_hotel_id: 101, platform: 'meituan' }));
  const responses = [
    { code: 200, data: firstPage },
    { code: 503, message: 'synthetic older-page failure' },
    { code: 200, data: olderPage },
  ];
  const context = createLoaderContext(async (url) => {
    urls.push(url);
    return responses.shift();
  });
  context.platformSyncLogScope.value = { system_hotel_id: 101, platform: 'meituan' };

  await context.loadTasks({ force: true });
  await context.loadTasks({ append: true });
  assert.equal(context.platformSyncTasks.value.length, 20);
  assert.match(context.platformSyncTasksMoreError.value, /较早任务|older-page failure/);
  assert.equal(context.platformSyncTasksLoadFailed.value, false);

  await context.loadTasks({ append: true });
  assert.equal(context.platformSyncTasks.value.length, 40);
  assert.equal(context.platformSyncTasksMoreError.value, '');
  assert.match(urls[1], /before_id=101/);
  assert.equal(urls[1], urls[2]);
  assert.match(template, /<Pager\s*\/>/);
});

test('switching hotel while an older page is pending clears and rejects the prior scope', async () => {
  let resolveOlderPage;
  const firstPage = Array.from({ length: 21 }, (_, index) => ({ id: 90 - index, system_hotel_id: 101, platform: 'ctrip' }));
  const context = createLoaderContext((url) => {
    if (url.includes('before_id=')) return new Promise((resolve) => { resolveOlderPage = resolve; });
    return Promise.resolve({ code: 200, data: firstPage });
  });
  context.platformSyncLogScope.value = { system_hotel_id: 101, platform: 'ctrip' };
  await context.loadLogs({ force: true });
  const oldScopeLoad = context.loadLogs({ append: true });

  context.setScope({ system_hotel_id: 102, platform: 'meituan' });
  resolveOlderPage({ code: 200, data: [{ id: 70, system_hotel_id: 101, platform: 'ctrip' }] });
  await oldScopeLoad;

  assert.deepEqual(JSON.parse(JSON.stringify(context.platformSyncLogs.value)), []);
  assert.equal(context.platformSyncLogsHasMore.value, false);
  assert.equal(context.platformSyncLogsMoreError.value, '');
  assert.equal(context.platformSyncLogsMoreLoading.value, false);
});

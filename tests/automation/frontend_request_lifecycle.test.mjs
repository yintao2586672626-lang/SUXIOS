import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/app-main.js', 'utf8');

const sliceBetween = (start, end, { includeEnd = false } = {}) => {
  const startIndex = source.indexOf(start);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert.notEqual(startIndex, -1, `missing start marker: ${start}`);
  assert.notEqual(endIndex, -1, `missing end marker: ${end}`);
  return source.slice(startIndex, endIndex + (includeEnd ? end.length : 0));
};

const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return { promise, resolve, reject };
};

const abortError = (message = 'Page request was cancelled') => {
  const error = new Error(message);
  error.name = 'AbortError';
  return error;
};

const jsonResponse = (data = { code: 200, data: {} }) => ({
  ok: true,
  status: 200,
  json: async () => data,
});

const flushCoordinator = async () => {
  for (let index = 0; index < 8; index += 1) {
    await Promise.resolve();
  }
};

const compileCoordinator = (fetchImpl) => {
  const coordinatorSource = sliceBetween(
    'const COORDINATED_GET_MAX_CONCURRENCY = 3;',
    'const apiRequest = request;',
    { includeEnd: true },
  );
  const context = vm.createContext({
    AbortController,
    API_BASE: '/api',
    Date,
    Headers,
    JSON,
    Map,
    Math,
    Object,
    Promise,
    String,
    URL,
    URLSearchParams,
    authContext: { value: { tenantId: 'tenant-a', hotelId: '80' } },
    captureAuthSession: () => ({ epoch: 7, token: 'token-a' }),
    clearAuthSessionIfCurrent: () => false,
    console: { error() {}, warn() {} },
    createRequestAbortError: abortError,
    currentPage: { value: 'compass' },
    fetch: fetchImpl,
    filterReportHotel: { value: '80' },
    isAuthSessionCurrent: () => true,
    isTerminalAuthFailureResponse: () => false,
    normalizeTokenStatusFromReason: value => value,
    pageRequestGeneration: 3,
    showToast() {},
    structuredClone,
    terminalAuthFailureReason: () => '',
    token: { value: 'token-a' },
    user: { value: { hotel_id: 80 } },
    withBusinessRequestContext: (url, options) => ({ url, options }),
    applyAuthContext() {},
  });
  vm.runInContext(`${coordinatorSource}\n    globalThis.__coordinator = {
      request,
      cancelPageGetConsumers,
      resetGetRequestCoordinator,
      coordinatedGetRequests,
      coordinatedGetQueue,
    };`, context);
  return { context, ...context.__coordinator };
};

const compilePageLoadHarness = () => {
  const pageLoadSource = sliceBetween(
    'const runPageLoadOnce = (page, loadingKey, task, options = {}) => {',
    'const activateCoreOperationsAfterLogin = () => {',
  );
  const context = vm.createContext({
    Date,
    Map,
    Number,
    PAGE_LOAD_DEDUP_MS: 2000,
    Promise,
    authSessionEpoch: 11,
    console: { error() {} },
    lastLoadedPage: '',
    lastLoadedPageAt: 0,
    pageLoadRequests: new Map(),
    pageRequestGeneration: 5,
  });
  vm.runInContext(`${pageLoadSource}\n    globalThis.__pageLoad = {
      runPageLoadOnce,
      pageLoadRequests,
      getLastLoadedPage: () => lastLoadedPage,
      getLastLoadedPageAt: () => lastLoadedPageAt,
      bumpLifecycle: () => { pageRequestGeneration += 1; },
    };`, context);
  return context.__pageLoad;
};

test('same in-flight GET shares one fetch and resolves every consumer', async () => {
  const network = deferred();
  const calls = [];
  const coordinator = compileCoordinator((url, options) => {
    calls.push({ url, options });
    return network.promise;
  });

  const first = coordinator.request('/dashboard?hotel_id=80', {
    requestPolicy: { scope: 'page', pageKey: 'compass', pageGeneration: 3 },
  });
  const second = coordinator.request('/dashboard?hotel_id=80', {
    requestPolicy: { scope: 'page', pageKey: 'compass', pageGeneration: 3 },
  });
  await flushCoordinator();

  assert.equal(calls.length, 1);
  network.resolve(jsonResponse({ code: 200, data: { hotel_id: 80 } }));
  const [firstResult, secondResult] = await Promise.all([first, second]);
  assert.deepEqual(firstResult, { code: 200, data: { hotel_id: 80 } });
  assert.deepEqual(secondResult, firstResult);
  assert.notEqual(firstResult, secondResult, 'each consumer should receive an isolated response value');
});

test('normalized query order deduplicates while hotel and date scope stay isolated', async () => {
  const pendingByUrl = new Map();
  const calls = [];
  const coordinator = compileCoordinator((url) => {
    calls.push(url);
    const pending = deferred();
    pendingByUrl.set(url, pending);
    return pending.promise;
  });

  const sameScopeA = coordinator.request('/dashboard?target_date=2026-07-30&hotel_id=80');
  const sameScopeB = coordinator.request('/dashboard?hotel_id=80&target_date=2026-07-30');
  const otherHotel = coordinator.request('/dashboard?hotel_id=81&target_date=2026-07-30');
  const otherDate = coordinator.request('/dashboard?hotel_id=80&target_date=2026-07-29');
  await flushCoordinator();

  assert.equal(calls.length, 3);
  calls.forEach((url) => pendingByUrl.get(url).resolve(jsonResponse({ code: 200, data: { url } })));
  await Promise.all([sameScopeA, sameScopeB, otherHotel, otherDate]);
});

test('successful TTL reuse and force refresh never cache failed business responses', async () => {
  const responses = [
    { code: 200, data: { generation: 1 } },
    { code: 200, data: { generation: 2 } },
    { code: 503, message: 'temporary failure' },
    { code: 200, data: { generation: 4 } },
  ];
  let calls = 0;
  const coordinator = compileCoordinator(async () => {
    const data = responses[calls];
    calls += 1;
    return jsonResponse(data);
  });

  const policy = { ttlMs: 60_000 };
  assert.equal((await coordinator.request('/ttl-success', { requestPolicy: policy })).data.generation, 1);
  assert.equal((await coordinator.request('/ttl-success', { requestPolicy: policy })).data.generation, 1);
  assert.equal(calls, 1);
  assert.equal((await coordinator.request('/ttl-success', {
    requestPolicy: { ...policy, force: true },
  })).data.generation, 2);
  assert.equal(calls, 2);

  assert.equal((await coordinator.request('/ttl-failure', { requestPolicy: policy })).code, 503);
  assert.equal((await coordinator.request('/ttl-failure', { requestPolicy: policy })).data.generation, 4);
  assert.equal(calls, 4);
});

test('cancelling one page consumer preserves a shared session consumer', async () => {
  const network = deferred();
  let networkSignal;
  const coordinator = compileCoordinator((_url, options) => {
    networkSignal = options.signal;
    return network.promise;
  });

  const pageConsumer = coordinator.request('/shared-read?hotel_id=80', {
    requestPolicy: { scope: 'page', pageKey: 'compass', pageGeneration: 3 },
  });
  const sessionConsumer = coordinator.request('/shared-read?hotel_id=80', {
    requestPolicy: { scope: 'session', priority: 'prewarm' },
  });
  await flushCoordinator();
  coordinator.cancelPageGetConsumers('compass');

  await assert.rejects(pageConsumer, error => error?.name === 'AbortError');
  assert.equal(networkSignal.aborted, false);
  network.resolve(jsonResponse({ code: 200, data: { shared: true } }));
  assert.deepEqual(await sessionConsumer, { code: 200, data: { shared: true } });
});

test('queued current and action reads run before prewarm and notification reads', async () => {
  const pendingByUrl = new Map();
  const calls = [];
  const coordinator = compileCoordinator((url) => {
    calls.push(url);
    const pending = deferred();
    pendingByUrl.set(url, pending);
    return pending.promise;
  });
  const requests = [1, 2, 3].map(index => coordinator.request(`/blocking-${index}`));
  const notification = coordinator.request('/queued-notification', {
    requestPolicy: { priority: 'notification' },
  });
  const action = coordinator.request('/queued-action', {
    requestPolicy: { priority: 'action' },
  });
  await flushCoordinator();
  assert.deepEqual(calls, ['/api/blocking-1', '/api/blocking-2', '/api/blocking-3']);

  pendingByUrl.get('/api/blocking-1').resolve(jsonResponse());
  await flushCoordinator();
  assert.equal(calls[3], '/api/queued-action');
  pendingByUrl.get('/api/blocking-2').resolve(jsonResponse());
  await flushCoordinator();
  assert.equal(calls[4], '/api/queued-notification');

  for (const pending of pendingByUrl.values()) pending.resolve(jsonResponse());
  await Promise.all([...requests, action, notification]);
});

test('POST bypasses GET coordination and fetches once per call', async () => {
  const calls = [];
  const coordinator = compileCoordinator(async (url, options) => {
    calls.push({ url, options });
    return jsonResponse({ code: 200, data: { accepted: true } });
  });

  await Promise.all([
    coordinator.request('/notifications/read', { method: 'POST', body: '{"ids":[1]}' }),
    coordinator.request('/notifications/read', { method: 'POST', body: '{"ids":[1]}' }),
  ]);

  assert.equal(calls.length, 2);
  assert.equal(coordinator.coordinatedGetRequests.size, 0);
  assert.equal(coordinator.coordinatedGetQueue.length, 0);
});

test('request policy and business-only options never reach fetch', async () => {
  let capturedOptions;
  const coordinator = compileCoordinator(async (_url, options) => {
    capturedOptions = options;
    return jsonResponse();
  });

  await coordinator.request('/dashboard?hotel_id=80', {
    requestPolicy: {
      scope: 'page',
      pageKey: 'compass',
      pageGeneration: 3,
      priority: 'current',
    },
    withBusinessContext: false,
    businessContext: { hotelId: 80, businessDate: '2026-07-30' },
    cache: 'no-store',
  });

  assert.equal(Object.hasOwn(capturedOptions, 'requestPolicy'), false);
  assert.equal(Object.hasOwn(capturedOptions, 'withBusinessContext'), false);
  assert.equal(Object.hasOwn(capturedOptions, 'businessContext'), false);
  assert.equal(capturedOptions.cache, 'no-store');
  assert.ok(capturedOptions.signal instanceof AbortSignal);
});

test('cancelling the only page consumer rejects with AbortError and aborts fetch', async () => {
  let networkSignal;
  const coordinator = compileCoordinator((_url, options) => {
    networkSignal = options.signal;
    return new Promise((resolve, reject) => {
      options.signal.addEventListener('abort', () => reject(abortError()), { once: true });
    });
  });

  const consumer = coordinator.request('/macro-signals/overview?hotel_id=80', {
    requestPolicy: { scope: 'page', pageKey: 'compass', pageGeneration: 3 },
  });
  await flushCoordinator();
  assert.equal(networkSignal.aborted, false);

  coordinator.cancelPageGetConsumers('compass');

  await assert.rejects(consumer, error => error?.name === 'AbortError');
  assert.equal(networkSignal.aborted, true);
  assert.equal(coordinator.coordinatedGetRequests.size, 0);
});

test('runPageLoadOnce caches only successful work', async () => {
  const successHarness = compilePageLoadHarness();
  let successRuns = 0;
  await successHarness.runPageLoadOnce('compass', 'main', async () => {
    successRuns += 1;
    return true;
  });
  const successEntry = successHarness.pageLoadRequests.get('11:compass:main');
  assert.ok(successEntry?.loadedAt > 0);
  await successHarness.runPageLoadOnce('compass', 'main', async () => {
    successRuns += 1;
    return true;
  });
  assert.equal(successRuns, 1);
  assert.equal(successHarness.getLastLoadedPage(), '11:compass:main');
  assert.ok(successHarness.getLastLoadedPageAt() > 0);

  const falseHarness = compilePageLoadHarness();
  let falseRuns = 0;
  assert.equal(await falseHarness.runPageLoadOnce('compass', 'main', async () => {
    falseRuns += 1;
    return false;
  }), false);
  assert.equal(falseHarness.pageLoadRequests.has('11:compass:main'), false);
  await falseHarness.runPageLoadOnce('compass', 'main', async () => {
    falseRuns += 1;
    return false;
  });
  assert.equal(falseRuns, 2);

  const throwHarness = compilePageLoadHarness();
  let throwRuns = 0;
  assert.equal(await throwHarness.runPageLoadOnce('compass', 'main', async () => {
    throwRuns += 1;
    throw new Error('expected failure');
  }), false);
  assert.equal(throwHarness.pageLoadRequests.has('11:compass:main'), false);
  await throwHarness.runPageLoadOnce('compass', 'main', async () => {
    throwRuns += 1;
    throw new Error('expected failure');
  });
  assert.equal(throwRuns, 2);

  const lifecycleHarness = compilePageLoadHarness();
  const pending = deferred();
  let lifecycleRuns = 0;
  const staleRun = lifecycleHarness.runPageLoadOnce('compass', 'main', () => {
    lifecycleRuns += 1;
    return pending.promise;
  });
  await Promise.resolve();
  lifecycleHarness.bumpLifecycle();
  pending.resolve(true);
  assert.equal(await staleRun, true);
  assert.equal(lifecycleHarness.pageLoadRequests.has('11:compass:main'), false);
  await lifecycleHarness.runPageLoadOnce('compass', 'main', async () => {
    lifecycleRuns += 1;
    return true;
  });
  assert.equal(lifecycleRuns, 2);
});

test('compass background queue excludes Revenue AI and competitor summary reads', () => {
  const compassLoader = sliceBetween(
    'const loadCompassData = async (options = {}) => {',
    'const refreshCompassDashboard = async () => {',
  );
  const queueStart = compassLoader.indexOf('const compassBackgroundJobs = [');
  const queueEnd = compassLoader.indexOf('for (const job of compassBackgroundJobs)', queueStart);
  assert.ok(queueStart >= 0 && queueEnd > queueStart, 'missing compass background queue');
  const queue = compassLoader.slice(queueStart, queueEnd);

  assert.match(queue, /loadMacroSignals\(\)/);
  assert.doesNotMatch(queue, /loadRevenueAiOverview\(\)/);
  assert.doesNotMatch(queue, /loadCompetitorSummary\(/);
});

test('page switch clears delayed work before any full-render early return', () => {
  const watcher = sliceBetween(
    'watch(currentPage, (newPage) => {',
    'watch(isLoggedIn, (loggedIn) => {',
  );
  const earlyReturn = watcher.indexOf('if (requestSuxiFullRenderForPage(newPage)) return;');
  assert.ok(earlyReturn > 0, 'missing full-render early return');
  for (const cleanup of [
    'clearPageLifecycleTimers();',
    'cancelPageGetConsumers(previousPage);',
    'clearHomeSecondaryPanelsReadyTimer();',
    'clearDualOtaSystemMetricDrilldownHydrationTimer();',
    'clearManualOnlineFetchConfigPrewarmTimer();',
    'clearDataHealthSecondaryPanelsReadyTimer();',
    'clearCtripEbookingModuleCardsReadyTimer();',
  ]) {
    const cleanupIndex = watcher.indexOf(cleanup);
    assert.ok(cleanupIndex >= 0 && cleanupIndex < earlyReturn, `${cleanup} must run before the early return`);
  }
});

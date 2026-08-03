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
  const contextFilterHotel = { value: '80' };
  const contextBusinessDate = { value: '' };
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
    currentPageReadPolicy: (pageKey = 'compass', priority = 'current') => ({
      scope: 'page',
      pageKey,
      pageGeneration: 3,
      priority,
      sessionEpoch: 7,
      tenantId: 'tenant-a',
      systemHotelId: contextFilterHotel.value,
      businessDate: contextBusinessDate.value,
    }),
    fetch: fetchImpl,
    filterReportHotel: contextFilterHotel,
    isAuthSessionCurrent: () => true,
    isPageLoadPolicyCurrent: (policy = {}) => {
      if (Number(policy.sessionEpoch ?? 7) !== 7) return false;
      if (policy.scope === 'page'
          && (Number(policy.pageGeneration ?? 3) !== 3
            || String(policy.pageKey || '') !== 'compass')) {
        return false;
      }
      return !policy.systemHotelId
        || String(policy.systemHotelId) === String(contextFilterHotel.value);
    },
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
      setHotel: (hotelId) => { filterReportHotel.value = String(hotelId || ''); },
    };`, context);
  return { context, ...context.__coordinator };
};

const compilePageLoadHarness = () => {
  const pageLoadSource = sliceBetween(
    'const currentPageReadPolicy = (pageKey = currentPage.value, priority = \'current\') => {',
    'const activateCoreOperationsAfterLogin = () => {',
  );
  const currentPage = { value: 'compass' };
  const filterReportHotel = { value: '80' };
  const authContext = { value: { tenantId: 'tenant-a', hotelId: '80' } };
  const context = vm.createContext({
    authContext,
    Date,
    filterReportHotel,
    Map,
    Number,
    PAGE_LOAD_DEDUP_MS: 2000,
    Promise,
    authSessionEpoch: 11,
    console: { error() {} },
    coreOperationsTargetDate: { value: '2026-08-02' },
    currentBusinessRequestContext: () => ({ tenant_id: 'tenant-a', system_hotel_id: filterReportHotel.value }),
    currentPage,
    lastLoadedPage: '',
    lastLoadedPageAt: 0,
    pageLoadRequests: new Map(),
    pageRequestGeneration: 5,
    revenueAiBusinessDate: { value: '2026-08-02' },
  });
  vm.runInContext(`${pageLoadSource}\n    globalThis.__pageLoad = {
      runPageLoadOnce,
      cancelPageLoadRequests,
      currentCompassReadPolicy,
      pageLoadRequests,
      getLastLoadedPage: () => lastLoadedPage,
      getLastLoadedPageAt: () => lastLoadedPageAt,
      bumpLifecycle: () => { pageRequestGeneration += 1; },
      navigateTo: (page) => { currentPage.value = String(page || ''); pageRequestGeneration += 1; },
      setBusinessDate: (date) => { revenueAiBusinessDate.value = String(date || ''); },
      setHotel: (hotelId) => { filterReportHotel.value = String(hotelId || ''); },
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

test('a TTL cache hit is rejected after the active hotel changes', async () => {
  let calls = 0;
  const coordinator = compileCoordinator(async () => {
    calls += 1;
    return jsonResponse({ code: 200, data: { hotel_id: 80 } });
  });
  const policy = {
    scope: 'page',
    pageKey: 'compass',
    pageGeneration: 3,
    sessionEpoch: 7,
    tenantId: 'tenant-a',
    systemHotelId: '80',
    ttlMs: 60_000,
  };

  assert.equal((await coordinator.request('/ttl-hotel?hotel_id=80', { requestPolicy: policy })).code, 200);
  coordinator.setHotel('81');
  await assert.rejects(
    coordinator.request('/ttl-hotel?hotel_id=80', { requestPolicy: policy }),
    error => error?.name === 'AbortError',
  );
  assert.equal(calls, 1, 'stale cache rejection must not start another network request');
});

test('force refresh supersedes an older in-flight GET instead of reusing it', async () => {
  const pending = [];
  const signals = [];
  const coordinator = compileCoordinator((_url, options) => {
    const network = deferred();
    pending.push(network);
    signals.push(options.signal);
    options.signal.addEventListener('abort', () => network.reject(abortError()), { once: true });
    return network.promise;
  });

  const first = coordinator.request('/force-refresh?hotel_id=80', {
    requestPolicy: { scope: 'page', pageKey: 'compass', pageGeneration: 3, systemHotelId: '80' },
  });
  await flushCoordinator();
  const second = coordinator.request('/force-refresh?hotel_id=80', {
    requestPolicy: {
      scope: 'page',
      pageKey: 'compass',
      pageGeneration: 3,
      systemHotelId: '80',
      force: true,
    },
  });

  await assert.rejects(first, error => error?.name === 'AbortError');
  await flushCoordinator();
  assert.equal(signals.length, 2);
  assert.equal(signals[0].aborted, true);
  assert.equal(signals[1].aborted, false);
  pending[1].resolve(jsonResponse({ code: 200, data: { generation: 2 } }));
  assert.equal((await second).data.generation, 2);
});

test('a page GET is rejected when its selected hotel changes before completion', async () => {
  const network = deferred();
  const coordinator = compileCoordinator(() => network.promise);
  const requestPromise = coordinator.request('/hotel-snapshot?hotel_id=80', {
    requestPolicy: {
      scope: 'page',
      pageKey: 'compass',
      pageGeneration: 3,
      tenantId: 'tenant-a',
      systemHotelId: '80',
    },
  });
  await flushCoordinator();
  coordinator.setHotel('81');
  network.resolve(jsonResponse({ code: 200, data: { hotel_id: 80 } }));
  await assert.rejects(requestPromise, error => error?.name === 'AbortError');
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

test('background reads use one slot so a new current-page read can start immediately', async () => {
  const pendingByUrl = new Map();
  const calls = [];
  const coordinator = compileCoordinator((url) => {
    calls.push(url);
    const network = deferred();
    pendingByUrl.set(url, network);
    return network.promise;
  });

  const background = [1, 2, 3].map(index => coordinator.request(`/prewarm-${index}`, {
    requestPolicy: { scope: 'session', priority: 'prewarm' },
  }));
  const current = coordinator.request('/current-page', {
    requestPolicy: { scope: 'page', pageKey: 'compass', pageGeneration: 3, priority: 'current' },
  });
  await flushCoordinator();

  assert.equal(calls.includes('/api/current-page'), true);
  assert.equal(calls.filter(url => url.includes('/api/prewarm-')).length, 1);
  pendingByUrl.get('/api/current-page').resolve(jsonResponse());
  pendingByUrl.get('/api/prewarm-1').resolve(jsonResponse());
  await Promise.all([current, background[0]]);
  await flushCoordinator();
  assert.equal(calls.at(-1), '/api/prewarm-2');
  pendingByUrl.get('/api/prewarm-2').resolve(jsonResponse());
  await background[1];
  await flushCoordinator();
  assert.equal(calls.at(-1), '/api/prewarm-3');
  pendingByUrl.get('/api/prewarm-3').resolve(jsonResponse());
  await Promise.all([...background, current]);
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

test('an authenticated GET without an explicit policy defaults to the current page lifecycle', async () => {
  let networkSignal;
  const coordinator = compileCoordinator((_url, options) => {
    networkSignal = options.signal;
    return new Promise((resolve, reject) => {
      options.signal.addEventListener('abort', () => reject(abortError()), { once: true });
    });
  });

  const consumer = coordinator.request('/users?page=1');
  await flushCoordinator();
  coordinator.cancelPageGetConsumers('compass');

  await assert.rejects(consumer, error => error?.name === 'AbortError');
  assert.equal(networkSignal.aborted, true);
});

test('runPageLoadOnce caches only successful work', async () => {
  const successHarness = compilePageLoadHarness();
  let successRuns = 0;
  await successHarness.runPageLoadOnce('compass', 'main', async () => {
    successRuns += 1;
    return true;
  });
  const successEntry = [...successHarness.pageLoadRequests.values()][0];
  assert.ok(successEntry?.loadedAt > 0);
  await successHarness.runPageLoadOnce('compass', 'main', async () => {
    successRuns += 1;
    return true;
  });
  assert.equal(successRuns, 1);
  assert.match(successHarness.getLastLoadedPage(), /^11:compass:main::/);
  assert.ok(successHarness.getLastLoadedPageAt() > 0);

  const falseHarness = compilePageLoadHarness();
  let falseRuns = 0;
  assert.equal(await falseHarness.runPageLoadOnce('compass', 'main', async () => {
    falseRuns += 1;
    return false;
  }), false);
  assert.equal(falseHarness.pageLoadRequests.size, 0);
  await falseHarness.runPageLoadOnce('compass', 'main', async () => {
    falseRuns += 1;
    return false;
  });
  assert.equal(falseRuns, 2);

  const incompleteHarness = compilePageLoadHarness();
  assert.equal(await incompleteHarness.runPageLoadOnce('compass', 'main', async () => null), null);
  assert.equal(incompleteHarness.pageLoadRequests.size, 0);
  const settledFailure = await incompleteHarness.runPageLoadOnce('compass', 'main', async () => ([
    { status: 'fulfilled', value: true },
    { status: 'rejected', reason: new Error('expected nested failure') },
  ]));
  assert.equal(settledFailure[1].status, 'rejected');
  assert.equal(incompleteHarness.pageLoadRequests.size, 0);
  await incompleteHarness.runPageLoadOnce('compass', 'main', async () => ([
    { status: 'fulfilled', value: false },
  ]));
  assert.equal(incompleteHarness.pageLoadRequests.size, 0);

  const throwHarness = compilePageLoadHarness();
  let throwRuns = 0;
  assert.equal(await throwHarness.runPageLoadOnce('compass', 'main', async () => {
    throwRuns += 1;
    throw new Error('expected failure');
  }), false);
  assert.equal(throwHarness.pageLoadRequests.size, 0);
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
  assert.equal(lifecycleHarness.pageLoadRequests.size, 0);
  await lifecycleHarness.runPageLoadOnce('compass', 'main', async () => {
    lifecycleRuns += 1;
    return true;
  });
  assert.equal(lifecycleRuns, 2);
});

test('compass page cache survives navigation and Revenue AI date hydration, allows force, and stays isolated by hotel', async () => {
  const harness = compilePageLoadHarness();
  let runs = 0;
  const initialPolicy = harness.currentCompassReadPolicy('compass', 'current');
  assert.equal(initialPolicy.businessDate, '');
  await harness.runPageLoadOnce('compass', 'main', async () => {
    runs += 1;
    return true;
  }, { ttlMs: 60_000, requestPolicy: initialPolicy });

  harness.navigateTo('hotels');
  harness.cancelPageLoadRequests('compass');
  harness.setBusinessDate('2026-08-03');
  harness.navigateTo('compass');
  const revisitPolicy = harness.currentCompassReadPolicy('compass', 'current');
  assert.equal(revisitPolicy.businessDate, '');
  await harness.runPageLoadOnce('compass', 'main', async () => {
    runs += 1;
    return true;
  }, { ttlMs: 60_000, requestPolicy: revisitPolicy });
  assert.equal(runs, 1, 'cached revisit should ignore the unrelated Revenue AI date and old page generation');

  const forcedPolicy = harness.currentCompassReadPolicy('compass', 'action');
  await harness.runPageLoadOnce('compass', 'main', async () => {
    runs += 1;
    return true;
  }, { force: true, ttlMs: 60_000, requestPolicy: forcedPolicy });
  assert.equal(runs, 2, 'a forced dashboard refresh must bypass the successful page cache');

  harness.setHotel('81');
  const otherHotelPolicy = harness.currentCompassReadPolicy('compass', 'current');
  await harness.runPageLoadOnce('compass', 'main', async () => {
    runs += 1;
    return true;
  }, { ttlMs: 60_000, requestPolicy: otherHotelPolicy });
  assert.equal(runs, 3, 'a different selected hotel must use a different page cache scope');
});

test('runPageLoadOnce force supersedes in-flight work and a failure preserves loadedAt', async () => {
  const harness = compilePageLoadHarness();
  await harness.runPageLoadOnce('compass', 'main', async () => true, { ttlMs: 60_000 });
  const loadedAt = [...harness.pageLoadRequests.values()][0]?.loadedAt;
  assert.ok(loadedAt > 0);

  assert.equal(await harness.runPageLoadOnce('compass', 'main', async () => false, {
    force: true,
    ttlMs: 60_000,
  }), false);
  assert.equal([...harness.pageLoadRequests.values()][0]?.loadedAt, loadedAt);
  assert.equal(harness.getLastLoadedPageAt(), loadedAt);

  const pending = deferred();
  const stale = harness.runPageLoadOnce('compass', 'secondary', () => pending.promise);
  const shared = harness.runPageLoadOnce('compass', 'secondary', async () => true);
  assert.equal(shared, stale);
  const forced = harness.runPageLoadOnce('compass', 'secondary', async () => true, { force: true });
  assert.notEqual(forced, stale);
  assert.equal(await forced, true);
  pending.resolve(true);
  assert.equal(await stale, true);
  assert.ok([...harness.pageLoadRequests.values()].some(entry => entry.loadedAt > 0 && !entry.promise));
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

test('compass cache and GET share a date-independent policy while manual refresh stays forced', () => {
  const compassLoader = sliceBetween(
    'const loadCompassData = async (options = {}) => {',
    'const refreshCompassDashboard = async () => {',
  );
  assert.match(source, /const currentCompassReadPolicy = \(pageKey = currentPage\.value, priority = 'current'\) => \(\{[\s\S]*?businessDate: '',[\s\S]*?\}\);/);
  assert.match(source, /const requestPolicy = currentCompassReadPolicy\(landingPage, 'current'\);[\s\S]*?loadCompassData\(\{ skipOtaBackground: true, requestPolicy \}\)[\s\S]*?\{ ttlMs: DASHBOARD_PAGE_CACHE_TTL_MS, requestPolicy \}/);
  assert.match(source, /const requestPolicy = currentCompassReadPolicy\(newPage, 'current'\);[\s\S]*?loadCompassData\(\{ skipOtaBackground: true, requestPolicy \}\)[\s\S]*?\{ ttlMs: DASHBOARD_PAGE_CACHE_TTL_MS, requestPolicy \}/);
  assert.match(compassLoader, /const requestPolicy = options\.requestPolicy && typeof options\.requestPolicy === 'object'[\s\S]*?: currentCompassReadPolicy\(requestPage, force \? 'action' : 'current'\);/);
  assert.match(compassLoader, /if \(force\) requestPolicy\.force = true;/);
  assert.match(source, /const refreshCompassDashboard = async \(\) => \{\s*await loadCompassData\(\{ notify: true, force: true \}\);\s*\};/);
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

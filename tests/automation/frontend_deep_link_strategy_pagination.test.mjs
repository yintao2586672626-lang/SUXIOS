import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync('public/app-main.js', 'utf8');

const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return { promise, resolve, reject };
};

const sliceBetween = (start, end) => {
  const startIndex = source.indexOf(start);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert.notEqual(startIndex, -1, `missing start marker: ${start}`);
  assert.notEqual(endIndex, -1, `missing end marker: ${end}`);
  return source.slice(startIndex, endIndex);
};

const compileScopedFunction = (functionSource, functionName, context) => {
  const names = Object.keys(context);
  return Function(...names, `${functionSource}\nreturn ${functionName};`)(...names.map(name => context[name]));
};

const filterVisibleMenuItems = (items = [], currentUser = null) => {
  if (!currentUser) return [];
  if (currentUser.is_super_admin) return items;
  const hasPermission = key => !!currentUser.permissions?.[key];
  const filterTree = list => list.map((item) => {
    if (item.requireSuper) return null;
    if (item.requireManager && currentUser.role_id !== 2 && !currentUser.is_hotel_manager) return null;
    if (item.permissions?.length && !item.permissions.some(hasPermission)) return null;
    const children = filterTree(item.children || []);
    if (children.length) return { ...item, children };
    return item.children ? null : { ...item };
  }).filter(Boolean);
  return filterTree(items);
};

test('initial deep links resolve only to active pages visible to the authenticated user', () => {
  const resolverSource = sliceBetween(
    'const normalizeCanonicalPage = (page) =>',
    'const requestedUrlPage = (() => {',
  );
  const menuItemDefinitions = [{
    name: 'root',
    children: [
      { path: 'compass' },
      { path: 'pms-operating-data' },
      { path: 'online-data', permissions: ['can_view_online_data'] },
      { path: 'knowledge-center', requireManager: true },
      { path: 'users', requireSuper: true },
      { path: 'ai-strategy' },
    ],
  }];
  const helpers = Function(
    'filterVisibleMenuItemsForUser',
    'menuItemDefinitions',
    `${resolverSource}\nreturn { ACTIVE_DISCOVERABLE_PAGE_PATHS, normalizeCanonicalPage, resolveInitialPageOverride };`,
  )(filterVisibleMenuItems, menuItemDefinitions);

  const regularUser = { role_id: 3, permissions: {} };
  const onlineDataUser = { role_id: 3, permissions: { can_view_online_data: true } };
  const superAdmin = { is_super_admin: true, permissions: {} };

  assert.equal(helpers.resolveInitialPageOverride('pms-operating-data', regularUser), 'pms-operating-data');
  assert.equal(helpers.resolveInitialPageOverride('online-data', regularUser), 'compass');
  assert.equal(helpers.resolveInitialPageOverride('online-data', onlineDataUser), 'online-data');
  assert.equal(helpers.resolveInitialPageOverride('users', regularUser), 'compass');
  assert.equal(helpers.resolveInitialPageOverride('users', superAdmin), 'users');
  assert.equal(helpers.resolveInitialPageOverride('ai-strategy', superAdmin), 'compass');
  assert.equal(helpers.resolveInitialPageOverride('does-not-exist', superAdmin), 'compass');
  assert.equal(helpers.resolveInitialPageOverride('ai-workbench', regularUser), 'compass');
  assert.equal(helpers.resolveInitialPageOverride('pms-operating-data', null), 'compass');
  assert.equal(helpers.ACTIVE_DISCOVERABLE_PAGE_PATHS.has('pms-operating-data'), true);
  assert.equal(helpers.ACTIVE_DISCOVERABLE_PAGE_PATHS.has('ai-strategy'), false);

  const authBootstrap = sliceBetween(
    'const bootstrapSession = captureAuthSession();',
    'onUnmounted(() => {',
  );
  assert.match(source, /let initialPageOverride = resolveInitialPageOverride\(requestedInitialPage, user\.value\);/);
  assert.match(authBootstrap, /initialPageOverride = resolveInitialPageOverride\(requestedInitialPage, res\.data\);/);
  assert.match(authBootstrap, /currentPage\.value = initialPageOverride;/);
  assert.match(authBootstrap, /requestSuxiFullRenderForPage\(currentPage\.value\);/);
});

test('entering the frozen strategy page loads dependencies and history without generating or saving', () => {
  const match = source.match(
    /if \(newPage === 'ai-strategy'\) \{([\s\S]*?)\n\s*\}\n\s*if \(newPage === 'ai-simulation'\)/,
  );
  assert.ok(match, 'missing ai-strategy page lifecycle branch');
  assert.match(match[1], /ensureExpansionStaticReady\(\)/);
  assert.match(match[1], /loadStrategyRecords\(\)/);
  assert.doesNotMatch(match[1], /handleStrategy|strategy\/simulate|method:\s*'POST'/);
  assert.match(
    source,
    /const previousPage = previousPageLifecycleKey;[\s\S]*?if \(previousPage === 'ai-strategy' && newPage !== 'ai-strategy'\) \{\s*invalidateStrategyPageRequests\(\);/,
  );
  assert.doesNotMatch(source, /if \(previous === 'ai-strategy'/);
  const authReset = sliceBetween(
    'const resetHotelScopedClientState = (',
    'const clearActiveHotelDashboardSnapshots = () => {',
  );
  assert.match(authReset, /invalidateStrategyPageRequests\(\);/);
});

const createStrategyHarness = ({ request, currentPageValue = 'ai-strategy' } = {}) => {
  const strategySource = sliceBetween(
    'let aiStrategyActionSeq = 0;',
    'const applyStrategyRecord = (record, reuseInput = false) => {',
  );
  const currentPage = { value: currentPageValue };
  const sessionState = { epoch: 1, token: 'token-a' };
  const aiStrategyLoading = { value: false };
  const aiStrategyRecordsLoading = { value: false };
  const aiStrategyRecordId = { value: null };
  const aiStrategyResult = { value: null };
  const historyCalls = [];
  const toasts = [];
  let requestCalls = 0;
  const context = {
    captureAuthSession: () => ({ ...sessionState }),
    currentPage,
    pageRequestGeneration: 7,
    isAuthSessionCurrent: session => session.epoch === sessionState.epoch && session.token === sessionState.token,
    aiStrategyLoading,
    aiStrategyRecordsLoading,
    ensureExpansionStaticReady: async () => true,
    request: async (...args) => {
      requestCalls += 1;
      return request(...args);
    },
    buildStrategyPayload: () => ({ project_name: '测试项目' }),
    aiStrategyRecordId,
    aiStrategyResult,
    normalizeStrategyResult: data => ({ ...data, normalized: true }),
    loadStrategyRecords: async options => {
      historyCalls.push(options);
      return [];
    },
    showToast: (...args) => toasts.push(args),
  };
  const names = Object.keys(context);
  const bundle = Function(
    ...names,
    `${strategySource}
return {
  handleStrategy,
  invalidateStrategyPageRequests,
  bumpPageGeneration: () => { pageRequestGeneration += 1; },
};`,
  )(...names.map(name => context[name]));
  return {
    ...bundle,
    currentPage,
    sessionState,
    aiStrategyLoading,
    aiStrategyRecordsLoading,
    aiStrategyRecordId,
    aiStrategyResult,
    historyCalls,
    toasts,
    requestCalls: () => requestCalls,
  };
};

test('a user-triggered strategy result is discarded after leaving and returning to the page', async () => {
  const response = deferred();
  const harness = createStrategyHarness({ request: () => response.promise });
  const run = harness.handleStrategy();
  await Promise.resolve();
  await Promise.resolve();
  assert.equal(harness.requestCalls(), 1);

  harness.currentPage.value = 'compass';
  harness.invalidateStrategyPageRequests();
  harness.bumpPageGeneration();
  harness.currentPage.value = 'ai-strategy';
  assert.equal(harness.aiStrategyLoading.value, false);
  response.resolve({ code: 200, data: { record_id: 88, score: 91 } });

  assert.equal(await run, false);
  assert.equal(harness.aiStrategyRecordId.value, null);
  assert.equal(harness.aiStrategyResult.value, null);
  assert.equal(harness.historyCalls.length, 0);
  assert.deepEqual(harness.toasts, []);
  assert.equal(harness.aiStrategyLoading.value, false);
});

test('a user-triggered strategy result is discarded after the auth session changes', async () => {
  const response = deferred();
  const harness = createStrategyHarness({ request: () => response.promise });
  const run = harness.handleStrategy();
  await Promise.resolve();
  await Promise.resolve();
  harness.sessionState.epoch = 2;
  harness.sessionState.token = 'token-b';
  harness.invalidateStrategyPageRequests();
  assert.equal(harness.aiStrategyLoading.value, false);
  response.resolve({ code: 200, data: { record_id: 99, score: 87 } });

  assert.equal(await run, false);
  assert.equal(harness.aiStrategyRecordId.value, null);
  assert.equal(harness.aiStrategyResult.value, null);
  assert.equal(harness.historyCalls.length, 0);
  assert.deepEqual(harness.toasts, []);
});

test('a current user-triggered strategy request still updates state and refreshes history', async () => {
  const harness = createStrategyHarness({
    request: async () => ({ code: 200, data: { record_id: 77, score: 93 } }),
  });
  assert.equal(await harness.handleStrategy(), true);
  assert.equal(harness.aiStrategyRecordId.value, 77);
  assert.deepEqual(harness.aiStrategyResult.value, { record_id: 77, score: 93, normalized: true });
  assert.equal(harness.historyCalls.length, 1);
  assert.equal(harness.historyCalls[0].force, true);
  assert.deepEqual(harness.toasts, [['战略推演已生成']]);
});

const pagePolicyHarness = currentPage => ({
  currentPageReadPolicy: (pageKey = currentPage.value, priority = 'current') => ({
    scope: 'page',
    pageKey,
    pageGeneration: 1,
    sessionEpoch: 1,
    priority,
  }),
  isPageLoadPolicyCurrent: () => true,
});

test('hotel management follows data.pagination.total_page and loads rows beyond the first 100', async () => {
  const loaderSource = sliceBetween(
    'const loadHotels = async (options = {}) => {',
    'let startupHotelListLoadTimer = null;',
  );
  const currentPage = { value: 'hotels' };
  const hotels = { value: [] };
  const calls = [];
  const firstPage = Array.from({ length: 100 }, (_, index) => ({ id: index + 1 }));
  const loadHotels = compileScopedFunction(loaderSource, 'loadHotels', {
    captureAuthSession: () => ({ epoch: 1, token: 'token-a' }),
    isAuthSessionCurrent: session => session.epoch === 1 && session.token === 'token-a',
    user: { value: { is_super_admin: true } },
    currentPage,
    ...pagePolicyHarness(currentPage),
    hotels,
    hotelListLoading: { value: false },
    hotelListLoadFailed: { value: false },
    hotelListSnapshotReady: { value: false },
    hotelListPendingCount: 0,
    hotelListRequestSeq: 0,
    hotelListSnapshotScope: '',
    loadHotelsRequestPromises: new Map(),
    loadHotelsRequestPriorityByKey: new Map(),
    hotelListResultCache: new Map(),
    hotelListRequestIntentSeqByKey: new Map(),
    coordinatedGetPriorityRank: () => 0,
    currentHotelListScope: () => 'paged-with-inactive',
    readRequestCache: () => false,
    writeRequestCache: () => {},
    loadHotelAutomationLifecycles: async () => ({}),
    request: async (url) => {
      calls.push(url);
      const page = Number(new URL(url, 'http://local').searchParams.get('page'));
      return page === 1
        ? { code: 200, data: { list: firstPage, pagination: { page: 1, total_page: 2 } } }
        : { code: 200, data: { list: [{ id: 101 }], pagination: { page: 2, total_page: 2 } } };
    },
    dedupeHotels: items => items,
    showToast: () => {},
  });

  const result = await loadHotels({ force: true, includeInactive: true });
  assert.equal(calls.length, 2);
  assert.equal(result.length, 101);
  assert.equal(result.at(-1).id, 101);
  assert.doesNotMatch(loaderSource, /pageRes\.data\?\.total_page/);
});

test('employee management follows data.pagination.total_page and loads rows beyond the first 100', async () => {
  const loaderSource = sliceBetween(
    'const loadUsers = async (options = {}) => {',
    'const loadRoles = async (options = {}) => {',
  );
  const currentPage = { value: 'users' };
  const users = { value: [] };
  const calls = [];
  const firstPage = Array.from({ length: 100 }, (_, index) => ({ id: index + 1 }));
  const loadUsers = compileScopedFunction(loaderSource, 'loadUsers', {
    captureAuthSession: () => ({ epoch: 1, token: 'token-a' }),
    isAuthSessionCurrent: session => session.epoch === 1 && session.token === 'token-a',
    currentPage,
    ...pagePolicyHarness(currentPage),
    usersRequestSeq: 0,
    users,
    usersLoading: { value: false },
    usersLoadError: { value: '' },
    usersSnapshotReady: { value: false },
    request: async (url) => {
      calls.push(url);
      const page = Number(new URL(url, 'http://local').searchParams.get('page'));
      return page === 1
        ? { code: 200, data: { list: firstPage, pagination: { page: 1, total_page: 2 } } }
        : { code: 200, data: { list: [{ id: 101 }], pagination: { page: 2, total_page: 2 } } };
    },
    showToast: () => {},
  });

  const result = await loadUsers();
  assert.equal(calls.length, 2);
  assert.equal(result.length, 101);
  assert.equal(result.at(-1).id, 101);
  assert.doesNotMatch(loaderSource, /res\.data\?\.total_page/);
});

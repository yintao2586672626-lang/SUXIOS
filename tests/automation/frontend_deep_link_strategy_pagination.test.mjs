import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync('public/app-main.js', 'utf8');

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
  const revenueAiUser = { role_id: 3, permissions: {}, capabilities: ['ai.view'] };
  const superAdmin = { is_super_admin: true, permissions: {} };

  assert.equal(helpers.resolveInitialPageOverride('pms-operating-data', regularUser), 'pms-operating-data');
  assert.equal(helpers.resolveInitialPageOverride('online-data', regularUser), 'compass');
  assert.equal(helpers.resolveInitialPageOverride('online-data', onlineDataUser), 'online-data');
  assert.equal(helpers.resolveInitialPageOverride('users', regularUser), 'compass');
  assert.equal(helpers.resolveInitialPageOverride('users', superAdmin), 'users');
  assert.equal(helpers.resolveInitialPageOverride('agent-center', regularUser), 'compass');
  assert.equal(helpers.resolveInitialPageOverride('agent-center', revenueAiUser), 'agent-center');
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

test('all retired page links resolve to the active compass without loading retired assets', () => {
  const resolverSource = sliceBetween('const normalizeCanonicalPage = (page) =>', 'const ACTIVE_DISCOVERABLE_PAGE_PATHS');
  const resolve = Function(resolverSource + '\nreturn normalizeCanonicalPage;')();
  for (const page of ['ai-workbench', 'ai-strategy', 'ai-feasibility', 'market-evaluation', 'market-eval', 'benchmark-model', 'collaboration-efficiency', 'sync-efficiency', 'asset-pricing', 'timing-strategy', 'decision-board', 'investment-decision', 'lifecycle', 'lifecycle-auxiliary']) {
    assert.equal(resolve(page), 'compass', page);
  }
  for (const page of ['ai-simulation', 'opening-overview', 'opening-checklist', 'investment-payback', 'ops-track']) assert.equal(resolve(page), page);
});

test('retired strategy runtime has no request handlers, state or setup exposure', () => {
  assert.doesNotMatch(source, /const (?:handleStrategy|applyStrategyRecord|loadStrategyRecords|archiveStrategyRecord|aiStrategyResult|strategyCurrentReadiness)\b/);
  assert.doesNotMatch(source, /request\(['"]\/strategy\//);
  assert.doesNotMatch(source, /expansion-static-options\.js/);
});

test('changing pages and clearing auth do not refer to removed strategy lifecycle handlers', () => {
  assert.doesNotMatch(source, /invalidateStrategyPageRequests|captureStrategyPageContext|isStrategyPageContextCurrent/);
  assert.doesNotMatch(source, /if \(newPage === ['"](?:ai-strategy|ai-feasibility|investment-decision|lifecycle)['"]\)/);
  assert.match(source, /const canonicalPage = normalizeCanonicalPage\(newPage\);/);
  assert.match(source, /clearAuthSessionWithStatus/);
});

test('operating closure navigation canonicalizes historical module links before routing', () => {
  const navigation = sliceBetween('const openOperationClosureModule =', 'const operationErrorMessage =');
  assert.match(navigation, /const targetPage = normalizeCanonicalPage\(module\?\.entry_page\);/);
  assert.doesNotMatch(navigation, /loadExpansionRecords|loadTransferRecords/);
  assert.match(navigation, /loadOperationActions\(\)/);
  assert.match(navigation, /loadOpeningProjects\(\)/);
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

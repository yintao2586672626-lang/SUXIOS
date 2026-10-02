// Original-control regression; all transport and prior account projections are synthetic.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

const defaultRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const option = name => process.argv.find(arg => arg.startsWith(`--${name}=`))?.slice(name.length + 3);
const sourceRoot = path.resolve(option('source-root') || defaultRoot);
const fallbackRoot = path.resolve(option('fallback-root') || defaultRoot);
const sha = text => createHash('sha256').update(text).digest('hex').toUpperCase();
const readers = new Map();
const read = file => {
  const preferred = path.join(sourceRoot, file);
  const resolvedPath = fs.existsSync(preferred) ? preferred : path.join(fallbackRoot, file);
  const bytes = fs.readFileSync(resolvedPath);
  readers.set(file, { path: file, resolved_path: resolvedPath, sha256: sha(bytes) });
  return bytes.toString('utf8').replaceAll('\r\n', '\n');
};
const main = read('public/app-main.js');
const cut = (source, startMarker, endMarker) => {
  const start = source.indexOf(startMarker), end = source.indexOf(endMarker, start + startMarker.length);
  assert.ok(start >= 0 && end > start, startMarker); return source.slice(start, end);
};
const decl = name => {
  const start = main.indexOf(`            const ${name} =`);
  assert.ok(start >= 0, name);
  const next = /\n            (?:const|let) /.exec(main.slice(start + 1));
  assert.ok(next, name); return main.slice(start, start + 1 + next.index);
};
const pageWatcher = cut(main, '            watch(currentPage, (newPage) => {', '            watch(isLoggedIn, (loggedIn) => {');
const requestSource = [
  cut(main, '            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  cut(main, '            const PAGE_LOAD_DEDUP_MS =', '            const activateCoreOperationsAfterLogin ='),
  cut(main, '            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  cut(main, '            const request = async (', '            const apiRequest = request;'),
].join('\n');
const targetNames = ['captureAuthSession', 'isAuthSessionCurrent', 'readRequestCooldown', 'terminalAuthFailureReason',
  'isTerminalAuthFailureResponse', 'userHasPermission', 'canManageOwnHotels',
  'normalizeCanonicalPage', 'SUPER_ADMIN_ONLY_PAGES', 'guardSuperAdminPageAccess', 'isCompassDataPage',
  'hotelWide', 'hotelRowsVisible', 'permittedHotels', 'getHotelNameById', 'normalizeUserHotelIds', 'userHotelIdsForForm', 'userHotelScopeSummary',
  'roleForUser', 'userRoleIssueProfile', 'roleIssueGuideCards', 'issueRoleIdForFilter', 'betaUserRoleIdForFilter',
  'hotelAuthorizationEligibleUsers', 'filteredHotelAuthorizationUsers', 'closeHotelUserAuthorization',
  'hotelAuthorizationCandidateDisabled', 'openUserAuthorization', 'saveHotelUserAuthorization', 'loadUsers', 'loadRoles',
  'getMenuItemName', 'stableHashSegmentForTestId', 'normalizeTestIdSegmentInline', 'pageTestId', 'menuTestId',
  'cloneMenuItem', 'flattenMenuItems', 'findMenuEntry', 'menuItems', 'isSidebarMenuItemActive', 'handleMenuClick', 'showToast', 'loadCompassData'];
const targetSource = (main.includes('            let hotelUserAuthorizationRequestSeq = 0;')
  ? '            let hotelUserAuthorizationRequestSeq = 0;\n' : '')
  + targetNames.map(decl).join('\n') + '\n'
  + cut(main, '            const hotelRowsForDisplay =', '            watch(() => [\n                hotelManagementSnapshotReady.value,') + '\n'
  + cut(main, '            const BOSS_VISIBLE_NAVIGATION_CONFIG =', '            // 展开的子菜单：') + '\n'
  + cut(main, '            homeRevenueFactLayerController = createHomeRevenueFactLayerController({', '            const loadRevenueAiOverview =');
const fullRenderStart = main.indexOf('    requestSuxiFullRenderForPage = (page) => {');
const fullRenderEnd = main.indexOf('\n    };', fullRenderStart);
assert.ok(fullRenderStart >= 0 && fullRenderEnd > fullRenderStart);
const fullRenderFunction = main.slice(fullRenderStart, fullRenderEnd + '\n    };'.length);

// Preserve original root/ancestor tags, v-if/v-else chains, loops and native
// controls. Unrelated branch bodies are omitted; no ancestor condition is removed.
const fragments = ['00-app-shell.html', '18-page-hotels.html', '36-app-shell-close.html', '39-dialogs-access-management.html'];
const combined = fragments.map(name => read(`resources/frontend/templates/fragments/${name}`)).join('\n');
const ast = parse(combined), selected = new Set();
const scan = node => {
  if (node.type === 1) {
    const event = node.props.find(p => p.name === 'on' && p.arg?.content === 'click');
    const testid = node.props.find(p => p.type === 6 && p.name === 'data-testid')?.value?.content;
    if ((node.tag === 'button' && ['openUserAuthorization(hotel)', 'openUserAuthorization()'].includes(event?.exp?.content))
      || (node.tag === 'a' && event?.exp?.content === 'handleMenuClick(item)')
      || testid === 'hotel-user-authorization-modal') selected.add(node);
  }
  for (const child of node.children || []) scan(child);
};
scan(ast); assert.equal(selected.size, 5, 'Header/desktop/mobile authorization, top-level menu and global modal');
const wrapper = (node, children = '') => node.type === 0 ? children
  : node.loc.source.slice(0, node.loc.source.indexOf('>') + 1) + children
    + node.loc.source.slice(node.loc.source.lastIndexOf('</'));
const hasDirective = (node, names) => node.type === 1 && node.props.some(p => p.type === 7 && names.includes(p.name));
const retain = node => {
  if (selected.has(node)) return node.loc.source;
  const children = node.children || [], outputs = children.map(retain);
  for (let i = 0; i < children.length; i += 1) {
    if (!outputs[i] || !hasDirective(children[i], ['else', 'else-if'])) continue;
    for (let j = i - 1; j >= 0; j -= 1) {
      if (children[j].type !== 1) continue;
      if (!hasDirective(children[j], ['if', 'else-if'])) break;
      if (!outputs[j]) outputs[j] = wrapper(children[j]);
      if (hasDirective(children[j], ['if'])) break;
    }
  }
  const body = outputs.join(''); return body ? wrapper(node, body) : '';
};
const template = `<section>${retain(ast)}</section>`;
const render = new Function('Vue', compile(template, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const clone = value => JSON.parse(JSON.stringify(value));
const textOf = node => typeof node === 'string' ? node : Array.isArray(node) ? node.map(textOf).join('') : textOf(node?.children || '');
const syntheticRole = { id: 2, name: 'beta_user', level: 2, status: 1, permissions: {} };
const syntheticHotel = { id: 80, tenant_id: 7, name: 'Synthetic hotel A', status: 1 };
// Consumed public projection of a previous successful User::index/roles read.
// No credentials; no operation grants; not a new response to the cancelled GET.
const previousUser = { id: 8101, tenant_id: 7, username: 'synthetic_member', realname: 'Synthetic member',
  role_id: 2, role: syntheticRole, status: 1, hotel_id: 80,
  assigned_hotel_ids: [80], owned_hotel_ids: [], hotel_ids: [80],
  assigned_hotels: [{ id: 80, name: syntheticHotel.name }], hotel_scope_text: syntheticHotel.name,
  tenant_scope_status: 'bound', tenant_scope_message: '租户已绑定', operation_execute_hotel_ids: [] };
const unassignedUser = { ...previousUser, hotel_id: null, assigned_hotel_ids: [], hotel_ids: [], assigned_hotels: [],
  hotel_scope_text: '无授权门店' };
const userWithHotels = hotelIds => ({ ...unassignedUser, hotel_id: hotelIds[0] ?? null,
  hotel_ids: hotelIds, assigned_hotel_ids: hotelIds,
  assigned_hotels: hotelIds.map(id => ({ id, name: `Synthetic hotel ${id}` })),
  hotel_scope_text: hotelIds.map(id => `Synthetic hotel ${id}`).join('、') });

function harness(t, { ignoreAbortForUsers = false, allowAuthorizationSave = false } = {}) {
  const requests = [], expectedErrors = [], runtimeErrors = [], boundaryCalls = [], timers = [], stops = [], flows = [];
  const state = { currentPage: 'hotels', isLoggedIn: true, sidebarCollapsed: false, systemConfig: {},
    user: { id: 11, tenant_id: 7, is_super_admin: true, permissions: { can_manage_own_hotels: true } },
    token: 'synthetic-round115-session-not-a-credential',
    authContext: { tenantId: 7, hotelId: 80, tokenStatus: 'valid', permissionStatus: 'allowed', platform: 'all' },
    filterReportHotel: '80', revenueAiBusinessDate: '2026-09-20', coreOperationsTargetDate: '2026-09-20',
    hotels: [syntheticHotel], filteredHotels: [syntheticHotel], hotelManagementVisibleRowLimit: 1,
    hotelManagementSnapshotReady: true, hotelManagementRowsReady: true,
    users: [previousUser], roles: [syntheticRole], usersLoading: false, usersLoadError: '', usersSnapshotReady: true,
    searchUser: '', filterUserRoleId: '', filterUserStatus: '', filterUserHotelId: '',
    showHotelUserAuthorizationModal: false, hotelUserAuthorizationTarget: null, hotelUserAuthorizationUserIds: [],
    hotelUserAuthorizationSearch: '', hotelUserAuthorizationLoading: false, hotelUserAuthorizationSaving: false, hotelUserAuthorizationError: '',
    expandedMenus: [], toast: { show: false, message: '', type: 'success' }, autoFetchRunState: { active: false, type: '' },
    fetchingData: false, homeSecondaryPanelsReady: false, dataHealthSecondaryPanelsReady: false,
    dataHealthDetailPanelsReady: false, dataHealthEmployeePanelsReady: false, platformAutoSettingsPanelsReady: false,
    platformAutoSecondaryPanelsReady: false, platformSourceGuidePanelsReady: false,
    ctripEbookingModuleCardsReady: false, ctripEbookingSecondaryPanelsReady: false, ctripEbookingDeepPanelsReady: false,
    ctripEbookingBusinessDetailsReady: false, ctripEbookingDiagnosticsPanelsReady: false,
    homeRevenueFactBusinessDate: '2026-09-19', homeRevenueFactLayer: null, homeRevenueFactLayerLoading: false, homeRevenueFactLayerError: '',
    compassLoading: false, compassWeather: [], compassTodos: [], compassMetrics: { day: {}, week: {}, month: {} },
    compassAlerts: [], compassHolidays: [], operatingLoop: null, operatingLoopError: '', compassLastSyncedAt: '--',
  };
  const refs = Object.fromEntries(Object.entries(state).map(([name, value]) => [name, Vue.ref(clone(value))]));
  const fakeSetTimeout = (callback, delay = 0) => {
    const timer = { id: timers.length + 1, callback, delay: Number(delay), executed: false, cleared: false };
    timers.push(timer); return timer;
  };
  const fakeClearTimeout = timer => { if (timer) timer.cleared = true; };
  const record = name => (...args) => { boundaryCalls.push({ name, args: args.map(arg => typeof arg === 'function' ? '[callback]' : arg) }); };
  const blocked = name => () => { throw new Error(`Out-of-scope action invoked: ${name}`); };
  const sandbox = { ...refs, ref: Vue.ref, computed: Vue.computed, nextTick: Vue.nextTick,
    watch: (...args) => { const stop = Vue.watch(...args); stops.push(stop); return stop; },
    URL, URLSearchParams, Headers, FormData, AbortController, DOMException, Date, TypeError,
    setTimeout: fakeSetTimeout, clearTimeout: fakeClearTimeout,
    window: { matchMedia: query => { assert.equal(query, '(min-width: 1280px)'); return { matches: true }; } },
    document: { documentElement: { dataset: { suxiRenderPhase: 'full' } } },
    API_BASE: 'https://synthetic.invalid/api', authSessionEpoch: 1, usersRequestSeq: 0,
    cachedPermittedHotels: clone([syntheticHotel]),
    previousPageLifecycleKey: 'hotels', compassRequestSeq: 0, compassDisplayedHotelId: '80',
    dataLoadTimer: null, homeRevenueFactLayerController: null, operationYesterday: '2026-09-19',
    // loadCompassData reads the current Shanghai business-yesterday snapshot.
    operatingLoopYesterday: '2026-09-19',
    requestSuxiFullRenderForPage: null,
    saveHotelUserAuthorization: blocked('saveHotelUserAuthorization'),
    clearAuthSessionIfCurrent: blocked('auth mutation'),
    // UI-only delayed callbacks are not advanced; original main loaders below are executed.
    deferUiTask: (callback, delay) => fakeSetTimeout(callback, delay),
    baseScheduleDelayedPageTask: (callback, delay) => fakeSetTimeout(callback, delay),
    console: { error: (...args) => {
      const error = args.at(-1), prefix = args[0];
      const originatingCall = requests.find(call => call.failure === error);
      const expectedPrefix = prefix === 'API请求失败:'
        ? originatingCall && args[1] === originatingCall.requestPath
        : ['加载基础经营事实失败:', '加载首页罗盘失败:'].includes(prefix);
      if (originatingCall && expectedPrefix && error instanceof TypeError && error.message === 'Failed to fetch') {
        expectedErrors.push({ prefix, endpoint: originatingCall.pathname, message: error.message }); return;
      }
      if (prefix === 'API请求失败:' && error?.status === 500 && error.message === '服务暂时不可用'
        && args[1] === '/users?page=1&page_size=100&sort_by=id&sort_order=desc') {
        expectedErrors.push({ prefix, endpoint: '/api/users', status: 500, message: error.message }); return;
      }
      if (prefix === 'API请求失败:' && allowAuthorizationSave && error?.status === 500
        && error.message === '服务暂时不可用' && args[1] === '/users/hotel-assignments') {
        expectedErrors.push({ prefix, endpoint: '/api/users/hotel-assignments', status: 500, message: error.message }); return;
      }
      runtimeErrors.push(args.map(String).join(' '));
    }, warn: (...args) => runtimeErrors.push(args.map(String).join(' ')) },
    fetch: (url, options = {}) => new Promise((resolve, reject) => {
      const parsed = new URL(url), method = String(options.method || 'GET').toUpperCase();
      const allowedRead = method === 'GET' && ['/api/users', '/api/compass', '/api/dashboard/revenue-facts'].includes(parsed.pathname);
      const allowedSyntheticWrite = allowAuthorizationSave && method === 'POST' && parsed.pathname === '/api/users/hotel-assignments';
      assert.equal(parsed.origin, 'https://synthetic.invalid');
      assert.ok(allowedRead || allowedSyntheticWrite, `${method} ${parsed.pathname} is outside the closed synthetic transport`);
      assert.equal(new Headers(options.headers).get('Authorization'), refs.token.value);
      if (method === 'GET') assert.ok(options.signal, 'Original coordinated GET AbortSignal');
      else assert.equal(options.signal, undefined, 'Mutation bypasses the read-only request coordinator');
      if (parsed.pathname === '/api/users' && method === 'GET') {
        assert.equal(parsed.searchParams.get('page'), '1'); assert.equal(parsed.searchParams.get('page_size'), '100');
      } else if (parsed.pathname !== '/api/users/hotel-assignments') {
        assert.equal(parsed.searchParams.get('hotel_id'), '80'); assert.equal(parsed.searchParams.get('business_date'), '2026-09-19');
      }
      const requestPath = parsed.pathname.slice('/api'.length) + parsed.search;
      const call = { pathname: parsed.pathname, requestPath, method, body: options.body ? JSON.parse(options.body) : null,
        settled: false, aborted: false, reject, resolve, failure: null };
      requests.push(call);
      options.signal?.addEventListener('abort', () => {
        if (call.settled) return; call.aborted = true;
        if (ignoreAbortForUsers && parsed.pathname === '/api/users') return;
        call.settled = true;
        reject(new DOMException('Original GET coordinator abort', 'AbortError'));
      }, { once: true });
    }),
  };
  // These ports stop/clear unrelated inactive UI timers or charts. They cannot
  // assign users/roles/modal/page, issue requests, or turn failed data into ready.
  for (const name of ['stopAutoFetchProgressMonitor', 'stopAutoFetchRunTimer', 'clearPostFetchRefreshTimers',
    'clearHomeSecondaryPanelsReadyTimer', 'clearDualOtaSystemMetricDrilldownHydrationTimer', 'destroyHomeTrendChart',
    'clearManualOnlineFetchConfigPrewarmTimer', 'clearDataHealthSecondaryPanelsReadyTimer', 'clearDataHealthDetailPanelsReadyTimer',
    'clearDataHealthEmployeePanelsReadyTimer', 'clearPlatformAutoSettingsPanelsReadyTimer', 'clearPlatformAutoSecondaryPanelsReadyTimer',
    'clearPlatformSourceGuidePanelsReadyTimer', 'destroyAnalysisChart', 'clearCtripEbookingModuleCardsReadyTimer',
    'clearCtripEbookingSecondaryPanelsReadyTimer', 'clearCtripEbookingDeepPanelsReadyTimer', 'clearCtripEbookingBusinessDetailsReadyTimer',
    'resetHotelManagementRowsReady', 'stopAutomationMonitorPolling']) sandbox[name] = record(name);
  for (const [name, delay] of [['schedulePageControlTestIdObserverStart', 520], ['scheduleFormOperationSupportLoad', 5200],
    ['scheduleHomeSecondaryPanelsReady', 4200], ['scheduleDualOtaWorkbenchAutoFetch', 9000], ['scheduleDualOtaSystemMetricDrilldownHydration', 30]]) {
    sandbox[name] = suppliedDelay => {
      boundaryCalls.push({ name, kind: 'adjacent timer registration only' });
      return fakeSetTimeout(blocked(`${name} callback outside observation window`), suppliedDelay ?? delay);
    };
  }
  vm.createContext(sandbox);
  for (const file of ['public/system-static.js', 'public/user-admin-static.js', 'public/home-static.js']) vm.runInContext(read(file), sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  for (const name of ['menuItemDefinitions', 'filterVisibleMenuItems', 'resolveMenuItems', 'testIdNameMap', 'isExpansionStaticPage', 'isSimulationStaticPage'])
    sandbox[name] = sandbox.appSystemStatic[name];
  for (const name of ['roleIssueProfile', 'withRolePermissionTags', 'summarizeUserHotelScope']) sandbox[name] = sandbox.window.SUXI_USER_ADMIN_STATIC[name];
  sandbox.createHomeRevenueFactLayerController = sandbox.window.SUXI_HOME_STATIC.createHomeRevenueFactLayerController;
  vm.runInContext(cut(main, '    const requireAppSystemStatic = (key) => {', '    const requireUserAdminStatic =')
    + '\n' + requestSource + '\n' + targetSource + '\n' + fullRenderFunction + '\n' + pageWatcher + '\n'
    + `globalThis.original = { ${targetNames.join(',')}, hotelRowsForDisplay, visibleMenuItems,
      loadHomeRevenueFactLayer, clearPageLifecycleTimers,
      pendingPageLoads: () => [...pageLoadRequests.values()].map(entry => entry.promise).filter(Boolean),
      coordinatorState: () => ({ active: coordinatedGetActiveCount, queued: coordinatedGetQueue.length,
        entries: coordinatedGetRequests.size, consumers: [...coordinatedGetRequests.values()].reduce((sum, item) => sum + item.consumers.size, 0) }),
      generation: () => pageRequestGeneration };`, sandbox);
  const context = { ...refs, ...sandbox.original, saveHotelUserAuthorization: allowAuthorizationSave
    ? sandbox.original.saveHotelUserAuthorization : sandbox.saveHotelUserAuthorization };
  let tree;
  const inspect = async () => {
    const app = Vue.createSSRApp({ setup: () => context, render() { tree = render(this, []); return tree; } });
    app.config.warnHandler = message => { throw new Error(`Unexpected Vue warning: ${message}`); };
    app.config.errorHandler = error => { runtimeErrors.push(error.message); throw error; };
    const html = await renderToString(app), rows = [];
    const walk = (node, parents = []) => {
      if (Array.isArray(node)) return node.forEach(child => walk(child, parents));
      if (!node || typeof node !== 'object') return;
      rows.push({ node, parents }); walk(node.children, [...parents, node]);
      if (node.component?.subTree) walk(node.component.subTree, [...parents, node]);
    };
    walk(tree); return { html, rows };
  };
  const visible = entry => ![...entry.parents, entry.node].some(node => node.props?.style?.display === 'none'
    || node.dirs?.some(binding => binding.dir === Vue.vShow && !binding.value));
  const enabled = entry => visible(entry) && !entry.node.props?.disabled
    && !entry.parents.some(node => node.type === 'fieldset' && node.props?.disabled);
  const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
  const fail = call => {
    assert.ok(call && !call.settled); assert.notEqual(call.pathname, '/api/users', 'Users is cancelled by original coordinator only');
    call.failure = new TypeError('Failed to fetch'); call.settled = true; call.reject(call.failure);
  };
  const replyUsers = (call, { failure = false, users = [previousUser] } = {}) => {
    assert.ok(call && !call.settled); assert.equal(call.pathname, '/api/users');
    // ExceptionHandle::render public API 500 envelope; Base::success/paginate for 200.
    const body = failure ? { code: 500, message: '服务暂时不可用', data: { reason: 'internal_error' }, time: 1789891200 }
      : { code: 200, message: '操作成功', time: 1789891200, data: { list: users,
        pagination: { total: 1, page: 1, page_size: 100, total_page: 1 } } };
    call.settled = true; call.resolve(new Response(JSON.stringify(body), {
      status: failure ? 500 : 200, headers: { 'Content-Type': 'application/json' },
    }));
  };
  const replyAssignment = (call, { failure = false, users = [] } = {}) => {
    assert.ok(call && !call.settled); assert.equal(call.pathname, '/api/users/hotel-assignments'); assert.equal(call.method, 'POST');
    const body = failure ? { code: 500, message: '服务暂时不可用', data: { reason: 'internal_error' }, time: 1789891200 }
      : { code: 200, message: '操作成功', time: 1789891200, data: { users } };
    call.settled = true; call.resolve(new Response(JSON.stringify(body), {
      status: failure ? 500 : 200, headers: { 'Content-Type': 'application/json' },
    }));
  };
  t.after(async () => {
    for (const call of requests.filter(item => !item.settled)) {
      call.teardown_only = true; call.settled = true;
      call.reject(new DOMException('Teardown only', 'AbortError'));
    }
    await Promise.allSettled(flows); await tick();
    sandbox.original.clearPageLifecycleTimers(); for (const stop of stops) stop();
    for (const timer of timers) fakeClearTimeout(timer);
    const evidence = { mode: 'local synthetic Vue SSR/VM', readers: [...readers.values()],
      requests: requests.map(({ pathname, requestPath, method, body, settled, aborted, teardown_only }) => ({
        pathname, requestPath, method, body: pathname === '/api/users/hotel-assignments' ? body : null, settled, aborted, teardown_only,
      })),
      expectedErrors, runtimeErrors, boundaryCalls, timers: timers.map(({ id, delay, executed, cleared }) => ({ id, delay, executed, cleared })),
      result: { page: refs.currentPage.value, modal: refs.showHotelUserAuthorizationModal.value,
        target: clone(refs.hotelUserAuthorizationTarget.value), selected: clone(refs.hotelUserAuthorizationUserIds.value),
        authorizationError: refs.hotelUserAuthorizationError.value, authorizationLoading: refs.hotelUserAuthorizationLoading.value,
        compassError: refs.operatingLoopError.value, revenueFactError: refs.homeRevenueFactLayerError.value,
        toast: clone(refs.toast.value), coordinator: sandbox.original.coordinatorState() },
      limits: ['No browser, live HTTP, DB, login, credential read, authorization or revocation.',
        allowAuthorizationSave
          ? 'Only the exact synthetic hotel-assignment POST path is enabled; no external or real account write is possible.'
          : 'All writes are forbidden.',
        ignoreAbortForUsers
          ? 'Users GET abort is signaled but synthetic transport resolves a late 200 to model a network-response race.'
          : 'Users/roles are explicit same-scope consumed projections; cancelled users GET is never given a 200 response.',
        'Compass navigation executes both immediate original readers and closes them with explicit network failures.',
        'Adjacent timers are registered without advancing time; no dashboard success or operating facts asserted.'] };
    t.diagnostic(JSON.stringify(evidence));
  });
  return { refs, requests, expectedErrors, runtimeErrors, flows, original: sandbox.original, inspect, visible, enabled, tick,
    fail, replyUsers, replyAssignment };
}

const openSyntheticHotelAuthorization = async (h, users = [previousUser]) => {
  const initial = await h.inspect();
  const entry = initial.rows.find(row => row.node.type === 'button' && textOf(row.node).trim() === '授权用户'
    && row.parents.some(node => node.type === 'tr' && Number(node.key) === 80));
  assert.ok(entry && h.enabled(entry), 'Original visible desktop A authorization button');
  const openRun = entry.node.props.onClick(); h.flows.push(openRun); await h.tick();
  const usersRequest = h.requests.find(call => call.pathname === '/api/users');
  assert.ok(usersRequest && !usersRequest.settled);
  h.replyUsers(usersRequest, { users }); await openRun; await h.tick();
  assert.equal(h.refs.hotelUserAuthorizationTarget.value.id, '80');
  assert.equal(h.refs.showHotelUserAuthorizationModal.value, true);
  return await h.inspect();
};

test('hotel authorization cancellation respects original navigation and releases its own busy state', async t => {
  const h = harness(t), initial = await h.inspect();
  assert.equal(h.refs.showHotelUserAuthorizationModal.value, false);
  const entry = initial.rows.find(row => row.node.type === 'button' && textOf(row.node).trim() === '授权用户'
    && row.parents.some(node => node.type === 'tr' && Number(node.key) === 80));
  assert.ok(entry && h.enabled(entry), 'Original visible desktop A authorization button');
  assert.ok(entry.parents.some(node => node.props?.['data-testid'] === 'hotel-account-summary-table'));
  const openRun = entry.node.props.onClick(); h.flows.push(openRun); await h.tick();
  assert.equal(h.refs.hotelUserAuthorizationLoading.value, true);
  assert.equal(h.refs.showHotelUserAuthorizationModal.value, false, 'No modal/overlay during initial read');
  assert.equal(h.requests.length, 1); assert.equal(h.requests[0].pathname, '/api/users');
  assert.equal(h.requests[0].settled, false);
  const loading = await h.inspect();
  const disabledAuthorization = loading.rows.filter(row => row.node.type === 'button' && textOf(row.node).includes('读取中'));
  assert.ok(disabledAuthorization.length > 0); assert.ok(disabledAuthorization.every(row => !h.enabled(row)));
  const nav = loading.rows.find(row => row.node.type === 'a' && row.node.props?.['data-testid'] === 'nav-operating-loop-kernel');
  assert.ok(nav && h.enabled(nav), 'Original sidebar remains reachable before modal opens');
  assert.ok(nav.parents.some(node => node.type === 'nav' && node.props?.['data-testid'] === 'app-nav'));
  assert.equal(textOf(nav.node).trim(), '今日经营看板');
  nav.node.props.onClick(); await h.tick();
  assert.equal(h.refs.currentPage.value, 'compass', 'Changed exclusively by original navigation handler');
  assert.equal(h.original.generation(), 1, 'Original page watcher ran');
  assert.equal(h.requests[0].aborted, true, 'Original watcher/coordinator cancelled users transport');
  assert.equal(h.requests.length, 3, 'Both immediate compass-startup readers really issued GETs');
  const facts = h.requests.find(call => call.pathname === '/api/dashboard/revenue-facts');
  const compass = h.requests.find(call => call.pathname === '/api/compass');
  assert.ok(facts && compass); h.fail(facts); h.fail(compass); await h.tick();
  await Promise.all([openRun, ...h.original.pendingPageLoads()]); await h.tick();
  assert.equal(h.refs.homeRevenueFactLayer.value, null); assert.equal(h.refs.homeRevenueFactLayerError.value, 'Failed to fetch');
  assert.equal(h.refs.homeRevenueFactLayerLoading.value, false); assert.equal(h.refs.operatingLoop.value, null);
  assert.equal(h.refs.operatingLoopError.value, 'Failed to fetch'); assert.equal(h.refs.compassLoading.value, false);
  const final = await h.inspect();
  assert.doesNotMatch(final.html, /hotel-user-authorization-modal/, 'Cancelled read must not open a stale assignment dialog on another page');
  assert.equal(h.refs.showHotelUserAuthorizationModal.value, false);
  assert.deepEqual(clone(h.refs.hotelUserAuthorizationUserIds.value), []);
  assert.equal(h.refs.hotelUserAuthorizationTarget.value, null);
  assert.equal(h.refs.hotelUserAuthorizationError.value, ''); assert.equal(h.refs.hotelUserAuthorizationLoading.value, false);
  assert.equal(h.refs.toast.value.show, false, 'No cancellation/error toast from stale authorization opening');
  assert.equal(h.expectedErrors.length, 4, 'Two original request diagnostics and two original dashboard consumer diagnostics');
  assert.deepEqual(h.runtimeErrors, []);
  assert.ok(h.requests.every(call => call.settled && !call.teardown_only));
  assert.deepEqual(clone(h.original.coordinatorState()), { active: 0, queued: 0, entries: 0, consumers: 0 });
});

test('late successful user-list response after navigation cancellation cannot reopen authorization context', async t => {
  const h = harness(t, { ignoreAbortForUsers: true }), initial = await h.inspect();
  const usersBefore = clone(h.refs.users.value);
  const entry = initial.rows.find(row => row.node.type === 'button' && textOf(row.node).trim() === '授权用户'
    && row.parents.some(node => node.type === 'tr' && Number(node.key) === 80));
  assert.ok(entry && h.enabled(entry), 'Original visible desktop A authorization button');
  const openRun = entry.node.props.onClick(); h.flows.push(openRun); await h.tick();
  const usersRequest = h.requests[0];
  assert.equal(usersRequest.pathname, '/api/users'); assert.equal(usersRequest.settled, false);
  assert.equal(h.refs.hotelUserAuthorizationLoading.value, true);
  const loading = await h.inspect();
  const nav = loading.rows.find(row => row.node.type === 'a' && row.node.props?.['data-testid'] === 'nav-operating-loop-kernel');
  assert.ok(nav && h.enabled(nav), 'Original sidebar remains reachable during user-list loading');
  nav.node.props.onClick(); await h.tick();
  assert.equal(h.refs.currentPage.value, 'compass');
  assert.equal(h.original.generation(), 1);
  assert.equal(usersRequest.aborted, true, 'Original coordinator signaled cancellation');
  assert.equal(usersRequest.settled, false, 'Synthetic transport deliberately keeps its already-racing response pending');
  const facts = h.requests.find(call => call.pathname === '/api/dashboard/revenue-facts');
  const compass = h.requests.find(call => call.pathname === '/api/compass');
  assert.ok(facts && compass, 'Original current-page readers still issue scoped GETs');
  h.fail(facts); h.fail(compass); await h.tick();
  await Promise.all([openRun, ...h.original.pendingPageLoads()]); await h.tick();
  assert.equal(h.refs.hotelUserAuthorizationLoading.value, false, 'Cancelled consumer releases its busy state without waiting for transport');
  assert.equal(h.refs.showHotelUserAuthorizationModal.value, false);
  assert.equal(h.refs.hotelUserAuthorizationTarget.value, null);
  assert.deepEqual(clone(h.refs.hotelUserAuthorizationUserIds.value), []);
  h.replyUsers(usersRequest); await h.tick();
  assert.equal(usersRequest.aborted, true); assert.equal(usersRequest.settled, true);
  assert.deepEqual(clone(h.refs.users.value), usersBefore, 'Late response has no surviving consumer and cannot rewrite user context');
  assert.equal(h.refs.hotelUserAuthorizationLoading.value, false);
  assert.equal(h.refs.showHotelUserAuthorizationModal.value, false);
  assert.equal(h.refs.hotelUserAuthorizationTarget.value, null);
  assert.equal(h.refs.hotelUserAuthorizationError.value, '');
  assert.equal(h.refs.toast.value.show, false);
  assert.deepEqual(h.runtimeErrors, []);
  assert.ok(h.requests.every(call => call.settled && !call.teardown_only));
  assert.deepEqual(clone(h.original.coordinatorState()), { active: 0, queued: 0, entries: 0, consumers: 0 });
});

test('current user-list failure can retry and the original header navigation does not retain busy', async t => {
  const h = harness(t);
  const rowButton = async () => (await h.inspect()).rows.find(row => row.node.type === 'button'
    && textOf(row.node).trim() === '授权用户' && row.parents.some(node => node.type === 'tr' && Number(node.key) === 80));
  let entry = await rowButton(); assert.ok(entry && h.enabled(entry));
  const failed = entry.node.props.onClick(); h.flows.push(failed); await h.tick();
  assert.equal(h.requests.length, 1); h.replyUsers(h.requests[0], { failure: true }); await failed; await h.tick();
  assert.equal(h.refs.showHotelUserAuthorizationModal.value, false);
  assert.equal(h.refs.hotelUserAuthorizationLoading.value, false);
  assert.equal(h.refs.hotelUserAuthorizationError.value, '服务暂时不可用');
  assert.equal(h.refs.toast.value.message, '服务暂时不可用');
  assert.equal(h.refs.toast.value.type, 'error'); assert.equal(h.expectedErrors.length, 1);
  assert.deepEqual(clone(h.refs.users.value), [previousUser], 'Failure retains the prior snapshot without opening it as current');

  entry = await rowButton(); assert.ok(entry && h.enabled(entry), 'Original row allows explicit retry');
  const retried = entry.node.props.onClick(); h.flows.push(retried); await h.tick();
  assert.equal(h.requests.length, 2); h.replyUsers(h.requests[1]); await retried; await h.tick();
  assert.equal(h.refs.hotelUserAuthorizationError.value, ''); assert.equal(h.refs.hotelUserAuthorizationLoading.value, false);
  assert.equal(h.refs.hotelUserAuthorizationTarget.value.id, '80');
  assert.deepEqual(clone(h.refs.hotelUserAuthorizationUserIds.value), ['8101']);
  const shown = await h.inspect(); assert.match(shown.html, /门店：Synthetic hotel A/);
  assert.match(shown.html, /type="checkbox"[^>]*checked|checked[^>]*type="checkbox"/);
  const close = shown.rows.find(row => row.node.type === 'button' && textOf(row.node).trim() === '取消');
  assert.ok(close && h.enabled(close)); close.node.props.onClick(); await h.tick();
  assert.equal(h.refs.showHotelUserAuthorizationModal.value, false);

  const header = (await h.inspect()).rows.find(row => row.node.type === 'button' && textOf(row.node).trim() === '用户授权');
  assert.ok(header && h.enabled(header), 'Original header is reachable only after explicit modal close');
  const headerRun = header.node.props.onClick(); h.flows.push(headerRun); await h.tick();
  assert.equal(h.requests.length, 3); h.replyUsers(h.requests[2]); await headerRun; await h.tick();
  assert.equal(h.refs.currentPage.value, 'users', 'Original no-hotel branch navigates to user management');
  assert.equal(h.refs.hotelUserAuthorizationLoading.value, false, 'Its own navigation must not prevent owner cleanup');
  assert.equal(h.refs.showHotelUserAuthorizationModal.value, false); assert.equal(h.refs.filterUserRoleId.value, '2');
  assert.equal(h.requests.length, 4, 'Original users-page watcher starts its users refresh; roles/hotels already exist');
  h.replyUsers(h.requests[3]); await Promise.all(h.original.pendingPageLoads()); await h.tick();
  assert.equal(h.refs.usersLoading.value, false); assert.equal(h.refs.usersLoadError.value, '');
  assert.deepEqual(h.runtimeErrors, []); assert.ok(h.requests.every(call => call.settled && !call.aborted && !call.teardown_only));
  assert.deepEqual(clone(h.original.coordinatorState()), { active: 0, queued: 0, entries: 0, consumers: 0 });
});

test('original hotel assignment saves one exact change and locks the visible draft until readback', async t => {
  const h = harness(t, { allowAuthorizationSave: true });
  const modal = await openSyntheticHotelAuthorization(h, [unassignedUser]);
  assert.deepEqual(clone(h.refs.hotelUserAuthorizationUserIds.value), []);
  const checkbox = modal.rows.find(row => row.node.type === 'input' && row.node.props?.type === 'checkbox'
    && row.node.props?.value === '8101');
  assert.ok(checkbox && h.enabled(checkbox), 'Original modal exposes the synthetic unassigned beta user');
  const updateSelection = checkbox.node.props['onUpdate:modelValue'];
  assert.equal(typeof updateSelection, 'function', 'Original checkbox owns its Vue model update');
  updateSelection(['8101']); await h.tick();
  assert.deepEqual(clone(h.refs.hotelUserAuthorizationUserIds.value), ['8101']);
  const save = (await h.inspect()).rows.find(row => row.node.type === 'button' && textOf(row.node).trim() === '保存分配');
  assert.ok(save && h.enabled(save), 'Original explicit save action');
  const saveRun = save.node.props.onClick(); h.flows.push(saveRun); await h.tick();
  const posts = h.requests.filter(call => call.pathname === '/api/users/hotel-assignments');
  assert.equal(posts.length, 1, 'One explicit submit produces one atomic assignment request');
  assert.equal(posts[0].method, 'POST');
  assert.deepEqual(posts[0].body, { changes: [{ user_id: 8101, hotel_ids: [80] }] });
  assert.equal(h.refs.hotelUserAuthorizationSaving.value, true);
  const pending = await h.inspect();
  const pendingCheckbox = pending.rows.find(row => row.node.type === 'input' && row.node.props?.type === 'checkbox'
    && row.node.props?.value === '8101');
  assert.ok(pendingCheckbox);
  assert.equal(pendingCheckbox.node.props.disabled, true, 'The submitted authorization draft cannot be edited while POST/readback is pending');
  const pendingSave = pending.rows.find(row => row.node.type === 'button' && textOf(row.node).includes('原子保存并回读中'));
  assert.ok(pendingSave && !h.enabled(pendingSave));
  const cancel = pending.rows.find(row => row.node.type === 'button' && textOf(row.node).trim() === '取消');
  assert.ok(cancel && !h.enabled(cancel));
  h.replyAssignment(posts[0], { users: [previousUser] }); await h.tick();
  const readback = h.requests.filter(call => call.pathname === '/api/users' && !call.settled).at(-1);
  assert.ok(readback, 'Committed assignment requires a separate exact user-list readback');
  h.replyUsers(readback, { users: [previousUser] }); await saveRun; await h.tick();
  assert.equal(h.refs.hotelUserAuthorizationSaving.value, false);
  assert.equal(h.refs.showHotelUserAuthorizationModal.value, false);
  assert.equal(h.refs.hotelUserAuthorizationTarget.value, null);
  assert.deepEqual(clone(h.refs.users.value), [previousUser]);
  assert.equal(h.refs.toast.value.type, 'success');
  assert.equal(h.refs.toast.value.message, '门店用户分配已保存并完成回读验证');
  assert.equal(h.requests.filter(call => call.pathname === '/api/users/hotel-assignments').length, 1);
  assert.deepEqual(h.runtimeErrors, []);
  assert.ok(h.requests.every(call => call.settled && !call.teardown_only));
});

test('rejected hotel assignment preserves the draft and only a manual retry sends another POST', async t => {
  const h = harness(t, { allowAuthorizationSave: true });
  await openSyntheticHotelAuthorization(h, [unassignedUser]);
  const checkbox = (await h.inspect()).rows.find(row => row.node.type === 'input' && row.node.props?.type === 'checkbox'
    && row.node.props?.value === '8101');
  checkbox.node.props['onUpdate:modelValue'](['8101']); await h.tick();
  const clickSave = async () => {
    const save = (await h.inspect()).rows.find(row => row.node.type === 'button' && textOf(row.node).trim() === '保存分配');
    assert.ok(save && h.enabled(save));
    const run = save.node.props.onClick(); h.flows.push(run); await h.tick(); return { run };
  };
  const { run: firstRun } = await clickSave();
  let posts = h.requests.filter(call => call.pathname === '/api/users/hotel-assignments');
  assert.equal(posts.length, 1); assert.deepEqual(posts[0].body, { changes: [{ user_id: 8101, hotel_ids: [80] }] });
  h.replyAssignment(posts[0], { failure: true }); await firstRun; await h.tick();
  assert.equal(h.refs.hotelUserAuthorizationSaving.value, false);
  assert.equal(h.refs.showHotelUserAuthorizationModal.value, true);
  assert.deepEqual(clone(h.refs.hotelUserAuthorizationUserIds.value), ['8101']);
  assert.equal(h.refs.hotelUserAuthorizationError.value, '服务暂时不可用');
  assert.equal(h.refs.toast.value.type, 'error');
  assert.equal(h.requests.filter(call => call.pathname === '/api/users').length, 1, 'Rejected POST does not trigger a false readback');
  assert.equal(posts.length, 1, 'No automatic POST retry occurs after the rejection');

  const { run: retryRun } = await clickSave();
  posts = h.requests.filter(call => call.pathname === '/api/users/hotel-assignments');
  assert.equal(posts.length, 2, 'Only the explicit second button click creates a second POST');
  assert.deepEqual(posts[1].body, posts[0].body);
  h.replyAssignment(posts[1], { users: [previousUser] }); await h.tick();
  const readback = h.requests.filter(call => call.pathname === '/api/users' && !call.settled).at(-1);
  assert.ok(readback); h.replyUsers(readback, { users: [previousUser] }); await retryRun; await h.tick();
  assert.equal(h.refs.showHotelUserAuthorizationModal.value, false);
  assert.equal(h.refs.hotelUserAuthorizationSaving.value, false);
  assert.equal(h.refs.toast.value.message, '门店用户分配已保存并完成回读验证');
  assert.deepEqual(h.runtimeErrors, []);
  assert.ok(h.requests.every(call => call.settled && !call.teardown_only));
});

test('assignment echo mismatch remains unverified and a committed readback failure needs an explicit retry', async t => {
  const h = harness(t, { allowAuthorizationSave: true });
  await openSyntheticHotelAuthorization(h, [unassignedUser]);
  const checkbox = (await h.inspect()).rows.find(row => row.node.type === 'input' && row.node.props?.type === 'checkbox'
    && row.node.props?.value === '8101');
  checkbox.node.props['onUpdate:modelValue'](['8101']); await h.tick();
  const clickSave = async () => {
    const save = (await h.inspect()).rows.find(row => row.node.type === 'button' && textOf(row.node).trim() === '保存分配');
    assert.ok(save && h.enabled(save));
    const run = save.node.props.onClick(); h.flows.push(run); await h.tick(); return { run };
  };

  const { run: mismatchRun } = await clickSave();
  const mismatchPost = h.requests.filter(call => call.pathname === '/api/users/hotel-assignments').at(-1);
  h.replyAssignment(mismatchPost, { users: [unassignedUser] }); await mismatchRun; await h.tick();
  assert.match(h.refs.hotelUserAuthorizationError.value, /保存接口回显与提交结果不一致/);
  assert.equal(h.refs.toast.value.type, 'warning');
  assert.equal(h.refs.showHotelUserAuthorizationModal.value, true);
  assert.deepEqual(clone(h.refs.hotelUserAuthorizationUserIds.value), ['8101']);
  assert.equal(h.requests.filter(call => call.pathname === '/api/users').length, 1, 'Unverified echo cannot be presented as readback');
  assert.equal(h.requests.filter(call => call.pathname === '/api/users/hotel-assignments').length, 1);

  const { run: committedRun } = await clickSave();
  const committedPost = h.requests.filter(call => call.pathname === '/api/users/hotel-assignments').at(-1);
  h.replyAssignment(committedPost, { users: [previousUser] }); await h.tick();
  const failedReadback = h.requests.filter(call => call.pathname === '/api/users' && !call.settled).at(-1);
  assert.ok(failedReadback); h.replyUsers(failedReadback, { failure: true }); await committedRun; await h.tick();
  assert.match(h.refs.hotelUserAuthorizationError.value, /^分配已保存，但回读确认失败：服务暂时不可用$/);
  assert.equal(h.refs.toast.value.type, 'warning');
  assert.equal(h.refs.hotelUserAuthorizationSaving.value, false);
  assert.equal(h.refs.showHotelUserAuthorizationModal.value, true);
  assert.deepEqual(clone(h.refs.hotelUserAuthorizationUserIds.value), ['8101']);
  assert.equal(h.requests.filter(call => call.pathname === '/api/users/hotel-assignments').length, 2,
    'A committed but unreadable assignment does not automatically post again');

  const { run: retryRun } = await clickSave();
  const posts = h.requests.filter(call => call.pathname === '/api/users/hotel-assignments');
  assert.equal(posts.length, 3, 'The operator explicitly clicked save to retry after the warning');
  assert.deepEqual(posts[2].body, posts[1].body);
  h.replyAssignment(posts[2], { users: [previousUser] }); await h.tick();
  const recoveredReadback = h.requests.filter(call => call.pathname === '/api/users' && !call.settled).at(-1);
  assert.ok(recoveredReadback); h.replyUsers(recoveredReadback, { users: [previousUser] }); await retryRun; await h.tick();
  assert.equal(h.refs.hotelUserAuthorizationSaving.value, false);
  assert.equal(h.refs.showHotelUserAuthorizationModal.value, false);
  assert.equal(h.refs.toast.value.message, '门店用户分配已保存并完成回读验证');
  assert.equal(h.requests.filter(call => call.pathname === '/api/users/hotel-assignments').length, 3);
  assert.deepEqual(h.runtimeErrors, []);
  assert.ok(h.requests.every(call => call.settled && !call.teardown_only));
});

const submitCrossHotelGrant = async h => {
  await openSyntheticHotelAuthorization(h, [userWithHotels([81])]);
  const checkbox = (await h.inspect()).rows.find(row => row.node.type === 'input' && row.node.props?.type === 'checkbox'
    && row.node.props?.value === '8101');
  assert.ok(checkbox && h.enabled(checkbox));
  checkbox.node.props['onUpdate:modelValue'](['8101']); await h.tick();
  const save = (await h.inspect()).rows.find(row => row.node.type === 'button' && textOf(row.node).trim() === '保存分配');
  assert.ok(save && h.enabled(save));
  const run = save.node.props.onClick(); h.flows.push(run); await h.tick();
  const post = h.requests.filter(call => call.pathname === '/api/users/hotel-assignments').at(-1);
  assert.ok(post && !post.settled);
  assert.deepEqual(post.body, { changes: [{ user_id: 8101, hotel_ids: [81, 80] }] },
    'The original modal submits both the new target and the pre-existing other hotel');
  return { run, post };
};

test('cross-hotel assignment echo missing an existing hotel remains unverified', async t => {
  const h = harness(t, { allowAuthorizationSave: true });
  const { run, post } = await submitCrossHotelGrant(h);
  h.replyAssignment(post, { users: [userWithHotels([80])] }); await h.tick();
  assert.equal(h.requests.filter(call => call.pathname === '/api/users').length, 1,
    'An echo that lost hotel 81 must fail before the separate readback GET');
  await run; await h.tick();
  assert.equal(h.refs.showHotelUserAuthorizationModal.value, true);
  assert.match(h.refs.hotelUserAuthorizationError.value, /回显与提交结果不一致/);
  assert.equal(h.refs.toast.value.type, 'warning');
  assert.deepEqual(clone(h.refs.hotelUserAuthorizationUserIds.value), ['8101']);
  assert.deepEqual(h.runtimeErrors, []);
});

test('cross-hotel assignment readback missing an existing hotel does not claim success', async t => {
  const h = harness(t, { allowAuthorizationSave: true });
  const { run, post } = await submitCrossHotelGrant(h);
  h.replyAssignment(post, { users: [userWithHotels([81, 80])] }); await h.tick();
  const readback = h.requests.filter(call => call.pathname === '/api/users' && !call.settled).at(-1);
  assert.ok(readback); h.replyUsers(readback, { users: [userWithHotels([80])] }); await run; await h.tick();
  assert.equal(h.refs.showHotelUserAuthorizationModal.value, true);
  assert.match(h.refs.hotelUserAuthorizationError.value, /保存后回读结果不一致/);
  assert.equal(h.refs.toast.value.type, 'warning');
  assert.deepEqual(clone(h.refs.hotelUserAuthorizationUserIds.value), ['8101']);
  assert.deepEqual(h.runtimeErrors, []);
});

test('cross-hotel assignment accepts the complete hotel set in legacy field order', async t => {
  const h = harness(t, { allowAuthorizationSave: true });
  const { run, post } = await submitCrossHotelGrant(h);
  const legacyResponse = { ...userWithHotels([80, 81]), hotel_ids: [] };
  h.replyAssignment(post, { users: [legacyResponse] }); await h.tick();
  const readback = h.requests.filter(call => call.pathname === '/api/users' && !call.settled).at(-1);
  assert.ok(readback); h.replyUsers(readback, { users: [legacyResponse] }); await run; await h.tick();
  assert.equal(h.refs.showHotelUserAuthorizationModal.value, false);
  assert.equal(h.refs.toast.value.type, 'success');
  assert.equal(h.refs.toast.value.message, '门店用户分配已保存并完成回读验证');
  assert.deepEqual(h.runtimeErrors, []);
});

test('cross-hotel removal preserves the other hotel in POST and exact readback', async t => {
  const h = harness(t, { allowAuthorizationSave: true });
  await openSyntheticHotelAuthorization(h, [userWithHotels([80, 81])]);
  assert.deepEqual(clone(h.refs.hotelUserAuthorizationUserIds.value), ['8101']);
  const checkbox = (await h.inspect()).rows.find(row => row.node.type === 'input' && row.node.props?.type === 'checkbox'
    && row.node.props?.value === '8101');
  assert.ok(checkbox && h.enabled(checkbox));
  checkbox.node.props['onUpdate:modelValue']([]); await h.tick();
  const save = (await h.inspect()).rows.find(row => row.node.type === 'button' && textOf(row.node).trim() === '保存分配');
  const run = save.node.props.onClick(); h.flows.push(run); await h.tick();
  const post = h.requests.filter(call => call.pathname === '/api/users/hotel-assignments').at(-1);
  assert.deepEqual(post.body, { changes: [{ user_id: 8101, hotel_ids: [81] }] });
  h.replyAssignment(post, { users: [userWithHotels([81])] }); await h.tick();
  const readback = h.requests.filter(call => call.pathname === '/api/users' && !call.settled).at(-1);
  assert.ok(readback); h.replyUsers(readback, { users: [userWithHotels([81])] }); await run; await h.tick();
  assert.equal(h.refs.showHotelUserAuthorizationModal.value, false);
  assert.equal(h.refs.toast.value.type, 'success');
  assert.deepEqual(h.runtimeErrors, []);
});

test('assignment readback starts a fresh GET after a concurrent pre-commit users request', async t => {
  const h = harness(t, { allowAuthorizationSave: true, ignoreAbortForUsers: true });
  const { run: saveRun, post } = await submitCrossHotelGrant(h);
  const preCommitRun = h.original.loadUsers(); h.flows.push(preCommitRun); await h.tick();
  const initialAndPreCommitGets = h.requests.filter(call => call.pathname === '/api/users');
  assert.equal(initialAndPreCommitGets.length, 2);
  const preCommitGet = initialAndPreCommitGets[1];
  assert.equal(preCommitGet.settled, false);
  h.replyAssignment(post, { users: [userWithHotels([81, 80])] }); await h.tick();
  const usersGets = h.requests.filter(call => call.pathname === '/api/users');
  assert.equal(usersGets.length, 3,
    'The post-commit confirmation must not join a users GET started before the assignment was saved');
  assert.equal(preCommitGet.aborted, true, 'The previous read is superseded, even if its transport returns late');
  const freshReadback = usersGets[2];
  assert.equal(freshReadback.settled, false);
  h.replyUsers(freshReadback, { users: [userWithHotels([81, 80])] }); await saveRun; await h.tick();
  assert.equal(h.refs.showHotelUserAuthorizationModal.value, false);
  assert.equal(h.refs.toast.value.type, 'success');
  assert.deepEqual(clone(h.refs.users.value), [userWithHotels([81, 80])]);
  h.replyUsers(preCommitGet, { users: [userWithHotels([81])] }); await preCommitRun; await h.tick();
  assert.deepEqual(clone(h.refs.users.value), [userWithHotels([81, 80])],
    'A late pre-commit 200 cannot replace the exact post-commit projection');
  assert.deepEqual(h.runtimeErrors, []);
});

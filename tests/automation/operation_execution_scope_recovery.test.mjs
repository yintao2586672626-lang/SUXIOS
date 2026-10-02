import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync('public/app-main.js', 'utf8');
const systemSource = fs.readFileSync('public/system-static.js', 'utf8');
const section = (start, end) => source.slice(source.indexOf(start), source.indexOf(end, source.indexOf(start)));
const deferred = () => {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
};
const flush = async () => { for (let i = 0; i < 10; i++) await Promise.resolve(); };
const memoryList = (list, overrides = {}) => ({
  data_status: 'ok', list, count: list.length, returned_count: list.length,
  matched_total: list.length, truncated: false, data_gaps: [], ...overrides,
});
export const flowPayload = (list, hotelId = null, overrides = {}) => ({
  capabilities: { hotel_id: hotelId }, summary: {}, stages: [], list,
  data_status: 'ok', data_gaps: [], matched_total: list.length,
  returned_count: list.length, truncated: false,
  statistics: { execution_total_loaded: true }, ...overrides,
});
export const actionPayload = (actions, overrides = {}) => ({
  actions, effect_validation: { status: 'data_gap', metrics: [], data_gaps: [], action_counts: {} },
  data_status: 'ok', data_gaps: [], matched_total: actions.length,
  returned_count: actions.length, truncated: false, ...overrides,
});

export function harness({ hotel = '', ready, fetch } = {}) {
  const ref = value => ({ value });
  const calls = [], toasts = [];
  const system = { window: {}, console, setTimeout, clearTimeout };
  vm.runInNewContext(systemSource, system);
  const context = vm.createContext({
    URLSearchParams, Set, FormData,
    epoch: 1,
    pageRequestGeneration: 1,
    globalBusinessDate: '2026-09-07',
    policyTenantId: '770',
    BUSINESS_CONTEXT_ENDPOINT_PREFIXES: ['/operation/'],
    STRICT_OTA_MANUAL_EXECUTION_PATHS: new Set(),
    authContext: ref({ permissionStatus: 'allowed', hotelId: '77', tenantId: '770', platform: 'meituan' }),
    user: ref({ is_super_admin: true }),
    filterReportHotel: ref('77'),
    permittedHotels: ref([{ id: 7, tenant_id: 70 }, { id: 8, tenant_id: 80 }, { id: 77, tenant_id: 770 }]),
    requireAppSystemStatic: key => system.window.SUXI_SYSTEM_STATIC[key],
    operationActionsRequestSeq: 0,
    currentPage: ref('ops-track'),
    operationFilters: ref({ hotel_id: hotel }),
    operationYesterday: '2026-09-07', shanghaiBusinessYesterday: '2026-09-07',
    operationLoading: ref({ actions: false }),
    operationError: ref({ actions: '' }),
    operatingGoalInterventionLoading: ref(false),
    operatingGoalInterventionError: ref(''),
    operationExecutionViewMode: ref('all'),
    operationExecutionFlow: ref({ list: [{ id: 99, hotel_id: 77 }], data_status: 'ok' }),
    operationActions: ref([{ id: 99, hotel_id: 77 }]),
    operationActionTrackingRead: ref({ data_status: 'ok' }),
    operationApprovalConfirmingIntentId: ref(0),
    operationEffectValidation: ref({}),
    operationClosureOverview: ref({}),
    operatingGoalInterventionOverview: ref({}),
    homeOperatingScheduleError: ref(''),
    captureAuthSession: () => context.epoch,
    isAuthSessionCurrent: epoch => epoch === context.epoch,
    currentPageReadPolicy: () => ({
      scope: 'page', pageKey: context.currentPage.value, pageGeneration: context.pageRequestGeneration,
      sessionEpoch: context.epoch, tenantId: context.policyTenantId,
      systemHotelId: context.filterReportHotel.value, businessDate: context.globalBusinessDate,
    }),
    ensureOperationStaticReady: async () => { await ready?.(); },
    normalizeOperationHotelSelection: form => form.value.hotel_id,
    loadOperatingMemories: async () => {},
    applyHomeOperatingScheduleFlow: () => {},
    operationErrorMessage: error => error.message,
    showToast: message => toasts.push(message),
  });
  vm.runInContext(section('const businessContextRequestPath =', 'const userHasPermission =')
    + '\nglobalThis.contextualize = withBusinessRequestContext;', context);
  context.apiRequest = async (path, options = {}) => {
    const scoped = context.contextualize(path, options);
    calls.push(scoped);
    if (fetch) return fetch(scoped);
    const url = new URL(scoped.url, 'http://fixture.invalid');
    const hotelId = Number(url.searchParams.get('hotel_id') || url.searchParams.get('system_hotel_id')) || null;
    const isFlow = /\/(execution-flow|my-tasks)$/.test(url.pathname);
    if (url.pathname.endsWith('/closure-overview')) return { code: 200, data: {
      summary: { status: 'unverified' }, modules: [], data_gaps: [], data_status: 'ok',
    } };
    return { code: 200, data: isFlow
      ? flowPayload((hotelId ? [hotelId] : [7, 8]).map(id => ({ id, hotel_id: id })), hotelId)
      : actionPayload([]) };
  };
  vm.runInContext(section('const loadOperationActions = async', 'const parseOperationEvidenceNumber =')
    + '\nglobalThis.loadFlow = loadOperationActions;', context);
  return { context, calls, toasts };
}

test('all permitted hotels never inherit the home hotel, tenant or platform filter', async () => {
  const h = harness();
  assert.equal(await h.context.loadFlow(), true);
  for (const call of h.calls) {
    const url = new URL(call.url, 'http://fixture.invalid');
    for (const key of ['hotel_id', 'system_hotel_id', 'tenant_id', 'platform']) assert.equal(url.searchParams.has(key), false, call.url);
    assert.equal(call.options.requestPolicy.systemHotelId, '');
    assert.equal(call.options.requestPolicy.businessDate, '');
    assert.equal(call.options.requestPolicy.tenantId, '770');
    assert.equal(call.options.requestPolicy.scope, 'page');
    assert.equal(call.options.requestPolicy.pageGeneration, 1);
    assert.equal(call.options.requestPolicy.sessionEpoch, 1);
  }
  assert.deepEqual(Array.from(h.context.operationExecutionFlow.value.list, row => row.hotel_id), [7, 8]);
});

test('a selected hotel is explicit and does not borrow another hotels tenant or channel', async () => {
  const h = harness({ hotel: '7' });
  assert.equal(await h.context.loadFlow(), true);
  for (const call of h.calls) {
    const params = new URL(call.url, 'http://fixture.invalid').searchParams;
    assert.equal(params.get('hotel_id'), '7');
    assert.equal(params.get('system_hotel_id'), '7');
    assert.equal(params.has('tenant_id'), false);
    assert.equal(params.has('platform'), false);
  }
});

test('a selected hotel rejects action-tracking rows from another hotel', async () => {
  const h = harness({ hotel: '7', fetch: async ({ url }) => {
    const path = new URL(url, 'http://fixture.invalid').pathname;
    if (path.endsWith('/execution-flow')) return { code: 200, data: flowPayload([{ id: 71, hotel_id: 7 }], 7) };
    if (path.endsWith('/action-tracking')) return { code: 200, data: actionPayload([{ id: 81, hotel_id: 8 }]) };
    if (path.endsWith('/goal-intervention-overview')) return { code: 200, data: { hotel_id: 7 } };
    return { code: 200, data: { summary: { status: 'unverified' }, modules: [], data_gaps: [], data_status: 'ok' } };
  } });
  assert.equal(await h.context.loadFlow(), false);
  assert.equal(h.context.operationActions.value.length, 0);
  assert.match(h.context.operationError.value.actions, /酒店身份不一致/);
});

test('a selected hotel keeps matching action-tracking rows', async () => {
  const h = harness({ hotel: '7', fetch: async ({ url }) => {
    const path = new URL(url, 'http://fixture.invalid').pathname;
    if (path.endsWith('/execution-flow')) return { code: 200, data: flowPayload([{ id: 71, hotel_id: 7 }], 7) };
    if (path.endsWith('/action-tracking')) return { code: 200, data: actionPayload([{ id: 71, hotel_id: 7 }]) };
    if (path.endsWith('/goal-intervention-overview')) return { code: 200, data: { hotel_id: 7 } };
    return { code: 200, data: { summary: { status: 'unverified' }, modules: [], data_gaps: [], data_status: 'ok' } };
  } });
  assert.equal(await h.context.loadFlow(), true);
  assert.equal(h.context.operationActions.value[0].hotel_id, 7);
});

test('a new scope clears old rows before deferred helpers finish loading', async () => {
  const gate = deferred();
  const h = harness({ hotel: '7', ready: () => gate.promise });
  const pending = h.context.loadFlow();
  assert.equal(h.context.operationExecutionFlow.value.list.length, 0);
  assert.equal(h.context.operationActions.value.length, 0);
  assert.equal(h.context.operationLoading.value.actions, true);
  gate.resolve(); await pending;
});

test('helper failure is visible, recoverable and does not leave a loading flag stuck', async () => {
  const h = harness({ ready: () => { throw new Error('helper unavailable'); } });
  assert.equal(await h.context.loadFlow(), false);
  assert.match(h.context.operationError.value.actions, /helper unavailable/);
  assert.equal(h.context.operationExecutionFlow.value.data_status, 'load_failed');
  assert.equal(h.context.operationLoading.value.actions, false);
  assert.equal(h.calls.length, 0);
});

test('a response from an ended login session cannot repopulate the task list', async () => {
  const gate = deferred();
  const h = harness({ fetch: () => gate.promise });
  const pending = h.context.loadFlow(); await flush();
  h.context.epoch++;
  gate.resolve({ code: 200, data: { capabilities: { hotel_id: null }, list: [{ id: 7, hotel_id: 7 }] } });
  await pending;
  assert.equal(h.context.operationExecutionFlow.value.list.length, 0);
  assert.equal(h.toasts.length, 0);
});

test('a stale completion cannot clear a newer scope loading state', async () => {
  const first = deferred(), second = deferred();
  const h = harness({ hotel: '7', fetch: call => call.url.includes('hotel_id=7') ? first.promise : second.promise });
  const old = h.context.loadFlow(); await flush();
  h.context.operationFilters.value.hotel_id = '8';
  const current = h.context.loadFlow(); await flush();
  first.resolve({ code: 200, data: { capabilities: { hotel_id: 7 }, list: [{ id: 7, hotel_id: 7 }] } });
  await old;
  assert.equal(h.context.operationLoading.value.actions, true);
  assert.equal(h.context.operationExecutionFlow.value.list.length, 0);
  second.resolve({ code: 200, data: flowPayload([{ id: 8, hotel_id: 8 }], 8) });
  await current;
  assert.equal(h.context.operationLoading.value.actions, false);
  assert.equal(h.context.operationExecutionFlow.value.list[0].hotel_id, 8);
});

test('an invalid all-hotel response remains a read failure instead of an empty success', async () => {
  const h = harness({ fetch: async () => ({ code: 200, data: {} }) });
  assert.equal(await h.context.loadFlow(), false);
  assert.equal(h.context.operationExecutionFlow.value.data_status, 'load_failed');
});

test('a 200 execution flow without quality and count cannot confirm zero tasks', async () => {
  const h = harness({ hotel: '7', fetch: async ({ url }) => {
    const path = new URL(url, 'http://fixture.invalid').pathname;
    if (path.endsWith('/execution-flow')) return { code: 200, data: { capabilities: { hotel_id: 7 }, list: [] } };
    if (path.endsWith('/action-tracking')) return { code: 200, data: { actions: [] } };
    if (path.endsWith('/goal-intervention-overview')) return { code: 200, data: { hotel_id: 7 } };
    return { code: 200, data: { summary: {}, modules: [], data_gaps: [], data_status: 'ok' } };
  } });
  assert.equal(await h.context.loadFlow(), false);
  assert.equal(h.context.operationExecutionFlow.value.data_status, 'load_failed');
  assert.match(h.context.operationError.value.actions, /执行闭环回读结构不完整/);
});

test('a partial execution flow retains a visible quality gap instead of complete zero', async () => {
  const gap = { code: 'operation_execution_flow_truncated', message: 'only first 100 rows' };
  const h = harness({ hotel: '7', fetch: async ({ url }) => {
    const path = new URL(url, 'http://fixture.invalid').pathname;
    if (path.endsWith('/execution-flow')) return { code: 200, data: flowPayload([], 7, {
      data_status: 'partial', data_gaps: [gap], matched_total: 1, truncated: true,
    }) };
    if (path.endsWith('/action-tracking')) return { code: 200, data: actionPayload([]) };
    if (path.endsWith('/goal-intervention-overview')) return { code: 200, data: { hotel_id: 7 } };
    return { code: 200, data: { summary: {}, modules: [], data_gaps: [], data_status: 'ok' } };
  } });
  assert.equal(await h.context.loadFlow(), true);
  assert.equal(h.context.operationExecutionFlow.value.data_status, 'partial');
  assert.equal(h.context.operationExecutionFlow.value.data_gaps[0].code, gap.code);
});

test('a migration gap remains distinct from an actual empty execution flow', async () => {
  const h = harness({ hotel: '7', fetch: async ({ url }) => {
    const path = new URL(url, 'http://fixture.invalid').pathname;
    if (path.endsWith('/execution-flow')) return { code: 200, data: flowPayload([], 7, {
      data_status: 'migration_required', data_gaps: [{ code: 'operation_execution_intents_missing' }],
      statistics: { execution_total_loaded: false },
    }) };
    if (path.endsWith('/action-tracking')) return { code: 200, data: actionPayload([]) };
    if (path.endsWith('/goal-intervention-overview')) return { code: 200, data: { hotel_id: 7 } };
    return { code: 200, data: { summary: {}, modules: [], data_gaps: [], data_status: 'ok' } };
  } });
  assert.equal(await h.context.loadFlow(), true);
  assert.equal(h.context.operationExecutionFlow.value.data_status, 'migration_required');
});

test('contradictory execution-flow totals cannot be presented as complete', async () => {
  const h = harness({ hotel: '7', fetch: async ({ url }) => {
    const path = new URL(url, 'http://fixture.invalid').pathname;
    if (path.endsWith('/execution-flow')) return { code: 200, data: flowPayload([], 7, { matched_total: 1 }) };
    if (path.endsWith('/action-tracking')) return { code: 200, data: { actions: [] } };
    if (path.endsWith('/goal-intervention-overview')) return { code: 200, data: { hotel_id: 7 } };
    return { code: 200, data: { summary: {}, modules: [], data_gaps: [], data_status: 'ok' } };
  } });
  assert.equal(await h.context.loadFlow(), false);
  assert.equal(h.context.operationExecutionFlow.value.data_status, 'load_failed');
});

test('execution list template displays quality notice before declaring no tasks', () => {
  const template = fs.readFileSync('resources/frontend/templates/fragments/17-page-ops-track.html', 'utf8');
  assert.match(template, /data-testid="operation-execution-read-notice"/);
  assert.match(template, /operationExecutionFlow\.data_status === 'ok'[^>]*>暂无执行闭环记录/);
  assert.match(source, /operationExecutionReadNotice = computed\(/);
});

test('a 200 action response without its list is a read failure, not confirmed zero tasks', async () => {
  const h = harness({ hotel: '7', fetch: async ({ url }) => {
    const path = new URL(url, 'http://fixture.invalid').pathname;
    if (path.endsWith('/execution-flow')) return { code: 200, data: flowPayload([], 7) };
    if (path.endsWith('/action-tracking')) return { code: 200, data: { data_status: 'ok' } };
    if (path.endsWith('/goal-intervention-overview')) return { code: 200, data: { hotel_id: 7 } };
    return { code: 200, data: {} };
  } });
  assert.equal(await h.context.loadFlow(), false);
  assert.deepEqual(Array.from(h.context.operationActions.value), []);
  assert.match(h.context.operationError.value.actions, /策略追踪回读结构不完整/);
});

test('a 200 strategy response without quality and count cannot confirm no history', async () => {
  const h = harness({ hotel: '7', fetch: async ({ url }) => {
    const path = new URL(url, 'http://fixture.invalid').pathname;
    if (path.endsWith('/execution-flow')) return { code: 200, data: flowPayload([], 7) };
    if (path.endsWith('/action-tracking')) return { code: 200, data: { actions: [] } };
    if (path.endsWith('/goal-intervention-overview')) return { code: 200, data: { hotel_id: 7 } };
    return { code: 200, data: { summary: {}, modules: [], data_gaps: [], data_status: 'ok' } };
  } });
  assert.equal(await h.context.loadFlow(), false);
  assert.equal(h.context.operationActions.value.length, 0);
  assert.match(h.context.operationError.value.actions, /策略追踪回读结构不完整/);
});

test('a partial strategy response keeps its 100 of 101 read limit', async () => {
  const gap = { code: 'operation_action_tracking_truncated', message: 'only 100 of 101' };
  const actions = Array.from({ length: 100 }, (_, index) => ({ id: index + 1, hotel_id: 7 }));
  const h = harness({ hotel: '7', fetch: async ({ url }) => {
    const path = new URL(url, 'http://fixture.invalid').pathname;
    if (path.endsWith('/execution-flow')) return { code: 200, data: flowPayload([], 7) };
    if (path.endsWith('/action-tracking')) return { code: 200, data: actionPayload(actions, {
      data_status: 'partial', data_gaps: [gap], matched_total: 101, truncated: true,
    }) };
    if (path.endsWith('/goal-intervention-overview')) return { code: 200, data: { hotel_id: 7 } };
    return { code: 200, data: { summary: {}, modules: [], data_gaps: [], data_status: 'ok' } };
  } });
  assert.equal(await h.context.loadFlow(), true);
  assert.equal(h.context.operationActionTrackingRead.value.data_status, 'partial');
  assert.equal(h.context.operationActionTrackingRead.value.matched_total, 101);
  assert.equal(h.context.operationActions.value.length, 100);
});

test('a missing strategy table remains a migration gap rather than no history', async () => {
  const h = harness({ hotel: '7', fetch: async ({ url }) => {
    const path = new URL(url, 'http://fixture.invalid').pathname;
    if (path.endsWith('/execution-flow')) return { code: 200, data: flowPayload([], 7) };
    if (path.endsWith('/action-tracking')) return { code: 200, data: actionPayload([], {
      data_status: 'migration_required', data_gaps: [{ code: 'operation_action_tracks_missing' }],
    }) };
    if (path.endsWith('/goal-intervention-overview')) return { code: 200, data: { hotel_id: 7 } };
    return { code: 200, data: { summary: {}, modules: [], data_gaps: [], data_status: 'ok' } };
  } });
  assert.equal(await h.context.loadFlow(), true);
  assert.equal(h.context.operationActionTrackingRead.value.data_status, 'migration_required');
});

test('strategy history template presents quality before any empty claim', () => {
  const template = fs.readFileSync('resources/frontend/templates/fragments/17-page-ops-track.html', 'utf8');
  assert.match(template, /data-testid="operation-action-read-notice"/);
  assert.match(template, /operationActionTrackingRead\.data_status === 'ok'[^>]*>暂无策略动作/);
});

test('a 200 closure response without its modules is a read failure, not a complete loop', async () => {
  const h = harness({ hotel: '7', fetch: async ({ url }) => {
    const path = new URL(url, 'http://fixture.invalid').pathname;
    if (path.endsWith('/execution-flow')) return { code: 200, data: flowPayload([], 7) };
    if (path.endsWith('/action-tracking')) return { code: 200, data: actionPayload([]) };
    if (path.endsWith('/goal-intervention-overview')) return { code: 200, data: { hotel_id: 7 } };
    return { code: 200, data: { summary: { status: 'unverified' }, data_status: 'ok', data_gaps: [] } };
  } });
  assert.equal(await h.context.loadFlow(), false);
  assert.match(h.context.operationError.value.actions, /闭环总览回读结构不完整/);
});

function memoryHarness(options = {}) {
  const h = harness(options);
  Object.assign(h.context, {
    operatingMemoryRequestSeq: 0,
    operatingMemoryLoading: { value: false },
    operatingMemoryError: { value: '' },
    operatingMemories: { value: { list: [{ id: 99, hotel_id: 77 }], data_status: 'ok' } },
  });
  vm.runInContext(section('const loadOperatingMemories = async', 'const saveOperationExecutionMemory = async')
    + '\nglobalThis.loadMemories = loadOperatingMemories;', h.context);
  return h;
}

test('a 200 memory list without quality or count cannot confirm no saved memories', async () => {
  const h = memoryHarness({ hotel: '7', fetch: async () => ({ code: 200, data: { list: [], data_gaps: [] } }) });
  assert.equal(await h.context.loadMemories(), null);
  assert.equal(h.context.operatingMemories.value.data_status, 'readback_failed');
  assert.match(h.context.operatingMemoryError.value, /经营记忆回读结构不完整/);
});

test('a 200 memory list with contradictory count cannot be shown as complete', async () => {
  const h = memoryHarness({ hotel: '7', fetch: async () => ({ code: 200, data: {
    data_status: 'ok', list: [], count: 1, returned_count: 1, matched_total: 1, truncated: false, data_gaps: [],
  } }) });
  assert.equal(await h.context.loadMemories(), null);
  assert.equal(h.context.operatingMemories.value.data_status, 'readback_failed');
});

test('a missing memory table remains an explicit migration gap rather than zero success', async () => {
  const gap = { code: 'operating_memory_table_missing', message: 'migration required' };
  const h = memoryHarness({ hotel: '7', fetch: async () => ({ code: 200, data: memoryList([], {
    data_status: 'migration_required', data_gaps: [gap],
  }) }) });
  const result = await h.context.loadMemories();
  assert.equal(result.data_status, 'migration_required');
  assert.equal(h.context.operatingMemories.value.data_gaps[0].code, gap.code);
  assert.equal(h.context.operatingMemoryError.value, '');
});

function coordinatorReply(h) {
  Object.assign(h.context, {
    coordinatedGetRequests: new Map(),
    coordinatedGetSuccessCache: new Map(),
    createRequestAbortError: () => Object.assign(new Error('scope changed'), { name: 'AbortError' }),
    settleCoordinatedGetConsumer: (entry, consumer, status, value) => consumer[status](value),
  });
  vm.runInContext(section('const finishCoordinatedGet =', 'const addCoordinatedGetConsumer =')
    + '\nglobalThis.finishRead = finishCoordinatedGet;', h.context);
  return (policy, value = { code: 200, data: {} }) => new Promise((resolve, reject) => {
    const consumer = { ...policy, requestSession: policy.sessionEpoch, resolve, reject };
    const entry = { key: 'fixture', ttlMs: 0, controller: { signal: { aborted: false } }, consumers: new Map([[1, consumer]]) };
    h.context.finishRead(entry, 'resolve', value);
  });
}

test('dashboard hotel and date hydration cannot abort a task read with an independent hotel selector', async () => {
  const gate = deferred();
  let settle;
  const h = harness({ hotel: '7', fetch: call => gate.promise.then(value => settle(call.options.requestPolicy, value)) });
  settle = coordinatorReply(h);
  const dashboardPolicy = h.context.currentPageReadPolicy();
  const pending = h.context.loadFlow();
  await flush();
  h.context.filterReportHotel.value = '8';
  h.context.globalBusinessDate = '2026-09-08';
  await assert.rejects(settle(dashboardPolicy), { name: 'AbortError' }, 'the previous default policy reproduces the hydration cancellation');
  gate.resolve({ code: 200, data: { ...flowPayload([{ id: 71, hotel_id: 7 }], 7), ...actionPayload([{ id: 71, hotel_id: 7 }]), modules: [] } });
  assert.equal(await pending, true);
  assert.equal(h.context.operationExecutionFlow.value.list[0].hotel_id, 7);
  assert.equal(h.context.operationError.value.actions, '');
});

test('dashboard hotel and date hydration cannot abort an independently scoped memory read', async () => {
  const gate = deferred();
  let settle;
  const h = memoryHarness({ hotel: '7', fetch: call => gate.promise.then(value => settle(call.options.requestPolicy, value)) });
  settle = coordinatorReply(h);
  const pending = h.context.loadMemories();
  h.context.filterReportHotel.value = '8';
  h.context.globalBusinessDate = '2026-09-08';
  gate.resolve({ code: 200, data: memoryList([{ id: 71, hotel_id: 7 }]) });
  const result = await pending;
  assert.equal(result.list[0].hotel_id, 7);
  assert.equal(h.context.operatingMemoryError.value, '');
});

test('independent task and memory policies still reject stale tenant, login, page and page-generation consumers', async () => {
  for (const kind of ['tasks', 'memories']) {
    for (const change of [
      context => { context.policyTenantId = '771'; },
      context => { context.epoch += 1; },
      context => { context.currentPage.value = 'compass'; },
      context => { context.pageRequestGeneration += 2; },
    ]) {
      const h = kind === 'tasks' ? harness({ hotel: '7' }) : memoryHarness({ hotel: '7', fetch: async () => ({ code: 200, data: memoryList([]) }) });
      if (kind === 'tasks') await h.context.loadFlow();
      else await h.context.loadMemories();
      const policy = h.calls[0].options.requestPolicy;
      const settle = coordinatorReply(h);
      change(h.context);
      await assert.rejects(settle(policy), { name: 'AbortError' });
    }
  }
});

test('all-hotel memories use the same explicit scope as the task list', async () => {
  const h = memoryHarness({ fetch: async () => ({ code: 200, data: memoryList([{ id: 1, hotel_id: 7 }]) }) });
  await h.context.loadMemories();
  const params = new URL(h.calls[0].url, 'http://fixture.invalid').searchParams;
  for (const key of ['system_hotel_id', 'hotel_id', 'tenant_id', 'platform']) assert.equal(params.has(key), false);
  assert.equal(h.calls[0].options.requestPolicy.systemHotelId, '');
  assert.equal(h.calls[0].options.requestPolicy.businessDate, '');
  assert.equal(h.calls[0].options.requestPolicy.tenantId, '770');
  assert.equal(h.calls[0].options.requestPolicy.scope, 'page');
  assert.equal(h.calls[0].options.requestPolicy.pageGeneration, 1);
  assert.equal(h.context.operatingMemories.value.list[0].hotel_id, 7);
});

test('a single-hotel memory read rejects rows from another hotel', async () => {
  const h = memoryHarness({ hotel: '7', fetch: async () => ({ code: 200, data: memoryList([{ id: 1, hotel_id: 8 }]) }) });
  assert.equal(await h.context.loadMemories(), null);
  assert.equal(h.context.operatingMemories.value.list.length, 0);
  assert.match(h.context.operatingMemoryError.value, /酒店身份不一致/);
});

test('late memories cannot repaint after logout or a scope change', async () => {
  for (const change of [context => { context.epoch++; }, context => { context.operationFilters.value.hotel_id = '8'; }]) {
    const gate = deferred();
    const h = memoryHarness({ hotel: '7', fetch: () => gate.promise });
    const pending = h.context.loadMemories();
    assert.equal(h.context.operatingMemories.value.list.length, 0);
    change(h.context);
    gate.resolve({ code: 200, data: memoryList([{ id: 1, hotel_id: 7 }]) });
    assert.equal(await pending, null);
    assert.equal(h.context.operatingMemories.value.list.length, 0);
    assert.equal(h.context.operatingMemoryError.value, '');
  }
});

test('a stale memory read cannot clear a newer read loading state', async () => {
  const first = deferred(), second = deferred();
  const h = memoryHarness({ hotel: '7', fetch: call => call.url.includes('hotel_id=7') ? first.promise : second.promise });
  const old = h.context.loadMemories();
  h.context.operationFilters.value.hotel_id = '8';
  const current = h.context.loadMemories();
  first.resolve({ code: 200, data: memoryList([{ id: 1, hotel_id: 7 }]) });
  await old;
  assert.equal(h.context.operatingMemoryLoading.value, true);
  second.resolve({ code: 200, data: memoryList([{ id: 2, hotel_id: 8 }]) });
  await current;
  assert.equal(h.context.operatingMemoryLoading.value, false);
  assert.equal(h.context.operatingMemories.value.list[0].hotel_id, 8);
});

test('saving a task memory and reading it back both use that task hotel from the all-hotel view', async () => {
  const memory = { id: 19, hotel_id: 7, source_record_id: 5, memory_layer: 'execution_review', content_digest: 'a'.repeat(64) };
  const h = harness({ fetch: async call => call.options.method === 'POST'
    ? { code: 200, data: { memory, persistence_status: 'readback_verified', write_boundaries: { ota_write: false, external_message: false } } }
    : { code: 200, data: memory } });
  Object.assign(h.context, {
    operatingMemorySavingTaskId: { value: 0 },
    operationCanSaveOperatingMemory: () => true,
    operationExecutionHotelId: item => Number(item.hotel_id || 0),
  });
  vm.runInContext(section('const saveOperationExecutionMemory = async', 'const canSaveMemo =')
    + '\nglobalThis.saveMemory = saveOperationExecutionMemory;', h.context);
  await h.context.saveMemory({ hotel_id: 7, execution: { task_id: 5 } });
  assert.equal(h.calls.length, 2);
  const body = JSON.parse(h.calls[0].options.body);
  assert.equal(Number(body.system_hotel_id), 7);
  assert.equal(Object.hasOwn(body, 'tenant_id'), false);
  assert.equal(Object.hasOwn(body, 'platform'), false);
  const params = new URL(h.calls[1].url, 'http://fixture.invalid').searchParams;
  assert.equal(params.get('system_hotel_id'), '7');
  assert.equal(params.has('tenant_id'), false);
  assert.equal(params.has('platform'), false);
  assert.match(h.toasts.at(-1), /严格回读/);
});

test('a system_hotel_id task accepts exact memory readback for the resolved hotel', async () => {
  const memory = { id: 19, hotel_id: 7, source_record_id: 5, memory_layer: 'execution_review', content_digest: 'a'.repeat(64) };
  for (const readbackHotelId of [7, 8]) {
    const h = harness({ fetch: async call => call.options.method === 'POST'
      ? { code: 200, data: { memory, persistence_status: 'readback_verified', write_boundaries: { ota_write: false, external_message: false } } }
      : { code: 200, data: { ...memory, hotel_id: readbackHotelId } } });
    Object.assign(h.context, {
      operatingMemorySavingTaskId: { value: 0 },
      operationCanSaveOperatingMemory: () => true,
      operationExecutionHotelId: item => Number(item.system_hotel_id || 0),
    });
    vm.runInContext(section('const saveOperationExecutionMemory = async', 'const canSaveMemo =')
      + '\nglobalThis.saveMemory = saveOperationExecutionMemory;', h.context);
    await h.context.saveMemory({ system_hotel_id: 7, execution: { task_id: 5 } });
    assert.equal(h.calls.length, 2);
    assert.match(h.toasts.at(-1), readbackHotelId === 7 ? /严格回读/ : /身份不一致/);
  }
});

test('memory POST confirmation with failed page GET reports pending readback, not failed save', async () => {
  const memory = { id: 19, hotel_id: 7, source_record_id: 5, memory_layer: 'execution_review', content_digest: 'a'.repeat(64) };
  const h = harness({ fetch: async call => call.options.method === 'POST'
    ? { code: 200, data: { memory, persistence_status: 'readback_verified', write_boundaries: { ota_write: false, external_message: false } } }
    : { code: 503, message: 'synthetic read unavailable' } });
  Object.assign(h.context, {
    operatingMemorySavingTaskId: { value: 0 },
    operationCanSaveOperatingMemory: () => true,
    operationExecutionHotelId: item => Number(item.hotel_id || 0),
  });
  vm.runInContext(section('const saveOperationExecutionMemory = async', 'const canSaveMemo =')
    + '\nglobalThis.saveMemory = saveOperationExecutionMemory;', h.context);
  await h.context.saveMemory({ hotel_id: 7, execution: { task_id: 5 } });
  assert.equal(h.calls.length, 2);
  assert.match(h.toasts.at(-1), /记录 #19.*页面回读未确认/);
  assert.doesNotMatch(h.toasts.at(-1), /保存失败/);
});

test('a cross-hotel memory acknowledgement cannot be reported as a saved local record', async () => {
  const memory = { id: 19, hotel_id: 8, source_record_id: 5, memory_layer: 'execution_review', content_digest: 'a'.repeat(64) };
  const h = harness({ fetch: async () => ({ code: 200, data: { memory,
    persistence_status: 'readback_verified', write_boundaries: { ota_write: false, external_message: false } } }) });
  Object.assign(h.context, {
    operatingMemorySavingTaskId: { value: 0 },
    operationCanSaveOperatingMemory: () => true,
    operationExecutionHotelId: item => Number(item.hotel_id || 0),
  });
  vm.runInContext(section('const saveOperationExecutionMemory = async', 'const canSaveMemo =')
    + '\nglobalThis.saveMemory = saveOperationExecutionMemory;', h.context);
  await h.context.saveMemory({ hotel_id: 7, execution: { task_id: 5 } });
  assert.equal(h.calls.length, 1);
  assert.match(h.toasts.at(-1), /保存结果未通过边界与回读校验/);
  assert.doesNotMatch(h.toasts.at(-1), /记录 #19 已收到服务端保存确认/);
});

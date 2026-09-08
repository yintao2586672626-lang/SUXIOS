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

function harness({ hotel = '', ready, fetch } = {}) {
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
    operationYesterday: '2026-09-07',
    operationLoading: ref({ actions: false }),
    operationError: ref({ actions: '' }),
    operatingGoalInterventionLoading: ref(false),
    operatingGoalInterventionError: ref(''),
    operationExecutionViewMode: ref('all'),
    operationExecutionFlow: ref({ list: [{ id: 99, hotel_id: 77 }], data_status: 'ok' }),
    operationActions: ref([{ id: 99, hotel_id: 77 }]),
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
    return { code: 200, data: isFlow
      ? { capabilities: { hotel_id: hotelId }, list: (hotelId ? [hotelId] : [7, 8]).map(id => ({ id, hotel_id: id })) }
      : { hotel_id: hotelId, actions: [] } };
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
  second.resolve({ code: 200, data: { capabilities: { hotel_id: 8 }, list: [{ id: 8, hotel_id: 8 }], hotel_id: 8 } });
  await current;
  assert.equal(h.context.operationLoading.value.actions, false);
  assert.equal(h.context.operationExecutionFlow.value.list[0].hotel_id, 8);
});

test('an invalid all-hotel response remains a read failure instead of an empty success', async () => {
  const h = harness({ fetch: async () => ({ code: 200, data: {} }) });
  assert.equal(await h.context.loadFlow(), false);
  assert.equal(h.context.operationExecutionFlow.value.data_status, 'load_failed');
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
  gate.resolve({ code: 200, data: { capabilities: { hotel_id: 7 }, list: [{ id: 71, hotel_id: 7 }], hotel_id: 7, actions: [] } });
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
  gate.resolve({ code: 200, data: { list: [{ id: 71, hotel_id: 7 }], data_gaps: [] } });
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
      const h = kind === 'tasks' ? harness({ hotel: '7' }) : memoryHarness({ hotel: '7', fetch: async () => ({ code: 200, data: { list: [], data_gaps: [] } }) });
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
  const h = memoryHarness({ fetch: async () => ({ code: 200, data: { list: [{ id: 1, hotel_id: 7 }], data_gaps: [] } }) });
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
  const h = memoryHarness({ hotel: '7', fetch: async () => ({ code: 200, data: { list: [{ id: 1, hotel_id: 8 }], data_gaps: [] } }) });
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
    gate.resolve({ code: 200, data: { list: [{ id: 1, hotel_id: 7 }], data_gaps: [] } });
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
  first.resolve({ code: 200, data: { list: [{ id: 1, hotel_id: 7 }], data_gaps: [] } });
  await old;
  assert.equal(h.context.operatingMemoryLoading.value, true);
  second.resolve({ code: 200, data: { list: [{ id: 2, hotel_id: 8 }], data_gaps: [] } });
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

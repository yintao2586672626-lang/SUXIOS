import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const knowledgeSource = readFileSync('public/components/system/knowledge-center-domain.js', 'utf8');
const campaignSource = readFileSync('public/components/system/campaign-operations-panel.js', 'utf8');
const appSource = readFileSync('public/app-main.js', 'utf8');
const ref = value => ({ value });
const cancelled = () => Object.assign(new Error('Page request was cancelled'), { name: 'AbortError' });
const deferredRequests = () => {
  const pending = [];
  return { pending, request: () => new Promise((resolve, reject) => pending.push({ resolve, reject })) };
};
const tick = () => new Promise(resolve => setImmediate(resolve));

function knowledgeHarness() {
  const browserWindow = {};
  vm.runInNewContext(knowledgeSource, { window: browserWindow, URLSearchParams });
  const reads = deferredRequests();
  let session = 1;
  const state = {
    computed: read => ({ get value() { return read(); } }),
    currentPage: ref('knowledge-center'), captureAuthSession: () => session,
    isAuthSessionCurrent: captured => captured === session,
    defaultKnowledgeCenterHotelId: () => 80,
    knowledgeCenterFilter: ref({}), knowledgeCenterPagination: ref({ page: 1, page_size: 10 }),
    knowledgeCenterUnits: ref([]), selectedKnowledgeCenterUnitIds: ref([]),
    knowledgeCenterChunks: ref([]), knowledgeCenterLoading: ref(false), knowledgeCenterListError: ref(''),
    knowledgeCenterDisplayLabel: String, formatKnowledgeJson: JSON.stringify,
    requireSystemStatic: () => [], showToast: () => {}, request: reads.request,
  };
  const context = new Proxy(state, { get: (target, key) => key in target ? target[key] : (target[key] = ref({})) });
  return { state, reads, api: browserWindow.SUXI_KNOWLEDGE_CENTER_DOMAIN.create(context), changeSession: () => { session++; } };
}
const knowledgeSuccess = id => ({ code: 0, data: { list: [{ unit_id: id }], pagination: { total: 1, page: 1, page_size: 10, total_page: 1 } } });

test('knowledge: an older response cannot replace the current result or finish its loading state', async () => {
  const { state, reads, api } = knowledgeHarness();
  const old = api.loadKnowledgeCenter();
  state.knowledgeCenterFilter.value.keyword = 'current';
  const current = api.loadKnowledgeCenter();
  reads.pending[0].resolve(knowledgeSuccess(1));
  await old;
  assert.equal(state.knowledgeCenterLoading.value, true);
  assert.equal(state.knowledgeCenterUnits.value.length, 0);
  reads.pending[1].resolve(knowledgeSuccess(2));
  await current;
  assert.equal(state.knowledgeCenterUnits.value[0].unit_id, 2);
  assert.equal(state.knowledgeCenterLoading.value, false);
});

test('knowledge: an older cancellation cannot erase a newer successful read', async () => {
  const { state, reads, api } = knowledgeHarness();
  const old = api.loadKnowledgeCenter();
  const current = api.loadKnowledgeCenter();
  reads.pending[1].resolve(knowledgeSuccess(2));
  await current;
  reads.pending[0].reject(cancelled());
  await old;
  assert.equal(state.knowledgeCenterListError.value, '');
  assert.equal(state.knowledgeCenterUnits.value[0].unit_id, 2);
});

test('knowledge: page, filter and authentication changes invalidate the pending read', async () => {
  for (const change of [
    harness => { harness.state.currentPage.value = 'hotels'; },
    harness => { harness.state.knowledgeCenterFilter.value.keyword = 'changed'; },
    harness => harness.changeSession(),
  ]) {
    const harness = knowledgeHarness();
    const pending = harness.api.loadKnowledgeCenter();
    change(harness);
    harness.reads.pending[0].resolve(knowledgeSuccess(1));
    await pending;
    assert.equal(harness.state.knowledgeCenterUnits.value.length, 0);
    assert.equal(harness.state.knowledgeCenterListError.value, '');
  }
});

test('knowledge: a genuine failure stays visible and a successful retry clears it', async () => {
  const { state, reads, api } = knowledgeHarness();
  const failed = api.loadKnowledgeCenter();
  reads.pending[0].reject(new Error('知识服务暂不可用'));
  await failed;
  assert.equal(state.knowledgeCenterListError.value, '知识服务暂不可用');
  assert.equal(state.knowledgeCenterLoading.value, false);
  const retry = api.loadKnowledgeCenter();
  reads.pending[1].resolve(knowledgeSuccess(3));
  await retry;
  assert.equal(state.knowledgeCenterListError.value, '');
  assert.equal(state.knowledgeCenterUnits.value[0].unit_id, 3);
});

test('knowledge: missing list or pagination is a failed read rather than zero matching records', async () => {
  for (const data of [{}, { list: [] }, { list: [], pagination: { page: 1, page_size: 10, total_page: 0 } },
    { list: [], pagination: { total: 0, page: 2, page_size: 10, total_page: 0 } }]) {
    const { state, reads, api } = knowledgeHarness();
    const pending = api.loadKnowledgeCenter();
    reads.pending[0].resolve({ code: 0, data });
    await pending;
    assert.match(state.knowledgeCenterListError.value, /回执不完整|分页范围/);
    assert.equal(state.knowledgeCenterLoading.value, false);
    assert.equal(state.knowledgeCenterPagination.value.total, undefined);
  }
});

test('knowledge: the documented code-zero empty list and legacy integer strings remain valid', async () => {
  const { state, reads, api } = knowledgeHarness();
  const pending = api.loadKnowledgeCenter();
  reads.pending[0].resolve({ code: 0, data: { list: [], pagination: { total: '0', page: '1', page_size: '10', total_page: '0' } } });
  await pending;
  assert.equal(state.knowledgeCenterListError.value, '');
  assert.equal(state.knowledgeCenterPagination.value.total, 0);
  assert.equal(state.knowledgeCenterPagination.value.total_page, 0);
});

function campaignHarness() {
  const browserWindow = { SUXI_SYSTEM_COMPONENTS: {} };
  vm.runInNewContext(campaignSource, { window: browserWindow, Intl, Date, URLSearchParams, Blob });
  const reads = deferredRequests();
  const methods = browserWindow.SUXI_SYSTEM_COMPONENTS.CampaignOperationsPanel.methods;
  const state = { seq: 1, hotelId: '80', businessDate: '2026-10-03', error: '', success: '', saved: null, request: reads.request };
  state.checkRecord = record => methods.checkRecord.call(state, record);
  return { reads, methods, state };
}

for (const method of ['edit', 'artifact']) {
  test(`campaign: an old ${method} cancellation cannot show an error after changing hotels`, async () => {
    const { state, reads, methods } = campaignHarness();
    const pending = methods[method].call(state, { id: 1, version_no: 1, business_date: state.businessDate }, 'html');
    state.seq++;
    state.hotelId = '81';
    state.error = '新门店已确认的提示';
    reads.pending[0].reject(cancelled());
    await pending;
    assert.equal(state.error, '新门店已确认的提示');
  });
}

test('campaign: an actual read failure remains visible and editing can be retried', async () => {
  const { state, reads, methods } = campaignHarness();
  const failed = methods.edit.call(state, { id: 1 });
  reads.pending[0].reject(new Error('作品回读失败'));
  await failed;
  assert.equal(state.error, '作品回读失败');
  const retry = methods.edit.call(state, { id: 1 });
  reads.pending[1].resolve({ code: 200, data: { id: 1, hotel_id: 80, business_date: state.businessDate,
    schema_version: 'campaign_operations.v1', kind: 'campaign', record_key: 'one', version_no: 1, payload: {} } });
  await retry;
  assert.equal(state.saved.id, 1);
  assert.equal(state.error, '');
});

function extractAppFunction(name, next, state) {
  const start = appSource.indexOf(`const ${name} =`);
  const end = appSource.indexOf(`const ${next} =`, start);
  assert.ok(start >= 0 && end > start);
  return vm.runInNewContext(`${appSource.slice(start, end)}\n${name};`, state);
}

function alertsHarness() {
  const reads = deferredRequests();
  const state = {
    operationLoading: ref({ alerts: false }), operationError: ref({ alerts: '' }), operationAlerts: ref({ list: [] }),
    operationFilters: ref({ hotel_id: '80', date: '2026-10-03' }), currentPage: ref('ops-insight'),
    isLoggedIn: ref(true), pageRequestGeneration: 1, operationAlertsRequestSeq: 0,
    captureAuthSession: () => 1, isAuthSessionCurrent: () => true,
    ensureOperationStaticReady: async () => {}, normalizeOperationHotelSelection: () => 80,
    apiRequest: reads.request, URLSearchParams, operationErrorMessage: (error, fallback) => /[\u4e00-\u9fa5]/.test(error?.message || '') ? error.message : fallback,
    showToast: () => {},
  };
  return { state, reads, load: extractAppFunction('loadOperationAlerts', 'markOperationAlertsRead', state) };
}

test('alerts: an older cancellation cannot obscure a successful retry', async () => {
  const { state, reads, load } = alertsHarness();
  const old = load(); await tick();
  const current = load(); await tick();
  reads.pending[1].resolve({ code: 200, data: { list: [{ id: 2 }], unread_count: 1 } });
  await current;
  reads.pending[0].reject(cancelled());
  await old;
  assert.equal(state.operationError.value.alerts, '');
  assert.equal(state.operationAlerts.value.list[0].id, 2);
});

test('alerts: a static resource failure is visible and remains retryable', async () => {
  const { state, reads, load } = alertsHarness();
  state.ensureOperationStaticReady = async () => { throw new Error('运营静态资源读取失败'); };
  await load();
  assert.equal(state.operationError.value.alerts, '运营静态资源读取失败');
  assert.equal(state.operationLoading.value.alerts, false);
  state.ensureOperationStaticReady = async () => {};
  const retry = load(); await tick();
  reads.pending[0].resolve({ code: 200, data: { list: [], unread_count: 0 } });
  await retry;
  assert.equal(state.operationError.value.alerts, '');
});

function monitorHarness() {
  const reads = deferredRequests();
  const state = {
    automationMonitorDate: ref('2026-10-02'), automationMonitorError: ref(''), automationMonitorLoading: ref(false),
    automationMonitor: ref({ rows: [] }), automationMonitorContractHotelId: ref(''), automationMonitorContract: ref({}), automationMonitorContractError: ref(''),
    currentPage: ref('automation-monitor'), isLoggedIn: ref(true), pageRequestGeneration: 1, automationMonitorRequestSeq: 0,
    captureAuthSession: () => 1, isAuthSessionCurrent: () => true,
    URLSearchParams, operationErrorMessage: (error, fallback) => /[\u4e00-\u9fa5]/.test(error?.message || '') ? error.message : fallback,
    loadAutomationMonitorContract: async () => {}, apiRequest: reads.request,
  };
  return { state, reads, load: extractAppFunction('loadAutomationMonitor', 'refreshAutomationMonitor', state) };
}
const monitorSuccess = date => ({ code: 200, data: { business_date: date, observed_at: '2026-10-03 10:00:00', rows: [],
  summary: { hotel_count: 0, data_ready_count: 0, collecting_count: 0, blocked_count: 0, waiting_push_count: 0, push_succeeded_count: 0 } } });

test('monitor: an old date response cannot replace the current result or finish its loading state', async () => {
  const { state, reads, load } = monitorHarness();
  const old = load();
  state.automationMonitorDate.value = '2026-10-03';
  const current = load();
  reads.pending[0].resolve(monitorSuccess('2026-10-02'));
  await old;
  assert.equal(state.automationMonitorLoading.value, true);
  assert.equal(state.automationMonitor.value.business_date, undefined);
  reads.pending[1].resolve(monitorSuccess('2026-10-03'));
  await current;
  assert.equal(state.automationMonitor.value.business_date, '2026-10-03');
});

test('monitor: a late cancellation cannot turn a current successful read into failure', async () => {
  const { state, reads, load } = monitorHarness();
  const old = load();
  const current = load();
  reads.pending[1].resolve(monitorSuccess('2026-10-02'));
  await current;
  reads.pending[0].reject(cancelled());
  await old;
  assert.equal(state.automationMonitorError.value, '');
});

test('monitor: a genuine failure stays visible and a successful retry clears it', async () => {
  const { state, reads, load } = monitorHarness();
  const failed = load();
  reads.pending[0].reject(new Error('监控服务暂不可用'));
  await failed;
  assert.equal(state.automationMonitorError.value, '监控服务暂不可用');
  assert.equal(state.automationMonitorLoading.value, false);
  const retry = load();
  reads.pending[1].resolve(monitorSuccess('2026-10-02'));
  await retry;
  assert.equal(state.automationMonitorError.value, '');
});

const notificationMethods = {
  loadManualNotificationMetadata: { next: 'loadManualNotificationHistory', key: 'metadata', field: 'manualNotificationMetadata' },
  loadManualNotificationHistory: { next: 'loadManualNotificationDispatchHistory', key: 'history', field: 'manualNotificationHistory' },
  loadManualNotificationDispatchHistory: { next: 'loadManualNotificationCenter', key: 'dispatchHistory', field: 'manualNotificationDispatchHistory' },
};
function notificationHarness(name) {
  const reads = deferredRequests();
  const state = {
    manualNotificationForm: ref({ hotel_id: '80', business_date: '2026-10-03', target_robot_id: '' }),
    manualNotificationReadRequestSeq: { metadata: 0, history: 0, dispatchHistory: 0 },
    manualNotificationLoading: ref({ metadata: false, history: false, dispatchHistory: false }),
    manualNotificationError: ref(''), manualNotificationMetadata: ref({ marker: 'initial', types: [] }),
    manualNotificationHistory: ref({ marker: 'initial', list: [], total: 0 }),
    manualNotificationDispatchHistory: ref({ marker: 'initial', list: [], total: 0 }),
    wechatNotificationDeliveryHistory: ref([]), currentPage: ref('manual-notifications'), pageRequestGeneration: 1,
    captureAuthSession: () => 1, isAuthSessionCurrent: () => true,
    operationErrorMessage: (error, fallback) => /[\u4e00-\u9fa5]/.test(error?.message || '') ? error.message : fallback,
    apiRequest: reads.request, URLSearchParams,
  };
  state.manualNotificationContext = () => ({ hotelId: state.manualNotificationForm.value.hotel_id,
    businessDate: state.manualNotificationForm.value.business_date });
  return { state, reads, load: extractAppFunction(name, notificationMethods[name].next, state) };
}
const notificationSuccess = marker => ({ code: 200, data: {
  marker, hotel_id: 80, business_date: '2026-10-03', types: [], send_methods: [], trigger_types: [],
  list: [{ id: marker, hotel_id: 80 }], total: 1,
} });

for (const [name, { key, field }] of Object.entries(notificationMethods)) {
  test(`notification ${key}: an old response cannot replace the current result or finish its loading state`, async () => {
    const { state, reads, load } = notificationHarness(name);
    const old = load();
    const current = load();
    reads.pending[0].resolve(notificationSuccess(1));
    await old;
    assert.equal(state.manualNotificationLoading.value[key], true);
    assert.notEqual(state[field].value.marker, 1);
    reads.pending[1].resolve(notificationSuccess(2));
    await current;
    assert.equal(state[field].value.marker, 2);
  });

  test(`notification ${key}: a late cancellation cannot obscure the current successful read`, async () => {
    const { state, reads, load } = notificationHarness(name);
    const old = load();
    const current = load();
    reads.pending[1].resolve(notificationSuccess(2));
    await current;
    reads.pending[0].reject(cancelled());
    await old;
    assert.equal(state.manualNotificationError.value, '');
    assert.equal(state[field].value.marker, 2);
  });

  test(`notification ${key}: a genuine failure stays visible and a successful retry clears it`, async () => {
    const { state, reads, load } = notificationHarness(name);
    const failed = load();
    reads.pending[0].reject(new Error('通知数据暂不可用'));
    await failed;
    assert.equal(state.manualNotificationError.value, '通知数据暂不可用');
    assert.equal(state.manualNotificationLoading.value[key], false);
    const retry = load();
    reads.pending[1].resolve(notificationSuccess(3));
    await retry;
    assert.equal(state.manualNotificationError.value, '');
    assert.equal(state[field].value.marker, 3);
  });
}

const openingMethods = {
  loadOpeningOverview: { next: 'loadOpeningTasks', key: 'overview', field: 'openingOverview' },
  loadOpeningTasks: { next: 'generateOpeningTasks', key: 'tasks', field: 'openingTasks' },
};
function openingHarness(name) {
  const reads = deferredRequests();
  const messages = [];
  const state = {
    openingReadRequestSeq: { overview: 0, tasks: 0 }, openingProjectsRequestSeq: 0, selectedOpeningProjectId: ref('1'),
    openingOverview: ref(null), openingTasks: ref([]), openingLoading: ref(false),
    currentPage: ref('opening-overview'), pageRequestGeneration: 1,
    captureAuthSession: () => 1, isAuthSessionCurrent: () => true,
    pruneSelectedOpeningTaskIds: () => {}, showToast: (message, type) => messages.push({ message, type }),
    request: reads.request,
  };
  return { state, reads, messages, load: extractAppFunction(name, openingMethods[name].next, state) };
}
const openingSuccess = id => ({ code: 200, data: { project_id: id, project: { id }, metrics: {}, list: [{ id }] } });
const openingDisplayedId = (state, field) => field === 'openingTasks' ? state[field].value[0]?.id : state[field].value?.project_id;

for (const [name, { key, field }] of Object.entries(openingMethods)) {
  test(`opening ${key}: an old project response cannot overwrite the current project`, async () => {
    const { state, reads, load } = openingHarness(name);
    const old = load();
    state.selectedOpeningProjectId.value = '2';
    const current = load();
    reads.pending[1].resolve(openingSuccess(2));
    await current;
    reads.pending[0].resolve(openingSuccess(1));
    await old;
    assert.equal(openingDisplayedId(state, field), 2);
  });

  test(`opening ${key}: an old cancellation cannot report a failure after switching projects`, async () => {
    const { state, reads, messages, load } = openingHarness(name);
    const old = load();
    state.selectedOpeningProjectId.value = '2';
    const current = load();
    reads.pending[1].resolve(openingSuccess(2));
    await current;
    reads.pending[0].reject(cancelled());
    await assert.doesNotReject(old);
    assert.equal(messages.length, 0);
    assert.equal(openingDisplayedId(state, field), 2);
  });

  test(`opening ${key}: a network failure is visible and the project read can be retried`, async () => {
    const { state, reads, messages, load } = openingHarness(name);
    const failed = load();
    reads.pending[0].reject(new Error('开业项目数据暂不可用'));
    await assert.doesNotReject(failed);
    assert.ok(messages.some(item => item.type === 'error' && /失败|不可用/.test(item.message)));
    const retry = load();
    reads.pending[1].resolve(openingSuccess(1));
    await retry;
    assert.equal(openingDisplayedId(state, field), 1);
  });
}

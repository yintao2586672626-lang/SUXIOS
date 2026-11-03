import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/app-main.js', 'utf8');
const ref = value => ({ value });
const tick = () => new Promise(resolve => setImmediate(resolve));
const emptyHistory = () => ({ list: [], total: 0 });
const emptyMonitor = () => ({ business_date: '2026-10-03', observed_at: '2026-10-03 10:00:00',
  refresh_interval_seconds: 60, rows: [], summary: { hotel_count: 0, data_ready_count: 0,
    collecting_count: 0, blocked_count: 0, waiting_push_count: 0, push_succeeded_count: 0 }, message: '当前账号没有可监控的营业门店。' });
const metadata = () => ({ types: [{ key: 'manual_notice', label: '人工通知' }],
  send_methods: [{ key: 'wechat', label: '企业微信' }], trigger_types: [{ key: 'manual_test', label: '人工测试' }],
  condition_rules: [], operating_daily_trigger_types: [], source_scopes: [], content_sections: [], business_date_rules: [],
  weekdays: [], variables: [], fixed_policies: {}, daily_content_template_modes: [], daily_template_variables: [],
  daily_custom_template: {}, test_target: { selection_required: true }, scheduler_status: 'not_deployed',
  scheduler_note: '尚未取得调度记录', latest_schedule_run: {}, latest_schedule_runs: {}, three_source_hourly_status: {}, automatic_tasks: {} });
const overview = () => ({ project: { id: 1, hotel_id: 80, project_name: '测试开业项目', risk_level: 'low' },
  metrics: {}, truth_context: {}, ai_suggestions: [], ai_recommendations: [], high_risk_tasks: [], overdue_tasks: [],
  category_progress: [], recent_result: {}, history: [] });

const cases = [
  { name: 'metadata', fn: 'loadManualNotificationMetadata', next: 'loadManualNotificationHistory',
    field: 'manualNotificationMetadata', valid: metadata, invalid: [undefined, {}, { types: 'broken' }], loading: 'metadata' },
  { name: 'notification history', fn: 'loadManualNotificationHistory', next: 'loadManualNotificationDispatchHistory',
    field: 'manualNotificationHistory', valid: emptyHistory, invalid: [undefined, {}, { list: [] }, { list: 'broken', total: 0 }], loading: 'history' },
  { name: 'dispatch history', fn: 'loadManualNotificationDispatchHistory', next: 'loadManualNotificationCenter',
    field: 'manualNotificationDispatchHistory', valid: emptyHistory, invalid: [undefined, {}, { list: [] }, { list: 'broken', total: 0 }], loading: 'dispatchHistory' },
  { name: 'monitor', fn: 'loadAutomationMonitor', next: 'refreshAutomationMonitor',
    field: 'automationMonitor', valid: emptyMonitor, invalid: [undefined, {}, { ...emptyMonitor(), rows: 'broken' }], loading: 'monitor' },
  { name: 'alerts', fn: 'loadOperationAlerts', next: 'markOperationAlertsRead',
    field: 'operationAlerts', valid: () => ({ list: [], unread_count: 0 }), invalid: [undefined, {}, { list: 'broken', unread_count: 0 }], loading: 'alerts' },
  { name: 'opening projects', fn: 'loadOpeningProjects', next: 'saveOpeningProject',
    field: 'openingProjects', valid: () => ({ list: [] }), invalid: [undefined, {}, { list: 'broken' }], loading: 'projects' },
  { name: 'opening overview', fn: 'loadOpeningOverview', next: 'loadOpeningTasks',
    field: 'openingOverview', valid: overview, invalid: [undefined, {}, { project: null }] },
  { name: 'opening tasks', fn: 'loadOpeningTasks', next: 'generateOpeningTasks',
    field: 'openingTasks', valid: () => ({ list: [] }), invalid: [undefined, {}, { list: 'broken' }] },
];

function harness(subject) {
  const pending = [], messages = [];
  const request = () => new Promise((resolve, reject) => pending.push({ resolve, reject }));
  const state = {
    captureAuthSession: () => 1, isAuthSessionCurrent: () => true, pageRequestGeneration: 1,
    URLSearchParams, request, apiRequest: request, ensureOperationStaticReady: async () => {},
    operationErrorMessage: (error, fallback) => /[\u4e00-\u9fa5]/.test(error?.message || '') ? error.message : fallback,
    showToast: (message, type) => messages.push({ message, type }),
    manualNotificationReadRequestSeq: { metadata: 0, history: 0, dispatchHistory: 0 },
    manualNotificationForm: ref({ hotel_id: '80', business_date: '2026-10-03', target_robot_id: '' }),
    manualNotificationError: ref(''), manualNotificationLoading: ref({ metadata: false, history: false, dispatchHistory: false }),
    manualNotificationMetadata: ref(metadata()), manualNotificationHistory: ref(emptyHistory()),
    manualNotificationDispatchHistory: ref(emptyHistory()), wechatNotificationDeliveryHistory: ref([]),
    automationMonitorRequestSeq: 0, automationMonitorDate: ref('2026-10-03'), automationMonitorLoading: ref(false),
    automationMonitorError: ref(''), automationMonitor: ref(emptyMonitor()),
    automationMonitorContractHotelId: ref(''), automationMonitorContract: ref({}), automationMonitorContractError: ref(''),
    loadAutomationMonitorContract: async () => {},
    operationAlertsRequestSeq: 0, operationFilters: ref({ hotel_id: '80' }), operationLoading: ref({ alerts: false }),
    operationError: ref({ alerts: '' }), operationAlerts: ref({ list: [], unread_count: 0 }),
    openingReadRequestSeq: { overview: 0, tasks: 0 }, openingProjectsRequestSeq: 0, token: ref(true), openingLoading: ref(false),
    openingProjects: ref([{ id: 1 }]), selectedOpeningProjectId: ref('1'), openingOverview: ref(overview()), openingTasks: ref([{ id: 1 }]),
    syncOpeningProjectForm: () => {}, loadOpeningOverview: async () => {}, loadOpeningTasks: async () => {}, pruneSelectedOpeningTaskIds: () => {},
  };
  state.manualNotificationContext = () => {
    const form = state.manualNotificationForm.value;
    if (!form.hotel_id || !form.business_date) { state.manualNotificationError.value = '请选择门店及业务日期'; return null; }
    return { hotelId: form.hotel_id, businessDate: form.business_date };
  };
  state.normalizeOperationHotelSelection = () => {
    const id = state.operationFilters.value.hotel_id;
    if (!id) { state.operationError.value.alerts = '请选择有权限的酒店'; return null; }
    return Number(id);
  };
  const start = source.indexOf(`const ${subject.fn} =`), end = source.indexOf(`const ${subject.next} =`, start);
  assert.ok(start >= 0 && end > start);
  const load = vm.runInNewContext(`${source.slice(start, end)}\n${subject.fn};`, state);
  return { state, pending, messages, load };
}
function hasError({ state, messages }) {
  return Boolean(state.manualNotificationError.value || state.automationMonitorError.value || state.operationError.value.alerts
    || messages.some(message => message.type === 'error'));
}
function isLoading(state, key) {
  if (['metadata', 'history', 'dispatchHistory'].includes(key)) return state.manualNotificationLoading.value[key];
  if (key === 'monitor') return state.automationMonitorLoading.value;
  if (key === 'alerts') return state.operationLoading.value.alerts;
  if (key === 'projects') return state.openingLoading.value;
  return false;
}

for (const subject of cases) {
  test(`${subject.name}: documented complete success, including a legitimate empty list, remains valid`, async () => {
    const h = harness(subject);
    const pending = h.load(); await tick();
    h.pending[0].resolve({ code: 200, data: subject.valid() });
    await pending;
    assert.equal(hasError(h), false);
    assert.equal(isLoading(h.state, subject.loading), false);
  });

  test(`${subject.name}: missing or malformed success data stays an explicit failed read`, async () => {
    const acceptedInvalidData = [];
    for (const data of subject.invalid) {
      const h = harness(subject);
      const pending = h.load(); await tick();
      h.pending[0].resolve({ code: 200, data });
      await pending;
      if (!hasError(h)) acceptedInvalidData.push(data === undefined ? 'missing data' : JSON.stringify(data));
      assert.equal(isLoading(h.state, subject.loading), false);
    }
    assert.equal(acceptedInvalidData.length, 0,
      `${subject.name} accepted malformed success data: ${acceptedInvalidData.join(', ')}`);
  });

  test(`${subject.name}: a late response from an obsolete page cannot replace current state`, async () => {
    const h = harness(subject);
    const pending = h.load(); await tick();
    h.state.pageRequestGeneration++;
    const current = { protectedCurrent: true };
    h.state[subject.field].value = current;
    h.pending[0].resolve({ code: 200, data: subject.valid() });
    await pending;
    assert.equal(h.state[subject.field].value, current);
  });
}

for (const subject of cases.filter(item => item.loading && item.loading !== 'projects')) {
  test(`${subject.name}: clearing the required selection invalidates the pending read and releases loading`, async () => {
    const h = harness(subject);
    const old = h.load(); await tick();
    if (subject.loading === 'monitor') h.state.automationMonitorDate.value = '';
    else if (subject.loading === 'alerts') h.state.operationFilters.value.hotel_id = '';
    else h.state.manualNotificationForm.value.hotel_id = '';
    await h.load();
    h.pending[0].resolve({ code: 200, data: subject.valid() });
    await old;
    assert.equal(isLoading(h.state, subject.loading), false);
  });
}

test('monitor: a response for a different business date is rejected', async () => {
  const h = harness(cases.find(item => item.name === 'monitor'));
  const pending = h.load(); await tick();
  h.pending[0].resolve({ code: 200, data: { ...emptyMonitor(), business_date: '2026-10-02' } });
  await pending;
  assert.equal(hasError(h), true);
});

test('opening overview: a response for a different project is rejected', async () => {
  const h = harness(cases.find(item => item.name === 'opening overview'));
  const pending = h.load(); await tick();
  h.pending[0].resolve({ code: 200, data: { ...overview(), project: { id: 2, hotel_id: 80 } } });
  await pending;
  assert.equal(hasError(h), true);
});

const countSubjects = cases.filter(item => ['notification history', 'dispatch history', 'monitor', 'alerts'].includes(item.name));
function withCount(subject, count) {
  const data = subject.valid();
  if (subject.name === 'monitor') data.summary.hotel_count = count;
  else if (subject.name === 'alerts') data.unread_count = count;
  else data.total = count;
  return data;
}
for (const subject of countSubjects) {
  test(`${subject.name}: boolean and array counts cannot become zero or one`, async () => {
    const accepted = [];
    for (const count of [false, true, [0], [1]]) {
      const h = harness(subject);
      const pending = h.load(); await tick();
      h.pending[0].resolve({ code: 200, data: withCount(subject, count) });
      await pending;
      if (!hasError(h)) accepted.push(JSON.stringify(count));
    }
    assert.equal(accepted.length, 0, `invalid count types accepted: ${accepted.join(', ')}`);
  });

  test(`${subject.name}: empty count text remains missing rather than zero`, async () => {
    for (const count of ['', '   ']) {
      const h = harness(subject);
      const pending = h.load(); await tick();
      h.pending[0].resolve({ code: 200, data: withCount(subject, count) });
      await pending;
      assert.equal(hasError(h), true);
    }
  });

  test(`${subject.name}: documented numeric zero and legacy integer text remain valid`, async () => {
    for (const count of [0, '0', '1']) {
      const h = harness(subject);
      const pending = h.load(); await tick();
      h.pending[0].resolve({ code: 200, data: withCount(subject, count) });
      await pending;
      assert.equal(hasError(h), false);
    }
  });
}

test('alerts: the documented migration-required gap remains an error with its original evidence', async () => {
  // Exact response shape from OperationManagementService::operationAlertSchemaGapResponse.
  const h = harness(cases.find(item => item.name === 'alerts'));
  const gap = { code: 'operation_alerts_missing', message: 'migration_required: operation alerts table is unavailable', migration_required: true };
  const data = { list: [], unread_count: 0, data_status: 'migration_required', data_gaps: [gap],
    selected_hotel_id: 80, generated_for_date: '2026-10-03', scope: 'single_hotel',
    capabilities: { can_execute: false, can_mark_read: false } };
  const pending = h.load(); await tick();
  h.pending[0].resolve({ code: 200, data });
  await pending;
  assert.equal(hasError(h), true, 'schema failure cannot become no-alerts success');
  assert.equal(h.state.operationAlerts.value.data_status, 'migration_required');
  assert.equal(h.state.operationAlerts.value.data_gaps[0].code, gap.code);
  assert.equal(h.state.operationAlerts.value.capabilities.can_mark_read, false);
});

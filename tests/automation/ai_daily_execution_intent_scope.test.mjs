import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = readFileSync('public/app-main.js', 'utf8');
const start = source.indexOf('            const createAiDailyExecutionIntent = async');
const end = source.indexOf('            const operatingMemoryItems =', start);
assert.ok(start >= 0 && end > start);
const handler = source.slice(start, end);

function deferred() {
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  return { promise, resolve };
}

function scenario() {
  const post = deferred();
  const report = { id: 41, hotel_id: 80, report_date: '2026-09-12' };
  const state = {
    aiDailyReport: { value: report },
    aiDailyReportForm: { value: { hotel_id: '80', report_date: '2026-09-12' } },
    operationFilters: { value: { hotel_id: '80' } },
    operationLoading: { value: { aiDailyReport: false } },
    currentPage: { value: 'ai-daily-report' },
  };
  const calls = { reads: [], latest: 0, actions: 0, posts: 0, notices: [] };
  const context = {
    ...state,
    revenueAiDailyReportActionExecutionReady: () => true,
    apiRequest: async path => {
      assert.equal(path, '/ai-daily-reports/41/actions/0/execution-intent');
      calls.posts++;
      return post.promise;
    },
    readOperationExecutionIntent: async (id, hotelId) => {
      calls.reads.push(['intent', id, hotelId]);
      return { id, hotel_id: 80, source_record_id: 41, status: 'pending_approval', blocked_reason: '' };
    },
    readAiDailyReportById: async (id, hotelId, date) => {
      calls.reads.push(['report', id, hotelId, date]);
      return { ...report, recommended_actions: [{ execution_intent_id: 91 }] };
    },
    loadAiDailyReport: async () => { calls.latest++; },
    loadOperationActions: async () => { calls.actions++; },
    showToast: (...args) => { calls.notices.push(args); },
    operationErrorMessage: error => error.message,
    captureAuthSession: () => ({ epoch: 1 }),
    isAuthSessionCurrent: () => true,
    aiDailyReportSendScopeCurrent: () => Number(state.aiDailyReport.value?.hotel_id || 0) === Number(state.aiDailyReportForm.value.hotel_id || 0)
      && String(state.aiDailyReport.value?.report_date || '') === String(state.aiDailyReportForm.value.report_date || ''),
  };
  vm.runInNewContext(`let aiDailyReportRequestSeq = 0;\n${handler}\nglobalThis.create = createAiDailyExecutionIntent;`, context);
  return { post, report, state, calls, context, create: context.create };
}

test('latest older report cannot create an execution intent under the selected newer business date', async () => {
  const s = scenario();
  s.state.aiDailyReportForm.value.report_date = '2026-09-28';
  const attempt = s.create({ title: 'review price' }, 0);
  if (s.calls.posts) s.post.resolve({ code: 503, message: 'synthetic rejected write' });
  await attempt;
  assert.equal(s.calls.posts, 0);
  assert.equal(s.state.operationLoading.value.aiDailyReport, false);
  assert.match(s.calls.notices.at(-1)?.[0] || '', /营业日与当前日报不一致/);
});

test('creating from a selected historical report reads back that exact report and hotel', async () => {
  const s = scenario();
  const pending = s.create({ title: 'review price' }, 0);
  s.post.resolve({ code: 200, data: { execution_intent: { id: 91 } } });
  await pending;
  assert.deepEqual(s.calls.reads, [['intent', 91, 80], ['report', 41, 80, '2026-09-12']]);
  assert.equal(s.calls.latest, 0);
  assert.equal(s.state.aiDailyReport.value.recommended_actions[0].execution_intent_id, 91);
});

test('an old hotel action response does not refresh the newly selected hotel', async () => {
  const s = scenario();
  const pending = s.create({ title: 'review price' }, 0);
  s.state.aiDailyReport.value = { id: 52, hotel_id: 81, report_date: '2026-09-13' };
  s.state.aiDailyReportForm.value = { hotel_id: '81', report_date: '2026-09-13' };
  s.state.operationFilters.value.hotel_id = '81';
  s.post.resolve({ code: 200, data: { execution_intent: { id: 91 } } });
  await pending;
  assert.equal(s.state.aiDailyReport.value.id, 52);
  assert.equal(s.state.operationFilters.value.hotel_id, '81');
  assert.equal(s.calls.latest, 0);
  assert.equal(s.calls.actions, 0);
});

test('unverified report action writeback stays on the selected report and can retry', async () => {
  const s = scenario();
  s.context.readAiDailyReportById = async () => ({
    ...s.report, recommended_actions: [{ execution_intent_id: 90 }],
  });
  s.post.resolve({ code: 200, data: { execution_intent: { id: 91 } } });
  await s.create({ title: 'review price' }, 0);
  assert.equal(s.state.aiDailyReport.value, s.report);
  assert.equal(s.calls.actions, 0);
  assert.match(s.calls.notices.at(-1)[0], /精确回读/);
  assert.equal(s.state.operationLoading.value.aiDailyReport, false);

  s.context.readAiDailyReportById = async () => ({
    ...s.report, recommended_actions: [{ execution_intent_id: 91 }],
  });
  await s.create({ title: 'review price' }, 0);
  assert.equal(s.state.aiDailyReport.value.recommended_actions[0].execution_intent_id, 91);
  assert.equal(s.calls.actions, 1);
});

test('operations list refresh failure does not deny a verified created intent', async () => {
  const s = scenario();
  s.context.loadOperationActions = async () => { throw new Error('synthetic operations refresh failure'); };
  s.post.resolve({ code: 200, data: { execution_intent: { id: 91 } } });
  await s.create({ title: 'review price' }, 0);
  assert.equal(s.state.aiDailyReport.value.recommended_actions[0].execution_intent_id, 91);
  assert.ok(s.calls.notices.some(([message]) => /已生成执行意图/.test(message)));
  assert.ok(s.calls.notices.some(([message]) => /列表刷新失败/.test(message)));
  assert.ok(!s.calls.notices.some(([message]) => /执行单创建失败/.test(message)));
});

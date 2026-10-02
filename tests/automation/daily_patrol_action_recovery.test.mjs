import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import { computed, ref, watch } from 'vue';

const source = readFileSync('public/app-main.js', 'utf8');
const extract = (start, end) => {
  const from = source.indexOf(start), to = source.indexOf(end, from);
  assert.ok(from >= 0 && to > from, start);
  return source.slice(from, to);
};
const action = (hotelId = 200, actionCode = 'check_rate') => ({
  hotelId, actionCode, questionKey: '', targetDate: '2026-09-14',
  platform: 'ctrip', priority: 'medium', actionText: 'Synthetic action', entry: 'operations',
});
const snapshot = (run = 'run-A', hotel = 200, status = 'pending', result = 'observing') => ({
  run_id: run, scope: { hotel_id: hotel, target_date: '2026-09-14' },
  action_tracking: { items: { [`${hotel}|check_rate`]: {
    hotel_id: hotel, action_code: 'check_rate', question_key: '', status,
    operation_execution: { intent_id: 17, task_id: 31 },
    review_result: { result_status: result },
  } } },
});
const harness = () => {
  const posts = [], notices = [], refreshes = [], operations = [];
  let session = 1, page = 1, operationWait = null, operationLoadSuccess = true;
  const context = {
    ref, computed, watch, Map,
    captureAuthSession: () => ({ epoch: session }),
    isAuthSessionCurrent: value => value.epoch === session,
    currentPageReadPolicy: () => ({ generation: page }),
    isPageLoadPolicyCurrent: value => value.generation === page,
    showToast: (...args) => notices.push(args),
    loadPhase3OperationEffectLoop: options => refreshes.push(options),
    loadOperationActions: async () => { operations.push(true); if (operationWait) await operationWait; return operationLoadSuccess; },
    request: (url, options) => new Promise((resolve, reject) => posts.push({ url, body: JSON.parse(options.body), resolve, reject })),
    console: { error() {} },
  };
  const state = vm.runInNewContext(`(() => {
    ${extract('const dailyWorkbenchPatrol = ref(null);', 'const phase3OperationEffectLoop =')}
    let dailyWorkbenchPatrolRequestSeq = 0;
    const operationFilters = ref({ hotel_id: '200' });
    const coreOperationsHotelId = ref('200');
    ${extract('const dailyWorkbenchPatrolLatest = computed(', 'const dailyWorkbenchPatrolHealth =')}
    ${extract('const dailyWorkbenchPatrolActionItems = computed(', 'const dailyWorkbenchPatrolTrackedStatus =')}
    ${extract('const dailyWorkbenchPatrolTaskId =', 'const dailyWorkbenchPatrolReviewStatus =')}
    ${extract('const dailyWorkbenchPatrolReviewSummary =', 'const dailyWorkbenchPatrolReviewText =')}
    ${extract('const dailyWorkbenchPatrolActionKey =', 'const loadDataHealthOperationLogs =')}
    return { patrol: dailyWorkbenchPatrol, error: dailyWorkbenchPatrolError,
      busy: dailyWorkbenchPatrolActionUpdating, filters: operationFilters, hotel: coreOperationsHotelId,
      update: updateDailyWorkbenchPatrolAction, review: reviewDailyWorkbenchPatrolAction,
      startRefresh: () => { dailyWorkbenchPatrolRequestSeq += 1; } };
  })()`, context);
  state.patrol.value = { latest: snapshot(), health: { status: 'synthetic' } };
  return { ...state, posts, notices, refreshes, operations,
    changeSession: () => { session += 1; }, changePage: () => { page += 1; },
    waitForOperations: value => { operationWait = value; }, setOperationLoadSuccess: value => { operationLoadSuccess = value; } };
};
const submit = (view, mode, item = action()) => mode === 'update'
  ? view.update(item, 'done') : view.review(item, 'success');
const receipt = (mode, run = 'run-A', hotel = 200) => ({ code: 200,
  data: { latest: snapshot(run, hotel, 'done', mode === 'review' ? 'success' : 'observing') } });

for (const mode of ['update', 'review']) {
  test(`${mode}: question-only actions retain the existing tracking and request identity`, async () => {
    const view = harness();
    const item = { ...action(), actionCode: '', questionKey: 'missing_evidence' };
    const tracked = view.patrol.value.latest.action_tracking.items['200|check_rate'];
    tracked.action_code = '';
    tracked.question_key = 'missing_evidence';
    view.patrol.value.latest.action_tracking.items = { '200|missing_evidence': tracked };
    const pending = submit(view, mode, item);
    assert.equal(view.posts[0].body.action_code, '');
    assert.equal(view.posts[0].body.question_key, 'missing_evidence');
    const response = receipt(mode);
    const saved = response.data.latest.action_tracking.items['200|check_rate'];
    saved.action_code = '';
    saved.question_key = 'missing_evidence';
    response.data.latest.action_tracking.items = { '200|missing_evidence': saved };
    view.posts[0].resolve(response);
    await pending;
    assert.equal(view.error.value, '');
    assert.equal(view.notices.length, 1);
    assert.equal(view.refreshes.length, 1);
  });

  test(`${mode}: a retained old-hotel snapshot cannot submit under the newly selected hotel`, () => {
    const view = harness();
    view.hotel.value = '201';
    submit(view, mode);
    assert.equal(view.posts.length, 0);
    assert.ok(view.error.value);
  });

  test(`${mode}: current receipt preserves health, applies the bound snapshot, and refreshes the requested run`, async () => {
    const view = harness();
    const pending = submit(view, mode);
    assert.equal(view.posts[0].body.run_id, 'run-A');
    assert.equal(view.posts[0].body.hotel_id, 200);
    if (mode === 'review') assert.equal(view.posts[0].body.task_id, 31);
    view.posts[0].resolve(receipt(mode));
    await pending;
    assert.equal(view.patrol.value.latest.action_tracking.items['200|check_rate'].status, 'done');
    assert.equal(view.patrol.value.health.status, 'synthetic');
    assert.equal(view.refreshes.length, 1);
    assert.equal(view.refreshes[0].runId, 'run-A');
    assert.equal(Number(view.refreshes[0].hotelId), 200);
    assert.equal(view.notices.length, 1);
    assert.equal(view.busy.value, '');
  });

  test(`${mode}: same run and action blocks repeated and conflicting submissions`, async () => {
    const view = harness();
    const pending = submit(view, mode);
    submit(view, mode);
    view.update(action(), 'in_progress');
    view.review(action(), 'failed');
    assert.equal(view.posts.length, 1);
    view.posts[0].resolve(receipt(mode));
    await pending;
  });

  for (const change of ['run', 'refresh', 'deep-refresh', 'refresh-start', 'page', 'hotel']) {
    test(`${mode}: ${change} invalidates an older response without altering the new snapshot or error`, async () => {
      const view = harness();
      const pending = submit(view, mode);
      if (change === 'run') view.patrol.value = { latest: snapshot('run-B') };
      if (change === 'refresh') view.patrol.value = { latest: snapshot('run-A', 200, 'skipped') };
      if (change === 'deep-refresh') view.patrol.value.latest.action_tracking.items['200|check_rate'].status = 'skipped';
      if (change === 'refresh-start') view.startRefresh();
      if (change === 'page') view.changePage();
      if (change === 'hotel') view.hotel.value = '201';
      const expected = JSON.stringify(view.patrol.value);
      view.error.value = 'Current snapshot warning';
      view.posts[0].resolve(receipt(mode));
      await pending;
      assert.equal(JSON.stringify(view.patrol.value), expected);
      assert.equal(view.error.value, 'Current snapshot warning');
      assert.equal(view.notices.length, 0);
      assert.equal(view.refreshes.length, 0);
      assert.equal(view.operations.length, 0);
      assert.equal(view.busy.value, '');
    });
  }

  for (const outcome of ['success', 'failure']) {
    test(`${mode}: old-session ${outcome} cannot unlock a new submission with the same visible key`, async () => {
      const view = harness();
      const old = submit(view, mode);
      view.changeSession();
      view.patrol.value = { latest: snapshot() };
      const current = submit(view, mode);
      const busy = view.busy.value;
      if (outcome === 'success') view.posts[0].resolve(receipt(mode));
      else view.posts[0].reject(new Error('Synthetic old failure'));
      await old;
      assert.equal(view.busy.value, busy);
      assert.equal(view.error.value, '');
      assert.equal(view.notices.length, 0);
      assert.equal(view.refreshes.length, 0);
      view.posts[1].resolve(receipt(mode));
      await current;
      assert.equal(view.busy.value, '');
      assert.equal(view.notices.length, 1);
    });
  }

  test(`${mode}: malformed success receipts preserve the snapshot and report a recoverable error`, async () => {
    const changes = [
      value => { value.data = {}; },
      value => { value.data.latest = []; },
      value => { value.data.latest.run_id = 'wrong-run'; },
      value => { value.data.latest.scope.hotel_id = 201; },
      value => { value.data.latest.scope.hotel_id = [200]; },
      value => { value.data.latest.scope.target_date = '2026-09-13'; },
      value => { value.data.latest.scope.target_date = ['2026-09-14']; },
      value => { value.data.latest.action_tracking.items = {}; },
      value => { value.data.latest.action_tracking.items['200|check_rate'].hotel_id = 201; },
      value => { value.data.latest.action_tracking.items['200|check_rate'].action_code = ['check_rate']; },
      value => { value.data.latest.action_tracking.items['200|check_rate'].question_key = 'wrong-question'; },
      value => { value.data.latest.action_tracking.items['200|check_rate'][mode === 'review' ? 'review_result' : 'status'] = mode === 'review' ? { result_status: 'failed' } : 'pending'; },
    ];
    if (mode === 'review') changes.push(value => { value.data.latest.action_tracking.items['200|check_rate'].operation_execution.task_id = 99; });
    for (const change of changes) {
      const view = harness();
      const expected = JSON.stringify(view.patrol.value);
      const pending = submit(view, mode), response = receipt(mode);
      change(response);
      view.posts[0].resolve(response);
      await pending;
      assert.equal(JSON.stringify(view.patrol.value), expected);
      assert.ok(view.error.value);
      assert.equal(view.notices.length, 0);
      assert.equal(view.refreshes.length, 0);
      assert.equal(view.busy.value, '');
    }
  });

  test(`${mode}: current business and transport failures keep the snapshot and allow retry`, async () => {
    const view = harness(), expected = JSON.stringify(view.patrol.value);
    const first = submit(view, mode);
    view.posts[0].resolve({ code: 422, message: 'Synthetic business failure' });
    await first;
    assert.equal(view.error.value, 'Synthetic business failure');
    assert.equal(JSON.stringify(view.patrol.value), expected);
    const second = submit(view, mode);
    view.posts[1].reject(new Error('Synthetic network failure'));
    await second;
    assert.equal(view.error.value, 'Synthetic network failure');
    assert.equal(JSON.stringify(view.patrol.value), expected);
    const retry = submit(view, mode);
    view.posts[2].resolve(receipt(mode));
    await retry;
    assert.equal(view.error.value, '');
    assert.equal(view.notices.length, 1);
  });
}

test('switching runs permits the new submission while the old completion cannot clear its busy state', async () => {
  const view = harness();
  const old = view.update(action(), 'done');
  view.patrol.value = { latest: snapshot('run-B') };
  const current = view.update(action(), 'done');
  const busy = view.busy.value;
  view.posts[0].resolve(receipt('update'));
  await old;
  assert.equal(view.patrol.value.latest.run_id, 'run-B');
  assert.equal(view.busy.value, busy);
  assert.equal(view.notices.length, 0);
  view.posts[1].resolve(receipt('update', 'run-B'));
  await current;
  assert.equal(view.busy.value, '');
  assert.equal(view.refreshes.length, 1);
  assert.equal(view.refreshes[0].runId, 'run-B');
});

test('different actions in one run are serialized visibly and can submit after the first receipt', async () => {
  const view = harness();
  const first = view.update(action(), 'done');
  view.update(action(200, 'check_rooms'), 'done');
  assert.equal(view.posts.length, 1);
  assert.match(view.notices[0][0], /提交.*重试/);
  view.posts[0].resolve(receipt('update'));
  await first;
  const second = view.update(action(200, 'check_rooms'), 'done');
  assert.equal(view.posts.length, 2);
  const response = receipt('update');
  response.data.latest.action_tracking.items['200|check_rooms'] = {
    hotel_id: 200, action_code: 'check_rooms', status: 'done',
  };
  view.posts[1].resolve(response);
  await second;
  assert.equal(view.patrol.value.latest.action_tracking.items['200|check_rate'].status, 'done');
  assert.equal(view.patrol.value.latest.action_tracking.items['200|check_rooms'].status, 'done');
  assert.equal(view.refreshes.length, 2);
});

test('in-progress success keeps operation-intent loading and accepts the snapshot response alias', async () => {
  const view = harness();
  const pending = view.update(action(), 'in_progress');
  view.posts[0].resolve({ code: 200, data: { snapshot: snapshot('run-A', 200, 'in_progress') } });
  await pending;
  assert.equal(view.operations.length, 1);
  assert.equal(view.filters.value.hotel_id, '200');
  assert.match(view.notices[0][0], /运营执行意图/);
  assert.equal(view.refreshes.length, 1);
});

test('saved in-progress intent does not claim operations entry when adjacent list loading fails', async () => {
  const view = harness();view.setOperationLoadSuccess(false);
  const pending = view.update(action(), 'in_progress');
  view.posts[0].resolve({code:200,data:{latest:snapshot('run-A',200,'in_progress')}});
  await pending;
  assert.equal(view.patrol.value.latest.action_tracking.items['200|check_rate'].status,'in_progress');
  assert.equal(view.operations.length,1);
  assert.equal(view.notices.some(item=>/请在下方完成审批/.test(item[0])),false);
  assert.match(view.error.value,/执行列表读取失败/);
  assert.equal(view.refreshes.length,1);
});

test('an action-specific target date is still sent without changing the patrol scope-date receipt contract', async () => {
  const view = harness();
  const pending = view.update({ ...action(), targetDate: '2026-09-13' }, 'done');
  assert.equal(view.posts[0].body.target_date, '2026-09-13');
  view.posts[0].resolve(receipt('update'));
  await pending;
  assert.equal(view.patrol.value.latest.scope.target_date, '2026-09-14');
  assert.equal(view.error.value, '');
  assert.equal(view.refreshes.length, 1);
});

test('switching snapshots while operation intents load suppresses the late success UI and refresh', async () => {
  const view = harness();
  let finishOperations;
  view.waitForOperations(new Promise(resolve => { finishOperations = resolve; }));
  const pending = view.update(action(), 'in_progress');
  view.posts[0].resolve({ code: 200, data: { latest: snapshot('run-A', 200, 'in_progress') } });
  for (let index = 0; index < 4; index += 1) await Promise.resolve();
  assert.equal(view.operations.length, 1);
  view.patrol.value = { latest: snapshot('run-B') };
  finishOperations();
  await pending;
  assert.equal(view.patrol.value.latest.run_id, 'run-B');
  assert.equal(view.notices.length, 0);
  assert.equal(view.refreshes.length, 0);
});

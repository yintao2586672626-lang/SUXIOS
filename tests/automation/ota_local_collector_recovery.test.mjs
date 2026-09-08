import assert from 'node:assert/strict';
import test from 'node:test';
import { ref, computed } from 'vue';
import { recoverySources, recoveryTask } from './helpers/ota_recovery_fixture.mjs';
const { projection, action } = await recoverySources();
const project = Function(`${projection}; return buildLocalCollectorCollectionReceipt;`)();

test('normal, partial, failed, unknown and recovered receipts have distinct evidence and actions', () => {
  const labels = new Set();
  for (const state of ['success', 'partial', 'failed', 'unknown', 'recovered_success']) {
    const row = project(recoveryTask(state));
    assert.equal(row.state, state);
    labels.add(row.stateText);
    if (state === 'unknown') {
      assert.deepEqual(row.recoveryActions.map(value => value.code), ['reconcile']);
      assert.match(row.savedText, /未确认/);
      assert.match(row.readbackText, /未知/);
    }
    if (state === 'success') assert.match(row.authorityText, /等待另一平台/);
    if (state === 'partial') assert.match(row.recoveryMissingText, /曝光.*详情访客.*转化率/);
  }
  assert.equal(labels.size, 5);
});

test('recovery scope mismatch, old attempts and status-only success cannot enable a successful receipt', () => {
  for (const key of ['system_hotel_id', 'tenant_id', 'account_id', 'platform', 'business_date', 'data_type']) {
    const task = recoveryTask();
    task.recovery_item.scope = { ...task.recovery_item.scope, [key]: 'different' };
    assert.equal(project(task).state, 'scope_mismatch');
    assert.deepEqual(project(task).recoveryActions, []);
  }
  const noEvidence = recoveryTask('success');
  noEvidence.result_summary = {};
  assert.equal(project(noEvidence).state, 'unverified');
  const previous = recoveryTask('success');
  previous.attempt = 3;
  assert.notEqual(project(previous).state, 'success');
});

function harness(request) {
  const tasks = ref([recoveryTask('partial')]);
  const views = ref({});
  let session = 1;
  let refreshes = 0;
  const rows = computed(() => tasks.value.map(project));
  const run = Function('localCollectorRecoveryViews', 'localCollectorCollectionTaskRows', 'captureAuthSession', 'isAuthSessionCurrent',
    'request', 'loadLocalCollectorStatus', `${projection}; let localCollectorRecoveryRequestSequence = 0; ${action}; return runLocalCollectorRecovery;`)(
    views, rows, () => session, captured => captured === session, request, async () => { refreshes++; });
  return { run, rows, views, tasks, invalidate: () => { session++; views.value = {}; }, refreshes: () => refreshes };
}

test('recovery serializes double clicks and sends the immutable original scope', async () => {
  let complete;
  let count = 0;
  const h = harness(async (url, options) => {
    count++;
    const input = JSON.parse(options.body);
    assert.equal(url, '/online-data/local-collector/tasks/42/recover');
    assert.equal(input.scope.business_date, '2026-09-01');
    assert.equal(input.scope.platform_hotel_id, 'SYNTHETIC-MT-101');
    return new Promise(resolve => { complete = resolve; });
  });
  const row = h.rows.value[0];
  const first = h.run(row, 'backfill');
  assert.equal(await h.run(row, 'backfill'), false);
  complete({ code: 200, data: { recovery: recoveryTask().recovery_item, message: 'Synthetic recovery verified' } });
  assert.equal(await first, true);
  assert.equal(count, 1);
  assert.equal(h.refreshes(), 1);
  assert.equal(h.views.value[row.evidenceKey].loading, false);
});

test('late recovery response after auth change cannot update the new session', async () => {
  let complete;
  const h = harness(() => new Promise(resolve => { complete = resolve; }));
  const result = h.run(h.rows.value[0], 'reconcile');
  h.invalidate();
  complete({ code: 200, data: { recovery: recoveryTask().recovery_item } });
  assert.equal(await result, false);
  assert.equal(h.refreshes(), 0);
  assert.deepEqual(h.views.value, {});
});

test('cross-date response remains an error and does not refresh unrelated facts', async () => {
  const item = recoveryTask().recovery_item;
  item.scope.business_date = '2026-09-02';
  const h = harness(async () => ({ code: 200, data: { recovery: item } }));
  assert.equal(await h.run(h.rows.value[0], 'reconcile'), false);
  assert.equal(h.refreshes(), 0);
  assert.match(Object.values(h.views.value)[0].error, /不一致/);
});

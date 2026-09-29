import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(new URL('../../public/components/system/app-main-components.js', import.meta.url), 'utf8');

function deferred() {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

function profile(hotelId, managerId, canManage = true) {
  return {
    hotel_id: hotelId, manager_user_id: managerId,
    dimensions: Array.from({ length: 6 }, (_, index) => ({ key: `dimension-${index}`, score: null })),
    recent_cases: [{ id: managerId * 10, problem_facts: 'Synthetic scoped case' }],
    scoring_contract: { version: 'manager_capability_evidence_v1' },
    daily_submission: { business_date: '2026-09-26', closure_inferred: false },
    window: { date_to: '2026-09-26' }, source: { fingerprint: 'a'.repeat(64) },
    permissions: { can_manage_evidence: canManage, can_view_evidence_detail: canManage },
  };
}

function fixture() {
  const runtimeWindow = { SUXI_ONLINE_DATA_COMPONENTS: {}, SUXI_SYSTEM_COMPONENTS: {} };
  const h = (type, props, children) => ({ type, props, children });
  new Function('window', source)(runtimeWindow);
  const panel = runtimeWindow.SUXI_APP_MAIN_COMPONENTS_FULL.create({ Vue: { h, defineAsyncComponent: loader => ({ loader }) }, h }).ManagerCapabilityPanel;
  const pending = [];
  const vm = { ...panel.data(), hotelId: 80, selectedManagerId: '7', request(url) {
    const request = { url, ...deferred() }; pending.push(request); return request.promise;
  } };
  for (const [name, method] of Object.entries(panel.methods)) vm[name] = method.bind(vm);
  for (const [name, getter] of Object.entries(panel.computed)) Object.defineProperty(vm, name, { get: () => getter.call(vm) });
  vm.profile = profile(80, 7);
  vm.managers = [{ id: 7 }];
  vm.followupQueue = { hotel_id: 80, manager_user_id: 7, rows: [{ id: 70 }] };
  return { vm, pending };
}

test('manager selection immediately clears prior profile permissions and ignores its late queue error', async () => {
  const { vm, pending } = fixture();
  const oldQueue = vm.loadFollowupQueue();
  vm.selectedManagerId = '8';
  const currentProfile = vm.loadProfile();
  assert.equal(vm.profile, null);
  assert.equal(vm.canManageEvidence, false);
  assert.equal(vm.canViewEvidenceDetail, false);
  assert.deepEqual(vm.recentCases, []);
  assert.equal(vm.followupQueue, null);
  assert.equal(vm.queueLoading, false);
  pending[1].resolve({ code: 200, data: profile(80, 8, false) });
  await currentProfile;
  pending[0].reject(new Error('Synthetic prior-manager queue failure'));
  await oldQueue;
  assert.equal(vm.profile.manager_user_id, 8);
  assert.equal(vm.queueError, '');
  assert.equal(vm.followupQueue, null);
});

test('hotel selection clears previous managers and permissions while its new list is pending', async () => {
  const { vm, pending } = fixture();
  const oldQueue = vm.loadFollowupQueue();
  vm.hotelId = 81;
  const currentList = vm.load();
  assert.deepEqual(vm.managers, []);
  assert.equal(vm.profile, null);
  assert.equal(vm.canManageEvidence, false);
  assert.equal(vm.followupQueue, null);
  assert.equal(vm.queueLoading, false);
  pending[0].reject(new Error('Synthetic prior-hotel queue failure'));
  await oldQueue;
  assert.equal(vm.queueError, '');
  pending[1].resolve({ code: 200, data: { hotel_id: 81, list: [] } });
  await currentList;
  assert.equal(vm.selectedManagerId, '');
  assert.equal(vm.profile, null);
});

test('refresh invalidates a same-identity queue before loading revised permissions', async () => {
  const { vm, pending } = fixture();
  const oldQueue = vm.loadFollowupQueue();
  const currentProfile = vm.loadProfile();
  pending[1].resolve({ code: 200, data: profile(80, 7, false) });
  await currentProfile;
  pending[0].resolve({ code: 200, data: { hotel_id: 80, manager_user_id: 7, rows: [{ id: 999 }] } });
  await oldQueue;
  assert.equal(vm.canManageEvidence, false);
  assert.equal(vm.followupQueue, null);
  assert.equal(vm.queueLoading, false);
});

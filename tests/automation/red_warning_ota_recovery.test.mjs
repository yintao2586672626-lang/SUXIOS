import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// Execute the actual handlers with synthetic responses; no browser, account or DB access.
const source = readFileSync('public/app-main.js', 'utf8');
const ref = (value) => ({ value });
const clone = (value) => JSON.parse(JSON.stringify(value));
const deferred = () => {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
};
const abort = () => Object.assign(new Error('page_unloaded'), { name: 'AbortError' });

function handler(name, context) {
  const marker = `            const ${name} =`;
  const start = source.indexOf(marker);
  assert.ok(start >= 0, `production handler ${name} exists`);
  const closing = '\n            };';
  const end = source.indexOf(closing, start);
  assert.ok(end > start, `production handler ${name} has a closing boundary`);
  return vm.runInNewContext(`${source.slice(start, end + closing.length)}\n${name}`, context,
    { filename: `public/app-main.js:${name}` });
}

function base() {
  const toasts = [];
  const session = { epoch: 1, userId: 1, tenantId: 1 };
  const context = {
    URLSearchParams, Date, console: { error() {}, warn() {} },
    pageRequestGeneration: 1,
    captureAuthSession: () => session,
    isAuthSessionCurrent: (captured) => captured === session,
    showToast: (...args) => toasts.push(args),
    normalizeRequestCacheOptions: (options) => options || {},
  };
  return { context, toasts };
}

const contracts = [
  {
    name: 'loadDataHealthOperationLogs', error: 'dataHealthOperationLogError',
    data: 'dataHealthOperationLogs', loading: 'dataHealthOperationLogLoading',
    bindings: () => ({ dataHealthOperationLogRequestSeq: 0, user: ref({ is_super_admin: true }), dataHealthOperationLogError: ref(''),
      dataHealthOperationLogs: ref([{ id: 7 }]), dataHealthOperationLogScope: ref({ days: 7 }),
      dataHealthOperationLogLoading: ref(false) }),
  },
  {
    name: 'loadPublicEndpointSecurity', error: 'publicEndpointSecurityError',
    data: 'publicEndpointSecurity', loading: 'publicEndpointSecurityLoading',
    bindings: () => ({ publicEndpointSecurityRequestSeq: 0, user: ref({ is_super_admin: true }), publicEndpointSecurityError: ref(''),
      publicEndpointSecurity: ref({ status: 'ready' }), publicEndpointSecurityLoading: ref(false) }),
  },
  {
    name: 'loadReleaseEvidenceStatus', error: 'releaseEvidenceError',
    data: 'releaseEvidenceStatus', loading: 'releaseEvidenceLoading',
    bindings: () => ({ releaseEvidenceRequestSeq: 0, user: ref({ is_super_admin: true }), releaseEvidenceError: ref(''),
      releaseEvidenceStatus: ref({ status: 'ready' }), releaseEvidenceLoading: ref(false) }),
  },
  {
    name: 'loadLocalCollectorStatus', error: 'localCollectorError',
    data: 'localCollectorStatus', loading: 'localCollectorLoading',
    bindings: () => ({ localCollectorStatusRequestSeq: 0, localCollectorLoading: ref(false),
      localCollectorError: ref(''), localCollectorStatus: ref({ tasks: [{ id: 7 }] }),
      localCollectorAccountForm: ref({}), localCollectorBindingForm: ref({}),
      localCollectorBackfillDate: ref('2026-10-02') }),
  },
  {
    name: 'loadCloudBrowserAuthorization', error: 'cloudBrowserAuthorizationError',
    data: 'cloudBrowserAuthorization', loading: 'cloudBrowserAuthorizationLoading',
    bindings: () => ({ getAutoFetchHotelId: () => 80, cloudBrowserAuthorizationRequestSeq: 0,
      cloudBrowserAuthorizationError: ref(''), cloudBrowserAuthorizationLoading: ref(false),
      cloudBrowserAuthorization: ref({ hotel_id: 80,
        profiles: [{ platform: 'ctrip', authorization_status: 'ready_to_collect' }] }) }),
  },
  {
    name: 'loadCompetitorEventFeed', error: 'competitorEventFeedError',
    data: 'competitorEventFeed', loading: 'competitorEventFeedLoading',
    bindings: () => ({ competitorEventFeedRequestSeq: 0,
      onlineDataFilter: ref({ hotel_id: '80', source: 'ctrip' }),
      competitorEventFeedStayDate: ref('2026-10-02'), competitorEventFeedError: ref(''),
      competitorEventFeed: ref({ status: 'ready', hotel_id: 80 }), competitorEventFeedLoading: ref(false) }),
  },
  {
    name: 'loadCoreOperationsMetrics', data: 'coreOperationsMetrics', args: [80, '2026-10-02', 1],
    bindings: () => ({ coreOperationsRequestSeq: 1,
      coreOperationsMetrics: ref({ ctrip: { status: 'ready', data: { marker: 'saved' } },
        meituan: { status: 'ready', data: { marker: 'saved' } } }) }),
  },
  {
    name: 'loadCoreOperationsDiagnoses', data: 'coreOperationsDiagnoses', args: [80, '2026-10-02', 1],
    bindings: () => ({ coreOperationsRequestSeq: 1,
      coreOperationsDiagnoses: ref({ ctrip: { status: 'ready', data: { marker: 'saved' } },
        meituan: { status: 'ready', data: { marker: 'saved' } } }) }),
  },
];

function fixture(contract, request) {
  const f = base();
  Object.assign(f.context, contract.bindings(), { request, readSavedOtaDiagnosis: request });
  f.load = handler(contract.name, f.context);
  return f;
}

for (const contract of contracts) {
  test(`${contract.name}: cancelled reads preserve prior evidence without a failure warning`, async () => {
    const f = fixture(contract, async () => { throw abort(); });
    const prior = clone(f.context[contract.data].value);
    await f.load(...(contract.args || []));
    if (contract.error) assert.equal(f.context[contract.error].value, '');
    assert.deepEqual(clone(f.context[contract.data].value), prior);
    if (contract.loading) assert.equal(f.context[contract.loading].value, false);
    assert.equal(f.toasts.length, 0, 'a cancellation must not produce a failure toast');
  });

  test(`${contract.name}: a genuine read failure remains explicit`, async () => {
    const f = fixture(contract, async () => { throw new Error('synthetic service unavailable'); });
    await f.load(...(contract.args || []));
    if (contract.error) assert.equal(f.context[contract.error].value, 'synthetic service unavailable');
    else for (const platform of ['ctrip', 'meituan']) {
      assert.equal(f.context[contract.data].value[platform].status, 'failed');
      assert.equal(f.context[contract.data].value[platform].message, 'synthetic service unavailable');
    }
    if (contract.loading) assert.equal(f.context[contract.loading].value, false);
  });
}

test('cloud authorization: an older hotel response cannot replace the current hotel state', async () => {
  const calls = [], contract = contracts.find((item) => item.name === 'loadCloudBrowserAuthorization');
  let hotelId = 80;
  const f = fixture(contract, () => { const call = deferred(); calls.push(call); return call.promise; });
  f.context.getAutoFetchHotelId = () => hotelId;
  const oldRequest = f.load();
  hotelId = 81;
  const newRequest = f.load();
  const current = { hotel_id: 81, profiles: [{ platform: 'ctrip', authorization_status: 'ready_to_collect' }] };
  calls[1].resolve({ code: 200, data: current });
  await newRequest;
  calls[0].resolve({ code: 200, data: { hotel_id: 80,
    profiles: [{ platform: 'ctrip', authorization_status: 'session_expired' }] } });
  await oldRequest;
  assert.deepEqual(clone(f.context.cloudBrowserAuthorization.value), current);
  assert.equal(f.context.cloudBrowserAuthorizationError.value, '');
});

for (const name of ['loadCloudBrowserAuthorization', 'loadLocalCollectorStatus']) {
  test(`${name}: an older cancellation cannot release a newer request's loading state`, async () => {
    const calls = [], contract = contracts.find((item) => item.name === name);
    const f = fixture(contract, () => { const call = deferred(); calls.push(call); return call.promise; });
    const first = f.load(), second = f.load();
    calls[0].reject(abort());
    await first;
    assert.equal(f.context[contract.loading].value, true);
    assert.equal(f.context[contract.error].value, '');
    calls[1].resolve({ code: 200, data: name === 'loadCloudBrowserAuthorization'
      ? { hotel_id: 80, profiles: [] } : { status: 'unverified', devices: [], accounts: [], tasks: [] } });
    await second;
    assert.equal(f.context[contract.loading].value, false);
  });
}

function executionFixture() {
  const f = base(), pending = deferred(), opened = [];
  let runs = { research: { result: { hotel_scope: { hotel_id: 80 }, execution_artifact: { id: 7 } } } };
  Object.assign(f.context, {
    revenueResearchRequestEpoch: 0, revenueResearchHotelId: ref('80'), revenueResearchModelKey: ref('deepseek_chat'),
    revenueResearchRequestSeq: new Map(), revenueResearchExecutionRequestSeq: new Map(),
    ensureRevenueResearchReady: async () => ({}),
    revenueResearchRunFor: (key) => runs[key] || {}, revenueResearchExecutionBlockedText: () => '',
    setRevenueResearchRun: (key, patch) => { runs[key] = { ...(runs[key] || {}), ...patch }; },
    apiRequest: () => pending.promise,
    openRevenueResearchExecutionIntent: async (product) => { opened.push(product.key); },
  });
  return { ...f, pending, opened, snapshot: () => clone(runs),
    load: handler('createRevenueResearchExecutionIntent', f.context),
    switchHotel() { f.context.revenueResearchRequestEpoch += 1; f.context.revenueResearchHotelId.value = '81'; runs = {}; },
  };
}

for (const outcome of ['success', 'failure']) {
  test(`revenue research execution: an old hotel's ${outcome} cannot recreate state or navigate`, async () => {
    const f = executionFixture();
    const oldRequest = f.load({ key: 'research' });
    await new Promise(setImmediate);
    f.switchHotel();
    if (outcome === 'success') f.pending.resolve({ code: 200,
      data: { execution_intent: { id: 99, hotel_id: 80 } } });
    else f.pending.reject(new Error('synthetic previous-hotel write response lost'));
    await oldRequest;
    assert.deepEqual(f.snapshot(), {}, 'an old result must not recreate the cleared current-hotel run');
    assert.equal(f.opened.length, 0, 'an old result must not navigate to the previous hotel');
    assert.equal(f.toasts.length, 0, 'an old failure must not warn in the newer hotel scope');
  });
}

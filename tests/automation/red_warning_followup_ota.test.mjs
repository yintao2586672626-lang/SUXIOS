import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// Follow-up gaps only: actual handlers, isolated fake transport, no accounts or DB.
const source = readFileSync('public/app-main.js', 'utf8');
const ref = (value) => ({ value });
const plain = (value) => JSON.parse(JSON.stringify(value));
const deferred = () => {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
};
const abort = () => Object.assign(new Error('old page read cancelled'), { name: 'AbortError' });

function handler(name, context) {
  const start = source.indexOf(`            const ${name} =`);
  const closing = '\n            };';
  const end = source.indexOf(closing, start);
  assert.ok(start >= 0 && end > start, `the production ${name} handler exists`);
  return vm.runInNewContext(`${source.slice(start, end + closing.length)}\n${name}`, context,
    { filename: `public/app-main.js:${name}` });
}

function contextBase() {
  const session = { epoch: 1 };
  return {
    session,
    context: {
      URLSearchParams, Date, console: { error() {}, warn() {} },
      captureAuthSession: () => ({ epoch: session.epoch }),
      isAuthSessionCurrent: (captured) => captured?.epoch === session.epoch,
      pageRequestGeneration: 1, filterReportHotel: ref('80'), currentPage: ref('online-data'),
      showToast() {},
    },
  };
}

test('cloud authorization: switching hotels hides the prior hotel while the new read is pending', async () => {
  const f = contextBase(), pending = deferred();
  let hotelId = 80;
  Object.assign(f.context, {
    cloudBrowserAuthorizationRequestSeq: 0, getAutoFetchHotelId: () => hotelId,
    cloudBrowserAuthorization: ref({ hotel_id: 80,
      profiles: [{ platform: 'ctrip', authorization_status: 'ready_to_collect' }] }),
    cloudBrowserAuthorizationError: ref(''), cloudBrowserAuthorizationLoading: ref(false),
    request: () => pending.promise,
  });
  hotelId = 81;
  const job = handler('loadCloudBrowserAuthorization', f.context)();
  const wasLoading = f.context.cloudBrowserAuthorizationLoading.value;
  const priorHotelProfilesWhilePending = (f.context.cloudBrowserAuthorization.value?.profiles || []).length;
  pending.resolve({ code: 200, data: { hotel_id: 81, profiles: [] } });
  await job;
  assert.equal(wasLoading, true);
  assert.equal(priorHotelProfilesWhilePending, 0,
    'hotel 80 authorization must not remain available to the hotel 81 panel during the pending read');
});

const summaries = [
  {
    name: 'loadDataHealthOperationLogs', data: 'dataHealthOperationLogs', error: 'dataHealthOperationLogError',
    loading: 'dataHealthOperationLogLoading', initial: [{ id: 1 }], latest: [{ id: 2 }],
    bindings: () => ({ dataHealthOperationLogRequestSeq: 0,
      dataHealthOperationLogs: ref([{ id: 1 }]), dataHealthOperationLogError: ref(''),
      dataHealthOperationLogLoading: ref(false), dataHealthOperationLogScope: ref({ marker: 'prior' }) }),
    response: () => ({ code: 200, data: { list: [{ id: 2 }], scan_scope: { marker: 'latest' } } }),
  },
  {
    name: 'loadPublicEndpointSecurity', data: 'publicEndpointSecurity', error: 'publicEndpointSecurityError',
    loading: 'publicEndpointSecurityLoading', initial: { marker: 'prior' }, latest: { marker: 'latest' },
    bindings: () => ({ publicEndpointSecurityRequestSeq: 0,
      publicEndpointSecurity: ref({ marker: 'prior' }), publicEndpointSecurityError: ref(''),
      publicEndpointSecurityLoading: ref(false) }),
    response: () => ({ code: 200, data: { marker: 'latest' } }),
  },
  {
    name: 'loadReleaseEvidenceStatus', data: 'releaseEvidenceStatus', error: 'releaseEvidenceError',
    loading: 'releaseEvidenceLoading', initial: { marker: 'prior' }, latest: { marker: 'latest' },
    bindings: () => ({ releaseEvidenceRequestSeq: 0,
      releaseEvidenceStatus: ref({ marker: 'prior' }), releaseEvidenceError: ref(''),
      releaseEvidenceLoading: ref(false) }),
    response: () => ({ code: 200, data: { marker: 'latest' } }),
  },
];

function summaryFixture(contract) {
  const f = contextBase(), calls = [];
  Object.assign(f.context, contract.bindings(), {
    user: ref({ is_super_admin: true }),
    request: () => { const pending = deferred(); calls.push(pending); return pending.promise; },
  });
  return { ...f, calls, load: handler(contract.name, f.context) };
}

for (const contract of summaries) {
  test(`${contract.name}: the old session cancellation cannot release a new session's pending loading`, async () => {
    const f = summaryFixture(contract);
    const oldRead = f.load();
    f.session.epoch += 1;
    const newRead = f.load();
    f.calls[0].reject(abort());
    await oldRead;
    const newerReadStillLoading = f.context[contract.loading].value;
    const cancellationError = f.context[contract.error].value;
    f.calls[1].resolve(contract.response());
    await newRead;
    assert.equal(newerReadStillLoading, true,
      'the new session read is still pending and must retain its loading state');
    assert.equal(cancellationError, '');
    assert.equal(f.context[contract.loading].value, false);
    assert.deepEqual(plain(f.context[contract.data].value), contract.latest);
  });

  test(`${contract.name}: a late old-session network failure cannot erase the newer successful result`, async () => {
    const f = summaryFixture(contract);
    const oldRead = f.load();
    f.session.epoch += 1;
    const newRead = f.load();
    f.calls[1].resolve(contract.response());
    await newRead;
    f.calls[0].reject(new Error('previous session transport failed late'));
    await oldRead;
    assert.deepEqual(plain(f.context[contract.data].value), contract.latest);
    assert.equal(f.context[contract.error].value, '', 'an old transport failure cannot warn in the new session');
  });
}

for (const [name, dataKey, kind] of [
  ['loadCoreOperationsMetrics', 'coreOperationsMetrics', 'metrics'],
  ['loadCoreOperationsDiagnoses', 'coreOperationsDiagnoses', 'diagnoses'],
]) {
  for (const outcome of ['success', 'failure']) {
    test(`${name}: a cancelled Ctrip read cannot discard Meituan's real ${outcome}`, async () => {
      const f = contextBase();
      const platformRead = async (...args) => {
        const platform = kind === 'metrics' ? new URLSearchParams(args[0].split('?')[1]).get('source') : args[1];
        if (platform === 'ctrip') throw abort();
        if (outcome === 'failure') throw new Error('current Meituan service unavailable');
        const payload = { status: 'ready', decision_status: 'ready', marker: 'current-meituan' };
        return kind === 'metrics' ? { code: 200, data: payload } : { code: 200, data: { diagnosis: payload } };
      };
      Object.assign(f.context, {
        coreOperationsRequestSeq: 1, coreOperationsHotelId: ref('80'), coreOperationsTargetDate: ref('2026-10-02'),
        [dataKey]: ref({ ctrip: { status: 'ready', data: { marker: 'prior-ctrip' } },
          meituan: { status: 'ready', data: { marker: 'prior-meituan' }, message: '' } }),
        request: platformRead, readSavedOtaDiagnosis: platformRead,
      });
      await handler(name, f.context)(80, '2026-10-02', 1);
      const meituan = f.context[dataKey].value.meituan;
      if (outcome === 'success') assert.equal(meituan.data?.marker, 'current-meituan');
      else {
        assert.equal(meituan.status, 'failed');
        assert.equal(meituan.message, 'current Meituan service unavailable');
      }
    });
  }
}

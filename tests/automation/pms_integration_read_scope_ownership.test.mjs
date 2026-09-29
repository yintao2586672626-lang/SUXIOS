import assert from 'node:assert/strict';
import test from 'node:test';
import vm from 'node:vm';
import { readAppMainContractSource } from './helpers/frontend_source.mjs';

const appMain = readAppMainContractSource();
const providers = [
  {
    key: 'dingdandao',
    method: 'loadDingdandaoPmsIntegration',
    nextMethod: 'saveDingdandaoPmsIntegration',
    state: 'dingdandaoPmsIntegration',
    error: 'dingdandaoPmsError',
    loading: 'dingdandaoPmsLoading',
    apply: 'applyDingdandaoPmsIntegration',
    message: '订单来了接口维护状态读取失败',
  },
  {
    key: 'meituan-cloud',
    method: 'loadMeituanCloudPmsIntegration',
    nextMethod: 'saveMeituanCloudPmsIntegration',
    state: 'meituanCloudPmsIntegration',
    error: 'meituanCloudPmsError',
    loading: 'meituanCloudPmsLoading',
    apply: 'applyMeituanCloudPmsIntegration',
    message: '美团云 PMS 数据源状态读取失败',
  },
];

const extractLoader = provider => {
  const marker = `const ${provider.method} = async`;
  const start = appMain.indexOf(marker);
  const end = appMain.indexOf(`\n            const ${provider.nextMethod}`, start + marker.length);
  assert.notEqual(start, -1, `${provider.method} must exist`);
  assert.notEqual(end, -1, `${provider.method} end must exist`);
  return appMain.slice(start, end);
};

const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return {promise, resolve, reject};
};

const makeHarness = provider => {
  const pending = [];
  const applied = [];
  const form = {value: {hotel_id: '64', target_date: '2026-09-28'}};
  const page = {value: 'operating-targets'};
  const state = {value: {marker: 'preserved'} };
  const error = {value: 'previous notice'};
  const loading = {value: {status: false}};
  const dingdandaoLoading = provider.key === 'dingdandao' ? loading : {value: {status: false}};
  const meituanLoading = provider.key === 'meituan-cloud' ? loading : {value: {status: false}};
  let inputVersion = 0;
  let sessionVersion = 0;
  const sandbox = {
    operatingTargetForm: form,
    currentPage: page,
    [provider.state]: state,
    [provider.error]: error,
    [provider.loading]: loading,
    operatingTargetMeituanCloudPmsStatus: {value: {marker: 'preserved-capture'}},
    dingdandaoPmsIntegrationRequestSequence: 0,
    meituanCloudPmsIntegrationRequestSequence: 0,
    operatingTargetContext: () => ({
      hotelId: String(form.value.hotel_id || '').trim(),
      targetDate: String(form.value.target_date || '').trim(),
    }),
    operatingTargetScopeIsCurrent: context => (
      String(form.value.hotel_id || '').trim() === String(context?.hotelId || '')
      && String(form.value.target_date || '').trim() === String(context?.targetDate || '')
    ),
    captureOperatingTargetInput: () => {
      const version = inputVersion;
      const session = sessionVersion;
      const currentPage = page.value;
      return {
        isCurrent: () => (
          version === inputVersion
          && session === sessionVersion
          && currentPage === page.value
        ),
        stop: () => {},
      };
    },
    apiRequest: path => {
      const request = deferred();
      pending.push({path, ...request});
      return request.promise;
    },
    operationErrorMessage: e => e?.message || 'PMS 状态读取失败',
    [provider.apply]: data => {
      applied.push(data);
      state.value = data;
      if (provider.key === 'meituan-cloud' && data?.capture) {
        sandbox.operatingTargetMeituanCloudPmsStatus.value = data.capture;
      }
    },
    dingdandaoPmsLoading: dingdandaoLoading,
    meituanCloudPmsLoading: meituanLoading,
  };
  const source = extractLoader(provider);
  const load = vm.runInNewContext(
    `(() => { ${source}; return ${provider.method}; })()`,
    sandbox,
  );
  const changeHotel = value => {
    form.value.hotel_id = value;
    inputVersion += 1;
  };
  const changeDate = value => {
    form.value.target_date = value;
    inputVersion += 1;
  };
  const changePage = value => {
    page.value = value;
    inputVersion += 1;
  };
  const changeSession = () => { sessionVersion += 1; };
  return {
    load, pending, applied, state, error, loading, form,
    changeHotel, changeDate, changePage, changeSession,
  };
};

const verifiedResponse = marker => ({
  code: 200,
  data: {marker, capture: {business_date: '2026-09-28', readback_status: 'readback_verified'}},
});

const staleTransitions = [
  ['hotel switch', h => h.changeHotel('80')],
  ['hotel A-B-A', h => { h.changeHotel('80'); h.changeHotel('64'); }],
  ['business-date A-B-A', h => { h.changeDate('2026-09-27'); h.changeDate('2026-09-28'); }],
  ['page leave and return', h => { h.changePage('home'); h.changePage('operating-targets'); }],
  ['auth session change', h => h.changeSession()],
];

for (const provider of providers) {
  for (const [index, [transition, mutate]] of staleTransitions.entries()) {
    test(`${provider.key} ignores a stale ${transition} response`, async () => {
      const h = makeHarness(provider);
      const done = h.load();
      mutate(h);
      if (index % 2 === 0) h.pending[0].resolve(verifiedResponse('stale'));
      else h.pending[0].reject(new Error('stale provider error'));
      await done;

      assert.equal(h.applied.length, 0);
      assert.deepEqual(h.state.value, {marker: 'preserved'});
      assert.equal(h.error.value, '');
      assert.equal(h.loading.value.status, false);
    });
  }

  test(`${provider.key} older request cannot overwrite newer integration state or clear its loading state`, async () => {
    const h = makeHarness(provider);
    const older = h.load();
    const newer = h.load();

    h.pending[0].resolve(verifiedResponse('older'));
    await older;
    assert.equal(h.applied.length, 0);
    assert.equal(h.loading.value.status, true);

    h.pending[1].resolve(verifiedResponse('newer'));
    await newer;
    assert.deepEqual(h.applied.map(item => item.marker), ['newer']);
    assert.equal(h.state.value.marker, 'newer');
    assert.equal(h.loading.value.status, false);
  });

  test(`${provider.key} applies a current response and exposes its current failure`, async () => {
    const h = makeHarness(provider);
    const success = h.load();
    h.pending[0].resolve(verifiedResponse('current'));
    await success;
    assert.deepEqual(h.applied.map(item => item.marker), ['current']);
    assert.equal(h.error.value, '');
    assert.equal(h.loading.value.status, false);

    const failure = h.load();
    h.pending[1].reject(new Error('current provider unavailable'));
    await failure;
    assert.equal(h.state.value, null);
    assert.equal(h.error.value, 'current provider unavailable');
    assert.equal(h.loading.value.status, false);
  });
}

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const appMain = readFileSync('public/app-main.js', 'utf8');
const ctripStaticSource = readFileSync('public/ctrip-static.js', 'utf8');
const sandbox = { console, URL, window: {} };
vm.runInNewContext(
  `${ctripStaticSource}\nthis.__api = window.SUXI_CTRIP_STATIC;`,
  sandbox,
  { filename: 'public/ctrip-static.js' },
);
const ctripStatic = sandbox.__api;

const sliceBetween = (source, startNeedle, endNeedle) => {
  const start = source.indexOf(startNeedle);
  assert.notEqual(start, -1, `missing start marker: ${startNeedle}`);
  const end = source.indexOf(endNeedle, start + startNeedle.length);
  assert.notEqual(end, -1, `missing end marker: ${endNeedle}`);
  return source.slice(start, end);
};

function startupHydrationHarness({ failLoader = false } = {}) {
  let resolveModule;
  const moduleReady = new Promise(resolve => { resolveModule = resolve; });
  const errors = [];
  const context = {
    window: {}, URL, Date, console: { error: (...args) => errors.push(args.map(String).join(' ')) },
    current: true, ref: value => ({ value }),
    captureAuthSession: () => 'synthetic-session', isAuthSessionCurrent: () => context.current,
    isPageLoadPolicyCurrent: () => true, currentPageReadPolicy: () => ({}),
    currentPage: { value: 'home' }, isManualConfigListCacheFresh: () => false,
    coordinatedGetPriorityRank: () => 1, alignCtripTargetHotelToConfiguredContext: () => {},
    ctripConfigList: { value: [] }, selectedCtripConfigIds: { value: [] }, selectedCtripHotelId: { value: '' },
    ctripConfigForm: { value: { hotel_id: '90001' } }, showToast: () => {},
    request: async () => ({ code: 200, data: [{ id: 'synthetic-config', hotel_id: 90001, hotel_room_count: 37 }] }),
  };
  vm.createContext(context);
  vm.runInContext(readFileSync('public/ctrip-static-loader.js', 'utf8'), context);
  context.hydrateCtripConfigFormForHotel = hotelId => { context.ctripConfigForm.value = context.window.SUXI_CTRIP_STATIC.buildCtripConfigFormForHotel({ hotelId, configs: context.ctripConfigList.value }); };
  context.window.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSET = async asset => {
    assert.equal(asset, 'app-deferred-helpers.min.js');
    if (failLoader) throw new Error('synthetic deferred load failure');
    await moduleReady;
    vm.runInContext(ctripStaticSource, context);
  };
  const loader = sliceBetween(appMain, 'const loadCtripConfigList = async (options = {}) => {', '\n\n            const ctripManualFetchConfigProofPending');
  vm.runInContext(`let ctripConfigListLoadingPromise = null, ctripConfigListLoadingPriorityRank = null, ctripConfigListLoadedAt = 0;
    const ctripConfigListLoading = ref(false), ctripConfigListLoaded = ref(false), ctripConfigListLoadFailed = ref(false);
    ${loader}
    this.api = { loadCtripConfigList, ctripConfigListLoading, ctripConfigListLoaded, ctripConfigListLoadFailed };`, context);
  return { context, api: context.api, errors, release: resolveModule };
}

test('fast config response waits for the deferred Ctrip module before first hydration', async () => {
  const h = startupHydrationHarness();
  const pending = h.api.loadCtripConfigList();
  await Promise.resolve(); await Promise.resolve();
  assert.equal(h.api.ctripConfigListLoading.value, true);
  assert.equal(h.api.ctripConfigListLoaded.value, false);
  assert.equal(h.context.ctripConfigList.value.length, 0);
  h.release(); await pending;
  assert.equal(h.api.ctripConfigListLoadFailed.value, false);
  assert.equal(h.context.ctripConfigForm.value.hotel_room_count, 37);
  assert.deepEqual(h.errors, []);
});

test('deferred hydration fails visibly and discards a response after session change', async () => {
  const failed = startupHydrationHarness({ failLoader: true });
  await failed.api.loadCtripConfigList();
  assert.equal(failed.api.ctripConfigListLoadFailed.value, true);
  assert.equal(failed.api.ctripConfigListLoaded.value, false);
  const stale = startupHydrationHarness();
  const pending = stale.api.loadCtripConfigList();
  await Promise.resolve(); await Promise.resolve();
  stale.context.current = false;
  stale.release(); await pending;
  assert.equal(stale.context.ctripConfigList.value.length, 0);
  assert.equal(stale.api.ctripConfigListLoaded.value, false);
  assert.deepEqual(stale.errors, []);
});

test('Ctrip form reuses stable fields for the selected hotel and keeps secrets blank', () => {
  const form = ctripStatic.buildCtripConfigFormForHotel({
    hotelId: '58',
    hotelName: '测试酒店',
    configs: [
      {
        config_id: 'ctrip-58',
        hotel_id: 58,
        name: '已保存携程配置',
        ctrip_hotel_id: 'ctrip-hotel-58',
        hotel_room_count: 37,
        competitor_room_count: 200,
        has_cookies: true,
        credential_status: 'ready',
        config_status: 'active',
      },
      {
        config_id: 'ctrip-59',
        hotel_id: 59,
        ctrip_hotel_id: 'ctrip-hotel-59',
        hotel_room_count: 99,
        competitor_room_count: 999,
        has_cookies: true,
        credential_status: 'ready',
        config_status: 'active',
      },
    ],
  });

  assert.equal(form.id, 'ctrip-58');
  assert.equal(form.hotel_id, '58');
  assert.equal(form.ctrip_hotel_id, 'ctrip-hotel-58');
  assert.equal(form.hotel_room_count, 37);
  assert.equal(form.competitor_room_count, 200);
  assert.equal(form.has_cookies, true);
  assert.equal(form.cookies, '');
});

test('hotel-management Ctrip entry loads persisted configuration and fails closed on read errors', () => {
  const openManualConfig = sliceBetween(
    appMain,
    'const openHotelManualFetchConfig = async',
    'const buildHotelPlatformLoginItem =',
  );
  assert.match(openManualConfig, /await loadCtripConfigList\(\{ force: true, applySelectedConfig: false \}\);/);
  assert.match(openManualConfig, /if \(ctripConfigListLoadFailed\.value\)/);
  assert.match(openManualConfig, /hydrateCtripConfigFormForHotel\(hotelId\);/);
  assert.doesNotMatch(openManualConfig, /ctripConfigForm\.value\s*=\s*createCtripConfigForm/);
});

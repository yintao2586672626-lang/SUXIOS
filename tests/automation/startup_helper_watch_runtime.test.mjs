import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const readPublic = name => fs.readFileSync(new URL(`../../public/${name}`, import.meta.url), 'utf8');
const appMain = readPublic('app-main.js');

function extract(start, end) {
  const startIndex = appMain.indexOf(start);
  const endIndex = appMain.indexOf(end, startIndex);
  assert.ok(startIndex >= 0 && endIndex > startIndex, `startup source must remain extractable: ${start}`);
  return appMain.slice(startIndex, endIndex);
}

function createStartupWatchHarness() {
  const hydrationCalls = [];
  const helperCalls = [];
  const context = { window: {}, console, setTimeout, clearTimeout };
  vm.createContext(context);
  vm.runInContext(readPublic('vue.runtime.global.prod.js'), context, { filename: 'vue-runtime.js' });
  vm.runInContext(readPublic('system-static.js'), context, { filename: 'system-static.js' });
  context.document = { documentElement: { dataset: {} } };
  const { ref, computed, watch } = context.Vue;
  Object.assign(context, {
    ref, computed, watch,
    dataHealthStaticVersion: ref(0),
    collectionHealthHistoryReplay: ref([]),
    collectionReliability: ref({}),
    collectionHealthCtripLatestModules: ref([]),
    collectionHealthCtripOverviewAuthState: ref({}),
    collectionHealthCtripIdentityBlocked: ref(false),
    collectionHealthCtripIdentityMessage: ref(''),
    getCtripOverviewTargetHotelId: () => '',
    ctripBusinessSummary: ref({}),
    ctripLatestComparison: ref({}),
    dualOtaComparisonMetrics: () => ({}),
    dualOtaObservedNumber: value => value == null ? null : Number(value),
    dualOtaEffectiveStoreScope: ref('ctrip'),
    dualOtaCurrentScopeGroup: () => ({ title: 'synthetic current scope' }),
    dualOtaCtripTopMetrics: () => [],
    dualOtaMissingCtripMetric: (label, source) => ({ label, value: '未返回', source }),
    dualOtaMetric: (label, value, source) => ({ label, value, source }),
    scheduleDualOtaSystemMetricDrilldownHydration: delay => hydrationCalls.push(delay),
    requireDataHealthStatic: key => {
      const resolve = context.window.SUXI_SYSTEM_STATIC.requireDeferredStaticFunction(
        'SUXI_DATA_HEALTH_STATIC', key, '缺少数据健康静态展示工具项',
      );
      return (...args) => {
        helperCalls.push(key);
        return resolve(...args);
      };
    },
  });
  vm.runInContext([
    extract('const DATA_HEALTH_STATIC_CONTRACT_VERSION =', '    const normalizeSuxiDomAttributeText ='),
    "const buildCollectionHealthCtripPersistedRows = requireDataHealthStatic('buildCollectionHealthCtripPersistedRows');",
    "const collectionHealthCtripMetricValueFromStatic = requireDataHealthStatic('collectionHealthCtripMetricValue');",
    extract('const collectionHealthCtripPersistedRows = computed', '            const collectionHealthCtripPersistedRowCount'),
    extract('const collectionHealthCtripRuntimeContext =', '            const collectionHealthCtripModuleStats'),
    extract('const collectionHealthCtripMetricValue =', '            const collectionHealthCtripOverviewContext'),
    extract('const dualOtaCtripAverageMetrics =', '            const dualOtaCtripTopRow'),
    extract('const dualOtaSystemOverviewGroups =', '            const dualOtaMetricUnitText'),
  ].join('\n'), context, { filename: 'current-source-startup-computed.js' });
  const watcherStart = appMain.lastIndexOf(
    '            watch(', appMain.indexOf('scheduleDualOtaSystemMetricDrilldownHydration(40);'),
  );
  const watcherEnd = appMain.indexOf('            const dualOtaTrustClass', watcherStart);
  assert.ok(watcherStart >= 0 && watcherEnd > watcherStart);
  return {
    context, helperCalls, hydrationCalls,
    registerWatch() {
      vm.runInContext(appMain.slice(watcherStart, watcherEnd), context, { filename: 'current-source-startup-watch.js' });
    },
    loadDataHealth() {
      vm.runInContext(readPublic('data-health-static.js'), context, { filename: 'data-health-static.js' });
    },
    finishDeferredAssets() {
      context.window.SUXI_APP_RENDER = () => null;
      context.window.SUXI_MEITUAN_STATIC = Object.fromEntries([
        'createMeituanRankingForm', 'createMeituanTrafficForm', 'createMeituanOrderForm',
        'createMeituanAdsForm', 'createMeituanBrowserCaptureForm', 'getMeituanOrderFlowPeriods',
      ].map(key => [key, () => ({})]));
      context.window.SUXI_REVIEW_MATCH_STATIC = { createCtripReviewMatchController: () => ({}) };
      context.window.SUXI_MEITUAN_FUTURE_FLOW = {};
      context.document.documentElement.dataset.suxiFullRenderReady = '1';
    },
    async publishReadiness() {
      context.dataHealthStaticVersion.value += 1;
      await context.Vue.nextTick();
    },
  };
}

test('startup metric watcher waits for the full deferred runtime before reading Ctrip history', async () => {
  const harness = createStartupWatchHarness();
  assert.doesNotThrow(() => harness.registerWatch());
  assert.deepEqual(harness.helperCalls, []);
  assert.deepEqual(harness.hydrationCalls, []);

  harness.loadDataHealth();
  await harness.publishReadiness();
  assert.deepEqual(harness.helperCalls, [], 'data-health alone must not bypass other full-render dependencies');
  assert.deepEqual(harness.hydrationCalls, []);

  harness.finishDeferredAssets();
  await harness.publishReadiness();
  assert.ok(harness.helperCalls.includes('buildCollectionHealthCtripPersistedRows'));
  assert.deepEqual(harness.hydrationCalls, [40]);

  harness.context.collectionHealthHistoryReplay.value = [{
    id: 1, source: 'ctrip', data_date: '2026-10-01', updated_at: '2026-10-01 01:00:00',
  }];
  await harness.context.Vue.nextTick();
  assert.deepEqual(harness.hydrationCalls, [40, 40], 'the ready watcher must keep observing later history updates');
});

test('a missing full-render data-health function leaves the metric watcher waiting and supports recovery', async () => {
  const harness = createStartupWatchHarness();
  assert.doesNotThrow(() => harness.registerWatch());
  harness.loadDataHealth();
  const fullDataHealth = harness.context.window.SUXI_DATA_HEALTH_STATIC;
  const incompleteDataHealth = { ...fullDataHealth };
  delete incompleteDataHealth.buildCollectionHealthCtripPersistedRows;
  harness.context.window.SUXI_DATA_HEALTH_STATIC = incompleteDataHealth;
  harness.finishDeferredAssets();
  await harness.publishReadiness();
  assert.deepEqual(harness.helperCalls, []);
  assert.deepEqual(harness.hydrationCalls, []);

  harness.context.window.SUXI_DATA_HEALTH_STATIC = fullDataHealth;
  await harness.publishReadiness();
  assert.deepEqual(harness.hydrationCalls, [40]);
});

test('the Ctrip startup facade forwards ranking preservation after the deferred full module arrives', () => {
  const context = { window: {}, console, URL, URLSearchParams, Intl, Date, setTimeout, clearTimeout };
  vm.createContext(context);
  vm.runInContext(readPublic('ctrip-static-loader.js'), context, { filename: 'ctrip-static-loader.js' });
  const facade = context.window.SUXI_CTRIP_STATIC;
  assert.equal(typeof facade.canPreserveCtripRankingSnapshot, 'function');
  assert.throws(() => facade.canPreserveCtripRankingSnapshot(), /携程完整静态能力尚未加载/);

  vm.runInContext(readPublic('ctrip-static.js'), context, { filename: 'ctrip-static.js' });
  assert.equal(facade.canPreserveCtripRankingSnapshot({ selectedHotelId: 'synthetic-hotel' }), false);
  assert.deepEqual(Object.keys(facade).sort(), Object.keys(context.window.SUXI_CTRIP_STATIC_FULL).sort());
});

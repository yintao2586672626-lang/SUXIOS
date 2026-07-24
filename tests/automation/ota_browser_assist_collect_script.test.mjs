import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const sourceBuffer = readFileSync('public/ota-browser-assist-static.js');
const source = sourceBuffer.toString('utf8');
const appMain = readFileSync('public/app-main.js', 'utf8');

const context = {
  window: {},
  globalThis: {},
};
context.globalThis = context;
vm.createContext(context);
vm.runInContext(source, context);

const helper = context.window.SUXI_OTA_BROWSER_ASSIST_STATIC;

function createActionLoaderHarness(outcomes = ['load']) {
  const appended = [];
  const document = {
    scripts: [],
    createElement() {
      const listeners = new Map();
      return {
        dataset: {},
        src: '',
        async: false,
        getAttribute(name) {
          return name === 'src' ? this.src : null;
        },
        addEventListener(name, callback) {
          listeners.set(name, callback);
        },
        remove() {
          const index = document.scripts.indexOf(this);
          if (index >= 0) document.scripts.splice(index, 1);
        },
        dispatch(name) {
          listeners.get(name)?.();
        },
      };
    },
    body: {
      appendChild(script) {
        document.scripts.push(script);
        appended.push(script);
        const outcome = outcomes.shift() || 'load';
        queueMicrotask(() => {
          if (outcome === 'load') {
            sandbox.window.SUXI_OTA_BROWSER_ASSIST_STATIC = helper;
          }
          script.dispatch(outcome);
        });
      },
    },
  };
  const sandbox = { window: {}, document, console, queueMicrotask };
  sandbox.globalThis = sandbox;
  const loaderStart = appMain.indexOf('const OTA_BROWSER_ASSIST_STATIC_ASSET =');
  const loaderEnd = appMain.indexOf('\n            const defaultMeituanAdsUrl', loaderStart);
  assert(loaderStart >= 0 && loaderEnd > loaderStart, 'action-gated OTA helper loader must exist');
  vm.createContext(sandbox);
  vm.runInContext(
    `${appMain.slice(loaderStart, loaderEnd)}\nglobalThis.loadOtaBrowserAssistStatic = loadOtaBrowserAssistStatic;`,
    sandbox,
  );
  return { appended, load: sandbox.loadOtaBrowserAssistStatic };
}

test('OTA browser assist static helper exposes the collector script generator', () => {
  assert.equal(typeof helper, 'object');
  assert.equal(helper.CONTRACT_VERSION, 'ota_browser_assist_collection_contract.v1');
  assert.equal(helper.COLLECTION_MODE, 'browser_assist_dom');
  assert.equal(typeof helper.buildOtaBrowserAssistCollectorScript, 'function');
});

test('OTA browser assist action loader reference follows the current helper hash', () => {
  const contentHash = crypto.createHash('sha256').update(sourceBuffer).digest('hex').slice(0, 10);
  const reference = appMain.match(/ota-browser-assist-static\.js\?v=[^"']+/)?.[0] || '';

  assert.match(reference, new RegExp(`(?:^|[-_])h${contentHash}(?:[-_]|$)`));
});

test('OTA browser assist action loader reuses success and retries a failed download', async () => {
  const successHarness = createActionLoaderHarness(['load']);
  assert.equal(await successHarness.load(), helper);
  assert.equal(await successHarness.load(), helper);
  assert.equal(successHarness.appended.length, 1);

  const retryHarness = createActionLoaderHarness(['error', 'load']);
  await assert.rejects(retryHarness.load(), /加载失败/);
  assert.equal(await retryHarness.load(), helper);
  assert.equal(retryHarness.appended.length, 2);
});

test('generated collector script produces supplemental OTA contract JSON without credential access', () => {
  const script = helper.buildOtaBrowserAssistCollectorScript();

  for (const marker of [
    '// ==UserScript==',
    '@version      0.2.0',
    '@match        https://ebooking.ctrip.com/*',
    '@match        https://me.meituan.com/*',
    '@match        https://eb.meituan.com/*',
    'source_contract',
    'ota_browser_assist_collection_contract.v1',
    'collection_mode',
    'browser_assist_dom',
    'capture_scope',
    'capture_template',
    'target_region',
    'target_region_not_selected',
    'target_region_stale',
    'target_region_not_unique',
    'target_region_empty',
    'page_scope_broad',
    'containsSensitiveText',
    '[REDACTED]',
    'invalidateLastCapture',
    'var parseInventoryRows = function (platform, warnings, root)',
    'var parseRealtimeMetrics = function (platform, warnings, root)',
    '圈选目标区域',
    '采集圈选区域',
    '页面状态变化后必须重新圈选',
    'ctripStats',
    'meituanStats',
    'warnings',
    'selector_not_found',
    'metrics_not_found',
    'navigator.clipboard.writeText',
    'platformIdentity',
    'partnerId',
    'poiId',
    'performance.getEntriesByType',
    '实时访客量',
    '曝光人数',
    '剩余',
  ]) {
    assert.match(script, new RegExp(marker.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `missing marker: ${marker}`);
  }

  for (const forbidden of [
    /document\.cookie/i,
    /localStorage/i,
    /sessionStorage/i,
    /fetch\s*\(/i,
    /XMLHttpRequest/i,
    /navigator\.credentials/i,
    /document\.querySelector(?:All)?\([^)]*(?:password|authorization|token)/i,
    /\.(?:password|authorization|accessToken|refreshToken|authToken|token)\b/i,
    /\[['"](?:password|authorization|accessToken|refreshToken|authToken|token)['"]\]/i,
  ]) {
    assert.doesNotMatch(script, forbidden, `generated collector must not include ${forbidden}`);
  }

  assert.doesNotThrow(() => new vm.Script(script));
});

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const main = readFileSync('public/app-main.js', 'utf8');
const contractSource = readFileSync('public/revenue-overview-contract-static.js', 'utf8');
const revenueSource = readFileSync('public/revenue-ai-static.js', 'utf8');
const slice = (start, end) => {
  const first = main.indexOf(start);
  const last = main.indexOf(end, first + start.length);
  assert.ok(first >= 0 && last > first, `missing source boundary: ${start}`);
  return main.slice(first, last);
};
const dependencyLoader = slice('const ensureHomeSecondaryStaticRuntimeReady = async', 'const scheduleHomeSecondaryPanelsReady =');
const revenueLoader = slice('const loadRevenueAiStatic =', 'const requireRevenueAiStatic =');
const fallbackSource = slice('const revenueAiStaticFallbacks = Object.freeze(', 'const loadRevenueAiStatic =');
const ref = (value) => ({ value });

function harness() {
  const events = [];
  const scripts = [];
  const s = {
    window: {}, revenueAiStaticScript: 'revenue-ai-static.js', revenueAiStaticVersion: 'fixture',
    revenueAiStaticReady: ref(false), revenueAiStaticLoading: ref(false), revenueAiStaticError: ref(''),
    revenueAiStaticRevision: ref(0), revenueAiStaticNotLoadedText: '未加载', revenueAiStaticNotLoadedClass: 'not-loaded',
    URLSearchParams,
    document: {
      createElement: () => ({ dataset: {}, remove() { this.removed = true; } }),
      querySelector: () => scripts.find((script) => !script.removed && script.dataset.loaded !== '1') || null,
      head: { appendChild(script) {
        scripts.push(script);
        events.push('revenue-script');
        queueMicrotask(() => {
          try { s.evaluateRevenue(); script.onload(); } catch { script.onerror(); }
        });
      } },
    },
  };
  vm.createContext(s);
  s.evaluateRevenue = () => vm.runInContext(revenueSource, s);
  const registerDependencies = () => {
    vm.runInContext(contractSource, s);
    s.window.SUXI_DATA_HEALTH_STATIC = {};
    events.push('dependencies-ready');
  };
  s.window.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSET = async (name) => {
    assert.equal(name, 'app-deferred-helpers.min.js');
    events.push('deferred-request');
    registerDependencies();
  };
  vm.runInContext(`let revenueAiStaticLoadPromise = null;\n${fallbackSource}\n${dependencyLoader}\n${revenueLoader}\nthis.ensureReady = ensureRevenueAiStaticReady;`, s);
  return { s, events, scripts, registerDependencies };
}

test('cold Revenue AI loading waits for the actual date contract before evaluating the revenue script', async () => {
  const { s, events } = harness();
  let finishDependencies;
  const normalLoader = s.window.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSET;
  s.window.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSET = () => new Promise((resolve) => {
    finishDependencies = async () => { await normalLoader('app-deferred-helpers.min.js'); resolve(); };
  });
  const pending = s.ensureReady();
  assert.equal(s.revenueAiStaticLoading.value, true);
  assert.equal(s.window.SUXI_REVENUE_AI_STATIC, undefined);
  assert.deepEqual(events, [], 'the revenue script must not start before the deferred dependency resolves');
  await finishDependencies();
  const loaded = await pending;
  assert.deepEqual(events, ['deferred-request', 'dependencies-ready', 'revenue-script']);
  assert.equal(loaded, s.window.SUXI_REVENUE_AI_STATIC);
  assert.equal(typeof loaded.resolveRevenueAiBusinessDate, 'function');
  assert.equal(s.revenueAiStaticReady.value, true);
  assert.equal(s.revenueAiStaticLoading.value, false);
  assert.equal(s.revenueAiStaticError.value, '');
  assert.equal(s.revenueAiStaticRevision.value, 1);
  await s.ensureReady();
  assert.equal(events.length, 3, 'a ready runtime reuses its registered helpers');
});

test('a deferred dependency failure does not load Revenue AI and a subsequent call can recover', async () => {
  const { s, events, scripts } = harness();
  const workingLoader = s.window.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSET;
  s.window.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSET = async () => { throw new Error('fixture deferred failure'); };
  await assert.rejects(s.ensureReady(), /fixture deferred failure/);
  assert.equal(s.revenueAiStaticReady.value, false);
  assert.equal(s.revenueAiStaticLoading.value, false);
  assert.match(s.revenueAiStaticError.value, /fixture deferred failure/);
  assert.equal(scripts.length, 0);
  s.window.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSET = workingLoader;
  await s.ensureReady();
  assert.equal(s.revenueAiStaticReady.value, true);
  assert.equal(s.revenueAiStaticError.value, '');
  assert.deepEqual(events, ['deferred-request', 'dependencies-ready', 'revenue-script']);
});

test('a deferred asset that fails to register the contract remains an error and is retryable', async () => {
  const { s, scripts } = harness();
  const workingLoader = s.window.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSET;
  s.window.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSET = async () => { s.window.SUXI_DATA_HEALTH_STATIC = {}; };
  await assert.rejects(s.ensureReady(), /加载完成但未注册/);
  assert.equal(s.revenueAiStaticReady.value, false);
  assert.equal(scripts.length, 0);
  s.window.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSET = workingLoader;
  await s.ensureReady();
  assert.equal(s.revenueAiStaticReady.value, true);
});

test('a revenue script load failure clears its pending promise and retries using the already loaded contract', async () => {
  const { s, events, scripts } = harness();
  const evaluate = s.evaluateRevenue;
  s.evaluateRevenue = () => { throw new Error('fixture script failure'); };
  await assert.rejects(s.ensureReady(), /展示工具加载失败/);
  assert.equal(s.revenueAiStaticReady.value, false);
  assert.equal(s.revenueAiStaticLoading.value, false);
  assert.equal(scripts[0].removed, true);
  s.evaluateRevenue = evaluate;
  await s.ensureReady();
  assert.equal(s.revenueAiStaticReady.value, true);
  assert.equal(s.revenueAiStaticError.value, '');
  assert.deepEqual(events, ['deferred-request', 'dependencies-ready', 'revenue-script', 'revenue-script']);
});

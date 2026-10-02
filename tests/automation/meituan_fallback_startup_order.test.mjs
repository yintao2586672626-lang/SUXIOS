import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const main = fs.readFileSync('public/app-main.js', 'utf8');
const binding = main.match(/const meituanStaticFallbackFor = [^\n]+;/)?.[0];
assert.ok(binding, 'Use the actual application fallback binding');
const context = { window: {}, console: { warn() {} }, URLSearchParams };
vm.runInNewContext(fs.readFileSync('public/system-static.js', 'utf8'), context);

test('initial Meituan fallbacks work before the later toast binding initializes', () => {
  const result = vm.runInNewContext(`(() => {
    const appSystemStatic = window.SUXI_SYSTEM_STATIC;
    const meituanDeferredRuntimePending = () => true;
    const meituanStaticUnavailableResult = key => ({ status: 'static_helper_missing', missing_helper: key });
    ${binding}
    const url = meituanStaticFallbackFor('defaultMeituanAdsUrl')();
    const pending = meituanStaticFallbackFor('missing-probe')();
    const showToast = () => { throw Error('startup must not need the toast'); };
    return { url, pending };
  })()`, context);
  assert.match(result.url, /^https:\/\//);
  assert.equal(result.pending.status, 'static_helper_missing');
  assert.equal(result.pending.missing_helper, 'missing-probe');
});

test('missing helper still displays a warning after full initialization', () => {
  const result = vm.runInNewContext(`(() => {
    const appSystemStatic = window.SUXI_SYSTEM_STATIC;
    const meituanDeferredRuntimePending = () => false;
    const meituanStaticUnavailableResult = key => ({ status: 'static_helper_missing', message: key });
    ${binding}
    const fallback = meituanStaticFallbackFor('missing-probe');
    const calls = [];
    const showToast = (...args) => calls.push(args);
    const state = fallback();
    return { state, calls };
  })()`, context);
  assert.equal(result.state.status, 'static_helper_missing');
  assert.deepEqual(JSON.parse(JSON.stringify(result.calls)), [['missing-probe', 'warning']]);
});

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
const source = readFileSync('public/app-main.js', 'utf8');
const from = source.indexOf('const ctripProfileFieldConfigPanelReady = ref(false);');
const to = source.indexOf('const ctripProfileFieldRecheckState = ref(', from);
assert.ok(from >= 0 && to > from);
function harness(loader, resolveComponent = () => ({ render() {} })) {
  let calls = 0;
  const box = vm.createContext({
    ref: value => ({ value }), shallowRef: value => ({ value }), markRaw: value => value,
    ctripProfileFieldConfigPanelScript: 'fixture-local-panel.js',
    loadOnlineDataComponentScript: (...args) => { calls++; return loader(...args); },
    requireOnlineDataComponent: resolveComponent,
  });
  vm.runInContext(`${source.slice(from, to)};this.panel={load:ensureCtripProfileFieldConfigPanelReady,retry:retryCtripProfileFieldConfigPanel,ready:ctripProfileFieldConfigPanelReady,body:ctripProfileFieldConfigPanelBody,error:ctripProfileFieldConfigPanelError};`, box);
  return { ...box.panel, calls: () => calls };
}

test('failed script load shows a bounded message and retry recovers without duplicating a ready load', async () => {
  let failing = true;
  const h = harness(() => failing ? Promise.reject(new Error('fixture raw URL must stay out of UI')) : Promise.resolve());
  await assert.rejects(h.load());
  assert.equal(h.ready.value, false);
  assert.equal(h.error.value, '字段配置界面加载失败，请重试。');
  failing = false;
  await h.retry();
  assert.equal(h.ready.value, true);
  assert.equal(h.error.value, '');
  assert.equal(typeof h.body.value.render, 'function');
  await h.retry();
  assert.equal(h.calls(), 2);
});

test('concurrent attempts share the same loader request and failed registration is retryable', async () => {
  let release;
  const h = harness(() => new Promise(resolve => { release = resolve; }));
  const first = h.load(); const second = h.retry();
  assert.equal(h.calls(), 1);
  release(); await Promise.all([first, second]);
  assert.equal(h.ready.value, true);
  let malformed = true;
  const registration = harness(() => Promise.resolve(), () => { if (malformed) throw new Error('missing fixture registration'); return { render() {} }; });
  await assert.rejects(registration.load());
  assert.equal(registration.error.value, '字段配置界面加载失败，请重试。');
  malformed = false;
  await registration.retry();
  assert.equal(registration.ready.value, true);
});

test('wrapper displays failed state and its retry button calls only the static-component retry', () => {
  const box = vm.createContext({ window: {}, console });
  vm.runInContext(readFileSync('public/vue.runtime.global.prod.js', 'utf8'), box);
  vm.runInContext(readFileSync('public/components/system/app-main-components.js', 'utf8'), box);
  const wrapper = box.window.SUXI_APP_MAIN_COMPONENTS_FULL.create({ Vue: box.Vue, h: box.Vue.h }).CtripProfileFieldConfigPanel;
  let calls = 0;
  const ctx = { ctripProfileFieldConfigPanelReady: false, ctripProfileFieldConfigPanelError: '字段配置界面加载失败，请重试。', retryCtripProfileFieldConfigPanel: () => { calls++; } };
  const vnode = wrapper.render.call({ ctx });
  assert.equal(vnode.props.role, 'alert');
  assert.equal(vnode.props['data-testid'], 'ctrip-profile-field-config-error');
  assert.equal(vnode.children[1].children, '重试加载字段配置');
  vnode.children[1].props.onClick();
  assert.equal(calls, 1);
});

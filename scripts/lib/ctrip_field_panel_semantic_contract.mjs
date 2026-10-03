import assert from 'node:assert/strict';
import { types } from 'node:util';
import vm from 'node:vm';
import { buildFrontendAssetHash, readFrontendAssetVersion } from './frontend_asset_version.mjs';

const asset = 'components/online-data/ctrip-profile-field-config-panel.js';
const bodyFile = `public/${asset}`;
const wrapperFile = 'public/components/system/app-main-components.js';
const bodyKey = 'CtripProfileFieldConfigPanelBody';

export function createCtripFieldPanelSemanticContract(readRaw) {
  let runtime;
  const load = () => {
    if (runtime) return runtime;
    const existing = { name: 'SyntheticUnrelatedComponent' };
    const sandbox = vm.createContext({ window: { SUXI_ONLINE_DATA_COMPONENTS: { SyntheticUnrelatedComponent: existing } }, console });
    vm.runInContext(readRaw('public/vue.runtime.global.prod.js'), sandbox, { filename: 'vue.runtime.global.prod.js' });
    vm.runInContext(readRaw(wrapperFile), sandbox, { filename: wrapperFile });
    vm.runInContext(readRaw(bodyFile), sandbox, { filename: bodyFile });
    const bindings = sandbox.window.SUXI_APP_MAIN_COMPONENTS_FULL.create({ Vue: sandbox.Vue, h: sandbox.Vue.h });
    runtime = { sandbox, existing, bindings, registry: sandbox.window.SUXI_ONLINE_DATA_COMPONENTS,
      body: sandbox.window.SUXI_ONLINE_DATA_COMPONENTS[bodyKey], wrapper: bindings.CtripProfileFieldConfigPanel };
    return runtime;
  };
  const checks = {
    version() {
      const { bindings } = load();
      const version = readFrontendAssetVersion(readRaw(wrapperFile), asset);
      assert.equal(version.versionPrefix, '20260613-profile-template-split');
      assert.equal(version.hash, buildFrontendAssetHash(readRaw(bodyFile)), 'lazy URL hash must match the exact compiled body bytes');
      assert.equal(bindings.ctripProfileFieldConfigPanelScript, `${asset}?v=${version.version}`);
    },
    loading() {
      const { wrapper, body } = load();
      for (const [ready, component] of [[false, null], [false, body], [true, null]]) {
        const ctx = { ctripProfileFieldConfigPanelReady: ready, ctripProfileFieldConfigPanelBody: component, ctripProfileFieldConfigPanelError: '' };
        const vnode = wrapper.render.call({ ctx });
        assert.equal(vnode.type, 'div');
        assert.equal(vnode.props['data-testid'], 'ctrip-profile-field-config-loading');
        assert.equal(vnode.children, '加载中...');
      }
      const ctx = { ctripProfileFieldConfigPanelReady: true, ctripProfileFieldConfigPanelBody: body, ctripProfileFieldConfigPanelError: '' };
      const vnode = wrapper.render.call({ ctx });
      assert.equal(vnode.type, body, 'readiness must render the exact registered lazy body');
      assert.equal(vnode.props.ctx, ctx, 'the wrapper passes its existing root context by identity');
    },
    registration() {
      const { registry, body, existing } = load();
      assert(Object.hasOwn(registry, bodyKey));
      assert.equal(body.name, bodyKey);
      assert.equal(typeof body.setup, 'function');
      assert.equal(typeof body.render, 'function');
      assert.equal(registry.SyntheticUnrelatedComponent, existing, 'lazy registration must preserve unrelated component keys');
    },
    template() {
      const { body, sandbox } = load();
      const ctx = { onlineDataTab: 'profile-fields', user: { is_super_admin: true },
        ctripProfileFieldSummary: {}, ctripProfileFields: [], filteredCtripProfileFields: [],
        ctripProfileFieldRecheckState: { active: false, message: '' }, ctripProfileFieldFilters: {},
        ctripProfileFieldSectionOptions: [], ctripProfileFieldAssetLedgerCards: [], ctripProfileForbiddenFieldAssets: [],
        showCtripProfileModuleManager: false, showCtripProfileFieldForm: false,
        selectedCtripProfileSampleField: null, ctripProfileFieldLoading: false };
      const proxy = body.setup({ ctx });
      const vnode = body.render(proxy, []);
      assert.equal(vnode.type, 'div');
      assert.equal(vnode.props['data-testid'], 'ctrip-profile-field-config-panel');
      ctx.user.is_super_admin = false;
      assert.equal(body.render(proxy, []).type, sandbox.Vue.Comment, 'ordinary users cannot render the admin body');
      ctx.user.is_super_admin = true;
      ctx.onlineDataTab = 'data';
      assert.equal(body.render(proxy, []).type, sandbox.Vue.Comment, 'other tabs cannot render the heavy admin body');
    },
    proxy() {
      const { body } = load();
      assert(types.isProxy(body.setup({ ctx: {} })), 'setup must return an actual Proxy bridge');
    },
    read() {
      const { body } = load();
      const ctx = { text: 'synthetic-root', zero: 0, disabled: false };
      const proxy = body.setup({ ctx });
      assert.equal(proxy.ctx, ctx);
      for (const key of Object.keys(ctx)) assert.equal(proxy[key], ctx[key], `root binding ${key} must retain its exact value`);
      const props = { ctx: null };
      const fallback = body.setup(props);
      fallback.local = 'synthetic-target';
      props.ctx = { local: null };
      assert.equal(fallback.local, 'synthetic-target', 'nullish root values preserve the target fallback');
    },
    write() {
      const { body } = load();
      const ctx = { selected: 'old' };
      const proxy = body.setup({ ctx });
      for (const value of ['synthetic-edit', 0, false, null]) {
        assert.equal(Reflect.set(proxy, 'selected', value), true);
        assert.equal(ctx.selected, value, 'v-model edits write the exact value to the passed root context');
      }
      proxy.added = 'new-binding';
      assert.equal(ctx.added, 'new-binding');
    },
    descriptor() {
      const { body, sandbox } = load();
      const ctx = { visibleBinding: 'synthetic-lookup' };
      const proxy = body.setup({ ctx });
      const descriptor = Object.getOwnPropertyDescriptor(proxy, 'visibleBinding');
      assert(descriptor, 'root keys must expose own-property descriptors to setup-state lookup');
      assert.equal(descriptor.enumerable, true);
      assert.equal(descriptor.configurable, true);
      assert.equal(Reflect.has(proxy, 'visibleBinding'), true);
      assert.equal(sandbox.Vue.proxyRefs(proxy).visibleBinding, ctx.visibleBinding, 'Vue setup-state lookup resolves the root binding');
    },
  };
  return (id, label) => {
    try {
      assert(Object.hasOwn(checks, id), `unknown field-panel semantic contract: ${id}`);
      checks[id]();
      return { file: `${wrapperFile} + ${bodyFile} (VM semantics)`, label, ok: true, detail: id };
    } catch (error) {
      return { file: `${wrapperFile} + ${bodyFile} (VM semantics)`, label, ok: false, detail: `${id}: ${error.message}` };
    }
  };
}

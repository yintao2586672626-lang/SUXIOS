import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { buildCtripProfileFieldConfigComponent } from '../../scripts/lib/frontend_template_build.mjs';

function runtime() {
  const sandbox = vm.createContext({ window: {}, console });
  vm.runInContext(readFileSync('public/vue.runtime.global.prod.js', 'utf8'), sandbox);
  return sandbox;
}

test('compiled field panel uses runtime-only Vue and preserves false, zero and editable context', async () => {
  const sandbox = runtime();
  const artifact = await buildCtripProfileFieldConfigComponent('<div v-if="user?.is_super_admin" data-testid="fixture-panel">{{ count }} {{ enabled }} <button @click="increment">增加</button></div>');
  vm.runInContext(artifact, sandbox);
  const component = sandbox.window.SUXI_ONLINE_DATA_COMPONENTS.CtripProfileFieldConfigPanelBody;
  assert.equal(typeof component.render, 'function');
  assert.equal(component.template, undefined);
  const ctx = { user: { is_super_admin: true }, count: 0, enabled: false, increment() { this.count++; } };
  const proxy = component.setup({ ctx });
  assert.equal(proxy.count, 0);
  assert.equal(proxy.enabled, false);
  const vnode = component.render(proxy, []);
  assert.equal(vnode.props['data-testid'], 'fixture-panel');
  proxy.count = 7;
  assert.equal(ctx.count, 7);
  assert.equal(proxy.ctx, ctx);
});

test('actual field template compiles and excludes the admin panel for ordinary users', async () => {
  const sandbox = runtime();
  vm.runInContext(await buildCtripProfileFieldConfigComponent(readFileSync('resources/frontend/templates/components/ctrip-profile-field-config-panel.html', 'utf8')), sandbox);
  const component = sandbox.window.SUXI_ONLINE_DATA_COMPONENTS.CtripProfileFieldConfigPanelBody;
  const vnode = component.render(component.setup({ ctx: { onlineDataTab: 'profile-fields', user: { is_super_admin: false } } }), []);
  assert.equal(vnode.type, sandbox.Vue.Comment);
});

test('field wrapper renders a loading placeholder before readiness and the compiled body after readiness', () => {
  const sandbox = runtime();
  vm.runInContext(readFileSync('public/components/system/app-main-components.js', 'utf8'), sandbox);
  const wrapper = sandbox.window.SUXI_APP_MAIN_COMPONENTS_FULL.create({ Vue: sandbox.Vue, h: sandbox.Vue.h }).CtripProfileFieldConfigPanel;
  const ctx = { ctripProfileFieldConfigPanelReady: false, ctripProfileFieldConfigPanelBody: null };
  assert.equal(wrapper.template, undefined);
  assert.equal(wrapper.render.call({ ctx }).props['data-testid'], 'ctrip-profile-field-config-loading');
  const body = { render() {} };
  ctx.ctripProfileFieldConfigPanelBody = body;
  ctx.ctripProfileFieldConfigPanelReady = true;
  const vnode = wrapper.render.call({ ctx });
  assert.equal(vnode.type, body);
  assert.equal(vnode.props.ctx, ctx);
});

import vm from 'node:vm';

function compiledPanelContextIsValid(component) {
  try {
    return vm.runInNewContext(`${component}\n(() => {
      const panel = window.SUXI_ONLINE_DATA_COMPONENTS?.CtripProfileFieldConfigPanelBody;
      if (!panel || typeof panel.render !== 'function' || panel.template !== undefined) return false;
      const ctx = { count: 0, enabled: false, onlineDataTab: 'profile-fields', user: { is_super_admin: false } };
      const proxy = panel.setup({ ctx });
      if (proxy.ctx !== ctx || proxy.count !== 0 || proxy.enabled !== false) return false;
      proxy.count = 7;
      proxy.enabled = true;
      if (ctx.count !== 7 || ctx.enabled !== true || !('count' in proxy)) return false;
      const descriptor = Object.getOwnPropertyDescriptor(proxy, 'count');
      if (descriptor?.enumerable !== true || descriptor?.configurable !== true) return false;
      if (panel.render(proxy, []).type !== 'contract-comment') return false;
      ctx.user.is_super_admin = true;
      ctx.onlineDataTab = 'other-tab';
      return panel.render(proxy, []).type === 'contract-comment';
    })()`, { window: {}, Vue: { createCommentVNode: () => ({ type: 'contract-comment' }) } }, { timeout: 1_000 }) === true;
  } catch {
    return false;
  }
}

export function inspectCtripProfileFieldConfigPanel({ entry, component, template }) {
  return entry.includes('components/online-data/ctrip-profile-field-config-panel.js?v=20260613-profile-template-split')
    && entry.includes('const CtripProfileFieldConfigPanel = {')
    && entry.includes('const ensureCtripProfileFieldConfigPanelReady = async () => {')
    && entry.includes("requireOnlineDataComponent('CtripProfileFieldConfigPanelBody')")
    && entry.includes('void ensureCtripProfileFieldConfigPanelReady().catch')
    && entry.includes('<ctrip-profile-field-config-panel')
    && entry.includes("'data-testid': 'ctrip-profile-field-config-loading'")
    && component.includes('CtripProfileFieldConfigPanelBody')
    && template.includes('data-testid="ctrip-profile-field-config-panel"')
    && component.includes('render:')
    && component.includes('"data-testid":"ctrip-profile-field-config-panel"')
    && component.includes('new Proxy')
    && component.includes('getOwnPropertyDescriptor')
    && !entry.includes('携程登录会话字段配置')
    && compiledPanelContextIsValid(component);
}

import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

import { inspectPublicEntryRuntimeContracts } from '../../scripts/lib/public_entry_ast_contract.mjs';

const validBootstrap = `
  const loadDeferredAuthenticatedAssets = async (assets = []) => {
    await Promise.all(assets);
    window.dispatchEvent(
      new CustomEvent('suxi:full-render-ready', { detail: { assets } }),
    );
  };
`;

const validAppMain = `
  const suxiActiveRender = { value: null };
  const suxiRenderCaches = new WeakMap();
  const suxiRootComponent = {
    render(...args) {
      const activeRender = suxiActiveRender.value;
      let cache = suxiRenderCaches.get(activeRender);
      if (!cache) { cache = []; suxiRenderCaches.set(activeRender, cache); }
      const renderArgs = [...args];
      renderArgs[1] = cache;
      return activeRender.apply(this, renderArgs);
    },
  };
  let suxiApp = null;
  let pendingFullRenderPage = '';
  let recoverSuxiRuntimeError = null;
  let requestSuxiFullRenderForPage = () => false;
  const currentPage = { value: 'other' };
  const renderSuxiStartupError = () => {};
  const scheduleSuxiStartupError = (error) => window.setTimeout(() => {
    const appToUnmount = suxiApp;
    suxiApp = null;
    try {
      appToUnmount?.unmount();
    } catch (unmountError) {
      console.error(unmountError);
    }
    renderSuxiStartupError(error);
  }, 0);
  const configureSuxiApp = (app) => {
    app.config.errorHandler = (error, instance, info) => {
      const recovered = (() => recoverSuxiRuntimeError({ error, info }))();
      if (recovered) return;
      scheduleSuxiStartupError(error);
    };
    return app;
  };
  const mountSuxiApp = () => {
    suxiApp = configureSuxiApp(createApp(suxiRootComponent));
    suxiApp.mount('#app');
  };
  const promoteSuxiFullRender = () => {
    const targetPage = pendingFullRenderPage;
    const fullRender = window.SUXI_APP_RENDER;
    window.SUXI_INITIAL_PAGE_OVERRIDE = targetPage;
    suxiApp?.unmount();
    suxiActiveRender.value = fullRender;
    mountSuxiApp();
  };
  requestSuxiFullRenderForPage = (page) => {
    pendingFullRenderPage = page;
    window.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSETS();
    Promise.resolve().then(promoteSuxiFullRender);
    return true;
  };
  recoverSuxiRuntimeError = ({ info }) => {
    const isFatalStartupError = /setup function|app errorHandler|app warnHandler|app unmount cleanup function/i.test(info);
    if (isFatalStartupError) return false;
    currentPage.value = 'compass';
    showToast('safe page', 'error');
    return true;
  };
  const handleSuxiFullRenderReady = () => requestSuxiFullRenderForPage(pendingFullRenderPage);
  window.addEventListener(
    'suxi:full-render-ready',
    handleSuxiFullRenderReady,
    { once: true },
  );
`;

test('AST contract accepts equivalent formatting without exact source strings', () => {
  assert.deepEqual(inspectPublicEntryRuntimeContracts({
    appBootstrapSource: validBootstrap,
    appMainSource: validAppMain,
  }).failures, []);
});

test('comments, strings, and statically unreachable markers cannot satisfy the contract', () => {
  const deadBootstrap = `
    const loadDeferredAuthenticatedAssets = () => {
      const marker = "window.dispatchEvent(new CustomEvent('suxi:full-render-ready'))";
      // window.dispatchEvent(new CustomEvent('suxi:full-render-ready'));
      if (false) window.dispatchEvent(new CustomEvent('suxi:full-render-ready'));
      return marker;
    };
  `;
  const result = inspectPublicEntryRuntimeContracts({
    appBootstrapSource: deadBootstrap,
    appMainSource: validAppMain,
  });
  assert.match(result.failures.join('\n'), /executable deferred authenticated asset loader/i);
});

test('fatal recovery cannot return from unmount cleanup before rendering the visible error', () => {
  const unsafeFatalMain = validAppMain.replace(
    'console.error(unmountError);',
    'console.error(unmountError); return;',
  );
  const result = inspectPublicEntryRuntimeContracts({
    appBootstrapSource: validBootstrap,
    appMainSource: unsafeFatalMain,
  });
  assert.match(result.failures.join('\n'), /safe unmount attempt/i);
});

test('current public entry sources satisfy the executable AST contract', () => {
  const result = inspectPublicEntryRuntimeContracts({
    appBootstrapSource: fs.readFileSync('public/app-bootstrap.js', 'utf8'),
    appMainSource: fs.readFileSync('public/app-main.js', 'utf8'),
  });
  assert.deepEqual(result.failures, []);
});

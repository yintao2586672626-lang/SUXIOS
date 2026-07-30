import assert from 'node:assert/strict';
import test from 'node:test';
import { bindLocalBrowserSandbox } from '../../scripts/bind_local_browser_sandbox.mjs';
import {
  browserProcessProfileMarkerUrl,
  browserSandboxMarkerUrl,
  normalizeBrowserSandboxId,
  platformContextCandidates,
  resolveBrowserSandboxContext,
} from '../../scripts/lib/browser_sandbox.mjs';

const sandboxId = 'sbx_dingdandao_h80_primary';

test('stable sandbox IDs resolve only an explicit isolated BrowserContext marker', () => {
  assert.equal(normalizeBrowserSandboxId(sandboxId), sandboxId);
  const marker = browserSandboxMarkerUrl(sandboxId);
  const resolved = resolveBrowserSandboxContext({
    sandboxId,
    browserContextIds: ['ctx_h80'],
    targetInfos: [{
      type: 'page',
      targetId: 'marker',
      browserContextId: 'ctx_h80',
      url: marker,
    }],
  });
  assert.deepEqual(resolved, {
    sandboxId,
    browserContextId: 'ctx_h80',
    contextKey: 'ctx_h80',
    isolation: 'browser_context',
  });
  assert.throws(() => resolveBrowserSandboxContext({
    sandboxId,
    targetInfos: [{ type: 'page', targetId: 'default', url: marker }],
  }), /browser_sandbox_not_isolated/);
  assert.throws(() => resolveBrowserSandboxContext({
    sandboxId,
    browserContextIds: ['ctx_a', 'ctx_b'],
    targetInfos: [
      { type: 'page', targetId: 'a', browserContextId: 'ctx_a', url: marker },
      { type: 'page', targetId: 'b', browserContextId: 'ctx_b', url: marker },
    ],
  }), /browser_sandbox_binding_ambiguous/);
});

test('a dedicated process Profile is accepted only with both explicit markers', () => {
  const marker = browserSandboxMarkerUrl(sandboxId);
  const processMarker = browserProcessProfileMarkerUrl(sandboxId);
  const resolved = resolveBrowserSandboxContext({
    sandboxId,
    targetInfos: [
      { type: 'page', targetId: 'sandbox', url: marker },
      { type: 'page', targetId: 'profile', url: processMarker },
    ],
  });

  assert.equal(resolved.browserContextId, null);
  assert.equal(resolved.isolation, 'process_profile');
  assert.throws(() => resolveBrowserSandboxContext({
    sandboxId,
    targetInfos: [{ type: 'page', targetId: 'sandbox', url: marker }],
  }), /browser_sandbox_not_isolated/);
});

test('new headless Chrome default-context IDs are process Profile isolated', () => {
  const contextId = 'headless_default_context';
  const resolved = resolveBrowserSandboxContext({
    sandboxId,
    browserContextIds: [],
    targetInfos: [
      {
        type: 'page',
        targetId: 'sandbox',
        browserContextId: contextId,
        url: browserSandboxMarkerUrl(sandboxId),
      },
      {
        type: 'page',
        targetId: 'profile',
        browserContextId: contextId,
        url: browserProcessProfileMarkerUrl(sandboxId),
      },
    ],
  });

  assert.equal(resolved.browserContextId, contextId);
  assert.equal(resolved.contextKey, contextId);
  assert.equal(resolved.isolation, 'process_profile');
});

test('platform discovery never promotes a default-context tab into an isolated sandbox', () => {
  assert.deepEqual(platformContextCandidates({
    platform: 'dingdandao',
    browserContextIds: ['ctx_h80'],
    targetInfos: [
      {
        type: 'page',
        targetId: 'isolated',
        browserContextId: 'ctx_h80',
        url: 'https://www.dingdandao.com/pmsManage/report/pro/dataCenter/overview',
      },
      {
        type: 'page',
        targetId: 'default',
        url: 'https://www.dingdandao.com/',
      },
      {
        type: 'page',
        targetId: 'other',
        browserContextId: 'ctx_h80',
        url: 'https://example.com/',
      },
    ],
  }), ['ctx_h80', '']);
});

test('sandbox creation returns a stable mapping without exposing the runtime context ID', async () => {
  const targets = [];
  const contextIds = [];
  const calls = [];
  const connection = {
    async send(method, params = {}) {
      calls.push({ method, params });
      if (method === 'Target.getTargets') return { targetInfos: [...targets] };
      if (method === 'Target.getBrowserContexts') {
        return { browserContextIds: [...contextIds] };
      }
      if (method === 'Target.createBrowserContext') {
        contextIds.push('runtime_context_secret');
        return { browserContextId: 'runtime_context_secret' };
      }
      if (method === 'Target.createTarget') {
        const targetId = `target_${targets.length + 1}`;
        targets.push({
          type: 'page',
          targetId,
          browserContextId: params.browserContextId,
          url: params.url,
        });
        return { targetId };
      }
      return {};
    },
    close() {
      calls.push({ method: 'connection.close', params: {} });
    },
  };

  const result = await bindLocalBrowserSandbox({
    cdpUrl: 'http://127.0.0.1:9223',
    sandboxId,
    platform: 'dingdandao',
    mode: 'create',
  }, {
    connect: async () => connection,
  });

  assert.equal(result.status, 'awaiting_login');
  assert.equal(result.sandbox_id, sandboxId);
  assert.equal(result.isolation, 'browser_context');
  assert.equal(result.browser_context_exposed, false);
  assert.equal(result.session_material_exposed, false);
  assert.equal(JSON.stringify(result).includes('runtime_context_secret'), false);
  assert.equal(
    calls.filter((call) => call.method === 'Target.createTarget').length,
    2,
  );
});

test('process Profile binding uses the persistent default context without exposing it', async () => {
  const targets = [];
  const calls = [];
  const connection = {
    async send(method, params = {}) {
      calls.push({ method, params });
      if (method === 'Target.getTargets') return { targetInfos: [...targets] };
      if (method === 'Target.getBrowserContexts') return { browserContextIds: [] };
      if (method === 'Target.createTarget') {
        const targetId = `target_${targets.length + 1}`;
        targets.push({
          type: 'page',
          targetId,
          url: params.url,
        });
        return { targetId };
      }
      return {};
    },
    close() {},
  };

  const result = await bindLocalBrowserSandbox({
    cdpUrl: 'http://127.0.0.1:9223',
    sandboxId,
    platform: 'dingdandao',
    mode: 'bind-process-profile',
  }, {
    connect: async () => connection,
  });

  assert.equal(result.status, 'awaiting_login');
  assert.equal(result.isolation, 'process_profile');
  assert.equal(
    calls.filter((call) => call.method === 'Target.createBrowserContext').length,
    0,
  );
  assert.equal(
    calls.filter((call) => call.method === 'Target.createTarget').length,
    3,
  );
  assert.equal(
    calls
      .filter((call) => call.method === 'Target.createTarget')
      .every((call) => !Object.hasOwn(call.params, 'browserContextId')),
    true,
  );
});

test('process Profile close is marker-verified and uses Browser.close', async () => {
  const targets = [
    {
      type: 'page',
      targetId: 'sandbox',
      url: browserSandboxMarkerUrl(sandboxId),
    },
    {
      type: 'page',
      targetId: 'profile',
      url: browserProcessProfileMarkerUrl(sandboxId),
    },
  ];
  const calls = [];
  const connection = {
    async send(method) {
      calls.push(method);
      if (method === 'Target.getTargets') return { targetInfos: targets };
      if (method === 'Target.getBrowserContexts') return { browserContextIds: [] };
      if (method === 'Browser.close') return {};
      return {};
    },
    close() {
      calls.push('connection.close');
    },
  };

  const result = await bindLocalBrowserSandbox({
    cdpUrl: 'http://127.0.0.1:9223',
    sandboxId,
    platform: 'dingdandao',
    mode: 'close-process-profile',
  }, {
    connect: async () => connection,
  });

  assert.equal(result.status, 'closed');
  assert.equal(result.isolation, 'process_profile');
  assert.equal(calls.filter((method) => method === 'Browser.close').length, 1);
  assert.equal(calls.filter((method) => method === 'Target.createTarget').length, 0);
});

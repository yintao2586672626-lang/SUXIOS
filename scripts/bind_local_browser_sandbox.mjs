#!/usr/bin/env node
import { pathToFileURL } from 'node:url';
import { connectLoopbackCdp } from './dingdandao_cloud_capture.mjs';
import {
  BROWSER_SANDBOX_PLATFORMS,
  assertContextHasNoDifferentSandbox,
  browserSandboxMarkerUrl,
  normalizeBrowserSandboxId,
  normalizeBrowserSandboxPlatform,
  platformContextCandidates,
  resolveBrowserSandboxContext,
} from './lib/browser_sandbox.mjs';

function safeReason(error) {
  return String(error?.message || error || 'browser_sandbox_operation_failed')
    .toLowerCase()
    .replace(/[^a-z0-9_-]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 80) || 'browser_sandbox_operation_failed';
}

function normalizeLoopbackCdpUrl(value) {
  const url = new URL(String(value || 'http://127.0.0.1:9223'));
  if (url.protocol !== 'http:'
    || url.hostname !== '127.0.0.1'
    || !/^[1-9][0-9]{1,4}$/.test(url.port)
    || Number(url.port) > 65535
    || url.pathname !== '/'
    || url.search !== ''
    || url.hash !== ''
    || url.username !== ''
    || url.password !== ''
  ) {
    throw new Error('browser_sandbox_cdp_scope_invalid');
  }
  return url.toString().replace(/\/$/, '');
}

function parseArguments(argv) {
  const values = {};
  for (const argument of argv) {
    const match = String(argument).match(/^--([a-z-]+)=(.*)$/);
    if (!match) throw new Error('browser_sandbox_argument_invalid');
    values[match[1]] = match[2];
  }
  const mode = String(values.mode || 'inspect').trim().toLowerCase();
  if (!['inspect', 'create', 'bind-existing'].includes(mode)) {
    throw new Error('browser_sandbox_mode_invalid');
  }
  return {
    cdpUrl: normalizeLoopbackCdpUrl(values['cdp-url']),
    sandboxId: normalizeBrowserSandboxId(values['sandbox-id']),
    platform: normalizeBrowserSandboxPlatform(values.platform),
    mode,
  };
}

async function browserState(connection) {
  const [{ targetInfos }, contexts] = await Promise.all([
    connection.send('Target.getTargets'),
    connection.send('Target.getBrowserContexts').catch(() => ({ browserContextIds: [] })),
  ]);
  return {
    targetInfos: Array.isArray(targetInfos) ? targetInfos : [],
    browserContextIds: Array.isArray(contexts?.browserContextIds)
      ? contexts.browserContextIds
      : [],
  };
}

function publicResult(options, status, isolation = 'browser_context') {
  return {
    status,
    platform: options.platform,
    sandbox_id: options.sandboxId,
    isolation,
    start_url: BROWSER_SANDBOX_PLATFORMS[options.platform].startUrl,
    browser_context_exposed: false,
    session_material_exposed: false,
    sensitive_values_exposed: false,
  };
}

export async function bindLocalBrowserSandbox(options, dependencies = {}) {
  const connect = dependencies.connect || connectLoopbackCdp;
  const connection = await connect(options.cdpUrl, dependencies.fetchImpl);
  let createdContextId = null;
  try {
    let state = await browserState(connection);
    try {
      const existing = resolveBrowserSandboxContext({
        ...state,
        sandboxId: options.sandboxId,
        requireIsolated: true,
      });
      return publicResult(options, 'bound', existing.isolation);
    } catch (error) {
      if (safeReason(error) !== 'browser_sandbox_not_bound') throw error;
    }

    if (options.mode === 'inspect') {
      throw new Error('browser_sandbox_not_bound');
    }

    let browserContextId = null;
    if (options.mode === 'create') {
      const created = await connection.send('Target.createBrowserContext', {
        disposeOnDetach: false,
      });
      browserContextId = String(created?.browserContextId || '').trim();
      if (!browserContextId) throw new Error('browser_sandbox_context_create_failed');
      createdContextId = browserContextId;
    } else {
      const candidates = platformContextCandidates({
        ...state,
        platform: options.platform,
      });
      if (candidates.includes('')) {
        throw new Error('browser_sandbox_existing_context_not_isolated');
      }
      if (candidates.length === 0) {
        throw new Error('browser_sandbox_platform_context_missing');
      }
      if (candidates.length > 1) {
        throw new Error('browser_sandbox_platform_context_ambiguous');
      }
      [browserContextId] = candidates;
    }

    assertContextHasNoDifferentSandbox({
      targetInfos: state.targetInfos,
      browserContextId,
      sandboxId: options.sandboxId,
    });
    const createInContext = async (url, background) => {
      const created = await connection.send('Target.createTarget', {
        url,
        background,
        browserContextId,
      });
      if (!String(created?.targetId || '').trim()) {
        throw new Error('browser_sandbox_target_create_failed');
      }
    };
    await createInContext(browserSandboxMarkerUrl(options.sandboxId), true);
    if (options.mode === 'create') {
      await createInContext(BROWSER_SANDBOX_PLATFORMS[options.platform].startUrl, false);
    }

    state = await browserState(connection);
    const verified = resolveBrowserSandboxContext({
      ...state,
      sandboxId: options.sandboxId,
      requireIsolated: true,
    });
    createdContextId = null;
    return publicResult(
      options,
      options.mode === 'create' ? 'awaiting_login' : 'bound',
      verified.isolation,
    );
  } catch (error) {
    if (createdContextId) {
      await connection.send('Target.disposeBrowserContext', {
        browserContextId: createdContextId,
      }).catch(() => {});
    }
    throw error;
  } finally {
    connection.close();
  }
}

async function main() {
  const options = parseArguments(process.argv.slice(2));
  const result = await bindLocalBrowserSandbox(options);
  process.stdout.write(`${JSON.stringify(result)}\n`);
}

const direct = process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url;
if (direct) {
  main().catch((error) => {
    process.stderr.write(`${JSON.stringify({
      status: 'blocked',
      reason: safeReason(error),
      browser_context_exposed: false,
      session_material_exposed: false,
      sensitive_values_exposed: false,
    })}\n`);
    process.exit(1);
  });
}

import assert from 'node:assert/strict';
import { createHash, randomBytes } from 'node:crypto';
import { access, appendFile, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { connect as connectTcp, createServer as createTcpServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import {
  EncryptedProfileVault,
  ReceiptChain,
  createGateway,
  decodeMasterKey,
  decryptArchive,
  encryptArchive,
  isCloudProfileReadOnlyRequestAllowed,
  isDingdandaoReadOnlyRequestAllowed,
  normalizeBrowserPageTarget,
  isUnsupportedSnapBrowserExecutable,
} from '../../deploy/remote-browser/cloud_browser_gateway.mjs';

const scopeDigest = (value) => createHash('sha256').update(value).digest('hex');

async function listenLoopback(server) {
  await new Promise((resolvePromise, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', resolvePromise);
  });
  return server.address().port;
}

async function closeListeningServer(server) {
  if (!server?.listening) return;
  await new Promise((resolvePromise) => server.close(resolvePromise));
}

async function openViewerUpgrade(port, profileId, sessionId) {
  return await new Promise((resolvePromise, reject) => {
    const socket = connectTcp({ host: '127.0.0.1', port });
    let response = '';
    const timer = setTimeout(() => {
      socket.destroy();
      reject(new Error('viewer_upgrade_timeout'));
    }, 2_000);
    socket.once('error', (error) => {
      clearTimeout(timer);
      reject(error);
    });
    socket.on('data', (chunk) => {
      response += chunk.toString('utf8');
      if (!response.includes('\r\n\r\n')) return;
      clearTimeout(timer);
      resolvePromise({ socket, response });
    });
    socket.once('connect', () => {
      socket.write([
        'GET /v1/viewer/websockify HTTP/1.1',
        `Host: 127.0.0.1:${port}`,
        'Connection: Upgrade',
        'Upgrade: websocket',
        'Sec-WebSocket-Key: dGVzdC1rZXk=',
        'Sec-WebSocket-Version: 13',
        `X-SUXIOS-Viewer-Profile-Scope: ${scopeDigest(profileId)}`,
        `X-SUXIOS-Viewer-Session-Scope: ${scopeDigest(sessionId)}`,
        '',
        '',
      ].join('\r\n'));
    });
  });
}

async function waitForSocketClose(socket) {
  if (socket.destroyed) return;
  await new Promise((resolvePromise, reject) => {
    const timer = setTimeout(() => reject(new Error('viewer_socket_not_closed')), 2_000);
    socket.once('close', () => {
      clearTimeout(timer);
      resolvePromise();
    });
  });
}

test('gateway health starts on loopback without starting a browser', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-cloud-gateway-health-'));
  let server;
  try {
    const keyFile = join(root, 'profile.key');
    const tokenFile = join(root, 'control.token');
    await writeFile(keyFile, randomBytes(32));
    await writeFile(tokenFile, 't'.repeat(48));
    const gateway = await createGateway({
      SUXIOS_CLOUD_BROWSER_BIND: '127.0.0.1',
      SUXIOS_CLOUD_BROWSER_PORT: '0',
      SUXIOS_CLOUD_BROWSER_PROFILE_KEY_FILE: keyFile,
      SUXIOS_CLOUD_BROWSER_CONTROL_TOKEN_FILE: tokenFile,
      SUXIOS_CLOUD_BROWSER_ENCRYPTED_ROOT: join(root, 'profiles'),
      SUXIOS_CLOUD_BROWSER_RUNTIME_ROOT: join(root, 'runtime'),
      SUXIOS_CLOUD_BROWSER_RECEIPT_CHAIN: join(root, 'receipts', 'chain.jsonl'),
    });
    server = gateway.server;
    await new Promise((resolvePromise, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolvePromise);
    });
    const address = server.address();
    const response = await fetch(`http://127.0.0.1:${address.port}/health`);
    assert.equal(response.status, 200);
    assert.deepEqual(await response.json(), {
      status: 'ok',
      bind: '127.0.0.1',
      encrypted_profile_store: true,
      receipt_chain_valid: true,
      active_login_sessions: 0,
      active_collection_sessions: 0,
      active_browser_sessions: 0,
      browser_autostart: false,
      read_only_policy_runtime: typeof globalThis.WebSocket === 'function',
    });
  } finally {
    if (server) await new Promise((resolvePromise) => server.close(resolvePromise));
    await rm(root, { recursive: true, force: true });
  }
});

test('login reserves global capacity before async validation and readiness failure releases it', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-login-capacity-'));
  let server;
  let releaseValidation;
  let markValidationStarted;
  let failReadiness = false;
  const validationStarted = new Promise((resolvePromise) => {
    markValidationStarted = resolvePromise;
  });
  const validationGate = new Promise((resolvePromise) => {
    releaseValidation = resolvePromise;
  });
  try {
    const keyFile = join(root, 'profile.key');
    const tokenFile = join(root, 'control.token');
    const token = 'a'.repeat(48);
    await writeFile(keyFile, randomBytes(32));
    await writeFile(tokenFile, token);
    const gateway = await createGateway({
      SUXIOS_CLOUD_BROWSER_BIND: '127.0.0.1',
      SUXIOS_CLOUD_BROWSER_PORT: '0',
      SUXIOS_CLOUD_BROWSER_PROFILE_KEY_FILE: keyFile,
      SUXIOS_CLOUD_BROWSER_CONTROL_TOKEN_FILE: tokenFile,
      SUXIOS_CLOUD_BROWSER_ENCRYPTED_ROOT: join(root, 'profiles'),
      SUXIOS_CLOUD_BROWSER_RUNTIME_ROOT: join(root, 'runtime'),
      SUXIOS_CLOUD_BROWSER_RECEIPT_CHAIN: join(root, 'receipts', 'chain.jsonl'),
    }, {
      bridge: async (action, payload) => {
        if (action === 'validate_login' && payload.session_id.endsWith('a')) {
          markValidationStarted();
          await validationGate;
        }
        if (action !== 'validate_login') throw new Error('unexpected_bridge_action');
        return { profile: { platform: 'ctrip' }, login_entry: { validated: true } };
      },
      startBrowser: async () => ({ exitCode: null }),
      waitForBrowserPage: async () => {
        if (failReadiness) throw new Error('browser_cdp_not_ready');
        return { type: 'page' };
      },
      stopBrowser: async () => {},
    });
    server = gateway.server;
    const port = await listenLoopback(server);
    const base = `http://127.0.0.1:${port}`;
    const loginA = {
      profile_id: 'cbp_loginprofileaaaa',
      session_id: 'cbls_loginsessionaaaa',
      ticket: 'a'.repeat(32),
      platform: 'ctrip',
    };
    const firstOpen = fetch(`${base}/v1/login/open`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(loginA),
    });
    await validationStarted;

    const busy = await fetch(`${base}/v1/collection/open`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify({
        profile_id: 'cbp_collectionaaaaaa',
        platform: 'dingdandao',
        tenant_id: 8,
        hotel_id: 80,
        owner_user_id: 7,
        target_date: '2026-08-14',
        collection_kind: 'operating_target_today',
        access_mode: 'read_only',
      }),
    });
    assert.equal(busy.status, 409);
    assert.equal((await busy.json()).reason, 'gateway_collection_capacity_busy');

    releaseValidation();
    assert.equal((await firstOpen).status, 201);
    const loginAbortNoop = await fetch(`${base}/v1/collection/abort`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify({ profile_public_id: loginA.profile_id }),
    }).then((response) => response.json());
    assert.equal(loginAbortNoop.status, 'no_active_collection');
    assert.equal(loginAbortNoop.cleanup_verified, true);
    const loginHealth = await fetch(`${base}/health`).then((response) => response.json());
    assert.equal(loginHealth.active_login_sessions, 1);
    const cancelled = await fetch(`${base}/v1/login/cancel`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify({
        profile_id: loginA.profile_id,
        session_id: loginA.session_id,
        platform: loginA.platform,
      }),
    });
    assert.equal(cancelled.status, 200);

    failReadiness = true;
    const failed = await fetch(`${base}/v1/login/open`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        ...loginA,
        profile_id: 'cbp_loginprofilebbbb',
        session_id: 'cbls_loginsessionbbbb',
        ticket: 'b'.repeat(32),
      }),
    });
    assert.equal(failed.status, 422);
    assert.equal((await failed.json()).reason, 'browser_cdp_not_ready');
    const health = await fetch(`${base}/health`).then((response) => response.json());
    assert.equal(health.active_browser_sessions, 0);
  } finally {
    await closeListeningServer(server);
    await rm(root, { recursive: true, force: true });
  }
});

test('viewer WebSocket is exact-session scoped and is forcibly disconnected before capacity reuse', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-viewer-lifecycle-'));
  let gatewayServer;
  const bridgeActions = [];
  const upstreamSockets = new Set();
  const noVncServer = createTcpServer((socket) => {
    upstreamSockets.add(socket);
    socket.once('close', () => upstreamSockets.delete(socket));
    socket.once('data', () => {
      socket.write('HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n\r\n');
    });
  });
  try {
    const noVncPort = await listenLoopback(noVncServer);
    const keyFile = join(root, 'profile.key');
    const tokenFile = join(root, 'control.token');
    const token = 'b'.repeat(48);
    await writeFile(keyFile, randomBytes(32));
    await writeFile(tokenFile, token);
    const gateway = await createGateway({
      SUXIOS_CLOUD_BROWSER_BIND: '127.0.0.1',
      SUXIOS_CLOUD_BROWSER_PORT: '0',
      SUXIOS_CLOUD_BROWSER_NOVNC_PORT: String(noVncPort),
      SUXIOS_CLOUD_BROWSER_PROFILE_KEY_FILE: keyFile,
      SUXIOS_CLOUD_BROWSER_CONTROL_TOKEN_FILE: tokenFile,
      SUXIOS_CLOUD_BROWSER_ENCRYPTED_ROOT: join(root, 'profiles'),
      SUXIOS_CLOUD_BROWSER_RUNTIME_ROOT: join(root, 'runtime'),
      SUXIOS_CLOUD_BROWSER_RECEIPT_CHAIN: join(root, 'receipts', 'chain.jsonl'),
    }, {
      bridge: async (action, payload) => {
        bridgeActions.push(action);
        if (action === 'validate_login') {
          return { profile: { platform: payload.profile_id.endsWith('a') ? 'ctrip' : 'meituan' }, login_entry: { validated: true } };
        }
        if (action === 'cancel_login_entry') {
          return { authorization_status: 'unauthorized' };
        }
        assert.equal(action, 'complete_login');
        assert.match(payload.session_expires_at, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/);
        if (payload.profile_id.endsWith('c')) throw new Error('simulated_complete_failure');
        return { authorization_status: 'ready_to_collect', session_expires_at: payload.session_expires_at };
      },
      startBrowser: async () => ({ exitCode: null }),
      waitForBrowserPage: async () => ({ type: 'page' }),
      stopBrowser: async () => {},
    });
    gatewayServer = gateway.server;
    const port = await listenLoopback(gatewayServer);
    const base = `http://127.0.0.1:${port}`;
    const loginA = {
      profile_id: 'cbp_viewerprofileaaaa',
      session_id: 'cbls_viewersessionaaaa',
      ticket: 'c'.repeat(32),
      platform: 'ctrip',
    };
    const loginB = {
      profile_id: 'cbp_viewerprofilebbbb',
      session_id: 'cbls_viewersessionbbbb',
      ticket: 'd'.repeat(32),
      platform: 'meituan',
    };
    const openLogin = (login) => fetch(`${base}/v1/login/open`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(login),
    });
    assert.equal((await openLogin(loginA)).status, 201);
    const viewerA = await openViewerUpgrade(port, loginA.profile_id, loginA.session_id);
    assert.match(viewerA.response, /^HTTP\/1\.1 101 /);

    const completed = await fetch(`${base}/v1/login/complete`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify({
        profile_id: loginA.profile_id,
        session_id: loginA.session_id,
        platform: loginA.platform,
      }),
    });
    assert.equal(completed.status, 200);
    await waitForSocketClose(viewerA.socket);

    assert.equal((await openLogin(loginB)).status, 201);
    const staleA = await openViewerUpgrade(port, loginA.profile_id, loginA.session_id);
    assert.match(staleA.response, /^HTTP\/1\.1 401 /);
    staleA.socket.destroy();
    const viewerB = await openViewerUpgrade(port, loginB.profile_id, loginB.session_id);
    assert.match(viewerB.response, /^HTTP\/1\.1 101 /);
    const cancelled = await fetch(`${base}/v1/login/cancel`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify({
        profile_id: loginB.profile_id,
        session_id: loginB.session_id,
        platform: loginB.platform,
      }),
    });
    assert.equal(cancelled.status, 200);
    const cancelledBody = await cancelled.json();
    assert.equal(cancelledBody.status, 'cancelled');
    assert.equal(cancelledBody.cleanup_verified, true);
    await waitForSocketClose(viewerB.socket);

    const loginC = {
      profile_id: 'cbp_viewerprofilecccc',
      session_id: 'cbls_viewersessioncccc',
      ticket: 'e'.repeat(32),
      platform: 'meituan',
    };
    assert.equal((await openLogin(loginC)).status, 201);
    const failedCompletion = await fetch(`${base}/v1/login/complete`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify({
        profile_id: loginC.profile_id,
        session_id: loginC.session_id,
        platform: loginC.platform,
      }),
    });
    assert.equal(failedCompletion.status, 422);
    assert.equal((await failedCompletion.json()).reason, 'simulated_complete_failure');
    assert.deepEqual(bridgeActions.slice(-3), [
      'validate_login',
      'complete_login',
      'cancel_login_entry',
    ]);
    const finalHealth = await fetch(`${base}/health`).then((response) => response.json());
    assert.equal(finalHealth.active_browser_sessions, 0);
  } finally {
    for (const socket of upstreamSockets) socket.destroy();
    await closeListeningServer(gatewayServer);
    await closeListeningServer(noVncServer);
    await rm(root, { recursive: true, force: true });
  }
});

test('login cancel is exact-session scoped, idempotent, and cancels an opening reservation', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-login-cancel-'));
  let server;
  let markOpeningStarted;
  const openingStarted = new Promise((resolvePromise) => {
    markOpeningStarted = resolvePromise;
  });
  try {
    const keyFile = join(root, 'profile.key');
    const tokenFile = join(root, 'control.token');
    const token = 'f'.repeat(48);
    await writeFile(keyFile, randomBytes(32));
    await writeFile(tokenFile, token);
    const gateway = await createGateway({
      SUXIOS_CLOUD_BROWSER_BIND: '127.0.0.1',
      SUXIOS_CLOUD_BROWSER_PORT: '0',
      SUXIOS_CLOUD_BROWSER_PROFILE_KEY_FILE: keyFile,
      SUXIOS_CLOUD_BROWSER_CONTROL_TOKEN_FILE: tokenFile,
      SUXIOS_CLOUD_BROWSER_ENCRYPTED_ROOT: join(root, 'profiles'),
      SUXIOS_CLOUD_BROWSER_RUNTIME_ROOT: join(root, 'runtime'),
      SUXIOS_CLOUD_BROWSER_RECEIPT_CHAIN: join(root, 'receipts', 'chain.jsonl'),
    }, {
      bridge: async (action, payload, options = {}) => {
        assert.equal(action, 'validate_login');
        markOpeningStarted();
        await new Promise((resolvePromise, reject) => {
          if (options.signal?.aborted) {
            reject(new Error('bridge_aborted'));
            return;
          }
          options.signal?.addEventListener('abort', () => reject(new Error('bridge_aborted')), { once: true });
        });
        return { profile: { platform: 'ctrip' }, login_entry: { validated: true } };
      },
      startBrowser: async () => ({ exitCode: null }),
      waitForBrowserPage: async () => ({ type: 'page' }),
      stopBrowser: async () => {},
    });
    server = gateway.server;
    const port = await listenLoopback(server);
    const base = `http://127.0.0.1:${port}`;
    const login = {
      profile_id: 'cbp_cancelprofileaaaa',
      session_id: 'cbls_cancelsessionaaaa',
      ticket: 'g'.repeat(32),
      platform: 'ctrip',
    };
    const opening = fetch(`${base}/v1/login/open`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(login),
    });
    await openingStarted;

    const wrong = await fetch(`${base}/v1/login/cancel`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify({ ...login, profile_id: 'cbp_cancelprofilebbbb', ticket: undefined }),
    }).then((response) => response.json());
    assert.equal(wrong.status, 'no_active_login');
    assert.equal(wrong.cleanup_verified, true);
    const stillOpening = await fetch(`${base}/health`).then((response) => response.json());
    assert.equal(stillOpening.active_login_sessions, 1);

    const cancelledResponse = await fetch(`${base}/v1/login/cancel`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify({
        profile_id: login.profile_id,
        session_id: login.session_id,
        platform: login.platform,
      }),
    });
    assert.equal(cancelledResponse.status, 200);
    const cancelled = await cancelledResponse.json();
    assert.equal(cancelled.status, 'cancelled');
    assert.equal(cancelled.cleanup_verified, true);
    assert.equal((await opening).status, 409);

    const repeated = await fetch(`${base}/v1/login/cancel`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify({
        profile_id: login.profile_id,
        session_id: login.session_id,
        platform: login.platform,
      }),
    }).then((response) => response.json());
    assert.equal(repeated.status, 'no_active_login');
    assert.equal(repeated.cleanup_verified, true);
    const health = await fetch(`${base}/health`).then((response) => response.json());
    assert.equal(health.active_browser_sessions, 0);
  } finally {
    await closeListeningServer(server);
    await rm(root, { recursive: true, force: true });
  }
});

test('collection abort is loopback-token protected, exact-profile scoped, idempotent, and cancels opening', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-collection-abort-'));
  let server;
  let markOpeningStarted;
  const openingStarted = new Promise((resolvePromise) => {
    markOpeningStarted = resolvePromise;
  });
  try {
    const keyFile = join(root, 'profile.key');
    const tokenFile = join(root, 'control.token');
    const token = 'e'.repeat(48);
    await writeFile(keyFile, randomBytes(32));
    await writeFile(tokenFile, token);
    const gateway = await createGateway({
      SUXIOS_CLOUD_BROWSER_BIND: '127.0.0.1',
      SUXIOS_CLOUD_BROWSER_PORT: '0',
      SUXIOS_CLOUD_BROWSER_PROFILE_KEY_FILE: keyFile,
      SUXIOS_CLOUD_BROWSER_CONTROL_TOKEN_FILE: tokenFile,
      SUXIOS_CLOUD_BROWSER_ENCRYPTED_ROOT: join(root, 'profiles'),
      SUXIOS_CLOUD_BROWSER_RUNTIME_ROOT: join(root, 'runtime'),
      SUXIOS_CLOUD_BROWSER_RECEIPT_CHAIN: join(root, 'receipts', 'chain.jsonl'),
    }, {
      bridge: async (action, payload, options = {}) => {
        assert.equal(action, 'validate_dingdandao_collection');
        markOpeningStarted();
        await new Promise((resolvePromise, reject) => {
          if (options.signal?.aborted) {
            reject(new Error('bridge_aborted'));
            return;
          }
          options.signal?.addEventListener('abort', () => reject(new Error('bridge_aborted')), { once: true });
        });
        return { profile: { profile_id: payload.profile_id, platform: 'dingdandao' } };
      },
      startBrowser: async () => ({ exitCode: null }),
      waitForBrowserPage: async () => ({ type: 'page' }),
      stopBrowser: async () => {},
      installReadOnlyPolicy: async () => ({
        requestPolicyEnforced: true,
        httpCacheDisabled: true,
        serviceWorkerBypassed: true,
        close() {},
      }),
    });
    server = gateway.server;
    const port = await listenLoopback(server);
    const base = `http://127.0.0.1:${port}`;
    const profileId = 'cbp_abortprofileaaaaa';
    const opening = fetch(`${base}/v1/collection/open`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify({
        profile_id: profileId,
        platform: 'dingdandao',
        tenant_id: 8,
        hotel_id: 80,
        owner_user_id: 7,
        target_date: '2026-08-14',
        collection_kind: 'operating_target_today',
        access_mode: 'read_only',
      }),
    });
    await openingStarted;

    const unauthorized = await fetch(`${base}/v1/collection/abort`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ profile_public_id: profileId }),
    });
    assert.equal(unauthorized.status, 401);
    const overScoped = await fetch(`${base}/v1/collection/abort`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify({ profile_public_id: profileId, hotel_id: 80 }),
    });
    assert.equal(overScoped.status, 422);
    assert.equal((await overScoped.json()).reason, 'collection_abort_scope_invalid');

    const wrongProfile = await fetch(`${base}/v1/collection/abort`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify({ profile_public_id: 'cbp_abortprofilebbbbb' }),
    }).then((response) => response.json());
    assert.deepEqual(wrongProfile, {
      status: 'no_active_collection',
      profile_public_id: 'cbp_abortprofilebbbbb',
      aborted: false,
      collection_session_id: null,
      cleanup_verified: true,
    });

    const wrongSession = await fetch(`${base}/v1/collection/abort`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify({
        profile_public_id: profileId,
        collection_session_id: 'cbcs_notthiscollection1',
      }),
    }).then((response) => response.json());
    assert.equal(wrongSession.status, 'no_active_collection');
    assert.equal(wrongSession.cleanup_verified, true);

    const abortResponse = await fetch(`${base}/v1/collection/abort`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify({ profile_public_id: profileId }),
    });
    assert.equal(abortResponse.status, 200);
    const aborted = await abortResponse.json();
    assert.equal(aborted.status, 'aborted');
    assert.equal(aborted.aborted, true);
    assert.equal(aborted.cleanup_verified, true);
    assert.equal((await opening).status, 409);

    const repeated = await fetch(`${base}/v1/collection/abort`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify({ profile_public_id: profileId }),
    }).then((response) => response.json());
    assert.equal(repeated.status, 'no_active_collection');
    assert.equal(repeated.cleanup_verified, true);
    const health = await fetch(`${base}/health`).then((response) => response.json());
    assert.equal(health.active_browser_sessions, 0);
  } finally {
    await closeListeningServer(server);
    await rm(root, { recursive: true, force: true });
  }
});

test('Dingdandao read-only policy permits only safe methods and the fixed main document', () => {
  const source =
    'https://www.dingdandao.com/pmsManage/report/pro/dataCenter/accommodationData';
  assert.equal(isDingdandaoReadOnlyRequestAllowed({
    url: source,
    method: 'GET',
    resourceType: 'Document',
  }), true);
  assert.equal(isDingdandaoReadOnlyRequestAllowed({
    url: 'https://www.dingdandao.com/other',
    method: 'GET',
    resourceType: 'Document',
  }), false);
  assert.equal(isDingdandaoReadOnlyRequestAllowed({
    url: 'https://www.dingdandao.com/api/read',
    method: 'POST',
    resourceType: 'XHR',
  }), false);
  assert.equal(isDingdandaoReadOnlyRequestAllowed({
    url: 'https://static.example.com/app.js',
    method: 'GET',
    resourceType: 'Script',
  }), true);
  assert.equal(isDingdandaoReadOnlyRequestAllowed({
    url: 'http://www.dingdandao.com/app.js',
    method: 'GET',
    resourceType: 'Script',
  }), false);
});

test('OTA Profile policy allows reads only inside the selected platform scope', () => {
  assert.equal(isCloudProfileReadOnlyRequestAllowed({
    platform: 'ctrip',
    url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportRealTimeDate',
    method: 'POST',
    resourceType: 'XHR',
  }), true);
  assert.equal(isCloudProfileReadOnlyRequestAllowed({
    platform: 'ctrip',
    url: 'https://ebooking.ctrip.com/api/rate/update',
    method: 'POST',
    resourceType: 'XHR',
  }), false);
  assert.equal(isCloudProfileReadOnlyRequestAllowed({
    platform: 'ctrip',
    url: 'https://ebooking.ctrip.com/api/report/query',
    method: 'POST',
    resourceType: 'XHR',
  }), false);
  assert.equal(isCloudProfileReadOnlyRequestAllowed({
    platform: 'meituan',
    url: 'https://eb.meituan.com/datacenter/home/traffic',
    method: 'POST',
    resourceType: 'Fetch',
  }), true);
  assert.equal(isCloudProfileReadOnlyRequestAllowed({
    platform: 'meituan',
    url: 'https://eb.meituan.com/datacenter/home/traffic/save',
    method: 'POST',
    resourceType: 'Fetch',
  }), false);
  assert.equal(isCloudProfileReadOnlyRequestAllowed({
    platform: 'ctrip',
    url: 'https://me.meituan.com/datacenter/home/traffic',
    method: 'POST',
    resourceType: 'XHR',
  }), false);
  for (const method of ['PUT', 'PATCH', 'DELETE']) {
    assert.equal(isCloudProfileReadOnlyRequestAllowed({
      platform: 'meituan',
      url: 'https://eb.meituan.com/api/report/query',
      method,
      resourceType: 'XHR',
    }), false);
  }
});

test('Chromium json/list page identifiers normalize before target policy comparison', () => {
  assert.equal(normalizeBrowserPageTarget({ type: 'page' }), null);
  assert.deepEqual(normalizeBrowserPageTarget({
    id: 'A1B2C3',
    type: 'page',
    webSocketDebuggerUrl: 'ws://127.0.0.1:9223/devtools/page/A1B2C3',
  }), {
    id: 'A1B2C3',
    targetId: 'A1B2C3',
    type: 'page',
    webSocketDebuggerUrl: 'ws://127.0.0.1:9223/devtools/page/A1B2C3',
  });
});

test('browser executable guard rejects Snap without misclassifying an explicit system Chrome', () => {
  assert.equal(isUnsupportedSnapBrowserExecutable('/snap/bin/chromium'), true);
  assert.equal(isUnsupportedSnapBrowserExecutable('/usr/bin/google-chrome-stable'), false);
});

test('protected Dingdandao collection window validates scope, stays read-only, and seals on close', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-dingdandao-window-'));
  let server;
  const calls = [];
  try {
    const keyFile = join(root, 'profile.key');
    const tokenFile = join(root, 'control.token');
    const token = 't'.repeat(48);
    await writeFile(keyFile, randomBytes(32));
    await writeFile(tokenFile, token);
    const gateway = await createGateway({
      SUXIOS_CLOUD_BROWSER_BIND: '127.0.0.1',
      SUXIOS_CLOUD_BROWSER_PORT: '0',
      SUXIOS_CLOUD_BROWSER_PROFILE_KEY_FILE: keyFile,
      SUXIOS_CLOUD_BROWSER_CONTROL_TOKEN_FILE: tokenFile,
      SUXIOS_CLOUD_BROWSER_ENCRYPTED_ROOT: join(root, 'profiles'),
      SUXIOS_CLOUD_BROWSER_RUNTIME_ROOT: join(root, 'runtime'),
      SUXIOS_CLOUD_BROWSER_RECEIPT_CHAIN: join(root, 'receipts', 'chain.jsonl'),
      SUXIOS_CLOUD_BROWSER_COLLECTION_TTL_SECONDS: '60',
    }, {
      bridge: async (action, payload) => {
        calls.push(['bridge', action, payload]);
        if (action !== 'validate_dingdandao_collection') throw new Error('unexpected_bridge_action');
        return {
          validated: true,
          collection_kind: 'operating_target_today',
          access_mode: 'read_only',
          target_date: payload.target_date,
          tenant_id: payload.tenant_id,
          hotel_id: payload.hotel_id,
          owner_user_id: payload.owner_user_id,
          profile: {
            profile_id: payload.profile_id,
            platform: 'dingdandao',
          },
        };
      },
      startBrowser: async (_config, profilePath, platform, startUrl) => {
        calls.push(['start', platform, startUrl, profilePath.endsWith('cbp_abcdefghijklmnop')]);
        return { exitCode: null };
      },
      stopBrowser: async () => {
        calls.push(['stop']);
      },
      waitForBrowserPage: async () => ({ targetId: 'test-page' }),
      installReadOnlyPolicy: async () => {
        calls.push(['guard']);
        return {
          requestPolicyEnforced: true,
          httpCacheDisabled: true,
          serviceWorkerBypassed: true,
          close: () => calls.push(['guard_close']),
        };
      },
    });
    server = gateway.server;
    await new Promise((resolvePromise, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolvePromise);
    });
    const address = server.address();
    const base = `http://127.0.0.1:${address.port}`;
    const body = {
      profile_id: 'cbp_abcdefghijklmnop',
      platform: 'dingdandao',
      tenant_id: 8,
      hotel_id: 5,
      owner_user_id: 7,
      target_date: '2026-07-27',
      collection_kind: 'operating_target_today',
      access_mode: 'read_only',
    };
    const unauthorized = await fetch(`${base}/v1/collection/open`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(body),
    });
    assert.equal(unauthorized.status, 401);
    assert.equal(calls.length, 0);

    const openedResponse = await fetch(`${base}/v1/collection/open`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify(body),
    });
    assert.equal(openedResponse.status, 201);
    const opened = await openedResponse.json();
    assert.equal(opened.status, 'collection_open');
    assert.equal(opened.read_only_enforced, true);
    assert.equal(opened.access_mode, 'read_only');
    assert.equal(opened.browser_started, true);
    assert.equal('viewer_url' in opened, false);
    assert.equal('cdp_url' in opened, false);
    assert.equal('profile_path' in opened, false);
    assert.deepEqual(calls.slice(0, 3).map((entry) => entry[0]), ['bridge', 'start', 'guard']);
    assert.deepEqual(calls[1].slice(1, 3), ['dingdandao', 'about:blank']);

    const busy = await fetch(`${base}/v1/collection/open`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify(body),
    });
    assert.equal(busy.status, 409);
    const activeHealth = await fetch(`${base}/health`).then((response) => response.json());
    assert.equal(activeHealth.active_login_sessions, 0);
    assert.equal(activeHealth.active_collection_sessions, 1);
    assert.equal(activeHealth.active_browser_sessions, 1);

    const closeResponse = await fetch(`${base}/v1/collection/close`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify({
        collection_session_id: opened.collection_session_id,
        profile_id: body.profile_id,
        platform: body.platform,
        outcome: 'completed',
      }),
    });
    assert.equal(closeResponse.status, 200);
    const closed = await closeResponse.json();
    assert.equal(closed.status, 'collection_closed');
    assert.equal(closed.profile_sealed, true);
    assert.equal(closed.data_status, 'unverified');
    assert.equal(calls.some((entry) => entry[0] === 'stop'), true);
    assert.equal(calls.some((entry) => entry[0] === 'guard_close'), true);
    const finalHealth = await fetch(`${base}/health`).then((response) => response.json());
    assert.equal(finalHealth.active_browser_sessions, 0);

    const otaReceipt = await fetch(`${base}/v1/collection/receipt`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify({
        task_id: 'cct_abcdefgh',
        collection_session_id: opened.collection_session_id,
        profile_id: body.profile_id,
        platform: 'dingdandao',
        tenant_id: 8,
        hotel_id: 5,
        target_date: body.target_date,
        source_method: 'cloud_browser_profile',
        status: 'blocked',
        identity_verified: false,
        saved_count: 0,
        readback_count: 0,
        field_facts_sha256: 'a'.repeat(64),
        failure_stage: 'platform_scope',
      }),
    });
    assert.equal(otaReceipt.status, 422);
    assert.equal((await otaReceipt.json()).reason, 'platform_invalid');
  } finally {
    if (server) await new Promise((resolvePromise) => server.close(resolvePromise));
    await rm(root, { recursive: true, force: true });
  }
});

test('OTA collection window requires an exact data source and exposes only controlled CDP readiness', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-ota-window-'));
  let server;
  const calls = [];
  try {
    const keyFile = join(root, 'profile.key');
    const tokenFile = join(root, 'control.token');
    const token = 'u'.repeat(48);
    await writeFile(keyFile, randomBytes(32));
    await writeFile(tokenFile, token);
    const gateway = await createGateway({
      SUXIOS_CLOUD_BROWSER_BIND: '127.0.0.1',
      SUXIOS_CLOUD_BROWSER_PORT: '0',
      SUXIOS_CLOUD_BROWSER_PROFILE_KEY_FILE: keyFile,
      SUXIOS_CLOUD_BROWSER_CONTROL_TOKEN_FILE: tokenFile,
      SUXIOS_CLOUD_BROWSER_ENCRYPTED_ROOT: join(root, 'profiles'),
      SUXIOS_CLOUD_BROWSER_RUNTIME_ROOT: join(root, 'runtime'),
      SUXIOS_CLOUD_BROWSER_RECEIPT_CHAIN: join(root, 'receipts', 'chain.jsonl'),
    }, {
      bridge: async (action, payload) => {
        calls.push(['bridge', action, payload]);
        assert.equal(action, 'validate_ota_collection');
        return {
          validated: true,
          collection_kind: 'ota_channel_profile',
          access_mode: 'read_only',
          data_source_id: payload.data_source_id,
          target_date: payload.target_date,
          tenant_id: payload.tenant_id,
          hotel_id: payload.hotel_id,
          owner_user_id: payload.owner_user_id,
          platform: payload.platform,
          profile: { profile_id: payload.profile_id, platform: payload.platform },
        };
      },
      startBrowser: async () => ({ exitCode: null }),
      stopBrowser: async () => calls.push(['stop']),
      waitForBrowserPage: async () => ({ targetId: 'test-page' }),
      installReadOnlyPolicy: async () => ({
        requestPolicyEnforced: true,
        httpCacheDisabled: true,
        serviceWorkerBypassed: true,
        close: () => calls.push(['guard_close']),
      }),
    });
    server = gateway.server;
    await new Promise((resolvePromise, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolvePromise);
    });
    const address = server.address();
    const base = `http://127.0.0.1:${address.port}`;
    const body = {
      profile_id: 'cbp_otaabcdefghijklmnop',
      platform: 'ctrip',
      data_source_id: 25,
      tenant_id: 8,
      hotel_id: 5,
      owner_user_id: 7,
      target_date: '2026-07-27',
      collection_kind: 'ota_channel_profile',
      access_mode: 'read_only',
    };
    const missingSource = await fetch(`${base}/v1/collection/open`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify({ ...body, data_source_id: undefined }),
    });
    assert.equal(missingSource.status, 422);
    assert.equal(calls.length, 0);

    const openedResponse = await fetch(`${base}/v1/collection/open`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify(body),
    });
    assert.equal(openedResponse.status, 201);
    const opened = await openedResponse.json();
    assert.equal(opened.data_source_id, 25);
    assert.equal(opened.read_only_enforced, true);
    assert.equal(opened.collector_read_only_contract, true);
    assert.deepEqual(opened.network_freshness_control, {
      http_cache_disabled: true,
      service_worker_bypassed: true,
    });
    assert.equal('cdp_url' in opened, false);
    assert.equal('profile_path' in opened, false);
    assert.equal(calls[0][1], 'validate_ota_collection');
    assert.equal(calls[0][2].data_source_id, 25);

    const closed = await fetch(`${base}/v1/collection/close`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify({
        collection_session_id: opened.collection_session_id,
        profile_id: body.profile_id,
        platform: body.platform,
        outcome: 'completed',
      }),
    });
    assert.equal(closed.status, 200);
    const closedPayload = await closed.json();
    assert.equal(closedPayload.profile_sealed, true);

    const resultBody = {
      task_id: 'cct_abcdefgh',
      collection_session_id: opened.collection_session_id,
      profile_id: body.profile_id,
      platform: body.platform,
      tenant_id: body.tenant_id,
      hotel_id: body.hotel_id,
      owner_user_id: body.owner_user_id,
      data_source_id: body.data_source_id,
      target_date: body.target_date,
      close_receipt_id: closedPayload.receipt_id,
      close_receipt_hash: closedPayload.receipt_hash,
      source_method: 'cloud_browser_profile',
      status: 'saved',
      identity_verified: true,
      saved_count: 2,
      readback_count: 2,
      field_facts_sha256: 'a'.repeat(64),
    };
    const receiptResponse = await fetch(`${base}/v1/collection/receipt`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify(resultBody),
    });
    assert.equal(receiptResponse.status, 201);
    const acceptedReceipt = await receiptResponse.json();
    const readbackResponse = await fetch(`${base}/v1/receipts/${acceptedReceipt.receipt_id}`, {
      headers: { authorization: `Bearer ${token}` },
    });
    assert.equal(readbackResponse.status, 200);
    const readback = await readbackResponse.json();
    assert.equal(readback.kind, 'collection_result');
    assert.deepEqual(readback.payload, { ...resultBody, failure_stage: null });

    const replayResponse = await fetch(`${base}/v1/collection/receipt`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify(resultBody),
    });
    assert.equal(replayResponse.status, 409);
    assert.equal((await replayResponse.json()).reason, 'collection_result_replay_blocked');
  } finally {
    if (server) await new Promise((resolvePromise) => server.close(resolvePromise));
    await rm(root, { recursive: true, force: true });
  }
});

test('legacy OTA target-date lease remains profile scoped without a data source id', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-ota-target-date-window-'));
  let server;
  const calls = [];
  try {
    const keyFile = join(root, 'profile.key');
    const tokenFile = join(root, 'control.token');
    const token = 'v'.repeat(48);
    await writeFile(keyFile, randomBytes(32));
    await writeFile(tokenFile, token);
    const gateway = await createGateway({
      SUXIOS_CLOUD_BROWSER_BIND: '127.0.0.1',
      SUXIOS_CLOUD_BROWSER_PORT: '0',
      SUXIOS_CLOUD_BROWSER_PROFILE_KEY_FILE: keyFile,
      SUXIOS_CLOUD_BROWSER_CONTROL_TOKEN_FILE: tokenFile,
      SUXIOS_CLOUD_BROWSER_ENCRYPTED_ROOT: join(root, 'profiles'),
      SUXIOS_CLOUD_BROWSER_RUNTIME_ROOT: join(root, 'runtime'),
      SUXIOS_CLOUD_BROWSER_RECEIPT_CHAIN: join(root, 'receipts', 'chain.jsonl'),
    }, {
      bridge: async (action, payload) => {
        calls.push(['bridge', action, payload]);
        assert.equal(action, 'validate_ota_collection');
        assert.equal('data_source_id' in payload, false);
        return {
          validated: true,
          collection_kind: 'ota_target_date',
          access_mode: 'read_only',
          target_date: payload.target_date,
          tenant_id: payload.tenant_id,
          hotel_id: payload.hotel_id,
          owner_user_id: payload.owner_user_id,
          platform: payload.platform,
          source_scope: 'target_date_only',
          profile: { profile_id: payload.profile_id, platform: payload.platform },
        };
      },
      startBrowser: async () => ({ exitCode: null }),
      waitForBrowserPage: async () => ({ targetId: 'test-page' }),
      stopBrowser: async () => calls.push(['stop']),
      installReadOnlyPolicy: async () => ({
        requestPolicyEnforced: true,
        httpCacheDisabled: true,
        serviceWorkerBypassed: true,
        close: () => calls.push(['guard_close']),
      }),
    });
    server = gateway.server;
    await new Promise((resolvePromise, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolvePromise);
    });
    const base = `http://127.0.0.1:${server.address().port}`;
    const body = {
      profile_id: 'cbp_legacyabcdefghijkl',
      platform: 'ctrip',
      tenant_id: 8,
      hotel_id: 5,
      owner_user_id: 7,
      target_date: '2026-07-27',
      collection_kind: 'ota_target_date',
      access_mode: 'read_only',
    };
    const openedResponse = await fetch(`${base}/v1/collection/open`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify(body),
    });
    assert.equal(openedResponse.status, 201);
    const opened = await openedResponse.json();
    assert.equal(opened.collection_kind, 'ota_target_date');
    assert.equal(opened.profile_restored, true);
    assert.equal(opened.session_owner, 'gateway_collection');
    assert.equal(opened.collector_read_only_contract, true);

    const closedResponse = await fetch(`${base}/v1/collection/close`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify({
        collection_session_id: opened.collection_session_id,
        profile_id: body.profile_id,
        platform: body.platform,
        outcome: 'completed',
      }),
    });
    assert.equal(closedResponse.status, 200);
    assert.equal((await closedResponse.json()).profile_sealed, true);
  } finally {
    if (server) await new Promise((resolvePromise) => server.close(resolvePromise));
    await rm(root, { recursive: true, force: true });
  }
});

test('encrypted Profile archive uses authenticated encryption and profile-scoped AAD', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-cloud-browser-'));
  try {
    const source = join(root, 'profile.tar.gz');
    const encrypted = join(root, 'profile.tar.gz.enc');
    const restored = join(root, 'restored.tar.gz');
    const payload = randomBytes(4096);
    const key = decodeMasterKey(randomBytes(32));
    await writeFile(source, payload);

    await encryptArchive(source, encrypted, key, 'cbp_abcdefghijklmnop');
    const encryptedBytes = await readFile(encrypted);
    assert.equal(encryptedBytes.includes(payload.subarray(0, 64)), false);

    await decryptArchive(encrypted, restored, key, 'cbp_abcdefghijklmnop');
    assert.deepEqual(await readFile(restored), payload);
    await assert.rejects(
      decryptArchive(encrypted, join(root, 'wrong-aad'), key, 'cbp_ponmlkjihgfedcba'),
    );
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test('Profile vault persists only ciphertext and restores plaintext into runtime storage', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-cloud-vault-'));
  try {
    const encryptedRoot = join(root, 'persistent');
    const runtimeRoot = join(root, 'runtime');
    const vault = new EncryptedProfileVault({
      encryptedRoot,
      runtimeRoot,
      key: randomBytes(32),
    });
    const profileId = 'cbp_abcdefghijklmnop';
    const runtimeProfile = await vault.restore(profileId);
    await writeFile(join(runtimeProfile, 'Preferences'), '{"fixture":true}');
    const encryptedPath = await vault.seal(profileId);
    await assert.rejects(access(runtimeProfile));
    assert.equal(encryptedPath.endsWith('.tar.gz.enc'), true);
    assert.equal((await readFile(encryptedPath)).includes(Buffer.from('{"fixture":true}')), false);

    const restoredProfile = await vault.restore(profileId);
    assert.equal(await readFile(join(restoredProfile, 'Preferences'), 'utf8'), '{"fixture":true}');
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test('receipt chain links records and detects silent tampering', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-cloud-receipts-'));
  try {
    const chain = new ReceiptChain(join(root, 'chain.jsonl'));
    const first = await chain.append('login_profile_ready', {
      profile_id: 'cbp_abcdefghijklmnop',
      platform: 'ctrip',
      encrypted_at_rest: true,
    });
    const second = await chain.append('collection_result', {
      task_id: 'cct_abcdefgh',
      profile_id: 'cbp_abcdefghijklmnop',
      platform: 'ctrip',
      status: 'saved',
      identity_verified: true,
      saved_count: 2,
      readback_count: 2,
      field_facts_sha256: 'a'.repeat(64),
    });
    assert.equal(second.prev_hash, first.receipt_hash);
    assert.equal(await chain.verify(), true);
    await assert.rejects(
      chain.append('collection_result', { cookie: 'must-not-enter-receipt-chain' }),
      /receipt_sensitive_field_rejected/,
    );

    await appendFile(chain.chainPath, `${JSON.stringify({ receipt_id: 'tampered' })}\n`);
    assert.equal(await chain.verify(), false);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test('deployment assets keep all listeners local and never autostart Chromium', async () => {
  const [gateway, installer, verifier, gatewayUnit, novncUnit, vncUnit, envExample, bridge] =
    await Promise.all([
      readFile(new URL('../../deploy/remote-browser/cloud_browser_gateway.mjs', import.meta.url), 'utf8'),
      readFile(new URL('../../deploy/remote-browser/install_secure_remote_browser.sh', import.meta.url), 'utf8'),
      readFile(new URL('../../deploy/remote-browser/verify_secure_remote_browser.sh', import.meta.url), 'utf8'),
      readFile(new URL('../../deploy/remote-browser/systemd/suxios-cloud-browser-gateway.service', import.meta.url), 'utf8'),
      readFile(new URL('../../deploy/remote-browser/systemd/suxios-cloud-browser-novnc.service', import.meta.url), 'utf8'),
      readFile(new URL('../../deploy/remote-browser/systemd/suxios-cloud-browser-vnc.service', import.meta.url), 'utf8'),
      readFile(new URL('../../deploy/remote-browser/gateway.env.example', import.meta.url), 'utf8'),
      readFile(new URL('../../scripts/cloud_browser_gateway_bridge.php', import.meta.url), 'utf8'),
    ]);

  assert.match(gateway, /bindAddress !== '127\.0\.0\.1'/);
  assert.match(gateway, /--remote-debugging-address=127\.0\.0\.1/);
  assert.match(gateway, /--lang=zh-CN/);
  assert.match(gateway, /--accept-lang=zh-CN,zh;q=0\.9/);
  assert.match(gateway, /LANG: 'zh_CN\.UTF-8'/);
  assert.match(gateway, /browser_autostart: false/);
  assert.match(gateway, /dingdandao: DINGDANDAO_SOURCE_URL/);
  assert.match(gateway, /validate_dingdandao_collection/);
  assert.match(gateway, /read_only_enforced: session\.guard\.requestPolicyEnforced === true/);
  assert.match(gateway, /collection_browser_policy_unverified/);
  assert.match(gateway, /Target\.setDiscoverTargets/);
  assert.match(gateway, /Target\.setAutoAttach/);
  assert.match(gateway, /waitForDebuggerOnStart: true/);
  assert.match(gateway, /message\.sessionId \|\| ''/);
  assert.match(gateway, /targetInfo\.type === 'page'/);
  assert.match(gateway, /policyViolation = true/);
  assert.match(gateway, /const markPolicyViolation = \(\) => \{\s*policyViolation = true;\s*child\?\.kill\?\.\('SIGTERM'\)/);
  assert.match(gateway, /if \(!intentionalClose\) \{\s*markPolicyViolation\(\)/);
  assert.match(gateway, /requestPolicyEnforced\s*&& !policyViolation\s*&& !closed\s*&& socket\.readyState === WebSocket\.OPEN/);
  assert.match(gateway, /pages\.length > 1/);
  assert.match(gateway, /collectionPolicyStillEnforced/);
  assert.match(gateway, /collection_browser_policy_breached/);
  assert.match(gateway, /collector_read_only_contract: OTA_RECEIPT_PLATFORM_PATTERN\.test/);
  assert.match(gateway, /Runtime\.evaluate/);
  assert.match(gateway, /read_only_navigation_document_not_ready/);
  assert.match(gateway, /navigationDeadline = Date\.now\(\) \+ \(platform === 'ctrip' \? 30000 : 12000\)/);
  assert.match(gateway, /platform === 'ctrip'[\s\S]*?\['loading', 'interactive', 'complete'\][\s\S]*?\['interactive', 'complete'\]/);
  assert.match(gateway, /read_only_navigation_evaluation_unavailable/);
  assert.match(gateway, /if \(sourcePageReady\) \{[\s\S]*?window\.name='suxios_profile_lease_guarded'[\s\S]*?break;/);
  assert.match(gateway, /closeUnexpectedPageTarget[\s\S]*?Target\.closeTarget/);
  assert.match(gateway, /\['service_worker', 'shared_worker', 'worker'\]\.includes\(targetInfo\.type\)[\s\S]*?closeUnexpectedPageTarget/);
  assert.doesNotMatch(gateway, /targetInfo\.type === 'page'[\s\S]{0,160}failClosedForTarget\(targetInfo\.targetId\)/);
  assert.match(gateway, /\['loading', 'interactive', 'complete'\]/);
  assert.match(gateway, /return 'origin_mismatch'/);
  assert.match(gateway, /return pathMatched \? 'matched' : 'path_mismatch'/);
  assert.match(gateway, /`read_only_navigation_\$\{locationState\}`/);
  assert.match(gateway, /reason\.startsWith\('browser_'\)/);
  assert.match(gateway, /reason\.startsWith\('read_only_'\)/);
  assert.match(gateway, /reason\.startsWith\('snap_chromium_'\)/);
  assert.match(gateway, /url\.pathname === '\/v1\/collection\/open'[\s\S]{0,220}!authorized\(request, controlToken\)/);
  assert.match(gateway, /url\.pathname === '\/v1\/collection\/close'[\s\S]{0,220}!authorized\(request, controlToken\)/);
  assert.match(gateway, /function claimCapacity\([\s\S]{0,220}capacitySlot !== null/);
  assert.match(gateway, /claimCapacity\(session, 'gateway_login_capacity_busy'\)/);
  assert.match(gateway, /claimCapacity\(session, 'gateway_collection_capacity_busy'\)/);
  assert.match(gateway, /waitForBrowserPageCall\(config, session\.browser\)/);
  assert.match(gateway, /Math\.max\(900, Number\.parseInt\(env\.SUXIOS_CLOUD_BROWSER_COLLECTION_TTL_SECONDS/);
  assert.match(gateway, /url\.pathname === '\/v1\/collection\/abort'/);
  assert.match(gateway, /cleanup_verified: capacitySlot !== session && session\.state === 'closed'/);
  assert.match(gateway, /session_expires_at: sessionExpiresAt/);
  assert.match(gateway, /\.toISOString\(\)/);
  assert.match(gateway, /validate_login/);
  assert.match(gateway, /complete_login/);
  assert.match(gateway, /url\.pathname === '\/v1\/login\/complete'[\s\S]{0,220}!authorized\(request, controlToken\)/);
  assert.match(gateway, /receipt_chain_integrity_failed/);
  assert.match(gateway, /receipt_truth_gate_failed/);
  assert.match(installer, /Chromium was not started/);
  assert.match(installer, /Node\.js >= 20\.10\.0 is required/);
  assert.doesNotMatch(installer, /apt-get install[^\n]*\bchromium\b/);
  assert.match(installer, /is_snap_chromium/);
  assert.match(installer, /Snap Chromium is not supported/);
  assert.match(installer, /SUXIOS_CLOUD_BROWSER_COLLECTION_TTL_SECONDS 1200/);
  assert.match(installer, /collection_ttl.*-lt 900.*collection_ttl.*-gt 1800/s);
  assert.match(installer, /--experimental-websocket/);
  assert.doesNotMatch(installer, /suxios-cloud-browser-chromium\.service/);
  assert.match(verifier, /CDP is listening before a short-lived login session was opened/);
  assert.match(verifier, /read_only_policy_runtime/);
  assert.match(gatewayUnit, /LoadCredential=profile-master-key/);
  assert.match(gatewayUnit, /LoadCredential=control-token/);
  assert.match(gatewayUnit, /ExecStart=@NODE_BIN@ --experimental-websocket/);
  assert.match(novncUnit, /127\.0\.0\.1:6080/);
  assert.match(vncUnit, /-listen 127\.0\.0\.1/);
  assert.match(envExample, /^SUXIOS_CLOUD_BROWSER_BIND=127\.0\.0\.1$/m);
  assert.match(envExample, /^SUXIOS_CLOUD_BROWSER_COLLECTION_TTL_SECONDS=1200$/m);
  assert.match(envExample, /^SUXIOS_CLOUD_BROWSER_EXECUTABLE=\/usr\/bin\/google-chrome-stable$/m);
  assert.match(bridge, /MAX_GATEWAY_INPUT_BYTES = 8192/);
  assert.doesNotMatch(bridge, /argv.*ticket/i);
});

test('remote browser verification tolerates bounded listener startup without hiding exposure', async () => {
  const verifier = await readFile(new URL('../../deploy/remote-browser/verify_secure_remote_browser.sh', import.meta.url), 'utf8');
  assert.match(verifier, /SUXIOS_CLOUD_BROWSER_VERIFY_TIMEOUT_SECONDS:-15/);
  assert.match(verifier, /while :; do[\s\S]+?systemctl is-active[\s\S]+?ss -H -ltn[\s\S]+?sleep 1/);
  assert.match(verifier, /Port \$port is exposed beyond loopback/);
  assert.match(verifier, /did not become ready within %ss/);
});

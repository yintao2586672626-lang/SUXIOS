import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { access, appendFile, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { createServer as createHttpServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import {
  EncryptedProfileVault,
  GATEWAY_BUILD_SHA256,
  ReceiptChain,
  createGateway,
  decodeMasterKey,
  decryptArchive,
  encryptArchive,
  isDingdandaoReadOnlyRequestAllowed,
  platformStartUrl,
  shanghaiToday,
} from '../../deploy/remote-browser/cloud_browser_gateway.mjs';

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
      protocol_version: 'suxios_cloud_browser_gateway.v2',
      build_sha256: GATEWAY_BUILD_SHA256,
      active_release_gateway_sha256: GATEWAY_BUILD_SHA256,
      active_release_build_match: true,
      bind: '127.0.0.1',
      encrypted_profile_store: true,
      receipt_chain_valid: true,
      active_login_sessions: 0,
      active_collection_sessions: 0,
      active_profile_leases: 0,
      active_browser_sessions: 0,
      profile_lease_contract: 'dingdandao_profile_lease.v1',
      browser_autostart: false,
      read_only_policy_runtime: typeof globalThis.WebSocket === 'function',
    });
  } finally {
    if (server) await new Promise((resolvePromise) => server.close(resolvePromise));
    await rm(root, { recursive: true, force: true });
  }
});

test('gateway blocks every business route when opt build differs from active release', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-cloud-gateway-build-'));
  let server;
  let bridgeCalls = 0;
  try {
    const keyFile = join(root, 'profile.key');
    const tokenFile = join(root, 'control.token');
    const activeGatewayPath = join(root, 'active-gateway.mjs');
    await writeFile(keyFile, randomBytes(32));
    await writeFile(tokenFile, 't'.repeat(48));
    await writeFile(activeGatewayPath, '// intentionally different build\n');
    const gateway = await createGateway({
      SUXIOS_CLOUD_BROWSER_BIND: '127.0.0.1',
      SUXIOS_CLOUD_BROWSER_PORT: '0',
      SUXIOS_CLOUD_BROWSER_PROFILE_KEY_FILE: keyFile,
      SUXIOS_CLOUD_BROWSER_CONTROL_TOKEN_FILE: tokenFile,
      SUXIOS_CLOUD_BROWSER_ENCRYPTED_ROOT: join(root, 'profiles'),
      SUXIOS_CLOUD_BROWSER_RUNTIME_ROOT: join(root, 'runtime'),
      SUXIOS_CLOUD_BROWSER_RECEIPT_CHAIN:
        join(root, 'receipts', 'chain.jsonl'),
      SUXIOS_CLOUD_BROWSER_ACTIVE_GATEWAY_PATH: activeGatewayPath,
    }, {
      bridge: async () => {
        bridgeCalls += 1;
        throw new Error('bridge_must_not_run');
      },
    });
    server = gateway.server;
    await new Promise((resolvePromise, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolvePromise);
    });
    const base = `http://127.0.0.1:${server.address().port}`;
    const healthResponse = await fetch(`${base}/health`);
    assert.equal(healthResponse.status, 503);
    const health = await healthResponse.json();
    assert.equal(health.status, 'blocked');
    assert.equal(health.active_release_build_match, false);
    assert.equal(health.build_sha256, GATEWAY_BUILD_SHA256);

    const businessResponse = await fetch(`${base}/v1/login/open`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        profile_id: 'cbp_build_mismatch_123456789',
        session_id: 'cbls_build_mismatch_123456789',
        ticket: 'a'.repeat(48),
        platform: 'dingdandao',
      }),
    });
    assert.equal(businessResponse.status, 503);
    assert.equal(
      (await businessResponse.json()).reason,
      'gateway_active_release_build_mismatch',
    );
    assert.equal(bridgeCalls, 0);
  } finally {
    if (server) await new Promise((resolvePromise) => server.close(resolvePromise));
    await rm(root, { recursive: true, force: true });
  }
});

test('Dingdandao login stays pending until exact identity is verified, then seals only its owned browser', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-cloud-login-life-'));
  let server;
  let identityAllowed = false;
  let stopCount = 0;
  const calls = [];
  const profileId = 'cbp_login_profile_123456789';
  const sessionId = 'cbls_login_session_123456789';
  const ticket = 'a'.repeat(48);
  const controlToken = 't'.repeat(48);
  try {
    const keyFile = join(root, 'profile.key');
    const tokenFile = join(root, 'control.token');
    await writeFile(keyFile, randomBytes(32));
    await writeFile(tokenFile, controlToken);
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
        calls.push([action, payload]);
        if (action === 'validate_login') {
          return {
            profile: {
              profile_id: profileId,
              hotel_id: 5,
              platform: 'dingdandao',
              authorization_status: 'awaiting_login',
            },
            login_entry: {
              session_id: sessionId,
              validated: true,
              consumed: false,
            },
          };
        }
        if (action === 'verify_dingdandao_login_identity') {
          if (!identityAllowed) throw new Error('identity_not_logged_in');
          return {
            validated: true,
            profile_id: profileId,
            session_id: sessionId,
            platform: 'dingdandao',
            hotel_id: 5,
            provider_hotel_name: '敦煌漠蓝',
            identity_status: 'matched',
            source_api_path: '/v2/ntw/web/ntw/get',
            capture_method: 'network_response',
            request_count: 1,
            captured_at: '2026-07-27T12:00:00.000Z',
            binding_persisted: false,
            session_material_exposed: false,
            raw_response_exposed: false,
            user_tabs_closed: false,
          };
        }
        if (action === 'complete_login') {
          return {
            profile_id: profileId,
            authorization_status: 'login_verified',
            session_expires_at: payload.session_expires_at,
          };
        }
        if (action === 'login_status') {
          return {
            profile_id: profileId,
            session_id: sessionId,
            platform: 'dingdandao',
            status: 'login_verified',
            login_session_status: 'verified',
            authorization_status: 'login_verified',
            expires_at: '2026-07-28 12:00:00',
            identity_verified: true,
            profile_encrypted_at_rest: true,
            terminal: true,
            sensitive_values_exposed: false,
          };
        }
        throw new Error('unexpected_bridge_action');
      },
      startBrowser: async () => ({ pid: 12345, exitCode: null }),
      waitForBrowserPage: async () => ({
        type: 'page',
        webSocketDebuggerUrl: 'ws://127.0.0.1/login-page',
      }),
      stopBrowser: async () => {
        stopCount += 1;
      },
    });
    server = gateway.server;
    await new Promise((resolvePromise, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolvePromise);
    });
    const address = server.address();
    const baseUrl = `http://127.0.0.1:${address.port}`;
    const loginBody = {
      profile_id: profileId,
      session_id: sessionId,
      ticket,
      platform: 'dingdandao',
    };
    const openResponse = await fetch(`${baseUrl}/v1/login/open`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(loginBody),
    });
    assert.equal(openResponse.status, 201);
    const opened = await openResponse.json();
    assert.equal(opened.status, 'awaiting_login');
    assert.equal(
      opened.protocol_version,
      'suxios_cloud_browser_gateway.v2',
    );
    assert.equal(opened.platform, 'dingdandao');
    assert.equal(opened.browser_started, true);
    assert.equal(opened.owned_browser_only, true);
    assert.equal(opened.user_browser_closed, false);

    const unauthorizedStatus = await fetch(`${baseUrl}/v1/login/status`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(loginBody),
    });
    assert.equal(unauthorizedStatus.status, 401);

    const statusResponse = await fetch(`${baseUrl}/v1/login/status`, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        authorization: `Bearer ${controlToken}`,
      },
      body: JSON.stringify(loginBody),
    });
    assert.equal(statusResponse.status, 200);
    const active = await statusResponse.json();
    assert.equal(active.status, 'awaiting_login');
    assert.equal(active.browser_started, true);
    assert.equal(active.identity_verified, false);
    assert.equal(active.sensitive_values_exposed, false);

    const blockedCompletion = await fetch(`${baseUrl}/v1/login/complete`, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        authorization: `Bearer ${controlToken}`,
      },
      body: JSON.stringify({
        profile_id: profileId,
        session_id: sessionId,
        platform: 'dingdandao',
      }),
    });
    assert.equal(blockedCompletion.status, 422);
    assert.equal(
      (await blockedCompletion.json()).reason,
      'dingdandao_login_identity_unverified',
    );
    assert.equal(stopCount, 0);

    identityAllowed = true;
    const completeResponse = await fetch(`${baseUrl}/v1/login/complete`, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        authorization: `Bearer ${controlToken}`,
      },
      body: JSON.stringify({
        profile_id: profileId,
        session_id: sessionId,
        platform: 'dingdandao',
      }),
    });
    assert.equal(completeResponse.status, 200);
    const completed = await completeResponse.json();
    assert.equal(completed.status, 'login_verified');
    assert.equal(completed.binding_required, true);
    assert.equal(completed.identity_verified, true);
    assert.equal(completed.owned_browser_closed, true);
    assert.equal(completed.user_browser_closed, false);
    assert.equal(completed.profile_encrypted_at_rest, true);
    assert.equal(completed.sensitive_values_exposed, false);
    assert.equal(stopCount, 1);
    await access(join(root, 'profiles', `${profileId}.tar.gz.enc`));
    await assert.rejects(
      access(join(root, 'runtime', profileId)),
      (error) => error?.code === 'ENOENT',
    );

    const terminalStatusResponse = await fetch(
      `${baseUrl}/v1/login/status`,
      {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          authorization: `Bearer ${controlToken}`,
        },
        body: JSON.stringify(loginBody),
      },
    );
    assert.equal(terminalStatusResponse.status, 200);
    const terminalStatus = await terminalStatusResponse.json();
    assert.equal(terminalStatus.status, 'login_verified');
    assert.equal(terminalStatus.browser_started, false);
    assert.equal(terminalStatus.terminal, true);
    assert.equal(terminalStatus.binding_required, true);
    assert.equal(terminalStatus.receipt_id, completed.receipt_id);

    const replay = await fetch(`${baseUrl}/v1/login/complete`, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        authorization: `Bearer ${controlToken}`,
      },
      body: JSON.stringify({
        profile_id: profileId,
        session_id: sessionId,
        platform: 'dingdandao',
      }),
    });
    assert.equal(replay.status, 200);
    const replayed = await replay.json();
    assert.equal(replayed.status, 'login_verified');
    assert.equal(replayed.idempotent_replay, true);
    assert.equal(replayed.binding_required, true);
    const actions = calls.map(([action]) => action);
    assert.deepEqual(actions, [
      'validate_login',
      'verify_dingdandao_login_identity',
      'verify_dingdandao_login_identity',
      'complete_login',
      'login_status',
      'login_status',
    ]);
    const receipts = await gateway.receipts.records();
    assert.equal(receipts.at(-1)?.kind, 'login_identity_verified');
    assert.equal(
      receipts.at(-1)?.payload?.identity_source_api_path,
      '/v2/ntw/web/ntw/get',
    );
  } finally {
    if (server) await new Promise((resolvePromise) => server.close(resolvePromise));
    await rm(root, { recursive: true, force: true });
  }
});

test('durable prepared receipt recovers one terminal receipt after restart and READY replays it', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-login-restart-recovery-'));
  let server;
  try {
    const keyFile = join(root, 'profile.key');
    const tokenFile = join(root, 'control.token');
    const receiptPath = join(root, 'receipts', 'chain.jsonl');
    const token = 't'.repeat(48);
    const profileId = 'cbp_restart_profile_123456789';
    const sessionId = 'cbls_restart_session_123456789';
    const expiresAt = '2026-07-28 10:00:00';
    await writeFile(keyFile, randomBytes(32));
    await writeFile(tokenFile, token);
    const chain = new ReceiptChain(receiptPath);
    const prepared = await chain.append('login_completion_prepared', {
      profile_id: profileId,
      session_id: sessionId,
      platform: 'dingdandao',
      authorization_status: 'login_verified',
      encrypted_at_rest: true,
      identity_verified: true,
      identity_status: 'matched',
      identity_source_api_path: '/v2/ntw/web/ntw/get',
      session_expires_at: expiresAt,
      binding_required: true,
      gateway_build_sha256: GATEWAY_BUILD_SHA256,
    });
    let ready = false;
    const gateway = await createGateway({
      SUXIOS_CLOUD_BROWSER_BIND: '127.0.0.1',
      SUXIOS_CLOUD_BROWSER_PORT: '0',
      SUXIOS_CLOUD_BROWSER_PROFILE_KEY_FILE: keyFile,
      SUXIOS_CLOUD_BROWSER_CONTROL_TOKEN_FILE: tokenFile,
      SUXIOS_CLOUD_BROWSER_ENCRYPTED_ROOT: join(root, 'profiles'),
      SUXIOS_CLOUD_BROWSER_RUNTIME_ROOT: join(root, 'runtime'),
      SUXIOS_CLOUD_BROWSER_RECEIPT_CHAIN: receiptPath,
    }, {
      bridge: async (action) => {
        assert.equal(action, 'login_status');
        return {
          profile_id: profileId,
          session_id: sessionId,
          platform: 'dingdandao',
          status: ready ? 'ready_to_collect' : 'login_verified',
          login_session_status: 'verified',
          authorization_status: ready
            ? 'ready_to_collect'
            : 'login_verified',
          expires_at: expiresAt,
          identity_verified: true,
          profile_encrypted_at_rest: true,
          terminal: true,
          sensitive_values_exposed: false,
        };
      },
    });
    server = gateway.server;
    await new Promise((resolvePromise, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolvePromise);
    });
    const base = `http://127.0.0.1:${server.address().port}`;
    const request = () => fetch(`${base}/v1/login/complete`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify({
        profile_id: profileId,
        session_id: sessionId,
        platform: 'dingdandao',
      }),
    });
    const recoveredResponse = await request();
    assert.equal(recoveredResponse.status, 200);
    const recovered = await recoveredResponse.json();
    assert.equal(recovered.status, 'login_verified');
    assert.equal(recovered.binding_required, true);
    const recoveredReceiptId = recovered.receipt_id;

    ready = true;
    const readyResponse = await request();
    assert.equal(readyResponse.status, 200);
    const readyReplay = await readyResponse.json();
    assert.equal(readyReplay.status, 'ready_to_collect');
    assert.equal(readyReplay.binding_required, false);
    assert.equal(readyReplay.receipt_id, recoveredReceiptId);
    const records = await chain.records();
    const terminal = records.filter(
      (record) => record.kind === 'login_identity_verified',
    );
    assert.equal(terminal.length, 1);
    assert.equal(
      terminal[0].payload.prepared_receipt_id,
      prepared.receipt_id,
    );
    assert.equal(
      terminal[0].payload.prepared_receipt_hash,
      prepared.receipt_hash,
    );
    assert.equal(terminal[0].payload.recovered_from_durable_state, true);
  } finally {
    if (server) await new Promise((resolvePromise) => server.close(resolvePromise));
    await rm(root, { recursive: true, force: true });
  }
});

test('login browser start failure seals the restored Profile, expires DB state, and leaves no active session', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-cloud-login-fail-'));
  let server;
  const profileId = 'cbp_login_failure_123456789';
  const sessionId = 'cbls_login_failure_123456789';
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
    }, {
      bridge: async (action, payload) => {
        if (action === 'validate_login') {
          return {
            profile: {
              profile_id: profileId,
              hotel_id: 5,
              platform: 'dingdandao',
              authorization_status: 'awaiting_login',
            },
            login_entry: {
              session_id: sessionId,
              validated: true,
              consumed: false,
            },
          };
        }
        if (action === 'expire_login') {
          assert.equal(payload.reason, 'gateway_login_open_failed');
          return {
            profile_id: profileId,
            authorization_status: 'session_expired',
          };
        }
        throw new Error('unexpected_bridge_action');
      },
      startBrowser: async () => {
        throw new Error('browser_spawn_failed');
      },
    });
    server = gateway.server;
    await new Promise((resolvePromise, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolvePromise);
    });
    const address = server.address();
    const baseUrl = `http://127.0.0.1:${address.port}`;
    const response = await fetch(`${baseUrl}/v1/login/open`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        profile_id: profileId,
        session_id: sessionId,
        ticket: 'a'.repeat(48),
        platform: 'dingdandao',
      }),
    });
    assert.equal(response.status, 422);
    assert.equal((await response.json()).reason, 'browser_spawn_failed');
    await access(join(root, 'profiles', `${profileId}.tar.gz.enc`));
    await assert.rejects(
      access(join(root, 'runtime', profileId)),
      (error) => error?.code === 'ENOENT',
    );
    const health = await fetch(`${baseUrl}/health`).then((result) => result.json());
    assert.equal(health.active_login_sessions, 0);
    assert.equal(health.active_browser_sessions, 0);
    const receipts = await gateway.receipts.records();
    assert.equal(receipts.at(-1)?.kind, 'login_open_failed');
    assert.equal(
      receipts.at(-1)?.payload?.profile_encrypted_at_rest,
      true,
    );
    assert.equal(
      receipts.at(-1)?.payload?.database_login_expired,
      true,
    );
  } finally {
    if (server) await new Promise((resolvePromise) => server.close(resolvePromise));
    await rm(root, { recursive: true, force: true });
  }
});

test('login open waits for CDP readiness and compensates a spawned-but-unready browser', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-cloud-login-cdp-fail-'));
  let server;
  let stopCount = 0;
  let expiryCount = 0;
  const profileId = 'cbp_login_cdp_failure_123456';
  const sessionId = 'cbls_login_cdp_failure_123456';
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
      SUXIOS_CLOUD_BROWSER_RECEIPT_CHAIN:
        join(root, 'receipts', 'chain.jsonl'),
    }, {
      bridge: async (action, payload) => {
        if (action === 'validate_login') {
          return {
            profile: {
              profile_id: profileId,
              platform: 'dingdandao',
              authorization_status: 'awaiting_login',
            },
            login_entry: {
              session_id: sessionId,
              validated: true,
              consumed: false,
            },
          };
        }
        if (action === 'expire_login') {
          expiryCount += 1;
          assert.equal(payload.reason, 'gateway_login_open_failed');
          return {
            profile_id: profileId,
            authorization_status: 'session_expired',
          };
        }
        throw new Error('unexpected_bridge_action');
      },
      startBrowser: async () => ({ pid: 34567, exitCode: null }),
      waitForBrowserPage: async () => {
        throw new Error('browser_cdp_not_ready');
      },
      stopBrowser: async () => {
        stopCount += 1;
      },
    });
    server = gateway.server;
    await new Promise((resolvePromise, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolvePromise);
    });
    const address = server.address();
    const baseUrl = `http://127.0.0.1:${address.port}`;
    const response = await fetch(`${baseUrl}/v1/login/open`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        profile_id: profileId,
        session_id: sessionId,
        ticket: 'a'.repeat(48),
        platform: 'dingdandao',
      }),
    });
    assert.equal(response.status, 422);
    assert.equal((await response.json()).reason, 'browser_cdp_not_ready');
    assert.equal(stopCount, 1);
    assert.equal(expiryCount, 1);
    const health = await fetch(`${baseUrl}/health`)
      .then((result) => result.json());
    assert.equal(health.active_login_sessions, 0);
    assert.equal(health.active_browser_sessions, 0);
    const receipts = await gateway.receipts.records();
    assert.equal(receipts.at(-1)?.kind, 'login_open_failed');
    assert.equal(
      receipts.at(-1)?.payload?.database_login_expired,
      true,
    );
  } finally {
    if (server) {
      await new Promise((resolvePromise) => server.close(resolvePromise));
    }
    await rm(root, { recursive: true, force: true });
  }
});

test('login timeout seals the owned browser and durably expires the gateway session', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-cloud-login-timeout-'));
  let server;
  let timeoutCallback;
  let stopCount = 0;
  const actions = [];
  const profileId = 'cbp_login_timeout_123456789';
  const sessionId = 'cbls_login_timeout_123456789';
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
    }, {
      bridge: async (action, payload) => {
        actions.push(action);
        if (action === 'validate_login') {
          return {
            profile: {
              profile_id: profileId,
              hotel_id: 5,
              platform: 'dingdandao',
              authorization_status: 'awaiting_login',
            },
            login_entry: {
              session_id: sessionId,
              validated: true,
              consumed: false,
            },
          };
        }
        if (action === 'expire_login') {
          assert.equal(payload.reason, 'gateway_login_timeout');
          return {
            profile_id: profileId,
            authorization_status: 'session_expired',
          };
        }
        throw new Error('unexpected_bridge_action');
      },
      startBrowser: async () => ({ pid: 23456, exitCode: null }),
      waitForBrowserPage: async () => ({
        type: 'page',
        webSocketDebuggerUrl: 'ws://127.0.0.1/login-page',
      }),
      stopBrowser: async () => {
        stopCount += 1;
      },
      setLoginTimeout: (callback) => {
        timeoutCallback = callback;
        return { unref() {} };
      },
      clearLoginTimeout: () => undefined,
    });
    server = gateway.server;
    await new Promise((resolvePromise, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolvePromise);
    });
    const address = server.address();
    const baseUrl = `http://127.0.0.1:${address.port}`;
    const response = await fetch(`${baseUrl}/v1/login/open`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        profile_id: profileId,
        session_id: sessionId,
        ticket: 'a'.repeat(48),
        platform: 'dingdandao',
      }),
    });
    assert.equal(response.status, 201);
    assert.equal(typeof timeoutCallback, 'function');
    await timeoutCallback();
    assert.equal(stopCount, 1);
    assert.deepEqual(actions, ['validate_login', 'expire_login']);
    await access(join(root, 'profiles', `${profileId}.tar.gz.enc`));
    await assert.rejects(
      access(join(root, 'runtime', profileId)),
      (error) => error?.code === 'ENOENT',
    );
    const health = await fetch(`${baseUrl}/health`).then((result) => result.json());
    assert.equal(health.active_login_sessions, 0);
    assert.equal(health.active_browser_sessions, 0);
    const receipts = await gateway.receipts.records();
    assert.equal(receipts.at(-1)?.kind, 'login_timeout');
    assert.equal(
      receipts.at(-1)?.payload?.status,
      'session_expired',
    );
  } finally {
    if (server) await new Promise((resolvePromise) => server.close(resolvePromise));
    await rm(root, { recursive: true, force: true });
  }
});

test('concurrent login completes and timeout share one finalization and seal exactly once', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-cloud-login-race-'));
  let server;
  let timeoutCallback;
  let stopCount = 0;
  let identityCalls = 0;
  let completionCalls = 0;
  let expiryCalls = 0;
  let releaseIdentity;
  let identityStartedResolve;
  const identityGate = new Promise((resolvePromise) => {
    releaseIdentity = resolvePromise;
  });
  const identityStarted = new Promise((resolvePromise) => {
    identityStartedResolve = resolvePromise;
  });
  const profileId = 'cbp_login_race_profile_123456';
  const sessionId = 'cbls_login_race_session_123456';
  const controlToken = 't'.repeat(48);
  try {
    const keyFile = join(root, 'profile.key');
    const tokenFile = join(root, 'control.token');
    await writeFile(keyFile, randomBytes(32));
    await writeFile(tokenFile, controlToken);
    const gateway = await createGateway({
      SUXIOS_CLOUD_BROWSER_BIND: '127.0.0.1',
      SUXIOS_CLOUD_BROWSER_PORT: '0',
      SUXIOS_CLOUD_BROWSER_PROFILE_KEY_FILE: keyFile,
      SUXIOS_CLOUD_BROWSER_CONTROL_TOKEN_FILE: tokenFile,
      SUXIOS_CLOUD_BROWSER_ENCRYPTED_ROOT: join(root, 'profiles'),
      SUXIOS_CLOUD_BROWSER_RUNTIME_ROOT: join(root, 'runtime'),
      SUXIOS_CLOUD_BROWSER_RECEIPT_CHAIN:
        join(root, 'receipts', 'chain.jsonl'),
    }, {
      bridge: async (action, payload) => {
        if (action === 'validate_login') {
          return {
            profile: {
              profile_id: profileId,
              platform: 'dingdandao',
              authorization_status: 'awaiting_login',
            },
            login_entry: {
              session_id: sessionId,
              validated: true,
              consumed: false,
            },
          };
        }
        if (action === 'verify_dingdandao_login_identity') {
          identityCalls += 1;
          identityStartedResolve();
          await identityGate;
          return {
            validated: true,
            profile_id: profileId,
            session_id: sessionId,
            platform: 'dingdandao',
            hotel_id: 5,
            provider_hotel_name: '敦煌漠蓝',
            identity_status: 'matched',
            source_api_path: '/v2/ntw/web/ntw/get',
            capture_method: 'network_response',
            request_count: 1,
            captured_at: '2026-07-27T12:00:00.000Z',
            binding_persisted: false,
            session_material_exposed: false,
            raw_response_exposed: false,
            user_tabs_closed: false,
          };
        }
        if (action === 'complete_login') {
          completionCalls += 1;
          return {
            profile_id: profileId,
            authorization_status: 'login_verified',
            session_expires_at: payload.session_expires_at,
          };
        }
        if (action === 'expire_login') {
          expiryCalls += 1;
          return {
            profile_id: profileId,
            authorization_status: 'session_expired',
          };
        }
        throw new Error('unexpected_bridge_action');
      },
      startBrowser: async () => ({ pid: 45678, exitCode: null }),
      waitForBrowserPage: async () => ({
        type: 'page',
        webSocketDebuggerUrl: 'ws://127.0.0.1/login-page',
      }),
      stopBrowser: async () => {
        stopCount += 1;
      },
      setLoginTimeout: (callback) => {
        timeoutCallback = callback;
        return { unref() {} };
      },
      clearLoginTimeout: () => undefined,
    });
    server = gateway.server;
    await new Promise((resolvePromise, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolvePromise);
    });
    const address = server.address();
    const baseUrl = `http://127.0.0.1:${address.port}`;
    const loginBody = {
      profile_id: profileId,
      session_id: sessionId,
      ticket: 'a'.repeat(48),
      platform: 'dingdandao',
    };
    const opened = await fetch(`${baseUrl}/v1/login/open`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(loginBody),
    });
    assert.equal(opened.status, 201);
    const completeRequest = () => fetch(`${baseUrl}/v1/login/complete`, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        authorization: `Bearer ${controlToken}`,
      },
      body: JSON.stringify({
        profile_id: profileId,
        session_id: sessionId,
        platform: 'dingdandao',
      }),
    });
    const firstComplete = completeRequest();
    await identityStarted;
    const secondComplete = completeRequest();
    const timeoutRace = timeoutCallback();
    releaseIdentity();
    const [firstResponse, secondResponse] = await Promise.all([
      firstComplete,
      secondComplete,
      timeoutRace,
    ]).then(([first, second]) => [first, second]);
    assert.equal(firstResponse.status, 200);
    assert.equal(secondResponse.status, 200);
    assert.equal((await firstResponse.json()).status, 'login_verified');
    assert.equal((await secondResponse.json()).status, 'login_verified');
    assert.equal(identityCalls, 1);
    assert.equal(completionCalls, 1);
    assert.equal(expiryCalls, 0);
    assert.equal(stopCount, 1);
    const receipts = await gateway.receipts.records();
    assert.equal(
      receipts.filter((receipt) =>
        receipt.kind === 'login_identity_verified').length,
      1,
    );
  } finally {
    if (server) {
      await new Promise((resolvePromise) => server.close(resolvePromise));
    }
    await rm(root, { recursive: true, force: true });
  }
});

test('failed identity completion releases the waiting timeout to expire and seal once', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-login-failed-race-'));
  let server;
  let timeoutCallback;
  let releaseIdentity;
  let identityStartedResolve;
  let stopCalls = 0;
  let expiryCalls = 0;
  const identityStarted = new Promise((resolvePromise) => {
    identityStartedResolve = resolvePromise;
  });
  const identityGate = new Promise((resolvePromise) => {
    releaseIdentity = resolvePromise;
  });
  try {
    const keyFile = join(root, 'profile.key');
    const tokenFile = join(root, 'control.token');
    const receiptPath = join(root, 'receipts', 'chain.jsonl');
    const token = 't'.repeat(48);
    const profileId = 'cbp_failed_race_profile_123456';
    const sessionId = 'cbls_failed_race_session_123456';
    await writeFile(keyFile, randomBytes(32));
    await writeFile(tokenFile, token);
    const gateway = await createGateway({
      SUXIOS_CLOUD_BROWSER_BIND: '127.0.0.1',
      SUXIOS_CLOUD_BROWSER_PORT: '0',
      SUXIOS_CLOUD_BROWSER_PROFILE_KEY_FILE: keyFile,
      SUXIOS_CLOUD_BROWSER_CONTROL_TOKEN_FILE: tokenFile,
      SUXIOS_CLOUD_BROWSER_ENCRYPTED_ROOT: join(root, 'profiles'),
      SUXIOS_CLOUD_BROWSER_RUNTIME_ROOT: join(root, 'runtime'),
      SUXIOS_CLOUD_BROWSER_RECEIPT_CHAIN: receiptPath,
    }, {
      bridge: async (action) => {
        if (action === 'validate_login') {
          return {
            profile: {
              profile_id: profileId,
              platform: 'dingdandao',
              authorization_status: 'awaiting_login',
            },
            login_entry: {
              session_id: sessionId,
              validated: true,
              consumed: false,
            },
          };
        }
        if (action === 'verify_dingdandao_login_identity') {
          identityStartedResolve();
          await identityGate;
          throw new Error('identity_mismatch');
        }
        if (action === 'expire_login') {
          expiryCalls += 1;
          return {
            profile_id: profileId,
            authorization_status: 'session_expired',
          };
        }
        throw new Error('unexpected_bridge_action');
      },
      startBrowser: async () => ({ exitCode: null }),
      waitForBrowserPage: async () => ({
        type: 'page',
        webSocketDebuggerUrl: 'ws://127.0.0.1/login-page',
      }),
      stopBrowser: async () => {
        stopCalls += 1;
      },
      setLoginTimeout: (callback) => {
        timeoutCallback = callback;
        return { unref() {} };
      },
      clearLoginTimeout: () => undefined,
    });
    server = gateway.server;
    await new Promise((resolvePromise, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolvePromise);
    });
    const base = `http://127.0.0.1:${server.address().port}`;
    const login = {
      profile_id: profileId,
      session_id: sessionId,
      ticket: 'a'.repeat(48),
      platform: 'dingdandao',
    };
    const opened = await fetch(`${base}/v1/login/open`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(login),
    });
    assert.equal(opened.status, 201);
    const completing = fetch(`${base}/v1/login/complete`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify({
        profile_id: profileId,
        session_id: sessionId,
        platform: 'dingdandao',
      }),
    });
    await identityStarted;
    const expiring = timeoutCallback();
    releaseIdentity();
    const completion = await completing;
    await expiring;
    assert.equal(completion.status, 422);
    assert.equal(expiryCalls, 1);
    assert.equal(stopCalls, 1);
    const health = await fetch(`${base}/health`).then(
      (response) => response.json(),
    );
    assert.equal(health.active_login_sessions, 0);
    assert.equal(health.active_browser_sessions, 0);
    const records = (await readFile(receiptPath, 'utf8'))
      .trim()
      .split(/\r?\n/)
      .map((line) => JSON.parse(line));
    assert.equal(
      records.filter((record) => record.kind === 'login_timeout').length,
      1,
    );
  } finally {
    if (server) await new Promise((resolvePromise) => server.close(resolvePromise));
    await rm(root, { recursive: true, force: true });
  }
});

test('Dingdandao read-only policy permits only the fixed page and exact same-day query POSTs', () => {
  const source =
    'https://www.dingdandao.com/pmsManage/report/pro/dataCenter/accommodationData';
  const today = '2026-07-27';
  const baseBody = {
    TIMEZONEOFFSET: -480,
    ntwNum: 'store_123',
  };
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
    postData: JSON.stringify(baseBody),
    today,
  }), false);
  assert.equal(isDingdandaoReadOnlyRequestAllowed({
    url: 'https://www.dingdandao.com/v2/um-b/web/pro/data/businessIndicatorsTrend/county',
    method: 'POST',
    resourceType: 'Fetch',
    postData: JSON.stringify({
      ...baseBody,
      startDate: today,
      endDate: today,
      type: 1,
    }),
    today,
  }), false);
  assert.equal(isDingdandaoReadOnlyRequestAllowed({
    url: 'https://www.dingdandao.com/v2/ntw/web/ntw/get',
    method: 'POST',
    resourceType: 'XHR',
    postData: JSON.stringify(baseBody),
    today,
  }), true);
  assert.equal(isDingdandaoReadOnlyRequestAllowed({
    url: 'https://www.dingdandao.com/v2/um-b/web/pro/data/businessIndicatorsTotal',
    method: 'POST',
    resourceType: 'Fetch',
    postData: JSON.stringify({
      ...baseBody,
      startDate: today,
      endDate: today,
      festivalType: -1200,
    }),
    today,
  }), true);
  assert.equal(isDingdandaoReadOnlyRequestAllowed({
    url: 'https://www.dingdandao.com/v2/um-b/web/pro/data/businessIndicatorsTotal/county',
    method: 'POST',
    resourceType: 'Fetch',
    postData: JSON.stringify({
      ...baseBody,
      startDate: today,
      endDate: today,
      festivalType: -1200,
    }),
    today,
  }), true);
  assert.equal(isDingdandaoReadOnlyRequestAllowed({
    url: 'https://www.dingdandao.com/v2/um-b/web/pro/data/businessIndicatorsTrend/county',
    method: 'POST',
    resourceType: 'Fetch',
    postData: JSON.stringify({
      ...baseBody,
      startDate: today,
      endDate: today,
      type: 5,
    }),
    today,
  }), true);
  for (const path of [
    'businessIndicatorsSumDetail',
    'businessIndicatorsDailyDetail',
  ]) {
    for (const type of [0, 1, 2, 3]) {
      assert.equal(isDingdandaoReadOnlyRequestAllowed({
        url: `https://www.dingdandao.com/v2/um-b/web/pro/data/${path}`,
        method: 'POST',
        resourceType: 'XHR',
        postData: JSON.stringify({
          ...baseBody,
          startDate: today,
          endDate: today,
          type,
        }),
        today,
      }), true);
    }
    assert.equal(isDingdandaoReadOnlyRequestAllowed({
      url: `https://www.dingdandao.com/v2/um-b/web/pro/data/${path}`,
      method: 'POST',
      resourceType: 'XHR',
      postData: JSON.stringify({
        ...baseBody,
        startDate: today,
        endDate: today,
        type: 4,
      }),
      today,
    }), false);
  }
  assert.equal(isDingdandaoReadOnlyRequestAllowed({
    url: 'https://www.dingdandao.com/v2/um-b/web/pro/data/businessIndicatorsDailyDetail',
    method: 'POST',
    resourceType: 'XHR',
    postData: JSON.stringify({
      ...baseBody,
      startDate: '2026-07-26',
      endDate: '2026-07-26',
      type: 0,
    }),
    today,
  }), false);
  assert.equal(isDingdandaoReadOnlyRequestAllowed({
    url: 'https://www.dingdandao.com/v2/um-b/web/pro/data/businessIndicatorsTotal',
    method: 'POST',
    resourceType: 'XHR',
    postData: JSON.stringify({
      ...baseBody,
      startDate: today,
      endDate: today,
      festivalType: -1200,
      unexpected: true,
    }),
    today,
  }), false);
  assert.equal(isDingdandaoReadOnlyRequestAllowed({
    url: 'https://www.dingdandao.com/v2/um-b/web/pro/data/businessIndicatorsTrend',
    method: 'POST',
    resourceType: 'XHR',
    postData: JSON.stringify({
      ...baseBody,
      startDate: today,
      endDate: today,
      type: 4,
    }),
    today,
  }), false);
  for (const type of [1, 2, 3, 5]) {
    assert.equal(isDingdandaoReadOnlyRequestAllowed({
      url: 'https://www.dingdandao.com/v2/um-b/web/pro/data/businessIndicatorsTrend',
      method: 'POST',
      resourceType: 'XHR',
      postData: JSON.stringify({
        ...baseBody,
        startDate: today,
        endDate: today,
        type,
      }),
      today,
    }), true);
  }
  assert.equal(isDingdandaoReadOnlyRequestAllowed({
    url: 'https://www.dingdandao.com/v2/um-b/web/pro/data/businessIndicatorsTrend',
    method: 'POST',
    resourceType: 'XHR',
    postData: JSON.stringify({
      ...baseBody,
      startDate: today,
      endDate: today,
      type: 6,
    }),
    today,
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

test('Dingdandao has an explicit login URL and never falls through to Ctrip', () => {
  assert.equal(
    platformStartUrl('dingdandao'),
    'https://www.dingdandao.com/pmsManage/report/pro/dataCenter/accommodationData',
  );
  assert.equal(platformStartUrl('ctrip'), 'https://ebooking.ctrip.com/home/mainland');
  assert.throws(() => platformStartUrl('unknown'), /platform_start_url_missing/);
});

test('gateway-owned Dingdandao Profile lease restores, guards, and seals only its own browser', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-dingdandao-profile-lease-'));
  let server;
  const calls = [];
  try {
    const keyFile = join(root, 'profile.key');
    const tokenFile = join(root, 'control.token');
    const token = 't'.repeat(48);
    await writeFile(keyFile, randomBytes(32));
    await writeFile(tokenFile, token);
    const now = new Date('2026-07-27T02:00:00.000Z');
    const targetDate = shanghaiToday(now);
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
      now: () => now,
      bridge: async (action, payload) => {
        calls.push(['bridge', action, payload]);
        if (action === 'validate_dingdandao_binding_lease') return {
          status: 'ready_for_identity_probe',
          profile_id: payload.profile_id,
          tenant_id: payload.tenant_id,
          hotel_id: payload.hotel_id,
          owner_user_id: payload.owner_user_id,
          provider: 'dingdandao',
          source_scope: 'current_authenticated_session_identity_only',
          binding_persisted: false,
        };
        throw new Error('unexpected_bridge_action');
      },
      assertCdpPortAvailable: async () => {
        calls.push(['cdp_available']);
      },
      startBrowser: async (_config, profilePath, platform, startUrl) => {
        calls.push([
          'start',
          platform,
          startUrl,
          profilePath.endsWith('cbp_abcdefghijklmnop'),
        ]);
        return { exitCode: null };
      },
      stopBrowser: async () => {
        calls.push(['stop']);
      },
      installReadOnlyPolicy: async (_config, _browser, expectedTargetUrl) => {
        calls.push(['guard', expectedTargetUrl]);
        return { close: () => calls.push(['guard_close']) };
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
      target_date: targetDate,
      lease_kind: 'binding_identity',
      access_mode: 'read_only',
    };

    const wrongBuild = await fetch(`${base}/v1/profile-lease/open`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify({
        ...body,
        expected_gateway_build_sha256: '0'.repeat(64),
      }),
    });
    assert.equal(wrongBuild.status, 409);
    assert.equal(
      (await wrongBuild.json()).reason,
      'cloud_browser_gateway_build_mismatch',
    );
    assert.equal(calls.length, 0);

    const unauthorized = await fetch(`${base}/v1/profile-lease/open`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(body),
    });
    assert.equal(unauthorized.status, 401);
    assert.equal(calls.length, 0);

    const openedResponse = await fetch(`${base}/v1/profile-lease/open`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify(body),
    });
    assert.equal(openedResponse.status, 201);
    const opened = await openedResponse.json();
    assert.equal(opened.status, 'profile_lease_open');
    assert.equal(opened.browser_started, true);
    assert.equal(opened.profile_restored, true);
    assert.equal(opened.read_only_enforced, true);
    assert.equal(opened.session_owner, 'gateway_profile_lease');
    assert.equal(opened.external_browser_required, false);
    assert.equal(opened.user_browser_closed, false);
    assert.equal('profile_path' in opened, false);
    assert.equal('cdp_url' in opened, false);
    assert.deepEqual(calls.map((entry) => entry[0]), [
      'bridge',
      'cdp_available',
      'start',
      'guard',
    ]);
    const startCall = calls.find((entry) => entry[0] === 'start');
    const guardCall = calls.find((entry) => entry[0] === 'guard');
    assert.match(startCall[2], /^about:blank#suxios-cbpl_[A-Za-z0-9_-]+$/);
    assert.equal(guardCall[1], startCall[2]);

    const activeHealth = await fetch(`${base}/health`).then((response) => response.json());
    assert.equal(activeHealth.active_profile_leases, 1);
    assert.equal(activeHealth.active_browser_sessions, 1);
    assert.equal(activeHealth.profile_lease_contract, 'dingdandao_profile_lease.v1');

    const closeResponse = await fetch(`${base}/v1/profile-lease/close`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify({
        profile_lease_id: opened.profile_lease_id,
        profile_id: body.profile_id,
        platform: body.platform,
        outcome: 'completed',
      }),
    });
    assert.equal(closeResponse.status, 200);
    const closed = await closeResponse.json();
    assert.equal(closed.status, 'profile_lease_closed');
    assert.equal(closed.owned_browser_closed, true);
    assert.equal(closed.profile_encrypted_at_rest, true);
    assert.equal(closed.user_browser_closed, false);
    assert.equal(closed.sensitive_values_exposed, false);
    assert.deepEqual(calls.map((entry) => entry[0]), [
      'bridge',
      'cdp_available',
      'start',
      'guard',
      'guard_close',
      'stop',
    ]);
    const finalHealth = await fetch(`${base}/health`).then((response) => response.json());
    assert.equal(finalHealth.active_profile_leases, 0);
    assert.equal(finalHealth.active_browser_sessions, 0);
  } finally {
    if (server) await new Promise((resolvePromise) => server.close(resolvePromise));
    await rm(root, { recursive: true, force: true });
  }
});

test('binding lease activates only from its sealed receipt and retries without a second close', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-binding-receipt-gate-'));
  let server;
  let activationCalls = 0;
  let stopCalls = 0;
  try {
    const keyFile = join(root, 'profile.key');
    const tokenFile = join(root, 'control.token');
    const receiptPath = join(root, 'receipts', 'chain.jsonl');
    const token = 't'.repeat(48);
    const now = new Date('2026-07-27T02:00:00.000Z');
    const targetDate = shanghaiToday(now);
    const fingerprint = 'a'.repeat(64);
    await writeFile(keyFile, randomBytes(32));
    await writeFile(tokenFile, token);
    const gateway = await createGateway({
      SUXIOS_CLOUD_BROWSER_BIND: '127.0.0.1',
      SUXIOS_CLOUD_BROWSER_PORT: '0',
      SUXIOS_CLOUD_BROWSER_PROFILE_KEY_FILE: keyFile,
      SUXIOS_CLOUD_BROWSER_CONTROL_TOKEN_FILE: tokenFile,
      SUXIOS_CLOUD_BROWSER_ENCRYPTED_ROOT: join(root, 'profiles'),
      SUXIOS_CLOUD_BROWSER_RUNTIME_ROOT: join(root, 'runtime'),
      SUXIOS_CLOUD_BROWSER_RECEIPT_CHAIN: receiptPath,
      SUXIOS_CLOUD_BROWSER_COLLECTION_TTL_SECONDS: '60',
    }, {
      now: () => now,
      bridge: async (action, payload) => {
        if (action === 'validate_dingdandao_binding_lease') {
          return {
            status: 'ready_for_identity_probe',
            profile_id: payload.profile_id,
            tenant_id: payload.tenant_id,
            hotel_id: payload.hotel_id,
            owner_user_id: payload.owner_user_id,
            provider: 'dingdandao',
            source_scope: 'current_authenticated_session_identity_only',
            binding_persisted: false,
          };
        }
        if (action === 'activate_dingdandao_binding') {
          activationCalls += 1;
          const records = (await readFile(receiptPath, 'utf8'))
            .trim()
            .split(/\r?\n/)
            .map((line) => JSON.parse(line));
          const receipt = records.at(-1);
          assert.equal(receipt.kind, 'profile_lease_closed');
          assert.equal(receipt.receipt_id, payload.receipt_id);
          assert.equal(receipt.receipt_hash, payload.receipt_hash);
          assert.equal(receipt.payload.activation_requested, true);
          assert.equal(
            receipt.payload.provider_hotel_id_fingerprint,
            fingerprint,
          );
          if (activationCalls === 1) {
            throw new Error('simulated_bridge_activation_failure');
          }
          return {
            profile_id: payload.profile_id,
            profile_lease_id: payload.profile_lease_id,
            receipt_id: payload.receipt_id,
            receipt_hash: payload.receipt_hash,
            profile_authorization_status: 'ready_to_collect',
            profile_ready_after_binding: true,
            receipt_verified: true,
            sensitive_values_exposed: false,
          };
        }
        throw new Error('unexpected_bridge_action');
      },
      assertCdpPortAvailable: async () => undefined,
      startBrowser: async () => ({ exitCode: null }),
      stopBrowser: async () => {
        stopCalls += 1;
      },
      installReadOnlyPolicy: async () => ({ close() {} }),
    });
    server = gateway.server;
    await new Promise((resolvePromise, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolvePromise);
    });
    const base = `http://127.0.0.1:${server.address().port}`;
    const scope = {
      profile_id: 'cbp_activation_profile_123456789',
      platform: 'dingdandao',
      tenant_id: 1,
      hotel_id: 5,
      owner_user_id: 7,
      target_date: targetDate,
      lease_kind: 'binding_identity',
      access_mode: 'read_only',
    };
    const openedResponse = await fetch(`${base}/v1/profile-lease/open`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify(scope),
    });
    assert.equal(openedResponse.status, 201);
    const opened = await openedResponse.json();
    const closeBody = {
      profile_lease_id: opened.profile_lease_id,
      profile_id: scope.profile_id,
      platform: scope.platform,
      outcome: 'completed',
      activate_binding: true,
      provider_hotel_id_fingerprint: fingerprint,
    };

    const failed = await fetch(`${base}/v1/profile-lease/close`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify(closeBody),
    });
    assert.equal(failed.status, 500);
    assert.equal(
      (await failed.json()).reason,
      'profile_lease_binding_activation_failed',
    );
    const retained = await fetch(`${base}/health`).then(
      (response) => response.json(),
    );
    assert.equal(retained.active_profile_leases, 1);
    assert.equal(retained.active_browser_sessions, 0);
    assert.equal(stopCalls, 1);

    const recovered = await fetch(`${base}/v1/profile-lease/close`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify(closeBody),
    });
    assert.equal(recovered.status, 200);
    const closed = await recovered.json();
    assert.equal(closed.binding_activated, true);
    assert.equal(closed.receipt_verified, true);
    assert.equal(closed.profile_authorization_status, 'ready_to_collect');
    assert.equal(activationCalls, 2);
    assert.equal(stopCalls, 1);
    const records = (await readFile(receiptPath, 'utf8'))
      .trim()
      .split(/\r?\n/)
      .map((line) => JSON.parse(line));
    assert.equal(
      records.filter((record) => record.kind === 'profile_lease_closed')
        .length,
      1,
    );
    const health = await fetch(`${base}/health`).then(
      (response) => response.json(),
    );
    assert.equal(health.active_profile_leases, 0);
  } finally {
    if (server) await new Promise((resolvePromise) => server.close(resolvePromise));
    await rm(root, { recursive: true, force: true });
  }
});

test('Profile lease timeout wins a concurrent explicit close exactly once and never activates', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-profile-lease-race-'));
  let server;
  let timeoutCallback;
  let stopCalls = 0;
  let activationCalls = 0;
  let releaseStop;
  let stopStartedResolve;
  const stopGate = new Promise((resolvePromise) => {
    releaseStop = resolvePromise;
  });
  const stopStarted = new Promise((resolvePromise) => {
    stopStartedResolve = resolvePromise;
  });
  try {
    const keyFile = join(root, 'profile.key');
    const tokenFile = join(root, 'control.token');
    const receiptPath = join(root, 'receipts', 'chain.jsonl');
    const token = 't'.repeat(48);
    const now = new Date('2026-07-27T02:00:00.000Z');
    await writeFile(keyFile, randomBytes(32));
    await writeFile(tokenFile, token);
    const gateway = await createGateway({
      SUXIOS_CLOUD_BROWSER_BIND: '127.0.0.1',
      SUXIOS_CLOUD_BROWSER_PORT: '0',
      SUXIOS_CLOUD_BROWSER_PROFILE_KEY_FILE: keyFile,
      SUXIOS_CLOUD_BROWSER_CONTROL_TOKEN_FILE: tokenFile,
      SUXIOS_CLOUD_BROWSER_ENCRYPTED_ROOT: join(root, 'profiles'),
      SUXIOS_CLOUD_BROWSER_RUNTIME_ROOT: join(root, 'runtime'),
      SUXIOS_CLOUD_BROWSER_RECEIPT_CHAIN: receiptPath,
      SUXIOS_CLOUD_BROWSER_COLLECTION_TTL_SECONDS: '60',
    }, {
      now: () => now,
      bridge: async (action, payload) => {
        if (action === 'validate_dingdandao_binding_lease') {
          return {
            status: 'ready_for_identity_probe',
            profile_id: payload.profile_id,
            tenant_id: payload.tenant_id,
            hotel_id: payload.hotel_id,
            owner_user_id: payload.owner_user_id,
            provider: 'dingdandao',
            source_scope: 'current_authenticated_session_identity_only',
            binding_persisted: false,
          };
        }
        if (action === 'activate_dingdandao_binding') {
          activationCalls += 1;
        }
        throw new Error('unexpected_bridge_action');
      },
      assertCdpPortAvailable: async () => undefined,
      startBrowser: async () => ({ exitCode: null }),
      stopBrowser: async () => {
        stopCalls += 1;
        stopStartedResolve();
        await stopGate;
      },
      installReadOnlyPolicy: async () => ({ close() {} }),
      setProfileLeaseTimeout: (callback) => {
        timeoutCallback = callback;
        return { unref() {} };
      },
      clearProfileLeaseTimeout: () => undefined,
    });
    server = gateway.server;
    await new Promise((resolvePromise, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolvePromise);
    });
    const base = `http://127.0.0.1:${server.address().port}`;
    const scope = {
      profile_id: 'cbp_profile_lease_race_123456',
      platform: 'dingdandao',
      tenant_id: 1,
      hotel_id: 5,
      owner_user_id: 7,
      target_date: shanghaiToday(now),
      lease_kind: 'binding_identity',
      access_mode: 'read_only',
    };
    const openedResponse = await fetch(`${base}/v1/profile-lease/open`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify(scope),
    });
    assert.equal(openedResponse.status, 201);
    const opened = await openedResponse.json();
    const expiry = timeoutCallback();
    await stopStarted;
    const closeRequest = fetch(`${base}/v1/profile-lease/close`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify({
        profile_lease_id: opened.profile_lease_id,
        profile_id: scope.profile_id,
        platform: scope.platform,
        outcome: 'completed',
        activate_binding: true,
        provider_hotel_id_fingerprint: 'a'.repeat(64),
      }),
    });
    await new Promise((resolvePromise) => setTimeout(resolvePromise, 20));
    releaseStop();
    await expiry;
    const closeResponse = await closeRequest;
    assert.equal(closeResponse.status, 409);
    assert.equal(
      (await closeResponse.json()).reason,
      'profile_lease_already_finalized',
    );
    assert.equal(stopCalls, 1);
    assert.equal(activationCalls, 0);
    const records = await gateway.receipts.records();
    assert.equal(
      records.filter((record) => record.kind === 'profile_lease_timeout')
        .length,
      1,
    );
    assert.equal(
      records.filter((record) => record.kind === 'profile_lease_closed')
        .length,
      0,
    );
    const health = await fetch(`${base}/health`).then(
      (response) => response.json(),
    );
    assert.equal(health.active_profile_leases, 0);
    assert.equal(health.active_browser_sessions, 0);
  } finally {
    releaseStop?.();
    if (server) await new Promise((resolvePromise) => server.close(resolvePromise));
    await rm(root, { recursive: true, force: true });
  }
});

test('Profile lease rejects an occupied external CDP without touching that browser', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-dingdandao-cdp-busy-'));
  let server;
  let externalCdp;
  let startCalls = 0;
  let stopCalls = 0;
  let guardCalls = 0;
  try {
    const keyFile = join(root, 'profile.key');
    const tokenFile = join(root, 'control.token');
    const token = 't'.repeat(48);
    await writeFile(keyFile, randomBytes(32));
    await writeFile(tokenFile, token);
    const now = new Date('2026-07-27T02:00:00.000Z');
    externalCdp = createHttpServer((request, response) => {
      if (request.url !== '/json/version') {
        response.writeHead(404).end();
        return;
      }
      response.writeHead(200, { 'content-type': 'application/json' });
      response.end(JSON.stringify({
        webSocketDebuggerUrl: 'ws://127.0.0.1/external-browser',
      }));
    });
    await new Promise((resolvePromise, reject) => {
      externalCdp.once('error', reject);
      externalCdp.listen(0, '127.0.0.1', resolvePromise);
    });
    const externalAddress = externalCdp.address();
    const gateway = await createGateway({
      SUXIOS_CLOUD_BROWSER_BIND: '127.0.0.1',
      SUXIOS_CLOUD_BROWSER_PORT: '0',
      SUXIOS_CLOUD_BROWSER_CDP_PORT: String(externalAddress.port),
      SUXIOS_CLOUD_BROWSER_PROFILE_KEY_FILE: keyFile,
      SUXIOS_CLOUD_BROWSER_CONTROL_TOKEN_FILE: tokenFile,
      SUXIOS_CLOUD_BROWSER_ENCRYPTED_ROOT: join(root, 'profiles'),
      SUXIOS_CLOUD_BROWSER_RUNTIME_ROOT: join(root, 'runtime'),
      SUXIOS_CLOUD_BROWSER_RECEIPT_CHAIN: join(root, 'receipts', 'chain.jsonl'),
    }, {
      now: () => now,
      bridge: async (_action, payload) => ({
        status: 'ready_for_identity_probe',
        profile_id: payload.profile_id,
        tenant_id: payload.tenant_id,
        hotel_id: payload.hotel_id,
        owner_user_id: payload.owner_user_id,
        provider: 'dingdandao',
        binding_persisted: false,
      }),
      startBrowser: async () => {
        startCalls += 1;
        return { exitCode: null };
      },
      stopBrowser: async () => {
        stopCalls += 1;
      },
      installReadOnlyPolicy: async () => {
        guardCalls += 1;
        return { close() {} };
      },
    });
    server = gateway.server;
    await new Promise((resolvePromise, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolvePromise);
    });
    const address = server.address();
    const response = await fetch(
      `http://127.0.0.1:${address.port}/v1/profile-lease/open`,
      {
        method: 'POST',
        headers: {
          authorization: `Bearer ${token}`,
          'content-type': 'application/json',
        },
        body: JSON.stringify({
          profile_id: 'cbp_abcdefghijklmnop',
          platform: 'dingdandao',
          tenant_id: 8,
          hotel_id: 5,
          owner_user_id: 7,
          target_date: shanghaiToday(now),
          lease_kind: 'binding_identity',
          access_mode: 'read_only',
        }),
      },
    );
    assert.equal(response.status, 422);
    assert.equal((await response.json()).reason, 'browser_cdp_port_busy');
    assert.equal(startCalls, 0);
    assert.equal(stopCalls, 0);
    assert.equal(guardCalls, 0);
    const health = await fetch(
      `http://127.0.0.1:${address.port}/health`,
    ).then((item) => item.json());
    assert.equal(health.active_profile_leases, 0);
    assert.equal(health.active_browser_sessions, 0);
  } finally {
    if (server) await new Promise((resolvePromise) => server.close(resolvePromise));
    if (externalCdp) {
      await new Promise((resolvePromise) => externalCdp.close(resolvePromise));
    }
    await rm(root, { recursive: true, force: true });
  }
});

test('Profile lease quarantines its runtime when owned-browser stop is unconfirmed', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-dingdandao-stop-failed-'));
  let server;
  let startCalls = 0;
  try {
    const keyFile = join(root, 'profile.key');
    const tokenFile = join(root, 'control.token');
    const token = 't'.repeat(48);
    const now = new Date('2026-07-27T02:00:00.000Z');
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
      now: () => now,
      bridge: async (_action, payload) => ({
        status: 'ready_for_identity_probe',
        profile_id: payload.profile_id,
        tenant_id: payload.tenant_id,
        hotel_id: payload.hotel_id,
        owner_user_id: payload.owner_user_id,
        provider: 'dingdandao',
        binding_persisted: false,
      }),
      assertCdpPortAvailable: async () => {},
      startBrowser: async () => {
        startCalls += 1;
        return { exitCode: null };
      },
      stopBrowser: async () => {
        throw new Error('browser_stop_unconfirmed');
      },
      installReadOnlyPolicy: async () => ({ close() {} }),
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
      target_date: shanghaiToday(now),
      lease_kind: 'binding_identity',
      access_mode: 'read_only',
    };
    const opened = await fetch(`${base}/v1/profile-lease/open`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify(body),
    }).then((response) => response.json());
    const closeResponse = await fetch(`${base}/v1/profile-lease/close`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify({
        profile_lease_id: opened.profile_lease_id,
        profile_id: body.profile_id,
        platform: body.platform,
        outcome: 'completed',
      }),
    });
    assert.equal(closeResponse.status, 500);
    assert.equal(
      (await closeResponse.json()).reason,
      'profile_lease_close_failed',
    );
    await access(join(root, 'runtime', body.profile_id));
    const health = await fetch(`${base}/health`).then((response) => response.json());
    assert.equal(health.active_profile_leases, 1);
    assert.equal(health.active_browser_sessions, 1);
    const secondOpen = await fetch(`${base}/v1/profile-lease/open`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify(body),
    });
    assert.equal(secondOpen.status, 409);
    assert.equal(startCalls, 1);
  } finally {
    if (server) await new Promise((resolvePromise) => server.close(resolvePromise));
    await rm(root, { recursive: true, force: true });
  }
});

test('protected Dingdandao collection runs only inside a gateway-owned Profile lease', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-dingdandao-window-'));
  let server;
  const calls = [];
  try {
    const keyFile = join(root, 'profile.key');
    const tokenFile = join(root, 'control.token');
    const token = 't'.repeat(48);
    await writeFile(keyFile, randomBytes(32));
    await writeFile(tokenFile, token);
    const now = new Date('2026-07-27T02:00:00.000Z');
    const targetDate = shanghaiToday(now);
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
      now: () => now,
      bridge: async (action, payload) => {
        calls.push(['bridge', action, payload]);
        if (action === 'validate_dingdandao_collection') return {
          validated: true,
          target_date: payload.target_date,
          tenant_id: payload.tenant_id,
          hotel_id: payload.hotel_id,
          owner_user_id: payload.owner_user_id,
          access_mode: 'read_only',
          profile: {
            profile_id: payload.profile_id,
            platform: 'dingdandao',
          },
        };
        if (action === 'claim_dingdandao_collection') return {
          claimed: true,
          claim_status: 'recorded',
          claim_id: 'cct_abcdefghijklmnop',
          collection_session_id: payload.collection_session_id,
          profile_id: payload.profile_id,
          platform: 'dingdandao',
          collection_kind: 'operating_target_today',
          access_mode: 'read_only',
          source_scope: 'today_only',
          target_date: payload.target_date,
          window_expires_at: payload.window_expires_at,
          provider_hotel_name: '\u6566\u714c\u6f20\u84dd',
          tenant_id: payload.tenant_id,
          hotel_id: payload.hotel_id,
          owner_user_id: payload.owner_user_id,
          lifecycle_status: 'open',
          data_status: 'unverified',
        };
        if (action === 'complete_dingdandao_collection') return {
          completed: true,
          claim_id: payload.claim_id,
          collection_session_id: payload.collection_session_id,
          profile_id: payload.profile_id,
          outcome: payload.outcome,
          lifecycle_status: 'closed',
          data_status: 'unverified',
        };
        throw new Error('unexpected_bridge_action');
      },
      assertCdpPortAvailable: async () => {
        calls.push(['cdp_available']);
      },
      startBrowser: async (_config, profilePath, platform, startUrl) => {
        calls.push(['start', platform, startUrl, profilePath.endsWith('cbp_abcdefghijklmnop')]);
        return { exitCode: null };
      },
      stopBrowser: async () => {
        calls.push(['stop']);
      },
      installReadOnlyPolicy: async (_config, _browser, expectedTargetUrl) => {
        calls.push(['guard', expectedTargetUrl]);
        return { close: () => calls.push(['guard_close']) };
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
      target_date: targetDate,
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

    const missingLease = await fetch(`${base}/v1/collection/open`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify(body),
    });
    assert.equal(missingLease.status, 409);
    assert.equal((await missingLease.json()).reason, 'gateway_profile_lease_required');
    assert.equal(calls.length, 0);

    const leaseResponse = await fetch(`${base}/v1/profile-lease/open`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify({
        profile_id: body.profile_id,
        platform: body.platform,
        tenant_id: body.tenant_id,
        hotel_id: body.hotel_id,
        owner_user_id: body.owner_user_id,
        target_date: body.target_date,
        lease_kind: 'daily_collection',
        access_mode: 'read_only',
      }),
    });
    assert.equal(leaseResponse.status, 201);
    const lease = await leaseResponse.json();
    assert.equal(lease.status, 'profile_lease_open');
    assert.equal(lease.browser_started, true);
    assert.equal(lease.external_browser_required, false);
    assert.deepEqual(calls.map((entry) => entry[0]), [
      'bridge',
      'cdp_available',
      'start',
      'guard',
    ]);

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
    assert.equal(opened.claim_id, 'cct_abcdefghijklmnop');
    assert.equal(opened.read_only_enforced, true);
    assert.equal(opened.access_mode, 'read_only');
    assert.equal(opened.browser_started, false);
    assert.equal(opened.collection_transport, 'existing_session_direct_post');
    assert.equal(opened.existing_session_required, true);
    assert.equal(opened.profile_mutated, false);
    assert.equal(opened.profile_lease_id, lease.profile_lease_id);
    assert.equal(opened.session_owner, 'gateway_profile_lease');
    assert.equal(opened.external_browser_required, false);
    assert.equal('viewer_url' in opened, false);
    assert.equal('cdp_url' in opened, false);
    assert.equal('profile_path' in opened, false);
    assert.deepEqual(calls.map((entry) => entry[0]), [
      'bridge',
      'cdp_available',
      'start',
      'guard',
      'bridge',
    ]);
    const claim = calls.find(
      (entry) => entry[0] === 'bridge' && entry[1] === 'claim_dingdandao_collection',
    );
    assert.ok(claim);
    assert.match(claim[2].collection_session_id, /^cbcs_/);
    assert.equal(claim[2].window_expires_at, opened.expires_at);

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
    assert.equal(activeHealth.active_profile_leases, 1);
    assert.equal(activeHealth.active_browser_sessions, 1);

    const unauthorizedClose = await fetch(`${base}/v1/collection/close`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        collection_session_id: opened.collection_session_id,
        profile_id: body.profile_id,
        platform: body.platform,
        outcome: 'completed',
      }),
    });
    assert.equal(unauthorizedClose.status, 401);
    assert.equal(
      calls.some(
        (entry) => entry[0] === 'bridge' && entry[1] === 'complete_dingdandao_collection',
      ),
      false,
    );

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
    assert.equal(closed.existing_browser_closed, false);
    assert.equal(closed.profile_mutated, false);
    assert.equal(closed.data_status, 'unverified');
    assert.equal(calls.filter((entry) => entry[0] === 'start').length, 1);
    assert.equal(calls.some((entry) => entry[0] === 'stop'), false);
    assert.equal(calls.filter((entry) => entry[0] === 'guard').length, 1);
    const completion = calls.find(
      (entry) => entry[0] === 'bridge' && entry[1] === 'complete_dingdandao_collection',
    );
    assert.ok(completion);
    assert.deepEqual(Object.keys(completion[2]).sort(), [
      'claim_id',
      'collection_session_id',
      'outcome',
      'profile_id',
    ]);
    assert.equal(completion[2].outcome, 'completed');
    const leaseCloseResponse = await fetch(`${base}/v1/profile-lease/close`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify({
        profile_lease_id: lease.profile_lease_id,
        profile_id: body.profile_id,
        platform: body.platform,
        outcome: 'completed',
      }),
    });
    assert.equal(leaseCloseResponse.status, 200);
    const leaseClosed = await leaseCloseResponse.json();
    assert.equal(leaseClosed.owned_browser_closed, true);
    assert.equal(leaseClosed.user_browser_closed, false);
    assert.equal(calls.filter((entry) => entry[0] === 'stop').length, 1);
    assert.equal(calls.filter((entry) => entry[0] === 'guard_close').length, 1);
    const finalHealth = await fetch(`${base}/health`).then((response) => response.json());
    assert.equal(finalHealth.active_profile_leases, 0);
    assert.equal(finalHealth.active_browser_sessions, 0);

    const otaReceipt = await fetch(`${base}/v1/collection/receipt`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify({
        task_id: 'cct_abcdefgh',
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

test('Dingdandao collection rejects every non-today scope before server-side claim', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-dingdandao-history-block-'));
  let server;
  let bridgeCalled = false;
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
    }, {
      now: () => new Date('2026-07-27T02:00:00.000Z'),
      bridge: async () => {
        bridgeCalled = true;
        throw new Error('bridge_must_not_run');
      },
    });
    server = gateway.server;
    await new Promise((resolvePromise, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolvePromise);
    });
    const address = server.address();
    const response = await fetch(`http://127.0.0.1:${address.port}/v1/collection/open`, {
      method: 'POST',
      headers: {
        authorization: `Bearer ${token}`,
        'content-type': 'application/json',
      },
      body: JSON.stringify({
        profile_id: 'cbp_abcdefghijklmnop',
        platform: 'dingdandao',
        tenant_id: 8,
        hotel_id: 5,
        owner_user_id: 7,
        target_date: '2026-07-26',
        collection_kind: 'operating_target_today',
        access_mode: 'read_only',
      }),
    });
    assert.equal(response.status, 422);
    assert.equal((await response.json()).reason, 'collection_target_date_not_today');
    assert.equal(bridgeCalled, false);
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

test('Profile vault keeps failed seal runtime quarantined instead of deleting recovery state', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-cloud-vault-quarantine-'));
  try {
    const vault = new EncryptedProfileVault({
      encryptedRoot: join(root, 'persistent'),
      runtimeRoot: join(root, 'runtime'),
      key: randomBytes(32),
      tarBinary: 'suxios-definitely-missing-tar',
    });
    const profileId = 'cbp_abcdefghijklmnop';
    const runtimeProfile = await vault.restore(profileId);
    const marker = join(runtimeProfile, 'Preferences');
    await writeFile(marker, '{"recovery":true}');
    await assert.rejects(vault.seal(profileId));
    assert.equal(await readFile(marker, 'utf8'), '{"recovery":true}');
    await assert.rejects(
      vault.restore(profileId),
      /profile_runtime_quarantine_present/,
    );
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test('Profile vault never treats a restore tool failure as a new empty Profile', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-cloud-vault-restore-failure-'));
  try {
    const key = randomBytes(32);
    const profileId = 'cbp_abcdefghijklmnop';
    const encryptedRoot = join(root, 'persistent');
    const runtimeRoot = join(root, 'runtime');
    const workingVault = new EncryptedProfileVault({
      encryptedRoot,
      runtimeRoot,
      key,
    });
    const initialRuntime = await workingVault.restore(profileId);
    await writeFile(join(initialRuntime, 'Preferences'), '{"authenticated":true}');
    const encryptedPath = await workingVault.seal(profileId);
    const encryptedBefore = await readFile(encryptedPath);

    const brokenRestoreVault = new EncryptedProfileVault({
      encryptedRoot,
      runtimeRoot,
      key,
      tarBinary: 'suxios-definitely-missing-tar',
    });
    await assert.rejects(brokenRestoreVault.restore(profileId));
    await access(join(runtimeRoot, profileId));
    assert.deepEqual(await readFile(encryptedPath), encryptedBefore);
    await assert.rejects(
      brokenRestoreVault.restore(profileId),
      /profile_runtime_quarantine_present/,
    );
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
    const [onceA, onceB] = await Promise.all([
      chain.appendOnce(
        'profile_lease_closed',
        (record) => record?.payload?.profile_lease_id === 'cbpl_once_1234567890123456',
        {
          profile_lease_id: 'cbpl_once_1234567890123456',
          profile_id: 'cbp_abcdefghijklmnop',
        },
      ),
      chain.appendOnce(
        'profile_lease_closed',
        (record) => record?.payload?.profile_lease_id === 'cbpl_once_1234567890123456',
        {
          profile_lease_id: 'cbpl_once_1234567890123456',
          profile_id: 'cbp_abcdefghijklmnop',
        },
      ),
    ]);
    assert.equal(onceA.receipt_id, onceB.receipt_id);
    assert.equal(
      (await chain.records()).filter(
        (record) => record.kind === 'profile_lease_closed',
      ).length,
      1,
    );
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
  const [
    gateway,
    installer,
    verifier,
    gatewayUnit,
    novncUnit,
    vncUnit,
    envExample,
    bridge,
    loginStatusCli,
    loginOpenCli,
    loginCompleteCli,
    bindingBootstrapCli,
  ] =
    await Promise.all([
      readFile(new URL('../../deploy/remote-browser/cloud_browser_gateway.mjs', import.meta.url), 'utf8'),
      readFile(new URL('../../deploy/remote-browser/install_secure_remote_browser.sh', import.meta.url), 'utf8'),
      readFile(new URL('../../deploy/remote-browser/verify_secure_remote_browser.sh', import.meta.url), 'utf8'),
      readFile(new URL('../../deploy/remote-browser/systemd/suxios-cloud-browser-gateway.service', import.meta.url), 'utf8'),
      readFile(new URL('../../deploy/remote-browser/systemd/suxios-cloud-browser-novnc.service', import.meta.url), 'utf8'),
      readFile(new URL('../../deploy/remote-browser/systemd/suxios-cloud-browser-vnc.service', import.meta.url), 'utf8'),
      readFile(new URL('../../deploy/remote-browser/gateway.env.example', import.meta.url), 'utf8'),
      readFile(new URL('../../scripts/cloud_browser_gateway_bridge.php', import.meta.url), 'utf8'),
      readFile(new URL('../../scripts/status_cloud_browser_login.php', import.meta.url), 'utf8'),
      readFile(new URL('../../scripts/open_cloud_browser_login.php', import.meta.url), 'utf8'),
      readFile(new URL('../../scripts/complete_cloud_browser_login.php', import.meta.url), 'utf8'),
      readFile(new URL('../../scripts/run_dingdandao_binding_bootstrap.php', import.meta.url), 'utf8'),
    ]);

  assert.match(gateway, /bindAddress !== '127\.0\.0\.1'/);
  assert.match(gateway, /--remote-debugging-address=127\.0\.0\.1/);
  assert.match(gateway, /--lang=zh-CN/);
  assert.match(gateway, /--accept-lang=zh-CN,zh;q=0\.9/);
  assert.match(gateway, /LANG: 'zh_CN\.UTF-8'/);
  assert.match(gateway, /browser_autostart: false/);
  assert.match(
    gateway,
    /GATEWAY_PROTOCOL_VERSION = 'suxios_cloud_browser_gateway\.v2'/,
  );
  assert.match(gateway, /runExclusiveLoginFinalization/);
  assert.match(gateway, /waitForBrowserPageCall\(config, browser, null\)/);
  assert.match(gateway, /dingdandao: DINGDANDAO_SOURCE_URL/);
  assert.match(gateway, /claim_dingdandao_collection/);
  assert.match(gateway, /complete_dingdandao_collection/);
  assert.match(gateway, /collection_transport: 'existing_session_direct_post'/);
  assert.match(gateway, /existing_session_required: true/);
  assert.match(gateway, /profile_mutated: false/);
  assert.match(gateway, /profile_lease_contract: 'dingdandao_profile_lease\.v1'/);
  assert.match(gateway, /browser_cdp_port_busy/);
  assert.match(gateway, /browser_stop_unconfirmed/);
  assert.match(gateway, /profile_runtime_quarantine_present/);
  assert.match(gateway, /profile_lease_start_cleanup_failed/);
  assert.match(
    gateway,
    /finalizeCollectionSession\([\s\S]{0,180}'window_expired'[\s\S]{0,100}'collection_window_timeout'/,
  );
  assert.match(gateway, /read_only_enforced: true/);
  assert.match(gateway, /url\.pathname === '\/v1\/collection\/open'[\s\S]{0,220}!authorized\(request, controlToken\)/);
  assert.match(gateway, /url\.pathname === '\/v1\/collection\/close'[\s\S]{0,220}!authorized\(request, controlToken\)/);
  assert.match(gateway, /sessions\.size > 0[\s\S]{0,120}gateway_login_capacity_busy/);
  assert.match(gateway, /validate_login/);
  assert.match(gateway, /verify_dingdandao_login_identity/);
  assert.match(gateway, /complete_login/);
  assert.match(gateway, /expire_login/);
  assert.match(gateway, /login_open_failed/);
  assert.match(gateway, /login_timeout_failed_closed/);
  assert.match(gateway, /url\.pathname === '\/v1\/login\/status'[\s\S]{0,220}!authorized\(request, controlToken\)/);
  assert.match(gateway, /url\.pathname === '\/v1\/login\/complete'[\s\S]{0,220}!authorized\(request, controlToken\)/);
  assert.match(gateway, /receipt_chain_integrity_failed/);
  assert.match(gateway, /receipt_truth_gate_failed/);
  assert.match(gateway, /active_release_build_match/);
  assert.match(gateway, /expected_gateway_build_sha256/);
  assert.match(installer, /Chromium was not started/);
  assert.match(installer, /\/snap\/bin\/chromium/);
  assert.match(installer, /--experimental-websocket/);
  assert.doesNotMatch(installer, /suxios-cloud-browser-chromium\.service/);
  assert.match(verifier, /CDP is listening before a short-lived login session was opened/);
  assert.match(verifier, /read_only_policy_runtime/);
  assert.match(verifier, /sha256sum "\$release_gateway"/);
  assert.match(
    verifier,
    /protocol_version"\] \?\? ""\)[\s\S]{0,80}suxios_cloud_browser_gateway\.v2/,
  );
  assert.match(gatewayUnit, /LoadCredential=profile-master-key/);
  assert.match(gatewayUnit, /LoadCredential=control-token/);
  assert.match(gatewayUnit, /SUXIOS_CLOUD_BROWSER_ACTIVE_GATEWAY_PATH/);
  assert.match(gatewayUnit, /ExecStartPre=\/usr\/bin\/cmp --silent/);
  assert.match(gatewayUnit, /ExecStart=@NODE_BIN@ --experimental-websocket/);
  assert.match(novncUnit, /127\.0\.0\.1:6080/);
  assert.match(vncUnit, /-listen 127\.0\.0\.1/);
  assert.match(envExample, /^SUXIOS_CLOUD_BROWSER_BIND=127\.0\.0\.1$/m);
  assert.match(bridge, /MAX_GATEWAY_INPUT_BYTES = 8192/);
  assert.match(bridge, /loginIdentityScope/);
  assert.match(bridge, /dingdandao_binding_probe\.mjs/);
  assert.match(bridge, /activate_dingdandao_binding/);
  assert.match(loginStatusCli, /\/v1\/login\/status/);
  assert.match(
    loginStatusCli,
    /CLOUD_BROWSER_GATEWAY_PROTOCOL_VERSION/,
  );
  assert.match(
    loginOpenCli,
    /cloud_browser_gateway_build_mismatch/,
  );
  assert.match(
    loginCompleteCli,
    /run_verified_hotel_binding_bootstrap/,
  );
  assert.match(
    bindingBootstrapCli,
    /dingdandao_binding_gateway_build_mismatch/,
  );
  assert.match(loginStatusCli, /sensitive_values_exposed/);
  assert.doesNotMatch(bridge, /argv.*ticket/i);
});

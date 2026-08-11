import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { access, appendFile, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
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
  assert.equal(isCloudProfileReadOnlyRequestAllowed({
    platform: 'meituan',
    url: 'https://eb.meituan.com/data-center',
    method: 'GET',
    resourceType: 'Document',
  }), true);
  for (const method of ['PUT', 'PATCH', 'DELETE']) {
    assert.equal(isCloudProfileReadOnlyRequestAllowed({
      platform: 'meituan',
      url: 'https://eb.meituan.com/api/report/query',
      method,
      resourceType: 'XHR',
    }), false);
  }
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
      installReadOnlyPolicy: async () => {
        calls.push(['guard']);
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

test('protected Ctrip collection uses the OTA scope and gateway-owned CDP session', async () => {
  const root = await mkdtemp(join(tmpdir(), 'suxios-ctrip-window-'));
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
    }, {
      bridge: async (action, payload) => {
        calls.push(['bridge', action, payload.platform]);
        assert.equal(action, 'validate_ota_collection');
        return {
          validated: true,
          collection_kind: 'ota_target_date',
          access_mode: 'read_only',
          platform: payload.platform,
          target_date: payload.target_date,
          tenant_id: payload.tenant_id,
          hotel_id: payload.hotel_id,
          owner_user_id: payload.owner_user_id,
          source_scope: 'target_date_only',
          profile: {
            profile_id: payload.profile_id,
            platform: payload.platform,
          },
        };
      },
      startBrowser: async () => ({ exitCode: null }),
      stopBrowser: async () => calls.push(['stop']),
      installReadOnlyPolicy: async (_config, _browser, platform) => {
        calls.push(['guard', platform]);
        return { close: () => calls.push(['guard_close']) };
      },
    });
    server = gateway.server;
    await new Promise((resolvePromise, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolvePromise);
    });
    const base = `http://127.0.0.1:${server.address().port}`;
    const body = {
      profile_id: 'cbp_abcdefghijklmnop',
      platform: 'ctrip',
      tenant_id: 1,
      hotel_id: 5,
      owner_user_id: 1,
      target_date: '2026-07-27',
      collection_kind: 'ota_target_date',
      access_mode: 'read_only',
    };
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
    assert.equal(opened.collection_kind, 'ota_target_date');
    assert.equal(opened.profile_restored, true);
    assert.equal(opened.read_only_enforced, true);
    assert.equal(opened.session_owner, 'gateway_collection');
    assert.deepEqual(calls.slice(0, 2), [
      ['bridge', 'validate_ota_collection', 'ctrip'],
      ['guard', 'ctrip'],
    ]);

    const closedResponse = await fetch(`${base}/v1/collection/close`, {
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
    assert.equal(closedResponse.status, 200);
    const closed = await closedResponse.json();
    assert.equal(closed.profile_sealed, true);
    assert.equal(closed.sensitive_values_exposed, false);
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
    for (const name of ['SingletonCookie', 'SingletonLock', 'SingletonSocket', 'DevToolsActivePort']) {
      await writeFile(join(runtimeProfile, name), 'stale-runtime-marker');
    }
    const encryptedPath = await vault.seal(profileId);
    await assert.rejects(access(runtimeProfile));
    assert.equal(encryptedPath.endsWith('.tar.gz.enc'), true);
    assert.equal((await readFile(encryptedPath)).includes(Buffer.from('{"fixture":true}')), false);

    const restoredProfile = await vault.restore(profileId);
    assert.equal(await readFile(join(restoredProfile, 'Preferences'), 'utf8'), '{"fixture":true}');
    for (const name of ['SingletonCookie', 'SingletonLock', 'SingletonSocket', 'DevToolsActivePort']) {
      await assert.rejects(access(join(restoredProfile, name)));
    }
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
  assert.match(gateway, /read_only_enforced: true/);
  assert.match(gateway, /url\.pathname === '\/v1\/collection\/open'[\s\S]{0,220}!authorized\(request, controlToken\)/);
  assert.match(gateway, /url\.pathname === '\/v1\/collection\/close'[\s\S]{0,220}!authorized\(request, controlToken\)/);
  assert.match(gateway, /sessions\.size > 0[\s\S]{0,120}gateway_login_capacity_busy/);
  assert.match(gateway, /validate_login/);
  assert.match(gateway, /complete_login/);
  assert.match(gateway, /url\.pathname === '\/v1\/login\/complete'[\s\S]{0,220}!authorized\(request, controlToken\)/);
  assert.match(gateway, /receipt_chain_integrity_failed/);
  assert.match(gateway, /receipt_truth_gate_failed/);
  assert.match(installer, /Chromium was not started/);
  assert.match(installer, /\/snap\/bin\/chromium/);
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
  assert.match(bridge, /MAX_GATEWAY_INPUT_BYTES = 8192/);
  assert.doesNotMatch(bridge, /argv.*ticket/i);
});

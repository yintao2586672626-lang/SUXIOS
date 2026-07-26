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

test('protected Dingdandao collection validates scope without opening or closing the existing browser', async () => {
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
    assert.equal('viewer_url' in opened, false);
    assert.equal('cdp_url' in opened, false);
    assert.equal('profile_path' in opened, false);
    assert.deepEqual(calls.map((entry) => entry[0]), ['bridge']);
    assert.equal(calls[0][1], 'claim_dingdandao_collection');
    assert.match(calls[0][2].collection_session_id, /^cbcs_/);
    assert.equal(calls[0][2].window_expires_at, opened.expires_at);

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
    assert.equal(activeHealth.active_browser_sessions, 0);

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
    assert.equal(calls.some((entry) => entry[0] === 'start'), false);
    assert.equal(calls.some((entry) => entry[0] === 'stop'), false);
    assert.equal(calls.some((entry) => entry[0] === 'guard'), false);
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
  assert.match(gateway, /claim_dingdandao_collection/);
  assert.match(gateway, /complete_dingdandao_collection/);
  assert.match(gateway, /collection_transport: 'existing_session_direct_post'/);
  assert.match(gateway, /existing_session_required: true/);
  assert.match(gateway, /profile_mutated: false/);
  assert.match(
    gateway,
    /finalizeCollectionSession\([\s\S]{0,180}'window_expired'[\s\S]{0,100}'collection_window_timeout'/,
  );
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

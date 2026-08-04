import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm, utimes, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import {
  accountProfileDirectoryName,
  buildCaptureResultSummary,
  createLocalConnectServer,
  extractSanitizedRows,
  isTrustedLocalConnectOrigin,
  orderedSectionsForTask,
  pruneLocalResultFiles,
  sanitizeBusinessValue,
  sessionVerified,
} from '../../scripts/ota_local_collector.mjs';

const read = path => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

test('one account Profile key is stable across mapped hotels', () => {
  const key = 'a'.repeat(64);
  assert.equal(
    accountProfileDirectoryName('ctrip', key),
    accountProfileDirectoryName('ctrip', key),
  );
  assert.equal(accountProfileDirectoryName('ctrip', key), `ctrip_account_profile_${key}`);
  assert.notEqual(
    accountProfileDirectoryName('ctrip', key),
    accountProfileDirectoryName('ctrip', 'b'.repeat(64)),
  );
});

test('website can connect a running local collector without exposing its pairing proof', async () => {
  assert.equal(isTrustedLocalConnectOrigin('https://www.glslsuxi.cn', 'https://www.glslsuxi.cn'), true);
  assert.equal(isTrustedLocalConnectOrigin('https://other.example', 'https://www.glslsuxi.cn'), false);
  let received = null;
  const listener = createLocalConnectServer({
    server: 'https://www.glslsuxi.cn',
    pairDeviceFn: async input => {
      received = input;
      return { device_name: input.name, device_public_id: 'device_public_test' };
    },
  });
  await new Promise((resolveListen, rejectListen) => {
    listener.once('error', rejectListen);
    listener.listen(0, '127.0.0.1', resolveListen);
  });
  const port = listener.address().port;
  try {
    const blocked = await fetch(`http://127.0.0.1:${port}/connect`, {
      method: 'POST', headers: { Origin: 'https://other.example', 'Content-Type': 'application/json' },
      body: JSON.stringify({ server: 'https://other.example', pair_code: 'nope' }),
    });
    assert.equal(blocked.status, 403);
    const connected = await fetch(`http://127.0.0.1:${port}/connect`, {
      method: 'POST', headers: { Origin: 'https://www.glslsuxi.cn', 'Content-Type': 'application/json' },
      body: JSON.stringify({ server: 'https://www.glslsuxi.cn', pair_code: 'internal-short-lived-proof', device_name: '测试电脑' }),
    });
    assert.equal(connected.status, 200);
    const result = await connected.json();
    assert.deepEqual(result, { status: 'paired', device_name: '测试电脑', device_public_id: 'device_public_test' });
    assert.equal(JSON.stringify(result).includes('internal-short-lived-proof'), false);
    assert.equal(received.code, 'internal-short-lived-proof');
  } finally {
    await new Promise(resolveClose => listener.close(resolveClose));
  }
});

test('local upload keeps business facts and strips all session material', () => {
  const task = {
    platform: 'meituan',
    system_hotel_id: 12,
    platform_hotel_id: 'MT-12',
    data_date: '2026-07-23',
    data_type: 'business',
  };
  const rows = extractSanitizedRows({
    traffic: [{
      visitors: 18,
      cookie: 'forbidden',
      cookieValue: 'forbidden-camel-case',
      sessionToken: 'forbidden-session-token',
      profilePath: 'C:/private-profile',
      rawSession: 'forbidden-raw-session',
      request_headers: { Authorization: 'Bearer forbidden' },
      raw_data: '{"set-cookie":"forbidden"}',
      note: 'safe fact',
    }],
  }, task);

  assert.equal(rows.length, 1);
  assert.equal(rows[0].visitors, 18);
  assert.equal(rows[0].system_hotel_id, 12);
  assert.equal(rows[0].platform_hotel_id, undefined);
  assert.equal(rows[0].source, 'meituan');
  assert.equal(rows[0].cookie, undefined);
  assert.equal(rows[0].cookieValue, undefined);
  assert.equal(rows[0].sessionToken, undefined);
  assert.equal(rows[0].profilePath, undefined);
  assert.equal(rows[0].rawSession, undefined);
  assert.equal(rows[0].request_headers, undefined);
  assert.equal(rows[0].raw_data, undefined);
  assert.equal(JSON.stringify(rows).includes('forbidden'), false);
});

test('session proof is a boolean summary rather than Cookie material', () => {
  assert.equal(sessionVerified({ session_probe: { collectable: true } }), true);
  assert.equal(sessionVerified({ auth_status: { status: 'logged_in' } }), false);
  assert.equal(sessionVerified({
    auth_status: { ok: true },
    platform_identity_validation: { status: 'matched' },
  }), true);
  assert.equal(sessionVerified({
    session_probe: { collectable: true },
    platform_identity_validation: { status: 'hotel_mismatch' },
  }), false);
  assert.equal(sessionVerified({ auth_status: { status: 'login_required' } }), false);
  assert.equal(sanitizeBusinessValue('Authorization: Bearer abcdefghijk'), '[敏感内容已在本机移除]');
});

test('local failed-result retention removes only expired collector task files', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'suxios-collector-retention-'));
  const now = Date.UTC(2026, 6, 25, 2, 0, 0);
  const oldTask = join(directory, 'task_1_1.json');
  const recentTask = join(directory, 'task_2_2.json');
  const unrelated = join(directory, 'manual-diagnosis.json');
  try {
    await Promise.all([
      writeFile(oldTask, '{"old":true}'),
      writeFile(recentTask, '{"recent":true}'),
      writeFile(unrelated, '{"keep":true}'),
    ]);
    await utimes(oldTask, new Date(now - (8 * 24 * 60 * 60 * 1_000)), new Date(now - (8 * 24 * 60 * 60 * 1_000)));
    const outcome = await pruneLocalResultFiles(directory, now);
    assert.equal(outcome.removed, 1);
    await assert.rejects(readFile(oldTask, 'utf8'), { code: 'ENOENT' });
    assert.equal(await readFile(recentTask, 'utf8'), '{"recent":true}');
    assert.equal(await readFile(unrelated, 'utf8'), '{"keep":true}');
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});

test('ordered yesterday collection uses existing core sections and reports exact gaps', () => {
  const task = {
    task_type: 'collect',
    platform: 'meituan',
    system_hotel_id: 80,
    platform_hotel_id: 'MT-80',
    data_date: '2026-07-23',
    request: {
      ordered_collection: {
        scope: 'ota_yesterday_core',
        interface_ids: ['traffic_cards', 'flow_conversion', 'orders_daily_summary'],
      },
    },
  };

  assert.deepEqual(orderedSectionsForTask(task), ['orders', 'traffic']);
  assert.deepEqual(orderedSectionsForTask({ ...task, platform: 'ctrip' }), [
    'business_overview',
    'traffic_report',
  ]);

  const payload = {
    traffic: [{
      list_exposure: 0,
      detail_exposure: 14,
      flow_rate: 0.2,
      data_type: 'traffic',
    }],
    orders: [{
      order_amount: 688,
      room_nights: 2,
      order_count: 1,
      data_type: 'order',
    }],
    peerRank: [{ rank: 2 }],
    ads: [{ spend: 20 }],
    platform_identity_validation: { status: 'matched' },
    capture_gate: { status: 'ready' },
  };
  const rows = extractSanitizedRows(payload, task);
  assert.equal(rows.length, 2);
  assert.equal(rows.some(row => row.data_type === 'peer_rank'), false);
  assert.equal(rows.some(row => row.data_type === 'advertising'), false);

  const summary = buildCaptureResultSummary(payload, task, rows);
  assert.deepEqual(summary.requested_sections, ['orders', 'traffic']);
  assert.deepEqual(summary.missing_field_keys, []);
  assert.deepEqual(summary.platform_identity_validation, {
    status: 'matched',
    source_validation: false,
    validated_identifier: '',
  });
  assert.deepEqual(summary.excluded_example_capabilities, ['comments', 'realtime', 'ads', 'subchannels']);
});

test('server contract exposes paired device endpoints and never accepts central Cookie custody', async () => {
  const [
    routes,
    service,
    adapter,
    controller,
    ctrip,
    meituan,
    localAgent,
    starter,
    autoStart,
    template,
    appMain,
    reassignmentMigration,
    notifications,
  ] = await Promise.all([
    read('route/app.php'),
    read('app/service/OtaLocalCollectorService.php'),
    read('app/service/platform/LocalCollectorDataSourceAdapter.php'),
    read('app/controller/ota/LocalCollectorController.php'),
    read('scripts/ctrip_browser_capture.mjs'),
    read('scripts/meituan_browser_capture.mjs'),
    read('scripts/ota_local_collector.mjs'),
    read('scripts/start_local_collector.ps1'),
    read('scripts/register_local_collector_autostart.ps1'),
    read('resources/frontend/templates/fragments/35-page-online-data.html'),
    read('public/app-main.js'),
    read('database/migrations/20260802_allow_ota_local_collector_account_reassignment.sql'),
    read('app/service/OtaFailureNotificationService.php'),
  ]);

  for (const endpoint of [
    '/local-collector/pair-code',
    '/local-collector/accounts',
    '/local-collector/accounts/:accountId/hotels/:hotelId',
    '/local-collector/tasks',
    '/tasks/next',
    '/tasks/:taskId/result',
  ]) {
    assert.match(routes, new RegExp(endpoint.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
  }
  assert.match(service, /device_token_hash/);
  assert.match(service, /assertNoSensitiveMaterial/);
  assert.match(service, /MAX_GAP_TASKS_PER_HEARTBEAT/);
  assert.match(service, /recordCollectionOutcome/);
  assert.match(service, /function unbindHotel/);
  assert.match(service, /local_collector_hotel_unbound/);
  assert.match(service, /previous_mapping_id/);
  assert.match(service, /write_action/);
  assert.match(service, /reassigned/);
  assert.match(service, /mappingReadbackReceipt/);
  assert.match(service, /mapping_readback/);
  assert.match(service, /run_readback_scope_verified/);
  assert.match(service, /scope_identity/);
  assert.match(service, /服务器保存结果的租户、来源、同步任务、酒店、平台、日期或行集合回读凭据不一致/);
  assert.match(service, /刚刚被其他账户绑定/);
  assert.match(service, /ordered_collection/);
  assert.match(service, /P0OtaFieldLoopVerifierRunner/);
  assert.match(service, /online_data_historical_executed_/);
  assert.match(service, /P0OtaDownstreamGateService/);
  assert.match(service, /explicit_gap_report/);
  assert.match(service, /YESTERDAY_WINDOW_CUTOFF/);
  assert.match(service, /identity_unverified/);
  assert.match(adapter, /local_collector_verified/);
  assert.doesNotMatch(adapter, /OtaCredentialVault/);
  assert.match(controller, /Authorization/);
  assert.match(controller, /function unbindHotel/);
  assert.match(ctrip, /ctrip_account_profile_/);
  assert.match(localAgent, /--sequential-sections=true/);
  assert.match(localAgent, /createLocalConnectServer/);
  assert.match(localAgent, /Access-Control-Allow-Private-Network/);
  assert.match(starter, /serve/);
  assert.match(starter, /--port=\$Port/);
  assert.match(autoStart, /ONLOGON/);
  assert.match(meituan, /meituan_account_profile_/);
  assert.match(template, /data-testid="local-collector-account-center"/);
  assert.match(template, /data-testid="local-collector-unbind-hotel"/);
  assert.match(template, /连接此电脑/);
  assert.doesNotMatch(template, /生成 10 分钟配对码/);
  assert.match(template, /联系管理员/);
  const unbindAction = appMain.slice(
    appMain.indexOf('const unbindLocalCollectorHotel = async'),
    appMain.indexOf('const createLocalCollectorTask = async'),
  );
  assert.match(unbindAction, /method:\s*'DELETE'/);
  assert.match(unbindAction, /readback_verified !== true/);
  assert.match(unbindAction, /mapping_status \|\| ''\) !== 'unbound'/);
  assert.match(unbindAction, /loadLocalCollectorStatus\(\{ silent: true \}\)/);
  assert.match(unbindAction, /schedulePlatformDataSourcePanelLoad\(\{ force: true \}\)/);
  assert.match(reassignmentMigration, /active_system_hotel_id/);
  assert.match(reassignmentMigration, /active_platform_hotel_id/);
  assert.match(reassignmentMigration, /uq_ota_local_active_hotel_platform/);
  assert.match(reassignmentMigration, /uq_ota_local_active_platform_hotel_identity/);
  assert.match(reassignmentMigration, /DROP INDEX IF EXISTS `uq_ota_local_hotel_platform`/);
  assert.match(reassignmentMigration, /DROP INDEX IF EXISTS `uq_ota_local_platform_hotel_identity`/);
  assert.doesNotMatch(reassignmentMigration, /^UPDATE\s/im);
  assert.match(notifications, /buildOtaCollectionFailurePayload/);
  assert.match(notifications, /notify_wecom/);
});

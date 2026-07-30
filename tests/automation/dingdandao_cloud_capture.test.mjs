import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import {
  classifyDingdandaoResponseRequest,
  collectDingdandaoDirect,
  dingdandaoDirectRequests,
  dingdandaoSessionMaterialFromStorage,
  DINGDANDAO_API_PATHS,
  DINGDANDAO_COLLECTION_MODES,
  DINGDANDAO_DETAIL_TYPES,
  DINGDANDAO_TREND_TYPES,
  isTrustedDingdandaoCaptureComplete,
  probeDingdandaoIdentity,
  readDingdandaoSessionMaterial,
  shanghaiToday,
} from '../../scripts/dingdandao_cloud_capture.mjs';

const providerHotelName = '\u6566\u714c\u6f20\u84dd';
const providerHotelId = 'provider-hotel-5';

function responseData(path, type, targetDate) {
  if (path === DINGDANDAO_API_PATHS.identity) {
    return { id: providerHotelId, name: providerHotelName };
  }
  if (path === DINGDANDAO_API_PATHS.total) {
    return {
      totalRoomFee: 6450.14,
      adr: 645.01,
      occ: 66.67,
      revPar: 430.01,
      totalSalesNight: 10,
      adn: 10,
    };
  }
  if (path === DINGDANDAO_API_PATHS.sumDetail) {
    return {
      list: [{
        roomTypeId: 'room-type-1',
        roomTypeName: '\u666f\u89c2\u5927\u5e8a\u623f',
        roomList: [{ sum: type === 0 ? 6450.14 : 10 }],
      }],
    };
  }
  if (path === DINGDANDAO_API_PATHS.dailyDetail && type === 0) {
    return {
      list: [
        {
          roomTypeId: 'room-type-1',
          roomId: 'room-1',
          roomName: 'V21',
          dailyRoomRate: [{ date: targetDate, price: 0 }],
        },
        {
          roomTypeId: 'room-type-1',
          roomId: 'room-2',
          roomName: 'V22',
          dailyRoomRate: [{ date: targetDate, price: 6450.14 }],
        },
        {
          roomTypeId: 'room-type-1',
          roomId: '0',
          roomName: '\u672a\u6392\u623f',
          dailyRoomRate: [{ date: targetDate, price: 0 }],
        },
        {
          roomTypeId: 'room-type-1',
          roomId: null,
          roomName: '\u666f\u89c2\u5927\u5e8a\u623f\u5c0f\u8ba1',
          dailyRoomRate: [{ date: targetDate, price: 6450.14 }],
        },
        {
          roomTypeId: null,
          roomId: null,
          roomName: '\u5408\u8ba1',
          dailyRoomRate: [{ date: targetDate, price: 6450.14 }],
        },
      ],
    };
  }
  if (path === DINGDANDAO_API_PATHS.dailyDetail) {
    return {
      list: [{
        roomTypeId: 'room-type-1',
        roomId: 'room-1',
        roomName: 'V21',
        dailyRoomRate: [{ date: targetDate, price: type }],
      }],
    };
  }
  if (path === DINGDANDAO_API_PATHS.trend) {
    return {
      list: [
        { date: '2026-07-26', value: 10679.29 },
        { date: targetDate, value: 6450.14 },
      ],
    };
  }
  if (path === DINGDANDAO_API_PATHS.countyTotal) {
    return {
      boolCity: true,
      totalRoomFee: 4573.08,
      adr: 411.18,
      occ: 44.1,
      revPar: 181.33,
      totalSalesNight: 11.12,
      adn: 11.12,
    };
  }
  if (path === DINGDANDAO_API_PATHS.countyTrend) {
    return {
      boolCity: true,
      list: [{ date: targetDate, value: 4573.08 }],
    };
  }
  throw new Error('unexpected_test_path');
}

test('session material accepts only the verified localStorage mapping and same-origin cookies', () => {
  const now = new Date('2026-07-27T02:00:00.000Z');
  const material = dingdandaoSessionMaterialFromStorage({
    now,
    userAgent: 'Mozilla/5.0 test-current-browser',
    entries: [
      ['networkInfo', JSON.stringify({
        ntwNum: 'network_123',
        ntwInviteCode: 'network_123',
      })],
      ['networkNumNew', 'network_123'],
      ['ntwIdNew', 'must_not_be_used'],
      ['token', 'local-storage-token-value'],
    ],
    cookies: [{
      name: 'sid',
      value: 'cookie-value',
      domain: '.dingdandao.com',
      path: '/',
      expires: -1,
    }],
  });
  assert.deepEqual(material, {
    ntwNum: 'network_123',
    token: 'local-storage-token-value',
    cookieHeader: 'sid=cookie-value',
    userAgent: 'Mozilla/5.0 test-current-browser',
  });

  assert.throws(() => dingdandaoSessionMaterialFromStorage({
    now,
    userAgent: 'Mozilla/5.0 test-current-browser',
    entries: [
      ['networkInfo', JSON.stringify({
        ntwNum: 'network_123',
        ntwInviteCode: 'different_network',
      })],
      ['networkNumNew', 'network_123'],
      ['token', 'local-storage-token-value'],
    ],
    cookies: [{
      name: 'sid',
      value: 'cookie-value',
      domain: '.dingdandao.com',
      path: '/',
      expires: -1,
    }],
  }), /capture_session_expired/);

  assert.throws(() => dingdandaoSessionMaterialFromStorage({
    now,
    userAgent: 'Mozilla/5.0 test-current-browser',
    entries: [
      ['ntwIdNew', 'network_123'],
      ['token', 'local-storage-token-value'],
    ],
    cookies: [{
      name: 'sid',
      value: 'cookie-value',
      domain: '.dingdandao.com',
      path: '/',
      expires: -1,
    }],
  }), /capture_session_expired/);
});

test('default request plan targets the operating indicators and reconciliation facts only', () => {
  const targetDate = '2026-07-27';
  const requests = dingdandaoDirectRequests('network_123', targetDate);
  assert.equal(requests.length, 5);
  assert.deepEqual(requests.map((request) => request.path), [
    DINGDANDAO_API_PATHS.identity,
    DINGDANDAO_API_PATHS.total,
    DINGDANDAO_API_PATHS.sumDetail,
    DINGDANDAO_API_PATHS.dailyDetail,
    DINGDANDAO_API_PATHS.trend,
  ]);
  assert.deepEqual(
    requests
      .filter((request) => request.path === DINGDANDAO_API_PATHS.sumDetail)
      .map((request) => request.body.type),
    [0],
  );
  assert.deepEqual(
    requests
      .filter((request) => request.path === DINGDANDAO_API_PATHS.dailyDetail)
      .map((request) => request.body.type),
    [0],
  );
  for (const request of requests.slice(1)) {
    assert.equal(request.body.startDate, targetDate);
    assert.equal(request.body.endDate, targetDate);
    assert.equal(request.body.TIMEZONEOFFSET, -480);
  }
  assert.deepEqual(Object.keys(requests[0].body).sort(), ['TIMEZONEOFFSET', 'ntwNum']);
});

test('full diagnostic request plan preserves all verified detail and county contracts', () => {
  const targetDate = '2026-07-27';
  const requests = dingdandaoDirectRequests(
    'network_123',
    targetDate,
    DINGDANDAO_COLLECTION_MODES.fullDiagnostic,
  );
  assert.equal(requests.length, 13);
  assert.deepEqual(
    requests
      .filter((request) => request.path === DINGDANDAO_API_PATHS.sumDetail)
      .map((request) => request.body.type),
    [0, 1, 2, 3],
  );
  assert.deepEqual(
    requests
      .filter((request) => request.path === DINGDANDAO_API_PATHS.dailyDetail)
      .map((request) => request.body.type),
    [0, 1, 2, 3],
  );
  assert.equal(
    requests.some((request) => request.path === DINGDANDAO_API_PATHS.countyTotal),
    true,
  );
  assert.equal(
    requests.some((request) => request.path === DINGDANDAO_API_PATHS.countyTrend),
    true,
  );
});

test('closed Dingdandao page uses and cleans only a collector-owned same-origin target', async () => {
  const calls = [];
  const connection = {
    async send(method, params = {}, sessionId = null) {
      calls.push({ method, params, sessionId });
      if (method === 'Target.getTargets') {
        return {
          targetInfos: [{
            type: 'page',
            targetId: 'user_page_target',
            url: 'https://example.com/',
          }],
        };
      }
      if (method === 'Storage.getCookies') {
        return {
          cookies: [{
            name: 'sid',
            value: 'cookie-value',
            domain: '.dingdandao.com',
            path: '/',
            expires: -1,
          }],
        };
      }
      if (method === 'Target.createTarget') return { targetId: 'collector_target' };
      if (method === 'Browser.getVersion') {
        return { userAgent: 'Mozilla/5.0 test-current-browser' };
      }
      if (method === 'Target.attachToTarget') return { sessionId: 'collector_session' };
      if (method === 'Page.getFrameTree') {
        return {
          frameTree: {
            frame: { url: 'https://www.dingdandao.com/' },
          },
        };
      }
      if (method === 'DOMStorage.getDOMStorageItems') {
        return {
          entries: [
            ['networkInfo', JSON.stringify({
              ntwNum: 'network_123',
              ntwInviteCode: 'network_123',
            })],
            ['networkNumNew', 'network_123'],
            ['token', 'local-storage-token-value'],
          ],
        };
      }
      return {};
    },
    close() {
      calls.push({ method: 'connection.close' });
    },
  };

  const material = await readDingdandaoSessionMaterial(
    'http://127.0.0.1:9223',
    {
      now: new Date('2026-07-27T02:00:00.000Z'),
      connect: async () => connection,
    },
  );
  assert.equal(material.ntwNum, 'network_123');
  assert.equal(
    calls.find((call) => call.method === 'Target.createTarget')?.params.url,
    'https://www.dingdandao.com/',
  );
  assert.equal(
    calls.find((call) => call.method === 'Target.createTarget')?.params.background,
    true,
  );
  assert.deepEqual(
    calls.filter((call) => call.method === 'Target.closeTarget')
      .map((call) => call.params.targetId),
    ['collector_target'],
  );
  assert.equal(
    calls.some((call) => (
      call.method === 'Target.closeTarget'
      && call.params.targetId === 'user_page_target'
    )),
    false,
  );
});

test('a stale browser context cannot block a valid default-context session', async () => {
  const calls = [];
  const connection = {
    async send(method, params = {}, sessionId = null) {
      calls.push({ method, params, sessionId });
      if (method === 'Target.getTargets') {
        return {
          targetInfos: [
            {
              type: 'page',
              targetId: 'stale_page_target',
              browserContextId: 'stale_context_id',
              url: 'https://www.dingdandao.com/stale',
            },
            {
              type: 'page',
              targetId: 'default_page_target',
              url: 'https://www.dingdandao.com/current',
            },
          ],
        };
      }
      if (method === 'Target.getBrowserContexts') {
        return { browserContextIds: ['stale_context_id'] };
      }
      if (
        method === 'Storage.getCookies'
        && params.browserContextId === 'stale_context_id'
      ) {
        throw new Error('Failed to find browser context: stale_context_id');
      }
      if (method === 'Storage.getCookies') {
        return {
          cookies: [{
            name: 'sid',
            value: 'cookie-value',
            domain: '.dingdandao.com',
            path: '/',
            expires: -1,
          }],
        };
      }
      if (method === 'Browser.getVersion') {
        return { userAgent: 'Mozilla/5.0 test-current-browser' };
      }
      if (method === 'Target.attachToTarget') return { sessionId: 'default_session' };
      if (method === 'Page.getFrameTree') {
        return {
          frameTree: {
            frame: { url: 'https://www.dingdandao.com/current' },
          },
        };
      }
      if (method === 'DOMStorage.getDOMStorageItems') {
        return {
          entries: [
            ['networkInfo', JSON.stringify({
              ntwNum: 'network_123',
              ntwInviteCode: 'network_123',
            })],
            ['networkNumNew', 'network_123'],
            ['token', 'local-storage-token-value'],
          ],
        };
      }
      return {};
    },
    close() {
      calls.push({ method: 'connection.close' });
    },
  };

  const material = await readDingdandaoSessionMaterial(
    'http://127.0.0.1:9223',
    {
      now: new Date('2026-07-27T02:00:00.000Z'),
      connect: async () => connection,
    },
  );

  assert.equal(material.ntwNum, 'network_123');
  assert.deepEqual(
    calls.filter((call) => call.method === 'Storage.getCookies')
      .map((call) => call.params.browserContextId || null),
    ['stale_context_id', null],
  );
  assert.equal(
    calls.find((call) => call.method === 'Target.attachToTarget')
      ?.params.targetId,
    'default_page_target',
  );
  assert.equal(calls.some((call) => call.method === 'Target.createTarget'), false);
});

test('missing current-session cookies fails before creating a temporary target', async () => {
  const calls = [];
  const connection = {
    async send(method) {
      calls.push(method);
      if (method === 'Target.getTargets') {
        return {
          targetInfos: [{
            type: 'page',
            targetId: 'user_page_target',
            url: 'https://example.com/',
          }],
        };
      }
      if (method === 'Storage.getCookies') return { cookies: [] };
      return {};
    },
    close() {
      calls.push('connection.close');
    },
  };
  await assert.rejects(readDingdandaoSessionMaterial(
    'http://127.0.0.1:9223',
    {
      now: new Date('2026-07-27T02:00:00.000Z'),
      connect: async () => connection,
    },
  ), /capture_session_expired/);
  assert.equal(calls.includes('Target.createTarget'), false);
  assert.equal(calls.includes('connection.close'), true);
});

test('endpoint and type classifier keeps hotel, auxiliary, and county facts separate', () => {
  const targetDate = '2026-07-27';
  const base = {
    TIMEZONEOFFSET: -480,
    ntwNum: 'network_123',
    startDate: targetDate,
    endDate: targetDate,
  };
  assert.equal(classifyDingdandaoResponseRequest({
    path: DINGDANDAO_API_PATHS.total,
    requestBody: { ...base, festivalType: -1200 },
    targetDate,
  }).fact_kind, 'hotel_total');
  assert.equal(classifyDingdandaoResponseRequest({
    path: DINGDANDAO_API_PATHS.countyTotal,
    requestBody: { ...base, festivalType: -1200 },
    targetDate,
  }).fact_kind, 'county_total');
  assert.equal(classifyDingdandaoResponseRequest({
    path: DINGDANDAO_API_PATHS.dailyDetail,
    requestBody: { ...base, type: 0 },
    targetDate,
  }).fact_kind, 'room_fee');
  for (const type of [1, 2, 3]) {
    assert.equal(classifyDingdandaoResponseRequest({
      path: DINGDANDAO_API_PATHS.dailyDetail,
      requestBody: { ...base, type },
      targetDate,
    }).fact_kind, 'auxiliary_detail');
  }
  assert.equal(classifyDingdandaoResponseRequest({
    path: DINGDANDAO_API_PATHS.trend,
    requestBody: { ...base, type: 5 },
    targetDate,
  }).fact_kind, 'total_room_fee_trend');
  assert.equal(classifyDingdandaoResponseRequest({
    path: DINGDANDAO_API_PATHS.countyTrend,
    requestBody: { ...base, type: 5 },
    targetDate,
  }).fact_kind, 'county_total_room_fee_trend');
  assert.equal(classifyDingdandaoResponseRequest({
    path: DINGDANDAO_API_PATHS.countyTrend,
    requestBody: { ...base, type: 1 },
    targetDate,
  }).allowed, false);
  assert.equal(classifyDingdandaoResponseRequest({
    path: DINGDANDAO_API_PATHS.trend,
    requestBody: { ...base, type: 4 },
    targetDate,
  }).allowed, false);
  assert.equal(classifyDingdandaoResponseRequest({
    path: DINGDANDAO_API_PATHS.total,
    requestBody: {
      ...base,
      startDate: '2026-07-26',
      endDate: '2026-07-26',
      festivalType: -1200,
    },
    targetDate,
  }).allowed, false);
});

test('binding identity probe performs exactly one verified POST and clears session material', async () => {
  const sessionMaterial = {
    ntwNum: 'network_123',
    token: 'secret-token-value',
    cookieHeader: 'sid=secret-cookie-value',
    userAgent: 'Mozilla/5.0 test-current-browser',
  };
  const calls = [];
  const identity = await probeDingdandaoIdentity({
    cdpUrl: 'http://127.0.0.1:9223',
    expectedHotelName: providerHotelName,
    capturedAt: '2026-07-27T02:00:00.000Z',
  }, {
    now: new Date('2026-07-27T02:00:00.000Z'),
    readSession: async () => sessionMaterial,
    postJson: async (path, body, activeSession) => {
      calls.push({ path, body: structuredClone(body) });
      assert.equal(activeSession.token, 'secret-token-value');
      return {
        code: '1',
        errorDetail: null,
        data: {
          id: providerHotelId,
          name: providerHotelName,
        },
      };
    },
  });

  assert.deepEqual(calls, [{
    path: DINGDANDAO_API_PATHS.identity,
    body: {
      TIMEZONEOFFSET: -480,
      ntwNum: 'network_123',
    },
  }]);
  assert.deepEqual(identity, {
    provider_hotel_id: providerHotelId,
    provider_hotel_name: providerHotelName,
    identity_status: 'matched',
    source_api_path: DINGDANDAO_API_PATHS.identity,
    capture_method: 'existing_session_direct_post',
    request_count: 1,
    captured_at: '2026-07-27T02:00:00.000Z',
  });
  assert.deepEqual(sessionMaterial, {
    ntwNum: '',
    token: '',
    cookieHeader: '',
    userAgent: '',
  });
});

test('binding identity probe rejects an inexact hotel name and still clears session material', async () => {
  const sessionMaterial = {
    ntwNum: 'network_123',
    token: 'secret-token-value',
    cookieHeader: 'sid=secret-cookie-value',
    userAgent: 'Mozilla/5.0 test-current-browser',
  };
  await assert.rejects(probeDingdandaoIdentity({
    cdpUrl: 'http://127.0.0.1:9223',
    expectedHotelName: providerHotelName,
  }, {
    now: new Date('2026-07-27T02:00:00.000Z'),
    readSession: async () => sessionMaterial,
    postJson: async () => ({
      code: '1',
      errorDetail: null,
      data: {
        id: providerHotelId,
        name: `${providerHotelName}\u65b0`,
      },
    }),
  }), /capture_identity_mismatch/);
  assert.deepEqual(sessionMaterial, {
    ntwNum: '',
    token: '',
    cookieHeader: '',
    userAgent: '',
  });
});

test('binding probe cannot expose identity on a terminal without its private pipe contract', () => {
  const result = spawnSync(process.execPath, [
    fileURLToPath(new URL('../../scripts/dingdandao_binding_probe.mjs', import.meta.url)),
    `--expected-hotel-name=${providerHotelName}`,
  ], {
    encoding: 'utf8',
  });
  assert.equal(result.status, 1);
  assert.equal(result.stdout, '');
  assert.match(result.stderr, /binding_probe_private_pipe_required/);
  assert.doesNotMatch(result.stderr, new RegExp(providerHotelId));
});

test('precise direct collector reads operating indicators, reconciles room fee, and exposes no session material', async () => {
  const now = new Date('2026-07-27T02:00:00.000Z');
  const targetDate = shanghaiToday(now);
  const sessionMaterial = {
    ntwNum: 'network_123',
    token: 'secret-token-value',
    cookieHeader: 'sid=secret-cookie-value',
    userAgent: 'Mozilla/5.0 test-current-browser',
  };
  const calls = [];
  const capture = await collectDingdandaoDirect({
    cdpUrl: 'http://127.0.0.1:9223',
    targetDate,
    expectedHotelName: providerHotelName,
    capturedAt: '2026-07-27T08:00:00+08:00',
  }, {
    now,
    readSession: async () => sessionMaterial,
    postJson: async (path, body, activeSession) => {
      assert.equal(activeSession.token, 'secret-token-value');
      assert.equal(activeSession.cookieHeader, 'sid=secret-cookie-value');
      calls.push({ path, body: structuredClone(body) });
      return {
        code: '1',
        errorDetail: null,
        data: responseData(path, body.type, targetDate),
      };
    },
  });

  assert.equal(calls.length, 5);
  assert.deepEqual(sessionMaterial, {
    ntwNum: '',
    token: '',
    cookieHeader: '',
    userAgent: '',
  });
  assert.equal(capture.provider_hotel_id, providerHotelId);
  assert.equal(capture.provider_hotel_name, providerHotelName);
  assert.equal(capture.summary.total_room_fee, 6450.14);
  assert.equal(capture.room_fee_details.filter(
    (row) => ['room', 'unassigned'].includes(row.row_kind),
  ).reduce((sum, row) => sum + row.room_fee, 0), 6450.14);
  assert.equal(capture.trend.total_room_fee.at(-1).value, 6450.14);
  assert.equal(capture.auxiliary_query_status.length, 0);
  assert.equal(capture.county_context.fact_scope, 'county_diagnostic_only');
  assert.equal(capture.county_context.data_status, 'partial');
  assert.equal(capture.county_context.summary.total_room_fee, null);
  assert.equal(isTrustedDingdandaoCaptureComplete(capture, {
    targetDate,
    expectedHotelName: '\u6566\u714c\u00b7\u6f20\u84dd',
  }), false);
  const serialized = JSON.stringify(capture);
  assert.doesNotMatch(serialized, /network_123|secret-token-value|secret-cookie-value/);
});

test('full diagnostic collector preserves auxiliary details and county context', async () => {
  const now = new Date('2026-07-27T02:00:00.000Z');
  const targetDate = shanghaiToday(now);
  const sessionMaterial = {
    ntwNum: 'network_123',
    token: 'secret-token-value',
    cookieHeader: 'sid=secret-cookie-value',
    userAgent: 'Mozilla/5.0 test-current-browser',
  };
  const calls = [];
  const capture = await collectDingdandaoDirect({
    cdpUrl: 'http://127.0.0.1:9223',
    targetDate,
    expectedHotelName: providerHotelName,
    collectionMode: DINGDANDAO_COLLECTION_MODES.fullDiagnostic,
  }, {
    now,
    readSession: async () => sessionMaterial,
    postJson: async (path, body) => {
      calls.push({ path, body: structuredClone(body) });
      return {
        code: '1',
        errorDetail: null,
        data: responseData(path, body.type, targetDate),
      };
    },
  });

  assert.equal(calls.length, 13);
  assert.equal(capture.auxiliary_query_status.length, 6);
  assert.equal(capture.auxiliary_query_status.every(
    (status) => status.status === 'readable_not_promoted',
  ), true);
  assert.equal(capture.county_context.data_status, 'readable_separate');
  assert.equal(capture.county_context.summary.total_room_fee, 4573.08);
  assert.notEqual(
    capture.county_context.summary.total_room_fee,
    capture.summary.total_room_fee,
  );
  assert.deepEqual(sessionMaterial, {
    ntwNum: '',
    token: '',
    cookieHeader: '',
    userAgent: '',
  });
  assert.doesNotMatch(
    JSON.stringify(capture),
    /network_123|secret-token-value|secret-cookie-value/,
  );
});

test('historical dates and expired current sessions fail closed without API fallback', async () => {
  const now = new Date('2026-07-27T02:00:00.000Z');
  let sessionRead = false;
  await assert.rejects(collectDingdandaoDirect({
    cdpUrl: 'http://127.0.0.1:9223',
    targetDate: '2026-07-26',
    expectedHotelName: providerHotelName,
  }, {
    now,
    readSession: async () => {
      sessionRead = true;
      throw new Error('must_not_run');
    },
  }), /capture_target_date_not_today/);
  assert.equal(sessionRead, false);

  await assert.rejects(collectDingdandaoDirect({
    cdpUrl: 'http://127.0.0.1:9223',
    targetDate: shanghaiToday(now),
    expectedHotelName: providerHotelName,
  }, {
    now,
    readSession: async () => {
      throw new Error('capture_session_expired');
    },
  }), /capture_session_expired/);
});

test('production collector uses CDP storage and direct POST without opening, navigating, or closing a browser', async () => {
  const source = await readFile(
    new URL('../../scripts/dingdandao_cloud_capture.mjs', import.meta.url),
    'utf8',
  );
  assert.match(source, /Storage\.getCookies/);
  assert.match(source, /DOMStorage\.getDOMStorageItems/);
  assert.match(source, /items\.get\('networkInfo'\)/);
  assert.match(source, /items\.get\('networkNumNew'\)/);
  assert.match(source, /items\.get\('token'\)/);
  assert.match(source, /headers:[\s\S]{0,300}token: sessionMaterial\.token/);
  assert.match(source, /Target\.createTarget/);
  assert.match(source, /Target\.closeTarget/);
  assert.doesNotMatch(source, /ntwIdNew/);
  assert.doesNotMatch(source, /connectOverCDP|page\.reload|Page\.navigate|browser\.close/);
});

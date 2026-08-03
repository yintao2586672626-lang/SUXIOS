import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { browserSandboxMarkerUrl } from '../../scripts/lib/browser_sandbox.mjs';
import {
  classifyDingdandaoResponseRequest,
  collectDingdandaoDirect,
  dingdandaoDirectRequests,
  dingdandaoEndpointRecipes,
  dingdandaoSessionMaterialFromStorage,
  DINGDANDAO_API_PATHS,
  DINGDANDAO_COLLECTION_MODES,
  DINGDANDAO_DETAIL_TYPES,
  DINGDANDAO_FORWARD_HORIZONS,
  DINGDANDAO_SOURCE_SCOPES,
  DINGDANDAO_TREND_TYPES,
  isTrustedDingdandaoCaptureComplete,
  isTrustedDingdandaoForwardRoomStatusComplete,
  probeDingdandaoIdentity,
  readDingdandaoSessionMaterial,
  shanghaiToday,
} from '../../scripts/dingdandao_cloud_capture.mjs';

const providerHotelName = '\u6566\u714c\u6f20\u84dd';
const providerHotelId = 'provider-hotel-5';

function plusDays(date, days) {
  const value = new Date(`${date}T00:00:00.000Z`);
  value.setUTCDate(value.getUTCDate() + days);
  return value.toISOString().slice(0, 10);
}

function forwardDay(date, values) {
  return {
    date,
    week: 1,
    availableSale: values.available,
    occupy: values.booked,
    unavailableSale: values.unavailable,
    oversold: 0,
    availablePercent: String(values.availablePercent),
    occupyPercent: String(values.bookedPercent),
    unavailableSalePercent: String(values.unavailablePercent),
    roomFee: values.roomFee,
    night: values.booked,
    avaRoom: values.available + values.booked,
    occ: values.occupancy,
    adr: values.adr,
    revPar: values.revpar,
  };
}

function forwardResponse(targetDate) {
  const dates = Array.from({ length: 31 }, (_, index) => plusDays(targetDate, index));
  const roomTypeA = dates.map((date) => forwardDay(date, {
    available: 3,
    booked: 7,
    unavailable: 0,
    availablePercent: 30,
    bookedPercent: 70,
    unavailablePercent: 0,
    roomFee: 4200,
    occupancy: 70,
    adr: 600,
    revpar: 420,
  }));
  const roomTypeB = dates.map((date) => forwardDay(date, {
    available: 2,
    booked: 3,
    unavailable: 0,
    availablePercent: 40,
    bookedPercent: 60,
    unavailablePercent: 0,
    roomFee: 1800,
    occupancy: 60,
    adr: 600,
    revpar: 360,
  }));
  const total = dates.map((date) => forwardDay(date, {
    available: 5,
    booked: 10,
    unavailable: 0,
    availablePercent: 33.3,
    bookedPercent: 66.7,
    unavailablePercent: 0,
    roomFee: 6000,
    occupancy: 66.67,
    adr: 600,
    revpar: 400,
  }));
  const ratio = total.map((row) => ({
    ...row,
    availableSale: null,
    occupy: null,
    unavailableSale: null,
    oversold: null,
    roomFee: null,
    night: null,
    avaRoom: null,
    occ: null,
    adr: null,
    revPar: null,
  }));
  return {
    list: [
      {
        roomTypeId: 'room-type-1',
        roomTypeShortName: '\u666f\u89c2\u5927\u5e8a\u623f',
        roomNum: 10,
        dateList: roomTypeA,
      },
      {
        roomTypeId: 'room-type-2',
        roomTypeShortName: '\u5ead\u9662\u5927\u5e8a\u623f',
        roomNum: 5,
        dateList: roomTypeB,
      },
      {
        roomTypeId: null,
        roomTypeShortName: '\u5360\u603b\u623f\u6570\u7684\u6bd4\u4f8b',
        roomNum: null,
        dateList: ratio,
      },
      {
        roomTypeId: null,
        roomTypeShortName: '\u603b\u8ba1',
        roomNum: 15,
        dateList: total,
      },
    ],
    empty: false,
  };
}

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
  if (path === DINGDANDAO_API_PATHS.revenueOverview) {
    return {
      totalConsume: 6449.94,
      subjects: [
        {
          type: -1,
          subjectTypeName: '\u6d88\u8d39\u603b\u8ba1',
          singleDayTotalConsume: 6449.94,
          subjectTypeTotalConsume: 6449.94,
          percent: 100,
          subjectTypeDates: [{ date: targetDate, consume: 6449.94 }],
        },
        {
          type: 1,
          subjectTypeName: '\u623f\u8d39',
          singleDayTotalConsume: 6450.14,
          subjectTypeTotalConsume: 6450.14,
          percent: 100,
          subjectTypeDates: [{ date: targetDate, consume: 6450.14 }],
        },
        {
          type: 7,
          subjectTypeName: '\u65e9\u9910/\u5ba2\u623f\u6d88\u8d39',
          singleDayTotalConsume: -0.2,
          subjectTypeTotalConsume: -0.2,
          percent: 0,
          subjectTypeDates: [{ date: targetDate, consume: -0.2 }],
        },
      ],
    };
  }
  if (path === DINGDANDAO_API_PATHS.sumDetail) {
    return {
      list: [{
        roomTypeId: 'room-type-1',
        roomTypeName: '\u666f\u89c2\u5927\u5e8a\u623f',
        roomList: [
          {
            roomId: 'room-1',
            roomName: 'V21',
            sum: type === 0 ? 6450.14 : 10,
          },
          {
            roomId: 'room-2',
            roomName: 'V22',
            sum: 0,
          },
          {
            roomId: null,
            roomName: '\u666f\u89c2\u5927\u5e8a\u623f\u5c0f\u8ba1',
            sum: type === 0 ? 6450.14 : 10,
          },
        ],
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
    const current = {
      [DINGDANDAO_TREND_TYPES.adr]: 645.01,
      [DINGDANDAO_TREND_TYPES.occupancyRate]: 66.67,
      [DINGDANDAO_TREND_TYPES.revpar]: 430.01,
      [DINGDANDAO_TREND_TYPES.soldRoomNights]: 10,
      [DINGDANDAO_TREND_TYPES.totalRoomFee]: 6450.14,
    }[type];
    return {
      list: [
        { date: '2026-07-26', value: current },
        { date: targetDate, value: current },
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
    const current = {
      [DINGDANDAO_TREND_TYPES.adr]: 411.18,
      [DINGDANDAO_TREND_TYPES.occupancyRate]: 44.1,
      [DINGDANDAO_TREND_TYPES.revpar]: 181.33,
      [DINGDANDAO_TREND_TYPES.soldRoomNights]: 11.12,
      [DINGDANDAO_TREND_TYPES.totalRoomFee]: 4573.08,
    }[type];
    return {
      boolCity: true,
      list: [{ date: targetDate, value: current }],
    };
  }
  if (path === DINGDANDAO_API_PATHS.forwardRoomStatus) {
    return forwardResponse(targetDate);
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
  assert.equal(requests.length, 6);
  assert.deepEqual(requests.map((request) => request.recipe_id), [
    'store_identity',
    'operating_total',
    'accommodation_revenue_overview',
    'sum_detail_room_fee',
    'daily_detail_room_fee',
    'trend_total_room_fee',
  ]);
  assert.equal(
    requests.every((request) => (
      request.platform === 'dingdandao'
      && request.source_kind === 'pms'
      && request.business_module === 'accommodation_operating'
      && request.origin === 'https://www.dingdandao.com'
      && request.method === 'POST'
    )),
    true,
  );
  assert.deepEqual(requests.map((request) => request.path), [
    DINGDANDAO_API_PATHS.identity,
    DINGDANDAO_API_PATHS.total,
    DINGDANDAO_API_PATHS.revenueOverview,
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

test('endpoint recipes contain no runtime hotel, date, or session material', () => {
  const recipes = dingdandaoEndpointRecipes(
    DINGDANDAO_COLLECTION_MODES.fullDiagnostic,
  );
  const serialized = JSON.stringify(recipes);

  assert.equal(recipes.length, 23);
  assert.equal(new Set(recipes.map((recipe) => recipe.id)).size, 23);
  assert.doesNotMatch(
    serialized,
    /network_123|provider-hotel-5|2026-07-27|secret-cookie|secret-token/i,
  );
  assert.match(serialized, /\{provider_network_id\}/);
  assert.match(serialized, /\{target_date\}/);
  assert.match(serialized, /\{forward_end_date\}/);
});

test('full diagnostic request plan preserves all verified detail and county contracts', () => {
  const targetDate = '2026-07-27';
  const requests = dingdandaoDirectRequests(
    'network_123',
    targetDate,
    DINGDANDAO_COLLECTION_MODES.fullDiagnostic,
  );
  assert.equal(requests.length, 23);
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
  const revenue = requests.find(
    (request) => request.path === DINGDANDAO_API_PATHS.revenueOverview,
  );
  assert.equal(revenue.optional, true);
  assert.equal(revenue.body.festivalType, -1200);
  assert.equal(
    requests.filter(
      (request) => request.path === DINGDANDAO_API_PATHS.countyTrend
    ).length,
    5,
  );
  const forward = requests.at(-1);
  assert.equal(forward.path, DINGDANDAO_API_PATHS.forwardRoomStatus);
  assert.equal(forward.optional, true);
  assert.deepEqual(forward.body, {
    TIMEZONEOFFSET: -480,
    ntwNum: 'network_123',
    pageNum: 1,
    pageSize: 9999,
    startDate: targetDate,
    endDate: '2026-08-26',
  });
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

test('explicit sandbox selection reads only the marked isolated BrowserContext', async () => {
  const calls = [];
  const sandboxId = 'sbx_dingdandao_h80_primary';
  const connection = {
    async send(method, params = {}, sessionId = null) {
      calls.push({ method, params, sessionId });
      if (method === 'Target.getTargets') {
        return {
          targetInfos: [
            {
              type: 'page',
              targetId: 'marker_target',
              browserContextId: 'ctx_h80',
              url: browserSandboxMarkerUrl(sandboxId),
            },
            {
              type: 'page',
              targetId: 'hotel_80_page',
              browserContextId: 'ctx_h80',
              url: 'https://www.dingdandao.com/pmsManage/report/pro/dataCenter/overview',
            },
            {
              type: 'page',
              targetId: 'other_hotel_page',
              browserContextId: 'ctx_other',
              url: 'https://www.dingdandao.com/pmsManage/report/pro/dataCenter/overview',
            },
          ],
        };
      }
      if (method === 'Target.getBrowserContexts') {
        return { browserContextIds: ['ctx_h80', 'ctx_other'] };
      }
      if (method === 'Storage.getCookies') {
        assert.equal(params.browserContextId, 'ctx_h80');
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
        return { userAgent: 'Mozilla/5.0 sandbox-test' };
      }
      if (method === 'Target.attachToTarget') return { sessionId: 'sandbox_session' };
      if (method === 'Page.getFrameTree') {
        return {
          frameTree: {
            frame: { url: 'https://www.dingdandao.com/pmsManage/report/pro/dataCenter/overview' },
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
      calls.push({ method: 'connection.close', params: {} });
    },
  };

  const material = await readDingdandaoSessionMaterial(
    'http://127.0.0.1:9223',
    {
      now: new Date('2026-07-27T02:00:00.000Z'),
      sandboxId,
      connect: async () => connection,
    },
  );

  assert.equal(material.ntwNum, 'network_123');
  assert.deepEqual(
    calls.filter((call) => call.method === 'Storage.getCookies')
      .map((call) => call.params.browserContextId),
    ['ctx_h80'],
  );
  assert.equal(
    calls.find((call) => call.method === 'Target.attachToTarget')
      ?.params.targetId,
    'hotel_80_page',
  );
  assert.deepEqual(
    calls.filter((call) => call.method === 'Target.attachToTarget')
      .map((call) => call.params.targetId),
    ['hotel_80_page'],
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
    path: DINGDANDAO_API_PATHS.revenueOverview,
    requestBody: { ...base, festivalType: -1200 },
    targetDate,
  }).fact_kind, 'accommodation_revenue_overview');
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
  }).fact_kind, 'county_occupancy_rate_percent_trend');
  const forward = classifyDingdandaoResponseRequest({
    path: DINGDANDAO_API_PATHS.forwardRoomStatus,
    requestBody: {
      TIMEZONEOFFSET: -480,
      ntwNum: 'network_123',
      pageNum: 1,
      pageSize: 9999,
      startDate: targetDate,
      endDate: '2026-08-26',
    },
    targetDate,
  });
  assert.equal(forward.fact_kind, 'forward_room_status');
  assert.equal(forward.scope_status, 'as_of_today_forward_verified');
  assert.equal(classifyDingdandaoResponseRequest({
    path: DINGDANDAO_API_PATHS.forwardRoomStatus,
    requestBody: {
      TIMEZONEOFFSET: -480,
      ntwNum: 'network_123',
      pageNum: 1,
      pageSize: 9999,
      startDate: targetDate,
      endDate: '2026-08-17',
    },
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

  assert.equal(calls.length, 6);
  assert.deepEqual(sessionMaterial, {
    ntwNum: '',
    token: '',
    cookieHeader: '',
    userAgent: '',
  });
  assert.equal(capture.provider_hotel_id, providerHotelId);
  assert.equal(capture.provider_hotel_name, providerHotelName);
  assert.equal(capture.collection_mode, 'operating_indicators');
  assert.equal(capture.source_scope, DINGDANDAO_SOURCE_SCOPES.todayOnly);
  assert.equal(capture.capture_strategy, 'verified_endpoint_recipe');
  assert.equal(capture.capture_evidence.source_kind, 'pms');
  assert.equal(
    capture.capture_evidence.business_module,
    'accommodation_operating',
  );
  assert.equal(capture.capture_evidence.data_date, targetDate);
  assert.equal(
    capture.capture_evidence.capture_strategy,
    'verified_endpoint_recipe',
  );
  assert.equal(
    capture.capture_evidence.response_evidence_type,
    'structured_json',
  );
  assert.equal(capture.capture_evidence.fallback_from, null);
  assert.equal(capture.capture_evidence.fallback_reason, null);
  assert.equal(capture.capture_evidence.recipe_count, 6);
  assert.match(
    capture.capture_evidence.recipe_plan_hash,
    /^[a-f0-9]{64}$/,
  );
  assert.match(
    capture.capture_evidence.provider_hotel_id_hash,
    /^[a-f0-9]{64}$/,
  );
  assert.match(capture.source_trace_id, /^dingdandao:[a-f0-9]{64}$/);
  assert.equal(
    capture.source_trace_id,
    capture.capture_evidence.source_trace_id,
  );
  assert.equal(
    capture.source_url_hash,
    capture.capture_evidence.source_url_hash,
  );
  assert.doesNotMatch(
    JSON.stringify(capture.capture_evidence),
    new RegExp(providerHotelId),
  );
  assert.equal(capture.summary.total_room_fee, 6450.14);
  assert.equal(capture.revenue_overview.data_status, 'verified');
  assert.equal(
    capture.revenue_overview.total_accommodation_turnover,
    6449.94,
  );
  assert.notEqual(
    capture.revenue_overview.total_accommodation_turnover,
    capture.summary.total_room_fee,
  );
  assert.equal(
    capture.revenue_overview.subjects.find(
      (subject) => subject.provider_subject_type === 7,
    ).single_day_total,
    -0.2,
  );
  assert.equal(capture.room_fee_summary_rows.length, 2);
  assert.equal(capture.room_fee_summary_rows[0].room_fee, 6450.14);
  assert.equal(capture.room_fee_summary_rows.reduce(
    (sum, row) => sum + row.room_fee,
    0,
  ), 6450.14);
  assert.equal(capture.room_fee_details.filter(
    (row) => ['room', 'unassigned'].includes(row.row_kind),
  ).reduce((sum, row) => sum + row.room_fee, 0), 6450.14);
  assert.equal(capture.trend.total_room_fee.at(-1).value, 6450.14);
  assert.equal(capture.auxiliary_query_status.length, 0);
  assert.equal(capture.county_context.fact_scope, 'county_diagnostic_only');
  assert.equal(capture.county_context.data_status, 'partial');
  assert.equal(capture.county_context.summary.total_room_fee, null);
  assert.equal(capture.forward_room_status.data_status, 'partial');
  assert.equal(isTrustedDingdandaoCaptureComplete(capture, {
    targetDate,
    expectedHotelName: '\u6566\u714c\u00b7\u6f20\u84dd',
  }), false);
  const serialized = JSON.stringify(capture);
  assert.doesNotMatch(serialized, /network_123|secret-token-value|secret-cookie-value/);
});

test('missing revenue overview stays partial without replacing or erasing verified room-fee core', async () => {
  const now = new Date('2026-07-27T02:00:00.000Z');
  const targetDate = shanghaiToday(now);
  const capture = await collectDingdandaoDirect({
    cdpUrl: 'http://127.0.0.1:9223',
    targetDate,
    expectedHotelName: providerHotelName,
  }, {
    now,
    readSession: async () => ({
      ntwNum: 'network_123',
      token: 'secret-token-value',
      cookieHeader: 'sid=secret-cookie-value',
      userAgent: 'Mozilla/5.0 test-current-browser',
    }),
    postJson: async (path, body) => {
      if (path === DINGDANDAO_API_PATHS.revenueOverview) {
        throw new Error('capture_api_request_failed');
      }
      return {
        code: '1',
        errorDetail: null,
        data: responseData(path, body.type, targetDate),
      };
    },
  });

  assert.equal(capture.summary.total_room_fee, 6450.14);
  assert.equal(capture.revenue_overview.data_status, 'partial');
  assert.equal(capture.revenue_overview.total_accommodation_turnover, null);
  assert.deepEqual(
    capture.revenue_overview.gap_codes,
    ['dingdandao_revenue_overview_request_failed'],
  );
  assert.equal(isTrustedDingdandaoCaptureComplete(capture, {
    targetDate,
    expectedHotelName: providerHotelName,
  }), true);
});

test('sum-detail room fee must reconcile with daily detail and operating total', async () => {
  const now = new Date('2026-07-27T02:00:00.000Z');
  const targetDate = shanghaiToday(now);
  await assert.rejects(collectDingdandaoDirect({
    cdpUrl: 'http://127.0.0.1:9223',
    targetDate,
    expectedHotelName: providerHotelName,
    collectionMode: DINGDANDAO_COLLECTION_MODES.operatingIndicators,
  }, {
    now,
    readSession: async () => ({
      ntwNum: 'network_123',
      token: 'secret-token-value',
      cookieHeader: 'sid=secret-cookie-value',
      userAgent: 'Mozilla/5.0 test-current-browser',
    }),
    postJson: async (path, body) => {
      const data = responseData(path, body.type, targetDate);
      if (path === DINGDANDAO_API_PATHS.sumDetail
        && body.type === DINGDANDAO_DETAIL_TYPES.roomFee
      ) {
        data.list[0].roomList[0].sum = 1;
      }
      return { code: '1', errorDetail: null, data };
    },
  }), /capture_exact_target_date_network_incomplete/);
});

test('full diagnostic collector preserves auxiliary details and county context', async () => {
  const now = new Date('2026-07-27T02:00:00.000Z');
  const targetDate = shanghaiToday(now);
  const sessionMaterial = {
    ntwNum: 'network_123',
    token: 'secret-token-value',
    cookieHeader: 'sid=secret-cookie-value',
    userAgent: 'Mozilla/5.0 test-current-browser',
    regionName: '甘肃省/酒泉市/敦煌市',
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

  assert.equal(calls.length, 23);
  assert.equal(capture.collection_mode, 'full_diagnostic');
  assert.equal(
    capture.capture_evidence.collection_mode,
    'full_diagnostic',
  );
  assert.equal(capture.capture_evidence.section, 'pms_full_diagnostic');
  assert.equal(capture.capture_strategy, 'verified_endpoint_recipe');
  assert.equal(capture.capture_evidence.recipe_count, 23);
  assert.match(capture.source_trace_id, /^dingdandao:[a-f0-9]{64}$/);
  assert.equal(
    capture.source_url_hash,
    '1937f09f551ebadbe32c6a097bcd890616af689f1c4e51fe61f928650e719d92',
  );
  assert.equal(capture.auxiliary_query_status.length, 6);
  assert.equal(capture.auxiliary_query_status.every(
    (status) => status.status === 'readable_not_promoted',
  ), true);
  assert.equal(capture.auxiliary_query_status.every(
    (status) => status.observed_row_count > 0,
  ), true);
  assert.equal(capture.county_context.data_status, 'readable_separate');
  assert.equal(capture.county_context.region_name, '甘肃省/酒泉市/敦煌市');
  assert.equal(capture.county_context.summary.total_room_fee, 4573.08);
  assert.equal(capture.trend.adr.at(-1).value, 645.01);
  assert.equal(capture.trend.occupancy_rate_percent.at(-1).value, 66.67);
  assert.equal(capture.trend.revpar.at(-1).value, 430.01);
  assert.equal(capture.trend.sold_room_nights.at(-1).value, 10);
  assert.equal(capture.county_context.trend.adr.at(-1).value, 411.18);
  assert.equal(capture.forward_room_status.data_status, 'verified');
  assert.equal(
    capture.forward_room_status.metric_definitions.unavailable_rooms
      .components.join(','),
    'stopped,maintenance,held,linked_closed',
  );
  assert.deepEqual(
    capture.forward_room_status.metric_definitions.room_fee.material_exclusions,
    ['guest_room_consumption', 'penalties', 'other_non_room_fee_revenue'],
  );
  assert.equal(capture.forward_room_status.source_day_count, 31);
  assert.equal(capture.forward_room_status.display_day_count, 21);
  assert.equal(
    capture.forward_room_status.display_semantics,
    'future_days_after_as_of_date',
  );
  assert.equal(capture.forward_room_status.source_coverage_status, 'complete');
  assert.deepEqual(capture.forward_room_status.source_gap_codes, []);
  assert.equal(capture.forward_room_status.daily_rows[0].oversold_rooms, 0);
  assert.equal(capture.forward_room_status.source_room_type_count, 2);
  assert.deepEqual(
    capture.forward_room_status.display_horizons,
    DINGDANDAO_FORWARD_HORIZONS,
  );
  assert.deepEqual(
    capture.forward_room_status.horizons.map((horizon) => ({
      days: horizon.horizon_days,
      covered: horizon.covered_days,
      booked: horizon.booked_room_nights,
      remaining: horizon.remaining_sellable_room_nights,
    })),
    [
      { days: 3, covered: 3, booked: 30, remaining: 15 },
      { days: 7, covered: 7, booked: 70, remaining: 35 },
      { days: 14, covered: 14, booked: 140, remaining: 70 },
      { days: 21, covered: 21, booked: 210, remaining: 105 },
    ],
  );
  assert.equal(isTrustedDingdandaoForwardRoomStatusComplete(
    capture.forward_room_status,
    targetDate,
  ), true);
  assert.notEqual(
    capture.county_context.summary.total_room_fee,
    capture.summary.total_room_fee,
  );
  assert.deepEqual(sessionMaterial, {
    ntwNum: '',
    token: '',
    cookieHeader: '',
    userAgent: '',
    regionName: '',
  });
  assert.doesNotMatch(
    JSON.stringify(capture),
    /network_123|secret-token-value|secret-cookie-value/,
  );
});

test('an unclassified daily-detail row is rejected instead of being counted as unassigned', async () => {
  const now = new Date('2026-07-27T02:00:00.000Z');
  const targetDate = shanghaiToday(now);
  await assert.rejects(collectDingdandaoDirect({
    cdpUrl: 'http://127.0.0.1:9223',
    targetDate,
    expectedHotelName: providerHotelName,
    collectionMode: DINGDANDAO_COLLECTION_MODES.operatingIndicators,
  }, {
    now,
    readSession: async () => ({
      ntwNum: 'network_123',
      token: 'secret-token-value',
      cookieHeader: 'sid=secret-cookie-value',
      userAgent: 'Mozilla/5.0 test-current-browser',
    }),
    postJson: async (path, body) => {
      const data = responseData(path, body.type, targetDate);
      if (path === DINGDANDAO_API_PATHS.dailyDetail
        && body.type === DINGDANDAO_DETAIL_TYPES.roomFee
      ) {
        data.list.push({
          roomTypeId: null,
          roomId: null,
          roomName: 'unexpected-provider-row',
          dailyRoomRate: [{ date: targetDate, price: 1 }],
        });
      }
      return { code: '1', errorDetail: null, data };
    },
  }), /capture_daily_detail_row_unclassified/);
});

test('an auxiliary response without a data list is not declared readable', async () => {
  const now = new Date('2026-07-27T02:00:00.000Z');
  const targetDate = shanghaiToday(now);
  const capture = await collectDingdandaoDirect({
    cdpUrl: 'http://127.0.0.1:9223',
    targetDate,
    expectedHotelName: providerHotelName,
    collectionMode: DINGDANDAO_COLLECTION_MODES.fullDiagnostic,
  }, {
    now,
    readSession: async () => ({
      ntwNum: 'network_123',
      token: 'secret-token-value',
      cookieHeader: 'sid=secret-cookie-value',
      userAgent: 'Mozilla/5.0 test-current-browser',
    }),
    postJson: async (path, body) => {
      if (path === DINGDANDAO_API_PATHS.sumDetail && body.type === 1) {
        return { code: '1', errorDetail: null, data: null };
      }
      return {
        code: '1',
        errorDetail: null,
        data: responseData(path, body.type, targetDate),
      };
    },
  });

  assert.equal(capture.auxiliary_query_status.length, 5);
  assert.equal(capture.auxiliary_query_status.some(
    (status) => status.api_path === DINGDANDAO_API_PATHS.sumDetail
      && status.type === 1,
  ), false);
});

test('structured county metrics remain readable when the optional DOM region label is missing', async () => {
  const now = new Date('2026-07-27T02:00:00.000Z');
  const targetDate = shanghaiToday(now);
  const capture = await collectDingdandaoDirect({
    cdpUrl: 'http://127.0.0.1:9223',
    targetDate,
    expectedHotelName: providerHotelName,
    collectionMode: DINGDANDAO_COLLECTION_MODES.fullDiagnostic,
  }, {
    now,
    readSession: async () => ({
      ntwNum: 'network_123',
      token: 'secret-token-value',
      cookieHeader: 'sid=secret-cookie-value',
      userAgent: 'Mozilla/5.0 test-current-browser',
    }),
    postJson: async (path, body) => ({
      code: '1',
      errorDetail: null,
      data: responseData(path, body.type, targetDate),
    }),
  });

  assert.equal(capture.county_context.data_status, 'readable_separate');
  assert.equal(capture.county_context.region_name, null);
  assert.equal(capture.county_context.summary.total_room_fee, 4573.08);
  assert.equal('region_name' in capture.county_context.field_trace, false);
});

test('verified 3/7/14/21 windows survive a trailing day coverage failure', async () => {
  const now = new Date('2026-07-27T02:00:00.000Z');
  const targetDate = shanghaiToday(now);
  const capture = await collectDingdandaoDirect({
    cdpUrl: 'http://127.0.0.1:9223',
    targetDate,
    expectedHotelName: providerHotelName,
    collectionMode: DINGDANDAO_COLLECTION_MODES.fullDiagnostic,
  }, {
    now,
    readSession: async () => ({
      ntwNum: 'network_123',
      token: 'secret-token-value',
      cookieHeader: 'sid=secret-cookie-value',
      userAgent: 'Mozilla/5.0 test-current-browser',
      regionName: '甘肃省/酒泉市/敦煌市',
    }),
    postJson: async (path, body) => {
      const data = responseData(path, body.type, targetDate);
      if (path === DINGDANDAO_API_PATHS.forwardRoomStatus) {
        const total = data.list.find(
          (row) => row.roomTypeShortName === '\u603b\u8ba1',
        );
        total.dateList[22].date = 'invalid-trailing-date';
      }
      return { code: '1', errorDetail: null, data };
    },
  });

  assert.equal(capture.forward_room_status.data_status, 'verified');
  assert.equal(capture.forward_room_status.source_day_count, 22);
  assert.equal(capture.forward_room_status.display_day_count, 21);
  assert.equal(capture.forward_room_status.source_coverage_status, 'partial');
  assert.deepEqual(
    capture.forward_room_status.source_gap_codes,
    ['dingdandao_forward_trailing_coverage_partial'],
  );
  assert.deepEqual(
    capture.forward_room_status.horizons.map((row) => row.quality_status),
    ['verified', 'verified', 'verified', 'verified'],
  );
  assert.equal(isTrustedDingdandaoForwardRoomStatusComplete(
    capture.forward_room_status,
    targetDate,
  ), true);
});

test('nonzero oversold inventory is preserved as a dated room-type anomaly', async () => {
  const now = new Date('2026-07-27T02:00:00.000Z');
  const targetDate = shanghaiToday(now);
  const capture = await collectDingdandaoDirect({
    cdpUrl: 'http://127.0.0.1:9223',
    targetDate,
    expectedHotelName: providerHotelName,
    collectionMode: DINGDANDAO_COLLECTION_MODES.fullDiagnostic,
  }, {
    now,
    readSession: async () => ({
      ntwNum: 'network_123',
      token: 'secret-token-value',
      cookieHeader: 'sid=secret-cookie-value',
      userAgent: 'Mozilla/5.0 test-current-browser',
      regionName: '甘肃省/酒泉市/敦煌市',
    }),
    postJson: async (path, body) => {
      const data = responseData(path, body.type, targetDate);
      if (path === DINGDANDAO_API_PATHS.forwardRoomStatus) {
        const total = data.list.find(
          (row) => row.roomTypeShortName === '\u603b\u8ba1',
        );
        const roomType = data.list.find(
          (row) => row.roomTypeId === 'room-type-1',
        );
        total.dateList[5].oversold = 1;
        roomType.dateList[5].oversold = 1;
      }
      return { code: '1', errorDetail: null, data };
    },
  });

  assert.equal(
    capture.forward_room_status.data_status,
    'verified_with_anomalies',
  );
  assert.equal(capture.forward_room_status.daily_rows.length, 31);
  assert.equal(capture.forward_room_status.room_types.length, 2);
  assert.deepEqual(
    capture.forward_room_status.gap_codes,
    ['dingdandao_forward_oversold_present'],
  );
  assert.deepEqual(capture.forward_room_status.anomalies, [{
    anomaly_type: 'oversold',
    stay_date: plusDays(targetDate, 5),
    provider_room_type_id: 'room-type-1',
    room_type_name: '\u666f\u89c2\u5927\u5e8a\u623f',
    oversold_rooms: 1,
  }]);
  assert.deepEqual(
    capture.forward_room_status.horizons.map((row) => row.quality_status),
    ['verified', 'warning', 'warning', 'warning'],
  );
  assert.equal(isTrustedDingdandaoForwardRoomStatusComplete(
    capture.forward_room_status,
    targetDate,
  ), false);
});

test('a failed optional forward request preserves verified current-day facts and reports the gap', async () => {
  const now = new Date('2026-07-27T02:00:00.000Z');
  const targetDate = shanghaiToday(now);
  const sessionMaterial = {
    ntwNum: 'network_123',
    token: 'secret-token-value',
    cookieHeader: 'sid=secret-cookie-value',
    userAgent: 'Mozilla/5.0 test-current-browser',
    regionName: 'test-region',
  };
  const capture = await collectDingdandaoDirect({
    cdpUrl: 'http://127.0.0.1:9223',
    targetDate,
    expectedHotelName: providerHotelName,
    collectionMode: DINGDANDAO_COLLECTION_MODES.fullDiagnostic,
  }, {
    now,
    readSession: async () => sessionMaterial,
    postJson: async (path, body) => {
      if (path === DINGDANDAO_API_PATHS.forwardRoomStatus) {
        throw new Error('capture_api_request_failed');
      }
      return {
        code: '1',
        errorDetail: null,
        data: responseData(path, body.type, targetDate),
      };
    },
  });

  assert.equal(capture.summary.total_room_fee, 6450.14);
  assert.equal(capture.forward_room_status.data_status, 'partial');
  assert.deepEqual(
    capture.forward_room_status.gap_codes,
    ['dingdandao_forward_request_failed'],
  );
  assert.equal(isTrustedDingdandaoCaptureComplete(capture, {
    targetDate,
    expectedHotelName: providerHotelName,
  }), true);
});

test('historical operating indicators use exact single-date scope without fallback', async () => {
  const now = new Date('2026-07-27T02:00:00.000Z');
  const targetDate = '2026-07-26';
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
    collectionMode: DINGDANDAO_COLLECTION_MODES.operatingIndicators,
    capturedAt: '2026-07-27T08:00:00+08:00',
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

  assert.equal(calls.length, 6);
  assert.equal(calls.every(({ body }) => (
    !Object.hasOwn(body, 'startDate')
      || (body.startDate === targetDate && body.endDate === targetDate)
  )), true);
  assert.equal(capture.business_date, targetDate);
  assert.equal(
    capture.source_scope,
    DINGDANDAO_SOURCE_SCOPES.historicalSingleDate,
  );
  assert.equal(capture.collection_mode, 'operating_indicators');
  assert.equal(capture.capture_evidence.data_date, targetDate);
  assert.equal(capture.capture_evidence.recipe_count, 6);
  assert.equal(isTrustedDingdandaoCaptureComplete(capture, {
    targetDate,
    expectedHotelName: providerHotelName,
    expectedSourceScope: DINGDANDAO_SOURCE_SCOPES.historicalSingleDate,
  }), true);
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

test('historical full diagnostics, future dates, and expired sessions fail closed before fallback', async () => {
  const now = new Date('2026-07-27T02:00:00.000Z');
  let sessionRead = false;
  await assert.rejects(collectDingdandaoDirect({
    cdpUrl: 'http://127.0.0.1:9223',
    targetDate: '2026-07-26',
    expectedHotelName: providerHotelName,
    collectionMode: DINGDANDAO_COLLECTION_MODES.fullDiagnostic,
  }, {
    now,
    readSession: async () => {
      sessionRead = true;
      throw new Error('must_not_run');
    },
  }), /capture_historical_collection_mode_invalid/);
  assert.equal(sessionRead, false);

  await assert.rejects(collectDingdandaoDirect({
    cdpUrl: 'http://127.0.0.1:9223',
    targetDate: '2026-07-28',
    expectedHotelName: providerHotelName,
  }, {
    now,
    readSession: async () => {
      sessionRead = true;
      throw new Error('must_not_run');
    },
  }), /capture_target_date_in_future/);
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

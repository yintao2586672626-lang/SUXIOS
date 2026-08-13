#!/usr/bin/env node
import { pathToFileURL } from 'node:url';
import { chromium } from 'playwright-core';

export const SOURCE_URL = 'https://pms.meituan.com/#qk-workbench';
export const IDENTITY_API =
  '/hotelpms/api/v1/property/hotel/getHotelInfo';
export const OVERVIEW_API =
  '/hotelpms/api/v1/report/home/workbench/businessOverview';
export const ROOM_API =
  '/hotelpms/api/v1/report/home/workbench/room';

function text(value, limit = 160) {
  return String(value ?? '').replace(/\s+/g, ' ').trim().slice(0, limit);
}

function normalizeHotelName(value) {
  return text(value).toLowerCase().replace(/[\s·•・（）()\-—_]/g, '');
}

function number(value, field, { integer = false, optional = false } = {}) {
  if ((value === null || value === undefined || value === '') && optional) return null;
  const parsed = Number(value);
  if (!Number.isFinite(parsed) || parsed < 0 || (integer && !Number.isInteger(parsed))) {
    throw new Error(`meituan_cloud_${field}_invalid`);
  }
  return integer ? parsed : Math.round(parsed * 100) / 100;
}

function apiData(response, name) {
  if (!response
    || response.status !== 200
    || response.body?.code !== 10000
  ) {
    throw new Error(`meituan_cloud_${name}_api_failed`);
  }
  return response.body.data;
}

function shanghaiDateTime(now = new Date()) {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Asia/Shanghai',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hourCycle: 'h23',
    })
      .formatToParts(now)
      .filter((part) => part.type !== 'literal')
      .map((part) => [part.type, part.value]),
  );
  return {
    date: `${parts.year}-${parts.month}-${parts.day}`,
    dateTime: `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}:${parts.second}`,
  };
}

function explicitBusinessDate(overview) {
  for (const key of ['businessDate', 'statDate', 'bizDate', 'reportDate']) {
    const match = text(overview?.[key], 32).match(/^\d{4}-\d{2}-\d{2}$/);
    if (match) return match[0];
  }
  return null;
}

function providerHotelId(...sources) {
  for (const source of sources) {
    for (const key of ['hotelId', 'poiId', 'hotelPoiId', 'orgId', 'id']) {
      const value = text(source?.[key], 120);
      if (value) return value;
    }
  }
  return null;
}

function saleOrderCount(overview) {
  for (const key of ['estimatedOrderNum', 'orderNum', 'saleOrderCount']) {
    if (overview?.[key] !== null && overview?.[key] !== undefined && overview?.[key] !== '') {
      return number(overview[key], 'sale_order_count', { integer: true });
    }
  }
  return null;
}

/**
 * Converts only the reviewed workbench fields into the SUXIOS sanitized PMS
 * fact contract. Raw account responses never leave this function's caller.
 */
export function buildCaptureFromResponses({
  identityResponse,
  overviewResponse,
  roomResponse,
  expectedHotelName,
  targetDate,
  capturedAt,
}) {
  const identity = apiData(identityResponse, 'identity');
  const overview = apiData(overviewResponse, 'overview');
  const roomRows = apiData(roomResponse, 'room');
  if (!identity || !overview || !Array.isArray(roomRows) || roomRows.length === 0) {
    throw new Error('meituan_cloud_business_data_missing');
  }
  const actualHotelName = text(identity.hotelName ?? identity.name);
  const normalizedExpectedHotelName = normalizeHotelName(expectedHotelName);
  if (!actualHotelName || !normalizedExpectedHotelName) {
    throw new Error('meituan_cloud_hotel_identity_unverified');
  }
  if (normalizeHotelName(actualHotelName) !== normalizedExpectedHotelName) {
    throw new Error('meituan_cloud_hotel_identity_mismatch');
  }

  const roomTypes = roomRows.map((row) => {
    const roomType = text(row?.roomName);
    if (!roomType) throw new Error('meituan_cloud_room_type_name_missing');
    return {
      room_type: roomType,
      total_rooms: number(row.roomCount, 'room_count', { integer: true }),
      sold_rooms: number(row.saledRoomCount, 'sold_room_count', { integer: true }),
    };
  });
  const totalRooms = roomTypes.reduce((sum, row) => sum + row.total_rooms, 0);
  const detailSold = roomTypes.reduce((sum, row) => sum + row.sold_rooms, 0);
  const roomTypeAvailableRooms = roomTypes.reduce(
    (sum, row) => sum + Math.max(row.total_rooms - row.sold_rooms, 0),
    0,
  );
  const soldRoomNights = number(
    overview.estimatedRoomNights,
    'sold_room_nights',
    { integer: true },
  );
  const availableRooms = number(overview.saleNum, 'available_rooms', { integer: true });
  const businessDate = explicitBusinessDate(overview) || targetDate;
  const dateEvidenceType = explicitBusinessDate(overview)
    ? 'verified_api_business_date'
    : 'trusted_realtime_workbench_capture';
  const occupancy = totalRooms > 0
    ? Math.round((soldRoomNights / totalRooms * 100) * 100) / 100
    : null;
  const availabilityDifference = Math.abs(availableRooms - roomTypeAvailableRooms);
  const availabilityTolerance = Math.max(2, Math.ceil(totalRooms * 0.05));
  const validationWarnings = [];
  if (availabilityDifference > 0) {
    validationWarnings.push(
      `首页可售${availableRooms}间与房型可售${roomTypeAvailableRooms}间相差`
      + `${availabilityDifference}间；容差${availabilityTolerance}间。`,
    );
  }
  for (const roomType of roomTypes) {
    if (roomType.sold_rooms > roomType.total_rooms) {
      validationWarnings.push(
        `${roomType.room_type}超售${roomType.sold_rooms - roomType.total_rooms}间。`,
      );
    }
  }

  return {
    source_url: SOURCE_URL,
    source_scope: 'today_realtime_accommodation',
    capture_method: 'same_origin_api',
    captured_at: capturedAt,
    business_date: businessDate,
    provider_hotel_id: providerHotelId(identity, overview),
    provider_hotel_name: actualHotelName,
    identity_evidence_type: 'verified_api_hotel_identity',
    date_evidence_type: dateEvidenceType,
    summary: {
      estimated_room_revenue: number(
        overview.estimatedRoomAmt,
        'estimated_room_revenue',
      ),
      adr: number(overview.estimatedAvgRoomPrice, 'adr'),
      revpar: number(overview.estimatedRevPAR, 'revpar'),
      sold_room_nights: soldRoomNights,
      total_rooms: totalRooms,
      available_rooms: availableRooms,
      room_type_available_rooms: roomTypeAvailableRooms,
      occupancy_rate_percent: occupancy,
      sale_order_count: saleOrderCount(overview),
    },
    room_types: roomTypes,
    field_trace: {
      provider_hotel_identity:
        `API:${IDENTITY_API}#data.hotelName+data.hotelId`,
      estimated_room_revenue: `API:${OVERVIEW_API}#data.estimatedRoomAmt`,
      adr: `API:${OVERVIEW_API}#data.estimatedAvgRoomPrice`,
      revpar: `API:${OVERVIEW_API}#data.estimatedRevPAR`,
      sold_room_nights: `API:${OVERVIEW_API}#data.estimatedRoomNights`,
      total_rooms: `API:${ROOM_API}#sum(data[].roomCount)`,
      available_rooms: `API:${OVERVIEW_API}#data.saleNum`,
      room_type_available_rooms:
        `API:${ROOM_API}#sum(max(data[].roomCount-data[].saledRoomCount,0))`,
      occupancy_rate_percent: 'DERIVED:sold_room_nights/total_rooms*100',
      ...(saleOrderCount(overview) === null
        ? {}
        : { sale_order_count: `API:${OVERVIEW_API}#data.orderCount` }),
    },
    validation_warnings: validationWarnings,
    collector_checks: {
      detail_sold_matches_overview: detailSold === soldRoomNights,
      availability_difference: availabilityDifference,
      availability_tolerance: availabilityTolerance,
    },
  };
}

function parseArguments(argv) {
  const values = {};
  for (const argument of argv) {
    const match = argument.match(/^--([a-z-]+)=(.*)$/);
    if (!match) throw new Error('capture_argument_invalid');
    values[match[1]] = match[2];
  }
  const cdpUrl = new URL(values['cdp-url'] || 'http://127.0.0.1:9223');
  if (cdpUrl.protocol !== 'http:' || cdpUrl.hostname !== '127.0.0.1' || cdpUrl.pathname !== '/') {
    throw new Error('capture_cdp_scope_invalid');
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(values['target-date'] || '')) {
    throw new Error('capture_target_date_invalid');
  }
  const expectedHotelName = text(values['expected-hotel-name']);
  if (!expectedHotelName) throw new Error('capture_expected_hotel_name_missing');
  return {
    cdpUrl: cdpUrl.toString().replace(/\/$/, ''),
    targetDate: values['target-date'],
    expectedHotelName,
    timeoutMs: Math.min(
      30000,
      Math.max(3000, Number.parseInt(values['timeout-ms'] || '15000', 10)),
    ),
  };
}

function safeReason(error) {
  return String(error?.message || error || 'meituan_cloud_capture_failed')
    .toLowerCase()
    .replace(/[^a-z0-9_-]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 100) || 'meituan_cloud_capture_failed';
}

async function collect(page) {
  return page.evaluate(async ({ identityApi, overviewApi, roomApi }) => {
    const bodyText = String(document.body?.innerText || '').replace(/\s+/g, ' ');
    const loginVisible = /验证码登录|账号登录|手机号登录/.test(bodyText);
    const request = async (path, method = 'GET') => {
      const response = await fetch(path, {
        method,
        credentials: 'include',
        headers: { Accept: 'application/json' },
      });
      let body = null;
      try {
        body = await response.json();
      } catch {
        body = null;
      }
      return { status: response.status, body };
    };
    const [identityResponse, overviewResponse, roomResponse] = await Promise.all([
      request(identityApi),
      request(overviewApi, 'POST'),
      request(roomApi, 'POST'),
    ]);
    return {
      loginVisible,
      identityResponse,
      overviewResponse,
      roomResponse,
    };
  }, {
    identityApi: IDENTITY_API,
    overviewApi: OVERVIEW_API,
    roomApi: ROOM_API,
  });
}

async function main() {
  const options = parseArguments(process.argv.slice(2));
  const browser = await chromium.connectOverCDP(options.cdpUrl);
  try {
    const pages = browser.contexts().flatMap((context) => context.pages());
    const page = pages.find((candidate) => {
      try {
        return new URL(candidate.url()).origin === 'https://pms.meituan.com';
      } catch {
        return false;
      }
    });
    if (!page) throw new Error('meituan_cloud_page_missing');
    await page.waitForLoadState('domcontentloaded', { timeout: options.timeoutMs });
    const current = new URL(page.url());
    if (current.origin !== 'https://pms.meituan.com'
      || !current.hash.startsWith('#qk-workbench')
    ) {
      throw new Error('meituan_cloud_session_not_authenticated');
    }
    const collected = await collect(page);
    if (collected.loginVisible) throw new Error('meituan_cloud_session_expired');
    const time = shanghaiDateTime();
    if (time.date !== options.targetDate) {
      throw new Error('meituan_cloud_target_date_not_today');
    }
    const capture = buildCaptureFromResponses({
      ...collected,
      expectedHotelName: options.expectedHotelName,
      targetDate: options.targetDate,
      capturedAt: time.dateTime,
    });
    process.stdout.write(`${JSON.stringify({
      status: 'captured_unverified',
      capture,
      raw_response_exposed: false,
      session_material_exposed: false,
    })}\n`);
  } finally {
    await browser.close();
  }
}

const direct = process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url;
if (direct) {
  main().catch((error) => {
    process.stderr.write(`${JSON.stringify({
      status: 'blocked',
      reason: safeReason(error),
    })}\n`);
    process.exit(1);
  });
}

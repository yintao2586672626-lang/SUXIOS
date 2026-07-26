#!/usr/bin/env node
import { pathToFileURL } from 'node:url';
import { chromium } from 'playwright-core';

export const SOURCE_URL =
  'https://www.dingdandao.com/pmsManage/report/pro/dataCenter/accommodationData';

export const DINGDANDAO_API_PATHS = Object.freeze({
  identity: '/v2/ntw/web/ntw/get',
  total: '/v2/um-b/web/pro/data/businessIndicatorsTotal',
  sumDetail: '/v2/um-b/web/pro/data/businessIndicatorsSumDetail',
  trend: '/v2/um-b/web/pro/data/businessIndicatorsTrend',
  dailyDetail: '/v2/um-b/web/pro/data/businessIndicatorsDailyDetail',
});

export const DINGDANDAO_DETAIL_TYPES = Object.freeze({
  roomFee: 0,
  roomNights: 1,
  occupancyRate: 2,
  revpar: 3,
});

export const DINGDANDAO_TREND_TYPES = Object.freeze({
  totalRoomFee: 5,
});

const DINGDANDAO_API_PATH_SET = new Set(Object.values(DINGDANDAO_API_PATHS));

const SUMMARY_DEFINITIONS = {
  total_room_fee: {
    labels: ['总房费'],
    keys: ['totalroomfee', 'roomfeetotal', 'totalroomamount'],
  },
  adr: {
    labels: ['ADR', '平均房价'],
    keys: ['adr', 'averageroomprice', 'averageprice'],
  },
  occupancy_rate_percent: {
    labels: ['入住率'],
    keys: ['occupancyrate', 'occupancyratepercent', 'occ'],
  },
  revpar: {
    labels: ['RevPAR', '平均客房收益'],
    keys: ['revpar', 'averageroomrevenue'],
  },
  sold_room_nights: {
    labels: ['累计售出间夜', '售出间夜'],
    keys: ['soldroomnights', 'cumulativesoldroomnights', 'roomsoldnights', 'totalsalesnight'],
  },
  average_daily_room_nights: {
    labels: ['平均每日间夜'],
    keys: ['averagedailyroomnights', 'avgdailyroomnights', 'adn'],
  },
};

function normalizeKey(value) {
  return String(value || '').toLowerCase().replace(/[^a-z0-9\u4e00-\u9fff]/g, '');
}

function normalizeText(value) {
  return String(value ?? '').replace(/\s+/g, ' ').trim();
}

function normalizeHotelName(value) {
  return normalizeText(value).toLowerCase().replace(/[\s·•（）()\-—_]/g, '');
}

function numberFromText(value) {
  const text = normalizeText(value);
  if (text === '' || /^(?:--|-|暂无|无)$/.test(text)) return null;
  const match = text.replace(/,/g, '').match(/-?\d+(?:\.\d+)?/);
  if (!match) return null;
  let number = Number(match[0]);
  if (!Number.isFinite(number) || number < 0) return null;
  if (/万/.test(text)) number *= 10000;
  return Math.round(number * 100) / 100;
}

function linesFromText(value) {
  return String(value || '')
    .split(/\r?\n/)
    .map(normalizeText)
    .filter(Boolean);
}

function metricFromLines(lines, labels) {
  for (const label of labels) {
    for (let index = 0; index < lines.length; index += 1) {
      if (!lines[index].toLowerCase().includes(label.toLowerCase())) continue;
      const sameLine = normalizeText(lines[index].replace(new RegExp(label, 'i'), ''));
      const candidates = [sameLine, lines[index + 1], lines[index + 2]].filter(Boolean);
      for (const candidate of candidates) {
        const value = numberFromText(candidate);
        if (value !== null) {
          return { value, trace: `DOM:经营指标/${label}` };
        }
      }
    }
  }
  return { value: null, trace: null };
}

function objectEntries(value, path = '$', depth = 0, result = []) {
  if (depth > 8 || value == null || typeof value !== 'object') return result;
  if (!Array.isArray(value)) result.push({ value, path });
  const entries = Array.isArray(value) ? value.entries() : Object.entries(value);
  for (const [key, child] of entries) {
    if (child != null && typeof child === 'object') {
      objectEntries(child, `${path}.${String(key).slice(0, 64)}`, depth + 1, result);
    }
  }
  return result;
}

function findValueByKeys(object, acceptedKeys) {
  for (const [key, value] of Object.entries(object || {})) {
    if (acceptedKeys.includes(normalizeKey(key))) return { value, key };
  }
  return { value: null, key: null };
}

export function extractNetworkCandidate(payload, sourceApiPath = null) {
  let best = null;
  for (const entry of objectEntries(payload)) {
    const summary = {};
    const trace = {};
    let present = 0;
    for (const [field, definition] of Object.entries(SUMMARY_DEFINITIONS)) {
      const found = findValueByKeys(entry.value, definition.keys);
      const value = numberFromText(found.value);
      summary[field] = value;
      if (value !== null) {
        present += 1;
        trace[field] = `API:${sourceApiPath || '/unknown'}#${entry.path}.${found.key}`;
      }
    }
    if (present >= 4 && (!best || present > best.present)) {
      const hotelId = findValueByKeys(entry.value, ['hotelid', 'storeid', 'shopid', 'propertyid']);
      const hotelName = findValueByKeys(entry.value, ['hotelname', 'storename', 'shopname', 'propertyname']);
      const businessDate = findValueByKeys(entry.value, ['businessdate', 'statdate', 'reportdate']);
      best = {
        present,
        summary,
        field_trace: trace,
        provider_hotel_id: normalizeText(hotelId.value) || null,
        provider_hotel_name: normalizeText(hotelName.value) || null,
        business_date: /^\d{4}-\d{2}-\d{2}$/.test(normalizeText(businessDate.value))
          ? normalizeText(businessDate.value)
          : null,
        source_api_path: sourceApiPath,
      };
    }
  }
  return best;
}

function identityFromControls(controls, expectedHotelName) {
  const expected = normalizeHotelName(expectedHotelName);
  const candidates = [];
  for (const control of controls || []) {
    const context = normalizeText(`${control.label || ''} ${control.context || ''}`);
    if (!/(酒店|门店|项目)/.test(context)) continue;
    const name = normalizeText(control.selectedText || control.value);
    if (!name || /(请选择|全部门店|全部酒店)/.test(name)) continue;
    candidates.push({
      name,
      id: normalizeText(control.optionValue) || null,
      evidence: control.authoritative === true ? 'platform_store_selector' : 'unverified',
    });
  }
  return candidates.find((candidate) => normalizeHotelName(candidate.name) === expected)
    || candidates[0]
    || { name: null, id: null, evidence: 'unverified' };
}

function businessDateFromSnapshot(snapshot) {
  for (const control of snapshot.controls || []) {
    if (!/(统计日期|营业日期|日期)/.test(`${control.label || ''} ${control.context || ''}`)) continue;
    const match = String(control.value || control.selectedText || '').match(/\d{4}-\d{2}-\d{2}/);
    if (match) return match[0];
  }
  const match = String(snapshot.bodyText || '').match(
    /(?:统计日期|营业日期|日期)\s*[:：]?\s*(\d{4}-\d{2}-\d{2})/,
  );
  return match ? match[1] : null;
}

function roomDetailsFromTables(tables) {
  const output = [];
  const seen = new Set();
  for (const table of tables || []) {
    const headers = (table.headers || []).map(normalizeText);
    const feeIndex = headers.findIndex((header) => /房费|房价金额|实收房费/.test(header));
    const typeIndex = headers.findIndex((header) => /房型/.test(header));
    const roomIndex = headers.findIndex((header) => /房间|房号/.test(header));
    if (feeIndex < 0 || (typeIndex < 0 && roomIndex < 0)) continue;
    for (const cells of table.rows || []) {
      const roomFee = numberFromText(cells[feeIndex]);
      if (roomFee === null) continue;
      const roomType = typeIndex >= 0 ? normalizeText(cells[typeIndex]) || null : null;
      const roomNumber = roomIndex >= 0 ? normalizeText(cells[roomIndex]) || null : null;
      const rowText = normalizeText(cells.join(' '));
      let rowKind = 'room';
      if (/小计|房型合计/.test(rowText)) rowKind = 'room_type_total';
      else if (/总计|合计/.test(rowText)) rowKind = 'grand_total';
      else if (/未分房|未排房|待排房/.test(rowText)) rowKind = 'unassigned';
      if (rowKind === 'room' && roomNumber === null) continue;
      const detail = {
        row_kind: rowKind,
        room_type: roomType,
        room_number: roomNumber,
        room_fee: roomFee,
      };
      const fingerprint = JSON.stringify(detail);
      if (seen.has(fingerprint)) continue;
      seen.add(fingerprint);
      output.push(detail);
      if (output.length >= 500) return output;
    }
  }
  return output;
}

export function buildCaptureFromSnapshot(
  snapshot,
  { expectedHotelName, targetDate, capturedAt = new Date().toISOString(), networkCandidates = [] },
) {
  const lines = linesFromText(snapshot.bodyText);
  const domSummary = {};
  const domTrace = {};
  for (const [field, definition] of Object.entries(SUMMARY_DEFINITIONS)) {
    const metric = metricFromLines(lines, definition.labels);
    domSummary[field] = metric.value;
    if (metric.trace) domTrace[field] = metric.trace;
  }

  const completeNetwork = networkCandidates
    .filter(Boolean)
    .sort((a, b) => b.present - a.present)
    .find((candidate) => candidate.present === Object.keys(SUMMARY_DEFINITIONS).length);
  const summary = completeNetwork ? completeNetwork.summary : domSummary;
  const fieldTrace = completeNetwork ? completeNetwork.field_trace : domTrace;
  const controlIdentity = identityFromControls(snapshot.controls, expectedHotelName);
  const networkIdentity = networkCandidates.find(
    (candidate) => candidate?.provider_hotel_id && candidate?.provider_hotel_name,
  );
  const providerHotelName = networkIdentity?.provider_hotel_name || controlIdentity.name;
  const providerHotelId = networkIdentity?.provider_hotel_id || controlIdentity.id;
  const identityEvidenceType = networkIdentity
    ? 'verified_api_store_identity'
    : controlIdentity.evidence;
  const businessDate = completeNetwork?.business_date
    || businessDateFromSnapshot(snapshot);

  return {
    source_url: SOURCE_URL,
    source_api_path: completeNetwork?.source_api_path || null,
    source_scope: 'today_only',
    capture_method: completeNetwork ? 'network_response' : 'browser_assist_dom',
    captured_at: capturedAt,
    business_date: businessDate,
    provider_hotel_id: providerHotelId,
    provider_hotel_name: providerHotelName,
    identity_evidence_type: identityEvidenceType,
    summary,
    room_fee_details: roomDetailsFromTables(snapshot.tables),
    trend: {},
    field_trace: fieldTrace,
    target_date_matches: businessDate === targetDate,
  };
}

function successfulResponseData(records, path, queryType = undefined) {
  const record = records.find((candidate) => (
    candidate?.method === 'POST'
    && candidate?.path === path
    && (queryType === undefined || candidate?.query_type === queryType)
    && candidate?.status === 200
    && candidate?.payload
    && typeof candidate.payload === 'object'
    && String(candidate.payload.code) === '1'
    && candidate.payload.data != null
  ));
  return record?.payload?.data ?? null;
}

function dailyRateForDate(row, targetDate) {
  const rates = Array.isArray(row?.dailyRoomRate) ? row.dailyRoomRate : [];
  const rate = rates.find((candidate) => normalizeText(candidate?.date) === targetDate);
  const price = numberFromText(rate?.price);
  return rate && price !== null
    ? { date: targetDate, price }
    : null;
}

function roomFeeDetailsFromResponses(sumDetail, dailyDetail, targetDate) {
  const roomTypes = Array.isArray(sumDetail?.list) ? sumDetail.list : [];
  const typeNameById = new Map(
    roomTypes.map((row) => [
      normalizeText(row?.roomTypeId),
      normalizeText(row?.roomTypeName) || null,
    ]),
  );
  const rows = Array.isArray(dailyDetail?.list) ? dailyDetail.list : [];
  const details = [];
  for (const row of rows.slice(0, 500)) {
    const rate = dailyRateForDate(row, targetDate);
    if (!rate) continue;
    const roomTypeId = normalizeText(row?.roomTypeId);
    const roomId = normalizeText(row?.roomId);
    const roomName = normalizeText(row?.roomName);
    let rowKind = 'unassigned';
    if (roomTypeId && roomId === '0') rowKind = 'unassigned';
    else if (roomTypeId && roomId) rowKind = 'room';
    else if (roomTypeId) rowKind = 'room_type_total';
    else if (!roomId && /(?:合计|总计)/.test(roomName)) rowKind = 'grand_total';
    const detail = {
      row_kind: rowKind,
      room_type: typeNameById.get(roomTypeId) || null,
      room_number: ['room', 'unassigned'].includes(rowKind) ? (roomName || null) : null,
      room_fee: rate.price,
    };
    details.push(detail);
  }
  return details;
}

function trendFromResponse(trendData, targetDate) {
  const points = Array.isArray(trendData?.list) ? trendData.list : [];
  const targetTimestamp = Date.parse(`${targetDate}T00:00:00Z`);
  if (!Number.isFinite(targetTimestamp)) return {};
  const minimumTimestamp = targetTimestamp - (30 * 24 * 60 * 60 * 1000);
  const byDate = new Map();
  for (const point of points.slice(0, 100)) {
    const date = normalizeText(point?.date);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) continue;
    const timestamp = Date.parse(`${date}T00:00:00Z`);
    if (!Number.isFinite(timestamp)
      || timestamp < minimumTimestamp
      || timestamp > targetTimestamp
    ) continue;
    const value = numberFromText(point?.value);
    if (value !== null) byDate.set(date, { date, value });
  }
  const normalized = [...byDate.values()]
    .sort((left, right) => left.date.localeCompare(right.date))
    .slice(-31);
  return normalized.length === 0 ? {} : { total_room_fee: normalized };
}

export function buildCaptureFromDingdandaoResponses(
  records,
  { targetDate, capturedAt = new Date().toISOString() },
) {
  const identity = successfulResponseData(records, DINGDANDAO_API_PATHS.identity);
  const total = successfulResponseData(records, DINGDANDAO_API_PATHS.total);
  const sumDetail = successfulResponseData(
    records,
    DINGDANDAO_API_PATHS.sumDetail,
    DINGDANDAO_DETAIL_TYPES.roomFee,
  );
  const dailyDetail = successfulResponseData(
    records,
    DINGDANDAO_API_PATHS.dailyDetail,
    DINGDANDAO_DETAIL_TYPES.roomFee,
  );
  const trendData = successfulResponseData(
    records,
    DINGDANDAO_API_PATHS.trend,
    DINGDANDAO_TREND_TYPES.totalRoomFee,
  );
  const summary = {
    total_room_fee: numberFromText(total?.totalRoomFee),
    adr: numberFromText(total?.adr),
    occupancy_rate_percent: numberFromText(total?.occ),
    revpar: numberFromText(total?.revPar),
    sold_room_nights: numberFromText(total?.totalSalesNight),
    average_daily_room_nights: numberFromText(total?.adn),
  };
  const fieldTrace = {
    provider_hotel_identity:
      `API:${DINGDANDAO_API_PATHS.identity}#data.id+data.name`,
    total_room_fee: `API:${DINGDANDAO_API_PATHS.total}#data.totalRoomFee`,
    adr: `API:${DINGDANDAO_API_PATHS.total}#data.adr`,
    occupancy_rate_percent: `API:${DINGDANDAO_API_PATHS.total}#data.occ`,
    revpar: `API:${DINGDANDAO_API_PATHS.total}#data.revPar`,
    sold_room_nights: `API:${DINGDANDAO_API_PATHS.total}#data.totalSalesNight`,
    average_daily_room_nights: `API:${DINGDANDAO_API_PATHS.total}#data.adn`,
    room_type_names:
      `API:${DINGDANDAO_API_PATHS.sumDetail}?type=${DINGDANDAO_DETAIL_TYPES.roomFee}#data.list[]`,
    room_fee_details:
      `API:${DINGDANDAO_API_PATHS.dailyDetail}?type=${DINGDANDAO_DETAIL_TYPES.roomFee}#data.list[].dailyRoomRate[]`,
    trend:
      `API:${DINGDANDAO_API_PATHS.trend}?type=${DINGDANDAO_TREND_TYPES.totalRoomFee}#data.list[]`,
  };
  const providerHotelId = normalizeText(identity?.id) || null;
  const providerHotelName = normalizeText(identity?.name) || null;
  const details = roomFeeDetailsFromResponses(sumDetail, dailyDetail, targetDate);
  const observedDates = new Set();
  for (const row of Array.isArray(dailyDetail?.list) ? dailyDetail.list : []) {
    for (const rate of Array.isArray(row?.dailyRoomRate) ? row.dailyRoomRate : []) {
      const date = normalizeText(rate?.date);
      if (/^\d{4}-\d{2}-\d{2}$/.test(date)) observedDates.add(date);
    }
  }
  const businessDate = observedDates.size === 1 ? [...observedDates][0] : null;
  return {
    source_url: SOURCE_URL,
    source_api_path: DINGDANDAO_API_PATHS.total,
    source_scope: 'today_only',
    capture_method: 'network_response',
    captured_at: capturedAt,
    business_date: businessDate,
    provider_hotel_id: providerHotelId,
    provider_hotel_name: providerHotelName,
    identity_evidence_type: providerHotelId && providerHotelName
      ? 'verified_api_store_identity'
      : 'unverified',
    summary,
    room_fee_details: details,
    trend: trendFromResponse(trendData, targetDate),
    field_trace: fieldTrace,
    target_date_matches: businessDate === targetDate,
  };
}

async function pageSnapshot(page) {
  return await page.evaluate(() => {
    const visible = (element) => {
      const style = getComputedStyle(element);
      return style.visibility !== 'hidden'
        && style.display !== 'none'
        && (element.offsetWidth > 0 || element.offsetHeight > 0);
    };
    const text = (value) => String(value || '').replace(/\s+/g, ' ').trim();
    const controls = [];
    for (const element of document.querySelectorAll('select,input[role="combobox"],input')) {
      if (!visible(element)) continue;
      const id = element.getAttribute('id');
      const explicitLabel = id
        ? document.querySelector(`label[for="${CSS.escape(id)}"]`)?.textContent
        : '';
      const context = element.closest('label,.form-item,.el-form-item,.ant-form-item,[class*="form-item"]')
        ?.textContent;
      const selected = element.tagName === 'SELECT'
        ? element.selectedOptions?.[0]
        : null;
      controls.push({
        label: text(explicitLabel || element.getAttribute('aria-label') || element.getAttribute('placeholder')),
        context: text(context).slice(0, 240),
        value: text(element.value),
        selectedText: text(selected?.textContent),
        optionValue: text(selected?.value),
        authoritative: element.tagName === 'SELECT'
          || element.getAttribute('role') === 'combobox',
      });
      if (controls.length >= 100) break;
    }

    const tables = [];
    for (const table of document.querySelectorAll('table')) {
      if (!visible(table)) continue;
      const headers = Array.from(table.querySelectorAll('thead th'))
        .map((cell) => text(cell.textContent));
      const rows = Array.from(table.querySelectorAll('tbody tr')).slice(0, 550)
        .map((row) => Array.from(row.querySelectorAll('td')).map((cell) => text(cell.textContent)));
      if (headers.length > 0 && rows.length > 0) tables.push({ headers, rows });
      if (tables.length >= 20) break;
    }
    return {
      bodyText: String(document.body?.innerText || '').slice(0, 250000),
      controls,
      tables,
    };
  });
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
  if (!normalizeText(values['expected-hotel-name'])) {
    throw new Error('capture_expected_hotel_name_missing');
  }
  return {
    cdpUrl: cdpUrl.toString().replace(/\/$/, ''),
    targetDate: values['target-date'],
    expectedHotelName: normalizeText(values['expected-hotel-name']).slice(0, 160),
    timeoutMs: Math.min(30000, Math.max(3000, Number.parseInt(values['timeout-ms'] || '12000', 10))),
  };
}

function safeReason(error) {
  return String(error?.message || error || 'dingdandao_capture_failed')
    .toLowerCase()
    .replace(/[^a-z0-9_-]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 80) || 'dingdandao_capture_failed';
}

async function main() {
  const options = parseArguments(process.argv.slice(2));
  const browser = await chromium.connectOverCDP(options.cdpUrl);
  try {
    const pages = browser.contexts().flatMap((context) => context.pages());
    const page = pages.find((candidate) => {
      try {
        const url = new URL(candidate.url());
        const source = new URL(SOURCE_URL);
        return url.origin === source.origin && url.pathname === source.pathname;
      } catch {
        return false;
      }
    }) || pages[0];
    if (!page) throw new Error('capture_page_missing');

    const networkCandidates = [];
    const responseRecords = [];
    const responseTasks = [];
    const responseHandler = (response) => {
      const task = (async () => {
      try {
        const request = response.request();
        const url = new URL(response.url());
        if (url.hostname !== 'www.dingdandao.com'
          || !/json/i.test(response.headers()['content-type'] || '')) return;
        const payload = await response.json();
        if (request.method() === 'POST' && DINGDANDAO_API_PATH_SET.has(url.pathname)) {
          let queryType = null;
          try {
            const requestBody = JSON.parse(request.postData() || '{}');
            if (Number.isInteger(requestBody?.type)) queryType = requestBody.type;
          } catch {
            // The response remains usable for endpoints without a type selector.
          }
          responseRecords.push({
            method: 'POST',
            path: url.pathname,
            status: response.status(),
            query_type: queryType,
            payload,
          });
          return;
        }
        if (request.method() !== 'GET') return;
        const candidate = extractNetworkCandidate(payload, url.pathname);
        if (candidate) networkCandidates.push(candidate);
      } catch {
        // Missing or non-JSON responses remain missing facts; raw responses are never emitted.
      }
      })();
      responseTasks.push(task);
    };
    page.on('response', responseHandler);

    const cdp = await page.context().newCDPSession(page);
    await cdp.send('Network.enable');
    await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
    await page.reload({ waitUntil: 'domcontentloaded', timeout: options.timeoutMs });
    const current = new URL(page.url());
    const source = new URL(SOURCE_URL);
    if (current.origin !== source.origin || current.pathname !== source.pathname) {
      throw new Error('capture_session_not_authenticated');
    }
    const tabItems = page.locator('.qd-tabs__item');
    const tabLabels = await tabItems.allTextContents();
    const operatingTargetTab = tabLabels.findIndex(
      (label) => normalizeText(label) === '\u7ecf\u8425\u6307\u6807',
    );
    if (operatingTargetTab >= 0) {
      await tabItems.nth(operatingTargetTab).click();
    }
    const roomFeeDetailTab = page.getByText('\u623f\u8d39\u660e\u7ec6', { exact: true });
    if (await roomFeeDetailTab.count() > 0) {
      await roomFeeDetailTab.last().click();
    }
    const totalRoomFeeTrendTab = page.getByText('\u603b\u623f\u8d39', { exact: true });
    if (await totalRoomFeeTrendTab.count() > 0) {
      await totalRoomFeeTrendTab.last().click();
    }
    await page.waitForTimeout(Math.min(7000, Math.floor(options.timeoutMs / 2)));
    page.off('response', responseHandler);
    await Promise.allSettled(responseTasks);
    const networkCapture = buildCaptureFromDingdandaoResponses(responseRecords, {
      targetDate: options.targetDate,
    });
    const networkComplete = Object.values(networkCapture.summary).every((value) => value !== null)
      && networkCapture.room_fee_details.length > 0
      && networkCapture.business_date !== null;
    const snapshot = await pageSnapshot(page);
    const capture = networkComplete
      ? networkCapture
      : buildCaptureFromSnapshot(snapshot, {
        expectedHotelName: options.expectedHotelName,
        targetDate: options.targetDate,
        networkCandidates,
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
    process.stderr.write(`${JSON.stringify({ status: 'blocked', reason: safeReason(error) })}\n`);
    process.exit(1);
  });
}

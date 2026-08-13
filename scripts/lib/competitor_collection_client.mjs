import { createHash } from 'node:crypto';

const PLATFORM_ALIASES = new Map([
  ['xc', 'xc'],
  ['ctrip', 'xc'],
  ['mt', 'mt'],
  ['meituan', 'mt'],
]);

const CTRIP_ROOM_ENDPOINT = '/restapi/soa2/33278/getHotelRoomListInland';
const MAX_RESPONSE_BYTES = 2_000_000;

export function normalizeCompetitorPlatform(value) {
  return PLATFORM_ALIASES.get(String(value || '').trim().toLowerCase()) || '';
}

export function normalizeCollectorServer(value) {
  const raw = String(value || '').trim().replace(/\/+$/u, '');
  let parsed;
  try {
    parsed = new URL(raw);
  } catch {
    throw new Error('collector_server_invalid');
  }
  if (!['http:', 'https:'].includes(parsed.protocol) || parsed.username || parsed.password || parsed.search || parsed.hash) {
    throw new Error('collector_server_invalid');
  }
  if (parsed.protocol === 'http:' && !['127.0.0.1', 'localhost'].includes(parsed.hostname)) {
    throw new Error('collector_server_https_required');
  }
  return parsed.href.replace(/\/$/u, '');
}

export function validateCompetitorTask(input) {
  const task = input && typeof input === 'object' ? input : {};
  const platform = normalizeCompetitorPlatform(task.platform);
  const captureScope = task.capture_scope && typeof task.capture_scope === 'object'
    ? task.capture_scope
    : {};
  const checkInDate = normalizeDate(captureScope.check_in_date);
  const checkOutDate = normalizeDate(captureScope.check_out_date);
  const checkIn = checkInDate ? Date.parse(`${checkInDate}T00:00:00Z`) : Number.NaN;
  const checkOut = checkOutDate ? Date.parse(`${checkOutDate}T00:00:00Z`) : Number.NaN;
  const normalized = {
    task_id: String(task.task_id || '').trim(),
    capture_scope_hash: String(task.capture_scope_hash || '').trim().toLowerCase(),
    store_id: positiveInteger(task.store_id),
    hotel_id: positiveInteger(task.hotel_id),
    hotel_name: boundedText(task.hotel_name, 160),
    city: boundedText(task.city, 80),
    platform,
    ota_hotel_id: numericHotelId(task.ota_hotel_id),
    capture_scope: {
      ota_hotel_id: numericHotelId(captureScope.ota_hotel_id || task.ota_hotel_id),
      check_in_date: checkInDate,
      check_out_date: checkOutDate,
      adults: nonNegativeInteger(captureScope.adults),
      children: nonNegativeInteger(captureScope.children),
      currency: String(captureScope.currency || '').trim().toUpperCase(),
      price_basis: String(captureScope.price_basis || '').trim().toLowerCase(),
      availability_values: Array.isArray(captureScope.availability_values)
        ? captureScope.availability_values.map(value => String(value || '').trim().toLowerCase()).filter(Boolean)
        : [],
    },
  };

  const valid = /^[a-f0-9]{32}$/u.test(normalized.task_id)
    && /^[a-f0-9]{64}$/u.test(normalized.capture_scope_hash)
    && normalized.store_id > 0
    && normalized.hotel_id > 0
    && ['xc', 'mt'].includes(normalized.platform)
    && normalized.ota_hotel_id !== ''
    && normalized.capture_scope.ota_hotel_id === normalized.ota_hotel_id
    && normalized.capture_scope.check_in_date !== ''
    && normalized.capture_scope.check_out_date !== ''
    && Number.isFinite(checkIn)
    && checkOut - checkIn === 86_400_000
    && normalized.capture_scope.adults > 0
    && normalized.capture_scope.children >= 0
    && normalized.capture_scope.currency === 'CNY'
    && normalized.capture_scope.price_basis === 'per_room_per_night'
    && normalized.capture_scope_hash === competitorCaptureScopeHash(normalized.capture_scope);
  if (!valid) throw new Error('competitor_task_scope_invalid');

  return normalized;
}

export function competitorCaptureScopeHash(captureScope = {}) {
  const normalized = {
    ota_hotel_id: String(captureScope.ota_hotel_id || '').trim(),
    check_in_date: String(captureScope.check_in_date || '').trim(),
    check_out_date: String(captureScope.check_out_date || '').trim(),
    adults: Number.isInteger(Number(captureScope.adults)) ? Number(captureScope.adults) : -1,
    children: Number.isInteger(Number(captureScope.children)) ? Number(captureScope.children) : -1,
    currency: String(captureScope.currency || '').trim().toUpperCase(),
    price_basis: String(captureScope.price_basis || '').trim().toLowerCase(),
  };
  return createHash('sha256').update(JSON.stringify(normalized)).digest('hex');
}

export function buildCompetitorPublicUrl(taskInput) {
  const task = validateCompetitorTask(taskInput);
  if (task.platform !== 'xc') {
    throw new Error('competitor_platform_adapter_unavailable');
  }
  const url = new URL(`https://hotels.ctrip.com/hotels/${task.ota_hotel_id}.html`);
  url.searchParams.set('checkIn', task.capture_scope.check_in_date);
  url.searchParams.set('checkOut', task.capture_scope.check_out_date);
  return url.href;
}

export function isCtripRoomResponseUrl(value) {
  try {
    const url = new URL(String(value || ''));
    return trustedCtripHost(url.hostname) && url.pathname === CTRIP_ROOM_ENDPOINT;
  } catch {
    return false;
  }
}

export function ctripRequestScopeMatchesTask(postData, taskInput) {
  const task = validateCompetitorTask(taskInput);
  const raw = String(postData || '');
  if (raw === '') return false;
  const scopeValues = { hotelId: [], checkIn: [], checkOut: [] };
  for (const payload of parseStructuredRequestBodies(raw)) {
    collectRequestScopeFields(payload, scopeValues);
  }
  return exactScopeValuesMatch(scopeValues.hotelId, task.ota_hotel_id, numericHotelId)
    && exactScopeValuesMatch(scopeValues.checkIn, task.capture_scope.check_in_date, normalizeDate)
    && exactScopeValuesMatch(scopeValues.checkOut, task.capture_scope.check_out_date, normalizeDate);
}

export function classifyCtripRoomResponse(payload) {
  if (!payload || typeof payload !== 'object') {
    return { status: 'collection_failed', reason: 'room_response_invalid' };
  }
  const actionCode = firstFiniteNumber(payload, [
    'data.htlSpiderActionErrorCode',
    'htlSpiderActionErrorCode',
  ]);
  if (actionCode !== null && actionCode !== 0) {
    return {
      status: 'verification_required',
      reason: 'ctrip_public_room_response_blocked',
      platform_code: actionCode,
    };
  }
  const responseAck = String(payload?.ResponseStatus?.Ack || '').trim().toLowerCase();
  if (responseAck !== '' && responseAck !== 'success') {
    return { status: 'collection_failed', reason: 'ctrip_room_response_not_success' };
  }
  return { status: 'ready', reason: '' };
}

export function classifyCtripPageUrl(value) {
  let url;
  try {
    url = new URL(String(value || ''));
  } catch {
    return { status: 'identity_mismatch', reason: 'public_page_url_invalid' };
  }
  if (!trustedCtripHost(url.hostname)) {
    return { status: 'identity_mismatch', reason: 'public_page_host_mismatch' };
  }
  const location = `${url.hostname}${url.pathname}`.toLowerCase();
  if (/passport|\/login(?:\/|$)|\/signin(?:\/|$)/u.test(location)) {
    return { status: 'login_required', reason: 'ctrip_profile_login_required' };
  }
  if (/captcha|challenge|verification|\/verify(?:\/|$)|riskcontrol/u.test(location)) {
    return { status: 'verification_required', reason: 'ctrip_browser_verification_required' };
  }
  return { status: 'ready', reason: '' };
}

export function extractCtripComparableRate(payload, taskInput, options = {}) {
  const task = validateCompetitorTask(taskInput);
  const classification = classifyCtripRoomResponse(payload);
  if (classification.status !== 'ready') return classification;
  if (options.responseBytes !== undefined && Number(options.responseBytes) > MAX_RESPONSE_BYTES) {
    return { status: 'collection_failed', reason: 'room_response_too_large' };
  }
  if (options.requestScopeMatched !== true) {
    return { status: 'identity_mismatch', reason: 'room_request_scope_mismatch' };
  }
  if (options.pageIdentityMatched !== true) {
    return { status: 'identity_mismatch', reason: 'public_page_hotel_identity_mismatch' };
  }
  if (!ctripResponseIdentityMatchesTask(payload, task)) {
    return { status: 'identity_mismatch', reason: 'room_response_scope_mismatch' };
  }

  const candidates = [];
  for (const collection of ctripTargetRoomCollections(payload)) {
    visitCtripRateNodes(collection, '', (object, inheritedRoomName) => {
    const roomName = firstText(object, ['roomName', 'roomTypeName', 'baseRoomName', 'physicalRoomName'])
      || inheritedRoomName;
    const ratePlanName = firstText(object, ['ratePlanName', 'rateName', 'productName', 'packageName']);
    const price = firstPositiveMoney(object, [
      'displayPrice', 'salePrice', 'afterCouponPrice', 'roomPrice', 'price', 'totalPrice', 'payPrice',
    ]);
    if (!roomName || !ratePlanName || price === null) return;

    const breakfast = firstText(object, ['breakfastName', 'breakfast', 'mealType', 'mealDesc']);
    const cancellationPolicy = firstText(object, ['cancellationPolicy', 'cancelPolicy', 'cancelDesc', 'cancellationDesc']);
    const paymentMode = firstText(object, ['paymentMode', 'paymentType', 'payType', 'payMode']);
    const taxIncluded = firstBoolean(object, ['taxFeeIncluded', 'taxIncluded', 'includeTax', 'isTaxIncluded']);
    if (!breakfast || !cancellationPolicy || !paymentMode || taxIncluded === null) return;

    const availability = normalizeAvailability(object);
    if (availability !== 'bookable') return;
    candidates.push({
      price,
      room_type_key: roomName,
      ota_product_id: firstText(object, ['productId', 'roomId', 'roomTypeId', 'ratePlanId']),
      rate_plan_key: ratePlanName,
      package_name: firstText(object, ['packageName', 'productName']),
      breakfast,
      cancellation_policy: cancellationPolicy,
      payment_mode: paymentMode,
      tax_fee_included: taxIncluded,
    });
    });
  }
  if (candidates.length === 0) {
    return { status: 'zero_rows', reason: 'comparable_rate_fields_missing' };
  }
  candidates.sort((left, right) => left.price - right.price);
  const selected = candidates[0];
  const collectedAt = normalizeCollectedAt(options.collectedAt);
  const sourceRef = sanitizeCtripSourceRef(options.sourceRef, task);

  return {
    status: 'collected',
    reason: '',
    candidate_count: candidates.length,
    report: {
      task_id: task.task_id,
      device_id: String(options.deviceId || '').trim(),
      store_id: task.store_id,
      hotel_id: task.hotel_id,
      platform: task.platform,
      city: task.city || '未标注',
      ota_hotel_id: task.ota_hotel_id,
      price_text: `¥${selected.price.toFixed(2)}`,
      availability: 'bookable',
      collected_at: collectedAt,
      source_method: 'local_browser_profile_response_json',
      source_ref: sourceRef,
      check_in_date: task.capture_scope.check_in_date,
      check_out_date: task.capture_scope.check_out_date,
      adults: task.capture_scope.adults,
      children: task.capture_scope.children,
      currency: task.capture_scope.currency,
      price_basis: task.capture_scope.price_basis,
      room_type_key: selected.room_type_key,
      ota_product_id: selected.ota_product_id,
      rate_plan_key: selected.rate_plan_key,
      package_name: selected.package_name,
      breakfast: selected.breakfast,
      cancellation_policy: selected.cancellation_policy,
      payment_mode: selected.payment_mode,
      tax_fee_included: selected.tax_fee_included ? 1 : 0,
    },
  };
}

export function sanitizedCollectorStatus(status, extra = {}) {
  const allowed = {};
  for (const key of ['task_count', 'reported_count', 'failed_count', 'status', 'reason', 'platform_code']) {
    if (extra[key] !== undefined) allowed[key] = extra[key];
  }
  return {
    timestamp: new Date().toISOString(),
    status: String(status || 'unknown'),
    ...allowed,
    sensitive_values_exposed: false,
  };
}

function normalizeDate(value) {
  const text = String(value || '').trim();
  if (!/^\d{4}-\d{2}-\d{2}$/u.test(text)) return '';
  const timestamp = Date.parse(`${text}T00:00:00Z`);
  return Number.isFinite(timestamp) && new Date(timestamp).toISOString().slice(0, 10) === text ? text : '';
}

function positiveInteger(value) {
  const number = Number(value);
  return Number.isInteger(number) && number > 0 ? number : 0;
}

function nonNegativeInteger(value) {
  const number = Number(value);
  return Number.isInteger(number) && number >= 0 ? number : -1;
}

function numericHotelId(value) {
  const text = String(value || '').trim();
  return /^[1-9][0-9]{0,19}$/u.test(text) ? text : '';
}

function boundedText(value, maxLength) {
  return String(value || '').replace(/\s+/gu, ' ').trim().slice(0, maxLength);
}

function trustedCtripHost(hostname) {
  const host = String(hostname || '').trim().toLowerCase().replace(/\.$/u, '');
  return host === 'ctrip.com' || host.endsWith('.ctrip.com')
    || host === 'ctripcorp.com' || host.endsWith('.ctripcorp.com');
}

function parseStructuredRequestBodies(raw) {
  const parsed = [];
  const seen = new Set();
  const tryJson = value => {
    const text = String(value || '').trim();
    if (text === '' || seen.has(text)) return;
    seen.add(text);
    try {
      const valueParsed = JSON.parse(text);
      if (valueParsed && typeof valueParsed === 'object') parsed.push(valueParsed);
    } catch {
      // Request bodies are also commonly application/x-www-form-urlencoded.
    }
  };
  tryJson(raw);
  try {
    tryJson(decodeURIComponent(raw.replace(/\+/gu, ' ')));
  } catch {
    // Malformed URL encoding remains an invalid, fail-closed request scope.
  }

  let formEntryCount = 0;
  for (const [key, value] of new URLSearchParams(raw)) {
    formEntryCount += 1;
    parsed.push({ [key]: value });
    tryJson(value);
    try {
      tryJson(decodeURIComponent(value.replace(/\+/gu, ' ')));
    } catch {
      // Ignore malformed nested form values; direct fields are still checked.
    }
  }
  if (formEntryCount === 0 && parsed.length === 0) return [];
  return parsed;
}

function collectRequestScopeFields(value, output, depth = 0, state = { count: 0 }) {
  if (depth > 10 || state.count > 5000 || value === null || value === undefined) return;
  if (Array.isArray(value)) {
    for (const item of value.slice(0, 500)) collectRequestScopeFields(item, output, depth + 1, state);
    return;
  }
  if (typeof value !== 'object') return;
  state.count += 1;
  for (const [key, fieldValue] of Object.entries(value).slice(0, 500)) {
    const normalizedKey = key.toLowerCase().replace(/[^a-z0-9]/gu, '');
    if (normalizedKey === 'hotelid' && ['string', 'number'].includes(typeof fieldValue)) {
      output.hotelId.push(fieldValue);
    } else if (['checkin', 'checkindate'].includes(normalizedKey) && ['string', 'number'].includes(typeof fieldValue)) {
      output.checkIn.push(fieldValue);
    } else if (['checkout', 'checkoutdate'].includes(normalizedKey) && ['string', 'number'].includes(typeof fieldValue)) {
      output.checkOut.push(fieldValue);
    }
    collectRequestScopeFields(fieldValue, output, depth + 1, state);
  }
}

function exactScopeValuesMatch(values, expected, normalizer) {
  return values.length > 0 && values.every(value => normalizer(value) === expected);
}

function ctripTargetRoomCollections(payload) {
  const data = payload?.data;
  if (!data || typeof data !== 'object' || Array.isArray(data)) return [];
  const collections = [];
  for (const key of ['roomGroups', 'roomList', 'hotelRoomList', 'rooms']) {
    if (Array.isArray(data[key])) collections.push(data[key]);
  }
  return collections;
}

function ctripResponseIdentityMatchesTask(payload, task) {
  const scopeValues = { hotelId: [], checkIn: [], checkOut: [] };
  collectDirectScopeFields(payload, scopeValues);
  collectDirectScopeFields(payload?.data, scopeValues);
  for (const collection of ctripTargetRoomCollections(payload)) {
    visitCtripRateNodes(collection, '', object => collectDirectScopeFields(object, scopeValues));
  }

  return optionalExactScopeValuesMatch(scopeValues.hotelId, task.ota_hotel_id, numericHotelId)
    && optionalExactScopeValuesMatch(scopeValues.checkIn, task.capture_scope.check_in_date, normalizeDate)
    && optionalExactScopeValuesMatch(scopeValues.checkOut, task.capture_scope.check_out_date, normalizeDate);
}

function collectDirectScopeFields(value, output) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return;
  for (const [key, fieldValue] of Object.entries(value)) {
    if (!['string', 'number'].includes(typeof fieldValue)) continue;
    const normalizedKey = key.toLowerCase().replace(/[^a-z0-9]/gu, '');
    if (normalizedKey === 'hotelid') output.hotelId.push(fieldValue);
    if (['checkin', 'checkindate'].includes(normalizedKey)) output.checkIn.push(fieldValue);
    if (['checkout', 'checkoutdate'].includes(normalizedKey)) output.checkOut.push(fieldValue);
  }
}

function optionalExactScopeValuesMatch(values, expected, normalizer) {
  return values.length === 0 || values.every(value => normalizer(value) === expected);
}

function visitCtripRateNodes(value, inheritedRoomName, visitor, depth = 0, state = { count: 0 }) {
  if (depth > 8 || state.count > 10_000 || value === null || value === undefined) return;
  if (Array.isArray(value)) {
    for (const item of value.slice(0, 1000)) {
      visitCtripRateNodes(item, inheritedRoomName, visitor, depth + 1, state);
    }
    return;
  }
  if (typeof value !== 'object') return;
  state.count += 1;
  const roomName = firstText(value, ['roomName', 'roomTypeName', 'baseRoomName', 'physicalRoomName'])
    || inheritedRoomName;
  visitor(value, roomName);
  for (const key of ['rooms', 'roomList', 'ratePlans', 'ratePlanList', 'rates', 'rateList', 'products', 'productList', 'packages', 'packageList']) {
    const child = value[key];
    if (child && typeof child === 'object') {
      visitCtripRateNodes(child, roomName, visitor, depth + 1, state);
    }
  }
}

function firstText(object, keys) {
  for (const key of keys) {
    const value = object?.[key];
    if (typeof value !== 'string' && typeof value !== 'number') continue;
    const text = boundedText(value, 500);
    if (text !== '') return text;
  }
  return '';
}

function firstPositiveMoney(object, keys) {
  for (const key of keys) {
    const raw = object?.[key];
    const values = raw && typeof raw === 'object'
      ? [raw.amount, raw.value, raw.price, raw.displayValue]
      : [raw];
    for (const value of values) {
      const normalized = String(value ?? '').replace(/[,，¥人民币元\s]/gu, '');
      if (!/^\d+(?:\.\d{1,2})?$/u.test(normalized)) continue;
      const amount = Number(normalized);
      if (Number.isFinite(amount) && amount > 0 && amount <= 1_000_000) return amount;
    }
  }
  return null;
}

function firstBoolean(object, keys) {
  for (const key of keys) {
    const value = object?.[key];
    if (value === true || value === 1 || String(value).toLowerCase() === 'true') return true;
    if (value === false || value === 0 || String(value).toLowerCase() === 'false') return false;
  }
  return null;
}

function normalizeAvailability(object) {
  for (const key of ['bookable', 'canBook', 'isBookable', 'available', 'isAvailable']) {
    const value = object?.[key];
    if (value === true || value === 1 || String(value).toLowerCase() === 'true') return 'bookable';
    if (value === false || value === 0 || String(value).toLowerCase() === 'false') return 'unavailable';
  }
  for (const key of ['stock', 'stockCount', 'remainingRooms', 'availableCount']) {
    const value = Number(object?.[key]);
    if (Number.isFinite(value)) return value > 0 ? 'bookable' : 'sold_out';
  }
  const status = firstText(object, ['availability', 'availabilityStatus', 'saleStatus', 'bookStatus']).toLowerCase();
  if (/bookable|available|可订|可售/u.test(status)) return 'bookable';
  if (/sold.?out|售罄|满房/u.test(status)) return 'sold_out';
  if (/unavailable|不可订|不可售/u.test(status)) return 'unavailable';
  return '';
}

function firstFiniteNumber(object, paths) {
  for (const path of paths) {
    let cursor = object;
    for (const part of path.split('.')) cursor = cursor?.[part];
    const value = Number(cursor);
    if (Number.isFinite(value)) return value;
  }
  return null;
}

function normalizeCollectedAt(value) {
  const date = value ? new Date(value) : new Date();
  if (!Number.isFinite(date.getTime())) throw new Error('collected_at_invalid');
  return date.toISOString();
}

function sanitizeCtripSourceRef(value, task) {
  const fallback = buildCompetitorPublicUrl(task);
  let url;
  try {
    url = new URL(String(value || fallback));
  } catch {
    throw new Error('source_ref_invalid');
  }
  if (!trustedCtripHost(url.hostname)
    || !new RegExp(`^/hotels/${task.ota_hotel_id}\\.html/?$`, 'u').test(url.pathname)
  ) {
    throw new Error('source_ref_identity_mismatch');
  }
  url.username = '';
  url.password = '';
  url.hash = '';
  return url.href;
}

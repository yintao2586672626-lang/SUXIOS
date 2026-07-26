#!/usr/bin/env node
import { pathToFileURL } from 'node:url';

export const SOURCE_URL =
  'https://www.dingdandao.com/pmsManage/report/pro/dataCenter/accommodationData';

export const DINGDANDAO_API_PATHS = Object.freeze({
  identity: '/v2/ntw/web/ntw/get',
  total: '/v2/um-b/web/pro/data/businessIndicatorsTotal',
  sumDetail: '/v2/um-b/web/pro/data/businessIndicatorsSumDetail',
  trend: '/v2/um-b/web/pro/data/businessIndicatorsTrend',
  dailyDetail: '/v2/um-b/web/pro/data/businessIndicatorsDailyDetail',
  countyTotal: '/v2/um-b/web/pro/data/businessIndicatorsTotal/county',
  countyTrend: '/v2/um-b/web/pro/data/businessIndicatorsTrend/county',
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

const DINGDANDAO_DETAIL_TYPE_SET = new Set(Object.values(DINGDANDAO_DETAIL_TYPES));
const DINGDANDAO_READABLE_TREND_TYPE_SET = new Set([0, 1, 2, 3, 5]);

function normalizeText(value) {
  return String(value ?? '').replace(/\s+/g, ' ').trim();
}

export function shanghaiToday(now = new Date()) {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Shanghai',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(now);
  const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
  return `${values.year}-${values.month}-${values.day}`;
}

function exactBodyKeys(body, keys) {
  return Object.keys(body).sort().join(',') === [...keys].sort().join(',');
}

export function classifyDingdandaoResponseRequest({ path, requestBody, targetDate }) {
  const blocked = { allowed: false };
  if (!requestBody || Array.isArray(requestBody) || typeof requestBody !== 'object') {
    return blocked;
  }
  if (requestBody.TIMEZONEOFFSET !== -480
    || typeof requestBody.ntwNum !== 'string'
    || !/^[A-Za-z0-9_-]{1,120}$/.test(requestBody.ntwNum)
  ) return blocked;
  if (path === DINGDANDAO_API_PATHS.identity) {
    if (!exactBodyKeys(requestBody, ['TIMEZONEOFFSET', 'ntwNum'])) return blocked;
    return {
      allowed: true,
      fact_kind: 'hotel_identity',
      query_type: null,
      scope_status: 'today_verified',
    };
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(targetDate)
    || requestBody.startDate !== targetDate
    || requestBody.endDate !== targetDate
  ) return blocked;
  if ([DINGDANDAO_API_PATHS.total, DINGDANDAO_API_PATHS.countyTotal].includes(path)) {
    if (!exactBodyKeys(requestBody, [
      'TIMEZONEOFFSET',
      'endDate',
      'festivalType',
      'ntwNum',
      'startDate',
    ]) || requestBody.festivalType !== -1200) return blocked;
    return {
      allowed: true,
      fact_kind: path === DINGDANDAO_API_PATHS.total
        ? 'hotel_total'
        : 'county_total',
      query_type: null,
      scope_status: 'today_verified',
    };
  }
  if ([DINGDANDAO_API_PATHS.sumDetail, DINGDANDAO_API_PATHS.dailyDetail].includes(path)) {
    if (!exactBodyKeys(requestBody, [
      'TIMEZONEOFFSET',
      'endDate',
      'ntwNum',
      'startDate',
      'type',
    ]) || !DINGDANDAO_DETAIL_TYPE_SET.has(requestBody.type)) return blocked;
    return {
      allowed: true,
      fact_kind: requestBody.type === DINGDANDAO_DETAIL_TYPES.roomFee
        ? 'room_fee'
        : 'auxiliary_detail',
      query_type: requestBody.type,
      scope_status: 'today_verified',
    };
  }
  if ([DINGDANDAO_API_PATHS.trend, DINGDANDAO_API_PATHS.countyTrend].includes(path)) {
    if (!exactBodyKeys(requestBody, [
      'TIMEZONEOFFSET',
      'endDate',
      'ntwNum',
      'startDate',
      'type',
    ]) || !DINGDANDAO_READABLE_TREND_TYPE_SET.has(requestBody.type)) return blocked;
    if (path === DINGDANDAO_API_PATHS.countyTrend
      && requestBody.type !== DINGDANDAO_TREND_TYPES.totalRoomFee
    ) return blocked;
    return {
      allowed: true,
      fact_kind: path === DINGDANDAO_API_PATHS.countyTrend
        ? 'county_total_room_fee_trend'
        : (requestBody.type === DINGDANDAO_TREND_TYPES.totalRoomFee
          ? 'total_room_fee_trend'
          : 'auxiliary_trend'),
      query_type: requestBody.type,
      scope_status: 'today_verified',
    };
  }
  return blocked;
}

class CdpConnection {
  constructor(socket) {
    this.socket = socket;
    this.nextId = 1;
    this.pending = new Map();
    this.closed = false;
    socket.addEventListener('message', (event) => {
      let message;
      try {
        message = JSON.parse(String(event.data));
      } catch {
        return;
      }
      if (!Number.isInteger(message.id) || !this.pending.has(message.id)) return;
      const pending = this.pending.get(message.id);
      this.pending.delete(message.id);
      clearTimeout(pending.timer);
      if (message.error) {
        pending.reject(new Error('capture_cdp_command_failed'));
      } else {
        pending.resolve(message.result || {});
      }
    });
    socket.addEventListener('close', () => {
      this.closed = true;
      for (const pending of this.pending.values()) {
        clearTimeout(pending.timer);
        pending.reject(new Error('capture_session_expired'));
      }
      this.pending.clear();
    });
  }

  async send(method, params = {}, sessionId = null) {
    if (this.closed || this.socket.readyState !== WebSocket.OPEN) {
      throw new Error('capture_session_expired');
    }
    const id = this.nextId++;
    const message = { id, method, params };
    if (sessionId) message.sessionId = sessionId;
    return await new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        this.pending.delete(id);
        reject(new Error('capture_cdp_command_timeout'));
      }, 5000);
      timer.unref?.();
      this.pending.set(id, { resolve, reject, timer });
      this.socket.send(JSON.stringify(message));
    });
  }

  close() {
    if (this.closed) return;
    this.closed = true;
    this.socket.close();
  }
}

async function connectCdp(cdpUrl, fetchImpl = fetch) {
  if (typeof WebSocket !== 'function') {
    throw new Error('capture_cdp_websocket_unavailable');
  }
  let response;
  try {
    response = await fetchImpl(`${cdpUrl}/json/version`, {
      signal: AbortSignal.timeout(3000),
    });
  } catch {
    throw new Error('capture_session_expired');
  }
  if (!response?.ok) throw new Error('capture_session_expired');
  let version;
  try {
    version = await response.json();
  } catch {
    throw new Error('capture_session_expired');
  }
  let webSocketUrl;
  try {
    webSocketUrl = new URL(String(version?.webSocketDebuggerUrl || ''));
  } catch {
    throw new Error('capture_session_expired');
  }
  const expectedCdp = new URL(cdpUrl);
  if (webSocketUrl.protocol !== 'ws:'
    || webSocketUrl.hostname !== '127.0.0.1'
    || webSocketUrl.port !== expectedCdp.port
    || webSocketUrl.username !== ''
    || webSocketUrl.password !== ''
    || !/^\/devtools\/browser\/[A-Za-z0-9_-]+$/.test(webSocketUrl.pathname)
  ) throw new Error('capture_cdp_scope_invalid');
  const socket = new WebSocket(webSocketUrl);
  await new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('capture_session_expired')), 5000);
    timer.unref?.();
    socket.addEventListener('open', () => {
      clearTimeout(timer);
      resolve();
    }, { once: true });
    socket.addEventListener('error', () => {
      clearTimeout(timer);
      reject(new Error('capture_session_expired'));
    }, { once: true });
  });
  return new CdpConnection(socket);
}

function validSessionText(value, maxLength, pattern = null) {
  const text = String(value || '').trim();
  if (text === ''
    || text.length > maxLength
    || /[\u0000-\u001f\u007f]/.test(text)
    || (pattern && !pattern.test(text))
  ) return null;
  return text;
}

function cookieHeaderForOrigin(cookies, origin, now = new Date()) {
  const host = new URL(origin).hostname;
  const nowSeconds = Math.floor(now.getTime() / 1000);
  const accepted = [];
  for (const cookie of Array.isArray(cookies) ? cookies : []) {
    const domain = String(cookie?.domain || '').replace(/^\./, '').toLowerCase();
    const name = validSessionText(
      cookie?.name,
      128,
      /^[!#$%&'*+\-.^_`|~0-9A-Za-z]+$/,
    );
    const value = String(cookie?.value || '');
    if (!name
      || value === ''
      || value.length > 4096
      || /[\r\n;]/.test(value)
      || !(host === domain || host.endsWith(`.${domain}`))
      || (Number(cookie?.expires) > 0 && Number(cookie.expires) <= nowSeconds)
    ) continue;
    accepted.push({
      name,
      value,
      pathLength: String(cookie?.path || '/').length,
    });
  }
  accepted.sort((left, right) => right.pathLength - left.pathLength);
  const byName = new Map();
  for (const cookie of accepted) {
    if (!byName.has(cookie.name)) byName.set(cookie.name, cookie.value);
  }
  const header = [...byName].map(([name, value]) => `${name}=${value}`).join('; ');
  return header.length > 0 && header.length <= 16384 ? header : null;
}

export function dingdandaoSessionMaterialFromStorage({
  entries,
  cookies,
  userAgent,
  now = new Date(),
}) {
  const items = new Map(
    (Array.isArray(entries) ? entries : [])
      .filter((entry) => Array.isArray(entry) && entry.length === 2)
      .map(([key, value]) => [String(key), String(value)]),
  );
  let networkInfo;
  try {
    networkInfo = JSON.parse(items.get('networkInfo') || '');
  } catch {
    throw new Error('capture_session_expired');
  }
  if (!networkInfo || Array.isArray(networkInfo) || typeof networkInfo !== 'object') {
    throw new Error('capture_session_expired');
  }
  const ntwNum = validSessionText(
    networkInfo.ntwNum,
    120,
    /^[A-Za-z0-9_-]+$/,
  );
  const ntwInviteCode = validSessionText(
    networkInfo.ntwInviteCode,
    120,
    /^[A-Za-z0-9_-]+$/,
  );
  const networkNumNew = validSessionText(
    items.get('networkNumNew'),
    120,
    /^[A-Za-z0-9_-]+$/,
  );
  const token = validSessionText(items.get('token'), 4096);
  const normalizedUserAgent = validSessionText(userAgent, 512);
  const cookieHeader = cookieHeaderForOrigin(
    cookies,
    'https://www.dingdandao.com',
    now,
  );
  if (!ntwNum
    || ntwNum !== ntwInviteCode
    || ntwNum !== networkNumNew
    || !token
    || !cookieHeader
    || !normalizedUserAgent
  ) throw new Error('capture_session_expired');
  return {
    ntwNum,
    token,
    cookieHeader,
    userAgent: normalizedUserAgent,
  };
}

export async function readDingdandaoSessionMaterial(
  cdpUrl,
  {
    fetchImpl = fetch,
    now = new Date(),
    connect = connectCdp,
  } = {},
) {
  const connection = await connect(cdpUrl, fetchImpl);
  let attachedSessionId = null;
  let createdTargetId = null;
  try {
    const { targetInfos } = await connection.send('Target.getTargets');
    const pages = (Array.isArray(targetInfos) ? targetInfos : [])
      .filter((target) => target?.type === 'page' && typeof target.targetId === 'string');
    let validContextIds = null;
    try {
      const contexts = await connection.send('Target.getBrowserContexts');
      if (Array.isArray(contexts?.browserContextIds)) {
        validContextIds = new Set(
          contexts.browserContextIds.filter((contextId) => (
            typeof contextId === 'string' && contextId !== ''
          )),
        );
      }
    } catch {
      validContextIds = null;
    }
    const contextCandidates = [];
    for (const page of [
      ...pages.filter((target) => String(target.url || '').startsWith(
        'https://www.dingdandao.com/',
      )),
      ...pages,
    ]) {
      const browserContextId = typeof page.browserContextId === 'string'
        ? page.browserContextId
        : '';
      if (!browserContextId) continue;
      if (validContextIds && !validContextIds.has(browserContextId)) continue;
      const key = browserContextId;
      if (contextCandidates.some((candidate) => candidate.key === key)) continue;
      contextCandidates.push({
        key,
        browserContextId,
      });
    }
    contextCandidates.push({
      key: '',
      browserContextId: null,
    });
    let selectedContext = null;
    let cookies = [];
    for (const candidate of contextCandidates) {
      let result;
      try {
        result = await connection.send(
          'Storage.getCookies',
          candidate.browserContextId
            ? { browserContextId: candidate.browserContextId }
            : {},
        );
      } catch {
        continue;
      }
      if (!cookieHeaderForOrigin(
        result?.cookies,
        'https://www.dingdandao.com',
        now,
      )) continue;
      selectedContext = candidate;
      cookies = result.cookies;
      break;
    }
    if (!selectedContext) throw new Error('capture_session_expired');
    let pageTarget = pages.find((target) => (
      String(target.url || '').startsWith('https://www.dingdandao.com/')
      && String(target.browserContextId || '') === selectedContext.key
    ));
    if (!pageTarget) {
      const createParams = {
        url: 'https://www.dingdandao.com/',
        background: true,
      };
      if (selectedContext.browserContextId) {
        createParams.browserContextId = selectedContext.browserContextId;
      }
      const created = await connection.send('Target.createTarget', createParams);
      createdTargetId = validSessionText(
        created?.targetId,
        160,
        /^[A-Za-z0-9_-]+$/,
      );
      if (!createdTargetId) throw new Error('capture_session_expired');
      pageTarget = {
        targetId: createdTargetId,
        browserContextId: selectedContext.browserContextId,
      };
    }
    const browserVersion = await connection.send('Browser.getVersion');
    const attached = await connection.send('Target.attachToTarget', {
      targetId: pageTarget.targetId,
      flatten: true,
    });
    attachedSessionId = validSessionText(
      attached?.sessionId,
      160,
      /^[A-Za-z0-9_-]+$/,
    );
    if (!attachedSessionId) throw new Error('capture_session_expired');
    await connection.send('Page.enable', {}, attachedSessionId);
    let sameOriginFrameReady = false;
    for (let attempt = 0; attempt < 30; attempt += 1) {
      const frameTree = await connection.send('Page.getFrameTree', {}, attachedSessionId);
      try {
        sameOriginFrameReady = new URL(
          String(frameTree?.frameTree?.frame?.url || ''),
        ).origin === 'https://www.dingdandao.com';
      } catch {
        sameOriginFrameReady = false;
      }
      if (sameOriginFrameReady) break;
      await new Promise((resolve) => setTimeout(resolve, 100));
    }
    if (!sameOriginFrameReady) throw new Error('capture_session_expired');
    await connection.send('DOMStorage.enable', {}, attachedSessionId);
    const storage = await connection.send('DOMStorage.getDOMStorageItems', {
      storageId: {
        securityOrigin: 'https://www.dingdandao.com',
        isLocalStorage: true,
      },
    }, attachedSessionId);
    return dingdandaoSessionMaterialFromStorage({
      entries: storage?.entries,
      cookies,
      userAgent: browserVersion?.userAgent,
      now,
    });
  } catch (error) {
    if (error?.message === 'capture_cdp_scope_invalid') throw error;
    throw new Error('capture_session_expired');
  } finally {
    if (attachedSessionId) {
      await connection.send('Target.detachFromTarget', {
        sessionId: attachedSessionId,
      }).catch(() => undefined);
    }
    if (createdTargetId) {
      await connection.send('Target.closeTarget', {
        targetId: createdTargetId,
      }).catch(() => undefined);
    }
    connection.close();
  }
}

export function dingdandaoDirectRequests(ntwNum, targetDate) {
  const base = {
    TIMEZONEOFFSET: -480,
    ntwNum: validSessionText(ntwNum, 120, /^[A-Za-z0-9_-]+$/),
  };
  if (!base.ntwNum || !/^\d{4}-\d{2}-\d{2}$/.test(targetDate)) {
    throw new Error('capture_direct_scope_invalid');
  }
  const dated = { ...base, startDate: targetDate, endDate: targetDate };
  const requests = [
    { path: DINGDANDAO_API_PATHS.identity, body: base },
    {
      path: DINGDANDAO_API_PATHS.total,
      body: { ...dated, festivalType: -1200 },
    },
  ];
  for (const type of [
    DINGDANDAO_DETAIL_TYPES.roomFee,
    DINGDANDAO_DETAIL_TYPES.roomNights,
    DINGDANDAO_DETAIL_TYPES.occupancyRate,
    DINGDANDAO_DETAIL_TYPES.revpar,
  ]) {
    requests.push(
      { path: DINGDANDAO_API_PATHS.sumDetail, body: { ...dated, type } },
      { path: DINGDANDAO_API_PATHS.dailyDetail, body: { ...dated, type } },
    );
  }
  requests.push(
    {
      path: DINGDANDAO_API_PATHS.trend,
      body: { ...dated, type: DINGDANDAO_TREND_TYPES.totalRoomFee },
    },
    {
      path: DINGDANDAO_API_PATHS.countyTotal,
      body: { ...dated, festivalType: -1200 },
    },
    {
      path: DINGDANDAO_API_PATHS.countyTrend,
      body: { ...dated, type: DINGDANDAO_TREND_TYPES.totalRoomFee },
    },
  );
  return requests;
}

async function postDingdandaoJson(
  path,
  body,
  sessionMaterial,
  { fetchImpl = fetch, timeoutMs = 12000 } = {},
) {
  let response;
  try {
    response = await fetchImpl(`https://www.dingdandao.com${path}`, {
      method: 'POST',
      redirect: 'manual',
      signal: AbortSignal.timeout(timeoutMs),
      headers: {
        accept: 'application/json, text/plain, */*',
        'content-type': 'application/json;charset=UTF-8',
        origin: 'https://www.dingdandao.com',
        referer: SOURCE_URL,
        'user-agent': sessionMaterial.userAgent,
        cookie: sessionMaterial.cookieHeader,
        token: sessionMaterial.token,
      },
      body: JSON.stringify(body),
    });
  } catch {
    throw new Error('capture_api_request_failed');
  }
  if ([301, 302, 303, 307, 308, 401, 403, 419].includes(response.status)) {
    throw new Error('capture_session_expired');
  }
  if (response.status !== 200
    || !/json/i.test(response.headers.get('content-type') || '')
  ) throw new Error('capture_api_response_unverified');
  let text;
  try {
    text = await response.text();
  } catch {
    throw new Error('capture_api_response_unverified');
  }
  if (Buffer.byteLength(text, 'utf8') > 2_000_000) {
    throw new Error('capture_api_response_too_large');
  }
  let payload;
  try {
    payload = JSON.parse(text);
  } catch {
    throw new Error('capture_api_response_unverified');
  }
  if (!payload
    || typeof payload !== 'object'
    || String(payload.code) !== '1'
    || payload.errorDetail != null
  ) {
    const message = String(payload?.msg || payload?.message || '');
    if (/(?:登录|失效|过期|unauth|login|session|token)/i.test(message)) {
      throw new Error('capture_session_expired');
    }
    throw new Error('capture_api_code_unverified');
  }
  return payload;
}

export async function collectDingdandaoDirect(
  {
    cdpUrl,
    targetDate,
    expectedHotelName,
    timeoutMs = 12000,
    capturedAt = new Date().toISOString(),
  },
  dependencies = {},
) {
  const now = dependencies.now instanceof Date ? dependencies.now : new Date();
  if (targetDate !== shanghaiToday(now)) {
    throw new Error('capture_target_date_not_today');
  }
  const readSession = dependencies.readSession || readDingdandaoSessionMaterial;
  const postJson = dependencies.postJson
    || ((path, body, session) => postDingdandaoJson(
      path,
      body,
      session,
      { timeoutMs },
    ));
  let sessionMaterial = await readSession(cdpUrl, { now });
  const records = [];
  try {
    for (const request of dingdandaoDirectRequests(sessionMaterial.ntwNum, targetDate)) {
      const classification = classifyDingdandaoResponseRequest({
        path: request.path,
        requestBody: request.body,
        targetDate,
      });
      if (!classification.allowed) throw new Error('capture_direct_request_blocked');
      const payload = await postJson(request.path, request.body, sessionMaterial);
      if (!payload
        || typeof payload !== 'object'
        || String(payload.code) !== '1'
        || payload.errorDetail != null
      ) {
        throw new Error('capture_api_code_unverified');
      }
      records.push({
        method: 'POST',
        path: request.path,
        status: 200,
        query_type: classification.query_type,
        fact_kind: classification.fact_kind,
        scope_status: classification.scope_status,
        payload,
      });
    }
  } finally {
    if (sessionMaterial && typeof sessionMaterial === 'object') {
      sessionMaterial.ntwNum = '';
      sessionMaterial.token = '';
      sessionMaterial.cookieHeader = '';
      sessionMaterial.userAgent = '';
    }
    sessionMaterial = null;
  }
  const capture = buildCaptureFromDingdandaoResponses(records, {
    targetDate,
    capturedAt,
  });
  if (!isTrustedDingdandaoCaptureComplete(capture, {
    targetDate,
    expectedHotelName,
  })) throw new Error('capture_exact_today_network_incomplete');
  return capture;
}

export async function probeDingdandaoIdentity(
  {
    cdpUrl,
    expectedHotelName,
    timeoutMs = 12000,
    capturedAt = new Date().toISOString(),
  },
  dependencies = {},
) {
  const now = dependencies.now instanceof Date ? dependencies.now : new Date();
  const expectedName = normalizeText(expectedHotelName);
  if (!expectedName || expectedName.length > 160) {
    throw new Error('capture_expected_hotel_name_missing');
  }
  const readSession = dependencies.readSession || readDingdandaoSessionMaterial;
  const postJson = dependencies.postJson
    || ((path, body, session) => postDingdandaoJson(
      path,
      body,
      session,
      { timeoutMs },
    ));
  let sessionMaterial = await readSession(cdpUrl, { now });
  try {
    const request = dingdandaoDirectRequests(
      sessionMaterial.ntwNum,
      shanghaiToday(now),
    )[0];
    const classification = classifyDingdandaoResponseRequest({
      path: request.path,
      requestBody: request.body,
      targetDate: shanghaiToday(now),
    });
    if (!classification.allowed || classification.fact_kind !== 'hotel_identity') {
      throw new Error('capture_identity_request_blocked');
    }
    const payload = await postJson(request.path, request.body, sessionMaterial);
    if (!payload
      || typeof payload !== 'object'
      || String(payload.code) !== '1'
      || payload.errorDetail != null
      || !payload.data
      || typeof payload.data !== 'object'
    ) {
      throw new Error('capture_identity_response_unverified');
    }
    const providerHotelId = normalizeText(payload.data.id);
    const providerHotelName = normalizeText(payload.data.name);
    if (!providerHotelId
      || providerHotelId.length > 120
      || !/^[A-Za-z0-9_-]+$/.test(providerHotelId)
      || !providerHotelName
      || providerHotelName.length > 160
      || providerHotelName !== expectedName
    ) {
      throw new Error('capture_identity_mismatch');
    }
    return {
      provider_hotel_id: providerHotelId,
      provider_hotel_name: providerHotelName,
      identity_status: 'matched',
      source_api_path: DINGDANDAO_API_PATHS.identity,
      capture_method: 'existing_session_direct_post',
      request_count: 1,
      captured_at: capturedAt,
    };
  } finally {
    if (sessionMaterial && typeof sessionMaterial === 'object') {
      sessionMaterial.ntwNum = '';
      sessionMaterial.token = '';
      sessionMaterial.cookieHeader = '';
      sessionMaterial.userAgent = '';
    }
    sessionMaterial = null;
  }
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

function successfulResponseData(records, path, factKind, queryType = undefined) {
  const record = records.find((candidate) => (
    candidate?.method === 'POST'
    && candidate?.path === path
    && candidate?.scope_status === 'today_verified'
    && candidate?.fact_kind === factKind
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
  const identity = successfulResponseData(
    records,
    DINGDANDAO_API_PATHS.identity,
    'hotel_identity',
  );
  const total = successfulResponseData(
    records,
    DINGDANDAO_API_PATHS.total,
    'hotel_total',
  );
  const sumDetail = successfulResponseData(
    records,
    DINGDANDAO_API_PATHS.sumDetail,
    'room_fee',
    DINGDANDAO_DETAIL_TYPES.roomFee,
  );
  const dailyDetail = successfulResponseData(
    records,
    DINGDANDAO_API_PATHS.dailyDetail,
    'room_fee',
    DINGDANDAO_DETAIL_TYPES.roomFee,
  );
  const trendData = successfulResponseData(
    records,
    DINGDANDAO_API_PATHS.trend,
    'total_room_fee_trend',
    DINGDANDAO_TREND_TYPES.totalRoomFee,
  );
  const countyTotal = successfulResponseData(
    records,
    DINGDANDAO_API_PATHS.countyTotal,
    'county_total',
  );
  const countyTrend = successfulResponseData(
    records,
    DINGDANDAO_API_PATHS.countyTrend,
    'county_total_room_fee_trend',
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
  const auxiliaryQueryStatus = records
    .filter((record) => (
      record?.scope_status === 'today_verified'
      && record?.fact_kind === 'auxiliary_detail'
      && [1, 2, 3].includes(record?.query_type)
      && [DINGDANDAO_API_PATHS.sumDetail, DINGDANDAO_API_PATHS.dailyDetail]
        .includes(record?.path)
      && record?.status === 200
      && String(record?.payload?.code) === '1'
    ))
    .map((record) => ({
      api_path: record.path,
      type: record.query_type,
      fact_scope: 'auxiliary_metric_only',
      status: 'readable_not_promoted',
    }));
  const countySummary = {
    total_room_fee: numberFromText(countyTotal?.totalRoomFee),
    adr: numberFromText(countyTotal?.adr),
    occupancy_rate_percent: numberFromText(countyTotal?.occ),
    revpar: numberFromText(countyTotal?.revPar),
    sold_room_nights: numberFromText(countyTotal?.totalSalesNight),
    average_daily_room_nights: numberFromText(countyTotal?.adn),
  };
  const countyTrendNormalized = trendFromResponse(countyTrend, targetDate);
  const countyComplete = Object.values(countySummary).every(
    (value) => typeof value === 'number' && Number.isFinite(value),
  ) && Array.isArray(countyTrendNormalized.total_room_fee);
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
    auxiliary_query_status: auxiliaryQueryStatus,
    county_context: {
      fact_scope: 'county_diagnostic_only',
      data_status: countyComplete ? 'readable_separate' : 'partial',
      bool_city: typeof countyTotal?.boolCity === 'boolean'
        ? countyTotal.boolCity
        : (typeof countyTrend?.boolCity === 'boolean' ? countyTrend.boolCity : null),
      summary: countySummary,
      trend: countyTrendNormalized,
      field_trace: {
        summary: `API:${DINGDANDAO_API_PATHS.countyTotal}#data`,
        trend:
          `API:${DINGDANDAO_API_PATHS.countyTrend}?type=${DINGDANDAO_TREND_TYPES.totalRoomFee}#data.list[]`,
      },
    },
    field_trace: fieldTrace,
    target_date_matches: businessDate === targetDate,
  };
}

export function isTrustedDingdandaoCaptureComplete(
  capture,
  { targetDate, expectedHotelName },
) {
  if (!capture
    || capture.capture_method !== 'network_response'
    || capture.source_scope !== 'today_only'
    || capture.business_date !== targetDate
    || capture.target_date_matches !== true
    || capture.identity_evidence_type !== 'verified_api_store_identity'
    || !capture.provider_hotel_id
    || normalizeText(capture.provider_hotel_name) !== normalizeText(expectedHotelName)
  ) return false;
  if (!Object.values(capture.summary || {}).every(
    (value) => typeof value === 'number' && Number.isFinite(value) && value >= 0,
  )) return false;
  if (!Array.isArray(capture.room_fee_details)
    || capture.room_fee_details.length === 0
    || !capture.room_fee_details.every(
      (row) => typeof row?.room_fee === 'number'
        && Number.isFinite(row.room_fee)
        && row.room_fee >= 0,
    )
  ) return false;
  const trend = capture.trend?.total_room_fee;
  return Array.isArray(trend)
    && trend.some((point) => (
      point?.date === targetDate
      && typeof point?.value === 'number'
      && Number.isFinite(point.value)
      && point.value >= 0
    ))
    && dingdandaoCaptureMathReconciles(capture, targetDate);
}

function dingdandaoCaptureMathReconciles(capture, targetDate) {
  const total = capture.summary.total_room_fee;
  const sold = capture.summary.sold_room_nights;
  const adr = capture.summary.adr;
  const occupancy = capture.summary.occupancy_rate_percent;
  const revpar = capture.summary.revpar;
  const averageDaily = capture.summary.average_daily_room_nights;
  const roomRows = capture.room_fee_details.filter(
    (row) => ['room', 'unassigned'].includes(row.row_kind),
  );
  if (roomRows.length === 0) return false;
  const roomTotal = Math.round(
    roomRows.reduce((sum, row) => sum + row.room_fee, 0) * 100,
  ) / 100;
  if (Math.abs(roomTotal - total) > 0.01) return false;
  const grandTotals = capture.room_fee_details.filter(
    (row) => row.row_kind === 'grand_total',
  );
  if (grandTotals.length > 0
    && Math.abs(grandTotals.at(-1).room_fee - total) > 0.01
  ) return false;
  const roomTypeTotals = capture.room_fee_details.filter(
    (row) => row.row_kind === 'room_type_total',
  );
  if (roomTypeTotals.length > 0) {
    const roomTypeTotal = Math.round(
      roomTypeTotals.reduce((sum, row) => sum + row.room_fee, 0) * 100,
    ) / 100;
    if (Math.abs(roomTypeTotal - total) > 0.01) return false;
  }
  if (sold > 0 && Math.abs(Math.round((total / sold) * 100) / 100 - adr) > 0.02) {
    return false;
  }
  if (Math.abs(sold - averageDaily) > 0.01) return false;
  if (occupancy > 0) {
    const sellable = sold / (occupancy / 100);
    if (Math.abs(sellable - Math.round(sellable)) > 0.01) return false;
    if (Math.round(sellable) > 0
      && Math.abs(
        Math.round((total / Math.round(sellable)) * 100) / 100 - revpar,
      ) > 0.02
    ) return false;
  }
  const targetTrend = capture.trend.total_room_fee.find(
    (point) => point.date === targetDate,
  );
  return Boolean(targetTrend) && Math.abs(targetTrend.value - total) <= 0.01;
}

function parseArguments(argv) {
  const values = {};
  for (const argument of argv) {
    const match = argument.match(/^--([a-z-]+)=(.*)$/);
    if (!match) throw new Error('capture_argument_invalid');
    values[match[1]] = match[2];
  }
  const cdpUrl = new URL(values['cdp-url'] || 'http://127.0.0.1:9223');
  if (cdpUrl.protocol !== 'http:'
    || cdpUrl.hostname !== '127.0.0.1'
    || !/^[1-9][0-9]{1,4}$/.test(cdpUrl.port)
    || Number(cdpUrl.port) > 65535
    || cdpUrl.pathname !== '/'
    || cdpUrl.search !== ''
    || cdpUrl.hash !== ''
    || cdpUrl.username !== ''
    || cdpUrl.password !== ''
  ) {
    throw new Error('capture_cdp_scope_invalid');
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(values['target-date'] || '')) {
    throw new Error('capture_target_date_invalid');
  }
  if (values['target-date'] !== shanghaiToday()) {
    throw new Error('capture_target_date_not_today');
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
  const capture = await collectDingdandaoDirect(options);
  process.stdout.write(`${JSON.stringify({
    status: 'captured_unverified',
    capture,
    raw_response_exposed: false,
    session_material_exposed: false,
    browser_opened: false,
    browser_closed: false,
  })}\n`);
}

const direct = process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url;
if (direct) {
  main().catch((error) => {
    process.stderr.write(`${JSON.stringify({ status: 'blocked', reason: safeReason(error) })}\n`);
    process.exit(1);
  });
}

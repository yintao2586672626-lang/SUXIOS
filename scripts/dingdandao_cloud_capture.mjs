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
  forwardRoomStatus: '/v2/hm-b/pro/web/accom/roomStat/forward/v2',
});

export const DINGDANDAO_DETAIL_TYPES = Object.freeze({
  roomFee: 0,
  roomNights: 1,
  occupancyRate: 2,
  revpar: 3,
});

export const DINGDANDAO_TREND_TYPES = Object.freeze({
  adr: 0,
  occupancyRate: 1,
  revpar: 2,
  soldRoomNights: 3,
  totalRoomFee: 5,
});

export const DINGDANDAO_COLLECTION_MODES = Object.freeze({
  operatingIndicators: 'operating_indicators',
  fullDiagnostic: 'full_diagnostic',
});

export const DINGDANDAO_FORWARD_HORIZONS = Object.freeze([3, 7, 14, 21]);
const DINGDANDAO_FORWARD_MIN_SOURCE_DAYS = 22;
const DINGDANDAO_FORWARD_MAX_SOURCE_DAYS = 31;
const DINGDANDAO_FORWARD_DISPLAY_SEMANTICS =
  'future_days_after_as_of_date';

const DINGDANDAO_DETAIL_TYPE_SET = new Set(Object.values(DINGDANDAO_DETAIL_TYPES));
const DINGDANDAO_READABLE_TREND_TYPE_SET =
  new Set(Object.values(DINGDANDAO_TREND_TYPES));

const DINGDANDAO_TREND_METRICS = Object.freeze({
  [DINGDANDAO_TREND_TYPES.adr]: 'adr',
  [DINGDANDAO_TREND_TYPES.occupancyRate]: 'occupancy_rate_percent',
  [DINGDANDAO_TREND_TYPES.revpar]: 'revpar',
  [DINGDANDAO_TREND_TYPES.soldRoomNights]: 'sold_room_nights',
  [DINGDANDAO_TREND_TYPES.totalRoomFee]: 'total_room_fee',
});

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

function addIsoDays(date, days) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || !Number.isInteger(days)) return null;
  const value = new Date(`${date}T00:00:00.000Z`);
  if (!Number.isFinite(value.getTime())) return null;
  value.setUTCDate(value.getUTCDate() + days);
  return value.toISOString().slice(0, 10);
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
  if (path === DINGDANDAO_API_PATHS.forwardRoomStatus) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(targetDate)
      || requestBody.startDate !== targetDate
      || requestBody.endDate !== addIsoDays(targetDate, 30)
      || !exactBodyKeys(requestBody, [
        'TIMEZONEOFFSET',
        'endDate',
        'ntwNum',
        'pageNum',
        'pageSize',
        'startDate',
      ])
      || requestBody.pageNum !== 1
      || requestBody.pageSize !== 9999
    ) return blocked;
    return {
      allowed: true,
      fact_kind: 'forward_room_status',
      query_type: null,
      scope_status: 'as_of_today_forward_verified',
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
    const metric = DINGDANDAO_TREND_METRICS[requestBody.type];
    if (!metric) return blocked;
    return {
      allowed: true,
      fact_kind: `${path === DINGDANDAO_API_PATHS.countyTrend ? 'county_' : ''}${metric}_trend`,
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
    const sameContextPages = pages.filter((target) => (
      String(target.url || '').startsWith('https://www.dingdandao.com/')
      && String(target.browserContextId || '') === selectedContext.key
    ));
    let pageTarget = sameContextPages.find((target) => (
      String(target.url || '').includes('/pmsManage/report/pro/dataCenter/overview')
    )) || sameContextPages[0];
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
    let regionName = null;
    const regionExpression = `(() => {
      const bodyText = String(document.body?.innerText || '').replace(/\\s+/g, ' ');
      const match = bodyText.match(/当前区域指标\\s*[（(]([^）)]+)[）)]/);
      return match ? match[1].trim().slice(0, 120) : null;
    })()`;
    for (let attempt = 0; attempt < 20 && !regionName; attempt += 1) {
      try {
        const evaluated = await connection.send('Runtime.evaluate', {
          expression: regionExpression,
          returnByValue: true,
        }, attachedSessionId);
        const value = normalizeText(evaluated?.result?.value);
        regionName = value || null;
      } catch {
        regionName = null;
      }
      if (!regionName) {
        await new Promise((resolve) => setTimeout(resolve, 100));
      }
    }
    if (!regionName) {
      for (const candidate of pages.filter((target) => (
        target.targetId !== pageTarget.targetId
        && String(target.url || '').startsWith('https://www.dingdandao.com/')
      ))) {
        let regionSessionId = null;
        try {
          const attachedRegion = await connection.send('Target.attachToTarget', {
            targetId: candidate.targetId,
            flatten: true,
          });
          regionSessionId = validSessionText(
            attachedRegion?.sessionId,
            160,
            /^[A-Za-z0-9_-]+$/,
          );
          if (!regionSessionId) continue;
          const evaluated = await connection.send('Runtime.evaluate', {
            expression: regionExpression,
            returnByValue: true,
          }, regionSessionId);
          const value = normalizeText(evaluated?.result?.value);
          regionName = value || null;
        } catch {
          regionName = null;
        } finally {
          if (regionSessionId) {
            await connection.send('Target.detachFromTarget', {
              sessionId: regionSessionId,
            }).catch(() => undefined);
          }
        }
        if (regionName) break;
      }
    }
    const sessionMaterial = dingdandaoSessionMaterialFromStorage({
      entries: storage?.entries,
      cookies,
      userAgent: browserVersion?.userAgent,
      now,
    });
    if (regionName) sessionMaterial.regionName = regionName;
    return sessionMaterial;
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

function normalizeDingdandaoCollectionMode(value) {
  const normalized = String(
    value || DINGDANDAO_COLLECTION_MODES.operatingIndicators,
  ).trim().toLowerCase();
  if (!Object.values(DINGDANDAO_COLLECTION_MODES).includes(normalized)) {
    throw new Error('capture_collection_mode_invalid');
  }
  return normalized;
}

export function dingdandaoDirectRequests(
  ntwNum,
  targetDate,
  collectionMode = DINGDANDAO_COLLECTION_MODES.operatingIndicators,
) {
  const mode = normalizeDingdandaoCollectionMode(collectionMode);
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
  const detailTypes = mode === DINGDANDAO_COLLECTION_MODES.fullDiagnostic
    ? [
      DINGDANDAO_DETAIL_TYPES.roomFee,
      DINGDANDAO_DETAIL_TYPES.roomNights,
      DINGDANDAO_DETAIL_TYPES.occupancyRate,
      DINGDANDAO_DETAIL_TYPES.revpar,
    ]
    : [DINGDANDAO_DETAIL_TYPES.roomFee];
  for (const type of detailTypes) {
    requests.push(
      { path: DINGDANDAO_API_PATHS.sumDetail, body: { ...dated, type } },
      { path: DINGDANDAO_API_PATHS.dailyDetail, body: { ...dated, type } },
    );
  }
  const trendTypes = mode === DINGDANDAO_COLLECTION_MODES.fullDiagnostic
    ? Object.values(DINGDANDAO_TREND_TYPES)
    : [DINGDANDAO_TREND_TYPES.totalRoomFee];
  for (const type of trendTypes) {
    requests.push({
      path: DINGDANDAO_API_PATHS.trend,
      body: { ...dated, type },
    });
  }
  if (mode === DINGDANDAO_COLLECTION_MODES.fullDiagnostic) {
    requests.push({
      path: DINGDANDAO_API_PATHS.countyTotal,
      body: { ...dated, festivalType: -1200 },
    });
    for (const type of trendTypes) {
      requests.push({
        path: DINGDANDAO_API_PATHS.countyTrend,
        body: { ...dated, type },
      });
    }
    requests.push({
      path: DINGDANDAO_API_PATHS.forwardRoomStatus,
      body: {
        ...base,
        pageNum: 1,
        pageSize: 9999,
        startDate: targetDate,
        endDate: addIsoDays(targetDate, 30),
      },
      optional: true,
    });
  }
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
    collectionMode = DINGDANDAO_COLLECTION_MODES.operatingIndicators,
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
  const regionName = normalizeText(sessionMaterial?.regionName) || null;
  const records = [];
  try {
    for (const request of dingdandaoDirectRequests(
      sessionMaterial.ntwNum,
      targetDate,
      collectionMode,
    )) {
      const classification = classifyDingdandaoResponseRequest({
        path: request.path,
        requestBody: request.body,
        targetDate,
      });
      if (!classification.allowed) throw new Error('capture_direct_request_blocked');
      try {
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
      } catch (error) {
        if (request.optional !== true) throw error;
        records.push({
          method: 'POST',
          path: request.path,
          status: 0,
          query_type: classification.query_type,
          fact_kind: classification.fact_kind,
          scope_status: classification.scope_status,
          error_code: safeReason(error),
        });
      }
    }
  } finally {
    if (sessionMaterial && typeof sessionMaterial === 'object') {
      sessionMaterial.ntwNum = '';
      sessionMaterial.token = '';
      sessionMaterial.cookieHeader = '';
      sessionMaterial.userAgent = '';
      if ('regionName' in sessionMaterial) sessionMaterial.regionName = '';
    }
    sessionMaterial = null;
  }
  const capture = buildCaptureFromDingdandaoResponses(records, {
    targetDate,
    capturedAt,
    regionName,
  });
  if (!isTrustedDingdandaoCaptureComplete(capture, {
    targetDate,
    expectedHotelName,
  })) throw new Error('capture_exact_today_network_incomplete');
  if (collectionMode === DINGDANDAO_COLLECTION_MODES.fullDiagnostic
    && !isFullDiagnosticDingdandaoCaptureComplete(capture, targetDate)
  ) {
    throw new Error(
      `capture_full_diagnostic_incomplete_${fullDiagnosticGapCodes(capture, targetDate).join('_')}`,
    );
  }
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
      if ('regionName' in sessionMaterial) sessionMaterial.regionName = '';
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

function successfulResponseData(
  records,
  path,
  factKind,
  queryType = undefined,
  scopeStatus = 'today_verified',
) {
  const record = records.find((candidate) => (
    candidate?.method === 'POST'
    && candidate?.path === path
    && candidate?.scope_status === scopeStatus
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

function forwardInteger(value) {
  const number = numberFromText(value);
  return number !== null && Number.isInteger(number) ? number : null;
}

function forwardDecimal(value) {
  return numberFromText(value);
}

function forwardPercent(value) {
  const number = numberFromText(value);
  return number !== null && number <= 100 ? number : null;
}

function forwardMathMatches(actual, expected, tolerance = 0.02) {
  return typeof actual === 'number'
    && typeof expected === 'number'
    && Math.abs(actual - expected) <= tolerance;
}

function normalizeForwardDay(row, expectedDate, roomCount) {
  if (!row || typeof row !== 'object' || normalizeText(row.date) !== expectedDate) {
    return null;
  }
  const remaining = forwardInteger(row.availableSale);
  const booked = forwardInteger(row.occupy);
  const unavailable = forwardInteger(row.unavailableSale);
  const oversold = forwardInteger(row.oversold);
  const roomFee = forwardDecimal(row.roomFee);
  const soldRoomNights = forwardInteger(row.night);
  const sellableRoomNights = forwardInteger(row.avaRoom);
  const occupancy = forwardPercent(row.occ);
  const adr = forwardDecimal(row.adr);
  const revpar = forwardDecimal(row.revPar);
  if ([
    remaining,
    booked,
    unavailable,
    oversold,
    roomFee,
    soldRoomNights,
    sellableRoomNights,
    occupancy,
    adr,
    revpar,
  ].includes(null)) return null;
  if ([remaining, booked, unavailable, oversold, soldRoomNights,
    sellableRoomNights, roomFee, occupancy, adr, revpar]
    .some((value) => value < 0)
  ) return null;
  if (remaining + booked + unavailable !== roomCount
    || sellableRoomNights !== remaining + booked
    || booked !== soldRoomNights
  ) return null;
  const expectedOccupancy = sellableRoomNights > 0
    ? Math.round((soldRoomNights / sellableRoomNights) * 10000) / 100
    : 0;
  const expectedAdr = soldRoomNights > 0
    ? Math.round((roomFee / soldRoomNights) * 100) / 100
    : 0;
  const expectedRevpar = sellableRoomNights > 0
    ? Math.round((roomFee / sellableRoomNights) * 100) / 100
    : 0;
  if (!forwardMathMatches(occupancy, expectedOccupancy)
    || !forwardMathMatches(adr, expectedAdr)
    || !forwardMathMatches(revpar, expectedRevpar)
  ) return null;
  return {
    stay_date: expectedDate,
    remaining_sellable_rooms: remaining,
    booked_rooms: booked,
    unavailable_rooms: unavailable,
    oversold_rooms: oversold,
    room_fee: roomFee,
    sold_room_nights: soldRoomNights,
    sellable_room_nights: sellableRoomNights,
    occupancy_rate_percent: occupancy,
    adr,
    revpar,
  };
}

function emptyForwardHorizon(asOfDate, horizonDays, gapCode) {
  return {
    horizon_days: horizonDays,
    date_from: addIsoDays(asOfDate, 1),
    date_to: addIsoDays(asOfDate, horizonDays),
    expected_days: horizonDays,
    covered_days: 0,
    sellable_room_nights: null,
    booked_room_nights: null,
    remaining_sellable_room_nights: null,
    unavailable_room_nights: null,
    room_fee: null,
    occupancy_rate_percent: null,
    adr: null,
    revpar: null,
    quality_status: 'partial',
    gap_codes: [gapCode],
  };
}

function forwardHorizon(asOfDate, dailyRows, horizonDays) {
  const rowsByDate = new Map(dailyRows.map((row) => [row.stay_date, row]));
  const rows = [];
  for (let offset = 1; offset <= horizonDays; offset += 1) {
    const row = rowsByDate.get(addIsoDays(asOfDate, offset));
    if (row) rows.push(row);
  }
  if (rows.length !== horizonDays) {
    const partial = emptyForwardHorizon(
      asOfDate,
      horizonDays,
      'dingdandao_forward_coverage_partial',
    );
    partial.covered_days = rows.length;
    return partial;
  }
  const sum = (field) => rows.reduce((total, row) => total + row[field], 0);
  const sellable = sum('sellable_room_nights');
  const booked = sum('booked_rooms');
  const roomFee = Math.round(sum('room_fee') * 100) / 100;
  return {
    horizon_days: horizonDays,
    date_from: rows[0].stay_date,
    date_to: rows.at(-1).stay_date,
    expected_days: horizonDays,
    covered_days: rows.length,
    sellable_room_nights: sellable,
    booked_room_nights: booked,
    remaining_sellable_room_nights: sum('remaining_sellable_rooms'),
    unavailable_room_nights: sum('unavailable_rooms'),
    room_fee: roomFee,
    occupancy_rate_percent: sellable > 0
      ? Math.round((booked / sellable) * 10000) / 100
      : 0,
    adr: booked > 0 ? Math.round((roomFee / booked) * 100) / 100 : 0,
    revpar: sellable > 0 ? Math.round((roomFee / sellable) * 100) / 100 : 0,
    quality_status: 'verified',
    gap_codes: [],
  };
}

function partialForwardRoomStatus(targetDate, gapCode) {
  return {
    contract_version: 'dingdandao_forward_room_status.v1',
    fact_scope: 'whole_hotel_forward_room_status',
    source_api_path: DINGDANDAO_API_PATHS.forwardRoomStatus,
    data_status: 'partial',
    as_of_date: targetDate,
    range_start_date: null,
    range_end_date: null,
    source_day_count: 0,
    display_day_count: 0,
    source_room_type_count: 0,
    total_room_count: null,
    display_horizons: [...DINGDANDAO_FORWARD_HORIZONS],
    display_semantics: DINGDANDAO_FORWARD_DISPLAY_SEMANTICS,
    requested_range_start_date: targetDate,
    requested_range_end_date: addIsoDays(targetDate, 30),
    source_coverage_status: 'missing',
    source_gap_codes: [gapCode],
    daily_rows: [],
    room_types: [],
    horizons: DINGDANDAO_FORWARD_HORIZONS.map(
      (horizon) => emptyForwardHorizon(targetDate, horizon, gapCode),
    ),
    reconciliation_status: 'unverified',
    gap_codes: [gapCode],
    field_trace: {
      request:
        `POST:${DINGDANDAO_API_PATHS.forwardRoomStatus}`
        + '#pageNum=1&pageSize=9999&startDate&endDate',
    },
  };
}

function forwardRatioCoverage(ratioRow, dailyRows, expectedDates) {
  const ratios = Array.isArray(ratioRow?.dateList) ? ratioRow.dateList : [];
  const maxRows = Math.min(
    ratios.length,
    dailyRows.length,
    expectedDates.length,
  );
  let covered = 0;
  for (let index = 0; index < maxRows; index += 1) {
    const row = ratios[index];
    const total = dailyRows[index];
    if (!total || normalizeText(row?.date) !== expectedDates[index]) break;
    const available = forwardPercent(row?.availablePercent);
    const occupied = forwardPercent(row?.occupyPercent);
    const unavailable = forwardPercent(row?.unavailableSalePercent);
    if ([available, occupied, unavailable].includes(null)) break;
    const roomCount = total.remaining_sellable_rooms
      + total.booked_rooms
      + total.unavailable_rooms;
    if (roomCount <= 0) break;
    const matches = forwardMathMatches(
      available,
      Math.round((total.remaining_sellable_rooms / roomCount) * 1000) / 10,
      0.11,
    ) && forwardMathMatches(
      occupied,
      Math.round((total.booked_rooms / roomCount) * 1000) / 10,
      0.11,
    ) && forwardMathMatches(
      unavailable,
      Math.round((total.unavailable_rooms / roomCount) * 1000) / 10,
      0.11,
    );
    if (!matches) break;
    covered += 1;
  }
  return covered;
}

function normalizeForwardDayPrefix(rows, expectedDates, roomCount) {
  if (!Array.isArray(rows)) return [];
  const normalized = [];
  const limit = Math.min(
    rows.length,
    expectedDates.length,
    DINGDANDAO_FORWARD_MAX_SOURCE_DAYS,
  );
  for (let index = 0; index < limit; index += 1) {
    const day = normalizeForwardDay(rows[index], expectedDates[index], roomCount);
    if (!day) break;
    normalized.push(day);
  }
  return normalized;
}

function forwardRequestGapCode(records) {
  const record = records.find((candidate) => (
    candidate?.method === 'POST'
    && candidate?.path === DINGDANDAO_API_PATHS.forwardRoomStatus
    && candidate?.scope_status === 'as_of_today_forward_verified'
    && candidate?.fact_kind === 'forward_room_status'
  ));
  const errorCode = normalizeText(record?.error_code);
  return {
    capture_api_request_failed: 'dingdandao_forward_request_failed',
    capture_api_code_unverified: 'dingdandao_forward_api_code_unverified',
    capture_api_response_unverified: 'dingdandao_forward_response_unverified',
    capture_session_expired: 'dingdandao_forward_session_expired',
  }[errorCode] || (
    record && record.status !== 200
      ? 'dingdandao_forward_request_failed'
      : 'dingdandao_forward_response_contract_unverified'
  );
}

function forwardRoomStatusFromResponse(records, targetDate) {
  const data = successfulResponseData(
    records,
    DINGDANDAO_API_PATHS.forwardRoomStatus,
    'forward_room_status',
    undefined,
    'as_of_today_forward_verified',
  );
  const rows = Array.isArray(data?.list) ? data.list : [];
  if (rows.length < 3 || rows.length > 300) {
    return partialForwardRoomStatus(
      targetDate,
      forwardRequestGapCode(records),
    );
  }
  const expectedDates = Array.from(
    { length: 31 },
    (_, index) => addIsoDays(targetDate, index),
  );
  const totalName = '\u603b\u8ba1';
  const ratioName = '\u5360\u603b\u623f\u6570\u7684\u6bd4\u4f8b';
  const totalRows = rows.filter(
    (row) => normalizeText(row?.roomTypeShortName) === totalName,
  );
  const ratioRows = rows.filter(
    (row) => normalizeText(row?.roomTypeShortName) === ratioName,
  );
  const roomTypeRows = rows.filter(
    (row) => normalizeText(row?.roomTypeId) !== '',
  );
  if (totalRows.length !== 1
    || ratioRows.length !== 1
    || roomTypeRows.length === 0
  ) {
    return partialForwardRoomStatus(
      targetDate,
      'dingdandao_forward_response_contract_unverified',
    );
  }
  const totalRoomCount = forwardInteger(totalRows[0]?.roomNum);
  if (totalRoomCount === null || totalRoomCount <= 0) {
    return partialForwardRoomStatus(
      targetDate,
      'dingdandao_forward_response_contract_unverified',
    );
  }
  const totalDateRows = Array.isArray(totalRows[0]?.dateList)
    ? totalRows[0].dateList
    : [];
  let dailyRows = normalizeForwardDayPrefix(
    totalDateRows,
    expectedDates,
    totalRoomCount,
  );
  if (dailyRows.length < DINGDANDAO_FORWARD_MIN_SOURCE_DAYS) {
    return partialForwardRoomStatus(
      targetDate,
      'dingdandao_forward_coverage_partial',
    );
  }
  const ratioCoverage = forwardRatioCoverage(
    ratioRows[0],
    dailyRows,
    expectedDates,
  );
  if (ratioCoverage < DINGDANDAO_FORWARD_MIN_SOURCE_DAYS) {
    return partialForwardRoomStatus(
      targetDate,
      'dingdandao_forward_reconciliation_mismatch',
    );
  }
  const roomTypes = [];
  const roomTypeIds = new Set();
  for (const row of roomTypeRows) {
    const providerRoomTypeId = normalizeText(row?.roomTypeId);
    const roomTypeName = normalizeText(row?.roomTypeShortName);
    const roomCount = forwardInteger(row?.roomNum);
    const dateRows = Array.isArray(row?.dateList) ? row.dateList : [];
    if (!/^[A-Za-z0-9_-]{1,120}$/.test(providerRoomTypeId)
      || roomTypeIds.has(providerRoomTypeId)
      || !roomTypeName
      || roomTypeName.length > 160
      || roomCount === null
      || roomCount <= 0
    ) {
      return partialForwardRoomStatus(
        targetDate,
        'dingdandao_forward_response_contract_unverified',
      );
    }
    const normalizedDates = normalizeForwardDayPrefix(
      dateRows,
      expectedDates,
      roomCount,
    );
    if (normalizedDates.length < DINGDANDAO_FORWARD_MIN_SOURCE_DAYS) {
      return partialForwardRoomStatus(
        targetDate,
        'dingdandao_forward_coverage_partial',
      );
    }
    roomTypeIds.add(providerRoomTypeId);
    roomTypes.push({
      provider_room_type_id: providerRoomTypeId,
      room_type_name: roomTypeName,
      room_count: roomCount,
      daily_rows: normalizedDates,
    });
  }
  if (roomTypes.reduce((sum, row) => sum + row.room_count, 0) !== totalRoomCount) {
    return partialForwardRoomStatus(
      targetDate,
      'dingdandao_forward_reconciliation_mismatch',
    );
  }
  const sourceDayCount = Math.min(
    dailyRows.length,
    ratioCoverage,
    ...roomTypes.map((row) => row.daily_rows.length),
    DINGDANDAO_FORWARD_MAX_SOURCE_DAYS,
  );
  if (sourceDayCount < DINGDANDAO_FORWARD_MIN_SOURCE_DAYS) {
    return partialForwardRoomStatus(
      targetDate,
      'dingdandao_forward_coverage_partial',
    );
  }
  dailyRows = dailyRows.slice(0, sourceDayCount);
  for (const roomType of roomTypes) {
    roomType.daily_rows = roomType.daily_rows.slice(0, sourceDayCount);
  }
  if (dailyRows.some((row) => row.oversold_rooms > 0)
    || roomTypes.some((roomType) => (
      roomType.daily_rows.some((row) => row.oversold_rooms > 0)
    ))
  ) {
    return partialForwardRoomStatus(
      targetDate,
      'dingdandao_forward_oversold_present',
    );
  }
  const sumRoomTypeMetric = (dateIndex, field) => roomTypes.reduce(
    (sum, roomType) => sum + roomType.daily_rows[dateIndex][field],
    0,
  );
  const reconciles = dailyRows.every((total, index) => (
    ['remaining_sellable_rooms', 'booked_rooms', 'unavailable_rooms',
      'oversold_rooms',
      'sold_room_nights', 'sellable_room_nights']
      .every((field) => sumRoomTypeMetric(index, field) === total[field])
    && forwardMathMatches(
      sumRoomTypeMetric(index, 'room_fee'),
      total.room_fee,
    )
  ));
  if (!reconciles) {
    return partialForwardRoomStatus(
      targetDate,
      'dingdandao_forward_reconciliation_mismatch',
    );
  }
  return {
    contract_version: 'dingdandao_forward_room_status.v1',
    fact_scope: 'whole_hotel_forward_room_status',
    source_api_path: DINGDANDAO_API_PATHS.forwardRoomStatus,
    data_status: 'verified',
    as_of_date: targetDate,
    range_start_date: expectedDates[0],
    range_end_date: expectedDates[sourceDayCount - 1],
    requested_range_start_date: targetDate,
    requested_range_end_date: addIsoDays(targetDate, 30),
    source_day_count: sourceDayCount,
    display_day_count: 21,
    source_room_type_count: roomTypes.length,
    total_room_count: totalRoomCount,
    display_horizons: [...DINGDANDAO_FORWARD_HORIZONS],
    display_semantics: DINGDANDAO_FORWARD_DISPLAY_SEMANTICS,
    source_coverage_status: sourceDayCount === DINGDANDAO_FORWARD_MAX_SOURCE_DAYS
      ? 'complete'
      : 'partial',
    source_gap_codes: sourceDayCount === DINGDANDAO_FORWARD_MAX_SOURCE_DAYS
      ? []
      : ['dingdandao_forward_trailing_coverage_partial'],
    daily_rows: dailyRows,
    room_types: roomTypes,
    horizons: DINGDANDAO_FORWARD_HORIZONS.map(
      (horizon) => forwardHorizon(targetDate, dailyRows, horizon),
    ),
    reconciliation_status: 'matched',
    gap_codes: [],
    field_trace: {
      request:
        `POST:${DINGDANDAO_API_PATHS.forwardRoomStatus}`
        + '#pageNum=1&pageSize=9999&startDate&endDate',
      total_room_count:
        `API:${DINGDANDAO_API_PATHS.forwardRoomStatus}`
        + '#data.list[roomTypeShortName=\u603b\u8ba1].roomNum',
      daily_rows:
        `API:${DINGDANDAO_API_PATHS.forwardRoomStatus}`
        + '#data.list[roomTypeShortName=\u603b\u8ba1].dateList[]',
      room_types:
        `API:${DINGDANDAO_API_PATHS.forwardRoomStatus}`
        + '#data.list[roomTypeId].dateList[]',
    },
  };
}

export function isTrustedDingdandaoForwardRoomStatusComplete(
  forwardRoomStatus,
  targetDate,
) {
  return Boolean(
    forwardRoomStatus
    && forwardRoomStatus.contract_version === 'dingdandao_forward_room_status.v1'
    && forwardRoomStatus.fact_scope === 'whole_hotel_forward_room_status'
    && forwardRoomStatus.source_api_path === DINGDANDAO_API_PATHS.forwardRoomStatus
    && forwardRoomStatus.data_status === 'verified'
    && forwardRoomStatus.as_of_date === targetDate
    && forwardRoomStatus.range_start_date === targetDate
    && forwardRoomStatus.requested_range_start_date === targetDate
    && forwardRoomStatus.requested_range_end_date === addIsoDays(targetDate, 30)
    && Number.isInteger(forwardRoomStatus.source_day_count)
    && forwardRoomStatus.source_day_count >= DINGDANDAO_FORWARD_MIN_SOURCE_DAYS
    && forwardRoomStatus.source_day_count <= DINGDANDAO_FORWARD_MAX_SOURCE_DAYS
    && forwardRoomStatus.range_end_date === addIsoDays(
      targetDate,
      forwardRoomStatus.source_day_count - 1,
    )
    && forwardRoomStatus.display_day_count === 21
    && forwardRoomStatus.display_semantics
      === DINGDANDAO_FORWARD_DISPLAY_SEMANTICS
    && ['complete', 'partial'].includes(
      forwardRoomStatus.source_coverage_status,
    )
    && Array.isArray(forwardRoomStatus.source_gap_codes)
    && (
      (
        forwardRoomStatus.source_coverage_status === 'complete'
        && forwardRoomStatus.source_day_count
          === DINGDANDAO_FORWARD_MAX_SOURCE_DAYS
        && forwardRoomStatus.source_gap_codes.length === 0
      )
      || (
        forwardRoomStatus.source_coverage_status === 'partial'
        && forwardRoomStatus.source_day_count
          < DINGDANDAO_FORWARD_MAX_SOURCE_DAYS
        && forwardRoomStatus.source_gap_codes.includes(
          'dingdandao_forward_trailing_coverage_partial',
        )
      )
    )
    && forwardRoomStatus.reconciliation_status === 'matched'
    && Array.isArray(forwardRoomStatus.daily_rows)
    && forwardRoomStatus.daily_rows.length === forwardRoomStatus.source_day_count
    && Array.isArray(forwardRoomStatus.room_types)
    && forwardRoomStatus.room_types.length > 0
    && Array.isArray(forwardRoomStatus.horizons)
    && forwardRoomStatus.horizons.length === DINGDANDAO_FORWARD_HORIZONS.length
    && forwardRoomStatus.horizons.every((horizon, index) => (
      horizon?.horizon_days === DINGDANDAO_FORWARD_HORIZONS[index]
      && horizon?.quality_status === 'verified'
      && horizon?.covered_days === horizon?.expected_days
    ))
    && Array.isArray(forwardRoomStatus.gap_codes)
    && forwardRoomStatus.gap_codes.length === 0
  );
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

function trendFromResponse(trendData, targetDate, metricKey = 'total_room_fee') {
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
  return normalized.length === 0 ? {} : { [metricKey]: normalized };
}

function trendsFromResponses(records, path, targetDate, county = false) {
  const output = {};
  for (const [type, metric] of Object.entries(DINGDANDAO_TREND_METRICS)) {
    const factKind = `${county ? 'county_' : ''}${metric}_trend`;
    const data = successfulResponseData(records, path, factKind, Number(type));
    Object.assign(output, trendFromResponse(data, targetDate, metric));
  }
  return output;
}

export function buildCaptureFromDingdandaoResponses(
  records,
  { targetDate, capturedAt = new Date().toISOString(), regionName = null },
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
  const countyTotal = successfulResponseData(
    records,
    DINGDANDAO_API_PATHS.countyTotal,
    'county_total',
  );
  const trend = trendsFromResponses(
    records,
    DINGDANDAO_API_PATHS.trend,
    targetDate,
  );
  const countyTrend = trendsFromResponses(
    records,
    DINGDANDAO_API_PATHS.countyTrend,
    targetDate,
    true,
  );
  const forwardRoomStatus = forwardRoomStatusFromResponse(records, targetDate);
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
    ...Object.fromEntries(
      Object.entries(DINGDANDAO_TREND_METRICS).map(([type, metric]) => [
        `trend_${metric}`,
        `API:${DINGDANDAO_API_PATHS.trend}?type=${type}#data.list[]`,
      ]),
    ),
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
  const countyComplete = Object.values(countySummary).every(
    (value) => typeof value === 'number' && Number.isFinite(value),
  ) && normalizeText(regionName) !== ''
    && Object.values(DINGDANDAO_TREND_METRICS).every(
      (metric) => Array.isArray(countyTrend[metric])
        && countyTrend[metric].length > 0,
    );
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
    trend,
    auxiliary_query_status: auxiliaryQueryStatus,
    county_context: {
      fact_scope: 'county_diagnostic_only',
      data_status: countyComplete ? 'readable_separate' : 'partial',
      region_name: normalizeText(regionName) || null,
      bool_city: typeof countyTotal?.boolCity === 'boolean'
        ? countyTotal.boolCity
        : null,
      summary: countySummary,
      trend: countyTrend,
      field_trace: {
        summary: `API:${DINGDANDAO_API_PATHS.countyTotal}#data`,
        region_name: 'DOM:当前区域指标',
        ...Object.fromEntries(
          Object.entries(DINGDANDAO_TREND_METRICS).map(([type, metric]) => [
            metric,
            `API:${DINGDANDAO_API_PATHS.countyTrend}?type=${type}#data.list[]`,
          ]),
        ),
      },
    },
    forward_room_status: forwardRoomStatus,
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

export function isFullDiagnosticDingdandaoCaptureComplete(capture, targetDate) {
  return fullDiagnosticGapCodes(capture, targetDate).length === 0;
}

function fullDiagnosticGapCodes(capture, targetDate) {
  const gaps = [];
  const county = capture?.county_context;
  if (!county
    || county.fact_scope !== 'county_diagnostic_only'
  ) {
    return ['county_scope'];
  }
  if (county.data_status !== 'readable_separate') gaps.push('county_status');
  if (!normalizeText(county.region_name)) gaps.push('region_name');
  const summaryKeys = Object.values(DINGDANDAO_TREND_METRICS);
  for (const metric of summaryKeys) {
    const hotelSummary = capture.summary?.[metric];
    const countySummary = county.summary?.[metric];
    const hotelPoint = capture.trend?.[metric]?.find(
      (point) => point?.date === targetDate,
    );
    const countyPoint = county.trend?.[metric]?.find(
      (point) => point?.date === targetDate,
    );
    if (![hotelSummary, hotelPoint?.value].every((value) => (
      typeof value === 'number' && Number.isFinite(value) && value >= 0
    ))) gaps.push(`hotel_${metric}`);
    if (![countySummary, countyPoint?.value].every((value) => (
      typeof value === 'number' && Number.isFinite(value) && value >= 0
    ))) gaps.push(`county_${metric}`);
    if (hotelPoint && typeof hotelSummary === 'number'
      && Math.abs(hotelPoint.value - hotelSummary) > 0.02
    ) gaps.push(`hotel_${metric}_mismatch`);
    if (countyPoint && typeof countySummary === 'number'
      && Math.abs(countyPoint.value - countySummary) > 0.02
    ) gaps.push(`county_${metric}_mismatch`);
    if (!capture.field_trace?.[`trend_${metric}`]) gaps.push(`hotel_${metric}_trace`);
    if (!county.field_trace?.[metric]) gaps.push(`county_${metric}_trace`);
  }
  if (!county.field_trace?.summary) gaps.push('county_summary_trace');
  if (!county.field_trace?.region_name) gaps.push('region_trace');
  return gaps;
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
  const collectionMode = normalizeDingdandaoCollectionMode(
    values['collection-mode'],
  );
  return {
    cdpUrl: cdpUrl.toString().replace(/\/$/, ''),
    targetDate: values['target-date'],
    expectedHotelName: normalizeText(values['expected-hotel-name']).slice(0, 160),
    collectionMode,
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
    collection_mode: options.collectionMode,
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
